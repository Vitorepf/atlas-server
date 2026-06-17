<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopResourceGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GAP-2 (24h endurance) — reap LEAKED code-symbol rows from dead loop scenario workspaces. A SIGKILL'd
 * materialization leaks indexed atlas_engineering_code_symbols rows forever (the ~11.9M-row OOM). The GC
 * deletes ONLY old `atlas-loop-scn-*` rows and NEVER the real workspaces, a freshly-indexed live scenario,
 * or a row whose age cannot be proven (null created_at).
 */
final class AtlasLoopSymbolGcTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_engineering_code_symbols')) {
            (require base_path('database/migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php'))->up();
        }
        if (! Schema::hasColumn('atlas_engineering_code_symbols', 'workspace_id')) {
            (require base_path('database/migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();
        }
    }

    private function seedSymbol(string $workspaceId, ?string $createdAt): void
    {
        $hash = Str::random(40);
        DB::table('atlas_engineering_code_symbols')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'symbol_type' => 'method',
            'symbol_name' => 'foo_'.$hash,
            'file_path' => 'src/Foo.php',
            'source_hash' => $hash,
            'status' => 'active',
            'docs_status' => 'undocumented',
            'related_doc_ids_json' => '[]',
            'metadata' => '{}',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function rowsFor(string $workspaceId): int
    {
        return DB::table('atlas_engineering_code_symbols')->where('workspace_id', $workspaceId)->count();
    }

    public function test_reaps_only_old_leaked_scenario_symbols(): void
    {
        $old = now()->subDays(3)->toDateTimeString();
        $fresh = now()->toDateTimeString();

        // leaked + old => MUST be reaped
        $this->seedSymbol('atlas-loop-scn-aaa-0-x', $old);
        $this->seedSymbol('atlas-loop-scn-aaa-1-y', $old);
        // leaked but FRESH (a live worker indexing right now) => spared by the safety window
        $this->seedSymbol('atlas-loop-scn-live-0-z', $fresh);
        // real workspaces, even old => never match the scn- prefix => spared
        $this->seedSymbol('atlas-server', $old);
        $this->seedSymbol('atlas', $old);
        // leaked but NULL created_at => age unprovable => spared (conservative)
        $this->seedSymbol('atlas-loop-scn-nullts-0-w', null);

        $deleted = app(AtlasLoopResourceGate::class)->reapLeakedCodeSymbols(7200);

        $this->assertSame(2, $deleted, 'exactly the 2 old leaked scenario symbols are reaped');
        $this->assertSame(0, $this->rowsFor('atlas-loop-scn-aaa-0-x'));
        $this->assertSame(0, $this->rowsFor('atlas-loop-scn-aaa-1-y'));
        $this->assertSame(1, $this->rowsFor('atlas-loop-scn-live-0-z'), 'a fresh live scenario is NEVER touched');
        $this->assertSame(1, $this->rowsFor('atlas-server'), 'the real workspace is NEVER touched');
        $this->assertSame(1, $this->rowsFor('atlas'), 'the real workspace is NEVER touched');
        $this->assertSame(1, $this->rowsFor('atlas-loop-scn-nullts-0-w'), 'null created_at is spared (age unprovable)');
    }

    public function test_is_idempotent_and_safe_on_empty(): void
    {
        $this->assertSame(0, app(AtlasLoopResourceGate::class)->reapLeakedCodeSymbols(7200), 'no leaks => 0 deleted, no error');
        $this->seedSymbol('atlas-loop-scn-aaa-0-x', now()->subDays(3)->toDateTimeString());
        $this->assertSame(1, app(AtlasLoopResourceGate::class)->reapLeakedCodeSymbols(7200));
        $this->assertSame(0, app(AtlasLoopResourceGate::class)->reapLeakedCodeSymbols(7200), 'second run finds nothing (idempotent)');
    }
}
