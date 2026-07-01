<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationCandidateScorecard;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSimplificationCandidateScorecardTest extends TestCase
{
    private function scorecard(): AtlasSelfConstructionSimplificationCandidateScorecard
    {
        return new AtlasSelfConstructionSimplificationCandidateScorecard;
    }

    public function test_ranking_order_across_high_leverage_risky_and_cosmetic_candidates(): void
    {
        $result = $this->scorecard()->score([
            'candidates' => [
                [
                    'candidate_id' => 'cosmetic',
                    'line_reduction' => 400,
                    'rename_or_wrap_only' => true,
                    'duplication_collapse_score' => 0.0,
                    'consumer_risk' => 0.0,
                ],
                [
                    'candidate_id' => 'risky',
                    'line_reduction' => 100,
                    'duplication_collapse_score' => 0.6,
                    'consumer_risk' => 0.9,
                    'proof_readiness' => 0.3,
                    'autonomy_gain' => 0.2,
                    'rollback_readiness' => 0.2,
                ],
                [
                    'candidate_id' => 'high_leverage',
                    'line_reduction' => 100,
                    'duplication_collapse_score' => 0.9,
                    'consumer_risk' => 0.1,
                    'proof_readiness' => 0.9,
                    'autonomy_gain' => 0.9,
                    'rollback_readiness' => 0.9,
                ],
            ],
        ]);

        $this->assertSame(['high_leverage', 'risky', 'cosmetic'], $result['recommended_order']);
    }

    public function test_rename_or_wrap_only_candidate_is_disqualified(): void
    {
        $result = $this->scorecard()->score([
            'candidates' => [
                [
                    'candidate_id' => 'cosmetic',
                    'line_reduction' => 999,
                    'rename_or_wrap_only' => true,
                ],
            ],
        ]);

        $row = $result['scores'][0];
        $this->assertContains('cosmetic_rename_or_wrap_no_real_reduction', $row['disqualifiers']);
        $this->assertSame(0.0, $row['total_score']);
    }

    public function test_rename_or_wrap_only_is_not_disqualified_when_it_also_reduces_duplication(): void
    {
        $result = $this->scorecard()->score([
            'candidates' => [
                [
                    'candidate_id' => 'real_wrap',
                    'rename_or_wrap_only' => true,
                    'duplication_collapse_score' => 0.5,
                ],
            ],
        ]);

        $this->assertSame([], $result['scores'][0]['disqualifiers']);
    }

    public function test_every_candidate_row_exposes_required_keys(): void
    {
        $result = $this->scorecard()->score([
            'candidates' => [
                ['candidate_id' => 'a'],
                ['candidate_id' => 'b', 'rename_or_wrap_only' => true],
            ],
        ]);

        foreach ($result['scores'] as $row) {
            foreach (['candidate_id', 'total_score', 'component_scores', 'disqualifiers', 'rationale'] as $key) {
                $this->assertArrayHasKey($key, $row);
            }
        }
        $this->assertArrayHasKey('recommended_order', $result);
        $this->assertArrayHasKey('schema', $result);
    }

    public function test_candidates_missing_candidate_id_are_skipped(): void
    {
        $result = $this->scorecard()->score([
            'candidates' => [
                ['line_reduction' => 100],
                ['candidate_id' => 'valid'],
            ],
        ]);

        $this->assertCount(1, $result['scores']);
        $this->assertSame(['valid'], $result['recommended_order']);
    }

    public function test_proven_deletion_candidate_outranks_additive_cleanup_candidate(): void
    {
        $result = $this->scorecard()->score([
            'candidates' => [
                [
                    'candidate_id' => 'additive_cleanup',
                    'removable_lines' => 20,
                    'duplicate_surface' => 0.1,
                    'proof_coverage' => 0.2,
                    'rollback_ready' => false,
                    'behavior_parity' => false,
                    'autonomy_gain' => 0.1,
                ],
                [
                    'candidate_id' => 'proven_deletion',
                    'removable_lines' => 300,
                    'duplicate_surface' => 0.9,
                    'proof_coverage' => 0.9,
                    'rollback_ready' => true,
                    'behavior_parity' => true,
                    'consumer_count' => 1,
                    'autonomy_gain' => 0.5,
                ],
            ],
        ]);

        $this->assertSame(['proven_deletion', 'additive_cleanup'], $result['recommended_order']);
    }

    public function test_high_consumer_count_without_behavior_parity_or_rollback_ready_lowers_score(): void
    {
        $unproven = $this->scorecard()->score([
            'candidates' => [[
                'candidate_id' => 'unproven',
                'removable_lines' => 400,
                'duplicate_surface' => 0.9,
                'proof_coverage' => 0.9,
                'consumer_count' => 10,
                'rollback_ready' => false,
                'behavior_parity' => false,
            ]],
        ])['scores'][0];

        $proven = $this->scorecard()->score([
            'candidates' => [[
                'candidate_id' => 'proven',
                'removable_lines' => 400,
                'duplicate_surface' => 0.9,
                'proof_coverage' => 0.9,
                'consumer_count' => 10,
                'rollback_ready' => true,
                'behavior_parity' => true,
            ]],
        ])['scores'][0];

        $this->assertArrayHasKey('unproven_high_consumer_count_penalty', $unproven['component_scores']);
        $this->assertLessThan($proven['total_score'], $unproven['total_score']);
    }

    public function test_score_explanation_lists_the_factors_used(): void
    {
        $result = $this->scorecard()->score([
            'candidates' => [[
                'candidate_id' => 'x',
                'removable_lines' => 50,
                'consumer_count' => 8,
                'rollback_ready' => false,
                'behavior_parity' => false,
            ]],
        ]);

        $rationale = $result['scores'][0]['rationale'];
        $this->assertStringContainsString('factors=[', $rationale);
        $this->assertStringContainsString('unproven_high_consumer_count_penalty', $rationale);
    }

    // ── deletion_roi / duplication / consumer_risk / proof_cost / autonomy_gain / recommendation (AC) ──

    public function test_every_row_exposes_new_scorecard_dimensions(): void
    {
        $result = $this->scorecard()->score([
            'candidates' => [['candidate_id' => 'a', 'removable_lines' => 100, 'duplicate_surface' => 0.5]],
        ]);
        $row = $result['scores'][0];

        foreach (['deletion_roi', 'duplication', 'consumer_risk', 'proof_cost', 'autonomy_gain', 'recommendation'] as $key) {
            $this->assertArrayHasKey($key, $row, "missing {$key}");
        }
    }

    public function test_high_roi_deletion_candidate_is_recommended_delete_now(): void
    {
        $row = $this->scorecard()->score([
            'candidates' => [[
                'candidate_id' => 'clean-delete',
                'removable_lines' => 400,
                'duplicate_surface' => 0.9,
                'proof_coverage' => 0.9,
                'rollback_ready' => true,
                'behavior_parity' => true,
                'consumer_count' => 1,
                'autonomy_gain' => 0.5,
                'consumer_risk' => 0.05,
            ]],
        ])['scores'][0];

        $this->assertSame('delete_now', $row['recommendation']);
        $this->assertGreaterThan(0.3, $row['deletion_roi']);
        $this->assertLessThan(0.2, $row['proof_cost']);
    }

    public function test_risky_refactor_candidate_is_recommended_refactor_with_caution(): void
    {
        $row = $this->scorecard()->score([
            'candidates' => [[
                'candidate_id' => 'risky',
                'removable_lines' => 100,
                'duplicate_surface' => 0.6,
                'consumer_risk' => 0.9,
                'proof_coverage' => 0.3,
                'autonomy_gain' => 0.2,
            ]],
        ])['scores'][0];

        $this->assertSame('refactor_with_caution', $row['recommendation']);
    }

    public function test_low_value_cleanup_candidate_is_recommended_defer(): void
    {
        $row = $this->scorecard()->score([
            'candidates' => [[
                'candidate_id' => 'low-value',
                'removable_lines' => 5,
                'duplicate_surface' => 0.05,
                'consumer_risk' => 0.1,
                'proof_coverage' => 0.1,
                'autonomy_gain' => 0.0,
            ]],
        ])['scores'][0];

        $this->assertSame('defer_low_value', $row['recommendation']);
    }

    public function test_cosmetic_disqualified_candidate_is_recommended_reject(): void
    {
        $row = $this->scorecard()->score([
            'candidates' => [['candidate_id' => 'cosmetic', 'line_reduction' => 999, 'rename_or_wrap_only' => true]],
        ])['scores'][0];

        $this->assertSame('reject_cosmetic', $row['recommendation']);
        $this->assertSame(0.0, $row['deletion_roi']);
    }

    public function test_deterministic_tie_break_by_candidate_id_when_scores_are_equal(): void
    {
        $candidate = fn (string $id) => [
            'candidate_id' => $id,
            'removable_lines' => 100,
            'duplicate_surface' => 0.5,
            'consumer_risk' => 0.1,
            'proof_coverage' => 0.5,
            'autonomy_gain' => 0.5,
        ];

        $result = $this->scorecard()->score(['candidates' => [$candidate('zeta'), $candidate('alpha'), $candidate('mu')]]);

        $this->assertSame(['alpha', 'mu', 'zeta'], $result['recommended_order']);
    }
}
