<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

use App\Console\Commands\AtlasFinanceStrategySearchCommand;
use App\Services\Ai\Finance\StrategyLoop\Bar;
use App\Services\Ai\Finance\StrategyLoop\Metrics\HonestMetrics;
use App\Services\Ai\Finance\StrategyLoop\Strategy\MeanReversionStrategy;
use App\Services\Ai\Finance\StrategyLoop\Strategy\MomentumStrategy;
use App\Services\Ai\Finance\StrategyLoop\Strategy\StrategyRunner;
use App\Services\Ai\Finance\StrategyLoop\Strategy\TrendBreakoutStrategy;
use App\Support\UtcIsoTimestamp;

/**
 * Dry adversarial audit for the strategy campaign platform.
 *
 * These checks intentionally try bad states: wrong second-engine metadata,
 * exhausted holdout, unavailable second engine, terminal campaign reopen shape,
 * and family/signature confusion. It mutates only temporary files.
 */
final class StrategyLoopAdversarialAudit
{
    use CampaignCheckHelper;

    /** @return array<string,mixed> */
    public function audit(): array
    {
        $checks = [
            $this->unsupportedFamilyNotInSearchAllowlist(),
            $this->terminalCampaignIsRecognizedAsTerminal(),
            $this->exhaustedHoldoutBlocksQuarantine(),
            $this->missingSecondEngineBlocksQuarantine(),
            $this->scenarioPenaltyBlocksQuarantine(),
            $this->divergentSecondEngineFailsGate(),
            $this->pythonReplaySupportsImplementedFamilies(),
            $this->freqtradeScenarioMismatchFailsClosed(),
            $this->freqtradeLivePathFailsClosed(),
            $this->noExecutionSurfaceScannerCatchesBrokerPath(),
            $this->holdoutRegistryDoesNotDoubleCountLocalRound(),
            $this->candidateSignaturesAreFamilyScoped(),
            $this->roadmapIsScenarioScoped(),
            $this->scenarioEliteSeedsDoNotCrossMarketsOrFamilies(),
            $this->zeroCandidateNullCannotCloseScenario(),
            $this->holdoutExhaustionRetriesFreshGeneration(),
            $this->searchReuseBudgetDoesNotCloseFamily(),
            $this->timeframeCostStressMultiplierIsApplied(),
            $this->activeScenarioResumePreemptsOlderFreshGenerationRetry(),
        ];
        $passed = array_reduce($checks, static fn (bool $ok, array $check): bool => $ok && (bool) $check['passed'], true);

        return [
            'schema_version' => 'atlas.finance.strategy_loop_adversarial_audit.v1',
            'generated_at' => UtcIsoTimestamp::now(),
            'status' => $passed ? 'pass' : 'fail',
            'score' => [
                'passed' => count(array_filter($checks, static fn (array $check): bool => (bool) $check['passed'])),
                'total' => count($checks),
            ],
            'checks' => $checks,
            'propose_only' => true,
            'live_trading' => 'forbidden',
        ];
    }

    /** @return array<string,mixed> */
    private function unsupportedFamilyNotInSearchAllowlist(): array
    {
        $constant = (new \ReflectionClass(AtlasFinanceStrategySearchCommand::class))->getReflectionConstant('SUPPORTED_FAMILIES');
        $families = is_object($constant) ? (array) $constant->getValue() : [];
        $passed = in_array('trend-breakout-v1', $families, true)
            && in_array('mean-reversion-v1', $families, true)
            && in_array('momentum-v1', $families, true)
            && ! in_array('unsupported-family-v1', $families, true);

        return $this->check('unsupported_family_not_allowlisted', $passed, 'search command has an explicit family allowlist');
    }

