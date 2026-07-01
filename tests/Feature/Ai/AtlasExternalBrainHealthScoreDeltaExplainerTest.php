<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainHealthScoreDeltaExplainer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainHealthScoreDeltaExplainerTest extends TestCase
{
    private function explainer(): AtlasExternalBrainHealthScoreDeltaExplainer
    {
        return new AtlasExternalBrainHealthScoreDeltaExplainer;
    }

    // ── AC2: each named gap produces a recommendation ─────────────────────────

    public function test_each_gap_condition_produces_its_own_recommendation(): void
    {
        $breakdowns = [
            'gate_holes' => ['gate_holes' => 2],
            'zero_entropy' => ['entropy' => 0.0],
            'insufficient_trend_data' => ['trend_data_points' => 1],
            'stale_evidence' => ['evidence_age_days' => 10],
            'high_give_back_rate' => ['give_back_rate' => 0.5],
            'poison_rate' => ['poison_rate' => 0.2],
            'queue_collision_risk' => ['queue_collision_risk' => 0.3],
            'low_compounding_rate' => ['compounding_rate' => 0.1],
        ];

        foreach ($breakdowns as $expectedGap => $breakdown) {
            $result = $this->explainer()->explain($breakdown);
            $gaps = array_column($result['ranked_recommendations'], 'gap');

            $this->assertContains($expectedGap, $gaps, "breakdown: {$expectedGap}");
        }
    }

    public function test_healthy_breakdown_produces_no_recommendations(): void
    {
        $result = $this->explainer()->explain([
            'gate_holes' => 0,
            'entropy' => 0.5,
            'trend_data_points' => 10,
            'evidence_age_days' => 1,
            'give_back_rate' => 0.05,
            'poison_rate' => 0.01,
            'queue_collision_risk' => 0.02,
            'compounding_rate' => 0.5,
        ]);

        $this->assertSame([], $result['ranked_recommendations']);
        $this->assertSame(0.0, $result['total_expected_lift']);
    }

    // ── AC3: recommendations sorted by expected_score_lift desc, gap-name tie-break ──

    public function test_recommendations_sorted_by_expected_lift_descending(): void
    {
        $result = $this->explainer()->explain([
            'gate_holes' => 1, // lift 5.0
            'entropy' => 0.0,  // lift 15.0
            'poison_rate' => 0.5, // lift 20.0
        ]);

        $lifts = array_column($result['ranked_recommendations'], 'expected_score_lift');
        $sorted = $lifts;
        rsort($sorted);
        $this->assertSame($sorted, $lifts);
        $this->assertSame('poison_rate', $result['ranked_recommendations'][0]['gap']);
    }

    public function test_tied_lift_breaks_alphabetically_by_gap_name(): void
    {
        // stale_evidence lift=12.0, queue_collision_risk lift=7.0 -- pick two equal-lift gaps
        // by using zero_entropy (15.0) alone isn't tied; construct a genuine tie via gate_holes.
        $result = $this->explainer()->explain([
            'gate_holes' => 3, // lift 15.0
            'entropy' => 0.0,  // lift 15.0
        ]);

        $this->assertCount(2, $result['ranked_recommendations']);
        $this->assertSame(
            $result['ranked_recommendations'][0]['expected_score_lift'],
            $result['ranked_recommendations'][1]['expected_score_lift'],
        );
        $this->assertSame('gate_holes', $result['ranked_recommendations'][0]['gap']);
        $this->assertSame('zero_entropy', $result['ranked_recommendations'][1]['gap']);
    }

    // ── AC4: every recommendation includes required actionable fields ─────────

    public function test_every_recommendation_includes_required_actionable_fields(): void
    {
        $result = $this->explainer()->explain([
            'gate_holes' => 1,
            'poison_rate' => 0.5,
        ]);

        foreach ($result['ranked_recommendations'] as $rec) {
            $this->assertArrayHasKey('task_families', $rec);
            $this->assertNotEmpty($rec['task_families']);
            $this->assertArrayHasKey('owner_subsystem', $rec);
            $this->assertNotEmpty($rec['owner_subsystem']);
            $this->assertArrayHasKey('risk_level', $rec);
            $this->assertArrayHasKey('falsification_evidence', $rec);
            $this->assertNotEmpty($rec['falsification_evidence']);
            $this->assertArrayHasKey('stop_condition', $rec);
            $this->assertNotEmpty($rec['stop_condition']);
        }
    }
}
