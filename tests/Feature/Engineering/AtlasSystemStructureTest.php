<?php

declare(strict_types=1);

use App\Services\Engineering\AtlasSystemStructureService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Integration test for AtlasSystemStructureService (criterion C1, STRUCTURE
 * AUTO-DERIVED). This deliberately hits the REAL code index / filesystem rather
 * than mocking, because the whole point is proving the structure tracks real code.
 * Determinism is kept via lower-bound assertions and a self-cleaning temp probe.
 *
 * NEVER uses RefreshDatabase: the runtime DB is pgsql with the populated index;
 * wiping it would defeat the test. The default test connection here is the in-memory
 * sqlite from phpunit.xml, on which the symbols table does NOT exist — so the
 * filesystem-source tests below resolve to the filesystem branch automatically.
 * The single index-source probe test creates ONLY the one symbols table it needs
 * and tearDown() drops it again, so no seeded table ever leaks into the other tests
 * (which would otherwise flip them to the empty index branch and break their
 * realistic-size thresholds).
 */
final class AtlasSystemStructureTest extends TestCase
{
    private const SYMBOLS_TABLE = 'atlas_engineering_code_symbols';

    /**
     * Guarantee the seeded index table NEVER survives a test: the in-memory sqlite
     * connection is shared across tests in the process, so a leaked table would make
     * the filesystem-source tests resolve to the (tiny, seeded) index branch and
     * fail their >=1000 thresholds. Idempotent drop keeps every other test honest.
     */
    protected function tearDown(): void
    {
        Schema::dropIfExists(self::SYMBOLS_TABLE);

        parent::tearDown();
    }

    /**
     * (a) A hardcoded 23-node topology fails this immediately: the derived counts
     * must be realistically large because they come from ~134k indexed symbols /
     * ~3244 service files / ~871 command classes — not a literal array.
     */
    public function test_derived_counts_are_realistically_large_not_a_hardcoded_map(): void
    {
        $structure = app(AtlasSystemStructureService::class)->deriveStructure();

        $this->assertTrue($structure['available'], 'structure must be derivable');
        $this->assertSame(AtlasSystemStructureService::SCHEMA_VERSION, $structure['schema_version']);
        $this->assertContains($structure['source'], ['index', 'filesystem']);
        $this->assertFalse($structure['writes']);
        $this->assertTrue($structure['claim_policy']['structure_is_derived_not_authored']);
        $this->assertTrue($structure['claim_policy']['reflects_new_code_without_source_edit']);

        $summary = $structure['summary'];

        // The load-bearing anti-stub thresholds. The replaced literal map had 23
        // nodes / 31 edges — these bounds are an order of magnitude beyond that.
        $this->assertGreaterThanOrEqual(1000, $summary['service_count'], 'service_count must reflect the real ~3244 services');
        $this->assertGreaterThanOrEqual(500, $summary['command_count'], 'command_count must reflect the real ~871 commands');
        $this->assertGreaterThanOrEqual(50, $summary['subsystem_count'], 'subsystems must reflect real Ai/<Subsystem> dirs');
        $this->assertGreaterThanOrEqual(5, $summary['area_count']);

        // node_count >> 23 and edge_count >> 31 (the old literal sizes).
        $this->assertGreaterThan(100, $summary['node_count']);
        $this->assertGreaterThan(100, $summary['edge_count']);
        $this->assertSame(count($structure['nodes']), $summary['node_count']);
        $this->assertSame(count($structure['edges']), $summary['edge_count']);

        // Counts are internally consistent (derived, not invented per field).
        $this->assertSame(
            $summary['service_count'] + $summary['command_count'],
            $summary['total_leaf_count'],
        );
        $this->assertSame(
            $summary['containment_edge_count'] + $summary['dependency_edge_count'],
            $summary['edge_count'],
        );
    }

