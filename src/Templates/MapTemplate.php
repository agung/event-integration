<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Templates;

use Agung\EventIntegration\Contracts\OutputFormatTemplate;
use Agung\EventIntegration\Engine\PayloadCompiler;
use Agung\EventIntegration\Exceptions\InvalidIntegrationDefinition;
use Closure;

final class MapTemplate implements OutputFormatTemplate
{
    public function key(): string
    {
        return '$map';
    }

    public function compile(mixed $configuration, array $payload, Closure $compileTemplate): mixed
    {
        if (! is_array($configuration) || ! is_string($configuration['path'] ?? null)
            || ! array_key_exists('template', $configuration)) {
            throw new InvalidIntegrationDefinition('$map requires a path and template.');
        }

        $items = PayloadCompiler::valueAtPath($payload, $configuration['path']);

        if (! is_array($items)) {
            throw new InvalidIntegrationDefinition(
                'The $map path ['.$configuration['path'].'] must resolve to an array.',
            );
        }

        $mapped = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $mapped[] = $compileTemplate($configuration['template'], $item, $payload);
            }
        }

        return $mapped;
    }
}
