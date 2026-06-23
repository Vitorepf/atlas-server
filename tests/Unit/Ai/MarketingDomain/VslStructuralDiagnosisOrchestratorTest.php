<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Decision\OfferDoctorScorer;
use App\Services\Ai\MarketingDomain\Decision\VslAwarenessAlignmentAuditor;
use App\Services\Ai\MarketingDomain\Decision\VslPersuasionAuditService;
use App\Services\Ai\MarketingDomain\Decision\VslStructuralDiagnosisOrchestrator;
use PHPUnit\Framework\TestCase;

class VslStructuralDiagnosisOrchestratorTest extends TestCase
{
    private function orch(): VslStructuralDiagnosisOrchestrator
    {
        return new VslStructuralDiagnosisOrchestrator(
            new VslPersuasionAuditService,
            new VslAwarenessAlignmentAuditor,
            new OfferDoctorScorer,
        );
    }

    public function test_weak_vsl_predicts_which_floor_breaks_before_spend(): void
    {
        // No proof/offer/guarantee/scarcity → checkout_rate floor predicted to break.
        $asset = new AiMarketingVslAsset([
            'transcript' => strtolower('Imagine a better life. You are tired of the struggle. Click the button below.'),
            'awareness_level' => '',
            'problem_mechanism' => 'the cause',
        ]);

        $d = $this->orch()->diagnose($asset);

        $this->assertLessThan(60, $d['structural_strength']);
        $this->assertContains('checkout_rate', $d['expected_floor_breaks']);
        $this->assertNotEmpty($d['predicted_funnel_symptoms']);
        $this->assertNotEmpty($d['prioritized_edit_plan']);
    }

    public function test_strong_vsl_has_high_structural_strength_and_no_breaks(): void
    {
        $transcript = strtolower(
            'What if I told you a shocking secret? You are tired of the struggle and pain. '.
            "It's not your fault — the real reason is hidden. The only breakthrough method, our mechanism, ".
            'is backed by a clinical study, university research, proven results, a doctor and scientist endorse it. '.
            'Order the package today, a free bonus gift. 60-day money-back guarantee, risk-free. '.
            'Limited supplies, hurry, deadline expires. Click the button below to order now. '.
            'P.S. you might be thinking it is too good — thousands of customers just like you joined us together.'
        );

        $asset = new AiMarketingVslAsset([
            'transcript' => $transcript,
            'awareness_level' => 'problem_aware',
            'sophistication_level' => 'unique_mechanism',
            'big_idea' => 'lose 30 pounds in 30 days',
            'core_promise' => 'reverse obesity',
            'problem_mechanism' => 'hidden cause',
            'solution_mechanism' => 'unique protocol',
            'mechanism_name' => 'Method X',
            'offer' => ['price' => 49],
            'claims' => ['clinically proven', 'doctor recommended'],
            'cta' => ['text' => 'order now'],
            'objection_rebuttals' => ['too good' => 'no'],
            'power_phrases' => ['shocking secret'],
            'value_equation' => ['dream_outcome' => 'slim', 'time_delay' => 'days', 'effort_sacrifice' => 'none'],
        ]);

        $d = $this->orch()->diagnose($asset);

        $this->assertGreaterThanOrEqual(80, $d['structural_strength']);
        $this->assertSame([], $d['expected_floor_breaks']);
    }
}
