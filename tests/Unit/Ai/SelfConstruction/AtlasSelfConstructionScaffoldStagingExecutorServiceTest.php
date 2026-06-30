<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionScaffoldStagingExecutorService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService;
use Tests\TestCase;

class AtlasSelfConstructionScaffoldStagingExecutorServiceTest extends TestCase
{
    private string $proposalsLog;

    private string $approvalsLog;

    private string $kernelLog;

    private string $admissionLog;

    private string $receiptsLog;

    private string $stagingRoot;

    private AtlasSelfConstructionSubsystemBuilderService $ascb;

    private AtlasSelfConstructionScaffoldStagingExecutorService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid('', true);
        $this->proposalsLog = sys_get_temp_dir()."/atlas_stage_prop_{$u}.jsonl";
        $this->approvalsLog = sys_get_temp_dir()."/atlas_stage_appr_{$u}.jsonl";
        $this->kernelLog = sys_get_temp_dir()."/atlas_stage_kernel_{$u}.jsonl";
        $this->admissionLog = sys_get_temp_dir()."/atlas_stage_adm_{$u}.jsonl";
        $this->receiptsLog = sys_get_temp_dir()."/atlas_stage_receipts_{$u}.jsonl";
        $this->stagingRoot = sys_get_temp_dir()."/atlas_stage_root_{$u}";

        $scoreCard = $this->app->make(AtlasCognitionScoreCardService::class);
        $this->ascb = new AtlasSelfConstructionSubsystemBuilderService($scoreCard);
        $this->ascb->setProposalsLogPathForTesting($this->proposalsLog);
        $this->ascb->setApprovalsLogPathForTesting($this->approvalsLog);

        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($this->kernelLog);
        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting($this->admissionLog);

