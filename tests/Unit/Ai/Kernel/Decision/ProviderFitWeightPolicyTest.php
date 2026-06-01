<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Kernel\Decision;

use App\Services\Ai\Kernel\Decision\ProviderFitWeightPolicy;
use PHPUnit\Framework\TestCase;

final class ProviderFitWeightPolicyTest extends TestCase
{
    private ProviderFitWeightPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ProviderFitWeightPolicy();
    }

    public function testReturnShapeMatchesSchemaWithFourFloatWeights(): void
    {
        $weights = $this->policy->weights('high', 'medium', null);

        $this->assertSame('atlas.aaeos.provider_fit_weights.v1', $weights['schema_version']);
        $this->assertIsFloat($weights['quality']);
        $this->assertIsFloat($weights['latency']);
        $this->assertIsFloat($weights['cost']);
        $this->assertIsFloat($weights['sample']);

        foreach (['quality', 'latency', 'cost', 'sample'] as $dimension) {
            $this->assertGreaterThanOrEqual(0.0, $weights[$dimension]);
            $this->assertLessThanOrEqual(1.0, $weights[$dimension]);
        }
    }

    public function testCriticalRiskMakesQualityStrictlyLargestAndCostStrictlySmallest(): void
    {
        $weights = $this->policy->weights('critical', 'high', null);

        $this->assertGreaterThan($weights['latency'], $weights['quality']);
        $this->assertGreaterThan($weights['cost'], $weights['quality']);
        $this->assertGreaterThan($weights['sample'], $weights['quality']);

        $this->assertLessThan($weights['quality'], $weights['cost']);
        $this->assertLessThan($weights['latency'], $weights['cost']);
        $this->assertLessThan($weights['sample'], $weights['cost']);
    }

    public function testCheapFastLaneFavoursLatencyAndCostAndCostsMoreThanPremium(): void
    {
        $cheapFast = $this->policy->weights('medium', 'medium', 'cheap_fast');
        $premium = $this->policy->weights('medium', 'medium', 'premium');

        $this->assertGreaterThan(
            $cheapFast['quality'] + $cheapFast['sample'],
            $cheapFast['latency'] + $cheapFast['cost'],
        );

        $this->assertGreaterThan($premium['cost'], $cheapFast['cost']);
    }

    public function testWeightsAlwaysSumToOneAcrossDistinctCombos(): void
    {
        $combos = [
            ['critical', 'high', null],
            ['low', 'low', null],
            ['medium', 'medium', 'cheap_fast'],
            ['high', 'low', 'premium'],
        ];

        foreach ($combos as [$risk, $complexity, $lane]) {
            $weights = $this->policy->weights($risk, $complexity, $lane);
            $sum = $weights['quality'] + $weights['latency'] + $weights['cost'] + $weights['sample'];

            $this->assertEqualsWithDelta(1.0, $sum, 1e-9);
        }
    }

    public function testPremiumLaneAtLowRiskMakesQualityStrictlyExceedLatencyAndCost(): void
    {
        $weights = $this->policy->weights('low', 'medium', 'premium');

        $this->assertGreaterThan($weights['latency'], $weights['quality']);
        $this->assertGreaterThan($weights['cost'], $weights['quality']);
    }

    public function testUnknownRiskFallsBackToNeutralProfile(): void
    {
        $neutral = $this->policy->weights('medium', 'medium', null);

        $this->assertSame($neutral, $this->policy->weights('totally-unknown', 'high', 'premium'));
    }

    public function testUnknownComplexityFallsBackToNeutralProfile(): void
    {
        $neutral = $this->policy->weights('medium', 'medium', null);

        $this->assertSame($neutral, $this->policy->weights('critical', '', 'cheap_fast'));
    }

    public function testDeterministicForIdenticalInput(): void
    {
        $first = $this->policy->weights('high', 'high', 'premium');
        $second = $this->policy->weights('high', 'high', 'premium');

        $this->assertSame($first, $second);
    }
}
