<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Coherence;

use App\Services\Ai\AutonomousEvolution\Coherence\AtlasLoopPostEditCoherenceReceiptLedger;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class AtlasLoopPostEditCoherenceReceiptLedgerTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-coh-ledger-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    public function test_history_returns_two_receipts_in_chronological_order(): void
    {
        $ledger = new AtlasLoopPostEditCoherenceReceiptLedger($this->ledgerPath);
        $a = $ledger->append('sha-1', 'edit-A', ['findings' => ['x']], ['orphans' => []], '2026-06-25T00:00:00Z');
        $b = $ledger->append('sha-2', 'edit-B', ['findings' => []], ['orphans' => ['y']], '2026-06-25T00:00:01Z');

        $history = $ledger->history(10);
        self::assertCount(2, $history);
        self::assertSame($a['receipt_id'], $history[0]['receipt_id']);
        self::assertSame($b['receipt_id'], $history[1]['receipt_id']);
    }

    public function test_latest_for_edit_set_returns_most_recent_matching(): void
    {
        $ledger = new AtlasLoopPostEditCoherenceReceiptLedger($this->ledgerPath);
        $ledger->append('sha-1', 'edit-A', [], [], '2026-06-25T00:00:00Z');
        $latest = $ledger->append('sha-2', 'edit-A', [], [], '2026-06-25T00:00:01Z');
        $ledger->append('sha-3', 'edit-B', [], [], '2026-06-25T00:00:02Z');

        $result = $ledger->latestForEditSet('edit-A');
        self::assertSame($latest['receipt_id'], $result['receipt_id']);
        self::assertNull($ledger->latestForEditSet('edit-NEVER'));
    }

    public function test_receipt_by_id_returns_exact_match(): void
    {
        $ledger = new AtlasLoopPostEditCoherenceReceiptLedger($this->ledgerPath);
        $r = $ledger->append('sha-1', 'edit-A', ['k' => 'v'], [], '2026-06-25T00:00:00Z');

        $found = $ledger->receiptById($r['receipt_id']);
        self::assertSame($r, $found);
        self::assertNull($ledger->receiptById('coh_never_existed'));
    }

    public function test_ledger_exposes_no_mutation_methods_by_reflection(): void
    {
        $reflection = new ReflectionClass(AtlasLoopPostEditCoherenceReceiptLedger::class);
        $publicNames = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->isConstructor()) {
                continue;
            }
            $publicNames[] = $m->getName();
        }
        sort($publicNames, SORT_STRING);
        self::assertSame(['append', 'history', 'latestForEditSet', 'receiptById'], $publicNames);
    }

    public function test_two_concurrent_appends_produce_two_well_formed_lines_without_interleaving(): void
    {
        $ledger = new AtlasLoopPostEditCoherenceReceiptLedger($this->ledgerPath);
        // Simulate concurrency by appending from two handles sequentially — the production code
        // uses flock so this is a contractual sanity check, not a true race test.
        $ledger->append('sha-1', 'edit-A', ['idx' => 1], [], '2026-06-25T00:00:00Z');
        $ledger->append('sha-2', 'edit-B', ['idx' => 2], [], '2026-06-25T00:00:01Z');

        $lines = file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(2, $lines);
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            self::assertIsArray($decoded);
            self::assertArrayHasKey('receipt_id', $decoded);
        }
    }
}
