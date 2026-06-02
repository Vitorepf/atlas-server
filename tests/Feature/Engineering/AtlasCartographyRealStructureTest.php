<?php

declare(strict_types=1);

use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceArtifactIntelligenceRepository;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceArtifactWorkroomService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceRuntimeService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceRuntimeProjectionRepository;
use App\Services\Engineering\AtlasCodeRealityUsageIntelligenceService;
use App\Services\Engineering\AtlasDocumentationRealitySystemService;
use App\Services\Engineering\AtlasSystemStructureService;
use App\Services\Engineering\AtlasUniversalRealityCartographyService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * C1 FAIL-ON-STUB proof for the AURC complete-derived-structure layer.
 *
 * The operator previously caught a "10/10" over-claim that a fresh AI could
 * reconstruct the COMPLETE system structure from the AURC cartography — when in
 * fact AURC emitted only a 23-node hand-authored curated map. This test is the
 * falsifiable guard that the gap is closed for real:
 *
 *   payload['complete_derived_structure'] (mirrored at payload['system_structure'])
 *   is a VERBATIM passthrough of AtlasSystemStructureService::deriveStructure('auto'),
 *   so it carries the live ~737 nodes / ~1430 edges (areas -> subsystems -> emitted
 *   leaves + containment/dependency edges + full summary cardinalities) that a fresh
 *   AI reads with NO source access to rebuild the system. Because it is DERIVED, not
 *   authored, inserting/removing a symbol in the live index moves it (TEST 1). A
 *   hand-authored bigger list would fail TEST 1; falling back to the 23-node curated
 *   map would fail TEST 2.
 *
 * The curated 23-node macro map is PRESERVED and honestly relabelled a
 * 'curated_macro_projection' (human entrypoint), never "the structure" (TEST 3); the
 * real human-comprehension layers (route map / clarity / task simulator / coverage /
 * semantic zoom / bounded visual scene) are untouched (TEST 4); and the derived layer
 * degrades honestly on an empty/absent index (TEST 5).
 *
 * Test harness contract (HARD): sqlite :memory:, extends Tests\TestCase, NEVER
 * RefreshDatabase. The realistic-size tests resolve to the live index/filesystem; the
 * index-mutation probe stands up ONLY the atlas_engineering_code_symbols table via the
 * real migration's up() and tearDown() drops it (+ siblings) so no seeded table leaks
 * into another :memory: test (mirrors AtlasSystemStructureTest / DriftGuard prior-art).
 */
final class AtlasCartographyRealStructureTest extends TestCase
{
    private const SYMBOLS_TABLE = 'atlas_engineering_code_symbols';

    private const MIGRATION = '2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php';

    /**
     * Idempotent drop of every table the code-intelligence migration creates, so a
     * seeded tiny index can never survive into the realistic-size tests (which would
     * otherwise flip them to the empty/tiny index branch and break their thresholds).
     */
    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_engineering_doc_links');
        Schema::dropIfExists(self::SYMBOLS_TABLE);
        Schema::dropIfExists('atlas_engineering_code_modules');

