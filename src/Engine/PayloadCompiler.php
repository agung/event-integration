<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Engine;

use Agung\EventIntegration\Exceptions\InvalidIntegrationDefinition;
use Agung\EventIntegration\Formatters\FormatterRegistry;
use Agung\EventIntegration\Formatters\Formatters\DefaultFormatter;
use Agung\EventIntegration\Formatters\Formatters\FloatFormatter;
use Agung\EventIntegration\Formatters\Formatters\IntegerFormatter;
use Agung\EventIntegration\Formatters\Formatters\StringFormatter;
use Agung\EventIntegration\Templates\CompiledMessages;
use Agung\EventIntegration\Templates\TemplateRegistry;
use Closure;

final class PayloadCompiler
{
    private readonly FormatterRegistry $formatters;

    private int $expansionDepth = 0;

    public function __construct(
        private readonly TemplateRegistry $templates,
        ?FormatterRegistry $formatters = null,
        private readonly int $maximumMessages = 1000,
        private readonly int $maximumDepth = 64,
    ) {
        if ($maximumMessages < 1 || $maximumDepth < 1) {
            throw new InvalidIntegrationDefinition('Compiler limits must be positive integers.');
        }

        $this->formatters = $formatters ?? new FormatterRegistry([
            new DefaultFormatter,
            new StringFormatter,
            new IntegerFormatter,
            new FloatFormatter,
        ]);
    }

    /**
     * Compiles an output format into one or more outbound message objects.
     *
     * @param  array<string, mixed>  $payload  Source payload used by the format.
     * @return list<array<string, mixed>> Compiled messages ready for delivery.
     */
    public function compileMessages(mixed $format, array $payload): array
    {
        $expanded = $this->expand($format, $payload, $payload);

        return array_map(static function (mixed $message): array {
            if (! is_array($message)) {
                throw new InvalidIntegrationDefinition('Each output message must compile to an object.');
            }

            return $message;
        }, $expanded);
    }

    public static function valueAtPath(array $payload, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (! is_array($payload) || ! array_key_exists($segment, $payload)) {
                return null;
            }

            $payload = $payload[$segment];
        }

        return $payload;
    }

    /**
     * Resolves a format value and expands nested each templates into combinations.
     *
     * @param  array<string, mixed>  $rootPayload  Original payload used by templates.
     * @param  array<string, mixed>  $scope  Current interpolation scope.
     * @return list<mixed> Compiled variants for the current value.
     */
    private function expand(mixed $value, array $rootPayload, array $scope): array
    {
        $this->expansionDepth++;

        try {
            if ($this->expansionDepth > $this->maximumDepth) {
                throw new InvalidIntegrationDefinition('Output format exceeds the maximum template depth.');
            }

            return $this->expandValue($value, $rootPayload, $scope);
        } finally {
            $this->expansionDepth--;
        }
    }

    /**
     * Expands a value after the recursive depth limit has been checked.
     *
     * @param  array<string, mixed>  $rootPayload  Original payload used by templates.
     * @param  array<string, mixed>  $scope  Current interpolation scope.
     * @return list<mixed> Compiled variants for the current value.
     */
    private function expandValue(mixed $value, array $rootPayload, array $scope): array
    {
        if (is_string($value)) {
            return [$this->interpolate($value, $scope, $rootPayload)];
        }

        if (! is_array($value)) {
            return [$value];
        }

        if (count($value) === 1 && is_string(array_key_first($value))
            && str_starts_with(array_key_first($value), '$')) {
            $template = $this->templates->get(array_key_first($value));
            $compiled = $template->compile(
                current($value),
                $rootPayload,
                Closure::fromCallable([$this, 'compileNestedTemplate']),
            );

            $messages = $compiled instanceof CompiledMessages ? $compiled->messages : [$compiled];

            if (count($messages) > $this->maximumMessages) {
                throw new InvalidIntegrationDefinition('Output format exceeds the maximum message count.');
            }

            return $messages;
        }

        $combinations = [[]];

        foreach ($value as $key => $child) {
            $childValues = $this->expand($child, $rootPayload, $scope);
            $next = [];

            foreach ($combinations as $combination) {
                foreach ($childValues as $childValue) {
                    $next[] = [...$combination, $key => $childValue];

                    if (count($next) > $this->maximumMessages) {
                        throw new InvalidIntegrationDefinition(
                            'Output format exceeds the maximum message count.',
                        );
                    }
                }
            }

            $combinations = $next;
        }

        return $combinations;
    }

    /**
     * Converts a list of compiled values into a scalar or array result.
     *
     * @param  list<mixed>  $values  Compiled values to collapse.
     */
    private function collapse(array $values): mixed
    {
        return count($values) === 1 ? $values[0] : $values;
    }

    /**
     * Compiles a nested template using its item scope and the original payload.
     *
     * @param  array<string, mixed>  $scope  Current item scope.
     * @param  array<string, mixed>  $rootPayload  Original event payload.
     */
    private function compileNestedTemplate(
        mixed $template,
        array $scope,
        array $rootPayload,
    ): mixed {
        return $this->collapse($this->expand($template, $rootPayload, $scope));
    }

    /**
     * Interpolates a string against the current item and root payload.
     *
     * @param  array<string, mixed>  $scope  Current interpolation scope.
     * @param  array<string, mixed>  $rootPayload  Original event payload.
     */
    private function interpolate(string $template, array $scope, array $rootPayload): mixed
    {
        if (preg_match('/^\{\{\s*(.*?)\s*\}\}$/s', $template, $match) === 1) {
            return $this->resolvePlaceholder($match[1], $scope, $rootPayload);
        }

        return preg_replace_callback(
            '/\{\{\s*(.*?)\s*\}\}/',
            function (array $match) use ($scope, $rootPayload): string {
                $value = $this->resolvePlaceholder($match[1], $scope, $rootPayload);

                return is_scalar($value) ? (string) $value : '';
            },
            $template,
        ) ?? $template;
    }

    /**
     * Resolves a placeholder path and applies its formatter pipeline in order.
     *
     * @param  array<string, mixed>  $scope  Current template scope.
     * @param  array<string, mixed>  $rootPayload  Original event payload.
     */
    private function resolvePlaceholder(string $expression, array $scope, array $rootPayload): mixed
    {
        $segments = preg_split('/\s*\|\s*/', trim($expression));
        $path = array_shift($segments);

        if (! is_string($path) || preg_match('/^[\w.\-]+$/', $path) !== 1) {
            throw new InvalidIntegrationDefinition("Invalid output placeholder [{$expression}].");
        }

        $value = self::valueAtPath($scope, $path) ?? self::valueAtPath($rootPayload, $path);

        foreach ($segments as $segment) {
            if (preg_match('/^([a-zA-Z_][\w-]*)(?:\(([^()]*)\))?$/', trim($segment), $match) !== 1) {
                throw new InvalidIntegrationDefinition("Invalid output formatter [{$segment}].");
            }

            $argument = isset($match[2]) ? trim($match[2]) : null;

            if ($argument !== null && preg_match('/^(".*"|\'.*\')$/s', $argument) === 1) {
                $argument = substr($argument, 1, -1);
            }

            $value = $this->formatters->get($match[1])->format(
                $value,
                $argument,
                $scope,
                $rootPayload,
            );
        }

        return $value;
    }
}
