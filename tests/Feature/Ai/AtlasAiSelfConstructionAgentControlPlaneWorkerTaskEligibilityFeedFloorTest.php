<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneWorkerTaskEligibilityCertificationService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentControlPlaneWorkerTaskEligibilityFeedFloorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_claimable_count_below_worker_feed_floor_blocks_with_breach_reason(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $queue->enqueue([
            'task_packet_id' => 'tp-floor-1',
            'task_packet_hash' => 'hash-floor-1',
            'status' => 'planned',
        ], ['tags' => ['feed-floor-test']]);
        $queue->enqueue([
            'task_packet_id' => 'tp-floor-2',
            'task_packet_hash' => 'hash-floor-2',
            'status' => 'planned',
        ], ['tags' => ['feed-floor-test']]);

        $result = $this->service($queue)->certify([
            'queue_tags' => ['feed-floor-test'],
            'active_worker_count' => 5,
            'minimum_claimable_per_worker' => 2,
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('worker_feed_floor_breach', $result['blocked_reasons']);
        $this->assertTrue($result['worker_feed_floor_breached']);
        $this->assertSame(2, $result['claimable_task_count']);
        $this->assertSame(10, $result['worker_feed_floor_required']);
    }

    public function test_sufficient_claimable_tasks_and_no_violations_remains_available(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        for ($i = 1; $i <= 10; $i++) {
            $queue->enqueue([
                'task_packet_id' => "tp-floor-ok-{$i}",
                'task_packet_hash' => "hash-floor-ok-{$i}",
                'status' => 'planned',
            ], ['tags' => ['feed-floor-test-ok']]);
        }

        $result = $this->service($queue)->certify([
            'queue_tags' => ['feed-floor-test-ok'],
            'active_worker_count' => 5,
            'minimum_claimable_per_worker' => 2,
        ]);

        $this->assertSame('available', $result['status']);
        $this->assertFalse($result['worker_feed_floor_breached']);
        $this->assertSame([], $result['blocked_reasons']);
    }

    private function service(AgentControlPlaneTaskPacketQueueRepository $queue): AgentControlPlaneWorkerTaskEligibilityCertificationService
    {
        return new AgentControlPlaneWorkerTaskEligibilityCertificationService(
            app(AtlasSelfConstructionReadinessService::class),
            $queue,
        );
    }
}
