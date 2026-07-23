<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasMemoryQualityService;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

final class AtlasMemoryQualitySourceIntegrityTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAtlasMemoryEntryTable();
        (require database_path('migrations/2026_07_09_153500_repair_missing_ai_memory_deltas_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_memory_deltas');
        $this->dropAtlasMemoryEntryTable();

        parent::tearDown();
    }

    public function test_invalid_uuid_source_is_reported_orphaned_without_breaking_quality(): void
    {
        AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Imported legacy proposal',
            'summary' => 'Legacy source identifiers must degrade honestly.',
            'body' => 'Quality inspection must not cast an invalid source id into a PostgreSQL UUID.',
            'status' => 'active',
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'source_type' => 'ai_memory_delta',
            'source_id' => 'legacy-non-uuid-source',
            'recorded_at' => now(),
        ]);

        $scorecard = app(AtlasMemoryQualityService::class)->scorecard();

        $this->assertTrue($scorecard['ok']);
        $this->assertSame(1, data_get($scorecard, 'counts.source_integrity.checked'));
        $this->assertSame(1, data_get($scorecard, 'counts.source_integrity.orphaned'));
        $this->assertSame(1, data_get($scorecard, 'counts.global_active'));
        $this->assertSame(0, data_get($scorecard, 'counts.non_global_active'));
        $this->assertSame(1.0, data_get($scorecard, 'ratios.global_scope_ratio'));
        $this->assertContains(
            'global_scope_concentration',
            collect($scorecard['issues'])->pluck('code')->all(),
        );
    }
}
