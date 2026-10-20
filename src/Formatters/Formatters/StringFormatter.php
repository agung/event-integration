<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Formatters\Formatters;

use Agung\EventIntegration\Contracts\ValueFormatter;
use Agung\EventIntegration\Exceptions\InvalidIntegrationDefinition;

final class StringFormatter implements ValueFormatter
{
    public function key(): string
    {
        return 'string';
    }

    public function format(mixed $value, ?string $argument, array $scope, array $payload): string
    {
        if ($argument !== null) {
            throw new InvalidIntegrationDefinition('The string formatter does not accept an argument.');
        }

        if ($value === null) {
            return '';
        }

        if (! is_scalar($value) && ! $value instanceof \Stringable) {
            throw new InvalidIntegrationDefinition('The string formatter requires a scalar value.');
        }

        return (string) $value;
    }
}
