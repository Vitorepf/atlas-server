<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendCompanyPortfolioService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDossierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendSkillPackService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendCompanyPortfolioServiceTest extends TestCase
{
    public function test_scans_local_company_frontend_portfolio_without_raw_paths_or_source(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-portfolio-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($root);
        $ready = $this->workspace($root, 'blackink-web');
        $blocked = $this->workspace($root, 'raw-saas');
        $this->fillDesignDocs($ready);
        app(AtlasFrontendSkillPackService::class)->install(['workspace' => $ready]);

        $payload = app(AtlasFrontendCompanyPortfolioService::class)->scan([
            'root' => $root,
            'max_depth' => 2,
        ]);

        $this->assertSame(AtlasFrontendCompanyPortfolioService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(2, data_get($payload, 'summary.candidate_repo_count'));
        $this->assertSame(1, data_get($payload, 'summary.ready_for_operator_execution_count'));
        $this->assertSame(1, data_get($payload, 'summary.blocked_count'));
        $this->assertFalse((bool) data_get($payload, 'scan_policy.raw_source_returned'));
        $this->assertFalse((bool) data_get($payload, 'scan_policy.absolute_paths_returned'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));

        $repos = collect($payload['repositories'])->keyBy('repo_ref.relative_name');
        $this->assertSame('ready_for_operator_execution', data_get($repos->get('blackink-web'), 'status'));
        $this->assertTrue((bool) data_get($repos->get('blackink-web'), 'dispatch_policy.provider_dispatch_allowed'));
        $this->assertSame('blocked', data_get($repos->get('raw-saas'), 'status'));
        $this->assertContains('run_atlas_frontend_onboard_for_repo', data_get($repos->get('raw-saas'), 'recommended_next_actions'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['portfolio_hash']);

        unset($blocked);
    }

    public function test_blocks_missing_portfolio_root(): void
    {
        $payload = app(AtlasFrontendCompanyPortfolioService::class)->scan([
            'root' => sys_get_temp_dir().'/atlas-frontend-portfolio-missing-'.bin2hex(random_bytes(4)),
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('root_not_found', $payload['blockers']);
    }

    private function workspace(string $root, string $name): string
    {
        $workspace = $root.'/'.$name;
        File::ensureDirectoryExists($workspace.'/src/components/ui');
        File::ensureDirectoryExists($workspace.'/src/pages');
        File::put($workspace.'/pnpm-lock.yaml', 'lockfileVersion: 9.0');
        File::put($workspace.'/package.json', json_encode([
            'scripts' => [
                'dev' => 'vite --host 127.0.0.1',
                'test' => 'vitest run',
                'build' => 'vite build',
                'typecheck' => 'tsc --noEmit',
                'lint' => 'eslint .',
            ],
            'dependencies' => ['react' => '^latest', 'vite' => '^latest', 'tailwindcss' => '^latest'],
        ], JSON_THROW_ON_ERROR));
        File::put($workspace.'/index.html', '<div id="root"></div>');
        File::put($workspace.'/src/main.tsx', 'import React from "react";');
        File::put($workspace.'/src/pages/Dashboard.tsx', 'export function Dashboard() { return <main className="bg-primary text-white" />; }');
        File::put($workspace.'/src/components/ui/Button.tsx', 'export function Button() { return <button className="bg-primary text-white" />; }');
        File::put($workspace.'/src/styles.css', ':root { --color-primary: #123456; --space-2: 8px; }');

        return $workspace;
    }

    private function fillDesignDocs(string $workspace): void
    {
        foreach (app(AtlasFrontendDesignDossierService::class)->requiredDocuments() as $definition) {
            File::ensureDirectoryExists(dirname($workspace.'/'.$definition['path']));
            File::put($workspace.'/'.$definition['path'], $this->filledDocument((string) $definition['title'], (array) $definition['sections']));
        }
    }

    /**
     * @param  array<int,string>  $sections
     */
    private function filledDocument(string $title, array $sections): string
    {
        $lines = ['# '.$title, '', 'Status: canonical', ''];
        foreach ($sections as $section) {
            $lines[] = '## '.$section;
            $lines[] = 'Approved frontend operating context for premium company software, including UX constraints, visual principles, measurable quality gates, and release evidence required before delivery.';
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }
}
