<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionPromotionPlanService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionScaffoldStagingExecutorService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService;
use Tests\TestCase;

class AtlasSelfConstructionPromotionPlanServiceTest extends TestCase
{
    private string $promotionLog;

    private string $stagingReceiptsLog;

    private AtlasSelfConstructionPromotionPlanService $svc;

    private AtlasSelfConstructionScaffoldStagingExecutorService $staging;

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid('', true);
        $this->promotionLog = sys_get_temp_dir()."/atlas_promo_{$u}.jsonl";
        $this->stagingReceiptsLog = sys_get_temp_dir()."/atlas_staging_receipts_{$u}.jsonl";

        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting(sys_get_temp_dir()."/atlas_promo_kernel_{$u}.jsonl");
        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting(sys_get_temp_dir()."/atlas_promo_admission_{$u}.jsonl");

        $scoreCard = $this->app->make(AtlasCognitionScoreCardService::class);
        $ascb = new AtlasSelfConstructionSubsystemBuilderService($scoreCard);
        $ascb->setProposalsLogPathForTesting(sys_get_temp_dir()."/atlas_promo_prop_{$u}.jsonl");
        $ascb->setApprovalsLogPathForTesting(sys_get_temp_dir()."/atlas_promo_appr_{$u}.jsonl");

        $this->staging = new AtlasSelfConstructionScaffoldStagingExecutorService($ascb, $kernel, $admission);
        $this->staging->setReceiptsLogPathForTesting($this->stagingReceiptsLog);

        $this->svc = new AtlasSelfConstructionPromotionPlanService($this->staging, $kernel, $admission);
        $this->svc->setLogPathForTesting($this->promotionLog);
    }

    protected function tearDown(): void
    {
        @unlink($this->promotionLog);
        @unlink($this->stagingReceiptsLog);
        parent::tearDown();
    }

    public function test_plan_returns_missing_staging_when_no_receipt(): void
    {
        $env = $this->svc->plan('proposal_abc', 'sha256:fakehash');
        $this->assertSame(AtlasSelfConstructionPromotionPlanService::STATUS_MISSING_STAGING, $env['status']);
        $this->assertSame([], $env['files']);
        $this->assertTrue($env['dry_run_only']);
        $this->assertFalse($env['source_tree_writes_authorized']);
    }

    public function test_plan_envelope_shape(): void
    {
        $env = $this->svc->plan('proposal_x', 'sha256:y');
        $this->assertSame(AtlasSelfConstructionPromotionPlanService::PLAN_SCHEMA, $env['schema_version']);
        $this->assertStringStartsWith('sha256:', $env['plan_hash']);
        $this->assertArrayHasKey('planned_at', $env);
    }

    public function test_plan_requires_proposal_id_and_hash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->plan('', '');
    }

    public function test_plan_persisted_appendonly(): void
    {
        $this->svc->plan('p1', 'h1');
        $this->svc->plan('p2', 'h2');
        $this->assertCount(2, $this->svc->listPlans());
    }
}
