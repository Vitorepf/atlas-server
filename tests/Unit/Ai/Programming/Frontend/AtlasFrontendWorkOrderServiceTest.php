<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDossierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendWorkOrderService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendWorkOrderServiceTest extends TestCase
{
    public function test_ready_company_repo_work_order_emits_executable_packets(): void
    {
        $payload = app(AtlasFrontendWorkOrderService::class)->compile([
            'task' => 'Ajustar tela de dashboard SaaS com qualidade premium',
            'workspace' => $this->readyWorkspace(),
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
        ]);

        $this->assertSame(AtlasFrontendWorkOrderService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('company_frontend_execution_work_order', $payload['work_order_type']);
        $this->assertTrue((bool) data_get($payload, 'dispatch_policy.provider_dispatch_allowed'));
        $this->assertFalse((bool) data_get($payload, 'dispatch_policy.world_best_claim_allowed'));
        $this->assertContains('repo_context_lock', collect($payload['work_packets'])->pluck('id')->all());
        $this->assertContains('visual_quality_verification', collect($payload['work_packets'])->pluck('id')->all());
        $this->assertContains('certified_handoff', collect($payload['work_packets'])->pluck('id')->all());
        $this->assertContains('scenario_matrix', collect($payload['work_packets'])->firstWhere('id', 'visual_quality_verification')['required_evidence']);
        $this->assertContains('execute_packet_repo_context_lock', $payload['required_next_actions']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['work_order_hash']);
    }

    public function test_work_order_blocks_provider_dispatch_when_context_is_missing(): void
    {
        $payload = app(AtlasFrontendWorkOrderService::class)->compile([
            'task' => 'Criar redesign premium SaaS novo',
            'workspace' => sys_get_temp_dir().'/atlas-frontend-work-order-missing-'.bin2hex(random_bytes(4)),
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'dispatch_policy.provider_dispatch_allowed'));
        $this->assertContains('context_repair_before_provider_dispatch', collect($payload['work_packets'])->pluck('id')->all());
        $this->assertContains('fill_company_design_dossier_docs', $payload['required_next_actions']);
        $this->assertContains('confirm_frontend_workspace_or_create_package_manifest', $payload['required_next_actions']);
    }

    public function test_work_order_carries_frontend_app_scope_into_verification_packets(): void
    {
        $workspace = $this->readyWorkspace();
        File::ensureDirectoryExists($workspace.'/apps/web/src/pages');
        File::put($workspace.'/apps/web/package.json', json_encode([
            'scripts' => ['dev' => 'vite', 'test' => 'vitest run', 'build' => 'vite build'],
            'dependencies' => ['react' => '^latest', 'vite' => '^latest'],
        ], JSON_THROW_ON_ERROR));

        $payload = app(AtlasFrontendWorkOrderService::class)->compile([
            'task' => 'Ajustar checkout web com qualidade premium',
            'workspace' => $workspace,
            'frontend_app' => 'apps/web',
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
        ]);

        $this->assertSame('ready', $payload['status']);
        $this->assertSame('subscope_selected', data_get($payload, 'frontend_app_scope.status'));
        $this->assertSame('apps/web', data_get($payload, 'frontend_app_scope.relative_name'));

        $commands = implode("\n", collect($payload['work_packets'])->flatMap(fn (array $packet): array => $packet['commands'])->all());
        $this->assertStringContainsString('atlas:frontend:evidence-kit prepare --task="<brief>" --workspace=<local-company-repo> --frontend-app=apps/web', $commands);
        $this->assertStringContainsString('atlas:frontend:scenarios --task="<brief>" --workspace=<local-company-repo> --frontend-app=apps/web', $commands);
        $this->assertStringNotContainsString($workspace.'/apps/web', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function readyWorkspace(): string
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-work-order-ready-'.bin2hex(random_bytes(4));
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
            $lines[] = 'Approved frontend operating context for premium company software, including UX constraints, visual principles, measurable quality gates, and release evidence required before delivery.';
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }
}
