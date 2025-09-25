<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\SAIA\Maps;

use Prism\Prism\Enums\FinishReason;

class FinishReasonMap
{
    public static function map(?string $reason): FinishReason
    {
        return match ($reason) {
            'stop' => FinishReason::Stop,
            'tool_calls' => FinishReason::ToolCalls,
            'length' => FinishReason::Length,
            'content_filter' => FinishReason::ContentFilter,
            null => FinishReason::Other,
            default => FinishReason::Unknown,
        };
    }
}