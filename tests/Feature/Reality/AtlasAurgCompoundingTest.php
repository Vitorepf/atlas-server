<?php

declare(strict_types=1);

namespace Tests\Feature\Reality;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AURG Phase-2 / F4 — compounding + temporal + status (Salto 1 "AURG vivo").
 *
 * Locks the F4 contract:
 *  - COMPOUNDING: a memory write through AtlasMemoryRegistryService best-effort
 *    upserts its brain node + row-scoped deterministic linkers (THIS row only,
 *    never a full sync inline), and is FAIL-OPEN — an absent brain table or a
 *    disabled flag never blocks the memory write;
 *  - TEMPORAL: a FULL atlas:aurg:ingest records a REAL snapshot tick (non-null
 *    snapshot_hash built by the canonical Builder over real graph state, honest
 *    counts in delta_summary), the chain still verifies, the hash is
 *    state-deterministic (unchanged store ⇒ identical hash), and partial
 *    --source syncs never tick;
 *  - STATUS: atlas:aurg:status reports live store counts, chain integrity and
 *    the growth delta computed from the temporal chain — and stays honest when
 *    the store is missing;
 *  - SCHEDULE: the daily full sync is registered (schedule:list precedent —
 *    mirrors AtlasAiLedgerProjectionCommandTest).
 *
 * Boots only the needed tables in setUp (the proven house pattern); sqlite-only —
 * no pgvector anywhere in the brain path.
 */
final class AtlasAurgCompoundingTest extends TestCase
{
    private string $temporalLogPath;

    private AtlasUnifiedRealityGraphTemporalService $temporal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootTables();
        config()->set('atlas.aurg.enabled', true);
        config()->set('atlas.aurg.ingest_on_write', true);

