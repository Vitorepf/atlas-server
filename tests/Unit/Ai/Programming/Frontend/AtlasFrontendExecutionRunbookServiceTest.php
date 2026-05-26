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
        $this->assertSame('repo_root', data_get($payload, 'frontend_app_scope.status'));
        $this->assertSame('pnpm', data_get($payload, 'repo_operating_map.package_manager'));
        $commands = implode("\n", collect($payload['runbook_steps'])->flatMap(fn (array $step): array => $step['commands'])->all());
        $this->assertStringContainsString('pnpm install --frozen-lockfile', $commands);
        $this->assertStringContainsString('pnpm run test', $commands);
        $this->assertStringContainsString('pnpm run build', $commands);
        $this->assertStringContainsString('pnpm run typecheck', $commands);
        $this->assertStringContainsString('atlas:frontend:evidence-kit', $commands);
        $this->assertStringContainsString('atlas:frontend:run-certify', $commands);
        $this->assertStringContainsString('atlas:frontend:publish attest --bundle=<bundle>', $commands);
        $this->assertStringContainsString('atlas:frontend:private-benchmark-plan', $commands);
        $this->assertStringNotContainsString('atlas:frontend:world-best-plan', $commands);
        $this->assertContains('private_benchmark_and_optional_publication_proof', collect($payload['runbook_steps'])->pluck('id')->all());
        $benchmarkStep = collect($payload['runbook_steps'])->firstWhere('id', 'private_benchmark_and_optional_publication_proof');
        $this->assertContains('product_proof_bundle_hash', $benchmarkStep['required_evidence']);
        $this->assertContains('private_benchmark_plan_hash', $benchmarkStep['required_evidence']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.runbook_is_not_execution_evidence'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.public_distribution_requires_publication_attestation'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.public_distribution_claim_requires_verified_receipt'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.public_distribution_step_is_not_required_for_customer_handoff'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));
        $this->assertFileExists($evidence.'/evidence-kit-manifest.json');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['runbook_hash']);
    }

    public function test_runbook_scopes_repo_native_commands_to_frontend_app_subdirectory(): void
    {
        $workspace = $this->readyWorkspace();
        $evidence = sys_get_temp_dir().'/atlas-frontend-runbook-app-evidence-'.bin2hex(random_bytes(4));

        $payload = app(AtlasFrontendExecutionRunbookService::class)->compile([
            'task' => 'Ajustar dashboard no app web',
            'workspace' => $workspace,
            'frontend_app' => 'apps/web',
            'evidence_output' => $evidence,
            'write_evidence_kit' => false,
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
        ]);

        $this->assertSame('ready', $payload['status']);
        $this->assertSame('subscope_selected', data_get($payload, 'frontend_app_scope.status'));
        $this->assertSame('apps/web', data_get($payload, 'frontend_app_scope.relative_name'));
        $this->assertSame(hash('sha256', 'apps/web'), data_get($payload, 'frontend_app_scope.relative_name_hash'));
        $this->assertTrue((bool) data_get($payload, 'frontend_app_scope.repo_workspace_remains_primary'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.frontend_app_scope_is_relative_subdirectory'));
        $commands = implode("\n", collect($payload['runbook_steps'])->flatMap(fn (array $step): array => $step['commands'])->all());
        $this->assertStringContainsString("cd 'apps/web' && pnpm install --frozen-lockfile", $commands);
        $this->assertStringContainsString("cd 'apps/web' && pnpm run test", $commands);
        $this->assertStringContainsString("cd 'apps/web' && pnpm run build", $commands);
        $this->assertStringContainsString('atlas:frontend:evidence-kit prepare', $commands);
        $this->assertStringNotContainsString($workspace.'/apps/web', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_read_only_runbook_projects_evidence_kit_without_writing_files(): void
    {
        $workspace = $this->readyWorkspace();
        $evidence = sys_get_temp_dir().'/atlas-frontend-runbook-read-only-evidence-'.bin2hex(random_bytes(4));

        $payload = app(AtlasFrontendExecutionRunbookService::class)->compile([
            'task' => 'Ajustar componente Button no frontend da empresa',
            'workspace' => $workspace,
            'evidence_output' => $evidence,
            'write_evidence_kit' => false,
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
        ]);

        $this->assertSame('ready', $payload['status']);
        $this->assertFalse((bool) $payload['write_evidence_kit']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.read_only_runbook_does_not_write_evidence_kit'));
        $commands = implode("\n", collect($payload['runbook_steps'])->flatMap(fn (array $step): array => $step['commands'])->all());
        $this->assertStringContainsString('atlas:frontend:evidence-kit prepare', $commands);
        $this->assertFileDoesNotExist($evidence.'/evidence-kit-manifest.json');
    }

    public function test_runbook_blocks_invalid_or_missing_frontend_app_subscope(): void
    {
        $workspace = $this->readyWorkspace();

        $invalid = app(AtlasFrontendExecutionRunbookService::class)->compile([
            'task' => 'Ajustar dashboard no app web',
            'workspace' => $workspace,
            'frontend_app' => '../secrets',
            'write_evidence_kit' => false,
            'acceptance_criteria' => true,
        ]);

        $this->assertSame('blocked', $invalid['status']);
        $this->assertSame('invalid_subscope', data_get($invalid, 'frontend_app_scope.status'));
        $this->assertContains('frontend_app_scope_invalid_relative_frontend_app_subscope', $invalid['blockers']);

        $missing = app(AtlasFrontendExecutionRunbookService::class)->compile([
            'task' => 'Ajustar dashboard no app web',
            'workspace' => $workspace,
            'frontend_app' => 'apps/missing',
            'write_evidence_kit' => false,
            'acceptance_criteria' => true,
        ]);

        $this->assertSame('blocked', $missing['status']);
        $this->assertSame('missing_subscope', data_get($missing, 'frontend_app_scope.status'));
        $this->assertContains('frontend_app_scope_frontend_app_subscope_directory_missing', $missing['blockers']);
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
