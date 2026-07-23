<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskLeaseRecoveryService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTerminalLoopHealthDigestService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentControlPlaneTerminalLoopHealthDigestServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function enqueue(AgentControlPlaneTaskPacketQueueRepository $queue, string $id, string $status): void
    {
        $queue->enqueue([
            'task_packet_id' => $id,
            'task_packet_hash' => hash('sha256', $id),
            'status' => $status,
        ]);
    }

    private function service(): AgentControlPlaneTerminalLoopHealthDigestService
    {
        return new AgentControlPlaneTerminalLoopHealthDigestService(
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneTaskLeaseRecoveryService,
        );
    }

    public function test_muscle_supply_state_recommends_pull_now_when_claimable_supply_present(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->enqueue($queue, 'task-claimable', 'claimable');

        $digest = $this->service()->digest(['target_min_claimable_tasks' => 1]);
        $state = $digest['muscle_supply_state'];

        $this->assertSame(1, $state['claimable_count']);
        $this->assertSame('pull_now', $state['next_safe_action']);
        $this->assertArrayHasKey('claimed_count', $state);
        $this->assertArrayHasKey('recoverable_count', $state);
        $this->assertArrayHasKey('blocked_count', $state);
        $this->assertArrayHasKey('hidden_claimable_outside_requested_tags', $state);
    }

    public function test_partial_claimable_supply_recommends_replenishment_consistently_across_digest_surfaces(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->enqueue($queue, 'task-partial-supply', 'claimable');

        $digest = $this->service()->digest(['target_min_claimable_tasks' => 3]);

        $this->assertSame('replenish_task_supply', $digest['loop_decision']['recommended_action']);
        $this->assertSame('replenish', $digest['muscle_supply_state']['next_safe_action']);
        $this->assertSame('claimable_supply_below_target', $digest['muscle_supply_state']['wait_reason']);
    }

    public function test_zero_max_new_tasks_blocks_replenishment_without_selecting_a_noop_command(): void
    {
        $digest = $this->service()->digest([
            'target_min_claimable_tasks' => 3,
            'max_new_tasks' => 0,
        ]);

        $plan = $digest['terminal_loop_fleet_replenishment_plan'];
        $handoff = $digest['terminal_loop_fleet_operator_handoff'];
        $supervisor = $digest['terminal_loop_cycle_supervisor'];

        $this->assertSame(3, $plan['required_new_task_count']);
        $this->assertSame(0, $plan['bounded_new_task_count']);
        $this->assertSame('fleet_replenishment_blocked_max_new_tasks_zero', $plan['status']);
        $this->assertFalse($plan['should_replenish_now']);
        $this->assertSame('fleet_operator_handoff_wait_or_inspect', $handoff['status']);
        $this->assertSame($digest['next_commands']['terminal_loop_health_digest'], $handoff['primary_command']);
        $this->assertSame('wait_or_inspect', $supervisor['cycle_state']);
        $this->assertSame($digest['next_commands']['terminal_loop_health_digest'], $supervisor['next_command']);
        $this->assertNotContains('run_replenishment_command_before_launch', $digest['terminal_loop_fleet_launch_runbook']['ordered_operator_sequence']);
    }

    public function test_active_leases_consume_the_terminal_parallelism_capacity_before_new_launches(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $leases = new \App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;

        for ($index = 1; $index <= 12; $index++) {
            $this->enqueue($queue, 'task-parallelism-'.$index, 'claimable');
        }

        for ($index = 1; $index <= 6; $index++) {
            $taskPacketId = 'task-parallelism-'.$index;
            $lease = $leases->claim($taskPacketId, 'agent-active-'.$index, ['write_set' => [], 'read_set' => []]);
            $queue->updateStatus($taskPacketId, 'claimed', [
                'lease_id' => $lease['lease']['lease_id'],
                'agent_id' => 'agent-active-'.$index,
            ]);
        }

        $digest = $this->service()->digest(['target_min_claimable_tasks' => 6]);
        $plan = $digest['terminal_loop_fleet_launch_plan'];

        $this->assertSame(6, $plan['active_lease_count']);
        $this->assertFalse($digest['loop_decision']['safe_to_start_new_worker']);
        $this->assertSame('fleet_launch_plan_blocked', $plan['status']);
        $this->assertSame(0, $plan['available_terminal_capacity']);
        $this->assertSame(0, $plan['recommended_terminal_count']);
        $this->assertContains('active_lease_parallel_capacity_reached', $plan['blocked_reasons']);
        $this->assertSame(6, $digest['terminal_loop_cycle_supervisor']['operator_loop_contract']['max_safe_parallel_terminals']);
        $this->assertSame(0, $digest['terminal_loop_cycle_supervisor']['operator_loop_contract']['max_recommended_terminals_per_batch']);
        $this->assertSame('fleet_launch_runbook_blocked', $digest['terminal_loop_fleet_launch_runbook']['status']);
    }

    public function test_muscle_supply_state_recommends_replenish_when_no_claimable_supply(): void
    {
        $digest = $this->service()->digest(['target_min_claimable_tasks' => 3]);
        $state = $digest['muscle_supply_state'];

        $this->assertSame(0, $state['claimable_count']);
        $this->assertSame('replenish', $state['next_safe_action']);
    }

    public function test_muscle_supply_state_recommends_reap_recoverable_when_lease_recoverable(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->enqueue($queue, 'task-claimed', 'claimable');
        $queue->updateStatus('task-claimed', 'claimed', ['lease_id' => 'lease-missing', 'agent_id' => 'agent-x']);

        $digest = $this->service()->digest(['target_min_claimable_tasks' => 1]);
        $state = $digest['muscle_supply_state'];

        $this->assertSame(1, $state['recoverable_count']);
        $this->assertSame('reap_recoverable', $state['next_safe_action']);
        $this->assertSame('recoverable_leases_require_reaping', $state['wait_reason']);
        $this->assertStringContainsString('--agent-control-plane-task-lease-recovery-status', $state['next_recheck_command']);
        $this->assertNotEmpty($state['supply_explanation']);
    }

    public function test_muscle_supply_state_counts_blocked_packets_without_making_them_claimable(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->enqueue($queue, 'task-blocked', 'blocked');

        $digest = $this->service()->digest(['target_min_claimable_tasks' => 1]);
        $state = $digest['muscle_supply_state'];

        $this->assertSame(1, $state['blocked_count']);
        $this->assertSame('blocked', $queue->get('task-blocked')['status']);
    }

    public function test_existing_loop_decision_fields_remain_present_alongside_muscle_supply_state(): void
    {
        $digest = $this->service()->digest(['target_min_claimable_tasks' => 1]);

        $this->assertArrayHasKey('muscle_supply_state', $digest);
        $this->assertArrayHasKey('loop_decision', $digest);
        $this->assertArrayHasKey('recommended_action', $digest['loop_decision']);
        $this->assertArrayHasKey('queue_health', $digest);
        $this->assertNotEmpty($digest['terminal_loop_health_digest_hash']);
    }

    public function test_digest_initializes_lazy_collaborators_without_dynamic_property_deprecations(): void
    {
        $deprecations = [];
        set_error_handler(static function (int $severity, string $message) use (&$deprecations): bool {
            if ($severity === E_DEPRECATED && str_contains($message, 'Creation of dynamic property')) {
                $deprecations[] = $message;

                return true;
            }

            return false;
        });

        try {
            $digest = $this->service()->digest();
        } finally {
            restore_error_handler();
        }

        $this->assertArrayHasKey('terminal_loop_health_digest_hash', $digest);
        $digestDeprecations = array_values(array_filter(
            $deprecations,
            static fn (string $message): bool => str_contains($message, AgentControlPlaneTerminalLoopHealthDigestService::class),
        ));
        $this->assertSame([], $digestDeprecations, 'digest must not create lazy collaborators as dynamic properties');
    }

    public function test_replenish_wait_state_includes_reason_command_and_explanation(): void
    {
        $digest = $this->service()->digest(['target_min_claimable_tasks' => 3]);
        $state = $digest['muscle_supply_state'];

        $this->assertSame('replenish', $state['next_safe_action']);
        $this->assertNotEmpty($state['wait_reason']);
        $this->assertNotEmpty($state['next_recheck_command']);
        $this->assertNotEmpty($state['supply_explanation']);
    }

    public function test_wait_for_workers_state_includes_reason_command_and_explanation(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;

        // Untagged claimable task: hidden from the 'lane-a' filter, so hiddenClaimableOutsideRequestedTags > 0.
        $queue->enqueue([
            'task_packet_id' => 'task-hidden-claimable',
            'task_packet_hash' => hash('sha256', 'task-hidden-claimable'),
            'status' => 'claimable',
        ]);

        // Tagged claimed task with an active lease: contributes to claimedCount within the filter.
        $queue->enqueue([
            'task_packet_id' => 'task-claimed-only',
            'task_packet_hash' => hash('sha256', 'task-claimed-only'),
            'status' => 'claimable',
        ], ['tags' => ['lane-a']]);
        $lease = (new \App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository)->claim(
            'task-claimed-only',
            'agent-active',
            ['write_set' => [], 'read_set' => []],
        );
        $queue->updateStatus('task-claimed-only', 'claimed', [
            'lease_id' => $lease['lease']['lease_id'],
            'agent_id' => 'agent-active',
        ]);

        $digest = $this->service()->digest(['target_min_claimable_tasks' => 1, 'queue_tags' => ['lane-a']]);
        $state = $digest['muscle_supply_state'];

        $this->assertSame('wait_for_workers', $state['next_safe_action']);
        $this->assertNotEmpty($state['wait_reason']);
        $this->assertNotEmpty($state['next_recheck_command']);
        $this->assertNotEmpty($state['supply_explanation']);
    }

    public function test_pull_now_state_does_not_include_wait_fields(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->enqueue($queue, 'task-claimable-only', 'claimable');

        $digest = $this->service()->digest(['target_min_claimable_tasks' => 1]);
        $state = $digest['muscle_supply_state'];

        $this->assertSame('pull_now', $state['next_safe_action']);
        $this->assertArrayNotHasKey('wait_reason', $state);
    }

    public function test_sufficient_queue_depth_recommends_continue_not_stop_origination(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->enqueue($queue, 'task-sufficient', 'claimable');

        $digest = $this->service()->digest(['target_min_claimable_tasks' => 1]);

        $this->assertSame('continue_or_start_terminal_workers', $digest['loop_decision']['recommended_action']);
    }

    public function test_recoverable_lease_recommends_recovery_before_replenish(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->enqueue($queue, 'task-recoverable', 'claimable');
        $queue->updateStatus('task-recoverable', 'claimed', ['lease_id' => 'lease-missing', 'agent_id' => 'agent-y']);

        $digest = $this->service()->digest(['target_min_claimable_tasks' => 5]);

        $this->assertSame('recover_stale_or_orphaned_leases', $digest['loop_decision']['recommended_action']);
        $this->assertSame('reap_recoverable', $digest['muscle_supply_state']['next_safe_action']);
    }

    // ── AC2/AC3: lease_leak_diagnostic ──

    public function test_lease_leak_diagnostic_present_when_lease_leak_detected(): void
    {
        $digest = $this->service()->digest([
            'target_min_claimable_tasks' => 3,
            'health_flags' => ['lease_leak_detected' => true],
        ]);

        $this->assertArrayHasKey('lease_leak_diagnostic', $digest);
        $this->assertNotNull($digest['lease_leak_diagnostic']);
    }

    public function test_lease_leak_diagnostic_null_when_not_detected(): void
    {
        $digest = $this->service()->digest(['target_min_claimable_tasks' => 3]);

        $this->assertArrayHasKey('lease_leak_diagnostic', $digest);
        $this->assertNull($digest['lease_leak_diagnostic']);
    }

    public function test_lease_leak_diagnostic_contains_required_fields(): void
    {
        $digest = $this->service()->digest([
            'target_min_claimable_tasks' => 3,
            'health_flags' => ['lease_leak_detected' => true],
        ]);

        $diagnostic = $digest['lease_leak_diagnostic'];
        $this->assertArrayHasKey('queue_pressure', $diagnostic);
        $this->assertArrayHasKey('worker_impact', $diagnostic);
        $this->assertArrayHasKey('likely_cause', $diagnostic);
        $this->assertArrayHasKey('next_self_healing_action', $diagnostic);
        $this->assertNotEmpty($diagnostic['queue_pressure']);
        $this->assertNotEmpty($diagnostic['likely_cause']);
        $this->assertNotEmpty($diagnostic['next_self_healing_action']);
    }

    // ── AC4: does not recommend creating more tasks for lease consistency issues ──

    public function test_lease_leak_diagnostic_next_healing_action_is_not_create_more_tasks(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->enqueue($queue, 'task-leak', 'claimable');
        $queue->updateStatus('task-leak', 'claimed', ['lease_id' => 'lease-missing', 'agent_id' => 'agent-z']);

        $digest = $this->service()->digest([
            'target_min_claimable_tasks' => 5,
            'health_flags' => ['lease_leak_detected' => true],
        ]);

        $diagnostic = $digest['lease_leak_diagnostic'];
        $this->assertNotSame('replenish', $digest['loop_decision']['recommended_action']);
        $this->assertStringNotContainsString('replenish', $diagnostic['next_self_healing_action']);
        $this->assertStringNotContainsString('create', $diagnostic['next_self_healing_action']);
    }

    public function test_resume_summary_sanitizes_packet_id_in_commands(): void
    {
        // prove safeCommandToken strips shell metacharacters from task_packet_id
        // when interpolated into recover_command / resume_packet_command.
        $service = new AgentControlPlaneTerminalLoopHealthDigestService(
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneTaskLeaseRecoveryService,
        );
        $ref = new \ReflectionMethod($service, 'safeCommandToken');
        $ref->setAccessible(true);

        $malicious = 'x; rm -rf /';
        $sanitized = $ref->invoke($service, $malicious, 'packet');

        // Must not contain shell metacharacters (;, &, |, `, $ are replaced with -).
        $this->assertDoesNotMatchRegularExpression('/[;&|`$]/', (string) $sanitized);
        $this->assertStringNotContainsString(';', (string) $sanitized);

        // Ordinary alphanumeric id passes through safely.
        $clean = $ref->invoke($service, 'pk-01h3abc', 'packet');
        $this->assertSame('pk-01h3abc', (string) $clean);
    }
}
