<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Aael\AtlasAaelExecutionPlanProver;
use App\Services\Ai\AutonomousEvolution\Aael\AtlasAaelExecutionDriftAuditor;

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
        private readonly object $runner,
        private readonly ?AtlasAaelExecutionPlanProver $prover = null,
        private readonly ?AtlasAaelExecutionDriftAuditor $driftAuditor = null,
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
        $proverRejected = [];
        $prover = $this->prover ?? new AtlasAaelExecutionPlanProver;
        $driftAuditor = $this->driftAuditor ?? new AtlasAaelExecutionDriftAuditor;

        foreach ($opportunities as $opportunity) {
            $task = is_array($opportunity['task'] ?? null) ? $opportunity['task'] : null;
            $hasMetric = $task !== null
                && is_array($task['acceptance'] ?? null);

            if ($hasMetric) {
                $verdict = $prover->prove($task);
                if ((bool) $verdict['passed']) {
                    $tasks[] = $task;
                } else {
                    $proverRejected[] = [
                        'objective' => (string) ($task['objective'] ?? $opportunity['objective'] ?? ''),
                        'opportunity_id' => $opportunity['opportunity_id'] ?? null,
                        'reasons' => array_values((array) $verdict['reasons']),
                        'schema_version' => $verdict['schema_version'],
                    ];
                }
            } else {
                $deferred[] = [
                    'objective' => (string) ($opportunity['objective'] ?? ''),
                    'opportunity_id' => $opportunity['opportunity_id'] ?? null,
                    'reason' => 'no_metric_shaped_task — needs decomposition into objective + frozen acceptance (the metric) before the loop can grind it',
                ];
            }
        }

        $loopRun = $tasks === []
            ? [
                'schema_version' => AtlasEvolutionLoopRunner::SCHEMA,
                'propose_only' => (bool) ($options['propose_only'] ?? true),
                'merged_to_main' => false,
                'tasks_processed' => 0,
                'proposals_certified_for_review' => 0,
                'stop_reason' => 'no_proven_tasks',
                'elapsed_seconds' => 0.0,
                'proposals' => [],
                'explorations' => [],
            ]
            : $this->runner->run($tasks, $options);

        $driftAudit = [];
        $explorations = is_array($loopRun['explorations'] ?? null) ? $loopRun['explorations'] : [];
        foreach ($tasks as $index => $task) {
            $taskId = (string) ($task['objective'] ?? 'task-'.$index);
            $driftAudit[$taskId] = $driftAuditor->audit($task, is_array($explorations[$index] ?? null) ? $explorations[$index] : []);
        }

        return [
            'schema_version' => self::SCHEMA,
            'opportunities_total' => count($opportunities),
            'executed_tasks' => count($tasks),
            'deferred_count' => count($deferred),
            'deferred' => $deferred,
            'prover_rejected' => $proverRejected,
            'drift_audit' => $driftAudit,
            // the loop NEVER merges — proposals are certified-for-review.
            'merged_to_main' => false,
            'loop_run' => $loopRun,
        ];
    }
}
