<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Transports;

use Agung\EventIntegration\Enums\ActivityStatus;
use Agung\EventIntegration\Exceptions\InvalidIntegrationDefinition;
use Agung\EventIntegration\Models\IntegrationActivity;
use Throwable;

final class IntegrationPublisher
{
    public function __construct(private readonly TransportPublisherRegistry $publishers) {}

    /**
     * Publishes one persisted activity and records the outcome for the host retry workflow.
     *
     * The calling application decides when to retry an activity in the failed state.
     */
    public function publish(IntegrationActivity $activity): void
    {
        if (! $activity->exists) {
            throw new InvalidIntegrationDefinition('Only persisted integration activities can be published.');
        }

        $now = now();
        $leaseExpiresAt = $now->copy()->addSeconds(
            max(1, (int) config('event-integration.retry.lease_seconds', 120)),
        );
        $claimed = IntegrationActivity::query()
            ->whereKey($activity->getKey())
            ->whereColumn('attempt_count', '<', 'max_attempts')
            ->where(function ($query) use ($now): void {
                $query
                    ->where(function ($query) use ($now): void {
                        $query
                            ->whereIn('status', [
                                ActivityStatus::Pending->value,
                                ActivityStatus::Failed->value,
                            ])
                            ->where(function ($query) use ($now): void {
                                $query->whereNull('next_attempt_at')
                                    ->orWhere('next_attempt_at', '<=', $now);
                            });
                    })
                    ->orWhere(function ($query) use ($now): void {
                        $query
                            ->where('status', ActivityStatus::Processing->value)
                            ->where(function ($query) use ($now): void {
                                $query->whereNull('lease_expires_at')
                                    ->orWhere('lease_expires_at', '<=', $now);
                            });
                    });
            })
            ->increment('attempt_count', 1, [
                'status' => ActivityStatus::Processing->value,
                'last_error' => null,
                'lease_expires_at' => $leaseExpiresAt,
                'updated_at' => $now,
            ]);

        if ($claimed !== 1) {
            return;
        }

        $activity->refresh()->load('channel');

        try {
            $channel = $activity->channel;

            if ($channel === null || ! $channel->is_active) {
                throw new InvalidIntegrationDefinition('The integration channel is missing or inactive.');
            }

            $this->publishers
                ->get($channel->transport)
                ->publish($channel, $activity);
        } catch (Throwable $exception) {
            $attemptCount = (int) $activity->attempt_count;
            $retryExceeded = $attemptCount >= $activity->max_attempts;

            $activity->forceFill([
                'status' => $retryExceeded
                    ? ActivityStatus::RetryExceeded->value
                    : ActivityStatus::Failed->value,
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                'lease_expires_at' => null,
                'next_attempt_at' => $retryExceeded ? null : now()->addSeconds(
                    $this->retryDelay($attemptCount),
                ),
            ])->save();

            throw $exception;
        }

        $activity->forceFill([
            'status' => ActivityStatus::Success->value,
            'last_error' => null,
            'lease_expires_at' => null,
            'next_attempt_at' => null,
            'dispatched_at' => now(),
        ])->save();
    }

    /**
     * Resets a failed activity so the host application can queue a manual replay.
     */
    public function retryNow(IntegrationActivity $activity): void
    {
        $updated = IntegrationActivity::query()
            ->whereKey($activity->getKey())
            ->whereIn('status', [
                ActivityStatus::Failed->value,
                ActivityStatus::RetryExceeded->value,
            ])
            ->update([
                'status' => ActivityStatus::Pending->value,
                'attempt_count' => 0,
                'last_error' => null,
                'lease_expires_at' => null,
                'next_attempt_at' => null,
                'dispatched_at' => null,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new InvalidIntegrationDefinition('Only failed activities can be manually replayed.');
        }

        $activity->refresh();
    }

    /**
     * Converts expired processing leases into failed or exhausted activities for queue recovery.
     *
     * @return int Number of activities recovered.
     */
    public function recoverExpiredActivities(int $batchSize = 100): int
    {
        $now = now();
        $activities = IntegrationActivity::query()
            ->where('status', ActivityStatus::Processing->value)
            ->where(function ($query) use ($now): void {
                $query->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', $now);
            })
            ->orderBy('id')
            ->limit(max(1, min(1000, $batchSize)))
            ->get();
        $recovered = 0;

        foreach ($activities as $activity) {
            $attemptCount = (int) $activity->attempt_count;
            $retryExceeded = $attemptCount >= $activity->max_attempts;
            $updated = IntegrationActivity::query()
                ->whereKey($activity->getKey())
                ->where('status', ActivityStatus::Processing->value)
                ->where(function ($query) use ($now): void {
                    $query->whereNull('lease_expires_at')
                        ->orWhere('lease_expires_at', '<=', $now);
                })
                ->update([
                    'status' => $retryExceeded
                        ? ActivityStatus::RetryExceeded->value
                        : ActivityStatus::Failed->value,
                    'last_error' => 'Publishing worker lease expired before delivery completed.',
                    'lease_expires_at' => null,
                    'next_attempt_at' => $retryExceeded
                        ? null
                        : $now->copy()->addSeconds($this->retryDelay($attemptCount)),
                    'updated_at' => $now,
                ]);

            $recovered += $updated;
        }

        return $recovered;
    }

    private function retryDelay(int $attemptCount): int
    {
        $baseDelay = max(1, (int) config('event-integration.retry.base_delay_seconds', 5));
        $maximumDelay = max($baseDelay, (int) config('event-integration.retry.max_delay_seconds', 3600));
        $exponent = min(30, max(0, $attemptCount - 1));

        return min($maximumDelay, $baseDelay * (2 ** $exponent));
    }
}
