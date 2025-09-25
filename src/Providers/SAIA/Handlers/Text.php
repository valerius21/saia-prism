<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\SAIA\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\SAIA\Maps\FinishReasonMap;
use Prism\Prism\Providers\SAIA\Maps\MessageMap;
use Prism\Prism\Providers\SAIA\Maps\ToolChoiceMap;
use Prism\Prism\Providers\SAIA\Maps\ToolMap;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Prism\Prism\Providers\SAIA\Concerns\ExtractsReasoning;
use Prism\Prism\Providers\SAIA\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\SAIA\Concerns\ValidateResponse;

class Text
{
    use CallsTools, ExtractsReasoning, ProcessRateLimits, ValidateResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): TextResponse
    {
        $response = $this->sendRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        $responseMessage = new AssistantMessage(
            data_get($data, 'choices.0.message.content') ?? '',
            $this->mapToolCalls(data_get($data, 'choices.0.message.tool_calls', []) ?? []),
            array_filter(['reasoning' => $this->extractReasoning($data)])
        );

        $request->addMessage($responseMessage);

        $finishReason = FinishReasonMap::map(data_get($data, 'choices.0.finish_reason', ''));

        return match ($finishReason) {
            FinishReason::ToolCalls => $this->handleToolCalls($data, $request, $response),
            FinishReason::Stop, FinishReason::Length => $this->handleStop($data, $request, $response, $finishReason),
            default => throw new PrismException('Saia: unhandled finish reason'),
        };
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        return $this->client->post(
            'chat/completions',
            Arr::whereNotNull([
                'model' => $request->model(),
                'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                'max_tokens' => $request->maxTokens(),
                'temperature' => $request->temperature(),
                'top_p' => $request->topP(),
                'tools' => ToolMap::map($request->tools()),
                'tool_choice' => ToolChoiceMap::map($request->toolChoice(), $request->tools()),
            ])
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleToolCalls(array $data, Request $request, ClientResponse $clientResponse): TextResponse
    {
        $toolResults = $this->callTools(
            $request->tools(),
            $this->mapToolCalls(data_get($data, 'choices.0.message.tool_calls', []) ?? []),
        );

        $request->addMessage(new ToolResultMessage($toolResults));

        $this->addStep($data, $request, $clientResponse, FinishReason::ToolCalls, $toolResults);

        if ($this->shouldContinue($request)) {
            return $this->handle($request);
        }

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleStop(
        array $data,
        Request $request,
        ClientResponse $clientResponse,
        FinishReason $finishReason
    ): TextResponse {
        $this->addStep($data, $request, $clientResponse, $finishReason);

        return $this->responseBuilder->toResponse();
    }

    protected function shouldContinue(Request $request): bool
    {
        return $this->responseBuilder->steps->count() < $request->maxSteps();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  ToolResult[]  $toolResults
     */
    protected function addStep(
        array $data,
        Request $request,
        ClientResponse $clientResponse,
        FinishReason $finishReason,
        array $toolResults = []
    ): void {
        $this->responseBuilder->addStep(new Step(
            text: data_get($data, 'choices.0.message.content') ?? '',
            finishReason: $finishReason,
            toolCalls: $this->mapToolCalls(data_get($data, 'choices.0.message.tool_calls', []) ?? []),
            toolResults: $toolResults,
            usage: new Usage(
                data_get($data, 'usage.prompt_tokens'),
                data_get($data, 'usage.completion_tokens'),
            ),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
                rateLimits: $this->processRateLimits($clientResponse),
            ),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: array_filter([
                'reasoning' => $this->extractReasoning($data),
            ]),
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, ToolCall>
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return array_map(fn(array $toolCall): ToolCall => new ToolCall(
            id: data_get($toolCall, 'id'),
            name: data_get($toolCall, 'function.name'),
            arguments: data_get($toolCall, 'function.arguments'),
        ), $toolCalls);
    }
}
