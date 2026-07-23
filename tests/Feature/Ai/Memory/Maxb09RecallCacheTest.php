<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use App\Services\Ai\Memory\AtlasMemoryRecallCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * MAXB-09 — repeat-queries dos hooks reusam recall com `cached=true`; write
 * na memória bumpa o corpus e o próximo hit vira miss.
 */
final class Maxb09RecallCacheTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasMemoryEntryTable();
        Schema::dropIfExists('atlas_memory_entry_usages');
        (require database_path('migrations/2026_05_02_001000_create_atlas_memory_entry_usages_table.php'))->up();

        config()->set('atlas.memory.recall_cache.enabled', true);
        config()->set('atlas.memory.recall_cache.ttl_seconds', 600);
        config()->set('atlas.aobg.include_runtime_compose', false);
        config()->set('atlas.aobg.semantic_retrieval', false);

        Cache::flush();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_memory_entry_usages');
        $this->dropAtlasMemoryEntryTable();
        parent::tearDown();
    }

    #[Test]
    public function identical_repeat_recall_returns_cached_payload(): void
    {
        $this->seedEntry('Contract for MAXB-09 cached recall');

        $recaller = app(AtlasHybridMemoryRetrievalService::class);

        $first = $recaller->recall('contract maxb-09', [], [], ['record_usage' => false]);
        $this->assertFalse($first['cached'] ?? true, 'first call must not be a cache hit');

        $second = $recaller->recall('contract maxb-09', [], [], ['record_usage' => false]);
        $this->assertTrue($second['cached'] ?? false, 'identical repeat must be a cache hit');
    }

    #[Test]
    public function record_usage_does_not_affect_cache_key(): void
    {
        $this->seedEntry('Contract for MAXB-09 usage-safe cache');

        $recaller = app(AtlasHybridMemoryRetrievalService::class);

        $first = $recaller->recall('usage-safe', [], [], ['record_usage' => false]);
        $this->assertFalse($first['cached']);

        // record_usage flips but must NOT force a re-recall.
        $second = $recaller->recall('usage-safe', [], [], ['record_usage' => true]);
        $this->assertTrue($second['cached']);
    }

    #[Test]
    public function corpus_bump_invalidates_the_cache(): void
    {
        $this->seedEntry('Contract for MAXB-09 corpus bump');

        $recaller = app(AtlasHybridMemoryRetrievalService::class);
        $first = $recaller->recall('bump me', [], [], ['record_usage' => false]);
        $this->assertFalse($first['cached']);

        $second = $recaller->recall('bump me', [], [], ['record_usage' => false]);
        $this->assertTrue($second['cached']);

        // Any write should bump the corpus and invalidate the cache.
        $this->seedEntry('Later contract for MAXB-09 corpus bump');

        $third = $recaller->recall('bump me', [], [], ['record_usage' => false]);
        $this->assertFalse($third['cached'], 'write to memory must invalidate the recall cache');
    }

    #[Test]
    public function ttl_is_clamped_to_the_spec_window(): void
    {
        $cache = new AtlasMemoryRecallCache();
        config()->set('atlas.memory.recall_cache.ttl_seconds', 1);
        $this->assertGreaterThanOrEqual(60, $cache->ttlSeconds());

        config()->set('atlas.memory.recall_cache.ttl_seconds', 10_000);
        $this->assertLessThanOrEqual(900, $cache->ttlSeconds());
    }

    #[Test]
    public function disabled_cache_returns_fresh_recall_every_time(): void
    {
        config()->set('atlas.memory.recall_cache.enabled', false);
        $this->seedEntry('Contract for MAXB-09 disabled cache');

        $recaller = app(AtlasHybridMemoryRetrievalService::class);
        $first = $recaller->recall('disabled', [], [], ['record_usage' => false]);
        $second = $recaller->recall('disabled', [], [], ['record_usage' => false]);

        $this->assertFalse($first['cached']);
        $this->assertFalse($second['cached']);
    }

    private function seedEntry(string $title): AtlasMemoryEntry
    {
        return AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => $title,
            'summary' => $title.' summary',
            'body' => $title.' body',
            'status' => 'active',
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'source_type' => 'test_fixture',
            'source_id' => 'maxb09-'.substr(hash('sha256', $title), 0, 12),
            'metadata' => [],
            'recorded_at' => now(),
        ]);
    }
}
