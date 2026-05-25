<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDossierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendEnterpriseBootstrapService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendEnterpriseBootstrapServiceTest extends TestCase
{
    public function test_write_bootstrap_creates_design_docs_and_blueprint_before_provider_dispatch(): void
    {
        $workspace = $this->minimalFrontendWorkspace('atlas-frontend-enterprise-bootstrap-write');

        $payload = app(AtlasFrontendEnterpriseBootstrapService::class)->run([
            'task' => 'Criar um SaaS novo com design ultra premium',
            'workspace' => $workspace,
            'write' => true,
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
            'senior_design_review' => true,
        ]);

        $this->assertSame(AtlasFrontendEnterpriseBootstrapService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('new_saas_or_product_creation', $payload['company_work_mode']);
        $this->assertContains('design_dossier_template', data_get($payload, 'write_result.written_artifacts'));
        $this->assertContains('product_blueprint_document', data_get($payload, 'write_result.written_artifacts'));
        $this->assertFileExists($workspace.'/docs/design/product-experience-brief.md');
        $this->assertFileExists($workspace.'/docs/design/atlas-frontend-product-blueprint.json');
        $this->assertFalse((bool) data_get($payload, 'readiness.provider_dispatch_allowed'));
        $this->assertContains('fill_company_frontend_design_dossier', $payload['required_next_actions']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.template_docs_do_not_count_as_ready_context'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['enterprise_bootstrap_hash']);
    }

    public function test_ready_company_repo_bootstrap_allows_premium_execution_but_not_world_best_claim(): void
    {
        $workspace = $this->readyFrontendWorkspace('atlas-frontend-enterprise-bootstrap-ready');

        $payload = app(AtlasFrontendEnterpriseBootstrapService::class)->run([
            'task' => 'Refinar BlackInk com redesign premium de dashboard',
            'workspace' => $workspace,
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
            'senior_design_review' => true,
        ]);

        $this->assertSame('ready', $payload['status']);
        $this->assertSame('existing_company_blackink_refinement', $payload['company_work_mode']);
        $this->assertTrue((bool) data_get($payload, 'readiness.design_dossier_ready'));
        $this->assertTrue((bool) data_get($payload, 'readiness.repo_intake_ready'));
        $this->assertTrue((bool) data_get($payload, 'readiness.work_order_ready'));
        $this->assertTrue((bool) data_get($payload, 'readiness.provider_dispatch_allowed'));
        $this->assertTrue((bool) data_get($payload, 'readiness.premium_frontend_claim_allowed'));
        $this->assertFalse((bool) data_get($payload, 'readiness.world_best_claim_allowed'));
    }

    private function minimalFrontendWorkspace(string $prefix): string
    {
        $workspace = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace.'/src/pages');
        File::ensureDirectoryExists($workspace.'/src/components/ui');
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

        return $workspace;
    }

    private function readyFrontendWorkspace(string $prefix): string
    {
        $workspace = $this->minimalFrontendWorkspace($prefix);
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
            $lines[] = 'Approved operating context for a premium company frontend product, including business intent, UX expectations, design constraints, quality gates, and release evidence.';
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }
}
