<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFrontierEscalationJustifier;
use Tests\TestCase;

final class AtlasExternalBrainFrontierEscalationJustifierTest extends TestCase
{
    private function justifier(): AtlasExternalBrainFrontierEscalationJustifier
    {
        return new AtlasExternalBrainFrontierEscalationJustifier;
    }

    public function test_high_ambiguity_produces_escalation_reason(): void
    {
        $r = $this->justifier()->justify(['ambiguity_score' => 0.9]);

        self::assertContains('high_ambiguity_score', $r['escalation_reasons']);
    }

    public function test_high_blast_radius_produces_escalation_reason(): void
    {
        $r = $this->justifier()->justify(['blast_radius' => 0.9]);

        self::assertContains('high_blast_radius', $r['escalation_reasons']);
    }

    public function test_conflicting_evidence_produces_escalation_reason(): void
    {
        $r = $this->justifier()->justify(['is_conflicting_evidence' => true]);

        self::assertContains('conflicting_evidence_requires_synthesis', $r['escalation_reasons']);
    }

    public function test_high_expected_lift_produces_escalation_reason(): void
    {
        $r = $this->justifier()->justify(['expected_lift' => 0.9]);

        self::assertContains('high_expected_lift', $r['escalation_reasons']);
    }

    public function test_high_architectural_leverage_produces_escalation_reason(): void
    {
        $r = $this->justifier()->justify(['architectural_leverage_score' => 0.9]);

        self::assertContains('high_architectural_leverage', $r['escalation_reasons']);
    }

    public function test_strong_evidence_known_classification_and_low_ambiguity_are_sufficient_for_small_model(): void
    {
        $r = $this->justifier()->justify([
            'evidence_strength' => 0.9,
            'task_classification' => 'known',
            'ambiguity_score' => 0.1,
        ]);

        self::assertSame('small_model', $r['recommended_tier']);
        self::assertSame([], $r['escalation_reasons']);
        self::assertCount(3, $r['small_model_sufficiency_reasons']);
    }

    public function test_excessive_frontier_cost_downgrades_to_scaffolded_small_model(): void
    {
        $r = $this->justifier()->justify([
            'ambiguity_score' => 0.9,
            'estimated_frontier_cost_units' => 100.0,
            'estimated_small_cost_units' => 1.0,
        ]);

        self::assertSame('scaffolded_small_model', $r['recommended_tier']);
        self::assertFalse($r['frontier_cost_justification']['justified']);
    }

    public function test_output_includes_all_required_keys(): void
    {
        $r = $this->justifier()->justify(['ambiguity_score' => 0.9]);

        foreach (['recommended_tier', 'escalation_reasons', 'small_model_sufficiency_reasons', 'frontier_cost_justification', 'confidence'] as $key) {
            self::assertArrayHasKey($key, $r, "Missing key: {$key}");
        }
    }
}
