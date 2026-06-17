<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopExplorerStrategyBanditService;
use Tests\TestCase;

/**
 * ACDE WD4 — the strategy-bandit UCB bucket splits by a MEASURED complexity tier when armed, so surgical-vs-
 * root_cause efficacy is no longer averaged across a trivial adapter and a 200-method hub. Default OFF keeps
 * targetType() byte-identical (bare path-prefix bucket, no file read).
 */
final class AtlasLoopBanditComplexityTierTest extends TestCase
{
    private const REAL_LOOP_FILE = 'app/Services/Ai/AutonomousEvolution/AtlasLoopExplorerStrategyBanditService.php';

    public function test_off_is_byte_identical_bare_bucket(): void
    {
        // Default OFF (no config set) => bare path-prefix type, no file read.
        $svc = new AtlasLoopExplorerStrategyBanditService;

        $this->assertSame('loop_harness', $svc->targetType(self::REAL_LOOP_FILE));
        $this->assertSame('service', $svc->targetType('app/Services/Foo/Bar.php'));
        $this->assertSame('test', $svc->targetType('tests/Unit/Whatever.php'));
        $this->assertSame('unknown', $svc->targetType(''));
    }

    public function test_armed_appends_a_measured_tier_band(): void
    {
        config(['atlas.loop.bandit_complexity_tier_enabled' => true]);
        $svc = new AtlasLoopExplorerStrategyBanditService;

        $type = $svc->targetType(self::REAL_LOOP_FILE);

        $this->assertStringStartsWith('loop_harness|', $type, 'the path-prefix type is preserved as the prefix');
        $this->assertMatchesRegularExpression('/\|(lo|mid|hi)$/', $type, 'a measured tier band is appended');
    }

    public function test_tier_band_is_controlled_by_the_thresholds(): void
    {
        // Force the whole [1, 1_000_000) range into 'mid' — any measurable PHP file (total cyclomatic >= 1)
        // lands deterministically in 'mid', independent of the file's exact complexity.
        config([
            'atlas.loop.bandit_complexity_tier_enabled' => true,
            'atlas.loop.bandit_complexity_tier_lo' => 1,
            'atlas.loop.bandit_complexity_tier_hi' => 1000000,
        ]);
        $svc = new AtlasLoopExplorerStrategyBanditService;

        $this->assertSame('loop_harness|mid', $svc->targetType(self::REAL_LOOP_FILE));
    }

    public function test_everything_is_hi_when_the_hi_floor_is_one(): void
    {
        config([
            'atlas.loop.bandit_complexity_tier_enabled' => true,
            'atlas.loop.bandit_complexity_tier_lo' => 1,
            'atlas.loop.bandit_complexity_tier_hi' => 2,
        ]);
        $svc = new AtlasLoopExplorerStrategyBanditService;

        // The bandit file's total cyclomatic is comfortably >= 2 => 'hi'.
        $this->assertSame('loop_harness|hi', $svc->targetType(self::REAL_LOOP_FILE));
    }

    public function test_unreadable_target_falls_back_to_the_coarse_bucket_when_armed(): void
    {
        config(['atlas.loop.bandit_complexity_tier_enabled' => true]);
        $svc = new AtlasLoopExplorerStrategyBanditService;

        // A path that does not exist on disk => unmeasurable => '' tier => bare bucket (never a wrong tier).
        $this->assertSame('service', $svc->targetType('app/Services/DoesNotExist/Nope.php'));
    }
}