        parent::tearDown();
    }

    /**
     * TEST 1 — DERIVED, MUTATE-INDEX -> LAYER-CHANGES (the core fail-on-stub).
     *
     * Stand up the index table, seed a tiny deterministic index, derive the
     * cartography, and read payload['system_structure']: it must be source='index'
     * with derivation 'live_code_index_symbols_and_use_graph'. Then INSERT a class
     * row under a brand-new Ai subsystem and re-map: the new subsystem node APPEARS in
     * the derived layer and service/subsystem/ai/node counts strictly INCREASE — then
     * DELETE the row and confirm it is GONE and counts return to baseline. No
     * hand-authored list can move these.
     */
    public function test_derived_structure_layer_tracks_live_index_mutations(): void
    {
        $this->runRealMigration();
        $this->assertTrue(Schema::hasTable(self::SYMBOLS_TABLE));

        // Baseline seed: two class rows under app/Services/Engineering + one CLI
        // command under app/Console/Commands. NOTE the index branch only counts CLASS
        // rows whose file_path LIKE 'app/%' (and skips app/Console/Commands/ class
        // rows), while cli_command rows are counted regardless of path — so a class
        // probe MUST use an app/... path.
        $this->seedClass('App\\Services\\Engineering\\IndexProbeFoo', 'app/Services/Engineering/IndexProbeFoo.php');
        $this->seedClass('App\\Services\\Engineering\\IndexProbeBar', 'app/Services/Engineering/IndexProbeBar.php');
        $this->seedCommand('App\\Console\\Commands\\IndexProbeCommand', 'app/Console/Commands/IndexProbeCommand.php', 'atlas:index-probe {--x}');

        $cartography = app(AtlasUniversalRealityCartographyService::class);

        $before = $cartography->map();
        $structureBefore = $before['system_structure'];

        // Proof the derived layer is bound to the INDEX, not the filesystem fallback.
        $this->assertTrue($structureBefore['available'], 'derived structure must be available');
        $this->assertSame('index', $structureBefore['source'], 'derived layer must read the live index, not the filesystem fallback');
        $this->assertSame('live_code_index_symbols_and_use_graph', $structureBefore['summary']['derivation']);
        $this->assertSame($before['complete_derived_structure'], $before['system_structure'], 'both keys must point at the same derived payload');

        // Baseline counts derived straight from the three seeded rows.
        $this->assertSame(2, $structureBefore['summary']['service_count'], 'baseline service_count must equal the seeded class rows');
        $this->assertSame(1, $structureBefore['summary']['command_count'], 'baseline command_count must equal the seeded command row');

        $probeSubsystemPath = 'app/Services/Ai/__CartoProbe__';
        $probeFilePath = 'app/Services/Ai/__CartoProbe__/CartoProbeService.php';

        $beforeSubsystemPaths = collect($structureBefore['nodes'])
            ->filter(fn (array $node): bool => ($node['kind'] ?? null) === 'subsystem')
            ->pluck('real_path')
            ->all();
        $this->assertNotContains($probeSubsystemPath, $beforeSubsystemPaths, 'probe subsystem must not pre-exist');

        try {
            // Mutate the LIVE table: add a class under a brand-new Ai subsystem.
            DB::table(self::SYMBOLS_TABLE)->insert($this->classRow(
                'App\\Services\\Ai\\__CartoProbe__\\CartoProbeService',
                $probeFilePath,
                'App\\Services\\Ai\\__CartoProbe__',
            ));

            $after = $cartography->map();
            $structureAfter = $after['system_structure'];
            $this->assertSame('index', $structureAfter['source']);

            $afterSubsystemNodes = collect($structureAfter['nodes'])
                ->filter(fn (array $node): bool => ($node['kind'] ?? null) === 'subsystem')
                ->keyBy('real_path');

            // (a) The brand-new subsystem now APPEARS in the derived layer.
            $this->assertTrue(
                $afterSubsystemNodes->has($probeSubsystemPath),
                'new subsystem must appear in the derived cartography layer after a row insert',
            );
            $this->assertGreaterThanOrEqual(
                1,
                (int) $afterSubsystemNodes->get($probeSubsystemPath)['service_count'],
                'new subsystem must count its new service',
            );

            // (b) Summary counts strictly INCREASED — bound to the live table.
            $this->assertGreaterThan($structureBefore['summary']['service_count'], $structureAfter['summary']['service_count'], 'service_count must increase');
            $this->assertGreaterThan($structureBefore['summary']['subsystem_count'], $structureAfter['summary']['subsystem_count'], 'subsystem_count must increase');
            $this->assertGreaterThan($structureBefore['summary']['ai_subsystem_count'], $structureAfter['summary']['ai_subsystem_count'], 'ai_subsystem_count must increase');
            $this->assertGreaterThan($structureBefore['summary']['node_count'], $structureAfter['summary']['node_count'], 'node_count must increase');

            // The surfaced top-level complete_node_count tracks the derived total too.
            $this->assertSame($structureAfter['summary']['node_count'], $after['summary']['complete_node_count']);
            $this->assertGreaterThan($before['summary']['complete_node_count'], $after['summary']['complete_node_count']);
        } finally {
            DB::table(self::SYMBOLS_TABLE)->where('file_path', $probeFilePath)->delete();
        }

        // (c) Deleting the row removes the subsystem again and reverts the counts —
        // the layer reflects the table both ways, so it cannot be a frozen literal.
        $afterDelete = $cartography->map();
        $structureAfterDelete = $afterDelete['system_structure'];
        $this->assertSame('index', $structureAfterDelete['source']);
        $afterDeletePaths = collect($structureAfterDelete['nodes'])
            ->filter(fn (array $node): bool => ($node['kind'] ?? null) === 'subsystem')
            ->pluck('real_path')
            ->all();
        $this->assertNotContains($probeSubsystemPath, $afterDeletePaths, 'probe subsystem must be gone after the row is deleted');
        $this->assertSame(
            $structureBefore['summary']['service_count'],
            $structureAfterDelete['summary']['service_count'],
            'service_count must return to baseline after the probe row is deleted',
        );
    }

    /**
     * TEST 2 — NODE COUNT TRACKS GROUND TRUTH, NOT 23/25.
     *
     * Against the REAL index/filesystem (no seeded table), payload['system_structure']
     * summary.node_count must EQUAL the node_count from `atlas:system-structure --json`
     * (the same deriveStructure() call) AND be > 100 — the sharp discriminator vs the
     * 23-node curated map. The area real_path SET and ai_subsystem_count must match the
     * ground truth exactly. Both sides are compared under the SAME source so a degraded
     * environment compares fairly rather than skipping.
     */
    public function test_complete_structure_node_count_matches_ground_truth_not_curated_map(): void
    {
        // Ensure no seeded residue — this targets the real derivation.
        Schema::dropIfExists(self::SYMBOLS_TABLE);

        $payload = app(AtlasUniversalRealityCartographyService::class)->map();
        $structure = $payload['system_structure'];

        $this->assertTrue($structure['available'], 'real derived structure must be available (index or filesystem present in CI)');

        $groundTruthExit = Artisan::call('atlas:system-structure', ['--json' => true]);
        $groundTruth = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $groundTruthExit);
        $this->assertTrue($groundTruth['available']);

        // Same service, same source -> byte-identical cardinality. This is the hard
        // gate: node_count == ground truth (NOT 23) proves the COMPLETE structure, not
        // the curated macro map, is what a fresh AI reconstructs.
        $this->assertSame($groundTruth['source'], $structure['source'], 'derived layer and ground truth must report the same source');
        $this->assertSame($groundTruth['summary']['node_count'], $structure['summary']['node_count'], 'derived node_count must equal ground truth exactly');
        $this->assertSame($groundTruth['summary']['edge_count'], $structure['summary']['edge_count'], 'derived edge_count must equal ground truth exactly');
        $this->assertSame($groundTruth['summary']['service_count'], $structure['summary']['service_count']);
        $this->assertSame($groundTruth['summary']['command_count'], $structure['summary']['command_count']);
        $this->assertSame($groundTruth['summary']['total_leaf_count'], $structure['summary']['total_leaf_count']);

        // > 100 discriminator vs the 23/25 curated map.
        $this->assertGreaterThan(100, $structure['summary']['node_count'], 'complete node_count must be >> the 23-node curated map');

        // Top-level surfaced complete totals mirror the derived layer (this is what the
        // ADRS report now reads instead of 23/31).
        $this->assertSame($structure['summary']['node_count'], $payload['summary']['complete_node_count']);
        $this->assertSame($structure['summary']['edge_count'], $payload['summary']['complete_edge_count']);

        // Area SET is a byte-identical identity (same derivation).
        $derivedAreas = collect($structure['nodes'])
            ->filter(fn (array $node): bool => ($node['kind'] ?? null) === 'area')
            ->pluck('real_path')->sort()->values()->all();
        $groundTruthAreas = collect($groundTruth['nodes'])
            ->filter(fn (array $node): bool => ($node['kind'] ?? null) === 'area')
            ->pluck('real_path')->sort()->values()->all();
        $this->assertSame($groundTruthAreas, $derivedAreas, 'area set must equal ground truth exactly');

        // ai_subsystem_count, recomputed from the emitted Ai subsystem nodes, equals
        // both the summary scalar and the ground truth (no hardcoded scalar).
        $aiSubsystemNodeCount = collect($structure['nodes'])
            ->filter(fn (array $node): bool => ($node['kind'] ?? null) === 'subsystem')
            ->filter(fn (array $node): bool => str_starts_with((string) ($node['real_path'] ?? ''), 'app/Services/Ai/'))
            ->count();
        $this->assertSame($structure['summary']['ai_subsystem_count'], $aiSubsystemNodeCount, 'ai_subsystem_count must equal the emitted Ai subsystem node count');
        $this->assertSame($groundTruth['summary']['ai_subsystem_count'], $structure['summary']['ai_subsystem_count'], 'ai_subsystem_count must equal ground truth');
    }

    /**
     * TEST 3 — CURATED MACRO MAP PRESERVED + HONESTLY LABELLED.
     *
     * The curated 23-node layer still exists, is a SEPARATE key from the derived layer,
     * stays small (<=30), is flagged a 'curated_macro_projection' / human entrypoint /
     * NOT the complete structure, while the derived layer is large (>100) and claims to
     * be derived-not-authored. Proves the two are distinct and the macro map was not
     * inflated to fake derivation.
     */
    public function test_curated_macro_map_is_preserved_and_relabelled_as_projection(): void
    {
        Schema::dropIfExists(self::SYMBOLS_TABLE);

        $payload = app(AtlasUniversalRealityCartographyService::class)->map();
        $curatedIds = collect($payload['nodes'])->pluck('id')->all();

        // The curated macro entrypoint nodes still exist.
        foreach (['universe', 'org.atlas', 'system.adrs', 'system.aurc'] as $expected) {
            $this->assertContains($expected, $curatedIds, 'curated macro node must be preserved: '.$expected);
        }

        // Curated layer stays small; the derived complete layer is large and distinct.
        $this->assertLessThanOrEqual(30, count($payload['nodes']), 'curated macro projection must stay small (not inflated to fake derivation)');
        $this->assertGreaterThan(100, count($payload['system_structure']['nodes']), 'derived complete structure must be large');
        $this->assertNotSame($payload['system_structure'], $payload['nodes'], 'derived structure must be a separate layer from the curated nodes');

        // Honest labelling: curated nodes carry the projection layer marker.
        foreach ($payload['nodes'] as $node) {
            $this->assertSame('curated_macro_projection', $node['layer'] ?? null, 'every curated node must be labelled a curated_macro_projection');
        }

        // The curated_macro_projection descriptor declares it is NOT the structure.
        $this->assertSame('curated_macro_projection', $payload['curated_macro_projection']['layer']);
        $this->assertFalse($payload['curated_macro_projection']['is_complete_structure']);
        $this->assertTrue($payload['curated_macro_projection']['is_human_entrypoint']);
        $this->assertSame('complete_derived_structure', $payload['curated_macro_projection']['complete_structure_key']);

        // The cartography never claims to be the source of truth; the derived layer is
        // honestly derived-not-authored and is the complete structure.
        $this->assertFalse($payload['claim_policy']['cartography_is_source_of_truth']);
        $this->assertTrue($payload['system_structure']['claim_policy']['structure_is_derived_not_authored']);
        $this->assertTrue($payload['system_structure']['claim_policy']['is_complete_structure']);
        $this->assertFalse($payload['system_structure']['claim_policy']['is_curated_macro_projection']);
        $this->assertTrue($payload['system_structure']['reconstruction']['is_complete_reconstruction_layer']);

        // Summary keeps the curated counts small while surfacing the real totals.
        $this->assertLessThanOrEqual(30, $payload['summary']['curated_node_count']);
        $this->assertGreaterThan(100, $payload['summary']['complete_node_count']);
    }

    /**
     * TEST 4 — REAL HUMAN LAYERS PRESERVED (regression gate against "delete the human
     * layers to simplify" and against the 737-node layer leaking into the bounded
     * visual scene / cognitive budget).
     */
    public function test_real_human_layers_are_preserved_and_not_polluted_by_derived_layer(): void
    {
        Schema::dropIfExists(self::SYMBOLS_TABLE);

        $payload = app(AtlasUniversalRealityCartographyService::class)->map();

        $this->assertSame('ready', $payload['human_route_map']['status']);
        $this->assertGreaterThanOrEqual(7, $payload['human_route_map']['route_count']);
        $this->assertSame(0, $payload['human_route_map']['invalid_route_count']);

        $this->assertGreaterThanOrEqual(9.8, $payload['human_clarity']['score']);
        $this->assertSame('ready', $payload['human_clarity']['status']);

        $this->assertSame('ready', $payload['task_simulator']['status']);
        $this->assertSame(0, $payload['coverage_audit']['broken_edge_count']);
        $this->assertSame('ready', $payload['semantic_zoom_scenes']['status']);

        // The derived 737-node layer must NOT leak into the bounded scene.
        $this->assertSame('ready', $payload['visual_scene']['cognitive_budget']['status']);
        $this->assertLessThanOrEqual(12, $payload['visual_scene']['cognitive_budget']['visible_node_count']);
        $this->assertLessThanOrEqual(16, $payload['visual_scene']['cognitive_budget']['visible_edge_count']);

        // coverage_audit still audits the CURATED node set (complete structure is a
        // separate un-audited data layer), so the curated node_count is small here.
        $this->assertLessThanOrEqual(30, $payload['coverage_audit']['node_count']);
    }

    /**
     * TEST 5 — DEGRADE-SAFE ON EMPTY/ABSENT INDEX.
     *
     * With the symbols table absent, the derived layer must honestly report the
     * FILESYSTEM source (real files present in CI) with zero use-graph dependency edges
     * and the filesystem derivation tag — never a fabricated index structure. Then we
     * force the genuinely unavailable path by pointing the source at an EMPTY seeded
     * table while making app/Services unreachable is not possible here, so we assert
     * the empty-index honest-degrade contract directly on the service: an empty index
     * falls back to filesystem (source='filesystem', dependency_edge_count===0), and an
     * unavailable derivation yields available=false with empty nodes/edges mirrored by
     * AURC.
     */
    public function test_derived_layer_degrades_honestly_on_empty_or_absent_index(): void
    {
        // (1) No symbols table at all -> filesystem source, honest, never faked index.
        Schema::dropIfExists(self::SYMBOLS_TABLE);

        $payload = app(AtlasUniversalRealityCartographyService::class)->map();
        $structure = $payload['system_structure'];

        $this->assertTrue($structure['available'], 'filesystem source is available in CI (files on disk)');
        $this->assertSame('filesystem', $structure['source'], 'with no index table the derived layer must degrade to the filesystem source');
        $this->assertSame('filesystem_single_pass_dirname_grouping', $structure['summary']['derivation']);
        $this->assertSame(0, $structure['summary']['dependency_edge_count'], 'filesystem mode emits no use-graph edges (never faked)');
        $this->assertSame('filesystem', $payload['summary']['complete_structure_source']);

        // (2) Empty index (table exists, zero active rows) -> service honestly switches
        // to the filesystem source rather than emitting a fabricated/empty structure.
        $this->runRealMigration();
        $emptyIndex = app(AtlasSystemStructureService::class)->deriveStructure('index');
        $this->assertTrue($emptyIndex['available']);
        $this->assertSame('filesystem', $emptyIndex['source'], 'empty index must degrade to filesystem, not emit a fake index structure');
        $this->assertSame(0, $emptyIndex['summary']['dependency_edge_count']);

        // (3) The AURC degrade MIRROR: when the derivation is unavailable, the embedded
        // layer reports available=false with empty nodes/edges and a degraded status —
        // never a fabricated structure. We assert AURC's mirror branch shape directly so
        // the contract is bound even though CI always has files on disk.
        $reflection = new ReflectionMethod(AtlasUniversalRealityCartographyService::class, 'completeDerivedStructure');
        $reflection->setAccessible(true);
        $stub = new class extends AtlasSystemStructureService
        {
            public function deriveStructure(string $sourceOverride = 'auto'): array
            {
                return [
                    'schema_version' => self::SCHEMA_VERSION,
                    'available' => false,
                    'source' => 'unavailable',
                    'reason' => 'code_index_and_filesystem_both_unavailable',
                    'writes' => false,
                ];
            }
        };
        $aurcDegraded = new AtlasUniversalRealityCartographyService(
            app(AtlasDocumentationRealitySystemService::class),
            app(AtlasCodeRealityUsageIntelligenceService::class),
            app(AtlasWorkspaceIntelligenceRuntimeService::class),
            app(AtlasWorkspaceArtifactIntelligenceRepository::class),
            app(AtlasWorkspaceArtifactWorkroomService::class),
            app(AtlasWorkspaceRuntimeProjectionRepository::class),
            $stub,
        );

        /** @var array<string,mixed> $degraded */
        $degraded = $reflection->invoke($aurcDegraded);
        $this->assertFalse($degraded['available'], 'an unavailable derivation must be mirrored as available=false');
        $this->assertSame('unavailable', $degraded['source']);
        $this->assertSame('degraded', $degraded['status']);
        $this->assertSame('code_index_and_filesystem_both_unavailable', $degraded['reason']);
        $this->assertSame([], $degraded['nodes'], 'degraded layer must not fabricate nodes');
        $this->assertSame([], $degraded['edges'], 'degraded layer must not fabricate edges');
        $this->assertTrue($degraded['claim_policy']['degraded']);
        $this->assertTrue($degraded['claim_policy']['is_complete_structure']);
    }

    private function runRealMigration(): void
    {
        $migration = require base_path('database/migrations/'.self::MIGRATION);
        $migration->up();
    }

    private function seedClass(string $name, string $path): void
    {
        DB::table(self::SYMBOLS_TABLE)->insert($this->classRow($name, $path, $this->namespaceOf($name)));
    }

    private function seedCommand(string $name, string $path, string $signature): void
    {
        DB::table(self::SYMBOLS_TABLE)->insert([
            'symbol_type' => 'cli_command',
            'symbol_name' => $name,
            'file_path' => $path,
            'language' => 'php',
            'signature' => $signature,
            'namespace' => $this->namespaceOf($name),
            'status' => 'active',
            'docs_status' => 'documented',
            'source_hash' => 'seed-'.md5('cli_command'.$name),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function classRow(string $name, string $path, string $namespace): array
    {
        return [
            'symbol_type' => 'class',
            'symbol_name' => $name,
            'file_path' => $path,
            'language' => 'php',
            'signature' => null,
            'namespace' => $namespace,
            'status' => 'active',
            'docs_status' => 'documented',
            'source_hash' => 'seed-'.md5('class'.$name),
        ];
    }

    private function namespaceOf(string $fqn): string
    {
        $parts = explode('\\', $fqn);
        array_pop($parts);

        return implode('\\', $parts);
    }
}
