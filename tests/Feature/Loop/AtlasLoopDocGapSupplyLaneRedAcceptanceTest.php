<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopDocGapSupplyLane;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasLoopDocGapSupplyLaneRedAcceptanceTest extends TestCase
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
    }

    private function model(array $gaps): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: [],
            edges: [],
            orphans: [],
            cloneClusters: [],
            forbidden: [],
            docPurposes: [],
            docStatedGaps: $gaps,
            snapshotId: 'test',
        );
    }

    public function test_lane_mints_a_deterministic_red_acceptance_handle(): void
    {
        $spec = (new AtlasLoopDocGapSupplyLane)->mint($this->model(['MissingCapability']), base_path())[0];

        $this->assertStringStartsWith('./vendor/bin/phpunit tests/Feature/Loop/', $spec['payload']['acceptance']['commands'][0]);
        $this->assertStringEndsWith('.php', $spec['payload']['acceptance']['commands'][0]);
        $this->assertTrue((bool) $spec['payload']['acceptance']['red_required']);
        $this->assertFalse((bool) $spec['payload']['acceptance']['revert_recheck']);
        $this->assertSame('tests/Feature/Loop/MissingCapabilityTest.php', $spec['payload']['acceptance']['expected_test_path']);
    }

    public function test_empty_or_degenerate_capabilities_are_skipped(): void
    {
        $specs = (new AtlasLoopDocGapSupplyLane)->mint($this->model(['', '  ', 'not a class name']), base_path());

        $this->assertSame([], $specs);
    }

    public function test_queue_refiller_carries_acceptance_onto_the_enqueued_payload(): void
    {
        $campaign = AtlasLoopCampaign::query()->create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'doc-gap-red-acceptance',
            'config' => [],
            'max_seconds' => 60,
        ]);

        $spec = (new AtlasLoopDocGapSupplyLane)->mint($this->model(['GapHandle']), base_path())[0];
        $method = new \ReflectionMethod(AtlasLoopQueueRefiller::class, 'mintDocGapTask');
        $method->setAccessible(true);

        $ok = $method->invoke(app(AtlasLoopQueueRefiller::class), $campaign, $spec, base_path());

        $this->assertTrue($ok);
        $task = AtlasLoopTask::query()->where('campaign_id', $campaign->id)->latest('id')->first();
        $this->assertNotNull($task);
        $this->assertSame(
            ['./vendor/bin/phpunit tests/Feature/Loop/GapHandleTest.php'],
            $task->payload['acceptance']['commands'] ?? null,
        );
        $this->assertTrue((bool) ($task->payload['acceptance']['red_required'] ?? false));
    }
}
