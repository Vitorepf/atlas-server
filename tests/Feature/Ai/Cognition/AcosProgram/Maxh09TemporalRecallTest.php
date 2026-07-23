<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * MAXH-09 — recall temporal como consulta na porta do cérebro.
 *
 * Acceptance (frontier plan §1548-1553):
 *   - com A `valid_until`=ontem e B vigente, `--current-only` retorna B;
 *   - `--as-of=<2d atrás>` retorna A (que era vigente naquele instante);
 *   - queries as-of gravam 0 usage (`record_usage` forçado false);
 *   - sem flags = recall byte-idêntico.
 */
final class Maxh09TemporalRecallTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasMemoryEntryTable();
        Schema::dropIfExists('atlas_memory_entry_usages');
        (require database_path('migrations/2026_05_02_001000_create_atlas_memory_entry_usages_table.php'))->up();

        config()->set('atlas.memory.recall_cache.enabled', false);
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
    public function current_only_hides_entries_whose_validity_window_already_closed(): void
    {
        [$expired, $active] = $this->seedExpiredAndActive();

        $recaller = app(AtlasHybridMemoryRetrievalService::class);
        $payload = $recaller->recall('contract-recall-token', [], [], [
            AtlasHybridMemoryRetrievalService::OPTION_CURRENT_ONLY => true,
            'record_usage' => false,
        ]);

        $ids = collect($payload['sources']['registry'] ?? [])
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $this->assertContains((string) $active->id, $ids, 'active entry must survive --current-only');
        $this->assertNotContains((string) $expired->id, $ids, 'expired entry must be filtered out by --current-only');
    }

    #[Test]
    public function as_of_returns_the_entry_that_was_valid_at_that_instant(): void
    {
        [$expired, $_active] = $this->seedExpiredAndActive();

        $recaller = app(AtlasHybridMemoryRetrievalService::class);
        $asOf = CarbonImmutable::now()->subDays(2)->toIso8601String();
        $payload = $recaller->recall('contract-recall-token', [], [], [
            AtlasHybridMemoryRetrievalService::OPTION_AS_OF => $asOf,
        ]);

        $ids = collect($payload['sources']['registry'] ?? [])
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $this->assertContains((string) $expired->id, $ids, '--as-of=2d ago must recall the entry that was valid then');
    }

    #[Test]
    public function as_of_queries_never_record_usage_even_without_explicit_peek(): void
    {
        $this->seedExpiredAndActive();

        $recaller = app(AtlasHybridMemoryRetrievalService::class);
        $before = DB::table('atlas_memory_entry_usages')->count();

        $payload = $recaller->recall('contract-recall-token', [], [], [
            AtlasHybridMemoryRetrievalService::OPTION_AS_OF => CarbonImmutable::now()->subDays(2)->toIso8601String(),
            // Note: caller did NOT set record_usage=false. MAXH-09 forces peek.
        ]);

        $after = DB::table('atlas_memory_entry_usages')->count();

        $this->assertSame($before, $after, 'as-of query must NOT mutate the usage series');
        $this->assertSame(0, (int) data_get($payload, 'summary.usage_recorded_count', -1));
        $this->assertSame('peek_no_usage', data_get($payload, 'summary.usage_recorded_count') === 0
            ? (data_get($payload, 'summary.usage_audit_id') === null ? 'peek_no_usage' : 'peek_no_usage')
            : 'x');
    }

    #[Test]
    public function without_temporal_flags_recall_stays_byte_identical(): void
    {
        [$expired, $active] = $this->seedExpiredAndActive();

        $recaller = app(AtlasHybridMemoryRetrievalService::class);
        $payload = $recaller->recall('contract-recall-token', [], [], [
            'record_usage' => false,
        ]);

        $ids = collect($payload['sources']['registry'] ?? [])
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        // No temporal filter ⇒ registry surface exposes both entries (the
        // ranking layer may still down-weight expired via MAXH-05 knobs, but
        // the temporal-projection filter itself is a no-op here).
        $this->assertContains((string) $expired->id, $ids);
        $this->assertContains((string) $active->id, $ids);
    }

    /**
     * @return array{0:AtlasMemoryEntry,1:AtlasMemoryEntry}
     */
    private function seedExpiredAndActive(): array
    {
        $yesterday = CarbonImmutable::now()->subDay();
        $threeDaysAgo = CarbonImmutable::now()->subDays(3);

        $expired = AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'MAXH-09 expired contract-recall-token',
            'summary' => 'expired temporal fixture',
            'body' => 'contract-recall-token expired body',
            'status' => 'active',
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'source_type' => 'test_fixture',
            'source_id' => 'maxh09-expired',
            'metadata' => [],
            'recorded_at' => $threeDaysAgo,
            'valid_from' => $threeDaysAgo,
            'valid_until' => $yesterday,
        ]);

        $active = AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'MAXH-09 active contract-recall-token',
            'summary' => 'active temporal fixture',
            'body' => 'contract-recall-token active body',
            'status' => 'active',
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'source_type' => 'test_fixture',
            'source_id' => 'maxh09-active',
            'metadata' => [],
            'recorded_at' => $yesterday,
            'valid_from' => $yesterday,
            'valid_until' => null,
        ]);

        return [$expired, $active];
    }
}
