<?php

namespace Agung\EventIntegration\Contracts;

use Closure;

interface OutputFormatTemplate
{
    public function key(): string;

    /**
     * Compiles a custom output directive using the event payload and recursive compiler.
     *
     * @param  array<string, mixed>  $payload  Original event payload.
     * @param  Closure  $compileTemplate
     *                                    Callback that compiles nested templates with an item scope and root payload.
     */
    public function compile(mixed $configuration, array $payload, Closure $compileTemplate): mixed;
}
