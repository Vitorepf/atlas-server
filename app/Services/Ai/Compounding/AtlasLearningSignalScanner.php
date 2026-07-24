<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Support\CanonicalValue;
use App\Models\AiAtlasRuntimeDispatch;
use App\Models\AiLearningProposal;
use App\Models\AiLearningSignal;
use App\Models\AiMission;
use App\Models\AiOperatorApproval;
use App\Models\AiQualityAction;
use App\Models\AiQualityEvaluation;
use App\Models\AiRealExecutionForgeHandoff;
use App\Models\AiTrace;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Atlas AI Memory & Learning Feedback Loop service.
 *
 * Closes the loop between Atlas AI's runtime outcomes and durable memory /
 * policy / routing knowledge — without auto-mutating critical behavior.
 *
 * Inputs (raw signals collected from):
 *   - completed/blocked missions ({@see AiMission})
 *   - operator approval decisions ({@see AiOperatorApproval})
 *   - quality evaluations + remediation actions ({@see AiQualityEvaluation},
 *     {@see AiQualityAction})
 *   - dispatch blockers ({@see AiAtlasRuntimeDispatch::$blockers})
 *   - failed traces ({@see AiTrace}::status='failed')
 *   - Forge handoff outcomes ({@see AiRealExecutionForgeHandoff})
 *
 * Outputs (governed):
 *   - {@see AiLearningSignal} — raw audit record (always persisted)
 *   - {@see AiLearningProposal} — actionable suggestion (only when signal
 *     has enough evidence and is not duplicate)
 *
 * Governance rules (hard):
 *   - Policy/routing/security/finance/programming critical → `requires_review=true`,
 *     `risk_level='critical'` — never auto-applied.
 *   - Harmless preferences (single thumbs_up on conversation, isolated
 *     positive feedback) → `risk_level='low'` but still `requires_review=true`
 *     per Atlas Compounding canon.
 *   - Failed/uncertain outcomes → SIGNAL collected, never promoted to memory fact.
 *   - Missing evidence → SIGNAL collected with `status='collected'`, but no
 *     proposal is created; surfaces as blocker in Control Plane.
 *   - Hash is deterministic over canonical content; duplicate signals are
 *     reused via `signal_hash` unique constraint instead of duplicated.
 */
class AtlasLearningSignalScanner
{
    public const SOURCE_MISSION_COMPLETED = 'mission_completed';

    public const SOURCE_MISSION_BLOCKED = 'mission_blocked';

    public const SOURCE_MISSION_FAILED = 'mission_failed';

    public const SOURCE_APPROVAL_APPROVED = 'approval_approved';

    public const SOURCE_APPROVAL_DENIED = 'approval_denied';

    public const SOURCE_QUALITY_FAILED = 'quality_failed';

    public const SOURCE_QUALITY_NEEDS_REVIEW = 'quality_needs_review';

    public const SOURCE_REMEDIATION_FAILED = 'remediation_failed';

    public const SOURCE_FORGE_HANDOFF_INCOMPLETE = 'forge_handoff_incomplete';

    public const SOURCE_DISPATCH_BLOCKED = 'dispatch_blocked';

    public const SOURCE_TRACE_FAILED = 'trace_failed';

    /**
     * Sources that are inherently critical and must always require review.
     */
    private const CRITICAL_SOURCES = [
        self::SOURCE_APPROVAL_DENIED,
        self::SOURCE_QUALITY_FAILED,
        self::SOURCE_FORGE_HANDOFF_INCOMPLETE,
        self::SOURCE_DISPATCH_BLOCKED,
        self::SOURCE_MISSION_FAILED,
        self::SOURCE_MISSION_BLOCKED,
        self::SOURCE_REMEDIATION_FAILED,
    ];

    /**
     * Sources that may produce a low-risk proposal when the outcome is
     * positive and isolated (still operator-reviewed, just fast-tracked).
     */
    private const LOW_RISK_SOURCES = [
        self::SOURCE_MISSION_COMPLETED,
        self::SOURCE_APPROVAL_APPROVED,
    ];

