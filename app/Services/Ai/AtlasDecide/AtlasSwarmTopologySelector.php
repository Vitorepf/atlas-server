<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

/**
 * L6-10: pure, dependency-free topology selection + plan-DAG composition.
 *
 * Extracted so BOTH the shadow proof gate ({@see AtlasSwarmTopologyAutoComposerService})
 * AND the live conductor ({@see AtlasEngineeringRunConductorService}) consume the
 * exact same topology-by-task-type decision without a circular dependency (the
 * composer depends on the conductor; the conductor must NOT depend back on the
 * composer). This selector has zero collaborators, so it is the single source of
 * truth for "which topology does this task category get" and "what bounded
 * plan-DAG does that topology compose to".
 *
 * It withholds new arm/role types, loops, dynamic fan-width and arbitrary code —
 * every composed plan is a small acyclic DAG of EXISTING dispatch nodes, exactly
 * what {@see AtlasConductorPlanGate} accepts. Selecting a topology here never
 * spends a provider token by itself; the conductor's mode guard still decides
 * shadow vs live downstream.
 */
final class AtlasSwarmTopologySelector
{
    public const TOPOLOGY_TOURNAMENT = 'tournament';

    public const TOPOLOGY_PANEL = 'panel';

    public const TOPOLOGY_PIPELINE = 'pipeline';

    public const TOPOLOGY_DEBATE = 'debate';

    /**
     * Select the bounded multi-agent topology for a task category. Deterministic
     * and pure: same category in => same topology out.
     */
    public function selectTopology(string $taskCategory): string
    {
        $category = strtolower($taskCategory);

        if (str_contains($category, 'code') || str_contains($category, 'generation')) {
            return self::TOPOLOGY_TOURNAMENT;
        }
        if (str_contains($category, 'audit') || str_contains($category, 'verify') || str_contains($category, 'review')) {
            return self::TOPOLOGY_PANEL;
        }
        if (str_contains($category, 'retrieval') || str_contains($category, 'context')) {
            return self::TOPOLOGY_PIPELINE;
        }

        return self::TOPOLOGY_DEBATE;
    }

    /**
     * Compose the bounded acyclic plan-DAG of EXISTING dispatch nodes for the
     * chosen topology, rooted on the task category. The shape is exactly what
     * {@see AtlasConductorPlanGate} validates (unique node ids, resolvable
     * depends_on, <= MAX_NODES).
     *
     * @return array{nodes:list<array<string,mixed>>}
     */
    public function composePlan(string $topology, string $rootCategory): array
    {
        return match ($topology) {
            self::TOPOLOGY_TOURNAMENT => [
                'nodes' => [
                    ['node_id' => 'candidate_a', 'task_category' => $rootCategory, 'role' => 'engineer'],
                    ['node_id' => 'candidate_b', 'task_category' => $rootCategory, 'role' => 'engineer'],
                    ['node_id' => 'verdict', 'task_category' => 'audit', 'role' => 'verifier', 'depends_on' => ['candidate_a', 'candidate_b']],
                ],
            ],
            self::TOPOLOGY_PANEL => [
                'nodes' => [
                    ['node_id' => 'static_audit', 'task_category' => $rootCategory, 'role' => 'auditor'],
                    ['node_id' => 'semantic_audit', 'task_category' => $rootCategory, 'role' => 'reviewer'],
                    ['node_id' => 'verdict', 'task_category' => $rootCategory, 'role' => 'verifier', 'depends_on' => ['static_audit', 'semantic_audit']],
                ],
            ],
            self::TOPOLOGY_PIPELINE => [
                'nodes' => [
                    ['node_id' => 'retrieve', 'task_category' => $rootCategory, 'role' => 'researcher'],
                    ['node_id' => 'rank', 'task_category' => $rootCategory, 'role' => 'ranker', 'depends_on' => ['retrieve']],
                    ['node_id' => 'synthesize', 'task_category' => 'reasoning', 'role' => 'synthesizer', 'depends_on' => ['rank']],
                ],
            ],
            default => [
                'nodes' => [
                    ['node_id' => 'position_a', 'task_category' => $rootCategory, 'role' => 'engineer'],
                    ['node_id' => 'position_b', 'task_category' => $rootCategory, 'role' => 'critic'],
                    ['node_id' => 'synthesis', 'task_category' => $rootCategory, 'role' => 'synthesizer', 'depends_on' => ['position_a', 'position_b']],
                ],
            ],
        };
    }

    /**
     * Convenience: select + compose in one call. Returns both the chosen topology
     * and the plan-DAG so a caller can record provenance.
     *
     * @return array{topology:string,plan:array{nodes:list<array<string,mixed>>}}
     */
    public function composeForTaskCategory(string $taskCategory): array
    {
        $topology = $this->selectTopology($taskCategory);

        return [
            'topology' => $topology,
            'plan' => $this->composePlan($topology, $taskCategory),
        ];
    }
}
