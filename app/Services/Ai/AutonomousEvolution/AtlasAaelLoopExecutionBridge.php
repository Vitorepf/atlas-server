<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Aael\AtlasAaelExecutionPlanProver;
use App\Services\Ai\AutonomousEvolution\Aael\AtlasAaelExecutionDriftAuditor;
use App\Services\Ai\AutonomousEvolution\Aael\AtlasAaelExecutionReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\InFlight\AtlasAaelInFlightStepValidator;
use Throwable;

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
        private readonly ?AtlasAaelInFlightStepValidator $inFlightStepValidator = null,
        private readonly ?AtlasAaelExecutionReceiptLedger $receiptLedger = null,
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
        $inFlightStepValidator = $this->inFlightStepValidator ?? new AtlasAaelInFlightStepValidator;

        foreach ($opportunities as $opportunity) {
            $task = is_array($opportunity['task'] ?? null) ? $opportunity['task'] : null;
            $hasMetric = $task !== null
                && is_array($task['acceptance'] ?? null);

            if ($hasMetric) {
                $contract = AtlasLoopResearchContract::fromAcceptance($task['acceptance'], [
                    'ambition' => (string) ($opportunity['ambition'] ?? AtlasLoopResearchContract::AMBITION_BEAT),
                    'scope' => (string) ($opportunity['scope'] ?? AtlasLoopResearchContract::SCOPE_MIXED),
                ]);
                if (! $contract->isComplete()) {
                    $deferred[] = [
                        'objective' => (string) ($task['objective'] ?? $opportunity['objective'] ?? ''),
                        'opportunity_id' => $opportunity['opportunity_id'] ?? null,
                        'reason' => 'incomplete_research_contract:'.implode(',', $contract->missingComponents()),
                    ];

                    continue;
                }

                $task['research_contract'] = $contract->toArray();
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

        $loopRun = $this->emptyLoopRun($options, $tasks === [] ? 'no_proven_tasks' : 'queue_exhausted');
        $driftAudit = [];
        $validationCalls = [];
        $validationHaltReason = null;
        $previousStepReceipt = null;
        $previousWorldSnapshot = null;

        foreach ($tasks as $index => $task) {
            if ($previousStepReceipt !== null) {
                $validation = $inFlightStepValidator->validate($previousStepReceipt, $previousWorldSnapshot ?? [], $index);
                $validationCalls[] = $validation;

                if (($validation['passed'] ?? false) !== true) {
                    $validationHaltReason = is_array($validation['reason'] ?? null)
                        ? $validation['reason']
                        : ['code' => 'in_flight_invariant_failed', 'step_index' => $index];
                    $loopRun['stop_reason'] = 'in_flight_invariant_failed';
                    break;
                }
            }

            $stepRun = $this->runner->run([$task], $options + ['max_tasks' => 1]);
            $stepExplorations = is_array($stepRun['explorations'] ?? null) ? $stepRun['explorations'] : [];
            $exploration = is_array($stepExplorations[0] ?? null) ? $stepExplorations[0] : [];

            $loopRun['tasks_processed']++;
            $loopRun['proposals_certified_for_review'] += (int) ($stepRun['proposals_certified_for_review'] ?? 0);
            $loopRun['elapsed_seconds'] = round((float) ($loopRun['elapsed_seconds'] ?? 0.0) + (float) ($stepRun['elapsed_seconds'] ?? 0.0), 1);
            $loopRun['proposals'] = array_values(array_merge(
                is_array($loopRun['proposals'] ?? null) ? $loopRun['proposals'] : [],
                is_array($stepRun['proposals'] ?? null) ? $stepRun['proposals'] : [],
            ));
            $loopRun['explorations'] = array_values(array_merge(
                is_array($loopRun['explorations'] ?? null) ? $loopRun['explorations'] : [],
                $stepExplorations,
            ));
            $loopRun['stop_reason'] = (string) ($stepRun['stop_reason'] ?? $loopRun['stop_reason']);

            $taskId = (string) ($task['objective'] ?? 'task-'.$index);
            $driftAudit[$taskId] = $driftAuditor->audit($task, $exploration);
            $previousStepReceipt = $this->buildStepReceipt($task, $exploration, $index);
            $previousWorldSnapshot = $this->extractWorldSnapshot($stepRun, $exploration);
        }

        $response = [
            'schema_version' => self::SCHEMA,
            'opportunities_total' => count($opportunities),
            'executed_tasks' => (int) ($loopRun['tasks_processed'] ?? 0),
            'deferred_count' => count($deferred),
            'incomplete_contracts_deferred' => count(array_filter(
                $deferred,
                static fn (array $item): bool => str_starts_with((string) ($item['reason'] ?? ''), 'incomplete_research_contract:'),
            )),
            'deferred' => $deferred,
            'prover_rejected' => $proverRejected,
            'drift_audit' => $driftAudit,
            'in_flight_validation' => [
                'calls' => $validationCalls,
                'halted' => $validationHaltReason !== null,
                'halt_reason' => $validationHaltReason,
            ],
            // the loop NEVER merges — proposals are certified-for-review.
            'merged_to_main' => false,
            'loop_run' => $loopRun,
        ];

        $ledger = $this->receiptLedger;
        if ($ledger === null) {
            $response['ledger_status'] = 'skipped';
            $response['execution_id'] = null;

            return $response;
        }
        try {
            $receipt = $ledger->record(
                $opportunities,
                ['rejected' => $proverRejected, 'rejected_count' => count($proverRejected)],
                [
                    'tasks_processed' => (int) ($loopRun['tasks_processed'] ?? 0),
                    'proposals_certified_for_review' => (int) ($loopRun['proposals_certified_for_review'] ?? 0),
                    'stop_reason' => (string) ($loopRun['stop_reason'] ?? ''),
                    'deferred_count' => count($deferred),
                ],
                $driftAudit,
            );
            $response['ledger_status'] = $receipt['status'];
            $response['execution_id'] = $receipt['execution_id'];
        } catch (Throwable $e) {
            $response['ledger_status'] = AtlasAaelExecutionReceiptLedger::STATUS_ERROR;
            $response['execution_id'] = null;
            $response['ledger_error'] = mb_substr($e->getMessage(), 0, 200);
        }

        return $response;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function emptyLoopRun(array $options, string $stopReason): array
    {
        return [
            'schema_version' => AtlasEvolutionLoopRunner::SCHEMA,
            'propose_only' => (bool) ($options['propose_only'] ?? true),
            'merged_to_main' => false,
            'tasks_processed' => 0,
            'proposals_certified_for_review' => 0,
            'stop_reason' => $stopReason,
            'elapsed_seconds' => 0.0,
            'proposals' => [],
            'explorations' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $task
     * @param  array<string,mixed>  $exploration
     * @return array<string,mixed>
     */
    private function buildStepReceipt(array $task, array $exploration, int $stepIndex): array
    {
        return [
            'objective' => (string) ($task['objective'] ?? $exploration['objective'] ?? ''),
            'step_index' => $stepIndex,
            'declared_invariants' => $this->normalizeInvariantMap(
                $task['declared_invariants'] ?? $task['invariants'] ?? [],
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $stepRun
     * @param  array<string,mixed>  $exploration
     * @return array<string,mixed>
     */
    private function extractWorldSnapshot(array $stepRun, array $exploration): array
    {
        if (is_array($exploration['world_snapshot'] ?? null)) {
            return $exploration['world_snapshot'];
        }

        return is_array($stepRun['world_snapshot'] ?? null) ? $stepRun['world_snapshot'] : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeInvariantMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        if (array_is_list($value)) {
            $normalized = [];
            foreach ($value as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $invariantId = (string) ($row['invariant_id'] ?? '');
                if ($invariantId === '') {
                    continue;
                }

                $normalized[$invariantId] = $row['declared_value'] ?? $row['value'] ?? null;
            }

            return $normalized;
        }

        return $value;
    }
}
