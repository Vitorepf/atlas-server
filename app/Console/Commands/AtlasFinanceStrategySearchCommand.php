<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Finance\StrategyLoop\Metrics\HonestMetrics;
use App\Services\Ai\Finance\StrategyLoop\MarketDataCache;
use App\Services\Ai\Finance\StrategyLoop\Strategy\TrendBreakoutStrategy;
use App\Services\Ai\Finance\StrategyLoop\TradingHonestyGate;
use Illuminate\Console\Command;

/**
 * THE TRADING SEARCH LOOP (fast engine). Param optimization is what an optimizer does, not a
 * heavyweight LLM agent: this evaluates HUNDREDS of candidate strategies per round IN-PROCESS
 * (no provider, no subprocess), reusing the entire AUDITED honesty harness — the same frozen
 * backtest, the same Deflated-Sharpe / PBO / sealed-holdout gate.
 *
 * Each round is one honest experiment: sample K candidates (random + evolutionary mutation of
 * the best-so-far), backtest each on the scoring region, keep those clearing the sanity gate,
 * then run the honesty gate on the best with N = K (the TRUE trial count — so the DSR deflation
 * is genuinely strict; the more strategies a round tries, the higher the bar). Propose-only,
 * never-merge, no real money. A per-round honest NULL is the expected, correct outcome.
 *
 * Cross-round note: running many rounds is itself a softer multiple-testing the per-round N does
 * not fully correct — the sealed holdout (re-faced every round) is the cross-round backstop, and
 * every proposal records this honestly. A human reviews; nothing trades.
 */
final class AtlasFinanceStrategySearchCommand extends Command
{
    protected $signature = 'atlas:finance:strategy-search
        {--symbol=BTCUSDT}
        {--interval=1d}
        {--candidates=600 : Candidate strategies evaluated per round (= the DSR trial count N)}
        {--rounds=0 : Stop after this many rounds (0 = unbounded)}
        {--max-seconds=0 : Stop after this many seconds (0 = unbounded)}
        {--sleep=2 : Seconds between rounds}
        {--holdout-frac=0.25}
        {--fee-bps=10 : FROZEN per-side fee}
        {--slippage-bps=5 : FROZEN per-side slippage}
        {--min-trades=20}
        {--max-dd=0.6}
        {--kill-switch=}';

    protected $description = 'Fast in-process evolutionary search for a trading strategy under the audited honesty gate (propose-only, no live trading).';

    private float $feeBps = 10.0;

    private float $slippageBps = 5.0;

    private int $minTrades = 20;

    private float $maxDd = 0.6;

    private float $ppy = 365.0;

