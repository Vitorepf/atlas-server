<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskLeaseRecoveryService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentControlPlaneTaskLeaseRecoveryServiceTest extends TestCase
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

    public function test_root_cause_summary_buckets_blocked_terminal_claimable_and_released_packets(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;

        $this->enqueue($queue, 'task-blocked', 'blocked');
        $this->enqueue($queue, 'task-terminal', 'completed_dry_run');
        $this->enqueue($queue, 'task-claimable', 'claimable');
        $this->enqueue($queue, 'task-released', 'claimable');
        $queue->updateStatus('task-released', 'claimed', ['lease_id' => 'lease-released', 'agent_id' => 'agent-x']);
        $queue->updateStatus('task-released', 'released', ['release_reason' => 'paused_for_replan']);

        $service = new AgentControlPlaneTaskLeaseRecoveryService;
        $result = $service->inspectRecoverability();

        $summary = $result['root_cause_summary'];

        $this->assertSame(1, $summary['blocked_non_recoverable']['count']);
        $this->assertSame(['task-blocked'], $summary['blocked_non_recoverable']['examples']);

        $this->assertSame(1, $summary['terminal']['count']);
        $this->assertSame(['task-terminal'], $summary['terminal']['examples']);

        $this->assertSame(1, $summary['released_returned_to_claimable']['count']);
        $this->assertSame(['task-released'], $summary['released_returned_to_claimable']['examples']);

        $this->assertSame(0, $summary['stale_claimed_leases']['count']);
        $this->assertSame(0, $summary['missing_records']['count']);
        $this->assertSame(0, $summary['transition_failures']['count']);
    }

    public function test_root_cause_summary_reports_missing_record_when_packet_filter_not_found(): void
    {
        $service = new AgentControlPlaneTaskLeaseRecoveryService;
        $result = $service->inspectRecoverability(['packet' => 'task-does-not-exist']);

        $summary = $result['root_cause_summary'];

        $this->assertSame(1, $summary['missing_records']['count']);
        $this->assertSame(['task-does-not-exist'], $summary['missing_records']['examples']);
        $this->assertSame(0, $result['inspected_count']);
    }

    public function test_blocked_packets_are_not_made_claimable_by_inspection(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->enqueue($queue, 'task-blocked', 'blocked');

        $service = new AgentControlPlaneTaskLeaseRecoveryService;
        $service->inspectRecoverability();

        $this->assertSame('blocked', $queue->get('task-blocked')['status']);
    }

    public function test_root_cause_summary_counts_stale_claimed_lease_from_orphaned_claim(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->enqueue($queue, 'task-claimed', 'claimable');
        $queue->updateStatus('task-claimed', 'claimed', ['lease_id' => 'lease-does-not-exist', 'agent_id' => 'agent-x']);

        $service = new AgentControlPlaneTaskLeaseRecoveryService;
        $result = $service->inspectRecoverability();

        $summary = $result['root_cause_summary'];

        $this->assertSame(1, $summary['stale_claimed_leases']['count']);
        $this->assertSame(['task-claimed'], $summary['stale_claimed_leases']['examples']);
    }
}
