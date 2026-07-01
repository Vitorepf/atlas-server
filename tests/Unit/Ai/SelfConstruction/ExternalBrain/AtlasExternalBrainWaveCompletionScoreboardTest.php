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

    // ── AC2: raw completed task count alone does not produce a high wave score ──

    public function test_many_completed_tasks_with_no_proof_scores_zero(): void
    {
        $result = $this->scoreboard()->score([
            'tasks' => array_fill(0, 20, ['self_reported_status' => 'completed']),
        ]);

        $this->assertSame(0.0, $result['wave_value_score']);
        $this->assertSame(20, $result['completed_count']);
        $this->assertSame(0, $result['green_commit_count']);
    }

    public function test_fewer_verified_tasks_outscores_more_unverified_completions(): void
    {
        $manyUnverified = $this->scoreboard()->score([
            'tasks' => array_fill(0, 10, ['self_reported_status' => 'completed']),
        ]);
        $oneVerified = $this->scoreboard()->score([
            'tasks' => [['self_reported_status' => 'completed', 'commit_hash' => 'a', 'tests_passed' => true]],
        ]);

        $this->assertGreaterThan($manyUnverified['wave_value_score'], $oneVerified['wave_value_score']);
    }

    // ── AC3: capability delta + strong proof + low give_back rate produce a high score ──

    public function test_capability_delta_and_proof_strength_increase_wave_value_score(): void
    {
        $plainGreen = $this->scoreboard()->score([
            'tasks' => [['self_reported_status' => 'completed', 'commit_hash' => 'a', 'tests_passed' => true]],
        ]);
        $highCapabilityAndProof = $this->scoreboard()->score([
            'tasks' => [[
                'self_reported_status' => 'completed',
                'commit_hash' => 'a',
                'tests_passed' => true,
                'capability_delta' => 0.9,
                'proof_strength' => 0.9,
            ]],
        ]);

        $this->assertGreaterThan($plainGreen['wave_value_score'], $highCapabilityAndProof['wave_value_score']);
        $this->assertSame(0.9, $highCapabilityAndProof['capability_delta_avg']);
        $this->assertSame(0.9, $highCapabilityAndProof['proof_strength_avg']);
    }

    public function test_high_give_back_rate_lowers_wave_value_score_even_without_repeated_root_cause(): void
    {
        $lowGiveBack = $this->scoreboard()->score([
            'tasks' => [
                ['self_reported_status' => 'completed', 'commit_hash' => 'a', 'tests_passed' => true],
                ['self_reported_status' => 'completed', 'commit_hash' => 'b', 'tests_passed' => true],
                ['self_reported_status' => 'completed', 'commit_hash' => 'c', 'tests_passed' => true],
                ['self_reported_status' => 'completed', 'commit_hash' => 'd', 'tests_passed' => true],
                ['self_reported_status' => 'give_back', 'give_back_reason' => 'unique_a'],
            ],
        ]);
        $highGiveBack = $this->scoreboard()->score([
            'tasks' => [
                ['self_reported_status' => 'completed', 'commit_hash' => 'a', 'tests_passed' => true],
                ['self_reported_status' => 'give_back', 'give_back_reason' => 'unique_a'],
                ['self_reported_status' => 'give_back', 'give_back_reason' => 'unique_b'],
                ['self_reported_status' => 'give_back', 'give_back_reason' => 'unique_c'],
            ],
        ]);

        $this->assertGreaterThan($lowGiveBack['give_back_rate'], $highGiveBack['give_back_rate']);
        $this->assertGreaterThan($highGiveBack['wave_value_score'], $lowGiveBack['wave_value_score']);
    }

    public function test_give_back_rate_field_reflects_ratio_of_give_backs_to_tasks(): void
    {
        $result = $this->scoreboard()->score([
            'tasks' => [
                ['self_reported_status' => 'completed', 'commit_hash' => 'a', 'tests_passed' => true],
                ['self_reported_status' => 'give_back', 'give_back_reason' => 'x'],
            ],
        ]);

        $this->assertEqualsWithDelta(0.5, $result['give_back_rate'], 0.001);
    }

    // ── AC4: next_gap and next_wave_hint for the originator ────────────────────

    public function test_output_includes_next_gap_and_next_wave_hint(): void
    {
        $result = $this->scoreboard()->score([
            'tasks' => [['self_reported_status' => 'completed', 'commit_hash' => 'a', 'tests_passed' => true]],
        ]);

        $this->assertArrayHasKey('next_gap', $result);
        $this->assertArrayHasKey('next_wave_hint', $result);
        $this->assertIsString($result['next_gap']);
        $this->assertIsString($result['next_wave_hint']);
    }

    public function test_next_gap_names_repeated_give_back_root_cause_first(): void
    {
        $result = $this->scoreboard()->score([
            'tasks' => [
                ['self_reported_status' => 'completed', 'commit_hash' => 'a', 'tests_passed' => true, 'capability_delta' => 0.9, 'proof_strength' => 0.9],
                ['self_reported_status' => 'give_back', 'give_back_reason' => 'flaky_test'],
                ['self_reported_status' => 'give_back', 'give_back_reason' => 'flaky_test'],
            ],
        ]);

        $this->assertSame('repeated_give_back_root_causes', $result['next_gap']);
        $this->assertStringContainsString('flaky_test', $result['next_wave_hint']);
    }

    public function test_next_gap_names_no_verified_completions_when_no_green_commits(): void
    {
        $result = $this->scoreboard()->score([
            'tasks' => [['self_reported_status' => 'completed']],
        ]);

        $this->assertSame('no_verified_completions', $result['next_gap']);
    }

    public function test_next_gap_names_proof_strength_when_low(): void
    {
        $result = $this->scoreboard()->score([
            'tasks' => [[
                'self_reported_status' => 'completed',
                'commit_hash' => 'a',
                'tests_passed' => true,
                'proof_strength' => 0.2,
                'capability_delta' => 0.9,
            ]],
        ]);

        $this->assertSame('proof_strength', $result['next_gap']);
    }

    public function test_next_gap_names_capability_delta_when_low(): void
    {
        $result = $this->scoreboard()->score([
            'tasks' => [[
                'self_reported_status' => 'completed',
                'commit_hash' => 'a',
                'tests_passed' => true,
                'proof_strength' => 0.9,
                'capability_delta' => 0.1,
            ]],
        ]);

        $this->assertSame('capability_delta', $result['next_gap']);
    }

    public function test_next_gap_is_none_when_wave_is_fully_healthy(): void
    {
        $result = $this->scoreboard()->score([
            'tasks' => [[
                'self_reported_status' => 'completed',
                'commit_hash' => 'a',
                'tests_passed' => true,
                'proof_strength' => 0.9,
                'capability_delta' => 0.9,
            ]],
        ]);

        $this->assertSame('none', $result['next_gap']);
    }

    // ── simplification_impact contributes a small bonus and is surfaced ───────

    public function test_simplification_impact_is_summed_and_surfaced(): void
    {
        $result = $this->scoreboard()->score([
            'tasks' => [
                ['self_reported_status' => 'completed', 'commit_hash' => 'a', 'tests_passed' => true, 'simplification_impact' => 40.0],
                ['self_reported_status' => 'completed', 'commit_hash' => 'b', 'tests_passed' => true, 'simplification_impact' => 60.0],
            ],
        ]);

        $this->assertEqualsWithDelta(100.0, $result['simplification_impact_total'], 0.001);
    }
}
