<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasMemoryQualityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

final class MemoryQualityRetrievalWindowTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAtlasMemoryEntryTable();
        Schema::dropIfExists('atlas_memory_entry_usages');
        (require database_path('migrations/2026_05_02_001000_create_atlas_memory_entry_usages_table.php'))->up();
        (require database_path('migrations/2026_07_09_153500_repair_missing_ai_memory_deltas_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_memory_deltas');
        Schema::dropIfExists('atlas_memory_entry_usages');
        $this->dropAtlasMemoryEntryTable();

        parent::tearDown();
    }

    public function test_retrieval_eval_counts_only_recent_recall_usage_and_keeps_all_time_audit_counts(): void
    {
        $recentEntry = $this->createActiveEntry('Recent recall entry');
        $oldEntry = $this->createActiveEntry('Old recall entry');

        $this->insertUsage($recentEntry, now()->subDays(5), 'useful');
        $this->insertUsage($oldEntry, now()->subDays(60), 'wrong_context');

        $scorecard = app(AtlasMemoryQualityService::class)->scorecard();
        $retrieval = (array) data_get($scorecard, 'counts.retrieval_eval');

        $this->assertSame(45, $retrieval['window_days']);
        $this->assertSame(1, $retrieval['recall_usage_window_total']);
        $this->assertSame(1, $retrieval['recall_usage_total']);
        $this->assertSame(1, $retrieval['entries_recalled']);
        $this->assertSame(1, $retrieval['active_entries_never_recalled']);
        $this->assertSame(1, $retrieval['recall_feedback_total']);
        $this->assertSame(0, $retrieval['recall_negative_feedback']);

        $this->assertSame(2, $retrieval['all_time_recall_usage_total']);
        $this->assertSame(2, $retrieval['all_time_entries_recalled']);
        $this->assertSame(2, $retrieval['all_time_recall_feedback_total']);
        $this->assertSame(1, $retrieval['all_time_recall_negative_feedback']);
        $this->assertSame(1, $retrieval['all_time_recall_wrong_context_feedback']);
    }

    private function createActiveEntry(string $title): string
    {
        $entry = AtlasMemoryEntry::query()->create([
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

        return (string) $entry->id;
    }

    private function insertUsage(string $memoryEntryId, \DateTimeInterface $createdAt, string $feedbackAction): void
    {
        DB::table('atlas_memory_entry_usages')->insert([
            'id' => (string) Str::uuid(),
            'memory_entry_id' => $memoryEntryId,
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'source_type' => 'memory_recall',
            'position' => 0,
            'source_ref_json' => '{}',
            'context_payload_json' => '{}',
            'metadata' => '{}',
            'feedback_action' => $feedbackAction,
            'feedback_recorded_at' => $createdAt,
            'used_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
