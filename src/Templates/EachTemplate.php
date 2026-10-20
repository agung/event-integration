<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Templates;

use Agung\EventIntegration\Contracts\OutputFormatTemplate;
use Agung\EventIntegration\Engine\PayloadCompiler;
use Agung\EventIntegration\Exceptions\InvalidIntegrationDefinition;
use Closure;

final class EachTemplate implements OutputFormatTemplate
{
    public function __construct(private readonly int $maximumMessages = 1000) {}

    public function key(): string
    {
        return '$each';
    }

    public function compile(mixed $configuration, array $payload, Closure $compileTemplate): mixed
    {
        if (! is_array($configuration) || ! is_string($configuration['path'] ?? null)
            || ! array_key_exists('template', $configuration)) {
            throw new InvalidIntegrationDefinition('$each requires a path and template.');
        }

        $items = PayloadCompiler::valueAtPath($payload, $configuration['path']);

        if (! is_array($items)) {
            throw new InvalidIntegrationDefinition(
                'The $each path ['.$configuration['path'].'] must resolve to an array.',
            );
        }

        $messages = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $messages[] = $compileTemplate($configuration['template'], $item, $payload);

            if (count($messages) > $this->maximumMessages) {
                throw new InvalidIntegrationDefinition('The $each template exceeds the maximum message count.');
            }
        }

        return new CompiledMessages($messages);
    }
}
