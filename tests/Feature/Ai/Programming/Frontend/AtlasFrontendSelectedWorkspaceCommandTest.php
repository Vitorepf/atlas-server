<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendSelectedWorkspaceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendSelectedWorkspaceCommandTest extends TestCase
{
    public function test_selected_workspace_command_emits_repo_selection_contract(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-selected-workspace-command-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace);
        File::put($workspace.'/pnpm-lock.yaml', 'lockfileVersion: 9.0');
        File::put($workspace.'/package.json', json_encode([
            'scripts' => [
                'dev' => 'vite --host 127.0.0.1',
                'test' => 'vitest run',
                'build' => 'vite build',
            ],
            'dependencies' => [
                'react' => '^latest',
                'vite' => '^latest',
            ],
        ], JSON_THROW_ON_ERROR));

        $exitCode = Artisan::call('atlas:frontend:selected-workspace', [
            '--task' => 'Refinar dashboard premium',
            '--workspace' => $workspace,
            '--selection-source' => 'atlas_code',
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(AtlasFrontendSelectedWorkspaceService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('operator_selected_repository_workspace', $output);
        $this->assertStringContainsString('selected_repository', $output);
        $this->assertStringContainsString('selected_workspace_hash', $output);
        $this->assertStringContainsString('repo_operating_summary', $output);
        $this->assertStringContainsString('frontend_app_candidates', $output);
        $this->assertStringContainsString('dispatch_readiness', $output);
        $this->assertStringContainsString('capability_readiness', $output);
        $this->assertStringContainsString('operator_start_panel', $output);
        $this->assertStringContainsString('task_binding', $output);
        $this->assertStringContainsString('next_best_action', $output);
        $this->assertStringContainsString('frontend_runtime_projection', $output);
        $this->assertStringContainsString('read_only_selected_repo_frontend_runtime_bundle', $output);
        $this->assertStringContainsString('runtime_projection_hash', $output);
        $this->assertStringContainsString('bound', $output);
        $this->assertStringNotContainsString('Refinar dashboard premium', $output);
        $this->assertStringContainsString('complete_frontend_context', $output);
        $this->assertStringContainsString('local_preview', $output);
        $this->assertStringContainsString('measured_evidence', $output);
        $this->assertStringContainsString('provider_dispatch_requires_gate_and_onboarding', $output);
        $this->assertStringContainsString('pnpm', $output);
        $this->assertStringContainsString('vite', $output);
        $this->assertStringContainsString('atlas:frontend:onboard', $output);
        $this->assertStringContainsString('atlas:frontend:gauntlet', $output);
        $this->assertStringContainsString('atlas:frontend:provider-packet', $output);
        $this->assertStringContainsString('atlas:frontend:runbook', $output);
        $this->assertStringContainsString('provider_instruction_packet_read_only_projection', $output);
        $this->assertStringNotContainsString($workspace, $output);
    }

    public function test_selected_workspace_command_fails_strict_when_workspace_missing(): void
    {
        $exitCode = Artisan::call('atlas:frontend:selected-workspace', [
            '--workspace' => sys_get_temp_dir().'/atlas-frontend-selected-workspace-missing-'.bin2hex(random_bytes(4)),
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('workspace_missing', $output);
        $this->assertStringContainsString('generic_or_missing_workspace', $output);
    }

    public function test_selected_workspace_command_accepts_frontend_app_subscope(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-selected-workspace-command-monorepo-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace.'/apps/web/src/pages');
        File::put($workspace.'/package.json', json_encode([
            'private' => true,
            'workspaces' => ['apps/*'],
        ], JSON_THROW_ON_ERROR));
        File::put($workspace.'/pnpm-workspace.yaml', 'packages: ["apps/*"]');
        File::put($workspace.'/apps/web/package.json', json_encode([
            'scripts' => [
                'dev' => 'vite --host 127.0.0.1',
                'test' => 'vitest run',
                'build' => 'vite build',
            ],
            'dependencies' => [
                'react' => '^latest',
                'vite' => '^latest',
            ],
        ], JSON_THROW_ON_ERROR));
        File::put($workspace.'/apps/web/src/pages/Dashboard.tsx', 'export function Dashboard() { return <main />; }');

        $exitCode = Artisan::call('atlas:frontend:selected-workspace', [
            '--task' => 'Refinar dashboard web',
            '--workspace' => $workspace,
            '--frontend-app' => 'apps/web',
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('requested_candidate_selected', $output);
        $this->assertStringContainsString('frontend_app_candidate_selected', $output);
        $this->assertStringContainsString('selected_repository', $output);
        $this->assertStringNotContainsString('selected-repo-or-frontend-app-subdir', $output);
        $this->assertStringNotContainsString($workspace.'/apps/web', $output);
    }

    public function test_selected_workspace_command_fails_strict_for_invalid_frontend_app_subscope(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-selected-workspace-command-invalid-subscope-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace.'/apps/web/src/pages');
        File::put($workspace.'/package.json', json_encode([
            'private' => true,
            'workspaces' => ['apps/*'],
        ], JSON_THROW_ON_ERROR));
        File::put($workspace.'/pnpm-workspace.yaml', 'packages: ["apps/*"]');
        File::put($workspace.'/apps/web/package.json', json_encode([
            'scripts' => [
                'dev' => 'vite --host 127.0.0.1',
                'test' => 'vitest run',
                'build' => 'vite build',
            ],
            'dependencies' => [
                'react' => '^latest',
                'vite' => '^latest',
            ],
        ], JSON_THROW_ON_ERROR));
        File::put($workspace.'/apps/web/src/pages/Dashboard.tsx', 'export function Dashboard() { return <main />; }');

        $exitCode = Artisan::call('atlas:frontend:selected-workspace', [
            '--task' => 'Refinar dashboard web',
            '--workspace' => $workspace,
            '--frontend-app' => 'apps/mobile',
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('requested_frontend_app_subscope_invalid', $output);
        $this->assertStringContainsString('requested_frontend_app_subscope_not_found', $output);
        $this->assertStringContainsString('choose_valid_frontend_app_subscope_inside_selected_repo', $output);
        $this->assertStringContainsString('--frontend-app=apps/web', $output);
        $this->assertStringNotContainsString($workspace.'/apps/mobile', $output);
    }
}
