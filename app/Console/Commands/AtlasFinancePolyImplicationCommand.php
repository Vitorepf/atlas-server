<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Finance\PolymarketShadow\PolymarketImplicationScanner;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Polymarket implication-violation scanner — SHADOW ONLY.
 *
 * Detects cross-market violations of P(A) <= P(B) for deterministically
 * discovered implication pairs (deadline monotonicity, nested thresholds,
 * group winner => advances). Records every CLOB-verified violation plus the
 * per-pass "best gap seen" so the report can say how CLOSE the universe runs
 * to violating even on days with zero signals. Places no orders, holds no keys.
 *
 * EXECUTION HONESTY: these signals require shorting the implicant (mirrored NO
 * book or mint+sell) — strictly more complex than sum-of-legs buying. The
 * execution_class on every signal says so; profit numbers are theoretical
 * locked edge, never a claim of equally executable profit.
 */
final class AtlasFinancePolyImplicationCommand extends Command
{
    protected $signature = 'atlas:finance:poly-implication
        {action=scan : scan|run|report}
        {--pages=4 : Gamma pages per pass (volume-ordered)}
        {--per-page=50 : Events per page}
        {--minutes=60 : Loop duration for run}
        {--interval=120 : Seconds between passes in run mode}
        {--min-edge=0.005 : Minimum locked edge per share}
        {--json : Emit JSON}';

    protected $description = 'Scan Polymarket for cross-market implication violations P(A) <= P(B) (shadow only, no orders).';

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

        $config = (array) config('atlas.finance_poly_implication', []);
        $sessionId = (string) Str::ulid();
        $pages = max(1, (int) $this->option('pages'));
        $perPage = max(10, min(100, (int) $this->option('per-page')));
        $minEdge = max(0.0, (float) $this->option('min-edge'));
        $interval = max(30, (int) $this->option('interval'));
        $deadline = microtime(true) + max(1, (int) $this->option('minutes')) * 60;

        $scanner = new PolymarketImplicationScanner;
        $passes = 0;
        $totalSignals = 0;

        $this->info(sprintf('[poly-implication] session=%s mode=%s pages=%d (SHADOW — detection only, no orders)',
            $sessionId, $once ? 'scan' : 'run', $pages));