    /**
     * (b) Several KNOWN-real subsystems must be present BY DERIVATION (we look them
     * up in the produced node graph; we do not assert them from a literal list in
     * the service). If the topology were hardcoded to the old 23 nodes, none of
     * these app/Services/Ai/* subsystems would appear.
     */
    public function test_known_real_subsystems_are_present_by_derivation(): void
    {
        $structure = app(AtlasSystemStructureService::class)->deriveStructure();
        $this->assertTrue($structure['available']);

        $subsystemPaths = collect($structure['nodes'])
            ->filter(fn (array $node): bool => ($node['kind'] ?? null) === 'subsystem')
            ->pluck('real_path')
            ->all();
        $areaPaths = collect($structure['nodes'])
            ->filter(fn (array $node): bool => ($node['kind'] ?? null) === 'area')
            ->pluck('real_path')
            ->all();

        // Atlas subsystems that demonstrably exist on disk today.
        $this->assertContains('app/Services/Ai/Aaeos', $subsystemPaths);
        $this->assertContains('app/Services/Engineering', $subsystemPaths);

        // At least one major Forge/Decide subsystem must be derived (kept as an OR
        // so a future rename of one does not falsely fail the criterion).
        $this->assertTrue(
            in_array('app/Services/Ai/Forge', $subsystemPaths, true)
                || in_array('app/Services/Ai/AtlasForge', $subsystemPaths, true)
                || in_array('app/Services/Ai/AtlasDecide', $subsystemPaths, true),
            'expected a Forge/Decide subsystem to be derived from real code',
        );

        // The Ai subsystem family is large and real.
        $this->assertGreaterThanOrEqual(50, $structure['summary']['ai_subsystem_count']);

        // FIX 3 — ai_subsystem_count must be RECOMPUTED from the emitted node set,
        // not trusted as a standalone scalar. Filter the actual subsystem nodes for
        // real_path under app/Services/Ai/ and assert the recomputed cardinality
        // equals BOTH summary.ai_subsystem_count AND the live ground truth (count of
        // app/Services/Ai/* directories on disk). A hardcoded scalar that disagrees
        // with the real node set is caught here.
        $aiSubsystemNodeCount = collect($structure['nodes'])
            ->filter(fn (array $node): bool => ($node['kind'] ?? null) === 'subsystem')
            ->filter(fn (array $node): bool => str_starts_with((string) ($node['real_path'] ?? ''), 'app/Services/Ai/'))
            ->count();
        $groundTruthAiDirs = count(File::directories(base_path('app/Services/Ai')));

        $this->assertSame(
            $aiSubsystemNodeCount,
            $structure['summary']['ai_subsystem_count'],
            'ai_subsystem_count must equal the count of emitted Ai subsystem nodes (no hardcoded scalar)',
        );
        $this->assertSame(
            $groundTruthAiDirs,
            $aiSubsystemNodeCount,
            'derived Ai subsystem nodes must equal the live count of app/Services/Ai/* dirs',
        );
        $this->assertSame(
            $groundTruthAiDirs,
            $structure['summary']['ai_subsystem_count'],
            'ai_subsystem_count must equal the live count of app/Services/Ai/* dirs',
        );

        // Top areas are derived too.
        $this->assertContains('app/Services', $areaPaths);
        $this->assertContains('app/Console/Commands', $areaPaths);

        // Edges only ever reference nodes that actually exist.
        $nodeIds = collect($structure['nodes'])->pluck('id')->flip();
        foreach ($structure['edges'] as $edge) {
            $this->assertTrue($nodeIds->has($edge['source']), 'dangling edge source '.$edge['source']);
            $this->assertTrue($nodeIds->has($edge['target']), 'dangling edge target '.$edge['target']);
        }
    }

