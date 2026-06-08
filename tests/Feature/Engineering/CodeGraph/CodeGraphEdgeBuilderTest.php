<?php

namespace Tests\Feature\Engineering\CodeGraph;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AiCodebaseWorldModelNode;
use App\Models\AtlasEngineeringCodeModule;
use App\Services\Engineering\CodeGraph\CodeGraphEdgeBuilder;
use App\Services\Engineering\CodeGraph\CodeGraphEdgeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CodeGraphEdgeBuilderTest extends TestCase
{
    // NOTE: the suite default connection is sqlite :memory: (phpunit.xml) and
    // the full migration set includes raw-Postgres DDL
    // (2026_04_27_000000_create_atlas_core_tables.php: CREATE EXTENSION /
    // TIMESTAMPTZ / PL/pgSQL triggers) that sqlite cannot parse, so
    // RefreshDatabase fatals here. Following the proven sibling convention
    // (WorldModelGraphRankerTest), boot only the few Blueprint-based tables this
    // adapter touches. The builder logic — Eloquent upserts, the existence-gate
    // query and the config flag — is still exercised for real.

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootSchema();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();

        parent::tearDown();
    }

    public function test_builds_real_depends_on_edge_when_flag_enabled(): void
    {
        config()->set('atlas.code_graph.real_edges', true);

        $model = $this->seedWorldModelWithTwoNodes();
        $this->seedModule('ai-dev', 'app/Services/Ai/Dev');
        $this->seedModule('ai-router', 'app/Services/Ai/Router');
        $this->seedFileSnapshotWithUseDependency('ai-dev', 'ai-router');

        $summary = (new CodeGraphEdgeBuilder)->build($model);

        $this->assertSame(CodeGraphEdgeBuilder::STATUS_WRITTEN, $summary['status']);
        $this->assertSame((string) $model->id, $summary['world_model_id']);
        $this->assertSame(1, $summary['edges_written']);

        $edge = AiCodebaseWorldModelEdge::query()
            ->where('world_model_id', $model->id)
            ->where('from_node_id', 'node:app/Services/Ai/Dev')
            ->where('to_node_id', 'node:app/Services/Ai/Router')
            ->where('edge_type', 'depends_on')
            ->first();

        $this->assertNotNull($edge, 'expected a real depends_on edge to be persisted');
        $this->assertSame(CodeGraphEdgeResolver::SCHEMA, $edge->metadata['resolver'] ?? null);
        $this->assertSame('EXTRACTED', $edge->metadata['confidence'] ?? null);
    }

    public function test_writes_nothing_when_flag_disabled(): void
    {
        config()->set('atlas.code_graph.real_edges', false);

        $model = $this->seedWorldModelWithTwoNodes();
        $this->seedModule('ai-dev', 'app/Services/Ai/Dev');
        $this->seedModule('ai-router', 'app/Services/Ai/Router');
        $this->seedFileSnapshotWithUseDependency('ai-dev', 'ai-router');

        $summary = (new CodeGraphEdgeBuilder)->build($model);

        $this->assertSame(CodeGraphEdgeBuilder::STATUS_DISABLED, $summary['status']);
        $this->assertSame(0, $summary['edges_written']);
        $this->assertSame(0, AiCodebaseWorldModelEdge::query()->where('world_model_id', $model->id)->count());
    }

    private function seedWorldModelWithTwoNodes(): AiCodebaseWorldModel
    {
        $modelId = 'aewm_codegraphbuilder_test';
        $model = AiCodebaseWorldModel::query()->create([
            'model_id' => $modelId,
            'scope' => 'atlas-server',
            'status' => 'built',
            'capabilities' => ['router', 'dev'],
            'risks' => ['autonomous_execution_requires_rag_gate'],
            'receipt' => ['schema_version' => 'atlas.ai.autonomous_engineering.codebase_world_model.v1'],
            'model_hash' => hash('sha256', $modelId),
        ]);

        foreach (['app/Services/Ai/Dev', 'app/Services/Ai/Router'] as $path) {
            AiCodebaseWorldModelNode::query()->create([
                'world_model_id' => $model->id,
                'node_id' => 'node:'.$path,
                'node_type' => 'module',
                'path' => $path,
                'capabilities' => [],
                'risks' => ['requires_evidence'],
                'metadata' => ['exists' => true, 'seeded_by' => 'CodeGraphEdgeBuilderTest'],
            ]);
        }

        return $model;
    }

    private function seedModule(string $slug, string $rootPath): void
    {
        AtlasEngineeringCodeModule::query()->create([
            'slug' => $slug,
            'name' => Str::headline($slug),
            'layer' => 'services',
            'root_path' => $rootPath,
            'status' => 'active',
            'source_hash' => hash('sha256', $slug),
        ]);
    }

    private function seedFileSnapshotWithUseDependency(string $fromSlug, string $toSlug): void
    {
        DB::table('atlas_engineering_code_file_snapshots')->insert([
            'id' => (string) Str::uuid(),
            'file_path' => 'app/Services/Ai/Dev/FooService.php',
            'module_slug' => $fromSlug,
            'language' => 'php',
            'source_hash' => hash('sha256', 'foo-service'),
            'file_size' => 128,
            'symbols_json' => json_encode([]),
            'relations_json' => json_encode([
                'dependencies' => [[
                    'kind' => 'php_use_ast',
                    'from_module' => $fromSlug,
                    'to_module' => $toSlug,
                    'symbol' => 'App\\Services\\Ai\\Router\\RouterService',
                    'file_path' => 'app/Services/Ai/Dev/FooService.php',
                    'line' => 7,
                ]],
                'symbol_references' => [],
                'test_targets' => [],
            ]),
            'status' => 'active',
            'indexed_at' => now(),
            'archived_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function bootSchema(): void
    {
        $this->dropSchema();
        (require database_path('migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php'))->up();
        (require database_path('migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table.php'))->up();
    }

    private function dropSchema(): void
    {
        foreach ([
            'atlas_engineering_code_file_snapshots',
            'atlas_engineering_doc_links',
            'atlas_engineering_code_symbols',
            'atlas_engineering_code_modules',
            'ai_codebase_world_model_edges',
            'ai_codebase_world_model_nodes',
            'ai_codebase_world_models',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
