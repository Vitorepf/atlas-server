<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Obra;

use App\Services\Ai\Obra\AtlasObraExecutor;
use App\Services\Ai\Obra\ObraNodeDelivery;
use App\Services\Ai\Obra\ObraNodeGate;
use App\Services\Ai\RealExecution\GovernedBranchMaterializationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Feature\Ai\RealExecution\MissionDeliveryOrchestratorTest;
use Tests\TestCase;

/**
 * AOBG N3.F2 — THE OBRA EXECUTOR: run the plan-DAG into ONE accumulating branch.
 *
 * Proves the F2 contract COST-FREE over sqlite + a REAL throwaway git repo: the
 * per-node delivery is a FAKE returning certified files per step (ZERO provider spend,
 * exactly the {@see MissionDeliveryOrchestratorTest}
 * philosophy), while the REAL {@see GovernedBranchMaterializationService} obra-
 * accumulate mode produces ONE real branch and the never-main invariant is asserted.
 *
 * Non-negotiable assertions:
 *  - a 3-node DAG runs onto ONE branch atlas/obra/<id> carrying ALL 3 steps' files;
 *  - step 3 SEES step 1 + step 2's changes (the ACCUMULATION invariant — one branch,
 *    steps build on each other);
 *  - main is byte-identical (HEAD + working tree) and the obra is never_merged;
 *  - a node failing certification HALTS the obra: steps AFTER it do NOT run, the
 *    branch is marked NOT-certified (fail-closed);
 *  - the whole obra branch is discardable (reversible).
 */
final class AtlasObraExecutorTest extends TestCase
{
    private string $repo = '';

    private string $headBefore = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createObraTables();
        config()->set('atlas.obra.enabled', true);
        // Brain write-back off in these tests (no AURG store) — fail-open is its own test.
        config()->set('atlas.aurg.enabled', false);

