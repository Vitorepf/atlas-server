<?php

namespace Tests\Feature\Ai\Rivals;

use App\Services\Ai\Rivals\Adapters\AtlasBenchSuiteAdapter;
use App\Services\Ai\Rivals\Core\Adjudicator;
use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\EvidencePackBuilder;
use App\Services\Ai\Rivals\Core\ReportBuilder;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Support\RunPaths;
use Tests\TestCase;

/**
 * Guard pétreo: testes NUNCA provisionam worktree no repo vivo — todo o
 * ciclo de execução roda contra um repo git de fixture em temp.
 */
class AtlasBenchAdapterTest extends TestCase
{
    private string $storage;

    private string $fixtureRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_bench_test_'.uniqid();
        $this->fixtureRepo = sys_get_temp_dir().'/rivals_bench_repo_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
        config()->set('atlas_rivals.atlasbench.repo_path', $this->fixtureRepo);
        $this->buildFixtureRepo();
    }

    protected function tearDown(): void
    {
        foreach ([$this->storage, $this->fixtureRepo] as $dir) {
            if (is_dir($dir)) {
                exec('rm -rf '.escapeshellarg($dir));
            }
        }
        parent::tearDown();
    }

    private function buildFixtureRepo(): void
    {
        mkdir($this->fixtureRepo.'/app', 0755, true);
        mkdir($this->fixtureRepo.'/tests', 0755, true);
        $git = fn (string $cmd) => exec('cd '.escapeshellarg($this->fixtureRepo)." && {$cmd} 2>&1");
        $git('git init -q && git config user.email t@t && git config user.name t');

        // base: implementação bugada + check real que a pega
        file_put_contents($this->fixtureRepo.'/app/calc.php', "<?php\nfunction add(int \$a, int \$b): int { return \$a - \$b; }\n");
        file_put_contents($this->fixtureRepo.'/tests/check.php', "<?php\nrequire __DIR__.'/../app/calc.php';\nexit(add(2, 3) === 5 ? 0 : 1);\n");
        $git('git add -A && git commit -qm "base: buggy add"');

        // solver stub versionado no base: simula um "modelo CLI" local que edita a worktree
        file_put_contents(
            $this->fixtureRepo.'/tests/solver.php',
            "<?php\nfile_put_contents(__DIR__.'/../app/calc.php', \"<?php\\nfunction add(int \\\$a, int \\\$b): int { return \\\$a + \\\$b; }\\n\");\n"
        );
        $git('git add -A && git commit -qm "base: add solver stub" --amend -q');

        // golden: o fix real
        file_put_contents($this->fixtureRepo.'/app/calc.php', "<?php\nfunction add(int \$a, int \$b): int { return \$a + \$b; }\n");
        $git('git add -A && git commit -qm "fix: add sums correctly"');
    }

    private function writeCase(): string
    {
        $sha = fn (string $ref) => trim(shell_exec('cd '.escapeshellarg($this->fixtureRepo)." && git rev-parse {$ref}"));
        $case = [
            'schema_version' => 'atlas.rivals2.atlasbench_case.v1',
            'case_id' => 'ab_fixture01',
            'task_type' => 'repair_regression_fixing',
            'title' => 'fix: add sums correctly',
            'base_sha' => $sha('HEAD^'),
            'golden_sha' => $sha('HEAD'),
            'check_command' => 'php tests/check.php',
            'changed_files' => ['code' => ['app/calc.php'], 'tests' => ['tests/check.php']],
            'diff_lines' => 2,
            'mined_at' => now()->toIso8601String(),
        ];
        $dir = $this->storage.'/atlasbench/cases';
        RunPaths::ensureDir($dir);
        file_put_contents($dir.'/ab_fixture01.json', json_encode($case));

        return $case['case_id'];
    }

    public function test_null_vs_golden_arms_produce_real_differentiated_receipts(): void
    {
        $caseId = $this->writeCase();
        $adapter = new AtlasBenchSuiteAdapter;
        $registry = new ArmRegistry;
        $plan = RunPlan::make(
            $adapter->suiteId(),
            [$caseId],
            [$registry->makeArm('harness_null', 'bare'), $registry->makeArm('harness_golden', 'bare')],
            3,
            ['max_usd' => 0.0, 'max_minutes' => 5],
            7,
        );
        $runId = $plan->persist();
        $adapter->execute($plan);
        (new EvidencePackBuilder)->build($runId);

        $adjudication = (new Adjudicator)->adjudicate($runId);
        $this->assertTrue($adjudication['claim_allowed'], implode(',', $adjudication['claim_blockers']));

        $report = (new ReportBuilder)->build($runId);
        $rows = collect($report['rows']);
        // execução REAL: null falha o check de verdade, golden passa de verdade
        $this->assertSame(0.0, $rows->firstWhere('arm_id', 'harness_null@bare')['success_rate']);
        $this->assertSame(1.0, $rows->firstWhere('arm_id', 'harness_golden@bare')['success_rate']);
        // worktrees limpas após o run
        $this->assertSame([], glob(RunPaths::runDir($runId).'/worktrees/*') ?: []);
    }

    public function test_local_cli_model_arm_executes_for_real_and_passes_check(): void
    {
        // S3: "modelo" = perfil CLI local (stub) que edita a worktree de verdade
        config()->set('atlas_rivals.models.stub_cli', [
            'provider' => 'local', 'access_type' => 'cli', 'local' => true, 'enabled' => true,
            'cost_hint_in' => 0.0, 'cost_hint_out' => 0.0,
            'command' => 'php tests/solver.php',
        ]);
        $caseId = $this->writeCase();
        $adapter = new AtlasBenchSuiteAdapter;
        $plan = RunPlan::make(
            $adapter->suiteId(),
            [$caseId],
            [(new ArmRegistry)->makeArm('stub_cli', 'bare')],
            3,
            ['max_usd' => 0.0, 'max_minutes' => 5],
            7,
        );
        $runId = $plan->persist();
        $adapter->execute($plan);
        (new EvidencePackBuilder)->build($runId);

        $adjudication = (new Adjudicator)->adjudicate($runId);
        $this->assertTrue($adjudication['claim_allowed'], implode(',', $adjudication['claim_blockers']));
        $report = (new ReportBuilder)->build($runId);
        $this->assertSame(1.0, collect($report['rows'])->firstWhere('arm_id', 'stub_cli@bare')['success_rate']);
    }

    public function test_non_local_model_without_spend_flag_fails_closed(): void
    {
        config()->set('atlas_rivals.models.claude_sonnet_5.command', 'claude -p "solve" --cwd {workspace}');
        config()->set('atlas_rivals.provider_spend_allowed', false);
        $caseId = $this->writeCase();
        $adapter = new AtlasBenchSuiteAdapter;
        $plan = RunPlan::make(
            $adapter->suiteId(),
            [$caseId],
            [(new ArmRegistry)->makeArm('claude_sonnet_5', 'bare')],
            3,
            ['max_usd' => 0.0, 'max_minutes' => 5],
            7,
        );
        $plan->persist();

        $this->expectExceptionMessageMatches('/atlasbench_provider_spend_not_allowed/');
        $adapter->execute($plan);
    }

    public function test_atlas_runtime_without_wrapper_blocks_honestly(): void
    {
        $caseId = $this->writeCase();
        $adapter = new AtlasBenchSuiteAdapter;
        $plan = RunPlan::make(
            $adapter->suiteId(),
            [$caseId],
            [(new ArmRegistry)->makeArm('harness_golden', 'atlas_dev')],
            3,
            ['max_usd' => 0.0, 'max_minutes' => 5],
            7,
        );
        $plan->persist();

        // harness_golden@atlas_dev não existe como executor → nunca simular
        $this->expectExceptionMessageMatches('/uplift_supported=false/');
        $adapter->execute($plan);
    }

    public function test_mining_live_repo_is_read_only_and_yields_valid_cases(): void
    {
        // mining pode ler o repo real (git log/show apenas; zero worktree)
        config()->set('atlas_rivals.atlasbench.repo_path', base_path());
        $cases = (new AtlasBenchSuiteAdapter)->mineCases(2);

        $this->assertNotEmpty($cases);
        foreach ($cases as $case) {
            $this->assertNotEmpty($case['base_sha']);
            $this->assertNotEmpty($case['golden_sha']);
            $this->assertStringContainsString('phpunit', $case['check_command']);
            $this->assertContains($case['task_type'], config('atlas_rivals.task_types'));
        }
    }
}
