<?php

declare(strict_types=1);

use Agung\EventIntegration\Contracts\EventPayloadResolver;
use Agung\EventIntegration\Contracts\FilterOperator;
use Agung\EventIntegration\Contracts\OutputFormatTemplate;
use Agung\EventIntegration\Contracts\ValueFormatter;
use Agung\EventIntegration\Engine\IntegrationEngine;
use Agung\EventIntegration\Engine\PayloadCompiler;
use Agung\EventIntegration\Events\EventRegistry;
use Agung\EventIntegration\Events\IntegrationEvent;
use Agung\EventIntegration\Exceptions\InvalidIntegrationDefinition;
use Agung\EventIntegration\Fields\FieldDefinition;
use Agung\EventIntegration\Filters\FilterOperatorRegistry;
use Agung\EventIntegration\Filters\Operators\ContainsOperator;
use Agung\EventIntegration\Filters\Operators\EqualsOperator;
use Agung\EventIntegration\Filters\Operators\GreaterThanOperator;
use Agung\EventIntegration\Filters\Operators\IsEmptyOperator;
use Agung\EventIntegration\Filters\Operators\IsNotEmptyOperator;
use Agung\EventIntegration\Filters\Operators\IsOneOfOperator;
use Agung\EventIntegration\Filters\Operators\LessThanOperator;
use Agung\EventIntegration\Filters\PayloadFilter;
use Agung\EventIntegration\Formatters\FormatterRegistry;
use Agung\EventIntegration\Templates\EachTemplate;
use Agung\EventIntegration\Templates\MapTemplate;
use Agung\EventIntegration\Templates\MaxTemplate;
use Agung\EventIntegration\Templates\TemplateRegistry;

function makeEngine(IntegrationEvent ...$events): IntegrationEngine
{
    $operators = new FilterOperatorRegistry([
        new EqualsOperator,
        new IsOneOfOperator,
        new ContainsOperator,
        new GreaterThanOperator,
        new LessThanOperator,
        new IsEmptyOperator,
        new IsNotEmptyOperator,
    ]);
    $templates = new TemplateRegistry([
        new EachTemplate,
        new MapTemplate,
        new MaxTemplate,
    ]);

    return new IntegrationEngine(
        new EventRegistry($events),
        new PayloadFilter($operators),
        new PayloadCompiler($templates),
    );
}

function transferEvent(): IntegrationEvent
{
    return IntegrationEvent::make('transfer.settled')
        ->fields([
            FieldDefinition::make('transfer_id', 'string')->filterable(['equals']),
            FieldDefinition::make('items', 'collection')->collection([
                FieldDefinition::make('item_type', 'enum')
                    ->options(['commission' => 'Commission', 'fee' => 'Fee'])
                    ->filterable(['equals', 'is_one_of']),
                FieldDefinition::make('amount', 'number')->filterable(['greater_than', 'less_than']),
                FieldDefinition::make('order_id', 'string')->filterable(['equals']),
            ]),
        ]);
}

it('expands each into one outbound message per matching collection item', function (): void {
    $engine = makeEngine(transferEvent());
    $payload = [
        'transfer_id' => 'tr_123',
        'items' => [
            ['item_type' => 'commission', 'order_id' => 'order_1', 'amount' => 120],
            ['item_type' => 'fee', 'order_id' => 'order_2', 'amount' => 10],
            ['item_type' => 'commission', 'order_id' => 'order_3', 'amount' => 80],
        ],
    ];

    $plan = $engine->plan(
        'transfer.settled',
        $payload,
        [['field' => 'items.item_type', 'operator' => 'equals', 'value' => 'commission']],
        [
            'request_id' => '{{ transfer_id }}',
            'order' => [
                '$each' => [
                    'path' => 'items',
                    'template' => ['order_id' => '{{ order_id }}', 'amount' => '{{ amount }}'],
                ],
            ],
        ],
    );

    expect($plan->shouldDispatch())->toBeTrue()
        ->and($plan->payload['items'])->toHaveCount(2)
        ->and($plan->messages)->toBe([
            ['request_id' => 'tr_123', 'order' => ['order_id' => 'order_1', 'amount' => 120]],
            ['request_id' => 'tr_123', 'order' => ['order_id' => 'order_3', 'amount' => 80]],
        ]);
});

