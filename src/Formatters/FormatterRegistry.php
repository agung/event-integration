<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Formatters;

use Agung\EventIntegration\Contracts\ValueFormatter;
use Agung\EventIntegration\Exceptions\InvalidIntegrationDefinition;

final class FormatterRegistry
{
    /**
     * Registered formatters indexed by their pipeline key.
     *
     * @var array<string, ValueFormatter>
     */
    private array $formatters = [];

    /**
     * Initializes the registry with optional value formatters.
     *
     * @param  iterable<ValueFormatter>  $formatters  Formatters available to output placeholders.
     */
    public function __construct(iterable $formatters = [])
    {
        foreach ($formatters as $formatter) {
            $this->register($formatter);
        }
    }

    /**
     * Registers or replaces a formatter for its pipeline key.
     */
    public function register(ValueFormatter $formatter): void
    {
        if ($formatter->key() === '') {
            throw new InvalidIntegrationDefinition('Formatter keys cannot be empty.');
        }

        $this->formatters[$formatter->key()] = $formatter;
    }

    /**
     * Returns the formatter registered for the requested key.
     */
    public function get(string $key): ValueFormatter
    {
        return $this->formatters[$key] ?? throw new InvalidIntegrationDefinition(
            "Unknown output formatter [{$key}].",
        );
    }
}
