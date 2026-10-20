<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Models;

use Agung\EventIntegration\Enums\ActivityStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationActivity extends Model
{
    use Prunable;

    protected $table = 'event_integration_activities';

    protected $fillable = [
        'channel_id',
        'event_name',
        'payload',
        'status',
        'attempt_count',
        'max_attempts',
        'last_error',
        'idempotency_key',
        'lease_expires_at',
        'next_attempt_at',
        'scheduled_at',
        'dispatched_at',
    ];

    /**
     * Returns the integration channel responsible for this outbound activity.
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(IntegrationChannel::class, 'channel_id');
    }

    /**
     * Selects completed activities that have passed the configured retention period.
     *
     * @return Builder<static> Activities eligible for pruning.
     */
    public function prunable(): Builder
    {
        $retentionDays = max(0, (int) config('event-integration.retention.completed_days', 90));
        $cutoff = now()->subDays($retentionDays);

        return static::query()
            ->where(function (Builder $query) use ($cutoff): void {
                $query
                    ->where(function (Builder $query) use ($cutoff): void {
                        $query
                            ->where('status', ActivityStatus::Success->value)
                            ->where('dispatched_at', '<=', $cutoff);
                    })
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query
                            ->where('status', ActivityStatus::RetryExceeded->value)
                            ->where('updated_at', '<=', $cutoff);
                    });
            });
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempt_count' => 'integer',
            'max_attempts' => 'integer',
            'lease_expires_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
    }
}
