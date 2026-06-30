<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainHealthScoreDeltaExplainer;
use Tests\TestCase;

final class AtlasExternalBrainHealthScoreDeltaExplainerTest extends TestCase
{
    private function svc(): AtlasExternalBrainHealthScoreDeltaExplainer
    {
        return new AtlasExternalBrainHealthScoreDeltaExplainer;
    }

    private function recFor(array $result, string $gap): ?array
    {
        foreach ($result['ranked_recommendations'] as $r) {
            if ($r['gap'] === $gap) {
                return $r;
            }
        }

        return null;
    }

    // ── individual gaps ───────────────────────────────────────────────────────

    public function test_gate_holes_produces_recommendation(): void
    {
        $r = $this->svc()->explain(['gate_holes' => 2, 'entropy' => 1.0, 'trend_data_points' => 5]);

        $rec = $this->recFor($r, 'gate_holes');
        $this->assertNotNull($rec);
        $this->assertEqualsWithDelta(10.0, $rec['expected_score_lift'], 0.01);
        $this->assertContains('gate-impl', $rec['task_families']);
        $this->assertContains('gate_pass_rate_increases', $rec['falsification_evidence']);
    }

    public function test_gate_lift_scales_with_count(): void
    {
        $lift5 = AtlasExternalBrainHealthScoreDeltaExplainer::LIFT_PER_GATE;
        $r = $this->svc()->explain(['gate_holes' => 4, 'entropy' => 1.0, 'trend_data_points' => 5]);

        $rec = $this->recFor($r, 'gate_holes');
        $this->assertEqualsWithDelta(4 * $lift5, $rec['expected_score_lift'], 0.01);
    }

    public function test_zero_entropy_produces_recommendation(): void
    {
        $r = $this->svc()->explain(['gate_holes' => 0, 'entropy' => 0.0, 'trend_data_points' => 5]);

        $rec = $this->recFor($r, 'zero_entropy');
        $this->assertNotNull($rec);
        $this->assertEqualsWithDelta(AtlasExternalBrainHealthScoreDeltaExplainer::ZERO_ENTROPY_LIFT, $rec['expected_score_lift'], 0.01);
        $this->assertContains('discovery', $rec['task_families']);
        $this->assertContains('new_capability_ids_appear', $rec['falsification_evidence']);
    }

    public function test_insufficient_trend_data_produces_recommendation(): void
    {
        $r = $this->svc()->explain(['gate_holes' => 0, 'entropy' => 1.0, 'trend_data_points' => 1]);

        $rec = $this->recFor($r, 'insufficient_trend_data');
        $this->assertNotNull($rec);
        $this->assertEqualsWithDelta(AtlasExternalBrainHealthScoreDeltaExplainer::TREND_MIN_LIFT, $rec['expected_score_lift'], 0.01);
        $this->assertContains('trend-measurement', $rec['task_families']);
        $this->assertContains('trend_data_points_reaches_3', $rec['falsification_evidence']);
    }

    // ── ranking & totals ──────────────────────────────────────────────────────

    public function test_ranked_descending_by_score_lift(): void
    {
        // zero_entropy(15) > insufficient_trend_data(10) > gate_holes(1×5=5)
        $r = $this->svc()->explain(['gate_holes' => 1, 'entropy' => 0.0, 'trend_data_points' => 0]);

        $lifts = array_column($r['ranked_recommendations'], 'expected_score_lift');
        $sorted = $lifts;
        rsort($sorted);
        $this->assertSame($sorted, $lifts);
    }

    public function test_all_three_gaps_detected(): void
    {
        $r = $this->svc()->explain(['gate_holes' => 1, 'entropy' => 0.0, 'trend_data_points' => 0]);

        $this->assertCount(3, $r['ranked_recommendations']);
        $gaps = array_column($r['ranked_recommendations'], 'gap');
        $this->assertContains('gate_holes', $gaps);
        $this->assertContains('zero_entropy', $gaps);
        $this->assertContains('insufficient_trend_data', $gaps);
    }

    public function test_total_expected_lift_is_sum(): void
    {
        $r = $this->svc()->explain(['gate_holes' => 2, 'entropy' => 0.0, 'trend_data_points' => 1]);

        $expected = round(
            2 * AtlasExternalBrainHealthScoreDeltaExplainer::LIFT_PER_GATE
            + AtlasExternalBrainHealthScoreDeltaExplainer::ZERO_ENTROPY_LIFT
            + AtlasExternalBrainHealthScoreDeltaExplainer::TREND_MIN_LIFT,
            2,
        );
        $this->assertEqualsWithDelta($expected, $r['total_expected_lift'], 0.01);
    }

    // ── no gaps ───────────────────────────────────────────────────────────────

    public function test_no_gaps_produces_empty_recommendations(): void
    {
        $r = $this->svc()->explain(['gate_holes' => 0, 'entropy' => 1.0, 'trend_data_points' => 5]);

        $this->assertSame([], $r['ranked_recommendations']);
        $this->assertEqualsWithDelta(0.0, $r['total_expected_lift'], 0.01);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->explain([]);

        $this->assertSame(AtlasExternalBrainHealthScoreDeltaExplainer::SCHEMA, $r['schema_version']);
    }
}