    /**
     * (c) THE KEY FILESYSTEM ANTI-STUB ASSERTION. Create throwaway classes under a
     * brand-new temp subsystem dir AND under a non-Ai area, re-derive via the
     * filesystem source, and assert each new node APPEARS and its area's counts
     * INCREASED — then delete the temp dirs in finally.
     *
     * We exercise the filesystem source (deriveStructureFromFilesystem) because a
     * just-created file is not yet in the persisted index; the filesystem pass is
     * exactly the path that must reflect new code with ZERO source edits here.
     * This assertion is impossible to pass with any hardcoded topology.
     *
     * The second probe lives under app/Support (a NON-Ai area) so the binding
     * covers the FULL area-root scan, not only the app/Services/Ai subtree — a
     * future hardcode of app/Support / app/Console / app/Models counts would fail.
     */
    public function test_new_throwaway_subsystem_appears_and_counts_increase(): void
    {
        $service = app(AtlasSystemStructureService::class);

        $probeSubsystem = 'app/Services/Ai/__StructureProbe__';
        $probeDir = base_path($probeSubsystem);
        $probeFile = $probeDir.'/StructureProbeService.php';
        $probeSubsystemPath = $probeSubsystem; // normalized form used in the graph

        // FIX 2 — non-Ai probe binds the full-tree scan, not just app/Services/Ai.
        $supportProbeSubsystem = 'app/Support/__StructureProbe__';
        $supportProbeDir = base_path($supportProbeSubsystem);
        $supportProbeFile = $supportProbeDir.'/ProbeSupport.php';

        // Sanity: a clean tree must not already contain either probe.
        File::deleteDirectory($probeDir);
        File::deleteDirectory($supportProbeDir);

        $before = $service->deriveStructureFromFilesystem();
        $this->assertTrue($before['available']);
        $beforeRealPaths = collect($before['nodes'])->pluck('real_path')->all();
        $this->assertNotContains($probeSubsystemPath, $beforeRealPaths, 'Ai probe must not pre-exist');
        $this->assertNotContains($supportProbeSubsystem, $beforeRealPaths, 'Support probe must not pre-exist');

        // Capture the NON-Ai area baseline so we can prove its own counts moved.
        $supportAreaBefore = collect($before['nodes'])
            ->first(fn (array $node): bool => ($node['kind'] ?? null) === 'area' && ($node['real_path'] ?? null) === 'app/Support');
        $this->assertNotNull($supportAreaBefore, 'app/Support must be a derived area before the probe');
        $supportMemberBefore = (int) $supportAreaBefore['member_count'];
        $supportServiceBefore = (int) $supportAreaBefore['service_count'];

        try {
            File::ensureDirectoryExists($probeDir);
            File::put($probeFile, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Services\Ai\__StructureProbe__;

final class StructureProbeService
{
    public function ping(): string
    {
        return 'pong';
    }
}
PHP);

            File::ensureDirectoryExists($supportProbeDir);
            File::put($supportProbeFile, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Support\__StructureProbe__;

final class ProbeSupport
{
    public function ping(): string
    {
        return 'pong';
    }
}
PHP);

            $after = $service->deriveStructureFromFilesystem();
            $this->assertTrue($after['available']);

            $afterSubsystemNodes = collect($after['nodes'])
                ->filter(fn (array $node): bool => ($node['kind'] ?? null) === 'subsystem')
                ->keyBy('real_path');

            // The brand-new subsystem now exists in the derived graph.
            $this->assertTrue($afterSubsystemNodes->has($probeSubsystemPath), 'new subsystem must appear after creation');

            // And the new subsystem reports the new service as a counted member.
            // (We assert via the subsystem's computed service_count rather than the
            // globally bounded leaf-emission window, so the proof does not depend on
            // how many leaf nodes happen to be emitted.)
            $this->assertGreaterThanOrEqual(
                1,
                (int) $afterSubsystemNodes->get($probeSubsystemPath)['service_count'],
                'new subsystem must count its new service',
            );

            // Counts strictly INCREASED — both the subsystem total and the service
            // total grew by at least one. A hardcoded map cannot do this.
            $this->assertGreaterThan(
                $before['summary']['subsystem_count'],
                $after['summary']['subsystem_count'],
                'subsystem_count must increase when a new subsystem dir is added',
            );
            $this->assertGreaterThan(
                $before['summary']['service_count'],
                $after['summary']['service_count'],
                'service_count must increase when a new service file is added',
            );
            $this->assertGreaterThan(
                $before['summary']['node_count'],
                $after['summary']['node_count'],
                'node_count must increase',
            );

            // Drill-down also sees it live.
            $area = $service->deriveArea('app/Services/Ai');
            $this->assertTrue($area['found']);
            $this->assertContains(
                $probeSubsystemPath,
                collect($area['subsystems'])->pluck('real_path')->all(),
                'area drill-down must include the new subsystem',
            );

            // FIX 2 — the NON-Ai area (app/Support) must reflect its own probe: the
            // new subsystem node appears under it AND the area's member/service
            // counts strictly increased. This binds the full area-root scan beyond
            // the Ai subtree, so a hardcode of non-Ai area counts would fail here.
            $this->assertTrue(
                $afterSubsystemNodes->has($supportProbeSubsystem),
                'non-Ai (app/Support) probe subsystem must appear after creation',
            );
            $this->assertGreaterThanOrEqual(
                1,
                (int) $afterSubsystemNodes->get($supportProbeSubsystem)['service_count'],
                'non-Ai probe subsystem must count its new member',
            );

            $supportAreaAfter = collect($after['nodes'])
                ->first(fn (array $node): bool => ($node['kind'] ?? null) === 'area' && ($node['real_path'] ?? null) === 'app/Support');
            $this->assertNotNull($supportAreaAfter, 'app/Support must still be a derived area after the probe');
            $this->assertGreaterThan(
                $supportMemberBefore,
                (int) $supportAreaAfter['member_count'],
                'app/Support member_count must increase when a non-Ai file is added',
            );
            $this->assertGreaterThan(
                $supportServiceBefore,
                (int) $supportAreaAfter['service_count'],
                'app/Support service_count must increase when a non-Ai class is added',
            );

            $supportDrill = $service->deriveArea('app/Support');
            $this->assertTrue($supportDrill['found']);
            $this->assertContains(
                $supportProbeSubsystem,
                collect($supportDrill['subsystems'])->pluck('real_path')->all(),
                'app/Support drill-down must include the new non-Ai subsystem',
            );
        } finally {
            File::deleteDirectory($probeDir);
            File::deleteDirectory($supportProbeDir);
        }

        // After cleanup both probes are gone again (no residue), proving the source
        // is genuinely live and not a one-time snapshot.
        $restoredPaths = collect($service->deriveStructureFromFilesystem()['nodes'])->pluck('real_path')->all();
        $this->assertNotContains(
            $probeSubsystemPath,
            $restoredPaths,
            'Ai probe must be gone after teardown',
        );
        $this->assertNotContains(
            $supportProbeSubsystem,
            $restoredPaths,
            'Support probe must be gone after teardown',
        );
    }