it('supports map and max templates while retaining typed placeholders', function (): void {
    $engine = makeEngine(transferEvent());
    $payload = [
        'transfer_id' => 'tr_456',
        'items' => [
            ['item_type' => 'commission', 'order_id' => 'order_1', 'amount' => 120],
            ['item_type' => 'commission', 'order_id' => 'order_2', 'amount' => 80],
        ],
    ];

    $plan = $engine->plan('transfer.settled', $payload, [], [
        'all_orders' => [
            '$map' => [
                'path' => 'items',
                'template' => ['id' => '{{ order_id }}', 'amount' => '{{ amount }}'],
            ],
        ],
        'largest' => [
            '$max' => [
                'path' => 'items',
                'by' => 'amount',
                'template' => ['order_id' => '{{ order_id }}', 'amount' => '{{ amount }}'],
            ],
        ],
    ]);

    expect($plan->messages)->toBe([[
        'all_orders' => [
            ['id' => 'order_1', 'amount' => 120],
            ['id' => 'order_2', 'amount' => 80],
        ],
        'largest' => ['order_id' => 'order_1', 'amount' => 120],
    ]]);
});

it('applies built-in formatter pipelines and resolves fallback fields', function (): void {
    $plan = makeEngine(transferEvent())->plan('transfer.settled', [
        'amount' => '',
        'fallback_amount' => 12500,
        'zero_amount' => 0,
        'false_value' => false,
        'fallback_value' => true,
        'rate' => '1.25',
    ], [], [
        'amount' => '{{ amount | default(fallback_amount) | string }}',
        'zero_amount' => '{{ zero_amount | default(fallback_amount) }}',
        'false_value' => '{{ false_value | default(fallback_value) }}',
        'rate' => '{{ rate | float }}',
        'embedded' => 'amount={{ fallback_amount | integer }}',
    ]);

    expect($plan->messages)->toBe([[
        'amount' => '12500',
        'zero_amount' => 0,
        'false_value' => false,
        'rate' => 1.25,
        'embedded' => 'amount=12500',
    ]]);
});

it('filters nested collections without flattening their parent items', function (): void {
    $event = IntegrationEvent::make('order.settled')->fields([
        FieldDefinition::make('orders', 'collection')->collection([
            FieldDefinition::make('order_id', 'string'),
            FieldDefinition::make('adjustments', 'collection')->collection([
                FieldDefinition::make('type', 'enum')->filterable(['equals']),
            ]),
        ]),
    ]);
    $plan = makeEngine($event)->plan(
        'order.settled',
        [
            'orders' => [
                [
                    'order_id' => 'order_1',
                    'adjustments' => [
                        ['type' => 'commission'],
                        ['type' => 'fee'],
                    ],
                ],
                [
                    'order_id' => 'order_2',
                    'adjustments' => [['type' => 'fee']],
                ],
            ],
        ],
        [['field' => 'orders.adjustments.type', 'operator' => 'equals', 'value' => 'commission']],
        ['orders' => '{{ orders }}'],
    );

    expect($plan->matches)->toBeTrue()
        ->and($plan->messages)->toBe([[
            'orders' => [
                ['order_id' => 'order_1', 'adjustments' => [['type' => 'commission']]],
                ['order_id' => 'order_2', 'adjustments' => []],
            ],
        ]]);
});

it('rejects output formats that exceed compiler limits', function (): void {
    $compiler = new PayloadCompiler(
        new TemplateRegistry([new EachTemplate(1)]),
        maximumMessages: 1,
    );

    expect(fn () => $compiler->compileMessages(
        ['$each' => ['path' => 'items', 'template' => ['id' => '{{ id }}']]],
        ['items' => [['id' => 1], ['id' => 2]]],
    ))->toThrow(InvalidIntegrationDefinition::class, 'maximum message count');
});

