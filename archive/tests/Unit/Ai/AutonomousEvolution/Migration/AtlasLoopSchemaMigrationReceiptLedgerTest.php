<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Migration;

use App\Services\Ai\AutonomousEvolution\Migration\AtlasLoopSchemaMigrationReceipt;
use App\Services\Ai\AutonomousEvolution\Migration\AtlasLoopSchemaMigrationReceiptLedger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AtlasLoopSchemaMigrationReceiptLedgerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $dir = sys_get_temp_dir().'/atlas-migration-ledger-'.bin2hex(random_bytes(4));
        @mkdir($dir, 0o755, true);
        $this->path = $dir.'/migration-receipts.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir(dirname($this->path));
        parent::tearDown();
    }

    public function test_public_surface_is_append_only_and_exposes_no_update_or_delete(): void
    {
        $reflection = new ReflectionClass(AtlasLoopSchemaMigrationReceiptLedger::class);
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            array_filter(
                $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn (\ReflectionMethod $method): bool => $method->class === AtlasLoopSchemaMigrationReceiptLedger::class,
            ),
        );

        $this->assertContains('append', $methods);
        $this->assertContains('list', $methods);
        $this->assertContains('verify', $methods);
        $this->assertNotContains('update', $methods);
        $this->assertNotContains('delete', $methods);
    }

    public function test_appending_two_receipts_and_relisting_preserves_insertion_order_and_content(): void
    {
        $ledger = new AtlasLoopSchemaMigrationReceiptLedger($this->path);

        $first = $ledger->append($this->receipt(
            artifactKind: 'atlas_loop_evidence_ledger',
            fromVersion: 1,
            toVersion: 2,
            preHash: 'pre-a',
            tokenFingerprint: 'fp-a',
            verifierResult: 'pass',
            startedAt: '2026-06-24T05:00:00+00:00',
            finishedAt: '2026-06-24T05:00:05+00:00',
            evidenceReceiptId: 'rcpt-a',
            outcome: 'success',
        ));
        $second = $ledger->append($this->receipt(
            artifactKind: 'atlas_loop_evidence_ledger',
            fromVersion: 2,
            toVersion: 3,
            preHash: 'pre-b',
            tokenFingerprint: 'fp-b',
            verifierResult: 'pass',
            startedAt: '2026-06-24T05:01:00+00:00',
            finishedAt: '2026-06-24T05:01:05+00:00',
            evidenceReceiptId: 'rcpt-b',
            outcome: 'success',
        ));

        $listed = $ledger->list();

        $this->assertSame(
            [json_encode($first->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), json_encode($second->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
            array_map(
                static fn (AtlasLoopSchemaMigrationReceipt $receipt): string|false => json_encode($receipt->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $listed,
            ),
        );
    }

    public function test_verify_detects_tampering_with_historical_row(): void
    {
        $ledger = new AtlasLoopSchemaMigrationReceiptLedger($this->path);
        $ledger->append($this->receipt(
            artifactKind: 'atlas_loop_workspace_blueprints',
            fromVersion: 1,
            toVersion: 2,
            preHash: 'pre-a',
            tokenFingerprint: 'fp-a',
            verifierResult: 'pass',
            startedAt: '2026-06-24T05:10:00+00:00',
            finishedAt: '2026-06-24T05:10:05+00:00',
            evidenceReceiptId: 'rcpt-a',
            outcome: 'success',
        ));
        $ledger->append($this->receipt(
            artifactKind: 'atlas_loop_workspace_blueprints',
            fromVersion: 2,
            toVersion: 3,
            preHash: 'pre-b',
            tokenFingerprint: 'fp-b',
            verifierResult: 'pass',
            startedAt: '2026-06-24T05:11:00+00:00',
            finishedAt: '2026-06-24T05:11:05+00:00',
            evidenceReceiptId: 'rcpt-b',
            outcome: 'success',
        ));

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $mutated = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        $mutated['outcome'] = 'tampered';
        $lines[0] = json_encode($mutated, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        file_put_contents($this->path, implode(PHP_EOL, $lines).PHP_EOL);

        $this->assertFalse($ledger->verify());
    }

    private function receipt(
        string $artifactKind,
        int $fromVersion,
        int $toVersion,
        string $preHash,
        string $tokenFingerprint,
        string $verifierResult,
        string $startedAt,
        string $finishedAt,
        string $evidenceReceiptId,
        string $outcome,
    ): AtlasLoopSchemaMigrationReceipt {
        return new AtlasLoopSchemaMigrationReceipt(
            artifactKind: $artifactKind,
            fromVersion: $fromVersion,
            toVersion: $toVersion,
            preHash: $preHash,
            postHash: null,
            operatorTokenFingerprint: $tokenFingerprint,
            verifierResult: $verifierResult,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            evidenceReceiptId: $evidenceReceiptId,
            outcome: $outcome,
        );
    }
}