        $this->svc = new AtlasSelfConstructionScaffoldStagingExecutorService($this->ascb, $kernel, $admission);
        $this->svc->setStagingRootForTesting($this->stagingRoot);
        $this->svc->setReceiptsLogPathForTesting($this->receiptsLog);
    }

    protected function tearDown(): void
    {
        @unlink($this->proposalsLog);
        @unlink($this->approvalsLog);
        @unlink($this->kernelLog);
        @unlink($this->admissionLog);
        @unlink($this->receiptsLog);
        $this->rrmdir($this->stagingRoot);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.'/*') ?: [] as $f) {
            is_dir($f) ? $this->rrmdir($f) : @unlink($f);
        }
        @rmdir($dir);
    }

    public function test_stage_requires_approved_proposal(): void
    {
        $p = $this->ascb->propose([
            'gap_kind' => AtlasSelfConstructionSubsystemBuilderService::GAP_OPERATOR_REQUEST,
            'subsystem_acronym' => 'TESTSTG',
            'subsystem_name' => 'Test Staging Subsystem',
            'group' => 'self_construction',
        ]);
        // Skip approval — should be rejected_not_approved.
        $r = $this->svc->stage($p['proposal_id'], $p['proposal_hash']);
        $this->assertSame(AtlasSelfConstructionScaffoldStagingExecutorService::STATUS_REJECTED_NOT_APPROVED, $r['status']);
        $this->assertSame([], $r['written_files']);
    }

    public function test_stage_succeeds_when_approved(): void
    {
        $p = $this->ascb->propose([
            'gap_kind' => AtlasSelfConstructionSubsystemBuilderService::GAP_OPERATOR_REQUEST,
            'subsystem_acronym' => 'TESTSTG2',
            'subsystem_name' => 'Test Staging 2',
            'group' => 'self_construction',
        ]);
        $this->ascb->approve([
            'proposal_id' => $p['proposal_id'],
            'proposal_hash' => $p['proposal_hash'],
            'action' => AtlasSelfConstructionSubsystemBuilderService::APPROVAL_APPROVE,
            'actor' => 'operator',
        ]);

        $r = $this->svc->stage($p['proposal_id'], $p['proposal_hash']);
        $this->assertSame(AtlasSelfConstructionScaffoldStagingExecutorService::STATUS_STAGED, $r['status']);
        $this->assertNotEmpty($r['written_files']);
        foreach ($r['written_files'] as $f) {
            $this->assertFileExists($f);
            // Path traversal protection: every file MUST be under staging root.
            $this->assertStringStartsWith($this->stagingRoot, $f);
        }
    }

    public function test_unknown_proposal_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->stage('prop_unknown', 'sha256:bogus');
    }

    public function test_hash_mismatch_throws(): void
    {
        $p = $this->ascb->propose([
            'gap_kind' => AtlasSelfConstructionSubsystemBuilderService::GAP_OPERATOR_REQUEST,
            'subsystem_acronym' => 'TESTSTG3',
            'subsystem_name' => 'Hash Mismatch Test',
            'group' => 'self_construction',
        ]);
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->stage($p['proposal_id'], 'sha256:wrong_hash');
    }

    public function test_receipts_persisted_append_only(): void
    {
        $p = $this->ascb->propose([
            'gap_kind' => AtlasSelfConstructionSubsystemBuilderService::GAP_OPERATOR_REQUEST,
            'subsystem_acronym' => 'TESTRC',
            'subsystem_name' => 'Receipt Test',
            'group' => 'self_construction',
        ]);
        $this->svc->stage($p['proposal_id'], $p['proposal_hash']);
        $this->svc->stage($p['proposal_id'], $p['proposal_hash']);
        $this->assertCount(2, $this->svc->listReceipts());
    }

    public function test_validate_plan_blocks_non_dry_run(): void
    {
        $result = AtlasSelfConstructionScaffoldStagingExecutorService::validatePlan([
            'dry_run' => false,
            'rollback_hint' => 'revert_staged_dir',
            'target_paths' => ['storage/atlas/staged/foo.php'],
        ]);
        $this->assertSame(AtlasSelfConstructionScaffoldStagingExecutorService::PLAN_BLOCKED, $result['verdict']);
        $this->assertContains('non_dry_run_rejected', $result['blockers']);
    }

    public function test_validate_plan_blocks_missing_rollback_hint(): void
    {
        $result = AtlasSelfConstructionScaffoldStagingExecutorService::validatePlan([
            'dry_run' => true,
            'rollback_hint' => '',
            'target_paths' => ['storage/atlas/staged/foo.php'],
        ]);
        $this->assertSame(AtlasSelfConstructionScaffoldStagingExecutorService::PLAN_BLOCKED, $result['verdict']);
        $this->assertContains('rollback_hint_missing', $result['blockers']);
    }

    public function test_validate_plan_blocks_production_path_mutation(): void
    {
        $result = AtlasSelfConstructionScaffoldStagingExecutorService::validatePlan([
            'dry_run' => true,
            'rollback_hint' => 'revert_staged_dir',
            'target_paths' => ['app/Services/Foo.php'],
        ]);
        $this->assertSame(AtlasSelfConstructionScaffoldStagingExecutorService::PLAN_BLOCKED, $result['verdict']);
        $this->assertTrue(count(array_filter($result['blockers'], fn ($b) => str_starts_with($b, 'production_path_mutation:'))) > 0);
    }

    public function test_validate_plan_emits_stable_artifact_hash_for_valid_plan(): void
    {
        $plan = [
            'dry_run' => true,
            'rollback_hint' => 'revert_staged_dir',
            'target_paths' => ['storage/atlas/staged/foo.php'],
        ];
        $a = AtlasSelfConstructionScaffoldStagingExecutorService::validatePlan($plan);
        $b = AtlasSelfConstructionScaffoldStagingExecutorService::validatePlan($plan);

        $this->assertSame(AtlasSelfConstructionScaffoldStagingExecutorService::PLAN_VALID, $a['verdict']);
        $this->assertSame(64, strlen($a['staged_artifact_hash']));
        $this->assertTrue(ctype_xdigit($a['staged_artifact_hash']));
        $this->assertSame($a['staged_artifact_hash'], $b['staged_artifact_hash']);
    }
}
