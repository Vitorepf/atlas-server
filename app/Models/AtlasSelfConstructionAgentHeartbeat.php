<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasSelfConstructionAgentHeartbeat extends Model
{
    use HasUuids;

    protected $fillable = [
        'agent_run_id',
        'heartbeat_key',
        'sequence',
        'status',
        'signal',
        'occurred_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AtlasSelfConstructionAgentRun::class, 'agent_run_id');
    }
}
