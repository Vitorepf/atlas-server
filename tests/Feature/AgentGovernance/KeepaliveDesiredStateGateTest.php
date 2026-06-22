<?php

declare(strict_types=1);

namespace Tests\Feature\AgentGovernance;

use App\Console\Commands\AtlasLoopKeepaliveCommand;
use App\Services\Ai\AgentGovernance\AtlasAgentDesiredStateStore;
use App\Services\Ai\AgentGovernance\AtlasAgentEventLedger;
use App\Services\Ai\AgentGovernance\AtlasFleetCatalog;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * PÉTREO ANTI-REGRESSION — the bug must never come back.
 *
 * Before this fix, the keepalive respawned ANY `status=running` row with a stale heartbeat, so the instant
 * the operator turned the loop on, every orphan campaign in the graveyard came back and burned provider
 * quota. These tests arm the master switch ON (the respawner is fully armed) AND seed orphan running rows,
 * then prove: with no matching DESIRED-STATE, the keepalive respawns NOTHING; only the exact campaign the
 * operator launched (target_ref) is respawned; and the FREIO (TTL) stops even that.
 *
 * Hermetic: a probe subclass overrides supervisorAlive()/respawn()/killSupervisor() so NO real process is
 * ever launched — the gate is proven, not exercised against live providers.
 */
final class KeepaliveDesiredStateGateTest extends TestCase
{
    private string $masterEnv;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
            '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
        ] as $file) {
            if (! Schema::hasTable('atlas_loop_campaigns') || ($file !== '2026_06_02_000100_create_atlas_loop_runtime_tables.php' && ! Schema::hasColumn('atlas_loop_campaigns', 'kill_switch'))) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        if (! Schema::hasTable('atlas_agent_desired_state')) {
            (require base_path('database/migrations/2026_06_22_000100_create_atlas_agent_governance_tables.php'))->up();
        }

        // Arm the §0 master switch ON — the respawner is fully enabled (operator turned the loop on).
        $this->masterEnv = sys_get_temp_dir().'/keepalive-master-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->masterEnv, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->masterEnv;

        config()->set('atlas.loop.morning_digest.keepalive_event_log_enabled', false); // keep the test hermetic
        config()->set('atlas.loop.campaign.restart_on_code_drift', false);              // irrelevant (all dead), keep noise out
        Carbon::setTestNow();
        DB::table('atlas_loop_campaigns')->delete();
        DB::table('atlas_agent_desired_state')->delete();
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        @unlink($this->masterEnv);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedRunningCampaign(string $id, int $heartbeatAgoMinutes = 30): void
    {
        $when = now()->subMinutes($heartbeatAgoMinutes);
        DB::table('atlas_loop_campaigns')->insert([
            'id' => $id,
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => 'running',
            'goal' => 'abandoned orphan',
            'max_seconds' => 0,        // unbounded soak — the case that was wrongly resurrected forever
            'elapsed_seconds' => 0,
            'kill_switch' => false,
            'config' => '{}',
            'heartbeat_at' => $when,   // stale
            'created_at' => $when,
            'updated_at' => $when,     // recent enough to NOT be reaped (so normally it WOULD respawn)
        ]);
    }

    /** @return array{probe:object,out:array<string,mixed>} */
    private function runKeepalive(): array
    {
        $probe = new class(null, new AtlasAgentDesiredStateStore(new AtlasAgentEventLedger())) extends AtlasLoopKeepaliveCommand {
            /** @var list<string> */
            public array $respawnedIds = [];

            protected function supervisorAlive(string $campaignId): bool
            {
                return false; // every seeded campaign is DEAD (no live process)
            }

            protected function respawn(string $campaignId): void
            {
                $this->respawnedIds[] = $campaignId; // record instead of shelling out a real campaign
            }

            protected function killSupervisor(string $campaignId): void {}
        };
        $probe->setLaravel($this->app);
        $output = new BufferedOutput();
        $probe->run(new ArrayInput(['--stale-minutes' => 2, '--json' => true]), $output);
        $out = json_decode(trim($output->fetch()), true) ?: [];

        return ['probe' => $probe, 'out' => $out];
    }

    public function test_orphan_running_rows_are_never_respawned_with_no_desired_state(): void
    {
        $this->seedRunningCampaign('orphan-A');
        $this->seedRunningCampaign('orphan-B');
        $this->seedRunningCampaign('orphan-C');
        // NO desired-state row at all (loop is OFF in the control plane).

        ['probe' => $probe, 'out' => $out] = $this->runKeepalive();

        // Master is ON (the respawner is fully armed) — proven by the keepalive actually scanning the rows.
        $this->assertGreaterThanOrEqual(3, $out['checked'] ?? 0, 'master ON: the keepalive really did scan the running rows');
        $this->assertSame([], $probe->respawnedIds, 'orphan rows must NEVER be respawned without an explicit desired-state');
        $this->assertSame([], $out['respawned'] ?? ['x'], 'the report shows zero respawns');
        $this->assertCount(3, $out['skipped_unauthorized'] ?? [], 'all three orphans are logged as unauthorized no-ops');
    }

    public function test_only_the_operator_launched_campaign_is_respawned(): void
    {
        $this->seedRunningCampaign('camp-A');
        $this->seedRunningCampaign('camp-B'); // the one the operator launched
        $this->seedRunningCampaign('camp-C');

        (new AtlasAgentDesiredStateStore(new AtlasAgentEventLedger()))
            ->setOn(AtlasFleetCatalog::LOOP, by: 'operator', targetRef: 'camp-B');

        ['probe' => $probe] = $this->runKeepalive();

        $this->assertSame(['camp-B'], $probe->respawnedIds, 'ONLY the explicitly launched campaign is kept alive');
    }

    public function test_ttl_expired_desired_state_respawns_nothing(): void
    {
        Carbon::setTestNow('2026-06-22 12:00:00');
        $this->seedRunningCampaign('camp-A');
        (new AtlasAgentDesiredStateStore(new AtlasAgentEventLedger()))
            ->setOn(AtlasFleetCatalog::LOOP, by: 'operator', ttlSeconds: 1_800, targetRef: 'camp-A');

        Carbon::setTestNow('2026-06-22 13:00:00'); // 30min past the 30min TTL
        ['probe' => $probe] = $this->runKeepalive();

        $this->assertSame([], $probe->respawnedIds, 'the FREIO (TTL) stops respawning even the launched campaign');
    }
}
