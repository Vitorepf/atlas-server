<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use App\Services\Ai\AutonomousEvolution\AtlasLoopRedReasonGate;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopExternalResearchService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWorkShapeRouter;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * §5.5 — research-as-assist, proven AT THE WIRING SEAM. The per-target authoring lane
 * ({@see AtlasLoopQueueRefiller::generateAndEnqueue}) must, when armed, derive an egress-safe research TOPIC
 * from the target's work-shape and pass it (with repo_root) into the generator's options — so the existing
 * EXTERNAL-RESEARCH advisory slot guides authoring. The obligation stays a real RED test on the real target;
 * research only informs. Flag OFF must be byte-identical (no research_topic in the generator options).
 *
 * The test captures the EXACT options the lane hands the generator (a capturing generator subclass), so it
 * bites on revert: remove the array_merge wiring and research_topic disappears even when armed.
 */
final class AtlasLoopResearchAuthoringWiringTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_targets')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        // Route the target straight to the generic provider authoring path (generateBestForTarget): every
        // substantive lane OFF, the shape router disabled (no skip/heavy-route), generic fallback ON.
        config([
            'atlas.loop.decision_router_enabled' => false,
            'atlas.loop.refactor_objectives_enabled' => false,
            'atlas.loop.framework_refactor_enabled' => false,
            'atlas.loop.decompose_supply_enabled' => false,
            'atlas.loop.extract_class_enabled' => false,
            'atlas.loop.multi_file_refactor_enabled' => false,
            'atlas.loop.generic_provider_fallback_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    private function repo(): string
    {
        $d = sys_get_temp_dir().'/atlas-research-authoring-'.bin2hex(random_bytes(6));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d.'/app');
        File::put($d.'/app/Foo.php', "<?php\n\nnamespace App;\n\nfinal class Foo\n{\n    public function v(): int\n    {\n        return 1;\n    }\n}\n");

        return $d;
    }

    private function campaign(string $repo): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => 'running',
            'goal' => 'research-authoring',
            'base_workspace' => $repo,
            'provider' => '',
            'config' => ['decision_priority_enabled' => false],
        ]);
    }

    private function target(AtlasLoopCampaign $campaign): AtlasLoopTarget
    {
        return AtlasLoopTarget::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.target.v1',
            'target_path' => 'app/Foo.php',
            'target_key' => hash('sha256', 'app/Foo.php'),
            'content_hash' => hash('sha256', 'foo-body'),
            'status' => AtlasLoopTarget::STATUS_QUEUED,
            'signals' => ['shape' => AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX],
            'lineage' => ['origin' => AtlasLoopTarget::ORIGIN_DISCOVERY],
            'attempts' => 0,
            'max_attempts' => 3,
        ]);
    }

    /**
     * A capturing DRIVER records the EXACT generation prompt the generator built. AtlasEvolutionTaskGenerator
     * is final (cannot subclass), so we observe the wiring through its real output: a research_topic in the
     * generator options makes withExternalResearchContext inject the (faked) research note INTO the prompt.
     * note present ⟺ the lane wired a research_topic ⟺ the deriver fired. This bites on revert.
     */
    private function capturingDriver(): LoopExecutionDriver
    {
        return new class implements LoopExecutionDriver
        {
            public string $captured = '';

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                $this->captured = $intent;

                return ['status' => 'noop'];
            }
        };
    }

    private function generatorWith(LoopExecutionDriver $driver): AtlasEvolutionTaskGenerator
    {
        // A real backend (faked) so an armed + wired topic actually yields a note; the egress filter still runs.
        return new AtlasEvolutionTaskGenerator(
            $driver,
            new AtlasLoopRedReasonGate,
            new AtlasLoopExternalResearchService(
                searchToolAvailable: true,
                backend: static fn (string $topic): string => "RESEARCH_NOTE_FOR::{$topic}",
            ),
        );
    }

    private function refiller(AtlasEvolutionTaskGenerator $generator): AtlasLoopQueueRefiller
    {
        return new AtlasLoopQueueRefiller(
            app(AtlasLoopTargetDiscoveryService::class),
            app(AtlasLoopTargetRepository::class),
            $generator,
            app(AtlasLoopBackService::class),
            app(AtlasLoopStore::class),
        );
    }

    private function runLane(AtlasLoopCampaign $campaign, AtlasLoopTarget $target, AtlasEvolutionTaskGenerator $generator): void
    {
        $m = new ReflectionMethod(AtlasLoopQueueRefiller::class, 'generateAndEnqueue');
        $m->setAccessible(true);
        $m->invoke($this->refiller($generator), $campaign, $target, 'test');
    }

    public function test_armed_lane_feeds_an_egress_safe_research_note_into_authoring(): void
    {
        config(['atlas.loop.research_authoring_enabled' => true]);
        $campaign = $this->campaign($this->repo());
        $driver = $this->capturingDriver();

        $this->runLane($campaign, $this->target($campaign), $this->generatorWith($driver));

        $this->assertNotSame('', $driver->captured, 'the generic authoring path must be reached (the prompt was built)');
        $this->assertStringContainsString('EXTERNAL RESEARCH (advisory', $driver->captured, 'armed ⇒ the research note reaches the authoring prompt');
        // The note carries the DERIVED concept topic — and no repo path (sovereignty by construction).
        $this->assertStringContainsString('RESEARCH_NOTE_FOR::', $driver->captured);
        $this->assertMatchesRegularExpression('/RESEARCH_NOTE_FOR::[^\n]*(refactor|design|complexity|patterns)/i', $driver->captured);
        $this->assertStringNotContainsString('.php', substr($driver->captured, (int) strpos($driver->captured, 'RESEARCH_NOTE_FOR::')));
    }

    public function test_flag_off_is_byte_identical_no_research_note(): void
    {
        config(['atlas.loop.research_authoring_enabled' => false]);
        $campaign = $this->campaign($this->repo());
        $driver = $this->capturingDriver();

        $this->runLane($campaign, $this->target($campaign), $this->generatorWith($driver));

        $this->assertNotSame('', $driver->captured, 'the generic authoring path must be reached (the prompt was built)');
        $this->assertStringNotContainsString('EXTERNAL RESEARCH', $driver->captured, 'flag OFF ⇒ no research_topic ⇒ no note in the prompt (byte-identical)');
    }
}
