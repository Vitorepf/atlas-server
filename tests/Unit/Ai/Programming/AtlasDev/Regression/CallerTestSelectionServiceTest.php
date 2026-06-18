<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Regression;

use App\Services\Ai\Programming\AtlasDev\Regression\CallerTestSelectionService;
use App\Services\Ai\Programming\ProgrammingTestImpactAnalyzer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * E5 -- CallerTestSelectionService unit tests (red-first).
 *
 * VAL-E5-006: changed symbol S with direct caller C (caller test T_C)
 *             expands selected_existing_tests to a strict superset
 *             containing T_C.
 * VAL-E5-008: caller tests flow through ProgrammingTestImpactAnalyzer
 *             via $codeGraph['related_tests'] (not a side channel).
 * VAL-E5-009: with CI tables absent, the expansion does not crash and
 *             falls back to conventional impacted-tests selection.
 *
 * The service resolves a $codeGraph payload (with 'related_tests') from
 * the CodeGraph read-model, guarded by DatabaseTableAvailability::has().
 * The analyzer then merges related_tests into selected_existing_tests.
 *
 * Tables are booted in setUp from the real migrations (the repo's
 * established pattern for code-intelligence feature tests) and dropped
 * in tearDown. The "tables absent" case is exercised by calling the
 * service after the tables are dropped (simulating a deployment without
 * the CI tables).
 */
