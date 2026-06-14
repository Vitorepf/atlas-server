<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraClusterDetectorService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWiredCallerService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AUTONOMOUS MULTI-FILE OBRA CANDIDATE PRODUCER — frozen proof of the detector that connects the
 * live grind loop to the operator-gated big-obra surface.
 *
 * Covers (all ungameable): the producer is byte-inert with the flag OFF; it parks a >=2-file obra
 * candidate ONLY for a WIRED high-leverage hub with REAL working-tree callers; any forbidden
 * self-target member rejects the WHOLE cluster; a stale-graph hub with no real callers produces
 * NO candidate (the >=2-file floor); the thresholds gate production; the same cluster is
 * cooldown-deduped; the detector NEVER enqueues a loop task; the refiller is wired with the
 * detector WITHOUT clobbering the LIVE framework_refactor synthesizer; and the new caller-PATHS
 * API returns measured production callers (self + tests excluded).
 */
final class AtlasLoopObraClusterDetectorTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local'); // isolate the backlog door + the detector's cooldown index
        if (! Schema::hasTable('atlas_loop_targets')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        config([
            'atlas.loop.obra_cluster_detection_enabled' => true,
            'atlas.loop.obra_cluster_min_callers' => 3,
            'atlas.loop.obra_cluster_min_cyclomatic' => 10,
            'atlas.loop.obra_cluster_leverage_floor' => 0.5,
            'atlas.loop.obra_cluster_max_files' => 8,
            'atlas.loop.obra_cluster_cooldown_hours' => 168,
            'atlas.loop.obra_cluster_max_candidates_per_cycle' => 2,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    /**
     * A temp repo with a hub class plus N production caller files that reference its FQCN.
     *
     * @param  list<string>  $callerRelPaths
     */
    private function repoWithHubAndCallers(array $callerRelPaths): string
    {
        $d = sys_get_temp_dir().'/atlas-obra-cluster-'.bin2hex(random_bytes(4));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d.'/app/Services');
        File::put($d.'/app/Services/Hub.php', "<?php\nnamespace App\\Services;\nfinal class Hub { public function x(): int { return 1; } }\n");
        foreach ($callerRelPaths as $caller) {
            File::ensureDirectoryExists($d.'/'.dirname($caller));
            $class = pathinfo($caller, PATHINFO_FILENAME);
            File::put(
                $d.'/'.$caller,
                "<?php\nnamespace App\\Callers;\nuse App\\Services\\Hub;\nfinal class {$class} { public function go(Hub \$h): int { return \$h->x(); } }\n",
            );
        }

        return $d;
    }

    private function campaign(string $repo): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => 'running',
            'goal' => 'test',
            'base_workspace' => $repo,
            'provider' => '',
            'config' => [],
        ]);
    }

    /** @param array<string,mixed> $signals */
    private function seedHub(AtlasLoopCampaign $campaign, array $signals, string $path = 'app/Services/Hub.php'): AtlasLoopTarget
    {
        return app(AtlasLoopTargetRepository::class)->upsert(
            $campaign->id,
            $path,
            hash('sha256', $path),
            ['score' => 0.9, 'self_contained' => 0.45, 'improvement' => 0.5, 'novelty' => 1.0, 'signals' => $signals],
            ['origin' => 'discovery'],
        );
    }

    private function detector(): AtlasLoopObraClusterDetectorService
    {
        return new AtlasLoopObraClusterDetectorService(
            app(AtlasSelfImprovementProposalBacklogService::class),
            null, // news up a repo-scoped AtlasLoopWiredCallerService at detect time
            new AtlasLoopHarnessGuard(),
        );
    }

    /** @return \Illuminate\Support\Collection<int,AtlasLoopTarget> */
    private function targets(AtlasLoopCampaign $campaign)
    {
        return AtlasLoopTarget::query()->where('campaign_id', $campaign->id)->get();
    }

    private function wiredSignals(): array
    {
        return ['impact_real_callers' => 3, 'cyclomatic' => 12, 'cyclomatic_total' => 40, 'refactor_leverage' => 0.7];
    }

    public function test_detector_is_inert_when_flag_off(): void
    {
        config(['atlas.loop.obra_cluster_detection_enabled' => false]);
        $repo = $this->repoWithHubAndCallers(['app/Services/CallerA.php', 'app/Services/CallerB.php']);
        $campaign = $this->campaign($repo);
        $this->seedHub($campaign, $this->wiredSignals());

        $created = $this->detector()->detect($campaign, $this->targets($campaign));

        $this->assertSame([], $created, 'flag OFF => the producer is fully inert (no candidate)');
    }

    public function test_parks_obra_candidate_for_a_wired_high_leverage_hub(): void
    {
        $repo = $this->repoWithHubAndCallers(['app/Services/CallerA.php', 'app/Services/CallerB.php']);
        $campaign = $this->campaign($repo);
        $this->seedHub($campaign, $this->wiredSignals());

        $created = $this->detector()->detect($campaign, $this->targets($campaign));

        $this->assertCount(1, $created, 'a wired, complex, high-leverage hub with >=2 real callers parks one candidate');
        $candidate = $created[0];
        $this->assertSame('app/Services/Hub.php', $candidate['hub_path']);
        $this->assertSame(
            ['app/Services/CallerA.php', 'app/Services/CallerB.php', 'app/Services/Hub.php'],
            $candidate['cluster_files'],
            'the cluster is the hub + its REAL measured production callers (sorted)',
        );
        $this->assertNotSame('', (string) $candidate['proposal_id'], 'a backlog proposal id is returned');
        $this->assertSame(3, $candidate['routing_rationale']['measured_impact_callers']);
        $this->assertSame(2, $candidate['routing_rationale']['measured_grep_caller_paths']);
    }

    public function test_forbidden_self_target_member_rejects_entire_cluster(): void
    {
        // One real caller is a PÉTREO forbidden self-target (a model the loop must never touch).
        $repo = $this->repoWithHubAndCallers(['app/Services/CallerA.php', 'app/Models/AtlasLoopProposal.php']);
        $campaign = $this->campaign($repo);
        $this->seedHub($campaign, $this->wiredSignals());

        $created = $this->detector()->detect($campaign, $this->targets($campaign));

        $this->assertSame([], $created, 'any forbidden-self-target member rejects the whole cluster (fail-closed)');
    }

    public function test_candidate_requires_two_measured_files_so_stale_graph_hub_is_rejected(): void
    {
        // Hub qualifies by the (stale-graph-inflatable) impact count, but has ZERO real
        // working-tree callers => grep returns [] => cluster < 2 files => NO candidate.
        $repo = $this->repoWithHubAndCallers([]); // only Hub.php exists, nothing references it
        $campaign = $this->campaign($repo);
        $this->seedHub($campaign, $this->wiredSignals()); // impact_real_callers=3 (phantom)

        $created = $this->detector()->detect($campaign, $this->targets($campaign));

        $this->assertSame([], $created, 'a hub with no REAL callers cannot form a >=2-file cluster (phantom-cluster hole closed)');
    }

    public function test_thresholds_gate_candidate_production(): void
    {
        $repo = $this->repoWithHubAndCallers(['app/Services/CallerA.php', 'app/Services/CallerB.php']);
        $campaign = $this->campaign($repo);
        // Below the cyclomatic floor (3 < 10) — not complex enough to be worth an obra.
        $this->seedHub($campaign, ['impact_real_callers' => 3, 'cyclomatic' => 3, 'cyclomatic_total' => 9, 'refactor_leverage' => 0.7]);

        $created = $this->detector()->detect($campaign, $this->targets($campaign));

        $this->assertSame([], $created, 'a target below any threshold (cyclomatic) produces no candidate');
    }

    public function test_identical_cluster_hash_is_cooldown_deduped(): void
    {
        $repo = $this->repoWithHubAndCallers(['app/Services/CallerA.php', 'app/Services/CallerB.php']);
        $campaign = $this->campaign($repo);
        $this->seedHub($campaign, $this->wiredSignals());
        $detector = $this->detector();

        $first = $detector->detect($campaign, $this->targets($campaign));
        $second = $detector->detect($campaign, $this->targets($campaign));

        $this->assertCount(1, $first, 'first cycle parks the candidate');
        $this->assertSame([], $second, 'the same cluster hash within the cooldown window is deduped (no spam)');
    }

    public function test_detector_never_enqueues_a_loop_task(): void
    {
        $repo = $this->repoWithHubAndCallers(['app/Services/CallerA.php', 'app/Services/CallerB.php']);
        $campaign = $this->campaign($repo);
        $this->seedHub($campaign, $this->wiredSignals());

        $this->detector()->detect($campaign, $this->targets($campaign));

        $this->assertSame(
            0,
            DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->count(),
            'the producer is proposal-only: it enqueues ZERO loop tasks',
        );
    }

    public function test_refiller_is_wired_with_detector_and_framework_synthesizer_not_clobbered(): void
    {
        $refiller = app(AtlasLoopQueueRefiller::class);

        $fw = (new \ReflectionProperty($refiller, 'frameworkRefactorSynthesizer'))->getValue($refiller);
        $detector = (new \ReflectionProperty($refiller, 'obraClusterDetector'))->getValue($refiller);

        $this->assertInstanceOf(
            \App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopFrameworkRefactorSynthesizer::class,
            $fw,
            'the LIVE framework_refactor synthesizer must still occupy arg 8 (not clobbered by the detector)',
        );
        $this->assertInstanceOf(
            AtlasLoopObraClusterDetectorService::class,
            $detector,
            'the obra cluster detector must be wired as arg 9',
        );
    }

    public function test_caller_paths_returns_measured_production_callers_excluding_self_and_tests(): void
    {
        $repo = $this->repoWithHubAndCallers(['app/Services/CallerA.php', 'app/Services/CallerB.php']);
        // A test file referencing the hub must NOT count as a production caller.
        File::ensureDirectoryExists($repo.'/tests/Unit/Services');
        File::put(
            $repo.'/tests/Unit/Services/HubTest.php',
            "<?php\nnamespace Tests\\Unit\\Services;\nuse App\\Services\\Hub;\nfinal class HubTest { public function t(): void { new Hub(); } }\n",
        );

        $paths = (new AtlasLoopWiredCallerService($repo))->callerPaths(['app/Services/Hub.php']);

        $this->assertArrayHasKey('app/Services/Hub.php', $paths, 'a resolvable FQCN is measured');
        $this->assertSame(
            ['app/Services/CallerA.php', 'app/Services/CallerB.php'],
            $paths['app/Services/Hub.php'],
            'returns production callers only — the hub itself and the test are excluded',
        );
    }
}
