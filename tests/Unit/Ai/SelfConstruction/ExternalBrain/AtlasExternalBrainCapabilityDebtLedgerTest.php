<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityDebtLedger;
use Tests\TestCase;

final class AtlasExternalBrainCapabilityDebtLedgerTest extends TestCase
{
    private function svc(): AtlasExternalBrainCapabilityDebtLedger
    {
        return new AtlasExternalBrainCapabilityDebtLedger;
    }

    private function debt(
        string $id,
        string $dim,
        int $leverage = 5,
        int $risk = 5,
        string $status = 'unresolved',
        string $evidence = '',
        bool $authoredSpec = false,
    ): array {
        return [
            'debt_id' => $id,
            'capability_dimension' => $dim,
            'leverage' => $leverage,
            'risk' => $risk,
            'status' => $status,
            'resolution_evidence' => $evidence,
            'authored_spec' => $authoredSpec,
        ];
    }

    private function assess(array $debts): array
    {
        return $this->svc()->assess(['debt_records' => $debts]);
    }

    // ── recording ─────────────────────────────────────────────────────────────

    public function test_debt_is_recorded_in_ledger_entries(): void
    {
        $r = $this->assess([$this->debt('d1', 'weak_scoring')]);

        $this->assertCount(1, $r['ledger_entries']);
        $this->assertSame('d1', $r['ledger_entries'][0]['debt_id']);
    }

    // ── deduplication ─────────────────────────────────────────────────────────

    public function test_duplicate_dimension_keeps_higher_priority_score(): void
    {
        $r = $this->assess([
            $this->debt('d1', 'weak_scoring', leverage: 3, risk: 3),  // score=9
            $this->debt('d2', 'weak_scoring', leverage: 8, risk: 8),  // score=64 — winner
        ]);

        $this->assertCount(1, $r['ledger_entries']);
        $this->assertSame('d2', $r['ledger_entries'][0]['debt_id']);
    }

    public function test_duplicate_rejected_contains_loser_id(): void
    {
        $r = $this->assess([
            $this->debt('d1', 'weak_scoring', leverage: 3, risk: 3),
            $this->debt('d2', 'weak_scoring', leverage: 8, risk: 8),
        ]);

        $this->assertContains('d1', $r['duplicate_rejected']);
        $this->assertNotContains('d2', $r['duplicate_rejected']);
    }

    public function test_different_dimensions_are_not_deduped(): void
    {
        $r = $this->assess([
            $this->debt('d1', 'weak_scoring'),
            $this->debt('d2', 'missing_evidence_intake'),
        ]);

        $this->assertCount(2, $r['ledger_entries']);
    }

    // ── prioritization ────────────────────────────────────────────────────────

    public function test_entries_sorted_by_priority_score_descending(): void
    {
        $r = $this->assess([
            $this->debt('d1', 'weak_scoring', leverage: 2, risk: 2),      // 4
            $this->debt('d2', 'stale_task_family', leverage: 9, risk: 9),  // 81 — first
            $this->debt('d3', 'missing_evidence_intake', leverage: 5, risk: 5), // 25
        ]);

        $this->assertSame('d2', $r['ledger_entries'][0]['debt_id']);
        $this->assertSame('d3', $r['ledger_entries'][1]['debt_id']);
        $this->assertSame('d1', $r['ledger_entries'][2]['debt_id']);
    }

    // ── resolution rules ──────────────────────────────────────────────────────

    public function test_authored_spec_alone_does_not_resolve_debt(): void
    {
        $r = $this->assess([
            $this->debt('d1', 'weak_scoring', status: 'resolved', authoredSpec: true, evidence: ''),
        ]);

        $this->assertSame('unresolved', $r['ledger_entries'][0]['status']);
        $this->assertTrue($r['ledger_entries'][0]['authored_spec_only']);
    }

    public function test_resolution_evidence_with_resolved_status_resolves(): void
    {
        $r = $this->assess([
            $this->debt('d1', 'weak_scoring', status: 'resolved', evidence: 'gate passes with score 8.5'),
        ]);

        $this->assertSame('resolved', $r['ledger_entries'][0]['status']);
        $this->assertFalse($r['ledger_entries'][0]['authored_spec_only']);
    }

    public function test_evidence_without_resolved_status_stays_unresolved(): void
    {
        // has evidence but status is 'unresolved' — not yet marked resolved
        $r = $this->assess([
            $this->debt('d1', 'weak_scoring', status: 'unresolved', evidence: 'partial evidence'),
        ]);

        $this->assertSame('unresolved', $r['ledger_entries'][0]['status']);
    }

