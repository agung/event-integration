<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Contracts;

interface ValueFormatter
{
    /**
     * Returns the name used to call this formatter in a placeholder pipeline.
     */
    public function key(): string;

    /**
     * Formats a resolved placeholder value.
     *
     * @param  array<string, mixed>  $scope  Current template scope.
     * @param  array<string, mixed>  $payload  Original event payload.
     */
    public function format(
        mixed $value,
        ?string $argument,
        array $scope,
        array $payload,
    ): mixed;
}
