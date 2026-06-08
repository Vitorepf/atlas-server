<?php

namespace Tests\Feature\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphRealityIngestionService;
use App\Services\Engineering\CodeGraph\CodeGraphUnifiedView;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AP-811/AP-812 M-9 — the reality half of the unified view.
 *
 * NOTE: the suite default connection is sqlite :memory: (phpunit.xml) and the
 * full migration set includes raw-Postgres DDL that sqlite cannot parse, so
 * RefreshDatabase fatals here. Following the proven sibling convention
 * ({@see CodeGraphEdgeBuilderTest}), we boot ONLY minimal Blueprint tables with
 * the exact columns the gatherer reads — and, crucially, we can leave a table
 * OUT to prove the read-defensive degrade path for real.
 *
 * The ledger table is booted with its REAL primary key `event_id` (not `id`),
 * so the test pins the column-name reality the service handles defensively.
 */
class CodeGraphRealityIngestionServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->dropSchema();

        parent::tearDown();
    }

    public function test_gathers_reality_nodes_and_doc_to_code_edges_in_unified_shape(): void
    {
        $this->bootDocsTable();
        $this->bootMemoryTable();
        $this->bootLedgerTable();

        // A doc whose canonical path points at a code module -> doc->code edge.
        DB::table('atlas_engineering_knowledge_items')->insert([
            'id' => 'doc-1',
            'title' => 'Code Graph Reality Ingestion',
            'category' => 'engineering',
            'canonical_path' => 'app/Services/Engineering/CodeGraph/CodeGraphRealityIngestionService.php',
            'related_paths_json' => json_encode(['app/Services/Engineering/CodeGraph/CodeGraphUnifiedView.php']),
        ]);
        // A doc that points only at a non-code path -> node, but NO edge.
        DB::table('atlas_engineering_knowledge_items')->insert([
            'id' => 'doc-2',
            'title' => 'Governance overview',
            'category' => 'governance',
            'canonical_path' => 'docs/engineering-knowledge-base/overview.md',
            'related_paths_json' => json_encode([]),
        ]);

        DB::table('atlas_memory_entries')->insert([
            'id' => 'mem-1',
            'title' => 'Reality ingestion decision',
        ]);
        DB::table('atlas_ledger_events')->insert([
            'event_id' => 'evt-1',
            'event_type' => 'code_graph.ingested',
        ]);

        $result = (new CodeGraphRealityIngestionService)->gather();

        $this->assertSame(CodeGraphRealityIngestionService::SCHEMA, $result['schema_version']);

        // --- nodes: right shape + right types -------------------------------
        $byId = [];
        foreach ($result['nodes'] as $node) {
            $this->assertArrayHasKey('node_id', $node);
            $this->assertArrayHasKey('node_type', $node);
            $this->assertArrayHasKey('label', $node);
            $byId[$node['node_id']] = $node;
        }

        $this->assertArrayHasKey('doc:doc-1', $byId);
        $this->assertSame(CodeGraphRealityIngestionService::NODE_DOCUMENT, $byId['doc:doc-1']['node_type']);
        $this->assertSame('Code Graph Reality Ingestion', $byId['doc:doc-1']['label']);

        $this->assertArrayHasKey('memory:mem-1', $byId);
        $this->assertSame(CodeGraphRealityIngestionService::NODE_MEMORY, $byId['memory:mem-1']['node_type']);

        $this->assertArrayHasKey('evidence:evt-1', $byId);
        $this->assertSame(CodeGraphRealityIngestionService::NODE_EVIDENCE, $byId['evidence:evt-1']['node_type']);

        $this->assertSame(2, $result['stats']['docs']);
        $this->assertSame(1, $result['stats']['memory']);
        $this->assertSame(1, $result['stats']['evidence']);

        // --- edges: doc->code 'documents', INFERRED, unified-view shape ------
        $edgeKeys = [];
        foreach ($result['edges'] as $edge) {
            foreach (['from_node_id', 'to_node_id', 'edge_type', 'confidence', 'metadata'] as $k) {
                $this->assertArrayHasKey($k, $edge, "edge missing $k");
            }
            $this->assertSame(CodeGraphRealityIngestionService::EDGE_DOCUMENTS, $edge['edge_type']);
            $this->assertSame(CodeGraphRealityIngestionService::CONFIDENCE_INFERRED, $edge['confidence']);
            $this->assertSame('doc:doc-1', $edge['from_node_id'], 'only the code-path doc should yield an edge');
            $edgeKeys[$edge['to_node_id']] = true;
        }

        // Both code paths (canonical + related) became node:<path> edges; the
        // non-code doc (doc-2 / docs/...) produced no edge.
        $this->assertArrayHasKey('node:app/Services/Engineering/CodeGraph/CodeGraphRealityIngestionService.php', $edgeKeys);
        $this->assertArrayHasKey('node:app/Services/Engineering/CodeGraph/CodeGraphUnifiedView.php', $edgeKeys);
        $this->assertCount(2, $result['edges']);
        $this->assertSame(2, $result['stats']['edges']);

        // --- the payoff: the reality edges merge into the unified view ------
        // A code edge touching the same node the doc documents, plus the reality
        // edges, unify into one layered set (the M-9 leap this ingestion unblocks).
        $codeEdges = [[
            'from_node_id' => 'node:app/Services/Engineering/CodeGraph/CodeGraphUnifiedView.php',
            'to_node_id' => 'node:app/Services/Engineering/CodeGraph/CodeGraphRealityIngestionService.php',
            'edge_type' => 'depends_on',
            'confidence' => 'EXTRACTED',
            'metadata' => [],
        ]];

        $unified = (new CodeGraphUnifiedView)->unify($codeEdges, $result['edges']);

        $this->assertSame(1, $unified['stats']['code']);
        $this->assertSame(2, $unified['stats']['reality']);
        $this->assertSame(3, $unified['stats']['total']);
        $layers = array_map(static fn (array $e): string => $e['metadata']['layer'], $unified['edges']);
        $this->assertContains(CodeGraphUnifiedView::LAYER_CODE, $layers);
        $this->assertContains(CodeGraphUnifiedView::LAYER_REALITY, $layers);
    }

    public function test_degrades_to_empty_when_a_source_table_is_absent(): void
    {
        // Only memory exists; docs + ledger tables are deliberately NOT booted.
        $this->dropSchema();
        $this->bootMemoryTable();

        DB::table('atlas_memory_entries')->insert([
            'id' => 'mem-only',
            'title' => 'lonely memory',
        ]);

        $result = (new CodeGraphRealityIngestionService)->gather();

        // The present source still yields its node...
        $this->assertSame(1, $result['stats']['memory']);
        $this->assertSame(['memory:mem-only'], array_map(
            static fn (array $n): string => $n['node_id'],
            $result['nodes'],
        ));

        // ...and the absent sources degrade to nothing, no exception thrown.
        $this->assertSame(0, $result['stats']['docs']);
        $this->assertSame(0, $result['stats']['evidence']);
        $this->assertSame([], $result['edges']);
        $this->assertGreaterThanOrEqual(2, $result['stats']['sources_skipped']);
    }

    public function test_gather_is_total_when_no_tables_exist_at_all(): void
    {
        $this->dropSchema();

        $result = (new CodeGraphRealityIngestionService)->gather();

        $this->assertSame(CodeGraphRealityIngestionService::SCHEMA, $result['schema_version']);
        $this->assertSame([], $result['nodes']);
        $this->assertSame([], $result['edges']);
        $this->assertSame(0, $result['stats']['docs']);
        $this->assertSame(0, $result['stats']['memory']);
        $this->assertSame(0, $result['stats']['evidence']);
        $this->assertSame(3, $result['stats']['sources_skipped']);
    }

    private function bootDocsTable(): void
    {
        Schema::create('atlas_engineering_knowledge_items', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('title')->nullable();
            $table->string('category')->nullable();
            $table->text('canonical_path')->nullable();
            $table->text('related_paths_json')->nullable();
        });
    }

    private function bootMemoryTable(): void
    {
        Schema::create('atlas_memory_entries', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('title')->nullable();
        });
    }

    private function bootLedgerTable(): void
    {
        // Real primary key is event_id (NOT id) — the service handles this.
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id')->primary();
            $table->string('event_type')->nullable();
        });
    }

    private function dropSchema(): void
    {
        foreach ([
            'atlas_engineering_knowledge_items',
            'atlas_memory_entries',
            'atlas_ledger_events',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
