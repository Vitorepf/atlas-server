<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFrontierEscalationJustifier;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainFrontierEscalationJustifierTest extends TestCase
{
    private function justifier(): AtlasExternalBrainFrontierEscalationJustifier
    {
        return new AtlasExternalBrainFrontierEscalationJustifier;
    }

    // ── AC4: output shape ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->justifier()->justify([]);
        $this->assertSame(AtlasExternalBrainFrontierEscalationJustifier::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('recommended_tier', $r);
        $this->assertArrayHasKey('escalation_reasons', $r);
        $this->assertArrayHasKey('small_model_sufficiency_reasons', $r);
        $this->assertArrayHasKey('frontier_cost_justification', $r);
        $this->assertArrayHasKey('confidence', $r);
    }

    // ── AC2: frontier escalation triggers ────────────────────────────────────

    public function test_high_ambiguity_triggers_escalation(): void
    {
        $r = $this->justifier()->justify([
            'ambiguity_score'              => 0.80,
            'blast_radius'                 => 0.1,
            'is_conflicting_evidence'      => false,
            'estimated_frontier_cost_units' => 2.0,
            'estimated_small_cost_units'   => 1.0,
        ]);
        $this->assertContains('high_ambiguity_score', $r['escalation_reasons']);
        $this->assertSame('frontier_model', $r['recommended_tier']);
    }

    public function test_high_blast_radius_triggers_escalation(): void
    {
        $r = $this->justifier()->justify([
            'ambiguity_score'              => 0.1,
            'blast_radius'                 => 0.75,
            'is_conflicting_evidence'      => false,
            'estimated_frontier_cost_units' => 2.0,
            'estimated_small_cost_units'   => 1.0,
        ]);
        $this->assertContains('high_blast_radius', $r['escalation_reasons']);
        $this->assertSame('frontier_model', $r['recommended_tier']);
    }

    public function test_conflicting_evidence_triggers_escalation(): void
    {
        $r = $this->justifier()->justify([
            'is_conflicting_evidence'      => true,
            'estimated_frontier_cost_units' => 2.0,
            'estimated_small_cost_units'   => 1.0,
        ]);
        $this->assertContains('conflicting_evidence_requires_synthesis', $r['escalation_reasons']);
        $this->assertSame('frontier_model', $r['recommended_tier']);
    }

    public function test_multiple_escalation_reasons_all_reported(): void
    {
        $r = $this->justifier()->justify([
            'ambiguity_score'         => 0.8,
            'blast_radius'            => 0.7,
            'is_conflicting_evidence' => true,
            'estimated_frontier_cost_units' => 2.0,
            'estimated_small_cost_units'    => 1.0,
        ]);
        $this->assertContains('high_ambiguity_score',                 $r['escalation_reasons']);
        $this->assertContains('high_blast_radius',                    $r['escalation_reasons']);
        $this->assertContains('conflicting_evidence_requires_synthesis', $r['escalation_reasons']);
    }

    // ── AC3: small-model sufficiency ─────────────────────────────────────────

    public function test_all_sufficiency_conditions_met_yields_small_model(): void
    {
        $r = $this->justifier()->justify([
            'ambiguity_score'       => 0.2,
            'blast_radius'          => 0.1,
            'is_conflicting_evidence' => false,
            'evidence_strength'     => 0.85,
            'task_classification'   => 'known',
        ]);
        $this->assertSame('small_model', $r['recommended_tier']);
        $this->assertContains('strong_scaffold_evidence',     $r['small_model_sufficiency_reasons']);
        $this->assertContains('task_classification_is_known', $r['small_model_sufficiency_reasons']);
        $this->assertContains('low_ambiguity_score',          $r['small_model_sufficiency_reasons']);
    }

    public function test_partial_sufficiency_yields_scaffolded_not_small(): void
    {
        // Only two of three sufficiency conditions → scaffolded_small_model.
        $r = $this->justifier()->justify([
            'ambiguity_score'     => 0.50, // >= LOW_AMBIGUITY(0.35) → NOT low ambiguity
            'evidence_strength'   => 0.85,
            'task_classification' => 'known',
        ]);
        $this->assertSame('scaffolded_small_model', $r['recommended_tier']);
    }

    public function test_extraction_classification_qualifies_as_known(): void
    {
        $r = $this->justifier()->justify([
            'ambiguity_score'     => 0.1,
            'evidence_strength'   => 0.9,
            'task_classification' => 'extraction',
        ]);
        $this->assertContains('task_classification_is_known', $r['small_model_sufficiency_reasons']);
    }

    // ── Cost justification ────────────────────────────────────────────────────

    public function test_escalation_downgraded_to_scaffolded_when_cost_too_high(): void
    {
        // cost_ratio = 10 / 1 = 10.0 > MAX_COST_RATIO(5.0) → downgrade.
        $r = $this->justifier()->justify([
            'ambiguity_score'              => 0.80,
            'estimated_frontier_cost_units' => 10.0,
            'estimated_small_cost_units'   =>  1.0,
        ]);
        $this->assertNotEmpty($r['escalation_reasons']);
        $this->assertSame('scaffolded_small_model', $r['recommended_tier']);
        $this->assertFalse($r['frontier_cost_justification']['justified']);
    }

    public function test_cost_ratio_within_bound_justified(): void
    {
        $r = $this->justifier()->justify([
            'ambiguity_score'              => 0.80,
            'estimated_frontier_cost_units' => 4.0,
            'estimated_small_cost_units'   => 1.0,
        ]);
        $this->assertTrue($r['frontier_cost_justification']['justified']);
        $this->assertSame(4.0, $r['frontier_cost_justification']['cost_ratio']);
    }

    public function test_no_escalation_reasons_cost_not_justified(): void
    {
        $r = $this->justifier()->justify([
            'ambiguity_score'     => 0.1,
            'blast_radius'        => 0.1,
            'evidence_strength'   => 0.9,
            'task_classification' => 'known',
        ]);
        $this->assertEmpty($r['escalation_reasons']);
        $this->assertFalse($r['frontier_cost_justification']['justified']);
    }

    // ── Confidence ────────────────────────────────────────────────────────────

    public function test_high_confidence_for_clear_escalation(): void
    {
        $r = $this->justifier()->justify([
            'ambiguity_score'              => 0.9,
            'estimated_frontier_cost_units' => 2.0,
            'estimated_small_cost_units'   => 1.0,
        ]);
        $this->assertSame('high', $r['confidence']);
    }

    public function test_high_confidence_for_clear_small_model(): void
    {
        $r = $this->justifier()->justify([
            'ambiguity_score'     => 0.1,
            'evidence_strength'   => 0.9,
            'task_classification' => 'known',
        ]);
        $this->assertSame('high', $r['confidence']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'ambiguity_score'              => 0.7,
            'blast_radius'                 => 0.3,
            'is_conflicting_evidence'      => false,
            'evidence_strength'            => 0.6,
            'task_classification'          => 'known',
            'estimated_frontier_cost_units' => 3.0,
            'estimated_small_cost_units'   => 1.0,
        ];
        $a = $this->justifier()->justify($facts);
        $b = $this->justifier()->justify($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── AC4: output includes threshold evidence, escalation reason, fallback route ──

    public function test_output_has_decision_threshold_evidence_escalation_reason_and_fallback_route(): void
    {
        $r = $this->justifier()->justify([]);

        foreach (['decision', 'threshold_evidence', 'escalation_reason', 'fallback_route', 'provider_specific_dependency'] as $key) {
            $this->assertArrayHasKey($key, $r, "missing key: {$key}");
        }
        $this->assertFalse($r['provider_specific_dependency']);
    }

    public function test_decision_is_frontier_required_when_escalation_and_cost_justified(): void
    {
        $r = $this->justifier()->justify([
            'ambiguity_score' => 0.9,
            'estimated_frontier_cost_units' => 2.0,
            'estimated_small_cost_units' => 1.0,
        ]);

        $this->assertSame(AtlasExternalBrainFrontierEscalationJustifier::DECISION_FRONTIER_REQUIRED, $r['decision']);
        $this->assertNull($r['fallback_route']);
    }

    public function test_decision_is_use_scaffolded_standard_model_for_routine_work(): void
    {
        $r = $this->justifier()->justify([
            'ambiguity_score' => 0.5,
            'evidence_strength' => 0.5,
        ]);

        $this->assertSame(AtlasExternalBrainFrontierEscalationJustifier::DECISION_USE_SCAFFOLDED_STANDARD_MODEL, $r['decision']);
        $this->assertNotNull($r['fallback_route']);
    }

    public function test_decision_falls_back_when_escalation_present_but_cost_unjustified(): void
    {
        $r = $this->justifier()->justify([
            'ambiguity_score' => 0.9,
            'estimated_frontier_cost_units' => 50.0,
            'estimated_small_cost_units' => 1.0,
        ]);

        $this->assertSame(AtlasExternalBrainFrontierEscalationJustifier::DECISION_USE_SCAFFOLDED_STANDARD_MODEL, $r['decision']);
    }

    // ── expected_lift / architectural_leverage_score escalation factors ──────

    public function test_high_expected_lift_triggers_escalation(): void
    {
        $r = $this->justifier()->justify([
            'expected_lift' => 0.9,
            'estimated_frontier_cost_units' => 2.0,
            'estimated_small_cost_units' => 1.0,
        ]);

        $this->assertContains('high_expected_lift', $r['escalation_reasons']);
        $this->assertSame(AtlasExternalBrainFrontierEscalationJustifier::DECISION_FRONTIER_REQUIRED, $r['decision']);
    }

    public function test_high_architectural_leverage_triggers_escalation(): void
    {
        $r = $this->justifier()->justify([
            'architectural_leverage_score' => 0.9,
            'estimated_frontier_cost_units' => 2.0,
            'estimated_small_cost_units' => 1.0,
        ]);

        $this->assertContains('high_architectural_leverage', $r['escalation_reasons']);
        $this->assertSame(AtlasExternalBrainFrontierEscalationJustifier::DECISION_FRONTIER_REQUIRED, $r['decision']);
    }

    public function test_low_expected_lift_and_leverage_do_not_trigger_escalation(): void
    {
        $r = $this->justifier()->justify([
            'expected_lift' => 0.1,
            'architectural_leverage_score' => 0.1,
        ]);

        $this->assertNotContains('high_expected_lift', $r['escalation_reasons']);
        $this->assertNotContains('high_architectural_leverage', $r['escalation_reasons']);
    }

    public function test_threshold_evidence_lists_all_five_factors(): void
    {
        $r = $this->justifier()->justify([]);
        $factors = array_column($r['threshold_evidence'], 'factor');

        foreach (['ambiguity_score', 'blast_radius', 'expected_lift', 'architectural_leverage_score', 'is_conflicting_evidence'] as $factor) {
            $this->assertContains($factor, $factors);
        }
    }

    public function test_escalation_reason_is_none_string_when_no_escalation(): void
    {
        $r = $this->justifier()->justify([]);
        $this->assertSame('none', $r['escalation_reason']);
    }

    // ── AC: vague ambition alone does not justify escalation ────────────────────

    public function test_empty_facts_never_escalate(): void
    {
        $r = $this->justifier()->justify([]);

        $this->assertSame([], $r['escalation_reasons']);
        $this->assertSame(AtlasExternalBrainFrontierEscalationJustifier::DECISION_USE_SCAFFOLDED_STANDARD_MODEL, $r['decision']);
    }

    public function test_unrecognized_ambition_field_alone_never_escalates(): void
    {
        // A free-text "ambition" claim that isn't one of the five measurable threshold
        // factors must never move the needle — only concrete crossed thresholds do.
        $r = $this->justifier()->justify([
            'ambition' => 'this could be revolutionary',
            'notes' => 'trust me, this is huge',
        ]);

        $this->assertSame([], $r['escalation_reasons']);
        $this->assertSame(AtlasExternalBrainFrontierEscalationJustifier::DECISION_USE_SCAFFOLDED_STANDARD_MODEL, $r['decision']);
    }

    public function test_major_unlock_claim_without_any_failed_lower_cost_path_does_not_get_the_evidence_reason(): void
    {
        $r = $this->justifier()->justify([
            'expected_lift' => 0.9,
            'lower_cost_paths_tried' => [],
        ]);

        $this->assertNotContains('failed_lower_cost_paths_with_capability_unlock', $r['escalation_reasons']);
    }

    // ── AC: failed local/subscription paths + high capability unlock justifies escalation ──

    public function test_failed_lower_cost_paths_with_high_expected_lift_justifies_escalation(): void
    {
        $r = $this->justifier()->justify([
            'expected_lift' => 0.9,
            'lower_cost_paths_tried' => [
                ['path' => 'local_model', 'failed' => true],
                ['path' => 'subscription_model', 'failed' => true],
            ],
            'estimated_frontier_cost_units' => 2.0,
            'estimated_small_cost_units' => 1.0,
        ]);

        $this->assertContains('failed_lower_cost_paths_with_capability_unlock', $r['escalation_reasons']);
        $this->assertSame(AtlasExternalBrainFrontierEscalationJustifier::DECISION_FRONTIER_REQUIRED, $r['decision']);
    }

    public function test_failed_lower_cost_paths_without_capability_unlock_does_not_add_the_evidence_reason(): void
    {
        $r = $this->justifier()->justify([
            'expected_lift' => 0.1,
            'architectural_leverage_score' => 0.1,
            'lower_cost_paths_tried' => [
                ['path' => 'local_model', 'failed' => true],
            ],
        ]);

        $this->assertNotContains('failed_lower_cost_paths_with_capability_unlock', $r['escalation_reasons']);
    }

    public function test_lower_cost_paths_tried_but_not_failed_does_not_add_the_evidence_reason(): void
    {
        $r = $this->justifier()->justify([
            'expected_lift' => 0.9,
            'lower_cost_paths_tried' => [
                ['path' => 'local_model', 'failed' => false],
            ],
        ]);

        $this->assertNotContains('failed_lower_cost_paths_with_capability_unlock', $r['escalation_reasons']);
    }

    // ── AC: output includes lower_cost_paths_tried and expected_unlock ──────────

    public function test_output_includes_lower_cost_paths_tried_and_expected_unlock(): void
    {
        $r = $this->justifier()->justify([]);

        $this->assertArrayHasKey('lower_cost_paths_tried', $r);
        $this->assertArrayHasKey('expected_unlock', $r);
        $this->assertSame([], $r['lower_cost_paths_tried']);
        $this->assertSame('incremental_capability_unlock', $r['expected_unlock']);
    }

    public function test_lower_cost_paths_tried_normalizes_input_shape(): void
    {
        $r = $this->justifier()->justify([
            'lower_cost_paths_tried' => [
                ['path' => 'local_model', 'failed' => true],
                'subscription_model',
            ],
        ]);

        $this->assertSame(
            [
                ['path' => 'local_model', 'failed' => true],
                ['path' => 'subscription_model', 'failed' => false],
            ],
            $r['lower_cost_paths_tried'],
        );
    }

    public function test_expected_unlock_is_major_when_lift_or_leverage_crosses_threshold(): void
    {
        $liftResult = $this->justifier()->justify(['expected_lift' => 0.9]);
        $leverageResult = $this->justifier()->justify(['architectural_leverage_score' => 0.9]);

        $this->assertSame('major_capability_unlock', $liftResult['expected_unlock']);
        $this->assertSame('major_capability_unlock', $leverageResult['expected_unlock']);
    }
}
