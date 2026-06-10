<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\AtlasOpenBrainMcpService;
use App\Services\Engineering\CodeGraph\CodeGraphContextRetriever;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
        Schema::dropIfExists('atlas_aurg_edges');
        Schema::dropIfExists('atlas_aurg_nodes');
        Schema::dropIfExists('atlas_engineering_code_symbols');
        $this->dropAtlasMemoryEntryTable();
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
}
