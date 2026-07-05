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

    public const REASON_UNVERIFIED_LANE = 'lane_not_verified';

    public const REASON_NAMESPACE_UNSAFE = 'lane_namespace_unsafe';

    /**
     * @param  array<string,int>  $demandByLane     lane_id => requested workers (>=0)
     * @param  array<string,array{cap?:int, critical?:bool, verified?:bool, namespace_safe?:bool, urgency?:int, value?:int, isolation_risk?:bool, age_seconds?:int}>  $laneCaps  lane_id => caps
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
        $fairnessRationale = [];
        $urgencyByLane = [];
        $valueByLane = [];
        $isolationRiskByLane = [];
        $ageByLane = [];

        foreach ($demandByLane as $laneId => $demand) {
            $laneId = (string) $laneId;
            $demand = max(0, (int) $demand);
            $cap = $laneCaps[$laneId] ?? [];

            // AC2: a lane lacking verification or namespace safety is refused outright — no
            // amount of demand or urgency grants it worker budget.
            $verified = (bool) ($cap['verified'] ?? false);
            $namespaceSafe = (bool) ($cap['namespace_safe'] ?? false);
            if (! $verified) {
                $deniedLanes[] = ['lane_id' => $laneId, 'reason' => self::REASON_UNVERIFIED_LANE];
                $fairnessRationale[] = "{$laneId}: denied — lane lacks verification, never eligible for worker budget";

                continue;
            }
            if (! $namespaceSafe) {
                $deniedLanes[] = ['lane_id' => $laneId, 'reason' => self::REASON_NAMESPACE_UNSAFE];
                $fairnessRationale[] = "{$laneId}: denied — lane namespace is not proven safe, never eligible for worker budget";

                continue;
            }

            if ($demand === 0) {
                $deniedLanes[] = ['lane_id' => $laneId, 'reason' => self::REASON_NO_DEMAND];

                continue;
            }
            $critical = (bool) ($cap['critical'] ?? false);
            $laneCap = $critical ? self::CRITICAL_CAP : max(0, (int) ($cap['cap'] ?? PHP_INT_MAX));
            $request = min($demand, $laneCap);
            if ($request < $demand) {
                $reasons[$laneId][] = ['reason' => self::REASON_CAPPED, 'cap' => $laneCap, 'demand' => $demand];
            }
            $allocations[$laneId] = ['lane_id' => $laneId, 'workers' => $request, 'critical' => $critical];
            $urgencyByLane[$laneId] = (int) ($cap['urgency'] ?? 0);
            $valueByLane[$laneId] = (int) ($cap['value'] ?? 0);
            $isolationRiskByLane[$laneId] = (bool) ($cap['isolation_risk'] ?? false);
            $ageByLane[$laneId] = max(0, (int) ($cap['age_seconds'] ?? 0));
        }

        $originalCapAppliedDemand = array_map(static fn (array $row): int => $row['workers'], $allocations);

        $totalRequested = array_sum(array_column($allocations, 'workers'));

        if ($totalRequested > $globalWorkerBudget) {
            // AC1: fair reduction — isolation-risky lanes are reduced first; among equally risky
            // lanes, lower urgency/value is reduced first (protecting high-priority lanes); ties
            // fall back to largest worker count, then lane_id — identical to prior behavior when
            // urgency/value/isolation_risk are never supplied (all default equal).
            while ($totalRequested > $globalWorkerBudget) {
                $candidate = null;
                foreach ($allocations as $laneId => $row) {
                    if ($row['workers'] <= 1) {
                        continue;
                    }
                    if ($candidate === null || $this->reductionOutranks(
                        $laneId, $row['workers'], $isolationRiskByLane, $urgencyByLane, $valueByLane,
                        $candidate, $allocations[$candidate]['workers'], $isolationRiskByLane, $urgencyByLane, $valueByLane,
                    )) {
                        $candidate = $laneId;
                    }
                }
                if ($candidate === null) {
                    break; // All at floor; starvation phase below.
                }
                $allocations[$candidate]['workers']--;
                $reasons[$candidate][] = ['reason' => self::REASON_BUDGET_REDUCED, 'budget' => $globalWorkerBudget];
                $fairnessRationale[] = "{$candidate}: reduced by 1 worker for budget fairness";
                $totalRequested--;
            }

            // Starvation: lanes floored at 1 but total still exceeds budget — deny by lowest
            // urgency/value first, then by lowest starvation age (protecting long-waiting lanes),
            // then smallest original demand, then lane_id ASC. Identical to the prior
            // demand-only ordering when urgency/value/age_seconds are never supplied.
            if ($totalRequested > $globalWorkerBudget) {
                $floorLanes = array_keys($allocations);
                usort($floorLanes, static function (string $a, string $b) use ($urgencyByLane, $valueByLane, $ageByLane, $originalCapAppliedDemand): int {
                    $diff = ($urgencyByLane[$a] ?? 0) <=> ($urgencyByLane[$b] ?? 0);
                    if ($diff !== 0) {
                        return $diff;
                    }
                    $diff = ($valueByLane[$a] ?? 0) <=> ($valueByLane[$b] ?? 0);
                    if ($diff !== 0) {
                        return $diff;
                    }
                    $diff = ($ageByLane[$a] ?? 0) <=> ($ageByLane[$b] ?? 0);
                    if ($diff !== 0) {
                        return $diff;
                    }
                    $diff = ($originalCapAppliedDemand[$a] ?? 0) - ($originalCapAppliedDemand[$b] ?? 0);
                    return $diff !== 0 ? $diff : strcmp($a, $b);
                });
                foreach ($floorLanes as $starveId) {
                    if ($totalRequested <= $globalWorkerBudget) {
                        break;
                    }
                    $deniedLanes[] = ['lane_id' => $starveId, 'reason' => self::REASON_BUDGET_STARVED];
                    $fairnessRationale[] = "{$starveId}: denied — global budget exhausted after starvation-floor reduction";
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
            'fairness_rationale' => $fairnessRationale,
        ];
    }

    /**
     * True when lane A should be reduced before lane B: isolation-risky lanes first, then lower
     * urgency, then lower value, then larger worker count, then lexically smaller lane_id.
     *
     * @param  array<string,bool>  $isolationRiskA
     * @param  array<string,int>  $urgencyA
     * @param  array<string,int>  $valueA
     * @param  array<string,bool>  $isolationRiskB
     * @param  array<string,int>  $urgencyB
     * @param  array<string,int>  $valueB
     */
    private function reductionOutranks(
        string $laneA, int $workersA, array $isolationRiskA, array $urgencyA, array $valueA,
        string $laneB, int $workersB, array $isolationRiskB, array $urgencyB, array $valueB,
    ): bool {
        $riskA = (bool) ($isolationRiskA[$laneA] ?? false);
        $riskB = (bool) ($isolationRiskB[$laneB] ?? false);
        if ($riskA !== $riskB) {
            return $riskA; // risky lane reduced first
        }
        $uA = $urgencyA[$laneA] ?? 0;
        $uB = $urgencyB[$laneB] ?? 0;
        if ($uA !== $uB) {
            return $uA < $uB; // lower urgency reduced first
        }
        $vA = $valueA[$laneA] ?? 0;
        $vB = $valueB[$laneB] ?? 0;
        if ($vA !== $vB) {
            return $vA < $vB; // lower value reduced first
        }
        if ($workersA !== $workersB) {
            return $workersA > $workersB; // larger lane reduced first
        }

        return strcmp($laneA, $laneB) < 0;
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
