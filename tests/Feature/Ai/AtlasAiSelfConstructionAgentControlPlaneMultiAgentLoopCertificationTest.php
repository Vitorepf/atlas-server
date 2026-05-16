<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneMultiAgentLoopCertificationService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneMultiAgentLoopCertificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_six_agents_receive_six_distinct_tasks(): void
    {
        $cert = $this->certify(['agent_count' => 6, 'cycles' => 1]);

        $cycle = $cert['cycle_evidence'][0];
        $this->assertSame(6, (int) $cycle['distinct_task_count']);
        $this->assertCount(6, $cycle['agents']);

        $taskIds = array_map(fn (array $a): string => (string) $a['task_packet_id'], $cycle['agents']);
        $this->assertCount(6, array_unique($taskIds));
    }

    public function test_lease_ids_are_unique_across_agents(): void
    {
        $cert = $this->certify(['agent_count' => 6, 'cycles' => 1]);

        $cycle = $cert['cycle_evidence'][0];
        $this->assertSame(6, (int) $cycle['distinct_lease_count']);

        $leaseIds = array_map(fn (array $a): string => (string) $a['lease_id'], $cycle['agents']);
        $this->assertCount(6, array_unique($leaseIds));
        foreach ($leaseIds as $leaseId) {
            $this->assertNotSame('', $leaseId);
            $this->assertStringStartsWith('lease_', $leaseId);
        }
    }

    public function test_no_write_set_overlap_across_concurrent_claims(): void
    {
        $cert = $this->certify(['agent_count' => 6, 'cycles' => 1]);

        $cycle = $cert['cycle_evidence'][0];
        $this->assertSame(0, (int) $cycle['write_set_collision_count']);

        $allWriteSets = [];
        foreach ($cycle['agents'] as $agent) {
            $this->assertNotEmpty((array) $agent['write_set']);
            foreach ($allWriteSets as $other) {
                $this->assertSame(
                    [],
                    array_intersect((array) $agent['write_set'], $other),
                    'concurrent claims must have disjoint write sets',
                );
            }
            $allWriteSets[] = (array) $agent['write_set'];
        }
    }

    public function test_complete_dry_run_closes_every_lease(): void
    {
        $cert = $this->certify(['agent_count' => 4, 'cycles' => 1]);

        $cycle = $cert['cycle_evidence'][0];
        $this->assertSame(4, (int) $cycle['completed_count']);
        $this->assertSame(0, (int) $cycle['active_leases_after_complete']);
        foreach ($cycle['agents'] as $agent) {
            $this->assertTrue((bool) $agent['completed']);
            $this->assertSame('completed_dry_run', (string) $agent['completion_event']);
        }
    }

    public function test_second_cycle_replenishes_queue_supply(): void
    {
        $cert = $this->certify(['agent_count' => 3, 'cycles' => 2]);

        $this->assertCount(2, $cert['cycle_evidence']);
        $this->assertGreaterThanOrEqual(3, (int) $cert['cycle_evidence'][1]['claimable_before_claim']);
        $this->assertSame(3, (int) $cert['cycle_evidence'][1]['distinct_task_count']);
        $this->assertSame(3, (int) $cert['cycle_evidence'][1]['completed_count']);
    }

    public function test_recovery_never_reopens_completed_packets(): void
    {
        $cert = $this->certify(['agent_count' => 3, 'cycles' => 2]);

        $this->assertTrue((bool) $cert['invariants']['recovery_never_reopens_completed']);
        foreach ($cert['cycle_evidence'] as $cycle) {
            $this->assertTrue((bool) $cycle['reclaim_completed_blocked']);
            $this->assertTrue((bool) $cycle['recovery']['orphan_resolved']);
            $this->assertTrue((bool) $cycle['recovery']['expired_resolved']);
        }
    }

    public function test_status_is_available_when_invariants_all_green(): void
    {
        $cert = $this->certify(['agent_count' => 3, 'cycles' => 2]);

        $this->assertSame('available', (string) $cert['status']);
        $this->assertTrue((bool) $cert['invariants_all_true']);
        $this->assertSame(0, (int) $cert['violation_count']);
    }

    public function test_certification_exercises_terminal_worker_bootstrap_path(): void
    {
        $cert = $this->certify(['agent_count' => 3, 'cycles' => 1]);
        $probe = (array) $cert['terminal_worker_bootstrap_probe'];

        $this->assertSame('available', (string) $probe['status']);
        $this->assertSame(2, (int) $probe['probe_agent_count']);
        $this->assertSame(2, (int) $probe['ready_count']);
        $this->assertSame(2, (int) $probe['completed_dry_run_count']);
        $this->assertTrue((bool) $probe['one_shot_packets_ready']);
        $this->assertTrue((bool) $probe['completion_command_uses_dry_run']);
        $this->assertTrue((bool) $probe['resumption_contracts_present']);
        $this->assertTrue((bool) $probe['parallel_lanes_distinct']);
        $this->assertTrue((bool) $probe['leases_closed_by_dry_run']);
        $this->assertTrue((bool) $probe['runtime_safety_all_false']);
        $this->assertMatchesRegularExpression('/^terminal_worker_bootstrap_probe_/', (string) $probe['queue_tag']);
        $this->assertTrue((bool) $cert['invariants']['terminal_worker_bootstrap_ready']);
        $this->assertTrue((bool) $cert['invariants']['terminal_worker_bootstrap_completion_command_uses_dry_run']);
        $this->assertTrue((bool) $cert['invariants']['terminal_worker_bootstrap_resumption_contract_present']);
    }

    public function test_terminal_worker_bootstrap_probe_returns_copy_paste_completion_commands(): void
    {
        $cert = $this->certify(['agent_count' => 2, 'cycles' => 1]);
        $probeResults = (array) data_get($cert, 'terminal_worker_bootstrap_probe.results', []);

        $this->assertCount(2, $probeResults);
        foreach ($probeResults as $result) {
            $this->assertSame('ready_for_worker', (string) $result['status']);
            $this->assertTrue((bool) $result['one_shot_worker_packet_ready']);
            $this->assertStringContainsString(
                '--agent-control-plane-task-queue-complete-dry-run-status',
                (string) $result['completion_command'],
            );
            $this->assertStringContainsString(
                '--agent-control-plane-task-lease-recovery-status',
                (string) $result['resume_after_interruption_command'],
            );
            $this->assertTrue((bool) $result['resumption_contract_present']);
            $this->assertNotEmpty((string) $result['one_shot_packet_hash']);
            $this->assertNotEmpty((array) $result['write_set']);
        }
    }

    public function test_certification_claims_only_current_run_cycle_tasks(): void
    {
        [$service, $orchestrator] = $this->newStack();

        $orchestrator->prepareAndEnqueue([
            'task_packet' => [
                'task_packet_id' => 'foreign_cycle_0_task',
                'objective' => 'foreign stale cycle task',
                'operator_id' => 'test',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/__multi_agent_loop_certification_synthetic__/foreign.php'],
                'scope_in' => ['app/Services/Ai/SelfConstruction/__multi_agent_loop_certification_synthetic__/foreign.php'],
                'acceptance_criteria' => ['foreign_task_must_not_be_claimed_by_certification'],
                'required_evidence' => ['foreign_task_seeded'],
                'risk_level' => 'low',
                'rollback_strategy' => 'plan_only',
            ],
            'queue' => ['priority' => 10, 'tags' => ['multi_agent_loop_certification', 'cycle_0']],
        ]);

        $cert = $service->certify(['agent_count' => 2, 'cycles' => 1]);
        $claimedTaskIds = array_map(
            static fn (array $agent): string => (string) $agent['task_packet_id'],
            (array) data_get($cert, 'cycle_evidence.0.agents', []),
        );

        $this->assertSame('available', (string) $cert['status']);
        $this->assertNotContains('foreign_cycle_0_task', $claimedTaskIds);
    }

    public function test_status_is_blocked_when_simulate_overlap_forces_collision(): void
    {
        $cert = $this->certify([
            'agent_count' => 4,
            'cycles' => 1,
            'simulate_overlap' => true,
        ]);

        $this->assertSame('blocked', (string) $cert['status']);
        $this->assertGreaterThan(0, (int) $cert['violation_count']);
        $cycle = $cert['cycle_evidence'][0];
        $this->assertLessThan(4, (int) $cycle['distinct_task_count']);
        // Runtime safety must remain intact even when invariants intentionally fail.
        $this->assertTrue((bool) data_get($cert, 'runtime_safety.runtime_safety_all_false'));
    }

    public function test_runtime_flags_remain_false_throughout(): void
    {
        $cert = $this->certify(['agent_count' => 3, 'cycles' => 1]);

        foreach (['runtime_execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed', 'ledger_write_allowed', 'completion_real_allowed'] as $flag) {
            $this->assertFalse((bool) $cert[$flag], "top-level {$flag} must be false");
            $this->assertFalse((bool) data_get($cert, "runtime_safety.queue_runtime_flags.{$flag}"));
            $this->assertFalse((bool) data_get($cert, "runtime_safety.lease_runtime_flags.{$flag}"));
        }
        $this->assertFalse((bool) $cert['legacy_reservation_used']);
        $this->assertContains('multi_agent_loop_certification_does_not_use_legacy_reservation_ledger', (array) $cert['non_execution_guarantees']);
    }

    public function test_cli_status_returns_canonical_json_payload(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-multi-agent-loop-certification-status' => true,
            '--agent-count' => 2,
            '--cycles' => 1,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            'atlas.self_construction_agent_control_plane_multi_agent_loop_certification_status.v1',
            (string) $payload['schema_version'],
        );
        $this->assertContains(
            (string) data_get($payload, 'agent_control_plane_multi_agent_loop_certification_status.status'),
            ['available', 'blocked'],
        );
        $this->assertSame(2, (int) data_get($payload, 'agent_control_plane_multi_agent_loop_certification_status.agent_count'));
        $this->assertSame(1, (int) data_get($payload, 'agent_control_plane_multi_agent_loop_certification_status.cycles'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'agent_control_plane_multi_agent_loop_certification_status.certification_hash'));

        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-multi-agent-loop-certification-{$stage}" => true,
                '--json' => true,
            ]);
            $quartetPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_multi_agent_loop_certification_{$stageKey}.v1",
                (string) $quartetPayload['schema_version'],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function certify(array $options): array
    {
        return $this->newService()->certify($options);
    }

    private function newService(): AgentControlPlaneMultiAgentLoopCertificationService
    {
        return $this->newStack()[0];
    }

    /**
     * @return array{0: AgentControlPlaneMultiAgentLoopCertificationService, 1: AgentControlPlaneTaskQueueOrchestrator}
     */
    private function newStack(): array
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

        return [new AgentControlPlaneMultiAgentLoopCertificationService($orchestrator, $queue, $leases), $orchestrator];
    }
}
