<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryUsage;
use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use App\Services\Ai\Memory\AtlasMemoryUsageService;
use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\Memory\AtlasMemoryRecallConcentrationDemotion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * RAG-03 — concentration is sensed pre-filter, while composition keeps dominant
 * memories out of unrelated top-K packs without starving the dominance sensor.
 */
final class RecallConcentrationCompositionTest extends TestCase
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
        config()->set('atlas.aobg.semantic_retrieval', false);
        config()->set('atlas.aurg.enabled', false);
        config()->set('atlas.semantic_memory.recall_concentration_demotion_enabled', true);
        config()->set('atlas.semantic_memory.recall_concentration_window_days', 45);
        config()->set('atlas.semantic_memory.recall_concentration_demote_ratio', 0.35);
        config()->set('atlas.semantic_memory.recall_concentration_min_recalls', 10);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_memory_entry_usages');
        $this->dropAtlasMemoryEntryTable();

        parent::tearDown();
    }

    public function test_dominant_entry_ids_read_recalled_pre_filter_not_delivered_usage(): void
    {
        $dominant = $this->seedMemory('Dominant pre-filter ranking signal');
        $minority = $this->seedMemory('Minority pre-filter ranking signal');

        $this->insertUsages((string) $dominant->id, 7, AtlasMemoryUsageService::SOURCE_TYPE_RECALLED_PRE_FILTER);
        $this->insertUsages((string) $minority->id, 3, AtlasMemoryUsageService::SOURCE_TYPE_RECALLED_PRE_FILTER);

        $this->assertSame(
            [(string) $dominant->id],
            app(AtlasMemoryRecallConcentrationDemotion::class)->dominantEntryIds(),
        );
    }

    public function test_dominant_memory_is_removed_from_unrelated_pack_but_still_feeds_sensor(): void
    {
        $dominant = $this->seedMemory('Billing-only memory that should not occupy retrieval AURG context');
        $sensor = $this->seedMemory('Retrieval AURG memory that should occupy the freed context slot');
        $this->insertUsages((string) $dominant->id, 7, AtlasMemoryUsageService::SOURCE_TYPE_RECALLED_PRE_FILTER);
        $this->insertUsages((string) $sensor->id, 3, AtlasMemoryUsageService::SOURCE_TYPE_RECALLED_PRE_FILTER);

        $this->mockRecallCandidates([
            $this->recallRow($dominant, 0.98),
            $this->recallRow($sensor, 0.78),
        ], expectedCalls: 2);

        $pack = app(AtlasOpenBrainContextPackService::class)->packFor('retrieval AURG context composition', [
            'workspace' => 'atlas-server',
            'budget' => 3000,
            'code_budget' => 0,
            'memory_budget' => 2000,
        ]);

        $ids = array_values(array_map('strval', array_column($pack['memory'], 'id')));
        $this->assertNotContains((string) $dominant->id, $ids);
        $this->assertContains((string) $sensor->id, $ids);
        $this->assertSame(
            [(string) $dominant->id],
            app(AtlasMemoryRecallConcentrationDemotion::class)->dominantEntryIds(),
            'pre-filter usage keeps the dominant sensor fed even when delivery filters it',
        );
    }

    public function test_wiper_guard_uses_stable_incident_scope_not_volatile_text_markers(): void
    {
        config()->set('atlas.semantic_memory.recall_concentration_min_recalls', 1000);

        $incident = $this->seedMemory(
            'Operational safety incident',
            'Fixture deliberately omits volatile incident keywords.',
            ['incident_scope' => 'wiper'],
        );
        $retrieval = $this->seedMemory('Retrieval and AURG context memory');

        $this->mockRecallCandidates([
            $this->recallRow($incident, 0.99, ['incident_scope' => 'wiper']),
            $this->recallRow($retrieval, 0.80),
        ], expectedCalls: 4);

        $unrelated = app(AtlasOpenBrainContextPackService::class)->packFor('retrieval AURG context composition', [
            'workspace' => 'atlas-server',
            'budget' => 3000,
            'code_budget' => 0,
            'memory_budget' => 2000,
        ]);
        $wiperQuery = app(AtlasOpenBrainContextPackService::class)->packFor('wiper autoload vendor symlink incident', [
            'workspace' => 'atlas-server',
            'budget' => 3000,
            'code_budget' => 0,
            'memory_budget' => 2000,
        ]);

        $this->assertNotContains((string) $incident->id, array_column($unrelated['memory'], 'id'));
        $this->assertContains((string) $retrieval->id, array_column($unrelated['memory'], 'id'));
        $this->assertContains((string) $incident->id, array_column($wiperQuery['memory'], 'id'));
    }

    private function seedMemory(string $title, ?string $body = null, array $metadata = []): AtlasMemoryEntry
    {
        return AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => $title,
            'summary' => $title.' summary',
            'body' => $body ?? $title.' body',
            'status' => 'active',
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'source_type' => 'test_fixture',
            'source_id' => 'rag03-'.substr(hash('sha256', $title), 0, 12),
            'metadata' => $metadata,
            'recorded_at' => now(),
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function mockRecallCandidates(array $rows, int $expectedCalls): void
    {
        $this->mock(AtlasHybridMemoryRetrievalService::class, function ($mock) use ($rows, $expectedCalls): void {
            $mock->shouldReceive('recall')
                ->times($expectedCalls)
                ->andReturn([
                    'summary' => [
                        'policy' => 'provider_safe_only',
                        'recall_count' => count($rows),
                        'redacted_ref_count' => 0,
                    ],
                    'recall' => $rows,
                ]);
        });
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function recallRow(AtlasMemoryEntry $entry, float $score, array $metadata = []): array
    {
        return [
            'source_ref_type' => 'atlas_memory_entry',
            'source_ref_id' => (string) $entry->id,
            'id' => (string) $entry->id,
            'score' => $score,
            'type' => $entry->memory_type,
            'scope' => $entry->scope_type,
            'title' => $entry->title,
            'summary' => $entry->summary,
            'body' => $entry->body,
            'privacy_class' => $entry->privacy_class,
            'source_type' => $entry->source_type,
            'content_hash' => (string) $entry->content_hash,
            'metadata' => $metadata,
        ];
    }

    private function insertUsages(string $memoryEntryId, int $count, string $sourceType): void
    {
        $now = now();
        $rows = [];
        for ($position = 0; $position < $count; $position++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'memory_entry_id' => $memoryEntryId,
                'memory_type' => 'decision',
                'scope_type' => 'global',
                'source_type' => $sourceType,
                'position' => $position + 1,
                'source_ref_json' => '{}',
                'context_payload_json' => '{}',
                'metadata' => '{}',
                'used_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('atlas_memory_entry_usages')->insert($rows);
    }
}
