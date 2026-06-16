<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCapabilityTrendService;
use PHPUnit\Framework\TestCase;

/**
 * ACDE lever D1 — the curve-bend math of the per-delivery capability trend. The slope of the clean-delivery-
 * rate over rolling buckets is the ONLY thing that answers "is capability(t) bending upward"; the Wilson
 * lower bound keeps each bucket honest. Pure + deterministic; the DB read is a thin wrapper over this.
 */
final class AtlasLoopCapabilityTrendTest extends TestCase
{
    public function test_slope_is_positive_when_the_clean_rate_rises_over_buckets(): void
    {
        $buckets = [
            ['index' => 0, 'total' => 5, 'rate' => 0.2],
            ['index' => 1, 'total' => 5, 'rate' => 0.5],
            ['index' => 2, 'total' => 5, 'rate' => 0.9],
        ];
        $this->assertGreaterThan(0.0, AtlasLoopCapabilityTrendService::slope($buckets), 'a rising clean-delivery-rate bends the curve up');
    }

    public function test_slope_is_negative_when_the_rate_declines(): void
    {
        $buckets = [
            ['index' => 0, 'total' => 5, 'rate' => 0.9],
            ['index' => 1, 'total' => 5, 'rate' => 0.3],
        ];
        $this->assertLessThan(0.0, AtlasLoopCapabilityTrendService::slope($buckets));
    }

    public function test_empty_buckets_are_no_evidence_not_a_zero(): void
    {
        // Only one NON-EMPTY bucket => no trend (an empty bucket is missing data, never a 0 rate).
        $buckets = [
            ['index' => 0, 'total' => 0, 'rate' => 0.0],
            ['index' => 1, 'total' => 3, 'rate' => 0.7],
        ];
        $this->assertSame(0.0, AtlasLoopCapabilityTrendService::slope($buckets));
    }

    public function test_wilson_lower_is_a_conservative_bound(): void
    {
        $lb = AtlasLoopCapabilityTrendService::wilsonLower(8, 10);
        $this->assertGreaterThan(0.0, $lb);
        $this->assertLessThan(0.8, $lb, 'the lower bound sits below the 0.8 point estimate (conservative)');
        $this->assertSame(0.0, AtlasLoopCapabilityTrendService::wilsonLower(0, 0));
    }
}
