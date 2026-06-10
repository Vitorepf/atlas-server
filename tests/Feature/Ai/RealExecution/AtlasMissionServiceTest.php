<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\RealExecution;

use App\Models\AtlasAurgNode;
use App\Services\Ai\AtlasMemoryPrivacyService;
use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use App\Services\Ai\RealExecution\AtlasLiveCodeDeliveryService;
use App\Services\Ai\RealExecution\AtlasMissionOutcomeRecorder;
use App\Services\Ai\RealExecution\AtlasMissionService;
use App\Services\Ai\RealExecution\GovernedBranchMaterializationService;
use App\Services\Ai\RealExecution\MissionDeliveryOrchestrator;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * S2.F3 — the CLOSED MISSION LOOP product surface ({@see AtlasMissionService}).
 *
 * Proves the product contract end to end, ZERO provider spend (a fake delivery
 * returns a certified sandbox file), with the REAL materializer (real branch) and
 * the REAL ingestion (real AURG mission node), all on sqlite:
 *  - run() returns the flat product envelope {mission_id, request, delivered,
 *    branch, brain_context_used, evidence_recorded, review_commands, ...};
 *  - a delivered run RECORDS the mission back INTO the brain (the node exists) and
 *    reports evidence_recorded=true — the loop closes;
 *  - a blocked delivery records NO mission node and reports evidence_recorded=false;
 *  - --no-brain bypasses the brain-context bridge for that run WITHOUT mutating the
 *    global flag (other runs untouched), and the write-back still records;
 *  - the minted mission_id IS the id the brain stored its mission node under.
 */
final class AtlasMissionServiceTest extends TestCase
{
    private string $repo = '';

    private string $sandboxFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAurgTable();
        config()->set('atlas.aurg.enabled', true);
        config()->set('atlas.mission.record_outcome_enabled', true);
        config()->set('atlas.mission.brain_context_enabled', false);

