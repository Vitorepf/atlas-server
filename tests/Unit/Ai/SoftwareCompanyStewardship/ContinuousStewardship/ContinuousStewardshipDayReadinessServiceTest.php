<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\ContinuousStewardship;

use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipDayReadinessService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class ContinuousStewardshipDayReadinessServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap777_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): ContinuousStewardshipDayReadinessService
    {
        $service = app(ContinuousStewardshipDayReadinessService::class);
        $service->setStorageRootForTesting($this->tmp.'/readiness');

        return $service;
    }

    public function test_default_blocks_because_runner_is_disabled_by_default(): void
    {
        $report = $this->service()->assess([
            'repo_root' => base_path(),
        ]);

        $this->assertSame(ContinuousStewardshipDayReadinessService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('continuous_runner_not_enabled', $report['blockers']);
        $this->assertContains('continuous_runner_status_not_ready', $report['blockers']);
        $this->assertTrue($report['claim_policy']['read_only']);
        $this->assertFalse($report['claim_policy']['starts_loop']);
    }

    public function test_ready_when_runner_branch_system_surfaces_and_budget_are_ready(): void
    {
        $report = $this->service()->assess([
            'repo_root' => base_path(),
            'enabled' => true,
            'duration_hours' => 24,
            'max_runs_per_day' => 48,
            'min_interval_seconds' => 900,
            'lock_ttl_seconds' => 600,
        ]);

        $this->assertSame(ContinuousStewardshipDayReadinessService::STATUS_READY, $report['status']);
        $this->assertSame([], $report['blockers']);
        $this->assertSame('certified', $report['branch_system_certification']['status']);
        $this->assertSame('ready', $report['runner_status']['status']);
        $this->assertStringContainsString('continuous-runner', $report['operator_start_command']);
        $this->assertTrue($report['command_coverage']['coverage']['continuous-24h-readiness']);
        $this->assertTrue($report['surface_coverage']['class_coverage']['product_mode_cockpit']);
    }

    public function test_blocks_dangerously_low_interval_and_zero_budget(): void
    {
        $report = $this->service()->assess([
            'repo_root' => base_path(),
            'enabled' => true,
            'duration_hours' => 24,
            'max_runs_per_day' => 0,
            'min_interval_seconds' => 0,
        ]);

        $this->assertSame(ContinuousStewardshipDayReadinessService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('daily_budget_must_allow_at_least_one_run', $report['blockers']);
        $this->assertContains('min_interval_too_low_for_24h_run', $report['blockers']);
    }

    public function test_blocks_when_branch_system_cannot_be_certified(): void
    {
        $repo = $this->tmp.'/empty-repo';
        File::ensureDirectoryExists($repo);

        $report = $this->service()->assess([
            'repo_root' => $repo,
            'enabled' => true,
            'duration_hours' => 24,
            'max_runs_per_day' => 48,
            'min_interval_seconds' => 900,
        ]);

        $this->assertSame(ContinuousStewardshipDayReadinessService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('branch_system_not_certified', $report['blockers']);
    }
}
