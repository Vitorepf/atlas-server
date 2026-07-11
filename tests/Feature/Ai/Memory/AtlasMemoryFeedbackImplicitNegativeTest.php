<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryUsage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

final class AtlasMemoryFeedbackImplicitNegativeTest extends TestCase
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

    public function test_identity_based_ignored_implicit_is_dry_run_by_default_and_apply_never_archives_or_inactivates(): void
    {
        $entry = $this->createActiveEntry('Atlas Memory Ranking Rules');
        for ($i = 0; $i < 3; $i++) {
            $this->insertRecallUsage($entry->id);
        }

        $diffPath = sys_get_temp_dir().'/atlas-ignored-implicit-'.bin2hex(random_bytes(4)).'.txt';
        file_put_contents($diffPath, "diff --git a/app/Unrelated.php b/app/Unrelated.php\n+// unrelated widget change\n");

        try {
            $dryRunExit = Artisan::call('atlas:memory:feedback-implicit', [
                '--diff' => $diffPath,
                '--min-ignored-sessions' => 3,
                '--json' => true,
            ]);
            $dryRunOutput = Artisan::output();

            $this->assertSame(0, $dryRunExit);
            $this->assertStringContainsString('"marked_ignored_implicit": 3', $dryRunOutput);
            $this->assertSame(0, AtlasMemoryEntryUsage::query()->where('feedback_action', 'ignored_implicit')->count());

            $applyExit = Artisan::call('atlas:memory:feedback-implicit', [
                '--diff' => $diffPath,
                '--min-ignored-sessions' => 3,
                '--apply' => true,
                '--json' => true,
            ]);
        } finally {
            @unlink($diffPath);
        }

        $this->assertSame(0, $applyExit);
        $this->assertSame(3, AtlasMemoryEntryUsage::query()->where('feedback_action', 'ignored_implicit')->count());

        $entry->refresh();
        $this->assertSame('active', $entry->status);
        $this->assertNull($entry->archived_at);
        $this->assertSame(0, data_get($entry->metadata, 'governance.feedback.negative_count'));
        $this->assertSame(3, data_get($entry->metadata, 'governance.feedback.ignored_count'));
    }

    public function test_explicit_feedback_command_records_not_useful_for_latest_unrated_usage(): void
    {
        $entry = $this->createActiveEntry('Explicit Feedback Surface');
        $usage = $this->insertRecallUsage($entry->id);

        $exit = Artisan::call('atlas:memory:feedback', [
            'memory' => (string) $entry->id,
            '--action' => 'not_useful',
            '--comment' => 'operator marked it noisy',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('not_useful', $usage->fresh()->feedback_action);
        $this->assertSame('operator marked it noisy', $usage->fresh()->feedback_comment);
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

    private function insertRecallUsage(string $memoryEntryId): AtlasMemoryEntryUsage
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
            'feedback_action' => null,
            'used_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return AtlasMemoryEntryUsage::query()->findOrFail($id);
    }
}
