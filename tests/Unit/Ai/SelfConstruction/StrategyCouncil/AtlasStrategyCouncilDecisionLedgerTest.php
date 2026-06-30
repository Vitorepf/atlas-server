<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\StrategyCouncil;

use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilAmbitionBudgetPolicy;
use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilDecisionLedger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves AtlasStrategyCouncilDecisionLedger: valid payload appends one row; duplicate decision_hash
 * idempotent without a second row; missing evidence_refs throws; invalid ambition_level throws;
 * bySelectedCandidate filters rows.
 */
final class AtlasStrategyCouncilDecisionLedgerTest extends TestCase
{
    private string $ledgerPath;

    private AtlasStrategyCouncilDecisionLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas_strategy_decisions_'.bin2hex(random_bytes(6)).'.jsonl';
        $this->ledger = new AtlasStrategyCouncilDecisionLedger($this->ledgerPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function payload(string $selected = 'cand-1'): array
    {
        return [
            'decision_id' => 'dec-'.$selected,
            'selected_candidate_id' => $selected,
            'rejected_candidate_ids' => ['cand-rejected'],
            'reason_vectors' => ['highest_leverage', 'matches_admitted_scope'],
            'ambition_level' => AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_STANDARD,
            'evidence_refs' => ['evh-1'],
            'decided_at' => '2026-06-25T00:00:00Z',
        ];
    }

    public function test_valid_payload_appends_one_row(): void
    {
        $res = $this->ledger->append($this->payload());
        $this->assertSame(AtlasStrategyCouncilDecisionLedger::STATUS_OK, $res['status']);
        $this->assertSame(64, strlen($res['row']['decision_hash']));
        $this->assertCount(1, file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function test_duplicate_decision_hash_idempotent_no_second_row(): void
    {
        $this->ledger->append($this->payload());
        $size = filesize($this->ledgerPath);
        $res2 = $this->ledger->append($this->payload());
        $this->assertSame(AtlasStrategyCouncilDecisionLedger::STATUS_ALREADY, $res2['status']);
        $this->assertSame($size, filesize($this->ledgerPath));
    }

    public function test_missing_evidence_refs_throws(): void
    {
        $p = $this->payload();
        $p['evidence_refs'] = [];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing evidence_refs/');
        $this->ledger->append($p);
    }

    public function test_invalid_ambition_level_throws(): void
    {
        $p = $this->payload();
        $p['ambition_level'] = 'reckless';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid ambition_level/');
        $this->ledger->append($p);
    }

    public function test_by_selected_candidate_filters_rows(): void
    {
        $this->ledger->append($this->payload('cand-A'));
        $this->ledger->append($this->payload('cand-B'));
        $rows = $this->ledger->bySelectedCandidate('cand-A');
        $this->assertCount(1, $rows);
        $this->assertSame('cand-A', $rows[0]['selected_candidate_id']);
    }

    public function test_stable_hash_same_input_yields_same_decision_hash(): void
    {
        $a = $this->ledger->append($this->payload());
        $this->assertSame(64, strlen($a['row']['decision_hash']));

        // Re-create ledger with different path to avoid dedup, then check same canonical payload → same hash.
        $path2 = sys_get_temp_dir().'/atlas_sc_dec2_'.bin2hex(random_bytes(6)).'.jsonl';
        $ledger2 = new AtlasStrategyCouncilDecisionLedger($path2);
        $b = $ledger2->append($this->payload());
        @unlink($path2);

        $this->assertSame($a['row']['decision_hash'], $b['row']['decision_hash']);
    }

    public function test_by_ambition_level_priority_query(): void
    {
        $p1 = $this->payload('A');
        $p1['ambition_level'] = AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_BOLD;
        $this->ledger->append($p1);

        $p2 = $this->payload('B');
        $p2['ambition_level'] = AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_NARROW;
        $this->ledger->append($p2);

        $bold = $this->ledger->byAmbitionLevel(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_BOLD);
        $this->assertCount(1, $bold);
        $this->assertSame('A', $bold[0]['selected_candidate_id']);

        $this->assertCount(0, $this->ledger->byAmbitionLevel(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_STANDARD));
    }

    public function test_by_refused_candidate_refusal_query(): void
    {
        $p = $this->payload('winner');
        $p['rejected_candidate_ids'] = ['loser-1', 'loser-2'];
        $this->ledger->append($p);

        $this->assertCount(1, $this->ledger->byRefusedCandidate('loser-1'));
        $this->assertCount(0, $this->ledger->byRefusedCandidate('winner'));
    }

    public function test_bounded_export_limits_rows(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $p = $this->payload("cand-{$i}");
            $p['decision_id'] = "dec-{$i}";
            $p['decided_at'] = "2026-06-25T0{$i}:00:00Z";
            $this->ledger->append($p);
        }

        $this->assertCount(3, $this->ledger->export(3));
        $this->assertCount(5, $this->ledger->all());
    }
}
