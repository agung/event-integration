# Event Integration

A Laravel package for registering integration events, filtering their payloads, compiling custom output formats, and publishing activities to webhook or RabbitMQ destinations.

## Requirements

- PHP 8.3 or later
- Laravel 12 or 13
- A RabbitMQ connection when using the `rabbitmq` transport

## Installation

Install the package through Composer. Laravel discovers `EventIntegrationServiceProvider` automatically.

Publish the package configuration and migration:

```shell
php artisan vendor:publish --tag=event-integration-config
php artisan vendor:publish --tag=event-integration-migrations
php artisan migrate
```

The config file is `config/event-integration.php`. It provides webhook security and timeout settings, compiler limits, retry backoff and lease duration, activity retention, and named RabbitMQ connection settings.

## Registering an event

Register event definitions from an application service provider. A resolver can map an application model or event object into the payload exposed to filters and output templates.

```php
use App\Models\Transfer;
use Illuminate\Support\ServiceProvider;
use Agung\EventIntegration\Events\EventRegistry;
use Agung\EventIntegration\Events\IntegrationEvent;
use Agung\EventIntegration\Fields\FieldDefinition;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(EventRegistry $events): void
    {
        $events->register(
            IntegrationEvent::make('transfer.settled')
                ->payloadUsing(static fn (Transfer $transfer): array => [
                    'transfer_id' => $transfer->id,
                    'items' => $transfer->items->map(static fn ($item): array => [
                        'order_id' => $item->order_id,
                        'amount' => $item->amount,
                        'item_type' => $item->item_type,
                    ])->all(),
                ])
                ->fields([
                    FieldDefinition::make('transfer_id', 'string')
                        ->filterable(['equals']),
                    FieldDefinition::make('items', 'collection')->collection([
                        FieldDefinition::make('order_id', 'string')->filterable(['equals']),
                        FieldDefinition::make('amount', 'number')
                            ->filterable(['greater_than', 'less_than']),
                        FieldDefinition::make('item_type', 'enum')
                            ->options(['commission' => 'Commission', 'fee' => 'Fee'])
                            ->filterable(['equals', 'is_one_of']),
                    ]),
                ]),
        );
    }
}
```

All configured filter rules must match. Collection field rules filter individual items and the event is skipped if no item remains.

## Compiling output formats

`IntegrationEngine::plan()` applies filters and compiles the destination payload without publishing it. This can be used to preview an output format.

```php
use Agung\EventIntegration\Engine\IntegrationEngine;

$plan = app(IntegrationEngine::class)->plan(
    eventName: 'transfer.settled',
    source: $transfer,
    rules: [
        ['field' => 'items.item_type', 'operator' => 'equals', 'value' => 'commission'],
    ],
    outputFormat: [
        'request_id' => '{{ transfer_id }}',
        'order' => [
            '$each' => [
                'path' => 'items',
                'template' => [
                    'order_id' => '{{ order_id }}',
                    'amount' => '{{ amount }}',
                ],
            ],
        ],
    ],
);

$plan->matches; // Whether the event passed its filters.
$plan->messages; // Compiled payloads; $each creates one payload per item.
```

### Value formatters

Placeholders can apply a formatter pipeline with `|`. The built-in `string`, `integer`, and `float` formatters convert scalar values. `default(path)` uses another payload field when the current value is `null` or an empty string; values such as `0` and `false` are preserved. Pipelines run from left to right:

```php
[
    'amount' => '{{ amount | default(fallback_amount) | string }}',
    'rate' => '{{ rate | float }}',
]
```

When a placeholder occupies the entire output value, the compiler preserves its resulting type. When it is embedded in other text, the result is converted to a string. Float conversion uses PHP floating point and can lose decimal precision, so use string values for money amounts.

Applications can add formatters by implementing `ValueFormatter` and registering it with `FormatterRegistry`:

