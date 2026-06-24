<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\SchemaFuzz;

use App\Services\Ai\AutonomousEvolution\SchemaFuzz\AtlasLoopSchemaFuzzReceiptLedger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AtlasLoopSchemaFuzzReceiptLedgerTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = sys_get_temp_dir().'/atlas-schemafuzz-ledger-'.bin2hex(random_bytes(4));
        @mkdir($this->basePath, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);
        parent::tearDown();
    }

    public function test_append_is_order_preserving_and_never_rewrites_prior_bytes(): void
    {
        $ledger = new AtlasLoopSchemaFuzzReceiptLedger($this->basePath);

        $first = $ledger->append($this->runEnvelope('run-1', 'seed-a'), $this->rows('payload-a'));
        $ledgerPath = $this->basePath.'/receipts.jsonl';
        $afterFirstAppend = (string) file_get_contents($ledgerPath);
        $this->assertSame($this->canonicalLine($first)."\n", $afterFirstAppend);

        $second = $ledger->append($this->runEnvelope('run-2', 'seed-b'), $this->rows('payload-b'));
        $afterSecondAppend = (string) file_get_contents($ledgerPath);
        $lines = file($ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertIsArray($lines);
        $this->assertCount(2, $lines);
        $this->assertSame(substr($afterFirstAppend, 0, strlen($afterFirstAppend)), substr($afterSecondAppend, 0, strlen($afterFirstAppend)));
        $this->assertSame($this->canonicalLine($first), $lines[0]);
        $this->assertSame($this->canonicalLine($second), $lines[1]);
        $this->assertSame($first, $ledger->queryByRunId('run-1'));
        $this->assertSame([$second], $ledger->queryBySeed('seed-b'));
    }

    public function test_tampering_any_persisted_row_bytes_breaks_digest_verification(): void
    {
        $ledger = new AtlasLoopSchemaFuzzReceiptLedger($this->basePath);
        $receipt = $ledger->append($this->runEnvelope('run-1', 'seed-a'), $this->rows('payload-a'));

        $this->assertTrue($ledger->verifyRun('run-1'));
        $this->assertSame($receipt['per_row_digest_root'], $ledger->recomputePerRowDigestRoot('run-1'));

        $rawRows = (string) file_get_contents($receipt['raw_rows_path']);
        file_put_contents($receipt['raw_rows_path'], preg_replace('/payload-a-1/', 'payload-z-1', $rawRows, 1, $count) ?? $rawRows);
        $this->assertGreaterThan(0, $count);

        $this->assertFalse($ledger->verifyRun('run-1'));
        $this->assertNotSame($receipt['per_row_digest_root'], $ledger->recomputePerRowDigestRoot('run-1'));
    }

    public function test_public_surface_has_no_mutation_api_and_uses_append_semantics(): void
    {
        $reflection = new ReflectionClass(AtlasLoopSchemaFuzzReceiptLedger::class);
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            array_filter(
                $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn (\ReflectionMethod $method): bool => $method->class === AtlasLoopSchemaFuzzReceiptLedger::class,
            ),
        );

        $this->assertContains('append', $methods);
        $this->assertContains('queryByRunId', $methods);
        $this->assertContains('queryBySeed', $methods);
        $this->assertNotContains('update', $methods);
        $this->assertNotContains('delete', $methods);
        $this->assertNotContains('prune', $methods);

        $source = (string) file_get_contents(__DIR__.'/../../../../../app/Services/Ai/AutonomousEvolution/SchemaFuzz/AtlasLoopSchemaFuzzReceiptLedger.php');
        $this->assertStringContainsString('FILE_APPEND', $source);
    }

    /**
     * @return array{
     *   run_id:string,
     *   seed:string,
     *   generator_version:string,
     *   reporter_version:string,
     *   schema_versions:list<string>,
     *   started_at_utc:string,
     *   finished_at_utc:string
     * }
     */
    private function runEnvelope(string $runId, string $seed): array
    {
        return [
            'run_id' => $runId,
            'seed' => $seed,
            'generator_version' => 'generator.v1',
            'reporter_version' => 'reporter.v1',
            'schema_versions' => ['attempt_ledger_record.v1', 'decision_receipt.v1'],
            'started_at_utc' => '2026-06-24T06:00:00Z',
            'finished_at_utc' => '2026-06-24T06:00:05Z',
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(string $payloadPrefix): array
    {
        return [
            [
                'payload_id' => $payloadPrefix.'-2',
                'schema_name' => 'task_envelope',
                'outcome' => 'accept',
                'reject_reason_code' => null,
                'validator_engine_id' => 'atlas.array_shape',
                'validator_version' => 'task_envelope.v1',
            ],
            [
                'payload_id' => $payloadPrefix.'-1',
                'schema_name' => 'decision_receipt',
                'outcome' => 'reject',
                'reject_reason_code' => 'missing_required_receipt_id',
                'validator_engine_id' => 'atlas.array_shape',
                'validator_version' => 'decision_receipt.v1',
            ],
            [
                'payload_id' => $payloadPrefix.'-1',
                'schema_name' => 'attempt_ledger_record',
                'outcome' => 'accept',
                'reject_reason_code' => null,
                'validator_engine_id' => 'atlas.array_shape',
                'validator_version' => 'attempt_ledger_record.v1',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function canonicalLine(array $receipt): string
    {
        ksort($receipt);

        return (string) json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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
