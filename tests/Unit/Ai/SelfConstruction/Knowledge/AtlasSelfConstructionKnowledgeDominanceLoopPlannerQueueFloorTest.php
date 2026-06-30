<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Knowledge;

use App\Services\Ai\SelfConstruction\Knowledge\AtlasSelfConstructionKnowledgeDominanceLoopPlanner;
use Tests\TestCase;

final class AtlasSelfConstructionKnowledgeDominanceLoopPlannerQueueFloorTest extends TestCase
{
    public function test_claimable_depth_changed_with_stale_queue_health_blocks_with_worker_floor_reason(): void
    {
        $result = (new AtlasSelfConstructionKnowledgeDominanceLoopPlanner)->plan([
            'claimable_depth_changed' => true,
            'queue_health_freshness_seconds' => 600,
        ]);

        $this->assertFalse($result['next_originator_context_ready']);
        $this->assertSame('stop', $result['stop_go']);
        $this->assertContains(
            AtlasSelfConstructionKnowledgeDominanceLoopPlanner::ACTION_REFRESH_QUEUE_HEALTH,
            array_column($result['refresh_actions'], 'action_id'),
        );
        $this->assertContains('queue_health_stale', $result['not_ready_reasons']);
        $this->assertContains('worker_floor_queue_health_not_refreshed_after_claimable_depth_change', $result['not_ready_reasons']);
    }

    public function test_queued_targets_stale_after_batch_blocks_with_reason(): void
    {
        $result = (new AtlasSelfConstructionKnowledgeDominanceLoopPlanner)->plan([
            'queued_targets_stale_after_batch' => true,
        ]);

        $this->assertFalse($result['next_originator_context_ready']);
        $this->assertContains(
            AtlasSelfConstructionKnowledgeDominanceLoopPlanner::ACTION_REFRESH_QUEUED_TARGETS,
            array_column($result['refresh_actions'], 'action_id'),
        );
        $this->assertContains('queued_targets_stale_after_batch', $result['not_ready_reasons']);
    }

    public function test_claimable_depth_changed_without_stale_queue_health_does_not_add_worker_floor_reason(): void
    {
        $result = (new AtlasSelfConstructionKnowledgeDominanceLoopPlanner)->plan([
            'claimable_depth_changed' => true,
            'queue_health_freshness_seconds' => 10,
        ]);

        $this->assertNotContains('worker_floor_queue_health_not_refreshed_after_claimable_depth_change', $result['not_ready_reasons']);
    }
}
