<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryUsage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * D4 (Obra #18) — a recalled memory whose subject appears in the session diff is
 * marked useful_implicit (cited ∧ in the change); a diff that never mentions it
 * leaves the usage untouched. Closes the 0/18320 dead-write feedback gap. Runs on
 * the two memory tables migrated in isolation (the pgsql suite never boots here).
 */
final class AtlasMemoryFeedbackImplicitCommandTest extends TestCase
{
    /** @var list<object> */
    private array $migrations = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'database/migrations/2026_05_02_000000_create_atlas_memory_entries_table.php',
            'database/migrations/2026_05_02_001000_create_atlas_memory_entry_usages_table.php',
        ] as $path) {
            $migration = require base_path($path);
            $migration->down();
            $migration->up();
            $this->migrations[] = $migration;
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->migrations) as $migration) {
            $migration->down();
        }
        parent::tearDown();
    }

    public function test_marks_useful_implicit_when_the_memory_subject_is_in_the_diff(): void
    {
        $entry = $this->seedEntry('AtlasTaskScopedCommitter fail-closed lock');
        $usage = $this->seedUsage($entry->id);

        $diffPath = sys_get_temp_dir().'/atlas-d4-diff-'.bin2hex(random_bytes(4)).'.txt';
        file_put_contents($diffPath, "diff --git a/app/Services/Ai/SelfConstruction/AtlasTaskScopedCommitter.php\n+// commit scope\n");

        try {
            $code = Artisan::call('atlas:memory:feedback-implicit', ['--diff' => $diffPath, '--apply' => true, '--json' => true]);
            $out = Artisan::output();
        } finally {
            @unlink($diffPath);
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('"marked_useful_implicit": 1', $out);
        $this->assertSame('useful_implicit', $usage->fresh()->feedback_action);
    }

    public function test_diff_without_the_subject_leaves_the_usage_untouched(): void
    {
        $entry = $this->seedEntry('Quantum trading arbitrage ledger');
        $usage = $this->seedUsage($entry->id);

        $diffPath = sys_get_temp_dir().'/atlas-d4-diff-'.bin2hex(random_bytes(4)).'.txt';
        file_put_contents($diffPath, "diff --git a/app/Unrelated/Widget.php\n+// nothing relevant here\n");

        try {
            Artisan::call('atlas:memory:feedback-implicit', ['--diff' => $diffPath, '--apply' => true, '--json' => true]);
        } finally {
            @unlink($diffPath);
        }

        $this->assertNull($usage->fresh()->feedback_action, 'no subject match ⇒ no fabricated feedback');
    }

    private function seedEntry(string $title): AtlasMemoryEntry
    {
        return AtlasMemoryEntry::query()->create([
            'id' => (string) Str::uuid(),
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'status' => 'active',
            'title' => $title,
            'summary' => $title,
            'body' => $title.' — body',
        ]);
    }

    private function seedUsage(string $entryId): AtlasMemoryEntryUsage
    {
        return AtlasMemoryEntryUsage::query()->create([
            'id' => (string) Str::uuid(),
            'memory_entry_id' => $entryId,
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'feedback_action' => null,
            'created_at' => now(),
        ]);
    }
}
