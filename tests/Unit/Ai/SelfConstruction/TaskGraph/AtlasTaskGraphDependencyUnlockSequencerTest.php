<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskGraph;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasTaskGraphDependencyUnlockSequencer;
use Tests\TestCase;

final class AtlasTaskGraphDependencyUnlockSequencerTest extends TestCase
{
    private function sequencer(): AtlasTaskGraphDependencyUnlockSequencer
    {
        return new AtlasTaskGraphDependencyUnlockSequencer;
    }

    public function test_high_unlock_prerequisite_ranks_before_isolated_leaf_with_equal_risk(): void
    {
        $tasks = [
            ['task_packet_id' => 'isolated-leaf', 'depends_on' => [], 'risk' => 1],
            ['task_packet_id' => 'key-prereq', 'depends_on' => [], 'risk' => 1],
            ['task_packet_id' => 'chain-a', 'depends_on' => ['key-prereq'], 'risk' => 1],
            ['task_packet_id' => 'chain-b', 'depends_on' => ['key-prereq'], 'risk' => 1],
            ['task_packet_id' => 'chain-c', 'depends_on' => ['key-prereq'], 'risk' => 1],
        ];

        $result = $this->sequencer()->sequence($tasks);

        $this->assertFalse($result['cycle_detected']);
        $keyPos = array_search('key-prereq', $result['ordered_task_ids'], true);
        $leafPos = array_search('isolated-leaf', $result['ordered_task_ids'], true);
        $this->assertLessThan($leafPos, $keyPos, 'key-prereq unlocks 3 downstream tasks and must rank before the isolated leaf');
        $this->assertSame(3, $result['unlock_counts']['key-prereq']);
        $this->assertSame(0, $result['unlock_counts']['isolated-leaf']);
    }

    public function test_circular_dependency_is_rejected_with_cycle_path(): void
    {
        $tasks = [
            ['task_packet_id' => 'a', 'depends_on' => ['b']],
            ['task_packet_id' => 'b', 'depends_on' => ['c']],
            ['task_packet_id' => 'c', 'depends_on' => ['a']],
        ];

        $result = $this->sequencer()->sequence($tasks);

        $this->assertTrue($result['cycle_detected']);
        $this->assertNotEmpty($result['cycle_path']);
        $this->assertSame([], $result['ordered_task_ids']);
    }

    public function test_missing_prerequisite_is_reported_without_unsafe_sequence(): void
    {
        $tasks = [
            ['task_packet_id' => 'orphan-dep', 'depends_on' => ['does-not-exist']],
            ['task_packet_id' => 'safe-leaf', 'depends_on' => []],
        ];

        $result = $this->sequencer()->sequence($tasks);

        $this->assertFalse($result['cycle_detected']);
        $this->assertNotEmpty($result['blocked_reasons']);
        $reasons = array_column($result['blocked_reasons'], 'reason', 'task_packet_id');
        $this->assertSame('missing_prerequisite', $reasons['orphan-dep']);
        $this->assertNotContains('orphan-dep', $result['ordered_task_ids'], 'a task with a missing prerequisite must never appear in the safe sequence');
        $this->assertContains('safe-leaf', $result['ordered_task_ids']);
    }

    public function test_task_depending_on_a_blocked_task_is_also_excluded_from_the_sequence(): void
    {
        $tasks = [
            ['task_packet_id' => 'orphan-dep', 'depends_on' => ['does-not-exist']],
            ['task_packet_id' => 'downstream-of-orphan', 'depends_on' => ['orphan-dep']],
        ];

        $result = $this->sequencer()->sequence($tasks);

        $this->assertNotContains('orphan-dep', $result['ordered_task_ids']);
        $this->assertNotContains('downstream-of-orphan', $result['ordered_task_ids']);
        $reasons = array_column($result['blocked_reasons'], 'reason', 'task_packet_id');
        $this->assertSame('blocked_by_unsequenceable_prerequisite', $reasons['downstream-of-orphan']);
    }

    public function test_output_includes_all_four_required_fields(): void
    {
        $result = $this->sequencer()->sequence([
            ['task_packet_id' => 'solo', 'depends_on' => []],
        ]);

        $this->assertArrayHasKey('ordered_task_ids', $result);
        $this->assertArrayHasKey('unlock_counts', $result);
        $this->assertArrayHasKey('blocked_reasons', $result);
        $this->assertArrayHasKey('next_unlock_target', $result);
    }

    public function test_next_unlock_target_is_the_first_ordered_task(): void
    {
        $tasks = [
            ['task_packet_id' => 'leaf', 'depends_on' => [], 'risk' => 1],
            ['task_packet_id' => 'big-unlock', 'depends_on' => [], 'risk' => 1],
            ['task_packet_id' => 'follower', 'depends_on' => ['big-unlock'], 'risk' => 1],
        ];

        $result = $this->sequencer()->sequence($tasks);

        $this->assertSame('big-unlock', $result['next_unlock_target']);
        $this->assertSame($result['ordered_task_ids'][0], $result['next_unlock_target']);
    }

    public function test_lower_risk_breaks_ties_when_unlock_counts_are_equal(): void
    {
        $tasks = [
            ['task_packet_id' => 'risky', 'depends_on' => [], 'risk' => 9],
            ['task_packet_id' => 'safe', 'depends_on' => [], 'risk' => 1],
        ];

        $result = $this->sequencer()->sequence($tasks);

        $this->assertSame('safe', $result['ordered_task_ids'][0]);
    }

    public function test_empty_task_list_produces_empty_safe_output(): void
    {
        $result = $this->sequencer()->sequence([]);

        $this->assertFalse($result['cycle_detected']);
        $this->assertSame([], $result['ordered_task_ids']);
        $this->assertNull($result['next_unlock_target']);
    }

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $sequencer = $this->sequencer();
        $tasks = [
            ['task_packet_id' => 'key-prereq', 'depends_on' => [], 'risk' => 1],
            ['task_packet_id' => 'chain-a', 'depends_on' => ['key-prereq'], 'risk' => 1],
        ];

        $this->assertSame($sequencer->sequence($tasks), $sequencer->sequence($tasks));
    }
}
