<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphWorkspacePurger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AP-815 · G-9 — proves the workspace purger is a clean right-to-forget executor:
 * purging one workspace removes ONLY that workspace's relational rows + world models
 * (module + symbol scope, with their nodes/edges) and leaves every other workspace
 * byte-for-byte intact; the primary (home) workspace is refused unless forced.
 *
 * Boots only the needed tables in setUp (the repo's established pattern — full
 * RefreshDatabase is unreliable here because a core migration is pgsql-only SQL).
 */
final class CodeGraphWorkspacePurgerTest extends TestCase
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

        // Real create migrations (pre-W-1 schema) + the world-model tables, then the W-1
        // migration adds workspace_id and swaps the unique indexes.
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

    public function test_purges_only_the_target_workspace_graph_and_leaves_others_intact(): void
    {
        // Seed a full graph (relational + module/symbol world models w/ nodes & edges)
        // for BOTH the home workspace and a second project.
        $this->seedWorkspaceGraph('atlas-server');
        $this->seedWorkspaceGraph('blackink');

        // Sanity: both workspaces present across every family before the purge.
        $this->assertSame(1, DB::table('atlas_engineering_code_modules')->where('workspace_id', 'blackink')->count());
        $this->assertSame(2, DB::table('atlas_engineering_code_symbols')->where('workspace_id', 'blackink')->count());
        $this->assertSame(2, DB::table('ai_codebase_world_models')->whereIn('scope', ['blackink', 'blackink-symbols'])->count());
        $blackinkModelIds = DB::table('ai_codebase_world_models')
            ->whereIn('scope', ['blackink', 'blackink-symbols'])->pluck('id')->all();
        $this->assertSame(2, DB::table('ai_codebase_world_model_nodes')->whereIn('world_model_id', $blackinkModelIds)->count());
        $this->assertSame(1, DB::table('ai_codebase_world_model_edges')->whereIn('world_model_id', $blackinkModelIds)->count());

        $result = app(CodeGraphWorkspacePurger::class)->purge('blackink');

        // Result contract: exact per-family counts, not protected.
        $this->assertSame('blackink', $result['workspace_id']);
        $this->assertFalse($result['protected']);
        $this->assertSame([
            'modules' => 1,
            'symbols' => 2,
            'doc_links' => 1,
            'file_snapshots' => 1,
            'world_models' => 2,
        ], $result['deleted']);

        // blackink is GONE from every family…
        $this->assertSame(0, DB::table('atlas_engineering_code_modules')->where('workspace_id', 'blackink')->count());
        $this->assertSame(0, DB::table('atlas_engineering_code_symbols')->where('workspace_id', 'blackink')->count());
        $this->assertSame(0, DB::table('atlas_engineering_doc_links')->where('workspace_id', 'blackink')->count());
        $this->assertSame(0, DB::table('atlas_engineering_code_file_snapshots')->where('workspace_id', 'blackink')->count());
        $this->assertSame(0, DB::table('ai_codebase_world_models')->whereIn('scope', ['blackink', 'blackink-symbols'])->count());
        // …including its world-model children (no orphans left behind).
        $this->assertSame(0, DB::table('ai_codebase_world_model_nodes')->whereIn('world_model_id', $blackinkModelIds)->count());
        $this->assertSame(0, DB::table('ai_codebase_world_model_edges')->whereIn('world_model_id', $blackinkModelIds)->count());

        // …while atlas-server is completely untouched.
        $this->assertSame(1, DB::table('atlas_engineering_code_modules')->where('workspace_id', 'atlas-server')->count());
        $this->assertSame(2, DB::table('atlas_engineering_code_symbols')->where('workspace_id', 'atlas-server')->count());
        $this->assertSame(1, DB::table('atlas_engineering_doc_links')->where('workspace_id', 'atlas-server')->count());
        $this->assertSame(1, DB::table('atlas_engineering_code_file_snapshots')->where('workspace_id', 'atlas-server')->count());
        $this->assertSame(2, DB::table('ai_codebase_world_models')->whereIn('scope', ['atlas-server', 'atlas-server-symbols'])->count());
        $atlasModelIds = DB::table('ai_codebase_world_models')
            ->whereIn('scope', ['atlas-server', 'atlas-server-symbols'])->pluck('id')->all();
        $this->assertSame(2, DB::table('ai_codebase_world_model_nodes')->whereIn('world_model_id', $atlasModelIds)->count());
        $this->assertSame(1, DB::table('ai_codebase_world_model_edges')->whereIn('world_model_id', $atlasModelIds)->count());
    }

    public function test_refuses_to_purge_the_primary_workspace_without_force(): void
    {
        $this->seedWorkspaceGraph('atlas-server');

        $result = app(CodeGraphWorkspacePurger::class)->purge('atlas-server');

        // Refused: protected flag set, nothing deleted.
        $this->assertTrue($result['protected']);
        $this->assertSame('atlas-server', $result['workspace_id']);
        $this->assertSame([
            'modules' => 0,
            'symbols' => 0,
            'doc_links' => 0,
            'file_snapshots' => 0,
            'world_models' => 0,
        ], $result['deleted']);

        // The home graph is fully intact — nothing was touched.
        $this->assertSame(1, DB::table('atlas_engineering_code_modules')->where('workspace_id', 'atlas-server')->count());
        $this->assertSame(2, DB::table('atlas_engineering_code_symbols')->where('workspace_id', 'atlas-server')->count());
        $this->assertSame(2, DB::table('ai_codebase_world_models')->whereIn('scope', ['atlas-server', 'atlas-server-symbols'])->count());
    }

    public function test_force_overrides_the_primary_workspace_protection(): void
    {
        $this->seedWorkspaceGraph('atlas-server');

        $result = app(CodeGraphWorkspacePurger::class)->purge('atlas-server', ['force' => true]);

        // Forced: not protected, the home graph IS reclaimed.
        $this->assertFalse($result['protected']);
        $this->assertSame([
            'modules' => 1,
            'symbols' => 2,
            'doc_links' => 1,
            'file_snapshots' => 1,
            'world_models' => 2,
        ], $result['deleted']);

        $this->assertSame(0, DB::table('atlas_engineering_code_modules')->where('workspace_id', 'atlas-server')->count());
        $this->assertSame(0, DB::table('ai_codebase_world_models')->whereIn('scope', ['atlas-server', 'atlas-server-symbols'])->count());
    }

    public function test_purging_an_unknown_workspace_is_a_safe_no_op(): void
    {
        $this->seedWorkspaceGraph('blackink');

        $result = app(CodeGraphWorkspacePurger::class)->purge('never-indexed-project');

        // Nothing matched: zero deletions, not protected, and the existing workspace is intact.
        $this->assertFalse($result['protected']);
        $this->assertSame('never-indexed-project', $result['workspace_id']);
        $this->assertSame([
            'modules' => 0,
            'symbols' => 0,
            'doc_links' => 0,
            'file_snapshots' => 0,
            'world_models' => 0,
        ], $result['deleted']);

        $this->assertSame(1, DB::table('atlas_engineering_code_modules')->where('workspace_id', 'blackink')->count());
    }

    public function test_purge_is_fail_safe_when_a_keyed_table_is_missing(): void
    {
        // Drop one keyed table to simulate a partial / pre-W-1 schema. The purger must
        // skip it (Schema::hasTable guard) rather than throw, and still purge the rest.
        $this->seedWorkspaceGraph('blackink');
        Schema::dropIfExists('atlas_engineering_doc_links');

        $result = app(CodeGraphWorkspacePurger::class)->purge('blackink');

        $this->assertFalse($result['protected']);
        // doc_links table is gone → reported as 0 deleted, no error; the rest still purged.
        $this->assertSame(0, $result['deleted']['doc_links']);
        $this->assertSame(1, $result['deleted']['modules']);
        $this->assertSame(2, $result['deleted']['symbols']);
        $this->assertSame(2, $result['deleted']['world_models']);
        $this->assertSame(0, DB::table('atlas_engineering_code_modules')->where('workspace_id', 'blackink')->count());
    }

    /**
     * Seed a complete graph for a workspace: 1 module, 2 symbols, 1 doc link, 1 file
     * snapshot, plus a module-scope and a symbol-scope world model — each carrying 1 node,
     * and the module model carrying 1 edge — so the purge has every family to exercise.
     */
    private function seedWorkspaceGraph(string $workspace): void
    {
        DB::table('atlas_engineering_code_modules')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace,
            'slug' => 'module-'.$workspace,
            'name' => 'Module '.$workspace,
            'layer' => 'runtime',
            'source_hash' => substr(hash('sha256', $workspace.':module'), 0, 64),
        ]);

        foreach (['alpha', 'beta'] as $sym) {
            DB::table('atlas_engineering_code_symbols')->insert([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace,
                'symbol_type' => 'class',
                'symbol_name' => $sym,
                'file_path' => "src/{$workspace}/{$sym}.php",
                'source_hash' => substr(hash('sha256', $workspace.':'.$sym), 0, 64),
            ]);
        }

        DB::table('atlas_engineering_doc_links')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace,
            'link_type' => 'module_doc',
            'canonical_path' => "docs/{$workspace}.md",
            'link_hash' => substr(hash('sha256', $workspace.':link'), 0, 64),
        ]);

        DB::table('atlas_engineering_code_file_snapshots')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace,
            'file_path' => "src/{$workspace}/main.php",
            'module_slug' => 'module-'.$workspace,
            'source_hash' => substr(hash('sha256', $workspace.':file'), 0, 64),
        ]);

        // Module-scope world model "<ws>" + symbol-scope world model "<ws>-symbols".
        $moduleModelId = $this->insertWorldModel($workspace, $workspace);
        $symbolModelId = $this->insertWorldModel($workspace.'-symbols', $workspace);

        // A node under each model, and one edge under the module model.
        $this->insertWorldModelNode($moduleModelId, 'node-module');
        $this->insertWorldModelNode($symbolModelId, 'node-symbol');
        $this->insertWorldModelEdge($moduleModelId);
    }

    private function insertWorldModel(string $scope, string $workspace): string
    {
        $id = (string) Str::uuid();
        DB::table('ai_codebase_world_models')->insert([
            'id' => $id,
            'model_id' => 'model-'.substr(hash('sha256', $scope), 0, 18),
            'scope' => $scope,
            'status' => 'built',
            'model_hash' => substr(hash('sha256', 'model:'.$scope.':'.$workspace), 0, 64),
        ]);

        return $id;
    }

    private function insertWorldModelNode(string $worldModelId, string $nodeId): void
    {
        DB::table('ai_codebase_world_model_nodes')->insert([
            'id' => (string) Str::uuid(),
            'world_model_id' => $worldModelId,
            'node_id' => $nodeId,
            'node_type' => 'symbol',
        ]);
    }

    private function insertWorldModelEdge(string $worldModelId): void
    {
        DB::table('ai_codebase_world_model_edges')->insert([
            'id' => (string) Str::uuid(),
            'world_model_id' => $worldModelId,
            'from_node_id' => 'node-module',
            'to_node_id' => 'node-symbol',
            'edge_type' => 'calls',
        ]);
    }
}
