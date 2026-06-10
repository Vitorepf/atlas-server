<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Services\Ai\AtlasMemoryPrivacyService;
use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Ai\RealExecution\AtlasLiveCodeDeliveryService;
use App\Services\Ai\RealExecution\AtlasMissionOutcomeRecorder;
use App\Services\Ai\RealExecution\AtlasMissionService;
use App\Services\Ai\RealExecution\GovernedBranchMaterializationService;
use App\Services\Ai\RealExecution\MissionDeliveryOrchestrator;
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use App\Services\Ai\Reality\AtlasRealityGraphSnapshotBuilderService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionDetector;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionLoopService;
use App\Services\Ai\SelfConstruction\AtlasSelfImprovementMetaMetricService;
use App\Services\Ai\SelfConstruction\AtlasSelfImprovementRelevanceGate;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Feature\Ai\RealExecution\AtlasMissionLoopCompoundsTest;
use Tests\TestCase;

/**
 * S3.F3 — THE RECURSIVE GOVERNED SELF-IMPROVEMENT LOOP (the heart) + the HONEST
 * meta-metric, proven cost-free (a fake certified delivery stands in for the spend,
 * the SAME seam {@see AtlasMissionLoopCompoundsTest}
 * uses) over the REAL mission service, the REAL materializer (real branch, never main),
 * the REAL AURG ingestion + query (the brain), the REAL relevance gate, and the REAL
 * meta-metric (real persisted history).
 *
 * THE RECURSION (test 1, load-bearing): cycle #1 detects a relevant signal whose
 * gate-passed outcome is RECORDED in the brain. cycle #2 (a RELATED signal touching the
 * same shared module) then runs, and its brain-anchored mission query SEES cycle #1's
 * outcome in the context — reached through the shared module the loop wrote. The
 * asserted path cycle#2 → shared module → cycle#1 IS the recursion: the brain that
 * improved now informs the next improvement.
 *
 * THE HONEST META-METRIC (tests 2-3): the per-cycle history is computed LIVE from real
 * persisted rows — including a REJECTION from a gate-failing cycle counted honestly,
 * the brain-growth delta, and an insufficient_history → trend transition. Zero
 * hardcoded; no claim of acceleration.
 *
 * sqlite-only; boots only the needed tables (the proven AURG house pattern).
 */
final class AtlasSelfImprovementRecursiveLoopTest extends TestCase
{
    private string $repo = '';

    private string $sandboxFile = '';

    /** The shared bridge module BOTH cycles' signals live under (its AURG node id). */
    private const SHARED_MODULE_NODE = 'code:module:atlas-server/services-ai-reality';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTables();
        config()->set('atlas.aurg.enabled', true);
        config()->set('atlas.mission.record_outcome_enabled', true);
        // The brain CONTEXT bridge is OFF in the orchestrator prompt for cost-free tests
        // (the recursion is proven on the recorded-outcome graph, the SAME way the
        // compounds test does — the loop compounds via the write-back, not the prompt).
        config()->set('atlas.mission.brain_context_enabled', false);
        config()->set('atlas.mission.record_temporal_enabled', false);

