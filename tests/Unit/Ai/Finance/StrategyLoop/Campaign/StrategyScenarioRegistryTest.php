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

    public function test_records_family_exhausted_report_as_scenario_knowledge(): void
    {
        $registry = new StrategyScenarioRegistry($this->path);
        $registry->recordReport([
            'campaign_id' => 'c1',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'verdict' => 'NULL_FAMILY_EXHAUSTED',
            'summary' => [
                'rounds' => 100,
                'total_candidates' => 60000,
                'best_ann_sharpe' => 0.77,
                'best_dsr' => 0.42,
                'best_holdout_sharpe' => 0.3,
                'data_sha' => 'data-sha',
                'cost_profile_hash' => 'cost-hash',
            ],
            'scenario_profile' => [
                'best_observed' => [
                    'ann_sharpe' => [
                        'round' => 12,
                        'winner_signature' => ['signature' => 'sig-12'],
                        'winner_strategy' => ['entry_lookback' => 20],
                    ],
                ],
                'knowledge_note' => 'Best observed candidates are research evidence, not executable signals.',
            ],
            'negative_conclusion' => 'No robust edge found.',
            'regime_summary' => [
                'scenario_note' => 'Regimes explain scenario fit.',
            ],
        ]);

        $scenario = $registry->load()['scenarios']['BTCUSDT-1d-trend-breakout-v1'];

        $this->assertSame('NULL_FAMILY_EXHAUSTED', $scenario['latest_verdict']);
        $this->assertTrue($scenario['family_exhausted']);
        $this->assertSame(0.77, $scenario['latest_summary']['best_ann_sharpe']);
        $this->assertSame('data-sha', $scenario['latest_summary']['data_sha']);
        $this->assertSame('cost-hash', $scenario['latest_summary']['cost_profile_hash']);
        $this->assertSame('sig-12', $scenario['best_observed']['ann_sharpe']['winner_signature']['signature']);
        $this->assertSame('No robust edge found.', $scenario['research_history'][0]['negative_conclusion']);
        $this->assertSame(0.77, $scenario['research_history'][0]['best_ann_sharpe']);
        $this->assertSame('Regimes explain scenario fit.', $scenario['regime_note']);
    }

    public function test_next_roadmap_scenario_skips_exhausted_families(): void
    {
        $registry = new StrategyScenarioRegistry($this->path);
        $registry->recordReport([
            'campaign_id' => 'btc-done',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'verdict' => 'NULL_FAMILY_EXHAUSTED',
            'summary' => ['rounds' => 100, 'total_candidates' => 60000],
        ]);

        $next = $registry->nextRoadmapScenario('trend-breakout-v1');

        $this->assertSame('ETHUSDT', $next['symbol']);
        $this->assertSame('1d', $next['interval']);
        $this->assertSame('scenario_not_started', $next['reason']);
    }

    public function test_roadmap_mode_treats_family_as_part_of_the_scenario(): void
    {
        $registry = new StrategyScenarioRegistry($this->path);
        $registry->recordReport([
            'campaign_id' => 'btc-trend-done',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'verdict' => 'NULL_FAMILY_EXHAUSTED',
            'summary' => ['rounds' => 100, 'total_candidates' => 60000],
        ]);

        $next = $registry->nextRoadmapScenario(null);

        $this->assertSame('BTCUSDT', $next['symbol']);
        $this->assertSame('1d', $next['interval']);
        $this->assertSame('mean-reversion-v1', $next['strategy_family']);
        $this->assertSame('scenario_not_started', $next['reason']);
    }

    public function test_holdout_exhausted_scenario_advances_to_next_family_without_reopening_terminal_campaign(): void
    {
        $registry = new StrategyScenarioRegistry($this->path);
        $registry->recordReport([
            'campaign_id' => 'btc-trend-terminal',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'verdict' => 'NULL_HOLDOUT_EXHAUSTED',
            'summary' => ['rounds' => 478, 'total_candidates' => 286800],
        ]);

        $next = $registry->nextRoadmapScenario(null);

        $this->assertSame('BTCUSDT', $next['symbol']);
        $this->assertSame('mean-reversion-v1', $next['strategy_family']);
        $this->assertArrayNotHasKey('latest_campaign_id', $next);
    }

    public function test_family_filter_still_selects_that_family_across_markets(): void
    {
        $registry = new StrategyScenarioRegistry($this->path);
        $registry->recordReport([
            'campaign_id' => 'btc-momentum-done',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'momentum-v1',
            'verdict' => 'NULL_FAMILY_EXHAUSTED',
            'summary' => ['rounds' => 100, 'total_candidates' => 60000],
        ]);

        $next = $registry->nextRoadmapScenario('momentum-v1');

        $this->assertSame('ETHUSDT', $next['symbol']);
        $this->assertSame('momentum-v1', $next['strategy_family']);
    }

    public function test_next_roadmap_scenario_preserves_incomplete_latest_campaign(): void
    {
        $registry = new StrategyScenarioRegistry($this->path);
        $registry->registerCampaign([
            'campaign_id' => 'btc-active',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'status' => 'running',
        ]);

        $next = $registry->nextRoadmapScenario('trend-breakout-v1');

        $this->assertSame('BTCUSDT', $next['symbol']);
        $this->assertSame('scenario_incomplete', $next['reason']);
        $this->assertSame('btc-active', $next['latest_campaign_id']);
    }
}
