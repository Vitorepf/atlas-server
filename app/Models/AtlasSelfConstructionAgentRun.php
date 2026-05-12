<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasSelfConstructionAgentRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'run_key',
        'packet_id',
        'reservation_id',
        'actor',
        'provider',
        'provider_role',
        'session_id',
        'workspace_id',
        'obra_id',
        'status',
        'liveness',
        'packet_hash',
        'allowed_files_hash',
        'lease_expires_at',
        'last_heartbeat_at',
        'started_at',
        'finished_at',
        'input_tokens',
        'output_tokens',
        'cost_usd',
        'completion_evidence_hash',
        'summary',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'lease_expires_at' => 'immutable_datetime',
            'last_heartbeat_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_usd' => 'decimal:6',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function heartbeats(): HasMany
    {
        return $this->hasMany(AtlasSelfConstructionAgentHeartbeat::class, 'agent_run_id')
            ->orderBy('sequence');
    }

    public function costEvents(): HasMany
    {
        return $this->hasMany(AtlasSelfConstructionAgentCostEvent::class, 'agent_run_id')
            ->orderBy('occurred_at');
    }

    public function workProducts(): HasMany
    {
        return $this->hasMany(AtlasSelfConstructionAgentWorkProduct::class, 'agent_run_id')
            ->orderBy('created_at');
    }

    public function wakeupItems(): HasMany
    {
        return $this->hasMany(AtlasSelfConstructionAgentWakeupItem::class, 'agent_run_id')
            ->orderBy('scheduled_for');
    }

    public function dispatchReceipts(): HasMany
    {
        return $this->hasMany(AtlasSelfConstructionAgentDispatchReceipt::class, 'agent_run_id')
            ->orderByDesc('created_at');
    }
}