    // ── counts + maturity_blocked ─────────────────────────────────────────────

    public function test_unresolved_count_and_maturity_blocked(): void
    {
        $r = $this->assess([
            $this->debt('d1', 'weak_scoring'),                             // unresolved
            $this->debt('d2', 'missing_evidence_intake', status: 'resolved', evidence: 'proven'),
        ]);

        $this->assertSame(1, $r['unresolved_count']);
        $this->assertSame(1, $r['resolved_count']);
        $this->assertTrue($r['maturity_blocked']);
    }

    public function test_all_resolved_unblocks_maturity(): void
    {
        $r = $this->assess([
            $this->debt('d1', 'weak_scoring', status: 'resolved', evidence: 'gate 9.1'),
        ]);

        $this->assertSame(0, $r['unresolved_count']);
        $this->assertFalse($r['maturity_blocked']);
    }

    // ── dimension_summary ─────────────────────────────────────────────────────

    public function test_dimension_summary_maps_dimension_to_status(): void
    {
        $r = $this->assess([
            $this->debt('d1', 'weak_scoring'),
            $this->debt('d2', 'stale_task_family', status: 'resolved', evidence: 'ok'),
        ]);

        $this->assertSame('unresolved', $r['dimension_summary']['weak_scoring']);
        $this->assertSame('resolved', $r['dimension_summary']['stale_task_family']);
    }

    // ── empty + schema ────────────────────────────────────────────────────────

    public function test_empty_records_returns_clean_state(): void
    {
        $r = $this->svc()->assess([]);

        $this->assertSame([], $r['ledger_entries']);
        $this->assertSame(0, $r['unresolved_count']);
        $this->assertFalse($r['maturity_blocked']);
    }

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->assess([]);

