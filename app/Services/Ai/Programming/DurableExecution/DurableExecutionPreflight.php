<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\DurableExecution;

/**
 * Programming Harness — Durable Execution Preflight.
 *
 * Implements AP-283 / Safe Next Block #2 of the Atlas Agentic Engineering
 * OS scaffold matrix ("Programming harness durable execution"). The DoD
 * from `implemented-vs-scaffold-matrix.md` is:
 *
 *   "Unificar execucao pesada atras de Decision Receipt, Evidence Ledger,
 *    repair policy e AP/harness existente sem criar fluxo paralelo ao
 *    `atlas dev/forge`."
 *
 * This preflight is the first slice — it validates that a programming
 * work item is safe to send through a durable execution worker before
 * any provider invocation happens. It does NOT execute; it returns an
 * envelope the caller (worker/CLI/HTTP controller) consults.
 *
 * Named per the `atlas-self-construction-catalog.md` naming policy
 * (`<=50 chars` for new classes); the original AP-283 filename was 160
 * chars, which would violate the policy. The AP is referenced via the
 * canonical schema_version so cross-walks remain machine-readable.
 *
 * Consumes inputs from Atlas Dev's existing schemas (PlanVisible,
 * MiniProgrammingSpec) without rewriting them. Emits its own envelope
 * `atlas.programming.durable_execution_preflight.v1`.
 */
final class DurableExecutionPreflight
{
    public const SCHEMA_VERSION = 'atlas.programming.durable_execution_preflight.v1';

    public const AP_REFERENCE = 'AP-283';

    public const STATUS_READY = 'ready_for_durable_execution';

    public const STATUS_BLOCKED_PLAN_NOT_APPROVED = 'blocked_plan_not_approved';

    public const STATUS_BLOCKED_SPEC_MISSING = 'blocked_spec_missing';

    public const STATUS_BLOCKED_HIGH_RISK_NO_TESTS = 'blocked_high_risk_without_tests';

    public const STATUS_BLOCKED_RECEIPT_MISSING = 'blocked_decision_receipt_missing';

    public const STATUS_BLOCKED_REPAIR_BUDGET_EXHAUSTED = 'blocked_repair_budget_exhausted';

