<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendRepairPlannerService;
use Tests\TestCase;

class AtlasFrontendRepairPlannerServiceTest extends TestCase
{
    public function test_visual_quality_failures_compile_into_ordered_repair_plan(): void
    {
        $plan = app(AtlasFrontendRepairPlannerService::class)->plan([
            'task' => 'Repair dashboard frontend',
            'task_spec_hash' => str_repeat('a', 64),
            'blockers' => ['design_review_score_below_threshold', 'text_overlap'],
            'failed_gates' => ['visual_quality_gate'],
        ]);

        $this->assertSame(AtlasFrontendRepairPlannerService::SCHEMA_VERSION, $plan['schema_version']);
        $this->assertSame('ready', $plan['status']);
        $this->assertSame('visual_quality_loop', $plan['repair_strategy']);
        $this->assertSame('medium', $plan['severity']);
        $this->assertFalse((bool) $plan['raw_task_returned']);
        $this->assertContains('design_5d_review', $plan['rerun_gates']);
        $this->assertContains('visual_quality_gate', $plan['rerun_gates']);
        $this->assertContains('design_review_report', $plan['evidence_required']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $plan['repair_plan_hash']);
    }

    public function test_task_spec_hash_mismatch_uses_context_first_strategy(): void
    {
        $plan = app(AtlasFrontendRepairPlannerService::class)->plan([
            'blockers' => ['task_spec_hash_mismatch'],
        ]);

        $this->assertSame('ready', $plan['status']);
        $this->assertSame('context_first', $plan['repair_strategy']);
        $this->assertSame('high', $plan['severity']);
        $this->assertSame('repair_task_spec_hash', data_get($plan, 'repair_steps.0.id'));
        $this->assertContains('task_spec_hash', $plan['evidence_required']);
    }

    public function test_competitive_dimension_gaps_compile_into_replay_repair_plan(): void
    {
        $plan = app(AtlasFrontendRepairPlannerService::class)->plan([
            'dimension_gaps' => [
                [
                    'dimension' => 'product_intent_fit',
                    'points_to_match' => 2,
                    'delta_vs_best_rival' => -2,
                    'best_rival_score' => 12,
                    'case_id' => 'live_mode_repair_loop',
                    'best_rival_system' => 'pbakaus_impeccable',
                ],
            ],
        ]);

        $this->assertSame('ready', $plan['status']);
        $this->assertSame('competitive_replay_repair', $plan['repair_strategy']);
        $this->assertSame('high', $plan['severity']);
        $this->assertContains('rival_replay_inspect', $plan['rerun_gates']);
        $this->assertContains('score_breakdown', $plan['evidence_required']);
        $this->assertSame('repair_competitive_dimension_product_intent_fit', data_get($plan, 'repair_steps.1.id'));
        $this->assertSame('competitive_rubric.product_intent_fit', data_get($plan, 'repair_steps.1.target'));
        $this->assertStringContainsString('Close at least 2 point(s)', data_get($plan, 'repair_steps.1.action'));
        $this->assertSame(-2, data_get($plan, 'repair_steps.1.competitive_gap.delta_vs_best_rival'));
        $this->assertSame('live_mode_repair_loop', data_get($plan, 'repair_steps.1.competitive_gap.case_id'));
        $this->assertSame('pbakaus_impeccable', data_get($plan, 'repair_steps.1.competitive_gap.best_rival_system'));
    }

    public function test_invalid_competitive_dimension_gap_blocks_fake_repair_plan(): void
    {
        $plan = app(AtlasFrontendRepairPlannerService::class)->plan([
            'dimension_gaps' => [
                [
                    'dimension' => 'not_a_rubric_dimension',
                    'points_to_match' => 2,
                    'delta_vs_best_rival' => -2,
                    'best_rival_score' => 12,
                ],
            ],
        ]);

        $this->assertSame('blocked', $plan['status']);
        $this->assertContains('invalid_competitive_dimension_gap', $plan['blockers']);
        $this->assertSame([], $plan['repair_steps']);
        $this->assertSame([], data_get($plan, 'inputs.dimension_gaps'));
        $this->assertSame('dimension_not_in_competitive_rubric', data_get($plan, 'inputs.invalid_dimension_gaps.0.reason'));
        $this->assertSame('not_a_rubric_dimension', data_get($plan, 'inputs.invalid_dimension_gaps.0.dimension'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($plan, 'inputs.invalid_dimension_gaps.0.dimension_hash'));
    }

    public function test_mixed_competitive_dimension_gaps_keep_valid_steps_and_warn(): void
    {
        $plan = app(AtlasFrontendRepairPlannerService::class)->plan([
            'dimension_gaps' => [
                ['dimension' => 'product_intent_fit', 'points_to_match' => 1],
                ['dimension' => 'made_up_dimension', 'points_to_match' => 1],
            ],
        ]);

        $this->assertSame('ready', $plan['status']);
        $this->assertContains('some_competitive_dimension_gaps_invalid', $plan['warnings']);
        $this->assertSame('repair_competitive_dimension_product_intent_fit', data_get($plan, 'repair_steps.1.id'));
        $this->assertSame('made_up_dimension', data_get($plan, 'inputs.invalid_dimension_gaps.0.dimension'));
    }

    public function test_missing_repair_signal_blocks_strict_claims(): void
    {
        $plan = app(AtlasFrontendRepairPlannerService::class)->plan([]);

        $this->assertSame('blocked', $plan['status']);
        $this->assertContains('no_repair_signal_provided', $plan['blockers']);
    }
}
