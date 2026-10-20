<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class IntegrationChannel extends Model
{
    protected $table = 'event_integration_channels';

    protected $fillable = [
        'name',
        'transport',
        'event_name',
        'is_active',
        'destination',
        'filters',
        'output_format',
        'options',
    ];

    /**
     * Returns the application model that owns this integration channel.
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Returns the outbound activity records created for this channel.
     */
    public function activities(): HasMany
    {
        return $this->hasMany(IntegrationActivity::class, 'channel_id');
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'destination' => 'array',
            'filters' => 'array',
            'output_format' => 'array',
            'options' => 'array',
        ];
    }
}
