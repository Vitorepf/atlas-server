<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;
use App\Services\Ai\AutonomousEvolution\Aael\Parallel\AtlasAaelParallelExecutionReceiptLedger;

final class AtlasAaelParallelExecutionReceiptLedgerHardeningTest extends TestCase
{
    protected function tearDown(): void
    {
        AtlasAaelParallelExecutionReceiptLedger::setRootForTesting(null);
        parent::tearDown();
    }

    /**
     * read must throw on an unreadable file instead of silently returning
     * an empty ledger (which would lose all receipts).
     */
    public function test_read_throws_on_unreadable_file(): void
    {
        $tmpDir = sys_get_temp_dir().'/aael-parallel-ledger-'.bin2hex(random_bytes(4));
        mkdir($tmpDir, 0o755, true);

        AtlasAaelParallelExecutionReceiptLedger::setRootForTesting($tmpDir);
        $ledger = new AtlasAaelParallelExecutionReceiptLedger();

        $day = '2026-07-03';
        // Write a valid receipt so the file exists.
        $ledger->append([
            'step_id' => 'step-1',
            'group_index' => 0,
            'write_set' => [],
            'lock_handle_id' => 'lock-1',
            'start_ts' => '2026-07-03T10:00:00Z',
            'end_ts' => '2026-07-03T10:01:00Z',
            'exit_status' => 0,
            'files_touched_sha256' => 'abc',
            'day' => $day,
        ]);

        $path = $tmpDir.'/'.$day.'.ndjson';
        $this->assertFileExists($path);

        // Make the file unreadable.
        chmod($path, 0o000);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('unable to read ledger file');

            $oldLevel = error_reporting(0);
            iterator_to_array($ledger->read($day));
            error_reporting($oldLevel);
        } finally {
            chmod($path, 0o644);
            @unlink($path);
            @rmdir($tmpDir);
        }
    }

    /**
     * Verify the source code has the fail-closed guard.
     */
    public function test_source_has_file_get_contents_guard(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/Aael/Parallel/AtlasAaelParallelExecutionReceiptLedger.php');

        $this->assertStringContainsString('RuntimeException', $source, 'read must throw RuntimeException on file read failure');
        $this->assertStringNotContainsString('(string) file_get_contents(', $source, 'read must not cast file_get_contents to string without checking');
    }

    /**
     * Normal readable file still works correctly.
     */
    public function test_read_returns_receipts_for_readable_file(): void
    {
        $tmpDir = sys_get_temp_dir().'/aael-parallel-ledger-'.bin2hex(random_bytes(4));
        mkdir($tmpDir, 0o755, true);

        AtlasAaelParallelExecutionReceiptLedger::setRootForTesting($tmpDir);
        $ledger = new AtlasAaelParallelExecutionReceiptLedger();
        $day = '2026-07-03';

        $ledger->append([
            'step_id' => 'step-1',
            'group_index' => 0,
            'write_set' => [],
            'lock_handle_id' => 'lock-1',
            'start_ts' => '2026-07-03T10:00:00Z',
            'end_ts' => '2026-07-03T10:01:00Z',
            'exit_status' => 0,
            'files_touched_sha256' => 'abc',
            'day' => $day,
        ]);
        $ledger->append([
            'step_id' => 'step-2',
            'group_index' => 1,
            'write_set' => [],
            'lock_handle_id' => 'lock-2',
            'start_ts' => '2026-07-03T10:00:00Z',
            'end_ts' => '2026-07-03T10:01:00Z',
            'exit_status' => 0,
            'files_touched_sha256' => 'def',
            'day' => $day,
        ]);

        $receipts = iterator_to_array($ledger->read($day));

        $this->assertCount(2, $receipts);
        $this->assertSame('step-1', $receipts[0]['step_id']);
        $this->assertSame('step-2', $receipts[1]['step_id']);

        @unlink($tmpDir.'/'.$day.'.ndjson');
        @rmdir($tmpDir);
    }
}
