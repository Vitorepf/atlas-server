<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasFrontendBenchmarkCommandTest extends TestCase
{
    public function test_benchmark_command_emits_competitive_matrix(): void
    {
        $exitCode = Artisan::call('atlas:frontend:benchmark', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.benchmark_runtime.v1', $output);
        $this->assertStringContainsString('atlas_more_complete_than_impeccable_on_governed_delivery_contract', $output);
        $this->assertStringContainsString('atlas_world_best_frontend_system', $output);
    }
}
