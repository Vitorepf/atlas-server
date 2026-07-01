<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneCostImportReconciliationDryRun;
use PHPUnit\Framework\TestCase;

final class AgentControlPlaneCostImportReconciliationDryRunTest extends TestCase
{
    private function dryRun(): AgentControlPlaneCostImportReconciliationDryRun
    {
        return new AgentControlPlaneCostImportReconciliationDryRun;
    }

    public function test_blank_refs_are_invalid_and_do_not_count_as_observed_or_expected(): void
    {
        $r = $this->dryRun()->reconcile(
            [['task_packet_id' => '', 'run_id' => 'run-1']],
            [['task_packet_id' => 'task-1', 'run_id' => '']],
        );

        self::assertSame(0, $r['observed_ref_count']);
        self::assertSame(0, $r['expected_ref_count']);
        self::assertSame(1, $r['invalid_observed_ref_count']);
        self::assertSame(1, $r['invalid_expected_ref_count']);
    }

    public function test_duplicate_observed_refs_do_not_inflate_observed_ref_count_and_are_reported(): void
    {
        $r = $this->dryRun()->reconcile(
            [
                ['task_packet_id' => 'task-1', 'run_id' => 'run-1'],
                ['task_packet_id' => 'task-1', 'run_id' => 'run-1'],
                ['task_packet_id' => 'task-1', 'run_id' => 'run-1'],
            ],
            [['task_packet_id' => 'task-1', 'run_id' => 'run-1']],
        );

        self::assertSame(1, $r['observed_ref_count']);
        self::assertCount(1, $r['duplicate_observed_refs']);
        self::assertSame(3, $r['duplicate_observed_refs'][0]['occurrences']);
    }

    public function test_unexpected_and_missing_refs_return_has_gaps_with_repair_next_action(): void
    {
        $r = $this->dryRun()->reconcile(
            [['task_packet_id' => 'unexpected-task', 'run_id' => 'run-9']],
            [['task_packet_id' => 'expected-task', 'run_id' => 'run-1']],
        );

        self::assertSame('reconciliation_dry_run_has_gaps', $r['status']);
        self::assertSame('repair_cost_event_manifest_before_import', $r['next_action']);
        self::assertCount(1, $r['missing_cost_event_refs']);
        self::assertCount(1, $r['unexpected_observed_refs']);
    }

    public function test_matching_refs_are_clear_with_no_next_action_repair(): void
    {
        $r = $this->dryRun()->reconcile(
            [['task_packet_id' => 'task-1', 'run_id' => 'run-1']],
            [['task_packet_id' => 'task-1', 'run_id' => 'run-1']],
        );

        self::assertSame('reconciliation_dry_run_clear', $r['status']);
        self::assertNotSame('repair_cost_event_manifest_before_import', $r['next_action']);
        self::assertSame([], $r['missing_cost_event_refs']);
        self::assertSame([], $r['unexpected_observed_refs']);
    }
}
