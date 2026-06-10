<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Obra;

use App\Services\Ai\Obra\AtlasObraExecutor;
use App\Services\Ai\Obra\AtlasObraPlanService;
use App\Services\Ai\Obra\AtlasObraService;
use App\Services\Ai\Obra\DeterministicObraDecomposer;
use App\Services\Ai\Obra\ObraNodeDelivery;
use App\Services\Ai\RealExecution\GovernedBranchMaterializationService;
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * AOBG N3.F4 — the OPERATOR SURFACE: one call commissions an obra.
 *
 * Proves the F4 spine COST-FREE over sqlite + a REAL throwaway git repo: the
 * per-node delivery is a FAKE returning certified files per step (ZERO provider spend),
 * the decomposer is the deterministic one (cost-free), and the REAL
 * {@see GovernedBranchMaterializationService} obra-
 * accumulate mode produces ONE real branch — exactly one human gesture, end to end.
 *
 * Non-negotiable assertions:
 *  - commission(intent) decomposes → executes → certifies → returns the full envelope:
 *    {obra_id, intent(redacted), plan:[nodes], branch, certified, evidence,
 *    review_commands, main_untouched, never_merged};
 *  - the whole obra is ONE branch atlas/obra/<id> carrying all steps;
 *  - main stays byte-identical and the obra is never_merged;
 *  - a refused plan (empty intent) is an honest envelope (status=refused), no obra;
 *  - status(obra_id) reads back the plan + per-node status + branch (cost-free).
 */
final class AtlasObraServiceTest extends TestCase
{
    private string $repo = '';

    private string $headBefore = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createObraTables();
        config()->set('atlas.obra.enabled', true);
        // Brain write-back off here (no AURG store) — brain_recorded honestly false;
        // the brain-recorded path is proven in test_commission_records_into_the_brain.
        config()->set('atlas.aurg.enabled', false);

        $this->repo = sys_get_temp_dir().'/atlas-obra-svc-'.substr(md5(uniqid('', true)), 0, 8);
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

    public function test_commission_decomposes_executes_and_returns_the_full_envelope_one_branch(): void
    {
        $statusBefore = $this->gOut(['status', '--porcelain']);

        // ONE human gesture: a 3-clause intent → a 3-node obra → ONE branch.
        $obra = $this->service()->commission(
            'create step1.php; then create step2.php; then create step3.php',
            ['workspace' => 'atlas-server', 'repo_dir' => $this->repo],
        );

        // --- The full operator envelope. ---
        $this->assertSame('atlas.obra.commission.v1', $obra['schema']);
        $this->assertSame(AtlasObraExecutor::STATUS_DONE, $obra['status'], 'reason: '.($obra['reason'] ?? ''));
        $this->assertNotSame('', (string) $obra['obra_id']);
        $this->assertSame('atlas/obra/'.$obra['obra_id'], $obra['branch']);
        $this->assertTrue($obra['certified']);
        $this->assertTrue($obra['main_untouched']);
        $this->assertTrue($obra['never_merged']);
        $this->assertTrue($obra['never_pushed']);
        $this->assertTrue($obra['reversible']);
        $this->assertSame(3, $obra['node_count']);
        $this->assertSame(3, $obra['delivered_nodes']);
        $this->assertNull($obra['failed_node']);
        $this->assertSame('deterministic', $obra['decomposer']);

        // --- The plan-DAG is surfaced with per-step status (all done) + deps chain. ---
        $this->assertCount(3, $obra['plan']);
        foreach ($obra['plan'] as $i => $node) {
            $this->assertSame('done', $node['status'], 'node '.$i.' should be done');
            $this->assertSame($i, $node['seq']);
            if ($i > 0) {
                $this->assertNotEmpty($node['depends_on'], 'a chained step has a dependency');
            }
        }

        // --- The F3 integration-certification evidence envelope is carried through. ---
        $this->assertIsArray($obra['evidence']);
        $this->assertSame('atlas.obra.certification.v1', $obra['evidence']['schema']);
        $this->assertArrayHasKey('receipt_hash', $obra['evidence']);

        // --- review commands point the operator at the ONE branch (never a merge). ---
        $this->assertNotEmpty($obra['review_commands']);

        // --- ONE BRANCH carrying ALL steps; main byte-identical. ---
        $tree = $this->gOut(['ls-tree', '-r', '--name-only', $obra['branch']]);
        $this->assertStringContainsString('step1.php', $tree);
        $this->assertStringContainsString('step2.php', $tree);
        $this->assertStringContainsString('step3.php', $tree);
        $this->assertSame($this->headBefore, trim($this->gOut(['rev-parse', 'HEAD'])));
        $this->assertSame($statusBefore, $this->gOut(['status', '--porcelain']));

        // --- The spine tables recorded the obra done. ---
        $this->assertSame('done', DB::table('atlas_obra_plans')->where('id', $obra['obra_id'])->value('status'));
    }

    public function test_a_refused_plan_is_an_honest_envelope_no_obra(): void
    {
        $obra = $this->service()->commission('   ', ['repo_dir' => $this->repo]);

        $this->assertSame(AtlasObraService::STATUS_REFUSED, $obra['status']);
        $this->assertSame('intent_required', $obra['reason']);
        $this->assertNull($obra['branch']);
        $this->assertFalse($obra['certified']);
        $this->assertSame([], $obra['plan']);
        // No obra branch was ever cut.
        $exists = new Process(['git', 'branch', '--list', 'atlas/obra/*'], $this->repo);
        $exists->run();
        $this->assertSame('', trim($exists->getOutput()), 'a refused plan creates no branch');
    }

