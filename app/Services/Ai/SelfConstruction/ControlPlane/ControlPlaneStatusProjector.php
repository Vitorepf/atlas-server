<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ControlPlane;

/**
 * Read-only status owner for the persistent Agent Control Plane.
 *
 * Every projection reads durable queue and lease registry snapshots directly.
 * Lease expiry is itself a mutation, so this owner never asks the repository
 * to refresh expiry before reporting the stored state.
 */
final class ControlPlaneStatusProjector
{
    public const SCHEMA_VERSION = 'atlas.self_construction.control_plane_status_projector.v1';

    public function __construct(
        private readonly ?AgentControlPlaneTaskPacketQueueRepository $queue = null,
        private readonly ?AgentControlPlaneClaimLeaseRepository $leases = null,
    ) {}

    /** @param array<string, mixed> $context */
    public function projectTaskLeaseRecovery(array $context = []): array
    {
        return $this->project('task_lease_recovery', $context, ['lease_expired', 'released']);
    }

    /** @param array<string, mixed> $context */
    public function projectTaskQueueOrchestrator(array $context = []): array
    {
        return $this->project('task_queue_orchestrator', $context);
    }

    /** @param array<string, mixed> $context */
    public function projectTaskQueueClaimNext(array $context = []): array
    {
        return $this->project('task_queue_claim_next', $context, ['claimable']);
    }

    /** @param array<string, mixed> $context */
    public function projectTaskAutoReplenishment(array $context = []): array
    {
        return $this->project('task_auto_replenishment', $context, ['claimable', 'queued']);
    }

    /** @param array<string, mixed> $context */
    public function projectTerminalWorkerBootstrap(array $context = []): array
    {
        return $this->project('terminal_worker_bootstrap', $context, ['claimable', 'claimed']);
    }

    /** @param array<string, mixed> $context */
    public function projectOperatorEvidenceDraftWorkspacePublisher(array $context = []): array
    {
        return $this->project('operator_evidence_draft_workspace_publisher', $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $statuses
     * @return array<string, mixed>
     */
    private function project(string $operation, array $context, array $statuses = []): array
    {
        $queue = $this->queue();
        $queueTags = $this->stringList((array) ($context['queue_tags'] ?? []));
        $records = $this->records($queueTags, $statuses);
        $leaseRegistry = $this->leases()->registry();
        $activeLeaseIds = array_values(array_filter(array_map(
            static fn (array $entry): string => (string) ($entry['lease_status'] ?? '')
                === AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE
                ? (string) ($entry['lease_id'] ?? '')
                : '',
            (array) ($leaseRegistry['entries'] ?? []),
        )));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'operation' => $operation,
            'status' => 'projected_read_only',
            'queue_tags' => $queueTags,
            'queue_registry' => $queue->registry(readOnly: true),
            'matching_task_packet_ids' => array_values(array_map(
                static fn (array $record): string => (string) ($record['task_packet_id'] ?? ''),
                $records,
            )),
            'matching_task_count' => count($records),
            'lease_registry_status' => (bool) ($leaseRegistry['corrupt'] ?? false)
                ? 'corrupt_read_only_snapshot'
                : 'read_only_snapshot',
            'lease_registry' => $leaseRegistry,
            'active_lease_count' => count($activeLeaseIds),
            'active_lease_ids' => $activeLeaseIds,
            'runtime_write_performed' => false,
            'runtime_execution_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'ledger_write_allowed' => false,
            'self_programming_allowed' => false,
        ];
    }

    /**
     * @param  list<string>  $queueTags
     * @param  list<string>  $statuses
     * @return list<array<string, mixed>>
     */
    private function records(array $queueTags, array $statuses): array
    {
        $records = [];
        foreach ($statuses === [] ? [''] : $statuses as $status) {
            $filters = $queueTags === [] ? [] : ['tags' => $queueTags];
            if ($status !== '') {
                $filters['status'] = $status;
            }
            foreach ($this->queue()->list($filters, readOnly: true) as $record) {
                $records[(string) ($record['task_packet_id'] ?? '')] = $record;
            }
        }

        return array_values($records);
    }

    /** @param array<int, mixed> $values @return list<string> */
    private function stringList(array $values): array
    {
        $normalized = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));

        return array_values(array_unique($normalized));
    }

    private function queue(): AgentControlPlaneTaskPacketQueueRepository
    {
        return $this->queue ?? new AgentControlPlaneTaskPacketQueueRepository;
    }

    private function leases(): AgentControlPlaneClaimLeaseRepository
    {
        return $this->leases ?? new AgentControlPlaneClaimLeaseRepository;
    }
}
