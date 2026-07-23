<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ControlPlane;

/**
 * Read-only status owner for the persistent Agent Control Plane.
 *
 * Every projection reads the durable queue registry directly. Lease expiry is
 * itself a mutation, so this owner reports that boundary as intentionally
 * uninspected instead of manufacturing a status request that changes it.
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

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'operation' => $operation,
            'status' => 'projected_read_only',
            'queue_tags' => $queueTags,
            'queue_registry' => $queue->registry(),
            'matching_task_packet_ids' => array_values(array_map(
                static fn (array $record): string => (string) ($record['task_packet_id'] ?? ''),
                $records,
            )),
            'matching_task_count' => count($records),
            'lease_registry_status' => 'not_read_to_preserve_projection_purity',
            'active_lease_count' => null,
            'active_lease_ids' => [],
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
            foreach ($this->queue->list($filters) as $record) {
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
}
