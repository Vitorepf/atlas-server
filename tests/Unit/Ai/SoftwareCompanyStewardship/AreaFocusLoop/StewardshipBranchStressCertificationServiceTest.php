<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchStressCertificationService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class StewardshipBranchStressCertificationServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap779_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);

        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for AP-779 branch stress certification tests.');
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_certifies_branch_stack_with_real_git_stress_scenarios(): void
    {
        $report = app(StewardshipBranchStressCertificationService::class)->certify([
            'tmp_root' => $this->tmp.'/stress',
            'preserve_tmp' => true,
            'skip_branch_system_certification' => true,
        ]);

        $this->assertSame(StewardshipBranchStressCertificationService::STATUS_CERTIFIED, $report['status']);
        $this->assertSame('AP-779', $report['ap_contract']);
        $this->assertSame(14, $report['scenario_count']);
        $this->assertSame(14, $report['passed_scenario_count']);
        $this->assertSame([], $report['blockers']);
        $this->assertTrue($report['enterprise_guarantees']['gitkraken_visual_review_metadata_proven']);
        $this->assertTrue($report['enterprise_guarantees']['docs_auto_merge_ff_only_proven']);
        $this->assertTrue($report['enterprise_guarantees']['tests_auto_merge_ff_only_proven']);
        $this->assertTrue($report['enterprise_guarantees']['tests_validation_green_path_proven']);
        $this->assertTrue($report['enterprise_guarantees']['code_requires_operator_review_proven']);
        $this->assertTrue($report['enterprise_guarantees']['conflict_blocks_before_merge_proven']);
        $this->assertTrue($report['enterprise_guarantees']['stale_branch_blocks_before_merge_proven']);
        $this->assertTrue($report['enterprise_guarantees']['orphan_branch_blocks_before_queue_proven']);
        $this->assertTrue($report['enterprise_guarantees']['repo_merge_lease_collision_blocks_proven']);
        $this->assertTrue($report['enterprise_guarantees']['merge_queue_respects_repo_merge_lease_proven']);
        $this->assertTrue($report['enterprise_guarantees']['merge_queue_never_rebase_squash_force_push_proven']);
        $this->assertTrue($report['enterprise_guarantees']['main_remains_primary_integration_branch_proven']);
        $this->assertTrue($report['enterprise_guarantees']['priority_orders_advancement_before_cosmetic_proven']);
        $this->assertTrue($report['enterprise_guarantees']['merge_queue_priority_orders_advancement_proven']);
        $this->assertTrue($report['claim_policy']['real_git_commands_invoked']);
        $this->assertFalse($report['claim_policy']['mutates_target_repo']);
        $this->assertFalse($report['claim_policy']['force_push_performed']);
        $this->assertStringStartsWith('sha256:', $report['stress_certification_hash']);
    }

    public function test_compacts_scenario_evidence_without_losing_blockers_and_review_surface(): void
    {
        $report = app(StewardshipBranchStressCertificationService::class)->certify([
            'tmp_root' => $this->tmp.'/stress',
            'preserve_tmp' => true,
            'skip_branch_system_certification' => true,
        ]);

        $scenarios = collect($report['scenarios'])->keyBy('id');

        $this->assertSame('passed', $scenarios['docs_only_auto_merge']['status']);
        $this->assertSame('branch_on_top_of_base', data_get($scenarios['docs_only_auto_merge'], 'evidence.gitkraken_review_surface.graph_shape'));
        $this->assertSame('passed', $scenarios['conflict_blocks_before_merge']['status']);
        $this->assertContains('merge_conflict_detected', data_get($scenarios['conflict_blocks_before_merge'], 'evidence.blockers'));
        $this->assertSame('passed', $scenarios['lease_collision_blocks']['status']);
        $this->assertContains('active_merge_lease_exists', data_get($scenarios['lease_collision_blocks'], 'evidence.second_blockers'));
        $this->assertSame('passed', $scenarios['orphan_branch_blocks']['status']);
        $this->assertContains('missing_active_lifecycle_registry_record', data_get($scenarios['orphan_branch_blocks'], 'evidence.branches.0.blockers'));
        $this->assertSame('finding_stress_001', data_get($scenarios['gitkraken_cycle_metadata_clear'], 'evidence.gitkraken_review_surface.cycle_traceability.finding_id'));
    }
}
