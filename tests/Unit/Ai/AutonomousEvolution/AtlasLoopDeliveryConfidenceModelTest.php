<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopDeliveryConfidenceModel;
use Tests\TestCase;

/**
 * Lever 2 — calibrated delivery confidence. A principled P(correct) over the cert's measurable signals,
 * gated at a threshold (default 0.93). Hard zero when behaviour is not preserved; calibratable weights.
 */
final class AtlasLoopDeliveryConfidenceModelTest extends TestCase
{
    private function fullyGreen(): array
    {
        return [
            'behavior_preserved' => true,
            'diff_earned' => true,
            'sealed_holdout_passed' => true,
            'complexity_reduced' => true,
            'cross_file_consumers_ok' => true,
            'mutation_kill_ratio' => 1.0,
            'quality_score' => 10.0,
            'adversarial_refuted_count' => 0,
        ];
    }

    public function test_behavior_not_preserved_is_zero_confidence(): void
    {
        $r = (new AtlasLoopDeliveryConfidenceModel)->estimate(['behavior_preserved' => false, 'mutation_kill_ratio' => 1.0]);
        $this->assertSame(0.0, $r['confidence']);
        $this->assertFalse($r['passes']);
        $this->assertContains('behavior_not_preserved', $r['reasons']);
    }

    public function test_fully_green_cert_clears_the_93_threshold(): void
    {
        $r = (new AtlasLoopDeliveryConfidenceModel)->estimate($this->fullyGreen());
        $this->assertGreaterThanOrEqual(0.93, $r['confidence'], 'an all-gates-green, strong-suite, high-quality delivery is high-confidence');
        $this->assertTrue($r['passes']);
        $this->assertSame(0.93, $r['threshold']);
    }

    public function test_half_evidenced_delivery_is_below_threshold(): void
    {
        $r = (new AtlasLoopDeliveryConfidenceModel)->estimate([
            'behavior_preserved' => true,
            'diff_earned' => true,
            'sealed_holdout_passed' => false,
            'complexity_reduced' => false,
            'cross_file_consumers_ok' => false,
            'mutation_kill_ratio' => 0.5,
            'quality_score' => 6.0,
            'adversarial_refuted_count' => 0,
        ]);
        $this->assertLessThan(0.93, $r['confidence'], 'a half-evidenced delivery must NOT clear the bar');
        $this->assertFalse($r['passes']);
        $this->assertContains('confidence_below_threshold:'.$r['confidence'].'<0.93', $r['reasons']);
    }

    public function test_two_adversarial_refutations_collapse_confidence(): void
    {
        $signals = $this->fullyGreen();
        $signals['adversarial_refuted_count'] = 2;
        $r = (new AtlasLoopDeliveryConfidenceModel)->estimate($signals);
        $this->assertLessThan(0.93, $r['confidence'], 'refuted evidence sharply lowers confidence');
        $this->assertFalse($r['passes']);
    }

    public function test_threshold_and_weights_are_calibratable_via_config(): void
    {
        config(['atlas.loop.confidence_model.threshold' => 0.5]);
        $r = (new AtlasLoopDeliveryConfidenceModel)->estimate([
            'behavior_preserved' => true,
            'diff_earned' => true,
            'mutation_kill_ratio' => 0.5,
            'quality_score' => 6.0,
        ]);
        // Same half-evidenced signals now PASS under a lower (calibrated) threshold.
        $this->assertTrue($r['passes']);
        $this->assertSame(0.5, $r['threshold']);
    }
}
