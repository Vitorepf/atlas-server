<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Autonomy;

use App\Services\Ai\SelfConstruction\Autonomy\AtlasSelfConstructionAutonomyRuntimeLedger;
use Tests\TestCase;

final class AtlasSelfConstructionAutonomyRuntimeLedgerTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-autonomy-ledger-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    public function test_append_writes_appendonly_jsonl_events(): void
    {
        $ledger = new AtlasSelfConstructionAutonomyRuntimeLedger($this->ledgerPath);
        $r1 = $ledger->append([
            'kind' => 'requested_transition',
            'level' => 'L1',
            'decision' => 'pending',
            'reasons' => ['scheduled'],
            'lane' => 'lane-A',
            'created_at_unix' => 1700000000,
        ]);
        $r2 = $ledger->append([
            'kind' => 'promoted',
            'level' => 'L2',
            'decision' => 'allow',
            'reasons' => ['evidence_ok', 'audit_fresh'],
            'lane' => 'lane-A',
            'created_at_unix' => 1700000010,
        ]);

        $all = $ledger->all();
        $this->assertCount(2, $all);
        $this->assertSame('requested_transition', $all[0]['kind']);
        $this->assertSame('promoted', $all[1]['kind']);
        $this->assertSame($r1['evidence_hash'], $all[0]['evidence_hash']);
        $this->assertSame($r2['evidence_hash'], $all[1]['evidence_hash']);
    }

    public function test_append_is_append_only_does_not_mutate_prior_events(): void
    {
        $ledger = new AtlasSelfConstructionAutonomyRuntimeLedger($this->ledgerPath);
        $r1 = $ledger->append([
            'kind' => 'requested_transition', 'level' => 'L1', 'decision' => 'pending',
            'reasons' => ['scheduled'], 'lane' => 'lane-A', 'created_at_unix' => 1700000000,
        ]);
        $snapshot1 = file_get_contents($this->ledgerPath);

        $ledger->append([
            'kind' => 'refused', 'level' => 'L1', 'decision' => 'reject',
            'reasons' => ['missing_evidence'], 'lane' => 'lane-A', 'created_at_unix' => 1700000020,
        ]);
        $snapshot2 = file_get_contents($this->ledgerPath);

        $this->assertStringStartsWith($snapshot1, $snapshot2, 'append must NOT mutate prior bytes');
        $all = $ledger->all();
        $this->assertSame($r1['evidence_hash'], $all[0]['evidence_hash'], 'first event hash must be unchanged after later append');
    }

    public function test_evidence_hash_is_deterministic_over_canonical_body(): void
    {
        $event = [
            'kind' => 'promoted', 'level' => 'L2', 'decision' => 'allow',
            'reasons' => ['b', 'a'], 'lane' => 'lane-X', 'created_at_unix' => 1700000100,
        ];
        $a = new AtlasSelfConstructionAutonomyRuntimeLedger($this->ledgerPath.'.a');
        $b = new AtlasSelfConstructionAutonomyRuntimeLedger($this->ledgerPath.'.b');
        $rowA = $a->append($event);
        $rowB = $b->append($event);

        // Reasons order in input differs but canonical hash should be the same.
        $event2 = $event;
        $event2['reasons'] = ['a', 'b'];
        $rowB2 = (new AtlasSelfConstructionAutonomyRuntimeLedger($this->ledgerPath.'.c'))->append($event2);

        @unlink($this->ledgerPath.'.a');
        @unlink($this->ledgerPath.'.b');
        @unlink($this->ledgerPath.'.c');

        $this->assertSame($rowA['evidence_hash'], $rowB['evidence_hash']);
        $this->assertSame($rowA['evidence_hash'], $rowB2['evidence_hash']);
    }

    public function test_latest_returns_most_recent_event(): void
    {
        $ledger = new AtlasSelfConstructionAutonomyRuntimeLedger($this->ledgerPath);
        $this->assertNull($ledger->latest());

        $ledger->append([
            'kind' => 'requested_transition', 'level' => 'L0', 'decision' => 'pending',
            'reasons' => [], 'lane' => 'lane-A', 'created_at_unix' => 1700000000,
        ]);
        $ledger->append([
            'kind' => 'degraded', 'level' => 'L0', 'decision' => 'demote',
            'reasons' => ['watchdog_alarm'], 'lane' => 'lane-A', 'created_at_unix' => 1700000050,
        ]);

        $latest = $ledger->latest();
        $this->assertSame('degraded', $latest['kind']);
        $this->assertSame('demote', $latest['decision']);
    }

    public function test_history_for_lane_filters_without_mutating(): void
    {
        $ledger = new AtlasSelfConstructionAutonomyRuntimeLedger($this->ledgerPath);
        $ledger->append(['kind' => 'promoted', 'level' => 'L1', 'decision' => 'allow', 'reasons' => [], 'lane' => 'lane-A', 'created_at_unix' => 1]);
        $ledger->append(['kind' => 'promoted', 'level' => 'L1', 'decision' => 'allow', 'reasons' => [], 'lane' => 'lane-B', 'created_at_unix' => 2]);
        $ledger->append(['kind' => 'refused', 'level' => 'L1', 'decision' => 'reject', 'reasons' => [], 'lane' => 'lane-A', 'created_at_unix' => 3]);

        $laneA = $ledger->historyForLane('lane-A');
        $this->assertCount(2, $laneA);
        $this->assertSame(['lane-A', 'lane-A'], array_column($laneA, 'lane'));
        $this->assertCount(3, $ledger->all(), 'history query must not mutate the ledger');
    }

    private function eventFor(string $kind, string $level, int $ts = 1700000000): array
    {
        return ['kind' => $kind, 'level' => $level, 'decision' => 'allow', 'reasons' => [], 'lane' => 'lane-A', 'created_at_unix' => $ts];
    }

    public function test_duplicate_suppression_append_if_new_skips_identical_event(): void
    {
        $ledger = new AtlasSelfConstructionAutonomyRuntimeLedger($this->ledgerPath);
        $event = $this->eventFor(AtlasSelfConstructionAutonomyRuntimeLedger::KIND_PROMOTED, 'L1', 1700000001);

        $r1 = $ledger->appendIfNew($event);
        $r2 = $ledger->appendIfNew($event);

        $this->assertTrue($r1['appended']);
        $this->assertFalse($r2['appended'], 'second call must not write a duplicate');
        $this->assertSame($r1['row']['evidence_hash'], $r2['row']['evidence_hash']);
        $this->assertCount(1, $ledger->all(), 'only one row must be on disk');
    }

    public function test_bounded_history_tail_returns_last_n_events(): void
    {
        $ledger = new AtlasSelfConstructionAutonomyRuntimeLedger($this->ledgerPath);
        foreach (['L0', 'L1', 'L2', 'L3', 'L4'] as $i => $lvl) {
            $ledger->append($this->eventFor(AtlasSelfConstructionAutonomyRuntimeLedger::KIND_PROMOTED, $lvl, 1700000000 + $i));
        }

        $tail = $ledger->tail(2);
        $this->assertCount(2, $tail);
        $this->assertSame('L3', $tail[0]['level']);
        $this->assertSame('L4', $tail[1]['level']);
        $this->assertCount(5, $ledger->all(), 'tail must not mutate the ledger');
    }

    public function test_level_regression_records_degraded_kind(): void
    {
        $ledger = new AtlasSelfConstructionAutonomyRuntimeLedger($this->ledgerPath);
        $ledger->append($this->eventFor(AtlasSelfConstructionAutonomyRuntimeLedger::KIND_PROMOTED, 'L2', 1700000001));
        $row = $ledger->append([
            'kind' => AtlasSelfConstructionAutonomyRuntimeLedger::KIND_DEGRADED,
            'level' => 'L1', 'decision' => 'demote',
            'reasons' => ['watchdog_alarm'], 'lane' => 'lane-A', 'created_at_unix' => 1700000002,
        ]);

        $this->assertSame(AtlasSelfConstructionAutonomyRuntimeLedger::KIND_DEGRADED, $row['kind']);
        $this->assertSame('L1', $row['level']);
        $this->assertSame('demote', $row['decision']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row['evidence_hash']);
    }

    public function test_level_promotion_records_promoted_kind_with_higher_level(): void
    {
        $ledger = new AtlasSelfConstructionAutonomyRuntimeLedger($this->ledgerPath);
        $ledger->append($this->eventFor(AtlasSelfConstructionAutonomyRuntimeLedger::KIND_REQUESTED, 'L1', 1700000001));
        $row = $ledger->append([
            'kind' => AtlasSelfConstructionAutonomyRuntimeLedger::KIND_PROMOTED,
            'level' => 'L2', 'decision' => 'allow',
            'reasons' => ['evidence_ok', 'audit_fresh'], 'lane' => 'lane-A', 'created_at_unix' => 1700000002,
        ]);

        $this->assertSame(AtlasSelfConstructionAutonomyRuntimeLedger::KIND_PROMOTED, $row['kind']);
        $this->assertSame('L2', $row['level']);
        $this->assertSame('allow', $row['decision']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row['evidence_hash']);
        $this->assertCount(2, $ledger->all());
    }

    public function test_records_required_fields(): void
    {
        $ledger = new AtlasSelfConstructionAutonomyRuntimeLedger($this->ledgerPath);
        $row = $ledger->append([
            'kind' => 'promoted', 'level' => 'L2', 'decision' => 'allow',
            'reasons' => ['ok'], 'lane' => 'lane-A', 'created_at_unix' => 1700000200,
        ]);

        foreach (['level', 'decision', 'reasons', 'evidence_hash', 'lane', 'created_at'] as $field) {
            $this->assertArrayHasKey($field, $row, "row must contain {$field}");
        }
        $this->assertSame('2023-11-14T22:16:40Z', $row['created_at']);
    }
}
