<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainValueDensityQueueOptimizer;
use Tests\TestCase;

/**
 * Proves the drain/feed decision gates on median per-packet density
 * instead of the outlier-sensitive mean.
 *
 * THREE CASES:
 *   1. Outlier queue: 9 packets at 0.10 + 1 at 4.5, adequate depth.
 *      mean=0.54 (≥0.50 floor), median=0.10 (<0.50 floor).
 *      BEFORE (mean gate): drain_first. AFTER (median gate): feed_queue.
 *   2. Uniform high-value queue: all >= 0.50 → drain_first (unchanged).
 *   3. Starved / no packets → feed_queue (unchanged).
 */
final class AtlasExternalBrainValueDensityMedianDrainTest extends TestCase
{
    private function optimizer(): AtlasExternalBrainValueDensityQueueOptimizer
    {
        return new AtlasExternalBrainValueDensityQueueOptimizer;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function packet(string $id, float $value, float $minutes, array $overrides = []): array
    {
        return array_merge([
            'packet_id' => $id,
            'expected_value' => $value,
            'estimated_worker_minutes' => $minutes,
        ], $overrides);
    }

    /**
     * @param  int  $count
     * @return list<array<string, mixed>>
     */
    private function uniformPackets(int $count, float $value, float $minutes): array
    {
        return array_map(
            fn (int $i): array => $this->packet("p{$i}", $value, $minutes),
            range(0, $count - 1),
        );
    }

    public function test_outlier_queue_with_mean_above_floor_median_below_feeds(): void
    {
        // 9 packets at density 0.10 + 1 at density 4.5
        // mean = 0.54 (≥0.50), median = 0.10 (<0.50)
        $packets = array_merge(
            $this->uniformPackets(9, 1.0, 10.0), // 9 × 0.10
            [$this->packet('outlier', 45.0, 10.0)], // 1 × 4.5
        );

        // Adequate depth: 10 packets >= oversat_limit (1*5=5)
        $result = $this->optimizer()->optimize([
            'packets' => $packets,
            'muscle_count' => 1,
            'value_density_floor' => 0.5,
        ]);

        // Must report both mean and median.
        $this->assertArrayHasKey('median_value_density', $result);
        $this->assertArrayHasKey('value_density_score', $result);

        // Mean is above floor (0.54 >= 0.50) — still reported.
        $this->assertGreaterThanOrEqual(0.50, $result['value_density_score']);

        // Median is below floor (0.10 < 0.50) — drives the decision.
        $this->assertLessThan(0.50, $result['median_value_density']);

        // The optimizer must feed (median < floor) rather than drain first.
        $this->assertSame(AtlasExternalBrainValueDensityQueueOptimizer::ACTION_FEED_QUEUE, $result['action']);
    }

    public function test_uniform_high_value_queue_drains_first(): void
    {
        // 10 packets all at density 1.0 — median = mean = 1.0 >= 0.50.
        $packets = $this->uniformPackets(10, 10.0, 10.0);

        $result = $this->optimizer()->optimize([
            'packets' => $packets,
            'muscle_count' => 1,
            'value_density_floor' => 0.5,
        ]);

        $this->assertSame(AtlasExternalBrainValueDensityQueueOptimizer::ACTION_DRAIN_FIRST, $result['action']);
        $this->assertSame(1.0, $result['median_value_density']);
        $this->assertSame(1.0, $result['value_density_score']);
    }

    public function test_starved_queue_with_no_packets_feeds(): void
    {
        $result = $this->optimizer()->optimize([
            'packets' => [],
            'muscle_count' => 1,
        ]);

        $this->assertSame(AtlasExternalBrainValueDensityQueueOptimizer::ACTION_FEED_QUEUE, $result['action']);
        $this->assertSame(0.0, $result['median_value_density']);
        $this->assertSame(0.0, $result['value_density_score']);
    }
}
