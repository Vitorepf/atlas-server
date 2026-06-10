<?php

declare(strict_types=1);

namespace Tests\Feature\Reality;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasRealityEntity;
use App\Models\AtlasRealityRelationship;
use App\Models\AtlasVerbatimMemory;
use App\Services\Ai\AtlasMemoryPrivacyService;
use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
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

    private string $evidenceProvingMemoryId;

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

        // domains: the 21 canonical domains + real mesh allowed-crossing edges.
        $this->assertSame(21, $stats['sources']['domains']['nodes']);
        $this->assertGreaterThan(0, $stats['sources']['domains']['edges']);

        // evidence: refs only (2 recent rows).
        $this->assertSame(2, $stats['sources']['evidence']['nodes']);

        // strategic: expired valid_until row skipped → 2 of 3 entities; 1 relationship edge.
        $this->assertSame(2, $stats['sources']['strategic']['nodes']);
        $this->assertSame(1, $stats['sources']['strategic']['edges']);
        $this->assertSame(0, AtlasAurgNode::query()->where('source_kind', 'strategic')->where('label', 'like', '%Expired%')->count());

        // Brain stays compact: refs, not copies.
        $this->assertSame(
            3 + 3 + 21 + 2 + 2,
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
        $this->assertEmpty(array_intersect(['summary', 'command', 'output_excerpt', 'artifact_url'], $evidenceMeta));
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
            ->where('from_node_id', 'evidence:evidence:'.$this->evidenceProvingMemoryId)
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

        // Evidence ledger table: created manually — the real migration's trigger
        // statement is pgsql-only syntax without a driver guard.
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

        // --- evidence (refs only) ---
        $this->evidenceProvingMemoryId = (string) Str::uuid();
        DB::table('atlas_engineering_evidence')->insert([
            'id' => $this->evidenceProvingMemoryId,
            'task_id' => (string) Str::uuid(),
            'evidence_type' => 'test_run',
            'target_id' => $this->safeMemoryId,
            'status' => 'passed',
            'summary' => 'payload that must NOT enter the brain',
            'files' => json_encode(['app/Services/Ai/Reality/AtlasRealityGraphIngestionService.php']),
            'metadata' => '{}',
            'recorded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('atlas_engineering_evidence')->insert([
            'id' => (string) Str::uuid(),
            'task_id' => (string) Str::uuid(),
            'evidence_type' => 'lint',
            'target_id' => null,
            'status' => 'passed',
            'summary' => 'another payload that must NOT enter the brain',
            'files' => '[]',
            'metadata' => '{}',
            'recorded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
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
