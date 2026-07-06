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

    public function test_released_task_that_cannot_be_requeued_is_counted_under_released_skipped(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->enqueue($queue, 'task-released-blocked', 'claimable');
        $queue->updateStatus('task-released-blocked', 'claimed', ['lease_id' => 'lease-x', 'agent_id' => 'agent-x']);
        $queue->updateStatus('task-released-blocked', 'released', ['release_reason' => 'worker_packet_blocked_before_handoff']);

        $service = new AgentControlPlaneTaskLeaseRecoveryService;
        $result = $service->recoverReleasedTasks();

        $this->assertSame(1, $result['released_skipped_count']);
        $this->assertSame('task-released-blocked', $result['released_skipped'][0]['task_packet_id']);
        $this->assertSame(
            AgentControlPlaneTaskLeaseRecoveryService::RECEIPT_RELEASED_TASK_REQUEUE_SKIPPED,
            $result['released_skipped'][0]['receipt_kind'],
        );
        $this->assertSame('released_task_requires_operator_investigation', $result['released_skipped'][0]['skip_reason']);

        // Not hidden in the generic skipped total — it's the SAME entry, counted both places.
        $this->assertSame(1, $result['skipped_count']);
        // The released-but-blocked task must NOT have transitioned to claimable.
        $this->assertSame('released', $queue->get('task-released-blocked')['status']);
    }

    public function test_released_task_successfully_requeued_is_not_counted_as_released_skipped(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->enqueue($queue, 'task-released-ok', 'claimable');
        $queue->updateStatus('task-released-ok', 'claimed', ['lease_id' => 'lease-y', 'agent_id' => 'agent-y']);
        $queue->updateStatus('task-released-ok', 'released', ['release_reason' => 'paused_for_replan']);

        $service = new AgentControlPlaneTaskLeaseRecoveryService;
        $result = $service->recoverReleasedTasks();

        $this->assertSame(0, $result['released_skipped_count']);
        $this->assertSame(1, $result['recovered_count']);
        $this->assertSame('claimable', $queue->get('task-released-ok')['status']);
    }

    // ── AC: claimed task with missing/zero expires_at_unix is recoverable (not immortal) ──

    public function test_claimed_lease_with_missing_expiry_is_recoverable_not_active(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->enqueue($queue, 'task-missing-expiry', 'claimable');
        $queue->updateStatus('task-missing-expiry', 'claimed', ['lease_id' => 'lease-no-expiry', 'agent_id' => 'agent-x']);

        $service = new AgentControlPlaneTaskLeaseRecoveryService;
        $result = $service->inspectRecoverability();

        $summary = $result['root_cause_summary'];
        // A claimed task whose lease has no expires_at_unix must be counted as
        // stale (recoverable), not as active_lease (immortal).
        $this->assertGreaterThanOrEqual(1, $summary['stale_claimed_leases']['count'],
            'missing-expiry lease must be classified as stale, not active');
    }
}
