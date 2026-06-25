<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\ReentrySafety;

use App\Services\Ai\AutonomousEvolution\ReentrySafety\AtlasLoopCycleReentryReceiptLedger;
use Tests\TestCase;

final class AtlasLoopCycleReentryReceiptLedgerTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-reentry-ledger-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_record_appends_three_entries_atomically_with_valid_hashes(): void
    {
        $ledger = new AtlasLoopCycleReentryReceiptLedger($this->path);
        $a = $ledger->record('cyc-1', 'phase1', 'merge_to_main', 'base-A', 'FRESH');
        $b = $ledger->record('cyc-1', 'phase2', 'emit_receipt', 'rcpt-A', 'FRESH');
        $c = $ledger->record('cyc-1', 'phase3', 'claim_task', 'claim-A', 'ALREADY_DONE', priorRef: 'prior-1');

        $contents = (string) file_get_contents($this->path);
        $this->assertSame(3, substr_count($contents, "\n"));
        $rows = $ledger->allRows();
        $this->assertCount(3, $rows);
        $this->assertSame(64, strlen((string) $rows[0]['content_hash']));
        $this->assertSame(64, strlen((string) $rows[1]['content_hash']));
        $this->assertSame(64, strlen((string) $rows[2]['content_hash']));
        $this->assertSame(1, $rows[0]['monotonic_seq']);
        $this->assertSame(2, $rows[1]['monotonic_seq']);
        $this->assertSame(3, $rows[2]['monotonic_seq']);
        $this->assertSame('prior-1', $rows[2]['prior_ref']);
    }

    public function test_torn_tail_is_truncated_on_next_open_then_new_append_is_clean(): void
    {
        $ledger = new AtlasLoopCycleReentryReceiptLedger($this->path);
        $ledger->record('cyc-1', 'phase1', 'merge_to_main', 'base-A', 'FRESH');
        $ledger->record('cyc-1', 'phase2', 'emit_receipt', 'rcpt-A', 'FRESH');

        // Corrupt the tail entry: load lines, mutate facts of the last row but keep its old hash.
        $lines = file($this->path, FILE_IGNORE_NEW_LINES);
        $tail = json_decode($lines[count($lines) - 1], true);
        $tail['decision'] = 'TAMPERED'; // mutation invalidates content_hash
        $lines[count($lines) - 1] = (string) json_encode($tail, JSON_UNESCAPED_SLASHES);
        file_put_contents($this->path, implode("\n", $lines)."\n");

        // Re-open and append a new entry — the torn tail must be truncated before the append.
        $reopened = new AtlasLoopCycleReentryReceiptLedger($this->path);
        $reopened->record('cyc-1', 'phase3', 'claim_task', 'claim-A', 'FRESH');

        $rows = $reopened->allRows();
        $this->assertCount(2, $rows, 'torn tail must be removed before the new append');
        $this->assertSame('phase1', $rows[0]['phase']);
        $this->assertSame('phase3', $rows[1]['phase']);
        foreach ($rows as $row) {
            $this->assertNotSame('TAMPERED', $row['decision']);
        }
    }

    public function test_query_returns_chronologically_ordered_facts_for_cycle(): void
    {
        $ledger = new AtlasLoopCycleReentryReceiptLedger($this->path);
        $ledger->record('cyc-1', 'phase1', 'merge_to_main', 'base-A', 'FRESH', recordedAtUnix: 10);
        $ledger->record('cyc-2', 'phase1', 'merge_to_main', 'base-X', 'FRESH', recordedAtUnix: 11);
        $ledger->record('cyc-1', 'phase2', 'emit_receipt', 'rcpt-A', 'ALREADY_DONE', priorRef: 'r1', recordedAtUnix: 12);
        $ledger->record('cyc-1', 'phase3', 'claim_task', 'claim-A', 'TORN', recordedAtUnix: 13);
        $ledger->record('cyc-1', 'phase4', 'emit_receipt', 'rcpt-B', 'FRESH', recordedAtUnix: 14);

        $rows = $ledger->query('cyc-1');
        $this->assertCount(4, $rows);
        $this->assertSame(['phase1', 'phase2', 'phase3', 'phase4'], array_column($rows, 'phase'));
        $this->assertSame(['FRESH', 'ALREADY_DONE', 'TORN', 'FRESH'], array_column($rows, 'decision'));
        $this->assertSame([10, 12, 13, 14], array_column($rows, 'recorded_at_unix'));
    }
}
