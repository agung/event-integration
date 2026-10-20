<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Filters\Operators;

use Agung\EventIntegration\Contracts\FilterOperator;

final class GreaterThanOperator implements FilterOperator
{
    public function key(): string
    {
        return 'greater_than';
    }

    public function label(): string
    {
        return 'Greater than';
    }

    public function matches(mixed $actual, mixed $expected): bool
    {
        return is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected;
    }
}