it('rejects unknown formatters and non-numeric conversion values', function (): void {
    $compiler = new PayloadCompiler(new TemplateRegistry);

    expect(fn () => $compiler->compileMessages(
        ['code' => '{{ value | missing }}'],
        ['value' => 'text'],
    ))->toThrow(InvalidIntegrationDefinition::class, 'Unknown output formatter [missing]');

    expect(fn () => $compiler->compileMessages(
        ['rate' => '{{ value | float }}'],
        ['value' => 'not-numeric'],
    ))->toThrow(InvalidIntegrationDefinition::class, 'requires a numeric value');
});

it('supports custom value formatters registered in the formatter registry', function (): void {
    $formatter = new class implements ValueFormatter
    {
        public function key(): string
        {
            return 'uppercase';
        }

        public function format(mixed $value, ?string $argument, array $scope, array $payload): string
        {
            return strtoupper((string) $value);
        }
    };
    $compiler = new PayloadCompiler(
        new TemplateRegistry,
        new FormatterRegistry([$formatter]),
    );
    $engine = new IntegrationEngine(
        new EventRegistry([IntegrationEvent::make('sample.created')]),
        new PayloadFilter(new FilterOperatorRegistry),
        $compiler,
    );
    $plan = $engine->plan('sample.created', ['code' => 'abc-123'], [], [
        'code' => '{{ code | uppercase }}',
    ]);

    expect($plan->messages)->toBe([['code' => 'ABC-123']]);
});

it('skips dispatch when non-collection filter rules do not match', function (): void {
    $engine = makeEngine(transferEvent());
    $plan = $engine->plan(
        'transfer.settled',
        ['transfer_id' => 'tr_123', 'items' => [['item_type' => 'fee']]],
        [['field' => 'transfer_id', 'operator' => 'equals', 'value' => 'tr_other']],
        ['transfer_id' => '{{ transfer_id }}'],
    );

    expect($plan->matches)->toBeFalse()
        ->and($plan->shouldDispatch())->toBeFalse()
        ->and($plan->messages)->toBe([]);
});

it('resolves event payloads through a custom resolver', function (): void {
    $resolver = new class implements EventPayloadResolver
    {
        public function resolve(mixed $source): array
        {
            return ['reference_id' => $source['id'], 'status' => 'settled'];
        }
    };
    $event = IntegrationEvent::make('transaction.settled')
        ->payloadUsing($resolver)
        ->fields([FieldDefinition::make('reference_id', 'string')]);
    $plan = makeEngine($event)->plan(
        'transaction.settled',
        ['id' => 'tx_1'],
        [],
        ['reference_id' => '{{ reference_id }}', 'status' => '{{ status }}'],
    );

    expect($plan->payload)->toBe(['reference_id' => 'tx_1', 'status' => 'settled'])
        ->and($plan->messages)->toBe([['reference_id' => 'tx_1', 'status' => 'settled']]);
});

it('allows host applications to register custom operators and templates', function (): void {
    $operator = new class implements FilterOperator
    {
        public function key(): string
        {
            return 'starts_with';
        }

        public function label(): string
        {
            return 'Starts with';
        }

        public function matches(mixed $actual, mixed $expected): bool
        {
            return is_string($actual) && is_string($expected) && str_starts_with($actual, $expected);
        }
    };
    $template = new class implements OutputFormatTemplate
    {
        public function key(): string
        {
            return '$constant';
        }

        public function compile(mixed $configuration, array $payload, Closure $compileTemplate): mixed
        {
            return ['value' => $compileTemplate($configuration, $payload, $payload)];
        }
    };
    $operators = new FilterOperatorRegistry([new EqualsOperator, $operator]);
    $templates = new TemplateRegistry([$template]);
    $event = IntegrationEvent::make('sample.created')
        ->fields([FieldDefinition::make('code', 'string')->filterable(['starts_with'])]);
    $engine = new IntegrationEngine(
        new EventRegistry([$event]),
        new PayloadFilter($operators),
        new PayloadCompiler($templates),
    );
    $plan = $engine->plan(
        'sample.created',
        ['code' => 'abc-123'],
        [['field' => 'code', 'operator' => 'starts_with', 'value' => 'abc']],
        ['wrapped' => ['$constant' => '{{ code }}']],
    );

    expect($plan->messages)->toBe([['wrapped' => ['value' => 'abc-123']]]);
});
