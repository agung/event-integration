<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Transports;

use Agung\EventIntegration\Contracts\WebhookUrlValidator;
use Agung\EventIntegration\Exceptions\InvalidIntegrationDefinition;
use Illuminate\Support\Str;

final class ConfiguredWebhookUrlValidator implements WebhookUrlValidator
{
    public function validate(string $url): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;

        if ($scheme !== 'https' || $host === '' || isset($parts['user'])
            || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidIntegrationDefinition('Webhook URLs must be HTTPS URLs without credentials or fragments.');
        }

        $allowedPorts = config('event-integration.webhook.allowed_ports', [443]);

        if (! is_array($allowedPorts) || $allowedPorts === []
            || array_filter($allowedPorts, static fn (mixed $port): bool => ! is_int($port)) !== []) {
            throw new InvalidIntegrationDefinition('Webhook allowed_ports must be a non-empty list of integers.');
        }

        if (isset($parts['port']) && ! in_array((int) $parts['port'], $allowedPorts, true)) {
            throw new InvalidIntegrationDefinition('Webhook port is not allowed.');
        }

        $allowedHosts = config('event-integration.webhook.allowed_hosts', []);

        if (! is_array($allowedHosts)
            || array_filter($allowedHosts, static fn (mixed $pattern): bool => ! is_string($pattern)) !== []) {
            throw new InvalidIntegrationDefinition('Webhook allowed_hosts must be a list of host patterns.');
        }

        if ($allowedHosts !== [] && ! $this->hostIsAllowed($host, $allowedHosts)) {
            throw new InvalidIntegrationDefinition('Webhook host is not in the configured allowlist.');
        }

        if ($this->isPrivateHost($host)) {
            throw new InvalidIntegrationDefinition('Webhook URLs cannot target local or private hosts.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return;
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];

        foreach ($records ?: [] as $record) {
            if (isset($record['ip'])) {
                $addresses[] = $record['ip'];
            }

            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        if ($addresses === []) {
            throw new InvalidIntegrationDefinition('Webhook host must resolve to a public IP address.');
        }

        foreach ($addresses as $address) {
            if (filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false) {
                throw new InvalidIntegrationDefinition('Webhook hosts cannot resolve to local or private IPs.');
            }
        }
    }

    /**
     * Checks the hostname against exact or wildcard allowlist entries.
     *
     * @param  list<string>  $allowedHosts  Host patterns configured by the application.
     */
    private function hostIsAllowed(string $host, array $allowedHosts): bool
    {
        foreach ($allowedHosts as $allowedHost) {
            if (is_string($allowedHost) && Str::is(strtolower($allowedHost), $host)) {
                return true;
            }
        }

        return false;
    }

    private function isPrivateHost(string $host): bool
    {
        if (filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false && filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        foreach (['.localhost', '.local', '.internal', '.test', '.invalid', '.example'] as $suffix) {
            if ($host === ltrim($suffix, '.') || str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return $host === 'localhost';
    }
}
