<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\LiveCycle\Nesting\AtlasLoopSubCycleReceiptLedger;
use Tests\TestCase;

final class AtlasLoopSubCycleReceiptLedgerTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-subcycle-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_spawn_merge_close_produce_monotonic_seq_in_chain_order(): void
    {
        $ledger = new AtlasLoopSubCycleReceiptLedger($this->path);
        $a = $ledger->append('SPAWN', 'parent-1', 'child-A', 1, '2026-06-25T00:00:00Z');
        $b = $ledger->append('MERGE', 'parent-1', 'child-A', 1, '2026-06-25T00:00:01Z');
        $c = $ledger->append('CLOSE', 'parent-1', 'child-A', 1, '2026-06-25T00:00:02Z');

        $this->assertSame(1, $a['seq']);
        $this->assertSame(2, $b['seq']);
        $this->assertSame(3, $c['seq']);

        $chain = $ledger->chain('parent-1');
        $this->assertCount(3, $chain);
        $this->assertSame(['SPAWN', 'MERGE', 'CLOSE'], array_column($chain, 'event_type'));
        $this->assertSame([1, 2, 3], array_column($chain, 'seq'));
    }

    public function test_two_independent_instances_reading_same_file_return_identical_chains(): void
    {
        $writer = new AtlasLoopSubCycleReceiptLedger($this->path);
        $writer->append('SPAWN', 'p', 'c1', 1, '2026-06-25T00:00:00Z');
        $writer->append('MERGE', 'p', 'c1', 1, '2026-06-25T00:00:01Z');

        $readerA = new AtlasLoopSubCycleReceiptLedger($this->path);
        $readerB = new AtlasLoopSubCycleReceiptLedger($this->path);
        $this->assertSame($readerA->chain('p'), $readerB->chain('p'));
    }

    public function test_negative_depth_and_empty_child_id_return_rejection_and_do_not_write(): void
    {
        $ledger = new AtlasLoopSubCycleReceiptLedger($this->path);

        $r1 = $ledger->append('SPAWN', 'p', '', 1, '2026-06-25T00:00:00Z');
        $this->assertSame('rejected', $r1['outcome']);
        $this->assertSame('empty_child_cycle_id', $r1['reason']);

        $r2 = $ledger->append('SPAWN', 'p', 'c', -1, '2026-06-25T00:00:00Z');
        $this->assertSame('rejected', $r2['outcome']);
        $this->assertSame('negative_depth', $r2['reason']);

        $this->assertFileDoesNotExist($this->path, 'rejected appends must not create or grow the ledger file');
    }

    public function test_rejection_after_valid_appends_does_not_mutate_existing_lines(): void
    {
        $ledger = new AtlasLoopSubCycleReceiptLedger($this->path);
        $ledger->append('SPAWN', 'p', 'c', 0, '2026-06-25T00:00:00Z');
        $before = (string) file_get_contents($this->path);

        $bad = $ledger->append('SPAWN', 'p', '', 0, '2026-06-25T00:00:01Z');
        $this->assertSame('rejected', $bad['outcome']);

        $after = (string) file_get_contents($this->path);
        $this->assertSame($before, $after, 'rejection must leave the file byte-identical');
    }

    public function test_chain_filters_by_parent_cycle_id(): void
    {
        $ledger = new AtlasLoopSubCycleReceiptLedger($this->path);
        $ledger->append('SPAWN', 'p1', 'c1', 1, '2026-06-25T00:00:00Z');
        $ledger->append('SPAWN', 'p2', 'c2', 1, '2026-06-25T00:00:01Z');
        $ledger->append('MERGE', 'p1', 'c1', 1, '2026-06-25T00:00:02Z');

        $this->assertCount(2, $ledger->chain('p1'));
        $this->assertCount(1, $ledger->chain('p2'));
        $this->assertSame([], $ledger->chain('p-missing'));
    }
}
