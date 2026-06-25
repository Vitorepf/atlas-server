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

    public function test_path_round_trip_is_idempotent_two_instances_share_one_file(): void
    {
        $a = new AtlasAutonomousRuntimeHeartbeatLedger($this->path);
        $b = new AtlasAutonomousRuntimeHeartbeatLedger($this->path);

        $a->append($this->record(['ts_unix' => 1700000000]));
        $b->append($this->record(['ts_unix' => 1700000001]));

        $this->assertCount(2, $a->readRecent());
        $this->assertCount(2, $b->readRecent());
    }
}
