<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopExplorerStrategyBanditService;
use Tests\TestCase;

/**
 * ACDE DC5 — compound the strategy-bandit UCB key with the work-class (type|tier|work-class). Default OFF keeps
 * targetType byte-identical; ON appends the work-class so a strategy is not averaged across different work-
 * classes of the same type+tier.
 */
final class AtlasLoopBanditCompoundKeyTest extends TestCase
{
    private const FILE = 'app/Services/Ai/AutonomousEvolution/AtlasLoopExplorerStrategyBanditService.php';

    public function test_off_is_byte_identical(): void
    {
        config(['atlas.loop.bandit_compound_workclass_key_enabled' => false, 'atlas.loop.bandit_complexity_tier_enabled' => false]);

        $this->assertSame('loop_harness', (new AtlasLoopExplorerStrategyBanditService)->targetType(self::FILE));
        $this->assertSame('service', (new AtlasLoopExplorerStrategyBanditService)->targetType('app/Services/Foo/Bar.php'));
    }

    public function test_armed_appends_the_work_class(): void
    {
        config(['atlas.loop.bandit_compound_workclass_key_enabled' => true, 'atlas.loop.bandit_complexity_tier_enabled' => false]);

        // work-class of the file = its first-3 dir segments = app/Services/Ai
        $this->assertSame('loop_harness|app/Services/Ai', (new AtlasLoopExplorerStrategyBanditService)->targetType(self::FILE));
    }

    public function test_composes_with_the_complexity_tier(): void
    {
        config([
            'atlas.loop.bandit_compound_workclass_key_enabled' => true,
            'atlas.loop.bandit_complexity_tier_enabled' => true,
            'atlas.loop.bandit_complexity_tier_lo' => 1,
            'atlas.loop.bandit_complexity_tier_hi' => 1000000, // force 'mid'
        ]);

        $this->assertSame('loop_harness|mid|app/Services/Ai', (new AtlasLoopExplorerStrategyBanditService)->targetType(self::FILE));
    }

    public function test_unknown_type_is_never_compounded(): void
    {
        config(['atlas.loop.bandit_compound_workclass_key_enabled' => true]);

        $this->assertSame('unknown', (new AtlasLoopExplorerStrategyBanditService)->targetType(''));
    }
}
