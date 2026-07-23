<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryUsage;
use App\Services\Ai\Memory\AtlasMemoryQualityService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

final class MemoryNegativeFeedbackPathTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAtlasMemoryEntryTable();
        Schema::dropIfExists('atlas_memory_entry_usages');
        (require database_path('migrations/2026_05_02_001000_create_atlas_memory_entry_usages_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_memory_entry_usages');
        $this->dropAtlasMemoryEntryTable();

        parent::tearDown();
    }

    public function test_explicit_wrong_context_relinks_retrieval_negative_ratio_without_useful_implicit_denominator_dilution(): void
    {
        $entry = $this->createActiveEntry('Context Pack Recall Gate');

        for ($i = 0; $i < 5; $i++) {
            $this->insertRecallUsage($entry->id, 'useful_implicit');
        }

        $before = app(AtlasMemoryQualityService::class)->scorecard();
        $this->assertSame(0, data_get($before, 'counts.retrieval_eval.recall_feedback_total'));
        $this->assertSame(0, data_get($before, 'counts.retrieval_eval.recall_negative_feedback'));
        $this->assertSame(0.0, data_get($before, 'ratios.retrieval_negative_feedback_ratio'));

        $unratedUsage = $this->insertRecallUsage($entry->id, null);

        $exit = Artisan::call('atlas:memory:feedback', [
            'memory' => (string) $entry->id,
            '--action' => 'wrong_context',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('wrong_context', $unratedUsage->fresh()->feedback_action);

        $after = app(AtlasMemoryQualityService::class)->scorecard();
        $this->assertSame(1, data_get($after, 'counts.retrieval_eval.recall_feedback_total'));
        $this->assertSame(1, data_get($after, 'counts.retrieval_eval.recall_negative_feedback'));
        $this->assertSame(1.0, data_get($after, 'ratios.retrieval_negative_feedback_ratio'));
    }

    private function createActiveEntry(string $title): AtlasMemoryEntry
    {
        return AtlasMemoryEntry::query()->create([
            'id' => (string) Str::uuid(),
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => $title,
            'summary' => $title.' summary',
            'body' => $title.' body',
            'status' => 'active',
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'source_type' => 'manual',
            'recorded_at' => now(),
        ]);
    }

    private function insertRecallUsage(string $memoryEntryId, ?string $feedbackAction): AtlasMemoryEntryUsage
    {
        $now = now();

        DB::table('atlas_memory_entry_usages')->insert([
            'id' => $id = (string) Str::uuid(),
            'memory_entry_id' => $memoryEntryId,
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'source_type' => 'memory_recall',
            'position' => 1,
            'source_ref_json' => json_encode(['type' => 'atlas_memory_entry', 'id' => $memoryEntryId], JSON_THROW_ON_ERROR),
            'context_payload_json' => '{}',
            'metadata' => '{}',
            'feedback_action' => $feedbackAction,
            'feedback_recorded_at' => $feedbackAction === null ? null : $now,
            'used_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return AtlasMemoryEntryUsage::query()->findOrFail($id);
    }
}
