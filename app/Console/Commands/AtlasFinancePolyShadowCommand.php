<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Finance\PolymarketShadow\BinanceSpotFeed;
use App\Services\Ai\Finance\PolymarketShadow\FairValueEngine;
use App\Services\Ai\Finance\PolymarketShadow\PolymarketShadowFeed;
use App\Services\Ai\Finance\PolymarketShadow\ShadowCalibrationReport;
use App\Services\Ai\Finance\PolymarketShadow\ShadowDecisionEngine;
use App\Services\Ai\Finance\PolymarketShadow\ShadowRunState;
use App\Services\Ai\Finance\PolymarketShadow\ShadowSettlement;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Polymarket 5-minute BTC Up/Down — SHADOW ONLY runtime.
 *
 * Places no orders, holds no keys, moves no money: it records what the
 * deterministic strategy WOULD have done against live public data, so the
 * calibration scorecard can certify or kill the edge before a single dollar
 * is risked. Live execution is a separate, operator-gated obra (finance canon
 * market_execution_forbidden stays untouched).
 */
final class AtlasFinancePolyShadowCommand extends Command
{
    protected $signature = 'atlas:finance:poly-shadow
        {action=run : run|report|reconcile}
        {--minutes=15 : Run duration in minutes}
        {--poll-ms=1500 : Poll interval in milliseconds}
        {--json : Emit JSON instead of human output}';

    protected $description = 'Shadow-trade the Polymarket 5-minute BTC Up/Down market (no orders, calibration evidence only).';

    public function handle(): int
    {
        return match ((string) $this->argument('action')) {
            'run' => $this->runShadow(),
            'report' => $this->report(),
            'reconcile' => $this->reconcile(),
            default => $this->invalidAction(),
        };
    }

