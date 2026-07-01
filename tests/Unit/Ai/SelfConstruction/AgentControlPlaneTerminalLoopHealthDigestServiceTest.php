<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskLeaseRecoveryService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTerminalLoopHealthDigestService;
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
        $lease = (new \App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository)->claim(
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
}
