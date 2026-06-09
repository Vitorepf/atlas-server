<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

/**
 * Axis N · Fleet PLANNER (deterministic, no provider).
 *
 * Selects up to N INDEPENDENT ready slices for a single parallel batch. Pure read over
 * the decomposed_plan.v1 + the tracker rollup; never invokes a provider, never mutates.
 *
 * "Independent" is the conjunction of two honest constraints, so two workers can never
 * collide on either the dependency graph or the file system:
 *  1. No inter-dependency: no chosen slice depends_on another chosen slice (a chosen
 *     slice may only depend on already-delivered slices — the same gate the sequential
 *     selector enforces).
 *  2. Disjoint file scope: the allowed_files sets of the chosen slices do not intersect,
 *     so each worker owns its files exclusively. A slice with no declared allowed_files
 *     is treated as touching an unknown/shared scope and is NEVER batched in parallel
 *     (it can only be picked as a solo batch), preserving real-or-blocked safety.
 *
 * Ordering mirrors PlanSliceSelectionService (sequence asc), so the parallel path is a
 * strict superset of the sequential one: the FIRST ready slice is always included, then
 * the planner greedily adds later ready slices that stay independent of the growing batch.
 *
 * Honest terminal kinds re-use the selector's vocabulary so the runner branches uniformly:
 *  - KIND_BATCH_READY    : 1..N independent ready slices to dispatch this batch.
 *  - KIND_PLAN_COMPLETE  : every slice delivered.
 *  - KIND_BLOCKED        : undelivered slices remain but none is ready.
 *  - KIND_EMPTY_PLAN     : the plan has no slices.
 */
final class FleetSlicePlannerService
{
    public const PLAN_SCHEMA = 'atlas.axis_n.fleet_plan.v1';

    public const KIND_BATCH_READY = 'batch_ready';

    public const KIND_PLAN_COMPLETE = 'plan_complete';

    public const KIND_BLOCKED = 'blocked';

    public const KIND_EMPTY_PLAN = 'empty_plan';

    public const DEFAULT_MAX_PARALLEL = 4;

    private PlanSliceSelectionService $selection;

    public function __construct(?PlanSliceSelectionService $selection = null)
    {
        $this->selection = $selection ?? new PlanSliceSelectionService;
    }

    /**
     * @param  array<string,mixed>  $decomposedPlan  decomposed_plan.v1
     * @param  array<string,mixed>  $rollup          plan_completion_ledger.v1 rollup
     * @param  array<string,bool>|list<string>  $skip  slice_ids already attempted-and-blocked this run
     * @return array<string,mixed>                   fleet_plan.v1
     */
    public function planBatch(array $decomposedPlan, array $rollup, int $maxParallel = self::DEFAULT_MAX_PARALLEL, array $skip = []): array
    {
        $cap = max(1, $maxParallel);

        // Reuse the proven selector to learn the terminal state AND the first ready slice.
        $first = $this->selection->selectNext($decomposedPlan, $rollup, $skip);
        $firstKind = (string) $first['kind'];

        if ($firstKind === PlanSliceSelectionService::KIND_PLAN_COMPLETE) {
            return $this->result(self::KIND_PLAN_COMPLETE, [], 'all_slices_delivered', $decomposedPlan, $cap);
        }
        if ($firstKind === PlanSliceSelectionService::KIND_EMPTY_PLAN) {
            return $this->result(self::KIND_EMPTY_PLAN, [], 'plan_has_no_slices', $decomposedPlan, $cap);
        }
        if ($firstKind !== PlanSliceSelectionService::KIND_SLICE_READY) {
            return $this->result(self::KIND_BLOCKED, [], (string) $first['reason'], $decomposedPlan, $cap);
        }

        // The selector already vetted the first slice's dependency gate. Build the batch
        // greedily over the ordered ready slices, enforcing batch-level independence.
        $ordered = PlanSliceReadModel::orderedSlices($decomposedPlan);
        $sliceStates = is_array($rollup['slice_states'] ?? null) ? $rollup['slice_states'] : [];
        $skipSet = PlanSliceReadModel::normalizeSkip($skip);

        $batch = [];
        $chosenIds = [];
        $claimedFiles = [];

        foreach ($ordered as $slice) {
            if (count($batch) >= $cap) {
                break;
            }
            $sid = (string) $slice['slice_id'];
            if (isset($skipSet[$sid])) {
                continue;
            }
            $row = is_array($sliceStates[$sid] ?? null) ? $sliceStates[$sid] : [];
            $state = (string) ($row['state'] ?? 'planned');
            if ($state === PlanSliceSelectionService::STATE_DELIVERED || $state === PlanSliceSelectionService::STATE_BLOCKED) {
                continue;
            }

            // Dependency gate: every dependency must be DELIVERED already. Dependencies on
            // slices chosen earlier in THIS batch are NOT satisfied (they are not merged
            // yet) — so an inter-batch dependency disqualifies the slice from this batch.
            if (! PlanSliceReadModel::dependenciesDelivered($slice, $sliceStates)) {
                continue;
            }

            $files = PlanSliceReadModel::allowedFiles($slice);

            // No declared scope => unknown/shared => may only run as a SOLO batch.
            if ($files === []) {
                if ($batch === []) {
                    $batch[] = $slice;
                    $chosenIds[$sid] = true;
                }

                break;
            }

            // Disjoint file scope across the batch.
            if (PlanSliceReadModel::intersects($files, $claimedFiles)) {
                continue;
            }

            $batch[] = $slice;
            $chosenIds[$sid] = true;
            foreach ($files as $f) {
                $claimedFiles[$f] = true;
            }
        }

        if ($batch === []) {
            // Should not happen (selector said a slice is ready), but stay honest.
            return $this->result(self::KIND_BLOCKED, [], 'no_independent_ready_slice', $decomposedPlan, $cap);
        }

        return $this->result(self::KIND_BATCH_READY, $batch, 'batch_ready', $decomposedPlan, $cap);
    }

    /**
     * @param  list<array<string,mixed>>  $batch
     * @param  array<string,mixed>  $decomposedPlan
     * @return array<string,mixed>
     */
    private function result(string $kind, array $batch, string $reason, array $decomposedPlan, int $cap): array
    {
        $sliceIds = [];
        foreach ($batch as $s) {
            $sliceIds[] = (string) ($s['slice_id'] ?? '');
        }

        return [
            'schema_version' => self::PLAN_SCHEMA,
            'kind' => $kind,
            'reason' => $reason,
            'plan_id' => (string) ($decomposedPlan['plan_id'] ?? ''),
            'max_parallel' => $cap,
            'batch_size' => count($batch),
            'slice_ids' => $sliceIds,
            'slices' => $batch,
        ];
    }
}
