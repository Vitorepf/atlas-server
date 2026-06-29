<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasAaelLoopExecutionBridge;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMultiSiteWiringPlanner;
use Closure;
use Illuminate\Support\Facades\Artisan;
use stdClass;
use Tests\TestCase;

/**
 * Proves the multi-site wiring planner is live at the operator surface via `atlas:aael wiring-plan`: each
 * (primitive, consumer-site) pair is reported wired (consumer file mentions the primitive class) or intended
 * (it does not), and the pre-existing actions are untouched.
 */
final class AtlasAaelCommandWiringPlanTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-wiring-plan-'.bin2hex(random_bytes(5));
        @mkdir($this->root.'/consumers', 0o775, true);
        // The command method-injects the AAEL bridge (its `object $runner` is not container-resolvable). The
        // wiring-plan / unknown actions never call the bridge, so a stub runner is enough to let handle() resolve.
        $this->app->bind(
            AtlasAaelLoopExecutionBridge::class,
            fn (): AtlasAaelLoopExecutionBridge => new AtlasAaelLoopExecutionBridge(new stdClass()),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->root.'/consumers/Wired.php');
        @unlink($this->root.'/consumers/Unwired.php');
        @rmdir($this->root.'/consumers');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_wiring_plan_emits_per_site_wired_and_intended_records(): void
    {
        config(['atlas.loop.multi_site_wiring_planner_enabled' => true]);

        // One consumer mentions the primitive class (wired); the other does not (intended).
        file_put_contents($this->root.'/consumers/Wired.php', "<?php\nclass Wired { public function __construct(AtlasFooPrimitive \$p) {} }\n");
        file_put_contents($this->root.'/consumers/Unwired.php', "<?php\nclass Unwired { public function handle() {} }\n");

        $this->app->bind(
            AtlasLoopMultiSiteWiringPlanner::class,
            fn (): AtlasLoopMultiSiteWiringPlanner => new AtlasLoopMultiSiteWiringPlanner(
                null,
                fn (): array => [[
                    'primitive_id' => 'foo',
                    'file_path' => 'app/Services/AtlasFooPrimitive.php',
                    'intended_consumer_paths' => ['consumers/Wired.php', 'consumers/Unwired.php'],
                ]],
                $this->root,
            ),
        );

        $exit = Artisan::call('atlas:aael', ['action' => 'wiring-plan', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopMultiSiteWiringPlanner::SCHEMA, $decoded['schema_version']);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame(2, $decoded['records_count']);

        $byConsumer = [];
        foreach ($decoded['plan'] as $record) {
            foreach (['primitive_id', 'consumer_path', 'seam_anchor', 'status'] as $key) {
                $this->assertArrayHasKey($key, $record);
            }
            $byConsumer[$record['consumer_path']] = $record['status'];
        }
        $this->assertSame('wired', $byConsumer['consumers/Wired.php'], 'consumer mentioning the primitive class is wired');
        $this->assertSame('intended', $byConsumer['consumers/Unwired.php'], 'consumer not mentioning it is intended');
    }

    public function test_unknown_action_still_returns_blocked_unknown_action(): void
    {
        $exit = Artisan::call('atlas:aael', ['action' => 'bogus-action', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exit, 'a blocked unknown action is a command FAILURE');
        $this->assertSame('blocked', $decoded['status']);
        $this->assertSame('unknown_action', $decoded['reason']);
    }
}