    public function handle(): int
    {
        $symbol = (string) $this->option('symbol');
        $interval = (string) $this->option('interval');
        $this->feeBps = max(0.0, (float) $this->option('fee-bps'));
        $this->slippageBps = max(0.0, (float) $this->option('slippage-bps'));
        $this->minTrades = max(1, (int) $this->option('min-trades'));
        $this->maxDd = (float) $this->option('max-dd');
        $this->ppy = $this->periodsPerYear($interval);
        $k = max(10, (int) $this->option('candidates'));
        $kill = trim((string) $this->option('kill-switch')) ?: storage_path('atlas/finance/STOP');
        $maxRounds = max(0, (int) $this->option('rounds'));
        $maxSeconds = max(0, (int) $this->option('max-seconds'));
        $sleep = max(0, (int) $this->option('sleep'));
        $ledger = storage_path('atlas/finance/search-ledger.jsonl');
        @mkdir(dirname($ledger), 0o755, true);

        $cache = MarketDataCache::default();
        if (! $cache->has($symbol, $interval)) {
            $this->error("market-data cache missing for {$symbol}-{$interval}");

            return self::FAILURE;
        }
        $allBars = $cache->load($symbol, $interval);
        $n = count($allBars);
        $scoringEnd = (int) floor($n * (1.0 - min(0.9, max(0.0, (float) $this->option('holdout-frac')))));
        $scoringBars = array_slice($allBars, 0, $scoringEnd);
        $holdoutBars = array_slice($allBars, $scoringEnd);

        $this->info("Atlas trading SEARCH loop — {$symbol}-{$interval}, {$k} candidates/round (N), in-process.");
        $this->line('Propose-only · never-merge · no real money · DSR/PBO/holdout gate. Kill-switch: '.$kill);
        $this->newLine();

        $start = time();
        $round = 0;
        $certified = 0;
        $bestPool = []; // elite params to mutate around (evolutionary memory)
        $bestDsr = null;

        while (true) {
            if (is_file($kill)) {
                $this->warn('kill-switch present — stopping.');
                break;
            }
            if ($maxRounds > 0 && $round >= $maxRounds) {
                break;
            }
            if ($maxSeconds > 0 && (time() - $start) >= $maxSeconds) {
                break;
            }
            $round++;

            $res = $this->runRound($scoringBars, $holdoutBars, $k, $bestPool);
            $bestPool = $res['elite'];
            if ($res['certified']) {
                $certified++;
            }
            $dsr = $res['report']['deflated_sharpe'] ?? null;
            if (is_numeric($dsr) && ($bestDsr === null || $dsr > $bestDsr)) {
                $bestDsr = (float) $dsr;
            }
            $this->appendLedger($ledger, $round, $symbol, $interval, $k, $res);
            if ($res['certified']) {
                $this->persistProposal($symbol, $interval, $k, $res);
            }

            $this->line(sprintf(
                '[round %d] passed %d/%d · %s · best ann-Sharpe %s · DSR %s · %s',
                $round,
                $res['n_passed'],
                $k,
                $res['certified'] ? '<info>CERTIFIED</info>' : 'null',
                $res['best_ann_sharpe'] !== null ? number_format($res['best_ann_sharpe'], 3) : 'n/a',
                $dsr !== null ? (string) $dsr : 'n/a',
                $res['certified'] ? 'proposal saved' : implode('; ', array_slice($res['reasons'], 0, 2)),
            ));

            if ($sleep > 0 && ! is_file($kill)) {
                sleep($sleep);
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('Rounds run', (string) $round);
        $this->components->twoColumnDetail('Certified proposals', (string) $certified);
        $this->components->twoColumnDetail('Best deflated Sharpe seen', $bestDsr !== null ? (string) $bestDsr : 'none');
        $this->components->twoColumnDetail('Ledger', $ledger);

        return self::SUCCESS;
    }

    /**
     * One honest experiment: K candidates, sanity-filter, then the honesty gate on the best.
     *
     * @param  list<\App\Services\Ai\Finance\StrategyLoop\Bar>  $scoringBars
     * @param  list<\App\Services\Ai\Finance\StrategyLoop\Bar>  $holdoutBars
     * @param  list<array<string,mixed>>  $elite
     * @return array<string,mixed>
     */
    private function runRound(array $scoringBars, array $holdoutBars, int $k, array $elite): array
    {
        $strategy = new TrendBreakoutStrategy;
        $metrics = new HonestMetrics;
        $passing = [];

        for ($i = 0; $i < $k; $i++) {
            $params = ($elite !== [] && $i % 5 !== 0) ? $this->mutate($elite[$i % count($elite)]) : $this->randomParams();
            $scored = $this->scoreScoring($strategy, $metrics, $scoringBars, $params);
            if ($scored !== null) {
                $passing[] = $scored;
            }
        }

        if ($passing === []) {
            return $this->roundResult(0, $k, null, false, ['no_candidate_passed_sanity_gate'], [], []);
        }

        // best = highest annualized scoring Sharpe; elite = top few for next round's mutation
        usort($passing, static fn (array $a, array $b): int => $b['ann_sharpe'] <=> $a['ann_sharpe']);
        $winner = $passing[0];
        $newElite = array_map(static fn (array $p): array => $p['params'], array_slice($passing, 0, 6));

        $holdout = $this->scoreRegion($strategy, $metrics, $holdoutBars, $winner['params']);

        $verdict = (new TradingHonestyGate)->evaluate([
            'winner_daily_returns' => $winner['daily_returns'],
            'sibling_windows' => array_map(static fn (array $p): array => $p['windows'], $passing),
            'sibling_sharpes' => array_map(static fn (array $p): float => $p['pp_sharpe'], $passing), // per-period
            'scenarios_explored' => $k, // the TRUE trial count this round
            'holdout_sharpe' => $holdout['ann_sharpe'],
            'holdout_trades' => $holdout['n_trades'],
        ]);

        return $this->roundResult(
            count($passing),
            $k,
            $winner,
            (bool) $verdict['certified'],
            $verdict['reasons'],
            $verdict['report'],
            $newElite,
            $holdout,
        );
    }

    /**
     * @param  array<string,mixed>|null  $winner
     * @param  list<string>  $reasons
     * @param  array<string,mixed>  $report
     * @param  list<array<string,mixed>>  $elite
     * @param  array<string,mixed>  $holdout
     * @return array<string,mixed>
     */
    private function roundResult(int $nPassed, int $k, ?array $winner, bool $certified, array $reasons, array $report, array $elite, array $holdout = []): array
    {
        return [
            'n_passed' => $nPassed,
            'candidates' => $k,
            'certified' => $certified,
            'reasons' => $reasons === [] ? ['certified'] : $reasons,
            'report' => $report,
            'best_ann_sharpe' => $winner['ann_sharpe'] ?? null,
            'winner_params' => $winner['params'] ?? null,
            'holdout' => $holdout,
            'elite' => $elite,
        ];
    }

    /**
     * Score a candidate on the SCORING region; return null if it fails the sanity gate.
     *
     * @param  list<\App\Services\Ai\Finance\StrategyLoop\Bar>  $bars
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>|null
     */
    private function scoreScoring(TrendBreakoutStrategy $strategy, HonestMetrics $metrics, array $bars, array $params): ?array
    {
        $m = $this->scoreRegion($strategy, $metrics, $bars, $params);
        if ($m['n_trades'] < $this->minTrades || $m['max_dd'] > $this->maxDd || ! is_finite($m['ann_sharpe'])) {
            return null;
        }

        return [
            'params' => $params,
            'pp_sharpe' => $m['pp_sharpe'],
            'ann_sharpe' => $m['ann_sharpe'],
            'max_dd' => $m['max_dd'],
            'n_trades' => $m['n_trades'],
            'windows' => $m['windows'],
            'daily_returns' => $m['daily_returns'],
        ];
    }

    /**
     * @param  list<\App\Services\Ai\Finance\StrategyLoop\Bar>  $bars
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function scoreRegion(TrendBreakoutStrategy $strategy, HonestMetrics $metrics, array $bars, array $params): array
    {
        $params['fee_bps'] = $this->feeBps;        // FROZEN costs — never from the candidate
        $params['slippage_bps'] = $this->slippageBps;
        $r = $strategy->run($bars, $params);

        return [
            'pp_sharpe' => $metrics->perPeriodSharpe($r->dailyReturns),
            'ann_sharpe' => $metrics->sharpe($r->dailyReturns, $this->ppy),
            'max_dd' => $metrics->maxDrawdown($r->equityCurve),
            'n_trades' => $r->nTrades,
            'windows' => $this->windowReturns($r->dailyReturns, 16),
            'daily_returns' => $r->dailyReturns,
        ];
    }

    /** @return array<string,float|int> a random point in the strategy search space */
    private function randomParams(): array
    {
        return [
            'regime_period' => $this->chance(0.30) ? 0 : $this->randInt(20, 300),
            'entry_lookback' => $this->randInt(5, 100),
            'exit_lookback' => $this->chance(0.30) ? 0 : $this->randInt(5, 100),
            'atr_period' => $this->randInt(5, 50),
            'atr_mult' => $this->randFloat(1.0, 8.0),
            'risk_pct' => $this->randFloat(0.05, 0.5),
            'min_hold_bars' => $this->randInt(0, 20),
        ];
    }

    /**
     * @param  array<string,mixed>  $base
     * @return array<string,float|int>
     */
    private function mutate(array $base): array
    {
        return [
            'regime_period' => $this->chance(0.15) ? ($this->chance(0.5) ? 0 : $this->randInt(20, 300)) : $this->jitterInt((int) ($base['regime_period'] ?? 100), 0, 300, 30),
            'entry_lookback' => $this->jitterInt((int) ($base['entry_lookback'] ?? 20), 5, 100, 10),
            'exit_lookback' => $this->chance(0.15) ? 0 : $this->jitterInt((int) ($base['exit_lookback'] ?? 10), 5, 100, 10),
            'atr_period' => $this->jitterInt((int) ($base['atr_period'] ?? 14), 5, 50, 6),
            'atr_mult' => $this->jitterFloat((float) ($base['atr_mult'] ?? 3.0), 1.0, 8.0, 1.0),
            'risk_pct' => $this->jitterFloat((float) ($base['risk_pct'] ?? 0.2), 0.05, 0.5, 0.08),
            'min_hold_bars' => $this->jitterInt((int) ($base['min_hold_bars'] ?? 3), 0, 20, 4),
        ];
    }

    private function chance(float $p): bool
    {
        return mt_rand(0, 1_000_000) / 1_000_000.0 < $p;
    }

    private function randInt(int $lo, int $hi): int
    {
        return mt_rand($lo, $hi);
    }

    private function randFloat(float $lo, float $hi): float
    {
        return $lo + ($hi - $lo) * (mt_rand(0, 1_000_000) / 1_000_000.0);
    }

    private function jitterInt(int $v, int $lo, int $hi, int $span): int
    {
        return max($lo, min($hi, $v + mt_rand(-$span, $span)));
    }

    private function jitterFloat(float $v, float $lo, float $hi, float $span): float
    {
        return max($lo, min($hi, $v + $this->randFloat(-$span, $span)));
    }

    /**
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
            $out[] = $slice === [] ? 0.0 : array_sum($slice) / count($slice);
        }

        return $out;
    }

    /** @param array<string,mixed> $res */
    private function appendLedger(string $ledger, int $round, string $symbol, string $interval, int $k, array $res): void
    {
        $line = json_encode([
            'round' => $round,
            'at' => date('c'),
            'symbol' => $symbol,
            'interval' => $interval,
            'candidates' => $k,
            'n_passed' => $res['n_passed'],
            'certified' => $res['certified'],
            'best_ann_sharpe' => $res['best_ann_sharpe'],
            'deflated_sharpe' => $res['report']['deflated_sharpe'] ?? null,
            'pbo' => $res['report']['pbo'] ?? null,
            'holdout_sharpe' => $res['holdout']['ann_sharpe'] ?? null,
            'reasons' => $res['reasons'],
            'merged_to_main' => false,
        ], JSON_UNESCAPED_SLASHES);
        file_put_contents($ledger, $line."\n", FILE_APPEND | LOCK_EX);
    }

    /** @param array<string,mixed> $res */
    private function persistProposal(string $symbol, string $interval, int $k, array $res): void
    {
        $dir = storage_path('atlas/finance/proposals');
        @mkdir($dir, 0o755, true);
        $payload = [
            'created_at' => date('c'),
            'flow' => 'finance.strategy_evolution',
            'engine' => 'in_process_search',
            'classification' => 'sensitive',
            'symbol' => $symbol,
            'interval' => $interval,
            'scenarios_explored' => $k,
            'certified' => true,
            'honesty_report' => $res['report'],
            'winner_strategy' => $res['winner_params'],
            'holdout' => $res['holdout'],
            'status' => 'certified_for_review',
            'merged_to_main' => false,
            'live_trading' => 'forbidden',
            'cross_round_caveat' => 'Many rounds run; the sealed holdout is the cross-round backstop. Human review required; nothing trades.',
        ];
        file_put_contents(
            $dir.'/'.date('Ymd-His').'-search-'.substr(hash('sha256', json_encode($payload) ?: ''), 0, 8).'.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    private function periodsPerYear(string $interval): float
    {
        return match ($interval) {
            '1h' => 24 * 365,
            '4h' => 6 * 365,
            '1w' => 52,
            default => 365,
        };
    }
}
