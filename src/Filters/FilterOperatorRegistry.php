<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Filters;

use Agung\EventIntegration\Contracts\FilterOperator;
use Agung\EventIntegration\Exceptions\InvalidFilterRule;

final class FilterOperatorRegistry
{
    /**
     * Registered operators indexed by their stable key.
     *
     * @var array<string, FilterOperator>
     */
    private array $operators = [];

    /**
     * Initializes the registry with optional filter operators.
     *
     * @param  iterable<FilterOperator>  $operators  Operators available for filter evaluation.
     */
    public function __construct(iterable $operators = [])
    {
        foreach ($operators as $operator) {
            $this->register($operator);
        }
    }

    public function register(FilterOperator $operator): void
    {
        $this->operators[$operator->key()] = $operator;
    }

    public function get(string $key): FilterOperator
    {
        return $this->operators[$key] ?? throw new InvalidFilterRule(
            "Unknown filter operator [{$key}].",
        );
    }

    /**
     * Returns the operator keys and labels available to consumers.
     *
     * @return list<array{key: string, label: string}> Operator descriptions.
     */
    public function all(): array
    {
        return array_values(array_map(
            static fn (FilterOperator $operator): array => [
                'key' => $operator->key(),
                'label' => $operator->label(),
            ],
            $this->operators,
        ));
    }
}
