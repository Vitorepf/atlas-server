<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Provenance;

use App\Services\Ai\SelfConstruction\Maestro\Provenance\AtlasMaestroPacketProvenanceReceiptLedger;
use App\Services\Ai\SelfConstruction\Maestro\Provenance\SequenceCollisionException;
use Tests\TestCase;

final class AtlasMaestroPacketProvenanceReceiptLedgerTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/maestro-prov-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_idempotent_append_on_same_packet_and_record_hash(): void
    {
        $ledger = new AtlasMaestroPacketProvenanceReceiptLedger($this->path);

        $a = $ledger->append('pkt-1', 0, 'hash-1', ['ok' => true], '2026-06-25T05:00:00+00:00');
        $b = $ledger->append('pkt-1', 0, 'hash-1', ['ok' => true], '2026-06-25T05:01:00+00:00');

        $this->assertSame($a['receipt_id'], $b['receipt_id']);
        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(1, $lines);
    }

    public function test_sequence_collision_throws_and_leaves_file_byte_identical(): void
    {
        $ledger = new AtlasMaestroPacketProvenanceReceiptLedger($this->path);
        $ledger->append('pkt-1', 0, 'hash-1', ['ok' => true], '2026-06-25T05:00:00+00:00');
        $hashBefore = sha1_file($this->path);

        try {
            $ledger->append('pkt-1', 0, 'hash-DIFFERENT', ['ok' => false], '2026-06-25T05:05:00+00:00');
            $this->fail('SequenceCollisionException expected');
        } catch (SequenceCollisionException) {
            // ok
        }

        $hashAfter = sha1_file($this->path);
        $this->assertSame($hashBefore, $hashAfter, 'file must be byte-identical after collision');
    }

    public function test_list_for_packet_returns_receipts_sorted_by_sequence_no(): void
    {
        $ledger = new AtlasMaestroPacketProvenanceReceiptLedger($this->path);
        $ledger->append('pkt-1', 2, 'h2', [], '2026-06-25T05:02:00+00:00');
        $ledger->append('pkt-1', 0, 'h0', [], '2026-06-25T05:00:00+00:00');
        $ledger->append('pkt-1', 1, 'h1', [], '2026-06-25T05:01:00+00:00');
        $ledger->append('pkt-other', 0, 'oh', [], '2026-06-25T05:05:00+00:00');

        $rows = $ledger->listForPacket('pkt-1');
        $seqs = array_column($rows, 'sequence_no');
        $this->assertSame([0, 1, 2], $seqs);
        $this->assertCount(3, $rows, 'must filter out other packets');
    }

    public function test_tail_returns_up_to_limit_in_descending_written_at_order(): void
    {
        $ledger = new AtlasMaestroPacketProvenanceReceiptLedger($this->path);
        $ledger->append('a', 0, 'h-a', [], '2026-06-25T05:00:00+00:00');
        $ledger->append('b', 0, 'h-b', [], '2026-06-25T05:05:00+00:00');
        $ledger->append('c', 0, 'h-c', [], '2026-06-25T05:10:00+00:00');

        $tail = $ledger->tail(2);
        $this->assertCount(2, $tail);
        $this->assertSame('c', $tail[0]['packet_id']);
        $this->assertSame('b', $tail[1]['packet_id']);
    }

    public function test_get_receipt_returns_null_for_unknown(): void
    {
        $ledger = new AtlasMaestroPacketProvenanceReceiptLedger($this->path);
        $ledger->append('a', 0, 'h-a', [], '2026-06-25T05:00:00+00:00');

        $this->assertNotNull($ledger->getReceipt(hash('sha256', 'a:0:h-a')));
        $this->assertNull($ledger->getReceipt('no-such-id'));
    }

    public function test_receipt_id_hash_is_stable_for_same_inputs(): void
    {
        $path2 = sys_get_temp_dir().'/maestro-prov2-'.bin2hex(random_bytes(6)).'.jsonl';
        $ledger1 = new AtlasMaestroPacketProvenanceReceiptLedger($this->path);
        $ledger2 = new AtlasMaestroPacketProvenanceReceiptLedger($path2);

        $a = $ledger1->append('pkt-stable', 0, 'hash-stable', [], '2026-06-25T06:00:00+00:00');
        $b = $ledger2->append('pkt-stable', 0, 'hash-stable', [], '2026-06-25T07:00:00+00:00');
        @unlink($path2);

        $this->assertSame($a['receipt_id'], $b['receipt_id']);
        $this->assertSame(64, strlen($a['receipt_id']));
    }

    public function test_for_family_filters_by_packet_id_prefix(): void
    {
        $ledger = new AtlasMaestroPacketProvenanceReceiptLedger($this->path);
        $ledger->append('atlas-task-1', 0, 'h1', [], '2026-06-25T05:00:00+00:00');
        $ledger->append('atlas-task-2', 0, 'h2', [], '2026-06-25T05:01:00+00:00');
        $ledger->append('other-task-1', 0, 'h3', [], '2026-06-25T05:02:00+00:00');

        $this->assertCount(2, $ledger->forFamily('atlas-task'));
        $this->assertCount(1, $ledger->forFamily('other-task'));
        $this->assertCount(0, $ledger->forFamily('nonexistent'));
    }

    public function test_for_source_filters_by_verdict_source_field(): void
    {
        $ledger = new AtlasMaestroPacketProvenanceReceiptLedger($this->path);
        $ledger->append('pkt-a', 0, 'ha', ['source' => 'autopoiesis'], '2026-06-25T05:00:00+00:00');
        $ledger->append('pkt-b', 0, 'hb', ['source' => 'task_fabric'], '2026-06-25T05:01:00+00:00');
        $ledger->append('pkt-c', 0, 'hc', ['source' => 'autopoiesis'], '2026-06-25T05:02:00+00:00');

        $this->assertCount(2, $ledger->forSource('autopoiesis'));
        $this->assertCount(1, $ledger->forSource('task_fabric'));
        $this->assertCount(0, $ledger->forSource('unknown'));
    }
}