    private function runShadow(): int
    {
        $config = (array) config('atlas.finance_poly_shadow', []);
        $minutes = max(1, (int) $this->option('minutes'));
        $pollMs = max(500, (int) $this->option('poll-ms'));
        $sessionId = (string) Str::ulid();
        $deadline = microtime(true) + $minutes * 60;

        $binance = new BinanceSpotFeed((string) ($config['symbol'] ?? 'BTCUSDT'));
        $poly = new PolymarketShadowFeed;
        $engine = new FairValueEngine(
            lambda: (float) ($config['ewma_lambda'] ?? 0.97),
            jumpZ: (float) ($config['jump_z'] ?? 5.0),
            jumpHoldSeconds: (float) ($config['jump_hold_sec'] ?? 60.0),
            jumpSigmaMultiplier: (float) ($config['jump_sigma_mult'] ?? 2.0),
        );
        $decider = new ShadowDecisionEngine(
            minEdge: (float) ($config['min_edge'] ?? 0.04),
            entryMinSec: (int) ($config['entry_min_sec'] ?? 15),
            entryMaxSec: (int) ($config['entry_max_sec'] ?? 180),
            riskPct: (float) ($config['risk_pct'] ?? 0.05),
            dailyHaltPct: (float) ($config['daily_halt_pct'] ?? 0.10),
            takerFeeRate: (float) ($config['taker_fee_rate'] ?? 0.0),
        );
        $state = new ShadowRunState((float) ($config['virtual_bankroll'] ?? 200.0));
        $settlement = new ShadowSettlement($state);

        $this->info(sprintf(
            '[poly-shadow] session=%s minutes=%d poll=%dms bankroll=%.2f (VIRTUAL — shadow only, no orders)',
            $sessionId, $minutes, $pollMs, $state->snapshot()['bankroll'],
        ));

        $seed = $binance->recentSecondCloses(300);
        if ($seed !== []) {
            $engine->seed($seed);
            $this->line(sprintf('[poly-shadow] vol seeded from %d 1s closes', count($seed)));
        } else {
            $this->warn('[poly-shadow] could not seed vol from klines; will warm up from live ticks');
        }

        $market = null;
        $previousSignal = null;
        $quoteSampledFor = null;
        $tradesOpened = 0;
        $lastStatusAt = 0.0;

        while (microtime(true) < $deadline) {
            $tickStart = microtime(true);

            try {
                $mid = $binance->mid();
                if ($mid === null) {
                    usleep($pollMs * 1000);

                    continue;
                }

                $nowSec = $mid['ts_ms'] / 1000.0;
                $nowUnix = (int) floor($nowSec);
                $windowStart = $poly->windowStart($nowUnix);
                $elapsed = $nowSec - $windowStart;

                $engine->observe($mid['price'], $nowSec);
                $this->recordBoundaries($windowStart, $mid['price'], $mid['ts_ms']);

                if ($market === null || $market['window_start'] !== $windowStart) {
                    $market = $poly->marketForWindow($windowStart);
                    if ($market !== null) {
                        DB::table('atlas_poly_shadow_windows')
                            ->where('window_start', $windowStart)
                            ->update(['market_slug' => $market['slug'], 'updated_at' => now()]);
                    }
                }

                $windowRow = DB::table('atlas_poly_shadow_windows')->where('window_start', $windowStart)->first();
                $sStart = $windowRow?->s_start !== null ? (float) $windowRow->s_start : null;

                if ($market !== null && $sStart !== null && $engine->isSeeded()) {
                    $fair = $engine->fairValueUp($mid['price'], $sStart, max(1.0, 300.0 - $elapsed), $nowSec);

                    if ($fair !== null) {
                        $bookUp = $poly->book($market['token_up']);
                        $bookDown = $poly->book($market['token_down']);

                        if ($quoteSampledFor !== $windowStart && $elapsed >= 60.0 && $elapsed <= 90.0) {
                            DB::table('atlas_poly_shadow_quotes')->insert([
                                'window_start' => $windowStart,
                                'captured_elapsed_sec' => round($elapsed, 2),
                                'fv_up' => round($fair['fv_up'], 6),
                                'ask_up' => $bookUp['best_ask'] ?? null,
                                'bid_up' => $bookUp['best_bid'] ?? null,
                                'ask_down' => $bookDown['best_ask'] ?? null,
                                'bid_down' => $bookDown['best_bid'] ?? null,
                                'sigma_per_second' => $fair['sigma_per_second'],
                                'regime' => $fair['regime'],
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            $quoteSampledFor = $windowStart;
                        }

                        $snapshot = $state->snapshot();
                        $decision = $decider->evaluate([
                            'window_start' => $windowStart,
                            'now' => $nowSec,
                            'fv_up' => $fair['fv_up'],
                            'book_up' => $bookUp,
                            'book_down' => $bookDown,
                            'bankroll' => $snapshot['bankroll'],
                            'day_start_bankroll' => $snapshot['day_start_bankroll'],
                            'day_pnl' => $snapshot['day_pnl'],
                            'has_position_for_window' => DB::table('atlas_poly_shadow_trades')
                                ->where('window_start', $windowStart)->exists(),
                            'previous_signal' => $previousSignal,
                        ]);

                        if ($decision['action'] === 'signal') {
                            $previousSignal = [
                                'window_start' => $windowStart,
                                'side' => $decision['side'],
                                'at' => $nowSec,
                            ];
                        } elseif ($decision['action'] === 'trade') {
                            DB::table('atlas_poly_shadow_trades')->insert([
                                'window_start' => $windowStart,
                                'market_slug' => $market['slug'],
                                'side' => $decision['side'],
                                'leg' => $decision['leg'],
                                'entered_elapsed_sec' => round($elapsed, 2),
                                'fv' => round($decision['fv'], 6),
                                'ask' => $decision['ask'],
                                'fee_per_share' => round($decision['fee_per_share'], 6),
                                'edge' => round($decision['edge'], 6),
                                'sigma_per_second' => $fair['sigma_per_second'],
                                'regime' => $fair['regime'],
                                'stake' => $decision['stake'],
                                'shares' => $decision['shares'],
                                'binance_price_entry' => $mid['price'],
                                'status' => 'open',
                                'session_id' => $sessionId,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            $tradesOpened++;
                            $previousSignal = null;
                            $this->info(sprintf(
                                '[poly-shadow] WOULD BUY %s @%.3f fv=%.3f edge=%.3f stake=$%.2f leg=%s (%ds into window)',
                                strtoupper((string) $decision['side']), $decision['ask'], $decision['fv'],
                                $decision['edge'], $decision['stake'], $decision['leg'], (int) $elapsed,
                            ));
                        } elseif ($decision['action'] === 'halted' && $previousSignal !== null) {
                            $previousSignal = null;
                        }

                        if ($tickStart - $lastStatusAt >= 30.0) {
                            $lastStatusAt = $tickStart;
                            $this->line(sprintf(
                                '[poly-shadow] t+%03ds btc=%.2f fv_up=%.3f ask_up=%.3f ask_down=%.3f regime=%s bankroll=%.2f trades=%d',
                                (int) $elapsed, $mid['price'], $fair['fv_up'],
                                $bookUp['best_ask'] ?? -1, $bookDown['best_ask'] ?? -1,
                                $fair['regime'], $snapshot['bankroll'], $tradesOpened,
                            ));
                        }
                    }
                }

                $settled = $settlement->settleDueWindows($nowUnix);
                if ($settled['settled_trades'] > 0 || $settled['voided_trades'] > 0) {
                    $this->info(sprintf(
                        '[poly-shadow] settled %d trade(s), voided %d, pnl=%+.4f',
                        $settled['settled_trades'], $settled['voided_trades'], $settled['pnl'],
                    ));
                }
            } catch (\Throwable $e) {
                $this->warn('[poly-shadow] tick error (continuing): '.$e->getMessage());
            }

            $sleepUs = (int) max(0, $pollMs * 1000 - (microtime(true) - $tickStart) * 1_000_000);
            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
        }

        $settlement->settleDueWindows((int) (microtime(true)) + 1);
        $report = (new ShadowCalibrationReport)->build();

        app(AtlasEvidenceLedger::class)->record(LedgerEventType::ToolEvidenceRecorded, [
            'tool' => 'atlas:finance:poly-shadow',
            'mode' => 'shadow_only_no_orders',
            'session_id' => $sessionId,
            'minutes' => $minutes,
            'trades_opened' => $tradesOpened,
            'report' => $report,
        ], [
            'scope_type' => 'finance_poly_shadow',
            'scope_id' => $sessionId,
        ]);

        $this->line($this->option('json') ? json_encode($report, JSON_PRETTY_PRINT) : '');
        $this->info(sprintf('[poly-shadow] session %s complete: %d shadow trade(s) opened.', $sessionId, $tradesOpened));

        return self::SUCCESS;
    }

    /**
     * At each 5-minute boundary the same Binance tick closes the old window and
     * opens the new one, so start/end prices are consistent by construction.
     *
     * A start price is only valid if captured within a few seconds of the
     * boundary: when the runner wakes up mid-window the strike is unknowable,
     * so the row is created with a NULL s_start and settlement voids it instead
     * of trading (or scoring) against a phantom strike.
     */
    private function recordBoundaries(int $windowStart, float $price, int $tsMs): void
    {
        $elapsed = $tsMs / 1000.0 - $windowStart;
        $nearBoundary = $elapsed >= 0.0 && $elapsed <= 5.0;

        DB::table('atlas_poly_shadow_windows')->insertOrIgnore([
            'window_start' => $windowStart,
            's_start' => $nearBoundary ? $price : null,
            's_start_ts_ms' => $nearBoundary ? $tsMs : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('atlas_poly_shadow_windows')
            ->where('window_start', $windowStart - PolymarketShadowFeed::WINDOW_SECONDS)
            ->whereNull('s_end')
            ->update(['s_end' => $price, 's_end_ts_ms' => $tsMs, 'updated_at' => now()]);
    }

    private function report(): int
    {
        $report = (new ShadowCalibrationReport)->build();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $cal = $report['calibration'];
        $trades = $report['trades'];
        $basis = $report['basis'];

        $this->info('=== Polymarket 5m Shadow — Calibration Scorecard (no win rate, by design) ===');
        $this->line(sprintf('Quotes scored: %d | Brier: %s  (0.25 = coin-flip baseline; lower is better)',
            $cal['quotes_scored'], $cal['brier'] ?? 'n/a'));
        foreach ($cal['reliability'] as $bucket) {
            $this->line(sprintf('  fv~%s  n=%-4d mean_fv=%.3f realized_up=%.3f',
                $bucket['bucket'], $bucket['n'], $bucket['mean_fv'], $bucket['realized_up_rate']));
        }
        $this->line(sprintf('Trades: settled=%d open=%d void=%d', $trades['settled'], $trades['open'], $trades['void']));
        $this->line(sprintf('Predicted EV: $%.4f | Realized P&L: $%.4f | EV capture: %s | Fees: $%.4f',
            $trades['predicted_ev'], $trades['realized_pnl'],
            $trades['ev_capture_ratio'] ?? 'n/a', $trades['fees_paid']));
        foreach ($trades['by_leg'] as $leg => $stats) {
            $this->line(sprintf('  leg=%-14s n=%-3d predicted=$%.4f realized=$%.4f',
                $leg, $stats['n'], $stats['predicted_ev'], $stats['realized_pnl']));
        }
        $this->line(sprintf('Basis (Binance vs Chainlink official): %d reconciled, %d mismatches (rate=%s)',
            $basis['windows_reconciled'], $basis['binance_chainlink_mismatches'], $basis['mismatch_rate'] ?? 'n/a'));
        $this->line(sprintf('Virtual bankroll: $%.2f (day P&L: $%+.2f)',
            $report['bankroll']['bankroll'], $report['bankroll']['day_pnl']));

        return self::SUCCESS;
    }

    private function reconcile(): int
    {
        $result = (new ShadowSettlement)->reconcileMarketOutcomes(new PolymarketShadowFeed);
        $this->info(sprintf(
            '[poly-shadow] reconciled %d window(s): %d matched, %d MISMATCHED (basis risk)',
            $result['checked'], $result['matched'], $result['mismatched'],
        ));

        return self::SUCCESS;
    }

    private function invalidAction(): int
    {
        $this->error('Unknown action. Use: run | report | reconcile');

        return self::FAILURE;
    }
}
