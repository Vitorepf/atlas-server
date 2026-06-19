<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBugReproductionLane;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWorkShapeRouter;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * §11.4 — bug-fix reproduction lane WIRED into the queue refiller's enqueue seam. The lane was a
 * dead class (built + unit-tested, never called by the refiller). This proves: a claimed target
 * whose discovery signals carry a RUNNABLE failure handle (failure_test_path + failing_assertion)
 * is turned into a FIRST-CLASS bug_fix task with the lane's RED-required acceptance — BEFORE the
 * refactor/framework cascade — and that with the flag OFF (or no failure handle) the lane is inert.
 *
 * HONEST NOTE: nothing in the LIVE discovery path stamps failure_test_path/failure_command into a
 * target's signals today, so the live default path is byte-identical; this test seeds the handle
 * directly to prove the SEAM fires once a failure source feeds it.
 */
final class AtlasLoopBugReproductionWiringTest extends TestCase
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
        $d = sys_get_temp_dir().'/atlas-bugrepro-'.bin2hex(random_bytes(5));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d.'/app/Services');
        // The target source MUST exist on disk: generateAndEnqueue() quarantines a missing source.
        File::put(
            $d.'/app/Services/Calculator.php',
            "<?php\nnamespace App\\Services;\nfinal class Calculator { public function divide(int \$a, int \$b): int { return intdiv(\$a, \$b); } }\n",
        );

        return $d;
    }

    private function refiller(): AtlasLoopQueueRefiller
    {
        // A no-op generator so the cascade NEVER reaches the provider call (the bug-repro branch must
        // intercept first); mirrors AtlasLoopDecisionPriorityWiringTest's stub.
        $this->app->bind(AtlasEvolutionTaskGenerator::class, fn () => new AtlasEvolutionTaskGenerator(new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                return ['status' => 'noop'];
            }
        }));

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
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            new AtlasLoopBugReproductionLane,
        );
    }

    private function campaign(string $repo): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1', 'status' => 'running', 'goal' => 'bugrepro',
            'base_workspace' => $repo, 'provider' => '', 'config' => ['decision_priority_enabled' => false],
        ]);
    }

    /** @param array<string,mixed> $signals */
    private function target(AtlasLoopCampaign $campaign, array $signals): AtlasLoopTarget
    {
        return app(AtlasLoopTargetRepository::class)->upsert(
            $campaign->id, 'app/Services/Calculator.php', hash('sha256', 'calc'.json_encode($signals)),
            ['score' => 0.6, 'self_contained' => 1.0, 'improvement' => 0.4, 'novelty' => 1.0, 'signals' => $signals],
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

    public function test_failure_handle_target_enqueues_a_red_required_bug_fix_task(): void
    {
        config(['atlas.loop.bug_reproduction_lane_enabled' => true]);
        $repo = $this->repo();
        $campaign = $this->campaign($repo);
        $target = $this->target($campaign, [
            'failure_test_path' => 'tests/Feature/Services/CalculatorTest.php',
            'failing_assertion' => 'test_divide_guards_against_zero',
            'failure_message' => 'DivisionByZeroError thrown',
        ]);

        $outcome = $this->invokeEnqueue($campaign, $target);
        $this->assertSame('enqueued', $outcome, 'a runnable failure handle is enqueued as a bug_fix');

        $tasks = AtlasLoopTask::query()->where('campaign_id', $campaign->id)->get();
        $this->assertCount(1, $tasks, 'exactly ONE bug_fix task is enqueued');
        $task = $tasks->first();

        $this->assertStringContainsString('Reproduce', (string) $task->objective);
        $this->assertStringContainsString('RED', (string) $task->objective);

        $payload = is_array($task->payload) ? $task->payload : (array) json_decode((string) $task->payload, true);

        // LOAD-BEARING: objective_kind==='bug_fix' ONLY appears if the wired bug-repro branch ran.
        $this->assertSame('bug_fix', $payload['objective_kind'] ?? null);
        $this->assertTrue($payload['revert_recheck'] ?? false, 'a bug-fix is behavior-changing (revert_recheck on)');
        $this->assertTrue($payload['acceptance']['red_required'] ?? false, 'the acceptance RED bar is required');
        $this->assertSame(
            ['./vendor/bin/phpunit tests/Feature/Services/CalculatorTest.php'],
            $payload['acceptance']['commands'] ?? null,
            'the RED command is derived from the failure_test_path',
        );

        // The target transitioned to QUEUED with the bug-repro reason.
        $this->assertSame(
            AtlasLoopTarget::STATUS_QUEUED,
            (string) DB::table('atlas_loop_targets')->where('id', $target->id)->value('status'),
        );
    }

    public function test_lane_inert_when_flag_off(): void
    {
        config(['atlas.loop.bug_reproduction_lane_enabled' => false]);
        $repo = $this->repo();
        $campaign = $this->campaign($repo);
        $target = $this->target($campaign, [
            'failure_test_path' => 'tests/Feature/Services/CalculatorTest.php',
            'failing_assertion' => 'test_divide_guards_against_zero',
        ]);

        $outcome = $this->invokeEnqueue($campaign, $target);

        // No bug_fix task: with the flag OFF the branch is skipped and the cascade falls through to
        // the no-op generator (which quarantines/defers since it returns no real work).
        $bugTasks = AtlasLoopTask::query()
            ->where('campaign_id', $campaign->id)
            ->where('payload->objective_kind', AtlasLoopBugReproductionLane::SHAPE)
            ->count();
        $this->assertSame(0, $bugTasks, 'flag OFF => no bug_fix task is enqueued');
        $this->assertNotSame('enqueued', $outcome, 'flag OFF => the bug-repro lane did not enqueue');
    }

    public function test_no_failure_handle_falls_through_byte_identical(): void
    {
        config(['atlas.loop.bug_reproduction_lane_enabled' => true]);
        $repo = $this->repo();
        $campaign = $this->campaign($repo);
        // No failure_test_path / failure_command => the lane has no runnable handle => fail-closed.
        $target = $this->target($campaign, ['cyclomatic' => 4]);

        $this->invokeEnqueue($campaign, $target);

        $bugTasks = AtlasLoopTask::query()
            ->where('campaign_id', $campaign->id)
            ->where('payload->objective_kind', AtlasLoopBugReproductionLane::SHAPE)
            ->count();
        $this->assertSame(0, $bugTasks, 'no runnable failure handle => no fabricated bug_fix (fail-closed)');
    }
}
