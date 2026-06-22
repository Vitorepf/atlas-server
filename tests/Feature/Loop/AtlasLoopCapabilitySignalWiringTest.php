<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraCandidateRanker;
use Closure;
use ReflectionMethod;
use Tests\TestCase;

/**
 * L3 (signal source) — the ranker feeds the REAL capability signal (CapabilityTrendService.trend) into the
 * selector's ambition. Pins the wire: flag-OFF feeds NO capability_factor (byte-identical default ambition);
 * flag-ON feeds the clamped [0,1] signal; the default resolver fails SAFE to 0 (no crash, baseline rung). The
 * factor → bigger-rung effect is proven by AtlasLoopCapabilityRungGrowthTest; this proves the feed.
 */
final class AtlasLoopCapabilitySignalWiringTest extends TestCase
{
    private function factor(?Closure $resolver): ?float
    {
        $ranker = new AtlasLoopObraCandidateRanker(null, $resolver);

        return (new ReflectionMethod($ranker, 'capabilityFactorForContext'))->invoke($ranker);
    }

    public function test_flag_off_feeds_no_capability_factor_byte_identical(): void
    {
        config(['atlas.loop.capability_ambition_enabled' => false]);

        $this->assertNull($this->factor(fn (): float => 0.9), 'flag OFF => no capability_factor key => the §3 default ambition stands');
    }

    public function test_flag_on_feeds_the_clamped_capability_signal(): void
    {
        config(['atlas.loop.capability_ambition_enabled' => true]);

        $this->assertSame(0.7, $this->factor(fn (): float => 0.7), 'a proven rising capability is fed in [0,1]');
        $this->assertSame(1.0, $this->factor(fn (): float => 2.0), 'clamped to 1.0 — never over-dares');
        $this->assertSame(0.0, $this->factor(fn (): float => -1.0), 'clamped to 0.0');
    }

    public function test_flag_on_default_resolver_fails_safe_to_zero(): void
    {
        config(['atlas.loop.capability_ambition_enabled' => true]);

        // The default resolver reads CapabilityTrendService.trend(); with no delivery data it is not bending
        // (or unavailable) => 0.0 (the loop starts at the baseline rung), and any DB hiccup is caught => 0.0.
        $this->assertSame(0.0, $this->factor(null));
    }
}
