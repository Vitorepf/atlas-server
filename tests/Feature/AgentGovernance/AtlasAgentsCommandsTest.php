<?php

declare(strict_types=1);

namespace Tests\Feature\AgentGovernance;

use App\Services\Ai\AgentGovernance\AtlasAgentDesiredStateStore;
use App\Services\Ai\AgentGovernance\AtlasFleetCatalog;
use App\Services\Ai\AgentGovernance\AtlasFleetMasterSwitch;
use App\Services\Ai\AgentGovernance\FleetDriver;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AgentGovernance\FakeFleetDriver;
use Tests\TestCase;

/**
 * The operator's run-on-request surface, end to end: atlas:loop:on/off and atlas:agents:on/off/status/reconcile
 * drive the desired-state + hard gates, and the babá converges via the (bound fake) driver — no real process.
 */
final class AtlasAgentsCommandsTest extends TestCase
{
    private string $loopEnv;
    private string $fleetEnv;
    private FakeFleetDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_agent_desired_state')) {
            (require base_path('database/migrations/2026_06_22_000100_create_atlas_agent_governance_tables.php'))->up();
        }
        $this->loopEnv = sys_get_temp_dir().'/cmd-loop-'.bin2hex(random_bytes(5)).'.env';
        $this->fleetEnv = sys_get_temp_dir().'/cmd-fleet-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->loopEnv, "ATLAS_LOOP_MASTER_ENABLED=false\n");
        file_put_contents($this->fleetEnv, "ATLAS_FLEET_ENABLED=false\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->loopEnv;
        AtlasFleetMasterSwitch::$envPathOverride = $this->fleetEnv;

        $this->driver = new FakeFleetDriver();
        $this->app->instance(FleetDriver::class, $this->driver);
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        AtlasFleetMasterSwitch::$envPathOverride = null;
        @unlink($this->loopEnv);
        @unlink($this->fleetEnv);
        parent::tearDown();
    }

    private function store(): AtlasAgentDesiredStateStore
    {
        return new AtlasAgentDesiredStateStore();
    }

    public function test_loop_on_arms_master_and_desired_state(): void
    {
        Artisan::call('atlas:loop:on', ['--json' => true]);
        $this->assertTrue(AtlasLoopMasterSwitch::enabled(), 'loop:on flips the §0 master switch');
        $this->assertTrue($this->store()->desired(AtlasFleetCatalog::LOOP), 'and records desired-state ON');
    }

    public function test_loop_off_clears_master_and_desired_state(): void
    {
        Artisan::call('atlas:loop:on', ['--json' => true]);
        Artisan::call('atlas:loop:off', ['--json' => true]);
        $this->assertFalse(AtlasLoopMasterSwitch::enabled());
        $this->assertFalse($this->store()->desired(AtlasFleetCatalog::LOOP));
    }

    public function test_agents_on_sets_desired_and_fleet_gate(): void
    {
        Artisan::call('atlas:agents:on', ['key' => AtlasFleetCatalog::FINANCE_STRATEGY_LOOP, '--ttl' => 3600, '--json' => true]);
        $this->assertTrue(AtlasFleetMasterSwitch::enabled(), 'a non-loop on arms the fleet master');
        $rec = $this->store()->record(AtlasFleetCatalog::FINANCE_STRATEGY_LOOP);
        $this->assertNotNull($rec);
        $this->assertTrue($rec->on);
        $this->assertNotNull($rec->ttlExpiresAtEpoch, 'the FREIO was recorded');
    }

    public function test_agents_on_rejects_unknown_key(): void
    {
        $code = Artisan::call('atlas:agents:on', ['key' => 'not.a.real.agent', '--json' => true]);
        $this->assertSame(1, $code, 'unknown agent is a clean failure, not a silent on');
    }

    public function test_agents_status_json_lists_whole_fleet(): void
    {
        $this->driver->alive[AtlasFleetCatalog::LOOP] = true;
        $out = json_decode(($this->runJson('atlas:agents:status')), true);
        $this->assertCount(count(AtlasFleetCatalog::keys()), $out['agents']);
        $this->assertSame(1, $out['active_count']);
    }

    public function test_agents_off_all_is_the_panic_kill(): void
    {
        Artisan::call('atlas:agents:on', ['key' => AtlasFleetCatalog::FINANCE_STRATEGY_LOOP, '--json' => true]);
        Artisan::call('atlas:loop:on', ['--json' => true]);

        Artisan::call('atlas:agents:off', ['--all' => true, '--json' => true]);

        $this->assertFalse(AtlasFleetMasterSwitch::enabled(), 'panic kills the fleet master');
        $this->assertFalse(AtlasLoopMasterSwitch::enabled(), 'and the loop master');
        $this->assertFalse($this->store()->desired(AtlasFleetCatalog::FINANCE_STRATEGY_LOOP));
        $this->assertFalse($this->store()->desired(AtlasFleetCatalog::LOOP));
    }

    public function test_reconcile_stops_an_unsanctioned_alive_agent(): void
    {
        $this->driver->alive[AtlasFleetCatalog::FINANCE_STRATEGY_LOOP] = true; // alive but never desired

        Artisan::call('atlas:agents:reconcile', ['--json' => true]);

        $this->assertContains(AtlasFleetCatalog::FINANCE_STRATEGY_LOOP, $this->driver->stopCalls, 'the babá stops what the operator never sanctioned');
    }

    private function runJson(string $command): string
    {
        Artisan::call($command, ['--json' => true]);

        return trim(Artisan::output());
    }
}