        // One temporal chain per test, shared by every command invocation.
        $this->temporalLogPath = sys_get_temp_dir().'/atlas_aurg_f4_'.uniqid('', true).'.jsonl';
        $this->temporal = app(AtlasUnifiedRealityGraphTemporalService::class);
        $this->temporal->setLogPathForTesting($this->temporalLogPath);
        $this->app->instance(AtlasUnifiedRealityGraphTemporalService::class, $this->temporal);
    }

    protected function tearDown(): void
    {
        @unlink($this->temporalLogPath);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 1. COMPOUNDING — ingest-on-write
    // ------------------------------------------------------------------

    public function test_memory_write_accrues_brain_node_and_row_scoped_links(): void
    {
        // Brain pre-seeded with the LINK TARGETS only (code modules + domains).
        $this->service()->sync(['code', 'domains']);

        // Control row written BEHIND the registry (direct model create): if the
        // accrual were secretly a full sync, this row would gain a node too.
        $control = AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Control row not written through the registry',
            'body' => 'must not accrue',
            'recorded_at' => now(),
        ]);

        $entry = $this->registry()->record([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Accrued reality-graph memory',
            'body' => 'Ingest-on-write accrual test.',
            'tags' => ['programming'],
            'metadata' => ['paths' => ['app/Services/Ai/Reality']],
        ]);

        // The node appeared from the WRITE, with no memory-layer sync ever run.
        $nodeId = 'memory:memory_entry:'.$entry->id;
        $node = AtlasAurgNode::query()->whereKey($nodeId)->first();
        $this->assertNotNull($node, 'expected ingest-on-write to upsert the brain node');
        $this->assertSame('memory', (string) $node->source_kind);
        $this->assertTrue((bool) $node->provider_safe);

        // Row-scoped linkers ran for THAT ROW: exact path → module (1.0, cited)…
        $codeEdge = AtlasAurgEdge::query()
            ->where('from_node_id', $nodeId)
            ->where('to_node_id', 'code:module:atlas-server/services-ai-reality')
            ->where('kind', 'references')
            ->first();
        $this->assertNotNull($codeEdge, 'expected memory→module references edge from accrual');
        $this->assertSame(1.0, (float) $codeEdge->confidence);
        $this->assertSame('app/Services/Ai/Reality', $codeEdge->meta['matched_path'] ?? null);

        // …and taxonomy-resolved tag → canonical domain (1.0).
        $domainEdge = AtlasAurgEdge::query()
            ->where('from_node_id', $nodeId)
            ->where('to_node_id', 'domain:domain:engineering')
            ->where('kind', 'belongs_to')
            ->first();
        $this->assertNotNull($domainEdge, 'expected memory→engineering belongs_to edge from accrual');
        $this->assertSame(1.0, (float) $domainEdge->confidence);

        // ROW ONLY — the control row did NOT accrue (no inline full sync).
        $this->assertFalse(
            AtlasAurgNode::query()->whereKey('memory:memory_entry:'.$control->id)->exists(),
            'accrual must never full-sync the memory layer inline',
        );
        $this->assertSame(1, (int) AtlasAurgNode::query()->where('source_kind', 'memory')->count());
    }

    public function test_memory_write_is_fail_open_when_brain_table_is_absent(): void
    {
        Schema::drop('atlas_aurg_nodes');

        $entry = $this->registry()->record([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Write must survive a missing brain',
            'body' => 'fail-open proof',
        ]);

        // The memory write SUCCEEDED even though the brain store is gone.
        $this->assertTrue(AtlasMemoryEntry::query()->whereKey($entry->id)->exists());
    }

    public function test_ingest_on_write_honest_skips_kill_switch_and_non_live_rows(): void
    {
        $this->service()->sync(['domains']);

        // Kill-switch off ⇒ no accrual (write still succeeds).
        config()->set('atlas.aurg.ingest_on_write', false);
        $off = $this->registry()->record([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Flag off',
            'body' => 'no accrual',
        ]);
        $this->assertFalse(AtlasAurgNode::query()->whereKey('memory:memory_entry:'.$off->id)->exists());

        // Archived rows are not in the full-sync projection ⇒ never accrue either.
        config()->set('atlas.aurg.ingest_on_write', true);
        $archived = $this->registry()->record([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Archived row',
            'body' => 'no accrual',
            'status' => 'archived',
        ]);
        $this->assertFalse(AtlasAurgNode::query()->whereKey('memory:memory_entry:'.$archived->id)->exists());
    }

    // ------------------------------------------------------------------
    // 2. TEMPORAL — real snapshot ticks on the full-sync path
    // ------------------------------------------------------------------

    public function test_full_sync_records_real_snapshot_tick_and_chain_verifies(): void
    {
        $this->artisan('atlas:aurg:ingest')->assertSuccessful();

        $ticks = $this->ticks();
        $this->assertCount(1, $ticks, 'expected exactly one tick from the full sync');
        $tick = $ticks[0];

        // REAL graph state: snapshot_recorded kind, NON-NULL 64-hex hash, honest counts.
        $this->assertSame('snapshot_recorded', $tick['kind']);
        $this->assertSame('atlas', $tick['actor']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $tick['snapshot_hash']);
        $this->assertSame((int) AtlasAurgNode::query()->count(), (int) $tick['delta_summary']['node_count']);
        $this->assertSame((int) AtlasAurgEdge::query()->count(), (int) $tick['delta_summary']['edge_count']);
        $this->assertFalse((bool) $tick['delta_summary']['truncated']);
        $this->assertGreaterThan(0, (int) $tick['delta_summary']['node_count']);

        $verify = $this->temporal->verifyChain();
        $this->assertTrue($verify['chain_intact']);
        $this->assertSame(1, $verify['ticks_walked']);
    }

    public function test_snapshot_hash_is_state_deterministic_and_partial_sync_never_ticks(): void
    {
        $this->artisan('atlas:aurg:ingest')->assertSuccessful();
        usleep(1100000); // distinct ISO-second timestamps (house precedent)
        $this->artisan('atlas:aurg:ingest')->assertSuccessful();

        $ticks = $this->ticks();
        $this->assertCount(2, $ticks);
        // Unchanged store ⇒ IDENTICAL snapshot_hash (state fingerprint, not noise)…
        $this->assertSame($ticks[0]['snapshot_hash'], $ticks[1]['snapshot_hash']);
        // …while the chain still links tick-to-tick.
        $this->assertSame($ticks[0]['tick_hash'], $ticks[1]['prev_tick_hash']);
        $this->assertTrue($this->temporal->verifyChain()['chain_intact']);

        // A PARTIAL sync (one layer) must never tick the 4D chain.
        $this->artisan('atlas:aurg:ingest', ['--source' => 'domains'])->assertSuccessful();
        $this->assertCount(2, $this->ticks(), 'partial --source sync must not record a tick');

        // A real state change flips the hash.
        usleep(1100000);
        AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'New row changes graph state',
            'body' => 'hash must change',
            'recorded_at' => now(),
        ]);
        $this->artisan('atlas:aurg:ingest')->assertSuccessful();
        $ticks = $this->ticks();
        $this->assertCount(3, $ticks);
        $this->assertNotSame($ticks[1]['snapshot_hash'], $ticks[2]['snapshot_hash']);
        $this->assertTrue($this->temporal->verifyChain()['chain_intact']);
    }

    // ------------------------------------------------------------------
    // 3. STATUS — the honest health surface
    // ------------------------------------------------------------------

    public function test_status_reports_live_counts_growth_delta_and_flags(): void
    {
        $this->artisan('atlas:aurg:ingest')->assertSuccessful();
        usleep(1100000);

        // One ACCRUED memory between the two full syncs ⇒ growth of exactly +1 node.
        $this->registry()->record([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Growth row',
            'body' => 'one more node between snapshots',
        ]);
        $this->artisan('atlas:aurg:ingest')->assertSuccessful();

        $this->assertSame(0, Artisan::call('atlas:aurg:status', ['--json' => true]));
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        // Store: every number live from the tables, nothing fabricated.
        $this->assertTrue($payload['store']['available']);
        $this->assertSame((int) AtlasAurgNode::query()->count(), $payload['store']['nodes_total']);
        $this->assertSame((int) AtlasAurgEdge::query()->count(), $payload['store']['edges_total']);
        $this->assertSame(
            (int) AtlasAurgNode::query()->where('source_kind', 'memory')->count(),
            $payload['store']['nodes_by_source_kind']['memory'],
        );
        $this->assertSame(21, $payload['store']['nodes_by_source_kind']['domain']);
        $this->assertArrayHasKey('memory_entry', $payload['store']['nodes_by_kind']);
        $this->assertSame(
            (int) AtlasAurgEdge::query()->where('source', 'linker_code_domain')->count(),
            $payload['store']['edges_by_source']['linker_code_domain'] ?? 0,
        );
        $this->assertSame(
            (int) AtlasAurgEdge::query()->where('source', 'like', 'linker_%')->count(),
            $payload['store']['linker_edges_total'],
        );
        $this->assertNotNull($payload['store']['last_ingest_at']);
        $this->assertTrue($payload['coverage']['available']);
        $this->assertSame(
            $payload['store']['nodes_by_source_kind']['memory'],
            $payload['coverage']['memory_nodes'],
        );
        $this->assertGreaterThanOrEqual(0, $payload['coverage']['memory_cross_layer_nodes']);
        $this->assertLessThanOrEqual(1.0, $payload['coverage']['memory_cross_layer_coverage_ratio']);
        $this->assertGreaterThanOrEqual(0, $payload['coverage']['orphan_nodes']);
        $this->assertContains($payload['coverage']['status'], ['measured', 'needs_links', 'empty']);

        // Temporal: chain of 2 full-sync ticks, intact, growth delta == the accrual.
        $this->assertSame(2, $payload['temporal']['chain_length']);
        $this->assertSame(2, $payload['temporal']['snapshot_ticks']);
        $this->assertTrue($payload['temporal']['chain_intact']);
        $this->assertNull($payload['temporal']['chain_break_at']);
        $this->assertNotNull($payload['temporal']['last_snapshot']);
        $growth = $payload['temporal']['growth']['vs_previous_snapshot'];
        $this->assertSame(1, $growth['nodes_delta']);
        $this->assertSame(0, $growth['edges_delta']);
        $this->assertTrue($growth['snapshot_hash_changed']);
        // Store untouched since the last tick ⇒ live delta zero.
        $this->assertSame(0, $payload['temporal']['growth']['live_vs_last_snapshot']['nodes_delta']);
        $this->assertSame(0, $payload['temporal']['growth']['live_vs_last_snapshot']['edges_delta']);

        // Flags: real config + LIVE scheduler probe. The injection flag MIRRORS
        // config, so it is pinned BOTH ways here instead of inherited from the
        // machine .env (F5 ships ATLAS_OPEN_BRAIN_INJECTION_INCLUDE_REALITY_GRAPH=true
        // in the live environment — asserting the old default would couple this
        // test to whichever box runs it). Status is read-only: re-calling it
        // appends no ticks, so the temporal assertions above stay valid.
        config()->set('atlas.open_brain.injection.include_reality_graph', false);
        $this->assertSame(0, Artisan::call('atlas:aurg:status', ['--json' => true]));
        $flagsOff = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($flagsOff['flags']['injection_include_reality_graph']);

        config()->set('atlas.open_brain.injection.include_reality_graph', true);
        $this->assertSame(0, Artisan::call('atlas:aurg:status', ['--json' => true]));
        $flagsOn = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($flagsOn['flags']['injection_include_reality_graph']);

        $this->assertTrue($payload['flags']['ingest_on_write']);
        $this->assertTrue($payload['flags']['schedule_enabled']);
        $this->assertTrue($payload['flags']['schedule_registered']);
    }

    public function test_status_is_honest_when_store_is_missing(): void
    {
        Schema::drop('atlas_aurg_edges');
        Schema::drop('atlas_aurg_nodes');

        $this->assertSame(0, Artisan::call('atlas:aurg:status', ['--json' => true]));
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($payload['store']['available']);
        $this->assertSame('tables_missing', $payload['store']['reason']);
        $this->assertArrayNotHasKey('nodes_total', $payload['store'], 'no zeros pretending the store is empty');
    }

    // ------------------------------------------------------------------
    // 4. SCHEDULE — daily full sync registered
    // ------------------------------------------------------------------

    public function test_daily_full_sync_schedule_is_registered(): void
    {
        $exit = Artisan::call('schedule:list');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('atlas:aurg:ingest --prune --json', Artisan::output());
    }

    // ------------------------------------------------------------------
    // fixtures
    // ------------------------------------------------------------------

    private function registry(): AtlasMemoryRegistryService
    {
        return app(AtlasMemoryRegistryService::class);
    }

    private function service(): AtlasRealityGraphIngestionService
    {
        return new AtlasRealityGraphIngestionService(
            new CrossDomainTaxonomyMap,
            app(AtlasMemoryPrivacyService::class),
            app(AtlasCrossDomainMeshService::class),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function ticks(): array
    {
        if (! is_file($this->temporalLogPath)) {
            return [];
        }
        $out = [];
        foreach (file($this->temporalLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    private function bootTables(): void
    {
        foreach ([
            'migrations/2026_05_02_000000_create_atlas_memory_entries_table.php',
            'migrations/2026_05_02_004000_create_atlas_verbatim_memories_table.php',
            'migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php',
            'migrations/2026_05_20_150000_create_atlas_strategic_reality_tables.php',
            'migrations/2026_06_09_120000_create_atlas_aurg_graph_tables.php',
        ] as $file) {
            $migration = require database_path($file);
            $migration->down();
            $migration->up();
        }

        (require database_path('migrations/2026_05_02_005000_add_privacy_columns_to_atlas_memory_entries.php'))->up();
        if (! Schema::hasColumn('atlas_memory_entries', 'superseded_by_id')) {
            Schema::table('atlas_memory_entries', function (Blueprint $table): void {
                $table->uuid('superseded_by_id')->nullable();
            });
        }

        (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();

        // Evidence table manually (real migration's trigger is pgsql-only syntax).
        Schema::dropIfExists('atlas_engineering_evidence');
        Schema::create('atlas_engineering_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id')->index();
            $table->uuid('project_id')->nullable()->index();
            $table->uuid('trace_id')->nullable()->index();
            $table->string('evidence_type', 80);
            $table->string('target_id', 120)->nullable();
            $table->string('status', 32);
            $table->text('summary');
            $table->string('command', 500)->nullable();
            $table->text('output_excerpt')->nullable();
            $table->json('files')->default('[]');
            $table->json('metadata')->default('{}');
            $table->string('source', 160)->default('tasks.engineering.evidence');
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();
        });

        // One code module so the row-scoped memory→code linker has a real target.
        DB::table('atlas_engineering_code_modules')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => 'atlas-server',
            'slug' => 'services-ai-reality',
            'name' => 'Ai Reality',
            'layer' => 'services',
            'root_path' => 'app/Services/Ai/Reality',
            'status' => 'active',
            'source_hash' => hash('sha256', 'services-ai-reality'),
            'tags_json' => '[]',
            'related_docs_json' => '[]',
            'related_tests_json' => '[]',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
