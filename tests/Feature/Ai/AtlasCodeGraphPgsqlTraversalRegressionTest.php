<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AiCodebaseWorldModelNode;
use App\Services\Ai\AtlasOpenBrainMcpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

/**
 * AP-815 B1 regression — the code-graph traversal MCP tools (atlas_code_neighbors /
 * atlas_code_explain) must NOT crash on pgsql with:
 *
 *   SQLSTATE[42883] could not identify an equality operator for type json
 *
 * Hazard: edgesTouching() reads a node's edges by UNIONing two `SELECT *` over
 * ai_codebase_world_model_edges. pgsql's `json` type has no `=` operator, so a
 * UNION's implicit DISTINCT (which compares whole rows, dragging in the `metadata`
 * json column via `select *`) fails. Closed two ways, both pinned here against the
 * REAL pgsql connection:
 *   1) edgesTouching() uses unionAll (no DISTINCT) + dedups the self-loop in PHP;
 *   2) the json payload columns are jsonb (which HAS `=`), so the class is gone.
 *
 * Runs ONLY against pgsql (the live default connection). On the default sqlite suite
 * it skips: sqlite stores json as TEXT, has `=`, and draws no json/jsonb distinction,
 * so it cannot reproduce the bug. Every seeded row is written inside a transaction
 * that is ALWAYS rolled back — the operator's real code graph is never touched.
 */
final class AtlasCodeGraphPgsqlTraversalRegressionTest extends TestCase
{
    private bool $inPgsqlTransaction = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->pgsqlReachable()) {
            $this->markTestSkipped('No reachable pgsql connection; the json-equality bug is pgsql-only.');
        }

        // Route the Eloquent models (no hard-coded $connection) at pgsql, then wrap
        // the whole test in a transaction we always roll back so nothing persists.
        config(['database.default' => 'pgsql']);
        DB::connection('pgsql')->beginTransaction();
        $this->inPgsqlTransaction = true;
    }

    protected function tearDown(): void
    {
        if ($this->inPgsqlTransaction) {
            DB::connection('pgsql')->rollBack();
            $this->inPgsqlTransaction = false;
        }

        parent::tearDown();
    }

    public function test_code_graph_traversal_tools_do_not_crash_on_json_metadata_over_pgsql(): void
    {
        [$modelId, $center] = $this->seedTinyGraph();

        $service = $this->app->make(AtlasOpenBrainMcpService::class);

        // atlas_code_explain → edgesTouching() (the UNION over edges).
        $explain = $this->callTool($service, 'atlas_code_explain', [
            'world_model_id' => $modelId,
            'node_id' => $center,
        ]);

        $this->assertFalse(
            $explain['isError'],
            'atlas_code_explain crashed on pgsql: '.($explain['structuredContent']['error'] ?? 'unknown error'),
        );
        $this->assertTrue($explain['structuredContent']['ok']);
        $this->assertSame(1, $explain['structuredContent']['outgoing_count'], 'one outgoing edge expected');
        $this->assertSame(1, $explain['structuredContent']['incoming_count'], 'one incoming edge expected');
        $this->assertSame(2, $explain['structuredContent']['degree'], 'degree must count both UNION sides');

        // atlas_code_neighbors → also edgesTouching().
        $neighbors = $this->callTool($service, 'atlas_code_neighbors', [
            'world_model_id' => $modelId,
            'node_id' => $center,
            'direction' => 'both',
        ]);

        $this->assertFalse(
            $neighbors['isError'],
            'atlas_code_neighbors crashed on pgsql: '.($neighbors['structuredContent']['error'] ?? 'unknown error'),
        );
        $this->assertTrue($neighbors['structuredContent']['ok']);
        $this->assertSame(2, $neighbors['structuredContent']['count'], 'both directions must resolve');
    }

    public function test_code_graph_json_payload_columns_are_jsonb_on_pgsql(): void
    {
        // The structural backstop: jsonb HAS an equality operator, so no whole-row
        // comparison over these tables can re-raise SQLSTATE 42883.
        $targets = [
            ['ai_codebase_world_model_edges', 'metadata'],
            ['ai_codebase_world_model_nodes', 'capabilities'],
            ['ai_codebase_world_model_nodes', 'risks'],
            ['ai_codebase_world_model_nodes', 'metadata'],
        ];

        foreach ($targets as [$table, $column]) {
            $row = DB::selectOne(
                'select data_type from information_schema.columns '
                .'where table_schema = current_schema() and table_name = ? and column_name = ?',
                [$table, $column],
            );

            $this->assertNotNull($row, "{$table}.{$column} not found");
            $this->assertSame(
                'jsonb',
                $row->data_type,
                "{$table}.{$column} must be jsonb — pgsql `json` has no `=` operator (SQLSTATE 42883 on "
                ."UNION/DISTINCT). Run the 2026_06_09_130000 json→jsonb migration.",
            );
        }
    }

    /**
     * Seed a 3-node graph — a center node with ONE outgoing and ONE incoming edge —
     * each edge carrying non-null json metadata so a `select *` drags a json value
     * into the UNION. Exercises both sides of edgesTouching().
     *
     * @return array{0:string,1:string} [model_id, center node_id]
     */
    private function seedTinyGraph(): array
    {
        $modelId = 'test-code-graph-'.Str::uuid()->toString();

        $model = AiCodebaseWorldModel::create([
            'schema_version' => 'atlas.ai.autonomous_engineering.codebase_world_model.v1',
            'model_id' => $modelId,
            'scope' => 'atlas-server-test',
            'status' => 'built',
            'model_hash' => hash('sha256', $modelId),
        ]);

        $center = 'sym:Test\\Center';
        $outNeighbor = 'sym:Test\\OutNeighbor';
        $inNeighbor = 'sym:Test\\InNeighbor';

        foreach ([$center, $outNeighbor, $inNeighbor] as $nodeId) {
            AiCodebaseWorldModelNode::create([
                'world_model_id' => $model->id,
                'node_id' => $nodeId,
                'node_type' => 'symbol',
                'metadata' => ['seeded' => true],
            ]);
        }

        // center -> outNeighbor (from = center) and inNeighbor -> center (to = center):
        // the two rows land on opposite sides of the from/to UNION.
        AiCodebaseWorldModelEdge::create([
            'world_model_id' => $model->id,
            'from_node_id' => $center,
            'to_node_id' => $outNeighbor,
            'edge_type' => 'depends_on',
            'metadata' => ['confidence' => 'EXTRACTED', 'inferred' => false],
        ]);
        AiCodebaseWorldModelEdge::create([
            'world_model_id' => $model->id,
            'from_node_id' => $inNeighbor,
            'to_node_id' => $center,
            'edge_type' => 'depends_on',
            'metadata' => ['confidence' => 'INFERRED', 'inferred' => true],
        ]);

        return [$modelId, $center];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed> the JSON-RPC `result` envelope (has isError + structuredContent)
     */
    private function callTool(AtlasOpenBrainMcpService $service, string $name, array $arguments): array
    {
        return $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ])['result'];
    }

    private function pgsqlReachable(): bool
    {
        if (! array_key_exists('pgsql', (array) config('database.connections'))) {
            return false;
        }

        try {
            DB::connection('pgsql')->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
