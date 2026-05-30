<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

/**
 * Pilar 1 · Plan Execution · the seam by which the plan-driven loop runner turns a
 * selected slice into a real loop cycle.
 *
 * The runner is execution-agnostic: it selects the next ready slice, asks an executor
 * to run it, then feeds the returned cycle to the PlanCompletionTrackerService — which
 * DERIVES delivery/provider-proof/acceptance from the cycle and NEVER trusts caller flags.
 * That means an executor cannot fabricate completion: the most a dishonest cycle can do
 * is fail to advance the plan.
 *
 * Implementations:
 *  - OwnerFlowPlanSliceCycleExecutor: the REAL path — drives the slice through the loop's
 *    AP-786 owner flow (atlas_dev senior loop / forge). isSimulated()=false.
 *  - DeterministicPlanSliceCycleExecutor: a SIMULATION for wiring proof and tests only.
 *    isSimulated()=true so every downstream report is honestly labelled simulated.
 */
interface PlanSliceCycleExecutor
{
    /**
     * Run one slice and return a loop cycle consumable by AutonomousLoopReceiptIntegrityService.
     *
     * @param  array<string,mixed>  $slice    one decomposed_plan.v1 slice (slice_id, finding_id, ...)
     * @param  array<string,mixed>  $context  area_id, scope_profile, plan_id, cycle_index, ...
     * @return array<string,mixed>            a cycle array (selected_finding, lifecycle/merge signals, ...)
     */
    public function executeSlice(array $slice, array $context): array;

    /**
     * True when this executor only SIMULATES a cycle (no real provider call / no real merge).
     * The runner propagates this so no report ever claims a real merge from a simulated run.
     */
    public function isSimulated(): bool;
}
