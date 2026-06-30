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
}
