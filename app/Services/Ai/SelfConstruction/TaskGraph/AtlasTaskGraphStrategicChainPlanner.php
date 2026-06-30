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

        // Index tasks by id.
        $taskMap = [];
        foreach ($rawTasks as $raw) {
            if (! is_array($raw) || ! isset($raw['id'])) {
                continue;
            }
            $id = (string) $raw['id'];
            $taskMap[$id] = [
                'id'            => $id,
                'prerequisites' => array_map('strval', (array) ($raw['prerequisites'] ?? [])),
                'unlocks'       => array_map('strval', (array) ($raw['unlocks']       ?? [])),
                'risk'          => max(0.0, min(1.0, (float) ($raw['risk']   ?? 0.0))),
                'payoff'        => max(0.0, min(1.0, (float) ($raw['payoff'] ?? 0.0))),
            ];
        }

        if (empty($taskMap)) {
            return $this->result([], [], [], 0, 0);
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

        // Rebuild depth groups after risk adjustment.
        $depthGroups = [];
        foreach ($adjustedDepth as $id => $d) {
            $depthGroups[$d][] = $id;
        }
        ksort($depthGroups);
        foreach ($depthGroups as &$ids) {
            sort($ids);
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

        return $this->result(
            $chains,
            $unresolvedTasks,
            $riskGuards,
            count($flatOrder),
            $highRiskCount,
        );
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
        array $chains, array $unresolved, array $riskGuards, int $chained, int $highRisk,
    ): array {
        return [
            'schema_version'   => self::SCHEMA,
            'chains'           => $chains,
            'unresolved_tasks' => $unresolved,
            'risk_guards'      => $riskGuards,
            'plan_summary'     => [
                'total_tasks'     => $chained + count($unresolved),
                'chained'         => $chained,
                'unresolved'      => count($unresolved),
                'high_risk_tasks' => $highRisk,
            ],
        ];
    }
}
