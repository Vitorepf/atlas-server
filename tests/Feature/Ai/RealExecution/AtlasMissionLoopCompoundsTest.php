<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\RealExecution;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Services\Ai\AtlasMemoryPrivacyService;
use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use App\Services\Ai\Reality\AtlasRealityGraphSnapshotBuilderService;
use App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService;
use App\Services\Ai\RealExecution\AtlasLiveCodeDeliveryService;
use App\Services\Ai\RealExecution\AtlasMissionOutcomeRecorder;
use App\Services\Ai\RealExecution\AtlasMissionService;
use App\Services\Ai\RealExecution\GovernedBranchMaterializationService;
use App\Services\Ai\RealExecution\MissionDeliveryOrchestrator;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * S2.F4 — PROVE THE LOOP COMPOUNDS (cognition accrues from execution).
 *
 * The heart of Salto 2. Two missions run through the REAL closed loop with ZERO
 * provider spend (a fake delivery returns a certified sandbox file, the same seam the
 * F3 tests use), the REAL materializer (real branch, never main), the REAL ingestion
 * (real AURG mission nodes/edges), and the REAL query — the SAME
 * {@see AtlasRealityGraphQueryService} the orchestrator threads into a code-gen prompt:
 *
 *   mission #1 runs → records `mission:mission:m1` + a cite-or-omit `references` edge
 *     to a shared code module that mission #1 touched;
 *   mission #2 runs (a DIFFERENT request, also touching that shared module) → records
 *     `mission:mission:m2` + its own `references` edge to the same module;
 *   mission #2's brain query (provider-bound, terms unique to mission #2) seeds the
 *     mission #2 node and, walking the graph, REACHES the mission #1 node THROUGH the
 *     shared module — i.e. the execution OUTCOME of #1 is now part of #2's context.
 *
 * The load-bearing assertion is the PATH `m2 → module → m1`: the prior mission's
 * execution outcome is reachable from the next mission, proving the loop compounds.
 * Nothing here is lexical coincidence — the query terms match ONLY mission #2, and
 * mission #1 is reached purely through the shared structural edge the loop wrote.
 *
 * sqlite-only; boots only the needed tables (the proven AURG house pattern).
 */
final class AtlasMissionLoopCompoundsTest extends TestCase
{
    private string $repo = '';

    private string $sandboxFile = '';

    private string $tickLog = '';

    /** The shared bridge module both missions touch (its AURG node id). */
    private const SHARED_MODULE_NODE = 'code:module:atlas-server/services-ai-reality';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTables();
        config()->set('atlas.aurg.enabled', true);
        config()->set('atlas.mission.record_outcome_enabled', true);
        config()->set('atlas.mission.brain_context_enabled', false);
        config()->set('atlas.mission.record_temporal_enabled', true);

