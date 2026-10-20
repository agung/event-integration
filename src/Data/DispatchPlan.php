<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Data;

final class DispatchPlan
{
    /**
     * Captures the filter result and messages prepared for a dispatch attempt.
     *
     * @param  array<string, mixed>  $payload  Payload after applicable item filters.
     * @param  list<array<string, mixed>>  $messages  Compiled outbound message payloads.
     */
    public function __construct(
        public readonly string $event,
        public readonly bool $matches,
        public readonly array $payload,
        public readonly array $messages,
    ) {}

    /**
     * Indicates whether the plan contains at least one message eligible for dispatch.
     */
    public function shouldDispatch(): bool
    {
        return $this->matches && $this->messages !== [];
    }
}
