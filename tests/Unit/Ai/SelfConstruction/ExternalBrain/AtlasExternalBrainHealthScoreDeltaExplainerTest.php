<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainHealthScoreDeltaExplainer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainHealthScoreDeltaExplainerTest extends TestCase
{
    private AtlasExternalBrainHealthScoreDeltaExplainer $svc;

    protected function setUp(): void
    {
        $this->svc = new AtlasExternalBrainHealthScoreDeltaExplainer;
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

    private function assertRecommendationShape(array $rec): void
    {
        foreach (['gap', 'expected_score_lift', 'task_families', 'owner_subsystem', 'risk_level', 'falsification_evidence', 'stop_condition'] as $k) {
            $this->assertArrayHasKey($k, $rec, "Missing field: {$k}");
        }
        $this->assertIsString($rec['owner_subsystem']);
        $this->assertNotEmpty($rec['owner_subsystem']);
        $this->assertContains($rec['risk_level'], ['low', 'medium', 'high']);
        $this->assertIsString($rec['stop_condition']);
        $this->assertNotEmpty($rec['stop_condition']);
    }

    // ── Gate holes ────────────────────────────────────────────────────────────

    public function test_gate_holes_produces_recommendation(): void
    {
        $r   = $this->svc->explain(['gate_holes' => 2, 'entropy' => 1.0, 'trend_data_points' => 5]);
        $rec = $this->recFor($r, 'gate_holes');

        $this->assertNotNull($rec);
        $this->assertEqualsWithDelta(10.0, $rec['expected_score_lift'], 0.01);
        $this->assertContains('gate-impl', $rec['task_families']);
        $this->assertContains('gate_pass_rate_increases', $rec['falsification_evidence']);
        $this->assertRecommendationShape($rec);
    }

    public function test_gate_lift_scales_with_count(): void
    {
        $r   = $this->svc->explain(['gate_holes' => 4, 'entropy' => 1.0, 'trend_data_points' => 5]);
        $rec = $this->recFor($r, 'gate_holes');

        $this->assertEqualsWithDelta(4 * AtlasExternalBrainHealthScoreDeltaExplainer::LIFT_PER_GATE, $rec['expected_score_lift'], 0.01);
    }

    // ── Zero entropy ─────────────────────────────────────────────────────────

    public function test_zero_entropy_produces_recommendation(): void
    {
        $r   = $this->svc->explain(['gate_holes' => 0, 'entropy' => 0.0, 'trend_data_points' => 5]);
        $rec = $this->recFor($r, 'zero_entropy');

        $this->assertNotNull($rec);
        $this->assertEqualsWithDelta(AtlasExternalBrainHealthScoreDeltaExplainer::ZERO_ENTROPY_LIFT, $rec['expected_score_lift'], 0.01);
        $this->assertContains('discovery', $rec['task_families']);
        $this->assertContains('new_capability_ids_appear', $rec['falsification_evidence']);
        $this->assertRecommendationShape($rec);
    }

    // ── Insufficient trend data ───────────────────────────────────────────────

    public function test_insufficient_trend_data_produces_recommendation(): void
    {
        $r   = $this->svc->explain(['gate_holes' => 0, 'entropy' => 1.0, 'trend_data_points' => 1]);
        $rec = $this->recFor($r, 'insufficient_trend_data');

        $this->assertNotNull($rec);
        $this->assertEqualsWithDelta(AtlasExternalBrainHealthScoreDeltaExplainer::TREND_MIN_LIFT, $rec['expected_score_lift'], 0.01);
        $this->assertContains('trend-measurement', $rec['task_families']);
        $this->assertContains('trend_data_points_reaches_3', $rec['falsification_evidence']);
        $this->assertRecommendationShape($rec);
    }

    // ── Stale evidence ────────────────────────────────────────────────────────

    public function test_stale_evidence_produces_recommendation(): void
    {
        $r   = $this->svc->explain(['evidence_age_days' => 10]);
        $rec = $this->recFor($r, 'stale_evidence');

        $this->assertNotNull($rec);
        $this->assertEqualsWithDelta(AtlasExternalBrainHealthScoreDeltaExplainer::STALE_EVIDENCE_LIFT, $rec['expected_score_lift'], 0.01);
        $this->assertContains('evidence-refresh', $rec['task_families']);
        $this->assertContains('evidence_age_drops_below_7_days', $rec['falsification_evidence']);
        $this->assertRecommendationShape($rec);
    }

    public function test_evidence_at_or_below_threshold_does_not_trigger(): void
    {
        $r = $this->svc->explain(['evidence_age_days' => 7]);

        $this->assertNull($this->recFor($r, 'stale_evidence'));
    }

    // ── High give-back rate ───────────────────────────────────────────────────

    public function test_high_give_back_rate_produces_recommendation(): void
    {
        $r   = $this->svc->explain(['give_back_rate' => 0.30]);
        $rec = $this->recFor($r, 'high_give_back_rate');

        $this->assertNotNull($rec);
        $this->assertEqualsWithDelta(AtlasExternalBrainHealthScoreDeltaExplainer::HIGH_GIVE_BACK_LIFT, $rec['expected_score_lift'], 0.01);
        $this->assertContains('task-routing', $rec['task_families']);
        $this->assertSame('high', $rec['risk_level']);
        $this->assertRecommendationShape($rec);
    }

    public function test_give_back_rate_at_threshold_does_not_trigger(): void
    {
        $r = $this->svc->explain(['give_back_rate' => 0.25]);

        $this->assertNull($this->recFor($r, 'high_give_back_rate'));
    }

    // ── Poison rate ───────────────────────────────────────────────────────────

    public function test_high_poison_rate_produces_recommendation(): void
    {
        $r   = $this->svc->explain(['poison_rate' => 0.15]);
        $rec = $this->recFor($r, 'poison_rate');

        $this->assertNotNull($rec);
        $this->assertEqualsWithDelta(AtlasExternalBrainHealthScoreDeltaExplainer::POISON_RATE_LIFT, $rec['expected_score_lift'], 0.01);
        $this->assertContains('quality-gate', $rec['task_families']);
        $this->assertSame('high', $rec['risk_level']);
        $this->assertRecommendationShape($rec);
    }

    public function test_poison_rate_at_threshold_does_not_trigger(): void
    {
        $r = $this->svc->explain(['poison_rate' => 0.10]);

        $this->assertNull($this->recFor($r, 'poison_rate'));
    }

    // ── Queue collision risk ──────────────────────────────────────────────────

    public function test_queue_collision_risk_produces_recommendation(): void
    {
        $r   = $this->svc->explain(['queue_collision_risk' => 0.20]);
        $rec = $this->recFor($r, 'queue_collision_risk');

        $this->assertNotNull($rec);
        $this->assertEqualsWithDelta(AtlasExternalBrainHealthScoreDeltaExplainer::QUEUE_COLLISION_LIFT, $rec['expected_score_lift'], 0.01);
        $this->assertContains('queue-governance', $rec['task_families']);
        $this->assertRecommendationShape($rec);
    }

    // ── Low compounding rate ──────────────────────────────────────────────────

    public function test_low_compounding_rate_produces_recommendation(): void
    {
        $r   = $this->svc->explain(['compounding_rate' => 0.10]);
        $rec = $this->recFor($r, 'low_compounding_rate');

        $this->assertNotNull($rec);
        $this->assertEqualsWithDelta(AtlasExternalBrainHealthScoreDeltaExplainer::LOW_COMPOUNDING_LIFT, $rec['expected_score_lift'], 0.01);
        $this->assertContains('compounding-wiring', $rec['task_families']);
        $this->assertContains('compounding_rate_reaches_0.30', $rec['falsification_evidence']);
        $this->assertRecommendationShape($rec);
    }

    public function test_compounding_at_or_above_threshold_does_not_trigger(): void
    {
        $r = $this->svc->explain(['compounding_rate' => 0.30]);

        $this->assertNull($this->recFor($r, 'low_compounding_rate'));
    }

    // ── Ranking & totals ──────────────────────────────────────────────────────

    public function test_ranked_descending_by_score_lift(): void
    {
        // zero_entropy(15) > insufficient_trend_data(10) > gate_holes(1×5=5)
        $r     = $this->svc->explain(['gate_holes' => 1, 'entropy' => 0.0, 'trend_data_points' => 0]);
        $lifts = array_column($r['ranked_recommendations'], 'expected_score_lift');
        $sorted = $lifts;
        rsort($sorted);

        $this->assertSame($sorted, $lifts);
    }

    public function test_all_three_original_gaps_detected(): void
    {
        $r    = $this->svc->explain(['gate_holes' => 1, 'entropy' => 0.0, 'trend_data_points' => 0]);
        $gaps = array_column($r['ranked_recommendations'], 'gap');

        foreach (['gate_holes', 'zero_entropy', 'insufficient_trend_data'] as $gap) {
            $this->assertContains($gap, $gaps);
        }
    }

    public function test_total_expected_lift_is_sum(): void
    {
        $r = $this->svc->explain(['gate_holes' => 2, 'entropy' => 0.0, 'trend_data_points' => 1]);

        $expected = round(
            2 * AtlasExternalBrainHealthScoreDeltaExplainer::LIFT_PER_GATE
            + AtlasExternalBrainHealthScoreDeltaExplainer::ZERO_ENTROPY_LIFT
            + AtlasExternalBrainHealthScoreDeltaExplainer::TREND_MIN_LIFT,
            2,
        );
        $this->assertEqualsWithDelta($expected, $r['total_expected_lift'], 0.01);
    }

    public function test_total_expected_lift_includes_all_active_gaps(): void
    {
        $r = $this->svc->explain([
            'gate_holes'          => 1,
            'entropy'             => 0.0,
            'trend_data_points'   => 1,
            'evidence_age_days'   => 10,
            'give_back_rate'      => 0.30,
            'poison_rate'         => 0.20,
            'queue_collision_risk' => 0.20,
            'compounding_rate'    => 0.10,
        ]);

        $expected = round(
            1 * AtlasExternalBrainHealthScoreDeltaExplainer::LIFT_PER_GATE
            + AtlasExternalBrainHealthScoreDeltaExplainer::ZERO_ENTROPY_LIFT
            + AtlasExternalBrainHealthScoreDeltaExplainer::TREND_MIN_LIFT
            + AtlasExternalBrainHealthScoreDeltaExplainer::STALE_EVIDENCE_LIFT
            + AtlasExternalBrainHealthScoreDeltaExplainer::HIGH_GIVE_BACK_LIFT
            + AtlasExternalBrainHealthScoreDeltaExplainer::POISON_RATE_LIFT
            + AtlasExternalBrainHealthScoreDeltaExplainer::QUEUE_COLLISION_LIFT
            + AtlasExternalBrainHealthScoreDeltaExplainer::LOW_COMPOUNDING_LIFT,
            2,
        );
        $this->assertEqualsWithDelta($expected, $r['total_expected_lift'], 0.01);
        $this->assertCount(8, $r['ranked_recommendations']);
    }

    // ── No gaps ───────────────────────────────────────────────────────────────

    public function test_no_gaps_produces_empty_recommendations(): void
    {
        $r = $this->svc->explain(['gate_holes' => 0, 'entropy' => 1.0, 'trend_data_points' => 5]);

        $this->assertSame([], $r['ranked_recommendations']);
        $this->assertEqualsWithDelta(0.0, $r['total_expected_lift'], 0.01);
    }

    // ── Schema ────────────────────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc->explain([]);

        $this->assertSame(AtlasExternalBrainHealthScoreDeltaExplainer::SCHEMA, $r['schema_version']);
    }
}
