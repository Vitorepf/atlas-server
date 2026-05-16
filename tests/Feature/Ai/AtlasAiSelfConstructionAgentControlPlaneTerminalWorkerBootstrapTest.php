<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneOneShotWorkerPacketService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskAutoReplenishmentService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTerminalWorkerBootstrapService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneTerminalWorkerBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_bootstrap_replenishes_claims_and_returns_one_shot_prompt(): void
    {
        $result = $this->service()->bootstrap($this->context(), [
            'actor' => 'claude-terminal-1',
            'target_min_claimable_tasks' => 3,
            'max_new_tasks' => 3,
        ]);

        $this->assertSame('ready_for_worker', $result['status']);
        $this->assertSame('claude-terminal-1', $result['actor']);
        $this->assertSame('claimed', $result['claim_event']);
        $this->assertTrue($result['runtime_claim_persisted']);
        $this->assertTrue($result['one_shot_worker_packet_ready']);
        $this->assertNotEmpty($result['task_packet_id']);
        $this->assertNotEmpty($result['lease_id']);
        $this->assertNotEmpty($result['worker_prompt_full']);
        $this->assertNotEmpty($result['one_shot_packet_hash']);
        $this->assertStringContainsString('--agent-control-plane-task-queue-complete-dry-run-status', $result['completion_command']);
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_worker_resumption_contract.v1',
            data_get($result, 'resumption_contract.schema_version'),
        );
        $this->assertStringContainsString('--agent-control-plane-task-lease-recovery-status', $result['resume_after_interruption_command']);
        $this->assertStringContainsString($result['task_packet_id'], $result['resume_after_interruption_command']);
        $this->assertSame('available', $result['auto_replenishment_status']);
    }

    public function test_multiple_bootstraps_can_claim_distinct_parallel_lanes(): void
    {
        $service = $this->service();

        $first = $service->bootstrap($this->context(), [
            'actor' => 'codex-terminal-1',
            'target_min_claimable_tasks' => 3,
            'max_new_tasks' => 3,
        ]);
        $second = $service->bootstrap($this->context(), [
            'actor' => 'claude-terminal-2',
            'target_min_claimable_tasks' => 3,
            'max_new_tasks' => 3,
        ]);

        $this->assertSame('ready_for_worker', $first['status']);
        $this->assertSame('ready_for_worker', $second['status']);
        $this->assertNotSame($first['task_packet_id'], $second['task_packet_id']);
        $this->assertNotSame($first['lease_id'], $second['lease_id']);

        $firstWriteSet = (array) data_get($first, 'one_shot_worker_packet.lease.write_set', []);
        $secondWriteSet = (array) data_get($second, 'one_shot_worker_packet.lease.write_set', []);
        $this->assertSame([], array_values(array_intersect($firstWriteSet, $secondWriteSet)));
    }

    public function test_bootstrap_can_be_isolated_by_queue_tag(): void
    {
        $service = $this->service();
        $first = $service->bootstrap($this->context(), [
            'actor' => 'tagged-codex',
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['isolated_terminal_lane_a'],
        ]);
        $second = $service->bootstrap($this->context(), [
            'actor' => 'tagged-claude',
            'target_min_claimable_tasks' => 2,
            'max_new_tasks' => 2,
            'queue_tags' => ['isolated_terminal_lane_b'],
        ]);

        $this->assertSame('ready_for_worker', $first['status']);
        $this->assertSame('ready_for_worker', $second['status']);
        $this->assertSame('isolated_terminal_lane_a', $first['claim_tag']);
        $this->assertSame('isolated_terminal_lane_b', $second['claim_tag']);
        $this->assertNotSame($first['task_packet_id'], $second['task_packet_id']);
        $this->assertContains('isolated_terminal_lane_a', (array) data_get($first, 'claim.queue_entry.tags', []));
        $this->assertContains('isolated_terminal_lane_b', (array) data_get($second, 'claim.queue_entry.tags', []));
    }

    public function test_runtime_flags_remain_false(): void
    {
        $result = $this->service()->bootstrap($this->context(), [
            'actor' => 'safe-terminal',
        ]);

        foreach ([
            'runtime_execution_allowed',
            'dispatch_allowed',
            'provider_call_allowed',
            'token_spend_allowed',
            'self_programming_allowed',
            'ledger_write_allowed',
            'completion_real_allowed',
        ] as $flag) {
            $this->assertFalse((bool) $result[$flag], $flag);
        }
        $this->assertContains('terminal_worker_bootstrap_does_not_call_provider', $result['non_execution_guarantees']);
        $this->assertContains('terminal_worker_bootstrap_does_not_dispatch_work', $result['non_execution_guarantees']);
    }

    public function test_cli_status_and_quartet_are_exposed(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-terminal-worker-bootstrap-status' => true,
            '--actor' => 'cli-terminal',
            '--target-min-claimable-tasks' => 3,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction_agent_control_plane_terminal_worker_bootstrap_status.v1', $payload['schema_version']);
        $this->assertSame('ready_for_worker', data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.status'));
        $this->assertSame('cli-terminal', data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.actor'));
        $this->assertTrue((bool) data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.runtime_claim_persisted'));
        $this->assertTrue((bool) data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.one_shot_worker_packet_ready'));
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_terminal_worker_bootstrap.worker_prompt_full'));

        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-terminal-worker-bootstrap-{$stage}" => true,
                '--json' => true,
            ]);
            $quartet = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame("atlas.self_construction_agent_control_plane_terminal_worker_bootstrap_{$stageKey}.v1", $quartet['schema_version']);
        }
    }

    private function service(): AgentControlPlaneTerminalWorkerBootstrapService
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

        return new AgentControlPlaneTerminalWorkerBootstrapService(
            new AgentControlPlaneTaskAutoReplenishmentService($orchestrator, $queue),
            $orchestrator,
            new AgentControlPlaneOneShotWorkerPacketService($leases, $queue),
            $queue,
            $leases,
        );
    }

    /**
     * @return array<string, mixed>
     */
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
        ];
    }
}
