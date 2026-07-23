<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use App\Services\Ai\MemoryGovernance\AtlasMemoryGovernanceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * B2d (fechamento ACOS) — the D3 auto-relation hook: relateNewEntry() relates a
 * just-written memory against its (memory_type, scope_type, scope_id) bucket the
 * moment it lands, so the duplicate/conflict graph accrues ON WRITE instead of only
 * on a manual `atlas:memory:govern scan`. It reuses the same detectors as scan() and
 * is deduped by the unique pair index (idempotent). This proves the mechanism the
 * registry (record/upsert/curate) and MCP record path now fire on every memory write.
 */
final class AtlasMemoryAutoRelationHookTest extends TestCase
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
        // table the model needs directly so the test runs on the sqlite suite (mirrors
        // AtlasMemoryRelationsLinkTest).
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

    public function test_relate_new_entry_creates_a_duplicate_relation_and_is_idempotent(): void
    {
        $governance = app(AtlasMemoryGovernanceService::class);

        $this->mkEntry('First decision', 'identical shared body');
        $new = $this->mkEntry('Second decision', 'identical shared body');

        $governance->relateNewEntry($new);

        $relations = AtlasMemoryEntryRelation::query()->get();
        self::assertCount(1, $relations, 'an identical-content peer yields exactly one duplicate relation');
        self::assertSame('duplicate', $relations->first()->relation_type);

        // Idempotent: re-running the hook must not create a second row (updateOrCreate
        // on the unique (source, target, type) pair).
        $governance->relateNewEntry($new);
        self::assertSame(1, AtlasMemoryEntryRelation::query()->count());
    }

    public function test_relate_new_entry_creates_a_conflict_relation_for_same_title_different_body(): void
    {
        $governance = app(AtlasMemoryGovernanceService::class);

        $this->mkEntry('Shared title', 'body one');
        $new = $this->mkEntry('Shared title', 'body two — different content');

        $governance->relateNewEntry($new);

        $relation = AtlasMemoryEntryRelation::query()->where('relation_type', 'conflict')->first();
        self::assertNotNull($relation, 'same title + different body → a conflict relation');
    }

    public function test_relate_new_entry_is_a_noop_when_alone_in_its_bucket(): void
    {
        $governance = app(AtlasMemoryGovernanceService::class);
        $new = $this->mkEntry('Lonely decision', 'unique body');

        $governance->relateNewEntry($new);

        self::assertSame(0, AtlasMemoryEntryRelation::query()->count());
    }

    private function mkEntry(string $title, string $body): AtlasMemoryEntry
    {
        return AtlasMemoryEntry::query()->create([
            'id' => (string) Str::uuid(),
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'status' => 'active',
            'title' => $title,
            'summary' => $title,
            'body' => $body,
        ]);
    }
}
