<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AlwaysOnLoopSupervisorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\BacklogDepthGovernorCheckContract;
use Tests\TestCase;

final class AlwaysOnLoopSupervisorServiceTest extends TestCase
{
    private function service(): AlwaysOnLoopSupervisorService
    {
        return app(AlwaysOnLoopSupervisorService::class);
    }

    /**
     * A fresh heartbeat with a fresh-pass AP-808 assurance and no orphan
     * processes / stale locks. Tests mutate a copy of this to drive each case.
     *
     * @return array<string,mixed>
     */
    private function healthyFixture(): array
    {
        return [
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'run_id' => 'run-001',
            'heartbeat_age' => 30,
            'max_heartbeat_age_seconds' => 900,
            'orphan_provider_processes' => 0,
            'stale_locks' => [],
            'assurance_status' => 'pass',
            'assurance_age_seconds' => 120,
            'max_cert_age_seconds' => 86400,
            'operator_visible_status' => 'loop running, cycle 12/100',
            'certifications' => [
                'ap807' => ['status' => 'pass', 'age_seconds' => 300],
                'ap808' => ['status' => 'pass', 'age_seconds' => 120],
                'ap809' => ['status' => 'pass', 'age_seconds' => 300],
            ],
            'restart_requested' => false,
        ];
    }

    public function test_healthy_heartbeat_and_fresh_assurance_is_healthy(): void
    {
        $report = $this->service()->assess($this->healthyFixture());

        $this->assertSame(AlwaysOnLoopSupervisorService::STATUS_HEALTHY, $report['status']);
        $this->assertTrue($report['heartbeat']['fresh']);
        $this->assertTrue($report['assurance']['fresh_pass']);
        $this->assertFalse($report['restart_recommended']);
        $this->assertNull($report['restart_refused_reason']);
        $this->assertSame([], $report['orphan_cleanup_plan']);
        $this->assertSame([], $report['blockers']);
        $this->assertSame('continue', $report['next_action']);
        $this->assertSame('AP-809', $report['ap_contract']);
        $this->assertSame('LHL-13', $report['slice_id']);
        $this->assertSame(AlwaysOnLoopSupervisorService::REPORT_SCHEMA, $report['schema_version']);
    }

    public function test_stale_ap808_assurance_blocks_and_refuses_restart(): void
    {
        $input = $this->healthyFixture();
        $input['assurance_age_seconds'] = 999999; // older than the 24h floor
        $input['heartbeat_age'] = 5000; // a stale heartbeat would normally want a restart
        $input['restart_requested'] = true;

        $report = $this->service()->assess($input);

        $this->assertSame(AlwaysOnLoopSupervisorService::STATUS_BLOCKED, $report['status']);
        $this->assertFalse($report['restart_recommended']);
        $this->assertSame('assurance_certification_stale', $report['restart_refused_reason']);
        $this->assertFalse($report['assurance']['fresh_pass']);
        $this->assertContains('ap808_assurance_stale', $report['blockers']);
        $this->assertSame('hold_blocked', $report['next_action']);
    }

    public function test_failed_ap808_assurance_blocks_and_refuses_restart(): void
    {
        $input = $this->healthyFixture();
        $input['assurance_status'] = 'failed';
        $input['heartbeat_age'] = 5000;
        $input['restart_requested'] = true;

        $report = $this->service()->assess($input);

        $this->assertSame(AlwaysOnLoopSupervisorService::STATUS_BLOCKED, $report['status']);
        $this->assertFalse($report['restart_recommended']);
        $this->assertSame('assurance_certification_failed', $report['restart_refused_reason']);
        $this->assertContains('ap808_assurance_failed', $report['blockers']);
    }

    public function test_orphan_processes_produce_a_plan_only_cleanup_with_no_execution(): void
    {
        $input = $this->healthyFixture();
        $input['orphan_provider_processes'] = 3;
        $input['orphan_provider_pids'] = [4011, '4012', 'not-a-pid'];

        $report = $this->service()->assess($input);

        // Orphan procs warrant a restart, and assurance is a fresh pass => eligible.
        $this->assertSame(AlwaysOnLoopSupervisorService::STATUS_RESTART_ELIGIBLE, $report['status']);
        $this->assertTrue($report['restart_recommended']);
        $this->assertCount(1, $report['orphan_cleanup_plan']);

        $plan = $report['orphan_cleanup_plan'][0];
        $this->assertSame('kill_orphan_provider_processes', $plan['action']);
        $this->assertSame(3, $plan['count']);
        $this->assertSame([4011, 4012], $plan['pids']); // non-numeric pid dropped
        $this->assertFalse($plan['executed'], 'orphan cleanup must be plan-only — never executed here');
        $this->assertTrue($plan['plan_only']);
        $this->assertSame('recommend_safe_restart', $report['next_action']);
    }