```php
namespace App\Integration\Formatters;

use Agung\EventIntegration\Contracts\ValueFormatter;

final class UppercaseFormatter implements ValueFormatter
{
    public function key(): string
    {
        return 'uppercase';
    }

    public function format(mixed $value, ?string $argument, array $scope, array $payload): string
    {
        return strtoupper((string) $value);
    }
}
```

```php
use App\Integration\Formatters\UppercaseFormatter;
use Illuminate\Support\ServiceProvider;
use Agung\EventIntegration\Formatters\FormatterRegistry;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(FormatterRegistry $formatters): void
    {
        $formatters->register(app(UppercaseFormatter::class));
    }
}
```

The custom formatter is then available in output formats as `{{ code | uppercase }}`. The formatter receives the current value, optional argument, current item scope, and original event payload. For example, a custom `default` formatter can implement different fallback rules or accept literal arguments.

### Built-in output templates

The package includes three output directives. Each directive is an object with one `$` key.

`$each` compiles the template once per item and creates one outbound message for each item. Other fields in the surrounding output format are copied into every message:

```php
[
    'request_id' => '{{ transfer_id }}',
    'order' => [
        '$each' => [
            'path' => 'items',
            'template' => [
                'order_id' => '{{ order_id }}',
                'amount' => '{{ amount }}',
            ],
        ],
    ],
]
```

For `transfer_id = tr_123` and two items, the result is two messages:

```php
[
    ['request_id' => 'tr_123', 'order' => ['order_id' => 'ord_1', 'amount' => 12500]],
    ['request_id' => 'tr_123', 'order' => ['order_id' => 'ord_2', 'amount' => 8400]],
]
```

`$map` compiles every item into an array within one message:

```php
[
    'transfer_id' => '{{ transfer_id }}',
    'orders' => [
        '$map' => [
            'path' => 'items',
            'template' => [
                'order_id' => '{{ order_id }}',
                'amount' => '{{ amount }}',
            ],
        ],
    ],
]
```

The result is one message with an `orders` array containing the mapped items.

`$max` selects the item with the greatest numeric value at the configured `by` path. Its optional `template` shapes the selected item; without one, the selected item is returned as-is:

```php
[
    'highest_commission' => [
        '$max' => [
            'path' => 'items',
            'by' => 'amount',
            'template' => [
                'order_id' => '{{ order_id }}',
                'amount' => '{{ amount }}',
            ],
        ],
    ],
]
```

This produces one message containing the item with the largest `amount`, shaped with only `order_id` and `amount`.

## Creating channels and publishing

Channels store the owner, transport, event name, destination settings, filters, and output format. Each message from the plan should be stored as its own activity; this keeps `$each` deliveries independently retryable.

```php
use Agung\EventIntegration\Models\IntegrationChannel;
use Agung\EventIntegration\Transports\IntegrationPublisher;

$outputFormat = [
    'request_id' => '{{ transfer_id }}',
    'order' => [
        '$each' => [
            'path' => 'items',
            'template' => [
                'order_id' => '{{ order_id }}',
                'amount' => '{{ amount }}',
            ],
        ],
    ],
];

$channel = new IntegrationChannel([
    'name' => 'Affiliate callback',
    'transport' => 'webhook',
    'event_name' => 'transfer.settled',
    'destination' => [
        'url' => 'https://affiliate.example.com/callback',
        'method' => 'POST',
        'headers' => ['X-Source' => 'journal'],
    ],
    'filters' => [],
    'output_format' => $outputFormat,
]);
$channel->owner()->associate($tenant);
$channel->save();

foreach ($plan->messages as $messageIndex => $message) {
    $activity = $channel->activities()->create([
        'event_name' => $channel->event_name,
        'payload' => $message,
        'idempotency_key' => hash('sha256', implode(':', [
            (string) $transfer->getKey(),
            (string) $channel->getKey(),
            $channel->event_name,
            (string) $messageIndex,
        ])),
    ]);

    app(IntegrationPublisher::class)->publish($activity);
}
```

