<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\SelfHealingMaintenanceWindowService;
use Tests\TestCase;

final class SelfHealingMaintenanceWindowServiceTest extends TestCase
{
    private function service(): SelfHealingMaintenanceWindowService
    {
        return app(SelfHealingMaintenanceWindowService::class);
    }

    /**
     * A healthy window request with evidence preserved and seam data for every
     * destructive task. Tests mutate a copy of this to drive each case.
     *
     * @return array<string,mixed>
     */
    private function healthyFixture(): array
    {
        return [
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'window_id' => 'win-001',
            'profile' => SelfHealingMaintenanceWindowService::PROFILE_FULL,
            'evidence_preserved' => true,
            // diagnostic seams.
            'stale_lock' => false,
            'loop_lock_held' => true,
            'provider_health' => 'healthy',
            'backlog_depth' => 7,
            'replay_sample_cycles' => 3,
            'replay_consistent' => true,
            'chaos_scenarios' => 4,
            'chaos_survived' => true,
            // destructive PLAN-only seams (planned candidates, never executed).
            'evidence_packs_to_archive' => ['pack-aaa', 'pack-bbb'],
            'ledger_line_count' => 12000,
            'ledger_compact_after_lines' => 10000,
            'stale_worktrees' => ['/tmp/wt-1'],
            'merged_branches' => ['atlas/old-merged'],
        ];
    }

