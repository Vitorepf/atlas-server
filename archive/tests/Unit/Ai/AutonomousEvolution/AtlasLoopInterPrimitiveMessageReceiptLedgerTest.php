<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\WireFormat\AtlasLoopInterPrimitiveMessageReceiptLedger;
use RuntimeException;
use Tests\TestCase;

final class AtlasLoopInterPrimitiveMessageReceiptLedgerTest extends TestCase
{
    private function receipt(array $overrides = []): array
    {
        return $overrides + [
            'source' => 'cortex',
            'target' => 'replenisher',
            'schema_id' => 'atlas.wire.scope_comprehended.v1',
            'payload_hash' => str_repeat('a', 64),
            'validation_ok' => true,
            'observed_at' => 1700000000,
        ];
    }

    public function test_append_records_a_well_formed_receipt(): void
    {
        $ledger = new AtlasLoopInterPrimitiveMessageReceiptLedger();
        $row = $ledger->append($this->receipt());

        $this->assertSame('cortex', $row['source']);
        $this->assertSame('replenisher', $row['target']);
        $this->assertSame('atlas.wire.scope_comprehended.v1', $row['schema_id']);
        $this->assertTrue($row['validation_ok']);
        $this->assertSame(64, strlen((string) $row['receipt_hash']));
    }

    public function test_history_filters_by_source_and_schema_id_newest_first(): void
    {
        $ledger = new AtlasLoopInterPrimitiveMessageReceiptLedger();
        $ledger->append($this->receipt(['source' => 'cortex', 'schema_id' => 'A', 'observed_at' => 1]));
        $ledger->append($this->receipt(['source' => 'replenisher', 'schema_id' => 'A', 'observed_at' => 2]));
        $ledger->append($this->receipt(['source' => 'cortex', 'schema_id' => 'B', 'observed_at' => 3]));
        $ledger->append($this->receipt(['source' => 'cortex', 'schema_id' => 'A', 'observed_at' => 4]));

        $rows = $ledger->history(['source' => 'cortex']);
        $this->assertCount(3, $rows);
        $this->assertSame(4, $rows[0]['observed_at']);
        $this->assertSame(3, $rows[1]['observed_at']);
        $this->assertSame(1, $rows[2]['observed_at']);

        $rowsA = $ledger->history(['source' => 'cortex', 'schema_id' => 'A']);
        $this->assertCount(2, $rowsA);
        $this->assertSame(4, $rowsA[0]['observed_at']);
        $this->assertSame(1, $rowsA[1]['observed_at']);
    }

    public function test_history_respects_limit(): void
    {
        $ledger = new AtlasLoopInterPrimitiveMessageReceiptLedger();
        for ($i = 1; $i <= 5; $i++) {
            $ledger->append($this->receipt(['observed_at' => $i, 'payload_hash' => str_repeat((string) $i, 64)]));
        }

        $rows = $ledger->history([], limit: 2);
        $this->assertCount(2, $rows);
        $this->assertSame(5, $rows[0]['observed_at']);
        $this->assertSame(4, $rows[1]['observed_at']);
    }

    public function test_append_does_not_mutate_prior_rows(): void
    {
        $ledger = new AtlasLoopInterPrimitiveMessageReceiptLedger();
        $first = $ledger->append($this->receipt(['observed_at' => 1]));
        $firstHash = $first['receipt_hash'];

        $ledger->append($this->receipt(['observed_at' => 2, 'payload_hash' => str_repeat('b', 64)]));
        $all = $ledger->history();

        $firstRow = end($all);
        $this->assertSame($firstHash, $firstRow['receipt_hash'], 'prior row hash must not change after later append');
    }

    public function test_malformed_receipt_is_rejected(): void
    {
        $ledger = new AtlasLoopInterPrimitiveMessageReceiptLedger();

        $this->expectException(RuntimeException::class);
        $ledger->append(['source' => 'cortex']);
    }

    public function test_receipt_hash_is_byte_stable_for_identical_bodies(): void
    {
        $a = new AtlasLoopInterPrimitiveMessageReceiptLedger();
        $b = new AtlasLoopInterPrimitiveMessageReceiptLedger();
        $rowA = $a->append($this->receipt());
        $rowB = $b->append($this->receipt());

        $this->assertSame($rowA['receipt_hash'], $rowB['receipt_hash']);
    }
}