        $this->assertSame(AtlasExternalBrainCapabilityDebtLedger::SCHEMA, $r['schema_version']);
    }

    // ── AC4: next_batch_focus ──────────────────────────────────────────────────

    public function test_next_batch_focus_key_always_present(): void
    {
        $r = $this->svc()->assess([]);

        $this->assertArrayHasKey('next_batch_focus', $r);
    }

    public function test_next_batch_focus_null_when_no_debts(): void
    {
        $r = $this->svc()->assess([]);

        $this->assertNull($r['next_batch_focus']);
    }

    public function test_next_batch_focus_null_when_all_resolved(): void
    {
        $r = $this->assess([
            $this->debt('d1', 'weak_scoring', status: 'resolved', evidence: 'gate 9.1'),
        ]);

        $this->assertNull($r['next_batch_focus']);
    }

    public function test_next_batch_focus_is_highest_priority_unresolved(): void
    {
        $r = $this->assess([
            $this->debt('low',  'weak_scoring',            leverage: 2, risk: 2),  // score=4
            $this->debt('high', 'missing_evidence_intake', leverage: 9, risk: 9),  // score=81
        ]);

        $this->assertSame('high', $r['next_batch_focus']['debt_id']);
        $this->assertSame(81, $r['next_batch_focus']['priority_score']);
    }

    public function test_next_batch_focus_skips_resolved_entries(): void
    {
        $r = $this->assess([
            $this->debt('resolved', 'weak_scoring',            leverage: 9, risk: 9, status: 'resolved', evidence: 'ok'),
            $this->debt('open',     'missing_evidence_intake', leverage: 3, risk: 3),  // score=9
        ]);

        // resolved has higher score but must be skipped; open is next_batch_focus
        $this->assertSame('open', $r['next_batch_focus']['debt_id']);
    }

    public function test_next_batch_focus_contains_entry_fields(): void
    {
        $r = $this->assess([$this->debt('d1', 'stale_task_family', leverage: 5, risk: 6)]);

        $focus = $r['next_batch_focus'];
        $this->assertSame('d1', $focus['debt_id']);
        $this->assertSame('stale_task_family', $focus['capability_dimension']);
        $this->assertSame(30, $focus['priority_score']); // 5*6
        $this->assertSame('unresolved', $focus['status']);
    }

    // ── AC3: all five dimensions accepted ─────────────────────────────────────

    public function test_all_five_dimensions_recorded(): void
    {
        $dims = AtlasExternalBrainCapabilityDebtLedger::DIMENSIONS;
        $debts = array_map(fn (string $d, int $i) => $this->debt("d{$i}", $d), $dims, range(1, count($dims)));

        $r = $this->assess($debts);

        $this->assertCount(5, $r['ledger_entries']);
        foreach ($dims as $dim) {
            $this->assertArrayHasKey($dim, $r['dimension_summary']);
        }
    }

    // ── rankByUnblockValue ──────────────────────────────────────────────────────

    private function debtRecord(array $overrides = []): array
    {
        return array_merge([
            'debt_id' => 'debt-1',
            'blocked_capability' => 'task_origination',
            'severity' => 'high',
            'blocked_downstream_circuits' => ['queue_replenishment'],
            'evidence_age_days' => 1,
            'implementation_effort' => 1,
        ], $overrides);
    }

    public function test_ranked_debts_have_required_fields(): void
    {
        $r = $this->svc()->rankByUnblockValue(['debt_records' => [$this->debtRecord()]]);

        $entry = $r['ranked_debts'][0];
        foreach (['debt_id', 'blocked_capability', 'unblock_score', 'first_safe_task_hint'] as $k) {
            $this->assertArrayHasKey($k, $entry);
        }
        $this->assertNotEmpty($entry['first_safe_task_hint']);
    }

    public function test_higher_severity_ranks_above_lower_severity(): void
    {
        $r = $this->svc()->rankByUnblockValue(['debt_records' => [
            $this->debtRecord(['debt_id' => 'low-sev', 'severity' => 'low', 'blocked_downstream_circuits' => []]),
            $this->debtRecord(['debt_id' => 'critical-sev', 'severity' => 'critical', 'blocked_downstream_circuits' => []]),
        ]]);

        $this->assertSame('critical-sev', $r['ranked_debts'][0]['debt_id']);
        $this->assertSame('critical-sev', $r['top_priority_debt_id']);
    }

    public function test_more_blocked_downstream_circuits_ranks_higher_at_same_severity(): void
    {
        $r = $this->svc()->rankByUnblockValue(['debt_records' => [
            $this->debtRecord(['debt_id' => 'few-circuits', 'severity' => 'medium', 'blocked_downstream_circuits' => ['a']]),
            $this->debtRecord(['debt_id' => 'many-circuits', 'severity' => 'medium', 'blocked_downstream_circuits' => ['a', 'b', 'c']]),
        ]]);

        $this->assertSame('many-circuits', $r['ranked_debts'][0]['debt_id']);
    }

    public function test_implementation_effort_does_not_affect_ranking(): void
    {
        $r = $this->svc()->rankByUnblockValue(['debt_records' => [
            $this->debtRecord(['debt_id' => 'hard-but-critical', 'severity' => 'critical', 'implementation_effort' => 100]),
            $this->debtRecord(['debt_id' => 'easy-but-low', 'severity' => 'low', 'implementation_effort' => 1, 'blocked_downstream_circuits' => []]),
        ]]);

        $this->assertSame('hard-but-critical', $r['ranked_debts'][0]['debt_id']);
    }

    public function test_stale_evidence_discounts_unblock_score(): void
    {
        $r = $this->svc()->rankByUnblockValue(['debt_records' => [
            $this->debtRecord(['debt_id' => 'fresh', 'evidence_age_days' => 1]),
            $this->debtRecord(['debt_id' => 'stale', 'evidence_age_days' => 30]),
        ]]);

        $byId = collect($r['ranked_debts'])->keyBy('debt_id');
        $this->assertSame('fresh', $byId['fresh']['evidence_freshness']);
        $this->assertSame('stale', $byId['stale']['evidence_freshness']);
        $this->assertGreaterThan($byId['stale']['unblock_score'], $byId['fresh']['unblock_score']);
    }

    public function test_debt_grouped_by_capability_area(): void
    {
        $r = $this->svc()->rankByUnblockValue(['debt_records' => [
            $this->debtRecord(['debt_id' => 'd1', 'blocked_capability' => 'task_origination']),
            $this->debtRecord(['debt_id' => 'd2', 'blocked_capability' => 'task_origination']),
            $this->debtRecord(['debt_id' => 'd3', 'blocked_capability' => 'evidence_capture']),
        ]]);

        $this->assertCount(2, $r['debt_by_capability_area']['task_origination']);
        $this->assertCount(1, $r['debt_by_capability_area']['evidence_capture']);
    }

    public function test_empty_debt_records_returns_empty_ranking(): void
    {
        $r = $this->svc()->rankByUnblockValue(['debt_records' => []]);

        $this->assertSame([], $r['ranked_debts']);
        $this->assertNull($r['top_priority_debt_id']);
    }
}
