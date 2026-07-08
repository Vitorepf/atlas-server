<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCostImportReconciliationDryRun;
use Tests\TestCase;

/**
 * Proves AgentControlPlaneCostImportReconciliationDryRun cannot collide
 * when task_packet_id contains the old pipe delimiter.
 */
final class AgentControlPlaneCostImportReconciliationDryRunHardeningTest extends TestCase
{
    public function test_pipe_in_task_packet_id_does_not_collide(): void
    {
        $reconciler = new AgentControlPlaneCostImportReconciliationDryRun();

        // observed has task_packet_id='a|b', run_id='c'
        // expected has task_packet_id='a', run_id='b|c'
        // With pipe delimiter, both would be 'a|b|c' — collision
        // With JSON encoding, they are distinct
        $result = $reconciler->reconcile(
            [
                [
                    'task_packet_id' => 'a|b',
                    'run_id' => 'c',
                    'cost_cents' => 100,
                ],
            ],
            [
                [
                    'task_packet_id' => 'a',
                    'run_id' => 'b|c',
                ],
            ]
        );

        // Both are gaps: the observed is unexpected, the expected is missing
        $this->assertSame('reconciliation_dry_run_has_gaps', $result['status']);
        $this->assertGreaterThan(0, $result['missing_count']);
        $this->assertGreaterThan(0, $result['unexpected_observed_ref_count']);
    }

    public function test_matching_refs_reconcile_clear(): void
    {
        $reconciler = new AgentControlPlaneCostImportReconciliationDryRun();

        $result = $reconciler->reconcile(
            [
                [
                    'task_packet_id' => 'brain:task-1',
                    'run_id' => 'run-1',
                    'cost_cents' => 100,
                ],
            ],
            [
                [
                    'task_packet_id' => 'brain:task-1',
                    'run_id' => 'run-1',
                ],
            ]
        );

        $this->assertSame('reconciliation_dry_run_clear', $result['status']);
    }

    public function test_pipe_in_task_packet_id_matches_correctly(): void
    {
        $reconciler = new AgentControlPlaneCostImportReconciliationDryRun();

        // Same ref on both sides — should reconcile clear
        $result = $reconciler->reconcile(
            [
                [
                    'task_packet_id' => 'a|b',
                    'run_id' => 'c',
                    'cost_cents' => 100,
                ],
            ],
            [
                [
                    'task_packet_id' => 'a|b',
                    'run_id' => 'c',
                ],
            ]
        );

        $this->assertSame('reconciliation_dry_run_clear', $result['status']);
    }
}
