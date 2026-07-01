<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Maestro\Cost;

use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroBudgetReceiptLedger;
use Tests\TestCase;

/**
 * Budget gate decisions become immutable receipts the brain can learn from: empty
 * task_packet_id and missing decision.gate throw, append preserves task/cycle/gate/reason/
 * window/overage, duplicate receipt_hash is not appended twice, receiptsForTask/receiptsForCycle
 * filter correctly, and append-order is preserved.
 */
final class AtlasMaestroBudgetReceiptLedgerTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-budget-receipt-declared-'.bin2hex(random_bytes(6)).'.jsonl';
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

    public function test_append_throws_for_empty_task_packet_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AtlasMaestroBudgetReceiptLedger($this->ledgerPath))->append('', 'cycle-A', $this->decision());
    }

    public function test_append_throws_for_missing_decision_gate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AtlasMaestroBudgetReceiptLedger($this->ledgerPath))->append('pk-1', 'cycle-A', $this->decision(['gate' => '']));
    }

    public function test_append_preserves_task_cycle_gate_reason_window_and_overage(): void
    {
        $ledger = new AtlasMaestroBudgetReceiptLedger($this->ledgerPath);
        $r = $ledger->append('pk-1', 'cycle-A', $this->decision(), '2026-06-25T00:00:00Z');

        self::assertSame('pk-1', $r['task_packet_id']);
        self::assertSame('cycle-A', $r['cycle_id']);
        self::assertSame('advise', $r['gate']);
        self::assertSame('cycle_budget_exceeded', $r['reason']);
        self::assertSame('per_cycle_cents', $r['window']);
        self::assertSame(100, $r['overage_cents']);
    }

    public function test_duplicate_receipt_hash_is_not_appended_twice(): void
    {
        $ledger = new AtlasMaestroBudgetReceiptLedger($this->ledgerPath);
        $ledger->append('pk-1', 'cycle-A', $this->decision(), '2026-06-25T00:00:00Z');
        $ledger->append('pk-1', 'cycle-A', $this->decision(), '2026-06-25T00:00:00Z');

        self::assertCount(1, $ledger->all());
    }

    public function test_receipt_hash_is_deterministic_for_identical_input(): void
    {
        $ledger = new AtlasMaestroBudgetReceiptLedger($this->ledgerPath);
        $a = $ledger->append('pk-1', 'cycle-A', $this->decision(), '2026-06-25T00:00:00Z');
        $b = $ledger->append('pk-1', 'cycle-A', $this->decision(), '2026-06-25T00:00:00Z');

        self::assertSame($a['receipt_hash'], $b['receipt_hash']);
    }

    public function test_receipts_for_task_filters_correctly(): void
    {
        $ledger = new AtlasMaestroBudgetReceiptLedger($this->ledgerPath);
        $ledger->append('pk-1', 'cycle-A', $this->decision(), '2026-06-25T00:00:00Z');
        $ledger->append('pk-2', 'cycle-A', $this->decision(), '2026-06-25T00:00:01Z');

        self::assertCount(1, $ledger->receiptsForTask('pk-1'));
        self::assertCount(1, $ledger->receiptsForTask('pk-2'));
    }

    public function test_receipts_for_cycle_filters_correctly(): void
    {
        $ledger = new AtlasMaestroBudgetReceiptLedger($this->ledgerPath);
        $ledger->append('pk-1', 'cycle-A', $this->decision(), '2026-06-25T00:00:00Z');
        $ledger->append('pk-2', 'cycle-B', $this->decision(), '2026-06-25T00:00:01Z');
        $ledger->append('pk-3', 'cycle-A', $this->decision(), '2026-06-25T00:00:02Z');

        self::assertSame(['pk-1', 'pk-3'], array_column($ledger->receiptsForCycle('cycle-A'), 'task_packet_id'));
    }

    public function test_append_order_is_preserved(): void
    {
        $ledger = new AtlasMaestroBudgetReceiptLedger($this->ledgerPath);
        $ledger->append('pk-3', 'cycle-A', $this->decision(), '2026-06-25T00:00:02Z');
        $ledger->append('pk-1', 'cycle-A', $this->decision(), '2026-06-25T00:00:00Z');
        $ledger->append('pk-2', 'cycle-A', $this->decision(), '2026-06-25T00:00:01Z');

        self::assertSame(['pk-3', 'pk-1', 'pk-2'], array_column($ledger->all(), 'task_packet_id'));
    }
}
