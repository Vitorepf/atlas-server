<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskGraph;

/**
 * Strategic chain planner. Groups candidate tasks into ordered evolution chains
 * that respect prerequisite ordering and risk constraints — no enqueue, no mutations.
 *
 * Input facts:
 *   tasks                — list of {id, prerequisites[], unlocks[], risk, payoff}.
 *   high_risk_threshold  — risk >= this is "high risk" (default 0.70).
 *
 * AC2 — plan() returns:
 *   chains           — ordered sequences of task_ids (each chain is a linear path).
 *   unresolved_tasks — task_ids with unsatisfiable prerequisites (cycle or missing dep).
 *   risk_guards      — {task_id, blocked_by, reason} for AC3 violations caught.
 *   plan_summary     — {total_tasks, chained, unresolved, high_risk_tasks}.
 *
 * AC3 — two hard rules enforced:
 *   1. No high-risk task appears before any of its listed prerequisite tasks.
 *      (Guaranteed by topological ordering on prerequisites.)
 *   2. No two high-risk tasks occupy the same topological depth level.
 *      (Enforced by shifting later high-risk tasks to the next depth.)
 *
 * Pure, deterministic, provider-free.
 */
final class AtlasTaskGraphStrategicChainPlanner
{
    public const SCHEMA = 'atlas.task_graph.strategic_chain_planner.v1';

    private const DEFAULT_HIGH_RISK_THRESHOLD = 0.70;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function plan(array $facts): array
    {
        $rawTasks = is_array($facts['tasks'] ?? null) ? $facts['tasks'] : [];
        $highRisk = (float) ($facts['high_risk_threshold'] ?? self::DEFAULT_HIGH_RISK_THRESHOLD);
        $outcomeFacts = is_array($facts['task_outcome_facts'] ?? null) ? $facts['task_outcome_facts'] : [];
        $outcomeAdjustments = $this->outcomeAdjustments($outcomeFacts);

        // Index tasks by id.
        $taskMap = [];
        foreach ($rawTasks as $raw) {
            if (! is_array($raw) || ! isset($raw['id'])) {
                continue;
            }
            $id = (string) $raw['id'];
            $taskMap[$id] = [
                'id'            => $id,
                'family'        => (string) ($raw['family'] ?? $id),
                'prerequisites' => array_map('strval', (array) ($raw['prerequisites'] ?? [])),
                'unlocks'       => array_map('strval', (array) ($raw['unlocks']       ?? [])),
                'risk'          => max(0.0, min(1.0, (float) ($raw['risk']   ?? 0.0))),
                'payoff'        => max(0.0, min(1.0, (float) ($raw['payoff'] ?? 0.0))),
            ];
        }

        if (empty($taskMap)) {
            return $this->result([], [], [], [], 0, 0);
        }

        // Build reverse adjacency from prerequisites: pre → tasks that need it.
        $successors = array_fill_keys(array_keys($taskMap), []);
        $inDegree   = array_fill_keys(array_keys($taskMap), 0);
        foreach ($taskMap as $id => $t) {
            foreach ($t['prerequisites'] as $pre) {
                if (! isset($taskMap[$pre])) {
                    continue; // missing dep — handled via unresolved below
                }
                $inDegree[$id]++;
                $successors[$pre][] = $id;
            }
        }

        // Kahn's BFS, level-by-level for depth tracking.
        $queue = [];
        foreach ($inDegree as $id => $deg) {
            if ($deg === 0) {
                $queue[] = $id;
            }
        }
        sort($queue);

        $levels    = [];
        $placed    = [];
        $remaining = $inDegree;
        $depth     = 0;

        while (! empty($queue)) {
            $levels[$depth] = $queue;
            $nextQueue      = [];
            foreach ($queue as $id) {
                $placed[$id] = $depth;
                foreach ($successors[$id] as $succ) {
                    $remaining[$succ]--;
                    if ($remaining[$succ] === 0) {
                        $nextQueue[] = $succ;
                    }
                }
            }
            sort($nextQueue);
            $queue = $nextQueue;
            $depth++;
        }

        // Tasks not placed → cycle or missing dependency.
        $unresolvedTasks = [];
        foreach ($taskMap as $id => $_) {
            if (! isset($placed[$id])) {
                $unresolvedTasks[] = $id;
            }
        }
        sort($unresolvedTasks);

        // AC3: no two high-risk tasks at the same depth level.
        $riskGuards    = [];
        $adjustedDepth = $placed;

        foreach ($levels as $d => $ids) {
            $highRiskAtLevel = [];
            foreach ($ids as $id) {
                if ($taskMap[$id]['risk'] >= $highRisk) {
                    $highRiskAtLevel[] = $id;
                }
            }
            // First one stays; subsequent ones shift to next depth.
            for ($i = 1; $i < count($highRiskAtLevel); $i++) {
                $blockedId = $highRiskAtLevel[$i];
                $riskGuards[] = [
                    'task_id'    => $blockedId,
                    'blocked_by' => $highRiskAtLevel[0],
                    'reason'     => 'parallel_high_risk_disallowed',
                ];
                $adjustedDepth[$blockedId] = $d + 1;
            }
        }

        // Rebuild depth groups after risk adjustment. Within each tier (same prerequisite depth),
        // order by outcome: boosted families first, demoted families last, ties broken by id — so
        // a tier never reorders across prerequisite/high-risk depth boundaries already enforced above.
        $depthGroups = [];
        foreach ($adjustedDepth as $id => $d) {
            $depthGroups[$d][] = $id;
        }
        ksort($depthGroups);
        foreach ($depthGroups as &$ids) {
            usort($ids, function (string $a, string $b) use ($taskMap, $outcomeAdjustments): int {
                $rankA = $this->outcomeRank($taskMap[$a]['family'], $outcomeAdjustments);
                $rankB = $this->outcomeRank($taskMap[$b]['family'], $outcomeAdjustments);

                return $rankA <=> $rankB ?: strcmp($a, $b);
            });
        }
        unset($ids);

        $flatOrder = [];
        foreach ($depthGroups as $ids) {
            foreach ($ids as $id) {
                $flatOrder[] = $id;
            }
        }

        $chains = $this->buildChains($flatOrder, $taskMap, $adjustedDepth);

        $highRiskCount = count(array_filter($taskMap, fn($t) => $t['risk'] >= $highRisk));

        $outcomeGuards = [];
        foreach ($taskMap as $id => $t) {
            $reasons = $outcomeAdjustments[$t['family']]['reasons'] ?? [];
            if ($reasons !== []) {
                $outcomeGuards[] = [
                    'task_id' => $id,
                    'family'  => $t['family'],
                    'reasons' => $reasons,
                ];
            }
        }
        usort($outcomeGuards, static fn (array $a, array $b): int => strcmp($a['task_id'], $b['task_id']));

        return $this->result(
            $chains,
            $unresolvedTasks,
            $riskGuards,
            $outcomeGuards,
            count($flatOrder),
            $highRiskCount,
        );
    }