Use a stable idempotency key derived from the source event identity, channel, and message identity. The publisher atomically claims an activity before sending it and uses a lease to recover activities left in `processing` after a worker crash. Delivery is at-least-once: if the destination accepts a request but the worker crashes before recording success, the destination may receive a retry. The receiver should deduplicate using `X-Request-Id` or the RabbitMQ `message_id`.

`IntegrationPublisher` records failed attempts, sets `next_attempt_at` with exponential backoff, and rethrows transport errors. The host application owns the queue job and should select only due activities (`pending`, or `failed` with `next_attempt_at` at or before now). Schedule lease recovery so crashed workers do not leave activities in `processing`:

```php
use Illuminate\Support\Facades\Schedule;
use Agung\EventIntegration\Transports\IntegrationPublisher;

Schedule::call(fn () => app(IntegrationPublisher::class)->recoverExpiredActivities())
    ->everyMinute();
```

The lease duration must exceed the maximum transport timeout and queue worker timeout. `retryNow($activity)` resets a failed or exhausted activity for an explicit manual replay.

## Webhook destination

The `webhook` transport sends the activity payload as JSON. Destination options are `url`, `method`, optional `timeout_ms`, and optional `headers`. Requests include `X-Event-Type` and `X-Request-Id` headers; the activity ID is used as the request ID when no idempotency key is set. Redirects are not followed. Only HTTPS URLs are accepted; local/private IPs, special-use hostnames, and unapproved ports are rejected. Hostnames must resolve to public IPs. Configure `webhook.allowed_hosts` to restrict destinations to known hosts, and enforce outbound network rules as a second layer because DNS can change between validation and connection.

Applications with a different outbound network policy can replace the validator by binding their own `WebhookUrlValidator` implementation.

Set `EVENT_INTEGRATION_WEBHOOK_SECRET` to sign requests. When configured, the package sends `X-Integration-Timestamp` and `X-Integration-Signature`, where the signature is `sha256=` followed by the HMAC-SHA256 of `{timestamp}.{raw JSON body}`. The header names can be changed in the package config. Receivers should verify the signature and reject stale timestamps.

## RabbitMQ destination

The `rabbitmq` transport publishes a persistent JSON message and waits for a broker confirm. Set `exchange`, `routing_key`, and/or `queue` in the channel destination. The destination's `connection` may be a named connection from `event-integration.rabbitmq.connections`, a Laravel queue connection, or a connection settings array. An application with tenant-specific RabbitMQ credentials can bind its own `RabbitMqConnectionResolver`.

```php
'rabbitmq' => [
    'default_connection' => 'journal-events',
    'connections' => [
        'journal-events' => [
            'host' => env('RABBITMQ_HOST'),
            'port' => (int) env('RABBITMQ_PORT', 5672),
            'username' => env('RABBITMQ_USERNAME'),
            'password' => env('RABBITMQ_PASSWORD'),
            'virtual_host' => env('RABBITMQ_VHOST', '/'),
        ],
    ],
],
```

## Compiler limits and activity retention

`compiler.max_messages` caps the number of outbound messages generated by `$each` and expansion combinations. `compiler.max_depth` limits nested output format recursion. Tune these values for the expected payload size.

The `IntegrationActivity` model uses Laravel's `Prunable` trait. The default retention period is 90 days for successful activities (from dispatch time) and exhausted activities (from their last update). Pending, processing, and retryable failed activities are retained. To prune eligible rows, schedule or run:

```shell
php artisan model:prune --model="Agung\EventIntegration\Models\IntegrationActivity"
```

The host application should schedule this command according to its operational retention requirements.

## Nested collection filters

Filter rules can target collection fields at nested paths. The package filters matching values in place and retains each parent object, including parents whose nested collection becomes empty. The event matches only if at least one item in the filtered collection matches:

