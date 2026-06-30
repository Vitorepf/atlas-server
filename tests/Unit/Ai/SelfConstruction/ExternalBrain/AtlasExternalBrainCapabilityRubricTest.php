<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityRubric;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCapabilityRubricTest extends TestCase
{
    private AtlasExternalBrainCapabilityRubric $rubric;

    protected function setUp(): void
    {
        $this->rubric = new AtlasExternalBrainCapabilityRubric;
    }

    public function test_dimensions_are_named_and_weights_sum_to_one(): void
    {
        $dims = $this->rubric->dimensions();
        $this->assertNotEmpty($dims);
        $names = array_column($dims, 'name');
        $expectedNames = [
            'strategic_origination',
            'grounded_system_comprehension',
            'high_leverage_task_synthesis',
            'anti_goodhart_resistance',
            'learning_from_muscle_outcomes',
            'autonomous_continuation',
            'research_pattern_expansion',
            'final_certification',
        ];
        foreach ($expectedNames as $name) {
            $this->assertContains($name, $names, "dimension {$name} must be present");
        }
        $totalWeight = array_sum(array_column($dims, 'weight'));
        $this->assertEqualsWithDelta(1.0, $totalWeight, 0.0001, 'dimension weights must sum to 1.0');
    }

    public function test_hard_fail_gates_include_the_three_anti_goodhart_gates(): void
    {
        $gates = array_column($this->rubric->hardFailGates(), 'gate');
        $this->assertContains('template_farm_volume', $gates);
        $this->assertContains('unverified_claims', $gates);
        $this->assertContains('human_dependent_steady_state', $gates);
        foreach ($this->rubric->hardFailGates() as $gate) {
            $this->assertNotEmpty($gate['description'], 'each gate must have a description');
        }
    }

    public function test_perfect_scores_with_no_gates_produce_final_band(): void
    {
        $scores = array_fill_keys(array_column($this->rubric->dimensions(), 'name'), 1.0);
        $result = $this->rubric->evaluate($scores);
        $this->assertTrue($result['final']);
        $this->assertGreaterThanOrEqual(AtlasExternalBrainCapabilityRubric::FINAL_THRESHOLD, $result['band'][0]);
        $this->assertSame([], $result['triggered_gates']);
    }

    public function test_template_farm_volume_gate_forces_below_final_even_with_perfect_scores(): void
    {
        $scores = array_fill_keys(array_column($this->rubric->dimensions(), 'name'), 1.0);
        $result = $this->rubric->evaluate($scores, ['template_farm_volume']);
        $this->assertFalse($result['final']);
        $this->assertLessThan(AtlasExternalBrainCapabilityRubric::FINAL_THRESHOLD, $result['band'][1]);
        $this->assertContains('template_farm_volume', $result['triggered_gates']);
    }

    public function test_unverified_claims_gate_forces_below_final_even_with_perfect_scores(): void
    {
        $scores = array_fill_keys(array_column($this->rubric->dimensions(), 'name'), 1.0);
        $result = $this->rubric->evaluate($scores, ['unverified_claims']);
        $this->assertFalse($result['final']);
        $this->assertLessThan(AtlasExternalBrainCapabilityRubric::FINAL_THRESHOLD, $result['band'][1]);
    }

    public function test_human_dependent_steady_state_gate_forces_below_final(): void
    {
        $scores = array_fill_keys(array_column($this->rubric->dimensions(), 'name'), 1.0);
        $result = $this->rubric->evaluate($scores, ['human_dependent_steady_state']);
        $this->assertFalse($result['final']);
        $this->assertLessThan(AtlasExternalBrainCapabilityRubric::FINAL_THRESHOLD, $result['band'][1]);
    }

    public function test_band_is_deterministic_for_same_inputs(): void
    {
        $scores = ['strategic_origination' => 0.8, 'grounded_system_comprehension' => 0.9];
        $a = $this->rubric->evaluate($scores);
        $b = $this->rubric->evaluate($scores);
        $this->assertSame($a['band'], $b['band']);
        $this->assertSame($a['weighted_score'], $b['weighted_score']);
    }

    public function test_raw_task_count_is_not_a_valid_dimension_name(): void
    {
        $dimNames = array_column($this->rubric->dimensions(), 'name');
        $this->assertNotContains('task_count', $dimNames);
        $this->assertNotContains('throughput', $dimNames);
        $this->assertNotContains('volume', $dimNames);
    }

    public function test_zero_scores_produce_not_final_band(): void
    {
        $result = $this->rubric->evaluate([]);
        $this->assertFalse($result['final']);
        $this->assertSame(0.0, $result['weighted_score']);
    }

    public function test_schema_key_present_in_evaluate_output(): void
    {
        $result = $this->rubric->evaluate([]);
        $this->assertSame(AtlasExternalBrainCapabilityRubric::SCHEMA, $result['schema']);
    }

    public function test_unknown_gates_are_silently_ignored(): void
    {
        $scores = array_fill_keys(array_column($this->rubric->dimensions(), 'name'), 1.0);
        $result = $this->rubric->evaluate($scores, ['nonexistent_gate']);
        $this->assertTrue($result['final'], 'unknown gates must not trigger fail-closed');
    }

    // ── AC1: weak_dimensions reported ────────────────────────────────────────

    public function test_weak_dimensions_empty_when_all_scores_above_floor(): void
    {
        $scores = array_fill_keys(array_column($this->rubric->dimensions(), 'name'), 1.0);
        $result = $this->rubric->evaluate($scores);
        $this->assertSame([], $result['weak_dimensions']);
    }

    public function test_weak_dimensions_lists_dimension_below_floor(): void
    {
        $scores = array_fill_keys(array_column($this->rubric->dimensions(), 'name'), 1.0);
        $scores['strategic_origination'] = 0.50; // below FINALITY_FLOOR
        $result = $this->rubric->evaluate($scores);
        $this->assertContains('strategic_origination', $result['weak_dimensions']);
    }

    public function test_weak_dimensions_lists_all_dimensions_when_all_zero(): void
    {
        $result = $this->rubric->evaluate([]);
        $this->assertCount(count($this->rubric->dimensions()), $result['weak_dimensions']);
    }

    public function test_dimension_exactly_at_floor_is_not_weak(): void
    {
        $scores = array_fill_keys(array_column($this->rubric->dimensions(), 'name'), 1.0);
        $scores['autonomous_continuation'] = AtlasExternalBrainCapabilityRubric::FINALITY_FLOOR;
        $result = $this->rubric->evaluate($scores);
        $this->assertNotContains('autonomous_continuation', $result['weak_dimensions']);
    }

    // ── AC2: final blocked when any dimension below floor ────────────────────

    public function test_final_false_when_weak_dimension_despite_high_weighted_score(): void
    {
        // research_pattern_expansion (weight=0.05) at 0 → weighted sum = 0.95 (at threshold)
        // but the dimension is below floor → final must be false
        $scores = array_fill_keys(array_column($this->rubric->dimensions(), 'name'), 1.0);
        $scores['research_pattern_expansion'] = 0.0;
        $result = $this->rubric->evaluate($scores);
        $this->assertGreaterThanOrEqual(AtlasExternalBrainCapabilityRubric::FINAL_THRESHOLD, $result['weighted_score']);
        $this->assertNotEmpty($result['weak_dimensions']);
        $this->assertFalse($result['final']);
    }

    public function test_final_true_only_when_high_score_and_no_weak_dimensions(): void
    {
        $scores = array_fill_keys(array_column($this->rubric->dimensions(), 'name'), 1.0);
        $result = $this->rubric->evaluate($scores);
        $this->assertSame([], $result['weak_dimensions']);
        $this->assertTrue($result['final']);
    }

    public function test_evaluate_output_has_weak_dimensions_key(): void
    {
        $result = $this->rubric->evaluate([]);
        $this->assertArrayHasKey('weak_dimensions', $result);
    }
}
