<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Replenishes the persistent Agent Control Plane task queue from governed
 * sources. It creates only local task packets; workers still need an explicit
 * claim/lease before doing any work.
 *
 * Runtime-safe: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming and never writes the evidence ledger.
 */
final class AgentControlPlaneTaskAutoReplenishmentService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_task_auto_replenishment.v1';

    public const MODE = 'persistent_local_agent_control_plane_task_auto_replenishment';

    public function __construct(
        private readonly AgentControlPlaneTaskQueueOrchestrator $orchestrator,
        private readonly AgentControlPlaneTaskPacketQueueRepository $queue,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function replenish(array $context = [], array $options = []): array
    {
        $targetMinClaimable = max(1, min(25, (int) ($options['target_min_claimable_tasks'] ?? 3)));
        $maxNewTasks = max(0, min(25, (int) ($options['max_new_tasks'] ?? $targetMinClaimable)));
        $actor = trim((string) ($options['actor'] ?? 'agent-control-plane-auto-replenishment'));
        $reason = trim((string) ($options['reason'] ?? 'claimable_queue_below_target'));

        $registryBefore = $this->queue->registry();
        $claimableBefore = (int) data_get($registryBefore, 'status_counts.claimable', 0);
        $totalBefore = (int) data_get($registryBefore, 'total_count', 0);

        $sources = $this->sources($context);
        $plan = $this->plan($sources, $targetMinClaimable, $maxNewTasks, $claimableBefore, $totalBefore);
        $generated = [];
        $skipped = [];

        foreach ($plan as $seed) {
            $taskPacket = $this->taskPacketFromSeed($seed, $actor);
            $result = $this->orchestrator->prepareAndEnqueue([
                'task_packet' => $taskPacket,
                'queue' => [
                    'priority' => (int) ($seed['priority'] ?? 5),
                    'tags' => array_values(array_unique(array_merge([
                        'atlas_self_construction_os',
                        'agent_control_plane',
                        'auto_replenished',
                    ], (array) ($seed['tags'] ?? [])))),
                    'metadata' => [
                        'auto_replenishment_reason' => $reason,
                        'auto_replenishment_source' => (string) ($seed['source'] ?? 'unknown'),
                    ],
                ],
            ]);

            $queueEvent = (string) data_get($result, 'queue_entry.event', '');
            $entry = [
                'seed_key' => (string) ($seed['seed_key'] ?? ''),
                'task_packet_id' => (string) data_get($result, 'task_packet.task_packet_id', $taskPacket['task_packet_id']),
                'task_packet_hash' => (string) data_get($result, 'task_packet.task_packet_hash', ''),
                'event' => (string) ($result['event'] ?? 'unknown'),
                'queue_event' => $queueEvent,
                'source' => (string) ($seed['source'] ?? 'unknown'),
                'reference' => (string) ($seed['reference'] ?? ''),
            ];

            if ((string) ($result['event'] ?? '') === 'prepared_and_enqueued' && $queueEvent !== 'idempotent_enqueue') {
                $generated[] = $entry;
            } else {
                $skipped[] = $entry;
            }
        }

        $registryAfter = $this->queue->registry();
        $claimableAfter = (int) data_get($registryAfter, 'status_counts.claimable', 0);
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'event' => 'auto_replenishment_completed',
            'status' => $claimableAfter >= $targetMinClaimable ? 'available' : ($generated !== [] ? 'available' : 'blocked'),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'actor' => $actor,
            'reason' => $reason,
            'target_min_claimable_tasks' => $targetMinClaimable,
            'max_new_tasks' => $maxNewTasks,
            'claimable_task_count_before' => $claimableBefore,
            'claimable_task_count_after' => $claimableAfter,
            'generated_task_count' => count($generated),
            'skipped_existing_task_count' => count($skipped),
            'sources' => $sources,
            'generated_tasks' => $generated,
            'skipped_tasks' => $skipped,
            'blockers' => $this->blockers($claimableAfter, $targetMinClaimable, $maxNewTasks),
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_real_allowed' => false,
            'non_execution_guarantees' => [
                'task_auto_replenishment_does_not_start_codex',
                'task_auto_replenishment_does_not_call_codex_cli_or_app',
                'task_auto_replenishment_does_not_spawn_subprocess',
                'task_auto_replenishment_does_not_invoke_adapter',
                'task_auto_replenishment_does_not_call_provider',
                'task_auto_replenishment_does_not_dispatch_work',
                'task_auto_replenishment_does_not_spend_tokens',
                'task_auto_replenishment_does_not_enable_self_programming',
                'task_auto_replenishment_does_not_write_ledger',
                'task_auto_replenishment_does_not_mutate_pointer',
                'task_auto_replenishment_does_not_mark_real_completion',
            ],
        ];
        $payload['replenishment_plan_hash'] = $this->stableHash([
            'sources' => $sources,
            'plan' => $plan,
            'target_min_claimable_tasks' => $targetMinClaimable,
            'max_new_tasks' => $maxNewTasks,
        ]);
        $payload['auto_replenishment_hash'] = $this->stableHash($this->normalizeForHash($payload));

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private function sources(array $context): array
    {
        $controlPlane = (array) ($context['control_plane'] ?? []);
        $completionAudit = (array) ($context['completion_audit'] ?? []);
        $chainIntegrity = (array) ($context['chain_integrity'] ?? []);
        $nextRequiredSlice = (string) data_get($controlPlane, 'control_plane.persistent_runtime.next_required_slice', data_get($context, 'next_required_slice', ''));
        $notYetRuntimeCapable = (array) data_get($controlPlane, 'control_plane.not_yet_runtime_capable', []);
        $failedCriteria = array_values(array_filter(array_map('strval', (array) data_get($completionAudit, 'failed_criteria', []))));
        $chainViolations = array_values((array) data_get($chainIntegrity, 'violations', []));

        return [
            [
                'source' => 'current_pointer',
                'value' => $nextRequiredSlice,
                'available' => $nextRequiredSlice !== '',
            ],
            [
                'source' => 'not_yet_runtime_capable',
                'value' => array_values($notYetRuntimeCapable),
                'available' => $notYetRuntimeCapable !== [],
            ],
            [
                'source' => 'completion_audit_failed_criteria',
                'value' => $failedCriteria,
                'available' => $failedCriteria !== [],
            ],
            [
                'source' => 'chain_integrity_violations',
                'value' => $chainViolations,
                'available' => $chainViolations !== [],
            ],
            [
                'source' => 'canonical_contract',
                'value' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
                'available' => true,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    private function plan(array $sources, int $targetMinClaimable, int $maxNewTasks, int $claimableBefore, int $totalBefore): array
    {
        if ($claimableBefore >= $targetMinClaimable || $maxNewTasks === 0) {
            return [];
        }

        $needed = min($maxNewTasks, $targetMinClaimable - $claimableBefore);
        $sourceMap = [];
        foreach ($sources as $source) {
            $sourceMap[(string) $source['source']] = $source;
        }

        $seeds = [];
        $next = (string) data_get($sourceMap, 'current_pointer.value', '');
        if ($next !== '') {
            $seeds[] = $this->seed('current_pointer_'.$this->slug($next), 'Implementar o próximo slice canônico do Agent Control Plane: '.$next, [
                'source' => 'current_pointer',
                'reference' => $next,
                'priority' => 1,
                'tags' => ['next_required_slice'],
            ]);
        }

        foreach (array_slice((array) data_get($sourceMap, 'completion_audit_failed_criteria.value', []), 0, 2) as $criterion) {
            $seeds[] = $this->seed('completion_audit_'.$this->slug((string) $criterion), 'Fechar critério falho do completion audit: '.$criterion, [
                'source' => 'completion_audit',
                'reference' => (string) $criterion,
                'priority' => 2,
                'tags' => ['completion_audit'],
            ]);
        }

        if ((array) data_get($sourceMap, 'chain_integrity_violations.value', []) !== []) {
            $seeds[] = $this->seed('chain_integrity_first_violation', 'Corrigir primeira violação da Chain Integrity Certification.', [
                'source' => 'chain_integrity',
                'reference' => 'first_violation',
                'priority' => 2,
                'tags' => ['chain_integrity'],
            ]);
        }

        $seeds[] = $this->seed('contract_docs_guardrail', 'Atualizar documentação e guardrails do Agent Control Plane quando contrato/comando/comportamento mudar.', [
            'source' => 'canonical_contract',
            'reference' => 'agent-control-plane-contract.md',
            'priority' => 4,
            'tags' => ['docs', 'guardrail'],
        ]);
        $seeds[] = $this->seed('focused_regression_tests', 'Adicionar ou reforçar testes focados para o slice em andamento do loop multiagente.', [
            'source' => 'test_guardrail',
            'reference' => 'focused_tests',
            'priority' => 4,
            'tags' => ['tests', 'guardrail'],
        ]);

        return array_slice(array_map(function (array $seed, int $index) use ($totalBefore): array {
            $sequence = str_pad((string) ($totalBefore + $index + 1), 4, '0', STR_PAD_LEFT);
            $seed['task_packet_id'] = 'acp-auto-'.$sequence.'-'.$this->slug((string) $seed['seed_key']);

            return $seed;
        }, $seeds, array_keys($seeds)), 0, $needed);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function seed(string $key, string $objective, array $extra = []): array
    {
        return array_merge([
            'seed_key' => $key,
            'objective' => $objective,
            'source' => 'unknown',
            'reference' => '',
            'priority' => 5,
            'tags' => [],
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $seed
     * @return array<string, mixed>
     */
    private function taskPacketFromSeed(array $seed, string $actor): array
    {
        return [
            'task_packet_id' => (string) $seed['task_packet_id'],
            'objective' => (string) $seed['objective'],
            'source' => 'agent_control_plane_auto_replenishment:'.(string) $seed['source'],
            'operator_id' => $actor,
            'parent_run_id' => 'AGENT-CONTROL-PLANE-AUTO-REPLENISHMENT-0001',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'forbidden_files' => [
                'routes/api.php',
                'app/Services/Ai/SelfImprovement/',
                'app/Services/Ai/Programming/',
                'atlas-desktop/',
            ],
            'scope_in' => [
                'app/Services/Ai/SelfConstruction/',
                'tests/Feature/Ai/',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'scope_out' => [
                'app/Services/Ai/SelfImprovement/',
                'app/Services/Ai/Programming/',
                'routes/api.php',
                'atlas-desktop/',
            ],
            'acceptance_criteria' => [
                'implementation_matches_canonical_contract',
                'scope_is_limited_to_agent_control_plane',
                'focused_tests_pass',
                'docs_updated_if_contract_or_cli_changes',
                'runtime_flags_remain_false',
                'git_status_preserves_unrelated_changes',
            ],
            'required_evidence' => [
                'task_packet_created',
                'lease_claim_required',
                'focused_tests_output',
                'docs_health_output',
                'architecture_validate_output',
                'git_diff_check_output',
                'continuation_summary',
            ],
            'risk_level' => 'low',
            'max_runtime_seconds' => 10800,
            'max_token_budget' => 0,
            'workspace_policy' => [
                'isolation' => 'shared_worktree_with_explicit_scope_lock',
                'auto_apply' => false,
            ],
            'continuation_context' => [
                'auto_replenishment_seed_key' => (string) $seed['seed_key'],
                'auto_replenishment_source' => (string) $seed['source'],
                'auto_replenishment_reference' => (string) $seed['reference'],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function blockers(int $claimableAfter, int $targetMinClaimable, int $maxNewTasks): array
    {
        if ($claimableAfter >= $targetMinClaimable) {
            return [];
        }
        if ($maxNewTasks === 0) {
            return ['max_new_tasks_zero'];
        }

        return ['claimable_queue_below_target_after_replenishment'];
    }

    private function slug(string $value): string
    {
        $slug = Str::slug($value, '_');

        return $slug === '' ? 'task' : substr($slug, 0, 80);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForHash(array $payload): array
    {
        $clone = $payload;
        unset($clone['generated_at'], $clone['auto_replenishment_hash']);

        return $this->recursivelyKsort($clone);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    private function recursivelyKsort(array $value): array
    {
        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->recursivelyKsort($entry);
            }
        }
        if ($isAssoc) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  array<mixed, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        $payload = $this->recursivelyKsort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
