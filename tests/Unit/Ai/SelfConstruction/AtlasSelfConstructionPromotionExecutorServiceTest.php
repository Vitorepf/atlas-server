<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionPromotionExecutorService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionPromotionPlanService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionScaffoldStagingExecutorService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * G4 — o executor de promoção governada: scaffold STAGED → branch novo via git
 * worktree, nunca main, nunca a working tree do operador.
 */
class AtlasSelfConstructionPromotionExecutorServiceTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    /** @var list<string> */
    private array $files = [];

    private AtlasSelfConstructionPromotionExecutorService $executor;

    private string $stagingReceiptsLog;

    private string $stagingDir;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $u = uniqid('', true);
        $this->stagingReceiptsLog = sys_get_temp_dir()."/atlas_promoexec_receipts_{$u}.jsonl";
        $this->files[] = $this->stagingReceiptsLog;

        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting(sys_get_temp_dir()."/atlas_promoexec_kernel_{$u}.jsonl");
        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting(sys_get_temp_dir()."/atlas_promoexec_adm_{$u}.jsonl");

        $scoreCard = $this->app->make(AtlasCognitionScoreCardService::class);
        $ascb = new AtlasSelfConstructionSubsystemBuilderService($scoreCard);
        $ascb->setProposalsLogPathForTesting(sys_get_temp_dir()."/atlas_promoexec_prop_{$u}.jsonl");
        $ascb->setApprovalsLogPathForTesting(sys_get_temp_dir()."/atlas_promoexec_appr_{$u}.jsonl");

        $staging = new AtlasSelfConstructionScaffoldStagingExecutorService($ascb, $kernel, $admission);
        $staging->setReceiptsLogPathForTesting($this->stagingReceiptsLog);

        $planner = new AtlasSelfConstructionPromotionPlanService($staging, $kernel, $admission);
        $planner->setLogPathForTesting(sys_get_temp_dir()."/atlas_promoexec_plan_{$u}.jsonl");

        $this->executor = new AtlasSelfConstructionPromotionExecutorService($planner);

        // Staging dir com um arquivo PHP válido e nome único (sem conflito no repo real).
        $this->stagingDir = $this->tmpDir('staging');
        // Repo git descartável que faz o papel da árvore-fonte.
        $this->repo = $this->tmpDir('repo');
        $this->git($this->repo, ['init', '-q']);
        file_put_contents($this->repo.'/README.md', "demo\n");
        $this->git($this->repo, ['add', '-A']);
        $this->git($this->repo, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);
        $this->executor->setRepoRootForTesting($this->repo);
    }

    protected function tearDown(): void
    {
        foreach (array_filter($this->dirs) as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function tmpDir(string $tag): string
    {
        $d = sys_get_temp_dir().'/atlas-promoexec-'.$tag.'-'.bin2hex(random_bytes(4));
        mkdir($d, 0o755, true);
        $this->dirs[] = $d;

        return $d;
    }

    /**
     * @param  list<string>  $argv
     */
    private function git(string $cwd, array $argv): Process
    {
        $p = new Process(array_merge(['git'], $argv), $cwd);
        $p->run();

        return $p;
    }

    private function stageFile(string $name, string $contents): string
    {
        $path = $this->stagingDir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    private function writeStagedReceipt(string $proposalId, string $proposalHash, array $writtenFiles): void
    {
        file_put_contents($this->stagingReceiptsLog, json_encode([
            'schema_version' => 'atlas.self_construction.staging_receipt.v1',
            'recorded_at' => now()->toAtomString(),
            'proposal_id' => $proposalId,
            'proposal_hash' => $proposalHash,
            'actor' => 'test',
            'status' => AtlasSelfConstructionScaffoldStagingExecutorService::STATUS_STAGED,
            'reason' => 'staged for test',
            'written_files' => $writtenFiles,
            'staging_root' => $this->stagingDir,
        ])."\n", FILE_APPEND);
    }

    // ── promoteFacts() preflight tests (pure — no git, no staging needed) ──

    private function goodFacts(array $overrides = []): array
    {
        return $overrides + [
            'preflight_passed' => true,
            'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'changed_files' => ['app/Foo.php'],
            'rollback_plan' => ['steps' => ['revert commit abc']],
            'verification_hash' => 'sha256:abc123',
            'knowledge_sync_plan' => ['sync_docs' => true],
        ];
    }

    public function test_preflight_not_passed_blocks_promotion_facts(): void
    {
        $r = $this->executor->promoteFacts($this->goodFacts(['preflight_passed' => false]));

        $this->assertFalse($r['promotable']);
        $this->assertContains('preflight_not_passed', $r['blockers']);
    }

    public function test_out_of_scope_changed_file_blocks_promotion_facts(): void
    {
        $r = $this->executor->promoteFacts($this->goodFacts(['changed_files' => ['app/NotAllowed.php']]));

        $this->assertFalse($r['promotable']);
        $this->assertContains('changed_file_out_of_scope:app/NotAllowed.php', $r['blockers']);
    }

    public function test_missing_rollback_plan_blocks_promotion_facts(): void
    {
        $r = $this->executor->promoteFacts($this->goodFacts(['rollback_plan' => null]));

        $this->assertFalse($r['promotable']);
        $this->assertContains('rollback_plan_missing', $r['blockers']);
    }

    public function test_missing_verification_hash_blocks_promotion_facts(): void
    {
        $r = $this->executor->promoteFacts($this->goodFacts(['verification_hash' => '']));

        $this->assertFalse($r['promotable']);
        $this->assertContains('verification_hash_missing', $r['blockers']);
    }

    public function test_missing_knowledge_sync_plan_blocks_promotion_facts(): void
    {
        $r = $this->executor->promoteFacts($this->goodFacts(['knowledge_sync_plan' => []]));

        $this->assertFalse($r['promotable']);
        $this->assertContains('knowledge_sync_plan_missing', $r['blockers']);
    }

    public function test_complete_bounded_promotion_facts_pass(): void
    {
        $r = $this->executor->promoteFacts($this->goodFacts());

        $this->assertTrue($r['promotable']);
        $this->assertSame([], $r['blockers']);
        $this->assertSame(AtlasSelfConstructionPromotionExecutorService::SCHEMA_VERSION, $r['schema_version']);
    }

    // ── existing promote() tests below ──

    public function test_denies_when_flag_disabled(): void
    {
        config(['atlas.ai.self_construction.promote_to_source_enabled' => false]);

        $r = $this->executor->promote('p1', 'h1', ['operator_id' => 'vitor', 'approved' => true]);

        $this->assertFalse($r['promoted']);
        $this->assertSame('promote_to_source_disabled', $r['reason']);
    }

    public function test_denies_without_explicit_operator_approval(): void
    {
        config(['atlas.ai.self_construction.promote_to_source_enabled' => true]);

        $r = $this->executor->promote('p1', 'h1', ['operator_id' => 'vitor', 'approved' => false]);

        $this->assertFalse($r['promoted']);
        $this->assertSame('operator_approval_required', $r['reason']);
    }

    public function test_denies_when_plan_is_not_ready(): void
    {
        config(['atlas.ai.self_construction.promote_to_source_enabled' => true]);

        $r = $this->executor->promote('ghost', 'sha256:none', ['operator_id' => 'vitor', 'approved' => true]);

        $this->assertFalse($r['promoted']);
        $this->assertStringStartsWith('plan_not_ready:', (string) $r['reason']);
    }

    public function test_denies_staged_php_with_syntax_error(): void
    {
        config(['atlas.ai.self_construction.promote_to_source_enabled' => true]);

        $staged = $this->stageFile('ZzAtlasBrokenDemoService.php', "<?php\n\nclass ZzAtlasBrokenDemoService {\n");
        $this->writeStagedReceipt('p-broken', 'sha256:broken', [$staged]);

        $r = $this->executor->promote('p-broken', 'sha256:broken', ['operator_id' => 'vitor', 'approved' => true]);

        $this->assertFalse($r['promoted']);
        $this->assertSame('syntax_invalid:ZzAtlasBrokenDemoService.php', $r['reason']);
    }

    public function test_space_in_path_does_not_cause_false_deny(): void
    {
        config(['atlas.ai.self_construction.promote_to_source_enabled' => true]);

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Services\\Ai\\SelfConstruction;\n\nfinal class ZzAtlasSpaceDemoService\n{\n    public function ping(): string { return 'pong'; }\n}\n";
        $staged = $this->stageFile('ZzAtlasSpaceDemoService.php', $contents);

        $subDir = $this->stagingDir.'/sub dir';
        @mkdir($subDir, 0o755, true);
        $staged2 = $subDir.'/ZzAtlasSpaceHelperService.php';
        file_put_contents($staged2, "<?php\n\nclass ZzAtlasSpaceHelperService {}\n");

        $this->writeStagedReceipt('p-space', 'sha256:space', [$staged, $staged2]);

        $r = $this->executor->promote('p-space', 'sha256:space', ['operator_id' => 'vitor', 'approved' => true]);

        $this->assertTrue($r['promoted'], 'space-in-path must not false-deny: '.(string) $r['reason']);
    }

    public function test_promotes_staged_scaffold_to_new_branch_never_main_never_working_tree(): void
    {
        config(['atlas.ai.self_construction.promote_to_source_enabled' => true]);

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Services\\Ai\\SelfConstruction;\n\nfinal class ZzAtlasPromoDemoService\n{\n    public function ping(): string\n    {\n        return 'pong';\n    }\n}\n";
        $staged = $this->stageFile('ZzAtlasPromoDemoService.php', $contents);
        $this->writeStagedReceipt('p-demo', 'sha256:demo', [$staged]);

        $r = $this->executor->promote('p-demo', 'sha256:demo', ['operator_id' => 'vitor', 'approved' => true]);

        $this->assertTrue($r['promoted'], 'reason: '.(string) $r['reason']);
        $this->assertFalse($r['merged_to_main']);
        $this->assertTrue($r['never_main']);
        $this->assertNotEmpty($r['receipt_hash']);

        $branch = (string) $r['branch'];
        $relative = (string) $r['files'][0];

        // O branch existe no repo…
        $branches = $this->git($this->repo, ['branch', '--list', $branch])->getOutput();
        $this->assertStringContainsString($branch, $branches);

        // …carrega EXATAMENTE o arquivo promovido…
        $show = $this->git($this->repo, ['show', $branch.':'.$relative]);
        $this->assertTrue($show->isSuccessful(), $show->getErrorOutput());
        $this->assertSame($contents, $show->getOutput());

        // …main/HEAD NÃO tem o arquivo (nunca main)…
        $this->assertFalse($this->git($this->repo, ['show', 'HEAD:'.$relative])->isSuccessful());

        // …e a working tree do repo segue LIMPA (nunca a working tree).
        $this->assertSame('', trim($this->git($this->repo, ['status', '--porcelain'])->getOutput()));
        $this->assertFileDoesNotExist($this->repo.'/'.$relative);
    }
}
