<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Attribution;

use App\Services\Ai\AutonomousEvolution\Attribution\AtlasLoopCapabilityDeltaAttributionService;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasLoopCapabilityDeltaAttributionService attributes measured behavior-Δ back to its origination
 * shape with a Wilson small-sample floor: a thin shape is never credited, a proven positive shape is, the
 * output is deterministic, and `credited` is COMPUTED (never a caller-supplied grade).
 */
final class AtlasLoopCapabilityDeltaAttributionServiceTest extends TestCase
{
    /** @param list<array<string,mixed>> $deliveries */
    private function attribute(array $deliveries, int $minSamples = 5): array
    {
        return (new AtlasLoopCapabilityDeltaAttributionService($minSamples))->attribute($deliveries);
    }

    /** @return list<array<string,mixed>> */
    private function deliveries(string $shape, int $count, int $delta): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = ['shape_token' => $shape, 'originator_id' => 'o'.$i, 'net_behavior_delta' => $delta, 'objective_class' => 'feature'];
        }

        return $out;
    }

    private function row(array $result, string $shape): ?array
    {
        foreach ($result['by_shape'] as $r) {
            if ($r['shape_token'] === $shape) {
                return $r;
            }
        }

        return null;
    }

    public function test_thin_shape_below_min_samples_is_never_credited(): void
    {
        // 3 strongly-positive samples, but minSamples=5 ⇒ NOT credited regardless of mean.
        $result = $this->attribute($this->deliveries('shape_thin', 3, 9));
        $row = $this->row($result, 'shape_thin');

        $this->assertNotNull($row);
        $this->assertSame(3, $row['samples']);
        $this->assertSame(9.0, $row['mean_delta']);
        $this->assertFalse($row['credited'], 'a thin shape never earns credit, however high its mean');
    }

    public function test_proven_positive_shape_is_credited_via_wilson(): void
    {
        $result = $this->attribute($this->deliveries('shape_strong', 6, 4));
        $row = $this->row($result, 'shape_strong');

        $this->assertNotNull($row);
        $this->assertSame(6, $row['samples']);
        $this->assertGreaterThan(0.0, $row['wilson_lower_bound'], 'positive-Δ rate has a Wilson LB above zero');
        $this->assertTrue($row['credited']);
    }

    public function test_zero_delta_shape_with_enough_samples_is_not_credited(): void
    {
        // 6 samples, all net_behavior_delta=0 ⇒ zero positives ⇒ Wilson LB 0 ⇒ not credited (no spurious credit).
        $result = $this->attribute($this->deliveries('shape_flat', 6, 0));
        $row = $this->row($result, 'shape_flat');

        $this->assertNotNull($row);
        $this->assertSame(0.0, $row['wilson_lower_bound']);
        $this->assertFalse($row['credited']);
    }

    public function test_deterministic_and_ignores_caller_supplied_grade(): void
    {
        $deliveries = $this->deliveries('shape_x', 5, 3);
        // A malicious delivery tries to hand itself a grade — it MUST be ignored.
        $deliveries[0]['credited'] = true;
        $deliveries[0]['wilson_lower_bound'] = 0.99;
        $deliveries[0]['net_behavior_delta'] = 3; // only the measured delta is read

        $run1 = $this->attribute($deliveries);
        $run2 = $this->attribute($deliveries);
        $this->assertSame(json_encode($run1), json_encode($run2), 'attribution is deterministic');

        $row = $this->row($run1, 'shape_x');
        $this->assertNotNull($row);
        // credited/wilson are COMPUTED from samples, not taken from the caller's injected fields.
        $this->assertSame(5, $row['samples']);
        $this->assertTrue($row['credited']);
        $this->assertNotSame(0.99, $row['wilson_lower_bound'], 'the caller-supplied wilson value was ignored');
    }

    // ── AC: capability_delta, confidence, sample_count, counterfactual_baseline, false_causality_warning, rationale ──

    public function test_output_includes_all_causal_attribution_fields(): void
    {
        $result = $this->attribute($this->deliveries('shape_x', 6, 4));
        $row = $this->row($result, 'shape_x');

        $this->assertNotNull($row);
        foreach (['capability_delta', 'confidence', 'sample_count', 'counterfactual_baseline', 'false_causality_warning', 'rationale'] as $key) {
            $this->assertArrayHasKey($key, $row, "missing field: {$key}");
        }
        $this->assertSame($row['samples'], $row['sample_count']);
        $this->assertSame($row['mean_delta'], $row['capability_delta']);
        $this->assertIsString($row['rationale']);
        $this->assertNotSame('', $row['rationale']);
    }

    public function test_enough_samples_credited_shape_beats_the_counterfactual_baseline_is_not_flagged(): void
    {
        // Every other shape is flat/negative (baseline ~0); shape_strong is decisively positive => real signal.
        $deliveries = array_merge(
            $this->deliveries('shape_strong', 8, 5),
            $this->deliveries('shape_flat_a', 8, -1),
            $this->deliveries('shape_flat_b', 8, 0),
        );
        $row = $this->row($this->attribute($deliveries), 'shape_strong');

        $this->assertNotNull($row);
        $this->assertTrue($row['credited']);
        $this->assertFalse($row['false_causality_warning'], 'a shape that clearly beats the background rate is not a false-causality risk');
        $this->assertSame('medium', $row['confidence']);
    }

    public function test_insufficient_samples_shape_is_low_confidence_and_not_credited(): void
    {
        $row = $this->row($this->attribute($this->deliveries('shape_thin', 2, 9)), 'shape_thin');

        $this->assertNotNull($row);
        $this->assertSame('low', $row['confidence']);
        $this->assertFalse($row['credited']);
        $this->assertStringContainsString('insufficient samples', $row['rationale']);
    }

    public function test_mixed_outcomes_shape_computes_partial_positive_rate(): void
    {
        $deliveries = [
            ['shape_token' => 'shape_mixed', 'net_behavior_delta' => 5],
            ['shape_token' => 'shape_mixed', 'net_behavior_delta' => -3],
            ['shape_token' => 'shape_mixed', 'net_behavior_delta' => 2],
            ['shape_token' => 'shape_mixed', 'net_behavior_delta' => 0],
            ['shape_token' => 'shape_mixed', 'net_behavior_delta' => -1],
            ['shape_token' => 'shape_mixed', 'net_behavior_delta' => 4],
        ];
        $row = $this->row($this->attribute($deliveries), 'shape_mixed');

        $this->assertNotNull($row);
        $this->assertSame(6, $row['sample_count']);
        $this->assertEqualsWithDelta(1.167, $row['capability_delta'], 0.001);
        $this->assertGreaterThan(0.0, $row['wilson_lower_bound']);
    }

    public function test_false_causality_warning_when_credited_shape_does_not_beat_the_background_rate(): void
    {
        // shape_loud is nearly all-positive and dominates the population, dragging the counterfactual
        // baseline for shape_marginal up near its own (barely-positive) rate => credited but NOT causal.
        $deliveries = array_merge(
            $this->deliveries('shape_loud', 20, 6),
            [
                ['shape_token' => 'shape_marginal', 'net_behavior_delta' => 1],
                ['shape_token' => 'shape_marginal', 'net_behavior_delta' => -1],
                ['shape_token' => 'shape_marginal', 'net_behavior_delta' => -1],
                ['shape_token' => 'shape_marginal', 'net_behavior_delta' => -1],
                ['shape_token' => 'shape_marginal', 'net_behavior_delta' => -1],
                ['shape_token' => 'shape_marginal', 'net_behavior_delta' => 1],
            ],
        );
        $row = $this->row($this->attribute($deliveries), 'shape_marginal');

        $this->assertNotNull($row);
        $this->assertTrue($row['credited'], 'the naive floor is still cleared (some positive samples, n>=minSamples)');
        $this->assertTrue($row['false_causality_warning'], 'wilson lower bound must not clear the loud shape-dominated baseline');
        $this->assertStringContainsString('does not clear counterfactual baseline', $row['rationale']);
    }

    public function test_confidence_bands_are_deterministic_across_sample_sizes(): void
    {
        $deliveries = array_merge(
            $this->deliveries('shape_low', 4, 1),
            $this->deliveries('shape_medium', 6, 1),
            $this->deliveries('shape_high', 12, 1),
        );
        $result1 = $this->attribute($deliveries);
        $result2 = $this->attribute($deliveries);
        $this->assertSame(json_encode($result1), json_encode($result2), 'confidence bands are deterministic');

        $this->assertSame('low', $this->row($result1, 'shape_low')['confidence']);
        $this->assertSame('medium', $this->row($result1, 'shape_medium')['confidence']);
        $this->assertSame('high', $this->row($result1, 'shape_high')['confidence']);
    }

    public function test_single_shape_population_has_zero_counterfactual_baseline(): void
    {
        // No other shape exists to compare against => baseline defaults to 0.0, never a spurious warning.
        $row = $this->row($this->attribute($this->deliveries('shape_only', 6, 3)), 'shape_only');

        $this->assertNotNull($row);
        $this->assertSame(0.0, $row['counterfactual_baseline']);
        $this->assertFalse($row['false_causality_warning']);
    }
}
