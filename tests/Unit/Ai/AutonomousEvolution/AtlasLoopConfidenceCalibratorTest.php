<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopConfidenceCalibrator;
use PHPUnit\Framework\TestCase;

/**
 * Absurd-leap 3 — the calibration flywheel. Derives the honest gate threshold from REAL outcomes; flags
 * overconfidence; returns null when no threshold reaches the target precision (insufficient evidence to arm).
 */
final class AtlasLoopConfidenceCalibratorTest extends TestCase
{
    public function test_no_samples_cannot_arm(): void
    {
        $r = (new AtlasLoopConfidenceCalibrator)->calibrate([]);
        $this->assertSame(0, $r['n']);
        $this->assertNull($r['recommended_threshold']);
        $this->assertFalse($r['well_calibrated']);
    }

    public function test_well_calibrated_high_confidence_recommends_an_arm_threshold(): void
    {
        // 30 high-confidence (0.95) samples, 29 correct (~0.967 precision >= 0.93) => arm at 0.95.
        $samples = [];
        for ($i = 0; $i < 30; $i++) {
            $samples[] = ['predicted' => 0.95, 'correct' => $i !== 0];
        }
        // plus 30 low-confidence (0.4) mostly-wrong samples (correctly NOT armed)
        for ($i = 0; $i < 30; $i++) {
            $samples[] = ['predicted' => 0.4, 'correct' => $i < 5];
        }
        $r = (new AtlasLoopConfidenceCalibrator)->calibrate($samples, 0.93, 20);
        $this->assertNotNull($r['recommended_threshold']);
        $this->assertEqualsWithDelta(0.95, $r['recommended_threshold'], 0.0001);
        $this->assertStringContainsString('arm_at:', $r['reason']);
    }

    public function test_overconfident_model_is_flagged_and_cannot_arm(): void
    {
        // Model says 0.95 everywhere but only ~50% are actually correct => overconfident, no honest arm.
        $samples = [];
        for ($i = 0; $i < 40; $i++) {
            $samples[] = ['predicted' => 0.95, 'correct' => $i % 2 === 0];
        }
        $r = (new AtlasLoopConfidenceCalibrator)->calibrate($samples, 0.93, 20);
        $this->assertGreaterThan(0.3, $r['overconfidence'], 'predicted 0.95 vs observed ~0.5 = large overconfidence');
        $this->assertFalse($r['well_calibrated']);
        $this->assertNull($r['recommended_threshold'], 'an overconfident model cannot honestly arm the gate');
    }

    public function test_insufficient_samples_at_threshold_cannot_arm(): void
    {
        // Only 5 samples (below minSamples=20) — even if all correct, not enough evidence to arm.
        $samples = [];
        for ($i = 0; $i < 5; $i++) {
            $samples[] = ['predicted' => 0.99, 'correct' => true];
        }
        $r = (new AtlasLoopConfidenceCalibrator)->calibrate($samples, 0.93, 20);
        $this->assertNull($r['recommended_threshold'], 'too few samples => insufficient evidence to arm');
    }

    public function test_reliability_buckets_are_emitted(): void
    {
        $samples = [
            ['predicted' => 0.95, 'correct' => true],
            ['predicted' => 0.92, 'correct' => true],
            ['predicted' => 0.35, 'correct' => false],
        ];
        $r = (new AtlasLoopConfidenceCalibrator)->calibrate($samples);
        $this->assertNotEmpty($r['buckets']);
    }
}
