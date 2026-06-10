<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Finance\PolymarketShadow\PolymarketArbScanner;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Polymarket sum-of-legs inconsistency scanner — SHADOW ONLY.
 *
 * Coverage is the edge: sweep the multi-outcome long tail continuously and
 * record every CLOB-verified deviation from the no-arbitrage band, plus the
 * per-scan "best sums seen" so the report can say how CLOSE the tail runs to
 * arbitrage even on days with zero signals. Places no orders, holds no keys.
 */
final class AtlasFinancePolyArbCommand extends Command
{
    protected $signature = 'atlas:finance:poly-arb
        {action=scan : scan|run|report}
        {--pages=4 : Gamma pages per pass (volume-ordered)}
        {--per-page=50 : Events per page}
        {--minutes=60 : Loop duration for run}
        {--interval=120 : Seconds between passes in run mode}
        {--min-profit=0.005 : Minimum locked profit per $1 set}
        {--json : Emit JSON}';

    protected $description = 'Scan Polymarket multi-outcome events for sum-of-legs arbitrage inconsistency (shadow only, no orders).';

    public function handle(): int
    {
        return match ((string) $this->argument('action')) {
            'scan' => $this->scan(once: true),
            'run' => $this->scan(once: false),
            'report' => $this->report(),
            default => $this->fail2('Unknown action. Use: scan | run | report'),
        };
    }

