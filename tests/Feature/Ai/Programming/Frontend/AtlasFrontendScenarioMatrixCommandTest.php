<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendScenarioMatrixService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasFrontendScenarioMatrixCommandTest extends TestCase
{
    public function test_scenarios_command_emits_matrix_hash(): void
    {
        $exitCode = Artisan::call('atlas:frontend:scenarios', [
            '--task' => 'Criar dashboard SaaS com login',
            '--acceptance' => true,
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(AtlasFrontendScenarioMatrixService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('scenario_matrix_hash', $output);
        $this->assertStringContainsString('route_viewport_state_visual_verification_matrix', $output);
    }
}