    /**
     * 0 = boosted (recent real success, no negative signals), 1 = neutral, 2 = demoted
     * (repeated give_back/poison/weak_green) — lower rank sorts first within a tier.
     *
     * @param  array<string,array{boost:bool,demote:bool,reasons:list<string>}>  $outcomeAdjustments
     */
    private function outcomeRank(string $family, array $outcomeAdjustments): int
    {
        $adjustment = $outcomeAdjustments[$family] ?? null;
        if ($adjustment === null) {
            return 1;
        }
        if ($adjustment['demote']) {
            return 2;
        }
        if ($adjustment['boost']) {
            return 0;
        }

        return 1;
    }

    /**
     * Derives a per-family boost/demote verdict from muscle outcome facts. A family is BOOSTED only
     * when it has a positive signal (recent_success or a high success_rate) AND no negative signal.
     * A family is DEMOTED when it carries repeated give_back, any poison, or repeated weak_green —
     * negative evidence always overrides a positive one (never let a stale success mask new poison).
     *
     * @param  array<string,mixed>  $outcomeFacts  family => {success_rate?:float, recent_success?:bool,
     *                                               give_back_count?:int, poison_count?:int, weak_green_count?:int}
     * @return array<string,array{boost:bool,demote:bool,reasons:list<string>}>
     */
    private function outcomeAdjustments(array $outcomeFacts): array
    {
        $adjustments = [];
        foreach ($outcomeFacts as $family => $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $family = (string) $family;

            $successRate = isset($raw['success_rate']) ? (float) $raw['success_rate'] : null;
            $recentSuccess = (bool) ($raw['recent_success'] ?? false);
            $giveBackCount = max(0, (int) ($raw['give_back_count'] ?? 0));
            $poisonCount = max(0, (int) ($raw['poison_count'] ?? 0));
            $weakGreenCount = max(0, (int) ($raw['weak_green_count'] ?? 0));

            $reasons = [];
            if ($giveBackCount >= 2) {
                $reasons[] = 'repeated_give_back';
            }
            if ($poisonCount > 0) {
                $reasons[] = 'poison_detected';
            }
            if ($weakGreenCount >= 2) {
                $reasons[] = 'repeated_weak_green';
            }

            $demote = $reasons !== [];
            $boost = ! $demote && ($recentSuccess || ($successRate !== null && $successRate >= 0.7));

            $adjustments[$family] = ['boost' => $boost, 'demote' => $demote, 'reasons' => $reasons];
        }

        return $adjustments;
    }

    /**
     * Groups the topological order into chains by following unlock edges.
     * A task that hasn't been assigned yet and whose `unlocks` are followed
     * forms a linear chain.
     *
     * @param  string[]  $order
     * @param  array<string,array{unlocks:string[]}>  $taskMap
     * @param  array<string,int>  $depths
     * @return list<list<string>>
     */
    private function buildChains(array $order, array $taskMap, array $depths): array
    {
        $inOrder  = array_flip($order);
        $assigned = [];
        $chains   = [];

        foreach ($order as $startId) {
            if (isset($assigned[$startId])) {
                continue;
            }
            $chain              = [$startId];
            $assigned[$startId] = true;
            $current            = $startId;

            while (true) {
                $best      = null;
                $bestDepth = PHP_INT_MAX;
                foreach ($taskMap[$current]['unlocks'] as $next) {
                    if (! isset($inOrder[$next]) || isset($assigned[$next])) {
                        continue;
                    }
                    $d = $depths[$next] ?? PHP_INT_MAX;
                    if ($d < $bestDepth) {
                        $bestDepth = $d;
                        $best      = $next;
                    }
                }
                if ($best === null) {
                    break;
                }
                $chain[]         = $best;
                $assigned[$best] = true;
                $current         = $best;
            }

            $chains[] = $chain;
        }

        return $chains;
    }

    /** @param list<list<string>> $chains */
    private function result(
        array $chains, array $unresolved, array $riskGuards, array $outcomeGuards, int $chained, int $highRisk,
    ): array {
        return [
            'schema_version'   => self::SCHEMA,
            'chains'           => $chains,
            'unresolved_tasks' => $unresolved,
            'risk_guards'      => $riskGuards,
            'outcome_guards'   => $outcomeGuards,
            'plan_summary'     => [
                'total_tasks'     => $chained + count($unresolved),
                'chained'         => $chained,
                'unresolved'      => count($unresolved),
                'high_risk_tasks' => $highRisk,
            ],
        ];
    }
}
