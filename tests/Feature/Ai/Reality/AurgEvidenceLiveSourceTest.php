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

    public function test_evidence_links_to_mission_and_obra_by_exact_receipt_trace_or_correlation_only(): void
    {
        AtlasAurgNode::query()->create([
            'id' => 'mission:mission:mission-maxd02',
            'kind' => 'mission',
            'source_kind' => 'mission',
            'source_id' => 'mission-maxd02',
            'label' => 'MAXD-02 governed mission',
            'workspace_id' => null,
            'provider_safe' => true,
            'sensitive' => false,
            'meta' => [
                'receipt' => 'receipt-maxd02-mission',
                'trace_ids' => ['trace-maxd02-mission'],
                'correlation_ids' => ['corr-maxd02-mission'],
            ],
            'content_hash' => hash('sha256', 'mission-maxd02'),
        ]);
        AtlasAurgNode::query()->create([
            'id' => 'obra:obra:obra-maxd02',
            'kind' => 'obra',
            'source_kind' => 'obra',
            'source_id' => 'obra-maxd02',
            'label' => 'MAXD-02 governed obra',
            'workspace_id' => null,
            'provider_safe' => true,
            'sensitive' => false,
            'meta' => [
                'receipt_hash' => 'receipt-maxd02-obra',
                'trace_id' => 'trace-maxd02-obra',
            ],
            'content_hash' => hash('sha256', 'obra-maxd02'),
        ]);

        $receiptEvent = (string) Str::ulid();
        AtlasLedgerEvent::query()->create([
            'event_id' => $receiptEvent,
            'schema_version' => AtlasEvidenceLedger::SCHEMA_VERSION,
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'maxd02-receipt',
            'receipt_id' => 'receipt-maxd02-mission',
            'trace_id' => 'trace-unmatched',
            'correlation_id' => 'corr-unmatched',
            'causation_id' => null,
            'event_type' => 'MAXD02_RECEIPT_MATCH',
            'emitter_stage' => 'atlas.test',
            'emitter_version' => 'v1',
            'payload' => [],
            'payload_hash' => hash('sha256', 'maxd02-receipt'),
            'occurred_at' => now()->addSecond(),
        ]);
        $traceEvent = (string) Str::ulid();
        AtlasLedgerEvent::query()->create([
            'event_id' => $traceEvent,
            'schema_version' => AtlasEvidenceLedger::SCHEMA_VERSION,
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'maxd02-trace',
            'receipt_id' => null,
            'trace_id' => 'trace-maxd02-obra',
            'correlation_id' => 'corr-unmatched-trace-event',
            'causation_id' => null,
            'event_type' => 'MAXD02_TRACE_MATCH',
            'emitter_stage' => 'atlas.test',
            'emitter_version' => 'v1',
            'payload' => [],
            'payload_hash' => hash('sha256', 'maxd02-trace'),
            'occurred_at' => now()->addSeconds(2),
        ]);
        $unmatchedEvent = (string) Str::ulid();
        AtlasLedgerEvent::query()->create([
            'event_id' => $unmatchedEvent,
            'schema_version' => AtlasEvidenceLedger::SCHEMA_VERSION,
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'maxd02-unmatched',
            'receipt_id' => 'receipt-not-in-brain',
            'trace_id' => 'trace-not-in-brain',
            'correlation_id' => 'corr-not-in-brain',
            'causation_id' => null,
            'event_type' => 'MAXD02_NO_MATCH',
            'emitter_stage' => 'atlas.test',
            'emitter_version' => 'v1',
            'payload' => [],
            'payload_hash' => hash('sha256', 'maxd02-unmatched'),
            'occurred_at' => now()->addSeconds(3),
        ]);

        $stats = $this->service()->sync(['evidence']);

        $this->assertGreaterThanOrEqual(2, $stats['linkers']['evidence_links']);

        $receiptEdge = AtlasAurgEdge::query()
            ->where('from_node_id', 'evidence:evidence:'.$receiptEvent)
            ->where('to_node_id', 'mission:mission:mission-maxd02')
            ->where('kind', 'proves')
            ->where('source', 'linker_evidence')
            ->first();
        $this->assertNotNull($receiptEdge);
        $this->assertSame(1.0, (float) $receiptEdge->confidence);
        $this->assertSame('receipt_id', $receiptEdge->meta['matched'] ?? null);
        $this->assertSame('receipt-maxd02-mission', $receiptEdge->meta['value'] ?? null);

        $traceEdge = AtlasAurgEdge::query()
            ->where('from_node_id', 'evidence:evidence:'.$traceEvent)
            ->where('to_node_id', 'obra:obra:obra-maxd02')
            ->where('kind', 'proves')
            ->where('source', 'linker_evidence')
            ->first();
        $this->assertNotNull($traceEdge);
        $this->assertSame(1.0, (float) $traceEdge->confidence);
        $this->assertSame('trace_id', $traceEdge->meta['matched'] ?? null);
        $this->assertSame('trace-maxd02-obra', $traceEdge->meta['value'] ?? null);

        $this->assertFalse(
            AtlasAurgEdge::query()
                ->where('from_node_id', 'evidence:evidence:'.$unmatchedEvent)
                ->where('source', 'linker_evidence')
                ->exists(),
            'evidence without exact receipt/trace/correlation references must be omitted',
        );
    }

    private function service(): AtlasRealityGraphIngestionService
    {
        return new AtlasRealityGraphIngestionService(
            new CrossDomainTaxonomyMap,
            app(\App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService::class),
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
