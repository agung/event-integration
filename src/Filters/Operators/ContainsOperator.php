<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Filters\Operators;

use Agung\EventIntegration\Contracts\FilterOperator;

final class ContainsOperator implements FilterOperator
{
    public function key(): string
    {
        return 'contains';
    }

    public function label(): string
    {
        return 'Contains';
    }

    public function matches(mixed $actual, mixed $expected): bool
    {
        return is_string($actual) && is_scalar($expected)
            && str_contains(mb_strtolower($actual), mb_strtolower((string) $expected));
    }
}
