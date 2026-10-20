<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Formatters\Formatters;

use Agung\EventIntegration\Contracts\ValueFormatter;
use Agung\EventIntegration\Exceptions\InvalidIntegrationDefinition;

final class IntegerFormatter implements ValueFormatter
{
    public function key(): string
    {
        return 'integer';
    }

    public function format(mixed $value, ?string $argument, array $scope, array $payload): ?int
    {
        if ($argument !== null) {
            throw new InvalidIntegrationDefinition('The integer formatter does not accept an argument.');
        }

        if ($value === null) {
            return null;
        }

        if (! is_numeric($value)) {
            throw new InvalidIntegrationDefinition('The integer formatter requires a numeric value.');
        }

        return (int) $value;
    }
}
