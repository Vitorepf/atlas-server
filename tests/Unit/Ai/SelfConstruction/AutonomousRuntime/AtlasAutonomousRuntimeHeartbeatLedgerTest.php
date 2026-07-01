<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\AutonomousRuntime;

use App\Services\Ai\SelfConstruction\AutonomousRuntime\AtlasAutonomousRuntimeHeartbeatLedger;
use Tests\TestCase;

final class AtlasAutonomousRuntimeHeartbeatLedgerTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-runtime-heartbeat-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function record(array $overrides = []): array
    {
        return $overrides + [
            'cycle_id' => 'cyc-1',
            'state' => 'observe',
            'decision' => 'continue',
            'safety_verdict' => 'green',
            'plan_hash' => 'abc123',
            'evidence_refs' => ['receipt:r1'],
            'ts_unix' => 1700000000,
        ];
    }

    public function test_append_writes_one_line_per_record(): void
    {
        $ledger = new AtlasAutonomousRuntimeHeartbeatLedger($this->path);

        $this->assertTrue($ledger->append($this->record())['appended']);
        $this->assertTrue($ledger->append($this->record(['ts_unix' => 1700000001]))['appended']);
        $this->assertTrue($ledger->append($this->record(['ts_unix' => 1700000002]))['appended']);

        $rows = $ledger->readRecent();
        $this->assertCount(3, $rows);
        $this->assertSame([1700000000, 1700000001, 1700000002], array_column($rows, 'ts_unix'));
    }

    public function test_missing_required_fields_block_without_partial_write(): void
    {
        $ledger = new AtlasAutonomousRuntimeHeartbeatLedger($this->path);
        $bad = $this->record();
        unset($bad['plan_hash'], $bad['evidence_refs']);

        $verdict = $ledger->append($bad);
        $this->assertFalse($verdict['appended']);
        $this->assertContains('missing_field:plan_hash', $verdict['blockers']);
        $this->assertContains('missing_field:evidence_refs', $verdict['blockers']);
        $this->assertFalse(is_file($this->path), 'failed validation MUST NOT create the ledger file');
    }

    public function test_evidence_refs_must_be_a_list(): void
    {
        $ledger = new AtlasAutonomousRuntimeHeartbeatLedger($this->path);
        $verdict = $ledger->append($this->record(['evidence_refs' => 'not-an-array']));

        $this->assertFalse($verdict['appended']);
        $this->assertContains('evidence_refs_not_list', $verdict['blockers']);
    }

    public function test_ts_unix_must_be_int(): void
    {
        $ledger = new AtlasAutonomousRuntimeHeartbeatLedger($this->path);
        $verdict = $ledger->append($this->record(['ts_unix' => 'now-please']));

        $this->assertFalse($verdict['appended']);
        $this->assertContains('ts_unix_not_int', $verdict['blockers']);
    }

    public function test_read_recent_returns_insertion_order_capped_by_limit(): void
    {
        $ledger = new AtlasAutonomousRuntimeHeartbeatLedger($this->path);
        for ($i = 0; $i < 5; $i++) {
            $ledger->append($this->record(['ts_unix' => 1700000000 + $i, 'cycle_id' => 'c-'.$i]));
        }

        $rows = $ledger->readRecent(3);
        $this->assertCount(3, $rows);
        $this->assertSame(['c-2', 'c-3', 'c-4'], array_column($rows, 'cycle_id'));
    }

    public function test_classify_stale_phases_from_now_timestamp(): void
    {
        $ledger = new AtlasAutonomousRuntimeHeartbeatLedger($this->path);
        // observe: 400s ago (> 300 threshold) → stale; plan: 300s ago (not >) → fresh; execute: 200s ago → fresh
        $records = [
            ['state' => 'observe', 'ts_unix' => 1700000000],
            ['state' => 'plan', 'ts_unix' => 1700000100],
            ['state' => 'execute', 'ts_unix' => 1700000200],
        ];

        $result = $ledger->classifyStalePhases($records, nowUnix: 1700000400, staleThresholdSeconds: 300);

        $this->assertSame(['observe'], $result['stale']);
        $this->assertSame(['execute', 'plan'], $result['fresh']); // sorted
    }

    public function test_detect_missing_phases_when_required_phases_absent_from_records(): void
    {
        $ledger = new AtlasAutonomousRuntimeHeartbeatLedger($this->path);
        $records = [
            ['state' => 'observe'],
            ['state' => 'execute'],
        ];

        $missing = $ledger->detectMissingPhases($records, ['observe', 'plan', 'execute', 'certify']);

        $this->assertSame(['certify', 'plan'], $missing); // sorted alphabetically
    }

    public function test_summarize_has_deterministic_ordering_without_scalar_health_score(): void
    {
        $ledger = new AtlasAutonomousRuntimeHeartbeatLedger($this->path);
        $records = [
            ['state' => 'execute'],
            ['state' => 'observe'],
            ['state' => 'execute'],
            ['state' => 'plan'],
            ['state' => 'observe'],
            ['state' => 'observe'],
        ];

        $summary = $ledger->summarize($records);

        $this->assertSame(['execute', 'observe', 'plan'], array_keys($summary['phases'])); // ksorted
        $this->assertSame(['execute' => 2, 'observe' => 3, 'plan' => 1], $summary['phases']);
        $this->assertSame(6, $summary['total']);
        $this->assertArrayNotHasKey('health_score', $summary);
    }

    public function test_path_round_trip_is_idempotent_two_instances_share_one_file(): void
    {
        $a = new AtlasAutonomousRuntimeHeartbeatLedger($this->path);
        $b = new AtlasAutonomousRuntimeHeartbeatLedger($this->path);

        $a->append($this->record(['ts_unix' => 1700000000]));
        $b->append($this->record(['ts_unix' => 1700000001]));

        $this->assertCount(2, $a->readRecent());
        $this->assertCount(2, $b->readRecent());
    }

    // ── AC1: stale phase details expose phase, last_seen_at, stale_seconds, recovery_hint ──

    public function test_stale_phase_details_include_last_seen_at_stale_seconds_and_recovery_hint(): void
    {
        $ledger = new AtlasAutonomousRuntimeHeartbeatLedger($this->path);
        $records = [
            ['state' => 'observe', 'ts_unix' => 1700000000],
            ['state' => 'plan', 'ts_unix' => 1700000100],
        ];

        $details = $ledger->stalePhaseDetails($records, nowUnix: 1700000400, staleThresholdSeconds: 300);
        $byPhase = array_column($details, null, 'phase');

        $this->assertTrue($byPhase['observe']['is_stale']);
        $this->assertSame(1700000000, $byPhase['observe']['last_seen_at']);
        $this->assertSame(400, $byPhase['observe']['stale_seconds']);
        $this->assertNotEmpty($byPhase['observe']['recovery_hint']);
        $this->assertStringContainsString('observe', $byPhase['observe']['recovery_hint']);

        $this->assertFalse($byPhase['plan']['is_stale']);
        $this->assertNull($byPhase['plan']['recovery_hint']);
    }

    // ── AC4: no-stale healthy ledger ────────────────────────────────────────────

    public function test_healthy_ledger_has_no_stale_phases_and_no_recovery_hints(): void
    {
        $ledger = new AtlasAutonomousRuntimeHeartbeatLedger($this->path);
        $records = [
            ['state' => 'observe', 'ts_unix' => 1700000390],
            ['state' => 'plan', 'ts_unix' => 1700000395],
            ['state' => 'execute', 'ts_unix' => 1700000400],
        ];

        $result = $ledger->classifyStalePhases($records, nowUnix: 1700000400, staleThresholdSeconds: 300);
        $this->assertSame([], $result['stale']);

        $details = $ledger->stalePhaseDetails($records, nowUnix: 1700000400, staleThresholdSeconds: 300);
        foreach ($details as $d) {
            $this->assertFalse($d['is_stale']);
            $this->assertNull($d['recovery_hint']);
        }
    }

    // ── AC3: missing cycle_id/state/ts_unix/evidence_refs is a rejected heartbeat ──

    public function test_missing_cycle_id_blocks_append(): void
    {
        $ledger = new AtlasAutonomousRuntimeHeartbeatLedger($this->path);
        $bad = $this->record();
        unset($bad['cycle_id']);

        $verdict = $ledger->append($bad);
        $this->assertFalse($verdict['appended']);
        $this->assertContains('missing_field:cycle_id', $verdict['blockers']);
    }

    public function test_missing_state_blocks_append(): void
    {
        $ledger = new AtlasAutonomousRuntimeHeartbeatLedger($this->path);
        $bad = $this->record();
        unset($bad['state']);

        $verdict = $ledger->append($bad);
        $this->assertFalse($verdict['appended']);
        $this->assertContains('missing_field:state', $verdict['blockers']);
    }

    public function test_missing_ts_unix_blocks_append(): void
    {
        $ledger = new AtlasAutonomousRuntimeHeartbeatLedger($this->path);
        $bad = $this->record();
        unset($bad['ts_unix']);

        $verdict = $ledger->append($bad);
        $this->assertFalse($verdict['appended']);
        $this->assertContains('missing_field:ts_unix', $verdict['blockers']);
    }
}
