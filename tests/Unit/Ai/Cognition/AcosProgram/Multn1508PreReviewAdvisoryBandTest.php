<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\Cognition\AcosProgram\PreReviewAdvisoryBand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MULTN15-08 acceptance (§2768-2772).
 */
final class Multn1508PreReviewAdvisoryBandTest extends TestCase
{
    #[Test]
    public function schema_and_formula_are_pinned(): void
    {
        $this->assertSame('atlas.operator.pre_review_advisory_band.v1', PreReviewAdvisoryBand::SCHEMA_VERSION);
        $this->assertSame('atlas.multn15_08.pre_review_band.v1', PreReviewAdvisoryBand::FORMULA_VERSION);
        $this->assertSame(10, PreReviewAdvisoryBand::MIN_N_FOR_BAND);
    }

    #[Test]
    public function n_below_floor_never_emits_a_band(): void
    {
        $out = PreReviewAdvisoryBand::judge([
            'target_class' => 'migrations',
            'risk_band' => 'high',
            'confidence_band' => 'high',
            'similar_revert_rate' => 0.9,
            'n_similar' => 5,
        ]);
        $this->assertSame('insufficient_sample', $out['basis']);
        $this->assertNull($out['predicted_revert_band']);
        $this->assertNull($out['probability']);
    }

    #[Test]
    public function n_equal_or_above_floor_emits_a_band_from_features(): void
    {
        $out = PreReviewAdvisoryBand::judge([
            'target_class' => 'migrations',
            'risk_band' => 'high',
            'confidence_band' => 'low',
            'similar_revert_rate' => 0.80,
            'n_similar' => 20,
        ]);
        $this->assertSame('measured', $out['basis']);
        $this->assertNotNull($out['probability']);
        // 0.80 + 0.08 (high risk) + 0.05 (low conf) = 0.93 → high band
        $this->assertSame('high', $out['predicted_revert_band']);
    }

    #[Test]
    public function low_revert_rate_and_low_risk_lands_in_low_band(): void
    {
        $out = PreReviewAdvisoryBand::judge([
            'target_class' => 'docs',
            'risk_band' => 'low',
            'confidence_band' => 'high',
            'similar_revert_rate' => 0.20,
            'n_similar' => 30,
        ]);
        $this->assertSame('low', $out['predicted_revert_band']);
    }

    #[Test]
    public function missing_rate_returns_insufficient_sample(): void
    {
        $out = PreReviewAdvisoryBand::judge([
            'target_class' => 'unknown',
            'n_similar' => 100,
        ]);
        $this->assertSame('insufficient_sample', $out['basis']);
        $this->assertNull($out['predicted_revert_band']);
    }

    #[Test]
    public function source_charter_encodes_the_advisory_pêtreo(): void
    {
        $out = PreReviewAdvisoryBand::judge([]);
        $src = $out['source'];
        $this->assertFalse($src['blocks_auto_apply']);
        $this->assertFalse($src['delays_auto_apply']);
        $this->assertFalse($src['mutates_pipeline']);
        $this->assertTrue($src['reorders_digest_only']);
    }

    #[Test]
    public function calibration_publishes_denominators_and_never_scalar_only(): void
    {
        $observations = array_merge(
            array_fill(0, 30, ['predicted_revert_band' => 'high', 'reverted' => true]),
            array_fill(0, 30, ['predicted_revert_band' => 'low', 'reverted' => false]),
        );
        $cal = PreReviewAdvisoryBand::calibration($observations);
        $this->assertSame(30, $cal['curve']['high']['n']);
        $this->assertSame(30, $cal['curve']['low']['n']);
        $this->assertEqualsWithDelta(1.0, $cal['curve']['high']['realized_revert_rate'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $cal['curve']['low']['realized_revert_rate'], 1e-9);
        $this->assertSame('measured', $cal['lift_basis']);
        // Death not satisfied: lift = 1.0 - 0.0 = 1.0 >= 0.15 ⇒ family stays alive
        $this->assertFalse($cal['death_criterion']['satisfied_for_death']);
    }

    #[Test]
    public function calibration_death_criterion_triggers_on_non_separating_bands(): void
    {
        // Both bands have similar revert rate ⇒ lift < DEATH_MIN_LIFT
        $observations = array_merge(
            array_fill(0, 30, ['predicted_revert_band' => 'high', 'reverted' => true]),
            array_fill(0, 30, ['predicted_revert_band' => 'high', 'reverted' => false]),
            array_fill(0, 30, ['predicted_revert_band' => 'low', 'reverted' => true]),
            array_fill(0, 30, ['predicted_revert_band' => 'low', 'reverted' => false]),
        );
        $cal = PreReviewAdvisoryBand::calibration($observations);
        $this->assertSame(60, $cal['curve']['high']['n']);
        $this->assertSame(60, $cal['curve']['low']['n']);
        $this->assertEqualsWithDelta(0.0, $cal['lift_high_over_low'], 1e-6);
        $this->assertTrue($cal['death_criterion']['satisfied_for_death']);
    }

    #[Test]
    public function calibration_below_death_min_n_leaves_lift_insufficient(): void
    {
        $observations = [
            ['predicted_revert_band' => 'high', 'reverted' => true],
            ['predicted_revert_band' => 'low', 'reverted' => false],
        ];
        $cal = PreReviewAdvisoryBand::calibration($observations);
        $this->assertSame('insufficient_sample', $cal['lift_basis']);
        $this->assertNull($cal['lift_high_over_low']);
        $this->assertFalse($cal['death_criterion']['satisfied_for_death']);
    }
}
