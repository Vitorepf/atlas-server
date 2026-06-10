<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasAurgNode;
use App\Models\AtlasSelfConstructCycle;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * S3.F3 — the HONEST META-METRIC of the RECURSIVE GOVERNED SELF-IMPROVEMENT LOOP.
 *
 * The loop's job is to improve Atlas; THIS service's job is to MEASURE that honestly —
 * the anti-self-deception layer. It does two things and nothing more:
 *
 *   record(summary)  — after a cycle runs, persist ONE durable history row of the
 *     cycle's MEASURED counts {signals_detected, generated, relevance_passed,
 *     relevance_rejected, branches_delivered, brain_nodes_added}. The counts come
 *     straight from the loop summary (the relevance gate's verdict) and the brain's
 *     own node-count delta — NOTHING is self-declared, NOTHING is hardcoded.
 *
 *   status()  — compute, LIVE from the persisted history, the per-cycle series + the
 *     relevance-pass-rate trend + brain growth. The rate is DERIVED at read time
 *     (passed / generated), never stored as a flattering scalar the loop could inflate.
 *
 * WHAT IT DELIBERATELY DOES NOT DO (anti-Goodhart — load-bearing):
 *   - it NEVER claims ACCELERATION. The "recursive / rate-of-improvement-improving"
 *     property is EMERGENT over real cycles, not asserted here. status() reports the
 *     measured trend (the delta between the first and last window's pass-rate, and the
 *     brain-growth delta) and labels it for what it is — a measurement, not a victory.
 *     With too few cycles to trend it says so ("insufficient_history") rather than
 *     fabricate a slope.
 *   - it NEVER counts a rejected generation as progress. relevance_rejected is surfaced
 *     as prominently as relevance_passed — a cycle that caught the 412-line-garbage
 *     failure is reported as a REJECTION, honestly.
 *
 * The brain-node-count delta is the recursion substrate made measurable: it is exactly
 * the new provider-safe refs the cycle added to the AURG that the NEXT cycle's
 * brain-anchored query can reach. Growth here is the loop compounding, quantified.
 *
 * FAIL-OPEN + sqlite-safe: a missing history table (the migration not run) degrades to
 * a non-recorded marker / an empty status — it NEVER breaks a loop cycle. The brain
 * node count tolerates an absent AURG store (returns 0). Pure reads otherwise.
 */
final class AtlasSelfImprovementMetaMetricService
{
    public const SCHEMA = 'atlas.ai.self_improvement_meta_metric.v1';

    /** Minimum cycles before a trend slope is reported (below this: insufficient_history). */
    private const MIN_CYCLES_FOR_TREND = 2;

    /**
     * The current absolute brain size (provider-safe AURG node count). Used as the
     * before/after anchor for a cycle's brain_nodes_added delta. Tolerates an absent
     * store (returns 0) so the loop never breaks on a brain outage.
     */
    public function brainNodeCount(): int
    {
        try {
            if (! Schema::hasTable('atlas_aurg_nodes')) {
                return 0;
            }

            return (int) AtlasAurgNode::query()->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Persist ONE durable history row for a completed cycle. Counts are taken from the
     * loop summary verbatim (the gate's verdict) + the brain-node delta the caller
     * measured around the cycle. Idempotent on the deterministic cycle_hash (re-running
     * an identical cycle upserts the same row, never double-counts). FAIL-OPEN.
     *
     * @param  array<string,mixed>  $summary  the {@see AtlasSelfConstructionLoopService::run} result
     * @return array<string,mixed> {recorded:bool, reason?:string, cycle?:array<string,mixed>}
     */
    public function record(array $summary, int $brainNodesBefore, int $brainNodesAfter): array
    {
        try {
            if (! Schema::hasTable('atlas_self_construct_cycles')) {
                return ['recorded' => false, 'reason' => 'history_table_missing'];
            }
        } catch (Throwable) {
            return ['recorded' => false, 'reason' => 'history_table_unavailable'];
        }

        // MEASURED counts — straight from the loop summary (the gate's verdict). The
        // loop never sets a "success" flag; these are detected/delivered/accepted/
        // rejected, the honest cycle ledger.
        $signalsDetected = (int) ($summary['detected'] ?? 0);
        $generated = (int) ($summary['delivered_count'] ?? 0);
        $passed = (int) ($summary['accepted_count'] ?? 0);
        $rejected = (int) ($summary['rejected_count'] ?? 0);
        $branches = count(array_values(array_filter(
            (array) ($summary['branches'] ?? []),
            'is_string',
        )));
        // The brain-node delta is the recursion substrate, measured. Clamp negatives to
        // 0 (a prune between snapshots is not "negative self-improvement" — it is not
        // measured here; this metric only credits ADDED refs).
        $brainAdded = max(0, $brainNodesAfter - $brainNodesBefore);
        $receiptHash = is_string($summary['receipt_hash'] ?? null) ? (string) $summary['receipt_hash'] : '';
        $brainAnchored = (bool) ($summary['brain_anchored'] ?? true);

        // Deterministic cycle fingerprint — idempotent upsert key.
        $cycleHash = hash('sha256', (string) json_encode([
            'receipt' => $receiptHash,
            'detected' => $signalsDetected,
            'generated' => $generated,
            'passed' => $passed,
            'rejected' => $rejected,
            'branches' => $branches,
            'brain_added' => $brainAdded,
        ], JSON_UNESCAPED_SLASHES));

        try {
            $row = AtlasSelfConstructCycle::query()->updateOrCreate(
                ['cycle_hash' => $cycleHash],
                [
                    'receipt_hash' => $receiptHash,
                    'brain_anchored' => $brainAnchored,
                    'signals_detected' => $signalsDetected,
                    'generated' => $generated,
                    'relevance_passed' => $passed,
                    'relevance_rejected' => $rejected,
                    'branches_delivered' => $branches,
                    'brain_nodes_added' => $brainAdded,
                    'brain_nodes_total' => max(0, $brainNodesAfter),
                ],
            );

            return [
                'recorded' => true,
                'cycle' => $this->rowToArray($row),
            ];
        } catch (Throwable) {
            // Honest degrade — a history-write outage never breaks a loop cycle.
            return ['recorded' => false, 'reason' => 'record_failed'];
        }
    }

    /**
     * Compute the LIVE meta-metric from persisted history. Everything is derived from
     * real rows — zero hardcoded. FAIL-OPEN: an absent table yields an empty,
     * well-formed status (cycles=[], totals zeroed, trend=insufficient_history).
     *
     * @param  int  $limit  most-recent cycles to include in the series (bounded read).
     * @return array<string,mixed>
     */
    public function status(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));

        $rows = $this->loadHistory($limit);
        $cycles = array_map(fn (AtlasSelfConstructCycle $r): array => $this->rowToArray($r), $rows);

        $totals = $this->totals($cycles);
        $trend = $this->trend($cycles);
        $brainGrowth = $this->brainGrowth($cycles);

        return [
            'schema_version' => self::SCHEMA,
            'cycle_count' => count($cycles),
            'cycles' => $cycles,
            'totals' => $totals,
            // The relevance-pass-RATE trend — DERIVED, never stored. The honest
            // statement of "is the loop getting better at staying on-target?", with an
            // explicit insufficient_history when there is too little to trend (no
            // fabricated slope).
            'relevance_pass_rate_trend' => $trend,
            // Brain growth = the recursion substrate quantified (refs the next cycle can
            // reach). A measurement of compounding, NOT a claim of acceleration.
            'brain_growth' => $brainGrowth,
            // ANTI-OVER-CLAIM, stated in the payload itself: this is a measurement.
            'note' => 'measured per-cycle history; the recursive/accelerating property is emergent over real cycles and is NOT asserted here',
        ];
    }

    /**
     * @return list<AtlasSelfConstructCycle>
     */
    private function loadHistory(int $limit): array
    {
        try {
            if (! Schema::hasTable('atlas_self_construct_cycles')) {
                return [];
            }

            // Oldest→newest within the most-recent window, so the series reads in cycle
            // order and the trend's first/last comparison is chronological.
            return AtlasSelfConstructCycle::query()
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->reverse()
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<array<string,mixed>>  $cycles
     * @return array<string,int|float|null>
     */
    private function totals(array $cycles): array
    {
        $sum = static fn (string $k): int => array_sum(array_map(static fn (array $c): int => (int) ($c[$k] ?? 0), $cycles));

        $generated = $sum('generated');
        $passed = $sum('relevance_passed');

        return [
            'signals_detected' => $sum('signals_detected'),
            'generated' => $generated,
            'relevance_passed' => $passed,
            'relevance_rejected' => $sum('relevance_rejected'),
            'branches_delivered' => $sum('branches_delivered'),
            'brain_nodes_added' => $sum('brain_nodes_added'),
            // Lifetime on-target rate (derived; null when nothing generated — never a
            // fabricated 1.0).
            'relevance_pass_rate' => $generated > 0 ? round($passed / $generated, 4) : null,
        ];
    }

    /**
     * The relevance-pass-rate trend across the history: the per-cycle rate series, and
     * the first→last delta. HONEST: with fewer than MIN_CYCLES_FOR_TREND cycles that
     * generated anything, it reports insufficient_history rather than a fabricated slope.
     *
     * @param  list<array<string,mixed>>  $cycles
     * @return array<string,mixed>
     */
    private function trend(array $cycles): array
    {
        // Per-cycle pass rate (null for a cycle that generated nothing — not 0, which
        // would falsely depress the trend; an idle cycle has no rate to report).
        $series = [];
        foreach ($cycles as $c) {
            $gen = (int) ($c['generated'] ?? 0);
            $series[] = $gen > 0 ? round((int) ($c['relevance_passed'] ?? 0) / $gen, 4) : null;
        }

        $rated = array_values(array_filter($series, static fn ($v): bool => $v !== null));
        if (count($rated) < self::MIN_CYCLES_FOR_TREND) {
            return [
                'direction' => 'insufficient_history',
                'series' => $series,
                'first' => $rated[0] ?? null,
                'last' => $rated[count($rated) - 1] ?? null,
                'delta' => null,
            ];
        }

        $first = (float) $rated[0];
        $last = (float) $rated[count($rated) - 1];
        $delta = round($last - $first, 4);

        return [
            // A label for the MEASURED delta — improving / declining / flat. NOT a claim
            // that the loop is self-accelerating; just the sign of first→last.
            'direction' => $delta > 0.0 ? 'improving' : ($delta < 0.0 ? 'declining' : 'flat'),
            'series' => $series,
            'first' => round($first, 4),
            'last' => round($last, 4),
            'delta' => $delta,
        ];
    }

    /**
     * Brain growth across the window — the recursion substrate quantified. Reports the
     * total refs added and the first→last absolute size delta (what the next cycle's
     * brain query gained reach over).
     *
     * @param  list<array<string,mixed>>  $cycles
     * @return array<string,int|null>
     */
    private function brainGrowth(array $cycles): array
    {
        if ($cycles === []) {
            return ['nodes_added_total' => 0, 'first_total' => null, 'last_total' => null, 'size_delta' => null];
        }

        $firstTotal = (int) ($cycles[0]['brain_nodes_total'] ?? 0);
        $lastTotal = (int) ($cycles[count($cycles) - 1]['brain_nodes_total'] ?? 0);

        return [
            'nodes_added_total' => array_sum(array_map(static fn (array $c): int => (int) ($c['brain_nodes_added'] ?? 0), $cycles)),
            'first_total' => $firstTotal,
            'last_total' => $lastTotal,
            'size_delta' => $lastTotal - $firstTotal,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function rowToArray(AtlasSelfConstructCycle $row): array
    {
        return [
            'cycle_hash' => $row->cycle_hash,
            'receipt_hash' => $row->receipt_hash,
            'brain_anchored' => (bool) $row->brain_anchored,
            'signals_detected' => (int) $row->signals_detected,
            'generated' => (int) $row->generated,
            'relevance_passed' => (int) $row->relevance_passed,
            'relevance_rejected' => (int) $row->relevance_rejected,
            'branches_delivered' => (int) $row->branches_delivered,
            'brain_nodes_added' => (int) $row->brain_nodes_added,
            'brain_nodes_total' => (int) $row->brain_nodes_total,
            'recorded_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
