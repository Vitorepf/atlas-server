<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyTimeframeProfile;
use PHPUnit\Framework\TestCase;

final class StrategyTimeframeProfileTest extends TestCase
{
    public function test_profiles_daily_swing_without_assuming_transferability(): void
    {
        $profile = (new StrategyTimeframeProfile)->describe('1d');

        $this->assertSame('atlas.finance.strategy_timeframe_profile.v1', $profile['schema_version']);
        $this->assertSame('daily_swing', $profile['horizon_bucket']);
        $this->assertSame('swing_trade', $profile['trade_style']);
        $this->assertSame(365.0, $profile['periods_per_year']);
        $this->assertTrue($profile['active_in_default_roadmap']);
        $this->assertSame('do_not_transfer_between_timeframes_without_new_campaign', $profile['timeframe_transfer_policy']);
    }

    public function test_profiles_five_and_fifteen_minute_as_deferred_intraday_research(): void
    {
        $profiler = new StrategyTimeframeProfile;
        $five = $profiler->describe('5m');
        $fifteen = $profiler->describe('15m');

        $this->assertSame('high_frequency_intraday', $five['horizon_bucket']);
        $this->assertSame('scalping_or_day_trade', $five['trade_style']);
        $this->assertSame(105120.0, $five['periods_per_year']);
        $this->assertFalse($five['active_in_default_roadmap']);
        $this->assertSame('deferred_until_microstructure_controls', $five['roadmap_readiness']);
        $this->assertContains('variable_slippage_stress_required', $five['data_controls']);
        $this->assertSame(35040.0, $fifteen['periods_per_year']);
    }

    public function test_profiles_monthly_without_confusing_it_with_one_minute(): void
    {
        $profiler = new StrategyTimeframeProfile;

        $this->assertSame('1mo', $profiler->describe('1M')['normalized_interval']);
        $this->assertSame('1mo', $profiler->describe('1mo')['normalized_interval']);
        $this->assertSame(12.0, $profiler->describe('1M')['periods_per_year']);
        $this->assertSame('position', $profiler->describe('1M')['horizon_bucket']);
        $this->assertSame(525600.0, $profiler->describe('1m')['periods_per_year']);
    }

    public function test_campaign_policy_changes_search_controls_by_timeframe(): void
    {
        $profiler = new StrategyTimeframeProfile;
        $daily = $profiler->campaignPolicy('1d');
        $fourHour = $profiler->campaignPolicy('4h');
        $fiveMinute = $profiler->campaignPolicy('5m');

        $this->assertSame('atlas.finance.strategy_timeframe_policy.v1', $daily['schema_version']);
        $this->assertSame(20, $daily['default_min_trades']);
        $this->assertSame(10, $daily['default_holdout_min_trades']);
        $this->assertFalse($daily['requires_explicit_activation']);

        $this->assertSame('intraday_swing', $fourHour['horizon_bucket']);
        $this->assertSame(40, $fourHour['default_min_trades']);
        $this->assertSame(20, $fourHour['default_holdout_min_trades']);
        $this->assertFalse($fourHour['requires_explicit_activation']);

        $this->assertSame('high_frequency_intraday', $fiveMinute['horizon_bucket']);
        $this->assertSame(120, $fiveMinute['default_min_trades']);
        $this->assertSame(60, $fiveMinute['default_holdout_min_trades']);
        $this->assertTrue($fiveMinute['requires_explicit_activation']);
        $this->assertSame('symbol_interval_family_campaign', $fiveMinute['policy_scope']);
    }
}
