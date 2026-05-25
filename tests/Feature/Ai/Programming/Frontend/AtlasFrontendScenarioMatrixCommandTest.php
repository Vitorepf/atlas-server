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

    public function test_scenarios_command_accepts_frontend_app_scope(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-scenario-command-scope-'.bin2hex(random_bytes(4));
        mkdir($workspace.'/apps/web', 0777, true);

        $exitCode = Artisan::call('atlas:frontend:scenarios', [
            '--task' => 'Criar dashboard SaaS com login',
            '--workspace' => $workspace,
            '--frontend-app' => 'apps/web',
            '--acceptance' => true,
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('frontend_app_scope', $output);
        $this->assertStringContainsString('subscope_selected', $output);
        $this->assertStringNotContainsString($workspace.'/apps/web', $output);
    }
}
