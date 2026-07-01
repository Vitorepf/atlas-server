<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Quaternity\DialogueToPackets;

use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\AtlasMaestroDialogueDrivenPacketLedger;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\DialogueLedgerEvent;
use PDO;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroDialogueDrivenPacketLedgerTest extends TestCase
{
    private string $sqlitePath;

    protected function setUp(): void
    {
        $this->sqlitePath = sys_get_temp_dir().'/atlas-dialogue-ledger-test-'.bin2hex(random_bytes(6)).'.sqlite';
    }

    protected function tearDown(): void
    {
        if (is_file($this->sqlitePath)) {
            unlink($this->sqlitePath);
        }
    }

    private function ledger(): AtlasMaestroDialogueDrivenPacketLedger
    {
        return new AtlasMaestroDialogueDrivenPacketLedger($this->sqlitePath);
    }

    private function appendFullApprovedFlow(AtlasMaestroDialogueDrivenPacketLedger $ledger, string $shapeHash): void
    {
        $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_INTENT_CAPTURED, 'shape_hash' => $shapeHash, 'cortex_snapshot_hash' => 'c1']);
        $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_PROPOSED, 'shape_hash' => $shapeHash, 'cortex_snapshot_hash' => 'c1']);
        $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_REVIEWED, 'shape_hash' => $shapeHash, 'cortex_snapshot_hash' => 'c1']);
        $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_APPROVED, 'shape_hash' => $shapeHash, 'cortex_snapshot_hash' => 'c1', 'operator_signature' => 'op-1']);
    }

    // ── replayProof() shape ──────────────────────────────────────────────────

    public function test_replay_proof_exposes_required_fields_on_empty_ledger(): void
    {
        $proof = $this->ledger()->replayProof();

        foreach (['schema_version', 'ok', 'total_events', 'head_hash', 'tail_hash', 'shape_count', 'approved_count', 'rejected_count', 'broken_ulid'] as $key) {
            $this->assertArrayHasKey($key, $proof, "Missing replayProof key: {$key}");
        }
        $this->assertTrue($proof['ok']);
        $this->assertSame(0, $proof['total_events']);
        $this->assertNull($proof['broken_ulid']);
    }

    public function test_replay_proof_counts_approved_and_rejected_and_shapes(): void
    {
        $ledger = $this->ledger();
        $this->appendFullApprovedFlow($ledger, 'shape-a');

        $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_INTENT_CAPTURED, 'shape_hash' => 'shape-b', 'cortex_snapshot_hash' => 'c2']);
        $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_PROPOSED, 'shape_hash' => 'shape-b', 'cortex_snapshot_hash' => 'c2']);
        $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_REVIEWED, 'shape_hash' => 'shape-b', 'cortex_snapshot_hash' => 'c2']);
        $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_REJECTED, 'shape_hash' => 'shape-b', 'cortex_snapshot_hash' => 'c2', 'operator_signature' => 'op-2']);

        $proof = $ledger->replayProof();

        $this->assertTrue($proof['ok']);
        $this->assertSame(8, $proof['total_events']);
        $this->assertSame(2, $proof['shape_count']);
        $this->assertSame(1, $proof['approved_count']);
        $this->assertSame(1, $proof['rejected_count']);
    }

    public function test_replay_proof_head_and_tail_hash_reflect_chain_boundaries(): void
    {
        $ledger = $this->ledger();
        $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_INTENT_CAPTURED, 'shape_hash' => 'shape-a', 'cortex_snapshot_hash' => 'c1']);
        $last = $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_PROPOSED, 'shape_hash' => 'shape-a', 'cortex_snapshot_hash' => 'c1']);

        $proof = $ledger->replayProof();

        $this->assertSame('0000000000000000000000000000000000000000000000000000000000000000', $proof['head_hash']);
        $this->assertSame($last->thisRowHash, $proof['tail_hash']);
    }

    // ── AC: replay proof reports ok=false + broken_ulid on tamper ─────────────

    public function test_replay_proof_detects_tampered_payload(): void
    {
        $ledger = $this->ledger();
        $event = $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_INTENT_CAPTURED, 'shape_hash' => 'shape-a', 'cortex_snapshot_hash' => 'c1', 'payload' => ['note' => 'original']]);
        $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_PROPOSED, 'shape_hash' => 'shape-a', 'cortex_snapshot_hash' => 'c1']);

        // Tamper with stored payload directly in SQLite — the chain hash no longer matches.
        $pdo = new PDO('sqlite:'.$this->sqlitePath);
        $pdo->exec("UPDATE dialogue_ledger_events SET payload_json = '{\"note\":\"tampered\"}' WHERE ulid = '{$event->ulid}'");

        $proof = $ledger->replayProof();

        $this->assertFalse($proof['ok']);
        $this->assertSame($event->ulid, $proof['broken_ulid']);
    }

    public function test_replay_proof_detects_tampered_hash(): void
    {
        $ledger = $this->ledger();
        $event = $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_INTENT_CAPTURED, 'shape_hash' => 'shape-a', 'cortex_snapshot_hash' => 'c1']);

        $pdo = new PDO('sqlite:'.$this->sqlitePath);
        $pdo->exec("UPDATE dialogue_ledger_events SET this_row_hash = 'deadbeef' WHERE ulid = '{$event->ulid}'");

        $proof = $ledger->replayProof();

        $this->assertFalse($proof['ok']);
        $this->assertSame($event->ulid, $proof['broken_ulid']);
    }

    // ── AC: replay proof never appends rows or mutates the ledger ─────────────

    public function test_replay_proof_does_not_mutate_or_append(): void
    {
        $ledger = $this->ledger();
        $this->appendFullApprovedFlow($ledger, 'shape-a');

        $before = $ledger->list();
        $ledger->replayProof();
        $ledger->replayProof();
        $after = $ledger->list();

        $this->assertCount(count($before), $after);
        $this->assertSame(
            array_map(fn (DialogueLedgerEvent $e) => $e->toArray(), $before),
            array_map(fn (DialogueLedgerEvent $e) => $e->toArray(), $after),
        );
    }

    // ── AC: Approved/Rejected still require a non-empty operator_signature ───

    public function test_approved_without_operator_signature_throws(): void
    {
        $ledger = $this->ledger();
        $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_INTENT_CAPTURED, 'shape_hash' => 'shape-a', 'cortex_snapshot_hash' => 'c1']);
        $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_PROPOSED, 'shape_hash' => 'shape-a', 'cortex_snapshot_hash' => 'c1']);
        $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_REVIEWED, 'shape_hash' => 'shape-a', 'cortex_snapshot_hash' => 'c1']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/operator_signature/');
        $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_APPROVED, 'shape_hash' => 'shape-a', 'cortex_snapshot_hash' => 'c1']);
    }

    public function test_rejected_with_empty_string_operator_signature_throws(): void
    {
        $ledger = $this->ledger();
        $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_INTENT_CAPTURED, 'shape_hash' => 'shape-a', 'cortex_snapshot_hash' => 'c1']);
        $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_PROPOSED, 'shape_hash' => 'shape-a', 'cortex_snapshot_hash' => 'c1']);
        $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_REVIEWED, 'shape_hash' => 'shape-a', 'cortex_snapshot_hash' => 'c1']);

        $this->expectException(\RuntimeException::class);
        $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_REJECTED, 'shape_hash' => 'shape-a', 'cortex_snapshot_hash' => 'c1', 'operator_signature' => '']);
    }

    public function test_approved_with_operator_signature_succeeds(): void
    {
        $ledger = $this->ledger();
        $this->appendFullApprovedFlow($ledger, 'shape-a');

        $proof = $ledger->replayProof();
        $this->assertSame(1, $proof['approved_count']);
    }
}
