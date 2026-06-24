<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopMainHealthCommand;
use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopRegressionWatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

final class AtlasLoopRegressionWatcherWindowTest extends TestCase
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

        AtlasLoopTask::query()->delete();
        AtlasLoopProposal::query()->delete();
        AtlasLoopCampaign::query()->delete();
    }

    private function campaign(string $goal = 'regression-window'): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => $goal,
            'config' => [],
            'max_seconds' => 60,
        ]);
    }

    private function mergedProposal(AtlasLoopCampaign $campaign, string $hash, array $changedFiles, string $mergeSha, string $reviewedAt): AtlasLoopProposal
    {
        $proposal = AtlasLoopProposal::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'objective' => 'merged proposal '.$hash,
            'target_path' => $changedFiles[0] ?? 'app/Fallback.php',
            'diff_text' => 'diff-'.$hash,
            'proposal_hash' => $hash,
            'metric' => null,
            'quality' => [
                '_merge_sha' => $mergeSha,
                '_changed_files' => $changedFiles,
            ],
        ]);

        DB::table('atlas_loop_proposals')->where('id', $proposal->id)->update([
            'merged_to_main' => true,
            'reviewed_at' => $reviewedAt,
            'updated_at' => $reviewedAt,
        ]);

        return $proposal->fresh();
    }

    /** @param  array<string,mixed>  $result */
    private function invokeRepair(array $result, int $window = 10): array
    {
        $command = new AtlasLoopMainHealthCommand;

        return (new ReflectionMethod($command, 'maybeEnqueueRepair'))
            ->invoke($command, app(AtlasLoopRegressionWatcher::class), $result, $window);
    }

    public function test_window_attribution_chooses_the_overlapping_proposal_not_the_latest_merge(): void
    {
        config(['atlas.loop.regression_sentinel_enabled' => true]);
        $campaign = $this->campaign();

        $latest = $this->mergedProposal($campaign, 'latest', ['app/Latest.php'], 'sha-latest', '2026-06-24T01:00:00Z');
        $culprit = $this->mergedProposal($campaign, 'culprit', ['app/Culprit.php'], 'sha-culprit', '2026-06-24T00:00:00Z');
        $older = $this->mergedProposal($campaign, 'older', ['app/Older.php'], 'sha-older', '2026-06-23T23:00:00Z');

        $result = $this->invokeRepair([
            'status' => 'reverted',
            'reverted_sha' => 'sha-reverted',
            'changed_files' => ['app/Culprit.php'],
            'reason' => 'green isolated, RED in combination',
        ], 3);

        $this->assertSame(1, $result['attributed']);
        $this->assertSame(1, $result['enqueued']);
        $task = AtlasLoopTask::query()->where('campaign_id', $campaign->id)->where('source', 'regression_sentinel')->first();
        $this->assertNotNull($task);
        $this->assertSame(['app/Culprit.php'], $task->payload['target_files'] ?? []);
        $this->assertStringContainsString(substr((string) data_get($culprit->quality, '_merge_sha'), 0, 12), $task->objective);
        $this->assertStringNotContainsString(substr((string) data_get($latest->quality, '_merge_sha'), 0, 12), $task->objective);
        $this->assertNotSame($latest->id, $culprit->id);
        $this->assertNotSame($older->id, $culprit->id);
    }

    public function test_no_overlap_in_the_window_remains_unattributed(): void
    {
        config(['atlas.loop.regression_sentinel_enabled' => true]);
        $campaign = $this->campaign('regression-window-unattributed');
        $this->mergedProposal($campaign, 'one', ['app/One.php'], 'sha-one', '2026-06-24T01:00:00Z');
        $this->mergedProposal($campaign, 'two', ['app/Two.php'], 'sha-two', '2026-06-24T00:00:00Z');

        $result = app(AtlasLoopRegressionWatcher::class)->enqueueRepairsForWindow(
            (string) $campaign->id,
            [['id' => 'Failure::test_x', 'related_files' => ['app/Three.php']]],
            2,
        );

        $this->assertSame(0, $result['attributed']);
        $this->assertSame(0, $result['enqueued']);
        $this->assertSame(1, $result['unattributed']);
    }

    public function test_window_size_excludes_older_overlapping_proposals(): void
    {
        config(['atlas.loop.regression_sentinel_enabled' => true]);
        $campaign = $this->campaign('regression-window-size');
        $this->mergedProposal($campaign, 'latest', ['app/Latest.php'], 'sha-latest', '2026-06-24T03:00:00Z');
        $outside = $this->mergedProposal($campaign, 'outside', ['app/Outside.php'], 'sha-outside', '2026-06-24T02:00:00Z');

        $result = app(AtlasLoopRegressionWatcher::class)->enqueueRepairsForWindow(
            (string) $campaign->id,
            [['id' => 'Failure::test_window', 'related_files' => ['app/Outside.php']]],
            1,
        );

        $this->assertSame(0, $result['attributed']);
        $this->assertSame(0, $result['enqueued']);
        $this->assertSame(1, $result['unattributed']);
        $this->assertNotNull($outside);
    }

    public function test_missing_proposals_table_fail_closes_without_fabricated_attribution(): void
    {
        config(['atlas.loop.regression_sentinel_enabled' => true]);
        Schema::drop('atlas_loop_proposals');

        $result = app(AtlasLoopRegressionWatcher::class)->enqueueRepairsForWindow('campaign-x', [
            ['id' => 'Failure::missing_table', 'related_files' => ['app/Foo.php']],
        ]);

        $this->assertSame(['attributed' => 0, 'enqueued' => 0, 'unattributed' => 1], $result);
    }
}
