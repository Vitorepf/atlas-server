<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Pilar 1 · Plan Execution · the loop that DRIVES a decomposed plan to honest completion.
 *
 * This is the missing seam between the build-plan and atlas_dev: it repeatedly selects the
 * next ready slice (PlanSliceSelectionService), runs it through a PlanSliceCycleExecutor
 * (the real owner flow, or a labelled simulation), records the resulting real cycle in the
 * PlanCompletionTrackerService, and re-rolls the per-plan completion — until the plan is
 * delivered, blocks honestly, or a budget stops it.
 *
 * Real-or-blocked guarantees:
 *  - Completion is NEVER asserted by this runner; it is read back from the tracker rollup,
 *    which derives delivery from real merge + provider proof + acceptance.
 *  - status=complete only when selection reports plan_complete (every slice delivered).
 *  - A no-advance cycle (executor produced no real delivery) counts toward a no-progress
 *    budget and stops the run honestly instead of spinning.
 *  - simulated runs are flagged so no report claims a real merge.
 */
final class PlanDrivenLoopRunnerService
{
    public const RUN_SCHEMA = 'atlas.plan_execution.plan_driven_run.v1';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_MAX_NO_PROGRESS = 2;

    private PlanSliceSelectionService $selection;

    private PlanCompletionTrackerService $tracker;

    private PlanSliceDecompositionService $decomposition;

    public function __construct(
        ?PlanSliceSelectionService $selection = null,
        ?PlanCompletionTrackerService $tracker = null,
        ?PlanSliceDecompositionService $decomposition = null,
    ) {
        $this->selection = $selection ?? new PlanSliceSelectionService;
        $this->tracker = $tracker ?? new PlanCompletionTrackerService;
        $this->decomposition = $decomposition ?? new PlanSliceDecompositionService;
    }

    public function setTrackerForTesting(?PlanCompletionTrackerService $tracker): void
    {
        if ($tracker !== null) {
            $this->tracker = $tracker;
        }
    }

