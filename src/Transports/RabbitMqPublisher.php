<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Transports;

use Agung\EventIntegration\Contracts\RabbitMqConnectionResolver;
use Agung\EventIntegration\Contracts\TransportPublisher;
use Agung\EventIntegration\Exceptions\InvalidIntegrationDefinition;
use Agung\EventIntegration\Models\IntegrationActivity;
use Agung\EventIntegration\Models\IntegrationChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

final class RabbitMqPublisher implements TransportPublisher
{
    public function __construct(private readonly RabbitMqConnectionResolver $connections) {}

    public function transport(): string
    {
        return 'rabbitmq';
    }

    public function publish(IntegrationChannel $channel, IntegrationActivity $activity): void
    {
        $destination = $channel->destination ?? [];
        $exchange = (string) ($destination['exchange'] ?? '');
        $routingKey = (string) ($destination['routing_key'] ?? '');
        $queue = (string) ($destination['queue'] ?? '');

        if ($exchange === '' && $queue === '') {
            throw new InvalidIntegrationDefinition('RabbitMQ destination requires an exchange or queue.');
        }

        $settings = $this->connections->resolve($channel);
        $connection = new AMQPStreamConnection(
            $settings['host'],
            $settings['port'],
            $settings['username'],
            $settings['password'],
            $settings['virtual_host'],
            false,
            'AMQPLAIN',
            null,
            'en_US',
            3.0,
            3.0,
            $this->streamContext($settings['ssl_options']),
            false,
            0,
        );
        $rabbitChannel = null;

        try {
            $rabbitChannel = $connection->channel();
            $rabbitChannel->confirm_select();

            if ($exchange !== '') {
                $exchangeType = (string) ($destination['exchange_type'] ?? 'topic');
                $rabbitChannel->exchange_declare($exchange, $exchangeType, true, false, false);
            }

            if ($queue !== '') {
                $rabbitChannel->queue_declare($queue, true, false, false, false);
            }

            $resolvedRoutingKey = $routingKey !== '' ? $routingKey : $queue;

            if ($exchange !== '' && $queue !== '') {
                $rabbitChannel->queue_bind($queue, $exchange, $resolvedRoutingKey);
            }

            $message = new AMQPMessage(
                json_encode($activity->payload ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    ?: '{}',
                [
                    'content_type' => 'application/json',
                    'delivery_mode' => ($destination['persistent'] ?? true) ? 2 : 1,
                    'message_id' => (string) ($activity->idempotency_key ?? $activity->getKey()),
                    'type' => $activity->event_name,
                    'application_headers' => new AMQPTable(
                        is_array($destination['headers'] ?? null) ? $destination['headers'] : [],
                    ),
                ],
            );

            $rabbitChannel->basic_publish(
                $message,
                $exchange,
                $resolvedRoutingKey,
                (bool) ($destination['mandatory'] ?? true),
            );
            $rabbitChannel->wait_for_pending_acks_returns(
                max(1, (float) ($destination['confirm_timeout_seconds'] ?? 5)),
            );
        } finally {
            if ($rabbitChannel !== null && $rabbitChannel->is_open()) {
                $rabbitChannel->close();
            }

            if ($connection->isConnected()) {
                $connection->close();
            }
        }
    }

    /**
     * Builds a TLS stream context when certificate settings are provided.
     *
     * @param  array<string, mixed>  $options  TLS stream options.
     */
    private function streamContext(array $options): mixed
    {
        $ssl = array_filter([
            'cafile' => $options['cafile'] ?? null,
            'local_cert' => $options['local_cert'] ?? null,
            'local_pk' => $options['local_pk'] ?? null,
            'passphrase' => $options['passphrase'] ?? null,
        ]);

        return $ssl === [] ? null : stream_context_create(['ssl' => $ssl]);
    }
}
