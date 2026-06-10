<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\PolymarketShadow;

use App\Services\Ai\Finance\PolymarketShadow\FairValueEngine;
use PHPUnit\Framework\TestCase;

final class FairValueEngineTest extends TestCase
{
    private function seededEngine(float $perSecondReturn = 0.0001): FairValueEngine
    {
        $engine = new FairValueEngine;
        $closes = [];
        $price = 100000.0;
        for ($i = 0; $i < 120; $i++) {
            $price *= ($i % 2 === 0) ? (1 + $perSecondReturn) : (1 - $perSecondReturn);
            $closes[] = $price;
        }
        $engine->seed($closes);

        return $engine;
    }

    public function test_fv_is_half_when_price_at_window_open(): void
    {
        $fair = $this->seededEngine()->fairValueUp(100000.0, 100000.0, 150.0);

        $this->assertNotNull($fair);
        $this->assertEqualsWithDelta(0.5, $fair['fv_up'], 1e-6);
        $this->assertSame('diffusion', $fair['regime']);
    }

    public function test_fv_monotonic_in_current_price(): void
    {
        $engine = $this->seededEngine();
        $below = $engine->fairValueUp(99950.0, 100000.0, 150.0)['fv_up'];
        $at = $engine->fairValueUp(100000.0, 100000.0, 150.0)['fv_up'];
        $above = $engine->fairValueUp(100050.0, 100000.0, 150.0)['fv_up'];

        $this->assertLessThan($at, $below);
        $this->assertGreaterThan($at, $above);
    }

    public function test_less_time_remaining_makes_fv_more_extreme(): void
    {
        $engine = $this->seededEngine();
        $early = $engine->fairValueUp(100050.0, 100000.0, 280.0)['fv_up'];
        $late = $engine->fairValueUp(100050.0, 100000.0, 10.0)['fv_up'];

        $this->assertGreaterThan($early, $late);
    }

    public function test_fv_clamped_to_open_interval(): void
    {
        $engine = $this->seededEngine(1e-7); // near-zero vol => extreme z
        $fair = $engine->fairValueUp(101000.0, 100000.0, 5.0);

        $this->assertNotNull($fair);
        $this->assertLessThanOrEqual(0.999, $fair['fv_up']);
        $this->assertGreaterThanOrEqual(0.001, $fair['fv_up']);
    }

    public function test_invalid_inputs_return_null_not_nan(): void
    {
        $engine = $this->seededEngine();

        $this->assertNull($engine->fairValueUp(0.0, 100000.0, 100.0));
        $this->assertNull($engine->fairValueUp(100000.0, -5.0, 100.0));
        $this->assertNull($engine->fairValueUp(NAN, 100000.0, 100.0));
        $this->assertNull($engine->fairValueUp(INF, 100000.0, 100.0));
    }

    public function test_unseeded_engine_returns_null(): void
    {
        $engine = new FairValueEngine;

        $this->assertFalse($engine->isSeeded());
        $this->assertNull($engine->fairValueUp(100000.0, 100000.0, 100.0));
    }

    public function test_jump_inflates_sigma_and_pulls_fv_toward_half(): void
    {
        $calm = $this->seededEngine();
        $jumped = $this->seededEngine();

        // Feed a violent move (~1% in one second against ~1bp/s vol) => jump regime.
        $jumped->observe(100000.0, 1000.0);
        $jumped->observe(101000.0, 1001.0);

        $fvCalm = $calm->fairValueUp(101050.0, 101000.0, 150.0, 1002.0);
        $fvJump = $jumped->fairValueUp(101050.0, 101000.0, 150.0, 1002.0);

        $this->assertNotNull($fvCalm);
        $this->assertNotNull($fvJump);
        $this->assertSame('jump', $fvJump['regime']);
        $this->assertGreaterThan($fvCalm['sigma_per_second'], $fvJump['sigma_per_second']);
        // Same displacement, fatter sigma => probability closer to 0.5 (less phantom edge).
        $this->assertLessThan($fvCalm['fv_up'], $fvJump['fv_up']);
        $this->assertGreaterThan(0.5, $fvJump['fv_up']);
    }

    public function test_jump_regime_expires_after_hold(): void
    {
        $engine = new FairValueEngine(jumpHoldSeconds: 30.0);
        $closes = [];
        $price = 100000.0;
        for ($i = 0; $i < 120; $i++) {
            $price *= ($i % 2 === 0) ? 1.0001 : 0.9999;
            $closes[] = $price;
        }
        $engine->seed($closes);
        $engine->observe(100000.0, 1000.0);
        $engine->observe(101000.0, 1001.0);

        $this->assertSame('jump', $engine->fairValueUp(101000.0, 101000.0, 100.0, 1010.0)['regime']);
        $this->assertSame('diffusion', $engine->fairValueUp(101000.0, 101000.0, 100.0, 1040.0)['regime']);
    }

    public function test_normal_cdf_reference_values(): void
    {
        $this->assertEqualsWithDelta(0.5, FairValueEngine::standardNormalCdf(0.0), 1e-7);
        $this->assertEqualsWithDelta(0.8413447, FairValueEngine::standardNormalCdf(1.0), 1e-5);
        $this->assertEqualsWithDelta(0.0227501, FairValueEngine::standardNormalCdf(-2.0), 1e-5);
    }
}
