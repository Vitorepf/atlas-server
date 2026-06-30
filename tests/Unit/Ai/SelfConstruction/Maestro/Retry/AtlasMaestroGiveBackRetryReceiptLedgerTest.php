<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Retry;

use App\Services\Ai\SelfConstruction\Maestro\Retry\AtlasMaestroGiveBackRetryReceiptLedger;
use Tests\TestCase;

final class AtlasMaestroGiveBackRetryReceiptLedgerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-retry-receipts-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
        AtlasMaestroGiveBackRetryReceiptLedger::setRootForTesting($this->root);
    }

    protected function tearDown(): void
    {
        AtlasMaestroGiveBackRetryReceiptLedger::setRootForTesting(null);
        foreach ((array) glob($this->root.'/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    private function facts(array $overrides = []): array
    {
        return $overrides + [
            'task_packet_id' => 'pkt-1',
            'attempt_index' => 1,
            'reshape_fingerprint' => 'fp-1',
            'original_allowed_files' => ['app/Foo.php'],
            'reshaped_allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'decision' => 'allow',
            'policy_reason' => 'ok',
            'give_back_evidence_hash' => str_repeat('a', 64),
        ];
    }

    public function test_receipt_id_is_deterministic_for_canonical_fields(): void
    {
        $ledger = new AtlasMaestroGiveBackRetryReceiptLedger();
        $a = $ledger->append($this->facts());
        $b = $ledger->append($this->facts());
        $this->assertSame($a->receiptId, $b->receiptId);

        $different = $ledger->append($this->facts(['decision' => 'deny']));
        $this->assertNotSame($a->receiptId, $different->receiptId);
    }

    public function test_ledger_is_append_only_and_query_preserves_order(): void
    {
        $ledger = new AtlasMaestroGiveBackRetryReceiptLedger();
        $first = $ledger->append($this->facts(['attempt_index' => 1]));
        $second = $ledger->append($this->facts(['attempt_index' => 2, 'reshape_fingerprint' => 'fp-2']));

        $rows = $ledger->forTask('pkt-1');
        $this->assertCount(2, $rows);
        $this->assertSame($first->receiptId, $rows[0]['receipt_id']);
        $this->assertSame($second->receiptId, $rows[1]['receipt_id']);
        $this->assertSame(1, $rows[0]['seq']);
        $this->assertSame(2, $rows[1]['seq']);

        // Append a third — prior rows must be unchanged.
        $ledger->append($this->facts(['attempt_index' => 3, 'reshape_fingerprint' => 'fp-3']));
        $rowsAfter = $ledger->forTask('pkt-1');
        $this->assertSame($rows[0]['receipt_id'], $rowsAfter[0]['receipt_id']);
        $this->assertSame($rows[1]['receipt_id'], $rowsAfter[1]['receipt_id']);
    }

    public function test_for_task_returns_empty_array_for_unknown_packet(): void
    {
        $ledger = new AtlasMaestroGiveBackRetryReceiptLedger();
        $this->assertSame([], $ledger->forTask('pkt-unknown'));
    }

    public function test_family_aggregation_collects_receipts_across_matching_task_ids(): void
    {
        $ledger = new AtlasMaestroGiveBackRetryReceiptLedger();
        $ledger->append($this->facts(['task_packet_id' => 'alpha-task-1']));
        $ledger->append($this->facts(['task_packet_id' => 'alpha-task-2', 'reshape_fingerprint' => 'fp-2']));
        $ledger->append($this->facts(['task_packet_id' => 'beta-task-1', 'reshape_fingerprint' => 'fp-3']));

        $this->assertCount(2, $ledger->forFamily('alpha'));
        $this->assertCount(1, $ledger->forFamily('beta'));
        $this->assertCount(0, $ledger->forFamily('gamma'));
    }

    public function test_quarantine_threshold_triggers_at_constant_n_receipts(): void
    {
        $ledger = new AtlasMaestroGiveBackRetryReceiptLedger();
        $threshold = AtlasMaestroGiveBackRetryReceiptLedger::QUARANTINE_THRESHOLD;

        for ($i = 1; $i < $threshold; $i++) {
            $ledger->append($this->facts(['attempt_index' => $i, 'reshape_fingerprint' => "fp-{$i}"]));
            $this->assertFalse($ledger->isQuarantined('pkt-1'));
        }

        $ledger->append($this->facts(['attempt_index' => $threshold, 'reshape_fingerprint' => 'fp-threshold']));
        $this->assertTrue($ledger->isQuarantined('pkt-1'));
    }

    public function test_duplicate_receipt_suppression_prevents_double_row(): void
    {
        $ledger = new AtlasMaestroGiveBackRetryReceiptLedger();
        $ledger->append($this->facts());
        $ledger->append($this->facts()); // identical canonical → same receipt_id

        $this->assertCount(1, $ledger->forTask('pkt-1'));
    }

    public function test_bounded_export_limits_returned_rows(): void
    {
        $ledger = new AtlasMaestroGiveBackRetryReceiptLedger();
        for ($i = 1; $i <= 5; $i++) {
            $ledger->append($this->facts(['attempt_index' => $i, 'reshape_fingerprint' => "fp-{$i}"]));
        }

        $this->assertCount(3, $ledger->export('pkt-1', 3));
        $this->assertCount(5, $ledger->forTask('pkt-1'));
    }
}
