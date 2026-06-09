<?php

declare(strict_types=1);

namespace Tests\Unit\Engineering\CodeGraph;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AiCodebaseWorldModelNode;
use App\Services\Engineering\CodeGraph\CodeGraphLanguageServer;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceModelResolver;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers the minimal LSP surface over the code graph: capabilities, go-to
 * definition, find-references, and JSON-RPC fail-safety (unknown method ->
 * error; missing symbol -> empty result).
 */
final class CodeGraphLanguageServerTest extends TestCase
{
    private string $workspace = 'atlas-server';

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

    public function test_initialize_returns_definition_and_references_capabilities(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [],
        ]);

        $this->assertSame('2.0', $response['jsonrpc']);
        $this->assertSame(1, $response['id']);
        $this->assertArrayNotHasKey('error', $response);
        $this->assertTrue($response['result']['capabilities']['definitionProvider']);
        $this->assertTrue($response['result']['capabilities']['referencesProvider']);
        $this->assertSame(CodeGraphLanguageServer::SERVER_NAME, $response['result']['serverInfo']['name']);
    }

    public function test_definition_returns_defining_node_location_for_seeded_symbol(): void
    {
        $this->seedGraph();

        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'textDocument/definition',
            'params' => ['symbol' => 'App\\Domain\\Beta'],
        ]);

        $this->assertSame(7, $response['id']);
        $this->assertArrayNotHasKey('error', $response);
        $locations = $response['result'];
        $this->assertCount(1, $locations);
        $this->assertSame('file:///src/Domain/Beta.php', $locations[0]['uri']);
        $this->assertSame('sym:App\\Domain\\Beta', $locations[0]['nodeId']);
        // Range came from metadata (1-based source lines -> 0-based LSP).
        $this->assertSame(11, $locations[0]['range']['start']['line']);
        $this->assertSame(19, $locations[0]['range']['end']['line']);
    }

    public function test_definition_resolves_a_bare_class_name_when_unique(): void
    {
        $this->seedGraph();

        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'textDocument/definition',
            'params' => ['symbol' => 'Beta'],
        ]);

        $this->assertSame('sym:App\\Domain\\Beta', $response['result'][0]['nodeId']);
    }

    public function test_references_returns_incoming_edges_who_reference_the_symbol(): void
    {
        $this->seedGraph();

        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'textDocument/references',
            'params' => ['symbol' => 'App\\Domain\\Beta'],
        ]);

        $this->assertSame(9, $response['id']);
        $locations = $response['result'];
        // Alpha and Gamma both depend on Beta; Beta does not reference itself.
        $this->assertCount(2, $locations);
        $uris = array_column($locations, 'uri');
        $this->assertContains('file:///src/Domain/Alpha.php', $uris);
        $this->assertContains('file:///src/Domain/Gamma.php', $uris);
        $this->assertNotContains('file:///src/Domain/Beta.php', $uris);
        $this->assertSame('depends_on', $locations[0]['edgeType']);
    }

    public function test_references_can_include_the_declaration(): void
    {
        $this->seedGraph();

        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 10,
            'method' => 'textDocument/references',
            'params' => ['symbol' => 'App\\Domain\\Beta', 'context' => ['includeDeclaration' => true]],
        ]);

        $uris = array_column($response['result'], 'uri');
        $this->assertContains('file:///src/Domain/Beta.php', $uris);
        $this->assertCount(3, $response['result']);
    }

    public function test_unknown_method_returns_json_rpc_method_not_found_error(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 'abc',
            'method' => 'textDocument/hover',
            'params' => [],
        ]);

        $this->assertSame('abc', $response['id']);
        $this->assertArrayNotHasKey('result', $response);
        $this->assertSame(-32601, $response['error']['code']);
        $this->assertStringContainsString('Method not found', $response['error']['message']);
    }

    public function test_missing_method_returns_invalid_request_error(): void
    {
        $response = $this->server()->handle(['jsonrpc' => '2.0', 'id' => 2]);

        $this->assertSame(-32600, $response['error']['code']);
    }

    public function test_unknown_symbol_returns_empty_result_not_error(): void
    {
        $this->seedGraph();

        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'textDocument/definition',
            'params' => ['symbol' => 'App\\Nope\\DoesNotExist'],
        ]);

        $this->assertArrayNotHasKey('error', $response);
        $this->assertSame([], $response['result']);
    }

    public function test_definition_with_no_graph_returns_empty_result(): void
    {
        // No model seeded — fail-safe empty, never an exception.
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'textDocument/definition',
            'params' => ['symbol' => 'App\\Domain\\Beta'],
        ]);

        $this->assertArrayNotHasKey('error', $response);
        $this->assertSame([], $response['result']);
    }

    private function server(): CodeGraphLanguageServer
    {
        return app(CodeGraphLanguageServer::class);
    }

    /**
     * Seed a symbol-level world model (scope "<workspace>-symbols", which the
     * resolver's default path reads) with Alpha/Gamma -> Beta dependency edges.
     */
    private function seedGraph(): void
    {
        $model = AiCodebaseWorldModel::query()->create([
            'model_id' => 'code-graph-symbols-test',
            'scope' => $this->workspace.'-symbols',
            'status' => 'built',
            'capabilities' => ['code_graph', 'symbol_level'],
            'risks' => [],
            'receipt' => ['source' => 'test'],
            'model_hash' => hash('sha256', 'lsp-test-model'),
        ]);

        // Beta carries its location in metadata.range (the richer-ingestion form).
        $this->node($model, 'sym:App\\Domain\\Beta', metadata: [
            'path' => 'src/Domain/Beta.php',
            'range' => ['start_line' => 12, 'end_line' => 20],
        ]);
        // Alpha/Gamma carry their path in the path COLUMN (the module-style form).
        $this->node($model, 'sym:App\\Domain\\Alpha', path: 'src/Domain/Alpha.php');
        $this->node($model, 'sym:App\\Domain\\Gamma', path: 'src/Domain/Gamma.php');

        $this->edge($model, 'sym:App\\Domain\\Alpha', 'sym:App\\Domain\\Beta', 'depends_on');
        $this->edge($model, 'sym:App\\Domain\\Gamma', 'sym:App\\Domain\\Beta', 'depends_on');
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function node(AiCodebaseWorldModel $model, string $nodeId, ?string $path = null, array $metadata = []): void
    {
        AiCodebaseWorldModelNode::query()->create([
            'world_model_id' => $model->id,
            'node_id' => $nodeId,
            'node_type' => 'symbol',
            'path' => $path,
            'metadata' => $metadata,
        ]);
    }

    private function edge(AiCodebaseWorldModel $model, string $from, string $to, string $type): void
    {
        AiCodebaseWorldModelEdge::query()->create([
            'world_model_id' => $model->id,
            'from_node_id' => $from,
            'to_node_id' => $to,
            'edge_type' => $type,
            'metadata' => [],
        ]);
    }

    private function bootSchema(): void
    {
        $this->dropSchema();
        (require database_path('migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php'))->up();

        config(['atlas.code_graph.default_workspace_id' => $this->workspace]);
    }

    private function dropSchema(): void
    {
        foreach ([
            'ai_codebase_world_model_edges',
            'ai_codebase_world_model_nodes',
            'ai_codebase_world_models',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
