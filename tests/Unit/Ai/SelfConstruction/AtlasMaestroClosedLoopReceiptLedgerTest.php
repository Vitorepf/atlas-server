<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\ClosedLoop\AtlasMaestroClosedLoopReceiptLedger;
use App\Services\Ai\SelfConstruction\Maestro\ClosedLoop\AtlasMaestroReplenisherFeedback;
use Tests\TestCase;

final class AtlasMaestroClosedLoopReceiptLedgerTest extends TestCase
{
    private string $receiptPath;

    private string $shapePath;

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = bin2hex(random_bytes(5));
        $this->receiptPath = sys_get_temp_dir()."/atlas-maestro-closed-loop-receipts-{$suffix}.jsonl";
        $this->shapePath = sys_get_temp_dir()."/atlas-maestro-closed-loop-shape-{$suffix}.jsonl";
        file_put_contents($this->shapePath, '{"task_packet_id":"packet-secret","payload":"must_not_leak"}'.PHP_EOL);
    }

    protected function tearDown(): void
    {
        foreach ([$this->receiptPath, $this->receiptPath.'.lock', $this->shapePath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_record_cycle_appends_provider_safe_receipt_without_raw_feedback_or_packet_payload(): void
    {
        $ledger = $this->ledger();

        $entry = $ledger->recordCycle([
            'feedback_block' => 'origin_kind=orphan: 14 delivered / 22 total',
            'mined_bucket_count' => 1,
            'guarded_pass_or_reject' => 'pass',
            'replenisher_consumed' => true,
            'flag_enabled' => true,
            'task_packet_id' => 'packet-secret',
        ]);

        $line = trim((string) file_get_contents($this->receiptPath));
        $row = json_decode($line, true);

        $this->assertSame($entry, $row);
        $this->assertArrayHasKey('ledger_snapshot_hash', $row);
        $this->assertArrayHasKey('feedback_block_sha256', $row);
        $this->assertSame(1, $row['mined_bucket_count']);
        $this->assertSame('pass', $row['guarded_pass_or_reject']);
        $this->assertTrue($row['flag_enabled']);
        $this->assertStringNotContainsString('origin_kind=orphan', $line);
        $this->assertStringNotContainsString('packet-secret', $line);
        $this->assertArrayNotHasKey('task_packet_id', $row);
    }

    public function test_replenisher_records_one_receipt_per_invocation_with_identical_hashes_for_identical_inputs(): void
    {
        config(['atlas.maestro.closed_loop.feedback_enabled' => true]);
        $feedback = new AtlasMaestroReplenisherFeedback($this->miner(), $this->ledger());

        $this->assertNotSame('', $feedback->renderFactsBlock());
        $this->assertNotSame('', $feedback->renderFactsBlock());

        $rows = iterator_to_array($this->ledger()->stream());
        $this->assertCount(2, $rows);
        $this->assertSame($rows[0]['ledger_snapshot_hash'], $rows[1]['ledger_snapshot_hash']);
        $this->assertSame($rows[0]['feedback_block_sha256'], $rows[1]['feedback_block_sha256']);
        $this->assertSame(['pass', 'pass'], array_column($rows, 'guarded_pass_or_reject'));
        $this->assertSame([true, true], array_column($rows, 'replenisher_consumed'));
    }

    public function test_replenisher_records_reject_receipt_when_flag_disabled(): void
    {
        config(['atlas.maestro.closed_loop.feedback_enabled' => false]);
        $feedback = new AtlasMaestroReplenisherFeedback($this->miner(), $this->ledger());

        $this->assertSame('', $feedback->renderFactsBlock());

        $rows = iterator_to_array($this->ledger()->stream());
        $this->assertCount(1, $rows);
        $this->assertSame('reject', $rows[0]['guarded_pass_or_reject']);
        $this->assertFalse($rows[0]['flag_enabled']);
        $this->assertFalse($rows[0]['replenisher_consumed']);
    }

    private function ledger(): AtlasMaestroClosedLoopReceiptLedger
    {
        return new AtlasMaestroClosedLoopReceiptLedger($this->receiptPath, $this->shapePath);
    }

    private function miner(): object
    {
        return new class
        {
            public function mine(): array
            {
                return [
                    'origin_kind' => [
                        'orphan' => [
                            'dimension' => 'origin_kind',
                            'bucket' => 'orphan',
                            'delivered' => 14,
                            'total' => 22,
                            'insufficient_support' => false,
                            'delivery_rate' => 14 / 22,
                        ],
                    ],
                ];
            }
        };
    }
}
