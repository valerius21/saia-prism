<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\SAIA;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Override;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Enums\Provider as ProviderName;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Providers\SAIA\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\SAIA\Handlers\Stream;
use Prism\Prism\Providers\SAIA\Handlers\Text;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use SensitiveParameter;

class SAIA extends Provider
{
    use InitializesClient;
    use ProcessRateLimits;

    public function __construct(
        #[SensitiveParameter] public readonly string $apiKey,
        public readonly string $url,
    ) {
    }

    /**
     * @throws PrismException
     */
    #[Override]
    public function text(TextRequest $request): TextResponse
    {
        $handler = new Text($this->client($request->clientOptions(), $request->clientRetry()));

        return $handler->handle($request);
    }

    /**
     * @param  TextRequest  $request
     * @return Generator
     * @throws PrismException
     */
    #[Override]
    public function stream(TextRequest $request): Generator
    {
        $handler = new Stream($this->client($request->clientOptions(), $request->clientRetry()));

        return $handler->handle($request);
    }

    /**
     * @throws PrismRateLimitedException
     * @throws PrismProviderOverloadedException
     * @throws PrismRequestTooLargeException
     * @throws PrismException
     */
    public function handleRequestException(string $model, RequestException $e): never
    {
        match ($e->response->getStatusCode()) {
            429 => throw PrismRateLimitedException::make(
                rateLimits: $this->processRateLimits($e->response),
                retryAfter: (int) $e->response->header('retry-after')
            ),
            529 => throw PrismProviderOverloadedException::make(ProviderName::SAIA),
            413 => throw PrismRequestTooLargeException::make(ProviderName::SAIA),
            default => throw PrismException::providerRequestError($model, $e),
        };
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array  $retry
     * @param  string|null  $baseUrl
     * @return PendingRequest
     */
    protected function client(array $options = [], array $retry = [], ?string $baseUrl = null): PendingRequest
    {
        return $this->baseClient()
            ->when($this->apiKey, fn($client) => $client->withToken($this->apiKey))
            ->withOptions($options)
            ->when($retry !== [], fn($client) => $client->retry(...$retry))
            ->baseUrl($baseUrl ?? $this->url);
    }
}