    /** @return array<string,mixed> */
    private function terminalCampaignIsRecognizedAsTerminal(): array
    {
        $store = new StrategyCampaignStore('terminal', sys_get_temp_dir(), sys_get_temp_dir().'/ledger.jsonl', [
            'campaign_id' => 'terminal',
            'status' => 'completed',
            'verdict' => 'NULL_HOLDOUT_EXHAUSTED',
        ], false);

        return $this->check('terminal_campaign_reopen_shape_rejected', $store->isTerminal(), 'terminal campaign shape is recognized as terminal');
    }

    /** @return array<string,mixed> */
    private function exhaustedHoldoutBlocksQuarantine(): array
    {
        $result = (new ChampionQuarantine)->evaluate($this->passingQuarantineInput([
            'holdout_status' => StrategyCampaignStore::HOLDOUT_EXHAUSTED,
        ]));

        return $this->check(
            'exhausted_holdout_blocks_certification',
            (bool) ($result['promoted'] ?? false) && ! (bool) ($result['certified'] ?? true) && in_array('fresh_holdout_required(EXHAUSTED)', (array) ($result['reasons'] ?? []), true),
            'candidate can be promoted but not certified on an exhausted holdout',
        );
    }

    /** @return array<string,mixed> */
    private function missingSecondEngineBlocksQuarantine(): array
    {
        $second = (new SecondEngineDivergenceGate)->evaluate(['trade_count' => 20, 'ann_sharpe' => 1.0, 'max_dd' => 0.1], null);
        $result = (new ChampionQuarantine)->evaluate($this->passingQuarantineInput(['second_engine' => $second]));

        return $this->check(
            'missing_second_engine_blocks_certification',
            (bool) ($result['promoted'] ?? false) && ! (bool) ($result['certified'] ?? true) && in_array('second_engine_required', (array) ($result['reasons'] ?? []), true),
            'quarantine refuses certification when independent engine is unavailable',
        );
    }

    /** @return array<string,mixed> */
    private function scenarioPenaltyBlocksQuarantine(): array
    {
        $result = (new ChampionQuarantine)->evaluate($this->passingQuarantineInput([
            'scenario_verdict' => ['certified' => false, 'reasons' => ['deflated_sharpe_too_low'], 'report' => ['n_trials' => 500_000]],
        ]));

        return $this->check(
            'scenario_level_trial_penalty_blocks_certification',
            (bool) ($result['promoted'] ?? false) && ! (bool) ($result['certified'] ?? true) && in_array('scenario_penalty_failed', (array) ($result['reasons'] ?? []), true),
            'scenario-wide cumulative N must pass before a champion can certify',
        );
    }

    /** @return array<string,mixed> */
    private function divergentSecondEngineFailsGate(): array
    {
        $result = (new SecondEngineDivergenceGate)->evaluate(
            ['trade_count' => 20, 'ann_sharpe' => 0.9, 'max_dd' => 0.10],
            ['trade_count' => 8, 'ann_sharpe' => -0.2, 'max_dd' => 0.21],
        );

        return $this->check(
            'divergent_second_engine_fails',
            ! (bool) ($result['passed'] ?? true)
                && in_array('trade_count_diverged', (array) ($result['reasons'] ?? []), true)
                && in_array('sharpe_sign_inverted', (array) ($result['reasons'] ?? []), true),
            'second-engine divergence is rejected',
        );
    }

