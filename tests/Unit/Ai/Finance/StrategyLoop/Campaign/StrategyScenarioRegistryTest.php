<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyScenarioRegistry;
use PHPUnit\Framework\TestCase;

final class StrategyScenarioRegistryTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/atlas-scenario-registry-'.bin2hex(random_bytes(4)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_registers_scenario_without_assuming_strategy_universality(): void
    {
        $registry = new StrategyScenarioRegistry($this->path);
        $registry->registerCampaign([
            'campaign_id' => 'c1',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'status' => 'running',
        ]);

        $snapshot = $registry->load();
        $scenario = $snapshot['scenarios']['BTCUSDT-1d-trend-breakout-v1'];

        $this->assertTrue($snapshot['do_not_start_in_parallel']);
        $this->assertSame('one_active_campaign_at_a_time', $scenario['parallelism_policy']);
        $this->assertSame('do_not_assume_transfer_between_assets_timeframes_or_regimes', $scenario['strategy_universality_policy']);
        $this->assertSame('c1', $scenario['latest_campaign_id']);
    }
}
