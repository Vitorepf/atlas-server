<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDossierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendSelectedWorkspaceService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendSelectedWorkspaceServiceTest extends TestCase
{
    public function test_selected_repository_workspace_is_primary_frontend_entrypoint(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-selected-workspace-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace);
        File::put($workspace.'/pnpm-lock.yaml', 'lockfileVersion: 9.0');
        File::put($workspace.'/package.json', json_encode([
            'scripts' => [
                'dev' => 'vite --host 127.0.0.1',
                'test' => 'vitest run',
                'build' => 'vite build',
                'typecheck' => 'tsc --noEmit',
            ],
            'dependencies' => [
                'react' => '^latest',
                'vite' => '^latest',
            ],
        ], JSON_THROW_ON_ERROR));

        $payload = app(AtlasFrontendSelectedWorkspaceService::class)->resolve([
            'workspace' => $workspace,
            'selection_source' => 'atlas_code',
        ]);

        $this->assertSame(AtlasFrontendSelectedWorkspaceService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('selected', $payload['status']);
        $this->assertSame('operator_selected_repository_workspace', $payload['selection_type']);
        $this->assertSame('selected_repository', $payload['primary_entrypoint']);
        $this->assertFalse((bool) $payload['portfolio_scan_required']);
        $this->assertFalse((bool) $payload['parallel_project_runtime_required']);
        $this->assertSame('local_company_or_product_repo', $payload['workspace_mode']);
        $this->assertSame(basename($workspace), $payload['workspace_label']);
        $this->assertContains('package.json', $payload['project_markers']);
        $this->assertSame('atlas.frontend.selected_workspace.operating_summary.v1', data_get($payload, 'repo_operating_summary.schema_version'));
        $this->assertSame('pnpm', data_get($payload, 'repo_operating_summary.package_manager'));
        $this->assertSame('vite', data_get($payload, 'repo_operating_summary.framework.primary'));
        $this->assertSame(1, data_get($payload, 'repo_operating_summary.command_inventory.test_command_count'));
        $this->assertTrue((bool) data_get($payload, 'repo_operating_summary.claim_policy.operating_summary_is_not_completion_evidence'));
        $this->assertSame('atlas.frontend.selected_workspace.app_candidates.v1', data_get($payload, 'frontend_app_candidates.schema_version'));
        $this->assertSame('root_frontend_app_candidate', data_get($payload, 'frontend_app_candidates.status'));
        $this->assertSame('.', data_get($payload, 'frontend_app_candidates.primary_candidate_relative_name'));
        $this->assertFalse((bool) data_get($payload, 'frontend_app_candidates.operator_decision.required'));
        $this->assertTrue((bool) data_get($payload, 'frontend_app_candidates.claim_policy.selected_repository_remains_primary_workspace'));
        $this->assertSame('atlas.frontend.selected_workspace.dispatch_readiness.v1', data_get($payload, 'dispatch_readiness.schema_version'));
        $this->assertSame('needs_context', data_get($payload, 'dispatch_readiness.status'));
        $this->assertFalse((bool) data_get($payload, 'dispatch_readiness.runtime_projection_allowed'));
        $this->assertFalse((bool) data_get($payload, 'dispatch_readiness.provider_dispatch_allowed'));
        $this->assertContains('company_repo_onboarding_ready', data_get($payload, 'dispatch_readiness.provider_dispatch_requires'));
        $this->assertTrue((bool) data_get($payload, 'dispatch_readiness.claim_policy.provider_dispatch_requires_gate_and_onboarding'));
        $this->assertSame('atlas.frontend.selected_workspace.capability_readiness.v1', data_get($payload, 'capability_readiness.schema_version'));
        $this->assertSame('partial', data_get($payload, 'capability_readiness.status'));
        $this->assertSame('ready', $this->capabilityStatus($payload, 'local_preview'));
        $this->assertSame('ready', $this->capabilityStatus($payload, 'test_verification'));
        $this->assertSame('ready', $this->capabilityStatus($payload, 'build_verification'));
        $this->assertSame('ready', $this->capabilityStatus($payload, 'quality_verification'));
        $this->assertSame('missing', $this->capabilityStatus($payload, 'design_context'));
        $this->assertSame('missing', $this->capabilityStatus($payload, 'measured_evidence'));
        $this->assertTrue((bool) data_get($payload, 'capability_readiness.claim_policy.measured_evidence_required_for_completion'));
        $this->assertSame('atlas.frontend.selected_workspace.operator_start_panel.v1', data_get($payload, 'operator_start_panel.schema_version'));
        $this->assertSame('needs_context', data_get($payload, 'operator_start_panel.status'));
        $this->assertSame('atlas.frontend.selected_workspace.task_binding.v1', data_get($payload, 'task_binding.schema_version'));
        $this->assertSame('missing_task', data_get($payload, 'task_binding.status'));
        $this->assertFalse((bool) data_get($payload, 'task_binding.task_present'));
        $this->assertTrue((bool) data_get($payload, 'task_binding.required_for_provider_dispatch'));
        $this->assertSame('atlas.frontend.selected_workspace.next_best_action.v1', data_get($payload, 'next_best_action.schema_version'));
        $this->assertSame('bind_task_to_selected_repository', data_get($payload, 'next_best_action.id'));
        $this->assertSame(90, data_get($payload, 'next_best_action.priority'));
        $this->assertStringContainsString('atlas:frontend:selected-workspace', (string) data_get($payload, 'next_best_action.command'));
        $this->assertFalse((bool) data_get($payload, 'next_best_action.writes_only_when_command_is_explicit'));
        $this->assertSame('complete_frontend_context', data_get($payload, 'operator_start_panel.primary_action'));
        $this->assertContains('provider_dispatch', data_get($payload, 'operator_start_panel.disabled_actions'));
        $this->assertContains('design_context', data_get($payload, 'operator_start_panel.missing_capabilities'));
        $this->assertSame('missing', $this->badgeValue($payload, 'design'));
        $this->assertTrue((bool) data_get($payload, 'operator_start_panel.claim_policy.panel_is_navigation_not_evidence'));
        $this->assertContains('provider_instruction_packet_read_only_projection', $payload['attachable_runtime_components']);
        $this->assertContains('do_not_treat_parent_folder_scan_as_selected_repo', $payload['forbidden_assumptions']);
        $this->assertContains('run_atlas_frontend_onboarding_or_dev_forge_projection_for_selected_repo', $payload['required_next_actions']);
        $commands = implode("\n", collect($payload['recommended_command_sequence'])->pluck('command')->all());
        $this->assertStringContainsString('atlas:frontend:selected-workspace', $commands);
        $this->assertStringContainsString('atlas:frontend:onboard', $commands);
        $this->assertStringContainsString('atlas:frontend:provider-packet', $commands);
        $this->assertStringContainsString('atlas:frontend:runbook', $commands);
        $this->assertStringContainsString('atlas:frontend:evidence-kit prepare', $commands);
        $this->assertTrue((bool) data_get($payload, 'readiness.portfolio_scan_is_optional_inventory_only'));
        $this->assertFalse((bool) data_get($payload, 'readiness.atlas_frontend_runtime_projection_allowed'));
        $this->assertSame('partial', data_get($payload, 'readiness.capability_readiness_status'));
        $this->assertSame('needs_context', data_get($payload, 'readiness.operator_start_panel_status'));
        $this->assertSame('bind_task_to_selected_repository', data_get($payload, 'readiness.next_best_action_id'));
        $this->assertSame('root_frontend_app_candidate', data_get($payload, 'readiness.frontend_app_candidate_status'));
        $this->assertSame(1, data_get($payload, 'readiness.frontend_app_candidate_count'));
        $this->assertTrue((bool) data_get($payload, 'readiness.repo_operating_summary_available'));
        $this->assertFalse((bool) data_get($payload, 'readiness.write_performed'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.selected_repo_is_runtime_scope'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.portfolio_scan_is_not_primary_entrypoint'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.frontend_app_candidate_is_subscope_not_new_workspace'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.selected_repository_is_sufficient_for_frontend_dispatch'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.selection_check_is_not_delivery_evidence'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.provider_dispatch_requires_task_binding'));
        $this->assertArrayNotHasKey('workspace', $payload);
        $this->assertStringNotContainsString($workspace, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['selected_workspace_hash']);
    }

    public function test_missing_workspace_blocks_selected_repository_contract(): void
    {
        $payload = app(AtlasFrontendSelectedWorkspaceService::class)->resolve([
            'workspace' => sys_get_temp_dir().'/atlas-frontend-selected-missing-'.bin2hex(random_bytes(4)),
            'selection_source' => 'atlas_ai',
        ]);

        $this->assertSame('missing', $payload['status']);
        $this->assertSame('generic_or_missing_workspace', $payload['workspace_mode']);
        $this->assertContains('workspace_missing', $payload['blockers']);
        $this->assertContains('choose_existing_local_repository_workspace', $payload['required_next_actions']);
        $this->assertFalse((bool) data_get($payload, 'readiness.atlas_frontend_can_attach_repo_runtime'));
        $this->assertSame('blocked', data_get($payload, 'dispatch_readiness.status'));
        $this->assertContains('selected_repository_workspace_required', data_get($payload, 'dispatch_readiness.blockers'));
        $this->assertSame('blocked', data_get($payload, 'capability_readiness.status'));
        $this->assertSame('blocked', data_get($payload, 'operator_start_panel.status'));
        $this->assertSame('choose_repository', data_get($payload, 'operator_start_panel.primary_action'));
        $this->assertSame('choose_repository', data_get($payload, 'next_best_action.id'));
        $this->assertSame(100, data_get($payload, 'next_best_action.priority'));
        $this->assertContains('selected_repository_workspace_required', data_get($payload, 'next_best_action.requires'));
    }

    public function test_ready_selected_repository_allows_runtime_projection_without_provider_dispatch(): void
    {
        $workspace = $this->readyWorkspace();

        $payload = app(AtlasFrontendSelectedWorkspaceService::class)->resolve([
            'workspace' => $workspace,
            'selection_source' => 'atlas_ai',
        ]);

        $this->assertSame('selected', $payload['status']);
        $this->assertSame('ready_for_runtime_projection', data_get($payload, 'dispatch_readiness.status'));
        $this->assertTrue((bool) data_get($payload, 'dispatch_readiness.runtime_projection_allowed'));
        $this->assertFalse((bool) data_get($payload, 'dispatch_readiness.provider_dispatch_allowed'));
        $this->assertSame('attach_read_only_frontend_runtime_projection_then_run_onboarding_or_gate', data_get($payload, 'dispatch_readiness.next_action'));
        $this->assertTrue((bool) data_get($payload, 'readiness.atlas_frontend_runtime_projection_allowed'));
        $this->assertSame('runtime_capable', data_get($payload, 'capability_readiness.status'));
        $this->assertSame('ready', $this->capabilityStatus($payload, 'design_context'));
        $this->assertSame('ready', $this->capabilityStatus($payload, 'live_mode_candidate'));
        $this->assertSame('missing', $this->capabilityStatus($payload, 'measured_evidence'));
        $this->assertSame('ready_to_project_runtime', data_get($payload, 'operator_start_panel.status'));
        $this->assertSame('open_frontend_runtime_projection', data_get($payload, 'operator_start_panel.primary_action'));
        $this->assertSame('bind_task_to_selected_repository', data_get($payload, 'next_best_action.id'));
        $this->assertContains('provider_dispatch_until_gate_onboarding_packet_and_runbook_pass', data_get($payload, 'operator_start_panel.disabled_actions'));
    }

    public function test_selected_repository_binds_task_without_returning_raw_task_text(): void
    {
        $workspace = $this->readyWorkspace();
        $task = 'Refinar dashboard premium da BlackInk';

        $payload = app(AtlasFrontendSelectedWorkspaceService::class)->resolve([
            'task' => $task,
            'workspace' => $workspace,
            'selection_source' => 'atlas_code',
        ]);

        $this->assertSame('bound', data_get($payload, 'task_binding.status'));
        $this->assertTrue((bool) data_get($payload, 'task_binding.task_present'));
        $this->assertSame(hash('sha256', $task), data_get($payload, 'task_binding.task_hash'));
        $this->assertTrue((bool) data_get($payload, 'task_binding.claim_policy.raw_task_text_returned') === false);
        $this->assertSame('open_frontend_runtime_projection', data_get($payload, 'next_best_action.id'));
        $this->assertSame(70, data_get($payload, 'next_best_action.priority'));
        $this->assertStringContainsString('atlas:frontend:gauntlet', (string) data_get($payload, 'next_best_action.command'));
        $this->assertTrue((bool) data_get($payload, 'next_best_action.claim_policy.provider_dispatch_not_authorized_by_next_best_action'));
        $this->assertStringNotContainsString($task, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_selected_monorepo_recommends_frontend_app_candidate_without_creating_parallel_project_runtime(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-selected-monorepo-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace.'/apps/web/src/pages');
        File::put($workspace.'/package.json', json_encode([
            'private' => true,
            'workspaces' => ['apps/*'],
        ], JSON_THROW_ON_ERROR));
        File::put($workspace.'/pnpm-workspace.yaml', 'packages: ["apps/*"]');
        File::put($workspace.'/apps/web/package.json', json_encode([
            'scripts' => [
                'dev' => 'vite --host 127.0.0.1',
                'test' => 'vitest run',
                'build' => 'vite build',
            ],
            'dependencies' => [
                'react' => '^latest',
                'vite' => '^latest',
            ],
        ], JSON_THROW_ON_ERROR));
        File::put($workspace.'/apps/web/src/pages/Dashboard.tsx', 'export function Dashboard() { return <main />; }');

        $payload = app(AtlasFrontendSelectedWorkspaceService::class)->resolve([
            'task' => 'Refinar dashboard web',
            'workspace' => $workspace,
            'selection_source' => 'atlas_code',
        ]);

        $this->assertSame('selected', $payload['status']);
        $this->assertSame('selected_repository', $payload['primary_entrypoint']);
        $this->assertFalse((bool) $payload['parallel_project_runtime_required']);
        $this->assertSame('nested_frontend_app_candidate_recommended', data_get($payload, 'frontend_app_candidates.status'));
        $this->assertTrue((bool) data_get($payload, 'frontend_app_candidates.monorepo_like'));
        $this->assertSame('apps/web', data_get($payload, 'frontend_app_candidates.primary_candidate_relative_name'));
        $this->assertTrue((bool) data_get($payload, 'frontend_app_candidates.operator_decision.required'));
        $this->assertSame('confirm_frontend_app_candidate_inside_selected_repo', data_get($payload, 'frontend_app_candidates.operator_decision.action'));
        $this->assertSame('frontend_app_candidate_not_selected', data_get($payload, 'frontend_app_candidates.candidates.0.selection_state'));
        $this->assertContains('vite', data_get($payload, 'frontend_app_candidates.candidates.0.framework_signals'));
        $this->assertSame('confirm_frontend_app_candidate_inside_selected_repo', data_get($payload, 'next_best_action.id'));
        $this->assertSame(85, data_get($payload, 'next_best_action.priority'));
        $this->assertStringContainsString('--workspace=<selected-repo> --frontend-app=apps/web', (string) data_get($payload, 'next_best_action.command'));
        $this->assertStringContainsString('--workspace=<selected-repo> --frontend-app=apps/web', (string) data_get($payload, 'frontend_app_candidates.operator_decision.command'));
        $this->assertStringNotContainsString('selected-repo-or-frontend-app-subdir', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertTrue((bool) data_get($payload, 'frontend_app_candidates.claim_policy.frontend_app_candidate_is_subscope_not_new_project'));
        $this->assertStringNotContainsString($workspace, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_selected_monorepo_accepts_frontend_app_subscope_without_changing_workspace(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-selected-monorepo-subscope-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace.'/apps/web/src/pages');
        File::put($workspace.'/package.json', json_encode([
            'private' => true,
            'workspaces' => ['apps/*'],
        ], JSON_THROW_ON_ERROR));
        File::put($workspace.'/pnpm-workspace.yaml', 'packages: ["apps/*"]');
        File::put($workspace.'/apps/web/package.json', json_encode([
            'scripts' => [
                'dev' => 'vite --host 127.0.0.1',
                'test' => 'vitest run',
                'build' => 'vite build',
            ],
            'dependencies' => [
                'react' => '^latest',
                'vite' => '^latest',
            ],
        ], JSON_THROW_ON_ERROR));
        File::put($workspace.'/apps/web/src/pages/Dashboard.tsx', 'export function Dashboard() { return <main />; }');

        $payload = app(AtlasFrontendSelectedWorkspaceService::class)->resolve([
            'task' => 'Refinar dashboard web',
            'workspace' => $workspace,
            'frontend_app' => 'apps/web',
            'selection_source' => 'atlas_code',
        ]);

        $this->assertSame('selected_repository', $payload['primary_entrypoint']);
        $this->assertTrue((bool) data_get($payload, 'frontend_app_candidates.requested_candidate_selected'));
        $this->assertSame('frontend_app_candidate_selected', data_get($payload, 'frontend_app_candidates.candidates.0.selection_state'));
        $this->assertFalse((bool) data_get($payload, 'frontend_app_candidates.operator_decision.required'));
        $this->assertNull(data_get($payload, 'frontend_app_candidates.operator_decision.command'));
        $this->assertSame('subscope_selected', data_get($payload, 'confirmed_frontend_app_scope.status'));
        $this->assertSame('apps/web', data_get($payload, 'confirmed_frontend_app_scope.relative_name'));
        $this->assertTrue((bool) data_get($payload, 'confirmed_frontend_app_scope.claim_policy.selected_repository_remains_primary_workspace'));
        $commands = implode("\n", collect($payload['recommended_command_sequence'])->pluck('command')->all());
        $this->assertStringContainsString('atlas:frontend:onboard --task="<intent>" --workspace=<local-company-repo> --frontend-app=apps/web', $commands);
        $this->assertStringContainsString('atlas:frontend:provider-packet --task="<intent>" --workspace=<local-company-repo> --frontend-app=apps/web', $commands);
        $this->assertStringContainsString('atlas:frontend:runbook --task="<intent>" --workspace=<local-company-repo> --frontend-app=apps/web', $commands);
        $this->assertStringContainsString('atlas:frontend:evidence-kit prepare --task="<intent>" --workspace=<local-company-repo> --frontend-app=apps/web', $commands);
        $this->assertSame('complete_frontend_context', data_get($payload, 'next_best_action.id'));
        $this->assertStringContainsString('atlas:frontend:onboard --task="<intent>" --workspace=<local-company-repo> --frontend-app=apps/web', (string) data_get($payload, 'next_best_action.command'));
        $this->assertStringNotContainsString($workspace.'/apps/web', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_selected_monorepo_blocks_invalid_frontend_app_subscope_without_changing_workspace(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-selected-monorepo-invalid-subscope-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace.'/apps/web/src/pages');
        File::put($workspace.'/package.json', json_encode([
            'private' => true,
            'workspaces' => ['apps/*'],
        ], JSON_THROW_ON_ERROR));
        File::put($workspace.'/pnpm-workspace.yaml', 'packages: ["apps/*"]');
        File::put($workspace.'/apps/web/package.json', json_encode([
            'scripts' => [
                'dev' => 'vite --host 127.0.0.1',
                'test' => 'vitest run',
                'build' => 'vite build',
            ],
            'dependencies' => [
                'react' => '^latest',
                'vite' => '^latest',
            ],
        ], JSON_THROW_ON_ERROR));
        File::put($workspace.'/apps/web/src/pages/Dashboard.tsx', 'export function Dashboard() { return <main />; }');

        $payload = app(AtlasFrontendSelectedWorkspaceService::class)->resolve([
            'task' => 'Refinar dashboard web',
            'workspace' => $workspace,
            'frontend_app' => 'apps/mobile',
            'selection_source' => 'atlas_code',
        ]);

        $this->assertSame('selected', $payload['status']);
        $this->assertSame('selected_repository', $payload['primary_entrypoint']);
        $this->assertSame('requested_frontend_app_subscope_invalid', data_get($payload, 'frontend_app_candidates.status'));
        $this->assertSame('not_selected', data_get($payload, 'confirmed_frontend_app_scope.status'));
        $this->assertTrue((bool) data_get($payload, 'frontend_app_candidates.requested_candidate_invalid'));
        $this->assertFalse((bool) data_get($payload, 'frontend_app_candidates.requested_candidate_selected'));
        $this->assertSame('choose_valid_frontend_app_subscope_inside_selected_repo', data_get($payload, 'frontend_app_candidates.operator_decision.action'));
        $this->assertContains('requested_frontend_app_subscope_not_found', data_get($payload, 'frontend_app_candidates.operator_decision.blockers'));
        $this->assertSame('blocked', data_get($payload, 'dispatch_readiness.status'));
        $this->assertFalse((bool) data_get($payload, 'dispatch_readiness.runtime_projection_allowed'));
        $this->assertContains('requested_frontend_app_subscope_not_found', data_get($payload, 'dispatch_readiness.blockers'));
        $this->assertSame('choose_valid_frontend_app_subscope_inside_selected_repo', data_get($payload, 'dispatch_readiness.next_action'));
        $this->assertTrue((bool) data_get($payload, 'readiness.frontend_app_candidate_invalid'));
        $this->assertFalse((bool) data_get($payload, 'readiness.atlas_frontend_runtime_projection_allowed'));
        $this->assertSame('choose_valid_frontend_app_subscope_inside_selected_repo', data_get($payload, 'next_best_action.id'));
        $this->assertSame(95, data_get($payload, 'next_best_action.priority'));
        $this->assertStringContainsString('--workspace=<selected-repo> --frontend-app=apps/web', (string) data_get($payload, 'next_best_action.command'));
        $this->assertStringNotContainsString($workspace.'/apps/mobile', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function capabilityStatus(array $payload, string $id): ?string
    {
        return collect((array) data_get($payload, 'capability_readiness.capabilities', []))
            ->firstWhere('id', $id)['status'] ?? null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function badgeValue(array $payload, string $id): ?string
    {
        return collect((array) data_get($payload, 'operator_start_panel.badges', []))
            ->firstWhere('id', $id)['value'] ?? null;
    }

    private function readyWorkspace(): string
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-selected-ready-'.bin2hex(random_bytes(4));
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
            'dependencies' => [
                'react' => '^latest',
                'vite' => '^latest',
                'tailwindcss' => '^latest',
            ],
        ], JSON_THROW_ON_ERROR));
        File::put($workspace.'/index.html', '<div id="root"></div>');
        File::put($workspace.'/src/main.tsx', 'import React from "react";');
        File::put($workspace.'/src/pages/Dashboard.tsx', 'export function Dashboard() { return <main className="bg-primary" />; }');
        File::put($workspace.'/src/components/ui/Button.tsx', 'export function Button() { return <button className="bg-primary text-white" />; }');
        File::put($workspace.'/src/styles.css', ':root { --color-primary: #123456; --space-2: 8px; }');

        foreach (app(AtlasFrontendDesignDossierService::class)->requiredDocuments() as $definition) {
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
            $lines[] = 'Approved frontend operating context with UX constraints, design system rules, quality gates, and release evidence requirements.';
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }
}
