<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Process-liveness reclaim: a task whose grind worker died must be freed in SECONDS (not after the 90min
 * lease) so its worker slot is not held hostage — but never at the cost of a live grind. These pin the
 * invariants: the attempt-increment is reversed (no zombie pending@max), the anti-race grace floor protects
 * a just-claimed task, only the caller-confirmed dead ids are touched, and a finished task is never
 * resurrected.
 */
final class AtlasLoopReclaimDeadWorkerTasksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // focused migration of the loop runtime tables (the full suite is Postgres-only, no RefreshDatabase).
        if (! Schema::hasTable('atlas_loop_tasks')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    private function campaign(): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::query()->create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'goal' => 'dead worker reclaim proof',
            'status' => 'running',
            'config' => [],
        ]);
    }

    private function inflightTask(string $cid, string $status, Carbon $heartbeat, int $attempts): AtlasLoopTask
    {
        $t = AtlasLoopTask::query()->create([
            'campaign_id' => $cid,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => $status,
            'source' => 'discovery',
            'self_contained' => false,
            'target_path' => 'app/Svc/X.php',
            'objective' => 'reduce complexity',
            'payload' => ['objective_kind' => 'refactor_reduce_complexity'],
            'priority' => 0,
            'attempts' => $attempts,
            'max_attempts' => 2,
            'dedupe_key' => 'dk-'.Str::uuid()->toString(),
        ]);
        $t->forceFill([
            'claimed_by' => 'pool-dead-worker-1',
            'heartbeat_at' => $heartbeat,
            'lease_expires_at' => Carbon::now()->addMinutes(80),
        ])->save();

        return $t->refresh();
    }

    public function test_reclaims_dead_worker_task_and_reverses_the_attempt_increment(): void
    {
        $store = app(AtlasLoopStore::class);
        $c = $this->campaign();
        $t = $this->inflightTask($c->id, AtlasLoopTask::STATUS_RUNNING, Carbon::now()->subMinutes(10), 1);

        $n = $store->reclaimDeadWorkerTasks($c->id, [$t->id], 180);

        $this->assertSame(1, $n);
        $t->refresh();
        $this->assertSame(AtlasLoopTask::STATUS_PENDING, $t->status);
        $this->assertSame(0, (int) $t->attempts, 'an incomplete attempt must give its increment back');
        $this->assertNull($t->claimed_by);
        $this->assertNull($t->lease_expires_at);
    }

    public function test_grace_floor_protects_a_just_claimed_task(): void
    {
        $store = app(AtlasLoopStore::class);
        $c = $this->campaign();
        $t = $this->inflightTask($c->id, AtlasLoopTask::STATUS_RUNNING, Carbon::now()->subSeconds(20), 1);

        // heartbeat only 20s old, grace 180s ⇒ inside the anti-race window ⇒ NOT reclaimed (worker may be spawning).
        $this->assertSame(0, $store->reclaimDeadWorkerTasks($c->id, [$t->id], 180));
        $this->assertSame(AtlasLoopTask::STATUS_RUNNING, $t->refresh()->status);
    }

    public function test_only_ids_in_the_dead_set_are_reclaimed(): void
    {
        $store = app(AtlasLoopStore::class);
        $c = $this->campaign();
        $t = $this->inflightTask($c->id, AtlasLoopTask::STATUS_RUNNING, Carbon::now()->subMinutes(10), 1);

        // an id the caller did NOT confirm dead (a live worker) is never touched.
        $this->assertSame(0, $store->reclaimDeadWorkerTasks($c->id, [(string) Str::uuid()], 180));
        $this->assertSame(AtlasLoopTask::STATUS_RUNNING, $t->refresh()->status);
    }

    public function test_does_not_resurrect_a_finished_task(): void
    {
        $store = app(AtlasLoopStore::class);
        $c = $this->campaign();
        $t = $this->inflightTask($c->id, AtlasLoopTask::STATUS_DONE, Carbon::now()->subMinutes(10), 1);

        $this->assertSame(0, $store->reclaimDeadWorkerTasks($c->id, [$t->id], 180));
        $this->assertSame(AtlasLoopTask::STATUS_DONE, $t->refresh()->status);
    }
}
