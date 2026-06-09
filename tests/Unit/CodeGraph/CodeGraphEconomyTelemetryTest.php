<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphEconomyTelemetry;
use Tests\TestCase;

/**
 * AP-815 · E-10 — contract for the per-workspace token-economy ROI recorder.
 *
 * Pure (no DB): the recorder is a deterministic in-memory ledger + rollup. We prove
 * the economy math (saved/ratio), the per-workspace and grand-total aggregation, the
 * single-workspace filter, and the fail-safe clamping of negative / garbage inputs.
 */
class CodeGraphEconomyTelemetryTest extends TestCase
{
    private function telemetry(?array $store = null): CodeGraphEconomyTelemetry
    {
        return new CodeGraphEconomyTelemetry($store);
    }

    /**
     * Happy path: two queries in workspace A + one in workspace B. The recorded rows,
     * per-workspace buckets and grand totals must all carry the correct
     * baseline/actual/saved/ratio/queries.
     */
    public function test_records_and_reports_per_workspace_and_totals(): void
    {
        $t = $this->telemetry();

        // ws-a query 1: baseline 1000, actual 250 → saved 750, ratio 0.75.
        $row1 = $t->record('ws-a', 'symbol_lookup', 1000, 250);
        $this->assertSame('ws-a', $row1['workspace_id']);
        $this->assertSame('symbol_lookup', $row1['query_key']);
        $this->assertSame(1000, $row1['baseline']);
        $this->assertSame(250, $row1['actual']);
        $this->assertSame(750, $row1['saved']);
        $this->assertSame(0.75, $row1['ratio']);

        // ws-a query 2: baseline 400, actual 100 → saved 300, ratio 0.75.
        $t->record('ws-a', 'callers_of', 400, 100);

        // ws-b query 1: baseline 600, actual 300 → saved 300, ratio 0.5.
        $t->record('ws-b', 'blast_radius', 600, 300);

        $report = $t->report();

        // Two workspaces present, deterministically ordered (a before b).
        $this->assertSame(['ws-a', 'ws-b'], array_keys($report['by_workspace']));

        // ws-a aggregate: baseline 1400, actual 350, saved 1050, ratio 0.75, 2 queries.
        $a = $report['by_workspace']['ws-a'];
        $this->assertSame(1400, $a['baseline']);
        $this->assertSame(350, $a['actual']);
        $this->assertSame(1050, $a['saved']);
        $this->assertSame(0.75, $a['ratio']);
        $this->assertSame(2, $a['queries']);

        // ws-b aggregate: baseline 600, actual 300, saved 300, ratio 0.5, 1 query.
        $b = $report['by_workspace']['ws-b'];
        $this->assertSame(600, $b['baseline']);
        $this->assertSame(300, $b['actual']);
        $this->assertSame(300, $b['saved']);
        $this->assertSame(0.5, $b['ratio']);
        $this->assertSame(1, $b['queries']);

        // Grand totals: baseline 2000, actual 650, saved 1350, ratio 0.675, 3 queries.
        $totals = $report['totals'];
        $this->assertSame(2000, $totals['baseline']);
        $this->assertSame(650, $totals['actual']);
        $this->assertSame(1350, $totals['saved']);
        $this->assertSame(0.675, $totals['ratio']);
        $this->assertSame(3, $totals['queries']);
    }

    /**
     * The workspace filter restricts both `by_workspace` and `totals` to the one
     * workspace requested, leaving every other workspace's rows out of the rollup.
     */
    public function test_report_filters_by_workspace(): void
    {
        $t = $this->telemetry();
        $t->record('ws-a', 'q1', 1000, 200); // saved 800
        $t->record('ws-a', 'q2', 1000, 400); // saved 600
        $t->record('ws-b', 'q3', 500, 100);  // saved 400 — must be excluded

        $report = $t->report('ws-a');

        $this->assertSame(['ws-a'], array_keys($report['by_workspace']));

        // Only ws-a rows: baseline 2000, actual 600, saved 1400, ratio 0.7, 2 queries.
        $this->assertSame(2000, $report['by_workspace']['ws-a']['baseline']);
        $this->assertSame(1400, $report['by_workspace']['ws-a']['saved']);
        $this->assertSame(0.7, $report['by_workspace']['ws-a']['ratio']);
        $this->assertSame(2, $report['by_workspace']['ws-a']['queries']);

        // Totals reflect ONLY the filtered workspace, not ws-b.
        $this->assertSame(2000, $report['totals']['baseline']);
        $this->assertSame(1400, $report['totals']['saved']);
        $this->assertSame(2, $report['totals']['queries']);

        // A filter that matches nothing yields empty buckets and zeroed totals.
        $empty = $t->report('ws-nonexistent');
        $this->assertSame([], $empty['by_workspace']);
        $this->assertSame(0, $empty['totals']['baseline']);
        $this->assertSame(0, $empty['totals']['saved']);
        $this->assertSame(0.0, $empty['totals']['ratio']);
        $this->assertSame(0, $empty['totals']['queries']);
    }

