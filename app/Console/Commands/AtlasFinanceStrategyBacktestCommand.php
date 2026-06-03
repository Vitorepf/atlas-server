<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Finance\StrategyLoop\Metrics\HonestMetrics;
use App\Services\Ai\Finance\StrategyLoop\MarketDataCache;
use App\Services\Ai\Finance\StrategyLoop\Strategy\TrendBreakoutStrategy;
use Illuminate\Console\Command;

/**
 * THE BACKTEST ACCEPTANCE COMMAND — the frozen scorer the evolution loop runs against a
 * candidate strategy.json. The loop edits strategy.json; this command (and the data) are
 * frozen, so the loop can tune WHAT it trades, never HOW it is judged.
 *
 * Contract with the frozen judge:
 *   - reads the candidate params (strategy.json, relative to CWD),
 *   - runs the strategy over a REGION of real history (after costs, no look-ahead),
 *   - prints `ATLAS_METRIC=<annualized OOS Sharpe>` for the engine to MAXIMIZE,
 *   - prints `ATLAS_TRADING_REPORT={...}` (compact per-window returns) for the sibling
 *     PBO matrix,
 *   - EXITS 0 only if the sanity GATE passes (enough trades, enough history, not blown
 *     up); otherwise exit 1 (RED).
 *
 * Regions: `scoring` (what the loop optimizes) and a SEALED `holdout` (the most recent
 * slice the loop's metric never reflects — the honesty gate runs the winner there once).
 * An inert baseline (risk_pct=0 ⇒ 0 trades) fails the gate ⇒ reverting any candidate to
 * baseline goes RED, satisfying the loop's diff-earned anti-fake contract.
 */
final class AtlasFinanceStrategyBacktestCommand extends Command
{
    protected $signature = 'atlas:finance:strategy-backtest
        {--strategy=strategy.json : Candidate params path (relative to CWD unless absolute)}
        {--symbol=BTCUSDT : Market symbol}
        {--interval=1d : Bar interval}
        {--region=scoring : scoring|holdout|full}
        {--data-dir= : Override the frozen market-data directory}
        {--holdout-frac=0.25 : Fraction of history sealed as holdout}
        {--min-trades=20 : Minimum realized trades for the gate}
        {--max-dd=0.6 : Maximum tolerated drawdown for the gate}
        {--min-years=3 : Minimum years the scoring region must span}
        {--fee-bps=10 : FROZEN per-side fee in bps (overrides strategy.json — costs are not editable)}
        {--slippage-bps=5 : FROZEN per-side slippage in bps (overrides strategy.json)}
        {--windows=16 : Number of per-window return buckets in the report}
        {--json : Emit the full JSON report (incl. full daily returns) for the honesty gate}';

    protected $description = 'Backtest a candidate strategy over real history and emit the frozen ATLAS_METRIC (propose-only; no live trading).';