    public function test_stale_heartbeat_with_fresh_assurance_is_restart_eligible(): void
    {
        $input = $this->healthyFixture();
        $input['heartbeat_age'] = 5000; // beyond the 900s floor

        $report = $this->service()->assess($input);

        $this->assertSame(AlwaysOnLoopSupervisorService::STATUS_RESTART_ELIGIBLE, $report['status']);
        $this->assertFalse($report['heartbeat']['fresh']);
        $this->assertTrue($report['restart_recommended']);
        $this->assertNull($report['restart_refused_reason']);
        $this->assertContains('heartbeat_stale', $report['blockers']);
    }

    public function test_stale_locks_surface_as_plan_and_trigger_restart_when_assurance_fresh(): void
    {
        $input = $this->healthyFixture();
        $input['stale_loop_lock'] = true;
        $input['stale_merge_lock'] = true;

        $report = $this->service()->assess($input);

        $this->assertSame(['loop_lock', 'merge_lock'], $report['stale_locks']);
        $this->assertTrue($report['restart_recommended']);
        $this->assertContains('stale_locks_present', $report['warnings']);
        $this->assertSame(AlwaysOnLoopSupervisorService::STATUS_RESTART_ELIGIBLE, $report['status']);
    }

    public function test_never_mutates_code_or_merge_claim_policy_is_locked(): void
    {
        $report = $this->service()->assess($this->healthyFixture());

        $policy = $report['claim_policy'];
        $this->assertTrue($policy['read_only']);
        $this->assertFalse($policy['runs_provider']);
        $this->assertFalse($policy['runs_loop']);
        $this->assertFalse($policy['runs_merge']);
        $this->assertFalse($policy['mutates_code']);
        $this->assertFalse($policy['kills_processes']);
        $this->assertFalse($policy['deletes_branches']);
        $this->assertTrue($policy['cleanup_is_plan_only']);
        $this->assertTrue($policy['blocked_never_dressed_as_healthy']);
    }

    public function test_blocked_is_never_dressed_as_healthy_when_assurance_not_passing(): void
    {
        // A non-pass (degraded) assurance refuses restart and is never healthy,
        // even with a perfectly fresh heartbeat.
        $input = $this->healthyFixture();
        $input['assurance_status'] = 'degraded';

        $report = $this->service()->assess($input);

        $this->assertSame(AlwaysOnLoopSupervisorService::STATUS_BLOCKED, $report['status']);
        $this->assertNotSame(AlwaysOnLoopSupervisorService::STATUS_HEALTHY, $report['status']);
        $this->assertFalse($report['restart_recommended']);
        $this->assertSame('assurance_certification_not_passing', $report['restart_refused_reason']);
    }

    public function test_accepts_snapshot_via_fixture_input_seam(): void
    {
        // The wiring phase passes a whole snapshot under `fixture`; direct keys win.
        $report = $this->service()->assess(['fixture' => $this->healthyFixture()]);

        $this->assertSame(AlwaysOnLoopSupervisorService::STATUS_HEALTHY, $report['status']);
        $this->assertTrue($report['heartbeat']['fresh']);
    }

    public function test_emits_a_stable_deterministic_report_hash(): void
    {
        $input = $this->healthyFixture();

        $first = $this->service()->assess($input);
        $second = $this->service()->assess($input);

        $this->assertArrayHasKey('report_hash', $first);
        $this->assertStringStartsWith('sha256:', $first['report_hash']);
        $this->assertSame(
            $first['report_hash'],
            $second['report_hash'],
            'same input must produce an identical report_hash (volatile fields stripped)',
        );

        // A blocked input hashes stably too AND differs from the healthy hash.
        $blocked = $input;
        $blocked['assurance_status'] = 'failed';
        $b1 = $this->service()->assess($blocked);
        $b2 = $this->service()->assess($blocked);
        $this->assertSame($b1['report_hash'], $b2['report_hash']);
        $this->assertNotSame($first['report_hash'], $b1['report_hash']);
    }

    public function test_backlog_depth_governor_check_contract_is_a_dedicated_psr4_class(): void
    {
        $contractPath = app_path(
            'Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/BacklogDepthGovernorCheckContract.php',
        );
        $supervisorPath = app_path(
            'Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AlwaysOnLoopSupervisorService.php',
        );

        $this->assertFileExists($contractPath);
        $this->assertTrue(class_exists(BacklogDepthGovernorCheckContract::class));
        $this->assertStringNotContainsString(
            'class BacklogDepthGovernorCheckContract',
            (string) file_get_contents($supervisorPath),
            'the backlog depth governor check contract must not live inside AlwaysOnLoopSupervisorService',
        );
    }

    public function test_default_empty_input_does_not_crash_and_blocks_honestly(): void
    {
        // Diagnostic default: nothing proven fresh => honestly blocked, never a
        // crash and never a fabricated healthy.
        $report = $this->service()->assess();

        $this->assertSame(AlwaysOnLoopSupervisorService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(AlwaysOnLoopSupervisorService::STATUS_BLOCKED, $report['status']);
        $this->assertFalse($report['restart_recommended']);
        $this->assertContains('heartbeat_age_unknown', $report['blockers']);
        $this->assertSame('assurance_status_unknown', $report['restart_refused_reason']);
        $this->assertSame('LHL-13', $report['slice_id']);
        $this->assertSame('AP-809', $report['ap_contract']);
    }
}
