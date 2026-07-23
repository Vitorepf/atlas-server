<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneOneShotWorkerPacketService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneRuntime;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskAutoReplenishmentService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTerminalWorkerBootstrapService;
use App\Services\Ai\SelfConstruction\ControlPlane\ControlPlaneStatusProjector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneStatusTruthfulnessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15T10:00:00Z'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_terminal_bootstrap_projection_is_read_only_against_durable_control_plane_state(): void
    {
        $orchestrator = $this->orchestrator();
        $orchestrator->prepareAndEnqueue(['task_packet' => $this->taskInput('truthfulness-projection')]);
        $claim = $orchestrator->claimNext('truthfulness-existing-worker', ['ttl_seconds' => 60]);
        $this->assertSame('claimed', $claim['event']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15T10:05:00Z'));
        $before = $this->durableSnapshot();

        $payload = $this->projector()->projectTerminalWorkerBootstrap([
            'queue_tags' => ['truthfulness_projection_lane'],
        ]);

        $this->assertFalse((bool) $payload['runtime_write_performed']);
        $this->assertArrayHasKey('runtime_execution_allowed', $payload);
        $this->assertFalse((bool) $payload['runtime_execution_allowed']);
        $this->assertFalse((bool) $payload['dispatch_allowed']);
        $this->assertFalse((bool) $payload['provider_call_allowed']);
        $this->assertFalse((bool) $payload['token_spend_allowed']);
        $this->assertFalse((bool) $payload['ledger_write_allowed']);
        $this->assertSame('read_only_snapshot', $payload['lease_registry_status']);
        $this->assertSame(1, $payload['active_lease_count']);
        $this->assertSame([(string) $claim['lease_id']], $payload['active_lease_ids']);
        $this->assertSame($before, $this->durableSnapshot());
    }

    public function test_projection_does_not_self_heal_an_oversized_queue_registry(): void
    {
        $entries = [];
        for ($index = 0; $index < 520; $index++) {
            $entries[] = [
                'task_packet_id' => 'oversized-registry-'.$index,
                'task_packet_hash' => str_repeat('a', 64),
                'status' => 'claimable',
                'tags' => ['truthfulness_oversized_registry'],
                'padding' => str_repeat('x', 900),
            ];
        }
        Storage::disk('local')->put(
            AgentControlPlaneTaskPacketQueueRepository::REGISTRY_PATH,
            json_encode(['entries' => $entries], JSON_THROW_ON_ERROR),
        );
        $before = $this->durableSnapshot();

        $payload = $this->projector()->projectTaskQueueOrchestrator();

        $this->assertFalse((bool) $payload['runtime_write_performed']);
        $this->assertSame('projected_read_only', $payload['status']);
        $this->assertSame($before, $this->durableSnapshot());
    }

    public function test_named_terminal_bootstrap_runtime_reports_durable_ids_and_replays_without_writing(): void
    {
        CarbonImmutable::setTestNow();
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $leases = new AgentControlPlaneClaimLeaseRepository;
        $runtime = $this->runtime();
        $prepared = $this->orchestrator()->prepareAndEnqueue([
            'task_packet' => $this->taskInput('truthfulness-runtime-packet'),
            'queue' => ['tags' => ['truthfulness_runtime_lane']],
        ]);
        $this->assertSame('prepared_and_enqueued', $prepared['event']);

        $payload = $runtime->runTerminalWorkerBootstrap($this->context(), [
            'actor' => 'truthfulness-runtime',
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 0,
            'queue_tags' => ['truthfulness_runtime_lane'],
        ]);

        $taskPacketId = (string) data_get($payload, 'persisted_artifact_ids.task_packet_id');
        $leaseId = (string) data_get($payload, 'persisted_artifact_ids.lease_id');
        $this->assertTrue((bool) $payload['runtime_write_performed']);
        $this->assertNotEmpty($taskPacketId);
        $this->assertNotEmpty($leaseId);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['idempotency_key']);
        $this->assertFalse((bool) $payload['runtime_execution_allowed']);
        $this->assertFalse((bool) $payload['dispatch_allowed']);
        $this->assertFalse((bool) $payload['provider_call_allowed']);
        $this->assertFalse((bool) $payload['token_spend_allowed']);
        $this->assertFalse((bool) $payload['ledger_write_allowed']);
        $this->assertSame('ready_for_worker', data_get($payload, 'writer_result.status'));
        $this->assertSame('claimed', data_get($queue->get($taskPacketId), 'status'));
        $this->assertSame($taskPacketId, data_get($leases->get($leaseId), 'task_packet_id'));

        $stateAfterFirstRun = $this->durableSnapshot();
        $replay = $runtime->runTerminalWorkerBootstrap($this->context(), (array) $payload['replay_options']);

        $this->assertFalse((bool) $replay['runtime_write_performed']);
        $this->assertSame($payload['idempotency_key'], $replay['idempotency_key']);
        $this->assertSame($taskPacketId, data_get($replay, 'persisted_artifact_ids.task_packet_id'));
        $this->assertSame($leaseId, data_get($replay, 'persisted_artifact_ids.lease_id'));
        $this->assertSame($stateAfterFirstRun, $this->durableSnapshot());

        $freshRuntimeReplay = (new AgentControlPlaneRuntime)->runTerminalWorkerBootstrap(
            $this->context(),
            (array) $payload['replay_options'],
        );

        $this->assertSame('replayed_persisted_runtime_artifacts', $freshRuntimeReplay['status']);
        $this->assertFalse((bool) $freshRuntimeReplay['runtime_write_performed']);
        $this->assertSame($payload['idempotency_key'], $freshRuntimeReplay['idempotency_key']);
        $this->assertSame($taskPacketId, data_get($freshRuntimeReplay, 'persisted_artifact_ids.task_packet_id'));
        $this->assertSame($leaseId, data_get($freshRuntimeReplay, 'persisted_artifact_ids.lease_id'));
        $this->assertSame($stateAfterFirstRun, $this->durableSnapshot());
    }

    public function test_named_runtimes_do_not_claim_writes_without_persisted_artifacts(): void
    {
        $runtime = new AgentControlPlaneRuntime;
        $payloads = [
            $runtime->runTaskLeaseRecovery(),
            $runtime->runTaskQueueOrchestrator(),
            $runtime->runTaskQueueClaimNext(['actor' => 'truthfulness-no-op-worker']),
            $runtime->runTaskAutoReplenishment([], [
                'actor' => 'truthfulness-no-op-replenishment',
                'target_min_claimable_tasks' => 1,
                'max_new_tasks' => 0,
                'queue_tags' => ['truthfulness_no_op_lane'],
            ]),
            $runtime->runOperatorEvidenceDraftWorkspacePublisher(),
        ];

        foreach ($payloads as $payload) {
            $this->assertFalse((bool) $payload['runtime_write_performed']);
            $this->assertSame([
                'task_packet_ids' => [],
                'lease_ids' => [],
                'artifact_paths' => [],
            ], $payload['persisted_artifact_ids']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['idempotency_key']);
        }
    }

    public function test_named_queue_runtimes_persist_artifact_ids_and_replay_without_writing(): void
    {
        $enqueueInput = [
            'task_packet' => $this->taskInput('truthfulness-runtime-enqueue'),
            'queue' => ['tags' => ['truthfulness_runtime_enqueue_lane']],
        ];
        $runtime = new AgentControlPlaneRuntime;

        $enqueue = $runtime->runTaskQueueOrchestrator($enqueueInput);
        $taskPacketId = (string) data_get($enqueue, 'persisted_artifact_ids.task_packet_ids.0');
        $this->assertTrue((bool) $enqueue['runtime_write_performed']);
        $this->assertSame('truthfulness-runtime-enqueue', $taskPacketId);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $enqueue['idempotency_key']);
        $stateAfterEnqueue = $this->durableSnapshot();

        $enqueueReplay = (new AgentControlPlaneRuntime)->runTaskQueueOrchestrator($enqueueInput);
        $this->assertFalse((bool) $enqueueReplay['runtime_write_performed']);
        $this->assertSame($enqueue['idempotency_key'], $enqueueReplay['idempotency_key']);
        $this->assertSame($enqueue['persisted_artifact_ids'], $enqueueReplay['persisted_artifact_ids']);
        $this->assertSame($stateAfterEnqueue, $this->durableSnapshot());

        $claimInput = [
            'actor' => 'truthfulness-runtime-claim-worker',
            'ttl_seconds' => 300,
            'tags' => ['truthfulness_runtime_enqueue_lane'],
        ];
        $claim = $runtime->runTaskQueueClaimNext($claimInput);
        $leaseId = (string) data_get($claim, 'persisted_artifact_ids.lease_ids.0');
        $this->assertTrue((bool) $claim['runtime_write_performed']);
        $this->assertSame($taskPacketId, data_get($claim, 'persisted_artifact_ids.task_packet_ids.0'));
        $this->assertNotEmpty($leaseId);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $claim['idempotency_key']);
        $stateAfterClaim = $this->durableSnapshot();

        $claimReplay = (new AgentControlPlaneRuntime)->runTaskQueueClaimNext($claimInput);
        $this->assertFalse((bool) $claimReplay['runtime_write_performed']);
        $this->assertSame($claim['idempotency_key'], $claimReplay['idempotency_key']);
        $this->assertSame($claim['persisted_artifact_ids'], $claimReplay['persisted_artifact_ids']);
        $this->assertSame($stateAfterClaim, $this->durableSnapshot());
    }

    private function orchestrator(): AgentControlPlaneTaskQueueOrchestrator
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;

        return new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            $queue,
            new AgentControlPlaneClaimLeaseRepository,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );
    }

    private function projector(): ControlPlaneStatusProjector
    {
        return new ControlPlaneStatusProjector(
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
        );
    }

    private function runtime(): AgentControlPlaneRuntime
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $leases = new AgentControlPlaneClaimLeaseRepository;
        $orchestrator = new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            $queue,
            $leases,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );

        return new AgentControlPlaneRuntime(
            new AgentControlPlaneTerminalWorkerBootstrapService(
                new AgentControlPlaneTaskAutoReplenishmentService($orchestrator, $queue),
                $orchestrator,
                new AgentControlPlaneOneShotWorkerPacketService($leases, $queue),
                $queue,
                $leases,
            ),
            $queue,
            $leases,
            $orchestrator,
            new AgentControlPlaneTaskAutoReplenishmentService($orchestrator, $queue),
        );
    }

    /** @return array<string, mixed> */
    private function taskInput(string $id): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'Freeze durable status truthfulness.',
            'operator_id' => 'truthfulness-test',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'acceptance_criteria' => ['Status projection does not persist.'],
            'required_evidence' => ['durable_snapshot_unchanged'],
        ];
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return [
            'control_plane' => [
                'control_plane' => [
                    'persistent_runtime' => [
                        'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract',
                    ],
                    'not_yet_runtime_capable' => [
                        'adapter_execution_runtime',
                        'automatic_cost_import_runtime',
                    ],
                ],
            ],
            'completion_audit' => [
                'failed_criteria' => [
                    'runtime_gap_matrix_all_runtime_y',
                    'end_to_end_real_provider_smoke_green',
                ],
            ],
            'chain_integrity' => [
                'violations' => [],
            ],
        ];
    }

    /** @return array<string, string|null> */
    private function durableSnapshot(): array
    {
        $disk = Storage::disk('local');
        $paths = [
            AgentControlPlaneTaskPacketQueueRepository::REGISTRY_PATH,
            AgentControlPlaneClaimLeaseRepository::REGISTRY_PATH,
        ];
        $snapshot = [];
        foreach ($paths as $path) {
            $snapshot[$path] = $disk->exists($path) ? $disk->get($path) : null;
        }

        return $snapshot;
    }
}
