<?php

declare(strict_types=1);

use Agung\EventIntegration\Contracts\TransportPublisher;
use Agung\EventIntegration\Contracts\WebhookUrlValidator;
use Agung\EventIntegration\Exceptions\InvalidIntegrationDefinition;
use Agung\EventIntegration\Models\IntegrationActivity;
use Agung\EventIntegration\Models\IntegrationChannel;
use Agung\EventIntegration\Transports\ConfiguredRabbitMqConnectionResolver;
use Agung\EventIntegration\Transports\ConfiguredWebhookUrlValidator;
use Agung\EventIntegration\Transports\IntegrationPublisher;
use Agung\EventIntegration\Transports\RabbitMqPublisher;
use Agung\EventIntegration\Transports\TransportPublisherRegistry;
use Agung\EventIntegration\Transports\WebhookPublisher;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;

function bindHttpFake(?string $signingSecret = null): Factory
{
    Facade::clearResolvedInstances();
    $container = new Container;
    $factory = new Factory;
    $container->instance(Factory::class, $factory);
    $container->instance('config', new Repository([
        'event-integration' => [
            'webhook' => [
                'timeout_ms' => 10000,
                'connect_timeout_ms' => 3000,
                'allowed_methods' => ['POST', 'PUT', 'PATCH'],
                'signature_secret' => $signingSecret,
            ],
            'retry' => [
                'base_delay_seconds' => 5,
                'max_delay_seconds' => 3600,
                'lease_seconds' => 120,
            ],
        ],
    ]));
    Container::setInstance($container);
    Facade::setFacadeApplication($container);

    return $factory;
}

function makeTestWebhookPublisher(): WebhookPublisher
{
    $validator = new class implements WebhookUrlValidator
    {
        public function validate(string $url): void {}
    };

    return new WebhookPublisher($validator);
}

