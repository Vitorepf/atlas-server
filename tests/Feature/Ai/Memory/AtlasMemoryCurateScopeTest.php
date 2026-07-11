<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasMemoryCurateScopeTest extends TestCase
{
    /** @var list<object> */
    private array $migrations = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'database/migrations/2026_05_02_000000_create_atlas_memory_entries_table.php',
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

    public function test_curate_can_rescope_and_reclassify_to_a_non_long_horizon_scope(): void
    {
        $entry = $this->memory();
        $workspaceId = hash('sha256', base_path());

        $code = Artisan::call('atlas:memory:curate', [
            'id' => $entry->id,
            '--summary' => 'Updated summary remains retrievable from workspace scope.',
            '--scope-type' => 'workspace',
            '--scope-id' => $workspaceId,
            '--type' => 'preference',
            '--json' => true,
        ]);

        $this->assertSame(0, $code);
        $entry = $entry->fresh();

        $this->assertSame('workspace', $entry->scope_type);
        $this->assertSame($workspaceId, $entry->scope_id);
        $this->assertSame('preference', $entry->memory_type);

        $recallCode = Artisan::call('atlas:memory:recall', [
            'query' => ['workspace retrieval preference'],
            '--workspace' => base_path(),
            '--peek' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $recallCode);
        $this->assertContains($entry->id, collect(data_get($payload, 'memory_recall.sources.registry', []))->pluck('id')->all());
    }

    public function test_curate_refuses_long_horizon_scopes_so_delta_guard_cannot_be_bypassed(): void
    {
        $entry = $this->memory();

        $code = Artisan::call('atlas:memory:curate', [
            'id' => $entry->id,
            '--scope-type' => 'long_horizon',
            '--scope-id' => 'atlas-os',
            '--json' => true,
        ]);

        $this->assertSame(1, $code);
        $this->assertSame('global', $entry->fresh()->scope_type);
    }

    public function test_curate_validates_scope_and_type_enums(): void
    {
        $entry = $this->memory();

        $badScope = Artisan::call('atlas:memory:curate', [
            'id' => $entry->id,
            '--scope-type' => 'made_up',
            '--scope-id' => 'x',
            '--json' => true,
        ]);
        $badType = Artisan::call('atlas:memory:curate', [
            'id' => $entry->id,
            '--type' => 'random_type',
            '--json' => true,
        ]);

        $this->assertSame(1, $badScope);
        $this->assertSame(1, $badType);
    }

    private function memory(): AtlasMemoryEntry
    {
        return AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Workspace retrieval preference',
            'summary' => 'Workspace retrieval preference summary',
            'body' => 'motivo: this entry proves workspace scoped recall remains visible. provenance: "workspace scoped recall remains visible."',
            'status' => 'active',
            'priority' => 90,
            'importance' => 4,
            'confidence' => 0.9,
        ]);
    }
}
