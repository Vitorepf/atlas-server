<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDossierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendExecutionRunbookService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendExecutionRunbookServiceTest extends TestCase
{
    public function test_ready_repo_runbook_uses_repo_native_commands_and_evidence_kit(): void
    {
        $workspace = $this->readyWorkspace();
        $evidence = sys_get_temp_dir().'/atlas-frontend-runbook-evidence-'.bin2hex(random_bytes(4));

        $payload = app(AtlasFrontendExecutionRunbookService::class)->compile([
            'task' => 'Ajustar componente Button no frontend da empresa',
            'workspace' => $workspace,
            'evidence_output' => $evidence,
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
        ]);

        $this->assertSame(AtlasFrontendExecutionRunbookService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('company_frontend_repo_execution_runbook', $payload['runbook_type']);
        $this->assertSame('pnpm', data_get($payload, 'repo_operating_map.package_manager'));
        $commands = implode("\n", collect($payload['runbook_steps'])->flatMap(fn (array $step): array => $step['commands'])->all());
        $this->assertStringContainsString('pnpm install --frozen-lockfile', $commands);
        $this->assertStringContainsString('pnpm run test', $commands);
        $this->assertStringContainsString('pnpm run build', $commands);
        $this->assertStringContainsString('pnpm run typecheck', $commands);
        $this->assertStringContainsString('atlas:frontend:evidence-kit', $commands);
        $this->assertStringContainsString('atlas:frontend:run-certify', $commands);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.runbook_is_not_execution_evidence'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['runbook_hash']);
    }

    public function test_runbook_blocks_when_repo_context_is_missing(): void
    {
        $payload = app(AtlasFrontendExecutionRunbookService::class)->compile([
            'task' => 'Ajustar tela',
            'workspace' => sys_get_temp_dir().'/atlas-missing-repo-'.bin2hex(random_bytes(4)),
            'acceptance_criteria' => true,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('repo_workspace_not_found', $payload['blockers']);
        $this->assertContains('confirm_frontend_workspace_or_create_package_manifest', $payload['required_next_actions']);
    }

    private function readyWorkspace(): string
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-runbook-ready-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace.'/src/components/ui');
        File::ensureDirectoryExists($workspace.'/src/pages');
        File::put($workspace.'/pnpm-lock.yaml', 'lockfileVersion: 9.0');
        File::put($workspace.'/package.json', json_encode([
            'scripts' => [
                'dev' => 'vite --host 127.0.0.1',
                'test' => 'vitest run',
                'build' => 'vite build',
                'typecheck' => 'tsc --noEmit',
            ],
            'dependencies' => ['react' => '^latest', 'vite' => '^latest', 'tailwindcss' => '^latest'],
        ], JSON_THROW_ON_ERROR));
        File::put($workspace.'/index.html', '<div id="root"></div>');
        File::put($workspace.'/src/main.tsx', 'import React from "react";');
        File::put($workspace.'/src/pages/Dashboard.tsx', 'export function Dashboard() { return <main className="bg-primary" />; }');
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
            $lines[] = 'Approved frontend operating context with product intent, UX expectations, design constraints, quality gates, and release evidence for premium company work.';
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }
}
