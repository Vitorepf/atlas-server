<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Finance\PolymarketShadow\NetProfitModel;
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
        {--min-profit=0.002 : Minimum locked profit per $1 set to RECORD (census floor — see everything; the gas-aware net-positive floor that decides what to EXECUTE lives in the executor, not here)}
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
        $this->raiseMemoryFloor('512M');

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
                    $this->upsertOpportunity($signal);
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

            gc_collect_cycles();

            // Hot-watch tier between full sweeps: the top live opportunities get
            // re-verified every ~15s, so TTL is measured in seconds (the number
            // the future executor needs) instead of at full-sweep resolution.
            $sweepAt = $passStart + $interval;
            while (microtime(true) < min($sweepAt, $deadline)) {
                try {
                    $this->hotWatch($scanner, $minProfit, (float) ($config['fee_per_set'] ?? 0.0));
                } catch (\Throwable $e) {
                    $this->warn('[poly-arb] hot-watch error (continuing): '.$e->getMessage());
                }
                $pause = (int) min(15, max(1, min($sweepAt, $deadline) - microtime(true)));
                if ($pause > 0) {
                    sleep($pause);
                }
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
            'distinct_opportunities' => DB::table('atlas_poly_arb_opportunities')->count(),
            'net_profit' => $this->netProfitCensus(),
            'live_market_opportunities' => DB::table('atlas_poly_arb_opportunities')->where('dead_book', false)->count(),
            'live_market_locked_usd' => round((float) DB::table('atlas_poly_arb_opportunities')->where('dead_book', false)->sum('last_profit_usd'), 2),
            'dead_book_opportunities' => DB::table('atlas_poly_arb_opportunities')->where('dead_book', true)->count(),
            'dead_book_locked_usd' => round((float) DB::table('atlas_poly_arb_opportunities')->where('dead_book', true)->sum('last_profit_usd'), 2),
            'opportunities' => DB::table('atlas_poly_arb_opportunities')
                ->orderByDesc('max_profit_usd')->limit(15)
                ->get(['event_slug', 'kind', 'execution_class', 'first_seen_at', 'last_seen_at', 'observations', 'last_sum', 'max_sets', 'max_profit_usd'])
                ->map(fn ($r) => (array) $r)->all(),
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
        $this->line(sprintf('Distinct opportunities (lifecycle): %d — LIVE markets: %d ($%.2f locked) | dead-book flagged: %d ($%.2f excluded)',
            $report['distinct_opportunities'], $report['live_market_opportunities'], $report['live_market_locked_usd'],
            $report['dead_book_opportunities'], $report['dead_book_locked_usd']));
        $this->line('NET-of-gas census (which opportunities actually pay after capture cost — the "ser esperto" rule):');
        foreach ($report['net_profit'] as $scenario => $row) {
            $this->line(sprintf('  %-12s cost long=$%.2f short=$%.2f => %d/%d worth taking | net $%.2f (gross $%.2f)',
                $scenario, $row['fixed_cost_long'], $row['fixed_cost_short'],
                $row['worth_taking'], $row['of_total'], $row['net_usd'], $row['gross_usd']));
        }
        foreach ($report['opportunities'] as $row) {
            $this->line(sprintf('  %-50s %-15s obs=%-4d %s -> %s max_depth=%.1f sets max_locked=$%.2f',
                mb_substr((string) $row['event_slug'], 0, 50), $row['kind'], $row['observations'],
                $row['first_seen_at'], $row['last_seen_at'], (float) $row['max_sets'], (float) $row['max_profit_usd']));
        }
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

    /**
     * Net-profit census: applies the gas-aware cost model to every LIVE
     * opportunity and reports how many clear net-positive — plus a sensitivity
     * sweep across cost assumptions, so Friday's $5 measurement lands the model
     * on a real number instead of a guess. Implements the operator rule
     * "capture all that nets positive, however small".
     *
     * @return array<string, mixed>
     */
    private function netProfitCensus(): array
    {
        $opps = DB::table('atlas_poly_arb_opportunities')
            ->where('dead_book', false)
            ->get(['event_slug', 'kind', 'last_profit_usd', 'last_profit_per_set']);

        // n_legs lives on the signals table; fetch the latest per (slug,kind) once
        // (per-leg cost defaults to 0, so this only bites if a fee knob is set).
        $legsBySlugKind = DB::table('atlas_poly_arb_signals')
            ->select('event_slug', 'kind', 'n_legs')
            ->orderByDesc('id')
            ->get()
            ->reduce(function (array $map, $row): array {
                $key = $row->event_slug.'|'.$row->kind;
                $map[$key] ??= (int) $row->n_legs;

                return $map;
            }, []);

        $config = (array) config('atlas.finance_poly_arb', []);
        $perLeg = (float) ($config['cost_per_leg'] ?? 0.0);

        // Sensitivity sweep: optimistic / base / pessimistic per-basket fixed cost.
        $scenarios = [
            'optimistic' => ['long' => 0.02, 'short' => 0.05],
            'base' => [
                'long' => (float) ($config['cost_long_fixed'] ?? 0.10),
                'short' => (float) ($config['cost_short_fixed'] ?? 0.20),
            ],
            'pessimistic' => ['long' => 0.30, 'short' => 0.50],
        ];

        $out = [];
        foreach ($scenarios as $name => $cost) {
            $model = new NetProfitModel($cost['long'], $cost['short'], $perLeg);
            $worth = 0;
            $netTotal = 0.0;
            $grossTotal = 0.0;
            foreach ($opps as $o) {
                $nLegs = $legsBySlugKind[$o->event_slug.'|'.$o->kind] ?? 0;
                $e = $model->evaluate((string) $o->kind, (float) $o->last_profit_usd, (float) $o->last_profit_per_set, $nLegs);
                $grossTotal += $e['gross_usd'];
                if ($e['worth_taking']) {
                    $worth++;
                    $netTotal += $e['net_usd'];
                }
            }
            $out[$name] = [
                'fixed_cost_long' => $cost['long'],
                'fixed_cost_short' => $cost['short'],
                'worth_taking' => $worth,
                'of_total' => $opps->count(),
                'net_usd' => round($netTotal, 2),
                'gross_usd' => round($grossTotal, 2),
            ];
        }

        return $out;
    }

    /**
     * Hot-watch one cycle: re-verify the top live opportunities against the
     * live CLOB and advance their lifecycle rows. An opportunity that no longer
     * clears the floor simply stops advancing last_seen_at — its TTL is then
     * (last_seen_at - first_seen_at), measured at ~15s resolution.
     */
    private function hotWatch(PolymarketArbScanner $scanner, float $minProfit, float $fee): void
    {
        $hot = DB::table('atlas_poly_arb_opportunities')
            ->where('dead_book', false)
            ->where('last_seen_at', '>=', now()->subMinutes(15))
            ->orderByDesc('last_profit_usd')
            ->limit(10)
            ->get();

        foreach ($hot as $opportunity) {
            $legsJson = DB::table('atlas_poly_arb_signals')
                ->where('event_slug', $opportunity->event_slug)
                ->where('kind', $opportunity->kind)
                ->orderByDesc('id')
                ->value('legs');
            $legs = is_string($legsJson) ? json_decode($legsJson, true) : null;
            if (! is_array($legs) || $legs === []) {
                continue;
            }

            $fresh = $scanner->verifyKnownOpportunity($legs, (string) $opportunity->kind, $fee, $minProfit);
            if ($fresh === null) {
                continue; // gone or unreadable: lifecycle stops advancing => TTL recorded
            }

            $this->upsertOpportunity($fresh + [
                'event_slug' => $opportunity->event_slug,
                'kind' => $opportunity->kind,
                'event_title' => $opportunity->event_title,
                'execution_class' => $opportunity->execution_class,
                'volume_24hr' => $opportunity->volume_24hr,
                'liquidity' => $opportunity->liquidity,
            ]);
        }
    }

    /**
     * Opportunity lifecycle: one row per (event, kind), tracking persistence
     * (first/last seen, observations) and the largest executable size ever
     * verified. This is what answers "would there have been time to execute?".
     */
    private function upsertOpportunity(array $signal): void
    {
        $volume = isset($signal['volume_24hr']) ? (float) $signal['volume_24hr'] : null;
        $activity = [
            'volume_24hr' => $volume,
            'liquidity' => $signal['liquidity'] ?? null,
            // Phantom-liquidity guard: persistent inconsistency + no real trading
            // activity usually means a stale, unfillable book — flagged, not counted
            // in the headline census.
            'dead_book' => $volume !== null
                && $volume < (float) config('atlas.finance_poly_arb.min_volume_24hr', 50.0),
        ];

        $existing = DB::table('atlas_poly_arb_opportunities')
            ->where('event_slug', $signal['event_slug'])
            ->where('kind', $signal['kind'])
            ->first();

        if ($existing === null) {
            DB::table('atlas_poly_arb_opportunities')->insert($activity + [
                'event_slug' => $signal['event_slug'],
                'kind' => $signal['kind'],
                'event_title' => mb_substr((string) $signal['event_title'], 0, 300),
                'execution_class' => $signal['execution_class'],
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'observations' => 1,
                'last_sum' => $signal['sum'],
                'last_profit_per_set' => $signal['profit_per_set'],
                'last_sets' => $signal['sets'],
                'last_profit_usd' => $signal['profit_usd'],
                'max_sets' => $signal['sets'],
                'max_profit_usd' => $signal['profit_usd'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('atlas_poly_arb_opportunities')->where('id', $existing->id)->update($activity + [
            'last_seen_at' => now(),
            'observations' => (int) $existing->observations + 1,
            'last_sum' => $signal['sum'],
            'last_profit_per_set' => $signal['profit_per_set'],
            'last_sets' => $signal['sets'],
            'last_profit_usd' => $signal['profit_usd'],
            'max_sets' => max((float) $existing->max_sets, (float) $signal['sets']),
            'max_profit_usd' => max((float) $existing->max_profit_usd, (float) $signal['profit_usd']),
            'updated_at' => now(),
        ]);
    }

    /**
     * Raise-only memory floor (project pattern): each Gamma page is ~5MB of
     * JSON that multiplies on parse; the 128M CLI default OOMs on pass #2.
     * Never lowers an already-higher limit.
     */
    private function raiseMemoryFloor(string $floor): void
    {
        $current = ini_get('memory_limit');
        $currentBytes = $current === '-1' ? PHP_INT_MAX : $this->toBytes((string) $current);
        $floorBytes = $this->toBytes($floor);

        if ($currentBytes < $floorBytes) {
            ini_set('memory_limit', $floor);
        }
    }

    private function toBytes(string $value): int
    {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }
}
