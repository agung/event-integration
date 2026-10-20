<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Filters\Operators;

use Agung\EventIntegration\Contracts\FilterOperator;

final class IsEmptyOperator implements FilterOperator
{
    public function key(): string
    {
        return 'is_empty';
    }

    public function label(): string
    {
        return 'Is empty';
    }

    public function matches(mixed $actual, mixed $expected): bool
    {
        return $actual === null || $actual === '' || $actual === [];
    }
}
