<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Obra;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Ai\Obra\AtlasObraExecutor;
use App\Services\Ai\Obra\ObraNodeDelivery;
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService;
use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use App\Services\Ai\RealExecution\GovernedBranchMaterializationService;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * AOBG N3.F3 — INTEGRATION + CERTIFICATION of the WHOLE obra, end-to-end.
 *
 * The capstone of the obra: F2 walks the plan-DAG onto ONE accumulating branch; F3
 * certifies that ASSEMBLED branch AS A UNIT and records the obra back into the brain
 * so the NEXT obra sees it. Proven COST-FREE over sqlite + a REAL throwaway git repo:
 * the per-node delivery is a FAKE returning certified files (ZERO provider spend); the
 * REAL {@see GovernedBranchMaterializationService} produces ONE real branch; the
 * integrated check is a REAL shell command run on the assembled worktree.
 *
 * Non-negotiable assertions:
 *  - a fully-passing obra WITH a passing integrated check → certified=true, an
 *    evidence envelope, AND a brain 'obra' node + 'generated' edges to each step +
 *    an 'evidence' node for the certification;
 *  - the F3 INVARIANT: an obra whose per-steps ALL pass but whose INTEGRATED check
 *    FAILS on the assembled branch → certified=false / needs_review (the branch is
 *    kept; never stamped green);
 *  - COMPOUNDING: a later AURG query REACHES the recorded obra node.
 */
final class AtlasObraIntegrationCertificationTest extends TestCase
{
    private string $repo = '';

    private string $headBefore = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createObraTables();
        $this->createAurgTables();
        config()->set('atlas.obra.enabled', true);
        config()->set('atlas.aurg.enabled', true);
        // No configured default integrated check — each test supplies its own per-run.
        config()->set('atlas.obra.integrated_check', '');