    /** @return array<string,mixed> */
    private function pythonReplaySupportsImplementedFamilies(): array
    {
        $cases = [
            'trend-breakout-v1' => [
                'strategy' => new TrendBreakoutStrategy,
                'bars' => $this->trendBars(),
                'params' => [
                    'regime_period' => 10,
                    'entry_lookback' => 5,
                    'exit_lookback' => 5,
                    'atr_period' => 5,
                    'atr_mult' => 2.0,
                    'risk_pct' => 0.2,
                    'fee_bps' => 10,
                    'slippage_bps' => 5,
                    'min_hold_bars' => 1,
                ],
            ],
            'mean-reversion-v1' => [
                'strategy' => new MeanReversionStrategy,
                'bars' => $this->meanReversionBars(),
                'params' => [
                    'regime_period' => 0,
                    'lookback' => 8,
                    'entry_z' => 1.0,
                    'exit_z' => 0.0,
                    'risk_pct' => 0.5,
                    'stop_loss_pct' => 0.25,
                    'max_hold_bars' => 12,
                    'fee_bps' => 10,
                    'slippage_bps' => 5,
                ],
            ],
            'momentum-v1' => [
                'strategy' => new MomentumStrategy,
                'bars' => $this->momentumBars(),
                'params' => [
                    'regime_period' => 0,
                    'momentum_lookback' => 5,
                    'entry_momentum' => 0.03,
                    'exit_momentum' => 0.0,
                    'risk_pct' => 0.5,
                    'stop_loss_pct' => 0.12,
                    'trailing_stop_pct' => 0.10,
                    'max_hold_bars' => 18,
                    'fee_bps' => 10,
                    'slippage_bps' => 5,
                ],
            ],
        ];

        $failed = [];
        foreach ($cases as $family => $case) {
            /** @var StrategyRunner $strategy */
            $strategy = $case['strategy'];
            /** @var list<Bar> $bars */
            $bars = $case['bars'];
            /** @var array<string,mixed> $params */
            $params = $case['params'];
            $primary = $strategy->run($bars, $params);
            $metrics = new HonestMetrics;
            $primaryReport = [
                'trade_count' => $primary->nTrades,
                'ann_sharpe' => $metrics->sharpe($primary->dailyReturns, 365.0),
                'max_dd' => $metrics->maxDrawdown($primary->equityCurve),
            ];
            $secondary = (new ExternalPythonTrendBreakoutReplay)->evaluate($bars, $params, 365.0, $family);
            $divergence = (new SecondEngineDivergenceGate)->evaluate($primaryReport, $secondary);
            if ((string) ($secondary['status'] ?? '') !== 'ready' || ! (bool) ($divergence['passed'] ?? false)) {
                $failed[$family] = [
                    'secondary_status' => $secondary['status'] ?? null,
                    'secondary_reason' => $secondary['reason'] ?? null,
                    'divergence' => $divergence['reasons'] ?? [],
                ];
            }
        }

        return $this->check(
            'python_second_engine_supports_all_implemented_families',
            $failed === [],
            'external Python replay supports trend, mean-reversion, and momentum families',
            ['failed_families' => $failed],
        );
    }

    /** @return array<string,mixed> */
    private function freqtradeScenarioMismatchFailsClosed(): array
    {
        $path = $this->tempReport([
            'engine' => 'freqtrade',
            'trading_mode' => 'spot',
            'propose_only' => true,
            'live_trading' => 'forbidden',
            'symbol' => 'ETHUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'data_sha' => 'data-sha',
            'holdout_id' => 'holdout-1',
            'cost_profile_hash' => 'cost-hash',
            'metrics' => ['trade_count' => 20, 'ann_sharpe' => 1.0, 'max_dd' => 0.1],
        ]);
        try {
            $result = (new FreqtradeSecondEngineAdapter)->evaluate($path, [
                'symbol' => 'BTCUSDT',
                'interval' => '1d',
                'strategy_family' => 'trend-breakout-v1',
                'data_sha' => 'data-sha',
                'holdout_id' => 'holdout-1',
                'cost_profile_hash' => 'cost-hash',
            ]);
        } finally {
            @unlink($path);
        }

        return $this->check(
            'freqtrade_scenario_mismatch_fails_closed',
            (string) ($result['status'] ?? '') === 'unavailable' && (string) ($result['reason'] ?? '') === 'freqtrade_report_symbol_mismatch',
            'pinned external report cannot come from another symbol',
        );
    }

