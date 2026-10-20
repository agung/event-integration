<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Transports;

use Agung\EventIntegration\Contracts\RabbitMqConnectionResolver;
use Agung\EventIntegration\Exceptions\InvalidIntegrationDefinition;
use Agung\EventIntegration\Models\IntegrationChannel;

final class ConfiguredRabbitMqConnectionResolver implements RabbitMqConnectionResolver
{
    public function resolve(IntegrationChannel $channel): array
    {
        $destination = $channel->destination ?? [];
        $connection = $destination['connection']
            ?? config('event-integration.rabbitmq.default_connection', 'default');

        if (is_string($connection)) {
            $settings = config("event-integration.rabbitmq.connections.{$connection}")
                ?? config("queue.connections.{$connection}");
        } else {
            $settings = $connection;
        }

        if (! is_array($settings)) {
            throw new InvalidIntegrationDefinition(
                'RabbitMQ connection must be a connection name or settings array.',
            );
        }

        $resolved = [
            'host' => $settings['host'] ?? null,
            'port' => (int) ($settings['port'] ?? 5672),
            'username' => $settings['username'] ?? $settings['user'] ?? null,
            'password' => $settings['password'] ?? null,
            'virtual_host' => $settings['virtual_host'] ?? $settings['vhost'] ?? '/',
            'ssl_options' => $settings['ssl_options'] ?? [],
        ];

        if (! is_array($resolved['ssl_options'])) {
            throw new InvalidIntegrationDefinition('RabbitMQ ssl_options must be an array.');
        }

        foreach (['host', 'username', 'password'] as $required) {
            if (! is_string($resolved[$required]) || trim($resolved[$required]) === '') {
                throw new InvalidIntegrationDefinition(
                    "RabbitMQ connection is missing [{$required}].",
                );
            }
        }

        return $resolved;
    }
}
