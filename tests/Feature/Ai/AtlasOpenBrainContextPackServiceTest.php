<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Compounding\AtlasRagFeedbackService;
use App\Services\Ai\AtlasOpenBrainContextExpansionService;
use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\AtlasOpenBrainMcpService;
use App\Services\Engineering\CodeGraph\CodeGraphContextRetriever;
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

        // A generous budget admits more (proves the cap is what bounded it above).
        $generous = $this->service()->packFor('embedding decision', ['memory_budget' => 5000]);
        $this->assertGreaterThan(count($pack['memory']), count($generous['memory']));

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
        for ($i = 0; $i < 30; $i++) {
            $this->seedCodeSymbol('CodeGraphEmbeddingDecisionResolverNumber'.$i, 'atlas-server');
        }
        for ($i = 0; $i < 6; $i++) {
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

    public function test_workspace_scoping_never_leaks_another_workspace(): void
    {
        // The primary workspace resolves to the default id (base_path → 'atlas-server');
        // a SECOND project path resolves to its own distinct id. Seed each project's
        // symbol under the id the resolver will compute, then prove no cross-leak.
        $identity = $this->app->make(\App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity::class);
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

    private function seedMemory(string $sourceId, string $title, bool $providerSafe, string $privacyClass): void
    {
        AtlasMemoryEntry::create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => $title,
            'summary' => $providerSafe ? 'safe note summary' : 'secret vault key summary',
            'body' => $providerSafe ? 'safe note body about the embedding decision' : 'secret vault key material',
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
                        ['ref' => 'code:used-1', 'source_type' => 'code'],
                        ['ref' => 'code:used-2', 'source_type' => 'code'],
                        ['ref' => 'memory:unused-1', 'source_type' => 'memory'],
                        ['ref' => 'memory:unused-2', 'source_type' => 'memory'],
                    ],
                    'used_refs' => [
                        ['ref' => 'code:used-1', 'source_type' => 'code'],
                        ['ref' => 'code:used-2', 'source_type' => 'code'],
                    ],
                    'unused_refs' => [
                        ['ref' => 'memory:unused-1', 'source_type' => 'memory'],
                        ['ref' => 'memory:unused-2', 'source_type' => 'memory'],
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
