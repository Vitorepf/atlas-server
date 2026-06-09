<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphRegressionDetector;
use App\Services\Engineering\CodeGraph\CodeGraphSymbolBuilder;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AP-815 · D2 — GOLDEN-GRAPH BASELINE regression guard (characterization test).
 *
 * This is a characterization / golden-master test: it freezes a tiny fixture
 * workspace, indexes it through the REAL production path
 * (EngineeringCodeIntelligenceService::index -> CodeGraphSymbolBuilder::build),
 * and asserts the resulting graph shape — node count, edge count, per-edge-type
 * counts, and the EXTRACTED:INFERRED confidence split — matches a hard-coded
 * baseline computed from a first real run.
 *
 * Why a literal baseline: the graph is rebuilt on every index. A rebuild can
 * silently SHRINK for the wrong reasons (a parser regressed, a glob excluded a
 * tree, an extractor crashed mid-run, import-evidence promotion broke). The
 * graph still looks "fresh" while the EXTRACTED edges quietly collapse to ~0 and
 * poison every downstream context query. Hard-coding the expected counts turns
 * that silent degradation into a hard test failure at the seam.
 *
 * The KEY scenario this guards: a SILENT COLLAPSE OF EXTRACTED EDGES (extracted
 * edges dropping toward 0). {@see test_silent_extracted_edge_collapse_is_caught}
 * proves the detector flags it; {@see test_golden_graph_shape_matches_baseline}
 * proves the live build still matches the frozen baseline.
 *
 * Boots only the tables the path touches (RefreshDatabase is unreliable here —
 * pattern copied from CodeGraphIndexAllCommandTest), plus the two newest AP-815
 * migrations (composite edge indexes + snapshot mtime/file_hash).
 */
final class CodeGraphGoldenBaselineTest extends TestCase
{
    private const TABLES = [
        'atlas_engineering_doc_links', 'atlas_engineering_code_symbols', 'atlas_engineering_code_modules',
        'atlas_engineering_code_file_snapshots', 'ai_codebase_world_model_edges', 'ai_codebase_world_model_nodes', 'ai_codebase_world_models',
    ];

    // ---------------------------------------------------------------------
    // GOLDEN BASELINE — computed from a first real run of the frozen fixture
    // below, then hard-coded. If a legitimate fixture/parser change moves these
    // numbers, re-run, eyeball the new graph, and update these literals (that is
    // the deliberate human gate). Do NOT relax them to "greater than 0": the
    // whole point is to catch a SHRINK the build would otherwise hide.
    // ---------------------------------------------------------------------
    private const EXPECTED_NODE_COUNT = 6;
    private const EXPECTED_EDGE_COUNT = 5;

    /**
     * Per-edge-type counts. CHARACTERIZED (the legacy is the oracle): every edge
     * this fixture produces is 'depends_on'. The resolver only emits a 'tests'
     * edge from the test_targets bucket, which the parser populates ONLY from a
     * `Symbol::class` reference inside a tests/ file — NOT from `use` + `new`. The
     * test file's `use Fixture\Services\UserService;` is therefore graded as an
     * ordinary EXTRACTED 'depends_on' (import evidence), not a 'tests' edge. We
     * assert what the code ACTUALLY does, not what the names might suggest.
     */
    private const EXPECTED_EDGE_TYPES = [
        'depends_on' => 5,
    ];

    /** Confidence split read from each edge's persisted metadata.confidence. */
    private const EXPECTED_EXTRACTED_EDGES = 4;
    private const EXPECTED_INFERRED_EDGES = 1;

    private string $fixtureRoot = '';

