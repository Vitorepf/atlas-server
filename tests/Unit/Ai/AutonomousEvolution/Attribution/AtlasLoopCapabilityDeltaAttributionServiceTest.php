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
}
