<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Transports;

use Agung\EventIntegration\Contracts\TransportPublisher;
use Agung\EventIntegration\Exceptions\UnsupportedTransport;

final class TransportPublisherRegistry
{
    /**
     * Registered publishers indexed by transport key.
     *
     * @var array<string, TransportPublisher>
     */
    private array $publishers = [];

    /**
     * Initializes the registry with optional transport publishers.
     *
     * @param  iterable<TransportPublisher>  $publishers  Publishers available to channels.
     */
    public function __construct(iterable $publishers = [])
    {
        foreach ($publishers as $publisher) {
            $this->register($publisher);
        }
    }

    /**
     * Registers or replaces a publisher for its transport key.
     */
    public function register(TransportPublisher $publisher): void
    {
        $this->publishers[$publisher->transport()] = $publisher;
    }

    /**
     * Returns the publisher registered for the requested transport.
     */
    public function get(string $transport): TransportPublisher
    {
        return $this->publishers[$transport] ?? throw new UnsupportedTransport(
            "No publisher is registered for transport [{$transport}].",
        );
    }
}
