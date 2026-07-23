<?php

declare(strict_types=1);

namespace Tests\Feature\Reality;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Models\AtlasLedgerEvent;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasRealityEntity;
use App\Models\AtlasRealityRelationship;
use App\Models\AtlasVerbatimMemory;
use App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService;
use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AURG Phase-2 / F1 — fused store + ingestion (Salto 1 "AURG vivo").
 *
 * Locks the F1 contract:
 *  - bounded per-source projections land as compact refs (counts correct);
 *  - idempotent re-run (no duplicate nodes/edges);
 *  - --prune removes only vanished rows of the synced source kind;
 *  - deterministic cite-or-omit cross-layer linkers (references/proves/belongs_to)
 *    with the documented confidence ladder (1.0 exact, 0.7 derived);
 *  - privacy structure: non-provider-safe memory → provider_safe=false node with a
 *    REDACTED label only; blocked verbatims skipped ENTIRELY; sensitive domains
 *    provider_safe=false.
 *
 * Boots only the needed tables in setUp (the proven house pattern — RefreshDatabase
 * is unreliable for these); evidence table is created manually because the real
 * migration's trigger statement is pgsql-only syntax without a driver guard.
 */
final class AtlasAurgIngestionTest extends TestCase
{
    private string $safeMemoryId;

    private string $secretMemoryId;

    private string $allowedVerbatimId;

    private string $blockedVerbatimId;

    private string $moduleRealityUuid;

