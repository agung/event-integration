<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Contracts;

use Agung\EventIntegration\Models\IntegrationChannel;

interface RabbitMqConnectionResolver
{
    /**
     * Resolves connection credentials and TLS options for a RabbitMQ channel.
     *
     * @return array<string, mixed> Connection settings expected by php-amqplib.
     */
    public function resolve(IntegrationChannel $channel): array;
}
