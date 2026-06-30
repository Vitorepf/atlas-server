<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPostCommitWaveResequencer;
use Tests\TestCase;

final class AtlasExternalBrainPostCommitWaveResequencerTest extends TestCase
{
    private function resequencer(): AtlasExternalBrainPostCommitWaveResequencer
    {
        return new AtlasExternalBrainPostCommitWaveResequencer;
    }

    public function test_accepts_previous_wave_outcomes_changed_capabilities_and_stale_findings(): void
    {
        $result = $this->resequencer()->resequence([
            'previous_wave' => ['task_ids' => ['a', 'b']],
            'queue_tasks' => [
                ['task_id' => 'a'],
                ['task_id' => 'b'],
            ],
            'stale_dependency_findings' => ['stale_task_ids' => []],
        ]);

        foreach (['resequenced_task_ids', 'removed_stale_task_ids', 'newly_unblocked_task_ids', 'resequence_reasons'] as $field) {
            $this->assertArrayHasKey($field, $result, "missing field: {$field}");
        }
        $this->assertFalse($result['mutates_queue']);
    }

    public function test_newly_unblocked_critical_path_task_moves_to_front(): void
    {
        $result = $this->resequencer()->resequence([
            'previous_wave' => ['task_ids' => ['a', 'b', 'c']],
            'queue_tasks' => [
                ['task_id' => 'a'],
                ['task_id' => 'b'],
                ['task_id' => 'c', 'unblocked_by_latest_commit' => true, 'is_critical_path' => true],
            ],
        ]);

        $this->assertSame('c', $result['resequenced_task_ids'][0]);
        $this->assertContains('c', $result['newly_unblocked_task_ids']);
    }

    public function test_task_superseded_by_commit_is_removed(): void
    {
        $result = $this->resequencer()->resequence([
            'previous_wave' => ['task_ids' => ['a', 'b']],
            'queue_tasks' => [
                ['task_id' => 'a', 'superseded_by_commit' => true],
                ['task_id' => 'b'],
            ],
        ]);

        $this->assertContains('a', $result['removed_stale_task_ids']);
        $this->assertNotContains('a', $result['resequenced_task_ids']);
        $this->assertContains('b', $result['resequenced_task_ids']);
    }

    public function test_task_with_repeated_give_back_is_removed(): void
    {
        $result = $this->resequencer()->resequence([
            'previous_wave' => ['task_ids' => ['a', 'b']],
            'queue_tasks' => [
                ['task_id' => 'a', 'give_back_repeat_count' => 3],
                ['task_id' => 'b'],
            ],
            'give_back_repeat_threshold' => 2,
        ]);

        $this->assertContains('a', $result['removed_stale_task_ids']);
        $reasons = implode(' ', $result['resequence_reasons']);
        $this->assertStringContainsString('removed_repeated_give_back:a', $reasons);
    }

    public function test_task_flagged_stale_by_dependency_findings_is_removed(): void
    {
        $result = $this->resequencer()->resequence([
            'previous_wave' => ['task_ids' => ['a', 'b']],
            'queue_tasks' => [
                ['task_id' => 'a'],
                ['task_id' => 'b'],
            ],
            'stale_dependency_findings' => ['stale_task_ids' => ['a']],
        ]);

        $this->assertContains('a', $result['removed_stale_task_ids']);
        $this->assertNotContains('a', $result['resequenced_task_ids']);
    }

    public function test_non_critical_unblocked_task_is_prioritized_after_critical_path_ones(): void
    {
        $result = $this->resequencer()->resequence([
            'previous_wave' => ['task_ids' => ['a', 'b', 'c']],
            'queue_tasks' => [
                ['task_id' => 'a', 'unblocked_by_latest_commit' => true, 'is_critical_path' => false],
                ['task_id' => 'b', 'unblocked_by_latest_commit' => true, 'is_critical_path' => true],
                ['task_id' => 'c'],
            ],
        ]);

        $this->assertSame(['b', 'a', 'c'], $result['resequenced_task_ids']);
    }

    public function test_stable_remainder_preserves_previous_wave_order(): void
    {
        $result = $this->resequencer()->resequence([
            'previous_wave' => ['task_ids' => ['x', 'y', 'z']],
            'queue_tasks' => [
                ['task_id' => 'x'],
                ['task_id' => 'y'],
                ['task_id' => 'z'],
            ],
        ]);

        $this->assertSame(['x', 'y', 'z'], $result['resequenced_task_ids']);
    }
}