    public function test_a_halted_step_yields_a_failed_envelope_partial_branch_kept(): void
    {
        // step 2 (1-based) fails certification → the obra HALTS; step 3 never runs.
        $obra = $this->service($this->deliveryThatFailsStep(2))->commission(
            'create step1.php; then create step2.php; then create step3.php',
            ['workspace' => 'atlas-server', 'repo_dir' => $this->repo],
        );

        $this->assertSame(AtlasObraExecutor::STATUS_FAILED, $obra['status']);
        $this->assertFalse($obra['certified']);
        $this->assertSame($obra['obra_id'].':n1', $obra['failed_node']);
        $this->assertSame(1, $obra['delivered_nodes']);

        // The plan-DAG honestly shows n0 done, n1 failed, n2 skipped.
        $byId = collect($obra['plan'])->keyBy('id');
        $this->assertSame('done', $byId->get($obra['obra_id'].':n0')['status']);
        $this->assertSame('failed', $byId->get($obra['obra_id'].':n1')['status']);
        $this->assertSame('skipped', $byId->get($obra['obra_id'].':n2')['status']);

        // Main untouched even after a halt.
        $this->assertTrue($obra['main_untouched']);
        $this->assertSame($this->headBefore, trim($this->gOut(['rev-parse', 'HEAD'])));
    }

    public function test_status_reads_back_the_plan_per_node_status_and_branch(): void
    {
        $obra = $this->service()->commission(
            'create step1.php; then create step2.php',
            ['workspace' => 'atlas-server', 'repo_dir' => $this->repo],
        );
        $this->assertSame(AtlasObraExecutor::STATUS_DONE, $obra['status']);

        $status = $this->service()->status($obra['obra_id']);

        $this->assertTrue($status['found']);
        $this->assertSame($obra['obra_id'], $status['obra_id']);
        $this->assertSame('done', $status['status']);
        $this->assertSame('atlas-server', $status['workspace_id']);
        $this->assertSame('atlas/obra/'.$obra['obra_id'], $status['branch']);
        $this->assertSame(2, $status['node_count']);

        // Per-node status read back from the spine result column.
        $this->assertCount(2, $status['plan']);
        foreach ($status['plan'] as $node) {
            $this->assertSame('done', $node['status']);
            $this->assertNotEmpty($node['files_changed'], 'a done node recorded its files');
        }

        // An unknown id is an honest not-found (never a fabricated obra).
        $missing = $this->service()->status('obra-does-not-exist');
        $this->assertFalse($missing['found']);
        $this->assertSame('obra_not_found', $missing['reason']);
    }

    public function test_commission_records_into_the_brain_compounding(): void
    {
        // Stand up the AURG store so the brain write-back path runs (the executor's
        // recordObraOutcome). The whole commission stays cost-free (fake delivery).
        $aurg = require database_path('migrations/2026_06_09_120000_create_atlas_aurg_graph_tables.php');
        $aurg->down();
        $aurg->up();
        config()->set('atlas.aurg.enabled', true);

        $executor = new AtlasObraExecutor(
            $this->accumulatingDelivery(),
            new GovernedBranchMaterializationService,
            app(AtlasRealityGraphIngestionService::class),
        );
        $service = new AtlasObraService($this->planService(), $executor);

        $obra = $service->commission(
            'create step1.php; then create step2.php',
            ['workspace' => 'atlas-server', 'repo_dir' => $this->repo],
        );

        $this->assertSame(AtlasObraExecutor::STATUS_DONE, $obra['status'], 'reason: '.($obra['reason'] ?? ''));
        $this->assertTrue($obra['brain_recorded'], 'the obra is recorded back into the brain (compounding)');

        // The obra node exists in the AURG brain — so the NEXT obra would see it.
        $this->assertDatabaseHas('atlas_aurg_nodes', [
            'source_kind' => 'obra',
            'kind' => 'obra',
            'source_id' => $obra['obra_id'],
            'provider_safe' => true,
        ]);

        Schema::dropIfExists('atlas_aurg_edges');
        Schema::dropIfExists('atlas_aurg_nodes');
    }

    // ------------------------------------------------------------------
    // fixtures (all cost-free — no provider call anywhere)
    // ------------------------------------------------------------------

    private function service(?ObraNodeDelivery $delivery = null): AtlasObraService
    {
        $executor = new AtlasObraExecutor(
            $delivery ?? $this->accumulatingDelivery(),
            new GovernedBranchMaterializationService,
        );

        return new AtlasObraService($this->planService(), $executor);
    }

    private function planService(): AtlasObraPlanService
    {
        // Deterministic decomposer + no brain (cost-free planning).
        return new AtlasObraPlanService(
            new DeterministicObraDecomposer,
            app(CodeGraphWorkspaceIdentity::class),
            null,
        );
    }

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
                // The deterministic decomposer pulls the target file from the clause
                // ("create stepN.php" → target_area=stepN.php).
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
