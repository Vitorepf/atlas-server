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
}
