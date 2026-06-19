<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\AtlasLoopRealWorkScorecardService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWorkShapeRouter;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The live-soak starvation fix: discovery already stamped coverage deficits as
 * shape=characterization_test, but the refiller had no executor for that shape. This pins the
 * missing candidate->task conversion so a discovered self-loop coverage deficit becomes real,
 * scorecard-visible work instead of falling through to the generic generator and ending tasks=0.
 */
final class AtlasLoopCoverageDeficitRefillTest extends TestCase
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
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    private function repo(): string
    {
        $dir = sys_get_temp_dir().'/atlas-covdef-refill-'.bin2hex(random_bytes(5));
        $this->dirs[] = $dir;
        File::ensureDirectoryExists($dir.'/app/Services/Ai/AutonomousEvolution/Discovery');
        File::put($dir.'/app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopBacklogAutoFeederService.php', $this->source());

        return $dir;
    }

    private function source(): string
    {
        return <<<'PHP'
<?php

namespace App\Services\Ai\AutonomousEvolution\Discovery;

final class AtlasLoopBacklogAutoFeederService
{
    public function decide(int $a, int $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if ($a >= 0 && $b <= 10) {
            return false;
        }
        if ($a > 0) {
            return true;
        }

        return false;
    }
}
PHP;
    }

    private function refiller(?LoopExecutionDriver $driver = null): AtlasLoopQueueRefiller
    {
        $driver ??= new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                return ['status' => 'noop'];
            }
        };
        $this->app->bind(AtlasEvolutionTaskGenerator::class, fn () => new AtlasEvolutionTaskGenerator($driver));

        return new AtlasLoopQueueRefiller(
            app(AtlasLoopTargetDiscoveryService::class),
            app(AtlasLoopTargetRepository::class),
            app(AtlasEvolutionTaskGenerator::class),
            app(AtlasLoopBackService::class),
            app(AtlasLoopStore::class),
            null,
            new AtlasLoopHarnessGuard,
            null,
            null,
            new AtlasLoopWorkShapeRouter,
        );
    }

    private function campaign(string $repo): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => 'running',
            'goal' => 'coverage deficit refill',
            'base_workspace' => $repo,
            'provider' => '',
            'config' => ['decision_priority_enabled' => false],
        ]);
    }

    private function target(AtlasLoopCampaign $campaign): AtlasLoopTarget
    {
        return app(AtlasLoopTargetRepository::class)->upsert(
            (string) $campaign->id,
            'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopBacklogAutoFeederService.php',
            hash('sha256', $this->source()),
            [
                'score' => 0.95,
                'self_contained' => 0.45,
                'improvement' => 1.0,
                'novelty' => 1.0,
                'signals' => [
                    'shape' => 'characterization_test',
                    'coverage_objective' => 'ADD a characterization test for AtlasLoopBacklogAutoFeederService',
                    'coverage_deficit' => 1.0,
                    'coverage_deficit_mutants' => 8,
                    'has_sibling_test' => false,
                    'framework_reach' => 1,
                ],
            ],
            ['origin' => 'discovery'],
        );
    }

    private function invokeEnqueue(AtlasLoopCampaign $campaign, AtlasLoopTarget $target): string
    {
        $refiller = $this->refiller();
        $ref = new \ReflectionMethod($refiller, 'generateAndEnqueue');
        $ref->setAccessible(true);

        return (string) $ref->invoke($refiller, $campaign, $target, '');
    }

    private function invokeEnqueueWithRefiller(AtlasLoopQueueRefiller $refiller, AtlasLoopCampaign $campaign, AtlasLoopTarget $target): string
    {
        $ref = new \ReflectionMethod($refiller, 'generateAndEnqueue');
        $ref->setAccessible(true);

        return (string) $ref->invoke($refiller, $campaign, $target, '');
    }

    public function test_coverage_deficit_target_enqueues_real_characterization_work(): void
    {
        config(['atlas.loop.characterization_test_lane_enabled' => true]);
        $campaign = $this->campaign($this->repo());
        $target = $this->target($campaign);

        $outcome = $this->invokeEnqueue($campaign, $target);

        $this->assertSame('enqueued', $outcome);
        $task = AtlasLoopTask::query()->where('campaign_id', $campaign->id)->first();
        $this->assertNotNull($task);
        $this->assertSame('coverage_gap_characterization', $task->source);
        $payload = is_array($task->payload) ? $task->payload : (array) json_decode((string) $task->payload, true);

        $this->assertSame('characterization_test', $payload['objective_kind'] ?? null);
        $this->assertSame('create_new_sibling', $payload['characterization_mode'] ?? null);
        $this->assertSame('return_true', $payload['characterization_operator'] ?? null);
        $this->assertSame(
            'tests/Unit/Ai/AutonomousEvolution/Discovery/AtlasLoopBacklogAutoFeederServiceTest.php',
            $payload['characterization_sibling_test'] ?? null,
        );
        $this->assertSame(AtlasLoopTarget::STATUS_QUEUED, $target->fresh()->status);

        $scorecard = app(AtlasLoopRealWorkScorecardService::class)->scorecard((string) $campaign->id);
        $this->assertSame(1, $scorecard['tasks_total']);
        $this->assertSame(1, $scorecard['real_work_tasks']);
        $this->assertSame(1, $scorecard['verification_tasks']);
        $this->assertTrue($scorecard['claim_policy']['loop_real_work_claim_allowed']);
    }

    public function test_real_work_supply_profile_keeps_coverage_lane_while_proxy_refactor_is_disabled(): void
    {
        config([
            'atlas.loop.proxy_refactor_supply_enabled' => false,
            'atlas.loop.characterization_test_lane_enabled' => true,
            'atlas.loop.framework_refactor_enabled' => true,
            'atlas.loop.refactor_objectives_enabled' => true,
            'atlas.loop.framework_edge_gap_fallback_enabled' => false,
            'atlas.loop.generic_provider_fallback_enabled' => false,
        ]);
        $campaign = $this->campaign($this->repo());
        $target = $this->target($campaign);

        $outcome = $this->invokeEnqueue($campaign, $target);

        $this->assertSame('enqueued', $outcome);
        $task = AtlasLoopTask::query()->where('campaign_id', $campaign->id)->first();
        $this->assertNotNull($task);
        $payload = is_array($task->payload) ? $task->payload : (array) json_decode((string) $task->payload, true);
        $this->assertSame('characterization_test', $payload['objective_kind'] ?? null);

        $scorecard = app(AtlasLoopRealWorkScorecardService::class)->scorecard((string) $campaign->id);
        $this->assertSame(1, $scorecard['real_work_tasks']);
        $this->assertSame(0, $scorecard['proxy_refactor_tasks']);
        $this->assertTrue($scorecard['claim_policy']['loop_real_work_claim_allowed']);
    }

    public function test_generic_provider_fallback_can_be_disabled_for_soak_supply(): void
    {
        config([
            'atlas.loop.generic_provider_fallback_enabled' => false,
            'atlas.loop.characterization_test_lane_enabled' => false,
            'atlas.loop.framework_refactor_enabled' => false,
            'atlas.loop.refactor_objectives_enabled' => false,
        ]);
        $campaign = $this->campaign($this->repo());
        $target = app(AtlasLoopTargetRepository::class)->upsert(
            (string) $campaign->id,
            'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopBacklogAutoFeederService.php',
            hash('sha256', $this->source()),
            [
                'score' => 0.95,
                'self_contained' => 0.45,
                'improvement' => 1.0,
                'novelty' => 1.0,
                'signals' => [
                    'has_sibling_test' => false,
                    'framework_reach' => 0,
                    'impact_real_callers' => 1,
                    'cyclomatic' => 3,
                ],
            ],
            ['origin' => 'discovery'],
        );

        $refiller = $this->refiller(new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                throw new \RuntimeException('provider fallback must not run during soak supply');
            }
        });

        $outcome = $this->invokeEnqueueWithRefiller($refiller, $campaign, $target);

        $this->assertSame('quarantined', $outcome);
        $this->assertSame(0, AtlasLoopTask::query()->where('campaign_id', $campaign->id)->count());
        $this->assertSame(AtlasLoopTarget::STATUS_QUARANTINED, $target->fresh()->status);
        $this->assertSame('generic_provider_fallback_disabled', $target->fresh()->reason);
    }
}
