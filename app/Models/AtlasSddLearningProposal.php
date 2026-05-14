<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proposal-only learning record. Per drift-detector-and-learning.md:137-138,
 * learning NEVER auto-mutates Kernel, Policy, provider routing, memory truth
 * or critical behaviour.
 */
class AtlasSddLearningProposal extends Model
{
    use HasUuids;

    protected $table = 'atlas_sdd_learning_proposals';

    protected $fillable = [
        'operation_id', 'drift_report_id', 'proposal_type', 'summary',
        'observation_json', 'proposal_json', 'evidence_refs_json',
        'status', 'decided_by', 'decided_at', 'decision_notes',
    ];

    protected function casts(): array
    {
        return [
            'observation_json' => 'array',
            'proposal_json' => 'array',
            'evidence_refs_json' => 'array',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function driftReport(): BelongsTo
    {
        return $this->belongsTo(AtlasSddDriftReport::class, 'drift_report_id');
    }
}
