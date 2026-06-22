<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * §4 · ORPHAN-CAMPAIGN REAPER — the preflight that gives a soak a clean start: a dead `running` row whose
 * heartbeat is older than the tight grace is reaped (stopped), so arming the loop never resurrects a
 * graveyard. A recently-active row (fresh heartbeat) is SPARED — the keepalive resumes a genuine crash.
 */
final class AtlasLoopReapOrphansCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach (['2026_06_02_000100_create_atlas_loop_runtime_tables.php', '2026_06_02_000200_complete_atlas_loop_runtime_schema.php'] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    private function runningCampaign(int $heartbeatMinutesAgo): AtlasLoopCampaign
    {
        $c = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => 'running',
            'goal' => 'reaper test',
            'config' => [],
            'max_seconds' => 3600,
        ]);
        $c->forceFill(['heartbeat_at' => now()->subMinutes($heartbeatMinutesAgo)])->save();

        return $c->fresh();
    }

    public function test_reaps_a_dead_orphan_with_stale_heartbeat(): void
    {
        $c = $this->runningCampaign(heartbeatMinutesAgo: 60); // no process (test) + heartbeat older than grace
        $code = Artisan::call('atlas:loop:reap-orphans', ['--grace-minutes' => 30, '--json' => true]);
        $this->assertSame(0, $code);

        $fresh = $c->fresh();
        $this->assertSame('stopped', $fresh->status, 'an abandoned orphan is reaped');
        $this->assertSame('reaped_orphan_preflight', $fresh->stop_reason);
        $this->assertTrue((bool) $fresh->kill_switch, 'kill_switch set so the keepalive never resurrects it');
    }

    public function test_spares_a_recently_active_running_campaign(): void
    {
        $c = $this->runningCampaign(heartbeatMinutesAgo: 2); // fresh heartbeat ⇒ within grace
        Artisan::call('atlas:loop:reap-orphans', ['--grace-minutes' => 30, '--json' => true]);

        $this->assertSame('running', $c->fresh()->status, 'a recently-active campaign is spared (keepalive may resume it)');
    }
}
