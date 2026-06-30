<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCalibratedConfidenceGate;
use PHPUnit\Framework\TestCase;

/**
 * ACDE DG1 — the calibrated-abstention merge gate. Exercises the PURE core (recommendFrom over real
 * {predicted, correct} samples via the calibrator; wouldAbstain decision) with no DB and no container, so it is
 * hang-free and deterministic. Proves the honest threshold is FIT from outcomes (not declared) and that
 * abstention is below-band only.
 */
final class AtlasLoopCalibratedConfidenceGateTest extends TestCase
{
    private function gate(): AtlasLoopCalibratedConfidenceGate
    {
        return new AtlasLoopCalibratedConfidenceGate;
    }

    /** @return list<array{predicted:float, correct:bool}> */
    private function samples(float $predicted, bool $correct, int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = ['predicted' => $predicted, 'correct' => $correct];
        }

        return $out;
    }

    public function test_fits_the_threshold_from_real_outcomes(): void
    {
        // High-confidence predictions land (precision 1.0); low-confidence ones miss. The honest arm-point is
        // the deepest T whose {predicted >= T} subset still clears target precision with enough samples.
        $samples = array_merge(
            $this->samples(0.95, true, 6),   // 6 correct at 0.95
            $this->samples(0.55, false, 6),  // 6 wrong at 0.55
        );

        $t = $this->gate()->recommendFrom($samples, 0.9, 4);

        $this->assertNotNull($t);
        $this->assertSame(0.95, $t, 'the deepest T clearing 0.9 precision with >=4 samples is 0.95');
    }

    public function test_returns_null_when_too_few_samples_to_arm(): void
    {
        // Only 3 samples at the high band but minSamples is 4 => no band clears => null (fail-open upstream).
        $t = $this->gate()->recommendFrom($this->samples(0.95, true, 3), 0.9, 4);

        $this->assertNull($t);
    }

    public function test_returns_null_when_no_threshold_reaches_target_precision(): void
    {
        // Everything is a coin-flip at one confidence level => no T reaches 0.9 precision => null.
        $samples = array_merge($this->samples(0.8, true, 10), $this->samples(0.8, false, 10));

        $this->assertNull($this->gate()->recommendFrom($samples, 0.9, 4));
    }

    public function test_would_abstain_is_below_band_only(): void
    {
        $g = $this->gate();
        $this->assertTrue($g->wouldAbstain(0.60, 0.95), 'below the calibrated band => abstain');
        $this->assertFalse($g->wouldAbstain(0.95, 0.95), 'at the band => merge');
        $this->assertFalse($g->wouldAbstain(0.99, 0.95), 'above the band => merge');
        $this->assertFalse($g->wouldAbstain(0.10, null), 'no calibrated threshold => never abstain');
        $this->assertFalse($g->wouldAbstain('not-a-number', 0.95), 'no numeric prediction => never abstain');
    }

    public function test_recommend_from_with_reason_distinguishes_too_few_samples_from_uncalibrated(): void
    {
        $g = $this->gate();

        // Too few total samples (3 < minSamples 4) => too_few_samples.
        $r = $g->recommendFromWithReason($this->samples(0.95, true, 3), 0.9, 4);
        $this->assertNull($r['threshold']);
        $this->assertSame('too_few_samples', $r['null_reason']);

        // Enough samples but coin-flip precision => no band clears target => uncalibrated_model.
        $r2 = $g->recommendFromWithReason(
            array_merge($this->samples(0.8, true, 10), $this->samples(0.8, false, 10)),
            0.9, 4
        );
        $this->assertNull($r2['threshold']);
        $this->assertSame('uncalibrated_model', $r2['null_reason']);

        // Happy path — threshold found => null_reason is null.
        $r3 = $g->recommendFromWithReason(
            array_merge($this->samples(0.95, true, 6), $this->samples(0.55, false, 6)),
            0.9, 4
        );
        $this->assertNotNull($r3['threshold']);
        $this->assertNull($r3['null_reason']);
    }

}
