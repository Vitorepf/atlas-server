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
            'domain_map_quality',
            'outcome_learning',
            'queue_self_healing',
            'proof_strength',
            'autonomy_independence',
            'simplification_maturity',
        ];
        foreach ($expectedNames as $name) {
            $this->assertContains($name, $names, "dimension {$name} must be present");
        }
        $totalWeight = array_sum(array_column($dims, 'weight'));
        $this->assertEqualsWithDelta(1.0, $totalWeight, 0.0001, 'dimension weights must sum to 1.0');
    }

    public function test_dimension_breakdown_has_required_fields_for_every_dimension(): void
    {
        $names = array_column($this->rubric->dimensions(), 'name');
        $scores = array_fill_keys($names, 0.5);
        $r = $this->rubric->evaluate($scores);

        $this->assertCount(count($names), $r['dimension_breakdown']);
        foreach ($r['dimension_breakdown'] as $entry) {
            foreach (['name', 'weight', 'score', 'weighted_contribution', 'finality_floor_met', 'missing_evidence_hint'] as $k) {
                $this->assertArrayHasKey($k, $entry);
            }
        }
    }

    public function test_missing_evidence_hint_empty_when_floor_met_and_present_when_not(): void
    {
        $names = array_column($this->rubric->dimensions(), 'name');
        $scores = array_fill_keys($names, 0.9);
        $scores['research_pattern_expansion'] = 0.1;
        $r = $this->rubric->evaluate($scores);

        $weak = current(array_filter($r['dimension_breakdown'], fn ($d) => $d['name'] === 'research_pattern_expansion'));
        $strong = current(array_filter($r['dimension_breakdown'], fn ($d) => $d['name'] === 'strategic_origination'));

        $this->assertNotEmpty($weak['missing_evidence_hint']);
        $this->assertSame('', $strong['missing_evidence_hint']);
    }

    public function test_not_final_includes_next_gap_to_95_and_improvement_path_excluding_floor_met_dimensions(): void
    {
        $names = array_column($this->rubric->dimensions(), 'name');
        $scores = array_fill_keys($names, 0.9);
        $scores['research_pattern_expansion'] = 0.1;
        $scores['final_certification'] = 0.2;
        $r = $this->rubric->evaluate($scores);

        $this->assertFalse($r['final']);
        $this->assertArrayHasKey('next_gap_to_95', $r);
        $this->assertGreaterThan(0.0, $r['next_gap_to_95']);
        $this->assertArrayHasKey('recommended_improvement_path', $r);
        $this->assertNotContains('strategic_origination', $r['recommended_improvement_path']);
        $this->assertContains('research_pattern_expansion', $r['recommended_improvement_path']);
        $this->assertContains('final_certification', $r['recommended_improvement_path']);
    }

    public function test_gate_triggered_includes_gate_cap_reason_and_finality_blockers(): void
    {
        $names = array_column($this->rubric->dimensions(), 'name');
        $scores = array_fill_keys($names, 1.0);
        $r = $this->rubric->evaluate($scores, ['unverified_claims']);

        $this->assertFalse($r['final']);
        $this->assertArrayHasKey('gate_cap_reason', $r);
        $this->assertStringContainsString('unverified_claims', $r['gate_cap_reason']);
        $this->assertContains('unverified_claims', $r['finality_blockers']);
        $this->assertArrayHasKey('dimension_breakdown', $r);
        $this->assertNotEmpty($r['dimension_breakdown']);
    }

    public function test_raw_task_counts_are_not_accepted_as_dimension_evidence(): void
    {
        // Passing a raw count-shaped key (not a known dimension name) contributes nothing —
        // the rubric only reads known dimension names, never accepts arbitrary count fields.
        $r = $this->rubric->evaluate(['task_count' => 9999, 'queue_depth' => 500]);

        $this->assertSame(0.0, $r['weighted_score']);
        $this->assertFalse($r['final']);
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

    // ── AC: hard-fail gate detail, weak_dimension_details, readiness output shape ──

    public function test_final_is_false_when_hard_fail_gate_triggered_regardless_of_weighted_score(): void
    {
        $scores = array_fill_keys(array_column($this->rubric->dimensions(), 'name'), 1.0);
        $result = $this->rubric->evaluate($scores, ['template_farm_volume']);

        $this->assertFalse($result['final']);
        $this->assertContains('template_farm_volume', $result['blocking_gates']);
    }

    public function test_weak_dimensions_below_floor_have_score_weight_and_remediation_hint(): void
    {
        $scores = array_fill_keys(array_column($this->rubric->dimensions(), 'name'), 1.0);
        $scores['strategic_origination'] = 0.10;

        $result = $this->rubric->evaluate($scores);

        $detail = null;
        foreach ($result['weak_dimension_details'] as $d) {
            if ($d['name'] === 'strategic_origination') {
                $detail = $d;
            }
        }
        $this->assertNotNull($detail);
        $this->assertSame(0.10, $detail['score']);
        $this->assertSame(0.14, $detail['weight']);
        $this->assertNotEmpty($detail['remediation_hint']);
    }

    public function test_output_includes_readiness_band_blocking_gates_weak_dimensions_and_next_capability_focus(): void
    {
        $result = $this->rubric->evaluate([]);

        foreach (['readiness_band', 'blocking_gates', 'weak_dimensions', 'next_capability_focus'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
        $this->assertNotNull($result['next_capability_focus']);
    }

    // ── AC: scores proof quality, autonomy lift, simplification, downstream unlocks, operational safety ──

    public function test_dimensions_include_downstream_unlocks_and_operational_safety(): void
    {
        $names = array_column($this->rubric->dimensions(), 'name');

        $this->assertContains('downstream_unlocks', $names);
        $this->assertContains('operational_safety', $names);
        $this->assertContains('proof_strength', $names);
        $this->assertContains('autonomy_independence', $names);
        $this->assertContains('simplification_maturity', $names);
    }

    // ── AC: hard-fails proxy-only, proofless, provider-dependent, duplicated-capability ──

    public function test_hard_fail_gates_include_the_four_new_gates(): void
    {
        $gates = array_column($this->rubric->hardFailGates(), 'gate');

        $this->assertContains('proxy_only', $gates);
        $this->assertContains('proofless', $gates);
        $this->assertContains('provider_dependent', $gates);
        $this->assertContains('duplicated_capability', $gates);
    }

    public function test_duplicated_capability_gate_forces_below_final_even_with_perfect_scores(): void
    {
        $scores = array_fill_keys(array_column($this->rubric->dimensions(), 'name'), 1.0);
        $result = $this->rubric->evaluate($scores, ['duplicated_capability']);

        $this->assertFalse($result['final']);
        $this->assertContains('duplicated_capability', $result['blocking_gates']);
    }

    // ── AC: returns score, grade, hard-fail gates and next improvement recommendation ──

    public function test_score_is_alias_of_weighted_score(): void
    {
        $result = $this->rubric->evaluate(['strategic_origination' => 0.8]);

        $this->assertSame($result['weighted_score'], $result['score']);
    }

    public function test_grade_is_a_when_final(): void
    {
        $scores = array_fill_keys(array_column($this->rubric->dimensions(), 'name'), 1.0);
        $result = $this->rubric->evaluate($scores);

        $this->assertTrue($result['final']);
        $this->assertSame('A', $result['grade']);
    }

    public function test_grade_is_f_when_gate_triggered_regardless_of_score(): void
    {
        $scores = array_fill_keys(array_column($this->rubric->dimensions(), 'name'), 1.0);
        $result = $this->rubric->evaluate($scores, ['proofless']);

        $this->assertSame('F', $result['grade']);
    }

    public function test_grade_is_f_when_score_is_zero(): void
    {
        $result = $this->rubric->evaluate([]);

        $this->assertSame('F', $result['grade']);
    }

    public function test_next_capability_focus_present_alongside_grade_and_score(): void
    {
        $result = $this->rubric->evaluate([]);

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('grade', $result);
        $this->assertArrayHasKey('next_capability_focus', $result);
    }
}
