<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Migration\Runner\AtlasLoopSchemaMigrationExecutionReceiptLedger;
use Tests\TestCase;

final class AtlasLoopSchemaMigrationExecutionReceiptLedgerTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-migration-receipts-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function ledger(): AtlasLoopSchemaMigrationExecutionReceiptLedger
    {
        return new AtlasLoopSchemaMigrationExecutionReceiptLedger($this->path);
    }

    private function receipt(array $overrides = []): array
    {
        return $overrides + [
            'receipt_id' => 'r-'.bin2hex(random_bytes(8)),
            'step_id' => 'step-1',
            'action' => 'apply',
            'checkpoint_id' => 'ck-1',
            'pre_sha256_manifest' => str_repeat('a', 64),
            'post_sha256_manifest' => str_repeat('b', 64),
            'started_at' => '2026-06-25T12:00:00Z',
            'finished_at' => '2026-06-25T12:00:01Z',
            'status' => 'ok',
        ];
    }

    public function test_append_and_tail_returns_receipts_in_order_with_non_decreasing_finished_at(): void
    {
        $ledger = $this->ledger();
        $a = $this->receipt(['receipt_id' => 'r-1', 'finished_at' => '2026-06-25T12:00:01Z']);
        $b = $this->receipt(['receipt_id' => 'r-2', 'finished_at' => '2026-06-25T12:00:02Z']);
        $c = $this->receipt(['receipt_id' => 'r-3', 'finished_at' => '2026-06-25T12:00:03Z']);
        $ledger->append($a);
        $ledger->append($b);
        $ledger->append($c);

        $tail = $ledger->tail(10);
        $this->assertCount(3, $tail);
        $this->assertSame('r-1', $tail[0]['receipt_id']);
        $this->assertSame('r-3', $tail[2]['receipt_id']);

        $prev = '';
        foreach ($tail as $row) {
            $this->assertGreaterThanOrEqual($prev, (string) $row['finished_at']);
            $prev = (string) $row['finished_at'];
        }
    }

    public function test_duplicate_receipt_id_appends_second_line_and_original_bytes_survive(): void
    {
        $ledger = $this->ledger();
        $r = $this->receipt(['receipt_id' => 'dup-1', 'status' => 'ok']);
        $ledger->append($r);
        $firstSnapshot = (string) file_get_contents($this->path);

        $r2 = $this->receipt(['receipt_id' => 'dup-1', 'status' => 'replay']);
        $ledger->append($r2);

        $secondSnapshot = (string) file_get_contents($this->path);
        // Original bytes survive untouched as a prefix.
        $this->assertStringStartsWith($firstSnapshot, $secondSnapshot);
        $this->assertCount(2, $ledger->all());
        // find() returns the FIRST entry (idempotency).
        $this->assertSame('ok', $ledger->find('dup-1')['status']);
    }

    public function test_for_step_filters_by_step_id(): void
    {
        $ledger = $this->ledger();
        $ledger->append($this->receipt(['receipt_id' => 'a', 'step_id' => 'step-A']));
        $ledger->append($this->receipt(['receipt_id' => 'b', 'step_id' => 'step-B']));
        $ledger->append($this->receipt(['receipt_id' => 'c', 'step_id' => 'step-A']));

        $rows = $ledger->forStep('step-A');
        $this->assertCount(2, $rows);
        $this->assertSame('a', $rows[0]['receipt_id']);
        $this->assertSame('c', $rows[1]['receipt_id']);
    }

    public function test_missing_required_field_throws(): void
    {
        $ledger = $this->ledger();
        $bad = $this->receipt();
        unset($bad['status']);

        $this->expectException(\InvalidArgumentException::class);
        $ledger->append($bad);
    }
}
