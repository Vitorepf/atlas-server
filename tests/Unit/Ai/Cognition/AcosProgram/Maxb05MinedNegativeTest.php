<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryUsage;
use App\Services\Ai\Memory\AtlasMemoryUsageService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * MAXB-05 — mined_negative labels never feed FEEDBACK_NEGATIVE / archive floors.
 */
final class Maxb05MinedNegativeTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasMemoryEntryTable();
        Schema::dropIfExists('atlas_memory_entry_usages');
        (require database_path('migrations/2026_05_02_001000_create_atlas_memory_entry_usages_table.php'))->up();
        config(['atlas.semantic_memory.mined_negative_feedback_enabled' => true]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_memory_entry_usages');
        $this->dropAtlasMemoryEntryTable();
        parent::tearDown();
    }

    public function test_forget_writes_exactly_one_mined_negative_outside_negative_taxonomy(): void
    {
        $entry = AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Forget me',
            'summary' => 'summary',
            'body' => 'body text for forget mining',
            'importance' => 3,
            'priority' => 50,
            'confidence' => 0.7,
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'status' => 'active',
        ]);

        $code = Artisan::call('atlas:ai:memory-forget', ['id' => $entry->id, '--json' => true]);
        $this->assertSame(0, $code);

        $rows = AtlasMemoryEntryUsage::query()
            ->where('memory_entry_id', $entry->id)
            ->where('feedback_action', AtlasMemoryEntryUsage::FEEDBACK_ACTION_MINED_NEGATIVE)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('memory_forget', data_get($rows[0]->metadata, 'mining_source'));
        $this->assertFalse(AtlasMemoryEntryUsage::isNegativeFeedback(AtlasMemoryEntryUsage::FEEDBACK_ACTION_MINED_NEGATIVE));
        $this->assertNotContains(
            AtlasMemoryEntryUsage::FEEDBACK_ACTION_MINED_NEGATIVE,
            AtlasMemoryEntryUsage::negativeFeedbackActions(),
        );
    }

    public function test_flag_off_writes_nothing(): void
    {
        config(['atlas.semantic_memory.mined_negative_feedback_enabled' => false]);

        $entry = AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'No mine',
            'summary' => 'summary',
            'body' => 'body text',
            'importance' => 3,
            'priority' => 50,
            'confidence' => 0.7,
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'status' => 'active',
        ]);

        $written = app(AtlasMemoryUsageService::class)->recordMinedNegative((string) $entry->id, 'memory_forget');
        $this->assertNull($written);
        $this->assertSame(0, AtlasMemoryEntryUsage::query()->count());
    }
}
