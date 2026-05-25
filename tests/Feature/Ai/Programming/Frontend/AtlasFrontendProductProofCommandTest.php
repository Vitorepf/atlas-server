<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDossierService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendProductProofCommandTest extends TestCase
{
    public function test_proof_command_emits_demo_catalog(): void
    {
        $exitCode = Artisan::call('atlas:frontend:proof', ['action' => 'catalog', '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.product_proof_runtime.v1', $output);
        $this->assertStringContainsString('saas_dashboard_repair', $output);
        $this->assertStringContainsString('public_site_claim_requires_hosted_demo', $output);
    }

    public function test_proof_command_builds_static_bundle(): void
    {
        $outputPath = sys_get_temp_dir().'/atlas-frontend-proof-command-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:proof', [
            'action' => 'build',
            '--output' => $outputPath,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.product_proof_bundle.v1', $output);
        $this->assertStringContainsString('publication_workflow', $output);
        $this->assertStringContainsString('receipt-template --bundle=<bundle>', $output);
        $this->assertFileExists($outputPath.'/index.html');
        $this->assertFileExists($outputPath.'/manifest.json');
    }

    public function test_proof_command_builds_static_bundle_with_frontend_app_scope(): void
    {
        $outputPath = sys_get_temp_dir().'/atlas-frontend-proof-command-scope-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:proof', [
            'action' => 'build',
            '--output' => $outputPath,
            '--frontend-app' => 'apps/web',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('subscope_selected', data_get($payload, 'frontend_app_scope.status'));
        $this->assertSame('apps/web', data_get($payload, 'frontend_app_scope.relative_name'));
        $this->assertSame(hash('sha256', 'apps/web'), data_get($payload, 'frontend_app_scope.relative_name_hash'));
    }

    public function test_proof_command_builds_company_repo_pilot_dossier(): void
    {
        $workspace = $this->readyWorkspace();
        $outputPath = sys_get_temp_dir().'/atlas-frontend-proof-pilot-command-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:proof', [
            'action' => 'pilot',
            '--task' => 'Refinar BlackInk com design premium',
            '--workspace' => $workspace,
            '--provider' => 'codex_cli',
            '--output' => $outputPath,
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
        $this->assertStringContainsString('atlas.frontend.company_repo_proof_dossier.v1', $output);
        $this->assertStringContainsString('ready_for_operator_execution', $output);
        $this->assertStringContainsString('world_best_claim_allowed', $output);
        $this->assertFileExists($outputPath.'/pilot-dossier.json');
        $this->assertFileExists($outputPath.'/evidence-kit/evidence-kit-manifest.json');
    }

    public function test_proof_command_builds_company_repo_pilot_dossier_with_frontend_app_scope(): void
    {
        $workspace = $this->readyMonorepoWorkspace();
        $outputPath = sys_get_temp_dir().'/atlas-frontend-proof-pilot-command-scope-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:proof', [
            'action' => 'pilot',
            '--task' => 'Refinar app web premium no monorepo',
            '--workspace' => $workspace,
            '--frontend-app' => 'apps/web',
            '--provider' => 'codex_cli',
            '--output' => $outputPath,
            '--acceptance' => true,
            '--test-plan' => true,
            '--visual-quality-plan' => true,
            '--evidence-plan' => true,
            '--senior-design-review' => true,
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('subscope_selected', data_get($payload, 'frontend_app_scope.status'));
        $this->assertSame('apps/web', data_get($payload, 'frontend_app_scope.relative_name'));
        $this->assertTrue(collect(data_get($payload, 'execution_contract.collection_commands'))->contains(
            fn (string $command): bool => str_contains($command, '--frontend-app=apps/web'),
        ));
    }

    public function test_proof_command_strict_fails_blocked_pilot_dossier(): void
    {
        $exitCode = Artisan::call('atlas:frontend:proof', [
            'action' => 'pilot',
            '--task' => 'Criar tela bonita',
            '--workspace' => sys_get_temp_dir().'/atlas-frontend-proof-command-missing-'.bin2hex(random_bytes(4)),
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('blocked', Artisan::output());
    }

    private function readyWorkspace(): string
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-proof-command-ready-'.bin2hex(random_bytes(4));
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

        foreach (app(AtlasFrontendDesignDossierService::class)->requiredDocuments() as $definition) {
            File::ensureDirectoryExists(dirname($workspace.'/'.$definition['path']));
            File::put($workspace.'/'.$definition['path'], $this->filledDocument((string) $definition['title'], (array) $definition['sections']));
        }

        return $workspace;
    }

    private function readyMonorepoWorkspace(): string
    {
        $workspace = $this->readyWorkspace();
        File::ensureDirectoryExists($workspace.'/apps/web/src');
        File::put($workspace.'/apps/web/package.json', json_encode([
            'scripts' => [
                'dev' => 'vite --host 127.0.0.1',
                'test' => 'vitest run',
                'build' => 'vite build',
                'typecheck' => 'tsc --noEmit',
                'lint' => 'eslint .',
            ],
            'dependencies' => ['react' => '^latest', 'vite' => '^latest'],
        ], JSON_THROW_ON_ERROR));
        File::put($workspace.'/apps/web/index.html', '<div id="root"></div>');
        File::put($workspace.'/apps/web/src/main.tsx', 'import React from "react";');

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