    private string $evidenceProvingEventId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootTables();
        config()->set('atlas.aurg.enabled', true);
        $this->seedSources();
    }

    public function test_full_ingest_creates_bounded_provider_safe_refs_per_source(): void
    {
        $stats = $this->service()->sync();

        // memory: 2 entries + 1 allowed verbatim; the blocked verbatim is SKIPPED ENTIRELY.
        $this->assertSame(3, $stats['sources']['memory']['nodes']);
        $this->assertSame(0, AtlasAurgNode::query()->where('source_id', $this->blockedVerbatimId)->count());

        // code: 1 workspace + 2 modules, belongs_to edges from the same indexed rows.
        $this->assertSame(3, $stats['sources']['code']['nodes']);
        $this->assertSame(2, $stats['sources']['code']['edges']);

        // docs: canonical engineering knowledge docs are now first-class refs.
        $this->assertGreaterThanOrEqual(300, $stats['sources']['docs']['nodes']);

        // domains: the 21 canonical domains + real mesh allowed-crossing edges.
        $this->assertSame(21, $stats['sources']['domains']['nodes']);
        $this->assertGreaterThan(0, $stats['sources']['domains']['edges']);

        // evidence: refs only (2 recent ledger events).
        $this->assertSame(2, $stats['sources']['evidence']['nodes']);

        // strategic: expired valid_until row skipped → 2 of 3 entities; 1 relationship edge.
        $this->assertSame(2, $stats['sources']['strategic']['nodes']);
        $this->assertSame(1, $stats['sources']['strategic']['edges']);
        $this->assertSame(0, AtlasAurgNode::query()->where('source_kind', 'strategic')->where('label', 'like', '%Expired%')->count());

        // Brain stays compact: refs, not copies.
        $this->assertSame(
            3 + 3 + $stats['sources']['docs']['nodes'] + 21 + 2 + 2,
            (int) AtlasAurgNode::query()->count(),
        );

        // Evidence nodes carry ids/hashes only — never payload fields.
        $evidenceMeta = AtlasAurgNode::query()
            ->where('source_kind', 'evidence')
            ->get()
            ->map(fn (AtlasAurgNode $n): array => array_keys((array) $n->meta))
            ->flatten()
            ->unique()
            ->all();
        $this->assertEmpty(array_intersect(['summary', 'command', 'output_excerpt', 'artifact_url', 'payload'], $evidenceMeta));
    }

    public function test_cross_layer_linkers_are_deterministic_cite_or_omit(): void
    {
        $stats = $this->service()->sync();

        // (a) memory→code 'references': exact metadata path === module root_path → 1.0.
        $memoryNodeId = 'memory:memory_entry:'.$this->safeMemoryId;
        $moduleNodeId = 'code:module:atlas-server/services-ai-reality';
        $exact = AtlasAurgEdge::query()
            ->where('from_node_id', $memoryNodeId)
            ->where('to_node_id', $moduleNodeId)
            ->where('kind', 'references')
            ->first();
        $this->assertNotNull($exact, 'expected memory→module references edge');
        $this->assertSame(1.0, (float) $exact->confidence);
        $this->assertSame('app/Services/Ai/Reality', $exact->meta['matched_path'] ?? null);

        // path UNDER a module root → derived 0.7 (second memory path cites a file).
        $derived = AtlasAurgEdge::query()
            ->where('from_node_id', $memoryNodeId)
            ->where('to_node_id', 'code:module:atlas-server/services-engineering-codegraph')
            ->where('kind', 'references')
            ->first();
        $this->assertNotNull($derived, 'expected derived path-prefix references edge');
        $this->assertSame(0.7, (float) $derived->confidence);

        // (b) memory→domain 'belongs_to' via taxonomy-resolved tag (registry alias
        // "programming" resolves to canonical "engineering") → 1.0 exact resolution.
        $domainEdge = AtlasAurgEdge::query()
            ->where('from_node_id', $memoryNodeId)
            ->where('to_node_id', 'domain:domain:engineering')
            ->where('kind', 'belongs_to')
            ->first();
        $this->assertNotNull($domainEdge, 'expected memory→engineering belongs_to edge');
        $this->assertSame(1.0, (float) $domainEdge->confidence);

        // (c) evidence→memory 'proves' via exact target_id match → 1.0.
        $proves = AtlasAurgEdge::query()
            ->where('from_node_id', 'evidence:evidence:'.$this->evidenceProvingEventId)
            ->where('to_node_id', $memoryNodeId)
            ->where('kind', 'proves')
            ->first();
        $this->assertNotNull($proves, 'expected evidence→memory proves edge');
        $this->assertSame(1.0, (float) $proves->confidence);
        $this->assertSame('target_id', $proves->meta['matched'] ?? null);

        // (d) workspace→engineering domain.
        $this->assertSame(1, $stats['linkers']['code_domain']);
        $this->assertTrue(
            AtlasAurgEdge::query()
                ->where('from_node_id', 'code:workspace:atlas-server')
                ->where('to_node_id', 'domain:domain:engineering')
                ->where('kind', 'belongs_to')
                ->exists(),
        );

        // Cite-or-omit: only the documented ladder values ever appear.
        $confidences = AtlasAurgEdge::query()->pluck('confidence')
            ->map(fn ($c): float => round((float) $c, 3))->unique()->sort()->values()->all();
        $this->assertSame([], array_diff($confidences, [0.7, 1.0]));
    }

    public function test_provider_safe_explicit_path_in_memory_projection_links_to_code(): void
    {
        $entry = AtlasMemoryEntry::query()->create([
            'memory_type' => 'technical_context',
            'scope_type' => 'global',
            'title' => 'Reality ingestion owner',
            'summary' => 'The owner is explicit in the provider-safe projection.',
            'body' => 'See app/Services/Ai/Reality/AtlasRealityGraphIngestionService.php for the runtime.',
            'redacted_title' => 'Reality ingestion owner',
            'redacted_summary' => 'The owner is explicit in the provider-safe projection.',
            'redacted_body' => 'See app/Services/Ai/Reality/AtlasRealityGraphIngestionService.php for the runtime.',
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'status' => 'active',
            'source_type' => 'manual',
            'metadata' => [],
            'tags' => [],
            'recorded_at' => now(),
        ]);

        $this->service()->sync();

        $edge = AtlasAurgEdge::query()
            ->where('from_node_id', 'memory:memory_entry:'.$entry->id)
            ->where('to_node_id', 'code:module:atlas-server/services-ai-reality')
            ->where('source', 'linker_memory_code')
            ->first();
        $this->assertNotNull($edge);
        $this->assertSame(0.7, (float) $edge->confidence);
        $this->assertSame(
            'app/Services/Ai/Reality/AtlasRealityGraphIngestionService.php',
            $edge->meta['matched_path'] ?? null,
        );
    }

    public function test_doc_code_index_imports_current_links_aggregated_to_module_edges(): void
    {
        $docPath = 'docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md';
        $moduleId = (string) DB::table('atlas_engineering_code_modules')
            ->where('slug', 'services-ai-reality')
            ->value('id');
        $symbolId = (string) Str::uuid();
        DB::table('atlas_engineering_code_symbols')->insert([
            'id' => $symbolId,
            'workspace_id' => 'atlas-server',
            'module_id' => $moduleId,
            'symbol_type' => 'class',
            'symbol_name' => 'AtlasRealityGraphIngestionService',
            'file_path' => 'app/Services/Ai/Reality/AtlasRealityGraphIngestionService.php',
            'language' => 'php',
            'status' => 'active',
            'source_hash' => hash('sha256', 'maxd01-symbol'),
            'related_doc_ids_json' => '[]',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([
            ['kind' => 'module_path', 'module_id' => $moduleId, 'symbol_id' => null, 'hash' => 'maxd01-direct'],
            ['kind' => 'symbol_path', 'module_id' => null, 'symbol_id' => $symbolId, 'hash' => 'maxd01-symbol'],
        ] as $row) {
            DB::table('atlas_engineering_doc_links')->insert([
                'id' => (string) Str::uuid(),
                'workspace_id' => 'atlas-server',
                'knowledge_item_id' => null,
                'module_id' => $row['module_id'],
                'symbol_id' => $row['symbol_id'],
                'link_type' => $row['kind'],
                'status' => 'current',
                'canonical_path' => $docPath,
                'target_path' => 'app/Services/Ai/Reality/AtlasRealityGraphIngestionService.php',
                'doc_hash' => hash('sha256', $docPath),
                'target_hash' => hash('sha256', 'app/Services/Ai/Reality/AtlasRealityGraphIngestionService.php'),
                'link_hash' => hash('sha256', $row['hash']),
                'metadata' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('atlas_engineering_doc_links')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => 'other-workspace',
            'knowledge_item_id' => null,
            'module_id' => $moduleId,
            'symbol_id' => null,
            'link_type' => 'module_path',
            'status' => 'current',
            'canonical_path' => $docPath,
            'target_path' => 'app/Services/Ai/Reality/AtlasRealityGraphIngestionService.php',
            'doc_hash' => hash('sha256', $docPath),
            'target_hash' => hash('sha256', 'other'),
            'link_hash' => hash('sha256', 'maxd01-other-workspace'),
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stats = $this->service()->sync(['docs', 'code']);

        $this->assertSame(1, $stats['linkers']['doc_code_index']);
        $edge = AtlasAurgEdge::query()
            ->where('from_node_id', 'doc:doc:'.$docPath)
            ->where('to_node_id', 'code:module:atlas-server/services-ai-reality')
            ->where('kind', 'references')
            ->where('source', 'linker_doc_code_index')
            ->first();
        $this->assertNotNull($edge);
        $this->assertSame(1.0, (float) $edge->confidence);
        $this->assertSame(2, $edge->meta['link_count'] ?? null);
        $this->assertSame(
            min(hash('sha256', 'maxd01-direct'), hash('sha256', 'maxd01-symbol')),
            $edge->meta['sample_link_hash'] ?? null,
        );
    }

    public function test_docs_authority_graph_imports_doc_to_doc_edges_cite_or_omit(): void
    {
        $sourceDoc = 'docs/engineering-knowledge-base/atlas-acos-max-execution-scoreboard-v1.md';
        $ownerDoc = 'docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md';
        DB::table('atlas_docs_authority_graph')->insert([
            [
                'needle_kind' => 'doc_path',
                'needle' => $sourceDoc,
                'needle_normalized' => mb_strtolower($sourceDoc),
                'owner_doc_path' => $ownerDoc,
                'owner_doc_id' => 'acos-max-frontier',
                'owner_basis' => 'doc_path_fixture',
                'confidence' => 100,
                'owner_implementation_state' => 'current',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'needle_kind' => 'doc_path',
                'needle' => $ownerDoc,
                'needle_normalized' => mb_strtolower($ownerDoc),
                'owner_doc_path' => $ownerDoc,
                'owner_doc_id' => 'self-loop',
                'owner_basis' => 'doc_path_fixture',
                'confidence' => 100,
                'owner_implementation_state' => 'current',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'needle_kind' => 'doc_path',
                'needle' => 'docs/engineering-knowledge-base/not-ingested.md',
                'needle_normalized' => 'docs/engineering-knowledge-base/not-ingested.md',
                'owner_doc_path' => $ownerDoc,
                'owner_doc_id' => 'missing-source',
                'owner_basis' => 'doc_path_fixture',
                'confidence' => 100,
                'owner_implementation_state' => 'current',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $stats = $this->service()->sync(['docs']);

        $this->assertSame(1, $stats['linkers']['doc_authority']);
        $edge = AtlasAurgEdge::query()
            ->where('from_node_id', 'doc:doc:'.$sourceDoc)
            ->where('to_node_id', 'doc:doc:'.$ownerDoc)
            ->where('kind', 'references')
            ->where('source', 'linker_doc_authority')
            ->first();
        $this->assertNotNull($edge);
        $this->assertSame(1.0, (float) $edge->confidence);
        $this->assertSame('doc_path', $edge->meta['needle_kind'] ?? null);
        $this->assertSame('doc_path_fixture', $edge->meta['owner_basis'] ?? null);
        $this->assertFalse(
            AtlasAurgEdge::query()
                ->where('from_node_id', 'doc:doc:'.$ownerDoc)
                ->where('to_node_id', 'doc:doc:'.$ownerDoc)
                ->where('source', 'linker_doc_authority')
                ->exists(),
            'authority import must omit self-links',
        );
    }

    public function test_rerun_is_idempotent_no_duplicates(): void
    {
        $service = $this->service();
        $service->sync();
        $nodes = (int) AtlasAurgNode::query()->count();
        $edges = (int) AtlasAurgEdge::query()->count();

        $service->sync();

        $this->assertSame($nodes, (int) AtlasAurgNode::query()->count());
        $this->assertSame($edges, (int) AtlasAurgEdge::query()->count());
    }

    public function test_docs_ingest_skips_unchanged_files_on_consecutive_sync(): void
    {
        $service = $this->service();
        $first = $service->sync(['docs']);

        $second = $service->sync(['docs']);

        $this->assertGreaterThan(0, $first['sources']['docs']['nodes']);
        $this->assertSame(0, $second['sources']['docs']['nodes']);
        $this->assertGreaterThanOrEqual($first['sources']['docs']['nodes'], $second['sources']['docs']['skipped_unchanged']);
    }

    public function test_evidence_prune_keeps_linked_evidence_nodes_outside_recent_window(): void
    {
        $service = $this->service();
        $service->sync(['memory']);
        $staleEvidence = 'evidence:evidence:stale-linked-maxd08';
        $memoryNode = 'memory:memory_entry:'.$this->safeMemoryId;
        AtlasAurgNode::query()->create([
            'id' => $staleEvidence,
            'kind' => 'evidence',
            'source_kind' => 'evidence',
            'source_id' => 'stale-linked-maxd08',
            'label' => 'STALE_LINKED',
            'workspace_id' => null,
            'provider_safe' => true,
            'sensitive' => false,
            'meta' => ['payload_hash' => hash('sha256', 'stale-linked-maxd08')],
            'content_hash' => hash('sha256', 'stale-linked-maxd08'),
        ]);
        AtlasAurgEdge::query()->create([
            'from_node_id' => $staleEvidence,
            'to_node_id' => $memoryNode,
            'kind' => 'proves',
            'source' => 'linker_evidence',
            'confidence' => 1.0,
            'meta' => ['matched' => 'fixture'],
        ]);

        $stats = $service->sync(['evidence'], prune: true);

        $this->assertTrue(AtlasAurgNode::query()->whereKey($staleEvidence)->exists());
        $this->assertTrue(AtlasAurgEdge::query()->where('from_node_id', $staleEvidence)->exists());
        $this->assertSame(1, $stats['pruned']['evidence_kept_linked']);
    }

    public function test_prune_removes_vanished_rows_scoped_to_source_kind(): void
    {
        $service = $this->service();
        $service->sync();

        $moduleNodeId = 'code:module:atlas-server/services-ai-reality';
        $this->assertTrue(AtlasAurgNode::query()->whereKey($moduleNodeId)->exists());
        $memoryNodesBefore = (int) AtlasAurgNode::query()->where('source_kind', 'memory')->count();
        $domainNodesBefore = (int) AtlasAurgNode::query()->where('source_kind', 'domain')->count();

        // Source row vanishes; prune only the code layer.
        DB::table('atlas_engineering_code_modules')->where('slug', 'services-ai-reality')->delete();
        $stats = $service->sync(['code'], prune: true);

        $this->assertSame(1, $stats['pruned']['nodes']);
        $this->assertFalse(AtlasAurgNode::query()->whereKey($moduleNodeId)->exists());
        // Edges referencing the vanished node are swept with it.
        $this->assertSame(0, AtlasAurgEdge::query()
            ->where('from_node_id', $moduleNodeId)
            ->orWhere('to_node_id', $moduleNodeId)
            ->count());
        // Other source kinds untouched.
        $this->assertSame($memoryNodesBefore, (int) AtlasAurgNode::query()->where('source_kind', 'memory')->count());
        $this->assertSame($domainNodesBefore, (int) AtlasAurgNode::query()->where('source_kind', 'domain')->count());
    }

    public function test_privacy_flags_and_redaction_are_structural(): void
    {
        $this->service()->sync();

        // Non-provider-safe memory → node EXISTS but provider_safe=false + sensitive,
        // and its label is the redacted projection (the secret value never lands).
        $secretNode = AtlasAurgNode::query()->where('source_id', $this->secretMemoryId)->first();
        $this->assertNotNull($secretNode);
        $this->assertFalse((bool) $secretNode->provider_safe);
        $this->assertTrue((bool) $secretNode->sensitive);
        $this->assertStringNotContainsString('hunter2supersecret', (string) $secretNode->label);
        $this->assertStringContainsString('[redacted]', (string) $secretNode->label);

        // Provider-safe memory keeps provider_safe=true.
        $safeNode = AtlasAurgNode::query()->where('source_id', $this->safeMemoryId)->first();
        $this->assertNotNull($safeNode);
        $this->assertTrue((bool) $safeNode->provider_safe);
        $this->assertFalse((bool) $safeNode->sensitive);

        // Sensitive canonical domains are structurally provider-unsafe (never promptable).
        $finance = AtlasAurgNode::query()->whereKey('domain:domain:finance')->first();
        $this->assertNotNull($finance);
        $this->assertFalse((bool) $finance->provider_safe);
        $this->assertTrue((bool) $finance->sensitive);

        $engineering = AtlasAurgNode::query()->whereKey('domain:domain:engineering')->first();
        $this->assertNotNull($engineering);
        $this->assertTrue((bool) $engineering->provider_safe);
        $this->assertFalse((bool) $engineering->sensitive);
    }

    public function test_command_runs_with_json_stats_and_source_filter(): void
    {
        $this->artisan('atlas:aurg:ingest', ['--source' => 'domains', '--json' => true])
            ->assertSuccessful();

        // Only the domain layer was synced.
        $this->assertSame(21, (int) AtlasAurgNode::query()->where('source_kind', 'domain')->count());
        $this->assertSame(0, (int) AtlasAurgNode::query()->where('source_kind', 'memory')->count());

        // Invalid source fails fast.
        $this->artisan('atlas:aurg:ingest', ['--source' => 'nonsense'])->assertFailed();

        // Disabled flag short-circuits without writing.
        AtlasAurgNode::query()->delete();
        config()->set('atlas.aurg.enabled', false);
        $this->artisan('atlas:aurg:ingest')->assertSuccessful();
        $this->assertSame(0, (int) AtlasAurgNode::query()->count());
    }

    // ------------------------------------------------------------------
    // fixtures
    // ------------------------------------------------------------------

    private function service(): AtlasRealityGraphIngestionService
    {
        return new AtlasRealityGraphIngestionService(
            new CrossDomainTaxonomyMap,
            app(AtlasMemoryPrivacyService::class),
            app(AtlasCrossDomainMeshService::class),
        );
    }

    private function bootTables(): void
    {
        foreach ([
            'migrations/2026_05_02_000000_create_atlas_memory_entries_table.php',
            'migrations/2026_05_02_004000_create_atlas_verbatim_memories_table.php',
            'migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php',
            'migrations/2026_05_31_210000_create_atlas_docs_authority_graph_table.php',
            'migrations/2026_05_20_150000_create_atlas_strategic_reality_tables.php',
            'migrations/2026_06_09_120000_create_atlas_aurg_graph_tables.php',
        ] as $file) {
            $migration = require database_path($file);
            $migration->down();
            $migration->up();
        }

        // Additive memory columns (privacy + superseded pointer; FK-free on sqlite).
        (require database_path('migrations/2026_05_02_005000_add_privacy_columns_to_atlas_memory_entries.php'))->up();
        if (! Schema::hasColumn('atlas_memory_entries', 'superseded_by_id')) {
            Schema::table('atlas_memory_entries', function (Blueprint $table): void {
                $table->uuid('superseded_by_id')->nullable();
            });
        }

        // Workspace keying for code-intelligence tables (idempotent, cross-driver).
        (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();

        // Edge model now stamps temporal validity columns.
        (require database_path('migrations/2026_07_07_181500_add_temporal_truth_to_atlas_aurg_edges.php'))->up();

        // Evidence ledger table (live source for RAG-09).
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_19_050000_extend_atlas_ledger_events_with_timeline_fields.php'))->up();

        // Dead engineering evidence table kept only to prove ingest no longer reads it.
        Schema::dropIfExists('atlas_engineering_evidence');
        Schema::create('atlas_engineering_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id')->index();
            $table->uuid('project_id')->nullable()->index();
            $table->uuid('trace_id')->nullable()->index();
            $table->string('evidence_type', 80);
            $table->string('target_id', 120)->nullable();
            $table->string('status', 32);
            $table->text('summary');
            $table->string('command', 500)->nullable();
            $table->text('output_excerpt')->nullable();
            $table->json('files')->default('[]');
            $table->json('metadata')->default('{}');
            $table->string('source', 160)->default('tasks.engineering.evidence');
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();
        });
    }

    private function seedSources(): void
    {
        // --- memory ---
        $safe = AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Reality graph ingestion lives in services-ai-reality',
            'body' => 'Fused store decision.',
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'tags' => ['programming'],
            'metadata' => [
                'paths' => [
                    'app/Services/Ai/Reality',
                    'app/Services/Engineering/CodeGraph/CodeGraphWorkspaceIdentity.php',
                ],
            ],
            'recorded_at' => now(),
        ]);
        $this->safeMemoryId = (string) $safe->id;

        $secret = AtlasMemoryEntry::query()->create([
            'memory_type' => 'technical_context',
            'scope_type' => 'global',
            'title' => 'Vault rotation password=hunter2supersecret today',
            'body' => 'Secret body that must never leave.',
            'privacy_class' => 'secret',
            'external_ai_allowed' => false,
            'recorded_at' => now(),
        ]);
        $this->secretMemoryId = (string) $secret->id;

        $allowedVerbatim = AtlasVerbatimMemory::query()->create([
            'memory_entry_id' => $this->safeMemoryId,
            'verbatim_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Operator approved the fused store',
            'verbatim_text' => 'aprovado',
            'redacted_text' => 'aprovado',
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'content_hash' => hash('sha256', 'aprovado'),
            'recorded_at' => now(),
        ]);
        $this->allowedVerbatimId = (string) $allowedVerbatim->id;

        $blockedVerbatim = AtlasVerbatimMemory::query()->create([
            'verbatim_type' => 'command',
            'scope_type' => 'global',
            'title' => 'Blocked verbatim must not become a node',
            'verbatim_text' => 'secret command',
            'redacted_text' => '[redacted]',
            'privacy_class' => 'secret',
            'external_ai_allowed' => false,
            'content_hash' => hash('sha256', 'secret command'),
            'recorded_at' => now(),
        ]);
        $this->blockedVerbatimId = (string) $blockedVerbatim->id;

        // --- code (bounded module projection) ---
        foreach ([
            ['slug' => 'services-ai-reality', 'name' => 'Ai Reality', 'root' => 'app/Services/Ai/Reality', 'symbols' => 40],
            ['slug' => 'services-engineering-codegraph', 'name' => 'Code Graph', 'root' => 'app/Services/Engineering/CodeGraph', 'symbols' => 90],
        ] as $module) {
            DB::table('atlas_engineering_code_modules')->insert([
                'id' => (string) Str::uuid(),
                'workspace_id' => 'atlas-server',
                'slug' => $module['slug'],
                'name' => $module['name'],
                'layer' => 'services',
                'root_path' => $module['root'],
                'status' => 'active',
                'source_hash' => hash('sha256', $module['slug']),
                'tags_json' => '[]',
                'related_docs_json' => '[]',
                'related_tests_json' => '[]',
                'metadata' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // --- evidence (live ledger refs only) ---
        $this->evidenceProvingEventId = (string) Str::ulid();
        AtlasLedgerEvent::query()->create([
            'event_id' => $this->evidenceProvingEventId,
            'schema_version' => AtlasEvidenceLedger::SCHEMA_VERSION,
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'aurg-ingest-test',
            'receipt_id' => null,
            'trace_id' => 'trace-proves-memory',
            'correlation_id' => 'corr-proves-memory',
            'causation_id' => null,
            'event_type' => 'TEST_RUN',
            'emitter_stage' => 'atlas.test',
            'emitter_version' => 'v1',
            'payload' => [
                'target_id' => $this->safeMemoryId,
                'files' => ['app/Services/Ai/Reality/AtlasRealityGraphIngestionService.php'],
            ],
            'payload_hash' => hash('sha256', 'evidence-proves-memory'),
            'scope_type' => 'memory_entry',
            'scope_id' => $this->safeMemoryId,
            'occurred_at' => now(),
        ]);
        AtlasLedgerEvent::query()->create([
            'event_id' => (string) Str::ulid(),
            'schema_version' => AtlasEvidenceLedger::SCHEMA_VERSION,
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'aurg-ingest-test-2',
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => 'corr-lint',
            'causation_id' => null,
            'event_type' => 'LINT',
            'emitter_stage' => 'atlas.test',
            'emitter_version' => 'v1',
            'payload' => ['files' => []],
            'payload_hash' => hash('sha256', 'evidence-lint'),
            'occurred_at' => now()->subSecond(),
        ]);

        // --- strategic (ASRE; one expired row honours the 14-day decay) ---
        $entityA = AtlasRealityEntity::query()->create([
            'status' => 'active',
            'entity_key' => 'operator-vitor',
            'entity_type' => 'operator',
            'name' => 'Operator',
            'observed_at' => now(),
            'valid_until' => now()->addDays(14),
            'entity_hash' => hash('sha256', 'operator'),
        ]);
        $entityB = AtlasRealityEntity::query()->create([
            'status' => 'active',
            'entity_key' => 'mission-aurg',
            'entity_type' => 'mission',
            'name' => 'AURG vivo',
            'observed_at' => now(),
            'valid_until' => null,
            'entity_hash' => hash('sha256', 'mission'),
        ]);
        AtlasRealityEntity::query()->create([
            'status' => 'active',
            'entity_key' => 'expired-entity',
            'entity_type' => 'reality_item',
            'name' => 'Expired strategic row',
            'observed_at' => now()->subDays(30),
            'valid_until' => now()->subDays(16),
            'entity_hash' => hash('sha256', 'expired'),
        ]);
        AtlasRealityRelationship::query()->create([
            'status' => 'active',
            'source_entity_id' => (string) $entityA->id,
            'target_entity_id' => (string) $entityB->id,
            'relationship_type' => 'operates_in',
            'weight' => 1,
            'relationship_hash' => hash('sha256', 'rel'),
        ]);
    }
}
