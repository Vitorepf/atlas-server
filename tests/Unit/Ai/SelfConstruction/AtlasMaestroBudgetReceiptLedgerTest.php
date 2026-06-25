<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroBudgetReceiptLedger;
use Tests\TestCase;

class AtlasMaestroBudgetReceiptLedgerTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-budget-receipt-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function decision(array $override = []): array
    {
        return array_replace([
            'gate' => 'advise',
            'reason' => 'cycle_budget_exceeded',
            'window' => 'per_cycle_cents',
            'overage_cents' => 100,
        ], $override);
    }

    public function test_append_writes_a_well_formed_receipt(): void
    {
        $ledger = new AtlasMaestroBudgetReceiptLedger($this->ledgerPath);
        $r = $ledger->append('pk-1', 'cycle-A', $this->decision(), '2026-06-25T00:00:00Z');

        foreach (['task_packet_id', 'cycle_id', 'gate', 'reason', 'window', 'overage_cents', 'recorded_at', 'receipt_hash'] as $field) {
            self::assertArrayHasKey($field, $r);
        }
        self::assertSame('pk-1', $r['task_packet_id']);
        self::assertSame('cycle-A', $r['cycle_id']);
        self::assertSame('advise', $r['gate']);
        self::assertStringStartsWith('budget_receipt_', $r['receipt_hash']);
    }

    public function test_receipts_for_task_filters_by_task_packet_id(): void
    {
        $ledger = new AtlasMaestroBudgetReceiptLedger($this->ledgerPath);
        $ledger->append('pk-1', 'cycle-A', $this->decision(), '2026-06-25T00:00:00Z');
        $ledger->append('pk-2', 'cycle-A', $this->decision(), '2026-06-25T00:00:01Z');

        self::assertCount(1, $ledger->receiptsForTask('pk-1'));
        self::assertCount(1, $ledger->receiptsForTask('pk-2'));
    }

    public function test_receipts_for_cycle_filters_by_cycle_id(): void
    {
        $ledger = new AtlasMaestroBudgetReceiptLedger($this->ledgerPath);
        $ledger->append('pk-1', 'cycle-A', $this->decision(), '2026-06-25T00:00:00Z');
        $ledger->append('pk-2', 'cycle-B', $this->decision(), '2026-06-25T00:00:01Z');
        $ledger->append('pk-3', 'cycle-A', $this->decision(), '2026-06-25T00:00:02Z');

        $cycleA = $ledger->receiptsForCycle('cycle-A');
        self::assertCount(2, $cycleA);
        self::assertSame(['pk-1', 'pk-3'], array_column($cycleA, 'task_packet_id'));
    }

    public function test_append_preserves_prior_receipts_byte_identically(): void
    {
        $ledger = new AtlasMaestroBudgetReceiptLedger($this->ledgerPath);
        $ledger->append('pk-1', 'cycle-A', $this->decision(), '2026-06-25T00:00:00Z');
        $bytes = (string) file_get_contents($this->ledgerPath);

        $ledger->append('pk-2', 'cycle-A', $this->decision(), '2026-06-25T00:00:01Z');
        $bytesAfter = (string) file_get_contents($this->ledgerPath);

        self::assertSame($bytes, substr($bytesAfter, 0, strlen($bytes)));
    }

    public function test_receipt_hash_is_deterministic_for_identical_input(): void
    {
        $ledger = new AtlasMaestroBudgetReceiptLedger($this->ledgerPath);
        $a = $ledger->append('pk-1', 'cycle-A', $this->decision(), '2026-06-25T00:00:00Z');
        $b = $ledger->append('pk-1', 'cycle-A', $this->decision(), '2026-06-25T00:00:00Z');

        self::assertSame($a['receipt_hash'], $b['receipt_hash']);
    }
}
