<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Engine;

use Agung\EventIntegration\Data\DispatchPlan;
use Agung\EventIntegration\Events\EventRegistry;
use Agung\EventIntegration\Exceptions\InvalidIntegrationDefinition;
use Agung\EventIntegration\Filters\PayloadFilter;

final class IntegrationEngine
{
    public function __construct(
        private readonly EventRegistry $events,
        private readonly PayloadFilter $filters,
        private readonly PayloadCompiler $compiler,
    ) {}

    /**
     * Resolves an event, applies filters, and prepares output messages for delivery.
     *
     * @param  list<array{field: string, operator: string, value?: mixed}>  $rules  Filter rules applied to the payload.
     * @param  array<string, mixed>|null  $outputFormat  Output template; null skips message compilation.
     */
    public function plan(
        string $eventName,
        mixed $source,
        array $rules = [],
        ?array $outputFormat = null,
    ): DispatchPlan {
        $event = $this->events->get($eventName);
        $payload = $this->resolvePayload($event->resolver, $source);
        $result = $this->filters->apply($event, $payload, $rules);
        $messages = $result->matches && $outputFormat !== null
            ? $this->compiler->compileMessages($outputFormat, $result->payload)
            : [];

        return new DispatchPlan($eventName, $result->matches, $result->payload, $messages);
    }

    /**
     * Resolves an event source into the array payload consumed by filters and templates.
     *
     * @return array<string, mixed> Resolved event payload.
     */
    private function resolvePayload(mixed $resolver, mixed $source): array
    {
        if ($resolver === null) {
            if (! is_array($source)) {
                throw new InvalidIntegrationDefinition(
                    'An event without a payload resolver requires an array source.',
                );
            }

            return $source;
        }

        if (is_string($resolver)) {
            if (! class_exists($resolver)) {
                throw new InvalidIntegrationDefinition(
                    "Payload resolver class [{$resolver}] does not exist.",
                );
            }

            $resolver = function_exists('app') ? app($resolver) : new $resolver;
        }

        if (is_callable($resolver)) {
            $payload = $resolver($source);
        } elseif (method_exists($resolver, 'resolve')) {
            $payload = $resolver->resolve($source);
        } else {
            throw new InvalidIntegrationDefinition(
                'Payload resolvers must be callable or implement EventPayloadResolver.',
            );
        }

        if (! is_array($payload)) {
            throw new InvalidIntegrationDefinition('Payload resolvers must return an array.');
        }

        return $payload;
    }
}
