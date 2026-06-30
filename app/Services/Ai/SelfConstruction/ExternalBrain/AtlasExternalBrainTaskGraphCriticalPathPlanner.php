<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure planner. Selects the single highest-leverage implementation PATH
 * through a task graph instead of treating every claimable task as equal.
 *
 * A task is USABLE (can appear on the critical path) only when:
 *   status NOT IN {blocked, stale, duplicate, done}, AND
 *   evidence_strength >= 0.3 (default 1.0 when absent).
 * blocked/stale/duplicate/low-evidence tasks are excluded from the path
 * entirely (AC3) regardless of how high their nominal leverage_score is.
 *
 * effective_score(t) = leverage_score - 0.10 × effort + 0.20 × maturity_gap_coverage - risk_penalty
 *   risk_penalty: high=0.30, medium=0.10, low=0.0.
 *
 * path_score(t) = effective_score(t) + max(path_score(p)) over usable p in t.depends_on,
 *   i.e. the classic longest-weighted-path DAG recurrence. The task with the
 *   highest path_score anchors the critical path; it is walked back through
 *   its best-scoring usable predecessor chain to produce
 *   critical_path_task_ids in implementation (root → leaf) order.
 *
 * bottleneck_tasks = critical-path tasks that have >= 2 direct dependents
 *   (other tasks declaring them in depends_on) — high fan-out nodes.
 *
 * parallelizable_branches = connected components (via depends_on edges,
 *   undirected) of usable tasks NOT on the critical path — independent work
 *   that can run alongside it.
 *
 * next_best_task = first (root-most) task_id in critical_path_task_ids.
 *
 * INPUT:
 *   tasks: list<{
 *     task_id:                 string
 *     depends_on?:             list<string>
 *     leverage_score?:         float (default 0.0)
 *     effort?:                 float (default 0.0)
 *     risk?:                   string ('low'|'medium'|'high', default 'low')
 *     maturity_gap_coverage?:  float (default 0.0)
 *     evidence_strength?:      float (default 1.0)
 *     status?:                 string (default 'queued')
 *   }>
 *
 * Pure: no I/O, no side effects, never claims or reorders a real queue.
 */
final class AtlasExternalBrainTaskGraphCriticalPathPlanner
{
    public const SCHEMA = 'atlas.external_brain.task_graph_critical_path_planner.v1';

    private const EXCLUDED_STATUSES = ['blocked', 'stale', 'duplicate', 'done'];

    private const MIN_EVIDENCE_STRENGTH = 0.3;

    private const RISK_PENALTIES = ['high' => 0.30, 'medium' => 0.10, 'low' => 0.0];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $rawTasks = is_array($input['tasks'] ?? null) ? $input['tasks'] : [];

        $tasks = [];
        foreach ($rawTasks as $raw) {
            if (! is_array($raw) || ! isset($raw['task_id'])) {
                continue;
            }
            $id = (string) $raw['task_id'];
            $tasks[$id] = [
                'task_id' => $id,
                'depends_on' => $this->toStringList($raw['depends_on'] ?? null),
                'leverage_score' => (float) ($raw['leverage_score'] ?? 0.0),
                'effort' => (float) ($raw['effort'] ?? 0.0),
                'risk' => (string) ($raw['risk'] ?? 'low'),
                'maturity_gap_coverage' => (float) ($raw['maturity_gap_coverage'] ?? 0.0),
                'evidence_strength' => array_key_exists('evidence_strength', $raw) ? (float) $raw['evidence_strength'] : 1.0,
                'status' => (string) ($raw['status'] ?? 'queued'),
            ];
        }

        $usable = [];
        foreach ($tasks as $id => $task) {
            if (! in_array($task['status'], self::EXCLUDED_STATUSES, true) && $task['evidence_strength'] >= self::MIN_EVIDENCE_STRENGTH) {
                $usable[$id] = true;
            }
        }

        $dependents = [];
        foreach ($tasks as $id => $task) {
            foreach ($task['depends_on'] as $prereq) {
                $dependents[$prereq][$id] = true;
            }
        }

