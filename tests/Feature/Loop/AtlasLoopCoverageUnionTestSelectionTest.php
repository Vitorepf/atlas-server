<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopTestCoverageEdge;
use App\Services\Ai\AutonomousEvolution\AtlasLoopBroaderRegressionGate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ACDE QA5 — the broader-regression gate UNIONS learned cross-module coverage edges onto its static
 * subtree-map selection. The static map is blind to a suite in module X that exercises a class in module
 * Y; the ledger has those edges from real coverage runs. Default OFF => the ledger is never consulted =>
 * byte-identical selection. The union is purely ADDITIVE — it can only ADD a green suite, never hide a
 * regression.
 */
final class AtlasLoopCoverageUnionTestSelectionTest extends TestCase
{
    /** A real LOOP source whose static map = tests/Feature/Loop + tests/Unit/Ai/AutonomousEvolution. */
    private const LOOP_SOURCE = 'app/Services/Ai/AutonomousEvolution/AtlasLoopBroaderRegressionGate.php';

    /** A real CROSS-MODULE suite the loop static map does NOT select (so it can only arrive via the ledger). */
    private const CROSS_MODULE_TEST = 'tests/Feature/Ai/Obra/AtlasObraExecutorTest.php';

    protected function setUp(): void
    {
        parent::setUp();
        // The full migration set is Postgres-only; each test boots a fresh sqlite :memory: DB, so create
        // ONLY this lever's table from its own idempotent migration.
        if (! Schema::hasTable('atlas_loop_test_coverage_edges')) {
            (require base_path('database/migrations/2026_06_17_000100_create_atlas_loop_test_coverage_edges_table.php'))->up();
        }
    }

    private function gate(): AtlasLoopBroaderRegressionGate
    {
        return new AtlasLoopBroaderRegressionGate;
    }

    public function test_off_does_not_consult_the_ledger_byte_identical(): void
    {
        config(['atlas.loop.coverage_union_test_selection_enabled' => false]);
        AtlasLoopTestCoverageEdge::recordEdge(self::LOOP_SOURCE, self::CROSS_MODULE_TEST);

        $selected = $this->gate()->selectTestPaths(base_path(), [self::LOOP_SOURCE]);

        $this->assertNotContains(self::CROSS_MODULE_TEST, $selected, 'OFF must NOT union the ledger edge');
        // The static selection is unchanged and present.
        $this->assertContains(AtlasLoopBroaderRegressionGate::NEVER_MERGE_INVARIANT_TEST, $selected);
        $this->assertContains('tests/Feature/Loop', $selected);
        $this->assertContains('tests/Unit/Ai/AutonomousEvolution', $selected);
    }

    public function test_armed_unions_a_learned_cross_module_covering_suite(): void
    {
        config(['atlas.loop.coverage_union_test_selection_enabled' => true]);
        AtlasLoopTestCoverageEdge::recordEdge(self::LOOP_SOURCE, self::CROSS_MODULE_TEST);
        // A bogus edge to a NON-existent suite must never be selected (existing-path filter holds).
        AtlasLoopTestCoverageEdge::recordEdge(self::LOOP_SOURCE, 'tests/Feature/Ai/Obra/NoSuchPhantomTest.php');

        $selected = $this->gate()->selectTestPaths(base_path(), [self::LOOP_SOURCE]);

        $this->assertContains(self::CROSS_MODULE_TEST, $selected, 'the learned cross-module suite is unioned in');
        $this->assertNotContains('tests/Feature/Ai/Obra/NoSuchPhantomTest.php', $selected, 'a non-existent suite is filtered');
        // UNION, not replace: the static selection is still present.
        $this->assertContains(AtlasLoopBroaderRegressionGate::NEVER_MERGE_INVARIANT_TEST, $selected);
        $this->assertContains('tests/Feature/Loop', $selected);
    }

    public function test_armed_with_no_ledger_edge_for_the_changed_file_is_byte_identical(): void
    {
        config(['atlas.loop.coverage_union_test_selection_enabled' => true]);
        // An edge exists, but for a DIFFERENT source — the changed file has no learned coverage.
        AtlasLoopTestCoverageEdge::recordEdge('app/Services/Ai/Obra/AtlasObraExecutor.php', self::CROSS_MODULE_TEST);

        $armed = $this->gate()->selectTestPaths(base_path(), [self::LOOP_SOURCE]);

        config(['atlas.loop.coverage_union_test_selection_enabled' => false]);
        $off = $this->gate()->selectTestPaths(base_path(), [self::LOOP_SOURCE]);

        $this->assertSame($off, $armed, 'armed but with no edge for this source => identical to OFF (no phantom union)');
        $this->assertNotContains(self::CROSS_MODULE_TEST, $armed);
    }

    public function test_record_edge_upserts_and_increments_observed_count(): void
    {
        AtlasLoopTestCoverageEdge::recordEdge(self::LOOP_SOURCE, self::CROSS_MODULE_TEST);
        AtlasLoopTestCoverageEdge::recordEdge(self::LOOP_SOURCE, self::CROSS_MODULE_TEST);

        $rows = AtlasLoopTestCoverageEdge::query()
            ->where('source_path', self::LOOP_SOURCE)
            ->where('test_path', self::CROSS_MODULE_TEST)
            ->get();

        $this->assertCount(1, $rows, 'the (source,test) pair is upserted, never duplicated');
        $this->assertSame(2, $rows->first()->observed_count, 'a repeat sighting increments the count');
        $this->assertNotNull($rows->first()->last_observed_at);
    }

    public function test_test_paths_for_filters_unrelated_sources_and_dedups(): void
    {
        AtlasLoopTestCoverageEdge::recordEdge(self::LOOP_SOURCE, self::CROSS_MODULE_TEST);
        AtlasLoopTestCoverageEdge::recordEdge('app/Services/Other.php', 'tests/Feature/Loop/AtlasLoopAutoMergeServiceTest.php');

        $paths = AtlasLoopTestCoverageEdge::testPathsFor([self::LOOP_SOURCE]);

        $this->assertSame([self::CROSS_MODULE_TEST], $paths, 'only edges for the queried source are returned');
        $this->assertSame([], AtlasLoopTestCoverageEdge::testPathsFor([]), 'no sources => no edges');
    }
}
