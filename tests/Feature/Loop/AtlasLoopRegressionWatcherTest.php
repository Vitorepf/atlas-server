<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopRegressionWatcher;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * L6 — the post-merge ANTI-REGRESSION NET, proven deterministically (no live suite run; fake RED set). A
 * newly-RED check attributed to the loop's OWN recent merge (by file overlap) ENQUEUES a fix-forward repair
 * task, so a long run never silently knocks down a lower rung. External breakage (no overlap) is NEVER
 * auto-repaired (no self-blame). Flag-OFF enqueues nothing (byte-identical). Re-triaging dedupes.
 */
final class AtlasLoopRegressionWatcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_tasks')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    private function campaign(): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'regression watcher',
            'config' => [],
            'max_seconds' => 60,
        ]);
    }

    public function test_attributed_regression_enqueues_a_fix_forward_repair(): void
    {
        config(['atlas.loop.regression_sentinel_enabled' => true]);
        $campaign = $this->campaign();

        $result = app(AtlasLoopRegressionWatcher::class)->enqueueRepairs(
            (string) $campaign->id,
            [['id' => 'AtlasFooTest::test_bar', 'related_files' => ['app/Services/Ai/AutonomousEvolution/Foo.php']]],
            [['commit' => 'abc123def456', 'files' => ['app/Services/Ai/AutonomousEvolution/Foo.php'], 'merged_at' => '2026-06-22T10:00:00Z', 'proposal_id' => 'p1']],
        );

        $this->assertSame(1, $result['attributed']);
        $this->assertSame(1, $result['enqueued']);
        $task = AtlasLoopTask::where('campaign_id', $campaign->id)->where('source', 'regression_sentinel')->first();
        $this->assertNotNull($task, 'a fix-forward repair task is enqueued');
        $this->assertStringContainsString('REPAIR regression', $task->objective);
        $this->assertSame(AtlasLoopTask::STATUS_PENDING, $task->status, 'the repair is immediately claimable');
        $this->assertSame(50, (int) $task->priority, 'a regression repair preempts new work');
    }

    public function test_unattributed_failure_is_never_auto_repaired(): void
    {
        config(['atlas.loop.regression_sentinel_enabled' => true]);
        $campaign = $this->campaign();

        $result = app(AtlasLoopRegressionWatcher::class)->enqueueRepairs(
            (string) $campaign->id,
            [['id' => 'ExternalTest::test_x', 'related_files' => ['vendor/some/external.php']]],
            [['commit' => 'abc', 'files' => ['app/Services/Ai/AutonomousEvolution/Loop.php'], 'merged_at' => '2026-06-22T10:00:00Z']],
        );

        $this->assertSame(0, $result['enqueued'], 'no file overlap => external/pre-existing breakage => never self-blame');
        $this->assertSame(1, $result['unattributed']);
        $this->assertSame(0, AtlasLoopTask::where('campaign_id', $campaign->id)->count());
    }

    public function test_flag_off_enqueues_nothing_byte_identical(): void
    {
        config(['atlas.loop.regression_sentinel_enabled' => false]);
        $campaign = $this->campaign();

        $result = app(AtlasLoopRegressionWatcher::class)->enqueueRepairs(
            (string) $campaign->id,
            [['id' => 'X', 'related_files' => ['app/Foo.php']]],
            [['commit' => 'abc', 'files' => ['app/Foo.php']]],
        );

        $this->assertSame(0, $result['enqueued'], 'flag OFF => byte-identical (no repair tasks)');
        $this->assertSame(0, AtlasLoopTask::where('campaign_id', $campaign->id)->count());
    }

    public function test_re_triaging_the_same_regression_dedupes(): void
    {
        config(['atlas.loop.regression_sentinel_enabled' => true]);
        $campaign = $this->campaign();
        $failures = [['id' => 'AtlasFooTest::test_bar', 'related_files' => ['app/Foo.php']]];
        $merges = [['commit' => 'abc123', 'files' => ['app/Foo.php'], 'merged_at' => '2026-06-22T10:00:00Z']];

        $watcher = app(AtlasLoopRegressionWatcher::class);
        $watcher->enqueueRepairs((string) $campaign->id, $failures, $merges);
        $watcher->enqueueRepairs((string) $campaign->id, $failures, $merges); // same regression next window

        $this->assertSame(1, AtlasLoopTask::where('campaign_id', $campaign->id)->where('source', 'regression_sentinel')->count(), 're-triaging the same regression never floods the queue');
    }
}