    /**
     * Domains where any signal must escalate to critical regardless of the
     * source — finance/cyber/programming touch operator capital, safety or
     * shared infrastructure.
     */
    private const CRITICAL_FLOW_PREFIXES = [
        'atlas_finance',
        'atlas_cyber',
        'atlas_dev',
        'atlas_forge',
        'atlas_automation',
    ];

    public function __construct(
        private readonly AtlasLearningProposalService $proposals,
    ) {}

    /**
     * Collect signals from the last $hours window. Returns a summary of what
     * was collected (counts per source, proposals minted, blockers).
     *
     * @param  array<string,mixed>  $options  reserved for future filters
     * @return array<string,mixed>
     */
    public function collect(int $hours = 24, array $options = []): array
    {
        $hours = max(1, $hours);
        $since = CarbonImmutable::now()->subHours($hours);
        $collectedAt = CarbonImmutable::now();
        $collected = [];

        foreach ($this->missionSignals($since) as $signal) {
            $collected[] = $this->persistSignal($signal, $collectedAt);
        }
        foreach ($this->approvalSignals($since) as $signal) {
            $collected[] = $this->persistSignal($signal, $collectedAt);
        }
        foreach ($this->qualitySignals($since) as $signal) {
            $collected[] = $this->persistSignal($signal, $collectedAt);
        }
        foreach ($this->remediationSignals($since) as $signal) {
            $collected[] = $this->persistSignal($signal, $collectedAt);
        }
        foreach ($this->forgeHandoffSignals($since) as $signal) {
            $collected[] = $this->persistSignal($signal, $collectedAt);
        }
        foreach ($this->dispatchBlockerSignals($since) as $signal) {
            $collected[] = $this->persistSignal($signal, $collectedAt);
        }
        foreach ($this->failedTraceSignals($since) as $signal) {
            $collected[] = $this->persistSignal($signal, $collectedAt);
        }

        $collected = array_values(array_filter($collected));

        $bySource = [];
        $proposalsMinted = 0;
        $proposalsSkippedMissingEvidence = 0;
        foreach ($collected as $entry) {
            $source = $entry['source_type'];
            $bySource[$source] = ($bySource[$source] ?? 0) + 1;
            if ($entry['proposal_id'] !== null) {
                $proposalsMinted++;
            } elseif ($entry['proposal_skipped_reason'] === 'missing_evidence') {
                $proposalsSkippedMissingEvidence++;
            }
        }

        return [
            'schema_version' => AiLearningSignal::SCHEMA_VERSION,
            'generated_at' => $collectedAt->toJSON(),
            'window' => [
                'hours' => $hours,
                'since' => $since->toJSON(),
                'until' => $collectedAt->toJSON(),
            ],
            'summary' => [
                'signals_collected' => count($collected),
                'proposals_minted' => $proposalsMinted,
                'proposals_skipped_missing_evidence' => $proposalsSkippedMissingEvidence,
                'by_source' => $bySource,
            ],
            'signals' => $collected,
        ];
    }

