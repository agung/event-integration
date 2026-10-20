<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Formatters\Formatters;

use Agung\EventIntegration\Contracts\ValueFormatter;
use Agung\EventIntegration\Engine\PayloadCompiler;
use Agung\EventIntegration\Exceptions\InvalidIntegrationDefinition;

final class DefaultFormatter implements ValueFormatter
{
    public function key(): string
    {
        return 'default';
    }

    public function format(mixed $value, ?string $argument, array $scope, array $payload): mixed
    {
        if ($argument === null || $argument === '') {
            throw new InvalidIntegrationDefinition('The default formatter requires a fallback field path.');
        }

        if ($value !== null && $value !== '') {
            return $value;
        }

        return PayloadCompiler::valueAtPath($scope, $argument)
            ?? PayloadCompiler::valueAtPath($payload, $argument);
    }
}