    /**
     * FIX 1 — BIND THE INDEX SOURCE (the default branch every real caller uses).
     *
     * The filesystem probe above only binds the filesystem branch. This test binds
     * the INDEX branch the same way: it stands up ONLY the one table the index
     * derivation reads (atlas_engineering_code_symbols) on the in-memory sqlite,
     * seeds a deterministic handful of real-looking rows, derives source=index,
     * records the counts, then INSERTS a class row under a brand-new subsystem
     * path and re-derives — proving the new subsystem APPEARS and the counts
     * strictly INCREASED purely because the live table changed. Finally it deletes
     * that row and proves the subsystem is GONE.
     *
     * A hardcoded index branch that ignored the table would NOT move these counts
     * and would NOT make the probe node appear/disappear — so it would FAIL here.
     * tearDown() drops the table so no other test inherits the seeded index.
     */
    public function test_index_source_probe_binds_live_symbols_table(): void
    {
        // Minimal schema matching the real columns the index derivation reads
        // (symbol_type / symbol_name / file_path / namespace / signature / status /
        // archived_at). We create only this table — the snapshots table is absent,
        // so dependency edges degrade to empty exactly as the service guards for.
        Schema::dropIfExists(self::SYMBOLS_TABLE);
        Schema::create(self::SYMBOLS_TABLE, function ($table): void {
            $table->bigIncrements('id');
            $table->string('symbol_type', 60);
            $table->string('symbol_name', 300);
            $table->string('file_path', 500);
            $table->string('namespace', 220)->nullable();
            $table->text('signature')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamp('archived_at')->nullable();
        });

        $now = now()->toDateTimeString();
        // Baseline seed: two class rows under app/Services/Engineering + one CLI
        // command under app/Console/Commands. Real-looking but tiny + deterministic
        // (a handful of rows, not the live 134k).
        DB::table(self::SYMBOLS_TABLE)->insert([
            [
                'symbol_type' => 'class',
                'symbol_name' => 'App\\Services\\Engineering\\IndexProbeFoo',
                'file_path' => 'app/Services/Engineering/IndexProbeFoo.php',
                'namespace' => 'App\\Services\\Engineering',
                'signature' => null,
                'status' => 'active',
                'archived_at' => null,
            ],
            [
                'symbol_type' => 'class',
                'symbol_name' => 'App\\Services\\Engineering\\IndexProbeBar',
                'file_path' => 'app/Services/Engineering/IndexProbeBar.php',
                'namespace' => 'App\\Services\\Engineering',
                'signature' => null,
                'status' => 'active',
                'archived_at' => null,
            ],
            [
                'symbol_type' => 'cli_command',
                'symbol_name' => 'App\\Console\\Commands\\IndexProbeCommand',
                'file_path' => 'app/Console/Commands/IndexProbeCommand.php',
                'namespace' => 'App\\Console\\Commands',
                'signature' => 'atlas:index-probe {--x}',
                'status' => 'active',
                'archived_at' => null,
            ],
        ]);

        $service = app(AtlasSystemStructureService::class);

        $before = $service->deriveStructure('index');

        // Proof we are exercising the INDEX branch, not the filesystem fallback:
        // source must be 'index' and the derivation tag must say so. If the table
        // were ignored / empty, the service would degrade to 'filesystem'.
        $this->assertTrue($before['available'], 'index-derived structure must be available');
        $this->assertSame('index', $before['source'], 'must derive from the live index, not the filesystem fallback');
        $this->assertSame('live_code_index_symbols_and_use_graph', $before['summary']['derivation']);

        $probeSubsystemPath = 'app/Services/Ai/__IndexProbe__';
        $probeFilePath = 'app/Services/Ai/__IndexProbe__/IndexProbeService.php';

        $beforeSubsystemPaths = collect($before['nodes'])
            ->filter(fn (array $node): bool => ($node['kind'] ?? null) === 'subsystem')
            ->pluck('real_path')
            ->all();
        $this->assertNotContains($probeSubsystemPath, $beforeSubsystemPaths, 'index probe subsystem must not pre-exist');

        // Baseline counts derived straight from the three seeded rows.
        $this->assertSame(2, $before['summary']['service_count'], 'baseline service_count must equal the seeded class rows');
        $this->assertSame(1, $before['summary']['command_count'], 'baseline command_count must equal the seeded command row');

        try {
            // Mutate the LIVE table: add a class under a brand-new Ai subsystem.
            DB::table(self::SYMBOLS_TABLE)->insert([
                'symbol_type' => 'class',
                'symbol_name' => 'App\\Services\\Ai\\__IndexProbe__\\IndexProbeService',
                'file_path' => $probeFilePath,
                'namespace' => 'App\\Services\\Ai\\__IndexProbe__',
                'signature' => null,
                'status' => 'active',
                'archived_at' => null,
            ]);

            $after = $service->deriveStructure('index');
            $this->assertSame('index', $after['source']);

            $afterSubsystemNodes = collect($after['nodes'])
                ->filter(fn (array $node): bool => ($node['kind'] ?? null) === 'subsystem')
                ->keyBy('real_path');

            // The new subsystem APPEARS purely because the table row was inserted.
            $this->assertTrue(
                $afterSubsystemNodes->has($probeSubsystemPath),
                'new subsystem must appear in the INDEX-derived graph after a row insert',
            );
            $this->assertGreaterThanOrEqual(
                1,
                (int) $afterSubsystemNodes->get($probeSubsystemPath)['service_count'],
                'index probe subsystem must count its new service',
            );

            // Counts strictly INCREASED — bound to the live table, not hardcoded.
            $this->assertGreaterThan(
                $before['summary']['service_count'],
                $after['summary']['service_count'],
                'index service_count must increase when a class row is inserted',
            );
            $this->assertGreaterThan(
                $before['summary']['subsystem_count'],
                $after['summary']['subsystem_count'],
                'index subsystem_count must increase when a new subsystem path appears',
            );
            $this->assertGreaterThan(
                $before['summary']['node_count'],
                $after['summary']['node_count'],
                'index node_count must increase',
            );

            // ai_subsystem_count tracks the inserted Ai subsystem too, and stays
            // consistent with the emitted Ai subsystem node set (FIX 3 invariant,
            // applied to the index branch).
            $aiNodeCount = collect($after['nodes'])
                ->filter(fn (array $node): bool => ($node['kind'] ?? null) === 'subsystem')
                ->filter(fn (array $node): bool => str_starts_with((string) ($node['real_path'] ?? ''), 'app/Services/Ai/'))
                ->count();
            $this->assertSame(
                $aiNodeCount,
                $after['summary']['ai_subsystem_count'],
                'index ai_subsystem_count must equal the emitted Ai subsystem node count',
            );
            $this->assertGreaterThan(
                $before['summary']['ai_subsystem_count'],
                $after['summary']['ai_subsystem_count'],
                'index ai_subsystem_count must increase when an Ai subsystem row is inserted',
            );
        } finally {
            DB::table(self::SYMBOLS_TABLE)->where('file_path', $probeFilePath)->delete();
        }

        // Deleting the row removes the subsystem again — the index branch reflects
        // the table both ways, so it cannot be a frozen/hardcoded snapshot.
        $afterDelete = $service->deriveStructure('index');
        $this->assertSame('index', $afterDelete['source']);
        $afterDeletePaths = collect($afterDelete['nodes'])
            ->filter(fn (array $node): bool => ($node['kind'] ?? null) === 'subsystem')
            ->pluck('real_path')
            ->all();
        $this->assertNotContains(
            $probeSubsystemPath,
            $afterDeletePaths,
            'index probe subsystem must be gone after the row is deleted',
        );
        $this->assertSame(
            $before['summary']['service_count'],
            $afterDelete['summary']['service_count'],
            'index service_count must return to baseline after the probe row is deleted',
        );
    }

