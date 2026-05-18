<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proposal-only learning record produced by the Compounding feedback loop.
 *
 * Hard law (atlas-compounding-engineering-intelligence.md:258-267):
 *   Heuristic, policy, routing, gate or benchmark changes never auto-mutate
 *   runtime behaviour. They become proposals awaiting human approval.
 */
class AiLearningProposal extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'kind',
        'status',
        'scope',
        'flow_id',
        'summary',
        'current_state',
        'proposed_state',
        'evidence_refs',
        'run_outcome_id',
        'learning_candidate_id',
        'rag_feedback_id',
        'requires_human_review',
        'decided_by',
        'decided_at',
        'decision_notes',
        'payload',
        'proposal_hash',
    ];

    protected function casts(): array
    {
        return [
            'current_state' => 'array',
            'proposed_state' => 'array',
            'evidence_refs' => 'array',
            'payload' => 'array',
            'requires_human_review' => 'boolean',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function runOutcome(): BelongsTo
    {
        return $this->belongsTo(AiRunOutcome::class, 'run_outcome_id');
    }

    public function learningCandidate(): BelongsTo
    {
        return $this->belongsTo(AiLearningCandidate::class, 'learning_candidate_id');
    }

    public function ragFeedback(): BelongsTo
    {
        return $this->belongsTo(AiRagFeedbackEvent::class, 'rag_feedback_id');
    }
}
