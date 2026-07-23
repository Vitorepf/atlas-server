<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use App\Services\Ai\Memory\AtlasMemoryRecallCache;
use App\Services\Ai\SelfConstruction\Lineage\AtlasDecisionLineageLedger;
use App\Services\Ai\SelfConstruction\Lineage\AtlasRollbackCascadeExecutor;
use App\Services\Ai\SelfConstruction\Lineage\AtlasRollbackNegativeSearchVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * ESP-08 — rollback negative-search receipt across recall + caches + relations.
 */
final class Esp08NegativeSearchReceiptTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-12T12:00:00+00:00');
        $this->createAtlasMemoryEntryTable();
        $this->createRelationTable();
        $this->createLineageTable();

        Schema::dropIfExists('atlas_memory_entry_usages');
        (require database_path('migrations/2026_05_02_001000_create_atlas_memory_entry_usages_table.php'))->up();

        config()->set('atlas.memory.recall_cache.enabled', true);
        config()->set('atlas.memory.recall_cache.ttl_seconds', 600);
        config()->set('atlas.aobg.pack_cache.enabled', true);
        config()->set('atlas.aobg.include_runtime_compose', false);
        config()->set('atlas.aobg.semantic_retrieval', false);

        Cache::flush();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Schema::dropIfExists('atlas_memory_entry_usages');
        Schema::dropIfExists('atlas_decision_lineage_ledger');
        Schema::dropIfExists('atlas_memory_entry_relations');
        $this->dropAtlasMemoryEntryTable();
        parent::tearDown();
    }

    public function test_negative_search_passes_after_archive_with_all_four_stores_cited(): void
    {
        $entryId = $this->seedMemoryEntry('esp08-pass-contract', 'ESP-08 negative search pass fixture');

        $recaller = app(AtlasHybridMemoryRetrievalService::class);
        $recaller->recall('esp08 pass contract', [], [], ['record_usage' => false]);

        $entry = AtlasMemoryEntry::query()->where('id', $entryId)->firstOrFail();
        $entry->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

        $receipt = app(AtlasRollbackNegativeSearchVerifier::class)->verify([$entryId]);

        $this->assertSame(AtlasRollbackNegativeSearchVerifier::SCHEMA, $receipt['schema']);
        $this->assertSame(AtlasRollbackNegativeSearchVerifier::STATUS_PASS, $receipt['status']);
        $this->assertSame(AtlasRollbackNegativeSearchVerifier::REQUIRED_STORES, $receipt['required_stores']);
        $this->assertCount(4, $receipt['searches']);

        $stores = array_column($receipt['searches'], 'store');
        $this->assertSame(AtlasRollbackNegativeSearchVerifier::REQUIRED_STORES, array_values(array_unique($stores)));

        foreach ($receipt['searches'] as $search) {
            $this->assertFalse($search['hit'], 'store '.$search['store'].' must have zero hits');
            $this->assertSame(0, $search['hit_count']);
        }
    }

    public function test_negative_search_fails_when_recall_cache_still_serves_forgotten_item(): void
    {
        $entryId = $this->seedMemoryEntry('esp08-stale-cache', 'ESP-08 stale recall cache fixture');
        $cache = app(AtlasMemoryRecallCache::class);
        $staleKey = $cache->keyFor('esp08 stale cache', [], [], ['record_usage' => false]);

        $recaller = app(AtlasHybridMemoryRetrievalService::class);
        $first = $recaller->recall('esp08 stale cache', [], [], ['record_usage' => false]);
        $this->assertFalse($first['cached']);

        $entry = AtlasMemoryEntry::query()->where('id', $entryId)->firstOrFail();
        $entry->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

        Cache::put($staleKey, [
            'schema_version' => 'atlas.memory.recall_cache.v1',
            'stored_at' => now()->toJSON(),
            'recall' => [
                'recall' => [
                    ['id' => $entryId, 'title' => 'poisoned cache row'],
                ],
            ],
        ], now()->addHour());

        $receipt = app(AtlasRollbackNegativeSearchVerifier::class)->verify(
            [$entryId],
            ['stale_cache_keys' => [$staleKey]],
        );

        $this->assertSame(AtlasRollbackNegativeSearchVerifier::STATUS_FAIL, $receipt['status']);
        $this->assertContains(AtlasRollbackNegativeSearchVerifier::STORE_RECALL_CACHE, $receipt['failed_stores']);

        $cacheSearch = collect($receipt['searches'])->firstWhere('store', AtlasRollbackNegativeSearchVerifier::STORE_RECALL_CACHE);
        $this->assertNotNull($cacheSearch);
        $this->assertTrue($cacheSearch['hit']);
        $this->assertGreaterThan(0, $cacheSearch['hit_count']);
    }

    public function test_negative_search_fails_when_open_consolidation_relation_still_touches_entry(): void
    {
        $forgotten = $this->seedMemoryEntry('esp08-relation-loser', 'ESP-08 relation loser');
        $winner = $this->seedMemoryEntry('esp08-relation-winner', 'ESP-08 relation winner');

        AtlasMemoryEntryRelation::query()->create([
            'source_memory_entry_id' => $winner,
            'target_memory_entry_id' => $forgotten,
            'relation_type' => 'supersedes',
            'status' => 'open',
            'reason' => 'fixture open supersedes edge',
            'metadata' => [],
        ]);

        AtlasMemoryEntry::query()->where('id', $forgotten)->update([
            'status' => 'archived',
            'archived_at' => now(),
        ]);

        $receipt = app(AtlasRollbackNegativeSearchVerifier::class)->verify([$forgotten]);

        $this->assertSame(AtlasRollbackNegativeSearchVerifier::STATUS_FAIL, $receipt['status']);
        $this->assertContains(
            AtlasRollbackNegativeSearchVerifier::STORE_CONSOLIDATION_RELATIONS,
            $receipt['failed_stores'],
        );
    }

    public function test_cascade_execute_attaches_negative_search_receipt_with_completion_done(): void
    {
        $ledger = new AtlasDecisionLineageLedger;
        $entryId = $this->seedMemoryEntry('esp08-cascade', 'ESP-08 cascade integration fixture');
        $ledger->append('DEC-ESP08', AtlasDecisionLineageLedger::KIND_MEMORY, $entryId, 'memory_registry');

        $executor = new AtlasRollbackCascadeExecutor($ledger, sys_get_temp_dir());
        $result = $executor->execute('DEC-ESP08', dryRun: false, options: ['allow_git' => false]);

        $this->assertSame(AtlasRollbackCascadeExecutor::STATE_CLEAN, $result['state']);
        $this->assertSame('done', $result['completion']);
        $this->assertIsArray($result['negative_search']);
        $this->assertSame(AtlasRollbackNegativeSearchVerifier::STATUS_PASS, $result['negative_search']['status']);
        $this->assertCount(4, $result['negative_search']['searches']);
        $this->assertSame('archived', AtlasMemoryEntry::query()->where('id', $entryId)->value('status'));
    }

    public function test_cascade_completion_incomplete_when_negative_search_fails(): void
    {
        $ledger = new AtlasDecisionLineageLedger;
        $entryId = $this->seedMemoryEntry('esp08-cascade-fail', 'ESP-08 cascade fail fixture');
        $ledger->append('DEC-ESP08-FAIL', AtlasDecisionLineageLedger::KIND_MEMORY, $entryId, 'memory_registry');

        $cache = app(AtlasMemoryRecallCache::class);
        $staleKey = $cache->keyFor('esp08 cascade fail fixture', [], [], ['record_usage' => false]);
        Cache::put($staleKey, [
            'schema_version' => 'atlas.memory.recall_cache.v1',
            'recall' => ['recall' => [['id' => $entryId]]],
        ], now()->addHour());

        $executor = new AtlasRollbackCascadeExecutor(
            $ledger,
            sys_get_temp_dir(),
            new Esp08FailingNegativeSearchVerifier($staleKey),
        );
        $result = $executor->execute('DEC-ESP08-FAIL', dryRun: false, options: ['allow_git' => false]);

        $this->assertSame(AtlasRollbackCascadeExecutor::STATE_CLEAN, $result['state']);
        $this->assertSame('incomplete', $result['completion']);
        $this->assertSame(AtlasRollbackNegativeSearchVerifier::STATUS_FAIL, $result['negative_search']['status']);
        $this->assertContains(
            AtlasRollbackNegativeSearchVerifier::STORE_RECALL_CACHE,
            $result['negative_search']['failed_stores'],
        );
    }

    public function test_dry_run_skips_negative_search_receipt(): void
    {
        $ledger = new AtlasDecisionLineageLedger;
        $entryId = $this->seedMemoryEntry('esp08-dry', 'ESP-08 dry run fixture');
        $ledger->append('DEC-ESP08-DRY', AtlasDecisionLineageLedger::KIND_MEMORY, $entryId, 'memory_registry');

        $executor = new AtlasRollbackCascadeExecutor($ledger, sys_get_temp_dir());
        $result = $executor->execute('DEC-ESP08-DRY', dryRun: true, options: ['allow_git' => false]);

        $this->assertSame('dry_run', $result['completion']);
        $this->assertNull($result['negative_search']);
        $this->assertSame('active', AtlasMemoryEntry::query()->where('id', $entryId)->value('status'));
    }

    private function seedMemoryEntry(string $suffix, string $title): string
    {
        $id = (string) Str::uuid();
        $entry = new AtlasMemoryEntry;
        $entry->forceFill([
            'id' => $id,
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => $title,
            'body' => 'body '.$suffix,
            'status' => 'active',
            'source_type' => 'atlas_autonomous_learning',
        ])->save();

        return $id;
    }

    private function createRelationTable(): void
    {
        Schema::dropIfExists('atlas_memory_entry_relations');
        Schema::create('atlas_memory_entry_relations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_memory_entry_id')->index();
            $table->uuid('target_memory_entry_id')->index();
            $table->string('relation_type', 40);
            $table->string('status', 24)->default('open');
            $table->float('confidence')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }

    private function createLineageTable(): void
    {
        Schema::dropIfExists('atlas_decision_lineage_ledger');
        Schema::create('atlas_decision_lineage_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('decision_id', 120)->index();
            $table->string('obra_id', 120)->nullable()->index();
            $table->string('entity_kind', 32)->index();
            $table->string('entity_ref', 200);
            $table->string('entity_scope', 60)->nullable();
            $table->string('reverse_handle', 200)->nullable();
            $table->string('writer', 80);
            $table->json('meta')->nullable();
            $table->timestampTz('recorded_at')->index();
            $table->unique(['entity_kind', 'entity_ref']);
        });
    }
}

/** @internal test double — injects stale recall-cache key into ESP-08 verification. */
final class Esp08FailingNegativeSearchVerifier
{
    public function __construct(private readonly string $staleKey) {}

    /**
     * @param  list<string>  $memoryEntryIds
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function verify(array $memoryEntryIds, array $options = []): array
    {
        $options['stale_cache_keys'] = array_values(array_unique(array_merge(
            (array) ($options['stale_cache_keys'] ?? []),
            [$this->staleKey],
        )));

        return app(AtlasRollbackNegativeSearchVerifier::class)->verify($memoryEntryIds, $options);
    }
}
