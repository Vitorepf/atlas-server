<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AtlasOpenBrainContextExpansionService;
use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\AtlasOpenBrainMcpService;
use App\Services\Ai\Compounding\AtlasRagFeedbackService;
use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use App\Services\Engineering\CodeGraph\CodeGraphContextRetriever;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * AOBG N1.F1 — the unified context-pack front door.
 *
 * Locks the contract over a sqlite, COST-FREE fixture seeding all three brains
 * (no provider call anywhere — pure local DB reads). Tables are built with the
 * sqlite-safe concern + inline builders (the suite avoids RefreshDatabase
 * because some migrations are Postgres-only raw SQL):
 *  - code-graph symbols (atlas_engineering_code_symbols, W-1 workspace_id keyed);
 *  - AURG reality-graph nodes/edges (the fused brain, with a SENSITIVE domain);
 *  - provider-safe + secret memory entries (the redacted-projection recall).
 *
 * Asserts: fuses all three; provider_bound excludes sensitive memory + domain;
 * budget respected; honest empty per source; workspace scoping; the MCP tool;
 * the CLI.
 *
 * AURG fixture topology (M1 is the lexical seed for "embedding decision"):
 *   M1(memory) -references-> C1(code) ; M1 -belongs_to-> Dfin(domain, SENSITIVE)
 */
final class AtlasOpenBrainContextPackServiceTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    /** @var array<int,string> */
    private array $tempDirs = [];

    private const M1 = 'memory:memory_entry:mem-1';

    private const C1 = 'code:module:atlas-server/services-ai-memory';

    private const DFIN = 'domain:domain:finance';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAtlasMemoryEntryTable();
        $this->createCodeSymbolsTable();
        $this->createAurgTables();

        config()->set('atlas.aurg.enabled', true);
        config()->set('atlas.aurg.query_rank_enabled', false); // deterministic, no runtime
    }

    protected function tearDown(): void
    {
        $this->dropCompoundingSchema();
        Schema::dropIfExists('atlas_aurg_edges');
        Schema::dropIfExists('atlas_aurg_nodes');
        Schema::dropIfExists('atlas_engineering_code_symbols');
        $this->dropAtlasMemoryEntryTable();
        foreach ($this->tempDirs as $dir) {
            (new Process(['rm', '-rf', $dir]))->run();
        }
        parent::tearDown();
    }

    public function test_pack_fuses_all_three_brain_sources(): void
    {
        $this->seedCodeSymbol('CodeGraphEmbeddingDecisionResolver', 'atlas-server');
        $this->seedAurg();
        $this->seedMemory('mem-1', 'Embedding decision for memoria vector search', true, 'normal');

        $pack = $this->service()->packFor('embedding decision');

        $this->assertSame(AtlasOpenBrainContextPackService::SCHEMA, $pack['schema']);
        $this->assertTrue($pack['provider_bound']);
        $this->assertSame('curated top-K (not exhaustive)', $pack['honesty']);
        $this->assertSame(
            AtlasOpenBrainContextPackService::RUNTIME_SCHEMA,
            data_get($pack, 'provenance.aobg_runtime.schema_version'),
        );
        $this->assertContains(
            'initial_reality_cross_layer_only',
            data_get($pack, 'provenance.aobg_runtime.feature_flags'),
        );
        $this->assertContains(
            'context_hygiene_summary',
            data_get($pack, 'provenance.aobg_runtime.feature_flags'),
        );
        $this->assertContains(
            'initial_code_file_symbol_deferral',
            data_get($pack, 'provenance.aobg_runtime.feature_flags'),
        );
        $this->assertContains(
            'reality_doc_mission_filter',
            data_get($pack, 'provenance.aobg_runtime.feature_flags'),
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            data_get($pack, 'provenance.aobg_runtime.runtime_fingerprint'),
        );
        $this->assertSame(
            'context_pack_runtime_missing_or_mcp_process_stale',
            data_get($pack, 'provenance.aobg_runtime.stale_detection.if_missing'),
        );

        // All three sections present + recorded in provenance.
        $this->assertNotEmpty($pack['code_graph'], 'code-graph section should be populated');
        $this->assertNotEmpty($pack['reality_graph_paths'], 'reality-graph section should be populated');
        $this->assertNotEmpty($pack['memory'], 'memory section should be populated');

        $sources = $pack['provenance']['sources_present'];
        $this->assertContains('code_graph', $sources);
        $this->assertContains('reality_graph', $sources);
        $this->assertContains('memory', $sources);

        // A code symbol that matches the task is in the pack.
        $symbolIds = array_column($pack['code_graph'], 'id');
        $this->assertContains('sym:CodeGraphEmbeddingDecisionResolver', $symbolIds);

        // A cross-layer path from the brain is present (M1 -> C1).
        $targets = array_column($pack['reality_graph_paths'], 'target');
        $this->assertContains(self::C1, $targets);

        // The recalled memory title is provider-safe and present.
        $titles = array_column($pack['memory'], 'title');
        $this->assertNotEmpty(array_filter($titles, fn (string $t): bool => str_contains($t, 'Embedding')));

        // Rendered markdown carries all three section headers.
        $this->assertStringContainsString('# Atlas Open Brain Context Pack (AOBG)', $pack['markdown']);
        $this->assertStringContainsString('## Code graph', $pack['markdown']);
        $this->assertStringContainsString('## Reality graph', $pack['markdown']);
        $this->assertStringContainsString('## Memory', $pack['markdown']);
    }

    public function test_provider_bound_excludes_sensitive_memory_and_sensitive_domain(): void
    {
        $this->seedAurg();
        $this->seedMemory('mem-safe', 'Embedding decision safe note', true, 'normal');
        // A SECRET memory whose title also matches the task — must NOT ride out.
        $this->seedMemory('mem-secret', 'Embedding decision secret vault key', false, 'secret');

        $pack = $this->service()->packFor('embedding decision');

        // Sensitive AURG domain excluded from any provider-bound path chain.
        $pathNodeIds = [];
        foreach ($pack['reality_graph_paths'] as $path) {
            foreach ($path['chain'] as $node) {
                $pathNodeIds[] = $node['id'];
            }
        }
        $this->assertNotContains(self::DFIN, $pathNodeIds, 'sensitive domain must be excluded from provider-bound paths');

        // The secret memory body/key never appears in the pack; the safe one does.
        $memoryBlob = (string) json_encode($pack['memory']).$pack['markdown'];
        $this->assertStringNotContainsString('secret vault key', $memoryBlob);
        $this->assertStringNotContainsString('secret vault key material', $memoryBlob);
        $this->assertStringContainsString('safe note', $memoryBlob);
    }

    public function test_pack_includes_provider_safe_post_execution_feedback_request(): void
    {
        $this->seedCodeSymbol('CodeGraphEmbeddingDecisionResolver', 'atlas-server');
        $this->seedAurg();
        $this->seedMemory('mem-1', 'Embedding decision feedback note', true, 'normal');

        $pack = $this->service()->packFor('embedding decision', [
            'domain' => 'developer',
            'task_type' => 'debug',
        ]);

        $request = $pack['context_feedback_request'];

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $pack['context_pack_hash']);
        $this->assertSame(AtlasOpenBrainContextPackService::CONTEXT_FEEDBACK_REQUEST_SCHEMA, $request['schema_version']);
        $this->assertSame('atlas_context_feedback', $request['tool']);
        $this->assertSame('after_execution', $request['timing']);
        $this->assertSame($pack['context_pack_hash'], $request['context_pack_hash']);
        $this->assertSame($pack['context_pack_hash'], $request['retrieval_receipt_id']);
        $this->assertSame('developer.debug', $request['flow_id']);
        $this->assertSame('developer', $request['domain']);
        $this->assertSame('debug', $request['task_type']);
        $this->assertNotEmpty($request['delivered_context_refs']);
        $this->assertSame($request['delivered_context_refs'], data_get($request, 'arguments_template.delivered_context_refs'));
        $this->assertSame($pack['context_pack_hash'], data_get($request, 'arguments_template.context_pack_hash'));
        $this->assertTrue(data_get($request, 'arguments_template.record'));
        $this->assertFalse(data_get($request, 'policy.raw_text_exposed'));
        $this->assertFalse(data_get($request, 'policy.raw_logs_allowed'));
        $this->assertContains('used_context_refs', $request['required_after_execution']);
        $this->assertContains('post_execution_utility', $request['required_after_execution']);
        $this->assertStringContainsString('## Context feedback request', $pack['markdown']);
        $this->assertStringContainsString('context_pack_hash='.substr($pack['context_pack_hash'], 0, 16), $pack['markdown']);
        $this->assertStringContainsString('no raw logs or source text', $pack['markdown']);
    }

    public function test_feedback_request_preserves_bare_flow_id_without_relabeling_domain(): void
    {
        $pack = $this->service()->packFor('embedding decision', [
            'flow_id' => 'atlas_conversation',
        ]);

        $request = $pack['context_feedback_request'];

        $this->assertSame('atlas_conversation', $request['flow_id']);
        $this->assertSame('atlas', $request['domain']);
        $this->assertSame('dev', $request['task_type']);
        $this->assertSame('atlas_conversation', data_get($request, 'arguments_template.flow_id'));
        $this->assertSame('atlas', data_get($request, 'arguments_template.domain'));
    }

    public function test_budget_is_respected(): void
    {
        $this->seedMemory('mem-1', 'Embedding decision one with a fairly long body text here', true, 'normal');
        $this->seedMemory('mem-2', 'Embedding decision two also with a long body of text content', true, 'normal');
        $this->seedMemory('mem-3', 'Embedding decision three more body text to overflow the budget here', true, 'normal');

        // A tiny memory sub-budget admits the top hit but not all three.
        $pack = $this->service()->packFor('embedding decision', ['memory_budget' => 40]);

        $this->assertSame(40, $pack['budget']['memory_budget_chars']);
        // At least one item kept (never starves), but bounded below the full set.
        $this->assertGreaterThanOrEqual(1, count($pack['memory']));
        $this->assertLessThan(3, count($pack['memory']));

        // The TOTAL budget is a real ceiling: a tight total scales the text
        // sub-budgets down proportionally (not just reported as metadata).
        $tight = $this->service()->packFor('embedding decision', [
            'budget' => 1000, 'code_budget' => 2500, 'memory_budget' => 1500,
        ]);
        $this->assertSame(1000, $tight['budget']['total_chars']);
        $this->assertLessThan(2500, $tight['budget']['code_budget_chars']);
        $this->assertLessThan(1500, $tight['budget']['memory_budget_chars']);
        $this->assertLessThanOrEqual(
            1000,
            $tight['budget']['code_budget_chars'] + $tight['budget']['memory_budget_chars'],
        );
    }

    public function test_total_budget_is_a_real_ceiling_on_the_measured_pack(): void
    {
        // The code retriever budgets on signature TOKENS, but the pack also carries each
        // symbol's id + file_path (NOT token-counted), so without a final measured trim a
        // generous-token / tight-total pack overflows. Seed many matching symbols + memory
        // so both sections want to be large, then assert the MEASURED estimated_chars
        // (not just the sub-budget metadata) never exceeds the requested total.
        for ($i = 0; $i < 12; $i++) {
            $this->seedCodeSymbol('CodeGraphEmbeddingDecisionResolverNumber'.$i, 'atlas-server');
        }
        for ($i = 0; $i < 4; $i++) {
            $this->seedMemory('mem-'.$i, 'Embedding decision note number '.$i.' with a longer body to add weight', true, 'normal');
        }

        // A tight total must bound the assembled output, not just the reported sub-budgets.
        $tight = $this->service()->packFor('embedding decision', ['budget' => 900]);
        $this->assertSame(900, $tight['budget']['total_chars']);
        $this->assertLessThanOrEqual(
            900,
            $tight['budget']['estimated_chars'],
            'measured estimated_chars must respect the total ceiling',
        );
        // The estimated_chars equals the actual section char footprint (no phantom budget).
        $codeChars = 0;
        foreach ($tight['code_graph'] as $item) {
            $codeChars += strlen(((string) $item['id']).((string) $item['file_path']).((string) $item['signature']));
        }
        $memoryChars = 0;
        foreach ($tight['memory'] as $item) {
            $memoryChars += strlen(((string) $item['title']).((string) $item['summary']).((string) $item['body']));
        }
        $this->assertSame($codeChars + $memoryChars, $tight['budget']['estimated_chars']);

        // Never starves: a present section keeps at least its top hit, and the counts
        // metadata matches the trimmed item lists (no over-claim).
        $this->assertGreaterThanOrEqual(1, count($tight['code_graph']));
        $this->assertGreaterThanOrEqual(1, count($tight['memory']));
        $this->assertSame(count($tight['code_graph']), $tight['counts']['code_graph']);
        $this->assertSame(count($tight['memory']), $tight['counts']['memory']);
        $this->assertGreaterThan(0, data_get($tight, 'context_hygiene.total_ceiling_trimmed'));
        $this->assertSame(
            data_get($tight, 'provenance.code_graph.total_ceiling_trimmed_count', 0)
            + data_get($tight, 'provenance.reality_graph.total_ceiling_trimmed_count', 0)
            + data_get($tight, 'provenance.memory.total_ceiling_trimmed_count', 0),
            data_get($tight, 'context_hygiene.total_ceiling_trimmed'),
        );
        $this->assertStringContainsString('total_ceiling_trimmed=', $tight['markdown']);

        // A generous total leaves more in (proving the ceiling — not some other cap —
        // is what trimmed the tight pack).
        $generous = $this->service()->packFor('embedding decision', ['budget' => 100000]);
        $this->assertGreaterThan(count($tight['code_graph']), count($generous['code_graph']));
    }

    public function test_code_graph_initial_pack_fills_past_oversized_top_candidate(): void
    {
        $this->seedCodeRow(
            'class',
            'EmbeddingDecisionGateOversizedRuntimeWithVeryLongName',
            'app/Services/Ai/Memory/EmbeddingDecisionGateOversizedRuntimeWithVeryLongName.php',
            'class EmbeddingDecisionGateOversizedRuntimeWithVeryLongName { '.str_repeat('public function oversizedGate(): void {} ', 4000).' }',
        );
        $this->seedCodeRow(
            'method',
            'App\\Services\\Ai\\Kernel\\Architecture\\AtlasFeaturePlacementService::duplicateReview',
            'app/Services/Ai/Kernel/Architecture/AtlasFeaturePlacementService.php',
            'private function duplicateReview(array $placement, array $owners, array $duplicates): array',
        );

        $pack = $this->service()->packFor('embedding_decision duplicate_review gate', [
            'budget' => 1000,
            'code_budget' => 1000,
            'memory_budget' => 0,
        ]);
        $ids = array_column($pack['code_graph'], 'id');

        $this->assertContains(
            'sym:App\\Services\\Ai\\Kernel\\Architecture\\AtlasFeaturePlacementService::duplicateReview',
            $ids,
        );
        $this->assertNotContains(
            'sym:EmbeddingDecisionGateOversizedRuntimeWithVeryLongName',
            $ids,
        );
        $this->assertTrue(data_get($pack, 'provenance.code_graph.truncated'));
        $this->assertTrue(data_get($pack, 'provenance.code_graph.assembly_fill_gaps'));
    }

    public function test_each_source_degrades_to_honest_empty_independently(): void
    {
        // No code symbols, no AURG nodes, no memory matching → all honest empty,
        // never fabricated, and the service does not throw.
        $pack = $this->service()->packFor('nonexistent zzqqx wwyyk task');

        $this->assertSame([], $pack['code_graph']);
        $this->assertSame([], $pack['reality_graph_paths']);
        $this->assertSame([], $pack['memory']);
        $this->assertSame([], $pack['provenance']['sources_present']);
        $this->assertTrue($pack['provider_bound']);

        // Empty sections are LABELLED honestly in markdown (not silently dropped).
        $this->assertStringContainsString('_no matching symbols', $pack['markdown']);
        $this->assertStringContainsString('_no provider-safe paths', $pack['markdown']);
        $this->assertStringContainsString('_no provider-safe memory', $pack['markdown']);

        // A blank task is also a clean, non-throwing empty pack.
        $blank = $this->service()->packFor('   ');
        $this->assertSame('', $blank['task']);
        $this->assertSame([], $blank['code_graph']);
        $this->assertSame([], $blank['memory']);
    }

    public function test_initial_pack_omits_same_layer_reality_graph_paths(): void
    {
        $mission = 'mission:mission:aobg-same-layer';
        $evidence = 'mission:evidence:aobg-same-layer';

        foreach ([
            [
                'id' => $mission,
                'kind' => 'mission',
                'source_kind' => 'mission',
                'source_id' => 'aobg-same-layer',
                'label' => 'AOBG same-layer provider context mission',
                'provider_safe' => true,
                'sensitive' => false,
                'meta' => ['provider' => 'codex'],
            ],
            [
                'id' => $evidence,
                'kind' => 'evidence',
                'source_kind' => 'mission',
                'source_id' => 'aobg-same-layer',
                'label' => 'mission_outcome',
                'provider_safe' => true,
                'sensitive' => false,
                'meta' => ['status' => 'passed'],
            ],
        ] as $node) {
            AtlasAurgNode::query()->create($node + ['content_hash' => hash('sha256', $node['id'])]);
        }
        AtlasAurgEdge::query()->create([
            'from_node_id' => $mission,
            'to_node_id' => $evidence,
            'kind' => 'generated',
            'source' => 'mission_outcome',
            'confidence' => 1.0,
            'meta' => [],
        ]);

        $pack = $this->service()->packFor('aobg provider context mission');

        $this->assertSame([], $pack['reality_graph_paths']);
        $this->assertNotContains('reality_graph', $pack['provenance']['sources_present']);
        $this->assertSame(1, data_get($pack, 'provenance.reality_graph.raw_paths'));
        $this->assertSame(1, data_get($pack, 'provenance.reality_graph.same_layer_paths_omitted'));
    }

    public function test_context_hygiene_counts_filtered_documentation_mission_paths(): void
    {
        $code = 'code:module:atlas-server/context_pack_runtime_stale';
        $mission = 'mission:mission:docs-canonical-cleanup-aaeos';
        $this->mock(AtlasRealityGraphQueryService::class, function ($mock) use ($code, $mission): void {
            $mock->shouldReceive('query')->once()->andReturn([
                'provider_bound' => true,
                'ranking' => 'fixture',
                'seeds' => [$code],
                'nodes' => [
                    ['id' => $code, 'label' => 'Context Pack Runtime Stale', 'source_kind' => 'code'],
                    ['id' => $mission, 'label' => 'Atualizar docs canonicas stale apos limpeza bruta AAEOS', 'source_kind' => 'mission'],
                ],
                'paths' => [[
                    'target' => $mission,
                    'seed' => $code,
                    'depth' => 1,
                    'cross_layer' => true,
                    'nodes' => [$code, $mission],
                    'hops' => [],
                ]],
                'counts' => ['cross_layer_paths' => 1],
            ]);
        });

        $pack = $this->service()->packFor('corrigir bug no context pack runtime stale');

        $this->assertSame([], $pack['reality_graph_paths']);
        $this->assertSame(1, data_get($pack, 'provenance.reality_graph.doc_mission_paths_omitted'));
        $this->assertSame(1, data_get($pack, 'context_hygiene.doc_mission_filtered'));
        $this->assertSame(1, data_get($pack, 'context_hygiene.total_filtered'));
        $this->assertStringContainsString('doc_mission_filtered=1', $pack['markdown']);
    }

    public function test_workspace_scoping_never_leaks_another_workspace(): void
    {
        // The primary workspace resolves to the default id (base_path → 'atlas-server');
        // a SECOND project path resolves to its own distinct id. Seed each project's
        // symbol under the id the resolver will compute, then prove no cross-leak.
        $identity = $this->app->make(CodeGraphWorkspaceIdentity::class);
        $primaryId = $identity->default();
        $otherPath = sys_get_temp_dir().'/aobg-ws-'.Str::random(6);
        @mkdir($otherPath, 0777, true);
        $otherId = $identity->resolve($otherPath);

        $this->assertNotSame($primaryId, $otherId, 'two workspaces must resolve to distinct ids');

        $this->seedCodeSymbol('CodeGraphEmbeddingDecisionResolver', $primaryId);
        $this->seedCodeSymbol('CodeGraphEmbeddingDecisionResolver', $otherId);

        // Default (no workspace opt) → primary; explicit cwd → the other project.
        $packA = $this->service()->packFor('embedding decision');
        $packB = $this->service()->packFor('embedding decision', ['cwd' => $otherPath]);

        @rmdir($otherPath);

        $this->assertSame($primaryId, $packA['workspace']);
        $this->assertSame($otherId, $packB['workspace']);

        // Each workspace returns exactly its own symbol row (no cross-leak).
        $this->assertCount(1, $packA['code_graph']);
        $this->assertCount(1, $packB['code_graph']);
        $this->assertSame($primaryId, $packA['provenance']['code_graph']['workspace_id']);
        $this->assertSame($otherId, $packB['provenance']['code_graph']['workspace_id']);
    }

    public function test_mcp_tool_returns_provider_bound_pack(): void
    {
        $this->seedAurg();
        $this->seedMemory('mem-1', 'Embedding decision memoria note', true, 'normal');

        $service = $this->app->make(AtlasOpenBrainMcpService::class);

        // Listed in the inventory.
        $list = $service->handleJsonRpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);
        $this->assertContains('atlas_context_pack', array_column($list['result']['tools'], 'name'));

        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_context_pack', 'arguments' => ['task' => 'embedding decision']],
        ]);
        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_context_pack', $structured['tool']);
        $this->assertTrue($structured['provider_bound']);
        $this->assertTrue($structured['pack']['provider_bound']);
        $this->assertNotEmpty($structured['pack']['reality_graph_paths']);
        $this->assertSame(
            AtlasOpenBrainContextPackService::RUNTIME_SCHEMA,
            data_get($structured, 'pack.provenance.aobg_runtime.schema_version'),
        );
        $this->assertContains(
            'same_layer_path_omission_provenance',
            data_get($structured, 'pack.provenance.aobg_runtime.feature_flags'),
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            data_get($structured, 'pack.provenance.aobg_runtime.runtime_fingerprint'),
        );

        // The sensitive domain never rides the MCP (provider-bound) output.
        $pathNodeIds = [];
        foreach ($structured['pack']['reality_graph_paths'] as $path) {
            foreach ($path['chain'] as $node) {
                $pathNodeIds[] = $node['id'];
            }
        }
        $this->assertNotContains(self::DFIN, $pathNodeIds);

        // Input validation.
        $missing = $service->handleJsonRpc([
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_context_pack', 'arguments' => []],
        ]);
        $this->assertFalse($missing['result']['structuredContent']['ok']);
        $this->assertSame('task_required', $missing['result']['structuredContent']['error']);
    }

    public function test_code_graph_retrieval_prefers_aobg_owner_code_over_docs_and_tests(): void
    {
        $this->seedCodeRow(
            'doc_heading',
            'Open Brain MCP fluxo',
            'docs/engineering-knowledge-base/memory/open-brain-mcp.md',
            '## Open Brain MCP fluxo',
        );
        $this->seedCodeRow(
            'test_method',
            'Tests\\Feature\\AtlasMemoryRegistryTest::test_open_brain_mcp_lists_tools_recalls_memory_and_audits_context_pack',
            'tests/Feature/AtlasMemoryRegistryTest.php',
            'public function test_open_brain_mcp_lists_tools_recalls_memory_and_audits_context_pack(): void',
        );
        $this->seedCodeRow(
            'cli_command',
            'atlas:open-brain:mcp',
            'app/Console/Commands/AtlasOpenBrainMcpCommand.php',
            'atlas:open-brain:mcp {--workspace= : Default workspace for MCP tool calls}',
        );
        $this->seedCodeRow(
            'cli_command',
            'atlas:aobg:capture-session',
            'app/Console/Commands/AtlasAobgCaptureSessionCommand.php',
            'atlas:aobg:capture-session {--workspace= : Workspace path or id}',
        );

        $pack = app(CodeGraphContextRetriever::class)->packFor(
            'AOBG Open Brain MCP context pack',
            'atlas-server',
            600,
        );

        $files = array_column($pack['included'], 'file_path');
        $this->assertContains('app/Console/Commands/AtlasOpenBrainMcpCommand.php', $files);
        $this->assertContains('app/Console/Commands/AtlasAobgCaptureSessionCommand.php', $files);
        $this->assertNotContains('docs/engineering-knowledge-base/memory/open-brain-mcp.md', $files);
        $this->assertNotContains('tests/Feature/AtlasMemoryRegistryTest.php', $files);
        $this->assertContains($files[0], [
            'app/Console/Commands/AtlasOpenBrainMcpCommand.php',
            'app/Console/Commands/AtlasAobgCaptureSessionCommand.php',
        ]);
    }

    public function test_initial_context_pack_defers_test_symbols_for_dev_task_even_when_query_mentions_tests(): void
    {
        $this->seedCodeRow(
            'test_method',
            'Tests\\Feature\\Ai\\AtlasOpenBrainContextPackServiceTest::test_aobg_context_pack_defers_tests',
            'tests/Feature/Ai/AtlasOpenBrainContextPackServiceTest.php',
            'public function test_aobg_context_pack_defers_tests(): void',
        );
        $this->seedCodeRow(
            'class',
            'Tests\\Feature\\Ai\\AtlasOpenBrainContextPackServiceTest',
            'tests/Feature/Ai/AtlasOpenBrainContextPackServiceTest.php',
            'final class AtlasOpenBrainContextPackServiceTest extends TestCase',
        );
        $this->seedCodeRow(
            'method',
            'App\\Services\\Ai\\AtlasOpenBrainContextPackService::packFor',
            'app/Services/Ai/AtlasOpenBrainContextPackService.php',
            'public function packFor(string $task, array $opts = []): array',
        );

        $pack = $this->service()->packFor(
            'Implementar AOBG context pack para deferir testes docs sob demanda',
            ['task_type' => 'dev', 'budget' => 4000],
        );

        $types = array_column($pack['code_graph'], 'symbol_type');
        $this->assertContains('method', $types);
        $this->assertNotContains('test_method', $types);
        foreach (array_column($pack['code_graph'], 'file_path') as $path) {
            $this->assertFalse(str_starts_with((string) $path, 'tests/'), (string) $path);
        }
        $this->assertSame(
            'auxiliary_symbols_deferred',
            data_get($pack, 'provenance.code_graph.initial_delivery_policy.mode'),
        );
        $this->assertSame(
            1,
            data_get($pack, 'provenance.code_graph.initial_delivery_policy.deferred_symbol_counts.test_method'),
        );
        $this->assertContains('defer_auxiliary_code_symbols', data_get($pack, 'context_delivery_policy.actions'));
        $this->assertContains('test_symbols', data_get($pack, 'context_delivery_policy.deferred_source_types'));
        $this->assertContains('expand:test_symbols', data_get($pack, 'context_delivery_policy.on_demand_handles'));
        $this->assertSame(
            1,
            data_get($pack, 'provenance.code_graph.initial_delivery_policy.deferred_symbol_counts.class'),
        );
        $this->assertStringContainsString('deferred_code_symbols: count=2 sources=test_symbols', $pack['markdown']);
    }

    public function test_initial_context_pack_keeps_test_symbols_for_explicit_test_task(): void
    {
        $this->seedCodeRow(
            'test_method',
            'Tests\\Feature\\Ai\\AtlasOpenBrainContextPackServiceTest::test_aobg_context_pack_explicit_tests',
            'tests/Feature/Ai/AtlasOpenBrainContextPackServiceTest.php',
            'public function test_aobg_context_pack_explicit_tests(): void',
        );
        $this->seedCodeRow(
            'method',
            'App\\Services\\Ai\\AtlasOpenBrainContextPackService::packFor',
            'app/Services/Ai/AtlasOpenBrainContextPackService.php',
            'public function packFor(string $task, array $opts = []): array',
        );

        $pack = $this->service()->packFor(
            'listar testes do AOBG context pack',
            ['task_type' => 'test', 'budget' => 4000],
        );

        $this->assertContains('test_method', array_column($pack['code_graph'], 'symbol_type'));
        $this->assertSame(
            'auxiliary_symbols_included_by_intent',
            data_get($pack, 'provenance.code_graph.initial_delivery_policy.mode'),
        );
        $this->assertSame(0, data_get($pack, 'provenance.code_graph.initial_delivery_policy.deferred_count'));
    }

    public function test_initial_context_pack_defers_file_symbols_when_class_symbol_exists(): void
    {
        $this->seedCodeRow(
            'file',
            'app/Services/Ai/Context/AobgSemanticRetrievalLiftService.php',
            'app/Services/Ai/Context/AobgSemanticRetrievalLiftService.php',
            'app/Services/Ai/Context/AobgSemanticRetrievalLiftService.php',
        );
        $this->seedCodeRow(
            'class',
            'App\\Services\\Ai\\Context\\AobgSemanticRetrievalLiftService',
            'app/Services/Ai/Context/AobgSemanticRetrievalLiftService.php',
            'final class AobgSemanticRetrievalLiftService',
        );

        $pack = $this->service()->packFor('AOBG semantic retrieval lift', [
            'budget' => 4000,
            'code_budget' => 2000,
        ]);

        $this->assertContains('class', array_column($pack['code_graph'], 'symbol_type'));
        $this->assertNotContains('file', array_column($pack['code_graph'], 'symbol_type'));
        $this->assertSame(1, data_get($pack, 'provenance.code_graph.initial_delivery_policy.deferred_symbol_counts.file'));
        $this->assertContains('code_files', data_get($pack, 'provenance.code_graph.initial_delivery_policy.deferred_source_types'));
        $this->assertContains('expand:code_intelligence', data_get($pack, 'context_delivery_policy.on_demand_handles'));
        $this->assertContains('initial_code_file_symbol_deferral', data_get($pack, 'provenance.aobg_runtime.feature_flags'));
    }

    public function test_initial_context_pack_defers_runtime_surfaces_for_generic_aobg_query(): void
    {
        $this->seedCodeRow(
            'cli_command',
            'atlas:open-brain:context',
            'app/Console/Commands/AtlasOpenBrainContextCommand.php',
            'atlas:open-brain:context {objective*}',
        );
        $this->seedCodeRow(
            'route',
            'POST /ai/open-brain/context-pack',
            'routes/api.php',
            'POST /ai/open-brain/context-pack',
        );
        $this->seedCodeRow(
            'class',
            'App\\Console\\Commands\\AtlasOpenBrainContextCommand',
            'app/Console/Commands/AtlasOpenBrainContextCommand.php',
            'class AtlasOpenBrainContextCommand',
        );
        $this->seedCodeRow(
            'class',
            'App\\Http\\Requests\\BuildAtlasOpenBrainContextRequest',
            'app/Http/Requests/BuildAtlasOpenBrainContextRequest.php',
            'class BuildAtlasOpenBrainContextRequest',
        );
        $this->seedCodeRow(
            'class',
            'App\\Services\\Ai\\AtlasOpenBrainContextPackService',
            'app/Services/Ai/AtlasOpenBrainContextPackService.php',
            'class AtlasOpenBrainContextPackService',
        );

        $pack = $this->service()->packFor('melhorar qualidade contexto AOBG', [
            'budget' => 4000,
            'code_budget' => 2000,
        ]);

        $types = array_column($pack['code_graph'], 'symbol_type');
        $this->assertContains('class', $types);
        $this->assertNotContains('cli_command', $types);
        $this->assertNotContains('route', $types);
        $ids = array_column($pack['code_graph'], 'id');
        $this->assertNotContains('sym:App\\Console\\Commands\\AtlasOpenBrainContextCommand', $ids);
        $this->assertNotContains('sym:App\\Http\\Requests\\BuildAtlasOpenBrainContextRequest', $ids);
        $this->assertSame(1, data_get($pack, 'provenance.code_graph.initial_delivery_policy.deferred_symbol_counts.cli_command'));
        $this->assertSame(1, data_get($pack, 'provenance.code_graph.initial_delivery_policy.deferred_symbol_counts.route'));
        $this->assertSame(2, data_get($pack, 'provenance.code_graph.initial_delivery_policy.deferred_symbol_counts.class'));
        $this->assertContains('runtime_surfaces', data_get($pack, 'provenance.code_graph.initial_delivery_policy.deferred_source_types'));
        $this->assertContains('initial_surface_symbol_deferral', data_get($pack, 'provenance.aobg_runtime.feature_flags'));
    }

    public function test_initial_context_pack_keeps_runtime_surfaces_for_cli_intent(): void
    {
        $this->seedCodeRow(
            'cli_command',
            'atlas:open-brain:context',
            'app/Console/Commands/AtlasOpenBrainContextCommand.php',
            'atlas:open-brain:context {objective*}',
        );
        $this->seedCodeRow(
            'class',
            'App\\Services\\Ai\\AtlasOpenBrainContextPackService',
            'app/Services/Ai/AtlasOpenBrainContextPackService.php',
            'class AtlasOpenBrainContextPackService',
        );

        $pack = $this->service()->packFor('listar comando CLI AOBG', [
            'budget' => 4000,
            'code_budget' => 2000,
        ]);

        $this->assertContains('cli_command', array_column($pack['code_graph'], 'symbol_type'));
        $this->assertSame(
            'auxiliary_symbols_included_by_intent',
            data_get($pack, 'provenance.code_graph.initial_delivery_policy.mode'),
        );
    }

    public function test_expand_test_symbols_handle_returns_deferred_test_symbol_pointers(): void
    {
        $this->seedCodeRow(
            'test_method',
            'Tests\\Feature\\Ai\\AtlasOpenBrainContextPackServiceTest::test_aobg_context_pack_expands_tests',
            'tests/Feature/Ai/AtlasOpenBrainContextPackServiceTest.php',
            'public function test_aobg_context_pack_expands_tests(): void',
        );
        $this->seedCodeRow(
            'method',
            'App\\Services\\Ai\\AtlasOpenBrainContextPackService::packFor',
            'app/Services/Ai/AtlasOpenBrainContextPackService.php',
            'public function packFor(string $task, array $opts = []): array',
        );

        $payload = app(AtlasOpenBrainContextExpansionService::class)->expand([
            'handle' => 'expand:test_symbols',
            'objective' => 'AOBG context pack',
            'workspace' => 'atlas-server',
            'task_type' => 'dev',
            'max_refs' => 4,
            'budget' => 4000,
        ]);

        $this->assertSame('ready', $payload['status']);
        $this->assertSame('code_graph_test_symbol_expansion', $payload['mode']);
        $this->assertSame('test_symbols', data_get($payload, 'handle.source_type'));
        $this->assertSame(1, data_get($payload, 'expansion.selected_symbol_count'));
        $this->assertSame('test_method', data_get($payload, 'expansion.selected_symbols.0.symbol_type'));
        $this->assertFalse(data_get($payload, 'policy.raw_tests_dumped'));
        $this->assertFalse(data_get($payload, 'policy.providers_invoked'));
    }

    public function test_context_pack_uses_recent_feedback_to_shrink_initial_budget_and_offer_expansion_handles(): void
    {
        $this->bootCompoundingSchema();

        $this->seedCodeSymbol('CodeGraphEmbeddingDecisionResolver', 'atlas-server');
        $this->seedMemory('mem-1', 'Embedding decision memoria note', true, 'normal');
        $this->recordLowRoiFeedback('receipt-aobg-policy-1');
        $this->recordLowRoiFeedback('receipt-aobg-policy-2');

        $pack = $this->service()->packFor('embedding decision', [
            'budget' => 2000,
            'flow_id' => 'aobg.pack',
        ]);

        $policy = $pack['context_delivery_policy'];

        $this->assertSame(AtlasOpenBrainContextPackService::CONTEXT_DELIVERY_POLICY_SCHEMA, $policy['schema_version']);
        $this->assertSame('active', $policy['status']);
        $this->assertSame('feedback_shrunk_initial_expand_on_demand', $policy['delivery_mode']);
        $this->assertSame('latest_flow_feedback', $policy['source']);
        $this->assertSame('aobg.pack', $policy['flow_id']);
        $this->assertSame(0.85, $policy['initial_context_budget_multiplier']);
        $this->assertTrue($policy['applied_to_initial_budget']);
        $this->assertContains('shrink_initial_context', $policy['actions']);
        $this->assertContains('expand_missing_source_types', $policy['actions']);
        $this->assertContains('migration', $policy['expand_source_types']);
        $this->assertContains('expand:migration', $policy['on_demand_handles']);
        $this->assertContains('recheck:canonical_doc', $policy['on_demand_handles']);
        $this->assertSame(2, data_get($policy, 'evidence.feedback_event_count'));
        $this->assertSame(2, data_get($policy, 'evidence.low_roi_count'));
        $this->assertFalse(data_get($policy, 'policy.raw_text_exposed'));
        $this->assertFalse(data_get($policy, 'policy.providers_invoked'));
        $this->assertFalse(data_get($policy, 'policy.ref_demotion_auto_applied'));
        $this->assertSame('bounded_initial_budget_only', data_get($policy, 'policy.auto_apply_scope'));

        $this->assertSame(2000, $pack['budget']['requested_total_chars']);
        $this->assertSame(1700, $pack['budget']['total_chars']);
        $this->assertStringContainsString('## Context delivery policy', $pack['markdown']);
        $this->assertStringContainsString('expand:migration', $pack['markdown']);
        $this->assertStringNotContainsString('receipt-aobg-policy', json_encode($policy, JSON_THROW_ON_ERROR));
    }

    public function test_readiness_only_feedback_does_not_shrink_initial_budget_without_roi_signal(): void
    {
        $this->bootCompoundingSchema();

        $this->recordReadinessOnlyFeedback('receipt-aobg-ready-1');
        $this->recordReadinessOnlyFeedback('receipt-aobg-ready-2');

        $pack = $this->service()->packFor('embedding decision', [
            'budget' => 2000,
            'flow_id' => 'aobg.readiness',
        ]);

        $policy = $pack['context_delivery_policy'];

        $this->assertSame('observed', $policy['status']);
        $this->assertSame('standard_minimal_top_k', $policy['delivery_mode']);
        $this->assertSame('latest_flow_feedback', $policy['source']);
        $this->assertSame(['keep_current_pack'], $policy['actions']);
        $this->assertSame(1.0, $policy['initial_context_budget_multiplier']);
        $this->assertFalse($policy['applied_to_initial_budget']);
        $this->assertSame(2, data_get($policy, 'evidence.feedback_event_count'));
        $this->assertSame(0, data_get($policy, 'evidence.low_roi_count'));
        $this->assertSame(0, data_get($policy, 'evidence.non_passing_count'));
        $this->assertSame(0, data_get($policy, 'evidence.actionable_feedback_count'));
        $this->assertSame(2, data_get($policy, 'evidence.non_actionable_feedback_count'));
        $this->assertSame(2, data_get($policy, 'evidence.missing_roi_signal_count'));
        $this->assertSame('feedback_observed_but_not_actionable_for_budget', $policy['quality_gate_hint']);
        $this->assertSame(2000, $pack['budget']['total_chars']);
    }

    public function test_context_pack_uses_roi_feedback_to_adjust_initial_source_mix(): void
    {
        $this->bootCompoundingSchema();

        for ($i = 0; $i < 6; $i++) {
            $this->seedCodeSymbol('CodeGraphEmbeddingDecisionResolver'.$i, 'atlas-server');
            $this->seedMemory('mem-source-'.$i, 'Embedding decision source mix memory '.$i, true, 'normal');
        }
        $this->recordSourceMixFeedback('receipt-aobg-source-mix-1');
        $this->recordSourceMixFeedback('receipt-aobg-source-mix-2');

        $pack = $this->service()->packFor('embedding decision', [
            'budget' => 3000,
            'code_budget' => 1200,
            'memory_budget' => 1200,
            'flow_id' => 'aobg.source_mix',
        ]);

        $policy = $pack['context_delivery_policy'];
        $sourcePolicy = $policy['source_selection_policy'];

        $this->assertSame('active', $sourcePolicy['status']);
        $this->assertTrue($sourcePolicy['applied_to_initial_pack']);
        $this->assertContains('adjust_initial_source_mix', $policy['actions']);
        $this->assertSame(1.0, data_get($sourcePolicy, 'budget_multipliers.code'));
        $this->assertLessThan(1.0, data_get($sourcePolicy, 'budget_multipliers.memory'));
        $this->assertSame('reduce_initial_share', data_get($sourcePolicy, 'source_types.memory.action'));
        $this->assertSame('preserve_initial_share', data_get($sourcePolicy, 'source_types.code.action'));
        $this->assertLessThan($pack['budget']['code_budget_chars'], $pack['budget']['memory_budget_chars']);
        $this->assertFalse(data_get($sourcePolicy, 'guardrails.raw_text_exposed'));
        $this->assertStringContainsString('source_mix:', $pack['markdown']);
    }

    public function test_context_pack_filters_demoted_noise_refs_from_initial_code_graph(): void
    {
        $this->bootCompoundingSchema();

        $this->seedCodeRow(
            'class',
            'ContextRequirements',
            'app/Services/Ai/Noisy/ContextRequirements.php',
            'class ContextRequirements',
        );
        $this->seedCodeRow(
            'class',
            'ContextPackRequirementsService',
            'app/Services/Ai/ContextPackRequirementsService.php',
            'class ContextPackRequirementsService',
        );
        $this->recordDemotionFeedback('receipt-aobg-demote-1');

        $pack = $this->service()->packFor('context requirements', [
            'flow_id' => 'aobg.demote',
            'budget' => 3000,
            'code_budget' => 2000,
        ]);

        $ids = array_column($pack['code_graph'], 'id');
        $this->assertNotContains('sym:ContextRequirements', $ids);
        $this->assertContains('sym:ContextPackRequirementsService', $ids);
        $this->assertSame(1, data_get($pack, 'provenance.code_graph.feedback_demoted_count'));
        $this->assertContains(
            'app/Services/Ai/Noisy/ContextRequirements.php::ContextRequirements',
            data_get($pack, 'context_delivery_policy.demote_context_refs'),
        );
    }

    public function test_context_pack_filters_demoted_memory_refs_by_published_content_hash_ref(): void
    {
        $this->bootCompoundingSchema();
        Schema::table('atlas_memory_entries', function (Blueprint $table): void {
            $table->string('content_hash', 64)->nullable();
        });

        $contentHash = hash('sha256', 'noisy-memory-content');
        $publishedRef = 'memory:'.substr(hash('sha256', $contentHash), 0, 32);
        $this->seedMemory(
            'mem-noisy',
            'Noisy context requirements memory',
            true,
            'normal',
            'Noisy context requirements summary',
            'Noisy context requirements body',
        );
        AtlasMemoryEntry::query()
            ->where('source_id', 'mem-noisy')
            ->update(['content_hash' => $contentHash]);

        app(AtlasRagFeedbackService::class)->record([
            'retrieval_receipt_id' => 'receipt-aobg-memory-demote-1',
            'flow_id' => 'aobg.memory_demote',
            'query_plan_hash' => hash('sha256', 'receipt-aobg-memory-demote-1'),
            'included_sources' => 1,
            'used_sources' => 0,
            'noise_sources' => 1,
            'missed_required_sources' => [],
            'context_sufficiency' => 60,
            'post_execution_utility' => 20,
            'source_utility' => [],
            'outcome_status' => 'partial',
            'payload' => [
                'schema_version' => 'atlas.aucri.retrieval_feedback_loop.v1',
                'context_ref_attribution' => [
                    'noise_refs' => [
                        ['ref' => $publishedRef, 'source_type' => 'memory'],
                    ],
                    'noise_count' => 1,
                ],
                'next_context_policy' => [
                    'actions' => ['demote_noise_context_refs'],
                    'demote_context_refs' => [$publishedRef],
                    'auto_apply' => false,
                ],
                'raw_text_exposed' => false,
            ],
        ]);

        $pack = $this->service()->packFor('noisy context requirements', [
            'flow_id' => 'aobg.memory_demote',
            'budget' => 3000,
            'memory_budget' => 2000,
        ]);

        $this->assertSame([], $pack['memory']);
        $this->assertSame(1, data_get($pack, 'provenance.memory.feedback_demoted_count'));
        $this->assertSame(1, data_get($pack, 'context_hygiene.feedback_demoted'));
        $this->assertContains($publishedRef, data_get($pack, 'context_delivery_policy.demote_context_refs'));
    }

    public function test_context_pack_filters_demoted_memory_refs_by_published_title_fallback_ref(): void
    {
        $this->bootCompoundingSchema();
        $title = 'Noisy fallback context requirements memory';
        $publishedRef = 'memory:'.substr(hash('sha256', json_encode([
            'title' => $title,
            'type' => 'decision',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 32);

        $this->seedMemory(
            'mem-noisy-fallback',
            $title,
            true,
            'normal',
            'Noisy fallback context requirements summary',
            'Noisy fallback context requirements body',
        );

        app(AtlasRagFeedbackService::class)->record([
            'retrieval_receipt_id' => 'receipt-aobg-memory-demote-2',
            'flow_id' => 'aobg.memory_demote_fallback',
            'query_plan_hash' => hash('sha256', 'receipt-aobg-memory-demote-2'),
            'included_sources' => 1,
            'used_sources' => 0,
            'noise_sources' => 1,
            'missed_required_sources' => [],
            'context_sufficiency' => 60,
            'post_execution_utility' => 20,
            'source_utility' => [],
            'outcome_status' => 'partial',
            'payload' => [
                'schema_version' => 'atlas.aucri.retrieval_feedback_loop.v1',
                'context_ref_attribution' => [
                    'noise_refs' => [
                        ['ref' => $publishedRef, 'source_type' => 'memory'],
                    ],
                    'noise_count' => 1,
                ],
                'next_context_policy' => [
                    'actions' => ['demote_noise_context_refs'],
                    'demote_context_refs' => [$publishedRef],
                    'auto_apply' => false,
                ],
                'raw_text_exposed' => false,
            ],
        ]);

        $pack = $this->service()->packFor('noisy fallback context requirements', [
            'flow_id' => 'aobg.memory_demote_fallback',
            'budget' => 3000,
            'memory_budget' => 2000,
        ]);

        $this->assertSame([], $pack['memory']);
        $this->assertSame(1, data_get($pack, 'provenance.memory.feedback_demoted_count'));
        $this->assertContains($publishedRef, data_get($pack, 'context_delivery_policy.demote_context_refs'));
    }

    public function test_initial_pack_filters_noisy_code_paths_unless_task_explicitly_mentions_them(): void
    {
        $this->seedCodeRow(
            'class',
            'StateMachine',
            'tools/rivals/benchmarks/inspect_evals/src/inspect_evals/cyberseceval/example_state_machine.cpp',
            'class StateMachine',
        );
        $this->seedCodeRow(
            'class',
            'PolymarketExecutionStateMachine',
            'app/Services/Ai/Polymarket/PolymarketExecutionStateMachine.php',
            'class PolymarketExecutionStateMachine',
        );

        $pack = $this->service()->packFor('melhorar Polymarket state machine', [
            'budget' => 3000,
            'code_budget' => 2000,
        ]);

        $ids = array_column($pack['code_graph'], 'id');
        $this->assertContains('sym:PolymarketExecutionStateMachine', $ids);
        $this->assertNotContains('sym:StateMachine', $ids);
        $this->assertSame(1, data_get($pack, 'provenance.code_graph.path_filtered_count'));
        $this->assertSame(1, data_get($pack, 'context_hygiene.path_filtered'));
        $this->assertSame(1, data_get($pack, 'context_hygiene.total_filtered'));
        $this->assertStringContainsString('context_hygiene: path_filtered=1', $pack['markdown']);

        $retriever = app(CodeGraphContextRetriever::class);
        $extractTerms = new \ReflectionMethod(CodeGraphContextRetriever::class, 'extractTermsForQuery');
        $terms = $extractTerms->invoke($retriever, 'avaliar rivals benchmark state machine', []);

        $this->assertContains('rivals', $terms);
        $this->assertContains('benchmark', $terms);
        $this->assertContains('state', $terms);
        $this->assertContains('machine', $terms);
    }

    public function test_initial_pack_filters_vendor_namespace_stubs_unless_task_explicitly_mentions_them(): void
    {
        $this->seedCodeRow(
            'class',
            'phpDocumentor\\Reflection\\DocBlockFactory',
            'app/Services/Ai/AutonomousEvolution/SelfMod/AtlasLoopSelfModInvariantExtractor.php',
            'final class DocBlockFactory',
        );
        $this->seedCodeRow(
            'class',
            'AtlasOpenBrainMcpService',
            'app/Services/Ai/AtlasOpenBrainMcpService.php',
            'class AtlasOpenBrainMcpService { private function mcpSelfCheck(array $arguments): array {} }',
        );

        $pack = $this->service()->packFor('MCP self_check source_probe context_pack', [
            'budget' => 3000,
            'code_budget' => 2000,
        ]);

        $ids = array_column($pack['code_graph'], 'id');
        $this->assertContains('sym:AtlasOpenBrainMcpService', $ids);
        $this->assertNotContains('sym:phpDocumentor\\Reflection\\DocBlockFactory', $ids);
        $this->assertSame(1, data_get($pack, 'provenance.code_graph.path_filtered_count'));

        $retriever = app(CodeGraphContextRetriever::class);
        $extractTerms = new \ReflectionMethod(CodeGraphContextRetriever::class, 'extractTermsForQuery');
        $terms = $extractTerms->invoke($retriever, 'debug phpDocumentor DocBlockFactory invariant parsing', []);

        $this->assertContains('documentor', $terms);
        $this->assertContains('doc', $terms);
        $this->assertContains('factory', $terms);
    }

    public function test_self_check_query_does_not_pull_checkin_by_substring(): void
    {
        $this->seedCodeRow(
            'class',
            'Checkin',
            'app/Models/Checkin.php',
            'class Checkin',
        );
        $this->seedCodeRow(
            'class',
            'AtlasOpenBrainMcpService',
            'app/Services/Ai/AtlasOpenBrainMcpService.php',
            'class AtlasOpenBrainMcpService { private function mcpSelfCheck(array $arguments): array {} }',
        );

        $pack = $this->service()->packFor('MCP self_check source_probe context_pack', [
            'budget' => 3000,
            'code_budget' => 2000,
        ]);

        $ids = array_column($pack['code_graph'], 'id');
        $this->assertContains('sym:AtlasOpenBrainMcpService', $ids);
        $this->assertNotContains('sym:Checkin', $ids);
    }

    public function test_health_check_query_still_recalls_health_symbols(): void
    {
        $this->seedCodeRow(
            'class',
            'AtlasBrainHealthDoctorCommand',
            'app/Console/Commands/AtlasBrainHealthDoctorCommand.php',
            'class AtlasBrainHealthDoctorCommand',
        );
        $this->seedCodeRow(
            'class',
            'Checkin',
            'app/Models/Checkin.php',
            'class Checkin',
        );

        $pack = $this->service()->packFor('health check', [
            'budget' => 3000,
            'code_budget' => 2000,
        ]);

        $ids = array_column($pack['code_graph'], 'id');
        $this->assertContains('sym:AtlasBrainHealthDoctorCommand', $ids);
        $this->assertNotContains('sym:Checkin', $ids);
    }

    public function test_docblock_query_does_not_pull_blocker_by_substring(): void
    {
        $this->seedCodeRow(
            'class',
            'phpDocumentor\\Reflection\\DocBlockFactory',
            'app/Services/Ai/AutonomousEvolution/SelfMod/AtlasLoopSelfModInvariantExtractor.php',
            'final class DocBlockFactory',
        );
        $this->seedCodeRow(
            'class',
            'AtlasProjectBlocker',
            'app/Models/AtlasProjectBlocker.php',
            'class AtlasProjectBlocker',
        );

        $pack = $this->service()->packFor('debug phpDocumentor DocBlockFactory invariant parsing', [
            'budget' => 3000,
            'code_budget' => 2000,
        ]);

        $ids = array_column($pack['code_graph'], 'id');
        $this->assertContains('sym:phpDocumentor\\Reflection\\DocBlockFactory', $ids);
        $this->assertNotContains('sym:AtlasProjectBlocker', $ids);
    }

    public function test_aobg_query_does_not_pull_external_brain_by_generic_brain_expansion(): void
    {
        $this->seedCodeRow(
            'class',
            'AtlasOpenBrainContextPackService',
            'app/Services/Ai/AtlasOpenBrainContextPackService.php',
            'class AtlasOpenBrainContextPackService',
        );
        $this->seedCodeRow(
            'cli_command',
            'atlas:external-brain:task-graph-wave',
            'app/Console/Commands/AtlasExternalBrainTaskGraphWaveCommand.php',
            'atlas:external-brain:task-graph-wave',
        );
        $this->seedCodeRow(
            'class',
            'ChatWeakResponseProbe',
            'app/Services/Ai/Gateway/ChatWeakResponseProbe.php',
            'class ChatWeakResponseProbe',
        );
        $this->seedCodeRow(
            'class',
            'AtlasRuntimeEfficiencyOutcome',
            'app/Models/AtlasRuntimeEfficiencyOutcome.php',
            'class AtlasRuntimeEfficiencyOutcome',
        );
        $this->seedCodeRow(
            'class',
            'AtlasSelfImprovementScheduleService',
            'app/Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php',
            'class AtlasSelfImprovementScheduleService',
        );
        $this->seedCodeRow(
            'class',
            'AtlasDiffReviewService',
            'app/Services/Ai/Review/AtlasDiffReviewService.php',
            'class AtlasDiffReviewService',
        );

        $pack = $this->service()->packFor('AOBG', [
            'budget' => 3000,
            'code_budget' => 2000,
        ]);

        $ids = array_column($pack['code_graph'], 'id');
        $this->assertContains('sym:AtlasOpenBrainContextPackService', $ids);
        $this->assertNotContains('sym:atlas:external-brain:task-graph-wave', $ids);
        $this->assertNotContains('sym:ChatWeakResponseProbe', $ids);

        $efficiencyPack = $this->service()->packFor('eficiencia AOBG', [
            'budget' => 3000,
            'code_budget' => 2000,
        ]);
        $efficiencyIds = array_column($efficiencyPack['code_graph'], 'id');
        $this->assertContains('sym:AtlasOpenBrainContextPackService', $efficiencyIds);
        $this->assertNotContains('sym:AtlasRuntimeEfficiencyOutcome', $efficiencyIds);

        $retriever = app(CodeGraphContextRetriever::class);
        $extractTerms = new \ReflectionMethod(CodeGraphContextRetriever::class, 'extractTermsForQuery');
        $terms = $extractTerms->invoke($retriever, 'continuar melhoria incremental AOBG com menor diff', []);

        $this->assertNotContains('self', $terms);
        $this->assertNotContains('improvement', $terms);
        $this->assertNotContains('diff', $terms);
        $this->assertNotContains('review', $terms);
    }

    public function test_atlas_context_pack_alias_prefers_open_brain_context_pack(): void
    {
        $this->seedCodeRow(
            'class',
            'AtlasOpenBrainContextPackService',
            'app/Services/Ai/AtlasOpenBrainContextPackService.php',
            'class AtlasOpenBrainContextPackService',
        );
        $this->seedCodeRow(
            'cli_command',
            'atlas:context:observability',
            'app/Console/Commands/AtlasContextObservabilityPlaneCommand.php',
            'atlas:context:observability',
        );

        $pack = $this->service()->packFor('debug atlas_context_pack contexto desnecessario', [
            'budget' => 3000,
            'code_budget' => 2000,
        ]);

        $this->assertSame('sym:AtlasOpenBrainContextPackService', $pack['code_graph'][0]['id'] ?? null);
    }

    public function test_portuguese_quality_memory_query_prefers_memory_quality_service(): void
    {
        $this->seedCodeRow(
            'class',
            'AtlasMemoryEntryUsage',
            'app/Models/AtlasMemoryEntryUsage.php',
            'class AtlasMemoryEntryUsage',
        );
        $this->seedCodeRow(
            'class',
            'AtlasMemoryQualityService',
            'app/Services/Ai/AtlasMemoryQualityService.php',
            'class AtlasMemoryQualityService',
        );
        $this->seedCodeRow(
            'class',
            'AtlasAobgWorkspaceOnboardingService',
            'app/Services/Ai/AtlasAobgWorkspaceOnboardingService.php',
            'class AtlasAobgWorkspaceOnboardingService',
        );

        $pack = $this->service()->packFor('qualidade memoria provider safe AOBG', [
            'budget' => 3000,
            'code_budget' => 2000,
        ]);

        $this->assertSame('sym:AtlasMemoryQualityService', $pack['code_graph'][0]['id'] ?? null);

        $retriever = app(CodeGraphContextRetriever::class);
        $extractTerms = new \ReflectionMethod(CodeGraphContextRetriever::class, 'extractTermsForQuery');
        foreach ([
            'AOBG memoria baixa qualidade',
            'AOBG memória baixa qualidade',
            'AOBG revisar contextos guardados',
        ] as $query) {
            $terms = $extractTerms->invoke($retriever, $query, []);

            $this->assertContains('memory', $terms, $query);
            $this->assertContains('quality', $terms, $query);
            $this->assertNotContains('open', $terms, $query);
            $this->assertNotContains('pack', $terms, $query);
        }
    }

    public function test_portuguese_stored_contexts_with_hostile_language_query_finds_tone_filter(): void
    {
        $this->seedCodeRow(
            'class',
            'AtlasOpenBrainProviderSafeMemoryToneFilter',
            'app/Services/Ai/AtlasOpenBrainProviderSafeMemoryToneFilter.php',
            'final class AtlasOpenBrainProviderSafeMemoryToneFilter',
        );

        $pack = $this->service()->packFor('revisar contextos guardados com xingando', [
            'budget' => 3000,
            'code_budget' => 2000,
        ]);

        $this->assertContains(
            'sym:AtlasOpenBrainProviderSafeMemoryToneFilter',
            array_column($pack['code_graph'], 'id'),
        );
    }

    public function test_portuguese_noise_context_query_finds_aobg_quarantine_advisor(): void
    {
        $this->seedCodeRow(
            'class',
            'AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor',
            'app/Services/Ai/AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor.php',
            'final class AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor',
        );
        $this->seedCodeRow(
            'cli_command',
            'atlas:context:observability',
            'app/Console/Commands/AtlasContextObservabilityPlaneCommand.php',
            'atlas:context:observability',
        );

        $pack = $this->service()->packFor('ruido contexto desnecessario AOBG', [
            'budget' => 3000,
            'code_budget' => 2000,
        ]);

        $this->assertContains(
            'sym:AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor',
            array_column($pack['code_graph'], 'id'),
        );

        $retriever = app(CodeGraphContextRetriever::class);
        $extractTerms = new \ReflectionMethod(CodeGraphContextRetriever::class, 'extractTermsForQuery');
        foreach ([
            'limpar memoria AOBG contexto ruim sem sentido',
            'AOBG contexto irrelevante',
            'AOBG contexto duplicado repetido inutil aleatorio',
            'AOBG contexto demais excessivo',
            'AOBG contexto lixo toxico baixo valor sem utilidade ruim inutil',
            'AOBG contexto aleatorio distrai atrapalha polui',
            'AOBG contexto entulho sujeira bagunca contaminado misturado sem foco disperso confuso excesso enchendo prompt',
            'AOBG contexto bagunça tóxico',
            'AOBG prompt gigante contexto lotando ocupa token desnecessario verboso longo excesso',
        ] as $query) {
            $terms = $extractTerms->invoke($retriever, $query, []);

            $this->assertContains('noise', $terms, $query);
            $this->assertContains('quarantine', $terms, $query);
        }
    }

    public function test_portuguese_stale_context_query_finds_freshness_gate(): void
    {
        $this->seedCodeRow(
            'class',
            'AtlasContextFreshnessQualityGateService',
            'app/Services/Ai/Context/AtlasContextFreshnessQualityGateService.php',
            'final class AtlasContextFreshnessQualityGateService',
        );
        $this->seedCodeRow(
            'class',
            'AtlasOpenBrainContextPackService',
            'app/Services/Ai/AtlasOpenBrainContextPackService.php',
            'final class AtlasOpenBrainContextPackService',
        );

        $pack = $this->service()->packFor('AOBG contexto velho desatualizado stale', [
            'budget' => 3000,
            'code_budget' => 2000,
        ]);

        $this->assertContains(
            'sym:AtlasContextFreshnessQualityGateService',
            array_column($pack['code_graph'], 'id'),
        );
    }

    public function test_portuguese_injected_context_query_finds_injection_boundary(): void
    {
        $this->seedCodeRow(
            'class',
            'AtlasOpenBrainContextInjectionBoundaryClassifier',
            'app/Services/Ai/AtlasOpenBrainContextInjectionBoundaryClassifier.php',
            'final class AtlasOpenBrainContextInjectionBoundaryClassifier',
        );
        $this->seedCodeRow(
            'class',
            'AtlasOpenBrainContextPackService',
            'app/Services/Ai/AtlasOpenBrainContextPackService.php',
            'final class AtlasOpenBrainContextPackService',
        );

        $pack = $this->service()->packFor('revisar contexto injetado AOBG sem sentido', [
            'budget' => 3000,
            'code_budget' => 2000,
        ]);

        $this->assertContains(
            'sym:AtlasOpenBrainContextInjectionBoundaryClassifier',
            array_column($pack['code_graph'], 'id'),
        );

        $scopePack = $this->service()->packFor('AOBG contexto fora de escopo vazando', [
            'budget' => 3000,
            'code_budget' => 2000,
        ]);

        $this->assertContains(
            'sym:AtlasOpenBrainContextInjectionBoundaryClassifier',
            array_column($scopePack['code_graph'], 'id'),
        );
        $this->assertSame(
            'sym:AtlasOpenBrainContextInjectionBoundaryClassifier',
            data_get($scopePack, 'code_graph.0.id'),
        );

        $retriever = app(CodeGraphContextRetriever::class);
        $extractTerms = new \ReflectionMethod(CodeGraphContextRetriever::class, 'extractTermsForQuery');
        foreach ([
            'AOBG contexto sem relacao nao relacionado',
            'AOBG contexto não relacionado',
        ] as $query) {
            $terms = $extractTerms->invoke($retriever, $query, []);

            $this->assertContains('boundary', $terms, $query);
            $this->assertContains('scope', $terms, $query);
        }
    }

    public function test_exact_aobg_service_name_brings_owner_service_before_generic_commands(): void
    {
        $this->seedCodeRow(
            'cli_command',
            'atlas:open-brain:context',
            'app/Console/Commands/AtlasOpenBrainContextCommand.php',
            'atlas:open-brain:context {objective*}',
        );
        $this->seedCodeRow(
            'cli_command',
            'atlas:open-brain:expand-context',
            'app/Console/Commands/AtlasOpenBrainExpandContextCommand.php',
            'atlas:open-brain:expand-context {handle} {objective*}',
        );
        $this->seedCodeRow(
            'method',
            'App\\Services\\Ai\\AtlasOpenBrainContextPackService::packFor',
            'app/Services/Ai/AtlasOpenBrainContextPackService.php',
            'public function packFor(string $task, array $opts = []): array',
        );

        $pack = $this->service()->packFor('implementar melhoria no AtlasOpenBrainContextPackService', [
            'budget' => 1200,
            'code_budget' => 1000,
            'memory_budget' => 0,
        ]);

        $this->assertContains(
            'sym:App\\Services\\Ai\\AtlasOpenBrainContextPackService::packFor',
            array_column($pack['code_graph'], 'id'),
        );
    }

    public function test_generic_aobg_query_prefers_owner_service_over_cli_wrapper(): void
    {
        $this->seedCodeRow(
            'cli_command',
            'atlas:aobg:file-context',
            'app/Console/Commands/AtlasAobgFileContextCommand.php',
            'atlas:aobg:file-context {path}',
        );
        $this->seedCodeRow(
            'class',
            'App\\Services\\Ai\\AtlasOpenBrainContextPackService',
            'app/Services/Ai/AtlasOpenBrainContextPackService.php',
            'class AtlasOpenBrainContextPackService',
        );

        $pack = $this->service()->packFor('AOBG', [
            'budget' => 1200,
            'code_budget' => 1000,
            'memory_budget' => 0,
        ]);

        $this->assertSame(
            'sym:App\\Services\\Ai\\AtlasOpenBrainContextPackService',
            data_get($pack, 'code_graph.0.id'),
        );

        $retriever = app(CodeGraphContextRetriever::class);
        $extractTerms = new \ReflectionMethod(CodeGraphContextRetriever::class, 'extractTermsForQuery');
        foreach ([
            'qual o foco do AOBG',
            'qual o valor do AOBG',
            'qual o sentido do AOBG',
            'AOBG relacao com memoria',
            'AOBG baixo nivel',
            'qual o assunto do AOBG',
            'qual o escopo do AOBG',
            'limpar AOBG arquitetura',
            'AOBG fora do servidor',
            'AOBG rodando fora',
            'AOBG codigo confuso',
            'AOBG projeto gigante',
            'AOBG documento longo',
            'AOBG excesso de features',
            'AOBG teste duplicado',
            'AOBG classe duplicada',
            'AOBG random aleatorio',
            'AOBG coisas demais',
            'AOBG setup excessivo',
            'AOBG codigo ruim',
            'AOBG design ruim',
            'AOBG arquitetura ruim',
            'AOBG inutil para usuarios',
            'AOBG recurso inutil',
        ] as $query) {
            $terms = $extractTerms->invoke($retriever, $query, []);

            $this->assertNotContains('noise', $terms, $query);
            $this->assertNotContains('quarantine', $terms, $query);
            $this->assertNotContains('feedback', $terms, $query);
            $this->assertNotContains('boundary', $terms, $query);
            $this->assertNotContains('scope', $terms, $query);
        }
    }

    public function test_memory_relevance_floor_filters_wiper_memory_unless_task_mentions_wiper(): void
    {
        $this->seedMemory(
            'mem-wiper',
            'Incidente wiper vendor symlink RefreshDatabase',
            true,
            'normal',
            'Wiper de tabelas por vendor symlink',
            'RefreshDatabase caiu no pgsql de producao e dropou tabelas',
        );
        $this->seedMemory(
            'mem-feedback',
            'AOBG feedback demotion policy',
            true,
            'normal',
            'feedback demotion policy',
            'feedback demotion policy for context pack relevance',
        );

        $pack = $this->service()->packFor('debug feedback demotion policy do AOBG', [
            'memory_budget' => 4000,
        ]);
        $titles = array_column($pack['memory'], 'title');

        $this->assertContains('AOBG feedback demotion policy', $titles);
        $this->assertNotContains('Incidente wiper vendor symlink RefreshDatabase', $titles);
        $this->assertSame(1, data_get($pack, 'provenance.memory.relevance_filtered_count'));
        $this->assertStringContainsString('memory_relevance_filtered=1', $pack['markdown']);

        $wiper = $this->service()->packFor('debug wiper vendor symlink test safety', [
            'memory_budget' => 4000,
        ]);

        $this->assertContains('Incidente wiper vendor symlink RefreshDatabase', array_column($wiper['memory'], 'title'));
    }

    public function test_context_pack_expands_umbrella_workspace_scope_without_leaking_other_workspaces(): void
    {
        config()->set('atlas.code_folder_intelligence.umbrella_context', true);

        $umbrella = $this->makeTempDir('umbrella-aobg');
        $alpha = $this->makeGitFolder($umbrella.'/alpha');
        $beta = $this->makeGitFolder($umbrella.'/beta');

        config()->set('atlas_projects.profiles', [
            $this->workspaceProfile('umbrella-aobg', $umbrella),
            $this->workspaceProfile('alpha-aobg', $alpha),
            $this->workspaceProfile('beta-aobg', $beta),
        ]);

        $this->seedCodeSymbol('AlphaWorkspaceAssemblyResolver', 'alpha-aobg');
        $this->seedCodeSymbol('BetaWorkspaceAssemblyResolver', 'beta-aobg');
        $this->seedCodeSymbol('GammaWorkspaceAssemblyResolver', 'gamma-aobg');

        $pack = $this->service()->packFor('workspace assembly resolver', [
            'workspace' => $umbrella,
            'budget' => 4000,
            'code_budget' => 3000,
        ]);

        $symbolIds = array_column($pack['code_graph'], 'id');
        $this->assertContains('sym:AlphaWorkspaceAssemblyResolver', $symbolIds);
        $this->assertContains('sym:BetaWorkspaceAssemblyResolver', $symbolIds);
        $this->assertNotContains('sym:GammaWorkspaceAssemblyResolver', $symbolIds);
        $this->assertSame(
            ['umbrella-aobg', 'alpha-aobg', 'beta-aobg'],
            data_get($pack, 'provenance.code_graph.workspace_scope'),
        );
    }

    public function test_cli_command_runs_json_and_validates_input(): void
    {
        $this->seedMemory('mem-1', 'Embedding decision cli note', true, 'normal');

        $this->artisan('atlas:context-pack', ['task' => 'embedding decision', '--json' => true])
            ->assertSuccessful();

        // Empty task is still a clean exit 0 (fail-safe, never a gate).
        $this->artisan('atlas:context-pack', ['task' => '   '])->assertSuccessful();

        // Default (markdown) render also exits 0.
        $this->artisan('atlas:context-pack', ['task' => 'embedding decision'])->assertSuccessful();
    }

    // ------------------------------------------------------------------
    // fixtures (all sqlite, cost-free — no provider call)
    // ------------------------------------------------------------------

    private function service(): AtlasOpenBrainContextPackService
    {
        return $this->app->make(AtlasOpenBrainContextPackService::class);
    }

    /** Symbols table WITH the W-1 workspace_id column (so scoping is exercised). */
    private function createCodeSymbolsTable(): void
    {
        Schema::dropIfExists('atlas_engineering_code_symbols');
        Schema::create('atlas_engineering_code_symbols', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('module_id')->nullable()->index();
            $table->string('symbol_type', 60)->index();
            $table->string('symbol_name', 300)->index();
            $table->string('file_path', 500)->index();
            $table->string('language', 40)->nullable()->index();
            $table->text('signature')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->string('source_hash', 64)->index();
            $table->string('workspace_id', 160)->default('atlas-server')->index();
            $table->json('related_doc_ids_json')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }

    private function createAurgTables(): void
    {
        Schema::dropIfExists('atlas_aurg_edges');
        Schema::dropIfExists('atlas_aurg_nodes');

        $migration = require database_path('migrations/2026_06_09_120000_create_atlas_aurg_graph_tables.php');
        $migration->up();
    }

    private function seedCodeSymbol(string $name, string $workspaceId): void
    {
        $this->seedCodeRow(
            'class',
            $name,
            'app/Services/Ai/Memory/'.$name.'.php',
            'class '.$name,
            $workspaceId,
        );
    }

    private function seedCodeRow(
        string $type,
        string $name,
        string $filePath,
        string $signature,
        string $workspaceId = 'atlas-server',
    ): void {
        DB::table('atlas_engineering_code_symbols')->insert([
            'id' => (string) Str::uuid(),
            'symbol_type' => $type,
            'symbol_name' => $name,
            'file_path' => $filePath,
            'language' => 'php',
            'signature' => $signature,
            'status' => 'active',
            'source_hash' => hash('sha256', $workspaceId.$name),
            'workspace_id' => $workspaceId,
            'related_doc_ids_json' => '[]',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedMemory(
        string $sourceId,
        string $title,
        bool $providerSafe,
        string $privacyClass,
        ?string $summary = null,
        ?string $body = null,
    ): void {
        AtlasMemoryEntry::create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => $title,
            'summary' => $summary ?? ($providerSafe ? 'safe note summary' : 'secret vault key summary'),
            'body' => $body ?? ($providerSafe ? 'safe note body about the embedding decision' : 'secret vault key material'),
            'status' => 'active',
            'privacy_class' => $privacyClass,
            'external_ai_allowed' => $providerSafe,
            'redaction_status' => 'clean',
            'source_type' => 'test_fixture',
            'source_id' => $sourceId,
            'recorded_at' => now(),
        ]);
    }

    private function seedAurg(): void
    {
        $nodes = [
            [
                'id' => self::M1,
                'kind' => 'memory_entry',
                'source_kind' => 'memory',
                'source_id' => 'mem-1',
                'label' => 'Embedding decision for memoria vector search',
                'provider_safe' => true,
                'sensitive' => false,
                'meta' => ['type' => 'decision'],
            ],
            [
                'id' => self::C1,
                'kind' => 'module',
                'source_kind' => 'code',
                'source_id' => 'atlas-server/services-ai-memory',
                'label' => 'Ai Memory',
                'workspace_id' => 'atlas-server',
                'provider_safe' => true,
                'sensitive' => false,
                'meta' => ['slug' => 'services-ai-memory'],
            ],
            [
                'id' => self::DFIN,
                'kind' => 'domain',
                'source_kind' => 'domain',
                'source_id' => 'finance',
                'label' => 'Finance',
                'provider_safe' => false,
                'sensitive' => true,
                'meta' => [],
            ],
        ];
        foreach ($nodes as $node) {
            AtlasAurgNode::query()->create($node + ['content_hash' => hash('sha256', $node['id'])]);
        }

        $edges = [
            [self::M1, self::C1, 'references', 'linker_memory_code', 1.0, ['matched_path' => 'app/Services/Ai/Memory']],
            [self::M1, self::DFIN, 'belongs_to', 'linker_memory_domain', 1.0, ['matched_domain' => 'finance']],
        ];
        foreach ($edges as [$from, $to, $kind, $source, $confidence, $meta]) {
            AtlasAurgEdge::query()->create([
                'from_node_id' => $from,
                'to_node_id' => $to,
                'kind' => $kind,
                'source' => $source,
                'confidence' => $confidence,
                'meta' => $meta,
            ]);
        }
    }

    private function bootCompoundingSchema(): void
    {
        $this->dropCompoundingSchema();
        (require database_path('migrations/2026_05_17_180000_create_ai_compounding_engineering_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_19_030000_strengthen_rag_feedback_and_create_learning_proposals.php'))->up();
    }

    private function dropCompoundingSchema(): void
    {
        foreach ([
            'ai_learning_proposals',
            'ai_temporal_certifications',
            'ai_benchmark_cases',
            'ai_rag_feedback_events',
            'ai_heuristic_updates',
            'ai_compounding_memories',
            'ai_learning_candidates',
            'ai_run_outcomes',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function recordLowRoiFeedback(string $receiptId): void
    {
        app(AtlasRagFeedbackService::class)->record([
            'retrieval_receipt_id' => $receiptId,
            'flow_id' => 'aobg.pack',
            'query_plan_hash' => hash('sha256', $receiptId),
            'included_sources' => 4,
            'used_sources' => 1,
            'noise_sources' => 1,
            'missed_required_sources' => ['migration'],
            'context_sufficiency' => 62,
            'post_execution_utility' => 38,
            'source_utility' => [
                hash('sha256', 'source://noisy-doc') => 'noise',
            ],
            'outcome_status' => 'partial',
            'failure_reason' => 'retrieval_missed_required_source',
            'payload' => [
                'schema_version' => 'atlas.aucri.retrieval_feedback_loop.v1',
                'context_roi' => [
                    'roi_score' => 0.32,
                    'use_ratio' => 0.25,
                    'quality_band' => 'weak',
                    'context_sufficiency' => 62,
                    'post_execution_utility' => 38,
                ],
                'context_ref_attribution' => [
                    'use_ratio' => 0.25,
                    'waste_ratio' => 0.50,
                    'missing_source_types' => ['migration'],
                    'noise_count' => 1,
                ],
                'next_context_policy' => [
                    'actions' => ['shrink_initial_context', 'expand_missing_source_types'],
                    'next_initial_budget_multiplier' => 0.85,
                    'expand_source_types' => ['migration'],
                    'defer_sections' => ['canonical_doc'],
                    'demote_context_refs' => ['noise:canonical_doc'],
                    'auto_apply' => false,
                ],
                'raw_text_exposed' => false,
            ],
        ]);
    }

    private function recordSourceMixFeedback(string $receiptId): void
    {
        app(AtlasRagFeedbackService::class)->record([
            'retrieval_receipt_id' => $receiptId,
            'flow_id' => 'aobg.source_mix',
            'query_plan_hash' => hash('sha256', $receiptId),
            'included_sources' => 4,
            'used_sources' => 2,
            'noise_sources' => 0,
            'missed_required_sources' => [],
            'context_sufficiency' => 82,
            'post_execution_utility' => 78,
            'source_utility' => [],
            'outcome_status' => 'passed',
            'payload' => [
                'schema_version' => 'atlas.aucri.retrieval_feedback_loop.v1',
                'context_roi' => [
                    'roi_score' => 0.62,
                    'use_ratio' => 0.50,
                    'quality_band' => 'mixed',
                    'context_sufficiency' => 82,
                    'post_execution_utility' => 78,
                ],
                'context_ref_attribution' => [
                    'delivered_count' => 4,
                    'used_count' => 2,
                    'unused_count' => 2,
                    'noise_count' => 0,
                    'use_ratio' => 0.50,
                    'waste_ratio' => 0.50,
                    'delivered_refs' => [
                        ['ref' => 'code:used-1', 'source_type' => 'code_intelligence'],
                        ['ref' => 'code:used-2', 'source_type' => 'code_intelligence'],
                        ['ref' => 'memory:unused-1', 'source_type' => 'memory_signals'],
                        ['ref' => 'memory:unused-2', 'source_type' => 'memory_signals'],
                    ],
                    'used_refs' => [
                        ['ref' => 'code:used-1', 'source_type' => 'code_intelligence'],
                        ['ref' => 'code:used-2', 'source_type' => 'code_intelligence'],
                    ],
                    'unused_refs' => [
                        ['ref' => 'memory:unused-1', 'source_type' => 'memory_signals'],
                        ['ref' => 'memory:unused-2', 'source_type' => 'memory_signals'],
                    ],
                    'noise_refs' => [],
                    'missing_source_types' => [],
                ],
                'next_context_policy' => [
                    'actions' => ['shrink_initial_context'],
                    'next_initial_budget_multiplier' => 0.75,
                    'expand_source_types' => [],
                    'defer_sections' => ['memory'],
                    'demote_context_refs' => [],
                    'auto_apply' => false,
                ],
                'raw_text_exposed' => false,
            ],
        ]);
    }

    private function recordDemotionFeedback(string $receiptId): void
    {
        app(AtlasRagFeedbackService::class)->record([
            'retrieval_receipt_id' => $receiptId,
            'flow_id' => 'aobg.demote',
            'query_plan_hash' => hash('sha256', $receiptId),
            'included_sources' => 2,
            'used_sources' => 1,
            'noise_sources' => 1,
            'missed_required_sources' => [],
            'context_sufficiency' => 80,
            'post_execution_utility' => 60,
            'source_utility' => [],
            'outcome_status' => 'partial',
            'payload' => [
                'schema_version' => 'atlas.aucri.retrieval_feedback_loop.v1',
                'context_ref_attribution' => [
                    'delivered_refs' => [
                        ['ref' => 'app:useful', 'source_type' => 'code_intelligence'],
                        ['ref' => 'tools:rivals', 'source_type' => 'code_intelligence'],
                    ],
                    'used_refs' => [
                        ['ref' => 'app:useful', 'source_type' => 'code_intelligence'],
                    ],
                    'noise_refs' => [
                        ['ref' => 'tools:rivals', 'source_type' => 'code_intelligence'],
                    ],
                    'use_ratio' => 0.50,
                    'waste_ratio' => 0.50,
                    'noise_count' => 1,
                ],
                'next_context_policy' => [
                    'actions' => ['demote_noise_context_refs'],
                    'demote_context_refs' => [
                        'app/Services/Ai/Noisy/ContextRequirements.php::ContextRequirements',
                    ],
                    'auto_apply' => false,
                ],
                'raw_text_exposed' => false,
            ],
        ]);
    }

    private function recordReadinessOnlyFeedback(string $receiptId): void
    {
        app(AtlasRagFeedbackService::class)->record([
            'retrieval_receipt_id' => $receiptId,
            'flow_id' => 'aobg.readiness',
            'query_plan_hash' => hash('sha256', $receiptId),
            'included_sources' => 2,
            'used_sources' => 0,
            'noise_sources' => 0,
            'missed_required_sources' => [],
            'context_sufficiency' => 70,
            'post_execution_utility' => 70,
            'source_utility' => [],
            'outcome_status' => 'ready_for_provider',
            'payload' => [
                'schema_version' => 'atlas.aucri.retrieval_feedback_loop.v1',
                'raw_text_exposed' => false,
            ],
        ]);
    }

    private function makeTempDir(string $suffix): string
    {
        $root = sys_get_temp_dir().'/atlas-aobg-pack-test-'.getmypid();
        $dir = $root.'/'.$suffix;
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if (! in_array($root, $this->tempDirs, true)) {
            $this->tempDirs[] = $root;
        }

        return $dir;
    }

    private function makeGitFolder(string $path): string
    {
        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }
        if (! is_dir($path.'/.git')) {
            mkdir($path.'/.git', 0775, true);
        }

        return $path;
    }

    /**
     * @return array<string,mixed>
     */
    private function workspaceProfile(string $slug, string $path): array
    {
        return [
            'slug' => $slug,
            'name' => Str::headline($slug),
            'kind' => 'test',
            'workspace_path' => $path,
            'repo_root' => $path,
            'production_status' => 'development',
            'stack_summary' => 'Test workspace',
            'commands' => [],
            'test_commands' => [],
            'build_commands' => [],
            'critical_areas' => [],
            'docs_status' => 'test',
            'default_risk' => 'medium',
            'surfaces_enabled' => ['atlas_ai', 'code'],
        ];
    }
}
