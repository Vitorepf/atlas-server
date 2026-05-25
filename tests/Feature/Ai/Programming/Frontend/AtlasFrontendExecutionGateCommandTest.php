<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendExecutionGateCommandTest extends TestCase
{
    public function test_gate_command_blocks_strict_when_required_inputs_are_missing(): void
    {
        $exitCode = Artisan::call('atlas:frontend:gate', [
            '--task' => 'Criar frontend SaaS multiempresa',
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('atlas.frontend.execution_gate.v1', $output);
        $this->assertStringContainsString('task_spec', $output);
        $this->assertStringContainsString('acceptance_criteria_missing', $output);
        $this->assertStringContainsString('execution_allowed', $output);
    }

    public function test_gate_command_allows_simple_frontend_task_with_plans(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-gate-command-'.bin2hex(random_bytes(4));
        File::makeDirectory($workspace.'/components', 0755, true);
        file_put_contents($workspace.'/package.json', json_encode([
            'dependencies' => ['react' => '^latest'],
        ]));
        file_put_contents($workspace.'/components/Card.tsx', 'export function Card() { return <section className="text-primary" />; }');

        $exitCode = Artisan::call('atlas:frontend:gate', [
            '--task' => 'Ajustar Card frontend',
            '--workspace' => $workspace,
            '--company-profile-ready' => true,
            '--acceptance' => true,
            '--test-plan' => true,
            '--visual-quality-plan' => true,
            '--evidence-plan' => true,
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"status": "passed"', $output);
        $this->assertStringContainsString('"execution_allowed": true', $output);
        $this->assertStringContainsString('task_spec_hash', $output);
    }

    public function test_gate_command_blocks_mismatched_task_spec_hash(): void
    {
        $exitCode = Artisan::call('atlas:frontend:gate', [
            '--task' => 'Ajustar Card frontend',
            '--task-spec-hash' => str_repeat('b', 64),
            '--acceptance' => true,
            '--test-plan' => true,
            '--visual-quality-plan' => true,
            '--evidence-plan' => true,
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('task_spec_hash_mismatch', $output);
    }
}
