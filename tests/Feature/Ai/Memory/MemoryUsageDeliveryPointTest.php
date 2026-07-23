<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryUsage;
use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use App\Services\Ai\Memory\AtlasMemoryUsageService;
use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\Compounding\AtlasRagFeedbackService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

final class MemoryUsageDeliveryPointTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAtlasMemoryEntryTable();
        Schema::dropIfExists('atlas_memory_entry_usages');
        (require database_path('migrations/2026_05_02_001000_create_atlas_memory_entry_usages_table.php'))->up();

        config()->set('atlas.aobg.delivered_pack_ledger.enabled', false);
        config()->set('atlas.aobg.include_runtime_compose', false);
        config()->set('atlas.aurg.enabled', false);
    }

    protected function tearDown(): void
    {
        $this->dropCompoundingSchema();
        Schema::dropIfExists('atlas_memory_entry_usages');
        $this->dropAtlasMemoryEntryTable();

        parent::tearDown();
    }

    public function test_context_pack_demoted_memory_records_pre_filter_not_delivery(): void
    {
        $this->bootCompoundingSchema();

        $title = 'RAG delivery demoted memory fixture';
        $publishedRef = 'memory:'.substr(hash('sha256', json_encode([
            'title' => $title,
            'type' => 'decision',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 32);

        $entry = $this->seedMemory($title);

        app(AtlasRagFeedbackService::class)->record([
            'retrieval_receipt_id' => 'receipt-rag01-demote',
            'flow_id' => 'rag01.delivery_demote',
            'query_plan_hash' => hash('sha256', 'receipt-rag01-demote'),
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

        app(AtlasOpenBrainContextPackService::class)->packFor($title, [
            'flow_id' => 'rag01.delivery_demote',
            'workspace' => 'atlas-server',
            'budget' => 3000,
            'memory_budget' => 2000,
        ]);

        $this->assertSame(0, $this->deliveryUsageCount());
        $this->assertSame(1, $this->preFilterUsageCount());
        $this->assertSame(1, AtlasMemoryEntryUsage::query()
            ->where('memory_entry_id', $entry->id)
            ->where('source_type', AtlasMemoryUsageService::SOURCE_TYPE_RECALLED_PRE_FILTER)
            ->count());
    }

    public function test_context_pack_delivered_memory_records_single_delivery_row_with_surface(): void
    {
        $title = 'RAG delivery delivered memory fixture hybrid recall';
        $entry = $this->seedMemory($title);

        $pack = app(AtlasOpenBrainContextPackService::class)->packFor($title, [
            'workspace' => 'atlas-server',
            'budget' => 3000,
            'memory_budget' => 2000,
        ]);

        $this->assertNotEmpty($pack['memory']);
        $this->assertSame(1, $this->deliveryUsageCount());
        $this->assertSame(1, $this->preFilterUsageCount());

        $delivery = AtlasMemoryEntryUsage::query()
            ->where('memory_entry_id', $entry->id)
            ->where('source_type', AtlasMemoryUsageService::SOURCE_TYPE_MEMORY_RECALL)
            ->first();
        $this->assertNotNull($delivery);
        $this->assertSame(
            AtlasMemoryUsageService::DELIVERY_SURFACE_CONTEXT_PACK,
            data_get($delivery->metadata, 'delivery_surface'),
        );
        $this->assertSame('atlas_context_pack_delivery', data_get($delivery->metadata, 'created_by'));
    }

    public function test_direct_recall_still_records_usage_without_pre_filter_rows(): void
    {
        $title = 'RAG delivery direct recall fixture hybrid memory';
        $entry = $this->seedMemory($title);

        $recall = app(AtlasHybridMemoryRetrievalService::class)->recall(
            $title,
            ['workspace' => 'atlas-server'],
        );

        $this->assertGreaterThanOrEqual(1, data_get($recall, 'summary.usage_recorded_count'));
        $this->assertSame(1, $this->deliveryUsageCount());
        $this->assertSame(0, $this->preFilterUsageCount());
        $this->assertSame(1, AtlasMemoryEntryUsage::query()
            ->where('memory_entry_id', $entry->id)
            ->where('source_type', AtlasMemoryUsageService::SOURCE_TYPE_MEMORY_RECALL)
            ->count());
    }

    private function seedMemory(string $title): AtlasMemoryEntry
    {
        return AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => $title,
            'summary' => $title.' summary for hybrid recall matching',
            'body' => $title.' body for hybrid recall matching delivery point',
            'status' => 'active',
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'source_type' => 'test_fixture',
            'source_id' => 'rag01-'.substr(hash('sha256', $title), 0, 12),
            'recorded_at' => now(),
        ]);
    }

    private function deliveryUsageCount(): int
    {
        return (int) AtlasMemoryEntryUsage::query()
            ->where('source_type', AtlasMemoryUsageService::SOURCE_TYPE_MEMORY_RECALL)
            ->count();
    }

    private function preFilterUsageCount(): int
    {
        return (int) AtlasMemoryEntryUsage::query()
            ->where('source_type', AtlasMemoryUsageService::SOURCE_TYPE_RECALLED_PRE_FILTER)
            ->count();
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
}
