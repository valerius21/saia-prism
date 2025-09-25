<?php


declare(strict_types=1);

namespace Prism\Prism\Providers\SAIA\Handlers;

use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\ChunkType;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismChunkDecodeException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\SAIA\Concerns\ExtractsReasoning;
use Prism\Prism\Providers\SAIA\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\SAIA\Concerns\ValidateResponse;
use Prism\Prism\Providers\SAIA\Maps\FinishReasonMap;
use Prism\Prism\Providers\SAIA\Maps\MessageMap;
use Prism\Prism\Providers\SAIA\Maps\ToolChoiceMap;
use Prism\Prism\Providers\SAIA\Maps\ToolMap;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Psr\Http\Message\StreamInterface;
use Throwable;

class Stream
{
    use CallsTools, ExtractsReasoning, ProcessRateLimits, ValidateResponse;

    public function __construct(protected PendingRequest $client)
    {
    }

    /**
     * @return Generator<Chunk>
     * @throws PrismException
     */
    public function handle(Request $request): Generator
    {
        $response = $this->sendRequest($request);

        yield from $this->processStream($response, $request);
    }

    /**
     * @return Generator<Chunk>
     * @throws PrismException
     */
    protected function processStream(Response $response, Request $request, int $depth = 0): Generator
    {
        if ($depth >= $request->maxSteps()) {
            throw new PrismException('Maximum tool call chain depth exceeded');
        }

        $text = '';
        $toolCalls = [];
        $reasoning = '';

        while (!$response->getBody()->eof()) {
            $data = $this->parseNextDataLine($response->getBody());

            if ($data === null) {
                continue;
            }

            if ($this->hasError($data)) {
                $this->handleErrors($data, $request);
            }

            if ($this->hasToolCalls($data)) {
                $toolCalls = $this->extractToolCalls($data, $toolCalls);

                continue;
            }

            if ($this->mapFinishReason($data) === FinishReason::ToolCalls) {
                yield from $this->handleToolCalls($request, $text, $toolCalls, $depth, $reasoning);

                return;
            }

            $content = data_get($data, 'choices.0.delta.content', '') ?? '';
            $text .= $content;

            $reasoningDelta = $this->extractReasoningDelta($data);
            $reasoning .= $reasoningDelta;

            if ($reasoningDelta !== '') {
                yield new Chunk(
                    text: $reasoningDelta,
                    finishReason: null,
                    chunkType: ChunkType::Thinking
                );
            }

            $finishReason = $this->mapFinishReason($data);

            if ($content !== '') {
                yield new Chunk(
                    text: $content,
                    finishReason: $finishReason !== FinishReason::Unknown ? $finishReason : null,
                    chunkType: ChunkType::Text
                );
            }
        }
    }

    /**
     * @return array<string, mixed>|null Parsed JSON data or null if line should be skipped
     */
    protected function parseNextDataLine(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        if (!str_starts_with($line, 'data:')) {
            return null;
        }

        $line = trim(substr($line, strlen('data: ')));

        if ($line === '' || $line === '[DONE]') {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new PrismChunkDecodeException('Saia', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    protected function extractToolCalls(array $data, array $toolCalls): array
    {
        foreach (data_get($data, 'choices.0.delta.tool_calls', []) as $index => $toolCall) {
            if ($name = data_get($toolCall, 'function.name')) {
                $toolCalls[$index]['name'] = $name;
                $toolCalls[$index]['arguments'] = '';
                $toolCalls[$index]['id'] = data_get($toolCall, 'id');
            }

            $arguments = data_get($toolCall, 'function.arguments');

            if (!is_null($arguments)) {
                $toolCalls[$index]['arguments'] .= $arguments;
            }
        }

        return $toolCalls;
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return Generator<Chunk>
     * @throws PrismException
     */
    protected function handleToolCalls(
        Request $request,
        string $text,
        array $toolCalls,
        int $depth,
        string $reasoning = ''
    ): Generator {
        $toolCalls = $this->mapToolCalls($toolCalls);

        yield new Chunk(
            text: '',
            toolCalls: $toolCalls,
            chunkType: ChunkType::ToolCall,
        );

        $toolResults = $this->callTools($request->tools(), $toolCalls);

        yield new Chunk(
            text: '',
            toolResults: $toolResults,
            chunkType: ChunkType::ToolResult,
        );

        $request->addMessage(new AssistantMessage(
            $text,
            $toolCalls,
            array_filter(['reasoning' => $reasoning])
        ));
        $request->addMessage(new ToolResultMessage($toolResults));

        $nextResponse = $this->sendRequest($request);
        yield from $this->processStream($nextResponse, $request, $depth + 1);
    }

    /**
     * Convert raw tool call data to ToolCall objects.
     *
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, ToolCall>
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return collect($toolCalls)
            ->map(fn($toolCall): ToolCall => new ToolCall(
                data_get($toolCall, 'id'),
                data_get($toolCall, 'name'),
                data_get($toolCall, 'arguments'),
            ))
            ->toArray();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasToolCalls(array $data): bool
    {
        return (bool) data_get($data, 'choices.0.delta.tool_calls');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasError(array $data): bool
    {
        return data_get($data, 'error') !== null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReason(array $data): FinishReason
    {
        return FinishReasonMap::map(data_get($data, 'choices.0.finish_reason'));
    }

    /**
     * @throws PrismRateLimitedException
     * @throws PrismException
     * @throws ConnectionException
     */
    protected function sendRequest(Request $request): Response
    {
        try {
            return $this
                ->client
                ->withOptions(['stream' => true])
                ->throw()
                ->post('chat/completions',
                    array_merge([
                        'stream' => true,
                        'model' => $request->model(),
                        'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                        'max_tokens' => $request->maxTokens(),
                    ], Arr::whereNotNull([
                        'temperature' => $request->temperature(),
                        'top_p' => $request->topP(),
                        'tools' => ToolMap::map($request->tools()),
                        'tool_choice' => ToolChoiceMap::map($request->toolChoice(), $request->tools()),
                    ]))
                );
        } catch (\Illuminate\Http\Client\RequestException $e) {
            if ($e->response->getStatusCode() === 429) {
                throw new PrismRateLimitedException(
                    $this->processRateLimits($e->response),
                    (int) $e->response->header('retry-after')
                );
            }

            throw PrismException::providerRequestError($request->model(), $e);
        }
    }

    protected function readLine(StreamInterface $stream): string
    {
        $buffer = '';

        while (!$stream->eof()) {
            $byte = $stream->read(1);

            if ($byte === '') {
                return $buffer;
            }

            $buffer .= $byte;

            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }

    /**
     * @param  array<string, mixed>  $data
     * @throws PrismRateLimitedException
     * @throws PrismException
     */
    protected function handleErrors(array $data, Request $request): void
    {
        $error = data_get($data, 'error', []);
        $type = data_get($error, 'type', 'unknown_error');
        $message = data_get($error, 'message', 'No error message provided');

        if ($type === 'rate_limit_exceeded') {
            throw new PrismRateLimitedException([]);
        }

        throw new PrismException(sprintf(
            'Sending to model %s failed. Type: %s. Message: %s',
            $request->model(),
            $type,
            $message
        ));
    }
}