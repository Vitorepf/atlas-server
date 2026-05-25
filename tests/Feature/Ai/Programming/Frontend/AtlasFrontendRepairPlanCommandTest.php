<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendRepairPlannerService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasFrontendRepairPlanCommandTest extends TestCase
{
    public function test_repair_plan_command_emits_repair_plan(): void
    {
        $exitCode = Artisan::call('atlas:frontend:repair-plan', [
            '--blocker' => ['text_overlap'],
            '--failed-gate' => ['visual_quality_gate'],
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(AtlasFrontendRepairPlannerService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('repair_plan_hash', $output);
        $this->assertStringContainsString('repair_text_overlap', $output);
    }

    public function test_repair_plan_command_blocks_strict_without_signal(): void
    {
        $exitCode = Artisan::call('atlas:frontend:repair-plan', [
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('no_repair_signal_provided', $output);
    }

    public function test_repair_plan_command_accepts_competitive_dimension_gap(): void
    {
        $exitCode = Artisan::call('atlas:frontend:repair-plan', [
            '--dimension-gap' => ['product_intent_fit:2:-2:12:live_mode_repair_loop:pbakaus_impeccable:10:12'],
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('competitive_replay_repair', $output);
        $this->assertStringContainsString('repair_competitive_dimension_product_intent_fit', $output);
        $this->assertStringContainsString('live_mode_repair_loop', $output);
        $this->assertStringContainsString('pbakaus_impeccable', $output);
        $this->assertStringContainsString('points_to_lead', $output);
        $this->assertStringContainsString('target_score_to_lead', $output);
        $this->assertStringContainsString('lead_possible_within_rubric', $output);
        $this->assertStringContainsString('dimension_improvement_receipt', $output);
    }

    public function test_repair_plan_command_blocks_invalid_competitive_dimension_gap_in_strict_mode(): void
    {
        $exitCode = Artisan::call('atlas:frontend:repair-plan', [
            '--dimension-gap' => ['not_a_dimension:1:-1:10'],
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('invalid_competitive_dimension_gap', $output);
        $this->assertStringContainsString('dimension_not_in_competitive_rubric', $output);
        $this->assertStringNotContainsString('repair_competitive_dimension_not_a_dimension', $output);
    }
}
