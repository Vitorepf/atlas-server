<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasSelfConstructionAgentWorkProduct extends Model
{
    use HasUuids;

    protected $fillable = [
        'agent_run_id',
        'work_product_key',
        'packet_id',
        'actor',
        'provider',
        'artifact_type',
        'artifact_path',
        'artifact_hash',
        'status',
        'summary',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
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
