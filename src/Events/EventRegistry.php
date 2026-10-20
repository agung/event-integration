<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Events;

use Agung\EventIntegration\Exceptions\UnknownIntegrationEvent;

final class EventRegistry
{
    /**
     * Registered events indexed by their public event name.
     *
     * @var array<string, IntegrationEvent>
     */
    private array $events = [];

    /**
     * Initializes the registry with optional event definitions.
     *
     * @param  iterable<IntegrationEvent>  $events  Events available to the registry.
     */
    public function __construct(iterable $events = [])
    {
        foreach ($events as $event) {
            $this->register($event);
        }
    }

    public function register(IntegrationEvent $event): void
    {
        $this->events[$event->name] = $event;
    }

    public function get(string $name): IntegrationEvent
    {
        return $this->events[$name] ?? throw new UnknownIntegrationEvent($name);
    }

    /**
     * Returns all registered event definitions.
     *
     * @return list<IntegrationEvent> Registered events in insertion order.
     */
    public function all(): array
    {
        return array_values($this->events);
    }
}