        $effectiveScore = [];
        foreach ($usable as $id => $_) {
            $effectiveScore[$id] = $this->effectiveScore($tasks[$id]);
        }

        // path_score DP over usable tasks, in dependency-respecting order via memoized recursion.
        $pathScore = [];
        $bestPredecessor = [];
        $visiting = [];
        $compute = function (string $id) use (&$compute, &$pathScore, &$bestPredecessor, &$visiting, $usable, $tasks, $effectiveScore): float {
            if (isset($pathScore[$id])) {
                return $pathScore[$id];
            }
            if (isset($visiting[$id])) {
                // cycle guard: treat as a root to avoid infinite recursion
                return 0.0;
            }
            $visiting[$id] = true;

            $best = 0.0;
            $bestPred = null;
            foreach ($tasks[$id]['depends_on'] as $prereq) {
                if (! isset($usable[$prereq])) {
                    continue;
                }
                $score = $compute($prereq);
                if ($score > $best) {
                    $best = $score;
                    $bestPred = $prereq;
                }
            }

            unset($visiting[$id]);
            $bestPredecessor[$id] = $bestPred;
            $pathScore[$id] = round($effectiveScore[$id] + $best, 4);

            return $pathScore[$id];
        };

        foreach (array_keys($usable) as $id) {
            $compute($id);
        }

        $anchor = null;
        $anchorScore = -INF;
        foreach ($pathScore as $id => $score) {
            if ($score > $anchorScore) {
                $anchorScore = $score;
                $anchor = $id;
            }
        }

        $criticalPath = [];
        $cursor = $anchor;
        while ($cursor !== null) {
            array_unshift($criticalPath, $cursor);
            $cursor = $bestPredecessor[$cursor] ?? null;
        }

        $bottleneckTasks = array_values(array_filter(
            $criticalPath,
            static fn (string $id): bool => count($dependents[$id] ?? []) >= 2,
        ));

        $parallelizableBranches = $this->connectedComponents($usable, $tasks, $criticalPath);

        return [
            'schema' => self::SCHEMA,
            'critical_path_task_ids' => $criticalPath,
            'path_score' => $anchor !== null ? round($anchorScore, 4) : 0.0,
            'bottleneck_tasks' => $bottleneckTasks,
            'parallelizable_branches' => $parallelizableBranches,
            'next_best_task' => $criticalPath[0] ?? null,
        ];
    }

    /** @param array<string,mixed> $task */
    private function effectiveScore(array $task): float
    {
        $riskPenalty = self::RISK_PENALTIES[$task['risk']] ?? 0.0;

        return round(
            $task['leverage_score'] - 0.10 * $task['effort'] + 0.20 * $task['maturity_gap_coverage'] - $riskPenalty,
            4,
        );
    }

    /**
     * @param  array<string,bool>  $usable
     * @param  array<string,array<string,mixed>>  $tasks
     * @param  list<string>  $criticalPath
     * @return list<list<string>>
     */
    private function connectedComponents(array $usable, array $tasks, array $criticalPath): array
    {
        $remaining = array_diff(array_keys($usable), $criticalPath);
        if ($remaining === []) {
            return [];
        }

        $parent = array_combine($remaining, $remaining);
        $find = function (string $x) use (&$find, &$parent): string {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }

            return $x;
        };
        $union = function (string $a, string $b) use (&$find, &$parent): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$ra] = $rb;
            }
        };

        $remainingSet = array_flip($remaining);
        foreach ($remaining as $id) {
            foreach ($tasks[$id]['depends_on'] as $prereq) {
                if (isset($remainingSet[$prereq])) {
                    $union($id, $prereq);
                }
            }
        }

        $components = [];
        foreach ($remaining as $id) {
            $components[$find($id)][] = $id;
        }

        return array_values($components);
    }

    private function toStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('strval', $value), static fn (string $s): bool => $s !== '')));
    }
}
