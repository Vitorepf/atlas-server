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

    private string $stagingRoot;

    private AtlasSelfConstructionPromotionPlanService $svc;

    private AtlasSelfConstructionScaffoldStagingExecutorService $staging;

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid('', true);
        $this->promotionLog = sys_get_temp_dir()."/atlas_promo_{$u}.jsonl";
        $this->stagingReceiptsLog = sys_get_temp_dir()."/atlas_staging_receipts_{$u}.jsonl";
        $this->stagingRoot = sys_get_temp_dir()."/atlas_promo_staged_{$u}";
        mkdir($this->stagingRoot, 0o755, true);

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
        $this->removeDirectory($this->stagingRoot);
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

    public function test_plan_maps_staged_service_to_declared_namespace_target(): void
    {
        $proposalId = 'prop_l5_4_alog';
        $proposalHash = 'sha256:l5-4-alog';
        $service = $this->stageFile('AtlasAlogService.php', <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement;

final class AtlasAlogService
{
    public function status(): array
    {
        return ['schema_version' => 'atlas.subsystem.v1', 'status' => 'building'];
    }
}
PHP);
        $test = $this->stageFile('AtlasAlogServiceTest.php', <<<'PHP'
<?php
declare(strict_types=1);

class AtlasAlogServiceTest extends \Tests\TestCase
{
    public function test_status_returns_envelope(): void
    {
        $svc = new \App\Services\Ai\SelfImprovement\AtlasAlogService();
        $this->assertSame('atlas.subsystem.v1', $svc->status()['schema_version']);
    }
}
PHP);
        $doc = $this->stageFile('atlas-alog-subsystem.md', '# Atlas ALOG');
        $this->writeStagedReceipt($proposalId, $proposalHash, [$service, $test, $doc]);

        $env = $this->svc->plan($proposalId, $proposalHash);

        $this->assertSame(AtlasSelfConstructionPromotionPlanService::STATUS_READY, $env['status']);
        $targets = array_column($env['files'], 'target_path', 'staged_path');
        $this->assertSame(
            base_path('app/Services/Ai/SelfImprovement/AtlasAlogService.php'),
            $targets[$service],
        );
        $this->assertSame(
            base_path('tests/Unit/Ai/SelfImprovement/AtlasAlogServiceTest.php'),
            $targets[$test],
        );
        $this->assertSame(
            base_path('docs/engineering-knowledge-base/atlas-alog-subsystem.md'),
            $targets[$doc],
        );
    }

    private function stageFile(string $name, string $contents): string
    {
        $path = $this->stagingRoot.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @param  list<string>  $writtenFiles
     */
    private function writeStagedReceipt(string $proposalId, string $proposalHash, array $writtenFiles): void
    {
        file_put_contents($this->stagingReceiptsLog, json_encode([
            'schema_version' => 'atlas.self_construction.scaffold_staging_receipt.v1',
            'recorded_at' => now()->toAtomString(),
            'proposal_id' => $proposalId,
            'proposal_hash' => $proposalHash,
            'actor' => 'test',
            'status' => AtlasSelfConstructionScaffoldStagingExecutorService::STATUS_STAGED,
            'reason' => 'staged for planner test',
            'written_files' => $writtenFiles,
            'staging_root' => $this->stagingRoot,
        ], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND);
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.'/*') ?: [] as $path) {
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
