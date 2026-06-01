<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Kernel\Decision;

use App\Services\Ai\Kernel\Decision\ProviderFitScoreCombiner;
use PHPUnit\Framework\TestCase;

final class ProviderFitScoreCombinerTest extends TestCase
{
    private ProviderFitScoreCombiner $combiner;

    protected function setUp(): void
    {
        $this->combiner = new ProviderFitScoreCombiner();
    }

    public function testReturnShapeMatchesSchema(): void
    {
        $result = $this->combiner->score(
            ['quality' => 80, 'latency' => 80, 'cost' => 80, 'sample' => 80],
            ['quality' => 0.25, 'latency' => 0.25, 'cost' => 0.25, 'sample' => 0.25],
            10,
        );

        $this->assertSame('atlas.aaeos.provider_fit_score.v1', $result['schema_version']);
        $this->assertSame(80, $result['score']);
        $this->assertSame('strong', $result['band']);
        $this->assertFalse($result['confidence_dampened']);
        $this->assertSame(
            ['quality' => 0.25, 'latency' => 0.25, 'cost' => 0.25, 'sample' => 0.25],
            $result['effective_weights'],
        );
    }

    public function testZeroSampleForcesUntrustedAndDampenedEvenWithPerfectFactors(): void
    {
        $result = $this->combiner->score(
            ['quality' => 100, 'latency' => 100, 'cost' => 100, 'sample' => 100],
            ['quality' => 0.4, 'latency' => 0.2, 'cost' => 0.2, 'sample' => 0.2],
            0,
        );

        $this->assertSame('untrusted', $result['band']);
        $this->assertTrue($result['confidence_dampened']);
        $this->assertNotSame('strong', $result['band']);
        // sampleSize 0 collapses the score fully toward the neutral anchor.
        $this->assertSame(50, $result['score']);
    }

    public function testHigherQualityWithEqualOtherFactorsBeatsLowerCandidate(): void
    {
        $weights = ['quality' => 0.4, 'latency' => 0.2, 'cost' => 0.2, 'sample' => 0.2];

        $candidateA = $this->combiner->score(
            ['quality' => 90, 'latency' => 60, 'cost' => 60, 'sample' => 60],
            $weights,
            10,
        );

        $candidateB = $this->combiner->score(
            ['quality' => 70, 'latency' => 60, 'cost' => 60, 'sample' => 60],
            $weights,
            10,
        );

        $this->assertSame(72, $candidateA['score']);
        $this->assertSame(64, $candidateB['score']);
        $this->assertGreaterThan($candidateB['score'], $candidateA['score']);
    }

    public function testLowSampleDampensTowardNeutralAnchorRelativeToHighSample(): void
    {
        $factors = ['quality' => 90, 'latency' => 90, 'cost' => 90, 'sample' => 90];
        $weights = ['quality' => 0.25, 'latency' => 0.25, 'cost' => 0.25, 'sample' => 0.25];

        $lowSample = $this->combiner->score($factors, $weights, 2);
        $highSample = $this->combiner->score($factors, $weights, 50);

        $this->assertSame(66, $lowSample['score']);
        $this->assertSame(90, $highSample['score']);
        $this->assertLessThan(
            abs($highSample['score'] - 50),
            abs($lowSample['score'] - 50),
        );
        $this->assertTrue($lowSample['confidence_dampened']);
        $this->assertFalse($highSample['confidence_dampened']);
    }

    public function testWeightedMeanOfEqualFactorsIsInvariantToWeights(): void
    {
        $factors = ['quality' => 80, 'latency' => 80, 'cost' => 80, 'sample' => 80];

        $skewed = $this->combiner->score(
            $factors,
            ['quality' => 0.7, 'latency' => 0.1, 'cost' => 0.1, 'sample' => 0.1],
            8,
        );

        $balanced = $this->combiner->score(
            $factors,
            ['quality' => 0.25, 'latency' => 0.25, 'cost' => 0.25, 'sample' => 0.25],
            8,
        );

        $this->assertSame(80, $skewed['score']);
        $this->assertSame(80, $balanced['score']);
        $this->assertFalse($skewed['confidence_dampened']);
    }

    public function testOutOfRangeFactorsAreClampedBeforeWeighting(): void
    {
        $weights = ['quality' => 0.25, 'latency' => 0.25, 'cost' => 0.25, 'sample' => 0.25];

        $outOfRange = $this->combiner->score(
            ['quality' => 130, 'latency' => 50, 'cost' => -10, 'sample' => 50],
            $weights,
            10,
        );

        $clamped = $this->combiner->score(
            ['quality' => 100, 'latency' => 50, 'cost' => 0, 'sample' => 50],
            $weights,
            10,
        );

        $this->assertSame($clamped['score'], $outOfRange['score']);
        $this->assertSame(50, $outOfRange['score']);
    }

    public function testAllZeroWeightsFallBackToEqualQuarterWithoutDivideByZero(): void
    {
        $factors = ['quality' => 90, 'latency' => 30, 'cost' => 60, 'sample' => 20];

        $result = $this->combiner->score(
            $factors,
            ['quality' => 0.0, 'latency' => 0.0, 'cost' => 0.0, 'sample' => 0.0],
            10,
        );

        $arithmeticMean = (int) round((90 + 30 + 60 + 20) / 4);

        $this->assertSame(
            ['quality' => 0.25, 'latency' => 0.25, 'cost' => 0.25, 'sample' => 0.25],
            $result['effective_weights'],
        );
        $this->assertSame($arithmeticMean, $result['score']);
        $this->assertSame(50, $result['score']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $factors = ['quality' => 73, 'latency' => 41, 'cost' => 88, 'sample' => 12];
        $weights = ['quality' => 0.5, 'latency' => 0.2, 'cost' => 0.2, 'sample' => 0.1];

        $first = $this->combiner->score($factors, $weights, 7);
        $second = $this->combiner->score($factors, $weights, 7);

        $this->assertSame($first, $second);
    }
}