        // A real throwaway git repo so the materializer makes a real branch.
        $this->repo = sys_get_temp_dir().'/atlas-mission-f3-repo-'.substr(md5(uniqid('', true)), 0, 8);
        File::makeDirectory($this->repo, 0777, true, true);
        File::put($this->repo.'/README.md', "base\n");
        $this->git(['init', '-q']);
        $this->git(['add', '-A']);
        $this->git(['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);

        // The "generated" certified artifact (stands in for the provider output).
        $this->sandboxFile = sys_get_temp_dir().'/atlas-mission-f3-sandbox-'.substr(md5(uniqid('', true)), 0, 8).'.php';
        File::put($this->sandboxFile, "<?php\n\nnamespace App\\Generated;\n\nclass MissionF3 { public function ok(): bool { return true; } }\n");
    }

    protected function tearDown(): void
    {
        if ($this->repo !== '') {
            File::deleteDirectory($this->repo);
        }
        @unlink($this->sandboxFile);
        parent::tearDown();
    }

    public function test_run_delivers_returns_envelope_and_records_the_mission_into_the_brain(): void
    {
        $service = $this->service(AtlasLiveCodeDeliveryService::STATUS_CERTIFIED);

        $r = $service->run('add a MissionF3 helper class', ['repo_dir' => $this->repo, 'id' => 'f3-deliver']);

        // The flat product envelope.
        $this->assertSame(AtlasMissionService::SCHEMA, $r['schema_version']);
        $this->assertSame('f3-deliver', $r['mission_id']);
        $this->assertSame('add a MissionF3 helper class', $r['request']);
        $this->assertTrue($r['delivered'], 'reason: '.($r['reason'] ?? ''));
        $this->assertSame('atlas/materialize/f3-deliver', $r['branch']);
        $this->assertFalse($r['brain_context_used'], 'context flag is off in this test');
        $this->assertTrue($r['evidence_recorded'], 'a delivered run must feed the brain back');
        $this->assertTrue($r['main_untouched']);
        $this->assertTrue($r['never_merged']);
        $this->assertNotEmpty($r['review_commands']);

        // The loop CLOSED: the mission node exists in the brain under the SAME id.
        $node = AtlasAurgNode::query()->whereKey('mission:mission:f3-deliver')->first();
        $this->assertNotNull($node, 'the delivered mission must be recorded into the AURG');
        $this->assertSame('atlas/materialize/f3-deliver', $node->meta['branch'] ?? null);
        $this->assertTrue((bool) ($node->meta['never_merged'] ?? false));
        // The evidence node was recorded too.
        $this->assertTrue(AtlasAurgNode::query()->whereKey('mission:evidence:f3-deliver')->exists());
    }

    public function test_run_with_blocked_delivery_records_no_mission_node(): void
    {
        $service = $this->service(AtlasLiveCodeDeliveryService::STATUS_BLOCKED);

        $r = $service->run('a request the provider could not certify', ['repo_dir' => $this->repo, 'id' => 'f3-blocked']);

        $this->assertFalse($r['delivered']);
        $this->assertNull($r['branch']);
        $this->assertFalse($r['evidence_recorded']);
        $this->assertSame('delivery', $r['stage']);
        $this->assertNotSame('', (string) ($r['reason'] ?? ''));

        // Nothing was recorded into the brain for a blocked delivery.
        $this->assertFalse(AtlasAurgNode::query()->whereKey('mission:mission:f3-blocked')->exists());
        $this->assertSame(0, (int) AtlasAurgNode::query()->where('source_kind', 'mission')->count());
    }

    public function test_no_brain_bypasses_context_without_mutating_global_flag_and_still_records(): void
    {
        // Brain context flag ON globally — --no-brain must override THIS run only.
        config()->set('atlas.mission.brain_context_enabled', true);

        $service = $this->service(AtlasLiveCodeDeliveryService::STATUS_CERTIFIED);

        $r = $service->run('build with brain bypassed', [
            'repo_dir' => $this->repo,
            'id' => 'f3-nobrain',
            'no_brain' => true,
        ]);

        $this->assertTrue($r['delivered'], 'reason: '.($r['reason'] ?? ''));
        $this->assertFalse($r['brain_context_used'], '--no-brain must skip the context bridge');
        // The write-back still ran (bypassing the read-in does not silence compounding).
        $this->assertTrue($r['evidence_recorded']);
        $this->assertTrue(AtlasAurgNode::query()->whereKey('mission:mission:f3-nobrain')->exists());

        // GLOBAL flag is restored — the bypass was scoped to that one run only.
        $this->assertTrue((bool) config('atlas.mission.brain_context_enabled'));
    }

    public function test_run_mints_a_deterministic_id_when_none_is_pinned(): void
    {
        $service = $this->service(AtlasLiveCodeDeliveryService::STATUS_CERTIFIED);

        $request = 'a mission with no explicit id';
        $r = $service->run($request, ['repo_dir' => $this->repo]);

        $expected = 'mission-'.substr(hash('sha256', $request), 0, 10);
        $this->assertSame($expected, $r['mission_id']);
        // The minted id is the branch + the recorded node id (end-to-end identity).
        $this->assertSame('atlas/materialize/'.$expected, $r['branch']);
        $this->assertTrue(AtlasAurgNode::query()->whereKey('mission:mission:'.$expected)->exists());
    }

    // ------------------------------------------------------------------
    // fixtures
    // ------------------------------------------------------------------

    /**
     * The product service wired with the REAL materializer + REAL ingestion +
     * REAL query (provider-bound), and a FAKE delivery (zero provider spend).
     */
    private function service(string $deliveryStatus): AtlasMissionService
    {
        $ingestion = new AtlasRealityGraphIngestionService(
            new CrossDomainTaxonomyMap,
            app(AtlasMemoryPrivacyService::class),
            app(AtlasCrossDomainMeshService::class),
        );

        $orchestrator = new MissionDeliveryOrchestrator(
            $this->fakeDelivery($deliveryStatus),
            new GovernedBranchMaterializationService,
            new AtlasRealityGraphQueryService,
            $ingestion,
            new AtlasMissionOutcomeRecorder($ingestion),
        );

        return new AtlasMissionService($orchestrator);
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

                return [
                    'schema_version' => 'x',
                    'status' => AtlasLiveCodeDeliveryService::STATUS_CERTIFIED,
                    'certified' => true,
                    'provider' => 'fake_for_test',
                    'files' => [['path' => 'app/Generated/MissionF3.php', 'sandbox_path' => $this->sandboxFile]],
                    'syntax_check' => ['ok' => true, 'tool' => 'php -l'],
                ];
            }
        };
    }

    private function bootAurgTable(): void
    {
        $migration = require database_path('migrations/2026_06_09_120000_create_atlas_aurg_graph_tables.php');
        if (Schema::hasTable('atlas_aurg_nodes')) {
            $migration->down();
        }
        $migration->up();
    }

    /** @param list<string> $argv */
    private function git(array $argv): void
    {
        (new \Symfony\Component\Process\Process(array_merge(['git'], $argv), $this->repo))->run();
    }
}