        $this->repo = sys_get_temp_dir().'/atlas-obra-repo-'.substr(md5(uniqid('', true)), 0, 8);
        File::makeDirectory($this->repo, 0777, true, true);
        File::put($this->repo.'/README.md', "base\n");
        $this->g(['init', '-q']);
        $this->g(['add', '-A']);
        $this->g(['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);
        $this->headBefore = trim($this->gOut(['rev-parse', 'HEAD']));
    }

    protected function tearDown(): void
    {
        if ($this->repo !== '') {
            File::deleteDirectory($this->repo);
        }
        Schema::dropIfExists('atlas_obra_nodes');
        Schema::dropIfExists('atlas_obra_plans');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // ACDE Leap 4 (Stage A) — the wave scheduler is CONSUMED (de-orphaned)
    // when node_fanout_observe is ON; OFF is byte-identical (no wave_schedule).
    // ------------------------------------------------------------------

    public function test_stage_a_observe_de_orphans_the_wave_scheduler_and_off_is_byte_identical(): void
    {
        // OFF (default) => the scheduler is never invoked and the envelope has NO wave_schedule key.
        config()->set('atlas.obra.node_fanout_observe', false);
        $this->seedLinearPlan('obra-wave-off', 3);
        $off = (new AtlasObraExecutor($this->accumulatingDelivery(), new GovernedBranchMaterializationService))
            ->executePlanId('obra-wave-off', ['repo_dir' => $this->repo]);
        $this->assertSame(AtlasObraExecutor::STATUS_DONE, $off['status'], 'reason: '.($off['reason'] ?? ''));
        $this->assertArrayNotHasKey('wave_schedule', $off, 'OFF => byte-identical: no wave_schedule key');

        // ON => the executor consumes AtlasObraWaveScheduler and surfaces the antichain structure.
        config()->set('atlas.obra.node_fanout_observe', true);
        $this->seedLinearPlan('obra-wave-on', 3);
        $on = (new AtlasObraExecutor($this->accumulatingDelivery(), new GovernedBranchMaterializationService))
            ->executePlanId('obra-wave-on', ['repo_dir' => $this->repo]);
        $this->assertSame(AtlasObraExecutor::STATUS_DONE, $on['status'], 'reason: '.($on['reason'] ?? ''));
        $this->assertArrayHasKey('wave_schedule', $on, 'ON => the scheduler is consumed (no longer orphaned)');
        $this->assertArrayHasKey('levels', $on['wave_schedule']);
        $this->assertArrayHasKey('width', $on['wave_schedule']);
        $this->assertArrayHasKey('parallelizable', $on['wave_schedule']);
        $this->assertSame(3, array_sum(array_map('count', $on['wave_schedule']['levels'])), 'all 3 nodes are scheduled into antichain levels');
        // Observation is behaviour-NEUTRAL: the obra still certifies DONE exactly as with the flag OFF.
        $this->assertTrue($on['certified']);
        $this->assertTrue($on['main_untouched']);
    }

    // ------------------------------------------------------------------
    // 1) ONE BRANCH, MANY STEPS — accumulation (step N builds on N-1).
    // ------------------------------------------------------------------

    public function test_three_node_dag_runs_onto_one_accumulating_branch_main_untouched(): void
    {
        $statusBefore = $this->gOut(['status', '--porcelain']);
        $this->seedLinearPlan('obra-acc', 3);

        // A fake delivery whose step 3 READS step 1+2's files from the obra worktree —
        // proving the steps accumulate on ONE branch (step 3 sees the prior steps).
        $delivery = $this->accumulatingDelivery();

        $executor = new AtlasObraExecutor($delivery, new GovernedBranchMaterializationService);

        $r = $executor->executePlanId('obra-acc', ['repo_dir' => $this->repo]);

        $this->assertSame(AtlasObraExecutor::STATUS_DONE, $r['status'], 'reason: '.($r['reason'] ?? ''));
        $this->assertSame('atlas/obra/obra-acc', $r['branch']);
        $this->assertSame(3, $r['node_count']);
        $this->assertSame(3, $r['delivered_nodes']);
        $this->assertTrue($r['certified']);
        $this->assertTrue($r['main_untouched']);
        $this->assertTrue($r['never_merged']);

        // --- ACCUMULATION: ALL 3 steps' files live on the SINGLE obra branch. ---
        $tree = $this->gOut(['ls-tree', '-r', '--name-only', 'atlas/obra/obra-acc']);
        $this->assertStringContainsString('step1.php', $tree, 'step 1 file on the obra branch');
        $this->assertStringContainsString('step2.php', $tree, 'step 2 file on the obra branch');
        $this->assertStringContainsString('step3.php', $tree, 'step 3 file on the obra branch');

        // The branch has exactly 3 step commits (one per node) on top of base.
        $log = trim($this->gOut(['rev-list', '--count', $this->headBefore.'..atlas/obra/obra-acc']));
        $this->assertSame('3', $log, 'exactly one commit per step, all on ONE branch');

        // --- THE accumulation proof (graduated): each step's delivery physically READ
        //     the shared obra worktree and recorded which PRIOR step files it found there.
        //     step1 saw NOTHING; step2 saw step1; step3 saw step1+step2 — only possible if
        //     every step was applied onto the SAME accumulating worktree/branch in order.
        //     A per-branch (N-branches) design would make every step see nothing. ---
        $step1 = $this->gOut(['show', 'atlas/obra/obra-acc:step1.php']);
        $step2 = $this->gOut(['show', 'atlas/obra/obra-acc:step2.php']);
        $step3 = $this->gOut(['show', 'atlas/obra/obra-acc:step3.php']);
        $this->assertStringContainsString('saw=', $step1);
        $this->assertStringContainsString("// saw=\n", $step1, 'step 1 ran first — it saw NO prior step files');
        $this->assertStringContainsString('saw=step1.php', $step2, 'step 2 must see step 1 in the shared worktree');
        $this->assertStringNotContainsString('step2.php', explode('saw=', $step2)[1] ?? '', 'step 2 must NOT yet see itself/step3');
        $this->assertStringContainsString('saw=step1.php,step2.php', $step3,
            'step 3 must build on step 1 + step 2 (one accumulating branch)');

        // --- MAIN untouched: HEAD + working tree byte-identical; no step file on main. ---
        $this->assertSame($this->headBefore, trim($this->gOut(['rev-parse', 'HEAD'])));
        $this->assertSame($statusBefore, $this->gOut(['status', '--porcelain']));
        $this->assertFileDoesNotExist($this->repo.'/step1.php');
        $this->assertFileDoesNotExist($this->repo.'/step3.php');

        // Plan + nodes are recorded done in the spine tables.
        $this->assertSame('done', DB::table('atlas_obra_plans')->where('id', 'obra-acc')->value('status'));
        $this->assertSame(3, DB::table('atlas_obra_nodes')->where('plan_id', 'obra-acc')->where('status', 'done')->count());
    }

    public function test_running_obra_resumes_existing_worktree_and_skips_done_nodes(): void
    {
        $this->seedLinearPlan('obra-resume', 3);

        $materializer = new GovernedBranchMaterializationService;
        $open = $materializer->openObra(['id' => 'obra-resume', 'repo_dir' => $this->repo]);
        $this->assertTrue((bool) ($open['opened'] ?? false), (string) ($open['reason'] ?? 'open failed'));

        $apply = $materializer->applyStepToObra([
            'worktree' => (string) $open['worktree'],
            'base_head' => (string) $open['base_head'],
            'step_id' => 'obra-resume:n0',
            'files' => [['path' => 'step1.php', 'content' => "<?php\n// before kill\nreturn 1;\n"]],
            'certified' => true,
            'gate_receipt' => str_repeat('a', 40),
        ]);
        $this->assertTrue((bool) ($apply['applied'] ?? false), (string) ($apply['reason'] ?? 'apply failed'));

        DB::table('atlas_obra_plans')->where('id', 'obra-resume')->update([
            'status' => 'running',
            'meta' => json_encode([
                'obra_runtime' => [
                    'branch' => (string) $open['branch'],
                    'worktree' => (string) $open['worktree'],
                    'base_head' => (string) $open['base_head'],
                    'repo_dir' => $this->repo,
                    'resume_supported' => true,
                    'resume_count' => 0,
                ],
            ], JSON_UNESCAPED_SLASHES),
        ]);
        DB::table('atlas_obra_nodes')->where('id', 'obra-resume:n0')->update([
            'status' => 'done',
            'result' => json_encode([
                'commit' => $apply['commit'] ?? null,
                'files_changed' => ['step1.php'],
                'branch' => (string) $open['branch'],
                'provider' => 'fake_before_kill',
                'delivery' => 'fake_before_kill',
            ], JSON_UNESCAPED_SLASHES),
        ]);

        $executor = new AtlasObraExecutor($this->accumulatingDelivery(), $materializer);
        $r = $executor->executePlanId('obra-resume', ['repo_dir' => $this->repo]);

        $this->assertSame(AtlasObraExecutor::STATUS_DONE, $r['status'], 'reason: '.($r['reason'] ?? ''));
        $this->assertTrue($r['resumed']);
        $this->assertSame(1, $r['resume_count']);
        $this->assertSame(3, $r['delivered_nodes']);
        $this->assertTrue((bool) data_get($r, 'nodes.0.resumed'));
        $this->assertSame('fake_before_kill', data_get($r, 'nodes.0.provider'));

        $tree = $this->gOut(['ls-tree', '-r', '--name-only', 'atlas/obra/obra-resume']);
        $this->assertStringContainsString('step1.php', $tree);
        $this->assertStringContainsString('step2.php', $tree);
        $this->assertStringContainsString('step3.php', $tree);
        $this->assertSame('3', trim($this->gOut(['rev-list', '--count', $this->headBefore.'..atlas/obra/obra-resume'])));

        $meta = json_decode((string) DB::table('atlas_obra_plans')->where('id', 'obra-resume')->value('meta'), true);
        $this->assertSame(1, data_get($meta, 'obra_runtime.resume_count'));
        $this->assertSame(3, DB::table('atlas_obra_nodes')->where('plan_id', 'obra-resume')->where('status', 'done')->count());
    }

    // ------------------------------------------------------------------
    // 2) HALT-ON-FAILURE — a failed node stops the obra (fail-closed).
    // ------------------------------------------------------------------

    public function test_a_node_failing_certification_halts_the_obra_later_steps_do_not_run(): void
    {
        $this->seedLinearPlan('obra-halt', 3);

        // Delivery certifies steps 1 + 3 but BLOCKS step 2 (uncertified).
        $delivery = $this->deliveryThatFailsStep(2);

        $executor = new AtlasObraExecutor($delivery, new GovernedBranchMaterializationService);

        $r = $executor->executePlanId('obra-halt', ['repo_dir' => $this->repo]);

        $this->assertSame(AtlasObraExecutor::STATUS_FAILED, $r['status']);
        $this->assertFalse($r['certified'], 'a halted obra is NOT certified as a whole');
        $this->assertSame('obra-halt:n1', $r['failed_node']);
        $this->assertSame(1, $r['delivered_nodes'], 'only step 1 delivered before the halt');

        // Node statuses: n0 done, n1 failed, n2 SKIPPED (it did NOT run).
        $this->assertSame('done', DB::table('atlas_obra_nodes')->where('id', 'obra-halt:n0')->value('status'));
        $this->assertSame('failed', DB::table('atlas_obra_nodes')->where('id', 'obra-halt:n1')->value('status'));
        $this->assertSame('skipped', DB::table('atlas_obra_nodes')->where('id', 'obra-halt:n2')->value('status'));

        // The partial branch is KEPT for inspection: step1 present, step2 + step3 ABSENT
        // (no garbage from the failed/never-run steps was applied).
        $tree = $this->gOut(['ls-tree', '-r', '--name-only', 'atlas/obra/obra-halt']);
        $this->assertStringContainsString('step1.php', $tree);
        $this->assertStringNotContainsString('step2.php', $tree, 'a failed step applied no files');
        $this->assertStringNotContainsString('step3.php', $tree, 'a step after the halt never ran');

        // Exactly ONE commit (only step 1 made it onto the branch).
        $this->assertSame('1', trim($this->gOut(['rev-list', '--count', $this->headBefore.'..atlas/obra/obra-halt'])));

        // Main still untouched even after a halt.
        $this->assertTrue($r['main_untouched']);
        $this->assertSame($this->headBefore, trim($this->gOut(['rev-parse', 'HEAD'])));
    }

    public function test_failed_delivery_persists_bounded_provider_autopsy_without_raw_content(): void
    {
        $this->seedLinearPlan('obra-autopsy', 2);

        $executor = new AtlasObraExecutor($this->deliveryThatFailsWithDiagnostics(), new GovernedBranchMaterializationService);
        $r = $executor->executePlanId('obra-autopsy', ['repo_dir' => $this->repo, 'provider' => 'hermes_cli']);

        $this->assertSame(AtlasObraExecutor::STATUS_FAILED, $r['status']);
        $this->assertSame('obra-autopsy:n0', $r['failed_node']);
        $this->assertSame('provider_returned_not_ok:rate_limited', data_get($r, 'nodes.0.reason'));
        $this->assertSame('hermes_cli', data_get($r, 'nodes.0.provider'));
        $this->assertSame('gpt-5.5', data_get($r, 'nodes.0.model'));
        $this->assertSame('blocked', data_get($r, 'nodes.0.delivery_status'));
        $this->assertSame(1, data_get($r, 'nodes.0.file_count'));
        $this->assertSame(false, data_get($r, 'nodes.0.syntax_check.ok'));
        $this->assertStringContainsString('Parse error', (string) data_get($r, 'nodes.0.syntax_check.output_excerpt'));
        $this->assertSame('provider_returned_not_ok:rate_limited', data_get($r, 'nodes.0.delivery_diagnostic.blocked_reason'));

        $raw = (string) DB::table('atlas_obra_nodes')->where('id', 'obra-autopsy:n0')->value('result');
        $persisted = json_decode($raw, true);
        $this->assertSame('delivery', data_get($persisted, 'stage'));
        $this->assertSame('provider_returned_not_ok:rate_limited', data_get($persisted, 'reason'));
        $this->assertSame('hermes_cli', data_get($persisted, 'provider'));
        $this->assertSame('gpt-5.5', data_get($persisted, 'model'));
        $this->assertSame('blocked', data_get($persisted, 'delivery_status'));
        $this->assertStringContainsString('Parse error', (string) data_get($persisted, 'syntax_check.output_excerpt'));
        $this->assertStringContainsString('self-test failed', (string) data_get($persisted, 'run_check.output_excerpt'));
        $this->assertStringNotContainsString('RAW_PROMPT_SHOULD_NOT_PERSIST', $raw);
        $this->assertStringNotContainsString('SECRET_CODE_PREVIEW_SHOULD_NOT_PERSIST', $raw);
        $this->assertStringNotContainsString('provider_raw_output', $raw);

        $this->assertSame('skipped', DB::table('atlas_obra_nodes')->where('id', 'obra-autopsy:n1')->value('status'));
        $this->assertTrue($r['main_untouched']);
    }

    public function test_an_injected_gate_veto_halts_the_obra_fail_closed(): void
    {
        $this->seedLinearPlan('obra-gate', 3);

        // Every step delivers certified, but the GOVERNED gate vetoes step 2.
        $delivery = $this->accumulatingDelivery();
        $gate = $this->gateThatVetoesNode('obra-gate:n1');

        $executor = new AtlasObraExecutor($delivery, new GovernedBranchMaterializationService, null, $gate);

        $r = $executor->executePlanId('obra-gate', ['repo_dir' => $this->repo]);

        $this->assertSame(AtlasObraExecutor::STATUS_FAILED, $r['status']);
        $this->assertSame('obra-gate:n1', $r['failed_node']);
        // step 1 done; step 2 failed AT THE GATE; step 3 skipped.
        $this->assertSame('done', DB::table('atlas_obra_nodes')->where('id', 'obra-gate:n0')->value('status'));
        $this->assertSame('failed', DB::table('atlas_obra_nodes')->where('id', 'obra-gate:n1')->value('status'));
        $this->assertSame('skipped', DB::table('atlas_obra_nodes')->where('id', 'obra-gate:n2')->value('status'));

        // The gate runs AFTER apply, so step 2's commit IS on the branch — but the obra
        // is marked failed (not-certified) and step 3 never ran. Fail-closed: a vetoed
        // step does not let the obra continue.
        $tree = $this->gOut(['ls-tree', '-r', '--name-only', 'atlas/obra/obra-gate']);
        $this->assertStringNotContainsString('step3.php', $tree, 'no step runs after the gate veto');
    }

    // ------------------------------------------------------------------
    // 3) REVERSIBLE — the whole obra branch is discardable.
    // ------------------------------------------------------------------

    public function test_the_whole_obra_branch_is_discardable_reversible(): void
    {
        $this->seedLinearPlan('obra-rev', 2);
        $delivery = $this->accumulatingDelivery();
        $executor = new AtlasObraExecutor($delivery, new GovernedBranchMaterializationService);

        $r = $executor->executePlanId('obra-rev', ['repo_dir' => $this->repo]);
        $this->assertSame(AtlasObraExecutor::STATUS_DONE, $r['status']);

        // The branch exists.
        $exists = new Process(['git', 'rev-parse', '--verify', '--quiet', 'refs/heads/atlas/obra/obra-rev'], $this->repo);
        $exists->run();
        $this->assertTrue($exists->isSuccessful());

        // Discard the WHOLE obra — reversible, leaves zero trace on main.
        $d = $executor->discardObra($this->repo, 'obra-rev');
        $this->assertTrue($d['discarded']);
        $this->assertSame('atlas/obra/obra-rev', $d['branch']);

        $gone = new Process(['git', 'rev-parse', '--verify', '--quiet', 'refs/heads/atlas/obra/obra-rev'], $this->repo);
        $gone->run();
        $this->assertFalse($gone->isSuccessful(), 'the obra branch is gone after discard');

        // Main still byte-identical (the discard never touched it).
        $this->assertSame($this->headBefore, trim($this->gOut(['rev-parse', 'HEAD'])));
    }

    public function test_discard_refuses_a_non_obra_branch(): void
    {
        $executor = new AtlasObraExecutor($this->accumulatingDelivery(), new GovernedBranchMaterializationService);

        // discardObra always namespaces to atlas/obra/<slug>; the materializer guard
        // additionally refuses anything outside the governed prefixes. Prove the guard
        // directly: a main-ish ref cannot be discarded.
        $r = (new GovernedBranchMaterializationService)->discardBranch($this->repo, 'main');
        $this->assertFalse($r['discarded']);
        $this->assertSame('refused_non_materialize_branch', $r['reason']);
    }

    // ------------------------------------------------------------------
    // fixtures (all cost-free — no provider call anywhere)
    // ------------------------------------------------------------------

    /**
     * Seed a persisted LINEAR plan (n0 → n1 → ... ) with $count nodes, matching the
     * F1 spine's stored shape (the executor reads these rows).
     */
    private function seedLinearPlan(string $planId, int $count): void
    {
        DB::table('atlas_obra_plans')->insert([
            'id' => $planId,
            'intent' => 'test obra',
            'workspace_id' => 'atlas-server',
            'status' => 'planned',
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        for ($i = 0; $i < $count; $i++) {
            DB::table('atlas_obra_nodes')->insert([
                'id' => $planId.':n'.$i,
                'plan_id' => $planId,
                'seq' => $i,
                'title' => 'step '.($i + 1),
                'request' => 'create step '.($i + 1),
                'target_area' => 'step'.($i + 1).'.php',
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
     * A fake per-node delivery that GENUINELY reads the held obra worktree to discover
     * which PRIOR step files are already committed there, and records them in its
     * output. step3.php therefore contains "saw=step1.php,step2.php" ONLY IF steps 1+2
     * were really applied to the SAME accumulating worktree before step 3 ran — the
     * direct, non-fakeable proof that the obra accumulates on ONE branch (the fake
     * cannot "see" a file that was not physically committed into the shared worktree).
     * ZERO provider spend.
     */
    private function accumulatingDelivery(): ObraNodeDelivery
    {
        return new class implements ObraNodeDelivery
        {
            public function label(): string
            {
                return 'fake_accumulating';
            }

            public function deliver(string $request, array $context = []): array
            {
                $target = (string) ($context['target_area'] ?? 'step.php');
                $worktree = rtrim((string) ($context['obra_worktree'] ?? ''), '/');

                // Inspect the SHARED obra worktree for already-committed prior step files.
                $saw = [];
                if ($worktree !== '' && is_dir($worktree)) {
                    foreach (glob($worktree.'/step*.php') ?: [] as $f) {
                        $saw[] = basename($f);
                    }
                    sort($saw);
                }
                $content = "<?php\n// ".$request."\n// saw=".implode(',', $saw)."\nreturn 1;\n";

                return [
                    'certified' => true,
                    'files' => [['path' => $target, 'content' => $content]],
                    'gate_receipt' => str_repeat('a', 40),
                    'provider' => 'fake',
                ];
            }
        };
    }

    /**
     * A fake delivery that certifies every step EXCEPT the given 1-based step number,
     * which it BLOCKS (uncertified) — to prove the executor HALTS on a failed node.
     */
    private function deliveryThatFailsStep(int $failStep): ObraNodeDelivery
    {
        return new class($failStep) implements ObraNodeDelivery
        {
            public function __construct(private int $failStep) {}

            public function label(): string
            {
                return 'fake_fails_step';
            }

            public function deliver(string $request, array $context = []): array
            {
                $target = (string) ($context['target_area'] ?? 'step.php');
                $n = (int) (preg_replace('/\D+/', '', $target) ?: '0');
                if ($n === $this->failStep) {
                    return ['certified' => false, 'files' => [], 'reason' => 'provider_returned_not_ok'];
                }

                return [
                    'certified' => true,
                    'files' => [['path' => $target, 'content' => "<?php\n// ".$request."\nreturn ".$n.";\n"]],
                    'gate_receipt' => str_repeat('b', 40),
                    'provider' => 'fake',
                ];
            }
        };
    }

    private function deliveryThatFailsWithDiagnostics(): ObraNodeDelivery
    {
        return new class implements ObraNodeDelivery
        {
            public function label(): string
            {
                return 'fake_fails_with_diagnostics';
            }

            public function deliver(string $request, array $context = []): array
            {
                return [
                    'certified' => false,
                    'files' => [],
                    'reason' => 'provider_returned_not_ok:rate_limited',
                    'provider' => (string) ($context['provider'] ?? 'hermes_cli'),
                    'model' => 'gpt-5.5',
                    'delivery_status' => 'blocked',
                    'target_file' => (string) ($context['target_area'] ?? 'step1.php'),
                    'file_count' => 1,
                    'latency_ms' => 1234,
                    'syntax_check' => [
                        'ok' => false,
                        'tool' => 'php -l',
                        'output' => str_repeat('Parse error ', 80),
                    ],
                    'run_check' => [
                        'ok' => false,
                        'tool' => 'php_self_test',
                        'exit_code' => 1,
                        'output' => str_repeat('self-test failed ', 80),
                    ],
                    'delivery_diagnostic' => [
                        'schema_version' => 'atlas.obra.delivery_diagnostic.v1',
                        'reason' => 'provider_returned_not_ok:rate_limited',
                        'blocked_reason' => 'provider_returned_not_ok:rate_limited',
                        'delivery_status' => 'blocked',
                        'provider' => 'hermes_cli',
                        'model' => 'gpt-5.5',
                        'target_file' => (string) ($context['target_area'] ?? 'step1.php'),
                        'file_count' => 1,
                        'latency_ms' => 1234,
                        'syntax_check' => [
                            'ok' => false,
                            'tool' => 'php -l',
                            'output_excerpt' => str_repeat('Parse error ', 80),
                        ],
                        'run_check' => [
                            'ok' => false,
                            'tool' => 'php_self_test',
                            'exit_code' => 1,
                            'output_excerpt' => str_repeat('self-test failed ', 80),
                        ],
                    ],
                    'prompt' => 'RAW_PROMPT_SHOULD_NOT_PERSIST',
                    'code_preview' => 'SECRET_CODE_PREVIEW_SHOULD_NOT_PERSIST',
                    'provider_raw_output' => 'provider_raw_output',
                ];
            }
        };
    }

    private function gateThatVetoesNode(string $vetoNodeId): ObraNodeGate
    {
        return new class($vetoNodeId) implements ObraNodeGate
        {
            public function __construct(private string $vetoNodeId) {}

            public function certify(array $node, array $context = []): array
            {
                if ((string) ($node['id'] ?? '') === $this->vetoNodeId) {
                    return ['passed' => false, 'reason' => 'forge_gate_vetoed'];
                }

                return ['passed' => true];
            }
        };
    }

    private function createObraTables(): void
    {
        $migration = require database_path('migrations/2026_06_10_140000_create_atlas_obra_plan_tables.php');
        if (Schema::hasTable('atlas_obra_nodes')) {
            $migration->down();
        }
        $migration->up();
    }

    /** @param list<string> $argv */
    private function g(array $argv): void
    {
        (new Process(array_merge(['git'], $argv), $this->repo))->run();
    }

    /** @param list<string> $argv */
    private function gOut(array $argv): string
    {
        $p = new Process(array_merge(['git'], $argv), $this->repo);
        $p->run();

        return $p->getOutput();
    }
}
