<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Obra\AtlasObraExecutor;
use App\Services\Ai\Obra\ObraNodeDelivery;
use App\Services\Ai\Programming\AtlasForgeMultiNodeL410ProofService;
use App\Services\Ai\RealExecution\GovernedBranchMaterializationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasForgeMultiNodeL410ProofTest extends TestCase
{
    private string $evidencePath;

    private string $reportPath;

    private string $repo = '';

    private string $headBefore = '';

    protected function setUp(): void
    {
        parent::setUp();

        $id = (string) Str::uuid();
        $this->evidencePath = storage_path('framework/testing/l4-10-proof-'.$id.'.json');
        $this->reportPath = storage_path('framework/testing/l4-10-proof-report-'.$id.'.json');
    }

    protected function tearDown(): void
    {
        @File::delete($this->evidencePath);
        @File::delete($this->reportPath);

        if ($this->repo !== '') {
            File::deleteDirectory($this->repo);
            $this->repo = '';
        }
        Schema::dropIfExists('atlas_obra_nodes');
        Schema::dropIfExists('atlas_obra_plans');

        parent::tearDown();
    }

    public function test_without_real_evidence_plans_six_nodes_and_fails_closed(): void
    {
        $report = app(AtlasForgeMultiNodeL410ProofService::class)->report();

        $this->assertSame(AtlasForgeMultiNodeL410ProofService::SCHEMA_VERSION, $report['schema_version']);
        $this->assertSame('real_execution_blocked', $report['status']);
        $this->assertFalse($report['certified']);
        $this->assertSame(6, $report['planned_obra']['work_node_count']);
        $this->assertSame(10, $report['planned_obra']['schedule']['recommended_agent_count']);
        $this->assertSame('parallel_no_overlap', $report['planned_obra']['schedule']['integration_plan']);
        $this->assertSame(6, $report['planned_obra']['parallel_durable_assignment']['counts']['assignments']);
        $this->assertTrue($report['delivered_item']['local_digest_command_available']);
        $this->assertFalse($report['claim_policy']['provider_dispatches_now']);
        $this->assertFalse($report['claim_policy']['completion_claim_allowed']);
        $this->assertContains('real_provider_obra_run_evidence_missing', $report['blockers']);
        $this->assertContains('kill_resume_live_evidence_missing', $report['blockers']);
    }

    public function test_simulate_only_receipt_is_rejected_even_when_shape_looks_green(): void
    {
        $this->writeEvidence([
            'schema_version' => AtlasForgeMultiNodeL410ProofService::REAL_RECEIPT_SCHEMA_VERSION,
            'status' => 'done',
            'certified' => true,
            'execution_mode' => 'simulate_only_test_double',
            'obra_id' => 'obra-l4-10-real-shaped',
            'node_count' => 6,
            'provider_calls_made' => true,
            'provider' => ['model' => 'gpt-5.5'],
            'delivered_item_id' => 'L4-6',
            'delivered_files' => ['app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php'],
            'kill_resume' => [
                'kill_exercised' => true,
                'resume_exercised' => true,
                'evidence_refs' => ['ledger:kill', 'ledger:resume'],
            ],
            'command_results' => [
                ['command' => 'php artisan atlas:loop:morning-digest --json', 'exit_code' => 0],
            ],
        ]);

        $report = app(AtlasForgeMultiNodeL410ProofService::class)->report([
            'evidence_path' => $this->evidencePath,
        ]);

        $this->assertSame('real_execution_evidence_rejected', $report['status']);
        $this->assertFalse($report['certified']);
        $this->assertContains('non_real_or_fixture_execution_evidence', $report['blockers']);
    }

    public function test_thin_real_shaped_receipt_requires_hermes_schema_status_and_material_files(): void
    {
        $this->writeEvidence([
            'certified' => true,
            'obra_id' => 'obra-l4-10-thin-real-shaped',
            'node_count' => 6,
            'provider_calls_made' => true,
            'external_provider_call' => true,
            'provider' => [
                'name' => 'codex_cli',
                'model' => 'gpt-5.5',
            ],
            'delivered_item_id' => 'L4-6',
            'delivered_files' => ['app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php'],
            'kill_resume' => [
                'kill_exercised' => true,
                'resume_exercised' => true,
                'evidence_refs' => ['ledger:kill-real', 'ledger:resume-real'],
            ],
            'command_results' => [
                ['command' => 'php artisan atlas:loop:morning-digest --json', 'exit_code' => 0],
            ],
        ]);

        $report = app(AtlasForgeMultiNodeL410ProofService::class)->report([
            'evidence_path' => $this->evidencePath,
        ]);

        $this->assertSame('real_execution_evidence_rejected', $report['status']);
        $this->assertFalse($report['certified']);
        $this->assertContains('real_receipt_schema_version_mismatch', $report['blockers']);
        $this->assertContains('real_receipt_done_status_missing', $report['blockers']);
        $this->assertContains('hermes_cli_provider_evidence_missing', $report['blockers']);
        $this->assertContains('non_real_or_fixture_execution_evidence', $report['blockers']);
        $this->assertContains('l4_6_material_files_evidence_missing', $report['blockers']);
    }

    public function test_partially_filled_template_receipt_is_rejected_even_when_green_fields_are_flipped(): void
    {
        $this->writeEvidence([
            'schema_version' => AtlasForgeMultiNodeL410ProofService::REAL_RECEIPT_SCHEMA_VERSION,
            'template_only' => true,
            'status' => 'done',
            'certified' => true,
            'execution_mode' => 'real_provider_obra_run',
            'obra_id' => '<real Obra id>',
            'node_count' => 6,
            'provider_calls_made' => true,
            'external_provider_call' => true,
            'provider' => [
                'name' => '<hermes_cli>',
                'model' => '<gpt-5.5>',
            ],
            'delivered_item_id' => '<L4-6>',
            'delivered_files' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php',
                'app/Console/Commands/AtlasLoopMorningDigestCommand.php',
                'tests/Feature/Loop/AtlasLoopMorningDigestTest.php',
            ],
            'kill_resume' => [
                'kill_exercised' => true,
                'resume_exercised' => true,
                'evidence_refs' => ['<real kill event receipt ref>', '<real resume event receipt ref>'],
            ],
            'command_results' => [
                ['command' => '/opt/homebrew/bin/php artisan atlas:loop:morning-digest --json', 'exit_code' => 0],
            ],
        ]);

        $exit = Artisan::call('atlas:forge:l4-10-proof', [
            '--evidence' => $this->evidencePath,
            '--strict' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('real_execution_evidence_rejected', $payload['status']);
        $this->assertFalse($payload['certified']);
        $this->assertContains('template_or_placeholder_evidence_not_allowed', $payload['blockers']);
        $this->assertFalse($payload['claim_policy']['completion_claim_allowed']);
    }

    public function test_kill_resume_claim_requires_executor_resume_runtime_evidence(): void
    {
        $this->writeEvidence([
            'schema_version' => AtlasForgeMultiNodeL410ProofService::REAL_RECEIPT_SCHEMA_VERSION,
            'status' => 'done',
            'certified' => true,
            'execution_mode' => 'real_provider_obra_run',
            'obra_id' => 'obra-l4-10-real-no-executor-resume',
            'node_count' => 6,
            'provider_calls_made' => true,
            'external_provider_call' => true,
            'provider' => [
                'name' => 'hermes_cli',
                'model' => 'gpt-5.5',
            ],
            'delivered_item_id' => 'L4-6',
            'delivered_files' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php',
                'app/Console/Commands/AtlasLoopMorningDigestCommand.php',
                'tests/Feature/Loop/AtlasLoopMorningDigestTest.php',
            ],
            'kill_resume' => [
                'kill_exercised' => true,
                'resume_exercised' => true,
                'evidence_refs' => ['ledger:kill-real', 'ledger:resume-real'],
            ],
            'command_results' => [
                ['command' => 'php artisan atlas:loop:morning-digest --json', 'exit_code' => 0],
            ],
        ]);

        $report = app(AtlasForgeMultiNodeL410ProofService::class)->report([
            'evidence_path' => $this->evidencePath,
        ]);

        $this->assertSame('real_execution_evidence_rejected', $report['status']);
        $this->assertFalse($report['certified']);
        $this->assertContains('obra_executor_resume_evidence_missing', $report['blockers']);
        $this->assertFalse((bool) data_get($report, 'validation.checks.kill_resume.executor_resumed'));
        $this->assertSame(0, data_get($report, 'validation.checks.kill_resume.resume_count'));
    }

    public function test_executor_emitted_receipt_certifies_l4_10_and_strict_command_passes(): void
    {
        // L4-10 — the EXECUTOR EMITS the signed core receipt from a REAL run (real git
        // worktree, fake cost-free delivery). We wrap that executor OUTPUT in the live
        // run-evidence the proof also requires (kill/resume + digest command) and prove
        // the strict proof certifies it. main_untouched comes from the ISOLATED worktree.
        $run = $this->runRealExecutorAndBuildReceipt();
        $this->assertTrue((bool) ($run['executor_main_untouched'] ?? false), 'isolated worktree ⇒ main untouched');
        $this->writeEvidence($run['receipt']);

        $exit = Artisan::call('atlas:forge:l4-10-proof', [
            '--evidence' => $this->evidencePath,
            '--strict' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('certified', $payload['status']);
        $this->assertTrue($payload['certified']);
        $this->assertSame([], $payload['blockers']);
        $this->assertTrue($payload['claim_policy']['completion_claim_allowed']);
        $this->assertTrue($payload['delivered_item']['delivered_by_real_multi_node_obra']);

        // The proof verified the EXECUTOR self-stamp (an OUTPUT, not a hand-assembled file).
        $this->assertTrue((bool) data_get($payload, 'validation.checks.executor_provenance.stamped'));
        $this->assertTrue((bool) data_get($payload, 'validation.checks.executor_provenance.verified'));
        $this->assertSame('executor_receipt', data_get($payload, 'validation.checks.executor_provenance.source'));
    }

    public function test_hand_edited_executor_receipt_is_rejected_by_strict_proof(): void
    {
        // Take a GENUINE executor-emitted receipt and HAND-EDIT a sealed fact (the
        // node_count, faking a 6-node run from a smaller one). The HMAC no longer
        // recomputes ⇒ the strict proof REJECTS it. The provenance seal is what makes a
        // hand-assembled / hand-edited receipt impossible to pass off as a real run.
        $run = $this->runRealExecutorAndBuildReceipt();
        $receipt = $run['receipt'];

        // Tamper a sealed field inside the executor's signed core.
        $receipt['executor_receipt']['node_count'] = 99;
        // Also flip the certified core flag to be extra-adversarial (still must be rejected).
        $receipt['executor_receipt']['certified'] = true;
        $this->writeEvidence($receipt);

        $exit = Artisan::call('atlas:forge:l4-10-proof', [
            '--evidence' => $this->evidencePath,
            '--strict' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit, Artisan::output());
        $this->assertSame('real_execution_evidence_rejected', $payload['status']);
        $this->assertFalse($payload['certified']);
        $this->assertContains('executor_receipt_provenance_invalid', $payload['blockers']);
        $this->assertFalse((bool) data_get($payload, 'validation.checks.executor_provenance.verified'));
        $this->assertSame('signature_mismatch', data_get($payload, 'validation.checks.executor_provenance.reason'));
    }

    public function test_hand_assembled_receipt_without_executor_stamp_is_rejected(): void
    {
        // The OLD hand-assembled real-shaped receipt (every field flipped green, no
        // executor self-stamp). Before L4-10 this certified; now the proof requires an
        // executor OUTPUT, so a stamp-less hand-assembled file is rejected fail-closed.
        $this->writeEvidence([
            'schema_version' => AtlasForgeMultiNodeL410ProofService::REAL_RECEIPT_SCHEMA_VERSION,
            'status' => 'done',
            'certified' => true,
            'execution_mode' => 'real_provider_obra_run',
            'obra_id' => 'obra-l4-10-hand-assembled',
            'node_count' => 6,
            'resumed' => true,
            'resume_count' => 1,
            'provider_calls_made' => true,
            'external_provider_call' => true,
            'provider' => ['name' => 'hermes_cli', 'model' => 'gpt-5.5'],
            'delivered_item_id' => 'L4-6',
            'delivered_files' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php',
                'app/Console/Commands/AtlasLoopMorningDigestCommand.php',
                'tests/Feature/Loop/AtlasLoopMorningDigestTest.php',
            ],
            'kill_resume' => [
                'kill_exercised' => true,
                'resume_exercised' => true,
                'evidence_refs' => ['ledger:kill-real', 'ledger:resume-real'],
            ],
            'command_results' => [
                ['command' => 'php artisan atlas:loop:morning-digest --json', 'exit_code' => 0],
            ],
        ]);

        $exit = Artisan::call('atlas:forge:l4-10-proof', [
            '--evidence' => $this->evidencePath,
            '--strict' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit, Artisan::output());
        $this->assertSame('real_execution_evidence_rejected', $payload['status']);
        $this->assertFalse($payload['certified']);
        $this->assertContains('executor_stamped_receipt_required', $payload['blockers']);
        $this->assertFalse((bool) data_get($payload, 'validation.checks.executor_provenance.stamped'));
    }

    public function test_strict_command_fails_without_real_evidence(): void
    {
        $exit = Artisan::call('atlas:forge:l4-10-proof', [
            '--strict' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('real_execution_blocked', $payload['status']);
        $this->assertFalse($payload['certified']);
    }

    public function test_command_writes_blocked_proof_report_without_promoting_real_claim(): void
    {
        $exit = Artisan::call('atlas:forge:l4-10-proof', [
            '--write-report' => true,
            '--report-path' => $this->reportPath,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('real_execution_blocked', $payload['status']);
        $this->assertFalse($payload['certified']);
        $this->assertSame($this->reportPath, $payload['written_report_path']);
        $this->assertFileExists($this->reportPath);

        $written = json_decode((string) File::get($this->reportPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(AtlasForgeMultiNodeL410ProofService::SCHEMA_VERSION, $written['schema_version']);
        $this->assertSame('real_execution_blocked', $written['status']);
        $this->assertFalse($written['claim_policy']['provider_dispatches_now']);
        $this->assertFalse($written['claim_policy']['completion_claim_allowed']);
        $this->assertContains('real_provider_obra_run_evidence_missing', $written['blockers']);
    }

    /**
     * Run the REAL {@see AtlasObraExecutor} over a 6-node plan against a throwaway git
     * repo with a cost-free fake delivery (provider=hermes_cli/model=gpt-5.5, emitting
     * the L4-6 material files). Capture the executor's SELF-STAMPED `executor_receipt`
     * and wrap it into the full L4-10 real receipt the proof requires (the surrounding
     * live run-evidence — kill/resume + digest command — that lives OUTSIDE the signed
     * core). ZERO provider spend.
     *
     * @return array{receipt:array<string,mixed>,executor_main_untouched:bool}
     */
    private function runRealExecutorAndBuildReceipt(): array
    {
        $this->createObraRepoAndTables();
        config()->set('atlas.obra.enabled', true);
        config()->set('atlas.aurg.enabled', false);

        $planId = 'obra-l4-10-real-'.substr((string) Str::uuid(), 0, 8);
        $this->seedSixNodeMaterialPlan($planId);

        $executor = new AtlasObraExecutor($this->materialHermesDelivery(), new GovernedBranchMaterializationService);
        $result = $executor->executePlanId($planId, ['repo_dir' => $this->repo]);

        $this->assertSame(AtlasObraExecutor::STATUS_DONE, $result['status'], 'executor must finish DONE: '.($result['reason'] ?? ''));
        $this->assertTrue((bool) $result['certified']);
        $this->assertSame(6, $result['node_count']);
        $this->assertSame(6, $result['delivered_nodes']);

        $executorReceipt = (array) ($result['executor_receipt'] ?? []);
        $this->assertNotSame([], $executorReceipt, 'executor must emit a self-stamped receipt');
        $this->assertTrue((bool) data_get($executorReceipt, 'provenance.executor_stamped'));
        $this->assertSame('hermes_cli', $executorReceipt['provider'] ?? null);
        $this->assertSame('gpt-5.5', $executorReceipt['model'] ?? null);

        // The full L4-10 receipt = the executor's signed CORE (under executor_receipt)
        // PLUS the surrounding live run-evidence (kill/resume + digest command + the real
        // provider-call markers) the proof also requires. The proof verifies the signed
        // core; the surrounding evidence is NOT signed, so it can be added freely.
        $receipt = [
            'schema_version' => AtlasForgeMultiNodeL410ProofService::REAL_RECEIPT_SCHEMA_VERSION,
            'status' => 'done',
            'certified' => true,
            'execution_mode' => 'real_provider_obra_run',
            'obra_id' => (string) ($result['plan_id'] ?? $planId),
            'node_count' => 6,
            'resumed' => true,
            'resume_count' => 1,
            'provider_calls_made' => true,
            'external_provider_call' => true,
            'provider' => ['name' => 'hermes_cli', 'model' => 'gpt-5.5'],
            'delivered_item_id' => 'L4-6',
            'delivered_files' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php',
                'app/Console/Commands/AtlasLoopMorningDigestCommand.php',
                'tests/Feature/Loop/AtlasLoopMorningDigestTest.php',
            ],
            'kill_resume' => [
                'kill_exercised' => true,
                'resume_exercised' => true,
                'evidence_refs' => ['ledger:kill-real', 'ledger:resume-real'],
            ],
            'command_results' => [
                ['command' => 'php artisan atlas:loop:morning-digest --json', 'exit_code' => 0],
            ],
            // THE PROVENANCE-HARDENED CORE — the executor's own signed output.
            'executor_receipt' => $executorReceipt,
        ];

        return [
            'receipt' => $receipt,
            'executor_main_untouched' => (bool) ($result['main_untouched'] ?? false),
        ];
    }

    /**
     * Seed a persisted 6-node LINEAR plan whose nodes target the L4-6 material files,
     * matching the F1 spine's stored shape (the executor reads these rows).
     */
    private function seedSixNodeMaterialPlan(string $planId): void
    {
        $targets = [
            'app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php',
            'app/Console/Commands/AtlasLoopMorningDigestCommand.php',
            'tests/Feature/Loop/AtlasLoopMorningDigestTest.php',
            'app/Console/Commands/AtlasLoopKeepaliveCommand.php',
            'config/atlas.php',
            'docs/fable-lista-4-14-itens.md',
        ];

        DB::table('atlas_obra_plans')->insert([
            'id' => $planId,
            'intent' => 'L4-10 six-node obra delivers L4-6',
            'workspace_id' => 'atlas-server',
            'status' => 'planned',
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($targets as $i => $target) {
            DB::table('atlas_obra_nodes')->insert([
                'id' => $planId.':n'.$i,
                'plan_id' => $planId,
                'seq' => $i,
                'title' => 'node '.($i + 1),
                'request' => 'deliver '.$target,
                'target_area' => $target,
                'depends_on' => $i === 0 ? '[]' : json_encode([$planId.':n'.($i - 1)]),
                'status' => 'pending',
                'brain_refs' => '[]',
                'result' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * A cost-free fake delivery that certifies every node and reports the production
     * engine LABELS (hermes_cli / gpt-5.5) so the executor's self-stamped receipt records
     * the real provider/model from OBSERVED facts. Writes a tiny valid file per node's
     * target so the materializer commits a real change. ZERO provider spend.
     */
    private function materialHermesDelivery(): ObraNodeDelivery
    {
        return new class implements ObraNodeDelivery
        {
            public function label(): string
            {
                return 'fake_hermes_material';
            }

            public function deliver(string $request, array $context = []): array
            {
                $target = (string) ($context['target_area'] ?? 'step.php');
                $content = str_ends_with($target, '.md')
                    ? "# ".$request."\n\nL4-6 material.\n"
                    : "<?php\n// ".$request."\nreturn 1;\n";

                return [
                    'certified' => true,
                    'files' => [['path' => $target, 'content' => $content]],
                    'gate_receipt' => str_repeat('c', 40),
                    'provider' => 'hermes_cli',
                    'model' => 'gpt-5.5',
                ];
            }
        };
    }

    private function createObraRepoAndTables(): void
    {
        $migration = require database_path('migrations/2026_06_10_140000_create_atlas_obra_plan_tables.php');
        if (Schema::hasTable('atlas_obra_nodes')) {
            $migration->down();
        }
        $migration->up();

        $this->repo = sys_get_temp_dir().'/atlas-l4-10-repo-'.substr(md5(uniqid('', true)), 0, 8);
        File::makeDirectory($this->repo, 0777, true, true);
        File::put($this->repo.'/README.md', "base\n");
        $this->g(['init', '-q']);
        $this->g(['add', '-A']);
        $this->g(['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);
        $this->headBefore = trim($this->gOut(['rev-parse', 'HEAD']));
    }

    /** @param  list<string>  $argv */
    private function g(array $argv): void
    {
        (new Process(array_merge(['git'], $argv), $this->repo))->run();
    }

    /** @param  list<string>  $argv */
    private function gOut(array $argv): string
    {
        $p = new Process(array_merge(['git'], $argv), $this->repo);
        $p->run();

        return $p->getOutput();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeEvidence(array $payload): void
    {
        File::ensureDirectoryExists(dirname($this->evidencePath));
        File::put($this->evidencePath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
