<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * The bridge that turns the existing AAEL (Atlas Autonomous Evolution Loop)
 * governance — which observes + selects + gates opportunities but historically
 * NEVER executed ({@see AtlasAutonomousEvolutionLoopService}, claim policy
 * `provider_invoked_directly: false`) — into a REAL executing loop.
 *
 * AAEL stays the governance/selection brain (the Ladder Sources→Ideas stage).
 * This bridge takes AAEL's selected opportunities and runs the metric-shaped ones
 * through the {@see AtlasEvolutionLoopRunner} (explore → frozen judge → propose
 * only). Opportunities that do NOT yet carry a metric-shaped task are returned as
 * `deferred` — honestly flagged as needing decomposition into an objective + a
 * cheap, frozen acceptance (the metric) before the loop can grind them.
 *
 * Built ON AAEL, not parallel to it; provider-agnostic via the runner's driver.
 */
final class AtlasAaelLoopExecutionBridge
{
    public const SCHEMA = 'atlas.evolution.aael_bridge.v1';

    public function __construct(
        private readonly AtlasEvolutionLoopRunner $runner,
    ) {}

    /**
     * @param  list<array<string,mixed>>  $opportunities  AAEL selected_opportunities (each may carry a 'task')
     * @param  array<string,mixed>  $options  loop runner options (max_tasks, max_seconds, scenarios_per_task, propose_only)
     * @return array<string,mixed>  atlas.evolution.aael_bridge.v1
     */
    public function execute(array $opportunities, array $options = []): array
    {
        $tasks = [];
        $deferred = [];

        foreach ($opportunities as $opportunity) {
            $task = is_array($opportunity['task'] ?? null) ? $opportunity['task'] : null;
            $hasMetric = $task !== null
                && is_array($task['acceptance'] ?? null)
                && ($task['acceptance']['commands'] ?? []) !== [];

            if ($hasMetric) {
                $tasks[] = $task;
            } else {
                $deferred[] = [
                    'objective' => (string) ($opportunity['objective'] ?? ''),
                    'opportunity_id' => $opportunity['opportunity_id'] ?? null,
                    'reason' => 'no_metric_shaped_task — needs decomposition into objective + frozen acceptance (the metric) before the loop can grind it',
                ];
            }
        }

        $loopRun = $this->runner->run($tasks, $options);

        return [
            'schema_version' => self::SCHEMA,
            'opportunities_total' => count($opportunities),
            'executed_tasks' => count($tasks),
            'deferred_count' => count($deferred),
            'deferred' => $deferred,
            // the loop NEVER merges — proposals are certified-for-review.
            'merged_to_main' => false,
            'loop_run' => $loopRun,
        ];
    }
}
