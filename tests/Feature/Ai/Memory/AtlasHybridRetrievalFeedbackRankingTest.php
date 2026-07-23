<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

final class AtlasHybridRetrievalFeedbackRankingTest extends TestCase
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

    public function test_feedback_multiplier_reranks_hybrid_recall_only_when_switch_is_forced_on(): void
    {
        $this->assertFalse((bool) config('atlas.memory.feedback_ranking_enabled'));

        $explicit = $this->entry('Explicit useful memory', 3);
        $implicit = $this->entry('Implicit useful memory', 2);
        $neutral = $this->entry('Neutral memory', 1);
        $negative = $this->entry('Negative memory', 0);

        $this->feedback($explicit->id, 'useful');
        for ($i = 0; $i < 12; $i++) {
            $this->feedback($implicit->id, 'useful_implicit');
        }
        for ($i = 0; $i < 2; $i++) {
            $this->feedback($negative->id, 'not_useful');
        }
        $this->feedback($neutral->id, null);

        config(['atlas.memory.feedback_ranking_enabled' => true]);

        $payload = app(AtlasHybridMemoryRetrievalService::class)->recall('sharedneedle', [], [], [
            'limit' => 4,
            'registry_limit' => 4,
            'include_verbatim' => false,
            'include_semantic' => false,
            'include_compounding' => false,
            'record_usage' => false,
        ]);
        $titles = array_column($payload['recall'], 'title');

        $this->assertSame([
            'Explicit useful memory',
            'Implicit useful memory',
            'Neutral memory',
            'Negative memory',
        ], $titles);

        $byTitle = collect($payload['recall'])->keyBy('title');
        $this->assertSame(1.15, data_get($byTitle['Explicit useful memory'], 'explain.feedback_ranking.factor'));
        $this->assertLessThan(
            data_get($byTitle['Explicit useful memory'], 'explain.feedback_ranking.factor'),
            data_get($byTitle['Implicit useful memory'], 'explain.feedback_ranking.factor'),
        );
        $this->assertSame(1.0, data_get($byTitle['Neutral memory'], 'explain.feedback_ranking.factor'));
        $this->assertSame(0.7, data_get($byTitle['Negative memory'], 'explain.feedback_ranking.factor'));
    }

    private function entry(string $title, int $ageDays): AtlasMemoryEntry
    {
        return AtlasMemoryEntry::query()->create([
            'id' => (string) Str::uuid(),
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => $title,
            'summary' => 'sharedneedle provider-safe summary',
            'body' => 'sharedneedle provider-safe body',
            'importance' => 3,
            'priority' => 70,
            'confidence' => 0.7,
            'status' => 'active',
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'source_type' => 'manual',
            'metadata' => ['privacy' => ['external_ai_allowed' => true]],
            'recorded_at' => now()->subDays($ageDays),
        ]);
    }

    private function feedback(string $memoryEntryId, ?string $action): void
    {
        $now = now();

        DB::table('atlas_memory_entry_usages')->insert([
            'id' => (string) Str::uuid(),
            'memory_entry_id' => $memoryEntryId,
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'source_type' => 'memory_recall',
            'position' => 1,
            'source_ref_json' => json_encode(['type' => 'atlas_memory_entry', 'id' => $memoryEntryId], JSON_THROW_ON_ERROR),
            'context_payload_json' => '{}',
            'metadata' => '{}',
            'feedback_action' => $action,
            'feedback_recorded_at' => $action === null ? null : $now,
            'used_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
