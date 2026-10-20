<?php

namespace Agung\EventIntegration\Contracts;

interface FilterOperator
{
    /**
     * Returns the stable key used in filter rules.
     */
    public function key(): string;

    /**
     * Returns a label suitable for filter configuration interfaces.
     */
    public function label(): string;

    /**
     * Compares the payload value with the configured filter value.
     */
    public function matches(mixed $actual, mixed $expected): bool;
}
