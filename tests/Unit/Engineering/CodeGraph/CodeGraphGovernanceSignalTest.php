<?php

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphGovernanceSignal;
use PHPUnit\Framework\TestCase;

class CodeGraphGovernanceSignalTest extends TestCase
{
    /**
     * A fresh, populated, drift-free graph on the caller's minute-clock.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function stats(array $overrides = []): array
    {
        return array_merge([
            'edge_count' => 1200,
            'last_built_at' => 1_000,   // caller-clock minutes
            'drift_count' => 0,
            'max_age_minutes' => 60,
            'now_minutes' => 1_010,     // 10 min old => fresh
        ], $overrides);
    }

    public function test_fresh_populated_no_drift_is_ready(): void
    {
        $result = (new CodeGraphGovernanceSignal)->evaluate($this->stats());

        $this->assertSame(CodeGraphGovernanceSignal::STATUS_READY, $result['status']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_empty_edges_is_blocked(): void
    {
        $result = (new CodeGraphGovernanceSignal)->evaluate($this->stats(['edge_count' => 0]));

        $this->assertSame(CodeGraphGovernanceSignal::STATUS_BLOCKED, $result['status']);
        $this->assertContains('code_graph_edges_empty', $result['blockers']);
    }

    public function test_missing_edge_count_is_blocked_as_empty(): void
    {
        $stats = $this->stats();
        unset($stats['edge_count']);

        $result = (new CodeGraphGovernanceSignal)->evaluate($stats);

        $this->assertSame(CodeGraphGovernanceSignal::STATUS_BLOCKED, $result['status']);
        $this->assertContains('code_graph_edges_empty', $result['blockers']);
    }

    public function test_drift_is_blocked(): void
    {
        $result = (new CodeGraphGovernanceSignal)->evaluate($this->stats(['drift_count' => 3]));

        $this->assertSame(CodeGraphGovernanceSignal::STATUS_BLOCKED, $result['status']);
        $this->assertContains('code_graph_drift_detected', $result['blockers']);
    }

    public function test_stale_by_age_is_blocked(): void
    {
        // Built at minute 1000, now 1100 => age 100 > max 60.
        $result = (new CodeGraphGovernanceSignal)->evaluate($this->stats([
            'last_built_at' => 1_000,
            'now_minutes' => 1_100,
            'max_age_minutes' => 60,
        ]));

        $this->assertSame(CodeGraphGovernanceSignal::STATUS_BLOCKED, $result['status']);
        $this->assertContains('code_graph_index_stale_by_age', $result['blockers']);
    }

    public function test_age_exactly_at_budget_is_ready(): void
    {
        // Boundary: age == max is NOT stale (strictly-greater is the gate rule).
        $result = (new CodeGraphGovernanceSignal)->evaluate($this->stats([
            'last_built_at' => 1_000,
            'now_minutes' => 1_060,
            'max_age_minutes' => 60,
        ]));

        $this->assertSame(CodeGraphGovernanceSignal::STATUS_READY, $result['status']);
        $this->assertNotContains('code_graph_index_stale_by_age', $result['blockers']);
    }

    public function test_future_build_clock_skew_is_treated_as_fresh(): void
    {
        // Built "after" now: age clamps to 0, never negative-masking staleness.
        $result = (new CodeGraphGovernanceSignal)->evaluate($this->stats([
            'last_built_at' => 1_050,
            'now_minutes' => 1_000,
        ]));

        $this->assertSame(CodeGraphGovernanceSignal::STATUS_READY, $result['status']);
    }

    public function test_missing_last_built_at_fails_closed(): void
    {
        $stats = $this->stats();
        unset($stats['last_built_at']);

        $result = (new CodeGraphGovernanceSignal)->evaluate($stats);

        $this->assertSame(CodeGraphGovernanceSignal::STATUS_BLOCKED, $result['status']);
        $this->assertContains('code_graph_last_built_at_missing', $result['blockers']);
    }

    public function test_blank_last_built_at_fails_closed_as_missing(): void
    {
        $result = (new CodeGraphGovernanceSignal)->evaluate($this->stats(['last_built_at' => '   ']));

        $this->assertSame(CodeGraphGovernanceSignal::STATUS_BLOCKED, $result['status']);
        $this->assertContains('code_graph_last_built_at_missing', $result['blockers']);
    }

    public function test_invalid_last_built_at_fails_closed(): void
    {
        $result = (new CodeGraphGovernanceSignal)->evaluate($this->stats(['last_built_at' => 'not-a-timestamp']));

        $this->assertSame(CodeGraphGovernanceSignal::STATUS_BLOCKED, $result['status']);
        $this->assertContains('code_graph_last_built_at_invalid', $result['blockers']);
    }

    public function test_iso_timestamp_is_parsed_against_epoch_minutes(): void
    {
        // ISO built-at -> epoch minutes; now expressed on the same epoch-minute
        // clock, 30 min later, under a 60-min budget => ready.
        $builtIso = '2026-06-08T12:00:00+00:00';
        $builtMinutes = (int) floor(strtotime($builtIso) / 60);

        $result = (new CodeGraphGovernanceSignal)->evaluate([
            'edge_count' => 500,
            'last_built_at' => $builtIso,
            'drift_count' => 0,
            'max_age_minutes' => 60,
            'now_minutes' => $builtMinutes + 30,
        ]);

        $this->assertSame(CodeGraphGovernanceSignal::STATUS_READY, $result['status']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_non_positive_max_age_fails_closed(): void
    {
        $result = (new CodeGraphGovernanceSignal)->evaluate($this->stats(['max_age_minutes' => 0]));

        $this->assertSame(CodeGraphGovernanceSignal::STATUS_BLOCKED, $result['status']);
        $this->assertContains('code_graph_max_age_invalid', $result['blockers']);
    }

    public function test_missing_now_minutes_fails_closed(): void
    {
        $stats = $this->stats();
        unset($stats['now_minutes']);

        $result = (new CodeGraphGovernanceSignal)->evaluate($stats);

        $this->assertSame(CodeGraphGovernanceSignal::STATUS_BLOCKED, $result['status']);
        $this->assertContains('code_graph_now_minutes_invalid', $result['blockers']);
    }

    public function test_multiple_failures_collect_distinct_named_blockers(): void
    {
        // Empty + drift + stale all at once: every reason is named, none deduped away.
        $result = (new CodeGraphGovernanceSignal)->evaluate([
            'edge_count' => 0,
            'drift_count' => 5,
            'last_built_at' => 1_000,
            'now_minutes' => 5_000,
            'max_age_minutes' => 60,
        ]);

        $this->assertSame(CodeGraphGovernanceSignal::STATUS_BLOCKED, $result['status']);
        $this->assertContains('code_graph_edges_empty', $result['blockers']);
        $this->assertContains('code_graph_drift_detected', $result['blockers']);
        $this->assertContains('code_graph_index_stale_by_age', $result['blockers']);
        // No duplicates leaked through array_unique.
        $this->assertSame(array_values(array_unique($result['blockers'])), $result['blockers']);
    }

    public function test_blockers_are_a_clean_zero_indexed_list(): void
    {
        $result = (new CodeGraphGovernanceSignal)->evaluate($this->stats(['edge_count' => 0]));

        $this->assertSame(array_values($result['blockers']), $result['blockers']);
        $this->assertContainsOnlyString($result['blockers']);
    }

    public function test_is_deterministic_across_repeated_calls(): void
    {
        $signal = new CodeGraphGovernanceSignal;

        $ready = $this->stats();
        $blocked = ['edge_count' => 0, 'drift_count' => 2, 'max_age_minutes' => 60, 'now_minutes' => 9_999, 'last_built_at' => 10];

        $this->assertSame($signal->evaluate($ready), $signal->evaluate($ready));
        $this->assertSame($signal->evaluate($blocked), $signal->evaluate($blocked));
        // Distinct instances agree too — no hidden state.
        $this->assertSame((new CodeGraphGovernanceSignal)->evaluate($ready), $signal->evaluate($ready));
    }
}
