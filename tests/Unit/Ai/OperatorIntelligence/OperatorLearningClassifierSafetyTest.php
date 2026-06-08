<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\OperatorLearningClassifier;
use Tests\TestCase;

/**
 * The two load-bearing safety properties the adversarial design demanded, proven at the
 * classifier (the independent floor every signal — regex OR LLM — passes through):
 *   (1) privacy is RAISE-ONLY and sensitive claims are REDACTED at rest;
 *   (2) inferred signals are STRUCTURALLY clamped into review territory.
 */
class OperatorLearningClassifierSafetyTest extends TestCase
{
    private function classify(array $input): array
    {
        return (new OperatorLearningClassifier())->classify($input);
    }

    public function test_sensitive_claim_is_redacted_at_rest(): void
    {
        $out = $this->classify(['claim' => 'prefiro tratar dinheiro e documento com cuidado', 'taxonomy_item_id' => 'OP-141']);

        $this->assertSame('sensitive', $out['privacy_class']);
        $this->assertStringStartsWith('[redacted:sensitive:', $out['normalized_claim']);
        $this->assertTrue($out['metadata']['redacted_at_rest']);
    }

    public function test_a_model_normal_cannot_lower_an_inferred_sensitive(): void
    {
        // The model says 'normal' but the content is about saude → raise-only keeps sensitive.
        $out = $this->classify(['claim' => 'minha saude anda instavel ultimamente', 'privacy_class' => 'normal']);

        $this->assertSame('sensitive', $out['privacy_class']);
    }

    public function test_implicit_inference_is_clamped_to_review_even_when_low_risk_normal(): void
    {
        // The exact hole the design found: low-risk, normal-privacy, but inferred — must
        // NOT be allowed to ride a high confidence into auto-eligibility.
        $out = $this->classify([
            'claim' => 'parece que voce prefere respostas curtas',
            'taxonomy_item_id' => 'OP-073',
            'privacy_class' => 'normal',
            'risk_level' => 'low',
            'confidence' => 0.97,
            'inference_type' => 'implicit',
            'confidence_tier' => 'single_inference',
        ]);

        $this->assertLessThanOrEqual(0.6, $out['confidence']);
        $this->assertSame('implicit', $out['metadata']['inference_type']);
    }

    public function test_explicit_declaration_keeps_its_confidence(): void
    {
        $out = $this->classify([
            'claim' => 'prefiro respostas curtas',
            'taxonomy_item_id' => 'OP-073',
            'privacy_class' => 'normal',
            'risk_level' => 'low',
            'confidence' => 0.9,
            'inference_type' => 'explicit',
            'confidence_tier' => 'explicit',
        ]);

        $this->assertEqualsWithDelta(0.9, $out['confidence'], 0.001);
        $this->assertSame('prefiro respostas curtas', $out['normalized_claim']);
    }
}
