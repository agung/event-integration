<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Templates;

use Agung\EventIntegration\Contracts\OutputFormatTemplate;
use Agung\EventIntegration\Engine\PayloadCompiler;
use Agung\EventIntegration\Exceptions\InvalidIntegrationDefinition;
use Closure;

final class MaxTemplate implements OutputFormatTemplate
{
    public function key(): string
    {
        return '$max';
    }

    public function compile(mixed $configuration, array $payload, Closure $compileTemplate): mixed
    {
        if (! is_array($configuration) || ! is_string($configuration['path'] ?? null)
            || ! is_string($configuration['by'] ?? null)) {
            throw new InvalidIntegrationDefinition('$max requires a path and by field.');
        }

        $items = PayloadCompiler::valueAtPath($payload, $configuration['path']);

        if (! is_array($items)) {
            throw new InvalidIntegrationDefinition(
                'The $max path ['.$configuration['path'].'] must resolve to an array.',
            );
        }

        $winner = null;
        $maxValue = null;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $value = PayloadCompiler::valueAtPath($item, $configuration['by']);

            if (! is_numeric($value)) {
                continue;
            }

            if ($winner === null || (float) $value > (float) $maxValue) {
                $winner = $item;
                $maxValue = $value;
            }
        }

        if ($winner === null) {
            return null;
        }

        if (array_key_exists('template', $configuration)) {
            return $compileTemplate($configuration['template'], $winner, $payload);
        }

        return $winner;
    }
}
