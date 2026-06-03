<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Finance\StrategyLoop;

use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyCandidateSignature;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyCampaignStore;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyConfirmationQueue;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyScenarioRegistry;
use App\Services\Ai\Finance\StrategyLoop\Bar;
use App\Console\Commands\AtlasFinanceStrategySearchCommand;
use Illuminate\Support\Facades\Artisan;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasFinanceStrategySearchCampaignCommandTest extends TestCase
{
    private string $dryRunRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dryRunRoot = storage_path('framework/atlas/finance/dry-run-campaigns');
        if (! is_file(storage_path('atlas/finance/market-data/BTCUSDT-1d.csv'))) {
            $this->markTestSkipped('BTCUSDT-1d market data fixture is not available.');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dryRunRoot.'/phpunit-*') ?: [] as $dir) {
            (new Process(['rm', '-rf', $dir]))->run();
        }
        @unlink(storage_path('framework/atlas/finance/research-evidence-ledger.jsonl'));
        @unlink(storage_path('framework/atlas/finance/scenario-registry.json'));
        @unlink(storage_path('framework/atlas/finance/candidate-rediscovery-ledger.jsonl'));
        @unlink(storage_path('framework/atlas/finance/confirmation-queue.json'));
        foreach (glob(storage_path('atlas/finance/campaigns/phpunit-real-none-*')) ?: [] as $dir) {
            (new Process(['rm', '-rf', $dir]))->run();
        }
        parent::tearDown();
    }

    public function test_no_ledger_smoke_does_not_write_the_requested_ledger(): void
    {
        $ledger = storage_path('framework/testing/phpunit-no-ledger-'.bin2hex(random_bytes(4)).'.jsonl');

        $this->artisan('atlas:finance:strategy-search', [
            '--rounds' => 1,
            '--candidates' => 10,
            '--sleep' => 0,
            '--seed' => 123,
            '--ledger' => $ledger,
            '--no-ledger' => true,
        ])->assertExitCode(0);

        $this->assertFileDoesNotExist($ledger);
    }

    public function test_unsupported_strategy_family_fails_closed_instead_of_mislabeling_engine(): void
    {
        $this->artisan('atlas:finance:strategy-search', [
            '--family' => 'unsupported-family-v1',
            '--rounds' => 1,
            '--candidates' => 10,
            '--sleep' => 0,
            '--no-ledger' => true,
        ])->assertExitCode(1);
    }

    public function test_degenerate_holdout_split_is_rejected(): void
    {
        $this->artisan('atlas:finance:strategy-search', [
            '--rounds' => 1,
            '--candidates' => 10,
            '--sleep' => 0,
            '--holdout-frac' => 0.10,
            '--confirmation-holdout-frac' => 0.10,
            '--no-ledger' => true,
        ])->assertExitCode(1);
    }

    public function test_holdout_generation_walks_validation_slice_backwards(): void
    {
        $command = new AtlasFinanceStrategySearchCommand;
        $method = new ReflectionMethod($command, 'campaignSplit');
        $method->setAccessible(true);
        $bars = [];
        for ($i = 0; $i < 100; $i++) {
            $bars[] = new Bar($i * 1000, 1.0, 1.0, 1.0, 1.0, 1.0, ($i * 1000) + 999);
        }

        $generation0 = $method->invoke($command, $bars, 0.25, 0.10, 0);
        $generation1 = $method->invoke($command, $bars, 0.25, 0.10, 1);

        $this->assertSame(75_000, $generation0['holdout_bars'][0]->openTime);
        $this->assertSame(60_000, $generation1['holdout_bars'][0]->openTime);
        $this->assertSame(75, count($generation0['scoring_bars']));
        $this->assertSame(60, count($generation1['scoring_bars']));
        $this->assertSame('walkback_validation_holdout_before_reserved_confirmation', $generation1['split_policy']);
        $this->assertSame(4, $generation1['max_holdout_generation']);
    }

    public function test_second_engine_none_is_smoke_only_not_real_campaign_mode(): void
    {
        $campaign = 'phpunit-real-none-'.bin2hex(random_bytes(4));

        $this->artisan('atlas:finance:strategy-search', [
            '--rounds' => 1,
            '--max-rounds' => 1,
            '--candidates' => 10,
            '--sleep' => 0,
            '--seed' => 136,
            '--campaign-id' => $campaign,
            '--second-engine' => 'none',
        ])->assertExitCode(1);

        $this->assertDirectoryDoesNotExist(storage_path('atlas/finance/campaigns/'.$campaign));
    }

    public function test_mean_reversion_family_runs_as_its_own_campaign(): void
    {
        $campaign = 'phpunit-mean-reversion-'.bin2hex(random_bytes(4));

        $this->artisan('atlas:finance:strategy-search', [
            '--family' => 'mean-reversion-v1',
            '--rounds' => 1,
            '--max-rounds' => 1,
            '--candidates' => 10,
            '--sleep' => 0,
            '--seed' => 456,
            '--campaign-id' => $campaign,
            '--dry-run-ledger' => true,
        ])->assertExitCode(0);

        $campaignJson = json_decode((string) file_get_contents($this->dryRunRoot.'/'.$campaign.'/campaign.json'), true);
        $ledgerRows = file($this->dryRunRoot.'/'.$campaign.'/ledger.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $firstRow = json_decode((string) $ledgerRows[0], true);

        $this->assertSame('mean-reversion-v1', $campaignJson['strategy_family']);
        $this->assertSame('mean-reversion-v1', $firstRow['strategy_family']);
        $this->assertSame('completed', $campaignJson['status']);
        $this->assertStringStartsWith('NULL_', $campaignJson['verdict']);
    }

    public function test_momentum_family_runs_as_its_own_campaign(): void
    {
        $campaign = 'phpunit-momentum-'.bin2hex(random_bytes(4));

        $this->artisan('atlas:finance:strategy-search', [
            '--family' => 'momentum-v1',
            '--rounds' => 1,
            '--max-rounds' => 1,
            '--candidates' => 10,
            '--sleep' => 0,
            '--seed' => 654,
            '--campaign-id' => $campaign,
            '--dry-run-ledger' => true,
        ])->assertExitCode(0);

        $campaignJson = json_decode((string) file_get_contents($this->dryRunRoot.'/'.$campaign.'/campaign.json'), true);
        $ledgerRows = file($this->dryRunRoot.'/'.$campaign.'/ledger.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $firstRow = json_decode((string) $ledgerRows[0], true);

        $this->assertSame('momentum-v1', $campaignJson['strategy_family']);
        $this->assertSame('momentum-v1', $firstRow['strategy_family']);
        $this->assertSame('completed', $campaignJson['status']);
        $this->assertStringStartsWith('NULL_', $campaignJson['verdict']);
    }

    public function test_campaigns_write_to_isolated_dry_run_ledgers(): void
    {
        $first = 'phpunit-campaign-a-'.bin2hex(random_bytes(4));
        $second = 'phpunit-campaign-b-'.bin2hex(random_bytes(4));

        $this->runDryCampaign($first, 111);
        $this->runDryCampaign($second, 222);

        $firstDir = $this->dryRunRoot.'/'.$first;
        $secondDir = $this->dryRunRoot.'/'.$second;

        $this->assertFileExists($firstDir.'/ledger.jsonl');
        $this->assertFileExists($secondDir.'/ledger.jsonl');
        $this->assertNotSame(realpath($firstDir.'/ledger.jsonl'), realpath($secondDir.'/ledger.jsonl'));

        $firstLedgerRow = json_decode((string) (file($firstDir.'/ledger.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)[0] ?? ''), true);
        $firstCampaign = json_decode((string) file_get_contents($firstDir.'/campaign.json'), true);
        $secondCampaign = json_decode((string) file_get_contents($secondDir.'/campaign.json'), true);
        $firstReport = json_decode((string) file_get_contents($firstDir.'/null-report.json'), true);

        $this->assertSame($first, $firstCampaign['campaign_id']);
        $this->assertSame($second, $secondCampaign['campaign_id']);
        $this->assertArrayHasKey('winner_strategy', $firstLedgerRow);
        $this->assertArrayHasKey('winner_signature', $firstLedgerRow);
        $this->assertArrayHasKey('holdout_total_return', $firstLedgerRow);
        $this->assertArrayHasKey('holdout_exposure', $firstLedgerRow);
        $this->assertArrayHasKey('holdout_equity_curve_sample', $firstLedgerRow);
        $this->assertSame(1, $firstCampaign['pre_registered_budget']['max_rounds']);
        $this->assertSame(10, $firstCampaign['pre_registered_budget']['candidates_per_round']);
        $this->assertSame(10, $firstCampaign['pre_registered_budget']['max_candidates']);
        $this->assertSame('daily_swing', $firstCampaign['timeframe_profile']['horizon_bucket']);
        $this->assertSame('price_only_v1', $firstCampaign['feature_set']['feature_set_id']);
        $this->assertTrue($firstCampaign['feature_set']['allowed_now']);
        $this->assertSame(['ohlcv_price_history'], $firstCampaign['feature_set']['input_families']);
        $this->assertSame('price_only_v1', $firstLedgerRow['feature_set_id']);
        $this->assertSame('price_only_v1', $firstReport['feature_set']['feature_set_id']);
        $this->assertSame('atlas.finance.strategy_timeframe_policy.v1', $firstCampaign['timeframe_policy']['schema_version']);
        $this->assertSame(20, $firstCampaign['timeframe_policy']['effective_min_trades']);
        $this->assertSame(10, $firstCampaign['timeframe_policy']['effective_holdout_min_trades']);
        $this->assertSame(1000, $firstCampaign['timeframe_policy']['effective_holdout_max_reuse']);
        $this->assertSame(20, $firstCampaign['promotion_criteria']['scoring_min_trades']);
        $this->assertSame(10, $firstCampaign['promotion_criteria']['holdout_min_trades']);
        $this->assertSame(2.0, $firstCampaign['promotion_criteria']['cost_stress_multiplier']);
        $this->assertTrue($firstCampaign['promotion_criteria']['cost_stress_required']);
        $this->assertSame('do_not_transfer_between_timeframes_without_new_campaign', $firstCampaign['timeframe_profile']['timeframe_transfer_policy']);
        $this->assertSame('daily_swing', $firstLedgerRow['timeframe_profile']['horizon_bucket']);
        $this->assertSame('forbidden', $firstCampaign['live_trading']);
        $this->assertTrue($firstCampaign['promotion_criteria']['second_engine_required']);
        $this->assertSame(['conservative', 'aggressive', 'robustness'], $firstCampaign['search_design']['islands']);
        $this->assertSame(['ann_sharpe', 'max_dd', 'stability_score', 'robustness_score'], $firstCampaign['search_design']['pareto_objectives']);
        $this->assertSame('python-replay', $firstCampaign['second_engine']['mode']);
        $this->assertSame(1, $firstCampaign['cross_campaign_rediscovery']['required_independent_campaigns']);
        $this->assertTrue($firstCampaign['promotion_criteria']['cross_campaign_rediscovery_required']);
        $this->assertSame('validation', $firstCampaign['holdout']['role']);
        $this->assertSame('confirmation', $firstCampaign['confirmation_holdout']['role']);
        $this->assertNotSame($firstCampaign['holdout']['holdout_id'], $firstCampaign['confirmation_holdout']['holdout_id']);
        $this->assertFileExists($firstDir.'/null-report.json');
        $this->assertSame($firstCampaign['data_manifest']['sha256'], $firstReport['summary']['data_sha']);
        $this->assertSame($firstCampaign['cost_profile']['cost_profile_hash'], $firstReport['summary']['cost_profile_hash']);
        $this->assertSame('daily_swing', $firstReport['timeframe_profile']['horizon_bucket']);
        $this->assertSame('daily_swing', $firstReport['summary']['timeframe_bucket']);
        $this->assertSame('BTCUSDT-1d-trend-breakout-v1', $firstReport['scenario_profile']['scenario_key']);
        $this->assertFileExists(storage_path('framework/atlas/finance/research-evidence-ledger.jsonl'));
        $this->assertStringContainsString('atlas.finance.strategy_research_evidence.v1', (string) file_get_contents(storage_path('framework/atlas/finance/research-evidence-ledger.jsonl')));
        $scenarioRegistry = json_decode((string) file_get_contents(storage_path('framework/atlas/finance/scenario-registry.json')), true);
        $this->assertTrue($scenarioRegistry['do_not_start_in_parallel']);
        $this->assertArrayHasKey('BTCUSDT-1d-trend-breakout-v1', $scenarioRegistry['scenarios']);
        $this->assertSame('daily_swing', $scenarioRegistry['scenarios']['BTCUSDT-1d-trend-breakout-v1']['timeframe_profile']['horizon_bucket']);
        $this->assertArrayHasKey('5m', $scenarioRegistry['timeframe_profiles']);
        $this->assertContains('15m', array_column($scenarioRegistry['deferred_timeframe_backlog'], 'interval'));
        $this->assertArrayHasKey('price_only_v1', $scenarioRegistry['feature_sets']);
        $this->assertContains('news_sentiment_v1', array_column($scenarioRegistry['deferred_feature_set_backlog'], 'feature_set_id'));
    }

    public function test_future_feature_sets_are_rejected_until_governed_contracts_exist(): void
    {
        $exit = Artisan::call('atlas:finance:strategy-search', [
            '--rounds' => 1,
            '--candidates' => 10,
            '--sleep' => 0,
            '--feature-set' => 'news-sentiment-v1',
            '--no-ledger' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('feature set news_sentiment_v1 is not active', $output);
        $this->assertStringContainsString('publication_time_no_lookahead_proof', $output);
    }

    public function test_four_hour_campaign_uses_intraday_swing_timeframe_policy(): void
    {
        if (! is_file(storage_path('atlas/finance/market-data/BTCUSDT-4h.csv'))) {
            $this->markTestSkipped('BTCUSDT-4h market data fixture is not available.');
        }

        $campaign = 'phpunit-4h-policy-'.bin2hex(random_bytes(4));

        $this->artisan('atlas:finance:strategy-search', [
            '--symbol' => 'BTCUSDT',
            '--interval' => '4h',
            '--rounds' => 1,
            '--max-rounds' => 1,
            '--candidates' => 10,
            '--sleep' => 0,
            '--seed' => 456,
            '--campaign-id' => $campaign,
            '--dry-run-ledger' => true,
        ])->assertExitCode(0);

        $campaignJson = json_decode((string) file_get_contents($this->dryRunRoot.'/'.$campaign.'/campaign.json'), true);

        $this->assertSame('intraday_swing', $campaignJson['timeframe_profile']['horizon_bucket']);
        $this->assertSame(40, $campaignJson['timeframe_policy']['effective_min_trades']);
        $this->assertSame(20, $campaignJson['timeframe_policy']['effective_holdout_min_trades']);
        $this->assertSame(750, $campaignJson['timeframe_policy']['effective_holdout_max_reuse']);
        $this->assertSame(40, $campaignJson['promotion_criteria']['scoring_min_trades']);
        $this->assertSame(20, $campaignJson['promotion_criteria']['holdout_min_trades']);
        $this->assertSame(2.0, $campaignJson['promotion_criteria']['cost_stress_multiplier']);
    }

    public function test_high_frequency_timeframes_are_blocked_until_explicitly_activated(): void
    {
        $exit = Artisan::call('atlas:finance:strategy-search', [
            '--symbol' => 'BTCUSDT',
            '--interval' => '5m',
            '--rounds' => 1,
            '--candidates' => 10,
            '--sleep' => 0,
            '--no-ledger' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('timeframe 5m is deferred by policy', $output);
        $this->assertStringContainsString('slippage_model_scaled_by_liquidity_and_volatility', $output);
    }

    public function test_existing_campaign_resumes_round_numbers_instead_of_restarting(): void
    {
        $campaign = 'phpunit-resume-'.bin2hex(random_bytes(4));

        $this->artisan('atlas:finance:strategy-search', [
            '--rounds' => 1,
            '--max-rounds' => 2,
            '--candidates' => 10,
            '--sleep' => 0,
            '--seed' => 123,
            '--campaign-id' => $campaign,
            '--dry-run-ledger' => true,
        ])->assertExitCode(0);

        $afterFirstRun = json_decode((string) file_get_contents($this->dryRunRoot.'/'.$campaign.'/campaign.json'), true);
        $this->assertSame('paused', $afterFirstRun['status']);
        $this->assertSame('INCONCLUSIVE', $afterFirstRun['verdict']);

        $this->artisan('atlas:finance:strategy-search', [
            '--rounds' => 1,
            '--max-rounds' => 2,
            '--candidates' => 10,
            '--sleep' => 0,
            '--seed' => 123,
            '--campaign-id' => $campaign,
            '--dry-run-ledger' => true,
        ])->assertExitCode(0);

        $rows = array_values(array_filter(array_map(
            static fn (string $line): ?array => json_decode($line, true),
            file($this->dryRunRoot.'/'.$campaign.'/ledger.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
        )));

        $this->assertSame([1, 2], array_column($rows, 'round'));
        $this->assertSame(0, $rows[0]['incoming_elite_size']);
        $this->assertGreaterThan(0, $rows[1]['incoming_elite_size']);
        $this->assertGreaterThan(0, $rows[1]['elite_pool_size']);
        $this->assertNotEmpty($rows[1]['elite_pool']);
        $afterSecondRun = json_decode((string) file_get_contents($this->dryRunRoot.'/'.$campaign.'/campaign.json'), true);
        $this->assertSame('completed', $afterSecondRun['status']);
        $this->assertStringStartsWith('NULL_', $afterSecondRun['verdict']);
    }

    public function test_default_campaign_budget_uses_holdout_reuse_limit_and_marks_campaign_completed(): void
    {
        $campaign = 'phpunit-budget-'.bin2hex(random_bytes(4));

        $this->artisan('atlas:finance:strategy-search', [
            '--candidates' => 10,
            '--holdout-max-reuse' => 1,
            '--sleep' => 0,
            '--seed' => 123,
            '--campaign-id' => $campaign,
            '--dry-run-ledger' => true,
        ])->assertExitCode(0);

        $campaignJson = json_decode((string) file_get_contents($this->dryRunRoot.'/'.$campaign.'/campaign.json'), true);

        $this->assertSame(1, $campaignJson['pre_registered_budget']['max_rounds']);
        $this->assertSame(10, $campaignJson['pre_registered_budget']['max_candidates']);
        $this->assertSame('completed', $campaignJson['status']);
        $this->assertSame('NULL_HOLDOUT_EXHAUSTED', $campaignJson['verdict']);
        $this->assertArrayHasKey('final_summary', $campaignJson);
        $this->assertSame('holdout_exhausted', $campaignJson['final_summary']['stop_reason']);
        $this->assertSame(StrategyCampaignStore::HOLDOUT_EXHAUSTED, $campaignJson['final_summary']['holdout_status']);

        $next = StrategyScenarioRegistry::default(true)->nextRoadmapScenario(null);
        $this->assertSame('BTCUSDT', $next['symbol']);
        $this->assertSame('trend-breakout-v1', $next['strategy_family']);
        $this->assertSame('holdout_exhausted_needs_fresh_holdout_generation', $next['reason']);
        $this->assertSame(1, $next['holdout_generation']);
    }

    public function test_terminal_campaign_cannot_be_reopened(): void
    {
        $campaign = 'phpunit-terminal-'.bin2hex(random_bytes(4));

        $this->artisan('atlas:finance:strategy-search', [
            '--candidates' => 10,
            '--holdout-max-reuse' => 1,
            '--sleep' => 0,
            '--seed' => 123,
            '--campaign-id' => $campaign,
            '--dry-run-ledger' => true,
        ])->assertExitCode(0);

        $this->artisan('atlas:finance:strategy-search', [
            '--candidates' => 10,
            '--holdout-max-reuse' => 1,
            '--sleep' => 0,
            '--seed' => 123,
            '--campaign-id' => $campaign,
            '--dry-run-ledger' => true,
        ])->assertExitCode(1);
    }

    public function test_confirmation_next_command_reports_pending_sequential_campaign(): void
    {
        $params = [
            'regime_period' => 100,
            'entry_lookback' => 30,
            'exit_lookback' => 15,
            'atr_period' => 14,
            'atr_mult' => 3.0,
            'risk_pct' => 0.15,
            'min_hold_bars' => 4,
        ];
        StrategyConfirmationQueue::default(true)->enqueue([
            'source_campaign_id' => 'phpunit-source',
            'source_round' => 2,
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'signature' => (new StrategyCandidateSignature)->make('BTCUSDT', '1d', 'trend-breakout-v1', $params),
            'candidate_params' => $params,
            'candidates_per_round' => 10,
            'seed' => 789,
        ]);

        $exit = Artisan::call('atlas:finance:strategy-confirmation-next', [
            '--dry-run-ledger' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('strategy_confirmation_next', $output);
        $this->assertStringContainsString('run_only_when_no_strategy_search_loop_is_active', $output);
        $this->assertStringContainsString('atlas:finance:strategy-search', $output);
    }

    public function test_campaign_runner_runs_one_dry_roadmap_campaign_sequentially(): void
    {
        $campaign = 'phpunit-runner-'.bin2hex(random_bytes(4));
        $exit = Artisan::call('atlas:finance:strategy-campaign-runner', [
            '--campaign-id' => $campaign,
            '--candidates' => 10,
            '--max-rounds' => 1,
            '--sleep' => 0,
            '--seed' => 321,
            '--dry-run-ledger' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertFileExists($this->dryRunRoot.'/'.$campaign.'/ledger.jsonl');
    }

    public function test_campaign_runner_can_focus_one_strategy_family_sequentially(): void
    {
        $campaign = 'phpunit-runner-momentum-'.bin2hex(random_bytes(4));
        $exit = Artisan::call('atlas:finance:strategy-campaign-runner', [
            '--family' => 'momentum-v1',
            '--campaign-id' => $campaign,
            '--candidates' => 10,
            '--max-rounds' => 1,
            '--sleep' => 0,
            '--seed' => 987,
            '--dry-run-ledger' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $row = json_decode((string) (file($this->dryRunRoot.'/'.$campaign.'/ledger.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)[0] ?? ''), true);
        $this->assertSame('momentum-v1', $row['strategy_family']);
    }

    public function test_campaign_runner_can_focus_symbol_and_interval_before_stale_active_scenarios(): void
    {
        StrategyScenarioRegistry::default(true)->registerCampaign([
            'campaign_id' => 'phpunit-stale-eth-running',
            'status' => 'running',
            'symbol' => 'ETHUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'data_manifest' => [
                'holdout_generation' => 0,
                'max_holdout_generation' => 4,
            ],
        ]);
        $campaign = 'phpunit-runner-btc-focus-'.bin2hex(random_bytes(4));

        $exit = Artisan::call('atlas:finance:strategy-campaign-runner', [
            '--symbol' => 'BTCUSDT',
            '--interval' => '1d',
            '--campaign-id' => $campaign,
            '--candidates' => 10,
            '--max-rounds' => 1,
            '--sleep' => 0,
            '--seed' => 654,
            '--dry-run-ledger' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $campaignJson = json_decode((string) file_get_contents($this->dryRunRoot.'/'.$campaign.'/campaign.json'), true);
        $this->assertSame('BTCUSDT', $campaignJson['symbol']);
        $this->assertSame('1d', $campaignJson['interval']);
    }

    public function test_campaign_runner_retries_zero_candidate_btc_daily_with_fresh_holdout_generation(): void
    {
        $registry = StrategyScenarioRegistry::default(true);
        foreach (['trend-breakout-v1', 'mean-reversion-v1'] as $family) {
            $registry->recordReport([
                'campaign_id' => 'phpunit-'.$family.'-terminal',
                'symbol' => 'BTCUSDT',
                'interval' => '1d',
                'strategy_family' => $family,
                'verdict' => 'NULL_HOLDOUT_EXHAUSTED',
                'summary' => ['rounds' => 478, 'total_candidates' => 286800, 'holdout_generation' => 0, 'max_holdout_generation' => 0],
            ]);
        }
        $registry->recordReport([
            'campaign_id' => 'phpunit-momentum-zero',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'momentum-v1',
            'verdict' => 'NULL_HOLDOUT_EXHAUSTED',
            'summary' => ['rounds' => 0, 'total_candidates' => 0, 'holdout_generation' => 0],
        ]);
        $campaign = 'phpunit-runner-fresh-holdout-'.bin2hex(random_bytes(4));

        $exit = Artisan::call('atlas:finance:strategy-campaign-runner', [
            '--campaign-id' => $campaign,
            '--candidates' => 10,
            '--max-rounds' => 1,
            '--sleep' => 0,
            '--seed' => 654,
            '--dry-run-ledger' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $campaignJson = json_decode((string) file_get_contents($this->dryRunRoot.'/'.$campaign.'/campaign.json'), true);
        $this->assertSame('BTCUSDT', $campaignJson['symbol']);
        $this->assertSame('1d', $campaignJson['interval']);
        $this->assertSame('momentum-v1', $campaignJson['strategy_family']);
        $this->assertSame(1, $campaignJson['data_manifest']['holdout_generation']);
        $this->assertGreaterThanOrEqual(1, $campaignJson['data_manifest']['max_holdout_generation']);
        $this->assertSame(1, $campaignJson['holdout']['generation']);
    }

    public function test_campaign_runner_continuous_mode_can_be_bounded_to_one_campaign(): void
    {
        $campaign = 'phpunit-runner-continuous-'.bin2hex(random_bytes(4));
        $exit = Artisan::call('atlas:finance:strategy-campaign-runner', [
            '--campaign-id' => $campaign,
            '--candidates' => 10,
            '--max-rounds' => 1,
            '--sleep' => 0,
            '--continuous' => true,
            '--max-campaigns' => 1,
            '--seed' => 246,
            '--dry-run-ledger' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertFileExists($this->dryRunRoot.'/'.$campaign.'/ledger.jsonl');
        $campaignJson = json_decode((string) file_get_contents($this->dryRunRoot.'/'.$campaign.'/campaign.json'), true);
        $this->assertSame('completed', $campaignJson['status']);
    }

    public function test_strategy_loop_audit_passes_for_governed_dry_run_campaign(): void
    {
        $campaign = 'phpunit-audit-'.bin2hex(random_bytes(4));
        $this->runDryCampaign($campaign, 135);

        $exit = Artisan::call('atlas:finance:strategy-loop-audit', [
            '--campaign-id' => $campaign,
            '--dry-run-ledger' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit, json_encode($payload));
        $this->assertSame('pass', $payload['status']);
        $this->assertSame($campaign, $payload['campaign_id']);
        $this->assertSame('forbidden', $payload['live_trading']);
        $this->assertSame($payload['score']['total'], $payload['score']['passed']);
    }

    public function test_scientific_readiness_audit_passes_for_governed_dry_run_campaign(): void
    {
        $campaign = 'phpunit-readiness-'.bin2hex(random_bytes(4));
        $this->runDryCampaign($campaign, 235);

        $exit = Artisan::call('atlas:finance:strategy-scientific-readiness-audit', [
            '--campaign-id' => $campaign,
            '--dry-run-ledger' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit, json_encode($payload));
        $this->assertSame('pass', $payload['status']);
        $this->assertSame($campaign, $payload['campaign_id']);
        $this->assertSame('scientific_campaigns_not_stronger_bruteforce', $payload['platform_policy']);
        $this->assertContains('scenario_matrix_research_only', array_column($payload['checks'], 'name'));
        $this->assertSame($payload['score']['total'], $payload['score']['passed']);
    }

    public function test_strategy_loop_audit_rejects_campaign_without_real_second_engine(): void
    {
        $campaign = 'phpunit-audit-no-second-engine-'.bin2hex(random_bytes(4));

        $this->artisan('atlas:finance:strategy-search', [
            '--rounds' => 1,
            '--max-rounds' => 1,
            '--candidates' => 10,
            '--sleep' => 0,
            '--seed' => 136,
            '--campaign-id' => $campaign,
            '--second-engine' => 'none',
            '--dry-run-ledger' => true,
        ])->assertExitCode(0);

        $exit = Artisan::call('atlas:finance:strategy-loop-audit', [
            '--campaign-id' => $campaign,
            '--dry-run-ledger' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit, json_encode($payload));
        $this->assertSame('fail', $payload['status']);
        $this->assertContains('campaign_second_engine_real', array_column($payload['checks'], 'name'));
        $failedChecks = array_values(array_filter(
            $payload['checks'],
            static fn (array $check): bool => ! (bool) $check['passed'],
        ));
        $this->assertSame(['campaign_second_engine_real'], array_column($failedChecks, 'name'));
    }

    public function test_strategy_loop_audit_validates_pinned_freqtrade_report_metadata(): void
    {
        $campaign = 'phpunit-audit-bad-freqtrade-'.bin2hex(random_bytes(4));
        $report = storage_path('framework/testing/phpunit-freqtrade-'.bin2hex(random_bytes(4)).'.json');
        @mkdir(dirname($report), 0o755, true);
        file_put_contents($report, json_encode([
            'engine' => 'freqtrade',
            'trading_mode' => 'spot',
            'propose_only' => true,
            'live_trading' => 'forbidden',
            'symbol' => 'ETHUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'data_sha' => 'wrong-data-sha',
            'holdout_id' => 'wrong-holdout',
            'cost_profile_hash' => 'wrong-cost',
            'metrics' => [
                'trade_count' => 20,
                'ann_sharpe' => 1.0,
                'max_dd' => 0.10,
                'total_return' => 0.12,
                'exposure' => 0.2,
                'equity_curve_sample' => [1.0, 1.04, 1.12],
                'holdout_passed' => true,
            ],
        ], JSON_UNESCAPED_SLASHES));

        try {
            $this->artisan('atlas:finance:strategy-search', [
                '--rounds' => 1,
                '--max-rounds' => 1,
                '--candidates' => 10,
                '--sleep' => 0,
                '--seed' => 137,
                '--campaign-id' => $campaign,
                '--second-engine' => 'freqtrade',
                '--freqtrade-report' => $report,
                '--dry-run-ledger' => true,
            ])->assertExitCode(0);

            $exit = Artisan::call('atlas:finance:strategy-loop-audit', [
                '--campaign-id' => $campaign,
                '--dry-run-ledger' => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true);

            $this->assertSame(1, $exit, json_encode($payload));
            $this->assertSame('fail', $payload['status']);
            $failedChecks = array_values(array_filter(
                $payload['checks'],
                static fn (array $check): bool => ! (bool) $check['passed'],
            ));
            $this->assertSame(['campaign_freqtrade_report_validated'], array_column($failedChecks, 'name'));
            $this->assertSame('freqtrade_report_symbol_mismatch', $failedChecks[0]['reason']);

            $campaignJson = json_decode((string) file_get_contents($this->dryRunRoot.'/'.$campaign.'/campaign.json'), true);
            file_put_contents($report, json_encode([
                'engine' => 'freqtrade',
                'trading_mode' => 'spot',
                'propose_only' => true,
                'live_trading' => 'forbidden',
                'symbol' => $campaignJson['symbol'],
                'interval' => $campaignJson['interval'],
                'strategy_family' => $campaignJson['strategy_family'],
                'data_sha' => $campaignJson['data_manifest']['sha256'],
                'holdout_id' => $campaignJson['holdout']['holdout_id'],
                'cost_profile_hash' => $campaignJson['cost_profile']['cost_profile_hash'],
                'metrics' => [
                    'trade_count' => 20,
                    'ann_sharpe' => 1.0,
                    'max_dd' => 0.10,
                    'total_return' => 0.12,
                    'exposure' => 0.2,
                    'equity_curve_sample' => [1.0, 1.04, 1.12],
                    'holdout_passed' => true,
                ],
            ], JSON_UNESCAPED_SLASHES));

            $exit = Artisan::call('atlas:finance:strategy-loop-audit', [
                '--campaign-id' => $campaign,
                '--dry-run-ledger' => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true);

            $this->assertSame(0, $exit, json_encode($payload));
            $this->assertSame('pass', $payload['status']);
        } finally {
            @unlink($report);
        }
    }

    public function test_strategy_adversarial_audit_passes_without_touching_real_loop_state(): void
    {
        $exit = Artisan::call('atlas:finance:strategy-adversarial-audit', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit, json_encode($payload));
        $this->assertSame('pass', $payload['status']);
        $this->assertSame($payload['score']['total'], $payload['score']['passed']);
        $this->assertContains('freqtrade_scenario_mismatch_fails_closed', array_column($payload['checks'], 'name'));
        $this->assertContains('exhausted_holdout_blocks_certification', array_column($payload['checks'], 'name'));
        $this->assertContains('python_second_engine_supports_all_implemented_families', array_column($payload['checks'], 'name'));
        $this->assertSame('forbidden', $payload['live_trading']);
    }

    public function test_certified_proposal_header_uses_cumulative_campaign_dsr(): void
    {
        $dir = storage_path('framework/testing/phpunit-proposal-'.bin2hex(random_bytes(4)));
        $store = new StrategyCampaignStore('phpunit-proposal', $dir, $dir.'/ledger.jsonl', ['campaign_id' => 'phpunit-proposal'], true);
        $command = new AtlasFinanceStrategySearchCommand;
        $method = new ReflectionMethod($command, 'persistProposal');
        $method->setAccessible(true);

        try {
            $method->invoke($command, $store, 'BTCUSDT', '1d', 'trend-breakout-v1', 600, 60_000, [
                'report' => ['deflated_sharpe' => 0.99, 'n_trials' => 600],
                'campaign_verdict' => ['certified' => true, 'report' => ['deflated_sharpe' => 0.951, 'n_trials' => 60_000]],
                'quarantine' => ['status' => 'certified_for_review'],
                'winner_island' => 'robustness',
                'winner_params' => ['entry_lookback' => 20],
                'holdout' => ['ann_sharpe' => 0.8],
                'confirmation_holdout' => ['ann_sharpe' => 0.7],
            ]);
            $files = glob($dir.'/proposals/*.json') ?: [];
            $this->assertCount(1, $files);
            $proposal = json_decode((string) file_get_contents($files[0]), true);

            $this->assertSame(0.951, $proposal['honesty_report']['deflated_sharpe']);
            $this->assertSame(0.99, $proposal['round_honesty_report']['deflated_sharpe']);
            $this->assertSame(0.951, $proposal['campaign_deflated_sharpe']);
            $this->assertSame(0.99, $proposal['round_deflated_sharpe']);
        } finally {
            (new Process(['rm', '-rf', $dir]))->run();
        }
    }

    public function test_round_result_keeps_round_and_campaign_reasons_separate(): void
    {
        $command = new AtlasFinanceStrategySearchCommand;
        $method = new ReflectionMethod($command, 'roundResult');
        $method->setAccessible(true);

        $result = $method->invoke(
            $command,
            42,
            600,
            ['ann_sharpe' => 1.2, 'island' => 'robustness', 'params' => ['entry_lookback' => 20]],
            false,
            false,
            'rejected_honest_null',
            ['deflated_sharpe_too_low(0.4<0.95, N=60000)'],
            ['deflated_sharpe' => 0.8, 'n_trials' => 600],
            [],
            ['ann_sharpe' => 0.7],
            ['reasons' => ['certified'], 'report' => ['deflated_sharpe' => 0.8, 'n_trials' => 600]],
            ['reasons' => ['deflated_sharpe_too_low(0.4<0.95, N=60000)'], 'report' => ['deflated_sharpe' => 0.4, 'n_trials' => 60_000]],
        );

        $this->assertSame(['certified'], $result['round_reasons']);
        $this->assertSame(['deflated_sharpe_too_low(0.4<0.95, N=60000)'], $result['campaign_reasons']);
        $this->assertSame(['deflated_sharpe_too_low(0.4<0.95, N=60000)'], $result['reasons']);
    }

    public function test_campaign_runner_fails_before_search_when_next_scenario_data_is_missing(): void
    {
        $params = [
            'regime_period' => 100,
            'entry_lookback' => 30,
            'exit_lookback' => 15,
            'atr_period' => 14,
            'atr_mult' => 3.0,
            'risk_pct' => 0.15,
            'min_hold_bars' => 4,
        ];
        StrategyConfirmationQueue::default(true)->enqueue([
            'source_campaign_id' => 'phpunit-source',
            'source_round' => 2,
            'symbol' => 'NOCOINUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'signature' => (new StrategyCandidateSignature)->make('NOCOINUSDT', '1d', 'trend-breakout-v1', $params),
            'candidate_params' => $params,
            'candidates_per_round' => 10,
            'seed' => 789,
        ]);

        $exit = Artisan::call('atlas:finance:strategy-campaign-runner', [
            '--candidates' => 10,
            '--max-rounds' => 1,
            '--sleep' => 0,
            '--dry-run-ledger' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('market_data_missing', $output);
        $this->assertStringContainsString('NOCOINUSDT-1d.csv', $output);
        $this->assertStringContainsString('fetch-btc-history.sh NOCOINUSDT 1d', $output);
    }

    private function runDryCampaign(string $campaignId, int $seed): void
    {
        $this->artisan('atlas:finance:strategy-search', [
            '--rounds' => 1,
            '--max-rounds' => 1,
            '--candidates' => 10,
            '--sleep' => 0,
            '--seed' => $seed,
            '--campaign-id' => $campaignId,
            '--dry-run-ledger' => true,
        ])->assertExitCode(0);
    }
}
