<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasSelfConstructionAgentWakeupItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'agent_run_id',
        'wakeup_key',
        'packet_id',
        'actor',
        'provider',
        'reason',
        'priority',
        'status',
        'scheduled_for',
        'claimed_at',
        'completed_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AtlasSelfConstructionAgentRun::class, 'agent_run_id');
    }

    public function dispatchReceipts(): HasMany
    {
        return $this->hasMany(AtlasSelfConstructionAgentDispatchReceipt::class, 'wakeup_item_id')
            ->orderByDesc('created_at');
    }
}
