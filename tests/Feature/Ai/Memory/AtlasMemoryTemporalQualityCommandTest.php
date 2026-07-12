<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use App\Services\Ai\AcosMax\AcosMaxMeasureSeriesRegistry;
use App\Services\Ai\Memory\AtlasMemoryTemporalQualityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

final class AtlasMemoryTemporalQualityCommandTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-12T02:00:00+00:00');
        $this->createAtlasMemoryEntryTable();
        $this->createRelationTable();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Schema::dropIfExists('atlas_memory_entry_relations');
        $this->dropAtlasMemoryEntryTable();

        parent::tearDown();
    }

    public function test_empty_corpus_returns_no_signal_with_raw_denominators(): void
    {
        $exit = Artisan::call('atlas:memory:temporal-quality', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('no_signal', data_get($payload, 'memory_temporal_quality.status'));
        $this->assertSame('active_below_minimum', data_get($payload, 'memory_temporal_quality.reason'));
        $this->assertSame(0, data_get($payload, 'memory_temporal_quality.active_entry_count'));
        $this->assertSame(0, data_get($payload, 'memory_temporal_quality.metrics.temporal_provenance_coverage.den'));
        $this->assertNull(data_get($payload, 'memory_temporal_quality.metrics.temporal_provenance_coverage.ratio'));
    }

    public function test_temporal_quality_counts_only_non_default_provenance_and_frozen_relation_bar(): void
    {
        $entries = [];
        for ($i = 0; $i < 8; $i++) {
            $entries[] = $this->memory('memory-'.$i, [
                'observed_at' => '2026-07-12T01:00:00+00:00',
                'source_hash' => hash('sha256', 'source-'.$i),
                'metadata' => $i < 2
                    ? ['temporal_truth' => ['provenance' => $i === 0 ? 'caller_supplied' : 'evidence_derived']]
                    : ['temporal_truth' => ['provenance' => 'default_type_map']],
            ]);
        }

        AtlasMemoryEntryRelation::query()->create([
            'id' => (string) Str::uuid(),
            'source_memory_entry_id' => $entries[0]->id,
            'target_memory_entry_id' => $entries[1]->id,
            'relation_type' => 'related',
            'status' => 'resolved',
            'confidence' => 0.42,
            'reason' => 'Below the frozen quality/confidence bar.',
            'metadata' => ['quality' => 0.40],
            'judgment_status' => 'judged',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('atlas:memory:temporal-quality', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('attention', data_get($payload, 'memory_temporal_quality.status'));
        $this->assertSame(2, data_get($payload, 'memory_temporal_quality.metrics.temporal_provenance_coverage.num'));
        $this->assertSame(8, data_get($payload, 'memory_temporal_quality.metrics.temporal_provenance_coverage.den'));
        $this->assertSame(0, data_get($payload, 'memory_temporal_quality.metrics.truth_density_v2.num'));
        $this->assertSame(1, data_get($payload, 'memory_temporal_quality.metrics.truth_density_v2.den'));
        $this->assertSame(0.0, data_get($payload, 'memory_temporal_quality.metrics.truth_density_v2.ratio'));
        $this->assertSame(0.86, data_get($payload, 'memory_temporal_quality.freeze.thresholds.relation_confidence_floor'));
    }

    public function test_supersession_maintained_requires_judged_supersedes_and_active_superseder(): void
    {
        $oldA = $this->memory('old-a');
        $newA = $this->memory('new-a');
        $oldB = $this->memory('old-b');
        $inactiveNewB = $this->memory('new-b', ['status' => 'inactive']);
        for ($i = 0; $i < 4; $i++) {
            $this->memory('filler-'.$i);
        }

        foreach ([[$oldA, $newA], [$oldB, $inactiveNewB]] as [$source, $target]) {
            AtlasMemoryEntryRelation::query()->create([
                'id' => (string) Str::uuid(),
                'source_memory_entry_id' => $source->id,
                'target_memory_entry_id' => $target->id,
                'relation_type' => 'supersedes',
                'status' => 'resolved',
                'confidence' => 0.94,
                'reason' => 'Judged supersession fixture.',
                'metadata' => ['quality' => 0.91],
                'judgment_status' => 'judged',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $exit = Artisan::call('atlas:memory:temporal-quality', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(1, data_get($payload, 'memory_temporal_quality.metrics.supersession_maintained.num'));
        $this->assertSame(2, data_get($payload, 'memory_temporal_quality.metrics.supersession_maintained.den'));
        $this->assertSame(0.5, data_get($payload, 'memory_temporal_quality.metrics.supersession_maintained.ratio'));
    }

    public function test_freeze_payload_is_available_without_touching_memory_quality_payload(): void
    {
        $this->memory('quality-control');

        $qualityExit = Artisan::call('atlas:memory:quality', ['--json' => true]);
        $qualityPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $qualityKeys = array_keys((array) data_get($qualityPayload, 'memory_quality'));

        $freezeExit = Artisan::call('atlas:memory:temporal-quality', [
            '--freeze-payload' => true,
            '--json' => true,
        ]);
        $freezePayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $secondQualityExit = Artisan::call('atlas:memory:quality', ['--json' => true]);
        $secondQualityPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $qualityExit);
        $this->assertSame(0, $freezeExit);
        $this->assertSame(0, $secondQualityExit);
        $this->assertSame('atlas.memory.temporal_truth.v2', data_get($freezePayload, 'freeze_payload.measure_id'));
        $this->assertSame(8, data_get($freezePayload, 'freeze_payload.denominator_min'));
        $this->assertSame($qualityKeys, array_keys((array) data_get($secondQualityPayload, 'memory_quality')));
        $this->assertArrayNotHasKey('memory_temporal_quality', (array) data_get($secondQualityPayload, 'memory_quality'));
        $this->assertArrayNotHasKey('temporal_provenance_coverage', (array) data_get($secondQualityPayload, 'memory_quality'));
    }

    public function test_maxh_01_series_is_registered_for_elev20s(): void
    {
        $entries = app(AcosMaxMeasureSeriesRegistry::class)->entries();
        $entry = collect($entries)->firstWhere('slice', 'MAXH-01');

        $this->assertIsArray($entry);
        $this->assertSame(AtlasMemoryTemporalQualityService::MEASURE_ID, $entry['series'] ?? null);
        $this->assertSame('command', $entry['source_type'] ?? null);
        $this->assertSame('freeze:atlas.memory.temporal_truth.v2', $entry['ttl_source'] ?? null);
    }

    /** @param array<string,mixed> $overrides */
    private function memory(string $title, array $overrides = []): AtlasMemoryEntry
    {
        return AtlasMemoryEntry::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => $title,
            'body' => 'Temporal truth fixture '.$title,
            'summary' => 'Temporal truth fixture '.$title,
            'confidence' => 0.9,
            'source_type' => 'test',
            'status' => 'active',
            'metadata' => [],
            'recorded_at' => now(),
        ], $overrides));
    }

    private function createRelationTable(): void
    {
        Schema::dropIfExists('atlas_memory_entry_relations');
        Schema::create('atlas_memory_entry_relations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_memory_entry_id')->index();
            $table->uuid('target_memory_entry_id')->index();
            $table->string('relation_type', 40)->index();
            $table->string('status', 24)->default('open')->index();
            $table->float('confidence')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->default('{}');
            $table->string('marked_by_actor', 32)->nullable()->index();
            $table->string('marked_by_model', 128)->nullable();
            $table->string('judgment_status', 24)->default('pending')->index();
            $table->json('evidence_refs')->nullable();
            $table->string('verdict_schema_version', 64)->default('atlas.memory.relation_verdict.v1');
            $table->timestamps();
        });
    }
}
