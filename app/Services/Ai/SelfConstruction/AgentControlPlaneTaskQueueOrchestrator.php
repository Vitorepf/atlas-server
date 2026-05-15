<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Orchestrates the first persistent runtime layer of the Agent Control
 * Plane: Task Packet Builder → Scope Lock Runtime Validator → Task Packet
 * Queue Repository → Claim/Lease Repository → Evidence Ledger Dry-Run →
 * Continuation Summary Builder. Every step still writes only to local
 * storage; no provider call, no dispatch, no real completion.
 *
 * Runtime-safe: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming and never writes the evidence ledger.
 */
final class AgentControlPlaneTaskQueueOrchestrator
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_task_queue_orchestrator.v1';

    public const MODE = 'persistent_local_agent_control_plane_task_queue_orchestrator';

    public function __construct(
        private readonly AgentControlPlaneTaskPacketBuilder $builder,
        private readonly AgentControlPlaneScopeLockRuntimeValidator $validator,
        private readonly AgentControlPlaneTaskPacketQueueRepository $queue,
        private readonly AgentControlPlaneClaimLeaseRepository $leases,
        private readonly AgentControlPlaneEvidenceLedgerDryRun $evidence,
        private readonly AgentControlPlaneContinuationSummaryBuilder $continuation,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function prepareAndEnqueue(array $input): array
    {
        $packetInput = (array) ($input['task_packet'] ?? $input);
        $queueOptions = (array) ($input['queue'] ?? []);
        $validatorOptions = (array) ($input['validator'] ?? []);

        $packet = $this->builder->build($packetInput);
        $validation = $this->validator->validate($packet, $validatorOptions);

        if ((string) ($packet['status'] ?? '') !== 'planned' || (string) ($validation['status'] ?? '') !== 'valid') {
            return $this->envelope('prepare_blocked', [
                'task_packet' => $packet,
                'validation' => $validation,
                'queue_entry' => null,
                'evidence_plan' => null,
                'continuation_summary' => null,
                'reason' => 'task_packet_or_validation_blocked',
            ]);
        }

        $enqueueResult = $this->queue->enqueue($packet, [
            'metadata' => [
                'scope_lock_hash' => (string) $validation['scope_lock_hash'],
                'validation_hash' => (string) $validation['validation_hash'],
            ],
            'priority' => (int) ($queueOptions['priority'] ?? 5),
            'tags' => (array) ($queueOptions['tags'] ?? []),
        ]);

        $evidencePlan = $this->evidence->plan($packet, [
            'write_set' => (array) $validation['normalized_scope_lock']['write_set'],
            'read_set' => (array) $validation['normalized_scope_lock']['read_set'],
            'scope_lock_plan_hash' => (string) $validation['scope_lock_hash'],
            'blocking_reasons' => [],
        ]);
        $continuation = $this->continuation->build($packet, $evidencePlan);

        // Attach planning receipts to the queue record.
        $taskPacketId = (string) data_get($enqueueResult, 'task_packet_id', '');
        if ($taskPacketId !== '' && (string) $enqueueResult['status'] === 'ok') {
            $this->queue->appendReceipt($taskPacketId, [
                'receipt_kind' => 'scope_lock_runtime_validated',
                'scope_lock_hash' => (string) $validation['scope_lock_hash'],
                'validation_hash' => (string) $validation['validation_hash'],
            ]);
            $this->queue->appendReceipt($taskPacketId, [
                'receipt_kind' => 'evidence_plan_prepared',
                'evidence_hash' => (string) $evidencePlan['evidence_hash'],
                'evidence_plan_hash' => (string) $evidencePlan['evidence_plan_hash'],
            ]);
            $this->queue->appendReceipt($taskPacketId, [
                'receipt_kind' => 'continuation_summary_prepared',
                'continuation_hash' => (string) $continuation['continuation_hash'],
            ]);
        }

        return $this->envelope('prepared_and_enqueued', [
            'task_packet' => $packet,
            'validation' => $validation,
            'queue_entry' => $enqueueResult,
            'evidence_plan' => $evidencePlan,
            'continuation_summary' => $continuation,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function claimNext(string $agentId, array $filters = []): array
    {
        if ($agentId === '') {
            return $this->envelope('claim_blocked', ['reason' => 'agent_id_missing']);
        }

        $candidates = $this->queue->list(array_merge(['status' => 'claimable'], $filters));
        foreach ($candidates as $candidate) {
            $taskPacketId = (string) $candidate['task_packet_id'];
            $scopeLock = [
                'write_set' => (array) data_get($candidate, 'task_packet.normalized_scope.allowed_files', []),
                'read_set' => (array) data_get($candidate, 'task_packet.normalized_scope.scope_in', []),
                'scope_lock_plan_hash' => (string) data_get($candidate, 'metadata.scope_lock_hash', ''),
            ];
            $claim = $this->leases->claim($taskPacketId, $agentId, $scopeLock, [
                'ttl_seconds' => (int) ($filters['ttl_seconds'] ?? 1800),
            ]);
            if ((string) $claim['status'] === 'ok') {
                $this->queue->updateStatus($taskPacketId, 'claimed', [
                    'lease_id' => (string) $claim['lease_id'],
                    'agent_id' => $agentId,
                ]);
                $this->queue->appendReceipt($taskPacketId, [
                    'receipt_kind' => 'claim_acquired_by_orchestrator',
                    'lease_id' => (string) $claim['lease_id'],
                    'agent_id' => $agentId,
                ]);

                return $this->envelope('claimed', [
                    'queue_entry' => $candidate,
                    'lease' => $claim['lease'] ?? null,
                    'lease_id' => (string) $claim['lease_id'],
                    'task_packet_id' => $taskPacketId,
                    'agent_id' => $agentId,
                ]);
            }
            // Conflict: try next candidate.
        }

        return $this->envelope('no_claimable_task', [
            'agent_id' => $agentId,
            'candidate_count' => count($candidates),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function renewLease(string $leaseId, string $agentId, int $ttlSeconds): array
    {
        $renewal = $this->leases->renew($leaseId, $agentId, $ttlSeconds);

        return $this->envelope('lease_renewal', ['renewal' => $renewal]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function releaseLease(string $leaseId, string $agentId, array $options = []): array
    {
        $release = $this->leases->release($leaseId, $agentId, $options);
        $taskPacketId = (string) data_get($release, 'task_packet_id', '');
        if ($taskPacketId !== '' && (string) $release['status'] === 'ok') {
            $this->queue->updateStatus($taskPacketId, 'released', [
                'lease_id' => $leaseId,
                'release_reason' => (string) ($options['reason'] ?? 'released_by_owner'),
            ]);
            $this->queue->appendReceipt($taskPacketId, [
                'receipt_kind' => 'lease_released_by_orchestrator',
                'lease_id' => $leaseId,
                'agent_id' => $agentId,
            ]);
        }

        return $this->envelope('lease_release', ['release' => $release]);
    }

    /**
     * Finalises the dry-run cycle: the lease is released and the queue
     * record moves to `completed_dry_run`. Real completion remains forbidden.
     *
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    public function completeDryRun(string $taskPacketId, string $leaseId, array $evidence = []): array
    {
        $lease = $this->leases->get($leaseId);
        if ($lease === null) {
            return $this->envelope('complete_dry_run_blocked', ['reason' => 'lease_not_found']);
        }
        if ((string) $lease['task_packet_id'] !== $taskPacketId) {
            return $this->envelope('complete_dry_run_blocked', [
                'reason' => 'task_packet_lease_mismatch',
                'lease_task_packet_id' => (string) $lease['task_packet_id'],
                'requested_task_packet_id' => $taskPacketId,
            ]);
        }
        if ((string) $lease['lease_status'] !== AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE) {
            return $this->envelope('complete_dry_run_blocked', [
                'reason' => 'lease_not_active',
                'lease_status' => (string) $lease['lease_status'],
            ]);
        }

        $agentId = (string) $lease['agent_id'];
        $release = $this->leases->release($leaseId, $agentId, ['reason' => 'completed_dry_run']);

        $this->queue->appendReceipt($taskPacketId, [
            'receipt_kind' => 'dry_run_completion_recorded',
            'lease_id' => $leaseId,
            'agent_id' => $agentId,
            'evidence_keys' => array_keys($evidence),
            'evidence_digest' => hash('sha256', (string) json_encode($evidence, JSON_THROW_ON_ERROR)),
        ]);
        $update = $this->queue->updateStatus($taskPacketId, 'completed_dry_run', [
            'lease_id' => $leaseId,
            'agent_id' => $agentId,
        ]);

        return $this->envelope('completed_dry_run', [
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'release' => $release,
            'queue_update' => $update,
            'completion_real_allowed' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function envelope(string $event, array $payload): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'event' => $event,
            'orchestration_id' => (string) Str::uuid(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_real_allowed' => false,
            'non_execution_guarantees' => [
                'task_queue_orchestrator_does_not_start_codex',
                'task_queue_orchestrator_does_not_call_codex_cli_or_app',
                'task_queue_orchestrator_does_not_spawn_subprocess',
                'task_queue_orchestrator_does_not_invoke_adapter',
                'task_queue_orchestrator_does_not_call_provider',
                'task_queue_orchestrator_does_not_dispatch_work',
                'task_queue_orchestrator_does_not_spend_tokens',
                'task_queue_orchestrator_does_not_enable_self_programming',
                'task_queue_orchestrator_does_not_write_ledger',
                'task_queue_orchestrator_does_not_mutate_pointer',
                'task_queue_orchestrator_does_not_mark_real_completion',
            ],
        ], $payload);
    }
}
