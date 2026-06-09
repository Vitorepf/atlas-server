<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AdversarialProofPanel;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AdversarialProofPanelService;

/**
 * Axis N · Fleet INTEGRATOR (serialized merge behind a single logical merge lock).
 *
 * Workers run concurrently in isolated worktrees; integration is SERIAL. This service
 * takes the per-slice worker cycles produced by one parallel batch and merges them
 * ONE-BY-ONE through the IDENTICAL gate chain a sequential cycle would face — paralelism
 * is only on execution, never on merge.
 *
 * Per result, in batch order, BEFORE recording the merge:
 *   1. File-conflict gate — if the result's allowed_files intersect any file already
 *      merged earlier in THIS batch, the result is DEFERRED (a later run re-plans it
 *      against the now-updated tree). Conflicts never merge; never fabricated.
 *   2. Adversarial proof panel — the same {@see AdversarialProofPanelService::refute()}
 *      that guards the sequential merge re-runs on the worker cycle. A single refutation
 *      withholds the merge (fail-closed); the result is DEFERRED, never weakened.
 *   3. Record — only a clean, panel-cleared result is fed to the tracker's append-only
 *      JSONL via recordCycle(). The tracker DERIVES delivery (real merge + provider proof
 *      + acceptance) and never trusts the worker's claim, so a dishonest worker cannot
 *      advance the plan.
 *
 * Real-or-blocked: this service never asserts completion; it returns the tracker rollup
 * read back after the last recorded merge, plus an honest per-result disposition list.
 */
final class FleetIntegratorService
{
    public const RESULT_SCHEMA = 'atlas.axis_n.integration_decision.v1';

    public const DISPOSITION_MERGED = 'merged';

    public const DISPOSITION_DEFERRED_CONFLICT = 'deferred_file_conflict';

    public const DISPOSITION_DEFERRED_REFUTED = 'deferred_panel_refuted';

    public const DISPOSITION_NO_PROGRESS = 'no_progress';

    private PlanCompletionTrackerService $tracker;

    private AdversarialProofPanel $panel;

    public function __construct(
        ?PlanCompletionTrackerService $tracker = null,
        ?AdversarialProofPanel $panel = null,
    ) {
        $this->tracker = $tracker ?? new PlanCompletionTrackerService;
        $this->panel = $panel ?? new AdversarialProofPanelService;
    }

    /**
     * Integrate one batch's worker results serially.
     *
     * @param  array<string,mixed>  $plan          decomposed_plan.v1
     * @param  list<array{slice:array<string,mixed>,cycle:array<string,mixed>}>  $workerResults
     *                                             one per worker, in dispatch order
     * @return array<string,mixed>                integration_decision.v1
     */
    public function integrateBatch(string $planId, string $areaId, array $plan, array $workerResults): array
    {
        $dispositions = [];
        $mergedFiles = [];
        $rollup = $this->tracker->rollup($planId, $areaId, $plan);

        foreach ($workerResults as $wr) {
            $slice = is_array($wr['slice'] ?? null) ? $wr['slice'] : [];
            $cycle = is_array($wr['cycle'] ?? null) ? $wr['cycle'] : [];
            $sliceId = (string) ($slice['slice_id'] ?? ($cycle['plan_slice_id'] ?? ''));
            $files = PlanSliceReadModel::integrationFiles($slice, $cycle);

            // 1. File-conflict gate against earlier merges in THIS batch.
            if (PlanSliceReadModel::intersects($files, $mergedFiles)) {
                $dispositions[] = $this->disposition($sliceId, self::DISPOSITION_DEFERRED_CONFLICT, 'allowed_files intersect a sibling merged this batch', null);

                continue;
            }

            // 2. Adversarial proof panel — fail closed.
            $verdict = $this->panel->refute($cycle);
            if (($verdict['merge_allowed'] ?? false) !== true) {
                $dispositions[] = $this->disposition($sliceId, self::DISPOSITION_DEFERRED_REFUTED, (string) ($verdict['reason'] ?? 'refuted'), $verdict);

                continue;
            }

            // 3. Record the merge (tracker derives delivery; never trusts the worker).
            $before = (int) ($rollup['delivered_count'] ?? 0);
            $rollup = $this->tracker->recordCycle([
                'decomposed_plan' => $plan,
                'area_id' => $areaId,
                'cycle' => $cycle,
            ]);
            $after = (int) ($rollup['delivered_count'] ?? 0);

            if ($after > $before) {
                foreach ($files as $f) {
                    $mergedFiles[$f] = true;
                }
                $dispositions[] = $this->disposition($sliceId, self::DISPOSITION_MERGED, 'tracker derived delivery', $verdict);
            } else {
                // Panel cleared but the tracker did not derive delivery (e.g. router used,
                // no real diff, acceptance not met). Honest: no progress, never claimed.
                $dispositions[] = $this->disposition($sliceId, self::DISPOSITION_NO_PROGRESS, 'tracker did not derive delivery', $verdict);
            }
        }

        $merged = array_values(array_filter($dispositions, static fn (array $d): bool => $d['disposition'] === self::DISPOSITION_MERGED));
        $deferred = array_values(array_filter($dispositions, static fn (array $d): bool => str_starts_with((string) $d['disposition'], 'deferred')));

        return [
            'schema_version' => self::RESULT_SCHEMA,
            'plan_id' => $planId,
            'area_id' => $areaId,
            'merged_count' => count($merged),
            'deferred_count' => count($deferred),
            'dispositions' => $dispositions,
            'rollup' => $rollup,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $verdict
     * @return array<string,mixed>
     */
    private function disposition(string $sliceId, string $disposition, string $reason, ?array $verdict): array
    {
        return [
            'slice_id' => $sliceId,
            'disposition' => $disposition,
            'reason' => $reason,
            'panel_merge_allowed' => $verdict !== null ? (bool) ($verdict['merge_allowed'] ?? false) : null,
        ];
    }

}
