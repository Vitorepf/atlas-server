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

    private FleetSlicePlannerService $fleetPlanner;

    private ?FleetIntegratorService $fleetIntegrator;

    private FleetAuditLedgerService $fleetAudit;

    public function __construct(
        ?PlanSliceSelectionService $selection = null,
        ?PlanCompletionTrackerService $tracker = null,
        ?PlanSliceDecompositionService $decomposition = null,
        ?FleetSlicePlannerService $fleetPlanner = null,
        ?FleetIntegratorService $fleetIntegrator = null,
        ?FleetAuditLedgerService $fleetAudit = null,
    ) {
        $this->selection = $selection ?? new PlanSliceSelectionService;
        $this->tracker = $tracker ?? new PlanCompletionTrackerService;
        $this->decomposition = $decomposition ?? new PlanSliceDecompositionService;
        $this->fleetPlanner = $fleetPlanner ?? new FleetSlicePlannerService($this->selection);
        $this->fleetIntegrator = $fleetIntegrator;
        $this->fleetAudit = $fleetAudit ?? new FleetAuditLedgerService;
    }

    public function setTrackerForTesting(?PlanCompletionTrackerService $tracker): void
    {
        if ($tracker !== null) {
            $this->tracker = $tracker;
            // Keep the fleet integrator's tracker in lockstep with the runner's so the
            // parallel path writes to the same append-only JSONL ledger.
            $this->fleetIntegrator = new FleetIntegratorService($tracker);
            // Co-locate the fleet audit ledger with the tracker's test storage so the
            // append-only audit is isolated per test run (no shared global default leaking
            // merged-slice state across tests). Production uses the unset default root.
            $trackerRoot = $tracker->storageRootOverride();
            if ($trackerRoot !== null) {
                $audit = new FleetAuditLedgerService;
                $audit->setStorageRootForTesting(rtrim($trackerRoot, '/').'/fleet_audit');
                $this->fleetAudit = $audit;
            }
        }
    }

    public function setFleetAuditForTesting(?FleetAuditLedgerService $audit): void
    {
        if ($audit !== null) {
            $this->fleetAudit = $audit;
        }
    }

    public function setFleetIntegratorForTesting(?FleetIntegratorService $integrator): void
    {
        if ($integrator !== null) {
            $this->fleetIntegrator = $integrator;
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

        // Axis N · parallel fleet path is OPT-IN. Default off keeps the sequential path
        // (below) byte-for-byte unchanged. When on, the planner picks N independent ready
        // slices per batch, workers run them via the SAME executor, and the integrator
        // serially merges through the identical gate chain (adversarial panel + tracker).
        $fleetParallel = (bool) ($input['fleet_parallel'] ?? false);
        if ($fleetParallel) {
            $maxParallel = (int) ($input['max_parallel'] ?? FleetSlicePlannerService::DEFAULT_MAX_PARALLEL);

            return $this->runFleet($plan, $planId, $areaId, $executor, $context, $maxCycles, $maxNoProgress, $maxParallel);
        }

        $simulated = $executor->isSimulated();
        $recordPlanCompletion = (bool) ($context['record_plan_completion'] ?? true);
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

            if ($recordPlanCompletion) {
                $rollup = $this->tracker->recordCycle([
                    'decomposed_plan' => $plan,
                    'area_id' => $areaId,
                    'cycle' => $cycle,
                ]);
            }
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
                'recorded_completion' => $recordPlanCompletion,
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
     * Axis N · parallel fleet path. Per batch: plan N independent ready slices, run each
     * worker through the SAME executor (paralelism only on execution), then serially
     * integrate the worker cycles behind the integrator's single logical merge lock
     * (file-conflict gate + adversarial proof panel + tracker-derived delivery). Conflicts
     * and refutations defer to a later batch; a no-progress slice is skipped (anti-spin).
     *
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function runFleet(array $plan, string $planId, string $areaId, PlanSliceCycleExecutor $executor, array $context, int $maxCycles, int $maxNoProgress, int $maxParallel): array
    {
        $integrator = $this->fleetIntegrator ?? new FleetIntegratorService($this->tracker);
        $simulated = $executor->isSimulated();
        $recordPlanCompletion = (bool) ($context['record_plan_completion'] ?? true);
        $trace = [];
        $blockers = [];
        $cyclesRun = 0;
        $batchesRun = 0;
        $skip = [];
        $status = self::STATUS_PARTIAL;
        $rollup = $this->tracker->rollup($planId, $areaId, $plan);

        // CRASH-RECOVERY: resume against the append-only fleet audit ledger (git/tracker is
        // the source of truth, the ledger mirrors it). Any slice already recorded MERGED is
        // seeded into skip so a re-run NEVER re-dispatches and NEVER double-merges it. The
        // tracker's own idempotent (last-event-per-slice) derivation is the second guard:
        // even if skip were bypassed, integrateBatch only counts before<after once.
        $resumedMerged = [];
        foreach ($this->fleetAudit->mergedSliceIds($planId, $areaId) as $sid) {
            $sid = (string) $sid;
            if ($sid !== '') {
                $skip[$sid] = true;
                $resumedMerged[] = $sid;
            }
        }

        while (true) {
            if ($cyclesRun >= $maxCycles) {
                $status = self::STATUS_PARTIAL;
                $blockers[] = 'max_cycles_exhausted';
                break;
            }

            $batch = $this->fleetPlanner->planBatch($plan, $rollup, $maxParallel, $skip);
            $kind = (string) $batch['kind'];

            if ($kind === FleetSlicePlannerService::KIND_PLAN_COMPLETE) {
                $status = self::STATUS_COMPLETE;
                break;
            }
            if ($kind !== FleetSlicePlannerService::KIND_BATCH_READY) {
                $status = self::STATUS_BLOCKED;
                $blockers[] = 'planner_'.$kind.':'.(string) $batch['reason'];
                break;
            }

            $batchesRun++;
            $this->fleetAudit->recordBatchPlanned($planId, $areaId, $batchesRun, array_map('strval', (array) ($batch['slice_ids'] ?? [])), $maxParallel);

            // --- WORKERS: run each slice (each conceptually in its own worktree). The
            // executor is the ONLY seam to a live provider; deterministic in tests. A
            // worker lease is recorded BEFORE execution, so a crash mid-run leaves an
            // orphaned (reclaimable) claim with no terminal event. ---
            $workerResults = [];
            $cycleIdBySlice = [];
            foreach ((array) ($batch['slices'] ?? []) as $rawSlice) {
                $slice = is_array($rawSlice) ? $rawSlice : [];
                $slice = $this->decomposition->resolveExecutableSlice($slice);
                $sliceId = (string) ($slice['slice_id'] ?? '');
                $this->fleetAudit->recordWorkerClaimed($planId, $areaId, $batchesRun, $sliceId);

                $context['cycle_index'] = $cyclesRun;
                $cycle = $executor->executeSlice($slice, $context);
                $cycle = is_array($cycle) ? $cycle : [];
                $cycleId = (string) ($cycle['cycle_id'] ?? '');
                $cycleIdBySlice[$sliceId] = $cycleId;
                $this->fleetAudit->recordWorkerReturned($planId, $areaId, $batchesRun, $sliceId, $cycleId);

                $workerResults[] = ['slice' => $slice, 'cycle' => $cycle];
                $cyclesRun++;
            }

            $before = (int) ($rollup['delivered_count'] ?? 0);

            // --- INTEGRATOR: serialized merge behind one logical merge lock. ---
            if ($recordPlanCompletion) {
                $decision = $integrator->integrateBatch($planId, $areaId, $plan, $workerResults);
                $rollup = is_array($decision['rollup'] ?? null) ? $decision['rollup'] : $rollup;
            } else {
                $decision = [
                    'merged_count' => 0,
                    'deferred_count' => count($workerResults),
                    'dispositions' => array_map(
                        static fn (array $result): array => [
                            'slice_id' => (string) (($result['slice'] ?? [])['slice_id'] ?? ''),
                            'disposition' => 'plan_only_not_recorded',
                        ],
                        $workerResults,
                    ),
                    'rollup' => $rollup,
                ];
            }
            $after = (int) ($rollup['delivered_count'] ?? 0);

            $mergeHashBySlice = $this->mergeHashesByMergedSlice($plan, $workerResults, $rollup);

            $delivered = [];
            foreach ((array) ($decision['dispositions'] ?? []) as $d) {
                $sid = (string) ($d['slice_id'] ?? '');
                $disp = (string) ($d['disposition'] ?? '');
                if ($disp === FleetIntegratorService::DISPOSITION_MERGED) {
                    $delivered[$sid] = true;
                }
                // Mirror the integrator's honest disposition into the append-only audit ledger.
                $this->fleetAudit->recordIntegration(
                    $planId, $areaId, $batchesRun, $d,
                    (string) ($cycleIdBySlice[$sid] ?? ''),
                    (string) ($mergeHashBySlice[$sid] ?? ''),
                );
            }

            $trace[] = [
                'batch_index' => $batchesRun,
                'slice_ids' => (array) ($batch['slice_ids'] ?? []),
                'merged_count' => (int) ($decision['merged_count'] ?? 0),
                'deferred_count' => (int) ($decision['deferred_count'] ?? 0),
                'dispositions' => (array) ($decision['dispositions'] ?? []),
                'delivered_before' => $before,
                'delivered_after' => $after,
                'simulated' => $simulated,
                'recorded_completion' => $recordPlanCompletion,
            ];

            // Anti-spin: any slice in this batch that did NOT merge is skipped for the rest
            // of the run, so the loop advances to other independent ready slices instead of
            // re-dispatching a stuck/conflicting one. Its dependents stay gated.
            foreach ((array) ($batch['slice_ids'] ?? []) as $sid) {
                $sid = (string) $sid;
                if ($sid !== '' && ! isset($delivered[$sid])) {
                    $skip[$sid] = true;
                    $blockers[] = 'slice_no_merge_skipped:'.$sid;
                }
            }
        }

        foreach ((array) ($rollup['blockers'] ?? []) as $b) {
            if (is_string($b) && ! in_array($b, $blockers, true)) {
                $blockers[] = $b;
            }
        }

        $summary = $this->summary($planId, $areaId, $status, $simulated, $cyclesRun, $rollup, $trace, $blockers);
        $summary['fleet_parallel'] = true;
        $summary['batches_run'] = $batchesRun;
        $summary['max_parallel'] = max(1, $maxParallel);
        // Crash-recovery transparency: slices that were already merged in a prior (possibly
        // crashed) run and so were NOT re-dispatched this run.
        $summary['resumed_merged_slice_ids'] = $resumedMerged;

        return $summary;
    }

    /**
     * Map each MERGED slice of this batch to its merge_hash, read back from the tracker
     * rollup (the SOLE authority on delivery) — never from the worker's self-reported cycle.
     *
     * @param  array<string,mixed>  $plan
     * @param  list<array{slice:array<string,mixed>,cycle:array<string,mixed>}>  $workerResults
     * @param  array<string,mixed>  $rollup
     * @return array<string,string>
     */
    private function mergeHashesByMergedSlice(array $plan, array $workerResults, array $rollup): array
    {
        $states = is_array($rollup['slice_states'] ?? null) ? $rollup['slice_states'] : [];
        $out = [];
        foreach ($workerResults as $wr) {
            $slice = is_array($wr['slice'] ?? null) ? $wr['slice'] : [];
            $sid = (string) ($slice['slice_id'] ?? '');
            if ($sid === '') {
                continue;
            }
            $row = is_array($states[$sid] ?? null) ? $states[$sid] : [];
            $out[$sid] = (string) ($row['merge_hash'] ?? '');
        }

        return $out;
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
