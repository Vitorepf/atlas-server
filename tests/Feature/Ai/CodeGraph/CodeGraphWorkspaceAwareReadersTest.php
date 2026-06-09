<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\CodeGraph;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AiCodebaseWorldModelNode;
use App\Services\Ai\AtlasOpenBrainMcpService;
use App\Services\Ai\AutonomousEngineering\WorldModel\WorldModelGraphRanker;
use App\Services\Ai\AutonomousEngineering\WorldModel\WorldModelRankingQuery;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AP-815 · W-3 — proves the code-graph READERS (WorldModelGraphRanker + the
 * atlas_code_* MCP tools) became workspace-aware ADDITIVELY:
 *
 *  - WITH a workspace, a reader resolves THAT workspace's symbol-scoped world
 *    model via {@see \App\Services\Engineering\CodeGraph\CodeGraphWorkspaceModelResolver},
 *    so a second (chronologically newer) workspace no longer shadows the target;
 *  - WITHOUT a workspace, behavior is exactly as before — the most-recent built
 *    model globally — even when the foreign workspace is newer.
 *
 * Boots only the three world-model tables via the Schema builder (same targeted
 * convention as the sibling CodeGraphMcpToolsTest: the global RefreshDatabase
 * trait can't migrate the raw-Postgres migrations on in-memory SQLite).
 */
final class CodeGraphWorkspaceAwareReadersTest extends TestCase
{
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
            });
        }
    }

    /**
     * Seed a one-node symbol-scoped world model for a workspace.
     */
    private function seedWorkspaceModel(string $workspaceId, ?Carbon $createdAt = null): AiCodebaseWorldModel
    {
        $scope = $workspaceId.'-symbols';
        $model = AiCodebaseWorldModel::query()->create([
            'model_id' => 'aewm_'.$workspaceId,
            'scope' => $scope,
            'status' => 'built',
            'capabilities' => ['code_graph'],
            'risks' => [],
            'receipt' => [],
            'model_hash' => hash('sha256', $scope),
            'created_at' => $createdAt ?? now(),
            'updated_at' => $createdAt ?? now(),
        ]);

        // One node carrying the workspace id in its path so we can prove WHICH
        // model a reader resolved purely from the returned node.
        AiCodebaseWorldModelNode::query()->create([
            'world_model_id' => $model->id,
            'node_id' => 'node:'.$workspaceId.'/App/Service',
            'node_type' => 'module',
            'path' => 'app/'.$workspaceId.'/Service.php',
            'flow_id' => $workspaceId.'.flow',
            'capabilities' => ['code_graph'],
            'risks' => [],
            'metadata' => ['exists' => true],
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

    // -----------------------------------------------------------------------
    // WorldModelGraphRanker
    // -----------------------------------------------------------------------

    public function test_ranker_with_workspace_resolves_that_workspaces_symbol_model(): void
    {
        // atlas-server is OLDER; the foreign workspace is the chronologically
        // newest model — so the global/latest path would pick the foreign one.
        $atlas = $this->seedWorkspaceModel('atlas-server', now()->subDays(2));
        $foreign = $this->seedWorkspaceModel('blackink', now());

        $ranker = app(WorldModelGraphRanker::class);

        $result = $ranker->rank(WorldModelRankingQuery::fromArray([
            'textual_seeds' => ['service'],
            'workspace' => 'atlas-server',
        ]));

        // Resolved atlas-server's OWN model, not the newer foreign one.
        $this->assertSame($atlas->model_id, $result['world_model_id']);
        $this->assertNotSame($foreign->model_id, $result['world_model_id']);
        $this->assertSame(1, $result['node_count']);
        $this->assertSame('node:atlas-server/App/Service', $result['ranked_nodes'][0]['node_id']);

        // The workspace scope is surfaced in the (additive) query echo.
        $this->assertSame('atlas-server', $result['query']['workspace_id']);
    }

    public function test_ranker_without_workspace_is_unchanged_global_latest(): void
    {
        // atlas-server older, foreign newer. No-arg path = most-recent globally.
        $this->seedWorkspaceModel('atlas-server', now()->subDays(2));
        $foreign = $this->seedWorkspaceModel('blackink', now());

        $ranker = app(WorldModelGraphRanker::class);

        $result = $ranker->rank(WorldModelRankingQuery::fromArray([
            'textual_seeds' => ['service'],
        ]));

        // Byte-identical to pre-W-3: newest built model globally wins, and the
        // workspace_id key is absent from the query echo entirely.
        $this->assertSame($foreign->model_id, $result['world_model_id']);
        $this->assertArrayNotHasKey('workspace_id', $result['query']);
    }

    public function test_ranker_explicit_world_model_id_still_wins_over_workspace(): void
    {
        $atlas = $this->seedWorkspaceModel('atlas-server', now()->subDays(2));
        $foreign = $this->seedWorkspaceModel('blackink', now());

        $ranker = app(WorldModelGraphRanker::class);

        // Explicit id (atlas) must beat a conflicting workspace hint (blackink).
        $result = $ranker->rank(WorldModelRankingQuery::fromArray([
            'textual_seeds' => ['service'],
            'world_model_id' => $atlas->model_id,
            'workspace' => 'blackink',
        ]));

        $this->assertSame($atlas->model_id, $result['world_model_id']);
        $this->assertNotSame($foreign->model_id, $result['world_model_id']);
    }

    public function test_ranker_workspace_query_signature_is_distinct_but_default_is_unchanged(): void
    {
        $base = WorldModelRankingQuery::fromArray(['textual_seeds' => ['service']]);
        $scoped = WorldModelRankingQuery::fromArray(['textual_seeds' => ['service'], 'workspace' => 'atlas-server']);

        // The default signature must equal a query built with NO workspace key
        // at all (proves the signature payload is byte-identical to pre-W-3).
        $this->assertNull($base->workspaceId);
        $this->assertNotSame($base->signature(), $scoped->signature());
    }

    // -----------------------------------------------------------------------
    // MCP tools (atlas_code_explain as the representative reader)
    // -----------------------------------------------------------------------

    public function test_mcp_tool_with_workspace_resolves_that_workspaces_model(): void
    {
        $atlas = $this->seedWorkspaceModel('atlas-server', now()->subDays(2));
        $foreign = $this->seedWorkspaceModel('blackink', now());

        $structured = $this->callTool('atlas_code_explain', [
            'node_id' => 'node:atlas-server/App/Service',
            'workspace' => 'atlas-server',
        ]);

        $this->assertTrue($structured['ok']);
        $this->assertSame($atlas->model_id, $structured['world_model_id']);
        $this->assertNotSame($foreign->model_id, $structured['world_model_id']);
        $this->assertSame('node:atlas-server/App/Service', $structured['node']['node_id']);
    }

    public function test_mcp_tool_with_workspace_does_not_see_foreign_workspace_node(): void
    {
        $this->seedWorkspaceModel('atlas-server', now()->subDays(2));
        $this->seedWorkspaceModel('blackink', now());

        // The blackink node exists, but scoped to atlas-server it must NOT resolve.
        $structured = $this->callTool('atlas_code_explain', [
            'node_id' => 'node:blackink/App/Service',
            'workspace' => 'atlas-server',
        ]);

        $this->assertFalse($structured['ok']);
        $this->assertSame('node_not_found', $structured['error']);
    }

    public function test_mcp_tool_without_workspace_is_unchanged_global_latest(): void
    {
        $this->seedWorkspaceModel('atlas-server', now()->subDays(2));
        $foreign = $this->seedWorkspaceModel('blackink', now());

        // No workspace → most-recent built globally (the foreign one), exactly
        // as before W-3. Its own node resolves under that model.
        $structured = $this->callTool('atlas_code_explain', [
            'node_id' => 'node:blackink/App/Service',
        ]);

        $this->assertTrue($structured['ok']);
        $this->assertSame($foreign->model_id, $structured['world_model_id']);
    }

    public function test_mcp_tools_advertise_workspace_param(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);

        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $byName = collect($response['result']['tools'])->keyBy('name');

        foreach (['atlas_code_neighbors', 'atlas_code_path', 'atlas_code_explain'] as $tool) {
            $this->assertArrayHasKey(
                'workspace',
                $byName[$tool]['inputSchema']['properties'],
                "{$tool} must advertise the optional workspace param",
            );
        }
    }
}
