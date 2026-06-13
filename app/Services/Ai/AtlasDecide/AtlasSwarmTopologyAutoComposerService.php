<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * L6-10: auto-compose a bounded multi-agent topology by task type.
 *
 * This is a shadow-only proof gate. It selects a plan topology, sends it
 * through the existing governed conductor, then measures convergence from the
 * real plan_trace. It never changes runtime routing or activates live spend.
 */
final class AtlasSwarmTopologyAutoComposerService
{
    public const SCHEMA_VERSION = 'atlas.swarm.topology_auto_composer.v1';

    public function __construct(
        private readonly AtlasEngineeringRunConductorService $conductor,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $options = []): array
    {
        $cfg = (array) config('atlas.patamar4.swarm_topology_auto_composer', []);
        $enabled = (bool) ($options['enabled'] ?? $cfg['enabled'] ?? true);
        $fixture = trim((string) ($options['fixture'] ?? 'two-types'));
        if ($fixture === '') {
            $fixture = 'two-types';
        }

        $minTaskTypes = max(2, (int) ($options['min_task_types'] ?? $cfg['min_task_types'] ?? 2));
        $minDistinctTopologies = max(2, (int) ($options['min_distinct_topologies'] ?? $cfg['min_distinct_topologies'] ?? 2));
        $minConvergenceRate = max(0.0, min(1.0, (float) ($options['min_convergence_rate'] ?? $cfg['min_convergence_rate'] ?? 1.0)));
        $forcedProvider = trim((string) ($options['forced_provider'] ?? $cfg['forced_provider'] ?? 'codex'));
        $forcedModel = trim((string) ($options['forced_model'] ?? $cfg['forced_model'] ?? 'gpt-5.5'));

        if (! $enabled) {
            return $this->payload(
                status: 'disabled',
                certified: false,
                fixture: $fixture,
                tasks: [],
                measurements: [],
                blockers: ['swarm_topology_auto_composer_disabled'],
                config: compact('minTaskTypes', 'minDistinctTopologies', 'minConvergenceRate', 'forcedProvider', 'forcedModel'),
            );
        }

        $tasks = $this->taskSpecs($fixture, $options);
        if ($tasks === null) {
            return $this->payload(
                status: 'blocked',
                certified: false,
                fixture: $fixture,
                tasks: [],
                measurements: [],
                blockers: ['unsupported_fixture'],
                config: compact('minTaskTypes', 'minDistinctTopologies', 'minConvergenceRate', 'forcedProvider', 'forcedModel'),
            );
        }

        $measurements = [];
        foreach ($tasks as $task) {
            $category = (string) $task['task_category'];
            $topology = $this->selectTopology($category);
            $plan = $this->composePlan($topology, $category);
            $envelope = $this->runPlan($task, $topology, $plan, $forcedProvider, $forcedModel);
            $measurements[] = $this->measure($task, $topology, $plan, $envelope, $minConvergenceRate);
        }

        $taskTypes = array_values(array_unique(array_map(
            static fn (array $task): string => (string) ($task['task_category'] ?? ''),
            $tasks,
        )));
        $topologies = array_values(array_unique(array_map(
            static fn (array $measurement): string => (string) ($measurement['topology'] ?? ''),
            $measurements,
        )));

        $blockers = [];
        if (count($taskTypes) < $minTaskTypes) {
            $blockers[] = 'task_type_coverage_below_floor';
        }
        if (count($topologies) < $minDistinctTopologies) {
            $blockers[] = 'distinct_topologies_below_floor';
        }

        foreach ($measurements as $measurement) {
            $category = (string) ($measurement['task_category'] ?? 'unknown');
            if (! (bool) ($measurement['measured'] ?? false)) {
                $blockers[] = 'convergence_not_measured:'.$category;
            }
            if (! (bool) ($measurement['converged'] ?? false)) {
                $blockers[] = 'convergence_below_floor:'.$category;
            }
            if ((int) data_get($measurement, 'status_counts.no_dispatch', 0) > 0) {
                $blockers[] = 'node_no_dispatch:'.$category;
            }
            foreach ((array) ($measurement['winner_providers'] ?? []) as $provider) {
                if (str_contains(strtolower((string) $provider), 'claude')) {
                    $blockers[] = 'forbidden_claude_provider_observed:'.$category;
                }
                if ((string) $provider !== $forcedProvider) {
                    $blockers[] = 'unexpected_provider_observed:'.$category;
                }
            }
        }

        $blockers = array_values(array_unique($blockers));
        $certified = $blockers === [];

        return $this->payload(
            status: $certified ? 'swarm_topologies_converged' : 'swarm_topology_convergence_blocked',
            certified: $certified,
            fixture: $fixture,
            tasks: $tasks,
            measurements: $measurements,
            blockers: $blockers,
            config: [
                'min_task_types' => $minTaskTypes,
                'min_distinct_topologies' => $minDistinctTopologies,
                'min_convergence_rate' => $minConvergenceRate,
                'forced_provider' => $forcedProvider,
                'forced_model' => $forcedModel,
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $options
     * @return list<array<string,mixed>>|null
     */
    private function taskSpecs(string $fixture, array $options): ?array
    {
        if (isset($options['tasks']) && is_array($options['tasks'])) {
            return $this->normalizeTasks($options['tasks']);
        }

        return match ($fixture) {
            'two-types', 'live' => [
                [
                    'task_category' => 'reasoning',
                    'role' => 'engineer',
                    'input' => 'Evaluate two implementation approaches and converge on one bounded recommendation.',
                ],
                [
                    'task_category' => 'code_generation',
                    'role' => 'engineer',
                    'input' => 'Produce two implementation candidates, then converge through an audit node.',
                ],
            ],
            'single-type' => [
                [
                    'task_category' => 'reasoning',
                    'role' => 'engineer',
                    'input' => 'Evaluate a single reasoning topology.',
                ],
                [
                    'task_category' => 'reasoning',
                    'role' => 'critic',
                    'input' => 'Evaluate the same reasoning topology from a second role.',
                ],
            ],
            default => null,
        };
    }

    /**
     * @param  array<int,mixed>  $tasks
     * @return list<array<string,mixed>>
     */
    private function normalizeTasks(array $tasks): array
    {
        $normalized = [];
        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            $category = trim((string) ($task['task_category'] ?? ''));
            if ($category === '') {
                continue;
            }
            $normalized[] = [
                'task_category' => $category,
                'role' => trim((string) ($task['role'] ?? 'engineer')) ?: 'engineer',
                'input' => trim((string) ($task['input'] ?? $category)) ?: $category,
            ];
        }

        return $normalized;
    }

    private function selectTopology(string $taskCategory): string
    {
        $category = strtolower($taskCategory);

        if (str_contains($category, 'code') || str_contains($category, 'generation')) {
            return 'tournament';
        }
        if (str_contains($category, 'audit') || str_contains($category, 'verify') || str_contains($category, 'review')) {
            return 'panel';
        }
        if (str_contains($category, 'retrieval') || str_contains($category, 'context')) {
            return 'pipeline';
        }

        return 'debate';
    }

    /**
     * @return array{nodes:list<array<string,mixed>>}
     */
    private function composePlan(string $topology, string $rootCategory): array
    {
        return match ($topology) {
            'tournament' => [
                'nodes' => [
                    ['node_id' => 'candidate_a', 'task_category' => $rootCategory, 'role' => 'engineer'],
                    ['node_id' => 'candidate_b', 'task_category' => $rootCategory, 'role' => 'engineer'],
                    ['node_id' => 'verdict', 'task_category' => 'audit', 'role' => 'verifier', 'depends_on' => ['candidate_a', 'candidate_b']],
                ],
            ],
            'panel' => [
                'nodes' => [
                    ['node_id' => 'static_audit', 'task_category' => $rootCategory, 'role' => 'auditor'],
                    ['node_id' => 'semantic_audit', 'task_category' => $rootCategory, 'role' => 'reviewer'],
                    ['node_id' => 'verdict', 'task_category' => $rootCategory, 'role' => 'verifier', 'depends_on' => ['static_audit', 'semantic_audit']],
                ],
            ],
            'pipeline' => [
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
     * @param  array<string,mixed>  $task
     * @param  array{nodes:list<array<string,mixed>>}  $plan
     * @return array<string,mixed>
     */
    private function runPlan(array $task, string $topology, array $plan, string $forcedProvider, string $forcedModel): array
    {
        $category = (string) $task['task_category'];

        return $this->conductor->run([
            'task_category' => $category,
            'role' => (string) ($task['role'] ?? 'engineer'),
            'framework' => null,
            'parallelism' => 1,
            'requested_autonomy' => 'suggest',
            'privacy_class' => 'normal',
            'scope' => [
                'privacy_class' => 'normal',
                'surface' => 'l6-10_swarm_topology_auto_composer',
                'topology' => $topology,
            ],
            'input' => (string) ($task['input'] ?? $category),
            'forced_provider' => $forcedProvider,
            'forced_model' => $forcedModel,
        ], [
            'mode' => AtlasEngineeringRunConductorService::MODE_SHADOW,
            'operator_approved' => false,
            'verify' => false,
            'changed_files' => [],
            'spec' => [],
            'plan' => $plan,
            'evidence_refs' => ['L6-10'],
            'rich_context' => false,
            'compound' => false,
            'deliver_code' => false,
            'target_file' => 'AtlasGeneratedSnippet.php',
            'verify_run' => false,
            'multi_file' => false,
        ]);
    }

    /**
     * @param  array<string,mixed>  $task
     * @param  array{nodes:list<array<string,mixed>>}  $plan
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    private function measure(array $task, string $topology, array $plan, array $envelope, float $minConvergenceRate): array
    {
        $nodes = is_array(data_get($envelope, 'plan_trace.nodes')) ? array_values((array) data_get($envelope, 'plan_trace.nodes')) : [];
        $nodeCount = (int) data_get($envelope, 'plan_trace.node_count', count($nodes));
        $blockedReason = data_get($envelope, 'plan_trace.blocked_reason');

        $statusCounts = [];
        $successes = 0;
        $providers = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $status = (string) ($node['status'] ?? 'unknown');
            $statusCounts[$status] = (int) ($statusCounts[$status] ?? 0) + 1;
            if (($node['winner_result'] ?? null) === 'success') {
                $successes++;
            }
            $provider = trim((string) ($node['winner_provider'] ?? ''));
            if ($provider !== '') {
                $providers[] = $provider;
            }
        }

        $convergenceRate = $nodeCount > 0 ? round($successes / $nodeCount, 4) : 0.0;
        $measured = $nodeCount > 0 && $blockedReason === null;
        $converged = $measured
            && (string) ($envelope['status'] ?? '') === AtlasEngineeringRunConductorService::STATUS_EXECUTED
            && $convergenceRate >= $minConvergenceRate
            && (int) ($statusCounts[AtlasEngineeringRunConductorService::STATUS_NO_DISPATCH] ?? 0) === 0;

        return [
            'task_category' => (string) ($task['task_category'] ?? ''),
            'topology' => $topology,
            'plan_node_count' => count($plan['nodes']),
            'trace_node_count' => $nodeCount,
            'blocked_reason' => $blockedReason,
            'status_counts' => $statusCounts,
            'winner_providers' => array_values(array_unique($providers)),
            'success_count' => $successes,
            'convergence_rate' => $convergenceRate,
            'min_convergence_rate' => $minConvergenceRate,
            'measured' => $measured,
            'converged' => $converged,
            'run_hash' => (string) ($envelope['run_hash'] ?? ''),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $tasks
     * @param  list<array<string,mixed>>  $measurements
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    private function payload(string $status, bool $certified, string $fixture, array $tasks, array $measurements, array $blockers, array $config): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'status' => $status,
            'certified' => $certified,
            'completion_claim_allowed' => $certified,
            'fixture' => $fixture,
            'tasks' => $tasks,
            'summary' => [
                'task_type_count' => count(array_unique(array_map(static fn (array $task): string => (string) ($task['task_category'] ?? ''), $tasks))),
                'topology_count' => count(array_unique(array_map(static fn (array $measurement): string => (string) ($measurement['topology'] ?? ''), $measurements))),
                'measured_count' => count(array_filter($measurements, static fn (array $measurement): bool => (bool) ($measurement['measured'] ?? false))),
                'converged_count' => count(array_filter($measurements, static fn (array $measurement): bool => (bool) ($measurement['converged'] ?? false))),
            ],
            'measurements' => $measurements,
            'blockers' => $blockers,
            'config' => $config,
            'claim_policy' => [
                'shadow_only' => true,
                'provider_tokens_spent' => false,
                'runtime_routing_effect' => 'none',
                'topology_activation_effect' => 'none',
                'aggregate_winner_claim_allowed' => false,
                'rivals_claim_allowed' => false,
                'benchmark_claim_allowed' => false,
                'superiority_claim_allowed' => false,
            ],
        ];
        $payload['receipt_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::SCHEMA_VERSION,
            'status' => $status,
            'fixture' => $fixture,
            'tasks' => array_map(static fn (array $task): string => (string) ($task['task_category'] ?? ''), $tasks),
            'topologies' => array_map(static fn (array $measurement): string => (string) ($measurement['topology'] ?? ''), $measurements),
            'blockers' => $blockers,
        ], JSON_THROW_ON_ERROR));

        return $payload;
    }
}
