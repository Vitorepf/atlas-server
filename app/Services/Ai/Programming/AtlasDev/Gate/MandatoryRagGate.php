<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Gate;

use App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecision;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassification;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\ContextRetrievalPlan;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;

/**
 * Mandatory RAG Gate — fail-closed for non-trivial engineering tasks.
 *
 * Atlas Dev / Atlas Forge must never execute non-trivial engineering work in
 * the dark. The gate consumes the deterministic artifacts produced earlier
 * in the plan-only pipeline (operation envelope, classification, compact
 * SDD, context retrieval plan, routing decision) and decides whether the
 * run can proceed.
 *
 * Trivial vs non-trivial is decided pragmatically (see classifyTask()).
 * Bypass is allowed only when the operator passes an explicit auditable
 * constraint AND the config flag is on; every bypass record carries an
 * audit trail.
 */
final class MandatoryRagGate
{
    public const CONFIG_KEY = 'atlas_dev.mandatory_rag_gate';

    public const BYPASS_CONSTRAINT_PREFIX = 'mandatory_rag_gate:bypass';

    public const BYPASS_REASON_CONSTRAINT_PREFIX = 'mandatory_rag_gate:bypass_reason=';

    /**
     * Task kinds that intrinsically require write or change in the
     * workspace and therefore demand full context retrieval.
     *
     * @var list<string>
     */
    private const WRITE_TASK_KINDS = [
        TaskClassification::KIND_PATCH,
        TaskClassification::KIND_REPAIR,
        TaskClassification::KIND_FRONTEND,
        TaskClassification::KIND_RISKY,
    ];

    /**
     * Risk levels at which the gate is mandatory even for read-only task kinds.
     *
     * @var list<string>
     */
    private const HIGH_RISK_LEVELS = ['R2', 'R3', 'R4', 'R5'];

    public function __construct(private readonly ?ConfigRepository $config = null) {}

    public function evaluate(
        OperationEnvelope $envelope,
        TaskClassification $classification,
        CompactSdd $compactSdd,
        ContextRetrievalPlan $contextPlan,
        RoutingDecision $routing,
    ): MandatoryRagGateResult {
        $runId = $envelope->runId;
        $taskClass = $this->classifyTask($classification, $compactSdd, $routing);
        $receiptBase = [
            'compact_sdd_hash' => $compactSdd->compactSddHash,
            'plan_hash' => $contextPlan->planHash,
            'routing_kind' => $routing->kind,
            'run_id' => $runId,
            'task_kind' => $classification->taskKind,
            'risk_level' => $compactSdd->riskLevel,
        ];

        // Delegations forward control to another Atlas AI flow, so the gate
        // is not applicable here. Persist the result anyway for auditability.
        if ($routing->kind === RoutingDecision::DELEGATE_TO_OTHER_FLOW) {
            return new MandatoryRagGateResult(
                runId: $runId,
                status: MandatoryRagGateResult::STATUS_PASSED,
                reason: MandatoryRagGateResult::REASON_DELEGATION_NO_GATE,
                taskClass: MandatoryRagGateResult::CLASS_TRIVIAL,
                missingSources: [],
                remediation: MandatoryRagGateResult::REMEDIATION_NONE,
                receipt: $receiptBase,
                blockers: [],
            );
        }

        if ($taskClass === MandatoryRagGateResult::CLASS_TRIVIAL) {
            return new MandatoryRagGateResult(
                runId: $runId,
                status: MandatoryRagGateResult::STATUS_PASSED,
                reason: MandatoryRagGateResult::REASON_TRIVIAL_TASK_NO_GATE,
                taskClass: MandatoryRagGateResult::CLASS_TRIVIAL,
                missingSources: [],
                remediation: MandatoryRagGateResult::REMEDIATION_NONE,
                receipt: $receiptBase,
                blockers: [],
            );
        }

        $bypass = $this->tryBypass($envelope, $receiptBase);
        if ($bypass !== null) {
            return $bypass;
        }

        // Hard checks for non-trivial tasks.
        if ($contextPlan->selectedTiers === []) {
            return new MandatoryRagGateResult(
                runId: $runId,
                status: MandatoryRagGateResult::STATUS_BLOCKED,
                reason: MandatoryRagGateResult::REASON_EMPTY_SELECTED_TIERS,
                taskClass: MandatoryRagGateResult::CLASS_NON_TRIVIAL,
                missingSources: [],
                remediation: MandatoryRagGateResult::REMEDIATION_RERUN_RETRIEVAL,
                receipt: $receiptBase,
                blockers: [MandatoryRagGateResult::BLOCKER_MANDATORY_RAG_INSUFFICIENT_CONTEXT],
            );
        }
        if ($contextPlan->planHash === '') {
            return new MandatoryRagGateResult(
                runId: $runId,
                status: MandatoryRagGateResult::STATUS_BLOCKED,
                reason: MandatoryRagGateResult::REASON_NO_CONTEXT_PACK_HASH,
                taskClass: MandatoryRagGateResult::CLASS_NON_TRIVIAL,
                missingSources: [],
                remediation: MandatoryRagGateResult::REMEDIATION_REGENERATE_PLAN,
                receipt: $receiptBase,
                blockers: [MandatoryRagGateResult::BLOCKER_MANDATORY_RAG_NO_CONTEXT_PACK_HASH],
            );
        }
        if ($compactSdd->compactSddHash === '') {
            return new MandatoryRagGateResult(
                runId: $runId,
                status: MandatoryRagGateResult::STATUS_BLOCKED,
                reason: MandatoryRagGateResult::REASON_NO_COMPACT_SDD_HASH,
                taskClass: MandatoryRagGateResult::CLASS_NON_TRIVIAL,
                missingSources: [],
                remediation: MandatoryRagGateResult::REMEDIATION_REGENERATE_PLAN,
                receipt: $receiptBase,
                blockers: [MandatoryRagGateResult::BLOCKER_MANDATORY_RAG_NO_CONTEXT_PACK_HASH],
            );
        }

        $missed = $this->missedRequiredSources($contextPlan);
        if ($missed !== []) {
            return new MandatoryRagGateResult(
                runId: $runId,
                status: MandatoryRagGateResult::STATUS_BLOCKED,
                reason: MandatoryRagGateResult::REASON_MISSED_REQUIRED_SOURCES,
                taskClass: MandatoryRagGateResult::CLASS_NON_TRIVIAL,
                missingSources: $missed,
                remediation: MandatoryRagGateResult::REMEDIATION_ADD_REQUIRED_SOURCES,
                receipt: $receiptBase,
                blockers: [MandatoryRagGateResult::BLOCKER_MANDATORY_RAG_MISSED_REQUIRED_SOURCES],
            );
        }

        return new MandatoryRagGateResult(
            runId: $runId,
            status: MandatoryRagGateResult::STATUS_PASSED,
            reason: MandatoryRagGateResult::REASON_SUFFICIENT_CONTEXT,
            taskClass: MandatoryRagGateResult::CLASS_NON_TRIVIAL,
            missingSources: [],
            remediation: MandatoryRagGateResult::REMEDIATION_NONE,
            receipt: $receiptBase,
            blockers: [],
        );
    }

