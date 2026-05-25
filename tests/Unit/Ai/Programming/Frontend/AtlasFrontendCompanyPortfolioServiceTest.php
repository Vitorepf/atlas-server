<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendCompanyPortfolioService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDossierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendSkillPackService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendCompanyPortfolioServiceTest extends TestCase
{
    public function test_scans_local_company_frontend_portfolio_without_raw_paths_or_source(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-portfolio-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($root);
        $ready = $this->workspace($root, 'blackink-web');
        $blocked = $this->workspace($root, 'raw-saas');
        $this->fillDesignDocs($ready);
        app(AtlasFrontendSkillPackService::class)->install(['workspace' => $ready]);

        $payload = app(AtlasFrontendCompanyPortfolioService::class)->scan([
            'root' => $root,
            'task' => 'Melhorar dashboard BlackInk',
            'max_depth' => 2,
        ]);

        $this->assertSame(AtlasFrontendCompanyPortfolioService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('optional_repository_discovery_only', $payload['inventory_role']);
        $this->assertSame('operator_selected_repository_workspace', $payload['primary_runtime_entrypoint']);
        $this->assertSame(2, data_get($payload, 'summary.candidate_repo_count'));
        $this->assertSame(1, data_get($payload, 'summary.ready_for_operator_execution_count'));
        $this->assertSame(1, data_get($payload, 'summary.blocked_count'));
        $this->assertSame('atlas.frontend.company_portfolio.task_binding.v1', data_get($payload, 'task_binding.schema_version'));
        $this->assertSame('task_bound_for_candidate_ranking', data_get($payload, 'task_binding.status'));
        $this->assertTrue((bool) data_get($payload, 'task_binding.task_present'));
        $this->assertTrue((bool) data_get($payload, 'task_binding.claim_policy.task_binding_guides_ranking_only'));
        $this->assertTrue((bool) data_get($payload, 'summary.selected_workspace_handoff_required'));
        $this->assertFalse((bool) data_get($payload, 'summary.portfolio_dispatch_allowed'));
        $this->assertFalse((bool) data_get($payload, 'scan_policy.raw_source_returned'));
        $this->assertFalse((bool) data_get($payload, 'scan_policy.absolute_paths_returned'));
        $this->assertFalse((bool) data_get($payload, 'scan_policy.executes_repo_commands'));
        $this->assertSame('local_folder_with_multiple_repositories', data_get($payload, 'scan_policy.discovery_model'));
        $this->assertContains('package.json', data_get($payload, 'scan_policy.repo_root_markers'));
        $this->assertContains('pnpm-workspace.yaml', data_get($payload, 'scan_policy.repo_root_markers'));
        $this->assertTrue((bool) data_get($payload, 'scan_policy.portfolio_root_is_not_selected_workspace'));
        $this->assertFalse((bool) data_get($payload, 'scan_policy.portfolio_scan_required_for_frontend_dispatch'));
        $this->assertTrue((bool) data_get($payload, 'scan_policy.selected_workspace_required_for_frontend_dispatch'));
        $this->assertSame('atlas.frontend.company_portfolio.selection_brief.v1', data_get($payload, 'selection_brief.schema_version'));
        $this->assertSame('ready_for_operator_choice', data_get($payload, 'selection_brief.status'));
        $this->assertSame('choose_one_candidate_then_run_selected_workspace', data_get($payload, 'selection_brief.operator_decision.action'));
        $this->assertStringContainsString('atlas:frontend:selected-workspace', (string) data_get($payload, 'selection_brief.operator_decision.command'));
        $this->assertFalse((bool) data_get($payload, 'selection_brief.claim_policy.provider_dispatch_allowed'));
        $this->assertTrue((bool) data_get($payload, 'selection_brief.claim_policy.selection_brief_is_not_runtime_scope'));
        $this->assertSame('blackink-web', data_get($payload, 'selection_brief.ranked_candidates.0.display_label'));
        $this->assertSame(1, data_get($payload, 'selection_brief.ranked_candidates.0.rank'));
        $this->assertSame('matched', data_get($payload, 'selection_brief.ranked_candidates.0.task_fit_status'));
        $this->assertGreaterThan(0, data_get($payload, 'selection_brief.ranked_candidates.0.task_fit_score'));
        $this->assertGreaterThan(0, data_get($payload, 'selection_brief.ranked_candidates.0.task_fit_signal_count'));
        $this->assertSame('candidate_not_selected', data_get($payload, 'selection_brief.ranked_candidates.0.selection_state'));
        $this->assertContains('ready_frontend_context_and_skill_pack', data_get($payload, 'selection_brief.ranked_candidates.0.decision_reasons'));
        $this->assertContains('run_selected_workspace_contract', data_get($payload, 'selection_brief.ranked_candidates.0.must_do_before_execution'));
        $this->assertSame('atlas.frontend.company_portfolio.selection_handoff.v1', data_get($payload, 'selection_handoff.schema_version'));
        $this->assertSame('candidate_ready_for_operator_selection', data_get($payload, 'selection_handoff.status'));
        $this->assertSame('atlas.frontend.selected_workspace.v1', data_get($payload, 'selection_handoff.selected_workspace_schema'));
        $this->assertStringContainsString('atlas:frontend:selected-workspace', (string) data_get($payload, 'selection_handoff.next_command'));
        $this->assertTrue((bool) data_get($payload, 'selection_handoff.claim_policy.portfolio_scan_only_discovers_repository_candidates'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.portfolio_root_is_not_selected_workspace'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.portfolio_candidate_is_not_selected_workspace'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.provider_dispatch_requires_selected_workspace_contract'));

        $repos = collect($payload['repositories'])->keyBy('repo_ref.relative_name');
        $this->assertContains('package.json', data_get($repos->get('blackink-web'), 'repo_ref.project_markers'));
        $this->assertSame('ready_for_operator_execution', data_get($repos->get('blackink-web'), 'status'));
        $this->assertSame('candidate_not_selected', data_get($repos->get('blackink-web'), 'selection_state'));
        $this->assertSame('matched', data_get($repos->get('blackink-web'), 'task_fit.status'));
        $this->assertSame(hash('sha256', 'Melhorar dashboard BlackInk'), data_get($repos->get('blackink-web'), 'task_fit.task_hash'));
        $this->assertFalse((bool) data_get($repos->get('blackink-web'), 'task_fit.raw_task_returned'));
        $this->assertGreaterThan(data_get($repos->get('raw-saas'), 'candidate_score'), data_get($repos->get('blackink-web'), 'candidate_score'));
        $this->assertFalse((bool) data_get($repos->get('blackink-web'), 'dispatch_policy.provider_dispatch_allowed'));
        $this->assertTrue((bool) data_get($repos->get('blackink-web'), 'dispatch_policy.requires_selected_workspace_contract'));
        $this->assertStringContainsString('atlas:frontend:selected-workspace', (string) data_get($repos->get('blackink-web'), 'selection_handoff.next_command'));
        $this->assertSame('blocked', data_get($repos->get('raw-saas'), 'status'));
        $this->assertContains('run_atlas_frontend_onboard_for_repo', data_get($repos->get('raw-saas'), 'recommended_next_actions'));
        $this->assertContains('choose_one_candidate_and_run_selected_workspace_contract', $payload['recommended_next_actions']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['portfolio_hash']);
        $this->assertStringNotContainsString('Melhorar dashboard BlackInk', json_encode($payload, JSON_THROW_ON_ERROR));

        unset($blocked);
    }

    public function test_portfolio_scan_keeps_monorepo_apps_as_subscope_not_separate_repositories(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-portfolio-monorepo-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($root);
        $workspace = $this->workspace($root, 'atlas-commerce');
        File::put($workspace.'/pnpm-workspace.yaml', 'packages: ["apps/*"]');
        File::ensureDirectoryExists($workspace.'/apps/web/src');
        File::put($workspace.'/apps/web/package.json', json_encode([
            'scripts' => ['dev' => 'vite', 'test' => 'vitest run', 'build' => 'vite build'],
            'dependencies' => ['react' => '^latest', 'vite' => '^latest'],
        ], JSON_THROW_ON_ERROR));

        $payload = app(AtlasFrontendCompanyPortfolioService::class)->scan([
            'root' => $root,
            'task' => 'Melhorar checkout do ecommerce',
            'max_depth' => 4,
        ]);

        $this->assertSame('ready', $payload['status']);
        $this->assertSame(1, data_get($payload, 'summary.candidate_repo_count'));
        $this->assertSame('atlas-commerce', data_get($payload, 'repositories.0.repo_ref.relative_name'));
        $this->assertContains('package.json', data_get($payload, 'repositories.0.repo_ref.project_markers'));
        $this->assertContains('pnpm-workspace.yaml', data_get($payload, 'repositories.0.repo_ref.project_markers'));
        $this->assertSame('candidate_not_selected', data_get($payload, 'repositories.0.selection_state'));
        $this->assertStringNotContainsString('apps/web', json_encode(data_get($payload, 'selection_brief.ranked_candidates'), JSON_THROW_ON_ERROR));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.portfolio_candidate_is_not_selected_workspace'));
        $this->assertTrue((bool) data_get($payload, 'selection_handoff.claim_policy.handoff_selects_one_repo_before_runtime'));
    }

    public function test_portfolio_scan_discovers_project_repositories_without_package_json(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-portfolio-polyrepo-'.bin2hex(random_bytes(4));
        $workspace = $root.'/atlas-laravel';
        File::ensureDirectoryExists($workspace);
        File::put($workspace.'/composer.json', json_encode(['require' => ['laravel/framework' => '^12.0']], JSON_THROW_ON_ERROR));
        File::put($workspace.'/artisan', '#!/usr/bin/env php');

        $payload = app(AtlasFrontendCompanyPortfolioService::class)->scan([
            'root' => $root,
            'task' => 'Criar dashboard administrativo',
            'max_depth' => 2,
        ]);

        $this->assertSame('ready', $payload['status']);
        $this->assertSame(1, data_get($payload, 'summary.candidate_repo_count'));
        $this->assertSame('atlas-laravel', data_get($payload, 'repositories.0.repo_ref.relative_name'));
        $this->assertContains('composer.json', data_get($payload, 'repositories.0.repo_ref.project_markers'));
        $this->assertContains('artisan', data_get($payload, 'repositories.0.repo_ref.project_markers'));
        $this->assertSame('blocked', data_get($payload, 'repositories.0.status'));
        $this->assertFalse((bool) data_get($payload, 'repositories.0.dispatch_policy.provider_dispatch_allowed'));
        $this->assertContains('confirm_frontend_workspace_or_create_package_manifest', data_get($payload, 'repositories.0.recommended_next_actions'));
    }

    public function test_portfolio_scan_does_not_treat_root_project_as_selected_workspace(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-portfolio-root-project-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($root);
        File::put($root.'/package.json', json_encode([
            'scripts' => ['dev' => 'vite', 'test' => 'vitest run', 'build' => 'vite build'],
            'dependencies' => ['react' => '^latest', 'vite' => '^latest'],
        ], JSON_THROW_ON_ERROR));

        $payload = app(AtlasFrontendCompanyPortfolioService::class)->scan([
            'root' => $root,
            'task' => 'Refinar frontend do repo aberto',
            'max_depth' => 2,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(0, data_get($payload, 'summary.candidate_repo_count'));
        $this->assertContains('no_package_json_candidates_found', $payload['blockers']);
        $this->assertTrue((bool) data_get($payload, 'scan_policy.portfolio_root_is_not_selected_workspace'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.portfolio_root_is_not_selected_workspace'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.provider_dispatch_requires_selected_workspace_contract'));
        $this->assertSame('operator_selected_repository_workspace', $payload['primary_runtime_entrypoint']);
    }

    public function test_blocks_missing_portfolio_root(): void
    {
        $payload = app(AtlasFrontendCompanyPortfolioService::class)->scan([
            'root' => sys_get_temp_dir().'/atlas-frontend-portfolio-missing-'.bin2hex(random_bytes(4)),
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('optional_repository_discovery_only', $payload['inventory_role']);
        $this->assertSame('operator_selected_repository_workspace', $payload['primary_runtime_entrypoint']);
        $this->assertSame('missing_task', data_get($payload, 'task_binding.status'));
        $this->assertFalse((bool) data_get($payload, 'summary.portfolio_dispatch_allowed'));
        $this->assertSame('no_candidates', data_get($payload, 'selection_brief.status'));
        $this->assertSame('no_candidates', data_get($payload, 'selection_handoff.status'));
        $this->assertContains('root_not_found', $payload['blockers']);
    }

    private function workspace(string $root, string $name): string
    {
        $workspace = $root.'/'.$name;
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

    private function fillDesignDocs(string $workspace): void
    {
        foreach (app(AtlasFrontendDesignDossierService::class)->requiredDocuments() as $definition) {
            File::ensureDirectoryExists(dirname($workspace.'/'.$definition['path']));
            File::put($workspace.'/'.$definition['path'], $this->filledDocument((string) $definition['title'], (array) $definition['sections']));
        }
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
