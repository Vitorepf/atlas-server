<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\Rollback\AtlasAaelExecutionRollbackReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\Rollback\AtlasAaelRollbackReceiptSchemaViolation;
use Tests\TestCase;

final class AtlasAaelExecutionRollbackReceiptLedgerTest extends TestCase
{
    private string $path;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/aael-rollback-ledger-'.bin2hex(random_bytes(4));
        @mkdir($this->dir, 0777, true);
        $this->path = $this->dir.'/ledger.jsonl';
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir.'/*') as $path) {
            @unlink((string) $path);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_append_links_three_receipts_with_genesis_and_verifiable_chain(): void
    {
        $ledger = $this->ledger();

        $first = $ledger->append($this->receipt('exec-1', 'post_verify_failed'));
        $second = $ledger->append($this->receipt('exec-2', 'operator_request'));
        $third = $ledger->append($this->receipt('exec-3', 'partial_restore'));

        $this->assertSame('GENESIS', $first['prev_hash']);
        $this->assertSame($first['receipt_hash'], $second['prev_hash']);
        $this->assertSame($second['receipt_hash'], $third['prev_hash']);

        $verification = $ledger->verifyChain();
        $this->assertTrue($verification['ok']);
        $this->assertNull($verification['bad_line']);
        $this->assertSame($third['receipt_hash'], $verification['head_hash']);
    }

    public function test_append_rejects_missing_or_invalid_required_fields_without_writing(): void
    {
        $ledger = $this->ledger();

        foreach ([
            ['execution_id' => ''],
            ['manifest_sha' => 'nope'],
            ['trigger_reason' => 'unknown'],
        ] as $override) {
            try {
                $ledger->append($this->receipt('exec-invalid', 'post_verify_failed', $override));
                $this->fail('Expected schema violation.');
            } catch (AtlasAaelRollbackReceiptSchemaViolation) {
                $contents = is_file($this->path) ? (string) file_get_contents($this->path) : '';
                $this->assertSame('', $contents);
            }
        }
    }

    public function test_list_filters_by_execution_id_in_append_order_with_bounded_memory_growth(): void
    {
        $ledger = $this->ledger();

        for ($i = 0; $i < 50; $i++) {
            $ledger->append($this->receipt(
                $i % 2 === 0 ? 'exec-even' : 'exec-odd',
                'post_verify_failed',
                ['evidence_ids' => [str_repeat((string) ($i % 10), 256)]]
            ));
        }

        gc_collect_cycles();
        $before = memory_get_usage(true);
        $rows = iterator_to_array($ledger->list('exec-even', 100), false);
        gc_collect_cycles();
        $after = memory_get_usage(true);

        $this->assertCount(25, $rows);
        $this->assertSame('exec-even', $rows[0]['execution_id']);
        $this->assertSame('exec-even', $rows[24]['execution_id']);
        $this->assertLessThan(2 * 1024 * 1024, $after - $before);
    }

    public function test_truncated_last_line_is_reported_and_first_append_quarantines_before_refusing(): void
    {
        $ledger = $this->ledger();
        $ledger->append($this->receipt('exec-1', 'post_verify_failed'));
        file_put_contents($this->path, '{"partial":', FILE_APPEND);

        $verification = $ledger->verifyChain();
        $this->assertFalse($verification['ok']);
        $this->assertSame(2, $verification['bad_line']);

        try {
            $ledger->append($this->receipt('exec-2', 'operator_request'));
            $this->fail('Expected corrupt-ledger refusal.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('aael_rollback_receipt_ledger_corrupt_quarantined', $exception->getMessage());
            $this->assertFileDoesNotExist($this->path);
            $this->assertFileExists($this->path.'.quarantine');
        }
    }

    private function ledger(): AtlasAaelExecutionRollbackReceiptLedger
    {
        return new AtlasAaelExecutionRollbackReceiptLedger($this->path);
    }

    /**
     * @param  array<string,mixed>  $override
     * @return array<string,mixed>
     */
    private function receipt(string $executionId, string $triggerReason, array $override = []): array
    {
        return array_replace_recursive([
            'schema_version' => AtlasAaelExecutionRollbackReceiptLedger::SCHEMA,
            'execution_id' => $executionId,
            'triggered_at_utc' => '2026-06-24T07:00:00Z',
            'trigger_reason' => $triggerReason,
            'manifest_sha' => str_repeat('a', 64),
            'target_count' => 2,
            'restored_count' => 1,
            'skipped_count' => 1,
            'status' => 'restored',
            'per_target' => [
                [
                    'path' => 'app/Services/Ai/AutonomousEvolution/Foo.php',
                    'pre_sha' => str_repeat('b', 64),
                    'post_sha' => str_repeat('c', 64),
                    'action' => 'restored',
                ],
                [
                    'path' => 'app/Services/Ai/SelfConstruction/Bar.php',
                    'pre_sha' => str_repeat('d', 64),
                    'post_sha' => str_repeat('e', 64),
                    'action' => 'skipped',
                ],
            ],
            'evidence_ids' => ['ev-1', 'ev-2'],
        ], $override);
    }
}
