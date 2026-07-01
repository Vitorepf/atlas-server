<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use Tests\TestCase;

final class AgentControlPlaneEvidenceLedgerDryRunTest extends TestCase
{
    private function dryRun(): AgentControlPlaneEvidenceLedgerDryRun
    {
        return new AgentControlPlaneEvidenceLedgerDryRun;
    }

    // ── AC2: all REQUIRED_RECEIPTS present in planned_receipts exactly once ──

    public function test_all_required_receipts_are_present_exactly_once(): void
    {
        $result = $this->dryRun()->plan(['task_packet_id' => 't1', 'status' => 'planned']);

        $kinds = array_column($result['planned_receipts'], 'receipt_kind');
        $counts = array_count_values($kinds);

        foreach (AgentControlPlaneEvidenceLedgerDryRun::REQUIRED_RECEIPTS as $required) {
            $this->assertContains($required, $kinds, "missing: {$required}");
            $this->assertSame(1, $counts[$required], "not exactly once: {$required}");
        }
    }

    // ── AC3: duplicate additional_receipts are deduplicated and reported ──────

    public function test_duplicate_additional_receipts_are_deduplicated_and_counted(): void
    {
        $result = $this->dryRun()->plan(
            ['task_packet_id' => 't1', 'status' => 'planned'],
            [],
            ['additional_receipts' => ['custom_receipt', 'custom_receipt', 'custom_receipt']],
        );

        $kinds = array_column($result['planned_receipts'], 'receipt_kind');
        $this->assertSame(1, array_count_values($kinds)['custom_receipt']);
        $this->assertSame(2, $result['duplicate_receipt_count']);
    }

    public function test_no_duplicate_receipt_count_when_additional_receipts_are_unique(): void
    {
        $result = $this->dryRun()->plan(
            ['task_packet_id' => 't1', 'status' => 'planned'],
            [],
            ['additional_receipts' => ['custom_a', 'custom_b']],
        );

        $this->assertSame(0, $result['duplicate_receipt_count']);
    }

    // ── AC4: planned_blocked status preserves the safety guarantees ──────────

    public function test_planned_blocked_status_preserves_safety_guarantees(): void
    {
        $result = $this->dryRun()->plan(['task_packet_id' => 't1', 'status' => 'draft']);

        $this->assertSame('planned_blocked', $result['status']);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertFalse($result['persistence_allowed']);
        $this->assertTrue($result['dry_run_only']);
        $this->assertContains('task_packet_not_planned', $result['blocking_reasons']);
    }

    public function test_planned_blocked_when_scope_lock_is_blocked(): void
    {
        $result = $this->dryRun()->plan(
            ['task_packet_id' => 't1', 'status' => 'planned'],
            ['blocking_reasons' => ['scope_violation']],
        );

        $this->assertSame('planned_blocked', $result['status']);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertFalse($result['persistence_allowed']);
        $this->assertTrue($result['dry_run_only']);
        $this->assertContains('scope_lock_blocked', $result['blocking_reasons']);
    }
}
