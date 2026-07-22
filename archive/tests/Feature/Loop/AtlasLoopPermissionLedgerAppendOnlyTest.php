<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Permissions\AtlasLoopPermissionLevelReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Permissions\AtlasLoopPermissionReceipt;
use Tests\TestCase;

class AtlasLoopPermissionLedgerAppendOnlyTest extends TestCase
{
    private string $tmpDir = '';

    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/atlas-perm-feature-'.bin2hex(random_bytes(6));
        $this->ledgerPath = $this->tmpDir.'/permission-receipts.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function receipt(string $decision = 'deny'): AtlasLoopPermissionReceipt
    {
        return new AtlasLoopPermissionReceipt(
            timestamp: '2026-06-25T00:00:00Z',
            phase: 'observe',
            attemptedLevel: 'MERGE',
            requiredLevel: 'READ',
            decision: $decision,
            reason: 'operation_level_exceeds_phase_authority',
            masterSwitchState: true,
            callerChokepoint: 'feature_test',
        );
    }

    public function test_master_off_filesystem_snapshot_is_unchanged(): void
    {
        $before = $this->snapshotDir(\dirname($this->ledgerPath));
        $ledger = new AtlasLoopPermissionLevelReceiptLedger($this->ledgerPath, fn (): bool => false);
        $ledger->record($this->receipt());
        $after = $this->snapshotDir(\dirname($this->ledgerPath));

        self::assertSame($before, $after, 'master OFF must be byte-identical no-op');
        self::assertFileDoesNotExist($this->ledgerPath);
    }

    public function test_append_only_records_three_sequential_lines(): void
    {
        $ledger = new AtlasLoopPermissionLevelReceiptLedger($this->ledgerPath, fn (): bool => true);
        $ledger->record($this->receipt('allow'));
        $ledger->record($this->receipt('deny'));
        $ledger->record($this->receipt('allow'));

        $lines = file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(3, $lines);
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            self::assertIsArray($decoded);
            self::assertArrayHasKey('decision', $decoded);
        }
    }

    public function test_ledger_path_layout_under_storage(): void
    {
        $default = (new AtlasLoopPermissionLevelReceiptLedger(null, fn (): bool => true))->path();
        self::assertStringContainsString('atlas/loop/permission-receipts.jsonl', $default);
    }

    /**
     * @return array<string,string>
     */
    private function snapshotDir(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }
        $map = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $info) {
            /** @var \SplFileInfo $info */
            if ($info->isFile()) {
                $map[$info->getPathname()] = (string) hash_file('sha256', $info->getPathname());
            }
        }
        ksort($map, SORT_STRING);

        return $map;
    }
}
