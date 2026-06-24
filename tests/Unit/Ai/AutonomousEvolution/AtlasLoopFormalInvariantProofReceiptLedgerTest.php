<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\SelfMod\FormalProofs\AtlasLoopFormalInvariantProofReceiptLedger;
use RuntimeException;
use Tests\TestCase;

final class AtlasLoopFormalInvariantProofReceiptLedgerTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = sys_get_temp_dir().'/atlas-formal-proof-ledger-'.bin2hex(random_bytes(4));
        @mkdir($this->basePath, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);
        parent::tearDown();
    }

    public function test_record_history_and_lookup_are_byte_identical(): void
    {
        $ledger = new AtlasLoopFormalInvariantProofReceiptLedger($this->basePath);

        $first = $ledger->record(
            $this->proofResult('inv-1', 'survives', [['symbol' => '$this->ready']]),
            $this->context('/tmp/Foo.php', 'pre-a', 'post-a', '2026-06-24T10:00:00Z'),
        );
        $ledger->record(
            $this->proofResult('inv-2', 'broken', [['symbol' => '$this->count']]),
            $this->context('/tmp/Bar.php', 'pre-b', 'post-b', '2026-06-24T10:01:00Z'),
        );
        $ledger->record(
            $this->proofResult('inv-1', 'indeterminate', [], 'unsupported_ast_node'),
            $this->context('/tmp/Foo.php', 'pre-c', 'post-c', '2026-06-24T10:02:00Z'),
        );

        $path = $this->basePath.'/2026-06-24.jsonl';
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertIsArray($lines);
        $this->assertCount(3, $lines);
        $this->assertSame($first, $ledger->history('inv-1', 50)[0]);
        $this->assertSame($first, $ledger->lookup((string) $first['receipt_id']));
        $this->assertSame(
            $lines[0],
            json_encode($first, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    public function test_duplicate_receipt_id_returns_existing_and_does_not_append_second_line(): void
    {
        $ledger = new AtlasLoopFormalInvariantProofReceiptLedger($this->basePath);

        $first = $ledger->record(
            $this->proofResult('inv-dup', 'survives', [['symbol' => '$this->ready']]),
            $this->context('/tmp/Foo.php', 'pre-a', 'post-a', '2026-06-24T10:00:00Z'),
        );
        $second = $ledger->record(
            $this->proofResult('inv-dup', 'survives', [['symbol' => '$this->ready']]),
            $this->context('/tmp/Foo.php', 'pre-a', 'post-a', '2026-06-24T10:00:00Z'),
        );

        $path = $this->basePath.'/2026-06-24.jsonl';
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertSame($first['receipt_id'], $second['receipt_id']);
        $this->assertIsArray($lines);
        $this->assertCount(1, $lines);
        $this->assertGreaterThanOrEqual(1, $ledger->stats()['duplicate_seen']);
    }

    public function test_tampered_payload_for_same_receipt_id_is_rejected_and_original_line_survives(): void
    {
        $ledger = new AtlasLoopFormalInvariantProofReceiptLedger($this->basePath);
        $first = $ledger->record(
            $this->proofResult('inv-dup', 'survives', [['symbol' => '$this->ready']]),
            $this->context('/tmp/Foo.php', 'pre-a', 'post-a', '2026-06-24T10:00:00Z'),
        );
        $path = $this->basePath.'/2026-06-24.jsonl';

        $tampered = $first;
        $tampered['pre_sha'] = 'pre-a-tampered';
        file_put_contents(
            $path,
            json_encode($tampered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL,
        );
        $before = (string) file_get_contents($path);

        try {
            $ledger->record(
                $this->proofResult('inv-dup', 'survives', [['symbol' => '$this->ready']]),
                $this->context('/tmp/Foo.php', 'pre-a', 'post-a', '2026-06-24T10:00:00Z'),
            );
            $this->fail('Expected append_only_violation_existing_receipt_differs');
        } catch (RuntimeException $e) {
            $this->assertSame('append_only_violation_existing_receipt_differs', $e->getMessage());
        }

        $after = (string) file_get_contents($path);
        $this->assertSame($before, $after);
        $this->assertSame((string) $first['receipt_id'], (string) $ledger->lookup((string) $first['receipt_id'])['receipt_id']);
    }

    /**
     * @param  list<array<string,mixed>>  $witnessNodes
     * @return array<string,mixed>
     */
    private function proofResult(string $invariantId, string $verdict, array $witnessNodes, ?string $unsupportedReason = null): array
    {
        return [
            'invariant_id' => $invariantId,
            'verdict' => $verdict,
            'witness_nodes' => $witnessNodes,
            'unsupported_reason' => $unsupportedReason,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function context(string $filePath, string $preSha, string $postSha, string $producedAt): array
    {
        return [
            'file_path' => $filePath,
            'pre_sha' => $preSha,
            'post_sha' => $postSha,
            'produced_at' => $producedAt,
            'prover_version' => 'formal-proof-survival-prover.v1',
        ];
    }

    private function deleteDirectory(string $path): void
    {
        if ($path === '' || ! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path.'/'.$item;
            if (is_dir($child)) {
                $this->deleteDirectory($child);
                continue;
            }

            @unlink($child);
        }

        @rmdir($path);
    }
}
