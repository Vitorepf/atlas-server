<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendCompanyRepoOnboardingService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDossierService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendCompanyRepoOnboardingServiceTest extends TestCase
{
    public function test_ready_company_repo_onboarding_installs_skill_and_prepares_provider_execution(): void
    {
        $workspace = $this->readyWorkspace('atlas-frontend-onboarding-ready');

        $payload = app(AtlasFrontendCompanyRepoOnboardingService::class)->run([
            'task' => 'Refinar BlackInk com dashboard premium responsivo',
            'workspace' => $workspace,
            'provider' => 'codex_cli',
            'write' => true,
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
            'senior_design_review' => true,
        ]);

        $this->assertSame(AtlasFrontendCompanyRepoOnboardingService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready_for_operator_execution', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'readiness.skill_pack_installed'));
        $this->assertTrue((bool) data_get($payload, 'readiness.enterprise_bootstrap_ready'));
        $this->assertTrue((bool) data_get($payload, 'readiness.proof_pilot_ready'));
        $this->assertTrue((bool) data_get($payload, 'readiness.provider_dispatch_allowed'));
        $this->assertFalse((bool) data_get($payload, 'readiness.measured_evidence_present'));
        $this->assertFalse((bool) data_get($payload, 'readiness.world_best_claim_allowed'));
        $this->assertFileExists($workspace.'/.atlas/skills/atlas-frontend/SKILL.md');
        $this->assertFileExists($workspace.'/.atlas/frontend/onboarding-receipt.json');
        $this->assertContains('dispatch_provider_with_provider_packet_then_execute_runbook_and_collect_measured_evidence', $payload['required_next_actions']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['onboarding_hash']);
    }

    public function test_minimal_repo_onboarding_writes_docs_but_blocks_provider_dispatch_until_context_is_filled(): void
    {
        $workspace = $this->minimalWorkspace('atlas-frontend-onboarding-minimal');

        $payload = app(AtlasFrontendCompanyRepoOnboardingService::class)->run([
            'task' => 'Criar SaaS novo premium',
            'workspace' => $workspace,
            'write' => true,
            'write_docs' => true,
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
            'senior_design_review' => true,
        ]);

        $this->assertSame('prepared_needs_context', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'readiness.skill_pack_installed'));
        $this->assertFalse((bool) data_get($payload, 'readiness.provider_dispatch_allowed'));
        $this->assertContains('bootstrap_repo_company_design_dossier_not_ready', $payload['blockers']);
        $this->assertFileExists($workspace.'/.atlas/skills/atlas-frontend/SKILL.md');
        $this->assertFileExists($workspace.'/docs/design/product-experience-brief.md');
        $this->assertFileExists($workspace.'/.atlas/frontend/onboarding-receipt.json');
        $this->assertTrue((bool) data_get($payload, 'claim_policy.prepared_needs_context_is_not_ready_for_provider_dispatch'));
    }

    public function test_read_only_onboarding_projects_selected_repo_without_writing_skill_or_receipt(): void
    {
        $workspace = $this->readyWorkspace('atlas-frontend-onboarding-read-only');

        $payload = app(AtlasFrontendCompanyRepoOnboardingService::class)->run([
            'task' => 'Refinar BlackInk com dashboard premium responsivo',
            'workspace' => $workspace,
            'provider' => 'codex_cli',
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
            'senior_design_review' => true,
        ]);

        $this->assertSame('ready_for_operator_execution', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'write_requested'));
        $this->assertTrue((bool) data_get($payload, 'readiness.provider_dispatch_allowed'));
        $this->assertFalse((bool) data_get($payload, 'readiness.skill_pack_installed'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.read_only_onboarding_does_not_write_workspace'));
        $this->assertFileDoesNotExist($workspace.'/.atlas/skills/atlas-frontend/SKILL.md');
        $this->assertFileDoesNotExist($workspace.'/.atlas/frontend/onboarding-receipt.json');
    }

    public function test_write_onboarding_carries_frontend_app_scope_into_proof_pilot(): void
    {
        $workspace = $this->readyMonorepoWorkspace('atlas-frontend-onboarding-monorepo');

        $payload = app(AtlasFrontendCompanyRepoOnboardingService::class)->run([
            'task' => 'Refinar app web premium no monorepo',
            'workspace' => $workspace,
            'frontend_app' => 'apps/web',
            'provider' => 'codex_cli',
            'write' => true,
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
            'senior_design_review' => true,
        ]);

        $this->assertSame('ready_for_operator_execution', $payload['status']);
        $this->assertSame('subscope_selected', data_get($payload, 'frontend_app_scope.status'));
        $this->assertSame('apps/web', data_get($payload, 'frontend_app_scope.relative_name'));
        $this->assertSame(hash('sha256', 'apps/web'), data_get($payload, 'frontend_app_scope.relative_name_hash'));
        $this->assertFileExists($workspace.'/.atlas/frontend/onboarding-receipt.json');
    }

    public function test_read_only_onboarding_projects_frontend_app_scope_without_writing_workspace(): void
    {
        $workspace = $this->readyMonorepoWorkspace('atlas-frontend-onboarding-read-only-monorepo');

        $payload = app(AtlasFrontendCompanyRepoOnboardingService::class)->run([
            'task' => 'Refinar app web premium no monorepo',
            'workspace' => $workspace,
            'frontend_app' => 'apps/web',
            'provider' => 'codex_cli',
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
            'senior_design_review' => true,
        ]);

        $this->assertSame('ready_for_operator_execution', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'write_requested'));
        $this->assertSame('subscope_selected', data_get($payload, 'frontend_app_scope.status'));
        $this->assertSame('apps/web', data_get($payload, 'frontend_app_scope.relative_name'));
        $this->assertFileDoesNotExist($workspace.'/.atlas/frontend/onboarding-receipt.json');
    }

    public function test_onboarding_blocks_missing_workspace(): void
    {
        $payload = app(AtlasFrontendCompanyRepoOnboardingService::class)->run([
            'task' => 'Refinar interface',
            'workspace' => sys_get_temp_dir().'/atlas-frontend-onboarding-missing-'.bin2hex(random_bytes(4)),
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('bootstrap_dossier_workspace_missing', $payload['blockers']);
        $this->assertContains('bootstrap_repo_workspace_not_found', $payload['blockers']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.read_only_onboarding_does_not_write_workspace'));
    }

    private function readyWorkspace(string $prefix): string
    {
        $workspace = $this->minimalWorkspace($prefix);
        foreach (app(AtlasFrontendDesignDossierService::class)->requiredDocuments() as $definition) {
            File::ensureDirectoryExists(dirname($workspace.'/'.$definition['path']));
            File::put($workspace.'/'.$definition['path'], $this->filledDocument((string) $definition['title'], (array) $definition['sections']));
        }

        return $workspace;
    }

    private function readyMonorepoWorkspace(string $prefix): string
    {
        $workspace = $this->readyWorkspace($prefix);
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

    private function minimalWorkspace(string $prefix): string
    {
        $workspace = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(4));
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
