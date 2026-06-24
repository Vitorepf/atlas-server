<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Frozen;

use App\Services\Ai\AutonomousEvolution\Frozen\AtlasLoopFrozenContractReceiptLedger;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class AtlasLoopFrozenContractReceiptLedgerTest extends TestCase
{
    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $tmpDir) {
            $this->removeDirectory($tmpDir);
        }

        parent::tearDown();
    }

    public function test_audit_and_drift_receipts_append_one_json_line_each_in_order(): void
    {
        $tmp = $this->makeTmpDir();
        $ledgerPath = $tmp.'/frozen-contract-receipts.jsonl';
        $manifestPath = $tmp.'/contracts.manifest.json';
        file_put_contents($manifestPath, "{\"contracts\":true}\n");
        $ledger = new AtlasLoopFrozenContractReceiptLedger($ledgerPath, $manifestPath);
        $audit = [
            'schema_version' => 'atlas.ai.loop_frozen_contract_auditor.v1',
            'verdict' => 'BLOCK',
            'offending_pairs' => [
                [
                    'fqcn' => 'App\\Tests\\FrozenOne',
                    'frozen_test_path' => 'tests/FrozenOneTest.php',
                ],
            ],
            'checked_pairs' => [],
        ];
        $drift = [
            [
                'fqcn' => 'App\\Tests\\FrozenOne',
                'test_path' => 'tests/FrozenOneTest.php',
                'expected_sha' => str_repeat('a', 64),
                'actual_sha' => str_repeat('b', 64),
                'drift' => true,
            ],
            [
                'fqcn' => 'App\\Tests\\FrozenTwo',
                'test_path' => 'tests/FrozenTwoTest.php',
                'expected_sha' => str_repeat('c', 64),
                'actual_sha' => str_repeat('c', 64),
                'drift' => false,
            ],
        ];

        $auditReceipt = $ledger->recordAudit($audit);
        $driftReceipt = $ledger->recordDrift($drift);
        $lines = $this->readJsonLines($ledgerPath);

        $this->assertCount(2, $lines);
        $this->assertSame([$auditReceipt, $driftReceipt], $lines);
        $this->assertSame('audit', $lines[0]['source']);
        $this->assertSame('drift', $lines[1]['source']);
        $this->assertSame(['App\\Tests\\FrozenOne'], $lines[0]['fqcn']);
        $this->assertSame(['App\\Tests\\FrozenOne', 'App\\Tests\\FrozenTwo'], $lines[1]['fqcn']);
        $this->assertSame($audit, $lines[0]['verdict_or_facts']);
        $this->assertSame($drift, $lines[1]['verdict_or_facts']);
        $this->assertSame(hash_file('sha256', $manifestPath), $lines[0]['manifest_sha']);
        $this->assertSame(hash_file('sha256', $manifestPath), $lines[1]['manifest_sha']);
    }

    public function test_public_api_exposes_no_truncate_clear_or_reset_method(): void
    {
        $reflection = new ReflectionClass(AtlasLoopFrozenContractReceiptLedger::class);
        $publicMethodNames = array_map(
            static fn (ReflectionMethod $method): string => strtolower($method->getName()),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        $this->assertNotContains('truncate', $publicMethodNames);
        $this->assertNotContains('clear', $publicMethodNames);
        $this->assertNotContains('reset', $publicMethodNames);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonLines(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);

        return array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            $lines,
        );
    }

    private function makeTmpDir(): string
    {
        $path = sys_get_temp_dir().'/atlas-frozen-ledger-'.bin2hex(random_bytes(8));
        mkdir($path, 0777, true);
        $this->tmpDirs[] = $path;

        return $path;
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path.'/'.$item;
            if (is_dir($child)) {
                $this->removeDirectory($child);
                continue;
            }

            @unlink($child);
        }

        @rmdir($path);
    }
}
