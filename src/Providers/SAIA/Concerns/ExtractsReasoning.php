<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\SAIA\Concerns;

trait ExtractsReasoning
{
    /**
     * Extract reasoning content from API response
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractReasoning(array $data): string
    {
        return data_get($data, 'choices.0.message.reasoning_content', '') ?? '';
    }

    /**
     * Extract reasoning delta from streaming response
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractReasoningDelta(array $data): string
    {
        return data_get($data, 'choices.0.delta.reasoning_content', '') ?? '';
    }
}