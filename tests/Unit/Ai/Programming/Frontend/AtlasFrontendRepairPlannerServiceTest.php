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

    public function test_missing_repair_signal_blocks_strict_claims(): void
    {
        $plan = app(AtlasFrontendRepairPlannerService::class)->plan([]);

        $this->assertSame('blocked', $plan['status']);
        $this->assertContains('no_repair_signal_provided', $plan['blockers']);
    }
}
