<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Filters\Operators;

use Agung\EventIntegration\Contracts\FilterOperator;

final class IsOneOfOperator implements FilterOperator
{
    public function key(): string
    {
        return 'is_one_of';
    }

    public function label(): string
    {
        return 'Is one of';
    }

    public function matches(mixed $actual, mixed $expected): bool
    {
        if (is_string($expected)) {
            $decoded = json_decode($expected, true);
            $expected = is_array($decoded) ? $decoded : [$expected];
        }

        if (! is_array($expected)) {
            $expected = [$expected];
        }

        foreach ($expected as $value) {
            if ($actual === $value || (is_scalar($actual) && is_scalar($value)
                && (string) $actual === (string) $value)) {
                return true;
            }
        }

        return false;
    }
}