        do {
            $passStart = microtime(true);

            try {
                $result = $scanner->scanOnce(
                    pages: $pages,
                    perPage: $perPage,
                    preFilterMargin: (float) ($config['pre_filter_margin'] ?? 0.02),
                    minEdgePerShare: $minEdge,
                    feePerShare: (float) ($config['fee_per_share'] ?? 0.0),
                    maxClobVerifications: (int) ($config['max_clob_verifications'] ?? 12),
                );
            } catch (\Throwable $e) {
                $this->warn('[poly-implication] pass error (continuing): '.$e->getMessage());
                $result = null;
            }

            if ($result !== null) {
                $passes++;
                DB::table('atlas_poly_implication_scans')->insert([
                    'session_id' => $sessionId,
                    'scanned_events' => $result['scanned_events'],
                    'markets_seen' => $result['markets_seen'],
                    'pairs' => $result['pairs'],
                    'shortlisted' => $result['shortlisted'],
                    'verified' => $result['verified'],
                    'signals_found' => count($result['signals']),
                    'best_gap' => $result['best_gap'],
                    'pairs_by_family' => json_encode($result['pairs_by_family']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($result['signals'] as $signal) {
                    $this->upsertOpportunity($signal);
                    DB::table('atlas_poly_implication_signals')->insert([
                        'session_id' => $sessionId,
                        'family' => $signal['family'],
                        'implicant_slug' => $signal['implicant_slug'],
                        'implied_slug' => $signal['implied_slug'],
                        'implicant_question' => mb_substr((string) $signal['implicant_question'], 0, 300),
                        'implied_question' => mb_substr((string) $signal['implied_question'], 0, 300),
                        'execution_class' => $signal['execution_class'],
                        'gap' => $signal['gap'],
                        'cached_gap' => $signal['cached_gap'],
                        'shares' => $signal['shares'],
                        'profit_usd' => $signal['profit_usd'],
                        'cost_usd' => $signal['cost_usd'],
                        'evidence' => json_encode($signal['evidence']),
                        'legs' => json_encode($signal['legs']),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $totalSignals++;
                    $this->info(sprintf('[poly-implication] SIGNAL %s %s => %s gap=%.4f depth=%.1f shares (~$%.2f locked) %s',
                        $signal['family'], $signal['implicant_slug'], $signal['implied_slug'],
                        $signal['gap'], $signal['shares'], $signal['profit_usd'], $signal['execution_class']));
                }

                $this->line(sprintf('[poly-implication] pass#%d events=%d markets=%d pairs=%d (%s) shortlisted=%d verified=%d signals=%d best_gap=%s (%.1fs)',
                    $passes, $result['scanned_events'], $result['markets_seen'], $result['pairs'],
                    $this->familySummary($result['pairs_by_family']),
                    $result['shortlisted'], $result['verified'], count($result['signals']),
                    $result['best_gap'] !== null ? sprintf('%.4f', $result['best_gap']) : 'n/a',
                    microtime(true) - $passStart));
            }

            if ($once) {
                break;
            }

            gc_collect_cycles();

            // Hot-watch tier between full sweeps: the top live violations get
            // re-verified every ~15s, so TTL is measured in seconds (the number
            // the future executor needs) instead of at full-sweep resolution.
            $sweepAt = $passStart + $interval;
            while (microtime(true) < min($sweepAt, $deadline)) {
                try {
                    $this->hotWatch($scanner, $minEdge, (float) ($config['fee_per_share'] ?? 0.0));
                } catch (\Throwable $e) {
                    $this->warn('[poly-implication] hot-watch error (continuing): '.$e->getMessage());
                }
                $pause = (int) min(15, max(1, min($sweepAt, $deadline) - microtime(true)));
                if ($pause > 0) {
                    sleep($pause);
                }
            }
        } while (microtime(true) < $deadline);

        app(AtlasEvidenceLedger::class)->record(LedgerEventType::ToolEvidenceRecorded, [
            'tool' => 'atlas:finance:poly-implication',
            'mode' => 'shadow_only_no_orders',
            'session_id' => $sessionId,
            'passes' => $passes,
            'signals' => $totalSignals,
        ], [
            'scope_type' => 'finance_poly_implication',
            'scope_id' => $sessionId,
        ]);

        $this->info(sprintf('[poly-implication] session %s complete: %d pass(es), %d signal(s).', $sessionId, $passes, $totalSignals));

        return self::SUCCESS;
    }

    private function report(): int
    {
        $scans = DB::table('atlas_poly_implication_scans');
        $signals = DB::table('atlas_poly_implication_signals');

        $report = [
            'schema_version' => 'atlas.finance.poly_implication.report.v1',
            'generated_at' => now()->toIso8601String(),
            'passes' => (clone $scans)->count(),
            'events_scanned_total' => (int) (clone $scans)->sum('scanned_events'),
            'pairs_discovered_last_pass' => (int) ((clone $scans)->orderByDesc('id')->value('pairs') ?? 0),
            'signals_total' => (clone $signals)->count(),
            'signals_by_family' => (clone $signals)
                ->selectRaw('family, count(*) as n, avg(gap) as avg_gap, max(profit_usd) as max_profit_usd')
                ->groupBy('family')->get()->map(fn ($r) => (array) $r)->all(),
            'closest_gap_ever' => (clone $scans)->whereNotNull('best_gap')->max('best_gap'),
            'distinct_opportunities' => DB::table('atlas_poly_implication_opportunities')->count(),
            'live_market_opportunities' => DB::table('atlas_poly_implication_opportunities')->where('dead_book', false)->count(),
            'live_market_locked_usd' => round((float) DB::table('atlas_poly_implication_opportunities')->where('dead_book', false)->sum('last_profit_usd'), 2),
            'dead_book_opportunities' => DB::table('atlas_poly_implication_opportunities')->where('dead_book', true)->count(),
            'dead_book_locked_usd' => round((float) DB::table('atlas_poly_implication_opportunities')->where('dead_book', true)->sum('last_profit_usd'), 2),
            'opportunities' => DB::table('atlas_poly_implication_opportunities')
                ->orderByDesc('max_profit_usd')->limit(15)
                ->get(['implicant_slug', 'implied_slug', 'family', 'execution_class', 'first_seen_at', 'last_seen_at', 'observations', 'last_gap', 'max_shares', 'max_profit_usd'])
                ->map(fn ($r) => (array) $r)->all(),
            'latest_signals' => (clone $signals)->orderByDesc('id')->limit(10)
                ->get(['created_at', 'family', 'implicant_slug', 'implied_slug', 'gap', 'profit_usd'])
                ->map(fn ($r) => (array) $r)->all(),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('=== Polymarket Implication Violations — Shadow Report ===');
        $this->line(sprintf('Passes: %d | Events scanned (cumulative): %d | Pairs (last pass): %d | Signals: %d',
            $report['passes'], $report['events_scanned_total'], $report['pairs_discovered_last_pass'], $report['signals_total']));
        foreach ($report['signals_by_family'] as $row) {
            $this->line(sprintf('  %-24s n=%-4d avg_gap=%.4f max_locked=$%.2f',
                $row['family'], $row['n'], (float) $row['avg_gap'], (float) $row['max_profit_usd']));
        }
        $this->line(sprintf('Closest the universe has run to violation: gap %s (violation > 0)',
            $report['closest_gap_ever'] !== null ? sprintf('%.4f', (float) $report['closest_gap_ever']) : 'n/a'));
        $this->line(sprintf('Distinct opportunities (lifecycle): %d — LIVE markets: %d ($%.2f locked) | dead-book flagged: %d ($%.2f excluded)',
            $report['distinct_opportunities'], $report['live_market_opportunities'], $report['live_market_locked_usd'],
            $report['dead_book_opportunities'], $report['dead_book_locked_usd']));
        foreach ($report['opportunities'] as $row) {
            $this->line(sprintf('  %-40s => %-40s %-22s obs=%-4d gap=%.4f max_depth=%.1f max_locked=$%.2f',
                mb_substr((string) $row['implicant_slug'], 0, 40), mb_substr((string) $row['implied_slug'], 0, 40),
                $row['family'], $row['observations'], (float) $row['last_gap'], (float) $row['max_shares'], (float) $row['max_profit_usd']));
        }
        foreach ($report['latest_signals'] as $row) {
            $this->line(sprintf('  %s %s %s => %s gap=%.4f locked=$%.2f',
                $row['created_at'], $row['family'], $row['implicant_slug'], $row['implied_slug'],
                (float) $row['gap'], (float) $row['profit_usd']));
        }

        return self::SUCCESS;
    }

    private function fail2(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    /**
     * @param  array<string, int>  $byFamily
     */
    private function familySummary(array $byFamily): string
    {
        if ($byFamily === []) {
            return 'none';
        }

        return implode(' ', array_map(
            fn (string $family, int $n) => sprintf('%s=%d', $family, $n),
            array_keys($byFamily), array_values($byFamily),
        ));
    }

    /**
     * Hot-watch one cycle: re-verify the top live violations against the live
     * CLOB and advance their lifecycle rows. A violation that no longer clears
     * the floor simply stops advancing last_seen_at — its TTL is then
     * (last_seen_at - first_seen_at), measured at ~15s resolution.
     */
    private function hotWatch(PolymarketImplicationScanner $scanner, float $minEdge, float $fee): void
    {
        $hot = DB::table('atlas_poly_implication_opportunities')
            ->where('dead_book', false)
            ->where('last_seen_at', '>=', now()->subMinutes(15))
            ->orderByDesc('last_profit_usd')
            ->limit(10)
            ->get();

        foreach ($hot as $opportunity) {
            $legsJson = DB::table('atlas_poly_implication_signals')
                ->where('implicant_slug', $opportunity->implicant_slug)
                ->where('implied_slug', $opportunity->implied_slug)
                ->orderByDesc('id')
                ->value('legs');
            $legs = is_string($legsJson) ? json_decode($legsJson, true) : null;
            $implicantToken = is_array($legs) ? (string) ($legs['implicant']['token'] ?? '') : '';
            $impliedToken = is_array($legs) ? (string) ($legs['implied']['token'] ?? '') : '';
            if ($implicantToken === '' || $impliedToken === '') {
                continue;
            }

            $fresh = $scanner->verifyKnownPair($implicantToken, $impliedToken, $fee, $minEdge);
            if ($fresh === null) {
                continue; // gone or unreadable: lifecycle stops advancing => TTL recorded
            }

            $this->upsertOpportunity($fresh + [
                'family' => $opportunity->family,
                'implicant_slug' => $opportunity->implicant_slug,
                'implied_slug' => $opportunity->implied_slug,
                'implicant_question' => $opportunity->implicant_question,
                'implied_question' => $opportunity->implied_question,
                'execution_class' => $opportunity->execution_class,
                'volume_24hr' => $opportunity->volume_24hr,
                'liquidity' => $opportunity->liquidity,
            ]);
        }
    }

    /**
     * Opportunity lifecycle: one row per (implicant, implied) pair, tracking
     * persistence (first/last seen, observations) and the largest executable
     * depth ever verified. This answers "would there have been time to execute?".
     */
    private function upsertOpportunity(array $signal): void
    {
        $volume = isset($signal['volume_24hr']) ? (float) $signal['volume_24hr'] : null;
        $activity = [
            'volume_24hr' => $volume,
            'liquidity' => $signal['liquidity'] ?? null,
            'dead_book' => $volume !== null
                && $volume < (float) config('atlas.finance_poly_implication.min_volume_24hr', 50.0),
        ];

        $existing = DB::table('atlas_poly_implication_opportunities')
            ->where('implicant_slug', $signal['implicant_slug'])
            ->where('implied_slug', $signal['implied_slug'])
            ->first();

        if ($existing === null) {
            DB::table('atlas_poly_implication_opportunities')->insert($activity + [
                'implicant_slug' => $signal['implicant_slug'],
                'implied_slug' => $signal['implied_slug'],
                'family' => $signal['family'],
                'implicant_question' => mb_substr((string) $signal['implicant_question'], 0, 300),
                'implied_question' => mb_substr((string) $signal['implied_question'], 0, 300),
                'execution_class' => $signal['execution_class'],
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'observations' => 1,
                'last_gap' => $signal['gap'],
                'last_shares' => $signal['shares'],
                'last_profit_usd' => $signal['profit_usd'],
                'max_shares' => $signal['shares'],
                'max_profit_usd' => $signal['profit_usd'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('atlas_poly_implication_opportunities')->where('id', $existing->id)->update($activity + [
            'last_seen_at' => now(),
            'observations' => (int) $existing->observations + 1,
            'last_gap' => $signal['gap'],
            'last_shares' => $signal['shares'],
            'last_profit_usd' => $signal['profit_usd'],
            'max_shares' => max((float) $existing->max_shares, (float) $signal['shares']),
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
