<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainGiveBackToQueueRepairPlanner;
use Tests\TestCase;

final class AtlasExternalBrainGiveBackToQueueRepairPlannerTest extends TestCase
{
    public function test_missing_files_produces_add_allowed_file_plan(): void
    {
        $result = (new AtlasExternalBrainGiveBackToQueueRepairPlanner)->plan([
            'give_backs' => [[
                'task_id' => 't1',
                'missing_files' => ['app/Foo.php'],
            ]],
        ]);

        $this->assertSame('add_allowed_file', $result['repair_candidates'][0]['repair_plan']);
    }

    public function test_forbidden_target_always_routes_to_operator_only_fix(): void
    {
        $result = (new AtlasExternalBrainGiveBackToQueueRepairPlanner)->plan([
            'give_backs' => [[
                'task_id' => 't1',
                'forbidden_target' => true,
                'missing_files' => ['app/Foo.php'],
            ]],
        ]);

        $this->assertSame('operator_only_fix', $result['repair_candidates'][0]['repair_plan']);
    }

    public function test_acceptance_contradiction_produces_rewrite_acceptance_plan(): void
    {
        $result = (new AtlasExternalBrainGiveBackToQueueRepairPlanner)->plan([
            'give_backs' => [['task_id' => 't1', 'acceptance_contradiction' => true]],
        ]);

        $this->assertSame('rewrite_acceptance', $result['repair_candidates'][0]['repair_plan']);
    }

    public function test_duplicate_capability_produces_cancel_duplicate_plan(): void
    {
        $result = (new AtlasExternalBrainGiveBackToQueueRepairPlanner)->plan([
            'give_backs' => [['task_id' => 't1', 'duplicate_capability' => true]],
        ]);

        $this->assertSame('cancel_duplicate', $result['repair_candidates'][0]['repair_plan']);
    }

    public function test_poison_root_cause_produces_quarantine_poison_plan(): void
    {
        $result = (new AtlasExternalBrainGiveBackToQueueRepairPlanner)->plan([
            'give_backs' => [['task_id' => 't1', 'root_cause' => 'poison_packet_detected']],
        ]);

        $this->assertSame('quarantine_poison', $result['repair_candidates'][0]['repair_plan']);
    }

    public function test_overly_broad_scope_root_cause_produces_split_task_plan(): void
    {
        $result = (new AtlasExternalBrainGiveBackToQueueRepairPlanner)->plan([
            'give_backs' => [['task_id' => 't1', 'root_cause' => 'scope_too_broad_multi_file']],
        ]);

        $this->assertSame('split_task', $result['repair_candidates'][0]['repair_plan']);
    }

    public function test_unrecognized_root_cause_falls_back_to_operator_only_fix_never_retry(): void
    {
        $result = (new AtlasExternalBrainGiveBackToQueueRepairPlanner)->plan([
            'give_backs' => [['task_id' => 't1', 'root_cause' => 'some_unknown_mystery_failure']],
        ]);

        $this->assertSame('operator_only_fix', $result['repair_candidates'][0]['repair_plan']);
        $this->assertNotSame('retry', $result['repair_candidates'][0]['repair_plan']);
    }

    public function test_no_repair_plan_value_is_ever_a_bare_retry(): void
    {
        $result = (new AtlasExternalBrainGiveBackToQueueRepairPlanner)->plan([
            'give_backs' => [
                ['task_id' => 't1', 'missing_files' => ['a']],
                ['task_id' => 't2', 'forbidden_target' => true],
                ['task_id' => 't3', 'acceptance_contradiction' => true],
                ['task_id' => 't4', 'duplicate_capability' => true],
                ['task_id' => 't5', 'root_cause' => 'poison'],
                ['task_id' => 't6', 'root_cause' => 'too_broad'],
                ['task_id' => 't7'],
            ],
        ]);

        foreach ($result['repair_candidates'] as $candidate) {
            $this->assertNotSame('retry', $candidate['repair_plan']);
            $this->assertContains($candidate['repair_plan'], [
                'add_allowed_file', 'split_task', 'rewrite_acceptance', 'cancel_duplicate', 'quarantine_poison', 'operator_only_fix',
            ]);
        }
    }

    public function test_ranking_prefers_higher_safety_then_unblock_count_then_token_savings(): void
    {
        $result = (new AtlasExternalBrainGiveBackToQueueRepairPlanner)->plan([
            'give_backs' => [
                ['task_id' => 'low-safety-high-tokens', 'forbidden_target' => true, 'token_savings' => 1000, 'unblock_count' => 0],
                ['task_id' => 'high-safety-low-tokens', 'missing_files' => ['a'], 'token_savings' => 10, 'unblock_count' => 1],
            ],
        ]);

        $ranked = $result['ranked_repair_candidates'];
        $this->assertSame('high-safety-low-tokens', $ranked[0]['task_id'], 'safety must dominate raw token savings');
    }

    public function test_within_same_safety_tier_higher_unblock_count_ranks_first(): void
    {
        $result = (new AtlasExternalBrainGiveBackToQueueRepairPlanner)->plan([
            'give_backs' => [
                ['task_id' => 'low-unblock', 'missing_files' => ['a'], 'unblock_count' => 1, 'token_savings' => 50],
                ['task_id' => 'high-unblock', 'missing_files' => ['b'], 'unblock_count' => 5, 'token_savings' => 10],
            ],
        ]);

        $ranked = $result['ranked_repair_candidates'];
        $this->assertSame('high-unblock', $ranked[0]['task_id']);
    }
}