    /**
     * Evaluate a work item snapshot for durable execution readiness.
     *
     * @param  array{
     *   work_item_id?: string,
     *   spec_hash?: ?string,
     *   plan_hash?: ?string,
     *   plan_json?: array<string,mixed>,
     *   decision_receipt?: ?array<string,mixed>,
     *   repair_attempts_so_far?: int,
     *   repair_budget?: int,
     *   risk_level?: string,
     * }  $snapshot
     * @return array{
     *   schema_version: string,
     *   ap_reference: string,
     *   status: string,
     *   may_execute: bool,
     *   blocking_reasons: list<string>,
     *   checks: array<string,array{passed: bool, reason: ?string}>,
     *   evidence: array{
     *     work_item_id: ?string,
     *     spec_hash: ?string,
     *     plan_hash: ?string,
     *     plan_approval_status: ?string,
     *     risk_level: ?string,
     *     repair_budget_remaining: ?int,
     *     decision_receipt_present: bool
     *   }
     * }
     */
    public function evaluate(array $snapshot): array
    {
        $checks = [
            'spec_present' => $this->checkSpecPresent($snapshot),
            'plan_approved' => $this->checkPlanApproved($snapshot),
            'high_risk_has_tests' => $this->checkHighRiskHasTests($snapshot),
            'decision_receipt_present' => $this->checkDecisionReceipt($snapshot),
            'repair_budget_remaining' => $this->checkRepairBudget($snapshot),
        ];

        $blockingReasons = [];
        if (! $checks['spec_present']['passed']) {
            $blockingReasons[] = self::STATUS_BLOCKED_SPEC_MISSING;
        }
        if (! $checks['plan_approved']['passed']) {
            $blockingReasons[] = self::STATUS_BLOCKED_PLAN_NOT_APPROVED;
        }
        if (! $checks['high_risk_has_tests']['passed']) {
            $blockingReasons[] = self::STATUS_BLOCKED_HIGH_RISK_NO_TESTS;
        }
        if (! $checks['decision_receipt_present']['passed']) {
            $blockingReasons[] = self::STATUS_BLOCKED_RECEIPT_MISSING;
        }
        if (! $checks['repair_budget_remaining']['passed']) {
            $blockingReasons[] = self::STATUS_BLOCKED_REPAIR_BUDGET_EXHAUSTED;
        }

        $mayExecute = $blockingReasons === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ap_reference' => self::AP_REFERENCE,
            'status' => $mayExecute ? self::STATUS_READY : $blockingReasons[0],
            'may_execute' => $mayExecute,
            'blocking_reasons' => $blockingReasons,
            'checks' => $checks,
            'evidence' => $this->evidenceBlock($snapshot),
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array{passed: bool, reason: ?string}
     */
    private function checkSpecPresent(array $snapshot): array
    {
        $specHash = $snapshot['spec_hash'] ?? null;
        if (! is_string($specHash) || $specHash === '') {
            return ['passed' => false, 'reason' => 'spec_hash absent — programming spec must be issued before durable execution'];
        }

        return ['passed' => true, 'reason' => null];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array{passed: bool, reason: ?string}
     */
    private function checkPlanApproved(array $snapshot): array
    {
        $plan = (array) ($snapshot['plan_json'] ?? []);
        $status = $plan['approval_status'] ?? null;
        if ($status !== 'approved') {
            return [
                'passed' => false,
                'reason' => sprintf('plan approval_status=%s — durable execution requires approved plan (Gap2 invariant)', is_string($status) ? $status : 'null'),
            ];
        }

        return ['passed' => true, 'reason' => null];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array{passed: bool, reason: ?string}
     */
    private function checkHighRiskHasTests(array $snapshot): array
    {
        $plan = (array) ($snapshot['plan_json'] ?? []);
        $risk = (string) ($plan['risk_band'] ?? $snapshot['risk_level'] ?? 'medium');
        $tests = (array) ($plan['tests_to_run'] ?? []);
        if (in_array($risk, ['high', 'critical'], true) && $tests === []) {
            return [
                'passed' => false,
                'reason' => sprintf('risk=%s with empty tests_to_run violates A2 invariant', $risk),
            ];
        }

        return ['passed' => true, 'reason' => null];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array{passed: bool, reason: ?string}
     */
    private function checkDecisionReceipt(array $snapshot): array
    {
        $receipt = $snapshot['decision_receipt'] ?? null;
        if (! is_array($receipt) || $receipt === []) {
            return [
                'passed' => false,
                'reason' => 'Decision Receipt v2 envelope absent — durable execution must trace through canonical Kernel receipt',
            ];
        }

        return ['passed' => true, 'reason' => null];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array{passed: bool, reason: ?string}
     */
    private function checkRepairBudget(array $snapshot): array
    {
        $attempted = (int) ($snapshot['repair_attempts_so_far'] ?? 0);
        $budget = (int) ($snapshot['repair_budget'] ?? 3);
        if ($attempted >= $budget) {
            return [
                'passed' => false,
                'reason' => sprintf('repair budget exhausted (%d/%d) — escalate to Forge or human', $attempted, $budget),
            ];
        }

        return ['passed' => true, 'reason' => null];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array{
     *   work_item_id: ?string,
     *   spec_hash: ?string,
     *   plan_hash: ?string,
     *   plan_approval_status: ?string,
     *   risk_level: ?string,
     *   repair_budget_remaining: ?int,
     *   decision_receipt_present: bool
     * }
     */
    private function evidenceBlock(array $snapshot): array
    {
        $plan = (array) ($snapshot['plan_json'] ?? []);
        $attempted = (int) ($snapshot['repair_attempts_so_far'] ?? 0);
        $budget = (int) ($snapshot['repair_budget'] ?? 3);

        return [
            'work_item_id' => isset($snapshot['work_item_id']) && is_string($snapshot['work_item_id']) ? $snapshot['work_item_id'] : null,
            'spec_hash' => isset($snapshot['spec_hash']) && is_string($snapshot['spec_hash']) ? $snapshot['spec_hash'] : null,
            'plan_hash' => isset($snapshot['plan_hash']) && is_string($snapshot['plan_hash']) ? $snapshot['plan_hash'] : null,
            'plan_approval_status' => isset($plan['approval_status']) && is_string($plan['approval_status']) ? $plan['approval_status'] : null,
            'risk_level' => (string) ($plan['risk_band'] ?? $snapshot['risk_level'] ?? 'medium'),
            'repair_budget_remaining' => max(0, $budget - $attempted),
            'decision_receipt_present' => is_array($snapshot['decision_receipt'] ?? null) && $snapshot['decision_receipt'] !== [],
        ];
    }
}