```php
[
    ['field' => 'orders.adjustments.type', 'operator' => 'equals', 'value' => 'commission'],
]
```

Declare each collection and its child fields in the event schema so the filter engine can identify the nested collection path.

## Extending the package

### Custom publisher

Add a transport by implementing `TransportPublisher`. The value returned by `transport()` is the key stored in the channel's `transport` field. The publisher receives the channel and activity, so it can use destination settings and the compiled payload.

```php
namespace App\Integration\Transports;

use Agung\EventIntegration\Contracts\TransportPublisher;
use Agung\EventIntegration\Models\IntegrationActivity;
use Agung\EventIntegration\Models\IntegrationChannel;

final class SqsPublisher implements TransportPublisher
{
    public function transport(): string
    {
        return 'sqs';
    }

    public function publish(IntegrationChannel $channel, IntegrationActivity $activity): void
    {
        // Publish $activity->payload using the channel's destination settings.
    }
}
```

Register the publisher in an application service provider. Laravel can resolve publisher dependencies from the container:

```php
use App\Integration\Transports\SqsPublisher;
use Illuminate\Support\ServiceProvider;
use Agung\EventIntegration\Transports\TransportPublisherRegistry;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(TransportPublisherRegistry $publishers): void
    {
        $publishers->register(app(SqsPublisher::class));
    }
}
```

A channel can now use `'transport' => 'sqs'`. The package publisher records delivery results in its activity; the application remains responsible for queue scheduling and retries.

### Custom filter operators and output templates

Register custom filter operators by implementing `FilterOperator` and adding the instance to `FilterOperatorRegistry`. Register custom output directives by implementing `OutputFormatTemplate` and adding the instance to `TemplateRegistry` from an application service provider:

```php
use App\Integration\Filters\StartsWithOperator;
use App\Integration\Templates\FirstTemplate;
use Illuminate\Support\ServiceProvider;
use Agung\EventIntegration\Filters\FilterOperatorRegistry;
use Agung\EventIntegration\Templates\TemplateRegistry;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(
        FilterOperatorRegistry $operators,
        TemplateRegistry $templates,
    ): void {
        $operators->register(new StartsWithOperator);
        $templates->register(new FirstTemplate);
    }
}
```

Both registries are container singletons, so these additions are available to the package compiler and filter engine. A custom template key must begin with `$` because output format directives use that syntax. This `FirstTemplate` implementation selects the first array item at the configured path and compiles its nested template:

```php
namespace App\Integration\Templates;

use Closure;
use Agung\EventIntegration\Contracts\OutputFormatTemplate;
use Agung\EventIntegration\Engine\PayloadCompiler;
use Agung\EventIntegration\Exceptions\InvalidIntegrationDefinition;

final class FirstTemplate implements OutputFormatTemplate
{
    public function key(): string
    {
        return '$first';
    }

    public function compile(mixed $configuration, array $payload, Closure $compileTemplate): mixed
    {
        if (! is_array($configuration) || ! is_string($configuration['path'] ?? null)
            || ! array_key_exists('template', $configuration)) {
            throw new InvalidIntegrationDefinition('$first requires a path and template.');
        }

        $items = PayloadCompiler::valueAtPath($payload, $configuration['path']);
        $first = is_array($items) ? reset($items) : false;

        if (! is_array($first)) {
            return null;
        }

        return $compileTemplate($configuration['template'], $first, $payload);
    }
}
```

Use the directive like this:

```php
[
    'first_order' => [
        '$first' => [
            'path' => 'orders',
            'template' => [
                'order_id' => '{{ id }}',
                'status' => '{{ status }}',
            ],
        ],
    ],
]
```

The template receives its directive configuration, the resolved root payload, and a callback for compiling nested templates. It can validate its own configuration, choose or transform payload data, and call the callback to compile its nested `template` using the selected item's scope.

## Tests

Run the package test suite with:

```shell
composer test
```
