<?php

declare(strict_types=1);

namespace Tests\Feature\AgentGovernance;

use App\Services\Ai\AgentGovernance\AtlasAgentDesiredStateStore;
use App\Services\Ai\AgentGovernance\AtlasFleetCatalog;
use App\Services\Ai\AgentGovernance\AtlasFleetMasterSwitch;
use App\Services\Ai\AgentGovernance\FleetDriver;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AgentGovernance\FakeFleetDriver;
use Tests\TestCase;

/**
 * The mobile/desktop apps' surface: GET active/status/history (read-only, never starts/stops) + POST off /
 * off-all (the DESLIGAR buttons — OFF only, no turn-ON endpoint by design).
 */
final class AtlasAgentGovernanceApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];
    private string $loopEnv;
    private string $fleetEnv;
    private FakeFleetDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.token', 'test-token-with-enough-length-123');
        if (! Schema::hasTable('atlas_agent_desired_state')) {
            (require base_path('database/migrations/2026_06_22_000100_create_atlas_agent_governance_tables.php'))->up();
        }
        $this->loopEnv = sys_get_temp_dir().'/api-loop-'.bin2hex(random_bytes(5)).'.env';
        $this->fleetEnv = sys_get_temp_dir().'/api-fleet-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->loopEnv, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        file_put_contents($this->fleetEnv, "ATLAS_FLEET_ENABLED=true\n");
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

    public function test_requires_the_atlas_token(): void
    {
        $this->getJson('/api/agents/active')->assertStatus(401);
    }

    public function test_active_returns_only_running_agents(): void
    {
        $this->driver->alive[AtlasFleetCatalog::LOOP] = true;

        $res = $this->getJson('/api/agents/active', $this->headers)->assertOk()->json();

        $this->assertSame(1, $res['active_count']);
        $this->assertCount(1, $res['agents']);
        $this->assertSame(AtlasFleetCatalog::LOOP, $res['agents'][0]['key']);
        $this->assertContains($res['agents'][0]['account'], $res['spending_accounts']);
    }

    public function test_status_lists_whole_fleet(): void
    {
        $res = $this->getJson('/api/agents/status', $this->headers)->assertOk()->json();
        $this->assertCount(count(AtlasFleetCatalog::keys()), $res['agents']);
    }

    public function test_history_returns_events(): void
    {
        (new AtlasAgentDesiredStateStore())->setOn(AtlasFleetCatalog::LOOP, reason: 'api test');

        $res = $this->getJson('/api/agents/history', $this->headers)->assertOk()->json();
        $this->assertNotEmpty($res['events']);
        $this->assertSame(AtlasFleetCatalog::LOOP, $res['events'][0]['agent_key']);
    }

    public function test_off_turns_an_agent_desired_off(): void
    {
        $store = new AtlasAgentDesiredStateStore();
        $store->setOn(AtlasFleetCatalog::FINANCE_STRATEGY_LOOP);

        $this->postJson('/api/agents/'.AtlasFleetCatalog::FINANCE_STRATEGY_LOOP.'/off', [], $this->headers)
            ->assertOk()->assertJson(['ok' => true, 'desired' => 'off']);

        $this->assertFalse($store->desired(AtlasFleetCatalog::FINANCE_STRATEGY_LOOP));
    }

    public function test_off_unknown_agent_is_404(): void
    {
        $this->postJson('/api/agents/not-a-real-agent/off', [], $this->headers)->assertStatus(404);
    }

    public function test_off_all_is_the_panic_kill(): void
    {
        $store = new AtlasAgentDesiredStateStore();
        $store->setOn(AtlasFleetCatalog::LOOP);
        $store->setOn(AtlasFleetCatalog::FINANCE_STRATEGY_LOOP);

        $this->postJson('/api/agents/off-all', [], $this->headers)
            ->assertOk()->assertJson(['ok' => true, 'fleet_master' => 'off', 'loop_master' => 'off']);

        $this->assertFalse(AtlasFleetMasterSwitch::enabled());
        $this->assertFalse(AtlasLoopMasterSwitch::enabled());
        $this->assertFalse($store->desired(AtlasFleetCatalog::LOOP));
        $this->assertFalse($store->desired(AtlasFleetCatalog::FINANCE_STRATEGY_LOOP));
    }
}
