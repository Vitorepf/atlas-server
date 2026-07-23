<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService;
use Illuminate\Support\Facades\Storage;

/**
 * Mutation owner for the bounded Agent Control Plane operations.
 *
 * Runtime methods invoke their governed writer explicitly and report durable
 * artifact identifiers. Replays are discovered from a queue receipt rather
 * than an in-memory cache, so a truthful status survives a new PHP process.
 */
final class AgentControlPlaneRuntime
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_runtime.v1';

    private const RUNTIME_RECEIPT_KIND = 'agent_control_plane_runtime_execution';

    private const PUBLISHER_RUNTIME_RECEIPT_PREFIX = 'atlas/self-construction/agent-control-plane/runtime-receipts';

    public function __construct(
        private readonly ?AgentControlPlaneTerminalWorkerBootstrapService $bootstrap = null,
        private readonly ?AgentControlPlaneTaskPacketQueueRepository $queue = null,
        private readonly ?AgentControlPlaneClaimLeaseRepository $leases = null,
        private readonly ?AgentControlPlaneTaskQueueOrchestrator $orchestrator = null,
        private readonly ?AgentControlPlaneTaskAutoReplenishmentService $replenishment = null,
        private readonly ?AgentControlPlaneTaskLeaseRecoveryService $leaseRecovery = null,
        private readonly ?AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService $publisher = null,
    ) {}

    /** @param array<string, mixed> $context */
    public function runTaskLeaseRecovery(array $context = []): array
    {
        if (($replay = $this->runtimeReplay('task_lease_recovery', $context)) !== null) {
            return $replay;
        }

        $result = ($this->leaseRecovery ?? new AgentControlPlaneTaskLeaseRecoveryService($this->queue(), $this->leases()))
            ->recoverExpiredLeases($context);

        return $this->runtimeResult('task_lease_recovery', $result, $context);
    }

    /** @param array<string, mixed> $context */
    public function runTaskQueueOrchestrator(array $context = []): array
    {
        if (($replay = $this->runtimeReplay('task_queue_orchestrator', $context)) !== null) {
            return $replay;
        }

        return $this->runtimeResult('task_queue_orchestrator', $this->orchestrator()->prepareAndEnqueue($context), $context);
    }

    /** @param array<string, mixed> $context */
    public function runTaskQueueClaimNext(array $context = []): array
    {
        if (($replay = $this->runtimeReplay('task_queue_claim_next', $context)) !== null) {
            return $replay;
        }

        $actor = trim((string) ($context['actor'] ?? ''));
        if ($actor === '') {
            return $this->blocked('task_queue_claim_next', 'actor_required');
        }

        return $this->runtimeResult('task_queue_claim_next', $this->orchestrator()->claimNext($actor, $context), $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $options
     */
    public function runTaskAutoReplenishment(array $context = [], array $options = []): array
    {
        if (($replay = $this->runtimeReplay('task_auto_replenishment', $context, $options)) !== null) {
            return $replay;
        }

        return $this->runtimeResult('task_auto_replenishment', $this->replenishment()->replenish($context, $options), $context, $options);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $options
     */
    public function runTerminalWorkerBootstrap(array $context = [], array $options = []): array
    {
        $operation = 'terminal_worker_bootstrap';
        $idempotencyKey = $this->idempotencyKey($operation, $context, $options);
        $replay = $this->findReplay($operation, $idempotencyKey);
        if ($replay !== null) {
            return $this->envelope($operation, [
                'status' => 'replayed_persisted_runtime_artifacts',
                'runtime_write_performed' => false,
                'task_packet_id' => $replay['task_packet_id'],
                'lease_id' => $replay['lease_id'],
                'persisted_artifact_ids' => [
                    'task_packet_id' => $replay['task_packet_id'],
                    'lease_id' => $replay['lease_id'],
                ],
                'idempotency_key' => $idempotencyKey,
                'replay_options' => $options,
            ]);
        }

        $result = $this->bootstrap()->bootstrap($context, $options);
        $taskPacketId = (string) ($result['task_packet_id'] ?? '');
        $leaseId = (string) ($result['lease_id'] ?? '');
        $taskPacket = $taskPacketId === '' ? null : $this->queue()->get($taskPacketId);
        $lease = $leaseId === '' ? null : $this->leases()->get($leaseId);
        $persisted = $taskPacketId !== ''
            && $leaseId !== ''
            && is_array($taskPacket)
            && (string) ($taskPacket['status'] ?? '') === 'claimed'
            && is_array($lease)
            && (string) ($lease['task_packet_id'] ?? '') === $taskPacketId
            && (string) ($lease['lease_status'] ?? '') === AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE;

        if ($persisted) {
            $this->queue()->appendReceipt($taskPacketId, [
                'receipt_kind' => self::RUNTIME_RECEIPT_KIND,
                'operation' => $operation,
                'idempotency_key' => $idempotencyKey,
                'lease_id' => $leaseId,
            ]);
        }

        return $this->envelope($operation, [
            'status' => $persisted ? 'runtime_artifacts_persisted' : 'runtime_artifacts_not_persisted',
            'runtime_write_performed' => $persisted,
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'persisted_artifact_ids' => [
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
            ],
            'idempotency_key' => $idempotencyKey,
            'replay_options' => $options,
            'writer_result' => $result,
        ]);
    }

    /** @param array<string, mixed> $context */
    public function runOperatorEvidenceDraftWorkspacePublisher(array $context = []): array
    {
        if (($replay = $this->runtimeReplay('operator_evidence_draft_workspace_publisher', $context)) !== null) {
            return $replay;
        }

        $result = ($this->publisher ?? new AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService)->publish($context);

        return $this->runtimeResult('operator_evidence_draft_workspace_publisher', $result, $context);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function runtimeResult(string $operation, array $result, array $context, array $options = []): array
    {
        $persistedArtifactIds = $this->persistedArtifactIds($operation, $result);
        $runtimeWritePerformed = $this->artifactsArePersisted($operation, $persistedArtifactIds);
        $idempotencyKey = $this->idempotencyKey($operation, $context, $options);

        if ($runtimeWritePerformed) {
            $this->recordRuntimeReceipt($operation, $idempotencyKey, $persistedArtifactIds);
        }

        return $this->envelope($operation, [
            'status' => (string) ($result['status'] ?? 'unknown'),
            'runtime_write_performed' => $runtimeWritePerformed,
            'persisted_artifact_ids' => $persistedArtifactIds,
            'idempotency_key' => $idempotencyKey,
            'replay_options' => $options,
            'writer_result' => $result,
        ]);
    }

    private function blocked(string $operation, string $reason): array
    {
        return $this->envelope($operation, [
            'status' => 'blocked_runtime_writer_dependency_missing',
            'reason' => $reason,
            'runtime_write_performed' => false,
        ]);
    }

    /** @return array{task_packet_id: string, lease_id: string}|null */
    private function findReplay(string $operation, string $idempotencyKey): ?array
    {
        foreach ($this->queue()->list() as $record) {
            foreach ((array) ($record['receipts'] ?? []) as $receipt) {
                if (
                    (string) ($receipt['receipt_kind'] ?? '') !== self::RUNTIME_RECEIPT_KIND
                    || (string) ($receipt['operation'] ?? '') !== $operation
                    || (string) ($receipt['idempotency_key'] ?? '') !== $idempotencyKey
                ) {
                    continue;
                }

                $taskPacketId = (string) ($record['task_packet_id'] ?? '');
                $leaseId = (string) ($receipt['lease_id'] ?? '');
                $lease = $leaseId === '' ? null : $this->leases()->get($leaseId);
                if (
                    $taskPacketId !== ''
                    && (string) ($record['status'] ?? '') === 'claimed'
                    && is_array($lease)
                    && (string) ($lease['task_packet_id'] ?? '') === $taskPacketId
                    && (string) ($lease['lease_status'] ?? '') === AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE
                ) {
                    return [
                        'task_packet_id' => $taskPacketId,
                        'lease_id' => $leaseId,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|null
     */
    private function runtimeReplay(string $operation, array $context, array $options = []): ?array
    {
        $idempotencyKey = $this->idempotencyKey($operation, $context, $options);
        foreach ($this->queue()->list([], readOnly: true) as $record) {
            foreach ((array) ($record['receipts'] ?? []) as $receipt) {
                if (
                    (string) ($receipt['receipt_kind'] ?? '') !== self::RUNTIME_RECEIPT_KIND
                    || (string) ($receipt['operation'] ?? '') !== $operation
                    || (string) ($receipt['idempotency_key'] ?? '') !== $idempotencyKey
                ) {
                    continue;
                }

                $persistedArtifactIds = (array) ($receipt['persisted_artifact_ids'] ?? []);
                if (! $this->artifactsArePersisted($operation, $persistedArtifactIds)) {
                    continue;
                }

                return $this->envelope($operation, [
                    'status' => 'replayed_persisted_runtime_artifacts',
                    'runtime_write_performed' => false,
                    'persisted_artifact_ids' => $persistedArtifactIds,
                    'idempotency_key' => $idempotencyKey,
                    'replay_options' => $options,
                ]);
            }
        }

        if ($operation === 'operator_evidence_draft_workspace_publisher') {
            return $this->publisherRuntimeReplay($idempotencyKey, $options);
        }

        return null;
    }

    /** @return array<string, list<string>> */
    private function persistedArtifactIds(string $operation, array $result): array
    {
        $taskPacketIds = [];
        $leaseIds = [];
        $artifactPaths = [];

        if ($operation === 'task_lease_recovery') {
            foreach ((array) ($result['recovered'] ?? []) as $recovered) {
                $taskPacketIds[] = (string) ($recovered['task_packet_id'] ?? '');
                $leaseIds[] = (string) ($recovered['lease_id'] ?? '');
            }
        } elseif ($operation === 'task_queue_orchestrator'
            && (string) data_get($result, 'queue_entry.event') === 'enqueued') {
            $taskPacketIds[] = (string) data_get($result, 'queue_entry.task_packet_id', '');
        } elseif ($operation === 'task_queue_claim_next'
            && (string) ($result['event'] ?? '') === 'claimed') {
            $taskPacketIds[] = (string) ($result['task_packet_id'] ?? '');
            $leaseIds[] = (string) ($result['lease_id'] ?? '');
        } elseif ($operation === 'task_auto_replenishment') {
            foreach ((array) ($result['generated_tasks'] ?? []) as $generated) {
                $taskPacketIds[] = (string) ($generated['task_packet_id'] ?? '');
            }
        } elseif ($operation === 'operator_evidence_draft_workspace_publisher') {
            foreach ((array) ($result['published_artifacts'] ?? []) as $artifact) {
                $artifactPaths[] = (string) data_get($result, 'artifacts.'.$artifact.'.destination_path', '');
            }
        }

        return [
            'task_packet_ids' => $this->stringList($taskPacketIds),
            'lease_ids' => $this->stringList($leaseIds),
            'artifact_paths' => $this->stringList($artifactPaths),
        ];
    }

    /** @param array<string, list<string>> $persistedArtifactIds */
    private function artifactsArePersisted(string $operation, array $persistedArtifactIds): bool
    {
        $taskPacketIds = (array) ($persistedArtifactIds['task_packet_ids'] ?? []);
        $leaseIds = (array) ($persistedArtifactIds['lease_ids'] ?? []);
        $artifactPaths = (array) ($persistedArtifactIds['artifact_paths'] ?? []);

        if ($operation === 'operator_evidence_draft_workspace_publisher') {
            if ($artifactPaths === []) {
                return false;
            }

            foreach ($artifactPaths as $artifactPath) {
                if (! Storage::disk('local')->exists((string) $artifactPath)) {
                    return false;
                }
            }

            return true;
        }
        if ($taskPacketIds === []) {
            return false;
        }
        foreach ($taskPacketIds as $taskPacketId) {
            if (! is_array($this->queue()->get((string) $taskPacketId))) {
                return false;
            }
        }
        if ($operation === 'task_queue_claim_next') {
            $taskPacketId = (string) $taskPacketIds[0];
            $leaseId = (string) ($leaseIds[0] ?? '');
            $record = $this->queue()->get($taskPacketId);
            $lease = $leaseId === '' ? null : $this->leases()->get($leaseId);

            return is_array($record)
                && (string) ($record['status'] ?? '') === 'claimed'
                && is_array($lease)
                && (string) ($lease['task_packet_id'] ?? '') === $taskPacketId
                && (string) ($lease['lease_status'] ?? '') === AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE;
        }
        if ($operation === 'task_lease_recovery') {
            if ($leaseIds === []) {
                return false;
            }

            foreach ($leaseIds as $leaseId) {
                if (! is_array($this->leases()->get((string) $leaseId))) {
                    return false;
                }
            }

            return true;
        }

        return true;
    }

    /** @param array<string, list<string>> $persistedArtifactIds */
    private function recordRuntimeReceipt(string $operation, string $idempotencyKey, array $persistedArtifactIds): void
    {
        if ($operation === 'operator_evidence_draft_workspace_publisher') {
            Storage::disk('local')->put($this->publisherRuntimeReceiptPath($idempotencyKey), json_encode([
                'schema_version' => self::SCHEMA_VERSION,
                'receipt_kind' => self::RUNTIME_RECEIPT_KIND,
                'operation' => $operation,
                'idempotency_key' => $idempotencyKey,
                'persisted_artifact_ids' => $persistedArtifactIds,
            ], JSON_THROW_ON_ERROR));

            return;
        }

        foreach ((array) ($persistedArtifactIds['task_packet_ids'] ?? []) as $taskPacketId) {
            $this->queue()->appendReceipt((string) $taskPacketId, [
                'receipt_kind' => self::RUNTIME_RECEIPT_KIND,
                'operation' => $operation,
                'idempotency_key' => $idempotencyKey,
                'persisted_artifact_ids' => $persistedArtifactIds,
            ]);
        }
    }

    /** @return array<string, mixed>|null */
    private function publisherRuntimeReplay(string $idempotencyKey, array $options): ?array
    {
        $receiptPath = $this->publisherRuntimeReceiptPath($idempotencyKey);
        if (! Storage::disk('local')->exists($receiptPath)) {
            return null;
        }
        $receipt = json_decode((string) Storage::disk('local')->get($receiptPath), true);
        $persistedArtifactIds = is_array($receipt) ? (array) ($receipt['persisted_artifact_ids'] ?? []) : [];
        if (
            ! is_array($receipt)
            || (string) ($receipt['receipt_kind'] ?? '') !== self::RUNTIME_RECEIPT_KIND
            || (string) ($receipt['operation'] ?? '') !== 'operator_evidence_draft_workspace_publisher'
            || (string) ($receipt['idempotency_key'] ?? '') !== $idempotencyKey
            || ! $this->artifactsArePersisted('operator_evidence_draft_workspace_publisher', $persistedArtifactIds)
        ) {
            return null;
        }

        return $this->envelope('operator_evidence_draft_workspace_publisher', [
            'status' => 'replayed_persisted_runtime_artifacts',
            'runtime_write_performed' => false,
            'persisted_artifact_ids' => $persistedArtifactIds,
            'idempotency_key' => $idempotencyKey,
            'replay_options' => $options,
        ]);
    }

    private function publisherRuntimeReceiptPath(string $idempotencyKey): string
    {
        return self::PUBLISHER_RUNTIME_RECEIPT_PREFIX.'/'.$idempotencyKey.'.json';
    }

    /** @param list<string> $values @return list<string> */
    private function stringList(array $values): array
    {
        return array_values(array_unique(array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''))));
    }

    /** @param array<string, mixed> $context @param array<string, mixed> $options */
    private function idempotencyKey(string $operation, array $context, array $options): string
    {
        return hash('sha256', json_encode($this->sortRecursively([
            'operation' => $operation,
            'context' => $context,
            'options' => $options,
        ]), JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function envelope(string $operation, array $payload): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'operation' => $operation,
            'runtime_write_allowed' => false,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'ledger_write_allowed' => false,
            'self_programming_allowed' => false,
        ], $payload);
    }

    /** @param array<int|string, mixed> $value @return array<int|string, mixed> */
    private function sortRecursively(array $value): array
    {
        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $value[$key] = $this->sortRecursively($nested);
            }
        }
        if (array_is_list($value)) {
            return $value;
        }
        ksort($value);

        return $value;
    }

    private function queue(): AgentControlPlaneTaskPacketQueueRepository
    {
        return $this->queue ?? new AgentControlPlaneTaskPacketQueueRepository;
    }

    private function leases(): AgentControlPlaneClaimLeaseRepository
    {
        return $this->leases ?? new AgentControlPlaneClaimLeaseRepository;
    }

    private function orchestrator(): AgentControlPlaneTaskQueueOrchestrator
    {
        return $this->orchestrator ?? new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            $this->queue(),
            $this->leases(),
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );
    }

    private function replenishment(): AgentControlPlaneTaskAutoReplenishmentService
    {
        return $this->replenishment ?? new AgentControlPlaneTaskAutoReplenishmentService(
            $this->orchestrator(),
            $this->queue(),
        );
    }

    private function bootstrap(): AgentControlPlaneTerminalWorkerBootstrapService
    {
        return $this->bootstrap ?? new AgentControlPlaneTerminalWorkerBootstrapService(
            $this->replenishment(),
            $this->orchestrator(),
            new AgentControlPlaneOneShotWorkerPacketService($this->leases(), $this->queue()),
            $this->queue(),
            $this->leases(),
        );
    }
}
