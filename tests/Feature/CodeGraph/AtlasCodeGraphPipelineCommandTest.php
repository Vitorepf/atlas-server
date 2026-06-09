<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AP-815 · W-4 — the per-workspace code-graph pipeline command runs the whole
 * AWIS flow (resolve workspace_id -> index Code Intelligence -> build symbol graph)
 * end-to-end for the PRIMARY workspace and emits a structured summary.
 *
 * The full Postgres migration set can't run on the suite's sqlite :memory:
 * connection, so — following the proven sibling convention
 * (CodeGraphWorkspaceKeyingTest / CodeGraphSymbolBuildWorkspaceTest) — we boot
 * ONLY the tables the pipeline touches from the real create migrations + the W-1
 * workspace-keying migration. NOT RefreshDatabase.
 */
final class AtlasCodeGraphPipelineCommandTest extends TestCase
{
    /** @var array<int,string> */
    private const TABLES = [
        'atlas_engineering_doc_links',
        'atlas_engineering_code_symbols',
        'atlas_engineering_code_modules',
        'atlas_engineering_code_file_snapshots',
        'ai_codebase_world_model_edges',
        'ai_codebase_world_model_nodes',
        'ai_codebase_world_models',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        // Real create migrations for the Code Intelligence read-model + the
        // autonomous-engineering world-model tables the symbol builder writes to,
        // then the W-1 migration that adds the workspace_id keying.
        (require database_path('migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table.php'))->up();
        (require database_path('migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php'))->up();
        (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_pipeline_runs_for_the_primary_workspace_and_emits_a_summary(): void
    {
        $exit = Artisan::call('atlas:code-graph:pipeline', [
            '--workspace' => base_path(),
            '--json' => true,
        ]);

        $this->assertSame(0, $exit, 'the pipeline must succeed for the primary workspace');

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, 'the --json output must be valid JSON');

        $this->assertSame('atlas-server', $payload['workspace_id'] ?? null, 'base_path() resolves to the primary workspace id');
        $this->assertSame('ok', $payload['status'] ?? null);

        // An index summary with real, non-negative counts (base_path() has modules).
        $this->assertArrayHasKey('index', $payload);
        $this->assertSame('ok', $payload['index']['status'] ?? null);
        $this->assertArrayHasKey('module_count', $payload['index']);
        $this->assertArrayHasKey('symbol_count', $payload['index']);
        $this->assertGreaterThan(0, (int) $payload['index']['module_count'], 'the primary workspace indexes at least one module');

        // The symbol stage is present and reports a status (disabled is acceptable
        // when the real_edges flag is off — the pipeline still succeeds).
        $this->assertArrayHasKey('symbols', $payload);
        $this->assertArrayHasKey('status', $payload['symbols']);
    }

    public function test_pipeline_builds_real_symbol_graph_when_flag_on(): void
    {
        config(['atlas.code_graph.real_edges' => true]);

        $exit = Artisan::call('atlas:code-graph:pipeline', [
            '--workspace' => base_path(),
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('atlas-server', $payload['workspace_id'] ?? null);
        $this->assertSame(
            'written',
            $payload['symbols']['status'] ?? null,
            'with the flag on, the symbol graph is actually built (not disabled)',
        );
    }
}