    /**
     * List signals + proposals with optional filters.
     *
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function list(array $filters = [], int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $signals = [];
        $proposals = [];

        if (DatabaseTableAvailability::has('ai_learning_signals')) {
            try {
                $query = AiLearningSignal::query()->orderByDesc('collected_at')->limit($limit);
                if (isset($filters['source_type'])) {
                    $query->where('source_type', $filters['source_type']);
                }
                if (isset($filters['risk_level'])) {
                    $query->where('risk_level', $filters['risk_level']);
                }
                if (isset($filters['status'])) {
                    $query->where('status', $filters['status']);
                }
                if (isset($filters['flow_id'])) {
                    $query->where('flow_id', $filters['flow_id']);
                }
                $signals = $query->get()->map(fn (AiLearningSignal $s): array => $this->signalToArray($s))->all();
            } catch (Throwable) {
                $signals = [];
            }
        }

        if (DatabaseTableAvailability::has('ai_learning_proposals')) {
            try {
                $proposalQuery = AiLearningProposal::query()->orderByDesc('updated_at')->limit($limit);
                if (isset($filters['proposal_status'])) {
                    $proposalQuery->where('status', $filters['proposal_status']);
                }
                if (isset($filters['kind'])) {
                    $proposalQuery->where('kind', $filters['kind']);
                }
                $proposals = $proposalQuery->get()->map(fn (AiLearningProposal $p): array => $this->proposalToArray($p))->all();
            } catch (Throwable) {
                $proposals = [];
            }
        }

        return [
            'schema_version' => AiLearningSignal::SCHEMA_VERSION,
            'generated_at' => CarbonImmutable::now()->toJSON(),
            'signals' => $signals,
            'proposals' => $proposals,
            'counts' => [
                'signals' => count($signals),
                'proposals' => count($proposals),
            ],
        ];
    }

    /**
     * Aggregated counts for Control Plane integration.
     *
     * @return array<string,mixed>
     */
    public function controlPlaneSummary(CarbonImmutable $since): array
    {
        $empty = [
            'status' => 'missing',
            'signals' => ['total' => 0, 'by_source' => [], 'by_risk_level' => [], 'by_status' => []],
            'proposals' => ['total' => 0, 'by_status' => [], 'by_kind' => [], 'pending_review' => 0],
            'blockers' => ['missing_evidence_signals' => 0],
            'last_signal_at' => null,
            'last_proposal_at' => null,
        ];

        if (! DatabaseTableAvailability::has('ai_learning_signals')) {
            return $empty;
        }

        try {
            $signals = AiLearningSignal::query()
                ->where('collected_at', '>=', $since)
                ->get(['source_type', 'risk_level', 'status', 'evidence_refs', 'collected_at']);
        } catch (Throwable) {
            return ['status' => 'degraded'] + $empty;
        }

        $bySource = [];
        $byRisk = [];
        $byStatus = [];
        $missingEvidence = 0;
        $lastSignalAt = null;
        foreach ($signals as $signal) {
            $bySource[$signal->source_type] = ($bySource[$signal->source_type] ?? 0) + 1;
            $byRisk[$signal->risk_level] = ($byRisk[$signal->risk_level] ?? 0) + 1;
            $byStatus[$signal->status] = ($byStatus[$signal->status] ?? 0) + 1;
            $refs = is_array($signal->evidence_refs) ? $signal->evidence_refs : [];
            if ($refs === []) {
                $missingEvidence++;
            }
            $collectedAt = $signal->collected_at?->toJSON();
            if ($collectedAt !== null && ($lastSignalAt === null || $collectedAt > $lastSignalAt)) {
                $lastSignalAt = $collectedAt;
            }
        }
        ksort($bySource);
        ksort($byRisk);
        ksort($byStatus);

        $proposals = [];
        $byProposalStatus = [];
        $byKind = [];
        $pendingReview = 0;
        $lastProposalAt = null;
        if (DatabaseTableAvailability::has('ai_learning_proposals')) {
            try {
                $proposals = AiLearningProposal::query()
                    ->where('created_at', '>=', $since)
                    ->get(['kind', 'status', 'requires_human_review', 'updated_at']);
                foreach ($proposals as $proposal) {
                    $byProposalStatus[$proposal->status] = ($byProposalStatus[$proposal->status] ?? 0) + 1;
                    $byKind[$proposal->kind] = ($byKind[$proposal->kind] ?? 0) + 1;
                    if ($proposal->status === 'proposed' && $proposal->requires_human_review) {
                        $pendingReview++;
                    }
                    $updatedAt = $proposal->updated_at?->toJSON();
                    if ($updatedAt !== null && ($lastProposalAt === null || $updatedAt > $lastProposalAt)) {
                        $lastProposalAt = $updatedAt;
                    }
                }
            } catch (Throwable) {
                // tolerate.
            }
        }
        ksort($byProposalStatus);
        ksort($byKind);

        return [
            'status' => 'ready',
            'signals' => [
                'total' => count($signals),
                'by_source' => $bySource,
                'by_risk_level' => $byRisk,
                'by_status' => $byStatus,
            ],
            'proposals' => [
                'total' => count($proposals),
                'by_status' => $byProposalStatus,
                'by_kind' => $byKind,
                'pending_review' => $pendingReview,
            ],
            'blockers' => ['missing_evidence_signals' => $missingEvidence],
            'last_signal_at' => $lastSignalAt,
            'last_proposal_at' => $lastProposalAt,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Signal collectors
    // ─────────────────────────────────────────────────────────────────

    /**
     * @return iterable<int,array<string,mixed>>
     */
    private function missionSignals(CarbonImmutable $since): iterable
    {
        if (! DatabaseTableAvailability::has('ai_missions')) {
            return [];
        }
        try {
            $missions = AiMission::query()
                ->where('updated_at', '>=', $since)
                ->whereIn('status', ['completed', 'failed', 'blocked', 'cancelled'])
                ->limit(200)
                ->get(['id', 'uuid', 'status', 'primary_domain', 'risk_level', 'blocker_reason', 'evidence_pack_hash', 'certification_hash', 'updated_at']);
        } catch (Throwable) {
            return [];
        }

        $signals = [];
        foreach ($missions as $mission) {
            $source = match ($mission->status) {
                'completed' => self::SOURCE_MISSION_COMPLETED,
                'failed' => self::SOURCE_MISSION_FAILED,
                'blocked' => self::SOURCE_MISSION_BLOCKED,
                default => self::SOURCE_MISSION_FAILED,
            };
            $evidenceRefs = array_values(array_filter([
                $mission->evidence_pack_hash ? ['type' => 'evidence_pack_hash', 'id' => $mission->evidence_pack_hash] : null,
                $mission->certification_hash ? ['type' => 'certification_hash', 'id' => $mission->certification_hash] : null,
                ['type' => 'mission_uuid', 'id' => $mission->uuid ?? (string) $mission->id],
            ]));
            $signals[] = [
                'source_type' => $source,
                'source_id' => $mission->uuid ?? (string) $mission->id,
                'mission_id' => (string) $mission->id,
                'flow_id' => null,
                'outcome' => $mission->status,
                'quality_score' => null,
                'failure_mode' => $source === self::SOURCE_MISSION_FAILED ? 'mission_failed' : null,
                'blocker_reason' => $mission->blocker_reason,
                'approval_decision' => null,
                'evidence_refs' => $evidenceRefs,
                'payload' => [
                    'primary_domain' => $mission->primary_domain,
                    'risk_level' => $mission->risk_level,
                ],
            ];
        }

        return $signals;
    }

    /**
     * @return iterable<int,array<string,mixed>>
     */
    private function approvalSignals(CarbonImmutable $since): iterable
    {
        if (! DatabaseTableAvailability::has('ai_operator_approvals')) {
            return [];
        }
        try {
            $approvals = AiOperatorApproval::query()
                ->where('decided_at', '>=', $since)
                ->whereNotNull('operator_decision')
                ->limit(200)
                ->get(['id', 'uuid', 'mission_id', 'requested_action', 'risk_level', 'operator', 'operator_decision', 'evidence_refs', 'receipt_hash', 'decided_at']);
        } catch (Throwable) {
            return [];
        }

        $signals = [];
        foreach ($approvals as $approval) {
            $decision = $approval->operator_decision;
            if ($decision === null) {
                continue;
            }
            $source = $decision === 'approve' || $decision === 'approved'
                ? self::SOURCE_APPROVAL_APPROVED
                : self::SOURCE_APPROVAL_DENIED;
            $evidenceRefs = is_array($approval->evidence_refs) ? $approval->evidence_refs : [];
            if ($approval->receipt_hash) {
                $evidenceRefs[] = ['type' => 'approval_receipt_hash', 'id' => $approval->receipt_hash];
            }
            $signals[] = [
                'source_type' => $source,
                'source_id' => $approval->uuid ?? (string) $approval->id,
                'mission_id' => $approval->mission_id ? (string) $approval->mission_id : null,
                'flow_id' => null,
                'outcome' => $source === self::SOURCE_APPROVAL_APPROVED ? 'approved' : 'denied',
                'quality_score' => null,
                'failure_mode' => null,
                'blocker_reason' => null,
                'approval_decision' => $decision,
                'evidence_refs' => $evidenceRefs,
                'payload' => [
                    'requested_action' => $approval->requested_action,
                    'approval_risk_level' => $approval->risk_level,
                    'operator' => $approval->operator,
                ],
            ];
        }

        return $signals;
    }

    /**
     * @return iterable<int,array<string,mixed>>
     */
    private function qualitySignals(CarbonImmutable $since): iterable
    {
        if (! DatabaseTableAvailability::has('ai_quality_evaluations')) {
            return [];
        }
        try {
            $evaluations = AiQualityEvaluation::query()
                ->where('created_at', '>=', $since)
                ->whereIn('status', ['failed', 'needs_review'])
                ->limit(200)
                ->get(['id', 'trace_id', 'status', 'score', 'flags', 'suggested_actions']);
        } catch (Throwable) {
            return [];
        }

        $signals = [];
        foreach ($evaluations as $evaluation) {
            $source = $evaluation->status === 'failed'
                ? self::SOURCE_QUALITY_FAILED
                : self::SOURCE_QUALITY_NEEDS_REVIEW;
            $flags = collect(is_array($evaluation->flags) ? $evaluation->flags : [])
                ->map(fn ($flag): ?string => is_array($flag) ? ($this->stringOrNull($flag['code'] ?? null)) : $this->stringOrNull($flag))
                ->filter()
                ->values()
                ->all();
            $signals[] = [
                'source_type' => $source,
                'source_id' => $evaluation->trace_id ? (string) $evaluation->trace_id : (string) $evaluation->id,
                'mission_id' => null,
                'flow_id' => null,
                'outcome' => $evaluation->status,
                'quality_score' => is_numeric($evaluation->score) ? (int) $evaluation->score : null,
                'failure_mode' => $flags === [] ? null : implode(',', array_slice($flags, 0, 3)),
                'blocker_reason' => null,
                'approval_decision' => null,
                'evidence_refs' => array_values(array_filter([
                    $evaluation->trace_id ? ['type' => 'trace_id', 'id' => (string) $evaluation->trace_id] : null,
                    ['type' => 'quality_evaluation_id', 'id' => (string) $evaluation->id],
                ])),
                'payload' => [
                    'flags' => $flags,
                    'suggested_actions' => is_array($evaluation->suggested_actions) ? $evaluation->suggested_actions : [],
                ],
            ];
        }

        return $signals;
    }

    /**
     * @return iterable<int,array<string,mixed>>
     */
    private function remediationSignals(CarbonImmutable $since): iterable
    {
        if (! DatabaseTableAvailability::has('ai_quality_actions')) {
            return [];
        }
        try {
            $actions = AiQualityAction::query()
                ->where('created_at', '>=', $since)
                ->whereIn('status', ['failed', 'blocked'])
                ->limit(200)
                ->get(['id', 'trace_id', 'action_type', 'status', 'reason', 'error_message']);
        } catch (Throwable) {
            return [];
        }

        $signals = [];
        foreach ($actions as $action) {
            $signals[] = [
                'source_type' => self::SOURCE_REMEDIATION_FAILED,
                'source_id' => $action->trace_id ? (string) $action->trace_id : (string) $action->id,
                'mission_id' => null,
                'flow_id' => null,
                'outcome' => $action->status,
                'quality_score' => null,
                'failure_mode' => $this->truncate($action->error_message ?? $action->reason, 160),
                'blocker_reason' => null,
                'approval_decision' => null,
                'evidence_refs' => array_values(array_filter([
                    $action->trace_id ? ['type' => 'trace_id', 'id' => (string) $action->trace_id] : null,
                    ['type' => 'quality_action_id', 'id' => (string) $action->id],
                ])),
                'payload' => [
                    'action_type' => $action->action_type,
                ],
            ];
        }

        return $signals;
    }

    /**
     * @return iterable<int,array<string,mixed>>
     */
    private function forgeHandoffSignals(CarbonImmutable $since): iterable
    {
        if (! DatabaseTableAvailability::has('ai_real_execution_forge_handoffs')) {
            return [];
        }
        try {
            $handoffs = AiRealExecutionForgeHandoff::query()
                ->where('created_at', '>=', $since)
                ->whereNotIn('status', ['succeeded', 'completed', 'closed'])
                ->limit(200)
                ->get(['id', 'handoff_id', 'goal_record_id', 'status', 'handoff_hash']);
        } catch (Throwable) {
            return [];
        }

        $signals = [];
        foreach ($handoffs as $handoff) {
            $signals[] = [
                'source_type' => self::SOURCE_FORGE_HANDOFF_INCOMPLETE,
                'source_id' => $handoff->handoff_id ?? (string) $handoff->id,
                'mission_id' => null,
                'flow_id' => 'atlas_forge',
                'outcome' => $handoff->status,
                'quality_score' => null,
                'failure_mode' => 'forge_handoff_not_terminal',
                'blocker_reason' => 'Forge handoff has not reached succeeded/completed/closed terminal status.',
                'approval_decision' => null,
                'evidence_refs' => array_values(array_filter([
                    $handoff->handoff_hash ? ['type' => 'handoff_hash', 'id' => $handoff->handoff_hash] : null,
                    $handoff->goal_record_id ? ['type' => 'goal_record_id', 'id' => (string) $handoff->goal_record_id] : null,
                    ['type' => 'forge_handoff_id', 'id' => $handoff->handoff_id ?? (string) $handoff->id],
                ])),
                'payload' => [],
            ];
        }

        return $signals;
    }

    /**
     * @return iterable<int,array<string,mixed>>
     */
    private function dispatchBlockerSignals(CarbonImmutable $since): iterable
    {
        if (! DatabaseTableAvailability::has('ai_atlas_runtime_dispatches')) {
            return [];
        }
        try {
            $dispatches = AiAtlasRuntimeDispatch::query()
                ->where('created_at', '>=', $since)
                ->whereNotNull('blockers')
                ->limit(200)
                ->get(['id', 'dispatch_target', 'dispatch_status', 'blockers', 'receipt_hash']);
        } catch (Throwable) {
            return [];
        }

        $signals = [];
        foreach ($dispatches as $dispatch) {
            $reasons = is_array($dispatch->blockers) ? $dispatch->blockers : [];
            if ($reasons === []) {
                continue;
            }
            foreach ($reasons as $reason) {
                $reasonText = is_array($reason)
                    ? ($this->stringOrNull($reason['reason'] ?? null) ?? 'unspecified')
                    : (string) $reason;
                $signals[] = [
                    'source_type' => self::SOURCE_DISPATCH_BLOCKED,
                    'source_id' => (string) $dispatch->id,
                    'mission_id' => null,
                    'flow_id' => $this->stringOrNull($dispatch->dispatch_target),
                    'outcome' => $dispatch->dispatch_status,
                    'quality_score' => null,
                    'failure_mode' => 'dispatch_blocked',
                    'blocker_reason' => $reasonText,
                    'approval_decision' => null,
                    'evidence_refs' => array_values(array_filter([
                        $dispatch->receipt_hash ? ['type' => 'dispatch_receipt_hash', 'id' => $dispatch->receipt_hash] : null,
                        ['type' => 'dispatch_id', 'id' => (string) $dispatch->id],
                    ])),
                    'payload' => [
                        'dispatch_target' => $dispatch->dispatch_target,
                    ],
                ];
            }
        }

        return $signals;
    }

    /**
     * @return iterable<int,array<string,mixed>>
     */
    private function failedTraceSignals(CarbonImmutable $since): iterable
    {
        if (! DatabaseTableAvailability::has('ai_traces')) {
            return [];
        }
        try {
            $traces = AiTrace::query()
                ->where('created_at', '>=', $since)
                ->where('status', 'failed')
                ->with(['job' => fn ($q) => $q->select(['id', 'trace_id', 'error_code', 'error_message'])])
                ->limit(200)
                ->get(['id', 'status', 'provider', 'metadata']);
        } catch (Throwable) {
            return [];
        }

        $signals = [];
        foreach ($traces as $trace) {
            $job = $trace->job;
            $flowId = $this->flowIdFromTrace($trace);
            $signals[] = [
                'source_type' => self::SOURCE_TRACE_FAILED,
                'source_id' => (string) $trace->id,
                'mission_id' => null,
                'flow_id' => $flowId,
                'outcome' => 'failed',
                'quality_score' => null,
                'failure_mode' => $this->truncate($job?->error_code ?? $job?->error_message, 160),
                'blocker_reason' => null,
                'approval_decision' => null,
                'evidence_refs' => array_values(array_filter([
                    ['type' => 'trace_id', 'id' => (string) $trace->id],
                    $job ? ['type' => 'job_id', 'id' => (string) $job->id] : null,
                ])),
                'payload' => [
                    'provider' => $trace->provider,
                ],
            ];
        }

        return $signals;
    }

    // ─────────────────────────────────────────────────────────────────
    // Persistence + proposal generation
    // ─────────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>|null
     */
    private function persistSignal(array $raw, CarbonImmutable $collectedAt): ?array
    {
        if (! DatabaseTableAvailability::has('ai_learning_signals')) {
            return null;
        }

        $evidenceRefs = is_array($raw['evidence_refs'] ?? null) ? $raw['evidence_refs'] : [];
        $riskLevel = $this->classifyRisk($raw['source_type'], $raw['flow_id'] ?? null);
        $requiresReview = true; // hard rule
        $signalHash = $this->hashSignal($raw);
        $signalId = 'als_'.substr($signalHash, 0, 24);

        try {
            $existing = AiLearningSignal::query()->where('signal_hash', $signalHash)->first();
        } catch (Throwable) {
            return null;
        }
        if ($existing instanceof AiLearningSignal) {
            return $this->signalSummaryFromModel($existing, 'duplicate');
        }

        $signal = new AiLearningSignal([
            'schema_version' => AiLearningSignal::SCHEMA_VERSION,
            'signal_id' => $signalId,
            'source_type' => $raw['source_type'],
            'source_id' => $this->stringOrNull($raw['source_id'] ?? null),
            'mission_id' => $this->uuidOrNull($raw['mission_id'] ?? null),
            'flow_id' => $this->stringOrNull($raw['flow_id'] ?? null),
            'outcome' => $this->stringOrNull($raw['outcome'] ?? null),
            'quality_score' => $raw['quality_score'] ?? null,
            'failure_mode' => $this->stringOrNull($raw['failure_mode'] ?? null),
            'blocker_reason' => $this->stringOrNull($raw['blocker_reason'] ?? null),
            'approval_decision' => $this->stringOrNull($raw['approval_decision'] ?? null),
            'evidence_refs' => $evidenceRefs,
            'proposed_memory_delta' => $raw['proposed_memory_delta'] ?? null,
            'proposed_policy_delta' => $raw['proposed_policy_delta'] ?? null,
            'proposed_rag_feedback' => $raw['proposed_rag_feedback'] ?? null,
            'requires_review' => $requiresReview,
            'risk_level' => $riskLevel,
            'status' => AiLearningSignal::STATUS_COLLECTED,
            'payload' => is_array($raw['payload'] ?? null) ? $raw['payload'] : null,
            'signal_hash' => $signalHash,
            'collected_at' => $collectedAt,
        ]);

        try {
            $signal->save();
        } catch (Throwable) {
            return null;
        }

        $proposalId = null;
        $proposalSkippedReason = null;
        if ($evidenceRefs === []) {
            $proposalSkippedReason = 'missing_evidence';
        } else {
            $proposal = $this->proposals->proposeFromSignal($signal);
            if ($proposal !== null) {
                $proposalId = $proposal->id;
                $signal->learning_proposal_id = $proposal->id;
                $signal->status = AiLearningSignal::STATUS_PROPOSED;
                $signal->save();
            } else {
                $proposalSkippedReason = 'no_actionable_pattern';
            }
        }

        return $this->signalSummary($signal, $proposalId, $proposalSkippedReason);
    }

    private function classifyRisk(string $sourceType, ?string $flowId): string
    {
        if ($flowId !== null) {
            foreach (self::CRITICAL_FLOW_PREFIXES as $prefix) {
                if (str_starts_with($flowId, $prefix)) {
                    return AiLearningSignal::RISK_CRITICAL;
                }
            }
        }
        if (in_array($sourceType, self::CRITICAL_SOURCES, true)) {
            return AiLearningSignal::RISK_CRITICAL;
        }
        if (in_array($sourceType, self::LOW_RISK_SOURCES, true)) {
            return AiLearningSignal::RISK_LOW;
        }

        return AiLearningSignal::RISK_CRITICAL;
    }

    /**
     * @param  array<string,mixed>  $raw
     */
    private function hashSignal(array $raw): string
    {
        $canonical = $this->canonicalize([
            'source_type' => $raw['source_type'],
            'source_id' => $raw['source_id'] ?? null,
            'mission_id' => $raw['mission_id'] ?? null,
            'flow_id' => $raw['flow_id'] ?? null,
            'outcome' => $raw['outcome'] ?? null,
            'failure_mode' => $raw['failure_mode'] ?? null,
            'blocker_reason' => $raw['blocker_reason'] ?? null,
            'approval_decision' => $raw['approval_decision'] ?? null,
            'evidence_refs' => $raw['evidence_refs'] ?? [],
        ]);

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    private function canonicalize(mixed $value): mixed
    {
        return CanonicalValue::canonicalize($value);
    }

    /**
     * @return array<string,mixed>
     */
    private function signalToArray(AiLearningSignal $signal): array
    {
        return [
            'id' => (string) $signal->id,
            'signal_id' => $signal->signal_id,
            'source_type' => $signal->source_type,
            'source_id' => $signal->source_id,
            'mission_id' => $signal->mission_id,
            'flow_id' => $signal->flow_id,
            'outcome' => $signal->outcome,
            'quality_score' => $signal->quality_score,
            'failure_mode' => $signal->failure_mode,
            'blocker_reason' => $signal->blocker_reason,
            'approval_decision' => $signal->approval_decision,
            'evidence_refs' => is_array($signal->evidence_refs) ? $signal->evidence_refs : [],
            'requires_review' => (bool) $signal->requires_review,
            'risk_level' => $signal->risk_level,
            'status' => $signal->status,
            'learning_proposal_id' => $signal->learning_proposal_id,
            'signal_hash' => $signal->signal_hash,
            'collected_at' => $signal->collected_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function proposalToArray(AiLearningProposal $proposal): array
    {
        return [
            'id' => (string) $proposal->id,
            'kind' => $proposal->kind,
            'status' => $proposal->status,
            'scope' => $proposal->scope,
            'flow_id' => $proposal->flow_id,
            'summary' => $proposal->summary,
            'requires_human_review' => (bool) $proposal->requires_human_review,
            'decided_by' => $proposal->decided_by,
            'decided_at' => $proposal->decided_at?->toJSON(),
            'proposal_hash' => $proposal->proposal_hash,
            'evidence_refs' => is_array($proposal->evidence_refs) ? $proposal->evidence_refs : [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function signalSummary(AiLearningSignal $signal, ?string $proposalId, ?string $proposalSkippedReason): array
    {
        return [
            'signal_id' => $signal->signal_id,
            'source_type' => $signal->source_type,
            'risk_level' => $signal->risk_level,
            'status' => $signal->status,
            'requires_review' => (bool) $signal->requires_review,
            'proposal_id' => $proposalId,
            'proposal_skipped_reason' => $proposalSkippedReason,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function signalSummaryFromModel(AiLearningSignal $signal, string $tag): array
    {
        return [
            'signal_id' => $signal->signal_id,
            'source_type' => $signal->source_type,
            'risk_level' => $signal->risk_level,
            'status' => $signal->status.':'.$tag,
            'requires_review' => (bool) $signal->requires_review,
            'proposal_id' => $signal->learning_proposal_id,
            'proposal_skipped_reason' => null,
        ];
    }

    private function flowIdFromTrace(AiTrace $trace): ?string
    {
        $metadata = is_array($trace->metadata) ? $trace->metadata : [];

        return $this->stringOrNull(data_get($metadata, 'hyperflow_runtime.flow_route.flow_id'))
            ?? $this->stringOrNull(data_get($metadata, 'hyperflow_runtime.flow_id'))
            ?? $this->stringOrNull(data_get($metadata, 'specialist_flow_runtime.flow_id'))
            ?? $this->stringOrNull(data_get($metadata, 'flow_id'));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function uuidOrNull(mixed $value): ?string
    {
        $string = $this->stringOrNull($value);
        if ($string === null) {
            return null;
        }
        if (preg_match('/^[0-9a-fA-F-]{32,36}$/', $string) === 1) {
            return $string;
        }

        return null;
    }

    private function truncate(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed) <= $max) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, $max - 1).'…';
    }
}