    /** @return array<string,mixed> */
    private function freqtradeLivePathFailsClosed(): array
    {
        $path = $this->tempReport([
            'engine' => 'freqtrade',
            'trading_mode' => 'futures',
            'propose_only' => false,
            'live_trading' => 'allowed',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'data_sha' => 'data-sha',
            'holdout_id' => 'holdout-1',
            'cost_profile_hash' => 'cost-hash',
            'metrics' => ['trade_count' => 20, 'ann_sharpe' => 1.0, 'max_dd' => 0.1],
        ]);
        try {
            $result = (new FreqtradeSecondEngineAdapter)->evaluate($path);
        } finally {
            @unlink($path);
        }

        return $this->check(
            'freqtrade_live_path_fails_closed',
            (string) ($result['status'] ?? '') === 'unavailable' && (string) ($result['reason'] ?? '') === 'freqtrade_report_not_propose_only',
            'external report must explicitly be propose-only and live-trading forbidden',
        );
    }

    /** @return array<string,mixed> */
    private function noExecutionSurfaceScannerCatchesBrokerPath(): array
    {
        $path = sys_get_temp_dir().'/atlas-execution-surface-adversarial-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($path, <<<'PHP'
<?php
$exchange = new ccxt\binance(['apiKey' => 'x', 'secretKey' => 'y']);
$exchange->createOrder('BTC/USDT', 'market', 'buy', 1);
$payload = ['live_trading' => 'allowed'];
PHP);

        try {
            $scan = (new StrategyNoExecutionSurfaceAudit)->scanFiles([$path]);
        } finally {
            @unlink($path);
        }

        $reasons = array_column((array) ($scan['violations'] ?? []), 'reason');

