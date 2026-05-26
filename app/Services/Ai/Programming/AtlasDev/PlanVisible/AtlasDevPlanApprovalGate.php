<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\PlanVisible;

use App\Services\Ai\Programming\AtlasDev\Schemas\PlanVisible;

/**
 * Atlas Dev A2 — Plan Visible approval gate.
 *
 * Sits between `AtlasDevPlanProjectionService` (Gap2.F2) and the provider
 * invocation that the runtime would otherwise fire. The gate has one job:
 *
 *   PROVIDER MAY FIRE  iff  approval_status == 'approved'
 *
 * Anything else (`pending`, `rejected`, missing plan) blocks the provider
 * and returns a canonical envelope so the surface (CLI, desktop) can
 * render the right operator action.
 *
 * This service is intentionally narrow:
 *
 *  - It does NOT call the provider; it returns a decision envelope.
 *  - It does NOT mutate the plan; approval transitions live on
 *    `PlanVisible::approve()` / `reject()`.
 *  - It does NOT decide WHO can approve; that's a Permission Gate concern.
 *
 * The gate emits its own canonical schema so downstream telemetry (Gap2.F5)
 * can count approvals, rejections, revisions and pending blocks without
 * peeking inside the plan itself.
 */
final class AtlasDevPlanApprovalGate
{
    public const SCHEMA_VERSION = 'atlas.dev.plan_approval_decision.v1';

    public const DECISION_MAY_FIRE = 'may_fire_provider';

    public const DECISION_BLOCKED = 'provider_blocked';

    public const BLOCK_REASON_PENDING = 'plan_pending_operator_approval';

    public const BLOCK_REASON_REJECTED = 'plan_rejected_by_operator';

    public const BLOCK_REASON_MISSING = 'no_plan_persisted';

    public const BLOCK_REASON_HIGH_RISK_REQUIRES_TESTS = 'high_risk_requires_focused_tests';

    /**
     * @return array{
     *   schema_version: string,
     *   decision: string,
     *   plan_hash: ?string,
     *   approval_status: ?string,
     *   risk_band: ?string,
     *   block_reason: ?string,
     *   detail: ?string,
     *   operator_actions: list<string>
     * }
     */
    public function evaluate(?PlanVisible $plan): array
    {
        if ($plan === null) {
            return $this->block(
                null,
                null,
                null,
                self::BLOCK_REASON_MISSING,
                'No PlanVisible persisted on the work item. Atlas Dev A2 requires a plan before the provider fires.',
                ['project_plan_via_AtlasDevPlanProjectionService'],
            );
        }

        if ($plan->isRejected()) {
            return $this->block(
                $plan->planHash,
                $plan->approvalStatus,
                $plan->riskBand,
                self::BLOCK_REASON_REJECTED,
                'Operator rejected this plan. Execution is cancelled. Revise the plan or open a new task.',
                ['revise_plan_and_reissue', 'cancel_task'],
            );
        }

        if ($plan->isPending()) {
            return $this->block(
                $plan->planHash,
                $plan->approvalStatus,
                $plan->riskBand,
                self::BLOCK_REASON_PENDING,
                'Plan is pending operator approval. Provider invocation is blocked until ship/reject.',
                ['operator_ship_or_reject'],
            );
        }

        // approved: still enforce one invariant — high-risk plans must
        // have focused tests. The schema validation already rejects
        // construction of a high/medium plan with empty tests, but a
        // legacy persisted plan might bypass this; double-check here.
        if ($plan->riskBand === PlanVisible::RISK_BAND_HIGH && $plan->testsToRun === []) {
            return $this->block(
                $plan->planHash,
                $plan->approvalStatus,
                $plan->riskBand,
                self::BLOCK_REASON_HIGH_RISK_REQUIRES_TESTS,
                'High-risk plan without focused tests violates the A2 invariant. Add tests_to_run before re-approving.',
                ['add_focused_tests', 'lower_risk_band_with_justification'],
            );
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'decision' => self::DECISION_MAY_FIRE,
            'plan_hash' => $plan->planHash,
            'approval_status' => $plan->approvalStatus,
            'risk_band' => $plan->riskBand,
            'block_reason' => null,
            'detail' => null,
            'operator_actions' => [],
        ];
    }

    /**
     * Convenience helper for callers that have a fresh `PlanVisible` and
     * an operator decision in one breath (`approve` | `reject`). Returns
     * the new plan, NOT the gate envelope; combine with `evaluate()` to
     * get the gate decision.
     */
    public function applyOperatorDecision(PlanVisible $plan, string $decision): PlanVisible
    {
        return match ($decision) {
            'approve' => $plan->approve(),
            'reject' => $plan->reject(),
            default => throw new \InvalidArgumentException(
                "AtlasDevPlanApprovalGate: operator decision must be 'approve' or 'reject', got '{$decision}'."
            ),
        };
    }

    /**
     * @param  list<string>  $operatorActions
     * @return array{
     *   schema_version: string,
     *   decision: string,
     *   plan_hash: ?string,
     *   approval_status: ?string,
     *   risk_band: ?string,
     *   block_reason: ?string,
     *   detail: ?string,
     *   operator_actions: list<string>
     * }
     */
    private function block(
        ?string $planHash,
        ?string $approvalStatus,
        ?string $riskBand,
        string $reason,
        string $detail,
        array $operatorActions,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'decision' => self::DECISION_BLOCKED,
            'plan_hash' => $planHash,
            'approval_status' => $approvalStatus,
            'risk_band' => $riskBand,
            'block_reason' => $reason,
            'detail' => $detail,
            'operator_actions' => $operatorActions,
        ];
    }
}
