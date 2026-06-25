<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Aael\Parallel\AtlasAaelParallelExecutionReceiptLedger;
use Tests\TestCase;

final class AtlasAaelParallelExecutionReceiptLedgerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-aael-receipts-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
        AtlasAaelParallelExecutionReceiptLedger::setRootForTesting($this->root);
    }

    protected function tearDown(): void
    {
        AtlasAaelParallelExecutionReceiptLedger::setRootForTesting(null);
        foreach ((array) glob($this->root.'/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    private function receipt(array $overrides = []): array
    {
        return $overrides + [
            'step_id' => 'step-1',
            'group_index' => 1,
            'write_set' => ['app/Foo.php'],
            'lock_handle_id' => 'lh-1',
            'start_ts' => 1000,
            'end_ts' => 1100,
            'exit_status' => 'ok',
            'files_touched_sha256' => str_repeat('a', 64),
            'day' => '2026-06-25',
        ];
    }

    public function test_append_writes_line_and_returns_deterministic_receipt_id(): void
    {
        $ledger = new AtlasAaelParallelExecutionReceiptLedger();
        $id1 = $ledger->append($this->receipt());
        $id2 = (new AtlasAaelParallelExecutionReceiptLedger())->receiptId([
            'step_id' => 'step-1',
            'group_index' => 1,
            'write_set' => ['app/Foo.php'],
            'lock_handle_id' => 'lh-1',
            'start_ts' => 1000,
            'end_ts' => 1100,
            'exit_status' => 'ok',
            'files_touched_sha256' => str_repeat('a', 64),
        ]);
        $this->assertSame($id1, $id2);
        $this->assertSame(64, strlen($id1));
    }

    public function test_read_returns_appended_receipts_in_order(): void
    {
        $ledger = new AtlasAaelParallelExecutionReceiptLedger();
        $ledger->append($this->receipt(['step_id' => 'a']));
        $ledger->append($this->receipt(['step_id' => 'b']));
        $ledger->append($this->receipt(['step_id' => 'c']));

        $rows = iterator_to_array($ledger->read('2026-06-25'));
        $this->assertCount(3, $rows);
        $this->assertSame(['a', 'b', 'c'], array_column($rows, 'step_id'));
    }

    public function test_partial_trailing_line_is_quarantined_not_silently_truncated(): void
    {
        $ledger = new AtlasAaelParallelExecutionReceiptLedger();
        $ledger->append($this->receipt(['step_id' => 'good']));

        // Simulate crash mid-write: append a partial line WITHOUT newline.
        $path = $ledger->pathForDay('2026-06-25');
        file_put_contents($path, '{"step_id":"partial","group_index":1,"start_ts"', FILE_APPEND);

        $rows = iterator_to_array($ledger->read('2026-06-25'));
        $stepIds = array_column($rows, 'step_id');
        $this->assertContains('good', $stepIds);
        $this->assertNotContains('partial', $stepIds);

        $this->assertFileExists($path.'.partial', 'partial line must be quarantined to .partial sidecar');
    }

    public function test_append_is_append_only_does_not_rewrite_prior_lines(): void
    {
        $ledger = new AtlasAaelParallelExecutionReceiptLedger();
        $ledger->append($this->receipt(['step_id' => 'first']));
        $firstSnapshot = (string) file_get_contents($ledger->pathForDay('2026-06-25'));
        $ledger->append($this->receipt(['step_id' => 'second']));
        $secondSnapshot = (string) file_get_contents($ledger->pathForDay('2026-06-25'));

        $this->assertStringStartsWith($firstSnapshot, $secondSnapshot);
    }
}
