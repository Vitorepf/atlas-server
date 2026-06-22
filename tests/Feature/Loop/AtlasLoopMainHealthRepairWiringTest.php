<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopMainHealthCommand;
use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopRegressionWatcher;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * L6 (post-merge wiring) — the main-health command turns a reverted regression into a fix-forward repair. Pins
 * the glue (verify() result => failure/merge => watcher.enqueueRepairs) with the REAL watcher and NO git / NO
 * sentinel run: a reverted_sha + a running campaign mints exactly one repair; no reverted_sha / flag-OFF is a
 * no-op. The sentinel's own suite-run + revert stay live (untestable here); this proves the net is wired.
 */
final class AtlasLoopMainHealthRepairWiringTest extends TestCase
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

    private function runningCampaign(): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'main health repair wiring',
            'config' => [],
            'max_seconds' => 60,
        ]);
    }

    /** @param  array<string,mixed>  $result */
    private function invokeRepair(array $result): array
    {
        $command = new AtlasLoopMainHealthCommand;

        return (new ReflectionMethod($command, 'maybeEnqueueRepair'))
            ->invoke($command, app(AtlasLoopRegressionWatcher::class), $result);
    }

    public function test_a_reverted_regression_enqueues_one_fix_forward_repair(): void
    {
        config(['atlas.loop.regression_sentinel_enabled' => true]);
        $campaign = $this->runningCampaign();

        $r = $this->invokeRepair([
            'status' => 'reverted',
            'reverted_sha' => 'abc123def456789',
            'changed_files' => ['app/Services/Ai/AutonomousEvolution/Foo.php'],
            'reason' => 'green isolated, RED in combination',
        ]);

        $this->assertSame(1, $r['enqueued'], 'a reverted regression becomes a fix-forward repair');
        $this->assertSame(1, AtlasLoopTask::where('campaign_id', $campaign->id)->where('source', 'regression_sentinel')->count());
    }

    public function test_no_reverted_sha_is_a_noop(): void
    {
        config(['atlas.loop.regression_sentinel_enabled' => true]);
        $campaign = $this->runningCampaign();

        $r = $this->invokeRepair(['status' => 'healthy', 'reverted_sha' => null, 'changed_files' => []]);

        $this->assertSame(0, $r['enqueued'], 'a healthy main has no rung to repair');
        $this->assertSame(0, AtlasLoopTask::where('campaign_id', $campaign->id)->count());
    }

    public function test_flag_off_is_a_noop_byte_identical(): void
    {
        config(['atlas.loop.regression_sentinel_enabled' => false]);
        $campaign = $this->runningCampaign();

        $r = $this->invokeRepair([
            'status' => 'reverted',
            'reverted_sha' => 'abc123def456789',
            'changed_files' => ['app/Services/Ai/AutonomousEvolution/Foo.php'],
        ]);

        $this->assertSame(0, $r['enqueued'], 'flag OFF => the watcher is a no-op => byte-identical');
        $this->assertSame(0, AtlasLoopTask::where('campaign_id', $campaign->id)->count());
    }
}