    public function test_index_source_is_scoped_to_current_workspace_when_workspace_column_exists(): void
    {
        Schema::dropIfExists(self::SYMBOLS_TABLE);
        Schema::create(self::SYMBOLS_TABLE, function ($table): void {
            $table->bigIncrements('id');
            $table->string('workspace_id', 120);
            $table->string('symbol_type', 60);
            $table->string('symbol_name', 300);
            $table->string('file_path', 500);
            $table->string('namespace', 220)->nullable();
            $table->text('signature')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamp('archived_at')->nullable();
        });

        DB::table(self::SYMBOLS_TABLE)->insert([
            [
                'workspace_id' => 'atlas-server',
                'symbol_type' => 'class',
                'symbol_name' => 'App\\Services\\Ai\\ScopedCurrent\\CurrentService',
                'file_path' => 'app/Services/Ai/ScopedCurrent/CurrentService.php',
                'namespace' => 'App\\Services\\Ai\\ScopedCurrent',
                'signature' => null,
                'status' => 'active',
                'archived_at' => null,
            ],
            [
                'workspace_id' => 'foreign-workspace',
                'symbol_type' => 'class',
                'symbol_name' => 'App\\Services\\Ai\\ScopedForeign\\ForeignService',
                'file_path' => 'app/Services/Ai/ScopedForeign/ForeignService.php',
                'namespace' => 'App\\Services\\Ai\\ScopedForeign',
                'signature' => null,
                'status' => 'active',
                'archived_at' => null,
            ],
        ]);

        $structure = app(AtlasSystemStructureService::class)->deriveStructure('index');

        $this->assertSame('index', $structure['source']);
        $this->assertSame(1, $structure['summary']['service_count']);

        $subsystemPaths = collect($structure['nodes'])
            ->filter(fn (array $node): bool => ($node['kind'] ?? null) === 'subsystem')
            ->pluck('real_path')
            ->all();

        $this->assertContains('app/Services/Ai/ScopedCurrent', $subsystemPaths);
        $this->assertNotContains('app/Services/Ai/ScopedForeign', $subsystemPaths);
    }

