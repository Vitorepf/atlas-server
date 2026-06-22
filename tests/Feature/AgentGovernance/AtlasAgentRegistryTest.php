<?php

declare(strict_types=1);

namespace Tests\Feature\AgentGovernance;

use App\Services\Ai\AgentGovernance\AtlasAgentDesiredStateStore;
use App\Services\Ai\AgentGovernance\AtlasAgentEventLedger;
use App\Services\Ai\AgentGovernance\AtlasAgentRegistry;
use App\Services\Ai\AgentGovernance\AtlasFleetCatalog;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AgentGovernance\FakeFleetDriver;
use Tests\TestCase;

/**
 * The registry is the truthful window the apps render: every fleet agent, what the operator declared, what is
 * actually running, and which account each spends.
 */
final class AtlasAgentRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_agent_desired_state')) {
            (require base_path('database/migrations/2026_06_22_000100_create_atlas_agent_governance_tables.php'))->up();
        }
    }

    private function store(): AtlasAgentDesiredStateStore
    {
        return new AtlasAgentDesiredStateStore(new AtlasAgentEventLedger());
    }

    public function test_snapshot_lists_whole_fleet_with_account_and_status(): void
    {
        $driver = new FakeFleetDriver();
        $driver->alive[AtlasFleetCatalog::LOOP] = true;
        $this->store()->setOn(AtlasFleetCatalog::LOOP, targetRef: 'camp-A');

        $snap = (new AtlasAgentRegistry($this->store(), $driver))->snapshot();

        $this->assertSame('atlas.agents.status.v1', $snap['schema_version']);
        $this->assertCount(count(AtlasFleetCatalog::keys()), $snap['agents'], 'every fleet agent is listed');
        $this->assertSame(1, $snap['active_count']);

        $loop = collect($snap['agents'])->firstWhere('key', AtlasFleetCatalog::LOOP);
        $this->assertSame('running', $loop['status']);
        $this->assertTrue($loop['desired']);
        $this->assertTrue($loop['alive']);
        $this->assertNotSame('', $loop['account'], 'the spending account is shown');
        $this->assertContains($loop['account'], $snap['spending_accounts']);

        $finance = collect($snap['agents'])->firstWhere('key', AtlasFleetCatalog::FINANCE_STRATEGY_LOOP);
        $this->assertSame('off', $finance['status']);
        $this->assertFalse($finance['desired']);
    }

    public function test_desired_on_but_dead_reads_as_desired_dead(): void
    {
        $this->store()->setOn(AtlasFleetCatalog::LOOP, targetRef: 'camp-A');
        $driver = new FakeFleetDriver(); // not alive

        $snap = (new AtlasAgentRegistry($this->store(), $driver))->snapshot();
        $loop = collect($snap['agents'])->firstWhere('key', AtlasFleetCatalog::LOOP);
        $this->assertSame('desired_dead', $loop['status']);
    }

    public function test_active_filters_to_running_only(): void
    {
        $driver = new FakeFleetDriver();
        $driver->alive[AtlasFleetCatalog::MAC_AGENT] = true;

        $active = (new AtlasAgentRegistry($this->store(), $driver))->active();

        $this->assertSame(1, $active['active_count']);
        $this->assertCount(1, $active['agents']);
        $this->assertSame(AtlasFleetCatalog::MAC_AGENT, $active['agents'][0]['key']);
    }
}
