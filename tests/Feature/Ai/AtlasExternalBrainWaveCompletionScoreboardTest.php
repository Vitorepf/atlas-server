<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainWaveCompletionScoreboard;
use Tests\TestCase;

final class AtlasExternalBrainWaveCompletionScoreboardTest extends TestCase
{
    private function scoreboard(): AtlasExternalBrainWaveCompletionScoreboard
    {
        return new AtlasExternalBrainWaveCompletionScoreboard;
    }

    public function test_returns_all_required_fields(): void
    {
        $result = $this->scoreboard()->score(['tasks' => [
            ['task_id' => 'a', 'self_reported_status' => 'completed', 'commit_hash' => 'abc123', 'tests_passed' => true],
        ]]);

        foreach (['completed_count', 'green_commit_count', 'give_back_count', 'repair_count', 'unlock_count', 'wasted_task_count', 'wave_value_score'] as $field) {
            $this->assertArrayHasKey($field, $result, "missing field: {$field}");
        }
    }

    public function test_verified_green_commit_counts_separately_from_self_reported_completion(): void
    {
        $result = $this->scoreboard()->score(['tasks' => [
            ['task_id' => 'a', 'self_reported_status' => 'completed', 'commit_hash' => 'abc123', 'tests_passed' => true],
            ['task_id' => 'b', 'self_reported_status' => 'completed', 'commit_hash' => '', 'tests_passed' => null],
        ]]);

        $this->assertSame(2, $result['completed_count']);
        $this->assertSame(1, $result['green_commit_count']);
        $this->assertSame(1, $result['wasted_task_count']);
    }

    public function test_self_reported_completion_without_commit_or_passing_tests_is_wasted(): void
    {
        $result = $this->scoreboard()->score(['tasks' => [
            ['task_id' => 'a', 'self_reported_status' => 'completed', 'commit_hash' => 'abc', 'tests_passed' => false],
        ]]);

        $this->assertSame(1, $result['wasted_task_count']);
        $this->assertSame(0, $result['green_commit_count']);
    }

    public function test_give_back_and_repair_counts_are_tracked(): void
    {
        $result = $this->scoreboard()->score(['tasks' => [
            ['task_id' => 'a', 'self_reported_status' => 'give_back', 'give_back_reason' => 'scope_violation'],
            ['task_id' => 'b', 'self_reported_status' => 'repair'],
        ]]);

        $this->assertSame(1, $result['give_back_count']);
        $this->assertSame(1, $result['repair_count']);
    }

    public function test_unlock_count_counts_unique_unlocked_task_ids(): void
    {
        $result = $this->scoreboard()->score(['tasks' => [
            ['task_id' => 'a', 'self_reported_status' => 'completed', 'commit_hash' => 'abc', 'tests_passed' => true, 'unlocks_task_ids' => ['x', 'y']],
            ['task_id' => 'b', 'self_reported_status' => 'completed', 'commit_hash' => 'def', 'tests_passed' => true, 'unlocks_task_ids' => ['y', 'z']],
        ]]);

        $this->assertSame(3, $result['unlock_count']);
    }

    public function test_repeated_give_back_root_cause_is_flagged_and_downgrades_score(): void
    {
        $withoutRepeat = $this->scoreboard()->score(['tasks' => [
            ['task_id' => 'a', 'self_reported_status' => 'completed', 'commit_hash' => 'abc', 'tests_passed' => true],
            ['task_id' => 'b', 'self_reported_status' => 'give_back', 'give_back_reason' => 'scope_violation'],
        ]]);
        $withRepeat = $this->scoreboard()->score(['tasks' => [
            ['task_id' => 'a', 'self_reported_status' => 'completed', 'commit_hash' => 'abc', 'tests_passed' => true],
            ['task_id' => 'b', 'self_reported_status' => 'give_back', 'give_back_reason' => 'scope_violation'],
            ['task_id' => 'c', 'self_reported_status' => 'give_back', 'give_back_reason' => 'scope_violation'],
        ]]);

        $this->assertSame([], $withoutRepeat['repeated_give_back_root_causes']);
        $this->assertContains('scope_violation', $withRepeat['repeated_give_back_root_causes']);
        $this->assertLessThan($withoutRepeat['wave_value_score'], $withRepeat['wave_value_score']);
    }

    public function test_empty_wave_scores_zero(): void
    {
        $result = $this->scoreboard()->score(['tasks' => []]);

        $this->assertSame(0, $result['task_count']);
        $this->assertSame(0.0, $result['wave_value_score']);
    }

    public function test_fully_proven_wave_scores_higher_than_wasted_wave(): void
    {
        $proven = $this->scoreboard()->score(['tasks' => [
            ['task_id' => 'a', 'self_reported_status' => 'completed', 'commit_hash' => 'abc', 'tests_passed' => true],
            ['task_id' => 'b', 'self_reported_status' => 'completed', 'commit_hash' => 'def', 'tests_passed' => true],
        ]]);
        $wasted = $this->scoreboard()->score(['tasks' => [
            ['task_id' => 'a', 'self_reported_status' => 'completed', 'commit_hash' => '', 'tests_passed' => null],
            ['task_id' => 'b', 'self_reported_status' => 'completed', 'commit_hash' => '', 'tests_passed' => null],
        ]]);

        $this->assertGreaterThan($wasted['wave_value_score'], $proven['wave_value_score']);
    }
}
