<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Simulation;

use App\Services\Ai\AutonomousEvolution\Simulation\AtlasLoopSimulationDryRunner;
use App\Services\Ai\AutonomousEvolution\Simulation\AtlasLoopSimulationReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Simulation\DryRunReceipt;
use ReflectionClass;
use Tests\TestCase;

// DryRunReceipt lives inside the dry-runner file (PSR-4 maps one file per class). Force its load.
\class_exists(AtlasLoopSimulationDryRunner::class);

class AtlasLoopSimulationReceiptLedgerTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-sim-ledger-'.bin2hex(random_bytes(6)).'.jsonl';
        app()->instance(AtlasLoopSimulationReceiptLedger::class, new AtlasLoopSimulationReceiptLedger($this->path));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function makeReceipt(int $i = 0): DryRunReceipt
    {
        return new DryRunReceipt(
            patchApplyExitCode: 0,
            patchApplyOutputTail: '',
            changedFiles: [['path' => 'app/Foo'.$i.'.php', 'sha256_pre' => 'a', 'sha256_post' => 'b']],
            phpLintExitCodePerFile: [],
            frozenTestExitCode: 0,
            frozenTestStdoutTail: '',
            runStartedAt: '2026-06-25T00:00:00Z',
            runFinishedAt: '2026-06-25T00:00:01Z',
        );
    }

    public function test_append_returns_distinct_receipt_ids_per_call(): void
    {
        /** @var AtlasLoopSimulationReceiptLedger $ledger */
        $ledger = app(AtlasLoopSimulationReceiptLedger::class);
        $ids = [];
        for ($i = 0; $i < 8; $i++) {
            $ids[] = $ledger->append($this->makeReceipt($i));
        }

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(8, $lines);
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            self::assertIsArray($decoded);
            self::assertArrayHasKey('receipt_id', $decoded);
        }
        self::assertCount(8, array_unique($ids));
    }

    public function test_ledger_exposes_no_update_delete_or_truncate_methods(): void
    {
        $reflection = new ReflectionClass(AtlasLoopSimulationReceiptLedger::class);
        foreach (['update', 'delete', 'truncate', 'remove', 'clear'] as $forbidden) {
            self::assertFalse($reflection->hasMethod($forbidden), "$forbidden must not exist");
        }
    }

    public function test_history_returns_receipts_in_reverse_chronological_order(): void
    {
        /** @var AtlasLoopSimulationReceiptLedger $ledger */
        $ledger = app(AtlasLoopSimulationReceiptLedger::class);
        $firstId = $ledger->append($this->makeReceipt(1));
        $secondId = $ledger->append($this->makeReceipt(2));
        $thirdId = $ledger->append($this->makeReceipt(3));

        $rows = $ledger->history(50);
        self::assertCount(3, $rows);
        self::assertSame($thirdId, $rows[0]['receipt_id']);
        self::assertSame($secondId, $rows[1]['receipt_id']);
        self::assertSame($firstId, $rows[2]['receipt_id']);
    }

    public function test_history_flags_broken_chain_after_tampering(): void
    {
        /** @var AtlasLoopSimulationReceiptLedger $ledger */
        $ledger = app(AtlasLoopSimulationReceiptLedger::class);
        $ledger->append($this->makeReceipt(1));
        $ledger->append($this->makeReceipt(2));
        $ledger->append($this->makeReceipt(3));

        // Tamper with the first line.
        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $first = json_decode($lines[0], true);
        $first['receipt']['injected'] = 'tamper';
        $lines[0] = (string) json_encode($first, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($this->path, implode("\n", $lines)."\n");

        $rows = $ledger->history(50);
        // The chain breaks at the line AFTER the tampered one (the stored prev_line_sha256 won't match).
        $brokenCount = 0;
        foreach ($rows as $row) {
            if (($row['chain_valid'] ?? true) === false) {
                $brokenCount++;
            }
        }
        self::assertGreaterThanOrEqual(1, $brokenCount, 'tampering must be flagged');
    }

    public function test_eight_sequential_appends_produce_eight_valid_json_lines(): void
    {
        /** @var AtlasLoopSimulationReceiptLedger $ledger */
        $ledger = app(AtlasLoopSimulationReceiptLedger::class);
        for ($i = 0; $i < 8; $i++) {
            $ledger->append($this->makeReceipt($i));
        }
        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(8, $lines);
        foreach ($lines as $line) {
            // Each line must independently decode (no partial writes).
            self::assertIsArray(json_decode($line, true));
        }
    }

    public function test_container_resolves_singleton_via_app_helper(): void
    {
        $a = app(AtlasLoopSimulationReceiptLedger::class);
        $b = app(AtlasLoopSimulationReceiptLedger::class);
        self::assertSame($a, $b);
    }
}
