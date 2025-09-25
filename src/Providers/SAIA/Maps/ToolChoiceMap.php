<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\SAIA\Maps;

use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Tool;

class ToolChoiceMap
{
    /**
     * @param  ToolChoice|null  $choice
     * @param  Tool[]  $tools
     * @return string|array<string, mixed>|null
     */
    public static function map(?ToolChoice $choice, array $tools = []): string|array|null
    {
        if (empty($tools) || $choice === null) {
            return null;
        }

        return match ($choice) {
            ToolChoice::Auto => 'auto',
            ToolChoice::None => 'none',
            ToolChoice::Any => 'any',
        };
    }
}