        // A real throwaway git repo so the materializer makes real branches, with two
        // signal-bearing files UNDER the shared module root (app/Services/Ai/Reality).
        $this->repo = sys_get_temp_dir().'/atlas-sc-f3-repo-'.substr(md5(uniqid('', true)), 0, 8);
        File::makeDirectory($this->repo.'/app/Services/Ai/Reality', 0777, true, true);
        // Two signal-bearing files under the SAME shared module, with DELIBERATELY
        // DISJOINT distinctive vocabularies so the cycle-#2 brain query (seeded by
        // cycle-#2's unique terms) does NOT also seed cycle #1 — cycle #1 must be
        // REACHED THROUGH the shared module, not matched directly (the recursion proof).
        File::put(
            $this->repo.'/app/Services/Ai/Reality/Admission.php',
            "<?php\n\nnamespace App\\Services\\Ai\\Reality;\n\nclass Admission\n{\n    // TODO: harden the admission whitelist enforcement\n    public function run(): void {}\n}\n",
        );
        File::put(
            $this->repo.'/app/Services/Ai/Reality/Pathfinder.php',
            "<?php\n\nnamespace App\\Services\\Ai\\Reality;\n\nclass Pathfinder\n{\n    // TODO: optimize the pathfinding traversal cursor\n    public function run(): void {}\n}\n",
        );
        $this->git(['init', '-q']);
        $this->git(['add', '-A']);
        $this->git(['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);

        // The "generated" certified artifact — its CONTENT covers BOTH concerns'
        // vocabularies so the relevance gate's content dimension passes for either cycle
        // (on-target, on-concern). One sandbox stands in for the provider output.
        $this->sandboxFile = sys_get_temp_dir().'/atlas-sc-f3-sandbox-'.substr(md5(uniqid('', true)), 0, 8).'.php';
        File::put(
            $this->sandboxFile,
            "<?php\n\nnamespace App\\Services\\Ai\\Reality;\n\n// admission whitelist enforcement + pathfinding traversal cursor hardening\nclass RealityGuard { public function harden(): bool { return true; } }\n",
        );
    }

    protected function tearDown(): void
    {
        if ($this->repo !== '') {
            File::deleteDirectory($this->repo);
        }
        @unlink($this->sandboxFile);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 1) THE RECURSION PROOF — cycle #2's brain query reaches cycle #1.
    // ------------------------------------------------------------------

    public function test_cycle_two_brain_query_reaches_cycle_one_outcome_through_the_shared_module(): void
    {
        // The brain already knows the shared bridge module (root app/Services/Ai/Reality).
        $this->ingestion()->sync(['code']);

        $loop = $this->loop();

        // ---- CYCLE #1 — detect the Ingestion.php TODO, deliver an on-target branch,
        // record the outcome in the brain (the recursion substrate). ----
        $c1 = $loop->run([
            'repo_dir' => $this->repo,
            'max' => 1,
            // Pin to the first file so the cycle acts on a single, known signal.
            'requests' => [],
        ]);

        // Find the accepted, recorded outcome of cycle #1.
        $a1 = $this->firstAccepted($c1);
        $this->assertNotNull($a1, 'cycle #1 must produce an accepted on-target outcome');
        $this->assertTrue((bool) $a1['evidence_recorded'], 'cycle #1 outcome must be recorded into the brain');
        $missionOne = (string) $a1['mission_id'];
        $missionOneNode = 'mission:mission:'.$missionOne;

        // cycle #1's mission node exists and references the shared module (the bridge).
        $this->assertTrue(
            AtlasAurgNode::query()->whereKey($missionOneNode)->exists(),
            'cycle #1 mission node must exist in the brain',
        );
        $this->assertTrue(
            AtlasAurgEdge::query()
                ->where('from_node_id', $missionOneNode)
                ->where('to_node_id', self::SHARED_MODULE_NODE)
                ->where('kind', AtlasRealityGraphSnapshotBuilderService::EDGE_REFERENCES)
                ->exists(),
            'cycle #1 must reference the shared module (the bridge the loop wrote)',
        );

        // ---- CYCLE #2 — a RELATED signal (Pathfinder.php, same shared module). Its
        // brain-anchored mission also references the shared module → the bridge. ----
        // Resolve the cycle-#1 marker so the detector lands on the second signal
        // (deterministic single-signal cycle; files scan in name order, Admission first).
        File::put(
            $this->repo.'/app/Services/Ai/Reality/Admission.php',
            "<?php\n\nnamespace App\\Services\\Ai\\Reality;\n\nclass Admission\n{\n    public function run(): void {}\n}\n",
        );

        $c2 = $loop->run([
            'repo_dir' => $this->repo,
            'max' => 1,
        ]);

        $a2 = $this->firstAccepted($c2);
        $this->assertNotNull($a2, 'cycle #2 must produce an accepted on-target outcome');
        $missionTwo = (string) $a2['mission_id'];
        $missionTwoNode = 'mission:mission:'.$missionTwo;
        $this->assertNotSame($missionOne, $missionTwo, 'cycle #2 is a distinct mission');
        $this->assertTrue(
            AtlasAurgEdge::query()
                ->where('from_node_id', $missionTwoNode)
                ->where('to_node_id', self::SHARED_MODULE_NODE)
                ->where('kind', AtlasRealityGraphSnapshotBuilderService::EDGE_REFERENCES)
                ->exists(),
            'cycle #2 must reference the shared module too (the bridge)',
        );

        // ---- THE PROOF ----
        // cycle #2's brain query — the SAME provider-bound query the orchestrator
        // threads into the prompt — with terms UNIQUE to cycle #2's concern (so cycle #1
        // is NOT matched directly). It must REACH the prior cycle-#1 mission, reached
        // PURELY through the shared module edge (the recursion: #1's outcome informs #2).
        $result = $this->query()->query('pathfinding traversal cursor', ['provider_bound' => true]);

        $reachedIds = array_column((array) $result['nodes'], 'id');
        $this->assertContains($missionTwoNode, $reachedIds, 'the query must seed cycle #2');
        $this->assertContains(
            $missionOneNode,
            $reachedIds,
            'cycle #2 brain query must REACH cycle #1 — the loop compounds (recursion)',
        );

        // THE LOAD-BEARING assertion: an actual PATH cycle#2 → shared module → cycle#1.
        // This is "the system's own prior self-improvement informs the next", not a
        // coincidental co-occurrence.
        $bridging = null;
        foreach ((array) $result['paths'] as $path) {
            $chain = (array) ($path['nodes'] ?? []);
            if (in_array($missionTwoNode, $chain, true)
                && in_array($missionOneNode, $chain, true)
                && in_array(self::SHARED_MODULE_NODE, $chain, true)) {
                $bridging = $path;
                break;
            }
        }
        $this->assertNotNull(
            $bridging,
            'a path cycle#2 → shared module → cycle#1 must exist (the closed recursive loop)',
        );
        $chain = (array) $bridging['nodes'];
        $this->assertSame($missionTwoNode, $chain[0], 'the path is anchored at the current cycle (#2)');
        $this->assertSame($missionOneNode, $chain[count($chain) - 1], 'the path ends at the prior cycle (#1)');
        $this->assertContains(self::SHARED_MODULE_NODE, $chain, 'reached through the shared module');
        $this->assertTrue((bool) $bridging['cross_layer'], 'mission→code→mission spans layers');

        // Governance held both cycles.
        $this->assertTrue($c1['never_merged'] && $c2['never_merged']);
        $this->assertTrue($c1['main_untouched'] && $c2['main_untouched']);
    }

    // ------------------------------------------------------------------
    // 2) THE HONEST META-METRIC — real counts incl. a rejection from a
    //    gate-failing cycle, computed LIVE from persisted history.
    // ------------------------------------------------------------------

    public function test_meta_metric_records_real_counts_including_a_rejected_cycle_and_brain_growth(): void
    {
        $this->ingestion()->sync(['code']);
        $meta = $this->meta();

        // ---- CYCLE A — an ON-TARGET, on-concern delivery (passes the gate). ----
        $loopOk = $this->loop($meta);
        $a = $loopOk->run(['repo_dir' => $this->repo, 'max' => 1]);
        $this->assertTrue((bool) ($a['cycle_recorded'] ?? false), 'cycle A must be persisted to the history');
        $this->assertSame(1, (int) $a['accepted_count']);
        $this->assertSame(0, (int) $a['rejected_count']);

        // ---- CYCLE B — an OFF-TARGET delivery (the 412-style failure). The relevance
        // gate REJECTS it and the loop discards the branch; the history must count the
        // rejection HONESTLY, not hide it. ----
        // Resolve cycle-A's marker so cycle B lands on the SECOND signal (a distinct
        // mission id → no branch_already_exists collision).
        File::put(
            $this->repo.'/app/Services/Ai/Reality/Admission.php',
            "<?php\n\nnamespace App\\Services\\Ai\\Reality;\n\nclass Admission\n{\n    public function run(): void {}\n}\n",
        );
        $loopOffTarget = $this->loop($meta, offTarget: true);
        $b = $loopOffTarget->run(['repo_dir' => $this->repo, 'max' => 1]);
        $this->assertTrue((bool) ($b['cycle_recorded'] ?? false), 'cycle B must be persisted to the history');
        $this->assertSame(0, (int) $b['accepted_count'], 'off-target is never accepted');
        $this->assertSame(1, (int) $b['rejected_count'], 'the rejection must be counted');

        // ---- THE STATUS — LIVE from the two real persisted rows. ----
        $status = $meta->status();

        $this->assertSame(AtlasSelfImprovementMetaMetricService::SCHEMA, $status['schema_version']);
        $this->assertSame(2, (int) $status['cycle_count'], 'two cycles persisted');

        $totals = $status['totals'];
        $this->assertSame(2, (int) $totals['generated'], 'both cycles cut a branch (delivered)');
        $this->assertSame(1, (int) $totals['relevance_passed'], 'cycle A passed the gate');
        $this->assertSame(1, (int) $totals['relevance_rejected'], 'cycle B was rejected — counted honestly');
        $this->assertSame(1, (int) $totals['branches_delivered'], 'only the on-target branch is kept');
        // Lifetime on-target rate = 1 passed / 2 generated = 0.5 (DERIVED, not stored).
        $this->assertSame(0.5, $totals['relevance_pass_rate']);

        // The series carries both per-cycle rates (A=1.0, B=0.0).
        $trend = $status['relevance_pass_rate_trend'];
        $this->assertSame([1.0, 0.0], $trend['series']);
        $this->assertSame('declining', $trend['direction'], 'first 1.0 → last 0.0 is a measured decline');
        $this->assertSame(-1.0, $trend['delta']);

        // Brain growth quantified: cycle A added the recursion substrate (mission +
        // evidence nodes). The rejected cycle still records (its outcome is recorded
        // too — the brain knows it tried), so growth is positive overall.
        $growth = $status['brain_growth'];
        $this->assertGreaterThanOrEqual(2, (int) $growth['nodes_added_total'], 'cycle A added >=2 refs (mission+evidence)');
        $this->assertGreaterThan((int) $growth['first_total'], (int) $growth['last_total'], 'the brain grew across the window');

        // ANTI-OVER-CLAIM: the payload itself disclaims acceleration.
        $this->assertStringContainsString('NOT asserted', (string) $status['note']);
    }

    public function test_meta_metric_status_is_empty_and_fail_open_with_no_history(): void
    {
        // No cycles run — status must be well-formed + honest (no fabricated trend),
        // never throw.
        $status = $this->meta()->status();

        $this->assertSame(0, (int) $status['cycle_count']);
        $this->assertSame([], $status['cycles']);
        $this->assertNull($status['totals']['relevance_pass_rate'], 'no generation ⇒ no fabricated rate');
        $this->assertSame('insufficient_history', $status['relevance_pass_rate_trend']['direction']);
        $this->assertSame(0, (int) $status['brain_growth']['nodes_added_total']);
    }

    // ------------------------------------------------------------------
    // fixtures
    // ------------------------------------------------------------------

    /**
     * The REAL loop wired over the REAL mission service (fake certified delivery only),
     * the REAL materializer/gate/brain, and the REAL meta-metric. The off-target
     * variant makes the (fake) delivery touch a file OUTSIDE the signal's directory —
     * the 412-style failure the gate must catch.
     */
    private function loop(?AtlasSelfImprovementMetaMetricService $meta = null, bool $offTarget = false): AtlasSelfConstructionLoopService
    {
        return new AtlasSelfConstructionLoopService(
            new AtlasSelfConstructionDetector,
            $this->missionService($offTarget),
            new AtlasSelfImprovementRelevanceGate,
            new GovernedBranchMaterializationService,
            $meta,
        );
    }

    private function missionService(bool $offTarget): AtlasMissionService
    {
        $ingestion = $this->ingestion();

        $orchestrator = new MissionDeliveryOrchestrator(
            $this->fakeDelivery($offTarget),
            new GovernedBranchMaterializationService,
            $this->query(),
            $ingestion,
            new AtlasMissionOutcomeRecorder($ingestion),
        );

        // No temporal wiring (record_temporal_enabled is off) — keeps the cycle lean.
        return new AtlasMissionService($orchestrator, $ingestion);
    }

    private function meta(): AtlasSelfImprovementMetaMetricService
    {
        return new AtlasSelfImprovementMetaMetricService;
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

    /**
     * Fake certified delivery — zero spend. On-target: it writes the sandbox content to
     * the caller's target_file (under the shared module). Off-target: it writes to an
     * UNRELATED directory (app/Generated) regardless of target_file → the gate rejects.
     */
    private function fakeDelivery(bool $offTarget): AtlasLiveCodeDeliveryService
    {
        $sandboxFile = $this->sandboxFile;

        return new class($sandboxFile, $offTarget) extends AtlasLiveCodeDeliveryService
        {
            public function __construct(private string $sandboxFile, private bool $offTarget) {}

            public function deliver(string $goal, array $options = []): array
            {
                $target = is_string($options['target_file'] ?? null) && $options['target_file'] !== ''
                    ? (string) $options['target_file']
                    : 'app/Services/Ai/Reality/Generated.php';

                // Off-target: ignore the aim and land in a different directory entirely
                // (the 412-line-garbage failure mode the relevance gate must catch).
                $path = $this->offTarget ? 'app/Generated/OffTarget.php' : $target;

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

    /**
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>|null
     */
    private function firstAccepted(array $cycle): ?array
    {
        foreach ((array) ($cycle['outcomes'] ?? []) as $o) {
            if ((bool) ($o['accepted'] ?? false)) {
                return $o;
            }
        }

        return null;
    }

    private function bootTables(): void
    {
        foreach ([
            'migrations/2026_05_02_000000_create_atlas_memory_entries_table.php',
            'migrations/2026_05_02_004000_create_atlas_verbatim_memories_table.php',
            'migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php',
            'migrations/2026_06_09_120000_create_atlas_aurg_graph_tables.php',
            'migrations/2026_06_10_120000_create_atlas_self_construct_cycles_table.php',
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

        // The shared bridge module both cycles' signals live under (root app/Services/Ai/Reality).
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
