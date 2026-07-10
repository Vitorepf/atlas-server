<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Memory;

use App\Services\Ai\Memory\AtlasMemoryRecallConcentrationDemotion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AtlasMemoryRecallConcentrationDemotionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_memory_entry_usages');
        (require database_path('migrations/2026_05_02_001000_create_atlas_memory_entry_usages_table.php'))->up();

        config()->set('atlas.semantic_memory.recall_concentration_demotion_enabled', true);
        config()->set('atlas.semantic_memory.recall_concentration_window_days', 45);
        config()->set('atlas.semantic_memory.recall_concentration_demote_ratio', 0.35);
        config()->set('atlas.semantic_memory.recall_concentration_min_recalls', 10);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_memory_entry_usages');

        parent::tearDown();
    }

    public function test_it_detects_only_entries_above_the_configured_recall_share(): void
    {
        $dominant = '11111111-1111-1111-1111-111111111111';
        $minority = '22222222-2222-2222-2222-222222222222';

        $this->insertUsages($dominant, 70);
        $this->insertUsages($minority, 30);

        $this->assertSame(
            [$dominant],
            app(AtlasMemoryRecallConcentrationDemotion::class)->dominantEntryIds(),
        );
    }

    private function insertUsages(string $memoryEntryId, int $count): void
    {
        $now = now();
        $rows = [];

        for ($position = 0; $position < $count; $position++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'memory_entry_id' => $memoryEntryId,
                'memory_type' => 'decision',
                'scope_type' => 'global',
                'source_type' => 'memory_recall',
                'position' => $position,
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
