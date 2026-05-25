<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendCompanyPortfolioService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendCompanyPortfolioCommandTest extends TestCase
{
    public function test_portfolio_command_scans_parent_directory(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-portfolio-command-'.bin2hex(random_bytes(4));
        $this->workspace($root, 'refinar-web');

        $exitCode = Artisan::call('atlas:frontend:portfolio', [
            '--root' => $root,
            '--task' => 'Melhorar dashboard Refinar',
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(AtlasFrontendCompanyPortfolioService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('local_company_frontend_repo_portfolio', $output);
        $this->assertStringContainsString('optional_repository_discovery_only', $output);
        $this->assertStringContainsString('operator_selected_repository_workspace', $output);
        $this->assertStringContainsString('portfolio_root_is_not_selected_workspace', $output);
        $this->assertStringContainsString('selection_brief', $output);
        $this->assertStringContainsString('ready_for_operator_choice', $output);
        $this->assertStringContainsString('task_bound_for_candidate_ranking', $output);
        $this->assertStringContainsString('task_fit_status', $output);
        $this->assertStringContainsString('selection_handoff', $output);
        $this->assertStringContainsString('atlas:frontend:selected-workspace', $output);
        $this->assertStringContainsString('refinar-web', $output);
        $this->assertStringNotContainsString('Melhorar dashboard Refinar', $output);
        $this->assertStringNotContainsString($root, $output);
    }

    public function test_portfolio_command_strict_fails_missing_root(): void
    {
        $exitCode = Artisan::call('atlas:frontend:portfolio', [
            '--root' => sys_get_temp_dir().'/atlas-frontend-portfolio-command-missing-'.bin2hex(random_bytes(4)),
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('root_not_found', $output);
        $this->assertStringContainsString('portfolio_dispatch_allowed', $output);
    }

    private function workspace(string $root, string $name): string
    {
        $workspace = $root.'/'.$name;
        File::ensureDirectoryExists($workspace.'/src/pages');
        File::put($workspace.'/pnpm-lock.yaml', 'lockfileVersion: 9.0');
        File::put($workspace.'/package.json', json_encode([
            'scripts' => ['dev' => 'vite', 'test' => 'vitest run', 'build' => 'vite build'],
            'dependencies' => ['react' => '^latest', 'vite' => '^latest'],
        ], JSON_THROW_ON_ERROR));
        File::put($workspace.'/index.html', '<div id="root"></div>');
        File::put($workspace.'/src/main.tsx', 'import React from "react";');
        File::put($workspace.'/src/pages/Home.tsx', 'export function Home() { return <main />; }');

        return $workspace;
    }
}
