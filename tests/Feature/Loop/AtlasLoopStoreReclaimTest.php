<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pins the supervisor-startup reclaim fix: a respawned supervisor must reclaim ALL in-flight tasks
 * its dead predecessor left — even ones whose (long) lease has NOT expired. The old lease-only
 * reclaim left fresh-leased orphans "running", occupying worker slots and STALLING the fresh
 * supervisor (observed live: 3 orphans, 0 grinding, heartbeat growing).
 */
final class AtlasLoopStoreReclaimTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    private function task(string $campaignId, string $status, ?\Carbon\Carbon $lease): AtlasLoopTask
    {
        return AtlasLoopTask::create([
            'id' => (string) Str::uuid(),
            'campaign_id' => $campaignId,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => $status,
            'source' => 'test',
            'objective' => 'x',
            'payload' => json_encode([]),
            'priority' => 100,
            'attempts' => 0,
            'max_attempts' => 2,
            'dedupe_key' => hash('sha256', Str::uuid()->toString()),
            'claimed_by' => in_array($status, ['claimed', 'running'], true) ? 'dead-predecessor-worker' : null,
            'lease_expires_at' => $lease,
        ]);
    }

    public function test_reclaim_all_in_flight_reclaims_unexpired_orphans_pending_and_done_untouched(): void
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1', 'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'reclaim proof', 'config' => [], 'max_seconds' => 60,
        ]);
        // The orphans carry a FUTURE lease (claimed minutes ago with a 90min lease) — the predecessor died.
        $claimed = $this->task((string) $campaign->id, 'claimed', now()->addHour());
        $running = $this->task((string) $campaign->id, 'running', now()->addHour());
        $pending = $this->task((string) $campaign->id, 'pending', null);
        $done = $this->task((string) $campaign->id, 'done', null);

        $reclaimed = app(AtlasLoopStore::class)->reclaimAllInFlight((string) $campaign->id);

        // Both in-flight (claimed + running) reclaimed DESPITE the unexpired lease — the lease-only
        // reclaim would have reclaimed 0 here.
        $this->assertSame(2, $reclaimed);
        $this->assertSame('pending', $claimed->fresh()->status);
        $this->assertNull($claimed->fresh()->claimed_by);
        $this->assertNull($claimed->fresh()->lease_expires_at);
        $this->assertSame('pending', $running->fresh()->status);
        // Terminal/pending untouched.
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame('done', $done->fresh()->status);
    }

    public function test_rebuild_in_flight_uses_the_reclaim_all_path(): void
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1', 'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'rebuild proof', 'config' => [], 'max_seconds' => 60,
        ]);
        $this->task((string) $campaign->id, 'running', now()->addHour());

        $res = app(AtlasLoopStore::class)->rebuildInFlight((string) $campaign->id);

        $this->assertSame(1, $res['reclaimed']);
        $this->assertSame(1, $res['pending']); // the reclaimed task is now pending + claimable
    }

    /**
     * INVARIANT: claim increments attempts; a reclaim of an INCOMPLETE attempt (still claimed/running,
     * never reached completeTask) must REVERSE that increment. Otherwise repeated supervisor deaths
     * march attempts to max and the task zombies (pending @ max => never claimable => loop idles).
     * Regression for the 2h "alive but 0 grinds" production-stall: 5 tasks orphaned to attempts=2/2
     * with zero real explorations.
     */
    public function test_reclaim_all_in_flight_gives_back_the_uncompleted_attempt(): void
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1', 'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'attempt-giveback proof', 'config' => [], 'max_seconds' => 60,
        ]);
        // A task orphaned on its FINAL attempt (claimed for attempt 2 of 2, supervisor died before grind).
        $exhausted = $this->task((string) $campaign->id, 'running', now()->addHour());
        $exhausted->update(['attempts' => 2, 'max_attempts' => 2]);

        app(AtlasLoopStore::class)->reclaimAllInFlight((string) $campaign->id);

        $fresh = $exhausted->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame(1, (int) $fresh->attempts, 'the un-completed attempt is given back');
        $this->assertTrue($fresh->attempts < $fresh->max_attempts, 'task is claimable again, not a zombie');
    }

    /**
     * countOpen drives the queue_starved_no_refill stop. A zombie (pending @ attempts==max) is NOT
     * workable, so it must NOT count as "open" — else it holds the loop in produce-nothing limbo
     * (alive, refilling, 0 claimable, never stopping) instead of the clean starvation stop.
     */
    public function test_count_open_excludes_unclaimable_zombies(): void
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1', 'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'countOpen proof', 'config' => [], 'max_seconds' => 60,
        ]);
        $cid = (string) $campaign->id;
        $claimablePending = $this->task($cid, 'pending', null);          // attempts 0/2 => open
        $running = $this->task($cid, 'running', now()->addHour());        // in-flight => open
        $zombie = $this->task($cid, 'pending', null);
        $zombie->update(['attempts' => 2, 'max_attempts' => 2]);          // pending @ max => NOT open
        $this->task($cid, 'done', null);                                  // terminal => never open

        $this->assertSame(2, app(AtlasLoopStore::class)->countOpen($cid), 'open = claimable pending + in-flight, zombie excluded');
        // And the zombie is correctly invisible to the claimable-pending count too.
        $this->assertSame(1, app(AtlasLoopStore::class)->countPending($cid));
        $this->assertNotNull($claimablePending->fresh());
        $this->assertNotNull($running->fresh());
    }

    public function test_reclaim_expired_decrements_with_floor_zero(): void
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1', 'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'floor proof', 'config' => [], 'max_seconds' => 60,
        ]);
        // Lease-expired claimed task at attempts=1 => decrements to 0.
        $one = $this->task((string) $campaign->id, 'claimed', now()->subMinute());
        $one->update(['attempts' => 1]);
        // A defensively-zero attempt must FLOOR at 0, never go negative.
        $zero = $this->task((string) $campaign->id, 'running', now()->subMinute());
        $zero->update(['attempts' => 0]);

        $reclaimed = app(AtlasLoopStore::class)->reclaimExpiredTasks((string) $campaign->id);

        $this->assertSame(2, $reclaimed);
        $this->assertSame(0, (int) $one->fresh()->attempts);
        $this->assertSame(0, (int) $zero->fresh()->attempts, 'floored at zero, no underflow');
    }
}
