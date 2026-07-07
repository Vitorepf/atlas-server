<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * D3 (Obra #18) — the 0-use relations command becomes a WRITE surface: `link`
 * creates a memory↔memory relation (the graph stops being empty) and `supersede`
 * marks the source superseded BY the target (a real superseded chain), setting
 * superseded_by_id AND recording the relation.
 */
final class AtlasMemoryRelationsLinkTest extends TestCase
{
    /** @var list<object> */
    private array $migrations = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'database/migrations/2026_05_02_000000_create_atlas_memory_entries_table.php',
            'database/migrations/2026_05_04_010000_add_superseded_by_to_atlas_memory_entries.php',
        ] as $path) {
            $migration = require base_path($path);
            $migration->down();
            $migration->up();
            $this->migrations[] = $migration;
        }
        // The real relations migration carries pgsql-only raw SQL; build the minimal
        // table the model needs directly so the test runs on the sqlite suite.
        Schema::dropIfExists('atlas_memory_entry_relations');
        Schema::create('atlas_memory_entry_relations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_memory_entry_id')->index();
            $table->uuid('target_memory_entry_id')->index();
            $table->string('relation_type', 40);
            $table->string('status', 24)->default('open');
            $table->float('confidence')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_memory_entry_relations');
        foreach (array_reverse($this->migrations) as $migration) {
            $migration->down();
        }
        parent::tearDown();
    }

    public function test_link_creates_a_relation_between_two_memories(): void
    {
        $a = $this->mkEntry('alpha');
        $b = $this->mkEntry('beta');

        $code = Artisan::call('atlas:memory:relations', [
            'action' => 'link',
            '--source-id' => $a->id,
            '--target-id' => $b->id,
            '--type' => ['duplicate'],
            '--json' => true,
        ]);

        $this->assertSame(0, $code);
        $this->assertSame(1, AtlasMemoryEntryRelation::query()
            ->where('source_memory_entry_id', $a->id)
            ->where('target_memory_entry_id', $b->id)
            ->where('relation_type', 'duplicate')
            ->count());
    }

    public function test_supersede_sets_superseded_by_and_records_a_chain(): void
    {
        $old = $this->mkEntry('v1');
        $new = $this->mkEntry('v2');

        $code = Artisan::call('atlas:memory:relations', [
            'action' => 'supersede',
            '--source-id' => $old->id,
            '--target-id' => $new->id,
            '--json' => true,
        ]);

        $this->assertSame(0, $code);
        $this->assertSame($new->id, $old->fresh()->superseded_by_id, 'source is superseded BY target');
        $this->assertNotNull(AtlasMemoryEntryRelation::query()
            ->where('source_memory_entry_id', $old->id)
            ->where('target_memory_entry_id', $new->id)
            ->first(), 'a superseded chain relation is recorded');
    }

    private function mkEntry(string $title): AtlasMemoryEntry
    {
        return AtlasMemoryEntry::query()->create([
            'id' => (string) Str::uuid(),
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'status' => 'active',
            'title' => $title,
            'summary' => $title,
            'body' => $title.' body',
        ]);
    }
}
