<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskGraph;

/**
 * Plans strategic task chains that prioritize dependency unlocks,
 * worker feed continuity and high-risk proof tasks before independent
 * low-impact work.
 *
 * Read-only: never starts processes, never calls providers, never
 * dispatches work, never writes the ledger.
 */
final class AtlasTaskGraphStrategicChainPlanner
{
    public const SCHEMA = 'atlas.self_construction.task_graph_strategic_chain_planner.v1';

    public const PRIORITY_DEPENDENCY_UNLOCK = 100;
    public const PRIORITY_WORKER_FEED = 80;
    public const PRIORITY_HIGH_RISK_PROOF = 60;
    public const PRIORITY_INDEPENDENT_LOW_IMPACT = 20;

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function plan(array $tasks, array $options = []): array
    {
        $workerFeedTarget = max(1, (int) ($options['worker_feed_target'] ?? 3));
        $chains = [];
        $allSteps = [];

        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }

            $id = (string) ($task['id'] ?? '');
            $kind = (string) ($task['kind'] ?? 'independent');
            $riskLevel = (string) ($task['risk_level'] ?? 'low');
            $impactScore = (float) ($task['impact_score'] ?? 0.0);
            $unlocksDependencies = (array) ($task['unlocks_dependencies'] ?? []);
            $proofPredecessors = (array) ($task['proof_predecessors'] ?? []);
            $dependsOn = (array) ($task['depends_on'] ?? []);

            $priority = $this->computePriority($kind, $riskLevel, $impactScore, $unlocksDependencies);

            $executable = true;
            $blockerReasons = [];

            // High-risk tasks require proof predecessors
            if ($riskLevel === 'high' && $proofPredecessors === []) {
                $executable = false;
                $blockerReasons[] = 'high_risk_requires_proof_predecessors';
            }

            // Check dependencies
            if ($dependsOn !== []) {
                $completedDeps = (array) ($options['completed_task_ids'] ?? []);
                $unmetDeps = array_diff($dependsOn, $completedDeps);
                if ($unmetDeps !== []) {
                    $executable = false;
                    $blockerReasons[] = 'unmet_dependencies:'.implode(',', $unmetDeps);
                }
            }

            $step = [
                'id' => $id,
                'kind' => $kind,
                'risk_level' => $riskLevel,
                'impact_score' => $impactScore,
                'priority' => $priority,
                'executable' => $executable,
                'blocker_reasons' => $blockerReasons,
                'unlocks_dependencies' => $unlocksDependencies,
                'proof_predecessors' => $proofPredecessors,
                'depends_on' => $dependsOn,
            ];

            $allSteps[] = $step;
        }

        // Sort by priority descending
        usort($allSteps, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        // Build chains ensuring worker feed target
        $chains = $this->buildChains($allSteps, $workerFeedTarget);

        return [
            'schema' => self::SCHEMA,
            'chains' => $chains,
            'chain_count' => count($chains),
            'worker_feed_target' => $workerFeedTarget,
            'total_steps' => count($allSteps),
            'executable_steps' => count(array_filter($allSteps, static fn (array $s): bool => $s['executable'])),
            'blocked_steps' => count(array_filter($allSteps, static fn (array $s): bool => ! $s['executable'])),
            'read_only' => true,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
        ];
    }

    private function computePriority(string $kind, string $riskLevel, float $impactScore, array $unlocksDependencies): int
    {
        if ($unlocksDependencies !== []) {
            return self::PRIORITY_DEPENDENCY_UNLOCK;
        }
        if ($kind === 'worker_feed') {
            return self::PRIORITY_WORKER_FEED;
        }
        if ($riskLevel === 'high') {
            return self::PRIORITY_HIGH_RISK_PROOF;
        }
        if ($impactScore < 0.3) {
            return self::PRIORITY_INDEPENDENT_LOW_IMPACT;
        }

        return 40;
    }

    private function buildChains(array $steps, int $workerFeedTarget): array
    {
        $chains = [];
        $currentChain = [];
        $executableSteps = array_filter($steps, static fn (array $s): bool => $s['executable']);

        foreach ($executableSteps as $step) {
            $currentChain[] = $step;
            if (count($currentChain) >= $workerFeedTarget) {
                $chains[] = [
                    'chain_id' => 'chain-'.(count($chains) + 1),
                    'steps' => $currentChain,
                    'step_count' => count($currentChain),
                ];
                $currentChain = [];
            }
        }

        if ($currentChain !== []) {
            $chains[] = [
                'chain_id' => 'chain-'.(count($chains) + 1),
                'steps' => $currentChain,
                'step_count' => count($currentChain),
            ];
        }

        return $chains;
    }
}