    /**
     * Edge case: negative and "actual exceeds baseline" inputs clamp `saved` to 0 and
     * `ratio` to 0.0; a zero baseline yields ratio 0.0 (never a divide-by-zero); blank
     * keys fall back to stable placeholders. The recorder never throws.
     */
    public function test_clamps_negatives_zero_baseline_and_blank_keys(): void
    {
        $t = $this->telemetry();

        // Negative baseline AND negative actual → both clamp to 0 → saved 0, ratio 0.0.
        $neg = $t->record('ws-a', 'neg', -500, -200);
        $this->assertSame(0, $neg['baseline']);
        $this->assertSame(0, $neg['actual']);
        $this->assertSame(0, $neg['saved']);
        $this->assertSame(0.0, $neg['ratio']);

        // Actual exceeds baseline (a non-saving run) → saved clamps to 0, not negative.
        $over = $t->record('ws-a', 'over', 100, 900);
        $this->assertSame(100, $over['baseline']);
        $this->assertSame(900, $over['actual']);
        $this->assertSame(0, $over['saved']);
        $this->assertSame(0.0, $over['ratio']);

        // Zero baseline → ratio 0.0 with no divide-by-zero, even with zero actual.
        $zero = $t->record('ws-a', 'zero', 0, 0);
        $this->assertSame(0, $zero['baseline']);
        $this->assertSame(0, $zero['saved']);
        $this->assertSame(0.0, $zero['ratio']);

        // Blank workspace + blank query → stable placeholders, not "".
        $blank = $t->record('   ', '', 1000, 500);
        $this->assertSame(CodeGraphEconomyTelemetry::UNKNOWN_WORKSPACE, $blank['workspace_id']);
        $this->assertSame(CodeGraphEconomyTelemetry::UNKNOWN_QUERY, $blank['query_key']);
        $this->assertSame(500, $blank['saved']);
        $this->assertSame(0.5, $blank['ratio']);

        // The blank-keyed row is reachable in the rollup under the placeholder id, and
        // a matching blank filter resolves to that same placeholder bucket.
        $report = $t->report();
        $this->assertArrayHasKey(CodeGraphEconomyTelemetry::UNKNOWN_WORKSPACE, $report['by_workspace']);
        $filtered = $t->report('   ');
        $this->assertSame(
            [CodeGraphEconomyTelemetry::UNKNOWN_WORKSPACE],
            array_keys($filtered['by_workspace'])
        );
    }

    /**
     * Edge case: a garbage/clamped row injected into the store directly is tolerated by
     * report() — the float baseline is floored, a non-numeric actual becomes 0, an
     * out-of-shape entry is skipped — and the rollup stays internally consistent
     * (saved = max(0, Σbaseline − Σactual)). reset() then empties the ledger.
     */
    public function test_report_is_fail_safe_over_garbage_store_and_reset_clears(): void
    {
        $t = $this->telemetry([
            // Float baseline (floored to 1000) + non-numeric actual (→ 0).
            ['workspace_id' => 'ws-x', 'query_key' => 'q', 'baseline' => 1000.9, 'actual' => 'oops'],
            // Missing fields default to 0 and the blank workspace → placeholder bucket.
            ['query_key' => 'partial'],
            // Wholly malformed entry → skipped entirely.
            'not-an-array',
        ]);

        $report = $t->report();

        // ws-x: baseline floored to 1000, actual coerced to 0 → saved 1000, ratio 1.0.
        $this->assertSame(1000, $report['by_workspace']['ws-x']['baseline']);
        $this->assertSame(0, $report['by_workspace']['ws-x']['actual']);
        $this->assertSame(1000, $report['by_workspace']['ws-x']['saved']);
        $this->assertSame(1.0, $report['by_workspace']['ws-x']['ratio']);
        $this->assertSame(1, $report['by_workspace']['ws-x']['queries']);

        // The partial row landed under the UNKNOWN_WORKSPACE placeholder with zeroes.
        $this->assertArrayHasKey(CodeGraphEconomyTelemetry::UNKNOWN_WORKSPACE, $report['by_workspace']);
        $this->assertSame(0, $report['by_workspace'][CodeGraphEconomyTelemetry::UNKNOWN_WORKSPACE]['baseline']);

        // The string entry was skipped → exactly two buckets / two counted queries.
        $this->assertCount(2, $report['by_workspace']);
        $this->assertSame(2, $report['totals']['queries']);
        $this->assertSame(1000, $report['totals']['baseline']);
        $this->assertSame(1000, $report['totals']['saved']);

        // reset() clears the ledger; the report is then empty and zeroed.
        $t->reset();
        $after = $t->report();
        $this->assertSame([], $after['by_workspace']);
        $this->assertSame(0, $after['totals']['queries']);
        $this->assertSame(0, $after['totals']['baseline']);
        $this->assertSame(0.0, $after['totals']['ratio']);
    }
}
