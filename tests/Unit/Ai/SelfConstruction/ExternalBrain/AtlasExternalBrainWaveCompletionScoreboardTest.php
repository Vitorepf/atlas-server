<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainWaveCompletionScoreboard;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainWaveCompletionScoreboardTest extends TestCase
{
    private function scoreboard(): AtlasExternalBrainWaveCompletionScoreboard
    {
        return new AtlasExternalBrainWaveCompletionScoreboard;
    }

    public function test_green_commit_count_requires_commit_hash_and_tests_passed(): void
    {
        $result = $this->scoreboard()->score([
            'tasks' => [
                ['self_reported_status' => 'completed', 'commit_hash' => 'abc123', 'tests_passed' => true],
                ['self_reported_status' => 'completed', 'commit_hash' => 'def456', 'tests_passed' => false],
                ['self_reported_status' => 'completed', 'tests_passed' => true],
                ['self_reported_status' => 'completed', 'commit_hash' => '', 'tests_passed' => true],
            ],
        ]);

        $this->assertSame(1, $result['green_commit_count']);
    }

    public function test_self_reported_completed_without_proof_is_wasted(): void
    {
        $result = $this->scoreboard()->score([
            'tasks' => [
                ['self_reported_status' => 'completed', 'commit_hash' => 'abc123', 'tests_passed' => true],
                ['self_reported_status' => 'completed'],
            ],
        ]);

        $this->assertSame(1, $result['wasted_task_count']);
    }

    public function test_repeated_give_back_root_causes_are_surfaced(): void
    {
        $result = $this->scoreboard()->score([
            'tasks' => [
                ['self_reported_status' => 'give_back', 'give_back_reason' => 'flaky_test'],
                ['self_reported_status' => 'give_back', 'give_back_reason' => 'flaky_test'],
                ['self_reported_status' => 'give_back', 'give_back_reason' => 'scope_gap'],
            ],
        ]);

        $this->assertContains('flaky_test', $result['repeated_give_back_root_causes']);
        $this->assertNotContains('scope_gap', $result['repeated_give_back_root_causes']);
    }

    public function test_repeated_give_back_causes_penalize_wave_value_score(): void
    {
        $clean = $this->scoreboard()->score([
            'tasks' => [
                ['self_reported_status' => 'completed', 'commit_hash' => 'a', 'tests_passed' => true],
            ],
        ]);

        $withRepeatedGiveBack = $this->scoreboard()->score([
            'tasks' => [
                ['self_reported_status' => 'completed', 'commit_hash' => 'a', 'tests_passed' => true],
                ['self_reported_status' => 'give_back', 'give_back_reason' => 'flaky_test'],
                ['self_reported_status' => 'give_back', 'give_back_reason' => 'flaky_test'],
            ],
        ]);

        $this->assertLessThan($clean['wave_value_score'], $withRepeatedGiveBack['wave_value_score']);
    }

    public function test_wasted_tasks_penalize_wave_value_score(): void
    {
        $withoutWaste = $this->scoreboard()->score([
            'tasks' => [
                ['self_reported_status' => 'completed', 'commit_hash' => 'a', 'tests_passed' => true],
                ['self_reported_status' => 'completed', 'commit_hash' => 'b', 'tests_passed' => true],
            ],
        ]);

        $withWaste = $this->scoreboard()->score([
            'tasks' => [
                ['self_reported_status' => 'completed', 'commit_hash' => 'a', 'tests_passed' => true],
                ['self_reported_status' => 'completed'],
            ],
        ]);

        $this->assertLessThan($withoutWaste['wave_value_score'], $withWaste['wave_value_score']);
    }

    public function test_downstream_unlocks_increase_wave_value_score(): void
    {
        $withoutUnlocks = $this->scoreboard()->score([
            'tasks' => [
                ['self_reported_status' => 'completed', 'commit_hash' => 'a', 'tests_passed' => true],
            ],
        ]);

        $withUnlocks = $this->scoreboard()->score([
            'tasks' => [
                ['self_reported_status' => 'completed', 'commit_hash' => 'a', 'tests_passed' => true, 'unlocks_task_ids' => ['t2', 't3']],
            ],
        ]);

        $this->assertGreaterThan($withoutUnlocks['wave_value_score'], $withUnlocks['wave_value_score']);
    }

    public function test_output_includes_required_keys(): void
    {
        $result = $this->scoreboard()->score([
            'tasks' => [
                ['self_reported_status' => 'completed', 'commit_hash' => 'a', 'tests_passed' => true],
            ],
        ]);

        foreach (['wave_value_score', 'green_commit_count', 'wasted_task_count', 'repeated_give_back_root_causes'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    public function test_empty_wave_is_safe_and_scores_zero(): void
    {
        $result = $this->scoreboard()->score(['tasks' => []]);

        $this->assertSame(0, $result['task_count']);
        $this->assertSame(0, $result['green_commit_count']);
        $this->assertSame(0.0, $result['wave_value_score']);
        $this->assertSame([], $result['repeated_give_back_root_causes']);
    }

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'tasks' => [
                ['self_reported_status' => 'completed', 'commit_hash' => 'a', 'tests_passed' => true],
                ['self_reported_status' => 'give_back', 'give_back_reason' => 'x'],
            ],
        ];

        $this->assertSame(
            $this->scoreboard()->score($facts),
            $this->scoreboard()->score($facts),
        );
    }
}
