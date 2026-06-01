<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\TenCycleReadinessGovernorService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * AP-805 — read-only 10-cycle readiness governor. All external state (repo, git,
 * provider config, capabilities, leases, locks, Product Mode) is supplied via
 * input overrides so the verdict is deterministic and never depends on live state.
 */
final class TenCycleReadinessGovernorServiceTest extends TestCase
{
    private function service(): TenCycleReadinessGovernorService
    {
        return app(TenCycleReadinessGovernorService::class);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function readyInput(array $overrides = []): array
    {
        return array_replace([
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'repo_root' => sys_get_temp_dir(),
            'repo_status' => [],                 // clean
            'provider_timeout_dev' => 600,
            'provider_timeout_cursor' => 600,
            'include_provider_probe' => true,
            'provider_binaries' => ['cursor-agent', 'claude'],
            'capability_overrides' => [
                'provider_port_session_store' => true,
                'finding_slice_planner' => true,
                'lane_orchestrator' => true,
                'integration_judge' => true,
                'repair_planner' => true,
                'ap793_substrate_facts' => true,
                'ap792_harness' => true,
            ],
            'merge_truth_guard_present' => true,
            'kill_switch_available' => true,
            'lock_held' => false,
            'stale_lock' => false,
            'open_leases' => 0,
            'include_product_mode' => true,
            'product_mode_memory_safe' => true,
            'include_branch_audit' => true,
            'branches' => [],
            'merged_branches' => [],
            'worktree_count' => 1,
            'docs_health' => true,
            'architecture_validate' => true,
        ], $overrides);
    }

    public function test_ready_with_clean_fixtures(): void
    {
        $report = $this->service()->assess($this->readyInput());

        $this->assertSame(TenCycleReadinessGovernorService::STATUS_READY, $report['status']);
        $this->assertSame([], $report['blockers']);
        $this->assertSame([], $report['warnings']);
        $this->assertSame([], $report['blocking_warnings']);
        $this->assertStringStartsWith('sha256:', (string) $report['report_hash']);
    }

    public function test_soft_validation_failures_do_not_block_when_operational_gates_are_ready(): void
    {
        $report = $this->service()->assess($this->readyInput([
            'docs_health' => false,
            'architecture_validate' => false,
        ]));

        $this->assertSame(TenCycleReadinessGovernorService::STATUS_READY, $report['status']);
        $this->assertSame([], $report['blockers']);
        $this->assertSame([], $report['blocking_warnings']);
        $this->assertContains('docs_health_issues', $report['warnings']);
        $this->assertContains('architecture_validate_violations', $report['warnings']);
        $this->assertFalse($report['gates']['docs_health_ok']['ok']);
        $this->assertFalse($report['gates']['docs_health_ok']['hard']);
        $this->assertFalse($report['gates']['architecture_validate_ok']['ok']);
        $this->assertFalse($report['gates']['architecture_validate_ok']['hard']);
        $this->assertFalse(str_starts_with((string) $report['recommended_command_for_10_cycle_run'], 'DO NOT RUN'));
    }

    public function test_missing_operational_probes_still_make_readiness_partial(): void
    {
        $report = $this->service()->assess($this->readyInput([
            'include_provider_probe' => false,
        ]));

        $this->assertSame(TenCycleReadinessGovernorService::STATUS_PARTIAL, $report['status']);
        $this->assertSame([], $report['blockers']);
        $this->assertContains('provider_probe_skipped', $report['warnings']);
        $this->assertContains('provider_probe_skipped', $report['blocking_warnings']);
        $this->assertStringStartsWith('DO NOT RUN', (string) $report['recommended_command_for_10_cycle_run']);
    }

    public function test_blocked_when_provider_unavailable_without_fallback(): void
    {
        $report = $this->service()->assess($this->readyInput([
            'provider_binaries' => [], // probe on, nothing available
        ]));

        $this->assertSame(TenCycleReadinessGovernorService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('no_provider_available_no_fallback', $report['blockers']);
        $this->assertStringStartsWith('DO NOT RUN', (string) $report['recommended_command_for_10_cycle_run']);
    }

    public function test_blocked_when_branch_lease_open(): void
    {
        $report = $this->service()->assess($this->readyInput(['open_leases' => 1]));

        $this->assertSame(TenCycleReadinessGovernorService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('open_repo_merge_lease', $report['blockers']);
    }

    public function test_blocked_when_loop_runner_branch_is_not_promoted_to_main(): void
    {
        $report = $this->service()->assess($this->readyInput([
            'loop_runner_current_branch' => 'atlas/loop-runner/agentic-engineering-os-dev-forge',
            'loop_runner_current_head' => 'controller-head',
            'main_head' => 'main-head',
        ]));

        $this->assertSame(TenCycleReadinessGovernorService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('loop_runner_branch_not_promoted_to_main', $report['blockers']);
        $this->assertFalse($report['gates']['loop_runner_branch_at_main']['ok']);
        $this->assertTrue($report['gates']['loop_runner_branch_at_main']['hard']);
    }

    public function test_blocked_when_product_mode_oom(): void
    {
        $report = $this->service()->assess($this->readyInput(['product_mode_memory_safe' => false]));

        $this->assertSame(TenCycleReadinessGovernorService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('product_mode_oom_risk', $report['blockers']);
        $this->assertFalse($report['gates']['product_mode_projection_memory_safe']['ok']);
        $this->assertTrue($report['gates']['product_mode_projection_memory_safe']['hard']);
    }

    public function test_blocked_when_multi_agent_capabilities_absent(): void
    {
        $report = $this->service()->assess($this->readyInput([
            'capability_overrides' => [
                'provider_port_session_store' => false,
                'finding_slice_planner' => false,
                'lane_orchestrator' => false,
                'integration_judge' => false,
                'repair_planner' => false,
                'ap793_substrate_facts' => false,
                'ap792_harness' => false,
            ],
        ]));

        $this->assertSame(TenCycleReadinessGovernorService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('lane_orchestrator_missing', $report['blockers']);
        $this->assertContains('judge_or_repair_missing', $report['blockers']);
    }

    public function test_stale_lock_blocks(): void
    {
        $report = $this->service()->assess($this->readyInput(['stale_lock' => true]));

        $this->assertSame(TenCycleReadinessGovernorService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('loop_lock_held', $report['blockers']);
    }

    public function test_warns_l1_worktree_isolation_not_l2_process_isolation(): void
    {
        $report = $this->service()->assess($this->readyInput());

        // L1 worktree isolation is acknowledged with an explicit warning field;
        // missing L2 process isolation (containers) is NOT a blocker.
        $this->assertSame('L1_git_worktree', $report['multi_agent_state']['isolation_level']);
        $this->assertStringContainsString('L2', (string) $report['multi_agent_state']['isolation_warning']);
        $this->assertFalse($report['multi_agent_state']['l2_process_isolation_present']);
        $this->assertNotContains('l2_process_isolation_missing', $report['blockers']);
        // Still ready overall — L1 is an accepted limitation, not a blocker.
        $this->assertSame(TenCycleReadinessGovernorService::STATUS_READY, $report['status']);
    }

    public function test_cleanup_plan_lists_old_branches_without_deleting(): void
    {
        $report = $this->service()->assess($this->readyInput([
            'branches' => [
                'atlas/area-focus/agentic_engineering_os/atlas_dev/aaaa',
                'atlas/area-focus/agentic_engineering_os/atlas_dev/bbbb',
            ],
            'merged_branches' => ['atlas/area-focus/agentic_engineering_os/atlas_dev/aaaa'],
        ]));

        $plan = $report['cleanup_plan'];
        $this->assertCount(2, $plan);
        foreach ($plan as $entry) {
            $this->assertFalse($entry['destructive']);
        }
        $merged = array_values(array_filter($plan, static fn (array $e): bool => $e['merged_into_main'] === true));
        $this->assertCount(1, $merged);
        $this->assertSame('safe_to_delete_after_run', $merged[0]['recommended_action']);
        // The governor reports a plan; it never deletes.
        $this->assertFalse($report['claim_policy']['deletes_branches']);
    }

    public function test_recommended_command_includes_safe_flags_when_ready(): void
    {
        $cmd = (string) $this->service()->assess($this->readyInput())['recommended_command_for_10_cycle_run'];

        $this->assertStringContainsString('reliable-24h-loop', $cmd);
        $this->assertStringContainsString('--max-cycles=12', $cmd);
        $this->assertStringContainsString('--max-merges=10', $cmd);
        $this->assertStringContainsString('--continue-on-blocked', $cmd);
        $this->assertStringContainsString('--cleanup-worktrees', $cmd);
        $this->assertStringContainsString('--multi-agent-workcell', $cmd);
        $this->assertStringContainsString('--scope-profile=factory_max', $cmd);
    }

    public function test_command_smoke_json(): void
    {
        $exit = Artisan::call('atlas:software-company-stewardship', [
            'action' => 'ten-cycle-readiness',
            '--area' => 'agentic_engineering_os',
            '--focus' => 'dev_forge',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertIsString($output);
        $decoded = json_decode(trim($output), true);
        $this->assertIsArray($decoded);
        $this->assertSame(TenCycleReadinessGovernorService::REPORT_SCHEMA, $decoded['schema_version']);
        $this->assertContains($decoded['status'], [
            TenCycleReadinessGovernorService::STATUS_READY,
            TenCycleReadinessGovernorService::STATUS_PARTIAL,
            TenCycleReadinessGovernorService::STATUS_BLOCKED,
        ]);
        $this->assertArrayHasKey('recommended_command_for_10_cycle_run', $decoded);
        $this->assertContains($exit, [0, 1]); // non-strict smoke: exit 0; strict-blocked would be 1
    }
}