final class CallerTestSelectionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'atlas_engineering_doc_links',
            'atlas_engineering_code_symbols',
            'atlas_engineering_code_modules',
            'atlas_engineering_code_file_snapshots',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        (require database_path('migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table.php'))->up();
    }

    protected function tearDown(): void
    {
        foreach ([
            'atlas_engineering_doc_links',
            'atlas_engineering_code_symbols',
            'atlas_engineering_code_modules',
            'atlas_engineering_code_file_snapshots',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    /**
     * VAL-E5-008: the service produces a $codeGraph whose related_tests
     * contain the caller test T_C for a changed symbol S, and the analyzer
     * merges those related_tests into selected_existing_tests.
     */
    public function test_val_e5_008_related_tests_populate_code_graph_for_analyzer(): void
    {
        // Changed symbol S lives in app/Service.php.
        // Caller C lives in app/Consumer.php whose file snapshot records a
        // symbol_reference to S and a test_target tests/Unit/ConsumerTest.php.
        $this->seedChangedSymbol('App\\Service', 'app/Service.php');
        $this->seedCallerTest('App\\Service', 'app/Consumer.php', 'tests/Unit/ConsumerTest.php');

        $service = new CallerTestSelectionService;
        $codeGraph = $service->resolveCodeGraph(
            changedFiles: ['app/Service.php'],
            workspace: base_path(),
        );

        $this->assertNotEmpty(
            $codeGraph['related_tests'],
            'VAL-E5-008: related_tests populated with caller tests',
        );
        $this->assertContains(
            'tests/Unit/ConsumerTest.php',
            $codeGraph['related_tests'],
            'VAL-E5-008: the caller test T_C is in related_tests',
        );

        // The analyzer merges related_tests into selected_existing_tests.
        // We pass a non-test changed file so the conventional candidates
        // do not already include ConsumerTest.php.
        $analyzer = new ProgrammingTestImpactAnalyzer;
        $impact = $analyzer->analyze(['app/Service.php'], $codeGraph);

        $this->assertContains(
            'tests/Unit/ConsumerTest.php',
            $impact['selected_tests'],
            'VAL-E5-008: analyzer widens selected_tests via related_tests',
        );
    }

    /**
     * VAL-E5-006: the expanded selection is a STRICT SUPERSET of the
     * conventional floor: it contains T_C AND every conventional candidate.
     */
    public function test_val_e5_006_expanded_selection_is_strict_superset_containing_caller_test(): void
    {
        $this->seedChangedSymbol('App\\Service', 'app/Service.php');
        $this->seedCallerTest('App\\Service', 'app/Consumer.php', 'tests/Unit/ConsumerTest.php');

        $service = new CallerTestSelectionService;

        // Conventional selection (no codeGraph): the analyzer's convention
        // maps app/Service.php to tests/Unit/ServiceTest.php (if it existed).
        // We assert against the floor by calling the analyzer with NO graph.
        $conventional = (new ProgrammingTestImpactAnalyzer)->analyze(['app/Service.php']);
        $conventionalSet = $conventional['selected_tests'];

        // Expanded selection (with codeGraph from caller tests).
        $codeGraph = $service->resolveCodeGraph(['app/Service.php'], base_path());
        $expanded = (new ProgrammingTestImpactAnalyzer)->analyze(['app/Service.php'], $codeGraph);
        $expandedSet = $expanded['selected_tests'];

        // Strict superset: every conventional candidate is in expanded ...
        foreach ($conventionalSet as $candidate) {
            $this->assertContains(
                $candidate,
                $expandedSet,
                'VAL-E5-006: conventional candidate preserved in expanded set',
            );
        }
        // ... AND expanded contains T_C that the conventional floor does NOT.
        $this->assertContains(
            'tests/Unit/ConsumerTest.php',
            $expandedSet,
            'VAL-E5-006: caller test T_C added to expanded set',
        );
        $this->assertNotContains(
            'tests/Unit/ConsumerTest.php',
            $conventionalSet,
            'VAL-E5-006: caller test T_C was NOT in the conventional floor',
        );
    }

    /**
     * VAL-E5-009: with CI tables ABSENT, the service does NOT crash and
     * returns an empty related_tests list; the analyzer then falls back
     * to the conventional impacted-tests selection (byte-identical).
     */
    public function test_val_e5_009_tables_absent_no_crash_empty_related_tests(): void
    {
        // Drop the tables to simulate a deployment without CI tables.
        foreach ([
            'atlas_engineering_code_symbols',
            'atlas_engineering_code_file_snapshots',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        $service = new CallerTestSelectionService;

        // No exception; empty related_tests.
        $codeGraph = $service->resolveCodeGraph(['app/Service.php'], base_path());

        $this->assertSame(
            [],
            $codeGraph['related_tests'],
            'VAL-E5-009: tables absent => empty related_tests (no crash)',
        );
        $this->assertArrayHasKey(
            'tables',
            $codeGraph,
            'VAL-E5-009: codeGraph reports table availability for auditability',
        );

        // The analyzer's output with the (empty) codeGraph is byte-identical
        // to calling it with no codeGraph at all: conventional selection.
        $withGraph = (new ProgrammingTestImpactAnalyzer)->analyze(['app/Service.php'], $codeGraph);
        $withoutGraph = (new ProgrammingTestImpactAnalyzer)->analyze(['app/Service.php']);

        $this->assertSame(
            $withoutGraph['selected_tests'],
            $withGraph['selected_tests'],
            'VAL-E5-009: empty related_tests => conventional selection (byte-identical)',
        );
    }

    /**
     * VAL-E5-009 corollary: the tables-availability guard flags the CI
     * tables as absent so the caller can record the degradation reason.
     */
    public function test_val_e5_009_reports_table_availability_in_code_graph(): void
    {
        // Drop the tables.
        Schema::dropIfExists('atlas_engineering_code_symbols');
        Schema::dropIfExists('atlas_engineering_code_file_snapshots');

        $service = new CallerTestSelectionService;
        $codeGraph = $service->resolveCodeGraph(['app/Service.php'], base_path());

        $this->assertFalse(
            $codeGraph['tables']['symbols'] ?? true,
            'VAL-E5-009: symbols table reported absent',
        );
        $this->assertFalse(
            $codeGraph['tables']['file_snapshots'] ?? true,
            'VAL-E5-009: file_snapshots table reported absent',
        );
    }

    /**
     * Edge: no changed files => empty related_tests (no crash, no query).
     */
    public function test_empty_changed_files_yields_empty_related_tests(): void
    {
        $service = new CallerTestSelectionService;
        $codeGraph = $service->resolveCodeGraph([], base_path());

        $this->assertSame([], $codeGraph['related_tests']);
        $this->assertSame([], $codeGraph['changed_symbols']);
    }

    /**
     * Edge: changed file with no known symbols => empty related_tests
     * (the file is not in the code graph; no crash).
     */
    public function test_changed_file_with_no_known_symbols_yields_empty_related_tests(): void
    {
        $this->seedChangedSymbol('App\\Service', 'app/Service.php');
        // No caller snapshot seeded.

        $service = new CallerTestSelectionService;
        $codeGraph = $service->resolveCodeGraph(['app/Service.php'], base_path());

        // changed_symbols may be populated, but related_tests stays empty
        // (no consumer/caller relation recorded).
        $this->assertSame(
            [],
            $codeGraph['related_tests'],
            'no caller relations => empty related_tests',
        );
    }

    // -- Seed helpers ---------------------------------------------------------

    /**
     * Seed a changed symbol into the code-intelligence read-model.
     */
    private function seedChangedSymbol(string $symbolName, string $filePath): void
    {
        DB::table('atlas_engineering_code_symbols')->insert([
            'id' => (string) Str::uuid(),
            'module_id' => null,
            'symbol_type' => 'class',
            'symbol_name' => $symbolName,
            'file_path' => $filePath,
            'line_start' => 10,
            'line_end' => 20,
            'language' => 'php',
            'signature' => null,
            'namespace' => 'App',
            'parent_symbol' => null,
            'visibility' => 'public',
            'status' => 'active',
            'docs_status' => 'undocumented',
            'source_hash' => hash('sha256', $symbolName.$filePath),
            'related_doc_ids_json' => '[]',
            'metadata' => '{}',
            'indexed_at' => now(),
            'archived_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Seed a file snapshot for a caller file whose relations_json records a
     * symbol_reference to the changed symbol AND a test_target pointing at
     * a caller test. Mirrors the shape AtlasLoopCrossFileConsumerGateService
     * reads from atlas_engineering_code_file_snapshots.relations_json.
     */
    private function seedCallerTest(string $changedSymbol, string $callerFile, string $testPath): void
    {
        $relations = [
            'symbol_references' => [
                [
                    'symbol' => $changedSymbol,
                    'kind' => 'call',
                    'file_path' => $callerFile,
                    'line' => 42,
                ],
            ],
            'test_targets' => [
                [
                    'symbol' => $changedSymbol,
                    'kind' => 'covers',
                    'file_path' => $callerFile,
                    'test_path' => $testPath,
                ],
            ],
            'dependencies' => [],
        ];

        DB::table('atlas_engineering_code_file_snapshots')->insert([
            'id' => (string) Str::uuid(),
            'file_path' => $callerFile,
            'module_slug' => 'test-module',
            'language' => 'php',
            'source_hash' => hash('sha256', $callerFile),
            'file_size' => 100,
            'symbols_json' => '[]',
            'relations_json' => json_encode($relations, JSON_THROW_ON_ERROR),
            'status' => 'active',
            'indexed_at' => now(),
            'archived_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
