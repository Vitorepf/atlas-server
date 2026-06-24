<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\MultiCycle;

use App\Services\Ai\AutonomousEvolution\MultiCycle\AtlasLoopMultiCycleReceiptLedger;
use PHPUnit\Framework\TestCase;

final class AtlasLoopMultiCycleReceiptLedgerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/atlas-multicycle-ledger-'.bin2hex(random_bytes(5));
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            exec('rm -rf '.escapeshellarg($this->tmpDir));
        }

        parent::tearDown();
    }

    public function test_record_appends_one_line_and_history_reads_rows_in_insertion_order(): void
    {
        $ledger = $this->ledger(
            ['r-1', 'r-2', 'r-3'],
            ['2026-06-24T08:00:00+00:00', '2026-06-24T08:01:00+00:00', '2026-06-24T08:02:00+00:00'],
        );

        $this->assertSame('r-1', $ledger->record('partition_emitted', 'cycle-a', 'scope-1', ['files' => ['a.php']]));
        $this->assertSame('r-2', $ledger->record('claim_granted', 'cycle-a', 'scope-1', ['holder' => 'cycle-a']));
        $this->assertSame('r-3', $ledger->record('release', 'cycle-a', 'scope-1', ['outcome' => 'done']));

        $lines = array_values(array_filter(array_map('trim', explode("\n", (string) file_get_contents($ledger->ledgerPath())))));
        $this->assertCount(3, $lines);

        $history = array_values(iterator_to_array($ledger->history()));
        $this->assertSame(['r-1', 'r-2', 'r-3'], array_column($history, 'receipt_id'));
        $this->assertSame(['partition_emitted', 'claim_granted', 'release'], array_column($history, 'event_type'));
    }

    public function test_history_is_tolerant_to_missing_deleted_rows_and_fresh_ids_never_collide(): void
    {
        $ledger = $this->ledger(
            ['r-1', 'r-2', 'r-3'],
            ['2026-06-24T08:00:00+00:00', '2026-06-24T08:01:00+00:00', '2026-06-24T08:02:00+00:00'],
        );

        $id1 = $ledger->record('claim_granted', 'cycle-a', 'scope-1', ['holder' => 'cycle-a']);
        $id2 = $ledger->record('claim_denied', 'cycle-b', 'scope-1', ['holder' => 'cycle-a']);
        $id3 = $ledger->record('release', 'cycle-a', 'scope-1', ['outcome' => 'abort']);

        $this->assertNotSame($id1, $id2);
        $this->assertNotSame($id2, $id3);

        $rows = array_values(array_filter(array_map('trim', explode("\n", (string) file_get_contents($ledger->ledgerPath())))));
        unset($rows[1]);
        file_put_contents($ledger->ledgerPath(), implode("\n", $rows)."\n");

        $history = array_values(iterator_to_array($ledger->history()));
        $this->assertCount(2, $history);
        $this->assertSame(['r-1', 'r-3'], array_column($history, 'receipt_id'));
    }

    /**
     * @param  list<string>  $receiptIds
     * @param  list<string>  $timestamps
     */
    private function ledger(array $receiptIds, array $timestamps): AtlasLoopMultiCycleReceiptLedger
    {
        $receiptIndex = 0;
        $timeIndex = 0;

        return new AtlasLoopMultiCycleReceiptLedger(
            ledgerPath: $this->tmpDir.'/receipts.ndjson',
            clock: function () use (&$timeIndex, $timestamps): string {
                $value = $timestamps[min($timeIndex, count($timestamps) - 1)];
                $timeIndex++;

                return $value;
            },
            receiptIdGenerator: function () use (&$receiptIndex, $receiptIds): string {
                $value = $receiptIds[min($receiptIndex, count($receiptIds) - 1)];
                $receiptIndex++;

                return $value;
            },
        );
    }
}
