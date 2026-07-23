<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService;

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
        $result = ($this->leaseRecovery ?? new AgentControlPlaneTaskLeaseRecoveryService($this->queue(), $this->leases()))
            ->recoverExpiredLeases($context);

        return $this->runtimeResult('task_lease_recovery', $result, $context);
    }

    /** @param array<string, mixed> $context */
    public function runTaskQueueOrchestrator(array $context = []): array
    {
        return $this->runtimeResult('task_queue_orchestrator', $this->orchestrator()->prepareAndEnqueue($context), $context);
    }

    /** @param array<string, mixed> $context */
    public function runTaskQueueClaimNext(array $context = []): array
    {
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
        return $this->runtimeResult('task_auto_replenishment', $this->replenishment()->replenish($context, $options), $options);
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
        $lease = $leaseId === '' ? null : $this->leases()->get($leaseId);
        $persisted = $taskPacketId !== ''
            && $leaseId !== ''
            && is_array($this->queue()->get($taskPacketId))
            && is_array($lease)
            && (string) ($lease['task_packet_id'] ?? '') === $taskPacketId;

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
        $result = ($this->publisher ?? new AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService)->publish($context);

        return $this->runtimeResult('operator_evidence_draft_workspace_publisher', $result, $context);
    }

    /** @param array<string, mixed> $result @param array<string, mixed> $options */
    private function runtimeResult(string $operation, array $result, array $options): array
    {
        return $this->envelope($operation, [
            'status' => (string) ($result['status'] ?? 'unknown'),
            'runtime_write_performed' => true,
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
        foreach ($this->queue->list() as $record) {
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
                $lease = $leaseId === '' ? null : $this->leases->get($leaseId);
                if (
                    $taskPacketId !== ''
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
