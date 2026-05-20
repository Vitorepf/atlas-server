<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasAemorOutcome extends Model
{
    use HasUuids;

    protected $fillable = [
        'episode_id',
        'schema_version',
        'status',
        'outcome_type',
        'failure_signature',
        'summary',
        'metrics',
        'blockers',
        'evidence_refs',
        'context_utility',
        'patch_outcome',
        'claim_policy',
        'outcome_hash',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'blockers' => 'array',
            'evidence_refs' => 'array',
            'context_utility' => 'array',
            'patch_outcome' => 'array',
            'claim_policy' => 'array',
            'closed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(AtlasAemorExecutionEpisode::class, 'episode_id');
    }
}
