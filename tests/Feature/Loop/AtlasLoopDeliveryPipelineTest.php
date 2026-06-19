<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopDeliveryPipeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 2 · Slice 8 — the async projection substrate. Proves the claim/lease the sever depends on:
 * idempotent dispatch, a leased projection blocks a second claimer (no double-run), resume-priority by
 * accrued_ev, expired-lease reclaim, and countOpenProjections (the supervisor's anti-starvation signal).
 */
final class AtlasLoopDeliveryPipelineTest extends TestCase
{
    private AtlasLoopDeliveryPipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_pipeline_state')) {
            (require base_path('database/migrations/2026_06_18_000100_create_atlas_loop_pipeline_state.php'))->up();
        }
        $this->pipeline = new AtlasLoopDeliveryPipeline();
    }

    public function test_dispatch_is_idempotent_on_objective_id(): void
    {
        $this->assertTrue($this->pipeline->dispatchProjection('camp-1', 'obj-1', 2.0));
        $this->assertFalse($this->pipeline->dispatchProjection('camp-1', 'obj-1', 9.0), 're-dispatch never creates a 2nd row');
        $this->assertSame(1, DB::table('atlas_loop_pipeline_state')->where('objective_id', 'obj-1')->count());
    }

    public function test_a_leased_projection_blocks_a_second_claimer(): void
    {
        $this->pipeline->dispatchProjection('camp-1', 'obj-1', 1.0);

        $first = $this->pipeline->claimNextProjection('camp-1', 'worker-A', 300);
        $this->assertNotNull($first);
        $this->assertSame('obj-1', $first['objective_id']);
        $this->assertSame('worker-A', $first['claim_owner']);

        // While leased, a different worker gets nothing — the projection never double-runs.
        $this->assertNull($this->pipeline->claimNextProjection('camp-1', 'worker-B', 300));
    }

    public function test_higher_accrued_ev_resumes_first(): void
    {
        $this->pipeline->dispatchProjection('camp-1', 'low', 1.0);
        $this->pipeline->dispatchProjection('camp-1', 'high', 5.0);

        $claim = $this->pipeline->claimNextProjection('camp-1', 'worker-A', 300);
        $this->assertSame('high', $claim['objective_id'], 'a checkpointed/high-EV projection resumes ahead of fresh ones');
    }

    public function test_an_expired_lease_is_reclaimable(): void
    {
        $this->pipeline->dispatchProjection('camp-1', 'obj-1', 1.0);
        $this->pipeline->claimNextProjection('camp-1', 'dead-worker', 300);
        // Simulate the worker dying: its lease lapses.
        DB::table('atlas_loop_pipeline_state')->where('objective_id', 'obj-1')->update(['lease_expires_at' => Carbon::now()->subMinutes(10)]);

        $this->assertSame(1, $this->pipeline->reclaimExpired('camp-1'));
        $reclaimed = $this->pipeline->claimNextProjection('camp-1', 'fresh-worker', 300);
        $this->assertSame('obj-1', $reclaimed['objective_id']);
        $this->assertSame('fresh-worker', $reclaimed['claim_owner']);
    }

    public function test_countOpenProjections_counts_in_flight_and_drops_on_park_or_complete(): void
    {
        $this->pipeline->dispatchProjection('camp-1', 'obj-1', 1.0);
        $this->pipeline->dispatchProjection('camp-1', 'obj-2', 2.0);
        $this->pipeline->claimNextProjection('camp-1', 'worker-A', 300);

        // BOTH (claimed + pending) are OPEN work — the supervisor must not declare starvation.
        $this->assertSame(2, $this->pipeline->countOpenProjections('camp-1'));

        $this->pipeline->park('obj-1', 'oscillation');
        $this->assertSame(1, $this->pipeline->countOpenProjections('camp-1'), 'a parked projection is no longer open');

        $this->pipeline->complete('obj-2');
        $this->assertSame(0, $this->pipeline->countOpenProjections('camp-1'));
    }

    public function test_checkpoint_persists_progress_without_releasing_the_claim(): void
    {
        $this->pipeline->dispatchProjection('camp-1', 'obj-1', 1.0);
        $this->pipeline->claimNextProjection('camp-1', 'worker-A', 300);

        $this->pipeline->checkpoint('obj-1', ['round' => 3], [['key' => 'mutation_killed|app\\foo::bar|mutop:x']]);

        // The held lease blocks a re-claim (a valid lease blocks ALL claimers); the owner resumes via find().
        $this->assertNull($this->pipeline->claimNextProjection('camp-1', 'worker-A', 300), 'a valid lease is not re-claimable');
        $row = $this->pipeline->find('obj-1');
        $this->assertSame(3, $row['checkpoint']['round']);
        $this->assertSame('mutation_killed|app\\foo::bar|mutop:x', $row['obligation_set'][0]['key']);
        $this->assertSame('worker-A', $row['claim_owner'], 'checkpoint never released the lease');
    }
}
