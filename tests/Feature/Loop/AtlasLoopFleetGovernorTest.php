<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopFleetGovernor;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * §4 · FLEET GOVERNOR — a GLOBAL in-flight grind cap across all campaigns. cap<=0 ⇒ unlimited (byte-identical);
 * otherwise a campaign may only spawn up to (cap − fleet-already-running), and 0 once the fleet is at/over cap.
 */
final class AtlasLoopFleetGovernorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_tasks')) {
            foreach (['2026_06_02_000100_create_atlas_loop_runtime_tables.php', '2026_06_02_000200_complete_atlas_loop_runtime_schema.php'] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    private function runningGrinds(int $n): void
    {
        $c = AtlasLoopCampaign::create(['schema_version' => 'atlas.loop.campaign.v1', 'status' => 'running', 'goal' => 'fleet', 'config' => [], 'max_seconds' => 60]);
        for ($i = 0; $i < $n; $i++) {
            AtlasLoopTask::create([
                'campaign_id' => $c->id,
                'schema_version' => 'atlas.loop.task.v1',
                'status' => 'running',
                'source' => 'fleet_test',
                'dedupe_key' => 'fleet-'.$c->id.'-'.$i,
                'objective' => 'grind '.$i,
                'target_path' => 'x'.$i.'.php',
                'payload' => [],
                'priority' => 1,
            ]);
        }
    }

    public function test_cap_zero_is_unlimited_byte_identical(): void
    {
        $this->runningGrinds(50);
        $v = (new AtlasLoopFleetGovernor)->admit(4, 0);
        $this->assertSame(4, $v['admitted'], 'cap<=0 ⇒ the governor never throttles');
        $this->assertFalse($v['throttled']);
    }

    public function test_admits_only_up_to_the_global_cap_minus_fleet(): void
    {
        $this->runningGrinds(8);
        $v = (new AtlasLoopFleetGovernor)->admit(4, 10); // cap 10, 8 already in flight ⇒ only 2 left
        $this->assertSame(8, $v['fleet_in_flight']);
        $this->assertSame(2, $v['admitted']);
        $this->assertTrue($v['throttled']);
    }

    public function test_admits_zero_when_fleet_is_at_or_over_cap(): void
    {
        $this->runningGrinds(12);
        $v = (new AtlasLoopFleetGovernor)->admit(4, 10); // fleet 12 > cap 10 ⇒ 0 new
        $this->assertSame(0, $v['admitted']);
        $this->assertTrue($v['throttled']);
    }

    public function test_admits_full_request_when_fleet_is_under_cap(): void
    {
        $this->runningGrinds(1);
        $v = (new AtlasLoopFleetGovernor)->admit(3, 10);
        $this->assertSame(3, $v['admitted'], 'plenty of fleet headroom ⇒ full request');
        $this->assertFalse($v['throttled']);
    }
}