    private string $workspaceId = '';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::TABLES as $t) {
            Schema::dropIfExists($t);
        }
        (require database_path('migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table.php'))->up();
        (require database_path('migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php'))->up();
        (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();
        // The two newest AP-815 migrations (task requirement).
        (require database_path('migrations/2026_06_09_120000_add_composite_indexes_to_ai_codebase_world_model_edges.php'))->up();
        (require database_path('migrations/2026_06_09_121000_add_mtime_and_file_hash_to_code_file_snapshots.php'))->up();

        config(['atlas.code_graph.real_edges' => true]);

        $this->fixtureRoot = sys_get_temp_dir().'/ap815_golden_'.substr(md5(uniqid('', true)), 0, 8);
        $this->writeFrozenFixture($this->fixtureRoot);

        // Resolve the workspace id the SAME way the index path does, so index()
        // (which resolves internally) and build($wid) agree on the scope.
        $this->workspaceId = app(CodeGraphWorkspaceIdentity::class)->resolve($this->fixtureRoot);
    }

    protected function tearDown(): void
    {
        if ($this->fixtureRoot !== '') {
            File::deleteDirectory($this->fixtureRoot);
        }
        foreach (self::TABLES as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    /**
     * GOLDEN MASTER: the live build of the frozen fixture must match the
     * hard-coded baseline exactly. Any drift (parser regressed, glob changed,
     * confidence grading moved) breaks this and forces a human to look.
     */
    public function test_golden_graph_shape_matches_baseline(): void
    {
        $graph = $this->buildGraph();

        $this->assertSame(
            self::EXPECTED_NODE_COUNT,
            $graph['node_count'],
            'GOLDEN node count drifted. Re-run, inspect the fixture graph, update EXPECTED_NODE_COUNT only if the change is intended.',
        );
        $this->assertSame(
            self::EXPECTED_EDGE_COUNT,
            $graph['edge_count'],
            'GOLDEN edge count drifted. A drop here usually means extraction regressed — inspect before updating EXPECTED_EDGE_COUNT.',
        );
        $this->assertSame(
            self::EXPECTED_EDGE_TYPES,
            $graph['edge_type_counts'],
            'GOLDEN per-edge-type split drifted (depends_on/tests). Inspect the resolver grading before updating EXPECTED_EDGE_TYPES.',
        );
        $this->assertSame(
            self::EXPECTED_EXTRACTED_EDGES,
            $graph['extracted_edges'],
            'GOLDEN EXTRACTED edge count drifted — the import-evidence promotion path may have regressed.',
        );
        $this->assertSame(
            self::EXPECTED_INFERRED_EDGES,
            $graph['inferred_edges'],
            'GOLDEN INFERRED edge count drifted.',
        );
    }

    /**
     * The EXTRACTED:INFERRED ratio is the health signal that degrades silently
     * when extraction breaks (every edge falls back to INFERRED, or the
     * extracted edges vanish entirely). Lock the ratio as a literal.
     */
    public function test_extracted_to_inferred_ratio_matches_baseline(): void
    {
        $graph = $this->buildGraph();

        // Ratio expressed as the extracted fraction of all confidence-graded
        // edges. 4 extracted / 5 total = 0.8. A collapse of extraction would
        // drive this toward 0.0.
        $total = $graph['extracted_edges'] + $graph['inferred_edges'];
        $this->assertGreaterThan(0, $total, 'fixture must produce confidence-graded edges');

        $extractedFraction = $graph['extracted_edges'] / $total;
        $this->assertEqualsWithDelta(
            0.8,
            $extractedFraction,
            0.0001,
            'GOLDEN EXTRACTED:INFERRED ratio drifted — extraction quality regressed.',
        );
    }

    /**
     * KEY SCENARIO — proves the Q-3 detector turns a silent collapse of the
     * graph (edges falling to ~0 on a rebuild) into a hard regression line.
     * Uses the REAL detector API (diff(before, after)) with the live baseline as
     * the "before" and a collapsed rebuild as the "after".
     */
    public function test_silent_extracted_edge_collapse_is_caught(): void
    {
        $detector = app(CodeGraphRegressionDetector::class);
        $baseline = $this->buildGraph();

        // Simulate a rebuild where extraction crashed mid-run: nodes survive,
        // but the edges silently collapsed to zero. This is exactly the failure
        // a "fresh-looking" rebuild hides.
        $before = ['node_count' => $baseline['node_count'], 'edge_count' => $baseline['edge_count']];
        $collapsed = ['node_count' => $baseline['node_count'], 'edge_count' => 0];

        $diff = $detector->diff($before, $collapsed);

        $this->assertNotEmpty(
            $diff['regressions'],
            'a collapse of all edges to 0 MUST be flagged as a regression',
        );
        $this->assertSame(0, $diff['after']['edges']);
        $this->assertSame($baseline['edge_count'], $diff['before']['edges']);
        $this->assertContains(
            'graph emptied: edges fell to 0 (was '.$baseline['edge_count'].')',
            $diff['regressions'],
            'the detector must name the edge collapse explicitly',
        );
    }

    /**
     * The detector must NOT cry wolf: a stable rebuild that reproduces the same
     * baseline graph yields no regression lines. (Guards against a flaky gate
     * that would mask the real collapse signal above.)
     */
    public function test_stable_rebuild_is_not_flagged_as_regression(): void
    {
        $detector = app(CodeGraphRegressionDetector::class);
        $baseline = $this->buildGraph();

        $snapshot = ['node_count' => $baseline['node_count'], 'edge_count' => $baseline['edge_count']];
        $diff = $detector->diff($snapshot, $snapshot);

        $this->assertSame([], $diff['regressions'], 'an identical rebuild is not a regression');
        $this->assertSame(0, $diff['node_delta']);
        $this->assertSame(0, $diff['edge_delta']);
    }

    /**
     * Re-running the index over the SAME frozen fixture must produce a byte-stable
     * graph shape — determinism is the precondition for a golden baseline to mean
     * anything. (The builder writes a fresh world model each run; we assert the
     * SHAPE of the latest build is identical across runs.)
     */
    public function test_rebuild_is_deterministic(): void
    {
        $first = $this->buildGraph();
        $second = $this->buildGraph();

        $this->assertSame($first['node_count'], $second['node_count']);
        $this->assertSame($first['edge_count'], $second['edge_count']);
        $this->assertSame($first['edge_type_counts'], $second['edge_type_counts']);
        $this->assertSame($first['extracted_edges'], $second['extracted_edges']);
        $this->assertSame($first['inferred_edges'], $second['inferred_edges']);
    }

    /**
     * Index the frozen fixture through the real path and read the resulting graph
     * shape back from the persisted world-model tables (latest build wins).
     *
     * @return array{world_model_id:string, node_count:int, edge_count:int, edge_type_counts:array<string,int>, extracted_edges:int, inferred_edges:int}
     */
    private function buildGraph(): array
    {
        app(EngineeringCodeIntelligenceService::class)->index([
            'workspace' => $this->fixtureRoot,
            'prune' => true,
        ]);

        $build = app(CodeGraphSymbolBuilder::class)->build($this->workspaceId);
        $this->assertSame(CodeGraphSymbolBuilder::STATUS_WRITTEN, $build['status'], 'symbol build must run (real_edges enabled)');

        $worldModelId = (string) $build['world_model_id'];

        $nodeCount = (int) DB::table('ai_codebase_world_model_nodes')
            ->where('world_model_id', $worldModelId)
            ->count();

        $edges = DB::table('ai_codebase_world_model_edges')
            ->where('world_model_id', $worldModelId)
            ->get(['edge_type', 'metadata']);

        $edgeTypeCounts = [];
        $extracted = 0;
        $inferred = 0;
        foreach ($edges as $edge) {
            $type = (string) $edge->edge_type;
            $edgeTypeCounts[$type] = ($edgeTypeCounts[$type] ?? 0) + 1;

            $meta = json_decode((string) ($edge->metadata ?? '{}'), true);
            $confidence = is_array($meta) ? (string) ($meta['confidence'] ?? '') : '';
            if ($confidence === 'EXTRACTED') {
                $extracted++;
            } elseif ($confidence === 'INFERRED') {
                $inferred++;
            }
        }
        ksort($edgeTypeCounts);

        return [
            'world_model_id' => $worldModelId,
            'node_count' => $nodeCount,
            'edge_count' => $edges->count(),
            'edge_type_counts' => $edgeTypeCounts,
            'extracted_edges' => $extracted,
            'inferred_edges' => $inferred,
        ];
    }

    /**
     * Write the FROZEN fixture workspace: 6 small PHP files shaped to produce a
     * known, extraction-heavy graph through the real parser. Every symbol below
     * is an edge endpoint, so all 6 become graph nodes. The exact resolved graph
     * (the oracle, captured from a real run) is:
     *   - UserService    --depends_on(EXTRACTED, use)--> BaseService
     *   - PaymentService --depends_on(EXTRACTED, use)--> BaseService
     *   - OrderService   --depends_on(EXTRACTED, use)--> Contract
     *   - OrderService   --depends_on(INFERRED, ::class no-use)--> BaseService
     *   - UserServiceTest --depends_on(EXTRACTED, use)--> UserService
     * Result: 6 nodes, 5 edges (all depends_on), split 4 EXTRACTED / 1 INFERRED.
     * The lone INFERRED edge comes from OrderService referencing
     * BaseService::class WITHOUT a matching `use` import — the import-evidence
     * promotion rule keeps it INFERRED, which is the signal a collapse erases.
     */
    private function writeFrozenFixture(string $root): void
    {
        File::makeDirectory($root.'/.git', 0777, true, true);
        File::makeDirectory($root.'/src/Foundation', 0777, true, true);
        File::makeDirectory($root.'/src/Services', 0777, true, true);
        File::makeDirectory($root.'/tests', 0777, true, true);

        File::put($root.'/src/Foundation/BaseService.php', <<<'PHP'
            <?php

            namespace Fixture\Foundation;

            class BaseService
            {
                public function boot(): void {}
            }
            PHP);

        File::put($root.'/src/Foundation/Contract.php', <<<'PHP'
            <?php

            namespace Fixture\Foundation;

            interface Contract
            {
                public function handle(): void;
            }
            PHP);

        File::put($root.'/src/Services/UserService.php', <<<'PHP'
            <?php

            namespace Fixture\Services;

            use Fixture\Foundation\BaseService;

            class UserService extends BaseService
            {
                public function register(): void {}
            }
            PHP);

        File::put($root.'/src/Services/PaymentService.php', <<<'PHP'
            <?php

            namespace Fixture\Services;

            use Fixture\Foundation\BaseService;

            class PaymentService extends BaseService
            {
                public function charge(): void {}
            }
            PHP);

        // Imports Contract (EXTRACTED) and references BaseService::class WITHOUT a
        // `use BaseService` import -> that reference grades INFERRED.
        File::put($root.'/src/Services/OrderService.php', <<<'PHP'
            <?php

            namespace Fixture\Services;

            use Fixture\Foundation\Contract;

            class OrderService implements Contract
            {
                public function handle(): void
                {
                    $target = \Fixture\Foundation\BaseService::class;
                }
            }
            PHP);

        File::put($root.'/tests/UserServiceTest.php', <<<'PHP'
            <?php

            namespace Fixture\Tests;

            use Fixture\Services\UserService;

            class UserServiceTest
            {
                public function test_registers(): void
                {
                    $service = new UserService();
                }
            }
            PHP);
    }
}
