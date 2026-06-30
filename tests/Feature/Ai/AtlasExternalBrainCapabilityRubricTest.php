<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityRubric;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCapabilityRubricTest extends TestCase
{
    private AtlasExternalBrainCapabilityRubric $rubric;

    protected function setUp(): void
    {
        $this->rubric = new AtlasExternalBrainCapabilityRubric;
    }

    private function allOnes(): array
    {
        return array_fill_keys(
            array_column($this->rubric->dimensions(), 'name'),
            1.0
        );
    }

    private function eval(array $scores, array $gates = []): array
    {
        return $this->rubric->evaluate($scores, $gates);
    }

    // ── AC1: weighted_score changes with dimension scores + maps to maturity band ─

    public function test_all_zero_scores_yield_zero_weighted_score(): void
    {
        $r = $this->eval([]);

        $this->assertSame(0.0, $r['weighted_score']);
    }

    public function test_all_one_scores_yield_weighted_score_of_one(): void
    {
        $r = $this->eval($this->allOnes());

        $this->assertSame(1.0, $r['weighted_score']);
    }

    public function test_partial_scores_produce_expected_weighted_score(): void
    {
        // Only strategic_origination (weight 0.20) set to 1.0
        $r = $this->eval(['strategic_origination' => 1.0]);

        $this->assertEqualsWithDelta(0.20, $r['weighted_score'], 0.001);
    }

    public function test_weighted_score_maps_into_band(): void
    {
        $r = $this->eval($this->allOnes());

        [$lo, $hi] = $r['band'];
        $this->assertGreaterThanOrEqual($lo, $r['weighted_score']);
        $this->assertLessThanOrEqual($hi, $r['weighted_score']);
    }

    public function test_band_changes_as_scores_change(): void
    {
        $low  = $this->eval([]);
        $high = $this->eval($this->allOnes());

        $this->assertLessThan($high['band'][0], $low['band'][1] + 0.001);
    }

    public function test_schema_is_present(): void
    {
        $r = $this->eval([]);

        $this->assertSame(AtlasExternalBrainCapabilityRubric::SCHEMA, $r['schema']);
    }

    // ── AC2: triggered hard-fail gates force final=false regardless of score ───

    public function test_triggered_gate_forces_final_false_even_with_perfect_scores(): void
    {
        $r = $this->eval($this->allOnes(), ['template_farm_volume']);

        $this->assertFalse($r['final'],
            'A triggered gate must force final=false even when all dimension scores are 1.0');
    }

    public function test_any_known_gate_forces_final_false(): void
    {
        foreach (['template_farm_volume', 'unverified_claims', 'human_dependent_steady_state'] as $gate) {
            $r = $this->eval($this->allOnes(), [$gate]);
            $this->assertFalse($r['final'], "Gate '{$gate}' must force final=false");
        }
    }

    public function test_triggered_gates_appear_in_output(): void
    {
        $r = $this->eval([], ['unverified_claims']);

        $this->assertContains('unverified_claims', $r['triggered_gates']);
    }

    public function test_unknown_gate_names_are_filtered_out(): void
    {
        // An unknown gate should not be treated as a real gate
        $r = $this->eval($this->allOnes(), ['invented_gate_that_does_not_exist']);

        // No real gates triggered → final=true when all scores are high
        $this->assertTrue($r['final']);
        $this->assertNotContains('invented_gate_that_does_not_exist', $r['triggered_gates']);
    }

    // ── AC3: missing autonomy / outcome-learning / anti-proxy blocks final ────

    public function test_missing_autonomous_continuation_blocks_final_readiness(): void
    {
        $scores = $this->allOnes();
        $scores['autonomous_continuation'] = 0.0;

        $r = $this->eval($scores);

        $this->assertFalse($r['final'],
            'Missing autonomous_continuation must block final=true');
        $this->assertContains('autonomous_continuation', $r['weak_dimensions']);
    }

    public function test_missing_learning_from_muscle_outcomes_blocks_final_readiness(): void
    {
        $scores = $this->allOnes();
        $scores['learning_from_muscle_outcomes'] = 0.0;

        $r = $this->eval($scores);

        $this->assertFalse($r['final']);
        $this->assertContains('learning_from_muscle_outcomes', $r['weak_dimensions']);
    }

    public function test_missing_anti_goodhart_resistance_blocks_final_readiness(): void
    {
        $scores = $this->allOnes();
        $scores['anti_goodhart_resistance'] = 0.0;

        $r = $this->eval($scores);

        $this->assertFalse($r['final']);
        $this->assertContains('anti_goodhart_resistance', $r['weak_dimensions']);
    }

    public function test_strong_other_dimensions_cannot_compensate_for_missing_critical_one(): void
    {
        // Even if weighted_score would be very high, a weak critical dimension blocks final
        $scores = $this->allOnes();
        $scores['autonomous_continuation'] = 0.50; // below FINALITY_FLOOR

        $r = $this->eval($scores);

        $this->assertGreaterThanOrEqual(AtlasExternalBrainCapabilityRubric::FINAL_THRESHOLD, $r['weighted_score'],
            'Weighted score should be at or above threshold (other dims are 1.0)');
        $this->assertFalse($r['final'],
            'High weighted score must not compensate for weak critical dimension');
    }

    // ── AC4: pure and deterministic ───────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $scores = ['strategic_origination' => 0.8, 'autonomous_continuation' => 0.9];

        $this->assertSame(json_encode($this->eval($scores)), json_encode($this->eval($scores)));
    }

    public function test_dimensions_weights_sum_to_one(): void
    {
        $total = array_sum(array_column($this->rubric->dimensions(), 'weight'));

        $this->assertEqualsWithDelta(1.0, $total, 0.001);
    }
}
