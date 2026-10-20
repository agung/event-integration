<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Contracts;

use Agung\EventIntegration\Models\IntegrationActivity;
use Agung\EventIntegration\Models\IntegrationChannel;

interface TransportPublisher
{
    /**
     * Returns the transport key used by integration channels.
     */
    public function transport(): string;

    /**
     * Publishes one activity payload to its configured destination.
     */
    public function publish(IntegrationChannel $channel, IntegrationActivity $activity): void;
}
