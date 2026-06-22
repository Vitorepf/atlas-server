<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignSupervisor;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * L4 — KILL THE STALL. At supply exhaustion the supervisor must ORIGINATE the next leap instead of stopping
 * at `queue_starved_no_refill`. Proven DETERMINISTICALLY: a fake origination producer (never a provider) is
 * injected; the seam is exercised in isolation via reflection. Guarantees: flag-OFF is byte-identical (the
 * producer is never even called), flag-ON + proceed mints a CLAIMABLE task (the loop loops back to grind it),
 * and abstain parks (asks the operator) without faking a leap.
 */
final class AtlasLoopOriginationOnStarvationWiringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
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
            'goal' => 'origination-on-starvation',
            'config' => [],
            'max_seconds' => 60,
        ]);
    }

    private function originate(AtlasLoopCampaign $campaign, \Closure $producer): bool
    {
        $supervisor = app(AtlasLoopCampaignSupervisor::class);
        $supervisor->setOriginationProducerForTesting($producer);
        (new ReflectionMethod($supervisor, 'ensureStorage'))->invoke($supervisor, $campaign->id);

        return (bool) (new ReflectionMethod($supervisor, 'maybeOriginate'))->invoke($supervisor, $campaign);
    }

    public function test_flag_off_never_originates_byte_identical(): void
    {
        config(['atlas.loop.origination_on_starvation_enabled' => false]);
        $campaign = $this->campaign();
        $called = false;

        $result = $this->originate($campaign, function () use (&$called): array {
            $called = true;

            return ['produced' => true, 'action' => 'proceed', 'objective' => 'x', 'target_path' => 'app/x.php'];
        });

        $this->assertFalse($result, 'flag OFF => no origination');
        $this->assertFalse($called, 'flag OFF => the producer is never even invoked (byte-identical)');
        $this->assertSame(0, AtlasLoopTask::where('campaign_id', $campaign->id)->count());
    }

    public function test_exhaustion_originates_a_claimable_task_not_a_stall(): void
    {
        config(['atlas.loop.origination_on_starvation_enabled' => true]);
        $campaign = $this->campaign();

        $result = $this->originate($campaign, fn (string $root, string $scope, array $prior): array => [
            'produced' => true,
            'action' => 'proceed',
            'objective' => 'Wire the parked CrossTypeLeverageSelector into the refiller candidate ranking',
            'target_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopCrossTypeLeverageSelector.php',
            'obligations' => [['kind' => 'wired_proof']],
        ]);

        $this->assertTrue($result, 'exhaustion => the loop ORIGINATES the next leap (not queue_starved)');
        $task = AtlasLoopTask::where('campaign_id', $campaign->id)->where('source', 'origination')->first();
        $this->assertNotNull($task, 'a fresh task is enqueued');
        $this->assertSame('Wire the parked CrossTypeLeverageSelector into the refiller candidate ranking', $task->objective);
        $this->assertSame(AtlasLoopTask::STATUS_PENDING, $task->status, 'the originated task is immediately claimable');
        $this->assertSame('app/Services/Ai/AutonomousEvolution/AtlasLoopCrossTypeLeverageSelector.php', $task->target_path);
    }

    public function test_abstain_parks_and_asks_operator_never_fakes_a_leap(): void
    {
        config(['atlas.loop.origination_on_starvation_enabled' => true]);
        $campaign = $this->campaign();

        $result = $this->originate($campaign, fn (): array => [
            'produced' => true,
            'action' => 'abstain',
            'reason' => 'novel_no_precedent',
        ]);

        $this->assertFalse($result, 'abstain => parks + asks the operator, never fabricates a task');
        $this->assertSame(0, AtlasLoopTask::where('campaign_id', $campaign->id)->count());
    }
}