    /**
     * OPTIONAL FIX 4 — index-vs-filesystem SET agreement on the REAL index. The set
     * of Ai-subsystem names derived from source=index must be a superset (or equal)
     * of the live app/Services/Ai/* directories, so the index branch can never
     * silently SHRINK below what is actually on disk. Runs against the real index
     * only; if this environment has no populated index (source degrades to
     * filesystem), the check is skipped rather than asserting against the wrong
     * source.
     */
    public function test_real_index_ai_subsystem_set_covers_live_dirs(): void
    {
        // This test must NOT use the tiny seeded sqlite table — it targets the real
        // index. Ensure no seeded residue is present, then derive forcing 'index'.
        Schema::dropIfExists(self::SYMBOLS_TABLE);

        $structure = app(AtlasSystemStructureService::class)->deriveStructure('index');

        if (($structure['available'] ?? false) === false || ($structure['source'] ?? null) !== 'index') {
            $this->markTestSkipped('real code index unavailable in this environment; index/filesystem set agreement not assertable');
        }

        $indexAiSubsystems = collect($structure['nodes'])
            ->filter(fn (array $node): bool => ($node['kind'] ?? null) === 'subsystem')
            ->pluck('real_path')
            ->filter(fn ($path): bool => str_starts_with((string) $path, 'app/Services/Ai/'))
            ->map(fn ($path): string => (string) $path)
            ->values()
            ->all();

        $liveAiDirs = collect(File::directories(base_path('app/Services/Ai')))
            ->map(fn (string $abs): string => 'app/Services/Ai/'.basename($abs))
            ->values()
            ->all();

        // Every live Ai directory must be covered by an index-derived subsystem.
        $missing = array_values(array_diff($liveAiDirs, $indexAiSubsystems));
        $this->assertSame(
            [],
            $missing,
            'index-derived Ai subsystems must cover every live app/Services/Ai/* dir (no silent shrink): missing '.implode(', ', $missing),
        );
        $this->assertGreaterThanOrEqual(
            count($liveAiDirs),
            count($indexAiSubsystems),
            'index Ai subsystem set must be a superset/equal of the live dirs',
        );
    }

    /**
     * The command emits the derived JSON with all computed counts and stays
     * read-only / auto-discovered.
     */
    public function test_command_emits_derived_structure_json(): void
    {
        $exit = Artisan::call('atlas:system-structure', ['--json' => true, '--strict' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertTrue($payload['available']);
        $this->assertSame(AtlasSystemStructureService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertFalse($payload['writes']);
        $this->assertGreaterThanOrEqual(1000, $payload['summary']['service_count']);
        $this->assertGreaterThanOrEqual(500, $payload['summary']['command_count']);

        $areaExit = Artisan::call('atlas:system-structure', ['--area' => 'app/Services/Ai', '--json' => true, '--strict' => true]);
        $areaPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $areaExit);
        $this->assertTrue($areaPayload['found']);
        $this->assertGreaterThanOrEqual(50, $areaPayload['subsystem_count']);
    }
}
