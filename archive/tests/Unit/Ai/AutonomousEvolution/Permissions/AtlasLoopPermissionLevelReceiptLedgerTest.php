<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Permissions;

use App\Services\Ai\AutonomousEvolution\Permissions\AtlasLoopPermissionLevelReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Permissions\AtlasLoopPermissionReceipt;
use Tests\TestCase;

class AtlasLoopPermissionLevelReceiptLedgerTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-perm-ledger-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function receipt(): AtlasLoopPermissionReceipt
    {
        return new AtlasLoopPermissionReceipt(
            timestamp: '2026-06-25T00:00:00Z',
            phase: 'observe',
            attemptedLevel: 'MERGE',
            requiredLevel: 'READ',
            decision: AtlasLoopPermissionReceipt::DECISION_DENY,
            reason: 'operation_level_exceeds_phase_authority',
            masterSwitchState: true,
            callerChokepoint: 'AtlasLoopWorkspaceMaterializer',
        );
    }

    public function test_two_identical_records_produce_two_byte_identical_lines(): void
    {
        $ledger = new AtlasLoopPermissionLevelReceiptLedger($this->path, fn (): bool => true);
        $ledger->record($this->receipt());
        $ledger->record($this->receipt());

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(2, $lines, 'append-only: no dedup');
        self::assertSame(hash('sha256', $lines[0]), hash('sha256', $lines[1]));
    }

    public function test_receipt_has_exactly_eight_fields(): void
    {
        $payload = $this->receipt()->toArray();
        self::assertCount(8, $payload);
        foreach (['attempted_level', 'caller_chokepoint', 'decision', 'master_switch_state', 'phase', 'reason', 'required_level', 'timestamp'] as $field) {
            self::assertArrayHasKey($field, $payload);
        }
    }

    public function test_to_json_line_serializes_in_sorted_lexical_order(): void
    {
        $line = $this->receipt()->toJsonLine();
        $decoded = json_decode($line, true);
        self::assertSame(['attempted_level', 'caller_chokepoint', 'decision', 'master_switch_state', 'phase', 'reason', 'required_level', 'timestamp'], array_keys($decoded));
    }

    public function test_master_off_is_byte_identical_no_op(): void
    {
        $ledger = new AtlasLoopPermissionLevelReceiptLedger($this->path, fn (): bool => false);
        $ledger->record($this->receipt());

        self::assertFalse(file_exists($this->path));
    }

    public function test_ledger_refuses_path_inside_app_path(): void
    {
        $insideApp = app_path('atlas-permission-ledger-fake.jsonl');
        $ledger = new AtlasLoopPermissionLevelReceiptLedger($insideApp, fn (): bool => true);

        $this->expectException(\Throwable::class);
        $ledger->record($this->receipt());
    }

    public function test_json_line_is_byte_equal_across_two_process_serializations(): void
    {
        // Simulate two independent process serializations by re-instantiating the receipt.
        $r1 = $this->receipt()->toJsonLine();
        $r2 = (new AtlasLoopPermissionReceipt(
            timestamp: '2026-06-25T00:00:00Z',
            phase: 'observe',
            attemptedLevel: 'MERGE',
            requiredLevel: 'READ',
            decision: AtlasLoopPermissionReceipt::DECISION_DENY,
            reason: 'operation_level_exceeds_phase_authority',
            masterSwitchState: true,
            callerChokepoint: 'AtlasLoopWorkspaceMaterializer',
        ))->toJsonLine();

        self::assertSame($r1, $r2);
    }
}
