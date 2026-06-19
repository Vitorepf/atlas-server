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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * B0 — REAL WORK SUPPLY, proven as ONE LIVE REFILL PATH (not three isolated classes). This is the
 * mission's acceptance bar: a phpunit-json REAL failure flows
 *
 *   phpunit-json real_failure  ->  atlas_loop_failure_handles (harvested inside refill())
 *                              ->  discovery FAILURE-HANDLE STAMP (failure_test_path on signals)
 *                              ->  AtlasLoopQueueRefiller bug-fix lane
 *                              ->  AtlasLoopTask payload objective_kind=bug_fix + red_required + revert_recheck
 *
 * The harvest runs at the FRONT of the SAME refill() the supervisor calls — it is NOT a separate
 * manual command and NOT a pre-seeded handle. With harvest OFF, or the stamp OFF, no bug_fix is
 * enqueued (both are load-bearing). Uses the loop's focused-migration setUp (no RefreshDatabase:
 * the full suite carries a Postgres-only extension).
 */
final class AtlasLoopFailureHandleLiveSupplyTest extends TestCase
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

    /** A temp repo holding one admissible, self-contained target file discovery will pick up. */
    private function repo(): string
    {
        $d = sys_get_temp_dir().'/atlas-fhlive-'.bin2hex(random_bytes(5));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d.'/app/Services');
        File::put($d.'/app/Services/Calculator.php', $this->calculatorSource());

        return $d;
    }

    private function calculatorSource(): string
    {
        // Pure-logic, framework-free (framework_reach==0), 40<=LOC<=400, lints clean, declares a class
        // => admissible to the plain-`php` discovery grind, so discovery scores + upserts it.
        $body = "<?php\n\nnamespace App\\Services;\n\nfinal class Calculator\n{\n";
        for ($i = 0; $i < 12; $i++) {
            $body .= "    public function op{$i}(int \$a, int \$b): int\n    {\n        return \$a + \$b + {$i};\n    }\n\n";
        }
        $body .= "    public function divide(int \$a, int \$b): int\n    {\n        return intdiv(\$a, \$b);\n    }\n}\n";

        return $body;
    }

    /**
     * A phpunit JSON report with ONE deterministic real_failure red whose test path CONVENTION-maps
     * to app/Services/Calculator.php (tests/Feature/Services/CalculatorTest.php -> app/Services/Calculator.php).
     * No explicit target_path: this proves the harvester's conservative path resolution end-to-end.
     */
    private function reportPath(): string
    {
        $d = sys_get_temp_dir().'/atlas-fhlive-rep-'.bin2hex(random_bytes(5));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d);
        $path = $d.'/report.json';
        File::put($path, (string) json_encode([
            'tests' => [
                [
                    'name' => 'test_divide_guards_against_zero',
                    'status' => 'failed',
                    'message' => 'Failed asserting that 0 matches expected 1.',
                    'test_path' => 'tests/Feature/Services/CalculatorTest.php',
                    'failing_assertion' => 'test_divide_guards_against_zero',
                ],
            ],
        ]));

        return $path;
    }

    /** Arm the live-supply config: harvest ON + report path + stamp ON + bug lane ON + scoped roots. */
    private function armLiveSupply(string $reportPath, bool $harvest = true, bool $stamp = true): void
    {
        config([
            'atlas.loop.failure_handle_harvest.enabled' => $harvest,
            'atlas.loop.failure_handle_harvest.report_path' => $reportPath,
            'atlas.loop.discovery_failure_handle_stamp_enabled' => $stamp,
            'atlas.loop.bug_reproduction_lane_enabled' => true,
            // refill() does NOT pass roots, so it uses this config default; scope it to the temp repo.
            'atlas.loop.campaign.discovery_roots' => ['app/Services'],
        ]);
    }

    private function refiller(): AtlasLoopQueueRefiller
    {
        // No-op generator: if the bug-fix lane fails to intercept, the cascade falls to a generator
        // that produces NO real work (quarantine/defer) — so a green bug_fix assertion can ONLY come
        // from the wired lane, never from the provider path. The harvester arg is LEFT NULL so it
        // self-resolves via app() inside refill() (the real lazy-resolution path being shipped).
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
            'schema_version' => 'atlas.loop.campaign.v1', 'status' => 'running', 'goal' => 'fhlive',
            'base_workspace' => $repo, 'provider' => '', 'config' => ['decision_priority_enabled' => false],
        ]);
    }

    public function test_live_refill_harvests_stamps_and_enqueues_a_red_required_bug_fix(): void
    {
        $repo = $this->repo();
        $report = $this->reportPath();
        $this->armLiveSupply($report);
        $campaign = $this->campaign($repo);

        // THE LIVE PATH: one call to the SAME refill() the supervisor drives.
        $result = $this->refiller()->refill($campaign, 4);

        $heartbeatPath = storage_path('atlas-loop/campaign/'.$campaign->id.'/heartbeat');
        $this->assertFileExists($heartbeatPath, 'live refill writes the heartbeat file consumed by campaign:status');
        $this->assertGreaterThan(0, (int) trim((string) file_get_contents($heartbeatPath)));

        // (1) the harvester ran INSIDE refill and colhe exactly 1 handle from the real_failure red.
        $this->assertSame('ok', $result['failure_handle_harvest']['status'] ?? null);
        $this->assertSame(1, $result['failure_handle_harvest']['harvested'] ?? null, 'exactly 1 handle harvested in-refill');
        $rows = DB::table(AtlasLoopFailureHandleSource::TABLE)->pluck('path')->all();
        $this->assertSame(['app/Services/Calculator.php'], $rows, 'the deterministic RED became a durable handle');

        // (2) discovery STAMPED the handle test path onto the target's signals.
        $target = AtlasLoopTarget::query()
            ->where('campaign_id', $campaign->id)
            ->where('target_path', 'app/Services/Calculator.php')
            ->first();
        $this->assertNotNull($target, 'discovery upserted the Calculator target');
        $signals = is_array($target->signals) ? $target->signals : (array) json_decode((string) $target->signals, true);
        $this->assertSame(
            'tests/Feature/Services/CalculatorTest.php',
            $signals['failure_test_path'] ?? null,
            'discovery stamped the harvested handle onto the target signals',
        );

        // (3) refill enqueued EXACTLY ONE bug_fix task with the lane's RED-required acceptance.
        $this->assertSame(1, (int) $result['enqueued'], 'the stamped handle drove exactly one enqueue');
        $tasks = AtlasLoopTask::query()->where('campaign_id', $campaign->id)->get();
        $this->assertCount(1, $tasks, 'exactly ONE task is enqueued');
        $task = $tasks->first();
        $payload = is_array($task->payload) ? $task->payload : (array) json_decode((string) $task->payload, true);

        $this->assertSame('bug_fix', $payload['objective_kind'] ?? null);
        $this->assertTrue($payload['revert_recheck'] ?? false, 'a bug-fix is behavior-changing (revert_recheck on)');
        $this->assertTrue($payload['acceptance']['red_required'] ?? false, 'the acceptance RED bar is required');
        $this->assertSame(
            ['./vendor/bin/phpunit tests/Feature/Services/CalculatorTest.php'],
            $payload['acceptance']['commands'] ?? null,
            'the RED command points at the test path from the report',
        );
    }

    public function test_harvest_off_starves_the_bug_fix_lane(): void
    {
        $repo = $this->repo();
        $report = $this->reportPath();
        $this->armLiveSupply($report, harvest: false); // harvest OFF => nothing harvested
        $campaign = $this->campaign($repo);

        $result = $this->refiller()->refill($campaign, 4);

        $this->assertSame('disabled', $result['failure_handle_harvest']['status'] ?? null, 'harvest OFF => disabled no-op');
        $this->assertSame(0, DB::table(AtlasLoopFailureHandleSource::TABLE)->count(), 'no handle written');

        $bugTasks = AtlasLoopTask::query()
            ->where('campaign_id', $campaign->id)
            ->where('payload->objective_kind', AtlasLoopBugReproductionLane::SHAPE)
            ->count();
        $this->assertSame(0, $bugTasks, 'harvest OFF => the bug_fix lane stays starved');
    }

    public function test_stamp_off_harvests_but_does_not_enqueue(): void
    {
        // LOAD-BEARING: even with a handle harvested, the STAMP is what feeds the lane. With it OFF
        // the handle exists but never reaches signals => no bug_fix task.
        $repo = $this->repo();
        $report = $this->reportPath();
        $this->armLiveSupply($report, stamp: false);
        $campaign = $this->campaign($repo);

        $result = $this->refiller()->refill($campaign, 4);

        $this->assertSame(1, $result['failure_handle_harvest']['harvested'] ?? null, 'the handle WAS harvested');
        $this->assertSame(1, DB::table(AtlasLoopFailureHandleSource::TABLE)->count(), 'the handle is durable');

        $target = AtlasLoopTarget::query()
            ->where('campaign_id', $campaign->id)
            ->where('target_path', 'app/Services/Calculator.php')
            ->first();
        $this->assertNotNull($target);
        $signals = is_array($target->signals) ? $target->signals : (array) json_decode((string) $target->signals, true);
        $this->assertArrayNotHasKey('failure_test_path', $signals, 'stamp OFF => no failure handle on signals');

        $bugTasks = AtlasLoopTask::query()
            ->where('campaign_id', $campaign->id)
            ->where('payload->objective_kind', AtlasLoopBugReproductionLane::SHAPE)
            ->count();
        $this->assertSame(0, $bugTasks, 'stamp OFF => harvested handle never becomes a bug_fix task');
    }
}