        $this->repo = sys_get_temp_dir().'/atlas-obra-f3-repo-'.substr(md5(uniqid('', true)), 0, 8);
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
        Schema::dropIfExists('atlas_aurg_edges');
        Schema::dropIfExists('atlas_aurg_nodes');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 1) FULLY-PASSING obra + passing integrated check → certified + brain record.
    // ------------------------------------------------------------------

    public function test_passing_obra_with_passing_integrated_check_is_certified_and_recorded_in_the_brain(): void
    {
        $this->seedLinearPlan('obra-cert', 2);
        $executor = $this->executorWithBrain();

        // The integrated check runs on the ASSEMBLED worktree and PASSES (both steps present).
        $r = $executor->executePlanId('obra-cert', [
            'repo_dir' => $this->repo,
            'integrated_check' => 'test -f step1.php && test -f step2.php',
        ]);

        $this->assertSame(AtlasObraExecutor::STATUS_DONE, $r['status'], 'reason: '.($r['reason'] ?? ''));
        $this->assertTrue($r['certified'], 'a passing integrated check certifies the whole obra');
        $this->assertSame('certified', $r['disposition']);
        $this->assertSame(2, $r['delivered_nodes']);

        // --- The F3 evidence envelope. ---
        $env = $r['certification'];
        $this->assertIsArray($env);
        $this->assertSame('atlas.obra.certification.v1', $env['schema']);
        $this->assertSame('obra-cert', $env['obra_id']);
        $this->assertSame('atlas/obra/obra-cert', $env['branch']);
        $this->assertTrue($env['certified']);
        $this->assertCount(2, $env['nodes']);
        $this->assertTrue($env['integrated_test_result']['ran']);
        $this->assertTrue($env['integrated_test_result']['passed']);
        $this->assertNotEmpty($env['receipt_hash']);

        // --- BRAIN: an 'obra' node recorded TRUTHFULLY (certified=true). ---
        $obraNode = AtlasAurgNode::query()->whereKey('obra:obra:obra-cert')->first();
        $this->assertNotNull($obraNode, 'the obra is recorded as a first-class brain node');
        $this->assertSame('obra', (string) $obraNode->kind);
        $this->assertSame('obra', (string) $obraNode->source_kind);
        $this->assertTrue((bool) ($obraNode->meta['certified'] ?? false));
        $this->assertSame('atlas/obra/obra-cert', $obraNode->meta['branch'] ?? null);
        // PRIVACY: no source / diff rode the brain.
        $this->assertArrayNotHasKey('content', (array) $obraNode->meta);
        $this->assertArrayNotHasKey('diff', (array) $obraNode->meta);

        // --- BRAIN: an 'evidence' node for the integrated certification. ---
        $evidence = AtlasAurgNode::query()->whereKey('obra:evidence:obra-cert')->first();
        $this->assertNotNull($evidence);
        $this->assertSame('evidence', (string) $evidence->kind);
        $this->assertSame('passed', $evidence->meta['status'] ?? null);

        // --- BRAIN: obra --generated--> evidence (1.0). ---
        $genEvidence = AtlasAurgEdge::query()
            ->where('from_node_id', 'obra:obra:obra-cert')
            ->where('to_node_id', 'obra:evidence:obra-cert')
            ->where('kind', 'generated')
            ->first();
        $this->assertNotNull($genEvidence, 'obra→evidence generated edge');
        $this->assertSame(1.0, (float) $genEvidence->confidence);

        // --- BRAIN: obra --generated--> each step's mission node (the steps compound). ---
        foreach (['obra-cert:n0', 'obra-cert:n1'] as $stepId) {
            $genStep = AtlasAurgEdge::query()
                ->where('from_node_id', 'obra:obra:obra-cert')
                ->where('to_node_id', 'mission:mission:'.$stepId)
                ->where('kind', 'generated')
                ->first();
            $this->assertNotNull($genStep, 'obra→step generated edge for '.$stepId);
            $this->assertSame(1.0, (float) $genStep->confidence);
        }

        // Main untouched across the whole obra.
        $this->assertTrue($r['main_untouched']);
        $this->assertSame($this->headBefore, trim($this->gOut(['rev-parse', 'HEAD'])));
    }

    // ------------------------------------------------------------------
    // 2) THE F3 INVARIANT — per-step passes do NOT imply integration.
    // ------------------------------------------------------------------

    public function test_per_step_passes_but_failing_integrated_check_is_needs_review_not_certified(): void
    {
        $this->seedLinearPlan('obra-conflict', 2);
        $executor = $this->executorWithBrain();

        // EVERY step delivers + certifies (the fake always certifies), and each step's
        // files are applied to ONE accumulating branch. But the INTEGRATED check fails
        // SPECIFICALLY on the ASSEMBLED state — it fails iff BOTH step files coexist
        // (a conflict only visible once the steps are on the same branch). A per-branch
        // or per-step check could never catch this; the whole-branch check does.
        $r = $executor->executePlanId('obra-conflict', [
            'repo_dir' => $this->repo,
            // exit 1 when step1.php AND step2.php BOTH exist (the integration conflict).
            'integrated_check' => 'if test -f step1.php && test -f step2.php; then exit 1; fi; exit 0',
        ]);

        // The obra is NOT certified — needs_review (not failed: no step halted).
        $this->assertSame(AtlasObraExecutor::STATUS_NEEDS_REVIEW, $r['status']);
        $this->assertFalse($r['certified'], 'a per-step pass must NOT certify a non-integrating obra');
        $this->assertSame('integration_failed', $r['disposition']);
        $this->assertSame('integrated_check_failed', $r['reason']);
        // Both steps DID deliver (the failure is at integration, not per-step).
        $this->assertSame(2, $r['delivered_nodes']);
        $this->assertFalse($r['certification']['integrated_test_result']['passed']);
        $this->assertSame(1, $r['certification']['integrated_test_result']['exit_code']);

        // The whole obra branch is KEPT for the operator (both step commits on it).
        $tree = $this->gOut(['ls-tree', '-r', '--name-only', 'atlas/obra/obra-conflict']);
        $this->assertStringContainsString('step1.php', $tree);
        $this->assertStringContainsString('step2.php', $tree);

        // The plan is recorded needs_review (NOT done) — honest, never a green claim.
        $this->assertSame('needs_review', DB::table('atlas_obra_plans')->where('id', 'obra-conflict')->value('status'));

        // BRAIN: the obra is recorded TRUTHFULLY as certified=false (never a green obra).
        $obraNode = AtlasAurgNode::query()->whereKey('obra:obra:obra-conflict')->first();
        $this->assertNotNull($obraNode);
        $this->assertFalse((bool) ($obraNode->meta['certified'] ?? true));
        $evidence = AtlasAurgNode::query()->whereKey('obra:evidence:obra-conflict')->first();
        $this->assertNotNull($evidence);
        $this->assertSame('failed', $evidence->meta['status'] ?? null);

        // Main still untouched even though the obra needs review.
        $this->assertTrue($r['main_untouched']);
        $this->assertSame($this->headBefore, trim($this->gOut(['rev-parse', 'HEAD'])));
    }

    // ------------------------------------------------------------------
    // 3) COMPOUNDING — a later AURG query reaches the recorded obra node.
    // ------------------------------------------------------------------

    public function test_a_later_aurg_query_reaches_the_recorded_obra_node_compounding(): void
    {
        $this->seedLinearPlan('obra-compound', 2);
        $executor = $this->executorWithBrain();

        $r = $executor->executePlanId('obra-compound', [
            'repo_dir' => $this->repo,
            'integrated_check' => 'true',
        ]);
        $this->assertSame(AtlasObraExecutor::STATUS_DONE, $r['status']);
        $this->assertTrue($r['brain_recorded'], 'the obra recorded into the brain');

        // A later provider-bound query (the NEXT obra's brain lookup) must SEE this obra.
        $query = new AtlasRealityGraphQueryService;
        $result = $query->query('obra obra-compound', ['provider_bound' => true]);

        $obraSeen = false;
        foreach ((array) $result['nodes'] as $node) {
            if (($node['id'] ?? null) === 'obra:obra:obra-compound') {
                $obraSeen = true;
                $this->assertTrue((bool) $node['provider_safe']);
                $this->assertSame('obra', $node['kind']);
            }
        }
        $this->assertTrue($obraSeen, 'the next provider-bound query must SEE the prior obra (compounding)');
    }

    // ------------------------------------------------------------------
    // 4) BUDGET — the integrated check honours the caller's timeout, not the 120s git constant.
    // ------------------------------------------------------------------

    public function test_integrated_check_honours_the_caller_budget_generous_certifies_tight_fails_closed(): void
    {
        // REGRESSION (HIGH false-reject): the whole-obra integrated check ran under the materializer's
        // 120s git constant regardless of the obra's DECLARED budget, so a legitimately-slow assembled
        // suite (bigger obra = slower) timed out → ran=false → integration_unrunnable → the GOOD branch
        // discarded. The executor must thread integrated_check_timeout.
        $this->seedLinearPlan('obra-slow-ok', 2);
        $ok = $this->executorWithBrain()->executePlanId('obra-slow-ok', [
            'repo_dir' => $this->repo,
            // ~2s but PASSING; a generous 10s budget lets it finish and certify.
            'integrated_check' => 'sleep 2; test -f step1.php && test -f step2.php',
            'integrated_check_timeout' => 10,
        ]);
        $this->assertSame(AtlasObraExecutor::STATUS_DONE, $ok['status'], 'reason: '.($ok['reason'] ?? ''));
        $this->assertTrue($ok['certified'], 'a slow-but-passing integrated check certifies under a generous budget (was: 120s default would also pass here, but a >120s suite would not)');
        $this->assertTrue($ok['certification']['integrated_test_result']['ran']);
        $this->assertTrue($ok['certification']['integrated_test_result']['passed']);

        // The SAME ~2s check under a TIGHT 1s budget times out → ran=false → fail-closed (never a
        // false green). This proves the timeout is actually threaded (not the 120s constant).
        $this->seedLinearPlan('obra-slow-tight', 2);
        $tight = $this->executorWithBrain()->executePlanId('obra-slow-tight', [
            'repo_dir' => $this->repo,
            'integrated_check' => 'sleep 2; exit 0',
            'integrated_check_timeout' => 1,
        ]);
        $this->assertSame(AtlasObraExecutor::STATUS_NEEDS_REVIEW, $tight['status']);
        $this->assertFalse($tight['certified'], 'a check exceeding the budget fails closed');
        $this->assertFalse((bool) ($tight['certification']['integrated_test_result']['ran'] ?? true), 'a timed-out check is recorded ran=false (integration unrunnable)');
    }

    // ------------------------------------------------------------------
    // fixtures (all cost-free — no provider call anywhere)
    // ------------------------------------------------------------------

    private function executorWithBrain(): AtlasObraExecutor
    {
        return new AtlasObraExecutor(
            $this->fakeDelivery(),
            new GovernedBranchMaterializationService,
            $this->brain(),
        );
    }

    private function brain(): AtlasRealityGraphIngestionService
    {
        return new AtlasRealityGraphIngestionService(
            new CrossDomainTaxonomyMap,
            app(AtlasMemoryPrivacyService::class),
            app(AtlasCrossDomainMeshService::class),
        );
    }

    /**
     * A deterministic fake delivery: each node writes its target file with a stable
     * body. ZERO provider spend. (The accumulation itself is proven in the F2 test;
     * here we only need certified files per step so the integrated check has a real
     * assembled tree to test.)
     */
    private function fakeDelivery(): ObraNodeDelivery
    {
        return new class implements ObraNodeDelivery
        {
            public function label(): string
            {
                return 'fake_f3';
            }

            public function deliver(string $request, array $context = []): array
            {
                $target = (string) ($context['target_area'] ?? 'step.php');

                return [
                    'certified' => true,
                    'files' => [['path' => $target, 'content' => "<?php\n// ".$request."\nreturn 1;\n"]],
                    'gate_receipt' => str_repeat('a', 40),
                    'provider' => 'fake',
                ];
            }
        };
    }

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

    private function createObraTables(): void
    {
        $migration = require database_path('migrations/2026_06_10_140000_create_atlas_obra_plan_tables.php');
        if (Schema::hasTable('atlas_obra_nodes')) {
            $migration->down();
        }
        $migration->up();
    }

    private function createAurgTables(): void
    {
        $migration = require database_path('migrations/2026_06_09_120000_create_atlas_aurg_graph_tables.php');
        $migration->down();
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
