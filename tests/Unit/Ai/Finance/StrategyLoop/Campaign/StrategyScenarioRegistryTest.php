<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyFeatureSetProfile;
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
        $this->assertSame('daily_swing', $scenario['timeframe_profile']['horizon_bucket']);
        $this->assertSame('do_not_transfer_between_timeframes_without_new_campaign', $scenario['timeframe_profile']['timeframe_transfer_policy']);
        $this->assertSame('price_only_v1', $scenario['feature_set']['feature_set_id']);
        $this->assertSame('atlas.finance.strategy_research_rationale.v1', $scenario['research_rationale']['schema_version']);
        $this->assertSame('exact_symbol_interval_family_feature_set', $scenario['research_rationale']['scenario_scope']);
        $this->assertSame('do_not_transfer_results_between_assets_timeframes_families_or_feature_sets_without_new_campaign', $scenario['research_rationale']['transfer_policy']);
        $this->assertSame('c1', $scenario['latest_campaign_id']);
    }

    public function test_non_price_feature_set_gets_its_own_scenario_key(): void
    {
        $registry = new StrategyScenarioRegistry($this->path);
        $registry->recordReport([
            'campaign_id' => 'feature-campaign',
            'symbol' => 'ETHUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'feature_set' => [
                'schema_version' => 'atlas.finance.strategy_feature_set.v1',
                'feature_set_id' => 'cross_asset_context_v1',
                'allowed_now' => false,
                'propose_only' => true,
                'execution_surface' => 'forbidden',
            ],
            'verdict' => 'INCONCLUSIVE',
            'summary' => ['rounds' => 1, 'total_candidates' => 10],
        ]);

        $snapshot = $registry->load();

        $this->assertArrayHasKey('ETHUSDT-1d-trend-breakout-v1-cross_asset_context_v1', $snapshot['scenarios']);
        $this->assertArrayNotHasKey('ETHUSDT-1d-trend-breakout-v1', $snapshot['scenarios']);
    }

    public function test_registering_new_campaign_preserves_existing_scenario_evidence(): void
    {
        $registry = new StrategyScenarioRegistry($this->path);
        $registry->recordReport([
            'campaign_id' => 'btc-trend-evidence',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'verdict' => 'NULL_HOLDOUT_EXHAUSTED',
            'summary' => ['rounds' => 478, 'total_candidates' => 286800, 'holdout_generation' => 0],
        ]);

        $registry->registerCampaign([
            'campaign_id' => 'btc-trend-next',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'status' => 'running',
        ]);

        $scenario = $registry->load()['scenarios']['BTCUSDT-1d-trend-breakout-v1'];

        $this->assertSame('btc-trend-next', $scenario['latest_campaign_id']);
        $this->assertSame('running', $scenario['latest_status']);
        $this->assertSame(478, $scenario['latest_summary']['rounds']);
        $this->assertSame(286800, $scenario['latest_summary']['total_candidates']);
        $this->assertSame('btc-trend-evidence', $scenario['research_history'][0]['campaign_id']);
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

    public function test_builds_research_only_family_matrix_per_exact_scenario(): void
    {
        $registry = new StrategyScenarioRegistry($this->path);
        $registry->recordReport([
            'campaign_id' => 'btc-trend',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'verdict' => 'NULL_FAMILY_EXHAUSTED',
            'summary' => [
                'rounds' => 100,
                'total_candidates' => 60000,
                'best_campaign_dsr' => 0.12,
                'best_holdout_sharpe' => 0.2,
            ],
        ]);
        $registry->recordReport([
            'campaign_id' => 'btc-momentum',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'momentum-v1',
            'verdict' => 'INCONCLUSIVE',
            'summary' => [
                'rounds' => 25,
                'total_candidates' => 15000,
                'best_campaign_dsr' => 0.42,
                'best_holdout_sharpe' => 0.5,
            ],
        ]);
        $registry->recordReport([
            'campaign_id' => 'eth-trend',
            'symbol' => 'ETHUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'verdict' => 'INCONCLUSIVE',
            'summary' => [
                'rounds' => 10,
                'total_candidates' => 6000,
                'best_campaign_dsr' => 0.9,
            ],
        ]);

        $matrix = $registry->load()['scenario_knowledge_matrix'];

        $this->assertArrayHasKey('BTCUSDT-1d', $matrix);
        $this->assertArrayHasKey('ETHUSDT-1d', $matrix);
        $this->assertSame('research_only_not_an_executable_signal', $matrix['BTCUSDT-1d']['knowledge_policy']);
        $this->assertSame(2, $matrix['BTCUSDT-1d']['coverage']['studied_strategy_families']);
        $this->assertSame(1, $matrix['BTCUSDT-1d']['coverage']['null_strategy_families']);
        $this->assertSame('momentum-v1', $matrix['BTCUSDT-1d']['leaderboard'][0]['strategy_family']);
        $this->assertSame(0.42, $matrix['BTCUSDT-1d']['leaderboard'][0]['research_score']);
        $this->assertSame('best_campaign_dsr', $matrix['BTCUSDT-1d']['leaderboard'][0]['research_score_basis']);
        $this->assertSame('trend-breakout-v1', $matrix['ETHUSDT-1d']['leaderboard'][0]['strategy_family']);
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
        $this->assertSame('daily_price_only_campaigns_are_the_lowest_noise_baseline_for_honest_crypto_discovery', $next['research_rationale']['selection_reason']);
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

    public function test_zero_candidate_holdout_exhaustion_does_not_advance_out_of_btc_daily(): void
    {
        $registry = new StrategyScenarioRegistry($this->path);
        foreach (['trend-breakout-v1', 'mean-reversion-v1'] as $family) {
            $registry->recordReport([
                'campaign_id' => 'btc-'.$family.'-terminal',
                'symbol' => 'BTCUSDT',
                'interval' => '1d',
                'strategy_family' => $family,
                'verdict' => 'NULL_HOLDOUT_EXHAUSTED',
                'summary' => ['rounds' => 478, 'total_candidates' => 286800, 'holdout_generation' => 0],
            ]);
        }
        $registry->recordReport([
            'campaign_id' => 'btc-momentum-zero',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'momentum-v1',
            'verdict' => 'NULL_HOLDOUT_EXHAUSTED',
            'summary' => [
                'rounds' => 0,
                'total_candidates' => 0,
                'holdout_generation' => 0,
                'stop_reason' => 'holdout_exhausted',
            ],
        ]);

        $snapshot = $registry->load();
        $scenario = $snapshot['scenarios']['BTCUSDT-1d-momentum-v1'];
        $next = $registry->nextRoadmapScenario(null);

        $this->assertSame('INCONCLUSIVE', $scenario['latest_verdict']);
        $this->assertSame('NULL_HOLDOUT_EXHAUSTED', $scenario['latest_raw_verdict']);
        $this->assertSame('BTCUSDT', $next['symbol']);
        $this->assertSame('1d', $next['interval']);
        $this->assertSame('momentum-v1', $next['strategy_family']);
        $this->assertSame('zero_candidate_campaign_needs_fresh_holdout_generation', $next['reason']);
        $this->assertSame(1, $next['holdout_generation']);
        $this->assertSame('btc-momentum-zero', $next['previous_campaign_id']);
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

    public function test_default_registry_profiles_active_and_deferred_timeframes_separately(): void
    {
        $registry = (new StrategyScenarioRegistry($this->path))->load();
        $activeRoadmap = array_values((array) $registry['sequential_roadmap']);
        $deferred = array_values((array) $registry['deferred_timeframe_backlog']);

        $activeIntervals = array_unique(array_map(static fn (array $row): string => (string) $row['interval'], $activeRoadmap));
        $deferredIntervals = array_unique(array_map(static fn (array $row): string => (string) $row['interval'], $deferred));

        $this->assertContains('1d', $activeIntervals);
        $this->assertContains('4h', $activeIntervals);
        $this->assertNotContains('5m', $activeIntervals);
        $this->assertNotContains('15m', $activeIntervals);
        $this->assertNotContains('1mo', $activeIntervals);

        $this->assertContains('5m', $deferredIntervals);
        $this->assertContains('15m', $deferredIntervals);
        $this->assertContains('1mo', $deferredIntervals);
        $this->assertSame('high_frequency_intraday', $registry['timeframe_profiles']['5m']['horizon_bucket']);
        $this->assertSame('position', $registry['timeframe_profiles']['1mo']['horizon_bucket']);
        $this->assertSame('registered_as_deferred_research_hypothesis_not_active_roadmap', $deferred[0]['reason']);
        $this->assertArrayHasKey('price_only_v1', $registry['feature_sets']);
        $this->assertContains('cross_asset_context_v1', array_column($registry['deferred_feature_set_backlog'], 'feature_set_id'));
        $this->assertContains('news_sentiment_v1', array_column($registry['deferred_feature_set_backlog'], 'feature_set_id'));
        $featureRoadmap = array_values((array) $registry['feature_set_activation_roadmap']);
        $featureRoadmapById = [];
        foreach ($featureRoadmap as $entry) {
            $featureRoadmapById[$entry['feature_set_id']] = $entry;
        }
        $this->assertSame(StrategyFeatureSetProfile::PRICE_ONLY, $featureRoadmap[0]['feature_set_id']);
        $this->assertLessThan($featureRoadmapById['news_sentiment_v1']['activation_priority'], $featureRoadmapById['derivatives_funding_oi_v1']['activation_priority']);
        $this->assertSame('late_experimental_only', $featureRoadmapById['news_sentiment_v1']['activation_phase']);
        $this->assertSame('atlas.finance.strategy_research_rationale.v1', $activeRoadmap[0]['research_rationale']['schema_version']);
        $this->assertSame('same_honesty_gate_or_stricter_no_shortcut_for_easier_timeframe', $activeRoadmap[0]['research_rationale']['certification_policy']);
        $this->assertSame('short_timeframes_are_deferred_until_microstructure_and_cost_controls_exist', $deferred[0]['research_rationale']['selection_reason']);
    }
}
