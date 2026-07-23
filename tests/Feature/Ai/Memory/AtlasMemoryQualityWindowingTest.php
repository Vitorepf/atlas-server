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

final class AtlasMemoryQualityWindowingTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.semantic_memory.recall_concentration_window_days', 45);
        config()->set('atlas.semantic_memory.recall_concentration_min_recalls', 100);

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

    public function test_feedback_uses_45_day_window_and_keeps_all_time_counts_for_audit(): void
    {
        $entry = $this->createActiveEntry('Windowed feedback entry');

        for ($i = 0; $i < 120; $i++) {
            $this->insertUsage($entry, now()->subDays(60), 'memory_recall', 'useful');
        }

        $scorecard = app(AtlasMemoryQualityService::class)->scorecard();

        $this->assertSame(120, data_get($scorecard, 'counts.feedback.usage_total'));
        $this->assertSame(120, data_get($scorecard, 'counts.feedback.feedback_total'));
        $this->assertSame(45, data_get($scorecard, 'counts.feedback.window_days'));
        $this->assertSame(0, data_get($scorecard, 'counts.feedback.usage_window_total'));
        $this->assertSame(0, data_get($scorecard, 'counts.feedback.feedback_window_total'));
        $this->assertSame(50, data_get($scorecard, 'components.feedback'));
        $this->assertNotSame(100, data_get($scorecard, 'components.feedback'));
    }

    public function test_retrieval_eval_exposes_window_aliases_and_all_time_counts(): void
    {
        $recent = $this->createActiveEntry('Recent recall');
        $old = $this->createActiveEntry('Old recall');

        $this->insertUsage($recent, now()->subDays(40), 'memory_recall', 'useful');
        $this->insertUsage($old, now()->subDays(60), 'memory_recall', 'wrong_context');

        $scorecard = app(AtlasMemoryQualityService::class)->scorecard();

        $this->assertSame(45, data_get($scorecard, 'counts.retrieval_eval.window_days'));
        $this->assertSame(1, data_get($scorecard, 'counts.retrieval_eval.recall_usage_window_total'));
        $this->assertSame(1, data_get($scorecard, 'counts.retrieval_eval.recall_usage_total'));
        $this->assertSame(2, data_get($scorecard, 'counts.retrieval_eval.all_time_recall_usage_total'));
    }

    public function test_recall_concentration_issue_uses_ratios_argument(): void
    {
        $dominant = $this->createActiveEntry('Dominant recalled memory');
        $other = $this->createActiveEntry('Other recalled memory');

        for ($i = 0; $i < 70; $i++) {
            $this->insertUsage($dominant, now()->subDays(2), 'memory_recall', 'useful');
        }
        for ($i = 0; $i < 30; $i++) {
            $this->insertUsage($other, now()->subDays(2), 'memory_recall', 'useful');
        }

        $scorecard = app(AtlasMemoryQualityService::class)->scorecard();

        $issue = collect((array) $scorecard['issues'])
            ->firstWhere('code', 'recall_concentration_high');

        $this->assertIsArray($issue);
        $this->assertSame('warning', $issue['severity']);
        $this->assertSame(0.7, $issue['ratio']);
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

    private function insertUsage(string $memoryEntryId, \DateTimeInterface $createdAt, string $sourceType, ?string $feedbackAction): void
    {
        DB::table('atlas_memory_entry_usages')->insert([
            'id' => (string) Str::uuid(),
            'memory_entry_id' => $memoryEntryId,
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'source_type' => $sourceType,
            'position' => 0,
            'source_ref_json' => '{}',
            'context_payload_json' => '{}',
            'metadata' => '{}',
            'feedback_action' => $feedbackAction,
            'feedback_recorded_at' => $feedbackAction === null ? null : $createdAt,
            'used_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