    public function handle(): int
    {
        $strategyPath = (string) $this->option('strategy');
        if (! str_starts_with($strategyPath, '/')) {
            $strategyPath = rtrim((string) getcwd(), '/').'/'.$strategyPath;
        }
        if (! is_file($strategyPath)) {
            $this->emitMetric(0.0);
            $this->error('strategy file not found: '.$strategyPath);

            return self::FAILURE;
        }
        $params = json_decode((string) file_get_contents($strategyPath), true);
        if (! is_array($params)) {
            $this->emitMetric(0.0);
            $this->error('strategy.json is not a valid JSON object');

            return self::FAILURE;
        }

        // FREEZE costs: the candidate may NOT reduce fees/slippage to fake an edge. These
        // come from the frozen acceptance command, never from the editable strategy.json.
        $params['fee_bps'] = max(0.0, (float) $this->option('fee-bps'));
        $params['slippage_bps'] = max(0.0, (float) $this->option('slippage-bps'));

        $symbol = (string) $this->option('symbol');
        $interval = (string) $this->option('interval');
        $dataDir = trim((string) $this->option('data-dir'));
        $cache = $dataDir !== '' ? new MarketDataCache($dataDir) : MarketDataCache::default();
        if (! $cache->has($symbol, $interval)) {
            $this->emitMetric(0.0);
            $this->error("market-data cache missing for {$symbol}-{$interval}");

            return self::FAILURE;
        }

        $allBars = $cache->load($symbol, $interval);
        $n = count($allBars);
        $holdoutFrac = min(0.9, max(0.0, (float) $this->option('holdout-frac')));
        $scoringEnd = (int) floor($n * (1.0 - $holdoutFrac));

        $region = (string) $this->option('region');
        $bars = match ($region) {
            'holdout' => array_slice($allBars, $scoringEnd),
            'full' => $allBars,
            default => array_slice($allBars, 0, $scoringEnd),
        };
        if (count($bars) < 30) {
            $this->emitMetric(0.0);
            $this->error("region '{$region}' has too few bars: ".count($bars));

            return self::FAILURE;
        }

        $metrics = new HonestMetrics;
        $result = (new TrendBreakoutStrategy)->run($bars, $params);
        $ppy = $this->periodsPerYear($interval);
        $sharpe = $metrics->sharpe($result->dailyReturns, $ppy);
        $maxDd = $metrics->maxDrawdown($result->equityCurve);
        $years = ($bars[count($bars) - 1]->closeTime - $bars[0]->openTime) / (365.25 * 86_400_000.0);
        $sharpe = is_finite($sharpe) ? $sharpe : 0.0;

        // SANITY GATE — not a quality bar (the DSR/PBO honesty gate does quality). Just:
        // is this a real, tradeable, non-degenerate strategy over enough history?
        $reasons = [];
        if ($result->nTrades < (int) $this->option('min-trades')) {
            $reasons[] = 'too_few_trades('.$result->nTrades.')';
        }
        if ($maxDd > (float) $this->option('max-dd')) {
            $reasons[] = 'drawdown_exceeded('.round($maxDd, 3).')';
        }
        if ($region !== 'holdout' && $years < (float) $this->option('min-years')) {
            $reasons[] = 'history_too_short('.round($years, 2).'y)';
        }
        if (! is_finite($sharpe)) {
            $reasons[] = 'sharpe_not_finite';
        }
        $passed = $reasons === [];

        $report = [
            'region' => $region,
            'sharpe' => round($sharpe, 6),
            'n_trades' => $result->nTrades,
            'max_dd' => round($maxDd, 6),
            'years' => round($years, 3),
            'windows' => $this->windowReturns($result->dailyReturns, max(2, (int) $this->option('windows'))),
        ];

        $this->emitMetric($sharpe);
        if ((bool) $this->option('json')) {
            $report['daily_returns'] = array_map(static fn (float $r): float => round($r, 8), $result->dailyReturns);
            // per-TRADE returns: the true count of independent bets, for an honest DSR sample size.
            $report['trade_returns'] = array_map(static fn (array $t): float => round((float) ($t['return'] ?? 0.0), 8), $result->trades);
            $report['passed'] = $passed;
            $report['gate_reasons'] = $reasons;
            $this->line('ATLAS_TRADING_REPORT='.json_encode($report, JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('ATLAS_TRADING_REPORT='.json_encode($report, JSON_UNESCAPED_SLASHES));
            if (! $passed) {
                $this->line('GATE_FAILED='.implode(',', $reasons));
            }
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    private function emitMetric(float $sharpe): void
    {
        $this->line('ATLAS_METRIC='.(is_finite($sharpe) ? number_format($sharpe, 6, '.', '') : '0.000000'));
    }

    /**
     * Split per-bar returns into K contiguous buckets; each bucket's MEAN return is one
     * column of the cross-sibling PBO matrix. Compact enough to survive the judge's
     * captured-stdout excerpt.
     *
     * @param  list<float>  $returns
     * @return list<float>
     */
    private function windowReturns(array $returns, int $k): array
    {
        $n = count($returns);
        if ($n === 0) {
            return array_fill(0, $k, 0.0);
        }
        $out = [];
        for ($w = 0; $w < $k; $w++) {
            $lo = (int) floor($n * $w / $k);
            $hi = (int) floor($n * ($w + 1) / $k);
            $slice = array_slice($returns, $lo, max(0, $hi - $lo));
            $out[] = $slice === [] ? 0.0 : round(array_sum($slice) / count($slice), 8);
        }

        return $out;
    }

    private function periodsPerYear(string $interval): float
    {
        return match ($interval) {
            '1h' => 24 * 365,
            '4h' => 6 * 365,
            '1w' => 52,
            default => 365, // 1d
        };
    }
}