    /**
     * Pull the task row for a given task id from a report.
     *
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function task(array $report, string $taskId): array
    {
        foreach ($report['tasks'] as $row) {
            if (($row['task'] ?? null) === $taskId) {
                return $row;
            }
        }

        $this->fail("task {$taskId} not present in report");
    }

    public function test_lightweight_runs_subset_only(): void
    {
        $input = $this->healthyFixture();
        $input['profile'] = SelfHealingMaintenanceWindowService::PROFILE_LIGHTWEIGHT;

        $report = $this->service()->run($input);

        $this->assertSame(SelfHealingMaintenanceWindowService::STATUS_OK, $report['status']);
        $this->assertSame(SelfHealingMaintenanceWindowService::PROFILE_LIGHTWEIGHT, $report['profile']);

        // Lightweight runs the fast hygiene subset (done) ...
        foreach ([
            SelfHealingMaintenanceWindowService::TASK_VERIFY_LOCKS,
            SelfHealingMaintenanceWindowService::TASK_REFRESH_PROVIDER_HEALTH,
            SelfHealingMaintenanceWindowService::TASK_RECALCULATE_BACKLOG_DEPTH,
            SelfHealingMaintenanceWindowService::TASK_EMIT_OPERATOR_SUMMARY,
        ] as $taskId) {
            $this->assertSame(
                SelfHealingMaintenanceWindowService::ACTION_DONE,
                $this->task($report, $taskId)['action'],
                "lightweight should run {$taskId}",
            );
        }

        // ... and SKIPS the heavier / destructive tasks.
        foreach ([
            SelfHealingMaintenanceWindowService::TASK_ARCHIVE_EVIDENCE,
            SelfHealingMaintenanceWindowService::TASK_COMPACT_LEDGER,
            SelfHealingMaintenanceWindowService::TASK_CLEAN_WORKTREES_AND_BRANCHES,
            SelfHealingMaintenanceWindowService::TASK_REPLAY_SAMPLE_CYCLES,
            SelfHealingMaintenanceWindowService::TASK_RUN_MINI_CHAOS_SUITE,
        ] as $taskId) {
            $this->assertSame(
                SelfHealingMaintenanceWindowService::ACTION_SKIPPED,
                $this->task($report, $taskId)['action'],
                "lightweight should skip {$taskId}",
            );
        }
    }

    public function test_full_runs_all_maintenance_tasks_none_skipped(): void
    {
        $input = $this->healthyFixture();
        $input['profile'] = SelfHealingMaintenanceWindowService::PROFILE_FULL;

        $report = $this->service()->run($input);

        $this->assertSame(SelfHealingMaintenanceWindowService::STATUS_OK, $report['status']);
        $this->assertSame(SelfHealingMaintenanceWindowService::PROFILE_FULL, $report['profile']);
        $this->assertSame('3d', $report['promotion_gate']);

        // Full runs every task except the deep-only chaos suite.
        foreach ([
            SelfHealingMaintenanceWindowService::TASK_VERIFY_LOCKS,
            SelfHealingMaintenanceWindowService::TASK_REFRESH_PROVIDER_HEALTH,
            SelfHealingMaintenanceWindowService::TASK_RECALCULATE_BACKLOG_DEPTH,
            SelfHealingMaintenanceWindowService::TASK_ARCHIVE_EVIDENCE,
            SelfHealingMaintenanceWindowService::TASK_COMPACT_LEDGER,
            SelfHealingMaintenanceWindowService::TASK_CLEAN_WORKTREES_AND_BRANCHES,
            SelfHealingMaintenanceWindowService::TASK_REPLAY_SAMPLE_CYCLES,
            SelfHealingMaintenanceWindowService::TASK_EMIT_OPERATOR_SUMMARY,
        ] as $taskId) {
            $this->assertNotSame(
                SelfHealingMaintenanceWindowService::ACTION_SKIPPED,
                $this->task($report, $taskId)['action'],
                "full should run {$taskId}",
            );
        }

        // The chaos suite is deep-only, so full skips it.
        $this->assertSame(
            SelfHealingMaintenanceWindowService::ACTION_SKIPPED,
            $this->task($report, SelfHealingMaintenanceWindowService::TASK_RUN_MINI_CHAOS_SUITE)['action'],
        );
    }

    public function test_deep_runs_chaos_suite_and_gates_7d(): void
    {
        $input = $this->healthyFixture();
        $input['profile'] = SelfHealingMaintenanceWindowService::PROFILE_DEEP;

        $report = $this->service()->run($input);

        $this->assertSame(SelfHealingMaintenanceWindowService::STATUS_OK, $report['status']);
        $this->assertSame('7d', $report['promotion_gate']);
        $this->assertSame(
            SelfHealingMaintenanceWindowService::ACTION_DONE,
            $this->task($report, SelfHealingMaintenanceWindowService::TASK_RUN_MINI_CHAOS_SUITE)['action'],
            'deep profile must run the mini chaos suite',
        );

        // No task is skipped at the deep profile.
        foreach ($report['tasks'] as $row) {
            $this->assertNotSame(
                SelfHealingMaintenanceWindowService::ACTION_SKIPPED,
                $row['action'],
                "deep should run every task ({$row['task']})",
            );
        }
    }

    public function test_destructive_tasks_are_plan_only_never_done(): void
    {
        // HARD RULE: cleanup is PLAN-only here — no real deletion, never `done`.
        $report = $this->service()->run($this->healthyFixture());

        foreach ([
            SelfHealingMaintenanceWindowService::TASK_ARCHIVE_EVIDENCE,
            SelfHealingMaintenanceWindowService::TASK_COMPACT_LEDGER,
            SelfHealingMaintenanceWindowService::TASK_CLEAN_WORKTREES_AND_BRANCHES,
        ] as $taskId) {
            $row = $this->task($report, $taskId);
            $this->assertSame(
                SelfHealingMaintenanceWindowService::ACTION_PLAN,
                $row['action'],
                "destructive task {$taskId} must be PLAN-only",
            );
            $this->assertNotSame(
                SelfHealingMaintenanceWindowService::ACTION_DONE,
                $row['action'],
                "destructive task {$taskId} must NOT report done",
            );
            $this->assertTrue($row['destructive']);
        }

        // The claim policy advertises plan-only + no deletion.
        $this->assertTrue($report['claim_policy']['destructive_tasks_plan_only']);
        $this->assertFalse($report['claim_policy']['deletes_branches']);
        $this->assertFalse($report['claim_policy']['runs_merge']);
    }

    public function test_clean_worktrees_plans_targets_without_deleting(): void
    {
        // The planned destructive targets are surfaced (for the gated execution
        // phase) but the action stays `plan` — nothing is deleted here.
        $report = $this->service()->run($this->healthyFixture());

        $clean = $this->task($report, SelfHealingMaintenanceWindowService::TASK_CLEAN_WORKTREES_AND_BRANCHES);
        $this->assertSame(SelfHealingMaintenanceWindowService::ACTION_PLAN, $clean['action']);
        $this->assertContains('/tmp/wt-1', $clean['planned_targets']['stale_worktrees']);
        $this->assertContains('atlas/old-merged', $clean['planned_targets']['merged_branches']);
    }

    public function test_evidence_preserved_true_before_any_cleanup_task(): void
    {
        // MUST preserve evidence before any cleanup. With evidence preserved, every
        // destructive task reports evidence_preserved=true and is allowed to plan.
        $report = $this->service()->run($this->healthyFixture());

        $this->assertTrue($report['evidence_preserved']);
        foreach ([
            SelfHealingMaintenanceWindowService::TASK_ARCHIVE_EVIDENCE,
            SelfHealingMaintenanceWindowService::TASK_COMPACT_LEDGER,
            SelfHealingMaintenanceWindowService::TASK_CLEAN_WORKTREES_AND_BRANCHES,
        ] as $taskId) {
            $row = $this->task($report, $taskId);
            $this->assertTrue(
                $row['evidence_preserved'],
                "cleanup task {$taskId} must record evidence preserved before planning",
            );
            $this->assertSame(SelfHealingMaintenanceWindowService::ACTION_PLAN, $row['action']);
        }
    }

    public function test_cleanup_is_held_and_blocked_when_evidence_not_preserved(): void
    {
        // NEGATIVE INVARIANT: no cleanup may even be PLANNED before evidence is safe.
        // Evidence not preserved => cleanup tasks held back (skipped) + window blocked.
        $input = $this->healthyFixture();
        $input['evidence_preserved'] = false;

        $report = $this->service()->run($input);

        $this->assertSame(SelfHealingMaintenanceWindowService::STATUS_BLOCKED, $report['status']);
        $this->assertFalse($report['evidence_preserved']);

        foreach ([
            SelfHealingMaintenanceWindowService::TASK_ARCHIVE_EVIDENCE,
            SelfHealingMaintenanceWindowService::TASK_COMPACT_LEDGER,
            SelfHealingMaintenanceWindowService::TASK_CLEAN_WORKTREES_AND_BRANCHES,
        ] as $taskId) {
            $row = $this->task($report, $taskId);
            $this->assertSame(
                SelfHealingMaintenanceWindowService::ACTION_SKIPPED,
                $row['action'],
                "cleanup task {$taskId} must be held back when evidence is not preserved",
            );
            $this->assertFalse($row['evidence_preserved']);
        }

        $heldBlockers = array_filter(
            $report['blockers'],
            static fn (string $b): bool => str_starts_with($b, 'cleanup_task_held_evidence_not_preserved:'),
        );
        $this->assertNotEmpty($heldBlockers);
    }

    public function test_read_only_diagnostics_report_done_and_observations(): void
    {
        $report = $this->service()->run($this->healthyFixture());

        $locks = $this->task($report, SelfHealingMaintenanceWindowService::TASK_VERIFY_LOCKS);
        $this->assertSame(SelfHealingMaintenanceWindowService::ACTION_DONE, $locks['action']);
        $this->assertFalse($locks['destructive']);
        $this->assertTrue($locks['observation']['loop_lock_held']);

        $backlog = $this->task($report, SelfHealingMaintenanceWindowService::TASK_RECALCULATE_BACKLOG_DEPTH);
        $this->assertSame(7, $backlog['observation']['backlog_depth']);

        $provider = $this->task($report, SelfHealingMaintenanceWindowService::TASK_REFRESH_PROVIDER_HEALTH);
        $this->assertSame('healthy', $provider['observation']['provider_health']);
    }

    public function test_operator_summary_describes_the_window(): void
    {
        $report = $this->service()->run($this->healthyFixture());

        $this->assertIsString($report['operator_summary']);
        $this->assertStringContainsString('planned cleanly', $report['operator_summary']);
        $this->assertStringContainsString('not executed', $report['operator_summary']);
    }

    public function test_unknown_profile_falls_back_to_lightweight(): void
    {
        $input = $this->healthyFixture();
        $input['profile'] = 'turbo-nonsense';

        $report = $this->service()->run($input);

        $this->assertSame(SelfHealingMaintenanceWindowService::PROFILE_LIGHTWEIGHT, $report['profile']);
    }

    public function test_accepts_window_request_via_fixture_input_seam(): void
    {
        // The wiring phase passes a whole window request under `fixture`.
        $report = $this->service()->run(['fixture' => $this->healthyFixture()]);

        $this->assertSame(SelfHealingMaintenanceWindowService::STATUS_OK, $report['status']);
        $this->assertSame(SelfHealingMaintenanceWindowService::PROFILE_FULL, $report['profile']);
    }

    public function test_emits_a_stable_report_hash(): void
    {
        $input = $this->healthyFixture();

        $first = $this->service()->run($input);
        $second = $this->service()->run($input);

        $this->assertArrayHasKey('report_hash', $first);
        $this->assertStringStartsWith('sha256:', $first['report_hash']);
        $this->assertSame(
            $first['report_hash'],
            $second['report_hash'],
            'same input must produce an identical report_hash (volatile fields stripped)',
        );

        // A blocked window must hash stably too AND differ from the ok hash.
        $blocked = $input;
        $blocked['evidence_preserved'] = false;
        $b1 = $this->service()->run($blocked);
        $b2 = $this->service()->run($blocked);
        $this->assertSame($b1['report_hash'], $b2['report_hash']);
        $this->assertNotSame($first['report_hash'], $b1['report_hash']);
    }

    public function test_default_empty_input_does_not_crash_and_plans_lightweight(): void
    {
        // Diagnostic default: empty state plans a clean lightweight window, never a crash.
        $report = $this->service()->run();

        $this->assertSame(SelfHealingMaintenanceWindowService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(SelfHealingMaintenanceWindowService::STATUS_OK, $report['status']);
        $this->assertSame(SelfHealingMaintenanceWindowService::PROFILE_LIGHTWEIGHT, $report['profile']);
        $this->assertSame('LHL-15', $report['slice_id']);
        $this->assertSame('AP-809', $report['ap_contract']);
        $this->assertTrue($report['evidence_preserved']);
        $this->assertNotEmpty($report['tasks']);
    }
}
