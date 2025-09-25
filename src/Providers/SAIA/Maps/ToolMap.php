<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\SAIA\Maps;


use Prism\Prism\Facades\Tool;

class ToolMap
{
    /**
     * @param  Tool[]  $tools
     * @return array<int, mixed>|null
     */
    public static function map(array $tools): ?array
    {
        if (empty($tools)) {
            return null;
        }

        return array_map(callback: static function (Tool $tool): array {
            return [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->parameters(),
                ],
            ];
        }, array: $tools);
    }
}