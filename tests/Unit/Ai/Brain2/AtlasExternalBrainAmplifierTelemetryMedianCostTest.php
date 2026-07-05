<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierTelemetryAggregator;
use PHPUnit\Framework\TestCase;

/**
 * Proves the amplifier telemetry cost gate uses median (outlier-insensitive)
 * instead of mean, so a single expensive run no longer rolls back a healthy
 * model — while a genuinely expensive cohort is still flagged.
 *
 * Before/after contrast (RED before fix, GREEN after):
 *   {10 runs @ 1.0, 1 run @ 110.0}: mean = 10.909 >= 10.0 → rollback_candidate,
 *   median = 1.0 < 5.0 → healthy.
 */
final class AtlasExternalBrainAmplifierTelemetryMedianCostTest extends TestCase
{
    private AtlasExternalBrainAmplifierTelemetryAggregator $aggregator;

    protected function setUp(): void
    {
        $this->aggregator = new AtlasExternalBrainAmplifierTelemetryAggregator;
    }

    /**
     * Baseline: all healthy signals, no cost issue — median gating doesn't
     * introduce false positives.
     */
    private function allHealthy(): array
    {
        return [
            'shadow_pass_rate'         => 0.95,
            'canary_pass_rate'         => 0.90,
            'slo_score'                => 0.85,
            'slo_met'                  => true,
            'scaffold_compliance_rate' => 0.88,
            'replay_pass_rate'         => 0.92,
        ];
    }

    public function test_outlier_spike_does_not_force_rollback_when_typical_cost_is_low(): void
    {
        // 10 runs at cost 1.0 + 1 runaway at 110.0.
        // Mean = 10.909 (>= 10.0 failure ceiling — would block).
        // Median = 1.0 (< 5.0 warning ceiling — healthy).
        $runs = array_merge(
            array_fill(0, 10, ['passed' => true, 'heldout_passed' => true, 'cost' => 1.0]),
            [['passed' => true, 'heldout_passed' => true, 'cost' => 110.0]],
        );
        $input = array_merge($this->allHealthy(), ['runs' => $runs]);

        $result = $this->aggregator->aggregate($input);

        // Median gating keeps it healthy despite the outlier.
        $this->assertSame(
            AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_HEALTHY,
            $result['status'],
            'outlier cohort with typical cost 1.0 must stay healthy under median gating',
        );
        $this->assertSame('healthy', $result['signal_rollup']['cost']);
        $this->assertSame(1.0, $result['median_cost']);
        // avg_cost is still reported (for observability).
        $this->assertGreaterThan($result['median_cost'], $result['avg_cost']);
        $this->assertSame(11, $result['sample_count']);
    }

    public function test_genuinely_expensive_cohort_is_still_flagged_as_blocking(): void
    {
        // 10 runs at cost 12.0 → median = 12.0 >= 10.0 failure ceiling.
        $runs = array_fill(0, 10, ['passed' => true, 'heldout_passed' => true, 'cost' => 12.0]);
        $input = array_merge($this->allHealthy(), ['runs' => $runs]);

        $result = $this->aggregator->aggregate($input);

        $this->assertSame(
            AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE,
            $result['status'],
            'genuinely expensive cohort must still force rollback',
        );
        $this->assertSame('blocking', $result['signal_rollup']['cost']);
        $this->assertSame(12.0, $result['median_cost']);
        $this->assertNotEmpty(
            array_filter($result['blocking_reasons'], fn (string $r): bool => str_contains($r, 'median_cost')),
        );
    }

    public function test_median_cost_at_warning_ceiling_triggers_watch(): void
    {
        // 5 runs at cost 6.0 → median = 6.0 >= 5.0 warning ceiling.
        $runs = array_fill(0, 5, ['passed' => true, 'heldout_passed' => true, 'cost' => 6.0]);
        $input = array_merge($this->allHealthy(), ['runs' => $runs]);

        $result = $this->aggregator->aggregate($input);

        $this->assertSame(
            AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_WATCH,
            $result['status'],
            'median cost above warning ceiling must trigger watch',
        );
        $this->assertSame('watch', $result['signal_rollup']['cost']);
        $this->assertSame(6.0, $result['median_cost']);
    }

    public function test_median_cost_is_surfaced_in_output(): void
    {
        $input = array_merge($this->allHealthy(), [
            'runs' => [
                ['passed' => true,  'heldout_passed' => true, 'cost' => 2.0],
                ['passed' => true,  'heldout_passed' => true, 'cost' => 4.0],
                ['passed' => false, 'heldout_passed' => true, 'cost' => 8.0],
            ],
        ]);

        $result = $this->aggregator->aggregate($input);

        // Costs sorted: [2.0, 4.0, 8.0]; odd count → middle = 4.0
        $this->assertArrayHasKey('median_cost', $result);
        $this->assertSame(4.0, $result['median_cost']);
        // avg_cost remains present.
        $this->assertArrayHasKey('avg_cost', $result);
    }
}
