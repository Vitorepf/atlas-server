<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

final class AtlasMemoryTemporalBackfillCommandTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-12T12:00:00+00:00');
        $this->createAtlasMemoryEntryTable();
        config([
            'atlas.memory_temporal_defaults.authority_by_type' => [
                'decision' => 'canonical',
                'technical_context' => 'operational',
            ],
            'atlas.memory_temporal_defaults.ttl_days_by_type' => [
                'decision' => null,
                'technical_context' => 90,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        $this->dropAtlasMemoryEntryTable();

        parent::tearDown();
    }

    public function test_registry_derives_default_temporal_truth_fields_without_claiming_non_default_provenance(): void
    {
        $entry = app(AtlasMemoryRegistryService::class)->record([
            'memory_type' => 'technical_context',
            'scope_type' => 'global',
            'title' => 'Runtime context default TTL',
            'body' => 'Runtime context remains provider safe. motivo: MAXH-02 default derivation fixture.',
            'summary' => 'Runtime context default TTL',
            'recorded_at' => '2026-07-01T09:30:00+00:00',
        ]);

        $this->assertSame('operational', $entry->authority_level);
        $this->assertSame('2026-07-01 09:30:00', $entry->observed_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-29 09:30:00', $entry->stale_after?->format('Y-m-d H:i:s'));
        $this->assertSame('default_type_map', data_get($entry->metadata, 'temporal_truth.provenance'));
        $this->assertSame('MAXH-02', data_get($entry->metadata, 'temporal_truth.derived_by'));
    }

    public function test_temporal_backfill_dry_run_and_apply_use_the_same_default_policy(): void
    {
        $entry = AtlasMemoryEntry::query()->create([
            'id' => (string) Str::uuid(),
            'memory_type' => 'technical_context',
            'scope_type' => 'global',
            'title' => 'Backfill target',
            'body' => 'Backfill target. motivo: default temporal truth fixture.',
            'summary' => 'Backfill target',
            'confidence' => 0.9,
            'source_type' => 'test',
            'status' => 'active',
            'metadata' => [],
            'recorded_at' => '2026-07-01T09:30:00+00:00',
        ]);

        $dryRunExit = Artisan::call('atlas:memory:temporal-backfill', [
            '--dry-run' => true,
            '--json' => true,
        ]);
        $dryRun = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $dryRunExit);
        $this->assertSame('dry_run', data_get($dryRun, 'temporal_backfill.mode'));
        $this->assertSame(1, data_get($dryRun, 'temporal_backfill.candidates'));
        $this->assertSame('operational', data_get($dryRun, 'temporal_backfill.preview.0.derived.authority_level'));
        $this->assertSame('2026-09-29T09:30:00+00:00', data_get($dryRun, 'temporal_backfill.preview.0.derived.stale_after'));

        $this->assertNull($entry->refresh()->authority_level);
        $this->assertNull($entry->stale_after);

        $applyExit = Artisan::call('atlas:memory:temporal-backfill', [
            '--apply' => true,
            '--json' => true,
        ]);
        $apply = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $entry->refresh();
        $this->assertSame(0, $applyExit);
        $this->assertSame('apply', data_get($apply, 'temporal_backfill.mode'));
        $this->assertSame(1, data_get($apply, 'temporal_backfill.applied'));
        $this->assertSame('operational', $entry->authority_level);
        $this->assertSame('2026-07-01 09:30:00', $entry->observed_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-29 09:30:00', $entry->stale_after?->format('Y-m-d H:i:s'));
        $this->assertSame('default_type_map', data_get($entry->metadata, 'temporal_truth.provenance'));
    }
}
