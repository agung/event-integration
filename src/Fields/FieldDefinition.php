<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Fields;

use Agung\EventIntegration\Exceptions\InvalidIntegrationDefinition;

final class FieldDefinition
{
    /**
     * Creates a field schema definition for event payload discovery and filtering.
     *
     * @param  array<string, mixed>  $options  Display options for the field.
     * @param  list<string>  $operators  Filter operators allowed for this field.
     * @param  list<FieldDefinition>  $children  Nested field definitions.
     */
    private function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $label,
        public readonly array $options = [],
        public readonly array $operators = [],
        public readonly array $children = [],
        public readonly bool $filterable = false,
        public readonly bool $collection = false,
    ) {
        if ($name === '') {
            throw new InvalidIntegrationDefinition('Field names cannot be empty.');
        }
    }

    public static function make(string $name, string $type, ?string $label = null): self
    {
        return new self($name, $type, $label ?? self::humanize($name));
    }

    /**
     * Sets selectable values or other field metadata.
     *
     * @param  array<string, mixed>  $options  Field metadata to expose to consumers.
     */
    public function options(array $options): self
    {
        return new self(
            $this->name,
            $this->type,
            $this->label,
            $options,
            $this->operators,
            $this->children,
            $this->filterable,
            $this->collection,
        );
    }

    /**
     * Marks the field as filterable and limits its supported operators.
     *
     * @param  list<string>  $operators  Operator keys accepted for this field.
     */
    public function filterable(array $operators): self
    {
        return new self(
            $this->name,
            $this->type,
            $this->label,
            $this->options,
            array_values($operators),
            $this->children,
            true,
            $this->collection,
        );
    }

    /**
     * Defines nested fields for an object payload value.
     *
     * @param  list<FieldDefinition>  $children  Child field definitions.
     */
    public function object(array $children): self
    {
        return new self(
            $this->name,
            'object',
            $this->label,
            $this->options,
            $this->operators,
            $children,
            $this->filterable,
            false,
        );
    }

    /**
     * Defines nested fields for each value in a collection.
     *
     * @param  list<FieldDefinition>  $children  Fields available on each item.
     */
    public function collection(array $children): self
    {
        return new self(
            $this->name,
            'collection',
            $this->label,
            $this->options,
            $this->operators,
            $children,
            $this->filterable,
            true,
        );
    }

    /**
     * Serializes the schema for APIs or configuration interfaces.
     *
     * @return array<string, mixed> The field schema and its nested children.
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'label' => $this->label,
            'options' => $this->options,
            'filterable' => $this->filterable,
            'operators' => $this->operators,
            'collection' => $this->collection,
            'children' => array_map(
                static fn (self $field): array => $field->toArray(),
                $this->children,
            ),
        ];
    }

    private static function humanize(string $name): string
    {
        return ucfirst(str_replace(['_', '.'], ' ', $name));
    }
}
