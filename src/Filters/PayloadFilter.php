<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Filters;

use Agung\EventIntegration\Events\IntegrationEvent;
use Agung\EventIntegration\Exceptions\InvalidFilterRule;
use Agung\EventIntegration\Fields\FieldDefinition;

final class PayloadFilter
{
    public function __construct(private readonly FilterOperatorRegistry $operators) {}

    /**
     * Applies all configured rules and removes collection items that fail their rules.
     *
     * @param  array<string, mixed>  $payload  Event payload to filter.
     * @param  list<array{field: string, operator: string, value?: mixed}>  $rules
     */
    public function apply(IntegrationEvent $event, array $payload, array $rules): FilterResult
    {
        $fields = $this->indexFields($event->fields);
        $collectionPaths = array_keys(array_filter(
            $fields,
            static fn (FieldDefinition $field): bool => $field->collection,
        ));
        $collectionRules = [];
        $eventRules = [];

        foreach ($rules as $rule) {
            $fieldPath = $rule['field'] ?? '';

            if (! is_string($fieldPath)) {
                throw new InvalidFilterRule('Filter fields must be strings.');
            }

            $field = $fields[$fieldPath] ?? null;

            if (! $field instanceof FieldDefinition || ! $field->filterable) {
                throw new InvalidFilterRule("Field [{$fieldPath}] is not registered as filterable.");
            }

            $operatorKey = $rule['operator'] ?? '';

            if (! is_string($operatorKey) || ! in_array($operatorKey, $field->operators, true)) {
                throw new InvalidFilterRule("Operator [{$operatorKey}] is not allowed for [{$fieldPath}].");
            }

            $compiledRule = [
                'field' => $fieldPath,
                'operator' => $this->operators->get($operatorKey),
                'value' => $rule['value'] ?? null,
            ];

            $collectionPath = null;

            foreach ($collectionPaths as $candidate) {
                if (str_starts_with($fieldPath, $candidate.'.')
                    && ($collectionPath === null || strlen($candidate) > strlen($collectionPath))) {
                    $collectionPath = $candidate;
                }
            }

            if ($collectionPath !== null) {
                $collectionRules[$collectionPath][] = $compiledRule;
            } else {
                $eventRules[] = $compiledRule;
            }
        }

        foreach ($eventRules as $rule) {
            $actual = self::valueAtPath($payload, $rule['field']);

            if (! $rule['operator']->matches($actual, $rule['value'])) {
                return new FilterResult(false, $payload);
            }
        }

        uksort($collectionRules, static fn (string $left, string $right): int => strlen($left) <=> strlen($right));

        foreach ($collectionRules as $collectionPath => $rulesForCollection) {
            $matchedAnyItem = false;
            $payload = $this->filterCollectionAtPath(
                $payload,
                explode('.', $collectionPath),
                $collectionPath,
                $rulesForCollection,
                $matchedAnyItem,
            );

            if (! $matchedAnyItem) {
                return new FilterResult(false, $payload);
            }
        }

        return new FilterResult(true, $payload);
    }

    /**
     * Indexes fields by their dot separated path, including nested collection fields.
     *
     * @param  list<FieldDefinition>  $definitions  Fields to index.
     * @return array<string, FieldDefinition> Fields keyed by payload path.
     */
    private function indexFields(array $definitions, string $prefix = ''): array
    {
        $indexed = [];

        foreach ($definitions as $definition) {
            $path = $prefix === '' ? $definition->name : "{$prefix}.{$definition->name}";
            $indexed[$path] = $definition;

            if ($definition->children !== []) {
                $indexed += $this->indexFields($definition->children, $path);
            }
        }

        return $indexed;
    }

    private static function valueAtPath(array $value, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Filters a declared collection, traversing parent objects and arrays when nested.
     *
     * @param  list<string>  $segments  Remaining path segments leading to the collection.
     * @param  list<array{field: string, operator: \Agung\EventIntegration\Contracts\FilterOperator,
     *     value: mixed}>  $rules  Rules applied to each item in the target collection.
     */
    private function filterCollectionAtPath(
        array $payload,
        array $segments,
        string $collectionPath,
        array $rules,
        bool &$matchedAnyItem,
    ): array {
        $segment = array_shift($segments);

        if ($segment === null || ! array_key_exists($segment, $payload) || ! is_array($payload[$segment])) {
            return $payload;
        }

        if ($segments !== []) {
            if (array_is_list($payload[$segment])) {
                foreach ($payload[$segment] as $index => $item) {
                    if (is_array($item)) {
                        $payload[$segment][$index] = $this->filterCollectionAtPath(
                            $item,
                            $segments,
                            $collectionPath,
                            $rules,
                            $matchedAnyItem,
                        );
                    }
                }
            } else {
                $payload[$segment] = $this->filterCollectionAtPath(
                    $payload[$segment],
                    $segments,
                    $collectionPath,
                    $rules,
                    $matchedAnyItem,
                );
            }

            return $payload;
        }

        $matchingItems = [];

        foreach ($payload[$segment] as $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach ($rules as $rule) {
                $fieldSuffix = substr($rule['field'], strlen($collectionPath) + 1);
                $actual = self::valueAtPath($item, $fieldSuffix);

                if (! $rule['operator']->matches($actual, $rule['value'])) {
                    continue 2;
                }
            }

            $matchingItems[] = $item;
            $matchedAnyItem = true;
        }

        $payload[$segment] = $matchingItems;

        return $payload;
    }
}
