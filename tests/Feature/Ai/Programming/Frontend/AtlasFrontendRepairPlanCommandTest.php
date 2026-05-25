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
}
