<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Context\Aucri;

use App\Services\Ai\Context\Aucri\AucriTokenQualityVerdict;
use Tests\TestCase;

final class AucriTokenQualityVerdictTest extends TestCase
{
    private AucriTokenQualityVerdict $verdict;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verdict = new AucriTokenQualityVerdict();
    }

    public function testTokensDroppedWithAllGatesPassingPromotes(): void
    {
        $before = 1000;
        $after = 600;
        $expectedRatio = round(($before - $after) / $before, 3);

        $result = $this->verdict->decide($before, $after, 0.80, 0.85, 1.0, 0.70, 0.75);

        $this->assertSame('atlas.aucri.token_quality_verdict.v1', $result['schema_version']);
        $this->assertSame('promote', $result['verdict']);
        $this->assertSame($expectedRatio, $result['token_reduction_ratio']);
        $this->assertGreaterThan(0.0, $result['token_reduction_ratio']);
        $this->assertSame(['safe_token_reduction'], $result['reasons']);
    }

    public function testTokensDroppedButMustKeepCoverageBelowOneReverts(): void
    {
        $before = 1000;
        $after = 600;
        $expectedRatio = round(($before - $after) / $before, 3);

        $result = $this->verdict->decide($before, $after, 0.80, 0.85, 0.9, 0.70, 0.75);

        $this->assertSame('revert', $result['verdict']);
        $this->assertSame(['must_keep_below_1_0'], $result['reasons']);
        // The reduction is real (0.4) yet the hard veto rejects this false saving.
        $this->assertSame($expectedRatio, $result['token_reduction_ratio']);
        $this->assertGreaterThan(0.0, $result['token_reduction_ratio']);
    }

    public function testTokensDroppedButQualityRegressedReverts(): void
    {
        $before = 1000;
        $after = 600;
        $expectedRatio = round(($before - $after) / $before, 3);

        $result = $this->verdict->decide($before, $after, 0.90, 0.70, 1.0, 0.70, 0.75);

        $this->assertSame('revert', $result['verdict']);
        $this->assertSame(['quality_regressed'], $result['reasons']);
        $this->assertSame($expectedRatio, $result['token_reduction_ratio']);
    }

    public function testNoTokenReductionYieldsNoChangeWithZeroRatio(): void
    {
        // after == before: no reduction, no_change wins ahead of any veto path.
        $result = $this->verdict->decide(500, 500, 0.80, 0.85, 1.0, 0.70, 0.75);

        $this->assertSame('no_change', $result['verdict']);
        $this->assertSame(['no_token_reduction'], $result['reasons']);
        $this->assertSame(0.0, $result['token_reduction_ratio']);
    }

    public function testTokensGrewStillNoChangeAndRatioClampedToZero(): void
    {
        // after > before: the ratio must never go negative; it stays at 0.0
        // and the verdict is no_change even though must_keep would otherwise veto.
        $result = $this->verdict->decide(400, 600, 0.80, 0.85, 0.5, 0.70, 0.40);

        $this->assertSame('no_change', $result['verdict']);
        $this->assertSame(['no_token_reduction'], $result['reasons']);
        $this->assertSame(0.0, $result['token_reduction_ratio']);
    }

    public function testTokensDroppedButEvidenceCoverageRegressedReverts(): void
    {
        $before = 1000;
        $after = 600;
        $expectedRatio = round(($before - $after) / $before, 3);

        $result = $this->verdict->decide($before, $after, 0.80, 0.85, 1.0, 0.80, 0.60);

        $this->assertSame('revert', $result['verdict']);
        $this->assertSame(['evidence_coverage_regressed'], $result['reasons']);
        $this->assertSame($expectedRatio, $result['token_reduction_ratio']);
    }

    public function testAllVetoesAccumulateWithMustKeepListedFirst(): void
    {
        // must_keep + quality + evidence all fail simultaneously while tokens drop.
        $result = $this->verdict->decide(1000, 250, 0.90, 0.40, 0.5, 0.80, 0.10);

        $this->assertSame('revert', $result['verdict']);
        $this->assertSame(
            ['must_keep_below_1_0', 'quality_regressed', 'evidence_coverage_regressed'],
            $result['reasons'],
        );
    }

    public function testReductionRatioNeverExceedsOneAtMaximumSaving(): void
    {
        // after = 0 is the maximum possible saving; ratio must cap at exactly 1.0.
        $before = 1000;
        $result = $this->verdict->decide($before, 0, 0.80, 0.90, 1.0, 0.70, 0.80);

        $this->assertSame('promote', $result['verdict']);
        $this->assertSame(round($before / $before, 3), $result['token_reduction_ratio']);
        $this->assertSame(1.0, $result['token_reduction_ratio']);
        $this->assertLessThanOrEqual(1.0, $result['token_reduction_ratio']);
    }

    public function testRatioStaysWithinUpperBoundOnMalformedNegativeAfter(): void
    {
        // A malformed negative "after" makes the saved gap (before - after)
        // exceed "before"; the field must still honour its documented 0..1 cap
        // rather than reporting an impossible > 1.0 reduction ratio.
        $result = $this->verdict->decide(1000, -500, 0.80, 0.90, 1.0, 0.70, 0.80);

        $this->assertLessThanOrEqual(1.0, $result['token_reduction_ratio']);
        $this->assertGreaterThanOrEqual(0.0, $result['token_reduction_ratio']);
        $this->assertSame(1.0, $result['token_reduction_ratio']);
    }

    public function testSubThresholdDropWithoutVetoIsNoChangeNotPromote(): void
    {
        // Tokens fall by 1 of 10000: the ratio rounds to 0.0 at three decimals.
        // Rule (6) requires ratio > 0 to promote, so a "safe_token_reduction"
        // verdict here would be self-contradictory (promote with zero reduction).
        $before = 10000;
        $after = 9999;
        $this->assertSame(0.0, round(($before - $after) / $before, 3));

        $result = $this->verdict->decide($before, $after, 0.80, 0.85, 1.0, 0.70, 0.70);

        $this->assertSame('no_change', $result['verdict']);
        $this->assertSame(['no_token_reduction'], $result['reasons']);
        $this->assertSame(0.0, $result['token_reduction_ratio']);
    }

    public function testSubThresholdDropStillRevertsWhenAVetoFires(): void
    {
        // A sub-threshold drop (ratio rounds to 0.0) must not let a hard veto
        // slip through as no_change: the must_keep veto still forces revert.
        $result = $this->verdict->decide(10000, 9999, 0.80, 0.85, 0.9, 0.70, 0.70);

        $this->assertSame('revert', $result['verdict']);
        $this->assertSame(['must_keep_below_1_0'], $result['reasons']);
        $this->assertSame(0.0, $result['token_reduction_ratio']);
    }

    public function testRatioGeneralisesAcrossArbitraryInputs(): void
    {
        // Inputs intentionally unlike any promote case above; the rule computes
        // the ratio from the formula rather than returning a canned value.
        $before = 880;
        $after = 517;
        $expectedRatio = round(($before - $after) / $before, 3);

        $result = $this->verdict->decide($before, $after, 0.55, 0.55, 1.0, 0.42, 0.42);

        $this->assertSame('promote', $result['verdict']);
        $this->assertSame($expectedRatio, $result['token_reduction_ratio']);
        $this->assertSame(0.413, $result['token_reduction_ratio']);
    }
}
