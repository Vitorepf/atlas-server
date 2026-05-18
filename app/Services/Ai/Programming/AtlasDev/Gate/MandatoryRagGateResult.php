<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Gate;

use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;

/**
 * Structured result of the Mandatory RAG Gate.
 *
 * Canon schema: `atlas.dev.mandatory_rag_gate.v1`. Fields:
 *  - status: passed | blocked | bypassed
 *  - reason: machine-readable reason key
 *  - task_class: trivial | non_trivial
 *  - missing_sources: list of required_source refs declared missing
 *  - remediation: machine-readable remediation key
 *  - receipt: { plan_hash, compact_sdd_hash, run_id, bypass_audit? }
 *  - blockers: list of canonical blocker strings (orchestrator merges into RoutingDecision)
 */
final class MandatoryRagGateResult
{
    public const SCHEMA_VERSION = 'atlas.dev.mandatory_rag_gate.v1';

    public const STATUS_PASSED = 'passed';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_BYPASSED = 'bypassed';

    public const STATUSES = [
        self::STATUS_PASSED,
        self::STATUS_BLOCKED,
        self::STATUS_BYPASSED,
    ];

    public const CLASS_TRIVIAL = 'trivial';

    public const CLASS_NON_TRIVIAL = 'non_trivial';

    public const REASON_TRIVIAL_TASK_NO_GATE = 'trivial_task_no_gate_required';

    public const REASON_DELEGATION_NO_GATE = 'delegation_decided_gate_not_applicable';

    public const REASON_SUFFICIENT_CONTEXT = 'sufficient_context_for_non_trivial_task';

    public const REASON_NO_CONTEXT_PACK_HASH = 'context_pack_hash_missing';

    public const REASON_EMPTY_SELECTED_TIERS = 'selected_tiers_empty';

    public const REASON_MISSED_REQUIRED_SOURCES = 'missed_required_sources';

    public const REASON_NO_COMPACT_SDD_HASH = 'compact_sdd_hash_missing';

    public const REASON_BYPASS_APPLIED = 'explicit_auditable_bypass_applied';

    public const REMEDIATION_NONE = 'no_action_required';

    public const REMEDIATION_RERUN_RETRIEVAL = 'rerun_retrieval_pass_with_required_tier';

    public const REMEDIATION_ADD_REQUIRED_SOURCES = 'add_required_sources_to_workspace_or_open_brain';

    public const REMEDIATION_REGENERATE_PLAN = 'regenerate_context_retrieval_plan';

    public const REMEDIATION_OPERATOR_DECISION = 'request_operator_decision';

    public const BLOCKER_MANDATORY_RAG_INSUFFICIENT_CONTEXT = 'mandatory_rag_insufficient_context';

    public const BLOCKER_MANDATORY_RAG_MISSED_REQUIRED_SOURCES = 'mandatory_rag_missed_required_sources';

    public const BLOCKER_MANDATORY_RAG_NO_CONTEXT_PACK_HASH = 'mandatory_rag_no_context_pack_hash';

    /**
     * @param  list<string>  $missingSources
     * @param  array<string,mixed>  $receipt
     * @param  list<string>  $blockers
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $status,
        public readonly string $reason,
        public readonly string $taskClass,
        public readonly array $missingSources,
        public readonly string $remediation,
        public readonly array $receipt,
        public readonly array $blockers,
    ) {}

    public function isPassed(): bool
    {
        return $this->status === self::STATUS_PASSED;
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }

    public function isBypassed(): bool
    {
        return $this->status === self::STATUS_BYPASSED;
    }

    /**
     * @return array<string,mixed>
     */
    public function toCanonicalArray(): array
    {
        $payload = [
            'blockers' => array_values($this->blockers),
            'missing_sources' => array_values($this->missingSources),
            'reason' => $this->reason,
            'receipt' => CanonicalJson::canonicalize($this->receipt),
            'remediation' => $this->remediation,
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $this->status,
            'task_class' => $this->taskClass,
        ];
        $payload['result_hash'] = CanonicalHasher::hashWithout($payload, 'result_hash');

        return CanonicalJson::canonicalize($payload);
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->toCanonicalArray());
    }
}
