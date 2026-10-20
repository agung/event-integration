<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Templates;

/**
 * Marks a template result that expands into separate outbound messages.
 *
 * @internal
 */
final class CompiledMessages
{
    /**
     * Stores compiled variants until the enclosing output format is expanded.
     *
     * @param  list<mixed>  $messages  Values emitted by an expanding template.
     */
    public function __construct(public readonly array $messages) {}
}
