<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskGraph;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasSelfConstructionTaskGraphAutonomousReplenisher;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionTaskGraphAutonomousReplenisherWorkerFloorTest extends TestCase
{
    private function replenisher(): AtlasSelfConstructionTaskGraphAutonomousReplenisher
    {
        return new AtlasSelfConstructionTaskGraphAutonomousReplenisher();
    }

    private function candidates(): array
    {
        return [
            ['task_packet_id' => 'speculative-high', 'value' => 9, 'dependency_count' => 0, 'is_unlocked_follow_up' => false, 'speculative' => true],
            ['task_packet_id' => 'followup-mid', 'value' => 7, 'dependency_count' => 1, 'is_unlocked_follow_up' => true, 'speculative' => false],
            ['task_packet_id' => 'speculative-low', 'value' => 3, 'dependency_count' => 0, 'is_unlocked_follow_up' => false, 'speculative' => true],
        ];
    }

    public function test_thin_worker_buffer_prioritizes_unlocked_high_value_follow_up(): void
    {
        $result = $this->replenisher()->prioritizeUnlockedFollowUps($this->candidates(), [
            'claimable_per_active_worker' => 2,
        ]);

        $this->assertTrue($result['worker_feed_thin']);
        $this->assertSame('followup-mid', $result['ordered'][0]['task_packet_id']);
    }

    public function test_comfortable_buffer_preserves_existing_value_ordering(): void
    {
        $result = $this->replenisher()->prioritizeUnlockedFollowUps($this->candidates(), [
            'claimable_per_active_worker' => 20,
        ]);

        $this->assertFalse($result['worker_feed_thin']);
        $ids = array_column($result['ordered'], 'task_packet_id');
        $this->assertSame(['speculative-high', 'followup-mid', 'speculative-low'], $ids);
    }

    public function test_no_worker_floor_facts_preserves_existing_value_ordering(): void
    {
        $result = $this->replenisher()->prioritizeUnlockedFollowUps($this->candidates());

        $this->assertFalse($result['worker_feed_thin']);
        $ids = array_column($result['ordered'], 'task_packet_id');
        $this->assertSame(['speculative-high', 'followup-mid', 'speculative-low'], $ids);
    }

    public function test_thin_buffer_with_no_high_value_follow_up_falls_back_to_value_ordering(): void
    {
        $candidates = [
            ['task_packet_id' => 'spec-high', 'value' => 9, 'dependency_count' => 0, 'is_unlocked_follow_up' => false],
            ['task_packet_id' => 'low-value-followup', 'value' => 2, 'dependency_count' => 1, 'is_unlocked_follow_up' => true],
        ];

        $result = $this->replenisher()->prioritizeUnlockedFollowUps($candidates, ['claimable_per_active_worker' => 1]);

        $this->assertSame('spec-high', $result['ordered'][0]['task_packet_id']);
    }

    // ── decideReplenishmentAction (AC) ───────────────────────────────────────

    public function test_sufficient_depth_with_no_urgent_signal_emits_no_op(): void
    {
        $result = $this->replenisher()->decideReplenishmentAction([
            'recommendation' => 'sufficient_depth',
        ]);

        $this->assertSame('no_op', $result['action']);
        $this->assertSame('sufficient_depth', $result['reason']);
    }

    public function test_low_claimable_per_active_worker_emits_task_fabric_top_up(): void
    {
        $result = $this->replenisher()->decideReplenishmentAction([
            'claimable_per_active_worker' => 1.0,
        ]);

        $this->assertSame('task_fabric_top_up', $result['action']);
        $this->assertSame('worker_floor_low', $result['reason']);
    }

    public function test_low_worker_floor_overrides_sufficient_depth_recommendation(): void
    {
        $result = $this->replenisher()->decideReplenishmentAction([
            'recommendation' => 'sufficient_depth',
            'claimable_per_active_worker' => 1.0,
        ]);

        $this->assertSame('task_fabric_top_up', $result['action']);
    }

    public function test_sufficient_depth_with_urgent_repair_signal_does_not_no_op(): void
    {
        $result = $this->replenisher()->decideReplenishmentAction([
            'recommendation' => 'sufficient_depth',
            'urgent_repair_signal' => true,
        ]);

        $this->assertNotSame('no_op', $result['action']);
    }

    public function test_comfortable_floor_without_sufficient_depth_recommendation_emits_replenish(): void
    {
        $result = $this->replenisher()->decideReplenishmentAction([
            'recommendation' => 'thin_queue',
            'claimable_per_active_worker' => 10.0,
        ]);

        $this->assertSame('replenish', $result['action']);
    }
}
