<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneWorkerTaskEligibilityCertificationService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves AgentControlPlaneWorkerTaskEligibilityCertificationService::certify() exposes a
 * top-level `blocked_reasons` contract: non-empty and stable when status=blocked, empty when
 * status=available, derived from failed_check_ids and violation codes so callers never see
 * status=blocked with no actionable reason.
 */
final class AgentControlPlaneWorkerTaskEligibilityCertificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_blocked_status_has_non_empty_blocked_reasons(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $queue->enqueue([
            'task_packet_id' => 'tp-blocked-1',
            'task_packet_hash' => 'hash-1',
            'status' => 'planned',
            'continuation_context' => [
                'operator_handoff_required' => true,
            ],
        ]);

        $result = $this->service($queue)->certify();

        $this->assertSame('blocked', $result['status']);
        $this->assertNotEmpty($result['blocked_reasons']);
        $this->assertContains(
            'claimable_or_claimed_task_requires_operator_handoff',
            $result['blocked_reasons'],
        );
    }

    public function test_blocked_reasons_includes_failed_check_id(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $queue->enqueue([
            'task_packet_id' => 'tp-blocked-2',
            'task_packet_hash' => 'hash-2',
            'status' => 'planned',
            'continuation_context' => [
                'operator_handoff_required' => true,
            ],
        ]);

        $result = $this->service($queue)->certify();

        $this->assertContains(
            'claimable_tasks_do_not_require_operator_handoff',
            $result['failed_check_ids'],
        );
        $this->assertContains(
            'claimable_tasks_do_not_require_operator_handoff',
            $result['blocked_reasons'],
        );
    }

    public function test_available_status_has_empty_blocked_reasons_and_checks_all_true(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;

        $result = $this->service($queue)->certify();

        $this->assertSame('available', $result['status']);
        $this->assertSame([], $result['blocked_reasons']);
        $this->assertTrue($result['checks_all_true']);
        $this->assertSame([], $result['failed_check_ids']);
    }

    public function test_blocked_reasons_is_deterministic_across_repeated_calls(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $queue->enqueue([
            'task_packet_id' => 'tp-blocked-3',
            'task_packet_hash' => 'hash-3',
            'status' => 'planned',
            'continuation_context' => [
                'operator_handoff_required' => true,
            ],
        ]);

        $service = $this->service($queue);
        $first = $service->certify();
        $second = $service->certify();

        $this->assertSame($first['blocked_reasons'], $second['blocked_reasons']);
    }

    public function test_worker_feed_floor_required_is_active_worker_count_times_minimum_claimable_per_worker(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;

        $result = $this->service($queue)->certify([
            'active_worker_count' => 3,
            'minimum_claimable_per_worker' => 2,
        ]);

        $this->assertSame(6, $result['worker_feed_floor_required']);
    }

    public function test_worker_feed_floor_breach_blocks_with_named_reason_and_refill_recommendation(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $queue->enqueue([
            'task_packet_id' => 'tp-refill-1',
            'task_packet_hash' => 'hash-refill-1',
            'status' => 'planned',
        ], ['tags' => ['refill-test']]);

        $result = $this->service($queue)->certify([
            'queue_tags' => ['refill-test'],
            'active_worker_count' => 5,
            'minimum_claimable_per_worker' => 2,
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertTrue($result['worker_feed_floor_breached']);
        $this->assertContains('worker_feed_floor_breach', $result['blocked_reasons']);
        $this->assertArrayHasKey('refill_recommendation', $result);
        $this->assertSame(9, $result['refill_recommendation']['target_new_task_count']);
        $this->assertSame('worker_feed_floor_breached', $result['refill_recommendation']['reason']);
    }

    public function test_refill_recommendation_target_is_zero_when_floor_not_breached_and_certify_creates_no_tasks(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;

        $result = $this->service($queue)->certify([
            'active_worker_count' => 0,
            'minimum_claimable_per_worker' => 1,
        ]);

        $this->assertFalse($result['worker_feed_floor_breached']);
        $this->assertSame(0, $result['refill_recommendation']['target_new_task_count']);
        $this->assertTrue($result['checks']['auto_replenishment_did_not_create_tasks_during_certification']);
    }

    private function service(AgentControlPlaneTaskPacketQueueRepository $queue): AgentControlPlaneWorkerTaskEligibilityCertificationService
    {
        return new AgentControlPlaneWorkerTaskEligibilityCertificationService(
            app(AtlasSelfConstructionReadinessService::class),
            $queue,
        );
    }
}
