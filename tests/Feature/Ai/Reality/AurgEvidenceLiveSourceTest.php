<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Reality;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Models\AtlasLedgerEvent;
use App\Models\AtlasMemoryEntry;
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
 * RAG-09 — evidence layer reads the live Evidence Ledger, not atlas_engineering_evidence.
 */
final class AurgEvidenceLiveSourceTest extends TestCase
{
    private string $ledgerEventId;

    private string $memoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootTables();
        config()->set('atlas.aurg.enabled', true);
        $this->seedSources();
    }

    public function test_gather_evidence_uses_live_ledger_and_not_dead_engineering_table(): void
    {
        DB::enableQueryLog();

        $stats = $this->service()->sync(['memory', 'code', 'evidence']);

        $sql = collect(DB::getQueryLog())
            ->pluck('query')
            ->implode("\n");

        $this->assertStringNotContainsString('atlas_engineering_evidence', strtolower($sql));
        $this->assertStringContainsString('atlas_ledger_events', strtolower($sql));

        $this->assertSame(1, $stats['sources']['evidence']['nodes']);
        $this->assertGreaterThanOrEqual(1, $stats['linkers']['evidence_links']);

        $evidenceNode = AtlasAurgNode::query()
            ->where('source_kind', 'evidence')
            ->where('source_id', $this->ledgerEventId)
            ->first();
        $this->assertNotNull($evidenceNode);
        $this->assertSame('atlas_ledger_events', $evidenceNode->meta['ledger_source'] ?? null);

        $memoryNodeId = 'memory:memory_entry:'.$this->memoryId;
        $this->assertTrue(
            AtlasAurgEdge::query()
                ->where('from_node_id', $evidenceNode->id)
                ->where('to_node_id', $memoryNodeId)
                ->where('source', 'linker_evidence')
                ->exists(),
        );

        $this->assertTrue(
            AtlasAurgEdge::query()
                ->where('from_node_id', $evidenceNode->id)
                ->where('source', 'linker_evidence')
                ->where('kind', 'proves')
                ->where('to_node_id', 'code:module:atlas-server/services-ai-reality')
                ->exists(),
        );
    }

    private function service(): AtlasRealityGraphIngestionService
    {
        return new AtlasRealityGraphIngestionService(
            new CrossDomainTaxonomyMap,
            app(\App\Services\Ai\AtlasMemoryPrivacyService::class),
            app(AtlasCrossDomainMeshService::class),
        );
    }

    private function bootTables(): void
    {
        foreach ([
            'migrations/2026_05_02_000000_create_atlas_memory_entries_table.php',
            'migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php',
            'migrations/2026_06_09_120000_create_atlas_aurg_graph_tables.php',
            'migrations/2026_05_05_020000_create_atlas_ledger_events_table.php',
        ] as $file) {
            $migration = require database_path($file);
            $migration->down();
            $migration->up();
        }

        (require database_path('migrations/2026_05_19_050000_extend_atlas_ledger_events_with_timeline_fields.php'))->up();
        (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_02_005000_add_privacy_columns_to_atlas_memory_entries.php'))->up();

        Schema::dropIfExists('atlas_engineering_evidence');
        Schema::create('atlas_engineering_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id')->index();
            $table->string('evidence_type', 80);
            $table->string('target_id', 120)->nullable();
            $table->string('status', 32);
            $table->text('summary');
            $table->json('files')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();
        });
    }

    private function seedSources(): void
    {
        $memory = AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Evidence ledger feeds AURG',
            'body' => 'Live evidence source.',
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'status' => 'active',
            'source_type' => 'manual',
            'metadata' => [],
            'tags' => [],
            'recorded_at' => now(),
        ]);
        $this->memoryId = (string) $memory->id;

        DB::table('atlas_engineering_code_modules')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => 'atlas-server',
            'slug' => 'services-ai-reality',
            'name' => 'Ai Reality',
            'layer' => 'services',
            'root_path' => 'app/Services/Ai/Reality',
            'status' => 'active',
            'source_hash' => hash('sha256', 'services-ai-reality'),
            'tags_json' => '[]',
            'related_docs_json' => '[]',
            'related_tests_json' => '[]',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('atlas_engineering_evidence')->insert([
            'id' => (string) Str::uuid(),
            'task_id' => (string) Str::uuid(),
            'evidence_type' => 'dead_table_row',
            'target_id' => $this->memoryId,
            'status' => 'passed',
            'summary' => 'must never be ingested',
            'files' => json_encode(['app/Services/Ai/Reality/AtlasRealityGraphIngestionService.php']),
            'metadata' => '{}',
            'recorded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ledgerEventId = (string) Str::ulid();
        AtlasLedgerEvent::query()->create([
            'event_id' => $this->ledgerEventId,
            'schema_version' => AtlasEvidenceLedger::SCHEMA_VERSION,
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'aurg-rag09',
            'receipt_id' => null,
            'trace_id' => 'trace-rag09',
            'correlation_id' => 'corr-rag09',
            'causation_id' => null,
            'event_type' => 'LOCAL_RAG_BENCHMARK',
            'emitter_stage' => 'atlas.context_retrieval',
            'emitter_version' => 'atlas.local_rag.v1',
            'payload' => [
                'target_id' => $this->memoryId,
                'files' => ['app/Services/Ai/Reality/AtlasRealityGraphIngestionService.php'],
            ],
            'payload_hash' => hash('sha256', 'rag09-live-ledger'),
            'scope_type' => 'memory_entry',
            'scope_id' => $this->memoryId,
            'occurred_at' => now(),
        ]);
    }
}
