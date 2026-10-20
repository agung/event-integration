<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Formatters\Formatters;

use Agung\EventIntegration\Contracts\ValueFormatter;
use Agung\EventIntegration\Exceptions\InvalidIntegrationDefinition;

final class FloatFormatter implements ValueFormatter
{
    public function key(): string
    {
        return 'float';
    }

    public function format(mixed $value, ?string $argument, array $scope, array $payload): ?float
    {
        if ($argument !== null) {
            throw new InvalidIntegrationDefinition('The float formatter does not accept an argument.');
        }

        if ($value === null) {
            return null;
        }

        if (! is_numeric($value)) {
            throw new InvalidIntegrationDefinition('The float formatter requires a numeric value.');
        }

        return (float) $value;
    }
}
