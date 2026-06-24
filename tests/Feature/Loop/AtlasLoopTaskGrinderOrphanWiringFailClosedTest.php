<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTaskGrinder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AtlasLoopTaskGrinderOrphanWiringFailClosedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }

        AtlasLoopTask::query()->delete();
        AtlasLoopCampaign::query()->delete();

        config([
            'atlas.loop.orphan_wiring_execution_enabled' => true,
        ]);
    }

    public function test_orphan_wiring_fails_closed_when_base_workspace_is_unavailable(): void
    {
        $campaign = AtlasLoopCampaign::query()->create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'orphan-wiring',
            'base_workspace' => '/definitely/missing/workspace',
            'config' => [],
            'max_seconds' => 60,
        ]);

        $workerId = 'worker-orphan-wiring-1';
        $task = AtlasLoopTask::query()->create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_RUNNING,
            'source' => AtlasLoopTask::SOURCE_DISCOVERY,
            'self_contained' => true,
            'target_path' => 'app/Services/MissingConsumer.php',
            'objective' => 'wire orphan consumer',
            'payload' => [
                'objective_kind' => 'orphan_wiring',
                'orphan_path' => 'app/Services/MissingConsumer.php',
                'allowed_files' => [
                    'app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php',
                    'tests/Feature/Loop/AtlasLoopTaskGrinderOrphanWiringFailClosedTest.php',
                ],
            ],
            'priority' => 100,
            'attempts' => 1,
            'max_attempts' => 2,
            'dedupe_key' => (string) Str::uuid(),
            'claimed_by' => $workerId,
            'claimed_at' => Carbon::now(),
            'lease_expires_at' => Carbon::now()->addMinutes(5),
        ]);

        $out = app(AtlasLoopTaskGrinder::class)->grind($task, $workerId, 1);

        $this->assertSame('no_winner', $out['status']);
        $this->assertSame('orphan_wiring_base_workspace_unavailable', $out['reason']);
        $this->assertSame(0, $out['proposals']);
        $this->assertFalse($out['has_winner']);

        $task->refresh();

        $this->assertSame(AtlasLoopTask::STATUS_FAILED, $task->status);
        $this->assertSame('no_winner', $task->result['status']);
        $this->assertSame('orphan_wiring_base_workspace_unavailable', $task->result['reason']);
        $this->assertNull($task->lease_expires_at);
    }
}
