<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use App\Services\Ai\Memory\AtlasMemoryConflictResolutionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * MEM-02 — canonical knowledge relation verbs unlock honest relation_density.
 */
final class AtlasMemoryRelationTaxonomyTest extends TestCase
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

    public function test_model_types_match_shared_conflict_resolution_taxonomy(): void
    {
        $this->assertSame(
            AtlasMemoryConflictResolutionService::RELATION_TYPES,
            AtlasMemoryEntryRelation::TYPES,
        );
    }

    #[DataProvider('knowledgeVerbProvider')]
    public function test_knowledge_verbs_require_reason_and_start_resolved(string $verb): void
    {
        $a = $this->mkEntry('src-'.$verb);
        $b = $this->mkEntry('tgt-'.$verb);

        $missingReason = Artisan::call('atlas:memory:relations', [
            'action' => 'link',
            '--source-id' => $a->id,
            '--target-id' => $b->id,
            '--type' => [$verb],
            '--json' => true,
        ]);
        $this->assertSame(1, $missingReason);

        $code = Artisan::call('atlas:memory:relations', [
            'action' => 'link',
            '--source-id' => $a->id,
            '--target-id' => $b->id,
            '--type' => [$verb],
            '--reason' => 'citable rationale for '.$verb,
            '--json' => true,
        ]);
        $this->assertSame(0, $code);

        $relation = AtlasMemoryEntryRelation::query()
            ->where('source_memory_entry_id', $a->id)
            ->where('target_memory_entry_id', $b->id)
            ->first();
        $this->assertNotNull($relation);
        $this->assertSame($verb, $relation->relation_type);
        $this->assertSame('resolved', $relation->status);
        $this->assertSame('citable rationale for '.$verb, $relation->reason);
    }

    public function test_rejects_unknown_relation_type(): void
    {
        $a = $this->mkEntry('src-unknown');
        $b = $this->mkEntry('tgt-unknown');

        $code = Artisan::call('atlas:memory:relations', [
            'action' => 'link',
            '--source-id' => $a->id,
            '--target-id' => $b->id,
            '--type' => ['refines'],
            '--reason' => 'not a canonical verb',
            '--json' => true,
        ]);

        $this->assertSame(1, $code);
        $this->assertSame(0, AtlasMemoryEntryRelation::query()->count());
    }

    public function test_supersede_records_supersedes_relation_type(): void
    {
        $old = $this->mkEntry('v1');
        $new = $this->mkEntry('v2');

        $code = Artisan::call('atlas:memory:relations', [
            'action' => 'supersede',
            '--source-id' => $old->id,
            '--target-id' => $new->id,
            '--reason' => 'v2 replaces v1 after audit',
            '--json' => true,
        ]);

        $this->assertSame(0, $code);
        $relation = AtlasMemoryEntryRelation::query()
            ->where('source_memory_entry_id', $old->id)
            ->where('target_memory_entry_id', $new->id)
            ->first();
        $this->assertNotNull($relation);
        $this->assertSame(AtlasMemoryConflictResolutionService::VERDICT_SUPERSEDES, $relation->relation_type);
        $this->assertSame('resolved', $relation->status);
    }

    public function test_pathology_types_keep_open_default_without_required_reason(): void
    {
        $a = $this->mkEntry('dup-a');
        $b = $this->mkEntry('dup-b');

        $code = Artisan::call('atlas:memory:relations', [
            'action' => 'link',
            '--source-id' => $a->id,
            '--target-id' => $b->id,
            '--type' => ['duplicate'],
            '--json' => true,
        ]);

        $this->assertSame(0, $code);
        $relation = AtlasMemoryEntryRelation::query()->first();
        $this->assertNotNull($relation);
        $this->assertSame('duplicate', $relation->relation_type);
        $this->assertSame('open', $relation->status);
    }

    /** @return iterable<string,array{string}> */
    public static function knowledgeVerbProvider(): iterable
    {
        foreach (AtlasMemoryConflictResolutionService::KNOWLEDGE_RELATION_TYPES as $verb) {
            yield $verb => [$verb];
        }
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
