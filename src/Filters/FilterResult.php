<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Filters;

final class FilterResult
{
    /**
     * Stores whether the event passed filtering and the resulting payload.
     *
     * @param  array<string, mixed>  $payload  Payload after collection item filtering.
     */
    public function __construct(
        public readonly bool $matches,
        public readonly array $payload,
    ) {}
}