    private function scan(bool $once): int
    {
        $config = (array) config('atlas.finance_poly_arb', []);
        $sessionId = (string) Str::ulid();
        $pages = max(1, (int) $this->option('pages'));
        $perPage = max(10, min(100, (int) $this->option('per-page')));
        $minProfit = max(0.0, (float) $this->option('min-profit'));
        $interval = max(30, (int) $this->option('interval'));
        $deadline = microtime(true) + max(1, (int) $this->option('minutes')) * 60;

        $scanner = new PolymarketArbScanner;
        $passes = 0;
        $totalSignals = 0;

        $this->info(sprintf('[poly-arb] session=%s mode=%s pages=%d (SHADOW — detection only, no orders)',
            $sessionId, $once ? 'scan' : 'run', $pages));

        do {
            $passStart = microtime(true);

            try {
                $result = $scanner->scanOnce(
                    pages: $pages,
                    perPage: $perPage,
                    preFilterMargin: (float) ($config['pre_filter_margin'] ?? 0.02),
                    minProfitPerSet: $minProfit,
                    feePerSet: (float) ($config['fee_per_set'] ?? 0.0),
                    maxClobVerifications: (int) ($config['max_clob_verifications'] ?? 12),
                );
            } catch (\Throwable $e) {
                $this->warn('[poly-arb] pass error (continuing): '.$e->getMessage());
                $result = null;
            }

            if ($result !== null) {
                $passes++;
                DB::table('atlas_poly_arb_scans')->insert([
                    'session_id' => $sessionId,
                    'scanned_events' => $result['scanned_events'],
                    'eligible_events' => $result['eligible_events'],
                    'shortlisted' => $result['shortlisted'],
                    'verified' => $result['verified'],
                    'signals_found' => count($result['signals']),
                    'best_long_sum' => $result['best_long_sum'],
                    'best_short_sum' => $result['best_short_sum'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($result['signals'] as $signal) {
                    DB::table('atlas_poly_arb_signals')->insert([
                        'session_id' => $sessionId,
                        'event_slug' => $signal['event_slug'],
                        'event_title' => mb_substr((string) $signal['event_title'], 0, 300),
                        'kind' => $signal['kind'],
                        'execution_class' => $signal['execution_class'],
                        'n_legs' => $signal['n_legs'],
                        'sum' => $signal['sum'],
                        'profit_per_set' => $signal['profit_per_set'],
                        'sets' => $signal['sets'],
                        'profit_usd' => $signal['profit_usd'],
                        'cost_usd' => $signal['cost_usd'],
                        'legs' => json_encode($signal['legs']),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $totalSignals++;
                    $this->info(sprintf('[poly-arb] SIGNAL %s %s sum=%.4f profit/set=%.4f depth=%.1f sets (~$%.2f locked) %s',
                        $signal['kind'], $signal['event_slug'], $signal['sum'],
                        $signal['profit_per_set'], $signal['sets'], $signal['profit_usd'],
                        $signal['execution_class']));
                }

                $this->line(sprintf('[poly-arb] pass#%d scanned=%d eligible=%d shortlisted=%d verified=%d signals=%d best_long_sum=%s best_short_sum=%s (%.1fs)',
                    $passes, $result['scanned_events'], $result['eligible_events'], $result['shortlisted'],
                    $result['verified'], count($result['signals']),
                    $result['best_long_sum'] !== null ? sprintf('%.4f', $result['best_long_sum']) : 'n/a',
                    $result['best_short_sum'] !== null ? sprintf('%.4f', $result['best_short_sum']) : 'n/a',
                    microtime(true) - $passStart));
            }

            if ($once) {
                break;
            }

            $sleep = $interval - (microtime(true) - $passStart);
            if ($sleep > 0 && microtime(true) + $sleep < $deadline) {
                sleep((int) $sleep);
            }
        } while (microtime(true) < $deadline);

        app(AtlasEvidenceLedger::class)->record(LedgerEventType::ToolEvidenceRecorded, [
            'tool' => 'atlas:finance:poly-arb',
            'mode' => 'shadow_only_no_orders',
            'session_id' => $sessionId,
            'passes' => $passes,
            'signals' => $totalSignals,
        ], [
            'scope_type' => 'finance_poly_arb',
            'scope_id' => $sessionId,
        ]);

        $this->info(sprintf('[poly-arb] session %s complete: %d pass(es), %d signal(s).', $sessionId, $passes, $totalSignals));

        return self::SUCCESS;
    }

    private function report(): int
    {
        $scans = DB::table('atlas_poly_arb_scans');
        $signals = DB::table('atlas_poly_arb_signals');

        $report = [
            'schema_version' => 'atlas.finance.poly_arb.report.v1',
            'generated_at' => now()->toIso8601String(),
            'passes' => (clone $scans)->count(),
            'events_scanned_total' => (int) (clone $scans)->sum('scanned_events'),
            'signals_total' => (clone $signals)->count(),
            'signals_by_kind' => (clone $signals)
                ->selectRaw("kind, count(*) as n, avg(profit_per_set) as avg_profit_per_set, max(profit_usd) as max_profit_usd")
                ->groupBy('kind')->get()->map(fn ($r) => (array) $r)->all(),
            'closest_long_sum_ever' => (clone $scans)->whereNotNull('best_long_sum')->min('best_long_sum'),
            'closest_short_sum_ever' => (clone $scans)->whereNotNull('best_short_sum')->max('best_short_sum'),
            'latest_signals' => (clone $signals)->orderByDesc('id')->limit(10)
                ->get(['created_at', 'event_slug', 'kind', 'sum', 'profit_per_set', 'profit_usd'])
                ->map(fn ($r) => (array) $r)->all(),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('=== Polymarket Sum-of-Legs Arb — Shadow Report ===');
        $this->line(sprintf('Passes: %d | Events scanned (cumulative): %d | Signals: %d',
            $report['passes'], $report['events_scanned_total'], $report['signals_total']));
        foreach ($report['signals_by_kind'] as $row) {
            $this->line(sprintf('  %-16s n=%-4d avg_profit/set=%.4f max_locked=$%.2f',
                $row['kind'], $row['n'], (float) $row['avg_profit_per_set'], (float) $row['max_profit_usd']));
        }
        $this->line(sprintf('Closest the tail has run to arb: long sum %s (arb < 1) | short sum %s (arb > 1)',
            $report['closest_long_sum_ever'] !== null ? sprintf('%.4f', (float) $report['closest_long_sum_ever']) : 'n/a',
            $report['closest_short_sum_ever'] !== null ? sprintf('%.4f', (float) $report['closest_short_sum_ever']) : 'n/a'));
        foreach ($report['latest_signals'] as $row) {
            $this->line(sprintf('  %s %s %s sum=%.4f locked=$%.2f',
                $row['created_at'], $row['kind'], $row['event_slug'], (float) $row['sum'], (float) $row['profit_usd']));
        }

        return self::SUCCESS;
    }

    private function fail2(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
