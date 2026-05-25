<?php

namespace Tests\Feature\Ai\Programming\Forge;

use App\Models\AiForgeWorkPacket;
use App\Services\Ai\Programming\Forge\Intelligence\ForgeSpecialistWorkcellRouterService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDossierService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ForgeFrontendWorkcellRuntimeTest extends TestCase
{
    public function test_surface_ui_work_packet_receives_atlas_frontend_runtime_contract(): void
    {
        $packet = new AiForgeWorkPacket([
            'packet_id' => 'wp-frontend-001',
            'title' => 'Implement frontend SaaS dashboard',
            'objective' => 'Criar UI multiempresa com design system, live variants e visual smoke',
            'scope' => 'React frontend components',
            'risk_band' => 'high',
        ]);

        $route = app(ForgeSpecialistWorkcellRouterService::class)->route($packet);

        $this->assertSame('surface_ui', $route['workcell']);
        $this->assertSame('atlas.frontend.design_runtime_contract.v1', data_get($route, 'atlas_frontend_runtime.schema_version'));
        $this->assertSame('atlas.frontend.execution_gate.v1', data_get($route, 'atlas_frontend_pre_execution_gate.schema_version'));
        $this->assertSame('blocked', data_get($route, 'atlas_frontend_pre_execution_gate.status'));
        $this->assertFalse((bool) data_get($route, 'atlas_frontend_pre_execution_gate.claim_policy.provider_dispatch_allowed'));
        $this->assertContains('visual_smoke_multi_viewport', data_get($route, 'atlas_frontend_runtime.required_gates', []));
        $this->assertContains('anti_ai_slop_detector', data_get($route, 'atlas_frontend_runtime.required_capabilities', []));
        $this->assertContains('atlas_frontend_runtime_contract_attached', $route['route_reasons']);
        $this->assertContains('atlas_frontend_pre_execution_gate_attached', $route['route_reasons']);
        $this->assertContains('atlas_frontend_enterprise_operating_contract_attached', $route['route_reasons']);
        $this->assertContains('atlas_frontend_provider_instruction_packet_attached', $route['route_reasons']);
        $this->assertSame('atlas.forge.frontend_enterprise_operating_contract.v1', data_get($route, 'atlas_frontend_enterprise_operating_contract.schema_version'));
        $this->assertSame('generic_or_missing_workspace', data_get($route, 'atlas_frontend_enterprise_operating_contract.workspace_mode'));
        $this->assertSame('missing', data_get($route, 'atlas_frontend_enterprise_operating_contract.selected_workspace_status'));
        $this->assertSame('atlas.frontend.selected_workspace.v1', data_get($route, 'atlas_frontend_selected_workspace.schema_version'));
        $this->assertFalse((bool) data_get($route, 'atlas_frontend_selected_workspace.portfolio_scan_required'));
        $this->assertTrue((bool) $route['requires_human_review']);
    }

    public function test_surface_ui_work_packet_with_local_repo_receives_enterprise_bootstrap_and_runbook(): void
    {
        $workspace = $this->readyFrontendWorkspace();
        $packet = new AiForgeWorkPacket([
            'packet_id' => 'wp-frontend-002',
            'title' => 'Refinar frontend premium da BlackInk',
            'objective' => 'Criar dashboard SaaS responsivo com evidencia visual e handoff empresarial',
            'scope' => 'React frontend components',
            'expected_files' => [$workspace.'/apps/web/src/pages/Dashboard.tsx'],
            'acceptance_criteria' => ['dashboard responsive states approved'],
            'required_evidence' => ['visual smoke screenshots', 'quality budget report', 'handoff receipt'],
            'suggested_tests' => ['pnpm run test', 'pnpm run build'],
            'risk_band' => 'high',
        ]);

        $route = app(ForgeSpecialistWorkcellRouterService::class)->route($packet);

        $this->assertSame('surface_ui', $route['workcell']);
        $this->assertSame('local_company_or_product_repo', data_get($route, 'atlas_frontend_enterprise_operating_contract.workspace_mode'));
        $this->assertSame('atlas.frontend.selected_workspace.v1', data_get($route, 'atlas_frontend_enterprise_operating_contract.selected_workspace_schema'));
        $this->assertSame('selected', data_get($route, 'atlas_frontend_enterprise_operating_contract.selected_workspace_status'));
        $this->assertSame('ready_for_runtime_projection', data_get($route, 'atlas_frontend_enterprise_operating_contract.selected_workspace_dispatch_readiness_status'));
        $this->assertTrue((bool) data_get($route, 'atlas_frontend_enterprise_operating_contract.runtime_projection_allowed'));
        $this->assertSame('ready', data_get($route, 'atlas_frontend_enterprise_operating_contract.runtime_projection_status'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($route, 'atlas_frontend_enterprise_operating_contract.hash_refs.selected_workspace_hash'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($route, 'atlas_frontend_enterprise_operating_contract.hash_refs.runtime_projection_hash'));
        $this->assertSame('nested_frontend_app_candidate_recommended', data_get($route, 'atlas_frontend_enterprise_operating_contract.frontend_app_candidate_status'));
        $this->assertSame(2, data_get($route, 'atlas_frontend_enterprise_operating_contract.frontend_app_candidate_count'));
        $this->assertFalse((bool) data_get($route, 'atlas_frontend_enterprise_operating_contract.frontend_app_candidate_confirmation_required'));
        $this->assertSame('subscope_selected', data_get($route, 'atlas_frontend_enterprise_operating_contract.frontend_app_scope_status'));
        $this->assertSame(hash('sha256', 'apps/web'), data_get($route, 'atlas_frontend_enterprise_operating_contract.frontend_app_scope_hash'));
        $this->assertSame('subscope_selected', data_get($route, 'atlas_frontend_enterprise_operating_contract.onboarding_frontend_app_scope_status'));
        $this->assertSame(hash('sha256', 'apps/web'), data_get($route, 'atlas_frontend_enterprise_operating_contract.onboarding_frontend_app_scope_hash'));
        $this->assertSame('ready_to_project_runtime', data_get($route, 'atlas_frontend_enterprise_operating_contract.operator_start_panel_status'));
        $this->assertSame('open_frontend_runtime_projection', data_get($route, 'atlas_frontend_enterprise_operating_contract.operator_primary_action'));
        $this->assertSame('open_frontend_runtime_projection', data_get($route, 'atlas_frontend_enterprise_operating_contract.next_best_action'));
        $this->assertSame(70, data_get($route, 'atlas_frontend_enterprise_operating_contract.next_best_action_priority'));
        $this->assertSame('bound', data_get($route, 'atlas_frontend_enterprise_operating_contract.task_binding_status'));
        $this->assertTrue((bool) data_get($route, 'atlas_frontend_enterprise_operating_contract.task_bound'));
        $this->assertFalse((bool) data_get($route, 'atlas_frontend_enterprise_operating_contract.selected_workspace_provider_dispatch_allowed'));
        $this->assertSame('ready_for_operator_execution', data_get($route, 'atlas_frontend_enterprise_operating_contract.onboarding_status'));
        $this->assertSame('ready', data_get($route, 'atlas_frontend_enterprise_operating_contract.bootstrap_status'));
        $this->assertSame('ready', data_get($route, 'atlas_frontend_enterprise_operating_contract.runbook_status'));
        $this->assertSame('ready', data_get($route, 'atlas_frontend_enterprise_operating_contract.provider_packet_status'));
        $this->assertTrue((bool) data_get($route, 'atlas_frontend_enterprise_operating_contract.provider_dispatch_allowed'));
        $this->assertTrue((bool) data_get($route, 'atlas_frontend_enterprise_operating_contract.premium_frontend_claim_allowed'));
        $this->assertFalse((bool) data_get($route, 'atlas_frontend_enterprise_operating_contract.world_best_claim_allowed'));
        $this->assertSame('atlas.frontend.selected_workspace.v1', data_get($route, 'atlas_frontend_selected_workspace.schema_version'));
        $this->assertSame('selected_repository', data_get($route, 'atlas_frontend_selected_workspace.primary_entrypoint'));
        $this->assertSame('ready_for_runtime_projection', data_get($route, 'atlas_frontend_selected_workspace.dispatch_readiness.status'));
        $this->assertSame('ready_to_project_runtime', data_get($route, 'atlas_frontend_selected_workspace.operator_start_panel.status'));
        $this->assertSame('nested_frontend_app_candidate_recommended', data_get($route, 'atlas_frontend_selected_workspace.frontend_app_candidates.status'));
        $this->assertTrue((bool) data_get($route, 'atlas_frontend_selected_workspace.frontend_app_candidates.requested_candidate_selected'));
        $this->assertSame('bound', data_get($route, 'atlas_frontend_selected_workspace.task_binding.status'));
        $this->assertSame('open_frontend_runtime_projection', data_get($route, 'atlas_frontend_selected_workspace.next_best_action.id'));
        $this->assertSame('ready', data_get($route, 'atlas_frontend_selected_workspace.frontend_runtime_projection.status'));
        $this->assertSame(
            data_get($route, 'atlas_frontend_selected_workspace.frontend_runtime_projection.runtime_projection_hash'),
            data_get($route, 'atlas_frontend_enterprise_operating_contract.hash_refs.runtime_projection_hash')
        );
        $this->assertFalse((bool) data_get($route, 'atlas_frontend_selected_workspace.portfolio_scan_required'));
        $this->assertFalse((bool) data_get($route, 'atlas_frontend_selected_workspace.parallel_project_runtime_required'));
        $this->assertArrayNotHasKey('space_runtime'.'_required', $route['atlas_frontend_selected_workspace']);
        $this->assertSame('atlas.frontend.enterprise_bootstrap.v1', data_get($route, 'atlas_frontend_enterprise_bootstrap.schema_version'));
        $this->assertSame('atlas.frontend.company_repo_onboarding.v1', data_get($route, 'atlas_frontend_company_repo_onboarding.schema_version'));
        $this->assertSame('atlas.frontend.execution_runbook.v1', data_get($route, 'atlas_frontend_execution_runbook.schema_version'));
        $this->assertSame('atlas.frontend.provider_instruction_packet.v1', data_get($route, 'atlas_frontend_provider_instruction_packet.schema_version'));
        $this->assertSame('ready', data_get($route, 'atlas_frontend_enterprise_bootstrap.status'));
        $this->assertSame('ready_for_operator_execution', data_get($route, 'atlas_frontend_company_repo_onboarding.status'));
        $this->assertFalse((bool) data_get($route, 'atlas_frontend_company_repo_onboarding.write_requested'));
        $this->assertSame('subscope_selected', data_get($route, 'atlas_frontend_company_repo_onboarding.frontend_app_scope.status'));
        $this->assertSame('apps/web', data_get($route, 'atlas_frontend_company_repo_onboarding.frontend_app_scope.relative_name'));
        $this->assertSame(hash('sha256', 'apps/web'), data_get($route, 'atlas_frontend_company_repo_onboarding.frontend_app_scope.relative_name_hash'));
        $this->assertSame('ready', data_get($route, 'atlas_frontend_execution_runbook.status'));
        $this->assertSame('subscope_selected', data_get($route, 'atlas_frontend_execution_runbook.frontend_app_scope.status'));
        $this->assertFalse((bool) data_get($route, 'atlas_frontend_execution_runbook.write_evidence_kit'));
        $this->assertTrue((bool) data_get($route, 'atlas_frontend_execution_runbook.claim_policy.read_only_runbook_does_not_write_evidence_kit'));
        $this->assertSame('ready', data_get($route, 'atlas_frontend_provider_instruction_packet.status'));
        $this->assertSame('subscope_selected', data_get($route, 'atlas_frontend_provider_instruction_packet.frontend_app_scope.status'));
        $this->assertTrue((bool) data_get($route, 'atlas_frontend_provider_instruction_packet.claim_policy.read_only_packet_does_not_write_evidence_kit'));
        $commands = implode("\n", collect(data_get($route, 'atlas_frontend_execution_runbook.runbook_steps', []))->flatMap(fn (array $step): array => $step['commands'])->all());
        $this->assertStringContainsString("cd 'apps/web' && pnpm install --frozen-lockfile", $commands);
        $this->assertStringContainsString('atlas:frontend:run-certify', $commands);
        $this->assertContains('never_claim_world_best_or_done_without_certified_evidence', data_get($route, 'atlas_frontend_provider_instruction_packet.provider_mandates'));
        $this->assertContains('atlas_frontend_selected_workspace_attached', $route['route_reasons']);
        $this->assertContains('atlas_frontend_company_repo_onboarding_attached', $route['route_reasons']);
        $this->assertFileDoesNotExist($workspace.'/.atlas/skills/atlas-frontend/SKILL.md');
    }

    private function readyFrontendWorkspace(): string
    {
        $workspace = sys_get_temp_dir().'/atlas-forge-frontend-workcell-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace.'/src/components/ui');
        File::ensureDirectoryExists($workspace.'/src/pages');
        File::ensureDirectoryExists($workspace.'/apps/web/src/pages');
        File::put($workspace.'/pnpm-lock.yaml', 'lockfileVersion: 9.0');
        $packageJson = json_encode([
            'scripts' => [
                'dev' => 'vite --host 127.0.0.1',
                'test' => 'vitest run',
                'build' => 'vite build',
                'typecheck' => 'tsc --noEmit',
            ],
            'dependencies' => ['react' => '^latest', 'vite' => '^latest', 'tailwindcss' => '^latest'],
        ], JSON_THROW_ON_ERROR);
        File::put($workspace.'/package.json', $packageJson);
        File::put($workspace.'/apps/web/package.json', $packageJson);
        File::put($workspace.'/index.html', '<div id="root"></div>');
        File::put($workspace.'/src/main.tsx', 'import React from "react";');
        File::put($workspace.'/src/pages/Dashboard.tsx', 'export function Dashboard() { return <main className="bg-primary" />; }');
        File::put($workspace.'/apps/web/src/pages/Dashboard.tsx', 'export function Dashboard() { return <main className="bg-primary" />; }');
        File::put($workspace.'/src/components/ui/Button.tsx', 'export function Button() { return <button className="bg-primary text-white" />; }');
        File::put($workspace.'/src/styles.css', ':root { --color-primary: #123456; --space-2: 8px; }');

        foreach (app(AtlasFrontendDesignDossierService::class)->requiredDocuments() as $definition) {
            File::ensureDirectoryExists(dirname($workspace.'/'.$definition['path']));
            File::put($workspace.'/'.$definition['path'], $this->filledFrontendDocument((string) $definition['title'], (array) $definition['sections']));
        }

        return $workspace;
    }

    /**
     * @param  array<int,string>  $sections
     */
    private function filledFrontendDocument(string $title, array $sections): string
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
