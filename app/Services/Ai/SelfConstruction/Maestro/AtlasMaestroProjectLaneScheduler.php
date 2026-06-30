<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro;

/**
 * Deterministic project-lane scheduler — the Maestro planning primitive that allocates a global worker
 * budget across lanes given per-lane demand and per-lane caps.
 *
 * Defaults:
 *   - topology: shared_local_main_with_scope_lock
 *   - default_worktree_or_sandbox: false
 *
 * Pure: no process spawning, no worktrees, no provider calls. Two calls with the same input MUST produce
 * byte-identical JSON.
 */
final class AtlasMaestroProjectLaneScheduler
{
    public const SCHEMA = 'atlas.maestro.project_lane_plan.v1';

    public const TOPOLOGY_DEFAULT = 'shared_local_main_with_scope_lock';

    public const CRITICAL_CAP = 1;

    public const REASON_NO_DEMAND = 'no_demand';

    public const REASON_CAPPED = 'capped_by_lane_cap';

    public const REASON_BUDGET_REDUCED = 'reduced_for_budget_fairness';

    public const REASON_BUDGET_STARVED = 'budget_starved';

    /**
     * @param  array<string,int>  $demandByLane     lane_id => requested workers (>=0)
     * @param  array<string,array{cap?:int, critical?:bool}>  $laneCaps  lane_id => caps
     * @return array<string,mixed>
     */
    public function plan(int $globalWorkerBudget, array $demandByLane, array $laneCaps): array
    {
        $globalWorkerBudget = max(0, $globalWorkerBudget);

        ksort($demandByLane);
        ksort($laneCaps);

        $allocations = [];
        $deniedLanes = [];
        $reasons = [];

        foreach ($demandByLane as $laneId => $demand) {
            $laneId = (string) $laneId;
            $demand = max(0, (int) $demand);
            if ($demand === 0) {
                $deniedLanes[] = ['lane_id' => $laneId, 'reason' => self::REASON_NO_DEMAND];

                continue;
            }
            $cap = $laneCaps[$laneId] ?? [];
            $critical = (bool) ($cap['critical'] ?? false);
            $laneCap = $critical ? self::CRITICAL_CAP : max(0, (int) ($cap['cap'] ?? PHP_INT_MAX));
            $request = min($demand, $laneCap);
            if ($request < $demand) {
                $reasons[$laneId][] = ['reason' => self::REASON_CAPPED, 'cap' => $laneCap, 'demand' => $demand];
            }
            $allocations[$laneId] = ['lane_id' => $laneId, 'workers' => $request, 'critical' => $critical];
        }

        $originalCapAppliedDemand = array_map(static fn (array $row): int => $row['workers'], $allocations);

        $totalRequested = array_sum(array_column($allocations, 'workers'));

        if ($totalRequested > $globalWorkerBudget) {
            // Fair reduction: drop one worker at a time from the largest lane, stop at the starvation floor (1).
            while ($totalRequested > $globalWorkerBudget) {
                $candidate = null;
                foreach ($allocations as $laneId => $row) {
                    if ($row['workers'] <= 1) {
                        continue;
                    }
                    if ($candidate === null
                        || $row['workers'] > $allocations[$candidate]['workers']
                        || ($row['workers'] === $allocations[$candidate]['workers'] && strcmp($laneId, $candidate) < 0)) {
                        $candidate = $laneId;
                    }
                }
                if ($candidate === null) {
                    break; // All at floor; starvation phase below.
                }
                $allocations[$candidate]['workers']--;
                $reasons[$candidate][] = ['reason' => self::REASON_BUDGET_REDUCED, 'budget' => $globalWorkerBudget];
                $totalRequested--;
            }

            // Starvation: lanes floored at 1 but total still exceeds budget — deny by smallest original demand first, tie-broken ASC.
            if ($totalRequested > $globalWorkerBudget) {
                $floorLanes = array_keys($allocations);
                usort($floorLanes, static function (string $a, string $b) use ($originalCapAppliedDemand): int {
                    $diff = ($originalCapAppliedDemand[$a] ?? 0) - ($originalCapAppliedDemand[$b] ?? 0);
                    return $diff !== 0 ? $diff : strcmp($a, $b);
                });
                foreach ($floorLanes as $starveId) {
                    if ($totalRequested <= $globalWorkerBudget) {
                        break;
                    }
                    $deniedLanes[] = ['lane_id' => $starveId, 'reason' => self::REASON_BUDGET_STARVED];
                    $totalRequested -= $allocations[$starveId]['workers'];
                    unset($allocations[$starveId]);
                }
            }
        }

        $remainingBudget = max(0, $globalWorkerBudget - $totalRequested);

        $allocations = array_values(array_map(static fn (array $row): array => [
            'lane_id' => $row['lane_id'],
            'workers' => $row['workers'],
            'critical' => $row['critical'],
        ], $allocations));

        return [
            'schema_version' => self::SCHEMA,
            'topology' => self::TOPOLOGY_DEFAULT,
            'default_worktree_or_sandbox' => false,
            'allocations' => $allocations,
            'denied_lanes' => $deniedLanes,
            'unallocated_budget' => $remainingBudget,
            'reasons' => $this->canonicalReasons($reasons),
        ];
    }

    /**
     * @param  array<string, list<array<string,mixed>>>  $reasons
     * @return array<string, list<array<string,mixed>>>
     */
    private function canonicalReasons(array $reasons): array
    {
        ksort($reasons);
        foreach ($reasons as $laneId => $rows) {
            foreach ($rows as $i => $row) {
                ksort($row);
                $reasons[$laneId][$i] = $row;
            }
        }

        return $reasons;
    }
}
