<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasSelfConstructionAgentCostEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'agent_run_id',
        'cost_event_key',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'cost_usd',
        'occurred_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_usd' => 'decimal:6',
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
