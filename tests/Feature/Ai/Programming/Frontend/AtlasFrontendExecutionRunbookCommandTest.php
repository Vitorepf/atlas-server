<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDossierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendExecutionRunbookService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendExecutionRunbookCommandTest extends TestCase
{
    public function test_runbook_command_emits_ready_selected_repo_subscope_and_private_benchmark_proof(): void
    {
        $workspace = $this->readyWorkspace();
        $evidence = sys_get_temp_dir().'/atlas-frontend-runbook-command-evidence-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:runbook', [
            '--task' => 'Ajustar checkout no app web selecionado',
            '--workspace' => $workspace,
            '--frontend-app' => 'apps/web',
            '--evidence-output' => $evidence,
            '--acceptance' => true,
            '--asset-context' => true,
            '--company-profile-ready' => true,
            '--test-plan' => true,
            '--visual-quality-plan' => true,
            '--evidence-plan' => true,
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $commands = implode("\n", collect($payload['runbook_steps'])->flatMap(fn (array $step): array => $step['commands'])->all());

        $this->assertSame(0, $exitCode);
        $this->assertSame(AtlasFrontendExecutionRunbookService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('subscope_selected', data_get($payload, 'frontend_app_scope.status'));
        $this->assertSame('apps/web', data_get($payload, 'frontend_app_scope.relative_name'));
        $this->assertTrue((bool) data_get($payload, 'frontend_app_scope.repo_workspace_remains_primary'));
        $this->assertStringContainsString("cd 'apps/web' && pnpm install --frozen-lockfile", $commands);
        $this->assertStringContainsString('atlas:frontend:publish attest --bundle=<bundle>', $commands);
        $this->assertStringContainsString('atlas:frontend:private-benchmark-plan', $commands);
        $this->assertContains('private_benchmark_and_optional_publication_proof', collect($payload['runbook_steps'])->pluck('id')->all());
        $this->assertTrue((bool) data_get($payload, 'claim_policy.public_distribution_step_is_not_required_for_customer_handoff'));
        $this->assertStringNotContainsString('space_runtime_required', Artisan::output());
    }

    public function test_runbook_command_emits_blocked_runbook_for_missing_repo(): void
    {
        $exitCode = Artisan::call('atlas:frontend:runbook', [
            '--task' => 'Ajustar tela',
            '--workspace' => sys_get_temp_dir().'/atlas-frontend-runbook-missing-'.bin2hex(random_bytes(4)),
            '--frontend-app' => 'apps/web',
            '--acceptance' => true,
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(AtlasFrontendExecutionRunbookService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('frontend_app_scope', $output);
        $this->assertStringContainsString('runbook_hash', $output);
        $this->assertStringContainsString('repo_workspace_not_found', $output);
    }

    private function readyWorkspace(): string
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-runbook-command-ready-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace.'/src/components/ui');
        File::ensureDirectoryExists($workspace.'/src/pages');
        File::ensureDirectoryExists($workspace.'/apps/web');
        File::put($workspace.'/pnpm-lock.yaml', 'lockfileVersion: 9.0');
        $package = json_encode([
            'scripts' => [
                'dev' => 'vite --host 127.0.0.1',
                'test' => 'vitest run',
                'build' => 'vite build',
                'typecheck' => 'tsc --noEmit',
            ],
            'dependencies' => ['react' => '^latest', 'vite' => '^latest', 'tailwindcss' => '^latest'],
        ], JSON_THROW_ON_ERROR);
        File::put($workspace.'/package.json', $package);
        File::put($workspace.'/apps/web/package.json', $package);
        File::put($workspace.'/index.html', '<div id="root"></div>');
        File::put($workspace.'/src/main.tsx', 'import React from "react";');
        File::put($workspace.'/src/pages/Checkout.tsx', 'export function Checkout() { return <main className="bg-primary" />; }');
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
