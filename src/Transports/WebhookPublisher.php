<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Transports;

use Agung\EventIntegration\Contracts\TransportPublisher;
use Agung\EventIntegration\Contracts\WebhookUrlValidator;
use Agung\EventIntegration\Exceptions\InvalidIntegrationDefinition;
use Agung\EventIntegration\Models\IntegrationActivity;
use Agung\EventIntegration\Models\IntegrationChannel;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class WebhookPublisher implements TransportPublisher
{
    public function __construct(private readonly WebhookUrlValidator $urlValidator) {}

    public function transport(): string
    {
        return 'webhook';
    }

    public function publish(IntegrationChannel $channel, IntegrationActivity $activity): void
    {
        $destination = $channel->destination ?? [];
        $url = $destination['url'] ?? null;

        if (! is_string($url) || trim($url) === '') {
            throw new InvalidIntegrationDefinition('Webhook destination requires a URL.');
        }

        $this->urlValidator->validate($url);
        $method = strtoupper((string) ($destination['method'] ?? 'POST'));
        $allowedMethods = config('event-integration.webhook.allowed_methods', ['POST', 'PUT', 'PATCH']);

        if (! is_array($allowedMethods) || ! in_array($method, $allowedMethods, true)) {
            throw new InvalidIntegrationDefinition('Webhook method is not allowed.');
        }

        $timeoutMilliseconds = max(1, (int) ($destination['timeout_ms']
            ?? config('event-integration.webhook.timeout_ms', 10000)));
        $connectTimeoutMilliseconds = max(1, (int) config(
            'event-integration.webhook.connect_timeout_ms',
            3000,
        ));
        $headers = is_array($destination['headers'] ?? null) ? $destination['headers'] : [];
        $requestId = (string) ($activity->idempotency_key ?? $activity->getKey());
        $body = json_encode(
            $activity->payload ?? [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $timestamp = (string) now()->timestamp;
        $signingSecret = config('event-integration.webhook.signature_secret');

        if (is_string($signingSecret) && $signingSecret !== '') {
            $signatureHeader = (string) config(
                'event-integration.webhook.signature_header',
                'X-Integration-Signature',
            );
            $timestampHeader = (string) config(
                'event-integration.webhook.timestamp_header',
                'X-Integration-Timestamp',
            );
            $headers[$timestampHeader] = $timestamp;
            $headers[$signatureHeader] = 'sha256='.hash_hmac('sha256', "{$timestamp}.{$body}", $signingSecret);
        }

        $response = Http::withHeaders(array_merge($headers, [
            'X-Event-Type' => $activity->event_name,
            'X-Request-Id' => $requestId,
        ]))
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout($connectTimeoutMilliseconds / 1000)
            ->timeout($timeoutMilliseconds / 1000)
            ->withBody($body, 'application/json')
            ->send($method, $url);

        if (! $response->successful()) {
            throw new RuntimeException("Webhook responded with HTTP {$response->status()}.");
        }
    }
}
