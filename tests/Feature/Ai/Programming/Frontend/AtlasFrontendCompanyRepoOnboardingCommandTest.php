<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendCompanyRepoOnboardingService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDossierService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendCompanyRepoOnboardingCommandTest extends TestCase
{
    public function test_onboard_command_prepares_ready_company_repo(): void
    {
        $workspace = $this->readyWorkspace();

        $exitCode = Artisan::call('atlas:frontend:onboard', [
            '--task' => 'Refinar BlackInk com dashboard premium',
            '--workspace' => $workspace,
            '--provider' => 'codex_cli',
            '--acceptance' => true,
            '--test-plan' => true,
            '--visual-quality-plan' => true,
            '--evidence-plan' => true,
            '--senior-design-review' => true,
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(AtlasFrontendCompanyRepoOnboardingService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('ready_for_operator_execution', $output);
        $this->assertFileExists($workspace.'/.atlas/skills/atlas-frontend/SKILL.md');
        $this->assertFileExists($workspace.'/.atlas/frontend/onboarding-receipt.json');
    }

    public function test_onboard_command_strict_fails_when_repo_only_prepared(): void
    {
        $workspace = $this->minimalWorkspace();

        $exitCode = Artisan::call('atlas:frontend:onboard', [
            '--task' => 'Criar SaaS novo premium',
            '--workspace' => $workspace,
            '--write-docs' => true,
            '--acceptance' => true,
            '--test-plan' => true,
            '--visual-quality-plan' => true,
            '--evidence-plan' => true,
            '--senior-design-review' => true,
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('prepared_needs_context', $output);
        $this->assertFileExists($workspace.'/.atlas/skills/atlas-frontend/SKILL.md');
        $this->assertFileExists($workspace.'/docs/design/product-experience-brief.md');
    }

    private function readyWorkspace(): string
    {
        $workspace = $this->minimalWorkspace();
        foreach (app(AtlasFrontendDesignDossierService::class)->requiredDocuments() as $definition) {
            File::ensureDirectoryExists(dirname($workspace.'/'.$definition['path']));
            File::put($workspace.'/'.$definition['path'], $this->filledDocument((string) $definition['title'], (array) $definition['sections']));
        }

        return $workspace;
    }

    private function minimalWorkspace(): string
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-onboarding-command-'.bin2hex(random_bytes(4));
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
