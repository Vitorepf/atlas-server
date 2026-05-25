<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDossierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRepoIntakeService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendRepoIntakeServiceTest extends TestCase
{
    public function test_ready_company_frontend_repo_emits_operating_map(): void
    {
        $workspace = $this->readyWorkspace();

        $payload = app(AtlasFrontendRepoIntakeService::class)->inspect($workspace);

        $this->assertSame(AtlasFrontendRepoIntakeService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('company_owned_frontend_repo_operating_map', $payload['intake_type']);
        $this->assertSame('pnpm', $payload['package_manager']);
        $this->assertSame('vite', data_get($payload, 'framework.primary'));
        $this->assertContains('pnpm run test', data_get($payload, 'repo_map.test_commands'));
        $this->assertContains('pnpm run build', data_get($payload, 'repo_map.build_commands'));
        $this->assertContains('pnpm run typecheck', data_get($payload, 'repo_map.quality_commands'));
        $this->assertNotEmpty(data_get($payload, 'repo_map.entrypoints'));
        $this->assertNotEmpty(data_get($payload, 'repo_map.route_candidates'));
        $this->assertFalse((bool) data_get($payload, 'repo_map.raw_source_returned'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['repo_intake_hash']);
    }

    public function test_blocks_repo_without_frontend_operating_map(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-intake-blocked-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace);

        $payload = app(AtlasFrontendRepoIntakeService::class)->inspect($workspace);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('package_json_missing', $payload['blockers']);
        $this->assertContains('frontend_framework_unknown', $payload['blockers']);
        $this->assertContains('company_design_dossier_not_ready', $payload['blockers']);
        $this->assertContains('confirm_frontend_workspace_or_create_package_manifest', $payload['recommended_next_actions']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.provider_can_start_with_repo_map'));
    }

    private function readyWorkspace(): string
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-intake-ready-'.bin2hex(random_bytes(4));
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
            'dependencies' => [
                'react' => '^latest',
                'vite' => '^latest',
                'tailwindcss' => '^latest',
            ],
        ], JSON_THROW_ON_ERROR));
        File::put($workspace.'/index.html', '<div id="root"></div>');
        File::put($workspace.'/src/main.tsx', 'import React from "react";');
        File::put($workspace.'/src/pages/Dashboard.tsx', 'export function Dashboard() { return <main className="bg-primary text-white" />; }');
        File::put($workspace.'/src/components/ui/Button.tsx', 'export function Button() { return <button className="bg-primary text-white" />; }');
        File::put($workspace.'/src/styles.css', ':root { --color-primary: #123456; --space-2: 8px; }');

        $dossier = app(AtlasFrontendDesignDossierService::class);
        foreach ($dossier->requiredDocuments() as $definition) {
            File::ensureDirectoryExists(dirname($workspace.'/'.$definition['path']));
            File::put($workspace.'/'.$definition['path'], $this->filledDocument((string) $definition['title'], (array) $definition['sections']));
        }

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
            $lines[] = 'Approved frontend operating context for a premium company product, with measurable UX, visual, quality, and release evidence constraints.';
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }
}
