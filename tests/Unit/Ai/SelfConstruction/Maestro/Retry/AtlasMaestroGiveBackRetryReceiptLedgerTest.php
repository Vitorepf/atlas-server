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
}
