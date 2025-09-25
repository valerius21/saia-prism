<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\SAIA\Concerns;

use Illuminate\Http\Client\Response;
use Prism\Prism\ValueObjects\ProviderRateLimit;

trait ProcessRateLimits
{
    /**
     * @return ProviderRateLimit[]
     */
    protected function processRateLimits(Response $response): array
    {
        $rateLimits = [];

        // Check for standard rate limit headers
        $headers = $response->headers();

        if (isset($headers['x-ratelimit-limit-requests'])) {
            $rateLimits[] = new ProviderRateLimit(
                name: 'requests',
                limit: (int) ($headers['x-ratelimit-limit-requests'][0] ?? 0),
                remaining: (int) ($headers['x-ratelimit-remaining-requests'][0] ?? 0),
                resetsAt: isset($headers['x-ratelimit-reset-requests'])
                    ? now()->addSeconds((int) $headers['x-ratelimit-reset-requests'][0])
                    : null,
            );
        }

        if (isset($headers['x-ratelimit-limit-tokens'])) {
            $rateLimits[] = new ProviderRateLimit(
                name: 'tokens',
                limit: (int) ($headers['x-ratelimit-limit-tokens'][0] ?? 0),
                remaining: (int) ($headers['x-ratelimit-remaining-tokens'][0] ?? 0),
                resetsAt: isset($headers['x-ratelimit-reset-tokens'])
                    ? now()->addSeconds((int) $headers['x-ratelimit-reset-tokens'][0])
                    : null,
            );
        }

        return $rateLimits;
    }
}