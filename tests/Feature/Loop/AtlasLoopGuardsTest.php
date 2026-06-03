<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopProposal;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopResourceGate;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use App\Services\Ai\AutonomousEvolution\TimeBoundedLoopExecutionDriver;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The adversarial guards in isolation — the things that keep a 24h unattended run from
 * wedging, filling the disk, double-processing, or ever recording a merge.
 */
final class AtlasLoopGuardsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach (['2026_06_02_000100_create_atlas_loop_runtime_tables.php', '2026_06_02_000200_complete_atlas_loop_runtime_schema.php'] as $f) {
                (require base_path('database/migrations/'.$f))->up();
            }
        }
    }

    public function test_time_bounded_driver_kills_a_hung_attempt(): void
    {
        $hung = new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                sleep(2); // a wedged provider call (overruns the 1s deadline)

                return ['status' => 'completed'];
            }
        };

        $start = microtime(true);
        $result = (new TimeBoundedLoopExecutionDriver($hung, 1))->attempt('s', sys_get_temp_dir(), 'i', [], []);
        $elapsed = microtime(true) - $start;

        $this->assertSame('timed_out', $result['status']);
        // With pcntl it is interrupted near the deadline; without, the honest post-hoc
        // path still marks it timed_out. Either way it must NOT silently "succeed".
        $this->assertTrue($result['timed_out'] ?? false);
    }

    public function test_time_bounded_driver_passes_through_a_fast_attempt(): void
    {
        $fast = new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                return ['status' => 'completed', 'ok' => true];
            }
        };

        $result = (new TimeBoundedLoopExecutionDriver($fast, 30))->attempt('s', sys_get_temp_dir(), 'i', [], []);

        $this->assertSame('completed', $result['status']);
        $this->assertArrayNotHasKey('timed_out', $result);
    }

    public function test_resource_gate_refuses_below_disk_floor_and_reaps_orphans(): void
    {
        $gate = new AtlasLoopResourceGate;
        $root = sys_get_temp_dir().'/atlas-loop-gate-'.bin2hex(random_bytes(4));
        @mkdir($root, 0o755, true);
        @mkdir($root.'/atlas-loop-scn-old', 0o755, true);
        @mkdir($root.'/atlas-loop-scn-new', 0o755, true);
        @touch($root.'/atlas-loop-scn-old', time() - 7200); // 2h old

        try {
            // An impossibly high free-MB floor must refuse (backpressure, not crash).
            $refuse = $gate->admitScenario($root, 1_000_000_000, 0);
            $this->assertFalse($refuse['admit']);
            $this->assertSame('disk_floor', $refuse['reason']);

            // A reasonable floor admits.
            $this->assertTrue($gate->admitScenario($root, 0, 0)['admit']);

            // The workspace cap refuses above the live count.
            $this->assertFalse($gate->admitScenario($root, 0, 1)['admit']);

            // Reap orphans older than 1h: the 2h-old dir goes, the fresh one stays.
            $reaped = $gate->sweepOrphans($root, 3600);
            $this->assertSame(1, $reaped);
            $this->assertDirectoryDoesNotExist($root.'/atlas-loop-scn-old');
            $this->assertDirectoryExists($root.'/atlas-loop-scn-new');
        } finally {
            (new Process(['rm', '-rf', $root]))->run();
        }
    }

    public function test_store_atomic_claim_never_double_issues_and_reclaims_expired_lease(): void
    {
        $store = $this->app->make(AtlasLoopStore::class);
        $campaign = $store->openCampaign('claim test', sys_get_temp_dir(), ['max_seconds' => 0]);

        $a = $store->enqueueTask($campaign->id, 'a', ['target_relative_path' => 'src/A.php', 'target_content' => 'x', 'acceptance' => ['commands' => ['true']]], AtlasLoopTask::SOURCE_SEED, 'src/A.php');
        $b = $store->enqueueTask($campaign->id, 'b', ['target_relative_path' => 'src/B.php', 'target_content' => 'x', 'acceptance' => ['commands' => ['true']]], AtlasLoopTask::SOURCE_SEED, 'src/B.php');
        $this->assertNotNull($a);
        $this->assertNotNull($b);

        $first = $store->claimNextTask($campaign->id, 'w1', 600);
        $second = $store->claimNextTask($campaign->id, 'w2', 600);
        $third = $store->claimNextTask($campaign->id, 'w3', 600);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first->id, $second->id);   // never the same task twice
        $this->assertNull($third);                        // queue drained — no phantom claim

        // Expire one lease -> it is reclaimable again (crash recovery).
        AtlasLoopTask::query()->whereKey($first->id)->update(['lease_expires_at' => now()->subMinutes(5)]);
        $this->assertSame(1, $store->reclaimExpiredTasks($campaign->id));
        $reclaimed = $store->claimNextTask($campaign->id, 'w4', 600);
        $this->assertNotNull($reclaimed);
        $this->assertSame($first->id, $reclaimed->id);
    }

    public function test_store_complete_by_non_owner_is_rejected(): void
    {
        $store = $this->app->make(AtlasLoopStore::class);
        $campaign = $store->openCampaign('complete test', sys_get_temp_dir(), ['max_seconds' => 0]);
        $store->enqueueTask($campaign->id, 'a', ['target_relative_path' => 'src/A.php', 'target_content' => 'x', 'acceptance' => ['commands' => ['true']]], AtlasLoopTask::SOURCE_SEED, 'src/A.php');

        $task = $store->claimNextTask($campaign->id, 'owner', 600);
        $this->assertNotNull($task);
        $this->assertFalse($store->completeTask($task->id, 'someone-else', [], true)); // stale worker cannot write
        $this->assertTrue($store->completeTask($task->id, 'owner', ['ok' => true], true));
    }

    public function test_proposal_eloquent_guard_forces_never_merged(): void
    {
        $store = $this->app->make(AtlasLoopStore::class);
        $campaign = $store->openCampaign('merge guard', sys_get_temp_dir(), ['max_seconds' => 0]);

        // Even when explicitly told to merge, the persistence boundary refuses.
        $proposal = AtlasLoopProposal::query()->create([
            'campaign_id' => $campaign->id,
            'objective' => 'x',
            'diff_text' => 'd',
            'proposal_hash' => 'h-guard',
            'status' => 'merged',
            'merged_to_main' => true,
        ]);

        $fresh = $proposal->fresh();
        $this->assertFalse((bool) $fresh->merged_to_main);
        $this->assertSame(AtlasLoopProposal::STATUS_CERTIFIED, $fresh->status);
    }
}