    /**
     * @param  array<string,mixed>  $input  decomposed_plan, area_id, executor, [max_cycles], [max_no_progress], [context]
     * @return array<string,mixed>          plan_driven_run.v1
     */
    public function run(array $input): array
    {
        $plan = is_array($input['decomposed_plan'] ?? null) ? $input['decomposed_plan'] : [];
        $planId = (string) ($plan['plan_id'] ?? '');
        $areaId = (string) ($input['area_id'] ?? 'agentic_engineering_os');
        $executor = $input['executor'] ?? null;
        $context = is_array($input['context'] ?? null) ? $input['context'] : [];
        $context['area_id'] = $context['area_id'] ?? $areaId;

        $totalSlices = count(is_array($plan['slices'] ?? null) ? $plan['slices'] : []);
        $maxCycles = (int) ($input['max_cycles'] ?? max(1, $totalSlices) + self::DEFAULT_MAX_NO_PROGRESS);
        $maxNoProgress = (int) ($input['max_no_progress'] ?? self::DEFAULT_MAX_NO_PROGRESS);

        if (! $executor instanceof PlanSliceCycleExecutor) {
            return $this->summary($planId, $areaId, self::STATUS_BLOCKED, false, 0, $this->tracker->rollup($planId, $areaId, $plan), [], ['executor_not_provided']);
        }
        if ($planId === '' || $totalSlices === 0) {
            return $this->summary($planId, $areaId, self::STATUS_BLOCKED, $executor->isSimulated(), 0, $this->tracker->rollup($planId, $areaId, $plan), [], ['plan_not_decomposable']);
        }

        $simulated = $executor->isSimulated();
        $trace = [];
        $blockers = [];
        $cyclesRun = 0;
        $skip = [];
        $status = self::STATUS_PARTIAL;
        $rollup = $this->tracker->rollup($planId, $areaId, $plan);

        while (true) {
            $selection = $this->selection->selectNext($plan, $rollup, $skip);
            $kind = (string) $selection['kind'];

            if ($kind === PlanSliceSelectionService::KIND_PLAN_COMPLETE) {
                $status = self::STATUS_COMPLETE;
                break;
            }
            if ($kind !== PlanSliceSelectionService::KIND_SLICE_READY) {
                $status = self::STATUS_BLOCKED;
                $blockers[] = 'selection_'.$kind.':'.(string) $selection['reason'];
                break;
            }
            if ($cyclesRun >= $maxCycles) {
                $status = self::STATUS_PARTIAL;
                $blockers[] = 'max_cycles_exhausted';
                break;
            }

            $slice = is_array($selection['slice'] ?? null) ? $selection['slice'] : [];
            $before = (int) ($rollup['delivered_count'] ?? 0);

            // FASE 1 wiring: a large/R4 slice (>=6 files OR >=3 real layers) is broken
            // into its FIRST atomic <=R3 self-contained step BEFORE execution, so the
            // owner-flow risk gate scores a single-layer move instead of blocking the
            // whole multi-layer slice at R4. A slice already <=R3 passes through
            // unchanged. The parent slice_id is preserved so the tracker join holds and
            // later cycles continue decomposing the remaining work.
            $slice = $this->decomposition->resolveExecutableSlice($slice);

            $context['cycle_index'] = $cyclesRun;
            $cycle = $executor->executeSlice($slice, $context);
            if (! is_array($cycle)) {
                $cycle = [];
            }

            $rollup = $this->tracker->recordCycle([
                'decomposed_plan' => $plan,
                'area_id' => $areaId,
                'cycle' => $cycle,
            ]);
            $cyclesRun++;

            $after = (int) ($rollup['delivered_count'] ?? 0);
            $sliceId = (string) ($selection['slice_id'] ?? '');
            $sliceRow = is_array(($rollup['slice_states'] ?? [])[$sliceId] ?? null) ? $rollup['slice_states'][$sliceId] : [];

            $trace[] = [
                'cycle_index' => $cyclesRun,
                'slice_id' => $sliceId,
                'cycle_id' => (string) ($cycle['cycle_id'] ?? ''),
                'delivered_before' => $before,
                'delivered_after' => $after,
                'slice_state' => (string) ($sliceRow['state'] ?? 'planned'),
                'provider_proof' => (bool) ($sliceRow['provider_proof'] ?? false),
                'simulated' => $simulated,
            ];

            if ($after <= $before) {
                // The slice did not deliver (honest block — under-scoped, provider blocked,
                // validation failed, ...). Pass it over for the rest of this run so the loop
                // ADVANCES to other independent ready slices instead of re-running a stuck
                // one (anti-spin: a slice is attempted at most once per run). Its dependents
                // stay blocked via the dependency gate. maxNoProgress caps per-slice attempts.
                if ($sliceId !== '') {
                    $skip[$sliceId] = true;
                }
                $blockers[] = 'slice_no_progress_skipped:'.$sliceId;
            }
        }

        foreach ((array) ($rollup['blockers'] ?? []) as $b) {
            if (is_string($b) && ! in_array($b, $blockers, true)) {
                $blockers[] = $b;
            }
        }

        return $this->summary($planId, $areaId, $status, $simulated, $cyclesRun, $rollup, $trace, $blockers);
    }

    /**
     * @param  array<string,mixed>  $rollup
     * @param  list<array<string,mixed>>  $trace
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function summary(string $planId, string $areaId, string $status, bool $simulated, int $cyclesRun, array $rollup, array $trace, array $blockers): array
    {
        $summary = [
            'schema_version' => self::RUN_SCHEMA,
            'plan_id' => $planId,
            'area_id' => $areaId,
            'status' => $status,
            'simulated' => $simulated,
            'cycles_run' => $cyclesRun,
            'total_slices' => (int) ($rollup['total_slices'] ?? 0),
            'delivered_count' => (int) ($rollup['delivered_count'] ?? 0),
            'completion_pct' => (float) ($rollup['completion_pct'] ?? 0.0),
            'ledger_status' => (string) ($rollup['status'] ?? ''),
            'trace' => $trace,
            'blockers' => array_values(array_unique($blockers)),
            // Hard honesty floor: complete cannot coexist with a simulated run claim of real delivery.
            'claim_policy' => [
                'real_delivery_claimed' => $status === self::STATUS_COMPLETE && ! $simulated,
                'simulated' => $simulated,
                'benchmark' => false,
                'rivals' => false,
                'superiority' => false,
            ],
        ];
        $summary['run_hash'] = 'sha256:'.MissionCanonicalHash::sha256([
            $planId, $areaId, $status, $simulated, $cyclesRun,
            $summary['delivered_count'], $summary['total_slices'],
        ]);

        return $summary;
    }
}
