<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use App\Models\AiCodebaseWorldModel;
use App\Services\Engineering\CodeGraph\CodeGraphSymbolBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AP-815 · W-2 — the symbol-level code graph builds PER WORKSPACE: the world model
 * is scoped "<workspace_id>-symbols" (no hardcoded atlas-server) and only that
 * workspace's symbols are loaded (a foreign workspace sees an empty graph).
 */
final class CodeGraphSymbolBuildWorkspaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['atlas_engineering_doc_links', 'atlas_engineering_code_symbols', 'atlas_engineering_code_modules', 'atlas_engineering_code_file_snapshots', 'ai_codebase_world_model_edges', 'ai_codebase_world_model_nodes', 'ai_codebase_world_models'] as $table) {
            Schema::dropIfExists($table);
        }
        (require database_path('migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table.php'))->up();
        (require database_path('migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php'))->up();
        (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();

        config(['atlas.code_graph.real_edges' => true]);

        // atlas-server: two classes with a resolvable dependency A -> B (forms 1 edge, 2 nodes).
        $this->symbol('atlas-server', 'class', 'App\\Alpha', 'src/Alpha.php');
        $this->symbol('atlas-server', 'class', 'App\\Beta', 'src/Beta.php');
        $this->snapshot('atlas-server', 'src/Alpha.php', ['dependencies' => [['symbol' => 'App\\Beta', 'kind' => 'use']]]);
    }

    protected function tearDown(): void
    {
        foreach (['atlas_engineering_doc_links', 'atlas_engineering_code_symbols', 'atlas_engineering_code_modules', 'atlas_engineering_code_file_snapshots'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    private function symbol(string $workspace, string $type, string $name, string $file): void
    {
        DB::table('atlas_engineering_code_symbols')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace,
            'symbol_type' => $type,
            'symbol_name' => $name,
            'file_path' => $file,
            'language' => 'php',
            'status' => 'active',
            'source_hash' => substr(hash('sha256', $workspace.$name.$file), 0, 64),
        ]);
    }

    private function snapshot(string $workspace, string $file, array $relations): void
    {
        DB::table('atlas_engineering_code_file_snapshots')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace,
            'file_path' => $file,
            'module_slug' => 'app',
            'language' => 'php',
            'source_hash' => substr(hash('sha256', $workspace.$file), 0, 64),
            'symbols_json' => '[]',
            'relations_json' => (string) json_encode($relations),
            'status' => 'active',
        ]);
    }

    public function test_build_is_scoped_to_the_primary_workspace(): void
    {
        $summary = app(CodeGraphSymbolBuilder::class)->build('atlas-server');

        $this->assertSame('written', $summary['status']);
        $this->assertSame('atlas-server', $summary['workspace_id']);
        $this->assertSame(2, $summary['symbol_nodes'], 'both atlas-server classes participate in the edge');
        $this->assertGreaterThanOrEqual(1, $summary['edges_written']);

        $model = AiCodebaseWorldModel::query()->find($summary['world_model_id']);
        $this->assertNotNull($model);
        $this->assertSame('atlas-server-symbols', $model->scope, 'the hardcoded scope is gone — it is workspace-scoped');
    }

    public function test_foreign_workspace_gets_its_own_empty_graph(): void
    {
        $summary = app(CodeGraphSymbolBuilder::class)->build('blackink');

        $this->assertSame('written', $summary['status']);
        $this->assertSame('blackink', $summary['workspace_id']);
        $this->assertSame(0, $summary['symbol_nodes'], 'blackink has no symbols — isolation holds');

        $model = AiCodebaseWorldModel::query()->find($summary['world_model_id']);
        $this->assertNotNull($model);
        $this->assertSame('blackink-symbols', $model->scope);
    }

    public function test_two_workspaces_produce_two_distinct_models(): void
    {
        $a = app(CodeGraphSymbolBuilder::class)->build('atlas-server');
        $b = app(CodeGraphSymbolBuilder::class)->build('blackink');

        $this->assertNotSame($a['world_model_id'], $b['world_model_id']);
        $scopes = AiCodebaseWorldModel::query()
            ->whereIn('id', [$a['world_model_id'], $b['world_model_id']])
            ->pluck('scope')
            ->all();
        sort($scopes);
        $this->assertSame(['atlas-server-symbols', 'blackink-symbols'], $scopes);
    }
}
