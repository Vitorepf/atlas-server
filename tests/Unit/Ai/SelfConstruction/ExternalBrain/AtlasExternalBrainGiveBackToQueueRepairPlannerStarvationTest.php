<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainGiveBackToQueueRepairPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainGiveBackToQueueRepairPlannerStarvationTest extends TestCase
{
    private function planner(): AtlasExternalBrainGiveBackToQueueRepairPlanner
    {
        return new AtlasExternalBrainGiveBackToQueueRepairPlanner;
    }

    public function test_no_claimable_task_give_back_returns_replenish_queue_repair_type(): void
    {
        $result = $this->planner()->plan([
            'give_backs' => [
                ['task_id' => 't1', 'reason' => 'no_claimable_task'],
            ],
        ]);

        $this->assertSame('replenish_queue', $result['repair_candidates'][0]['repair_type']);
    }

    public function test_impossible_scope_give_back_keeps_respec_packet_repair_type(): void
    {
        $result = $this->planner()->plan([
            'give_backs' => [
                ['task_id' => 't2', 'reason' => 'no_claimable_task', 'impossible_scope' => true],
            ],
        ]);

        $this->assertSame('respec_packet', $result['repair_candidates'][0]['repair_type']);
    }

    public function test_malformed_packet_give_back_keeps_respec_packet_repair_type(): void
    {
        $result = $this->planner()->plan([
            'give_backs' => [
                ['task_id' => 't3', 'missing_files' => ['app/Foo.php']],
            ],
        ]);

        $this->assertSame('respec_packet', $result['repair_candidates'][0]['repair_type']);
    }

    public function test_forbidden_target_still_takes_precedence_over_starvation_reason(): void
    {
        $result = $this->planner()->plan([
            'give_backs' => [
                ['task_id' => 't4', 'reason' => 'no_claimable_task', 'forbidden_target' => true],
            ],
        ]);

        $this->assertSame('operator_only_fix', $result['repair_candidates'][0]['repair_type']);
    }
}
