<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignSupervisor;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Proves the 24h supervisor's MECHANISM cycles + recovers — without a 24h wait. The
 * provider is faked (deterministic) and the clock/sleeper/storage are injected, so the
 * full loop (claim -> grind -> stream-persist -> loop-back, under budget + lock + crash
 * recovery) is exercised end-to-end, fast and repeatably.
 */
final class AtlasLoopCampaignSupervisorTest extends TestCase
{
    private string $storageRoot;

    private string $emptyRepo;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach (['2026_06_02_000100_create_atlas_loop_runtime_tables.php', '2026_06_02_000200_complete_atlas_loop_runtime_schema.php'] as $f) {
                (require base_path('database/migrations/'.$f))->up();
            }
        }
        $this->storageRoot = sys_get_temp_dir().'/atlas-loop-test-storage-'.bin2hex(random_bytes(4));
        // An empty repo root so the refiller's discovery is a fast no-op (no provider call):
        // this test drives the loop over directly-seeded tasks.
        $this->emptyRepo = sys_get_temp_dir().'/atlas-loop-empty-repo-'.bin2hex(random_bytes(4));
        @mkdir($this->emptyRepo, 0o755, true);

        // Deterministic provider: turn the seeded 'broken' into 'fixed' in every src file,
        // so each distinct seeded class yields a distinct winning diff (distinct proposal).
        $this->app->bind(LoopExecutionDriver::class, fn () => new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                foreach (glob($workspace.'/src/*.php') ?: [] as $file) {
                    file_put_contents($file, str_replace("'broken'", "'fixed'", (string) file_get_contents($file)));
                }

                return ['status' => 'completed'];
            }
        });
    }

    protected function tearDown(): void
    {
        foreach ([$this->storageRoot, $this->emptyRepo] as $dir) {
            (new Process(['rm', '-rf', $dir]))->run();
        }
        parent::tearDown();
    }

    /** A monotonic injected clock: each call advances time, so budget accrual is deterministic. */
    private function supervisor(): AtlasLoopCampaignSupervisor
    {
        $s = $this->app->make(AtlasLoopCampaignSupervisor::class);
        $s->setStorageRootForTesting($this->storageRoot);
        $t = 1000;
        $s->setClockForTesting(function () use (&$t): int { $now = $t; $t += 50; return $now; });
        $s->setSleeperForTesting(fn (int $secs): null => null);

        return $s;
    }

    private function seedCampaign(int $maxSeconds = 0): AtlasLoopCampaign
    {
        return $this->app->make(AtlasLoopStore::class)->openCampaign('prove supervisor', $this->emptyRepo, ['max_seconds' => $maxSeconds], ['scenarios_per_task' => 1]);
    }

    private function seedTask(string $campaignId, string $class): AtlasLoopTask
    {
        $rel = 'src/'.$class.'.php';

        return AtlasLoopTask::create([
            'campaign_id' => $campaignId,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_PENDING,
            'source' => AtlasLoopTask::SOURCE_SEED,
            'self_contained' => true,
            'target_path' => $rel,
            'objective' => "Make {$class}::v() return fixed. Edit {$rel} directly.",
            'payload' => [
                'target_relative_path' => $rel,
                'target_content' => "<?php\nnamespace S;\nfinal class {$class}{ public function v(): string { return 'broken'; } }\n",
                'frozen_tests' => [[
                    'path' => 'tests/'.$class.'_test.php',
                    'content' => "<?php\nrequire __DIR__.'/../{$rel}';\n\$s=new \\S\\{$class}();\nif (\$s->v() !== 'fixed') { fwrite(STDERR,'red'); exit(1); }\necho 'ok';\n",
                ]],
                'acceptance' => ['commands' => ['php tests/'.$class.'_test.php'], 'allowed_globs' => ['src/**'], 'frozen_globs' => ['tests/**', 'composer.json'], 'metric_kind' => 'gate'],
                'allowed_files' => [$rel],
                'validation_commands' => ['php tests/'.$class.'_test.php'],
            ],
            'priority' => 100,
            'attempts' => 0,
            'max_attempts' => 2,
            'dedupe_key' => 'dk-'.$class,
        ]);
    }

    public function test_full_cycle_grinds_distinct_tasks_persists_proposals_and_never_merges(): void
    {
        $campaign = $this->seedCampaign();
        $this->seedTask($campaign->id, 'Alpha');
        $this->seedTask($campaign->id, 'Bravo');

        $result = $this->supervisor()->run(['campaign_id' => $campaign->id, 'scenarios' => 1]);

        $this->assertSame('queue_starved_no_refill', $result['stop_reason']);
        $this->assertFalse($result['merged_to_main']);
        $this->assertGreaterThanOrEqual(2, $result['cycles']);

        $proposals = AtlasLoopProposal::query()->where('campaign_id', $campaign->id)->get();
        $this->assertCount(2, $proposals); // two distinct targets -> two distinct certified proposals
        foreach ($proposals as $p) {
            $this->assertFalse((bool) $p->merged_to_main);
            $this->assertSame(AtlasLoopProposal::STATUS_CERTIFIED, $p->status);
            $this->assertNotEmpty($p->diff_text);
        }

        $campaign->refresh();
        $this->assertSame(2, $campaign->tasks_processed);
        $this->assertSame(2, $campaign->proposals_count);
        $this->assertSame(AtlasLoopCampaign::STATUS_COMPLETED, $campaign->status);
        $this->assertSame(0, AtlasLoopTask::query()->where('campaign_id', $campaign->id)->where('status', AtlasLoopTask::STATUS_PENDING)->count());
    }

    public function test_stops_on_wall_clock_budget_with_work_remaining(): void
    {
        $campaign = $this->seedCampaign(maxSeconds: 10); // tiny budget; the monotonic clock crosses it fast
        $this->seedTask($campaign->id, 'Alpha');
        $this->seedTask($campaign->id, 'Bravo');
        $this->seedTask($campaign->id, 'Charlie');

        $result = $this->supervisor()->run(['campaign_id' => $campaign->id, 'scenarios' => 1]);

        $this->assertSame('time_budget_reached', $result['stop_reason']);
        $this->assertFalse($result['merged_to_main']);
        // Budget stopped it before the queue drained — real fixed-budget discipline.
        $this->assertGreaterThan(0, AtlasLoopTask::query()->where('campaign_id', $campaign->id)->where('status', AtlasLoopTask::STATUS_PENDING)->count());
    }

    public function test_crash_resume_reclaims_inflight_task_and_does_not_duplicate_proposal(): void
    {
        $campaign = $this->seedCampaign();
        // Simulate a crash: a task left 'claimed' by a dead worker with an EXPIRED lease.
        $task = $this->seedTask($campaign->id, 'Alpha');
        $task->forceFill([
            'status' => AtlasLoopTask::STATUS_CLAIMED,
            'claimed_by' => 'dead-worker',
            'claimed_at' => Carbon::now()->subMinutes(30),
            'lease_expires_at' => Carbon::now()->subMinutes(20), // expired
            'attempts' => 1,
        ])->save();

        $result = $this->supervisor()->run(['campaign_id' => $campaign->id, 'scenarios' => 1]);

        // The crashed task was reclaimed (rebuildInFlight), re-ground, and persisted ONCE.
        $task->refresh();
        $this->assertSame(AtlasLoopTask::STATUS_DONE, $task->status);
        $this->assertCount(1, AtlasLoopProposal::query()->where('campaign_id', $campaign->id)->get());
        $this->assertFalse($result['merged_to_main']);
    }
}
