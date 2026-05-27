<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchSystemCertificationService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class StewardshipBranchSystemCertificationServiceTest extends TestCase
{
    public function test_certifies_current_branch_stack_cluster(): void
    {
        $report = app(StewardshipBranchSystemCertificationService::class)->certify([
            'repo_root' => base_path(),
        ]);

        $this->assertSame(StewardshipBranchSystemCertificationService::STATUS_CERTIFIED, $report['status']);
        $this->assertSame('AP-776', $report['ap_contract']);
        $this->assertCount(10, $report['components']);
        $this->assertSame([], $report['blockers']);
        $this->assertTrue($report['claim_policy']['read_only']);
        $this->assertFalse($report['claim_policy']['merge_performed']);
        $this->assertTrue($report['command_actions']['coverage']['branch-system-certify']);
        $this->assertTrue($report['command_actions']['coverage']['branch-stress-certify']);
        $this->assertSame('ready', $report['policy_matrix']['safe_auto_merge']['status']);
        $this->assertSame('ready', $report['policy_matrix']['parallel_collision_prevention']['status']);
        $this->assertSame('ready', $report['policy_matrix']['real_git_stress']['status']);
        $this->assertSame('ready', $report['policy_matrix']['operator_review_packet']['status']);
        $this->assertSame('ready', $report['policy_matrix']['dirty_base_integration_lane']['status']);
        $this->assertSame('not_run', $report['optional_readiness_extensions']['branch_stress']['status']);
    }

    public function test_include_branch_stress_extension_can_embed_ap779_results(): void
    {
        $report = app(StewardshipBranchSystemCertificationService::class)->certify([
            'repo_root' => base_path(),
            'include_branch_stress' => true,
        ]);

        $this->assertSame(StewardshipBranchSystemCertificationService::STATUS_CERTIFIED, $report['status']);
        $this->assertSame('certified', $report['optional_readiness_extensions']['branch_stress']['status']);
        $this->assertSame(14, $report['optional_readiness_extensions']['branch_stress']['scenario_count']);
        $this->assertSame(14, $report['optional_readiness_extensions']['branch_stress']['passed_scenario_count']);
    }

    public function test_blocks_when_required_repo_files_are_missing(): void
    {
        $repo = sys_get_temp_dir().'/atlas_ap776_missing_'.uniqid('', true);
        File::ensureDirectoryExists($repo);

        try {
            $report = app(StewardshipBranchSystemCertificationService::class)->certify([
                'repo_root' => $repo,
            ]);

            $this->assertSame(StewardshipBranchSystemCertificationService::STATUS_BLOCKED, $report['status']);
            $this->assertContains('command_action_coverage_incomplete', $report['blockers']);
            $this->assertContains('component_not_ready:branch_merge_governor', $report['blockers']);
            $this->assertFalse($report['command_actions']['command_file_exists']);
        } finally {
            File::deleteDirectory($repo);
        }
    }

    public function test_enterprise_requirements_cover_original_operator_goal(): void
    {
        $report = app(StewardshipBranchSystemCertificationService::class)->certify([
            'repo_root' => base_path(),
        ]);

        $requirements = array_keys($report['enterprise_requirements']);

        $this->assertContains('gitkraken_visual_branch_review', $requirements);
        $this->assertContains('conflict_extermination_maximum', $requirements);
        $this->assertContains('automatic_safe_merge', $requirements);
        $this->assertContains('priority_by_advancement_and_robustness', $requirements);
        $this->assertContains('operator_auditability', $requirements);
        $this->assertContains('real_git_stress_certification', $requirements);
        $this->assertContains('single_operator_review_packet', $requirements);
        $this->assertContains('dirty_base_safe_progress', $requirements);
    }
}
