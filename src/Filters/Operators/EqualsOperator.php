<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Filters\Operators;

use Agung\EventIntegration\Contracts\FilterOperator;

final class EqualsOperator implements FilterOperator
{
    public function key(): string
    {
        return 'equals';
    }

    public function label(): string
    {
        return 'Equals';
    }

    public function matches(mixed $actual, mixed $expected): bool
    {
        return $actual === $expected || (is_scalar($actual) && is_scalar($expected)
            && (string) $actual === (string) $expected);
    }
}
