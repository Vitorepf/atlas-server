<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopKeepaliveCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F4: the death-respawn watchdog covers UNBOUNDED soaks (max_seconds=0 = "no wall-clock cap"),
 * which were wrongly excluded by `elapsed_seconds < max_seconds` (100 < 0 = false) and so were
 * never respawned. It must also REAP ancient orphan `running` rows (dead process + heartbeat
 * older than the reap window) instead of resurrecting them — the recency bound is what lets the
 * filter include unbounded soaks without waking yesterday's test zombies.
 */
final class AtlasLoopKeepaliveReapTest extends TestCase
{
    use ArmsAtlasLoopMaster;

    protected function setUp(): void
    {
        parent::setUp();
        // §0 master switch defaults OFF (fail-closed) — arm ON to exercise the keepalive's active reap path.
        $this->armLoopMasterOn();
        $this->beforeApplicationDestroyed(fn () => $this->disarmLoopMaster());
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        // Don't let the revive-starved pass interfere; these are running-row tests.
        config(['atlas.loop.keepalive_revive_starved' => false, 'atlas.loop.keepalive_reap_after_minutes' => 1440]);
    }

    private function seedRunning(array $over): string
    {
        $id = (string) Str::uuid();
        DB::table('atlas_loop_campaigns')->insert(array_merge([
            'id' => $id,
            'schema_version' => 'atlas.loop.campaign.v1',
            'goal' => 'reap-test',
            'status' => 'running',
            'stop_reason' => null,
            'max_seconds' => 0,
            'elapsed_seconds' => 100,
            'kill_switch' => false,
            'config' => '{}',
            'heartbeat_at' => now()->subMinutes(15),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subMinutes(15),
        ], $over));

        return $id;
    }

    /** Keepalive with respawn/alive stubbed (shells nothing; process is never "alive"). */
    private function runKeepalive(): array
    {
        $cmd = new class extends AtlasLoopKeepaliveCommand
        {
            public array $respawned = [];

            protected function respawn(string $campaignId): void
            {
                $this->respawned[] = $campaignId;
            }

            protected function supervisorAlive(string $campaignId): bool
            {
                return false;
            }
        };
        $cmd->setLaravel(app());
        $captured = [];
        $cmd->run(new \Symfony\Component\Console\Input\ArrayInput(['--json' => true]), new class($captured) extends \Symfony\Component\Console\Output\Output
        {
            public function __construct(private array &$cap)
            {
                parent::__construct();
            }

            protected function doWrite(string $message, bool $newline): void
            {
                $this->cap[] = $message;
            }
        });

        return [$cmd, implode("\n", $captured)];
    }

    public function test_unbounded_dead_recent_soak_is_respawned(): void
    {
        // max_seconds=0 (unbounded), heartbeat 15min stale (> default stale-minutes=10), dead,
        // NOT past the 24h reap window => respawn (previously excluded by elapsed<max_seconds).
        $id = $this->seedRunning(['max_seconds' => 0, 'heartbeat_at' => now()->subMinutes(15)]);

        [$cmd] = $this->runKeepalive();

        $this->assertContains($id, $cmd->respawned, 'unbounded soak that died recently IS respawned now');
        $this->assertSame('running', DB::table('atlas_loop_campaigns')->where('id', $id)->value('status'), 'not reaped (within recency window)');
    }

    public function test_ancient_unbounded_orphan_is_reaped_not_respawned(): void
    {
        // Heartbeat 25h stale (> 1440min reap window) => an abandoned zombie, NOT a recent death.
        $id = $this->seedRunning(['max_seconds' => 0, 'heartbeat_at' => now()->subMinutes(1500)]);

        [$cmd] = $this->runKeepalive();

        $this->assertNotContains($id, $cmd->respawned, 'an ancient orphan is never resurrected');
        $this->assertSame('completed', DB::table('atlas_loop_campaigns')->where('id', $id)->value('status'), 'reaped to completed');
        $this->assertSame('reaped_orphan_no_process', DB::table('atlas_loop_campaigns')->where('id', $id)->value('stop_reason'));
    }

    public function test_bounded_dead_recent_campaign_still_respawns(): void
    {
        // Regression guard: the existing bounded-with-budget respawn behavior is unchanged.
        $id = $this->seedRunning(['max_seconds' => 86400, 'elapsed_seconds' => 100, 'heartbeat_at' => now()->subMinutes(15)]);

        [$cmd] = $this->runKeepalive();

        $this->assertContains($id, $cmd->respawned, 'bounded campaign with budget that died recently still respawns');
    }

    public function test_ancient_null_heartbeat_orphan_is_reaped_not_respawned_forever(): void
    {
        // A dead unbounded row that NEVER beat (heartbeat NULL) AND is OLD by row age would,
        // without the heartbeat<=0 reaper branch, respawn every cycle forever (the adversarial
        // edge). It must be reaped instead.
        $id = $this->seedRunning(['max_seconds' => 0, 'heartbeat_at' => null, 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);

        [$cmd] = $this->runKeepalive();

        $this->assertNotContains($id, $cmd->respawned, 'an ancient never-beat orphan is not respawned forever');
        $this->assertSame('completed', DB::table('atlas_loop_campaigns')->where('id', $id)->value('status'), 'reaped to completed');
        $this->assertSame('reaped_orphan_no_process', DB::table('atlas_loop_campaigns')->where('id', $id)->value('stop_reason'));
    }

    public function test_fresh_null_heartbeat_dead_row_gets_one_respawn_not_reaped(): void
    {
        // A FRESH dead row that hasn't beat yet (just started, process died) is within the recency
        // window: it should get one respawn chance, NOT be reaped.
        $id = $this->seedRunning(['max_seconds' => 0, 'heartbeat_at' => null, 'created_at' => now()->subMinutes(5), 'updated_at' => now()->subMinutes(5)]);

        [$cmd] = $this->runKeepalive();

        $this->assertContains($id, $cmd->respawned, 'a fresh never-beat dead row is respawned once');
        $this->assertSame('running', DB::table('atlas_loop_campaigns')->where('id', $id)->value('status'), 'not reaped (within recency window)');
    }
}