        return $this->check(
            'no_execution_surface_scanner_catches_broker_path',
            (bool) ($scan['passed'] ?? true) === false
                && in_array('ccxt_dependency', $reasons, true)
                && in_array('api_key_reference', $reasons, true)
                && in_array('secret_key_reference', $reasons, true)
                && in_array('create_order_call', $reasons, true)
                && in_array('live_trading_allowed', $reasons, true),
            'static guard catches broker/order/key/live-money code',
        );
    }

    /** @return array<string,mixed> */
    private function holdoutRegistryDoesNotDoubleCountLocalRound(): array
    {
        $path = sys_get_temp_dir().'/atlas-holdout-adversarial-'.bin2hex(random_bytes(4)).'.json';
        $registry = new HoldoutRegistry($path);
        $registry->register([
            'holdout_id' => 'h1',
            'role' => 'validation',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'range' => ['bars' => 100],
            'data_sha' => 'abc',
            'max_reuse' => 1000,
        ]);
        try {
            for ($i = 0; $i < 521; $i++) {
                $registry->recordUse('h1', ['campaign_id' => 'c1', 'max_reuse' => 1000]);
            }
            $active = $registry->statusForUse('h1', 478, 1000) === StrategyCampaignStore::HOLDOUT_ACTIVE;
        } finally {
            @unlink($path);
        }

        return $this->check('holdout_reuse_not_double_counted', $active, 'global holdout reuse is not double-counted with local round number');
    }

    /** @return array<string,mixed> */
    private function candidateSignaturesAreFamilyScoped(): array
    {
        $signer = new StrategyCandidateSignature;
        $trend = $signer->make('BTCUSDT', '1d', 'trend-breakout-v1', [
            'regime_period' => 100,
            'entry_lookback' => 30,
            'exit_lookback' => 15,
            'atr_period' => 14,
            'atr_mult' => 3.0,
            'risk_pct' => 0.2,
            'min_hold_bars' => 3,
        ]);
        $momentum = $signer->make('BTCUSDT', '1d', 'momentum-v1', [
            'regime_period' => 100,
            'momentum_lookback' => 30,
            'entry_momentum' => 0.03,
            'exit_momentum' => 0.0,
            'risk_pct' => 0.2,
            'stop_loss_pct' => 0.08,
            'trailing_stop_pct' => 0.1,
            'max_hold_bars' => 30,
        ]);

        return $this->check(
            'candidate_signature_family_scoped',
            (string) ($trend['signature'] ?? '') !== (string) ($momentum['signature'] ?? ''),
            'cross-campaign signatures cannot mix strategy families',
        );
    }

    /** @return array<string,mixed> */
    private function roadmapIsScenarioScoped(): array
    {
        $path = sys_get_temp_dir().'/atlas-scenario-adversarial-'.bin2hex(random_bytes(4)).'.json';
        $registry = new StrategyScenarioRegistry($path);
        try {
            $snapshot = $registry->load();
            $roadmap = array_values((array) ($snapshot['sequential_roadmap'] ?? []));
            $first = $roadmap[0] ?? [];
            $families = array_unique(array_map(static fn (array $row): string => (string) ($row['strategy_family'] ?? ''), array_filter($roadmap, 'is_array')));
            $passed = is_array($first)
                && isset($first['symbol'], $first['interval'], $first['strategy_family'])
                && in_array('trend-breakout-v1', $families, true)
                && in_array('mean-reversion-v1', $families, true)
                && in_array('momentum-v1', $families, true);
        } finally {
            @unlink($path);
        }

        return $this->check('roadmap_is_full_scenario_scoped', $passed, 'roadmap encodes market, timeframe, and family');
    }

    /** @return array<string,mixed> */
    private function scenarioEliteSeedsDoNotCrossMarketsOrFamilies(): array
    {
        $path = sys_get_temp_dir().'/atlas-scenario-elite-seeds-adversarial-'.bin2hex(random_bytes(4)).'.json';
        $registry = new StrategyScenarioRegistry($path);
        try {
            $registry->recordReport([
                'campaign_id' => 'btc-trend',
                'symbol' => 'BTCUSDT',
                'interval' => '1d',
                'strategy_family' => 'trend-breakout-v1',
                'verdict' => 'NULL_HOLDOUT_EXHAUSTED',
                'summary' => ['rounds' => 100, 'total_candidates' => 60000],
                'scenario_profile' => [
                    'best_observed' => [
                        'campaign_deflated_sharpe' => [
                            'winner_island' => 'robustness',
                            'campaign_deflated_sharpe' => 0.2,
                            'winner_strategy' => ['entry_lookback' => 20, 'risk_pct' => 0.1],
                        ],
                    ],
                ],
            ]);
            $registry->recordReport([
                'campaign_id' => 'eth-trend',
                'symbol' => 'ETHUSDT',
                'interval' => '1d',
                'strategy_family' => 'trend-breakout-v1',
                'verdict' => 'NULL_HOLDOUT_EXHAUSTED',
                'summary' => ['rounds' => 100, 'total_candidates' => 60000],
                'scenario_profile' => [
                    'best_observed' => [
                        'campaign_deflated_sharpe' => [
                            'winner_island' => 'aggressive',
                            'campaign_deflated_sharpe' => 0.9,
                            'winner_strategy' => ['entry_lookback' => 99, 'risk_pct' => 0.5],
                        ],
                    ],
                ],
            ]);
            $btcSeeds = $registry->eliteSeeds('BTCUSDT', '1d', 'trend-breakout-v1');
            $momentumSeeds = $registry->eliteSeeds('BTCUSDT', '1d', 'momentum-v1');
            $passed = count($btcSeeds) === 1
                && (int) data_get($btcSeeds, '0.params.entry_lookback') === 20
                && $momentumSeeds === [];
        } finally {
            @unlink($path);
        }

        return $this->check(
            'scenario_elite_seeds_are_exact_scope',
            $passed,
            'cross-campaign elite warm-starts are scoped to exact symbol, timeframe, family, and feature set',
        );
    }

    /** @return array<string,mixed> */
    private function zeroCandidateNullCannotCloseScenario(): array
    {
        $path = sys_get_temp_dir().'/atlas-scenario-zero-candidate-adversarial-'.bin2hex(random_bytes(4)).'.json';
        $registry = new StrategyScenarioRegistry($path);
        try {
            foreach (['trend-breakout-v1', 'mean-reversion-v1'] as $family) {
                $registry->recordReport([
                    'campaign_id' => 'btc-'.$family.'-terminal',
                    'symbol' => 'BTCUSDT',
                    'interval' => '1d',
                    'strategy_family' => $family,
                    'verdict' => 'NULL_HOLDOUT_EXHAUSTED',
                    'summary' => [
                        'rounds' => 1000,
                        'total_candidates' => 600000,
                        'holdout_generation' => 0,
                        'max_holdout_generation' => 0,
                    ],
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
            $scenario = (array) data_get($snapshot, 'scenarios.BTCUSDT-1d-momentum-v1', []);
            $next = $registry->nextRoadmapScenario(null) ?? [];
            $passed = (string) ($scenario['latest_verdict'] ?? '') === 'INCONCLUSIVE'
                && (string) ($scenario['latest_raw_verdict'] ?? '') === 'NULL_HOLDOUT_EXHAUSTED'
                && (string) ($next['symbol'] ?? '') === 'BTCUSDT'
                && (string) ($next['interval'] ?? '') === '1d'
                && (string) ($next['strategy_family'] ?? '') === 'momentum-v1'
                && (string) ($next['reason'] ?? '') === 'zero_candidate_campaign_needs_fresh_holdout_generation'
                && (int) ($next['holdout_generation'] ?? -1) === 1;
        } finally {
            @unlink($path);
        }

        return $this->check(
            'zero_candidate_null_cannot_close_scenario',
            $passed,
            'zero-candidate NULL/CERTIFIED evidence is normalized to INCONCLUSIVE and retried on a fresh holdout generation',
        );
    }

    /** @return array<string,mixed> */
    private function holdoutExhaustionRetriesFreshGeneration(): array
    {
        $path = sys_get_temp_dir().'/atlas-scenario-fresh-generation-adversarial-'.bin2hex(random_bytes(4)).'.json';
        $registry = new StrategyScenarioRegistry($path);
        try {
            $registry->recordReport([
                'campaign_id' => 'btc-trend-generation-0',
                'symbol' => 'BTCUSDT',
                'interval' => '1d',
                'strategy_family' => 'trend-breakout-v1',
                'verdict' => 'NULL_HOLDOUT_EXHAUSTED',
                'summary' => [
                    'rounds' => 1000,
                    'total_candidates' => 600000,
                    'holdout_generation' => 0,
                    'max_holdout_generation' => 4,
                ],
            ]);
            $next = $registry->nextRoadmapScenario(null) ?? [];
            $passed = (string) ($next['symbol'] ?? '') === 'BTCUSDT'
                && (string) ($next['interval'] ?? '') === '1d'
                && (string) ($next['strategy_family'] ?? '') === 'trend-breakout-v1'
                && (string) ($next['reason'] ?? '') === 'holdout_exhausted_needs_fresh_holdout_generation'
                && (string) ($next['previous_campaign_id'] ?? '') === 'btc-trend-generation-0'
                && (int) ($next['holdout_generation'] ?? -1) === 1;
        } finally {
            @unlink($path);
        }

        return $this->check(
            'holdout_exhaustion_retries_fresh_generation',
            $passed,
            'evidence-bearing NULL_HOLDOUT_EXHAUSTED retries the same scenario while a fresh holdout generation remains',
        );
    }

    /** @return array<string,mixed> */
    private function searchReuseBudgetDoesNotCloseFamily(): array
    {
        $path = (new \ReflectionClass(AtlasFinanceStrategySearchCommand::class))->getFileName();
        $source = is_string($path) && is_file($path) ? (string) file_get_contents($path) : '';
        $budgetBlock = '';
        if (preg_match('/if \(\$maxRounds > 0 && \$round >= \$maxRounds\).*?break;\s*}/s', $source, $matches) === 1) {
            $budgetBlock = (string) ($matches[0] ?? '');
        }
        $passed = $budgetBlock !== ''
            && str_contains($budgetBlock, '$holdoutMaxReuse')
            && str_contains($budgetBlock, 'StrategyCampaignStore::HOLDOUT_EXHAUSTED')
            && str_contains($budgetBlock, "'holdout_exhausted'")
            && str_contains($budgetBlock, "'campaign_round_budget_complete'");

        return $this->check(
            'search_reuse_budget_does_not_close_family',
            $passed,
            'when the default round budget reaches the holdout reuse limit, search records holdout exhaustion instead of family exhaustion',
        );
    }

    /** @return array<string,mixed> */
    private function timeframeCostStressMultiplierIsApplied(): array
    {
        $searchPath = (new \ReflectionClass(AtlasFinanceStrategySearchCommand::class))->getFileName();
        $storePath = (new \ReflectionClass(StrategyCampaignStore::class))->getFileName();
        $search = is_string($searchPath) && is_file($searchPath) ? (string) file_get_contents($searchPath) : '';
        $store = is_string($storePath) && is_file($storePath) ? (string) file_get_contents($storePath) : '';
        $passed = str_contains($search, '$this->costStressMultiplier = max(2.0')
            && str_contains($search, "'cost_stress_multiplier' => \$this->costStressMultiplier")
            && str_contains($search, '$this->feeBps = $oldFee * $this->costStressMultiplier')
            && str_contains($search, '$this->slippageBps = $oldSlip * $this->costStressMultiplier')
            && ! str_contains($search, '$this->feeBps = $oldFee * 2.0')
            && ! str_contains($search, '$this->slippageBps = $oldSlip * 2.0')
            && str_contains($store, "'cost_stress_multiplier' => \$costStressMultiplier")
            && str_contains($store, "'cost_stress_required' => true");

        return $this->check(
            'timeframe_cost_stress_multiplier_is_applied',
            $passed,
            'cost stress is governed by the timeframe policy multiplier and recorded in campaign promotion criteria',
        );
    }

    /** @return array<string,mixed> */
    private function activeScenarioResumePreemptsOlderFreshGenerationRetry(): array
    {
        $path = sys_get_temp_dir().'/atlas-scenario-active-resume-adversarial-'.bin2hex(random_bytes(4)).'.json';
        $registry = new StrategyScenarioRegistry($path);
        try {
            $registry->recordReport([
                'campaign_id' => 'btc-trend-generation-0',
                'symbol' => 'BTCUSDT',
                'interval' => '1d',
                'strategy_family' => 'trend-breakout-v1',
                'verdict' => 'NULL_HOLDOUT_EXHAUSTED',
                'summary' => [
                    'rounds' => 1000,
                    'total_candidates' => 600000,
                    'holdout_generation' => 0,
                    'max_holdout_generation' => 4,
                ],
            ]);
            $registry->registerCampaign([
                'campaign_id' => 'btc-momentum-running',
                'symbol' => 'BTCUSDT',
                'interval' => '1d',
                'strategy_family' => 'momentum-v1',
                'status' => 'running',
                'data_manifest' => [
                    'holdout_generation' => 2,
                    'max_holdout_generation' => 4,
                ],
            ]);
            $next = $registry->nextRoadmapScenario(null) ?? [];
            $passed = (string) ($next['symbol'] ?? '') === 'BTCUSDT'
                && (string) ($next['interval'] ?? '') === '1d'
                && (string) ($next['strategy_family'] ?? '') === 'momentum-v1'
                && (string) ($next['reason'] ?? '') === 'scenario_incomplete'
                && (string) ($next['latest_campaign_id'] ?? '') === 'btc-momentum-running'
                && (int) ($next['holdout_generation'] ?? -1) === 2;
        } finally {
            @unlink($path);
        }

        return $this->check(
            'active_scenario_resume_preempts_fresh_generation_retry',
            $passed,
            'a running campaign is resumed before older terminal scenarios are retried with fresh holdout generations',
        );
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function passingQuarantineInput(array $overrides = []): array
    {
        return array_replace_recursive([
            'round_verdict' => ['certified' => true, 'reasons' => ['certified'], 'report' => ['n_trials' => 600]],
            'campaign_verdict' => ['certified' => true, 'reasons' => ['certified'], 'report' => ['n_trials' => 600]],
            'scenario_verdict' => ['certified' => true, 'reasons' => ['certified'], 'report' => ['n_trials' => 600]],
            'holdout_status' => StrategyCampaignStore::HOLDOUT_FRESH,
            'fresh_holdout' => ['ann_sharpe' => 0.7, 'n_trades' => 15],
            'cost_stress' => ['passed' => true, 'reason' => 'cost_stress_passed'],
            'neighborhood' => ['passed' => true, 'reason' => 'neighborhood_passed'],
            'second_engine' => ['passed' => true, 'status' => 'passed', 'reasons' => ['second_engine_passed']],
            'cross_campaign' => ['passed' => true, 'reason' => 'cross_campaign_rediscovery_passed'],
        ], $overrides);
    }

    /** @return list<Bar> */
    private function trendBars(): array
    {
        $bars = [];
        $price = 100.0;
        for ($i = 0; $i < 140; $i++) {
            $price *= $i < 60 ? 1.01 : ($i < 95 ? 0.995 : 1.012);
            $bars[] = $this->bar($i, $i === 0 ? $price : $price * 0.995, $price);
        }

        return $bars;
    }

    /** @return list<Bar> */
    private function meanReversionBars(): array
    {
        $prices = [];
        for ($i = 0; $i < 90; $i++) {
            if ($i < 20) {
                $prices[] = 100.0;
            } elseif ($i < 25) {
                $prices[] = 100.0 - ($i - 19) * 2.5;
            } elseif ($i < 38) {
                $prices[] = 87.5 + ($i - 24) * 1.35;
            } else {
                $prices[] = 105.0 + sin($i / 3.0) * 0.4;
            }
        }

        return $this->barsFromPrices($prices);
    }

    /** @return list<Bar> */
    private function momentumBars(): array
    {
        $prices = [];
        for ($i = 0; $i < 90; $i++) {
            if ($i < 20) {
                $prices[] = 100.0;
            } elseif ($i < 45) {
                $prices[] = 100.0 + ($i - 19) * 1.8;
            } elseif ($i < 60) {
                $prices[] = 145.0 - ($i - 44) * 1.7;
            } else {
                $prices[] = 120.0 + sin($i / 4.0) * 0.5;
            }
        }

        return $this->barsFromPrices($prices);
    }

    /**
     * @param  list<float>  $prices
     * @return list<Bar>
     */
    private function barsFromPrices(array $prices): array
    {
        $bars = [];
        $prev = (float) ($prices[0] ?? 100.0);
        foreach ($prices as $i => $price) {
            $open = $i === 0 ? (float) $price : $prev;
            $bars[] = $this->bar((int) $i, $open, (float) $price);
            $prev = (float) $price;
        }

        return $bars;
    }

    private function bar(int $i, float $open, float $close): Bar
    {
        $day = 86_400_000;

        return new Bar(
            $i * $day,
            $open,
            max($open, $close) * 1.004,
            min($open, $close) * 0.996,
            $close,
            1000.0,
            ($i + 1) * $day - 1,
        );
    }

    /** @param array<string,mixed> $payload */
    private function tempReport(array $payload): string
    {
        $path = sys_get_temp_dir().'/atlas-freqtrade-adversarial-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

        return $path;
    }

    /** @param array<string,mixed> $extra */
}
