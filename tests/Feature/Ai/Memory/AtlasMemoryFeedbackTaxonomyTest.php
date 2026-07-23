<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryUsage;
use App\Services\Ai\Memory\AtlasMemoryQualityService;
use App\Services\Ai\Memory\AtlasMemoryRecallConcentrationDemotion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

final class AtlasMemoryFeedbackTaxonomyTest extends TestCase
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

    public function test_scorecard_demotion_and_db_query_share_the_same_feedback_taxonomy(): void
    {
        $activeEntryA = $this->createActiveEntry('Entry A');
        $activeEntryB = $this->createActiveEntry('Entry B');
        $archivedEntry = $this->createActiveEntry('Archived', archived: true);

        $activeEntryIds = [$activeEntryA, $activeEntryB];

        $this->insertUsage($activeEntryA, 'useful');
        $this->insertUsage($activeEntryA, 'useful_implicit');
        $this->insertUsage($activeEntryA, 'not_useful');
        $this->insertUsage($activeEntryA, 'wrong_context');
        $this->insertUsage($activeEntryB, 'useful_implicit');
        $this->insertUsage($activeEntryB, 'stale');
        $this->insertUsage($activeEntryB, 'dismissed');
        $this->insertUsage($archivedEntry, 'useful');
        $this->insertUsage($archivedEntry, 'useful_implicit');

        $scorecard = app(AtlasMemoryQualityService::class)->scorecard();
        $feedback = data_get($scorecard, 'counts.feedback');

        $demotionStats = app(AtlasMemoryRecallConcentrationDemotion::class)
            ->feedbackStatsForEntries($activeEntryIds);
        $demotionPositive = array_sum(array_column($demotionStats, 'positive_count'));
        $demotionNegative = array_sum(array_column($demotionStats, 'negative_count'));

        $dbCounts = $this->directFeedbackCounts($activeEntryIds);

        $this->assertSame(7, $feedback['feedback_total']);
        $this->assertSame(1, $feedback['positive_explicit']);
        $this->assertSame(2, $feedback['positive_implicit']);
        $this->assertSame(3, $feedback['positive']);
        $this->assertSame(3, $feedback['negative']);
        $this->assertSame(1, $feedback['wrong_context']);
        $this->assertSame(1, $feedback['stale']);

        $this->assertSame($feedback['positive'], $demotionPositive);
        $this->assertSame($feedback['negative'], $demotionNegative);
        $this->assertSame($feedback['positive_explicit'], $dbCounts['positive_explicit']);
        $this->assertSame($feedback['positive_implicit'], $dbCounts['positive_implicit']);
        $this->assertSame($feedback['positive'], $dbCounts['positive']);
        $this->assertSame($feedback['negative'], $dbCounts['negative']);
    }

    private function createActiveEntry(string $title, bool $archived = false): string
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
            'archived_at' => $archived ? now() : null,
        ]);

        return (string) $entry->id;
    }

    private function insertUsage(string $memoryEntryId, string $feedbackAction): void
    {
        $now = now();

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
            'feedback_recorded_at' => $now,
            'used_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<int,string>  $activeEntryIds
     * @return array{positive:int,positive_explicit:int,positive_implicit:int,negative:int}
     */
    private function directFeedbackCounts(array $activeEntryIds): array
    {
        $query = AtlasMemoryEntryUsage::query()
            ->whereIn('memory_entry_id', $activeEntryIds)
            ->whereNotNull('feedback_action');

        $positiveExplicit = (clone $query)
            ->whereIn('feedback_action', AtlasMemoryEntryUsage::positiveExplicitFeedbackActions())
            ->count();
        $positiveImplicit = (clone $query)
            ->whereIn('feedback_action', AtlasMemoryEntryUsage::positiveImplicitFeedbackActions())
            ->count();

        return [
            'positive_explicit' => $positiveExplicit,
            'positive_implicit' => $positiveImplicit,
            'positive' => $positiveExplicit + $positiveImplicit,
            'negative' => (clone $query)
                ->whereIn('feedback_action', AtlasMemoryEntryUsage::negativeFeedbackActions())
                ->count(),
        ];
    }
}