        // A real throwaway git repo so the materializer makes real branches.
        $this->repo = sys_get_temp_dir().'/atlas-mission-f4-repo-'.substr(md5(uniqid('', true)), 0, 8);
        File::makeDirectory($this->repo, 0777, true, true);
        File::put($this->repo.'/README.md', "base\n");
        $this->git(['init', '-q']);
        $this->git(['add', '-A']);
        $this->git(['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);

        // The "generated" certified artifact (stands in for the provider output).
        $this->sandboxFile = sys_get_temp_dir().'/atlas-mission-f4-sandbox-'.substr(md5(uniqid('', true)), 0, 8).'.php';
        File::put($this->sandboxFile, "<?php\n\nnamespace App\\Generated;\n\nclass MissionF4 { public function ok(): bool { return true; } }\n");

        // Isolated temporal tick log (never the real storage path).
        $this->tickLog = sys_get_temp_dir().'/atlas-mission-f4-ticks-'.substr(md5(uniqid('', true)), 0, 8).'.jsonl';
    }

    protected function tearDown(): void
    {
        if ($this->repo !== '') {
            File::deleteDirectory($this->repo);
        }
        @unlink($this->sandboxFile);
        @unlink($this->tickLog);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 1) THE COMPOUNDING PROOF — mission #2 reaches mission #1 (the heart).
    // ------------------------------------------------------------------

    public function test_the_next_mission_brain_query_reaches_the_prior_mission_through_the_shared_module(): void
    {
        // The brain already knows the bridge module (root_path = app/Services/Ai/Reality).
        $this->ingestion()->sync(['code']);

        $service = $this->missionService();

        // mission #1 — touches the shared module (exact root → 1.0 references edge).
        $r1 = $service->run('refactor the reality service ingestion path', [
            'repo_dir' => $this->repo,
            'id' => 'm1',
            'target_file' => 'app/Services/Ai/Reality/Alpha.php',
        ]);
        $this->assertTrue($r1['delivered'], 'reason: '.($r1['reason'] ?? ''));
        $this->assertTrue($r1['evidence_recorded'], 'mission #1 must feed the brain back');
        $this->assertTrue(
            AtlasAurgNode::query()->whereKey('mission:mission:m1')->exists(),
            'mission #1 node must exist in the brain',
        );
        // mission #1 → shared module references edge (the bridge the loop wrote).
        $this->assertTrue(
            AtlasAurgEdge::query()
                ->where('from_node_id', 'mission:mission:m1')
                ->where('to_node_id', self::SHARED_MODULE_NODE)
                ->where('kind', AtlasRealityGraphSnapshotBuilderService::EDGE_REFERENCES)
                ->exists(),
            'mission #1 must reference the shared module',
        );

        // mission #2 — a DIFFERENT request (unique terms: "telemetry dashboard widget"),
        // but it ALSO touches the same shared module → same module references edge.
        $r2 = $service->run('add a telemetry dashboard widget to the reality service', [
            'repo_dir' => $this->repo,
            'id' => 'm2',
            'target_file' => 'app/Services/Ai/Reality/TelemetryDashboardWidget.php',
        ]);
        $this->assertTrue($r2['delivered'], 'reason: '.($r2['reason'] ?? ''));
        $this->assertTrue(
            AtlasAurgEdge::query()
                ->where('from_node_id', 'mission:mission:m2')
                ->where('to_node_id', self::SHARED_MODULE_NODE)
                ->where('kind', AtlasRealityGraphSnapshotBuilderService::EDGE_REFERENCES)
                ->exists(),
            'mission #2 must reference the shared module too (the bridge)',
        );

        // ---- THE PROOF ----
        // mission #2's brain query — the SAME provider-bound query the orchestrator
        // uses — with terms that match ONLY mission #2's request. It must SEE the
        // prior mission #1 node, reached PURELY through the shared module edge.
        $result = $this->query()->query('telemetry dashboard widget', ['provider_bound' => true]);

        // mission #2 is a seed (its label matches the query terms).
        $this->assertContains(
            'mission:mission:m2',
            array_column((array) $result['nodes'], 'id'),
            'the query must seed the current mission (#2)',
        );

        // mission #1 is REACHED (it is in the answer at all) — accrued cognition.
        $missionOneNode = null;
        foreach ((array) $result['nodes'] as $node) {
            if (($node['id'] ?? null) === 'mission:mission:m1') {
                $missionOneNode = $node;
            }
        }
        $this->assertNotNull(
            $missionOneNode,
            'mission #2 brain query must REACH the prior mission #1 (the loop compounds)',
        );
        $this->assertTrue((bool) $missionOneNode['provider_safe'], 'a reached mission node stays provider-safe');

        // The LOAD-BEARING assertion: there is an actual PATH m2 → … → m1 whose chain
        // passes through the shared module. This is "execution outcome of #1 is now
        // part of #2's context", not a coincidental co-occurrence.
        $bridgingPath = null;
        foreach ((array) $result['paths'] as $path) {
            $chain = (array) ($path['nodes'] ?? []);
            if (in_array('mission:mission:m1', $chain, true)
                && in_array('mission:mission:m2', $chain, true)
                && in_array(self::SHARED_MODULE_NODE, $chain, true)) {
                $bridgingPath = $path;
                break;
            }
        }
        $this->assertNotNull(
            $bridgingPath,
            'a path mission#2 → shared module → mission#1 must exist (the closed compounding loop)',
        );

        // The chain is exactly the 3-hop bridge: current mission, shared module, prior
        // mission (order may be either direction — BFS is undirected).
        $chain = (array) $bridgingPath['nodes'];
        $this->assertContains(self::SHARED_MODULE_NODE, $chain);
        $this->assertSame('mission:mission:m2', $chain[0], 'the path is anchored at the current mission seed');
        $this->assertSame('mission:mission:m1', $chain[count($chain) - 1], 'the path ends at the prior mission');
        $this->assertTrue((bool) $bridgingPath['cross_layer'], 'mission→code→mission spans layers');
    }

    // ------------------------------------------------------------------
    // 2) TEMPORAL — the mission accrual is timestamped in the 4D chain.
    // ------------------------------------------------------------------

    public function test_a_delivered_mission_records_a_real_temporal_tick_and_the_chain_verifies(): void
    {
        $temporal = $this->temporal();
        $service = $this->missionService($temporal);

        $r = $service->run('a mission that should leave a temporal footprint', [
            'repo_dir' => $this->repo,
            'id' => 'm-temporal',
        ]);

        $this->assertTrue($r['delivered'], 'reason: '.($r['reason'] ?? ''));
        // The envelope reports the tick honestly.
        $this->assertTrue($r['temporal_recorded'], 'a delivered mission must tick the 4D chain');
        $this->assertIsString($r['temporal_snapshot_hash']);
        $this->assertNotSame('', $r['temporal_snapshot_hash'], 'the tick carries a non-null snapshot hash');

        // The tick is REAL: it exists in the append-only log with a non-null hash, and
        // the hash chain verifies front to back.
        $timeline = $temporal->timeline();
        $this->assertSame(1, (int) $timeline['tick_count'], 'exactly one mission tick was appended');
        $tick = $timeline['ticks'][0];
        $this->assertSame(
            AtlasUnifiedRealityGraphTemporalService::KIND_SNAPSHOT_RECORDED,
            $tick['kind'],
        );
        $this->assertIsString($tick['snapshot_hash']);
        $this->assertNotSame('', (string) $tick['snapshot_hash']);
        $this->assertSame($r['temporal_snapshot_hash'], $tick['snapshot_hash'], 'envelope hash == logged hash');
        // delta_summary carries the real mission accrual (>=1 node from the write-back).
        $this->assertGreaterThanOrEqual(1, (int) ($tick['delta_summary']['node_count'] ?? 0));

        $verification = $temporal->verifyChain();
        $this->assertTrue($verification['chain_intact'], 'the 4D chain must verify');
        $this->assertNull($verification['chain_break_at']);
        $this->assertSame(1, $verification['ticks_walked']);
    }

    public function test_a_blocked_mission_records_no_temporal_tick(): void
    {
        $temporal = $this->temporal();
        $service = $this->missionService($temporal, AtlasLiveCodeDeliveryService::STATUS_BLOCKED);

        $r = $service->run('a request the provider could not certify', [
            'repo_dir' => $this->repo,
            'id' => 'm-blocked',
        ]);

        $this->assertFalse($r['delivered']);
        $this->assertFalse($r['temporal_recorded'], 'a blocked run has no accrual to timestamp');
        $this->assertNull($r['temporal_snapshot_hash']);
        // Nothing was appended to the chain.
        $this->assertSame(0, (int) $temporal->timeline()['tick_count']);
    }

    public function test_temporal_tick_failure_is_fail_open_and_the_delivery_still_succeeds(): void
    {
        // A temporal service whose log path is a DIRECTORY → every append throws.
        $brokenDir = sys_get_temp_dir().'/atlas-mission-f4-brokendir-'.substr(md5(uniqid('', true)), 0, 8);
        File::makeDirectory($brokenDir, 0777, true, true);
        $temporal = $this->temporal();
        $temporal->setLogPathForTesting($brokenDir); // fopen() on a dir fails → throws

        $service = $this->missionService($temporal);

        $r = $service->run('survive a dead temporal writer', [
            'repo_dir' => $this->repo,
            'id' => 'm-failopen',
        ]);

        // The delivery proceeded to a real branch despite the tick throwing.
        $this->assertTrue($r['delivered'], 'reason: '.($r['reason'] ?? ''));
        $this->assertSame('atlas/materialize/m-failopen', $r['branch']);
        // Honest degrade: the tick failed, reported as not recorded.
        $this->assertFalse($r['temporal_recorded'], 'a thrown tick degrades to temporal_recorded=false');
        // The outcome write-back still happened (the loop still compounded).
        $this->assertTrue($r['evidence_recorded']);
        $this->assertTrue(AtlasAurgNode::query()->whereKey('mission:mission:m-failopen')->exists());

        File::deleteDirectory($brokenDir);
    }

    // ------------------------------------------------------------------
    // 3) PRUNE ISOLATION — a full sync never wipes mission-source nodes.
    // ------------------------------------------------------------------

    public function test_a_full_sync_with_prune_leaves_mission_nodes_untouched(): void
    {
        $this->ingestion()->sync(['code']);

        // Record a mission outcome (mission-source nodes/edges).
        $service = $this->missionService();
        $r = $service->run('a mission that must survive a full prune', [
            'repo_dir' => $this->repo,
            'id' => 'm-survives',
            'target_file' => 'app/Services/Ai/Reality/Survivor.php',
        ]);
        $this->assertTrue($r['delivered'], 'reason: '.($r['reason'] ?? ''));

        $missionNodesBefore = (int) AtlasAurgNode::query()->where('source_kind', 'mission')->count();
        $this->assertSame(2, $missionNodesBefore, 'mission node + evidence node');
        $missionEdgesBefore = (int) AtlasAurgEdge::query()->where('source', 'mission_outcome')->count();
        $this->assertGreaterThanOrEqual(1, $missionEdgesBefore);

        // A FULL sync WITH prune over every read-model source. 'mission' is NOT one of
        // the synced sources (self::SOURCES), so the prune scope never sees it.
        $this->ingestion()->sync(AtlasRealityGraphIngestionService::SOURCES, true);

        $this->assertTrue(
            AtlasAurgNode::query()->whereKey('mission:mission:m-survives')->exists(),
            'the mission node must survive a full --prune sync',
        );
        $this->assertTrue(
            AtlasAurgNode::query()->whereKey('mission:evidence:m-survives')->exists(),
            'the evidence node must survive a full --prune sync',
        );
        $this->assertSame(
            $missionNodesBefore,
            (int) AtlasAurgNode::query()->where('source_kind', 'mission')->count(),
            'prune must not remove any mission-source node',
        );
        $this->assertSame(
            $missionEdgesBefore,
            (int) AtlasAurgEdge::query()->where('source', 'mission_outcome')->count(),
            'prune must not remove any mission_outcome edge',
        );
    }

    // ------------------------------------------------------------------
    // fixtures
    // ------------------------------------------------------------------

    private function missionService(
        ?AtlasUnifiedRealityGraphTemporalService $temporal = null,
        string $deliveryStatus = AtlasLiveCodeDeliveryService::STATUS_CERTIFIED,
    ): AtlasMissionService {
        $ingestion = $this->ingestion();

        $orchestrator = new MissionDeliveryOrchestrator(
            $this->fakeDelivery($deliveryStatus),
            new GovernedBranchMaterializationService,
            $this->query(),
            $ingestion,
            new AtlasMissionOutcomeRecorder($ingestion),
        );

        return new AtlasMissionService($orchestrator, $ingestion, $temporal);
    }

    private function ingestion(): AtlasRealityGraphIngestionService
    {
        return new AtlasRealityGraphIngestionService(
            new CrossDomainTaxonomyMap,
            app(AtlasMemoryPrivacyService::class),
            app(AtlasCrossDomainMeshService::class),
        );
    }

    private function query(): AtlasRealityGraphQueryService
    {
        return new AtlasRealityGraphQueryService;
    }

    private function temporal(): AtlasUnifiedRealityGraphTemporalService
    {
        $temporal = new AtlasUnifiedRealityGraphTemporalService(new AtlasRealityGraphSnapshotBuilderService);
        $temporal->setLogPathForTesting($this->tickLog);

        return $temporal;
    }

    private function fakeDelivery(string $status): AtlasLiveCodeDeliveryService
    {
        $sandboxFile = $this->sandboxFile;

        return new class($status, $sandboxFile) extends AtlasLiveCodeDeliveryService
        {
            public function __construct(private string $status, private string $sandboxFile) {}

            public function deliver(string $goal, array $options = []): array
            {
                if ($this->status !== AtlasLiveCodeDeliveryService::STATUS_CERTIFIED) {
                    return ['schema_version' => 'x', 'status' => $this->status, 'certified' => false, 'reason' => 'provider_returned_not_ok'];
                }

                // The touched path is the caller's target_file (so distinct missions
                // touch the SAME module root via different files under it) — the bridge.
                $path = is_string($options['target_file'] ?? null) && $options['target_file'] !== ''
                    ? (string) $options['target_file']
                    : 'app/Generated/MissionF4.php';

                return [
                    'schema_version' => 'x',
                    'status' => AtlasLiveCodeDeliveryService::STATUS_CERTIFIED,
                    'certified' => true,
                    'provider' => 'fake_for_test',
                    'files' => [['path' => $path, 'sandbox_path' => $this->sandboxFile]],
                    'syntax_check' => ['ok' => true, 'tool' => 'php -l'],
                ];
            }
        };
    }

    private function bootTables(): void
    {
        foreach ([
            'migrations/2026_05_02_000000_create_atlas_memory_entries_table.php',
            'migrations/2026_05_02_004000_create_atlas_verbatim_memories_table.php',
            'migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php',
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

        // The shared bridge module both missions touch (root app/Services/Ai/Reality).
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

    /** @param list<string> $argv */
    private function git(array $argv): void
    {
        (new Process(array_merge(['git'], $argv), $this->repo))->run();
    }
}
