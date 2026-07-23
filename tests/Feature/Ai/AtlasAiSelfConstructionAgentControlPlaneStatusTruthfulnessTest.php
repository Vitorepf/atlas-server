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
