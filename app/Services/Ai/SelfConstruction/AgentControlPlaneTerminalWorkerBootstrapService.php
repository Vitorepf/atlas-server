<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

/**
 * One-command bootstrap for a terminal worker.
 *
 * This stitches the already-governed runtime pieces together:
 * auto-replenish the task queue, claim exactly one packet with a lease, then
 * build the one-shot worker packet for that lease. It still never runs the
 * worker, starts a process, invokes a provider, dispatches work or marks real
 * completion.
 */
final class AgentControlPlaneTerminalWorkerBootstrapService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_terminal_worker_bootstrap.v1';

    public const MODE = 'persistent_local_agent_control_plane_terminal_worker_bootstrap';

    public function __construct(
        private readonly AgentControlPlaneTaskAutoReplenishmentService $replenishment,
        private readonly AgentControlPlaneTaskQueueOrchestrator $orchestrator,
        private readonly AgentControlPlaneOneShotWorkerPacketService $workerPacket,
        private readonly AgentControlPlaneTaskPacketQueueRepository $queue,
        private readonly AgentControlPlaneClaimLeaseRepository $leases,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function bootstrap(array $context = [], array $options = []): array
    {
        $actor = trim((string) ($options['actor'] ?? 'codex'));
        $actor = $actor === '' ? 'codex' : $actor;
        $leaseMinutes = max(1, min(240, (int) ($options['lease_minutes'] ?? 30)));
        $targetMin = max(1, min(25, (int) ($options['target_min_claimable_tasks'] ?? 6)));
        $maxNew = max(0, min(25, (int) ($options['max_new_tasks'] ?? $targetMin)));
        $queueTags = $this->stringList((array) ($options['queue_tags'] ?? []));

        $replenishment = $this->replenishment->replenish($context, [
            'target_min_claimable_tasks' => $targetMin,
            'max_new_tasks' => $maxNew,
            'actor' => $actor,
            'reason' => (string) ($options['reason'] ?? 'terminal_worker_bootstrap'),
            'queue_tags' => $queueTags,
        ]);

        $claimFilters = [
            'ttl_seconds' => $leaseMinutes * 60,
        ];
        if ($queueTags !== []) {
            $claimFilters['tag'] = $queueTags[0];
        }
        $claim = $this->orchestrator->claimNext($actor, $claimFilters);
        $claimEvent = (string) ($claim['event'] ?? 'unknown');
        $workerPacket = [];
        if ($claimEvent === 'claimed') {
            $workerPacket = $this->workerPacket->generate([
                'task_packet_id' => (string) data_get($claim, 'task_packet_id', ''),
                'lease_id' => (string) data_get($claim, 'lease_id', ''),
                'actor' => $actor,
            ]);
        }

        $workerReady = (string) data_get($workerPacket, 'status', '') === 'ok';
        $status = $claimEvent === 'claimed' && $workerReady ? 'ready_for_worker' : 'blocked';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'actor' => $actor,
            'lease_minutes' => $leaseMinutes,
            'target_min_claimable_tasks' => $targetMin,
            'max_new_tasks' => $maxNew,
            'queue_tags' => $queueTags,
            'claim_tag' => (string) ($claimFilters['tag'] ?? ''),
            'auto_replenishment_status' => (string) data_get($replenishment, 'status', 'unknown'),
            'auto_replenishment_hash' => (string) data_get($replenishment, 'auto_replenishment_hash', ''),
            'generated_task_count' => (int) data_get($replenishment, 'generated_task_count', 0),
            'claim_event' => $claimEvent,
            'runtime_claim_persisted' => $claimEvent === 'claimed',
            'task_packet_id' => (string) data_get($claim, 'task_packet_id', ''),
            'lease_id' => (string) data_get($claim, 'lease_id', ''),
            'one_shot_worker_packet_ready' => $workerReady,
            'one_shot_packet_hash' => (string) data_get($workerPacket, 'one_shot_packet_hash', ''),
            'worker_prompt_goal_short' => (string) data_get($workerPacket, 'worker_prompt_goal_short', ''),
            'worker_prompt_full' => (string) data_get($workerPacket, 'worker_prompt_full', ''),
            'completion_command' => (string) data_get($workerPacket, 'completion_command', ''),
            'lease_renew_command' => (string) data_get($workerPacket, 'lease_renew_command', ''),
            'resumption_contract' => (array) data_get($workerPacket, 'resumption_contract', []),
            'resume_after_interruption_command' => (string) data_get($workerPacket, 'resumption_contract.resume_commands.inspect_or_recover_current_packet', ''),
            'next_worker_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-worker-bootstrap-status --actor=<agent-id> --json',
            'auto_replenishment' => $replenishment,
            'claim' => $claim,
            'one_shot_worker_packet' => $workerPacket,
            'queue_summary' => $this->queue->registry(),
            'lease_summary' => [
                'active_lease_count' => count($this->leases->activeLeases()),
                'runtime_flags' => $this->leases->runtimeFlags(),
            ],
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_real_allowed' => false,
            'non_execution_guarantees' => [
                'terminal_worker_bootstrap_does_not_start_codex',
                'terminal_worker_bootstrap_does_not_call_codex_cli_or_app',
                'terminal_worker_bootstrap_does_not_spawn_subprocess',
                'terminal_worker_bootstrap_does_not_invoke_adapter',
                'terminal_worker_bootstrap_does_not_call_provider',
                'terminal_worker_bootstrap_does_not_dispatch_work',
                'terminal_worker_bootstrap_does_not_spend_tokens',
                'terminal_worker_bootstrap_does_not_enable_self_programming',
                'terminal_worker_bootstrap_does_not_write_ledger',
                'terminal_worker_bootstrap_does_not_mark_real_completion',
            ],
            'bootstrap_hash' => '',
        ];
        $payload['bootstrap_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['bootstrap_hash']);
        ksort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }
}
