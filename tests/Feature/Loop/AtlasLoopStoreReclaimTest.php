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
}
