<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

use App\Console\Commands\AtlasFinanceStrategySearchCommand;

/**
 * Dry adversarial audit for the strategy campaign platform.
 *
 * These checks intentionally try bad states: wrong second-engine metadata,
 * exhausted holdout, unavailable second engine, terminal campaign reopen shape,
 * and family/signature confusion. It mutates only temporary files.
 */
final class StrategyLoopAdversarialAudit
{
    /** @return array<string,mixed> */
    public function audit(): array
    {
        $checks = [
            $this->unsupportedFamilyNotInSearchAllowlist(),
            $this->terminalCampaignIsRecognizedAsTerminal(),
            $this->exhaustedHoldoutBlocksQuarantine(),
            $this->missingSecondEngineBlocksQuarantine(),
            $this->divergentSecondEngineFailsGate(),
            $this->freqtradeScenarioMismatchFailsClosed(),
            $this->freqtradeLivePathFailsClosed(),
            $this->noExecutionSurfaceScannerCatchesBrokerPath(),
            $this->holdoutRegistryDoesNotDoubleCountLocalRound(),
            $this->candidateSignaturesAreFamilyScoped(),
            $this->roadmapIsScenarioScoped(),
        ];
        $passed = array_reduce($checks, static fn (bool $ok, array $check): bool => $ok && (bool) $check['passed'], true);

        return [
            'schema_version' => 'atlas.finance.strategy_loop_adversarial_audit.v1',
            'generated_at' => gmdate('c'),
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

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function passingQuarantineInput(array $overrides = []): array
    {
        return array_replace_recursive([
            'round_verdict' => ['certified' => true, 'reasons' => ['certified'], 'report' => ['n_trials' => 600]],
            'campaign_verdict' => ['certified' => true, 'reasons' => ['certified'], 'report' => ['n_trials' => 600]],
            'holdout_status' => StrategyCampaignStore::HOLDOUT_FRESH,
            'fresh_holdout' => ['ann_sharpe' => 0.7, 'n_trades' => 15],
            'cost_stress' => ['passed' => true, 'reason' => 'cost_stress_passed'],
            'neighborhood' => ['passed' => true, 'reason' => 'neighborhood_passed'],
            'second_engine' => ['passed' => true, 'status' => 'passed', 'reasons' => ['second_engine_passed']],
            'cross_campaign' => ['passed' => true, 'reason' => 'cross_campaign_rediscovery_passed'],
        ], $overrides);
    }

    /** @param array<string,mixed> $payload */
    private function tempReport(array $payload): string
    {
        $path = sys_get_temp_dir().'/atlas-freqtrade-adversarial-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

        return $path;
    }

    private function check(string $name, bool $passed, string $detail): array
    {
        return [
            'name' => $name,
            'passed' => $passed,
            'detail' => $detail,
        ];
    }
}
