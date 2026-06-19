<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBugReproductionLane;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopFailureHandleSource;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWorkShapeRouter;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * S3 — END-TO-END proof of the REAL bug-fix work-supply: a harvested DETERMINISTIC-RED handle in
 * `atlas_loop_failure_handles` is STAMPED by discovery onto the matching target's signals, which
 * then drives the already-wired AtlasLoopQueueRefiller::tryBugReproduction to enqueue a FIRST-CLASS
 * bug_fix task. The stamp is the missing producer the refiller gap documented: without it the lane
 * is starved. LOAD-BEARING: with discovery_failure_handle_stamp_enabled OFF the signal is never
 * stamped => tryBugReproduction returns null => NO bug_fix task (both assertions go RED).
 */
final class AtlasLoopFailureHandleStampWiringTest extends TestCase
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
        if (! Schema::hasTable('atlas_loop_failure_handles')) {
            (require base_path('database/migrations/2026_06_19_000100_create_atlas_loop_failure_handles_table.php'))->up();
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
        $d = sys_get_temp_dir().'/atlas-fhstamp-'.bin2hex(random_bytes(5));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d.'/app/Services');
        // A self-contained, admissible target file (framework_reach==0, 40<=LOC<=400, lints clean,
        // declares a class) so discovery scores + upserts it with the stamped signals.
        File::put($d.'/app/Services/Calculator.php', $this->calculatorSource());

        return $d;
    }

    private function calculatorSource(): string
    {
        $body = "<?php\n\nnamespace App\\Services;\n\nfinal class Calculator\n{\n";
        // Pad to >= 40 LOC of pure-logic, framework-free code so the admissibility gate passes.
        for ($i = 0; $i < 12; $i++) {
            $body .= "    public function op{$i}(int \$a, int \$b): int\n    {\n        return \$a + \$b + {$i};\n    }\n\n";
        }
        $body .= "    public function divide(int \$a, int \$b): int\n    {\n        return intdiv(\$a, \$b);\n    }\n}\n";

        return $body;
    }

    private function refiller(): AtlasLoopQueueRefiller
    {
        // A no-op generator so the cascade NEVER reaches the provider call (the bug-repro branch must
        // intercept first); mirrors the bug-repro wiring test stub.
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
            'schema_version' => 'atlas.loop.campaign.v1', 'status' => 'running', 'goal' => 'fhstamp',
            'base_workspace' => $repo, 'provider' => '', 'config' => ['decision_priority_enabled' => false],
        ]);
    }

    private function seedHandle(string $path, string $testPath, string $assertion, string $message): void
    {
        DB::table('atlas_loop_failure_handles')->insert([
            'id' => (string) Str::uuid(),
            'dedup_key' => app(AtlasLoopFailureHandleSource::class)->dedupKey($path, $testPath),
            'path' => $path,
            'failure_test_path' => $testPath,
            'failure_command' => null,
            'failing_assertion' => $assertion,
            'failure_message' => $message,
            'recurrence_count' => 1,
            'last_seen_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    /** Run discovery (stamp fires here) and return the discovered+stamped target. */
    private function discoverTarget(AtlasLoopCampaign $campaign, string $repo): AtlasLoopTarget
    {
        $discovery = app(AtlasLoopTargetDiscoveryService::class);
        $discovery->discover($repo, $campaign->id, ['roots' => ['app/Services'], 'limit' => 12]);

        $target = AtlasLoopTarget::query()
            ->where('campaign_id', $campaign->id)
            ->where('target_path', 'app/Services/Calculator.php')
            ->first();
        $this->assertNotNull($target, 'discovery must have upserted the Calculator target');

        return $target;
    }

    private function invokeEnqueue(AtlasLoopCampaign $campaign, AtlasLoopTarget $target): string
    {
        $refiller = $this->refiller();
        $ref = new \ReflectionMethod($refiller, 'generateAndEnqueue');
        $ref->setAccessible(true);

        return (string) $ref->invoke($refiller, $campaign, $target, '');
    }

    public function test_stamped_failure_handle_enqueues_a_red_required_bug_fix_task(): void
    {
        config([
            'atlas.loop.discovery_failure_handle_stamp_enabled' => true,
            'atlas.loop.bug_reproduction_lane_enabled' => true,
        ]);
        $repo = $this->repo();
        $campaign = $this->campaign($repo);
        $this->seedHandle(
            'app/Services/Calculator.php',
            'tests/Feature/Services/CalculatorTest.php',
            'test_divide_guards_against_zero',
            'DivisionByZeroError thrown',
        );

        $target = $this->discoverTarget($campaign, $repo);

        // (1) discovery STAMPED the handle onto the target's signals.
        $signals = is_array($target->signals) ? $target->signals : (array) json_decode((string) $target->signals, true);
        $this->assertSame(
            'tests/Feature/Services/CalculatorTest.php',
            $signals['failure_test_path'] ?? null,
            'discovery stamps the handle test path onto signals',
        );

        $outcome = $this->invokeEnqueue($campaign, $target);
        $this->assertSame('enqueued', $outcome, 'the stamped failure handle is enqueued as a bug_fix');

        // (2) exactly ONE bug_fix task with the lane's RED-required acceptance.
        $tasks = AtlasLoopTask::query()->where('campaign_id', $campaign->id)->get();
        $this->assertCount(1, $tasks, 'exactly ONE bug_fix task is enqueued');
        $task = $tasks->first();
        $payload = is_array($task->payload) ? $task->payload : (array) json_decode((string) $task->payload, true);

        $this->assertSame('bug_fix', $payload['objective_kind'] ?? null);
        $this->assertTrue($payload['revert_recheck'] ?? false, 'a bug-fix is behavior-changing (revert_recheck on)');
        $this->assertTrue($payload['acceptance']['red_required'] ?? false, 'the acceptance RED bar is required');
        $this->assertSame(
            ['./vendor/bin/phpunit tests/Feature/Services/CalculatorTest.php'],
            $payload['acceptance']['commands'] ?? null,
            'the RED command is derived from the stamped failure_test_path',
        );
    }

    public function test_stamp_off_starves_the_bug_fix_lane(): void
    {
        // LOAD-BEARING mutation: stamp flag OFF => signals.failure_test_path absent =>
        // tryBugReproduction returns null => NO bug_fix task.
        config([
            'atlas.loop.discovery_failure_handle_stamp_enabled' => false,
            'atlas.loop.bug_reproduction_lane_enabled' => true,
        ]);
        $repo = $this->repo();
        $campaign = $this->campaign($repo);
        $this->seedHandle(
            'app/Services/Calculator.php',
            'tests/Feature/Services/CalculatorTest.php',
            'test_divide_guards_against_zero',
            'DivisionByZeroError thrown',
        );

        $target = $this->discoverTarget($campaign, $repo);

        $signals = is_array($target->signals) ? $target->signals : (array) json_decode((string) $target->signals, true);
        $this->assertArrayNotHasKey('failure_test_path', $signals, 'stamp OFF => no failure handle on signals');

        $outcome = $this->invokeEnqueue($campaign, $target);
        $this->assertNotSame('enqueued', $outcome, 'stamp OFF => bug-repro lane never enqueues from a handle');

        $bugTasks = AtlasLoopTask::query()
            ->where('campaign_id', $campaign->id)
            ->where('payload->objective_kind', AtlasLoopBugReproductionLane::SHAPE)
            ->count();
        $this->assertSame(0, $bugTasks, 'stamp OFF starves the bug_fix lane (no bug_fix task)');
    }
}
