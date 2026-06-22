<?php

declare(strict_types=1);

namespace Tests\Feature\AgentGovernance;

use App\Services\Ai\AgentGovernance\AtlasAgentDesiredStateStore;
use App\Services\Ai\AgentGovernance\AtlasAgentEventLedger;
use App\Services\Ai\AgentGovernance\AtlasAgentReconciler;
use App\Services\Ai\AgentGovernance\AtlasFleetCatalog;
use App\Services\Ai\AgentGovernance\AtlasFleetMasterSwitch;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AgentGovernance\FakeFleetDriver;
use Tests\TestCase;

/**
 * THE BABÁ INVARIANT, proven deterministically (fake driver, no real processes):
 *   - not-desired & alive  ⇒ STOP (enforces "se não ligou, nada roda")
 *   - desired & dead       ⇒ START only when the hard gate is ON; default OFF ⇒ suppressed (fail-closed)
 *   - FREIO (TTL/budget)   ⇒ auto-OFF, then the now-unsanctioned-but-alive agent is stopped
 *   - orphan running rows  ⇒ the reconciler reads desired-state, NOT campaign rows, so they never start anything
 */
final class AtlasAgentReconcilerTest extends TestCase
{
    private string $loopEnv;
    private string $fleetEnv;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_agent_desired_state')) {
            (require base_path('database/migrations/2026_06_22_000100_create_atlas_agent_governance_tables.php'))->up();
        }
        $this->loopEnv = sys_get_temp_dir().'/recon-loop-'.bin2hex(random_bytes(5)).'.env';
        $this->fleetEnv = sys_get_temp_dir().'/recon-fleet-'.bin2hex(random_bytes(5)).'.env';
        AtlasLoopMasterSwitch::$envPathOverride = $this->loopEnv;
        AtlasFleetMasterSwitch::$envPathOverride = $this->fleetEnv;
        $this->setLoopGate(false);
        $this->setFleetGate(false);
        Carbon::setTestNow();
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        AtlasFleetMasterSwitch::$envPathOverride = null;
        @unlink($this->loopEnv);
        @unlink($this->fleetEnv);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function setLoopGate(bool $on): void
    {
        file_put_contents($this->loopEnv, 'ATLAS_LOOP_MASTER_ENABLED='.($on ? 'true' : 'false')."\n");
    }

    private function setFleetGate(bool $on): void
    {
        file_put_contents($this->fleetEnv, 'ATLAS_FLEET_ENABLED='.($on ? 'true' : 'false')."\n");
    }

    private function store(): AtlasAgentDesiredStateStore
    {
        return new AtlasAgentDesiredStateStore(new AtlasAgentEventLedger());
    }

    private function reconciler(FakeFleetDriver $driver): AtlasAgentReconciler
    {
        return new AtlasAgentReconciler($this->store(), $driver, new AtlasAgentEventLedger());
    }

    public function test_not_desired_but_alive_is_stopped(): void
    {
        $driver = new FakeFleetDriver();
        $driver->alive[AtlasFleetCatalog::LOOP] = true; // running with NO desired-state row

        $report = $this->reconciler($driver)->reconcile();

        $this->assertContains(AtlasFleetCatalog::LOOP, $driver->stopCalls, 'an unsanctioned live agent must be stopped');
        $this->assertSame([], $driver->startCalls);
        $this->assertSame(AtlasFleetCatalog::LOOP, $report['stopped'][0]['agent'] ?? null);
    }

    public function test_desired_dead_starts_only_when_hard_gate_on(): void
    {
        $this->store()->setOn(AtlasFleetCatalog::LOOP, targetRef: 'camp-A');

        // Hard gate OFF ⇒ suppressed
        $driver = new FakeFleetDriver();
        $report = $this->reconciler($driver)->reconcile();
        $this->assertSame([], $driver->startCalls, 'hard gate OFF ⇒ no start (fail-closed)');
        $this->assertTrue(collect($report['noop'])->contains(fn ($n) => ($n['reason'] ?? null) === 'start_suppressed_hard_gate_off'));

        // Hard gate ON ⇒ start, carrying the exact target
        $this->setLoopGate(true);
        $driver2 = new FakeFleetDriver();
        $this->reconciler($driver2)->reconcile();
        $this->assertSame([['key' => AtlasFleetCatalog::LOOP, 'target' => 'camp-A']], $driver2->startCalls);
    }

    public function test_desired_and_alive_is_noop(): void
    {
        $this->store()->setOn(AtlasFleetCatalog::LOOP, targetRef: 'camp-A');
        $this->setLoopGate(true);
        $driver = new FakeFleetDriver();
        $driver->alive[AtlasFleetCatalog::LOOP] = true;

        $this->reconciler($driver)->reconcile();

        $this->assertSame([], $driver->startCalls);
        $this->assertSame([], $driver->stopCalls);
    }

    public function test_off_and_dead_is_noop(): void
    {
        $driver = new FakeFleetDriver();
        $this->reconciler($driver)->reconcile();
        $this->assertSame([], $driver->startCalls);
        $this->assertSame([], $driver->stopCalls);
    }

    public function test_ttl_freio_auto_offs_and_stops_running_agent(): void
    {
        Carbon::setTestNow('2026-06-22 12:00:00');
        $this->store()->setOn(AtlasFleetCatalog::LOOP, ttlSeconds: 3_600, targetRef: 'camp-A');
        $this->setLoopGate(true);

        Carbon::setTestNow('2026-06-22 13:30:00'); // past TTL
        $driver = new FakeFleetDriver();
        $driver->alive[AtlasFleetCatalog::LOOP] = true; // still running past its budget

        $report = $this->reconciler($driver)->reconcile();

        $this->assertSame('ttl_expired', $report['auto_off'][0]['reason'] ?? null, 'FREIO auto-OFFs');
        $this->assertContains(AtlasFleetCatalog::LOOP, $driver->stopCalls, 'and the now-unsanctioned run is stopped');
        $this->assertFalse($this->store()->desired(AtlasFleetCatalog::LOOP), 'desired-state persisted OFF');
    }

    public function test_budget_freio_auto_offs(): void
    {
        $this->store()->setOn(AtlasFleetCatalog::LOOP, budgetUsd: 5.0, targetRef: 'camp-A');
        $this->setLoopGate(true);
        $driver = new FakeFleetDriver();
        $driver->spent[AtlasFleetCatalog::LOOP] = 5.0; // budget hit

        $report = $this->reconciler($driver)->reconcile();

        $this->assertSame('budget_exhausted', $report['auto_off'][0]['reason'] ?? null);
        $this->assertFalse($this->store()->desired(AtlasFleetCatalog::LOOP));
    }

    public function test_orphan_running_campaign_rows_never_cause_a_start(): void
    {
        // The exact root-cause shape: orphan status=running rows sitting in the DB.
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            (require base_path('database/migrations/2026_06_02_000100_create_atlas_loop_runtime_tables.php'))->up();
        }
        foreach (['orphan-1', 'orphan-2', 'orphan-3'] as $id) {
            DB::table('atlas_loop_campaigns')->insert([
                'id' => $id, 'schema_version' => 'atlas.loop.campaign.v1', 'status' => 'running',
                'goal' => 'abandoned', 'max_seconds' => 0, 'config' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // Loop desired OFF, hard gate even ON — the reconciler must STILL start nothing, because it converges
        // toward desired-state, never toward these rows.
        $this->setLoopGate(true);
        $driver = new FakeFleetDriver(); // loop reported dead

        $report = $this->reconciler($driver)->reconcile();

        $this->assertSame([], $driver->startCalls, 'orphan running rows are NOT a reason to start the loop');
        $this->assertNotContains(AtlasFleetCatalog::LOOP, $driver->startedKeys());
    }

    public function test_non_loop_agent_uses_the_fleet_gate(): void
    {
        $this->store()->setOn(AtlasFleetCatalog::FINANCE_STRATEGY_LOOP);

        // Fleet gate OFF ⇒ suppressed even though desired ON
        $driver = new FakeFleetDriver();
        $this->reconciler($driver)->reconcile();
        $this->assertSame([], $driver->startCalls);

        // Fleet gate ON ⇒ starts
        $this->setFleetGate(true);
        $driver2 = new FakeFleetDriver();
        $this->reconciler($driver2)->reconcile();
        $this->assertContains(AtlasFleetCatalog::FINANCE_STRATEGY_LOOP, $driver2->startedKeys());
    }
}
