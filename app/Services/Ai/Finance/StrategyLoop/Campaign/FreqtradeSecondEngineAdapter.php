<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

/**
 * Adapter for an externally generated freqtrade holdout report.
 *
 * The trading loop does not shell out to a broker or emit orders. This adapter
 * accepts only a pinned JSON report with scenario/data/cost metadata matching
 * the Atlas campaign, then returns metrics for the divergence gate.
 */
final class FreqtradeSecondEngineAdapter
{
    /** @return array<string,mixed> */
    public function evaluate(string $reportPath, array $expected = []): array
    {
        $reportPath = trim($reportPath);
        if ($reportPath === '') {
            return $this->unavailable('freqtrade_report_not_configured');
        }
        if (! is_file($reportPath)) {
            return $this->unavailable('freqtrade_report_missing');
        }

        $decoded = json_decode((string) file_get_contents($reportPath), true);
        if (! is_array($decoded)) {
            return $this->unavailable('freqtrade_report_invalid_json');
        }

        $validation = $this->validatePinnedReport($decoded, $expected);
        if ($validation !== null) {
            return $this->unavailable($validation);
        }

        $metrics = $decoded['metrics'] ?? $decoded;
        if (! is_array($metrics)) {
            return $this->unavailable('freqtrade_metrics_missing');
        }

        $tradeCount = $metrics['trade_count'] ?? $metrics['n_trades'] ?? $metrics['trades'] ?? null;
        $sharpe = $metrics['ann_sharpe'] ?? $metrics['sharpe'] ?? null;
        $maxDd = $metrics['max_dd'] ?? $metrics['max_drawdown'] ?? null;
        $totalReturn = $metrics['total_return'] ?? $metrics['net_return'] ?? $metrics['return'] ?? null;
        $exposure = $metrics['exposure'] ?? $metrics['exposure_ratio'] ?? null;
        $equityCurve = $metrics['equity_curve_sample'] ?? $metrics['equity_curve'] ?? null;
        if (! is_numeric($tradeCount) || ! is_numeric($sharpe) || ! is_numeric($maxDd)) {
            return $this->unavailable('freqtrade_metrics_incomplete');
        }

        return [
            'status' => 'ready',
            'engine' => 'freqtrade',
            'report_path' => $reportPath,
            'report_sha256' => hash_file('sha256', $reportPath) ?: null,
            'trade_count' => (int) $tradeCount,
            'ann_sharpe' => (float) $sharpe,
            'max_dd' => (float) $maxDd,
            'total_return' => is_numeric($totalReturn) ? (float) $totalReturn : null,
            'exposure' => is_numeric($exposure) ? (float) $exposure : null,
            'equity_curve_sample' => is_array($equityCurve) ? array_values(array_map('floatval', $equityCurve)) : [],
            'holdout_passed' => $metrics['holdout_passed'] ?? null,
            'scenario' => [
                'symbol' => $decoded['symbol'] ?? $decoded['scenario']['symbol'] ?? null,
                'interval' => $decoded['interval'] ?? $decoded['scenario']['interval'] ?? null,
                'strategy_family' => $decoded['strategy_family'] ?? $decoded['scenario']['strategy_family'] ?? null,
                'data_sha' => $decoded['data_sha'] ?? $decoded['data_manifest']['sha256'] ?? null,
                'holdout_id' => $decoded['holdout_id'] ?? $decoded['holdout']['holdout_id'] ?? null,
                'cost_profile_hash' => $decoded['cost_profile_hash'] ?? $decoded['cost_profile']['cost_profile_hash'] ?? null,
            ],
            'source' => 'pinned_external_report',
            'propose_only' => true,
            'live_trading' => 'forbidden',
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @param  array<string,mixed>  $expected
     */
    private function validatePinnedReport(array $report, array $expected): ?string
    {
        $engine = strtolower((string) ($report['engine'] ?? $report['source_engine'] ?? ''));
        if ($engine !== 'freqtrade') {
            return 'freqtrade_report_engine_mismatch';
        }
        if ((bool) ($report['propose_only'] ?? false) !== true || (string) ($report['live_trading'] ?? '') !== 'forbidden') {
            return 'freqtrade_report_not_propose_only';
        }
        $mode = strtolower((string) ($report['trading_mode'] ?? $report['market_type'] ?? 'spot'));
        if ($mode !== 'spot') {
            return 'freqtrade_report_not_spot';
        }

        $scenario = is_array($report['scenario'] ?? null) ? $report['scenario'] : [];
        $dataManifest = is_array($report['data_manifest'] ?? null) ? $report['data_manifest'] : [];
        $holdout = is_array($report['holdout'] ?? null) ? $report['holdout'] : [];
        $costProfile = is_array($report['cost_profile'] ?? null) ? $report['cost_profile'] : [];
        $actual = [
            'symbol' => strtoupper((string) ($report['symbol'] ?? $scenario['symbol'] ?? '')),
            'interval' => (string) ($report['interval'] ?? $scenario['interval'] ?? ''),
            'strategy_family' => (string) ($report['strategy_family'] ?? $scenario['strategy_family'] ?? ''),
            'data_sha' => (string) ($report['data_sha'] ?? $dataManifest['sha256'] ?? ''),
            'holdout_id' => (string) ($report['holdout_id'] ?? $holdout['holdout_id'] ?? ''),
            'cost_profile_hash' => (string) ($report['cost_profile_hash'] ?? $costProfile['cost_profile_hash'] ?? ''),
        ];
        foreach ($actual as $key => $value) {
            if ($value === '') {
                return 'freqtrade_report_'.$key.'_missing';
            }
            if (array_key_exists($key, $expected)) {
                $expectedValue = $key === 'symbol'
                    ? strtoupper((string) $expected[$key])
                    : (string) $expected[$key];
                if ($expectedValue !== '' && $value !== $expectedValue) {
                    return 'freqtrade_report_'.$key.'_mismatch';
                }
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function unavailable(string $reason): array
    {
        return [
            'status' => 'unavailable',
            'engine' => 'freqtrade',
            'reason' => $reason,
            'propose_only' => true,
            'live_trading' => 'forbidden',
        ];
    }
}
