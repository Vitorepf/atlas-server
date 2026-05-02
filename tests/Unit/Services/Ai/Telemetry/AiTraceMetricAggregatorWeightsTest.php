<?php

namespace Tests\Unit\Services\Ai\Telemetry;

use App\Services\Ai\Telemetry\AiTraceMetricAggregator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class AiTraceMetricAggregatorWeightsTest extends TestCase
{
    public function test_quality_score_weights_constant_exists_and_sums_to_one(): void
    {
        $reflection = new ReflectionClass(AiTraceMetricAggregator::class);

        $this->assertTrue(
            $reflection->hasConstant('QUALITY_SCORE_WEIGHTS'),
            'Aggregator must expose QUALITY_SCORE_WEIGHTS so weights are inspectable and testable.'
        );

        $weights = $reflection->getConstant('QUALITY_SCORE_WEIGHTS');

        $this->assertIsArray($weights);
        $this->assertSame(
            ['auto_quality', 'continuity', 'human_feedback', 'outcome', 'remediation'],
            array_keys($weights),
            'Weight keys define the contract — components consumed by the aggregator.'
        );

        $sum = array_sum($weights);
        $this->assertEqualsWithDelta(
            1.0,
            $sum,
            0.0001,
            "QUALITY_SCORE_WEIGHTS must sum to 1.00 (got {$sum}). Sums other than 1.0 obscure the true contribution of each component."
        );

        foreach ($weights as $component => $weight) {
            $this->assertGreaterThan(0.0, $weight, "Weight for {$component} must be positive.");
            $this->assertLessThan(1.0, $weight, "Weight for {$component} must be below 1.0.");
        }
    }

    public function test_quality_score_weights_preserve_legacy_ratios_within_tolerance(): void
    {
        // Legacy weights (sum 1.10) implied effective weights of legacy/1.10 per component
        // when all components were present. New weights must sit within ±0.01 of those
        // effective values so historical scores remain comparable post-cutover.
        $legacyEffective = [
            'auto_quality'   => 0.45 / 1.10,
            'continuity'     => 0.20 / 1.10,
            'human_feedback' => 0.20 / 1.10,
            'outcome'        => 0.15 / 1.10,
            'remediation'    => 0.10 / 1.10,
        ];

        $weights = (new ReflectionClass(AiTraceMetricAggregator::class))
            ->getConstant('QUALITY_SCORE_WEIGHTS');

        foreach ($legacyEffective as $component => $expected) {
            $this->assertEqualsWithDelta(
                $expected,
                $weights[$component],
                0.01,
                "Weight for {$component} must stay within 0.01 of legacy effective weight ({$expected}) "
                    .'to keep historical scores comparable.'
            );
        }
    }
}
