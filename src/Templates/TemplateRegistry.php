<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Templates;

use Agung\EventIntegration\Contracts\OutputFormatTemplate;
use Agung\EventIntegration\Exceptions\InvalidIntegrationDefinition;

final class TemplateRegistry
{
    /**
     * Registered templates indexed by their directive key.
     *
     * @var array<string, OutputFormatTemplate>
     */
    private array $templates = [];

    /**
     * Initializes the registry with optional output template implementations.
     *
     * @param  iterable<OutputFormatTemplate>  $templates  Templates available to the compiler.
     */
    public function __construct(iterable $templates = [])
    {
        foreach ($templates as $template) {
            $this->register($template);
        }
    }

    public function register(OutputFormatTemplate $template): void
    {
        if ($template->key() === '') {
            throw new InvalidIntegrationDefinition('Template keys cannot be empty.');
        }

        $this->templates[$template->key()] = $template;
    }

    public function get(string $key): OutputFormatTemplate
    {
        return $this->templates[$key] ?? throw new InvalidIntegrationDefinition(
            "Unknown output template [{$key}].",
        );
    }

    /**
     * Returns all registered template implementations.
     *
     * @return list<OutputFormatTemplate> Registered templates in insertion order.
     */
    public function all(): array
    {
        return array_values($this->templates);
    }
}
