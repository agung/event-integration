<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Events;

use Agung\EventIntegration\Contracts\EventPayloadResolver;
use Agung\EventIntegration\Exceptions\InvalidIntegrationDefinition;
use Agung\EventIntegration\Fields\FieldDefinition;

final class IntegrationEvent
{
    /**
     * Creates an event definition with an optional display label.
     *
     * @param  list<FieldDefinition>  $fields  Payload field definitions exposed to consumers.
     */
    private function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly mixed $resolver,
        public readonly array $fields = [],
    ) {
        if ($name === '') {
            throw new InvalidIntegrationDefinition('Event names cannot be empty.');
        }
    }

    public static function make(string $name, ?string $label = null): self
    {
        return new self($name, $label ?? $name, null);
    }

    public function payloadUsing(string|EventPayloadResolver|callable $resolver): self
    {
        return new self($this->name, $this->label, $resolver, $this->fields);
    }

    /**
     * Declares the payload fields available for formatting and filtering.
     *
     * @param  list<FieldDefinition>  $fields  Field definitions for this event payload.
     */
    public function fields(array $fields): self
    {
        return new self($this->name, $this->label, $this->resolver, $fields);
    }

    /**
     * Returns the declared payload fields.
     *
     * @return list<FieldDefinition> The event payload field definitions.
     */
    public function fieldDefinitions(): array
    {
        return $this->fields;
    }
}
