<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Filters\Operators;

use Agung\EventIntegration\Contracts\FilterOperator;

final class IsNotEmptyOperator implements FilterOperator
{
    public function key(): string
    {
        return 'is_not_empty';
    }

    public function label(): string
    {
        return 'Is not empty';
    }

    public function matches(mixed $actual, mixed $expected): bool
    {
        return ! ($actual === null || $actual === '' || $actual === []);
    }
}