function createPublisherDatabase(): void
{
    $capsule = new Capsule(new Container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    Facade::setFacadeApplication($capsule->getContainer());
    $capsule->getContainer()->instance(
        'db.schema',
        $capsule->getConnection()->getSchemaBuilder(),
    );
    $configuration = $capsule->getContainer()->make('config');
    $configuration->set('event-integration.retry', [
        'base_delay_seconds' => 5,
        'max_delay_seconds' => 3600,
        'lease_seconds' => 120,
    ]);
    $configuration->set('event-integration.retention.completed_days', 90);
    Container::setInstance($capsule->getContainer());

    $migration = require dirname(__DIR__).'/database/migrations/2026_10_20_000001_create_event_integration_tables.php';
    $migration->up();
}

it('publishes a webhook with JSON payload and idempotency headers', function (): void {
    $http = bindHttpFake();
    $http->fake();
    $channel = new IntegrationChannel([
        'destination' => [
            'url' => 'https://affiliate.example.test/callback',
            'method' => 'PUT',
            'timeout_ms' => 4500,
            'headers' => ['X-Application' => 'journal'],
        ],
    ]);
    $activity = new IntegrationActivity([
        'event_name' => 'transfer.settled',
        'payload' => ['transfer_id' => 'tr_123'],
        'idempotency_key' => 'activity-123',
    ]);

    makeTestWebhookPublisher()->publish($channel, $activity);

    $http->assertSent(function ($request): bool {
        return $request->url() === 'https://affiliate.example.test/callback'
            && $request->method() === 'PUT'
            && $request->data() === ['transfer_id' => 'tr_123']
            && $request->hasHeader('X-Event-Type', 'transfer.settled')
            && $request->hasHeader('X-Request-Id', 'activity-123')
            && $request->hasHeader('X-Application', 'journal');
    });
});

it('treats non-successful webhook responses as publish failures', function (): void {
    $http = bindHttpFake();
    $http->fake(['*' => Http::response([], 503)]);
    $channel = new IntegrationChannel([
        'destination' => ['url' => 'https://affiliate.example.test/callback'],
    ]);
    $activity = new IntegrationActivity([
        'event_name' => 'commission.transfer_completed',
        'payload' => ['commission_id' => 'com_123'],
    ]);

    expect(fn () => makeTestWebhookPublisher()->publish($channel, $activity))
        ->toThrow(RuntimeException::class, 'HTTP 503');
});

it('does not follow webhook redirects', function (): void {
    $http = bindHttpFake();
    $http->fake(['*' => Http::response([], 302, ['Location' => 'http://127.0.0.1/internal'])]);
    $channel = new IntegrationChannel([
        'destination' => ['url' => 'https://affiliate.example.test/callback'],
    ]);
    $activity = new IntegrationActivity([
        'event_name' => 'transfer.settled',
        'payload' => [],
    ]);

    expect(fn () => makeTestWebhookPublisher()->publish($channel, $activity))
        ->toThrow(RuntimeException::class, 'HTTP 302');

    $http->assertSentCount(1);
});

it('signs webhook bodies when a signing secret is configured', function (): void {
    $http = bindHttpFake('shared-secret');
    $http->fake();
    $channel = new IntegrationChannel([
        'destination' => ['url' => 'https://affiliate.example.test/callback'],
    ]);
    $activity = new IntegrationActivity([
        'event_name' => 'transfer.settled',
        'payload' => ['transfer_id' => 'tr_signed'],
        'idempotency_key' => 'activity-signed',
    ]);

    makeTestWebhookPublisher()->publish($channel, $activity);

    $http->assertSent(function ($request): bool {
        $timestamp = $request->header('X-Integration-Timestamp')[0] ?? '';
        $signature = $request->header('X-Integration-Signature')[0] ?? '';

        return $request->body() === '{"transfer_id":"tr_signed"}'
            && $signature === 'sha256='.hash_hmac(
                'sha256',
                $timestamp.'.'.$request->body(),
                'shared-secret',
            );
    });
});

it('rejects webhook URLs that target private addresses', function (): void {
    expect(fn () => (new ConfiguredWebhookUrlValidator)->validate('https://127.0.0.1/callback'))
        ->toThrow(InvalidIntegrationDefinition::class, 'local or private hosts');
});

it('resolves RabbitMQ connection settings from a channel destination', function (): void {
    $channel = new IntegrationChannel([
        'destination' => [
            'connection' => [
                'host' => 'rabbitmq.example.test',
                'port' => 5671,
                'user' => 'publisher',
                'password' => 'secret',
                'vhost' => '/events',
            ],
        ],
    ]);

    $settings = (new ConfiguredRabbitMqConnectionResolver)->resolve($channel);

    expect($settings)->toMatchArray([
        'host' => 'rabbitmq.example.test',
        'port' => 5671,
        'username' => 'publisher',
        'password' => 'secret',
        'virtual_host' => '/events',
    ]);
});

it('rejects RabbitMQ channels without an exchange or queue', function (): void {
    $resolver = new class implements \Agung\EventIntegration\Contracts\RabbitMqConnectionResolver
    {
        public function resolve(IntegrationChannel $channel): array
        {
            return [];
        }
    };
    $channel = new IntegrationChannel(['destination' => ['connection' => []]]);
    $activity = new IntegrationActivity(['event_name' => 'transaction.settled', 'payload' => []]);

    expect(fn () => (new RabbitMqPublisher($resolver))->publish($channel, $activity))
        ->toThrow(InvalidIntegrationDefinition::class, 'requires an exchange or queue');
});

it('records successful delivery on the activity after publishing', function (): void {
    createPublisherDatabase();
    $channel = IntegrationChannel::query()->create([
        'name' => 'Affiliate callback',
        'transport' => 'fake',
        'event_name' => 'transfer.settled',
        'is_active' => true,
    ]);
    $activity = $channel->activities()->create([
        'event_name' => 'transfer.settled',
        'payload' => ['transfer_id' => 'tr_123'],
        'idempotency_key' => 'activity-123',
    ]);
    $transport = new class implements TransportPublisher
    {
        public function transport(): string
        {
            return 'fake';
        }

        public function publish(IntegrationChannel $channel, IntegrationActivity $activity): void {}
    };

    (new IntegrationPublisher(new TransportPublisherRegistry([$transport])))->publish($activity);
    $activity->refresh();

    expect($activity->status)->toBe('success')
        ->and($activity->attempt_count)->toBe(1)
        ->and($activity->dispatched_at)->not->toBeNull();
});

it('records failed delivery before propagating the transport exception', function (): void {
    createPublisherDatabase();
    $channel = IntegrationChannel::query()->create([
        'name' => 'Affiliate callback',
        'transport' => 'fake',
        'event_name' => 'transfer.settled',
        'is_active' => true,
    ]);
    $activity = $channel->activities()->create([
        'event_name' => 'transfer.settled',
        'payload' => ['transfer_id' => 'tr_456'],
        'max_attempts' => 2,
    ]);
    $transport = new class implements TransportPublisher
    {
        public function transport(): string
        {
            return 'fake';
        }

        public function publish(IntegrationChannel $channel, IntegrationActivity $activity): void
        {
            throw new RuntimeException('Remote broker unavailable.');
        }
    };

    expect(fn () => (new IntegrationPublisher(new TransportPublisherRegistry([$transport])))->publish($activity))
        ->toThrow(RuntimeException::class, 'Remote broker unavailable.');

    $activity->refresh();

    expect($activity->status)->toBe('failed')
        ->and($activity->attempt_count)->toBe(1)
        ->and($activity->last_error)->toBe('Remote broker unavailable.')
        ->and($activity->next_attempt_at)->not->toBeNull()
        ->and($activity->lease_expires_at)->toBeNull();
});

it('does not publish an activity again before its backoff expires', function (): void {
    createPublisherDatabase();
    $channel = IntegrationChannel::query()->create([
        'name' => 'Affiliate callback',
        'transport' => 'fake',
        'event_name' => 'transfer.settled',
        'is_active' => true,
    ]);
    $activity = $channel->activities()->create([
        'event_name' => 'transfer.settled',
        'payload' => ['transfer_id' => 'tr_backoff'],
        'max_attempts' => 3,
    ]);
    $transport = new class implements TransportPublisher
    {
        public int $attempts = 0;

        public function transport(): string
        {
            return 'fake';
        }

        public function publish(IntegrationChannel $channel, IntegrationActivity $activity): void
        {
            $this->attempts++;

            if ($this->attempts === 1) {
                throw new RuntimeException('Temporary failure.');
            }
        }
    };
    $publisher = new IntegrationPublisher(new TransportPublisherRegistry([$transport]));

    try {
        $publisher->publish($activity);
    } catch (RuntimeException) {
    }

    $publisher->publish($activity);
    expect($transport->attempts)->toBe(1);

    $activity->update(['next_attempt_at' => now()->subSecond()]);
    $publisher->publish($activity);

    expect($transport->attempts)->toBe(2)
        ->and($activity->refresh()->status)->toBe('success');
});

it('claims an activity atomically and skips a concurrent publish attempt', function (): void {
    createPublisherDatabase();
    $channel = IntegrationChannel::query()->create([
        'name' => 'Affiliate callback',
        'transport' => 'fake',
        'event_name' => 'transfer.settled',
        'is_active' => true,
    ]);
    $activity = $channel->activities()->create([
        'event_name' => 'transfer.settled',
        'payload' => ['transfer_id' => 'tr_claim'],
    ]);
    $publisher = null;
    $transport = new class implements TransportPublisher
    {
        public int $attempts = 0;

        public ?\Closure $publishDuringDelivery = null;

        public function transport(): string
        {
            return 'fake';
        }

        public function publish(IntegrationChannel $channel, IntegrationActivity $activity): void
        {
            $this->attempts++;
            ($this->publishDuringDelivery)();
        }
    };
    $publisher = new IntegrationPublisher(new TransportPublisherRegistry([$transport]));
    $transport->publishDuringDelivery = static function () use (&$publisher, $activity): void {
        $publisher->publish($activity);
    };

    $publisher->publish($activity);

    expect($transport->attempts)->toBe(1)
        ->and($activity->refresh()->status)->toBe('success');
});

it('allows a failed activity to be manually replayed', function (): void {
    createPublisherDatabase();
    $channel = IntegrationChannel::query()->create([
        'name' => 'Affiliate callback',
        'transport' => 'fake',
        'event_name' => 'transfer.settled',
        'is_active' => true,
    ]);
    $activity = $channel->activities()->create([
        'event_name' => 'transfer.settled',
        'payload' => [],
        'status' => 'retry_exceeded',
        'attempt_count' => 3,
        'last_error' => 'Permanent error.',
    ]);

    (new IntegrationPublisher(new TransportPublisherRegistry))->retryNow($activity);

    expect($activity->status)->toBe('pending')
        ->and($activity->attempt_count)->toBe(0)
        ->and($activity->last_error)->toBeNull()
        ->and($activity->next_attempt_at)->toBeNull();
});

it('recovers expired processing leases and exhausts attempts safely', function (): void {
    createPublisherDatabase();
    $channel = IntegrationChannel::query()->create([
        'name' => 'Affiliate callback',
        'transport' => 'fake',
        'event_name' => 'transfer.settled',
        'is_active' => true,
    ]);
    $activity = $channel->activities()->create([
        'event_name' => 'transfer.settled',
        'payload' => [],
        'status' => 'processing',
        'attempt_count' => 3,
        'max_attempts' => 3,
        'lease_expires_at' => now()->subMinute(),
    ]);

    $recovered = (new IntegrationPublisher(new TransportPublisherRegistry))
        ->recoverExpiredActivities();

    expect($recovered)->toBe(1)
        ->and($activity->refresh()->status)->toBe('retry_exceeded')
        ->and($activity->lease_expires_at)->toBeNull()
        ->and($activity->next_attempt_at)->toBeNull();
});

it('prunes only old successful and exhausted activities', function (): void {
    createPublisherDatabase();
    $channel = IntegrationChannel::query()->create([
        'name' => 'Affiliate callback',
        'transport' => 'fake',
        'event_name' => 'transfer.settled',
        'is_active' => true,
    ]);
    $oldSuccess = $channel->activities()->create([
        'event_name' => 'transfer.settled',
        'payload' => [],
        'status' => 'success',
        'dispatched_at' => now()->subDays(100),
    ]);
    $oldExhausted = $channel->activities()->create([
        'event_name' => 'transfer.settled',
        'payload' => [],
        'status' => 'retry_exceeded',
    ]);
    $oldFailed = $channel->activities()->create([
        'event_name' => 'transfer.settled',
        'payload' => [],
        'status' => 'failed',
    ]);
    $oldExhausted->forceFill(['updated_at' => now()->subDays(100)])->saveQuietly();
    $oldFailed->forceFill(['updated_at' => now()->subDays(100)])->saveQuietly();

    expect((new IntegrationActivity)->prunable()->pluck('id')->all())
        ->toBe([$oldSuccess->id, $oldExhausted->id])
        ->not->toContain($oldFailed->id);
});
