<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\PatternEmergence\AtlasLoopCrossCyclePatternReceiptLedger;
use App\Services\Ai\AutonomousEvolution\PatternEmergence\CrossCyclePatternIntegrityException;
use Tests\TestCase;

class AtlasLoopCrossCyclePatternReceiptLedgerTest extends TestCase
{
    private string $ndjsonPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ndjsonPath = sys_get_temp_dir().'/atlas-cross-cycle-receipts-'.bin2hex(random_bytes(6)).'.ndjson';
    }

    protected function tearDown(): void
    {
        @unlink($this->ndjsonPath);
        parent::tearDown();
    }

    public function test_record_appends_one_ndjson_line_with_all_required_fields(): void
    {
        $ledger = new AtlasLoopCrossCyclePatternReceiptLedger($this->ndjsonPath);
        $receipt = $ledger->record(10, 3,
            ['cycle_ids' => ['c1', 'c2', 'c3'], 'patterns' => ['p1']],
            ['cycle_ids' => ['c1', 'c2', 'c3'], 'stability' => 0.9],
            '2026-06-25T00:00:00Z',
        );

        foreach (['n', 'k', 'miner_payload_sha256', 'stability_payload_sha256', 'recorded_at_utc', 'cycle_ids'] as $field) {
            self::assertArrayHasKey($field, $receipt);
        }
        self::assertSame(10, $receipt['n']);
        self::assertSame(3, $receipt['k']);
        self::assertSame(['c1', 'c2', 'c3'], $receipt['cycle_ids']);

        $lines = file($this->ndjsonPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1, $lines);
    }

    public function test_two_records_produce_two_distinct_lines_and_never_rewrite_prior_line(): void
    {
        $ledger = new AtlasLoopCrossCyclePatternReceiptLedger($this->ndjsonPath);
        $ledger->record(5, 2, ['cycle_ids' => ['c1', 'c2']], ['cycle_ids' => ['c1', 'c2']], '2026-06-25T00:00:00Z');
        $bytes = (string) file_get_contents($this->ndjsonPath);

        $ledger->record(7, 3, ['cycle_ids' => ['c3', 'c4']], ['cycle_ids' => ['c3', 'c4']], '2026-06-25T00:00:01Z');
        $bytesAfter = (string) file_get_contents($this->ndjsonPath);

        self::assertSame($bytes, substr($bytesAfter, 0, strlen($bytes)), 'prior bytes must not be rewritten');
        $lines = file($this->ndjsonPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(2, $lines);
        $a = json_decode($lines[0], true);
        $b = json_decode($lines[1], true);
        self::assertNotSame($a['miner_payload_sha256'], $b['miner_payload_sha256']);
    }

    public function test_mismatched_cycle_ids_throws_integrity_exception_and_writes_nothing(): void
    {
        $ledger = new AtlasLoopCrossCyclePatternReceiptLedger($this->ndjsonPath);
        $threw = false;
        try {
            $ledger->record(
                10, 3,
                ['cycle_ids' => ['c1', 'c2', 'c3']],
                ['cycle_ids' => ['c1', 'c2']], // mismatched
            );
        } catch (CrossCyclePatternIntegrityException) {
            $threw = true;
        }

        self::assertTrue($threw, 'integrity exception must be thrown');
        self::assertFileDoesNotExist($this->ndjsonPath, 'no line must be written on integrity failure');
    }

    public function test_cycle_id_order_does_not_change_integrity_check(): void
    {
        $ledger = new AtlasLoopCrossCyclePatternReceiptLedger($this->ndjsonPath);
        $receipt = $ledger->record(
            10, 3,
            ['cycle_ids' => ['c3', 'c1', 'c2']],
            ['cycle_ids' => ['c1', 'c2', 'c3']],
            '2026-06-25T00:00:00Z',
        );

        self::assertSame(['c1', 'c2', 'c3'], $receipt['cycle_ids']);
    }

    public function test_read_all_returns_persisted_receipts(): void
    {
        $ledger = new AtlasLoopCrossCyclePatternReceiptLedger($this->ndjsonPath);
        $ledger->record(5, 2, ['cycle_ids' => ['a']], ['cycle_ids' => ['a']], '2026-06-25T00:00:00Z');
        $ledger->record(6, 3, ['cycle_ids' => ['b']], ['cycle_ids' => ['b']], '2026-06-25T00:00:01Z');

        $rows = $ledger->readAll();
        self::assertCount(2, $rows);
    }
}
