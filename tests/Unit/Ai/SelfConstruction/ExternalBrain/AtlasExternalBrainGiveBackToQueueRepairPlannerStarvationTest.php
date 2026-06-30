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

    // ── AC1/AC2: repeated give_back + low worker floor → safe respec, ranked above diagnostics ──

    private function repeatedEvent(string $taskId, array $overrides = []): array
    {
        return array_merge([
            'task_id' => $taskId,
            'root_cause' => 'vague_acceptance_criteria',
            'allowed_files' => ['app/Services/Foo.php'],
            'acceptance_criteria' => ['Runnable proof: ./vendor/bin/phpunit tests/Unit/FooTest.php'],
        ], $overrides);
    }

    public function test_repeated_give_back_pattern_under_low_worker_floor_becomes_safe_respec(): void
    {
        $result = $this->planner()->plan([
            'give_backs' => [
                $this->repeatedEvent('t1'),
                $this->repeatedEvent('t2'),
            ],
            'claimable_per_active_worker' => 1.0,
        ]);

        foreach ($result['repair_candidates'] as $candidate) {
            $this->assertSame('respec_for_queue_feed', $candidate['repair_plan']);
            $this->assertSame('respec_packet', $candidate['repair_type']);
        }
    }

    public function test_safe_respec_ranks_above_analysis_only_action_when_worker_floor_low(): void
    {
        $result = $this->planner()->plan([
            'give_backs' => [
                ['task_id' => 'diag1', 'root_cause' => 'unexplained_failure'],
                $this->repeatedEvent('rep1'),
                $this->repeatedEvent('rep2'),
            ],
            'claimable_per_active_worker' => 1.0,
        ]);

        $rankedPlans = array_column($result['ranked_repair_candidates'], 'repair_plan');
        $respecIndex = array_search('respec_for_queue_feed', $rankedPlans, true);
        $operatorOnlyIndex = array_search('operator_only_fix', $rankedPlans, true);

        $this->assertNotFalse($respecIndex);
        $this->assertNotFalse($operatorOnlyIndex);
        $this->assertLessThan($operatorOnlyIndex, $respecIndex, 'safe respec must rank above the analysis-only diagnostic');
    }

    public function test_refuses_respec_when_allowed_files_missing_even_with_repeated_pattern_and_low_floor(): void
    {
        $result = $this->planner()->plan([
            'give_backs' => [
                $this->repeatedEvent('t1', ['allowed_files' => []]),
                $this->repeatedEvent('t2', ['allowed_files' => []]),
            ],
            'claimable_per_active_worker' => 1.0,
        ]);

        foreach ($result['repair_candidates'] as $candidate) {
            $this->assertSame('operator_only_fix', $candidate['repair_plan']);
        }
    }

    public function test_refuses_respec_when_acceptance_criteria_not_runnable(): void
    {
        $result = $this->planner()->plan([
            'give_backs' => [
                $this->repeatedEvent('t1', ['acceptance_criteria' => ['looks good']]),
                $this->repeatedEvent('t2', ['acceptance_criteria' => ['looks good']]),
            ],
            'claimable_per_active_worker' => 1.0,
        ]);

        foreach ($result['repair_candidates'] as $candidate) {
            $this->assertSame('operator_only_fix', $candidate['repair_plan']);
        }
    }

    public function test_single_occurrence_root_cause_does_not_trigger_respec_even_under_low_floor(): void
    {
        $result = $this->planner()->plan([
            'give_backs' => [
                $this->repeatedEvent('t1'),
            ],
            'claimable_per_active_worker' => 1.0,
        ]);

        $this->assertSame('operator_only_fix', $result['repair_candidates'][0]['repair_plan']);
    }

    public function test_repeated_pattern_without_low_worker_floor_does_not_trigger_respec(): void
    {
        $result = $this->planner()->plan([
            'give_backs' => [
                $this->repeatedEvent('t1'),
                $this->repeatedEvent('t2'),
            ],
            'claimable_per_active_worker' => 10.0,
        ]);

        foreach ($result['repair_candidates'] as $candidate) {
            $this->assertSame('operator_only_fix', $candidate['repair_plan']);
        }
    }

    public function test_plan_with_worker_floor_input_is_deterministic(): void
    {
        $planner = $this->planner();
        $input = [
            'give_backs' => [$this->repeatedEvent('t1'), $this->repeatedEvent('t2')],
            'claimable_per_active_worker' => 1.0,
        ];

        $this->assertSame($planner->plan($input), $planner->plan($input));
    }
}
