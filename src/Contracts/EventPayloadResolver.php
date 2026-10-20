<?php

namespace Agung\EventIntegration\Contracts;

interface EventPayloadResolver
{
    /**
     * Builds the delivery payload from an application event or domain object.
     *
     * @return array<string, mixed> The associative event payload.
     */
    public function resolve(mixed $source): array;
}
