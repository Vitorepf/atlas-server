<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasMemoryRecallRelationsConsumerTest extends TestCase
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
        foreach (['stale_after', 'valid_until'] as $column) {
            if (! Schema::hasColumn('atlas_memory_entries', $column)) {
                Schema::table('atlas_memory_entries', function (Blueprint $table) use ($column): void {
                    $table->timestamp($column)->nullable();
                });
            }
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

    public function test_recall_rows_are_annotated_with_related_knowledge_relations(): void
    {
        $source = $this->memory('AOBG context pack rule', 'context pack brain rule');
        $target = $this->memory('Knowledge governance source of truth', 'canonical docs source truth');

        AtlasMemoryEntryRelation::query()->create([
            'source_memory_entry_id' => $source->id,
            'target_memory_entry_id' => $target->id,
            'relation_type' => 'related',
            'status' => 'resolved',
            'confidence' => 0.9,
            'reason' => 'Both memories cite the canonical docs/source-of-truth governance cluster.',
            'metadata' => ['cluster' => 'governance'],
        ]);

        $code = Artisan::call('atlas:memory:recall', [
            'query' => ['context pack brain rule'],
            '--peek' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $row = collect(data_get($payload, 'memory_recall.sources.registry', []))->firstWhere('id', $source->id);

        $this->assertSame(0, $code);
        $this->assertNotNull($row);
        $this->assertSame($target->id, data_get($row, 'relations.related.0.id'));
        $this->assertSame('related', data_get($row, 'relations.related.0.relation_type'));
        $this->assertStringContainsString('canonical docs', data_get($row, 'relations.related.0.reason'));
    }

    public function test_recall_rows_are_annotated_with_superseded_by_when_current_entry_supersedes_an_old_one(): void
    {
        $old = $this->memory('Old docs projection rule', 'old projection rule');
        $current = $this->memory('Current docs projection rule', 'current projection rule');
        $old->forceFill(['superseded_by_id' => $current->id])->save();

        AtlasMemoryEntryRelation::query()->create([
            'source_memory_entry_id' => $old->id,
            'target_memory_entry_id' => $current->id,
            'relation_type' => 'supersedes',
            'status' => 'resolved',
            'confidence' => 1.0,
            'reason' => 'Current rule supersedes the old projection rule.',
            'metadata' => ['cluster' => 'projection'],
        ]);

        $code = Artisan::call('atlas:memory:recall', [
            'query' => ['current projection rule'],
            '--peek' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $row = collect(data_get($payload, 'memory_recall.sources.registry', []))->firstWhere('id', $current->id);

        $this->assertSame(0, $code);
        $this->assertNotNull($row);
        $this->assertSame($old->id, data_get($row, 'relations.supersedes.0.id'));
        $this->assertSame('supersedes', data_get($row, 'relations.supersedes.0.relation_type'));
    }

    public function test_temporal_recall_demotion_ranks_stale_below_fresh_equivalent_without_hiding_it(): void
    {
        config(['atlas.semantic_memory.temporal_recall_demotion_enabled' => true]);

        $stale = $this->memory('Temporal ranking stale', 'temporal ranking target');
        $stale->forceFill(['stale_after' => now()->subDay()])->save();
        $fresh = $this->memory('Temporal ranking fresh', 'temporal ranking target');

        $code = Artisan::call('atlas:memory:recall', [
            'query' => ['temporal ranking target'],
            '--peek' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $registry = collect(data_get($payload, 'memory_recall.sources.registry', []));

        $this->assertSame(0, $code);
        $this->assertSame($fresh->id, data_get($payload, 'memory_recall.recall.0.source_ref_id'));
        $this->assertNotNull($registry->firstWhere('id', $stale->id), 'stale entry is demoted, not hidden');
        $this->assertSame(0.5, data_get($registry->firstWhere('id', $stale->id), 'explain.temporal_ranking.factor'));
        $this->assertContains('stale_after_elapsed', data_get($registry->firstWhere('id', $stale->id), 'explain.temporal_ranking.flags'));
    }

    public function test_superseded_entry_is_soft_demoted_when_superseder_is_outside_recall_pool(): void
    {
        config(['atlas.semantic_memory.temporal_recall_demotion_enabled' => true]);

        $outsideSuperseder = $this->memory('Unrelated superseder', 'outside-only needle');
        $outsideSuperseder->forceFill([
            'scope_type' => 'project',
            'scope_id' => 'outside-project',
        ])->save();
        $old = $this->memory('Temporal superseded old', 'orphan superseded target');
        $old->forceFill(['superseded_by_id' => $outsideSuperseder->id])->save();

        $code = Artisan::call('atlas:memory:recall', [
            'query' => ['orphan superseded target'],
            '--limit' => 1,
            '--peek' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $row = collect(data_get($payload, 'memory_recall.sources.registry', []))->firstWhere('id', $old->id);

        $this->assertSame(0, $code);
        $this->assertNotNull($row, 'superseded entry remains recoverable when the superseder is outside the pool');
        $this->assertSame(0.35, data_get($row, 'explain.temporal_ranking.factor'));
        $this->assertContains('superseded_without_superseder_in_pool', data_get($row, 'explain.temporal_ranking.flags'));
    }

    private function memory(string $title, string $needle): AtlasMemoryEntry
    {
        return AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => $title,
            'summary' => $title.' summary',
            'body' => 'motivo: '.$needle.' remains provider-safe. provenance: "'.$needle.' remains provider-safe."',
            'status' => 'active',
            'priority' => 90,
            'importance' => 4,
            'confidence' => 0.9,
        ]);
    }
}
