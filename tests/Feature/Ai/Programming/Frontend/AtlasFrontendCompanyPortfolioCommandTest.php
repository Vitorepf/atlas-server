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
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(AtlasFrontendCompanyPortfolioService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('local_company_frontend_repo_portfolio', $output);
        $this->assertStringContainsString('refinar-web', $output);
    }

    public function test_portfolio_command_strict_fails_missing_root(): void
    {
        $exitCode = Artisan::call('atlas:frontend:portfolio', [
            '--root' => sys_get_temp_dir().'/atlas-frontend-portfolio-command-missing-'.bin2hex(random_bytes(4)),
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('root_not_found', Artisan::output());
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