    private function classifyTask(
        TaskClassification $classification,
        CompactSdd $compactSdd,
        RoutingDecision $routing,
    ): string {
        if ($routing->kind === RoutingDecision::DELEGATE_TO_OTHER_FLOW) {
            return MandatoryRagGateResult::CLASS_TRIVIAL;
        }

        $taskKind = $classification->taskKind;
        $isWriteKind = $classification->writeImplied
            || in_array($taskKind, self::WRITE_TASK_KINDS, true);

        if ($isWriteKind) {
            return MandatoryRagGateResult::CLASS_NON_TRIVIAL;
        }

        if (in_array($compactSdd->riskLevel, self::HIGH_RISK_LEVELS, true)) {
            return MandatoryRagGateResult::CLASS_NON_TRIVIAL;
        }

        // Read-only kinds at low risk: trivial.
        if (in_array($taskKind, [
            TaskClassification::KIND_QUESTION,
            TaskClassification::KIND_REVIEW,
        ], true) && ! $classification->writeImplied) {
            return MandatoryRagGateResult::CLASS_TRIVIAL;
        }

        // Anything else falls through to non-trivial for safety (fail-closed
        // by default).
        return MandatoryRagGateResult::CLASS_NON_TRIVIAL;
    }

    /**
     * @param  array<string,mixed>  $receiptBase
     */
    private function tryBypass(OperationEnvelope $envelope, array $receiptBase): ?MandatoryRagGateResult
    {
        $enabled = $this->bypassConfig('bypass_enabled', false);
        if ($enabled !== true) {
            return null;
        }
        $constraints = $envelope->userConstraints;
        $hasBypassFlag = false;
        $bypassReason = null;
        foreach ($constraints as $constraint) {
            if (! is_string($constraint)) {
                continue;
            }
            $lower = strtolower(trim($constraint));
            if ($lower === self::BYPASS_CONSTRAINT_PREFIX) {
                $hasBypassFlag = true;
            }
            if (str_starts_with($lower, self::BYPASS_REASON_CONSTRAINT_PREFIX)) {
                $bypassReason = trim(substr($constraint, strlen(self::BYPASS_REASON_CONSTRAINT_PREFIX)));
            }
        }
        if (! $hasBypassFlag || $bypassReason === null || $bypassReason === '') {
            return null;
        }

        $allowedSurfaces = (array) $this->bypassConfig('allowed_surfaces', []);
        $surface = (string) $envelope->surfaceContext->productSurface;
        if ($allowedSurfaces !== [] && ! in_array($surface, $allowedSurfaces, true)) {
            return null;
        }

        $receipt = $receiptBase + [
            'bypass_audit' => [
                'surface' => $surface,
                'reason' => $bypassReason,
                'recorded_at' => Carbon::now()->toJSON(),
                'enabled_via_config' => true,
            ],
        ];

        return new MandatoryRagGateResult(
            runId: $envelope->runId,
            status: MandatoryRagGateResult::STATUS_BYPASSED,
            reason: MandatoryRagGateResult::REASON_BYPASS_APPLIED,
            taskClass: MandatoryRagGateResult::CLASS_NON_TRIVIAL,
            missingSources: [],
            remediation: MandatoryRagGateResult::REMEDIATION_NONE,
            receipt: $receipt,
            blockers: [],
        );
    }

    /**
     * @return list<string>
     */
    private function missedRequiredSources(ContextRetrievalPlan $contextPlan): array
    {
        if ($contextPlan->requiredSources === []) {
            return [];
        }
        if ($contextPlan->missingSources === []) {
            return [];
        }

        return array_values(array_intersect(
            $contextPlan->requiredSources,
            $contextPlan->missingSources,
        ));
    }

    private function bypassConfig(string $key, mixed $default = null): mixed
    {
        $repo = $this->config;
        if ($repo !== null) {
            return $repo->get(self::CONFIG_KEY.'.'.$key, $default);
        }
        if (function_exists('config')) {
            try {
                return config(self::CONFIG_KEY.'.'.$key, $default);
            } catch (\Throwable) {
                return $default;
            }
        }

        return $default;
    }
}
