<?php

namespace Tests\Feature\Ai\CodeGraph;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AiCodebaseWorldModelNode;
use App\Services\Ai\AtlasOpenBrainMcpService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature coverage for the AP-811 code-graph MCP traversal tools
 * (atlas_code_neighbors / atlas_code_path / atlas_code_explain). Seeds a tiny
 * world model (2 nodes + 1 edge) and asserts each tool returns sanitized,
 * read-only results and is advertised in tools/list.
 *
 * NOTE on RefreshDatabase: the suite runs on in-memory SQLite and several
 * migrations are raw Postgres SQL (CREATE EXTENSION / plpgsql / TIMESTAMPTZ),
 * so the global RefreshDatabase trait fails at migrate time on this connection
 * (documented suite-wide infra limitation). We therefore create only the three
 * world-model tables we exercise via the Schema builder in setUp/tearDown —
 * the same targeted-table convention the sibling AtlasOpenBrainMcpServiceTest
 * uses. The tools under test stay fully DB-backed and read-only.
 */
class CodeGraphMcpToolsTest extends TestCase
{
    private const FROM_NODE = 'node:app/Services/Ai/Router';

    private const TO_NODE = 'node:app/Services/Ai/Compounding';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createWorldModelTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_codebase_world_model_edges');
        Schema::dropIfExists('ai_codebase_world_model_nodes');
        Schema::dropIfExists('ai_codebase_world_models');
        parent::tearDown();
    }

    private function createWorldModelTables(): void
    {
        if (! Schema::hasTable('ai_codebase_world_models')) {
            Schema::create('ai_codebase_world_models', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('goal_record_id')->nullable()->index();
                $table->string('schema_version', 120)->default('atlas.ai.autonomous_engineering.codebase_world_model.v1');
                $table->string('model_id', 120)->unique();
                $table->string('scope', 160)->default('atlas-server')->index();
                $table->string('status', 40)->default('built')->index();
                $table->json('capabilities')->nullable();
                $table->json('risks')->nullable();
                $table->json('receipt')->nullable();
                $table->string('model_hash', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_codebase_world_model_nodes')) {
            Schema::create('ai_codebase_world_model_nodes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('world_model_id')->index();
                $table->string('node_id', 160)->index();
                $table->string('node_type', 80)->index();
                $table->string('path', 500)->nullable()->index();
                $table->string('flow_id', 80)->nullable()->index();
                $table->json('capabilities')->nullable();
                $table->json('risks')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['world_model_id', 'node_id'], 'idx_world_model_node_unique');
            });
        }

        if (! Schema::hasTable('ai_codebase_world_model_edges')) {
            Schema::create('ai_codebase_world_model_edges', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('world_model_id')->index();
                $table->string('from_node_id', 160)->index();
                $table->string('to_node_id', 160)->index();
                $table->string('edge_type', 80)->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
                // TEOS-I1 temporal-truth columns (all nullable), mirrored from
                // 2026_05_18_080200_add_temporal_truth_to_ai_codebase_world_model_edges.
                $table->timestamp('valid_from')->nullable();
                $table->timestamp('valid_until')->nullable();
                $table->timestamp('observed_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('stale_after')->nullable();
                $table->string('source_hash', 64)->nullable();
                $table->uuid('superseded_by')->nullable();
                $table->string('authority_level', 40)->nullable();
            });
        }
    }

    private function seedTinyWorldModel(): AiCodebaseWorldModel
    {
        $model = AiCodebaseWorldModel::query()->create([
            'model_id' => 'aewm_test_codegraph',
            'scope' => 'atlas-server',
            'status' => 'built',
            'capabilities' => ['router', 'compounding'],
            'risks' => ['requires_evidence'],
            'receipt' => ['schema_version' => 'test'],
            'model_hash' => sha1('aewm_test_codegraph'),
        ]);

        AiCodebaseWorldModelNode::query()->create([
            'world_model_id' => $model->id,
            'node_id' => self::FROM_NODE,
            'node_type' => 'module',
            // Embed a fake secret so we can prove provider-safe redaction.
            'path' => 'app/Services/Ai/Router sk-test1234567890ABCDEFGHIJ',
            'flow_id' => 'programming.route',
            'capabilities' => ['router'],
            'risks' => ['requires_evidence'],
            'metadata' => ['exists' => true],
        ]);

        AiCodebaseWorldModelNode::query()->create([
            'world_model_id' => $model->id,
            'node_id' => self::TO_NODE,
            'node_type' => 'module',
            'path' => 'app/Services/Ai/Compounding',
            'flow_id' => 'programming.compound',
            'capabilities' => ['compounding'],
            'risks' => ['requires_evidence'],
            'metadata' => ['exists' => true],
        ]);

        AiCodebaseWorldModelEdge::query()->create([
            'world_model_id' => $model->id,
            'from_node_id' => self::FROM_NODE,
            'to_node_id' => self::TO_NODE,
            'edge_type' => 'depends_on',
            'metadata' => ['inferred' => false, 'confidence' => 'EXTRACTED'],
        ]);

        return $model;
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function callTool(string $name, array $arguments): array
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);

        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ]);

        return $response['result']['structuredContent'];
    }

    public function test_traversal_tools_are_advertised_in_tools_list(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);

        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $names = array_column($response['result']['tools'], 'name');

        $this->assertContains('atlas_code_neighbors', $names);
        $this->assertContains('atlas_code_path', $names);
        $this->assertContains('atlas_code_explain', $names);

        $byName = collect($response['result']['tools'])->keyBy('name');
        foreach (['atlas_code_neighbors', 'atlas_code_path', 'atlas_code_explain'] as $tool) {
            $this->assertTrue((bool) $byName[$tool]['annotations']['readOnlyHint'], "{$tool} must be read-only");
            $this->assertFalse((bool) $byName[$tool]['annotations']['destructiveHint'], "{$tool} must be non-destructive");
        }
    }

    public function test_code_neighbors_returns_sanitized_neighbor(): void
    {
        $this->seedTinyWorldModel();

        $structured = $this->callTool('atlas_code_neighbors', [
            'node_id' => self::FROM_NODE,
            'direction' => 'out',
        ]);

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_code_neighbors', $structured['tool']);
        $this->assertSame('out', $structured['direction']);
        $this->assertSame(1, $structured['count']);

        $neighbor = $structured['neighbors'][0];
        $this->assertSame('out', $neighbor['direction']);
        $this->assertSame('depends_on', $neighbor['edge']['edge_type']);
        $this->assertSame(self::TO_NODE, $neighbor['node']['node_id']);

        // The starting node's path carried a fake secret — it must be redacted.
        $this->assertStringNotContainsString('sk-test1234567890ABCDEFGHIJ', $structured['node']['path']);
        $this->assertStringContainsString('[redacted]', $structured['node']['path']);

        // No world-model rows were mutated (read-only).
        $this->assertDatabaseCount('ai_codebase_world_model_edges', 1);
        $this->assertDatabaseCount('ai_codebase_world_model_nodes', 2);
    }

    public function test_code_neighbors_resolves_start_node_by_query(): void
    {
        $this->seedTinyWorldModel();

        $structured = $this->callTool('atlas_code_neighbors', [
            'query' => 'compounding',
            'direction' => 'both',
        ]);

        $this->assertTrue($structured['ok']);
        $this->assertSame(self::TO_NODE, $structured['node']['node_id']);
        // Compounding has one incoming depends_on edge from Router.
        $this->assertSame(1, $structured['count']);
        $this->assertSame('in', $structured['neighbors'][0]['direction']);
        $this->assertSame(self::FROM_NODE, $structured['neighbors'][0]['node']['node_id']);
    }

    public function test_code_path_finds_shortest_path_between_nodes(): void
    {
        $this->seedTinyWorldModel();

        $structured = $this->callTool('atlas_code_path', [
            'from' => self::FROM_NODE,
            'to' => self::TO_NODE,
        ]);

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_code_path', $structured['tool']);
        $this->assertTrue($structured['found']);
        $this->assertSame(1, $structured['hops']);

        $pathIds = array_column($structured['path'], 'node_id');
        $this->assertSame([self::FROM_NODE, self::TO_NODE], $pathIds);

        // Sanitized path text along the way (the fake secret is gone).
        $this->assertStringNotContainsString('sk-test1234567890ABCDEFGHIJ', $structured['path'][0]['path']);
    }

    public function test_code_explain_returns_node_with_sanitized_edges(): void
    {
        $this->seedTinyWorldModel();

        $structured = $this->callTool('atlas_code_explain', [
            'node_id' => self::FROM_NODE,
        ]);

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_code_explain', $structured['tool']);
        $this->assertSame(self::FROM_NODE, $structured['node']['node_id']);
        $this->assertSame(1, $structured['outgoing_count']);
        $this->assertSame(0, $structured['incoming_count']);
        $this->assertSame(1, $structured['degree']);

        $outgoing = $structured['outgoing_edges'][0];
        $this->assertSame('depends_on', $outgoing['edge']['edge_type']);
        $this->assertSame(self::TO_NODE, $outgoing['node']['node_id']);

        // Redaction reaches the node payload.
        $this->assertStringNotContainsString('sk-test1234567890ABCDEFGHIJ', $structured['node']['path']);
    }

    public function test_tools_return_graceful_no_graph_when_nothing_built(): void
    {
        // Tables exist but no world model is seeded.
        foreach (['atlas_code_neighbors', 'atlas_code_explain'] as $tool) {
            $structured = $this->callTool($tool, ['node_id' => self::FROM_NODE]);
            $this->assertTrue($structured['ok'], "{$tool} must not throw with no graph");
            $this->assertFalse($structured['graph_available']);
            $this->assertSame('no_world_model_built', $structured['reason']);
        }

        $pathStructured = $this->callTool('atlas_code_path', [
            'from' => self::FROM_NODE,
            'to' => self::TO_NODE,
        ]);
        $this->assertTrue($pathStructured['ok']);
        $this->assertFalse($pathStructured['graph_available']);
    }
}
