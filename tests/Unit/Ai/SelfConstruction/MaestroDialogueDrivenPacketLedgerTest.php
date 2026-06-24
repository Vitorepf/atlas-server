<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\AtlasMaestroDialogueDrivenPacketLedger;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\DialogueLedgerEvent;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\DialogueLedgerTransitionViolation;
use Tests\TestCase;

/**
 * Proves the QUAT-W330-P03 dialogue ledger: cryptographic chain integrity (mutation in the middle breaks the
 * verifier), state-machine transition enforcement (Enqueued without prior Approved throws), operator-as-real-
 * primitive invariant (Approved/Rejected with null operator_signature is refused at append), and append-only
 * (the ledger exposes NO update / delete public method).
 */
final class MaestroDialogueDrivenPacketLedgerTest extends TestCase
{
    private string $sqlitePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sqlitePath = sys_get_temp_dir().'/atlas_dlg_ledger_'.bin2hex(random_bytes(6)).'.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->sqlitePath);
        parent::tearDown();
    }

    private function ledger(): AtlasMaestroDialogueDrivenPacketLedger
    {
        return new AtlasMaestroDialogueDrivenPacketLedger($this->sqlitePath, static fn (): int => 1_700_000_000);
    }

    private function walkShape(AtlasMaestroDialogueDrivenPacketLedger $ledger, string $shapeHash, array $types, string $opSig = 'op-1'): void
    {
        foreach ($types as $type) {
            $needsOp = in_array($type, [DialogueLedgerEvent::TYPE_APPROVED, DialogueLedgerEvent::TYPE_REJECTED], true);
            $ledger->append([
                'event_type' => $type,
                'shape_hash' => $shapeHash,
                'cortex_snapshot_hash' => 'cortex-hash-'.$shapeHash,
                'operator_signature' => $needsOp ? $opSig : null,
                'payload' => ['t' => $type],
            ]);
        }
    }

    public function test_chain_integrity_50_events_then_mutate_one_row_breaks_verify(): void
    {
        $ledger = $this->ledger();
        // 10 shapes × 5 events each = 50; full walk Proposed→Reviewed→Approved→Enqueued→Executed.
        $walk = [
            DialogueLedgerEvent::TYPE_PROPOSED,
            DialogueLedgerEvent::TYPE_REVIEWED,
            DialogueLedgerEvent::TYPE_APPROVED,
            DialogueLedgerEvent::TYPE_ENQUEUED,
            DialogueLedgerEvent::TYPE_EXECUTED,
        ];
        for ($i = 1; $i <= 10; $i++) {
            $this->walkShape($ledger, 'shape-'.$i, $walk);
        }

        $report = $ledger->verifyChain();
        $this->assertTrue($report['ok']);
        $this->assertSame(50, $report['total']);

        // Mutate row 25's payload_json directly in SQLite.
        $pdo = new \PDO('sqlite:'.$this->sqlitePath);
        $stmt = $pdo->prepare('UPDATE '.AtlasMaestroDialogueDrivenPacketLedger::TABLE.' SET payload_json = :p WHERE rowid = 25');
        $stmt->execute(['p' => '{"tamper":true}']);

        $report = $this->ledger()->verifyChain();
        $this->assertFalse($report['ok']);
        $this->assertNotNull($report['broken_ulid'], 'verifier points at the broken row');
    }

    public function test_out_of_order_enqueued_without_prior_approved_throws(): void
    {
        $ledger = $this->ledger();
        $ledger->append([
            'event_type' => DialogueLedgerEvent::TYPE_PROPOSED,
            'shape_hash' => 'shape-x',
            'cortex_snapshot_hash' => 'c1',
        ]);

        $this->expectException(DialogueLedgerTransitionViolation::class);
        try {
            $ledger->append([
                'event_type' => DialogueLedgerEvent::TYPE_ENQUEUED,
                'shape_hash' => 'shape-x',
                'cortex_snapshot_hash' => 'c1',
            ]);
        } catch (DialogueLedgerTransitionViolation $e) {
            $this->assertSame(DialogueLedgerEvent::TYPE_PROPOSED, $e->fromState);
            $this->assertSame(DialogueLedgerEvent::TYPE_ENQUEUED, $e->toState);
            throw $e;
        }
    }

    public function test_approved_with_null_operator_signature_is_refused(): void
    {
        $ledger = $this->ledger();
        $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_PROPOSED, 'shape_hash' => 'shape-q', 'cortex_snapshot_hash' => 'c1']);
        $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_REVIEWED, 'shape_hash' => 'shape-q', 'cortex_snapshot_hash' => 'c1']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/operator_signature/');
        $ledger->append([
            'event_type' => DialogueLedgerEvent::TYPE_APPROVED,
            'shape_hash' => 'shape-q',
            'cortex_snapshot_hash' => 'c1',
            'operator_signature' => null, // QUATERNITY invariant: must be non-null
        ]);
    }

    public function test_rejected_with_null_operator_signature_is_refused(): void
    {
        $ledger = $this->ledger();
        $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_PROPOSED, 'shape_hash' => 'shape-r', 'cortex_snapshot_hash' => 'c1']);
        $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_REVIEWED, 'shape_hash' => 'shape-r', 'cortex_snapshot_hash' => 'c1']);

        $this->expectException(\RuntimeException::class);
        $ledger->append([
            'event_type' => DialogueLedgerEvent::TYPE_REJECTED,
            'shape_hash' => 'shape-r',
            'cortex_snapshot_hash' => 'c1',
            'operator_signature' => '',
        ]);
    }

    public function test_append_only_reflection_no_update_or_delete_method(): void
    {
        $reflection = new \ReflectionClass(AtlasMaestroDialogueDrivenPacketLedger::class);
        $publicNames = array_map(static fn (\ReflectionMethod $m): string => strtolower($m->getName()), $reflection->getMethods(\ReflectionMethod::IS_PUBLIC));

        foreach (['update', 'delete', 'truncate', 'remove', 'edit', 'patch'] as $banned) {
            $this->assertNotContains($banned, $publicNames, "append-only invariant: must not expose public $banned()");
        }
        $this->assertContains('append', $publicNames);
        $this->assertContains('get', $publicNames);
        $this->assertContains('list', $publicNames);
        $this->assertContains('verifychain', $publicNames);
    }

    public function test_get_returns_appended_event_and_list_returns_them_in_order(): void
    {
        $ledger = $this->ledger();
        $a = $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_PROPOSED, 'shape_hash' => 's1', 'cortex_snapshot_hash' => 'c']);
        $b = $ledger->append(['event_type' => DialogueLedgerEvent::TYPE_REVIEWED, 'shape_hash' => 's1', 'cortex_snapshot_hash' => 'c']);

        $this->assertSame($a->ulid, $ledger->get($a->ulid)?->ulid);
        $list = $ledger->list();
        $this->assertSame([$a->ulid, $b->ulid], array_map(static fn (DialogueLedgerEvent $e): string => $e->ulid, $list));
    }
}
