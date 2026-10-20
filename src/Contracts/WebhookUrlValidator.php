<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Contracts;

interface WebhookUrlValidator
{
    /**
     * Validates that a webhook URL is allowed for outbound requests.
     */
    public function validate(string $url): void;
}
