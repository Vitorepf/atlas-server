<?php

declare(strict_types=1);

namespace Tests\Feature\AgentGovernance;

use App\Services\Ai\AgentGovernance\AtlasAgentDesiredStateStore;
use App\Services\Ai\AgentGovernance\AtlasAgentEventLedger;
use App\Services\Ai\AgentGovernance\AtlasFleetCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The desired-state store is the single source of run/respawn authority. Proves: default OFF / fail-closed;
 * operator on/off round-trips; the FREIO (TTL + budget) auto-OFFs; and — the keystone — authorizesCampaign
 * grants respawn ONLY to the exact campaign the operator launched, so orphan rows are never authorized.
 *
 * Focused-migration setUp (no RefreshDatabase: the full suite has a Postgres-only extension).
 */
final class AtlasAgentDesiredStateStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTables();
        Carbon::setTestNow(); // reset to real now per test unless a test pins it
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ensureTables(): void
    {
        if (! Schema::hasTable('atlas_agent_desired_state')) {
            (require base_path('database/migrations/2026_06_22_000100_create_atlas_agent_governance_tables.php'))->up();
        }
    }

    private function store(): AtlasAgentDesiredStateStore
    {
        return new AtlasAgentDesiredStateStore(new AtlasAgentEventLedger());
    }

    public function test_default_is_off_no_row(): void
    {
        $s = $this->store();
        $this->assertFalse($s->desired(AtlasFleetCatalog::LOOP), 'absent row => OFF');
        $this->assertNull($s->record(AtlasFleetCatalog::LOOP));
        $this->assertFalse($s->authorizes(AtlasFleetCatalog::LOOP));
        $this->assertFalse($s->authorizesCampaign('any-campaign'));
    }

    public function test_set_on_off_round_trip(): void
    {
        $s = $this->store();
        $s->setOn(AtlasFleetCatalog::LOOP, by: 'operator', reason: 'manual run');
        $this->assertTrue($s->desired(AtlasFleetCatalog::LOOP));
        $this->assertTrue($s->authorizes(AtlasFleetCatalog::LOOP));
        $rec = $s->record(AtlasFleetCatalog::LOOP);
        $this->assertNotNull($rec);
        $this->assertSame('operator', $rec->setBy);

        $s->setOff(AtlasFleetCatalog::LOOP, by: 'operator', reason: 'done');
        $this->assertFalse($s->desired(AtlasFleetCatalog::LOOP));
        $this->assertFalse($s->authorizes(AtlasFleetCatalog::LOOP));
    }

    public function test_ttl_freio_auto_offs(): void
    {
        Carbon::setTestNow('2026-06-22 12:00:00');
        $s = $this->store();
        $s->setOn(AtlasFleetCatalog::LOOP, ttlSeconds: 3_600);
        $this->assertTrue($s->authorizes(AtlasFleetCatalog::LOOP), 'within TTL: authorized');

        Carbon::setTestNow('2026-06-22 13:00:01'); // past the 1h deadline
        $this->assertFalse($s->authorizes(AtlasFleetCatalog::LOOP), 'past TTL: auto-OFF');
    }

    public function test_budget_freio_auto_offs(): void
    {
        $s = $this->store();
        $s->setOn(AtlasFleetCatalog::LOOP, budgetUsd: 5.0);
        $this->assertTrue($s->authorizes(AtlasFleetCatalog::LOOP, spentUsd: 4.99));
        $this->assertFalse($s->authorizes(AtlasFleetCatalog::LOOP, spentUsd: 5.0), 'budget exhausted: auto-OFF');
    }

    public function test_authorizes_campaign_only_for_the_launched_target(): void
    {
        $s = $this->store();
        $s->setOn(AtlasFleetCatalog::LOOP, targetRef: 'camp-A');

        $this->assertTrue($s->authorizesCampaign('camp-A'), 'the operator-launched campaign is authorized');
        $this->assertFalse($s->authorizesCampaign('camp-B'), 'a DIFFERENT (orphan) campaign is NEVER authorized');
        $this->assertFalse($s->authorizesCampaign('camp-orphan-from-yesterday'));
    }

    public function test_loop_on_without_target_authorizes_no_campaign(): void
    {
        $s = $this->store();
        $s->setOn(AtlasFleetCatalog::LOOP); // generic ON, no target_ref
        $this->assertTrue($s->authorizes(AtlasFleetCatalog::LOOP), 'the agent itself is ON');
        $this->assertFalse($s->authorizesCampaign('camp-A'), 'no target + no launch time => no campaign respawn (fail-closed)');
    }

    public function test_generic_on_authorizes_only_campaigns_launched_after_the_floor(): void
    {
        Carbon::setTestNow('2026-06-22 12:00:00');
        $s = $this->store();
        $s->setOn(AtlasFleetCatalog::LOOP); // generic ON; set_at floor = 12:00:00

        $before = Carbon::parse('2026-06-22 11:00:00')->timestamp; // an orphan from before the operator turned it on
        $after = Carbon::parse('2026-06-22 12:05:00')->timestamp;  // a campaign launched after

        $this->assertFalse($s->authorizesCampaign('orphan', $before), 'orphans from before the ON floor are NEVER authorized');
        $this->assertTrue($s->authorizesCampaign('fresh', $after), 'a campaign launched after the operator turned it on IS authorized');
    }

    public function test_authorizes_campaign_false_when_loop_off(): void
    {
        $s = $this->store();
        $s->setOn(AtlasFleetCatalog::LOOP, targetRef: 'camp-A');
        $s->setOff(AtlasFleetCatalog::LOOP);
        $this->assertFalse($s->authorizesCampaign('camp-A'), 'loop OFF => even its own campaign is not authorized');
    }

    public function test_fail_closed_when_table_missing(): void
    {
        Schema::dropIfExists('atlas_agent_desired_state');
        $s = $this->store();
        $this->assertFalse($s->desired(AtlasFleetCatalog::LOOP), 'missing table => OFF (fail-closed)');
        $this->assertNull($s->record(AtlasFleetCatalog::LOOP));
        $this->assertFalse($s->authorizes(AtlasFleetCatalog::LOOP));
        $this->assertFalse($s->authorizesCampaign('camp-A'));
        $this->ensureTables(); // restore for subsequent tests sharing the in-memory connection
    }

    public function test_desired_flips_append_history(): void
    {
        $s = $this->store();
        $s->setOn(AtlasFleetCatalog::LOOP, reason: 'go');
        $s->setOff(AtlasFleetCatalog::LOOP, reason: 'stop');

        $events = (new AtlasAgentEventLedger())->recent(10, AtlasFleetCatalog::LOOP);
        $kinds = array_column($events, 'event');
        $this->assertContains(AtlasAgentEventLedger::EVENT_DESIRED_ON, $kinds);
        $this->assertContains(AtlasAgentEventLedger::EVENT_DESIRED_OFF, $kinds);
    }
}
