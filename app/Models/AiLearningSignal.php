<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inbox of raw learning observations collected from mission outcomes,
 * approvals, quality evaluations, follow-through cycles, forge handoffs,
 * dispatch blockers and other Atlas AI surfaces.
 *
 * Distinct from {@see AiLearningProposal}: a Signal is the raw observation;
 * a Proposal is the actionable suggestion derived from one or more signals.
 * A signal may or may not graduate to a proposal — that decision is governed
 * by the Learning Loop service with risk-level classification.
 *
 * Hard rule: a signal NEVER auto-applies behavior. It is read-only audit
 * material until promoted (with operator review) into an
 * {@see AiLearningProposal}.
 */
class AiLearningSignal extends Model
{
    use HasUuids;

    public const SCHEMA_VERSION = 'atlas.ai.learning_signal.v1';

    public const STATUS_COLLECTED = 'collected';

    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_EXPIRED = 'expired';

    public const RISK_LOW = 'low';

    public const RISK_CRITICAL = 'critical';

    protected $table = 'ai_learning_signals';

    protected $fillable = [
        'schema_version',
        'signal_id',
        'source_type',
        'source_id',
        'mission_id',
        'flow_id',
        'outcome',
        'quality_score',
        'failure_mode',
        'blocker_reason',
        'approval_decision',
        'evidence_refs',
        'proposed_memory_delta',
        'proposed_policy_delta',
        'proposed_rag_feedback',
        'requires_review',
        'risk_level',
        'status',
        'learning_proposal_id',
        'payload',
        'signal_hash',
        'collected_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence_refs' => 'array',
            'proposed_memory_delta' => 'array',
            'proposed_policy_delta' => 'array',
            'proposed_rag_feedback' => 'array',
            'requires_review' => 'boolean',
            'quality_score' => 'integer',
            'payload' => 'array',
            'collected_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(AiLearningProposal::class, 'learning_proposal_id');
    }
}
