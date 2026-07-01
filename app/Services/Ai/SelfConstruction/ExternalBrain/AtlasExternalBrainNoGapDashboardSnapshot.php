<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure composer: builds a single JSON-ready snapshot of every remaining self-construction
 * gap so an operator or a future brain session never has to reassemble gap status from
 * many small services by hand.
 *
 * INPUT:
 *   gaps_by_area           — map<area_name, open_gap_count>
 *   detached_task_risks    — list<{task_id?, area?, reason?}> tasks with no live consumer
 *   e2e_proof_status       — {passed:bool, area?:string}  (fresh end-to-end proof state)
 *   knowledge_sync_status  — {current:bool, area?:string} (KB/code-intelligence sync state)
 *
 * OUTPUT:
 *   { schema, total_gaps, gaps_by_area, detached_task_risks, e2e_proof_status,
 *     knowledge_sync_status, readiness, next_closure_task }
 *
 * total_gaps sums every open source: area gap counts, detached task risks, an unproven
 * E2E state, and a stale knowledge sync — each counts once toward "not actually closed".
 *
 * readiness = true only when total_gaps === 0. next_closure_task names the single
 * highest-leverage thing to close next (area gaps first, alphabetically, then detached
 * task risk, then E2E proof, then knowledge sync) — null only when readiness is true.
 *
 * Pure. No I/O, no provider calls, deterministic.
 */
final class AtlasExternalBrainNoGapDashboardSnapshot
{
    public const SCHEMA = 'atlas.external_brain.no_gap_dashboard_snapshot.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compose(array $input): array
    {
        $gapsByArea = [];
        foreach ((array) ($input['gaps_by_area'] ?? []) as $area => $count) {
            $count = max(0, (int) $count);
            if ($count > 0) {
                $gapsByArea[(string) $area] = $count;
            }
        }
        ksort($gapsByArea);

        $detachedTaskRisks = array_values((array) ($input['detached_task_risks'] ?? []));

        $e2eStatus = is_array($input['e2e_proof_status'] ?? null) ? $input['e2e_proof_status'] : [];
        $e2ePassed = (bool) ($e2eStatus['passed'] ?? false);

        $knowledgeSyncStatus = is_array($input['knowledge_sync_status'] ?? null) ? $input['knowledge_sync_status'] : [];
        $knowledgeSyncCurrent = (bool) ($knowledgeSyncStatus['current'] ?? false);

        $areaGapTotal = array_sum($gapsByArea);
        $totalGaps = $areaGapTotal
            + count($detachedTaskRisks)
            + ($e2ePassed ? 0 : 1)
            + ($knowledgeSyncCurrent ? 0 : 1);

        $readiness = $totalGaps === 0;

        $nextClosureTask = $readiness ? null : $this->nextClosureTask(
            $gapsByArea,
            $detachedTaskRisks,
            $e2ePassed,
            $knowledgeSyncCurrent,
        );

        return [
            'schema'                => self::SCHEMA,
            'total_gaps'            => $totalGaps,
            'gaps_by_area'          => $gapsByArea,
            'detached_task_risks'   => $detachedTaskRisks,
            'e2e_proof_status'      => ['passed' => $e2ePassed] + $e2eStatus,
            'knowledge_sync_status' => ['current' => $knowledgeSyncCurrent] + $knowledgeSyncStatus,
            'readiness'             => $readiness,
            'next_closure_task'     => $nextClosureTask,
        ];
    }

    /**
     * @param  array<string,int>  $gapsByArea
     * @param  list<mixed>  $detachedTaskRisks
     */
    private function nextClosureTask(array $gapsByArea, array $detachedTaskRisks, bool $e2ePassed, bool $knowledgeSyncCurrent): string
    {
        if ($gapsByArea !== []) {
            $area = array_key_first($gapsByArea);

            return 'close_gap_in:'.$area;
        }

        if ($detachedTaskRisks !== []) {
            return 'reattach_detached_task_risk';
        }

        if (! $e2ePassed) {
            return 'prove_e2e_status';
        }

        if (! $knowledgeSyncCurrent) {
            return 'sync_knowledge';
        }

        // Unreachable when readiness is correctly computed, but keep fail-closed.
        return 'investigate_unclassified_gap';
    }
}
