<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendAppScope;
use App\Services\Ai\Programming\Frontend\AtlasFrontendTaskSpecCompilerService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendSurface;
use Tests\TestCase;

class AtlasFrontendTaskSpecCompilerServiceTest extends TestCase
{
    public function test_saas_dashboard_task_spec_compiles_routes_viewports_acceptance_and_gates(): void
    {
        $spec = app(AtlasFrontendTaskSpecCompilerService::class)->compile([
            'task' => 'Criar dashboard SaaS multiempresa com settings, design system, performance e live preview',
            'workspace' => '/tmp/acme',
            'acceptance' => true,
            'company_profile' => true,
            'live' => true,
        ]);

        $this->assertSame(AtlasFrontendTaskSpecCompilerService::SCHEMA_VERSION, $spec['schema_version']);
        $this->assertSame('ready', $spec['status']);
        $this->assertFalse((bool) $spec['raw_task_returned']);
        $this->assertContains('saas_frontend', $spec['task_types']);
        $this->assertContains('/dashboard', $spec['routes']);
        $this->assertContains('/settings', $spec['routes']);
        $this->assertContains('desktop', $spec['viewports']);
        $this->assertContains('tablet', $spec['viewports']);
        $this->assertContains('mobile', $spec['viewports']);
        $this->assertContains('preview_variant', $spec['states']);
        $this->assertContains('frontend_execution_gate', $spec['required_gates']);
        $this->assertContains('visual_quality_gate', $spec['required_gates']);
        $this->assertContains('design_system_drift_gate', $spec['required_gates']);
        $this->assertContains('live_source_patch_boundary_gate', $spec['required_gates']);
        $this->assertContains('source_patch_recovery_test', $spec['required_tests']);
        $this->assertSame('medium', $spec['ambiguity_level']);
        $this->assertSame('high', $spec['risk_level']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $spec['task_spec_hash']);
    }

    public function test_vague_frontend_task_blocks_execution_without_acceptance_context(): void
    {
        $spec = app(AtlasFrontendTaskSpecCompilerService::class)->compile([
            'task' => 'Deixar a tela mais bonita e premium para inúmeras empresas',
        ]);

        $this->assertSame('blocked', $spec['status']);
        $this->assertSame('high', $spec['ambiguity_level']);
        $this->assertFalse((bool) data_get($spec, 'execution_policy.allowed_to_execute'));
        $this->assertContains('acceptance_context_required', collect($spec['blockers'])->pluck('id')->all());
        $this->assertContains('design_direction_selection_recommended', collect($spec['warnings'])->pluck('id')->all());
    }

    public function test_frontend_surface_helper_preserves_default_for_blank_surface(): void
    {
        $this->assertSame('programming.frontend', AtlasFrontendSurface::DEFAULT);
        $this->assertSame('programming.frontend', AtlasFrontendSurface::fromInput([]));
        $this->assertSame('programming.frontend', AtlasFrontendSurface::fromInput(['surface' => '']));
        $this->assertSame('programming.frontend.mobile', AtlasFrontendSurface::fromInput(['surface' => ' programming.frontend.mobile ']));
    }

    public function test_frontend_app_scope_helper_normalizes_scope_and_comparison_key(): void
    {
        $scope = AtlasFrontendAppScope::normalize(['status' => 'subscope_selected', 'relative_name' => 'apps\\web']);

        $this->assertSame('subscope_selected', $scope['status']);
        $this->assertSame('apps/web', $scope['relative_name']);
        $this->assertSame(hash('sha256', 'apps/web'), $scope['relative_name_hash']);
        $this->assertTrue((bool) $scope['repo_workspace_remains_primary']);
        $this->assertSame('subscope_selected:'.hash('sha256', 'apps/web'), AtlasFrontendAppScope::key($scope));

        $withoutWorkspaceFlag = AtlasFrontendAppScope::normalize(['status' => 'subscope_selected', 'relative_name' => 'apps/web'], includeWorkspaceFlag: false);
        $this->assertArrayNotHasKey('repo_workspace_remains_primary', $withoutWorkspaceFlag);
    }

    public function test_frontend_app_scope_helper_resolves_requested_app_with_runtime_options(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-scope-helper-'.bin2hex(random_bytes(4));
        mkdir($workspace.'/apps/web', 0755, true);

        $scope = AtlasFrontendAppScope::fromRequestedApp($workspace, 'apps\\web', [
            'include_raw_absolute_path_returned' => true,
            'include_root_blockers' => true,
            'reject_double_slash' => true,
        ]);

        $this->assertSame('subscope_selected', $scope['status']);
        $this->assertSame('apps/web', $scope['relative_name']);
        $this->assertSame([], $scope['blockers']);
        $this->assertFalse((bool) $scope['raw_absolute_path_returned']);

        $missingWorkspace = AtlasFrontendAppScope::fromRequestedApp('', 'apps/web', [
            'workspace_required_status' => 'workspace_unavailable',
            'workspace_required_blocker' => 'workspace_required_to_validate_frontend_app_subscope',
            'include_root_blockers' => true,
        ]);

        $this->assertSame('workspace_unavailable', $missingWorkspace['status']);
        $this->assertContains('workspace_required_to_validate_frontend_app_subscope', $missingWorkspace['blockers']);
    }

    public function test_task_spec_carries_frontend_app_scope_without_changing_workspace(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-spec-monorepo-'.bin2hex(random_bytes(4));
        mkdir($workspace.'/apps/web', 0755, true);

        $spec = app(AtlasFrontendTaskSpecCompilerService::class)->compile([
            'task' => 'Ajustar checkout web',
            'workspace' => $workspace,
            'frontend_app' => 'apps/web',
            'acceptance' => true,
        ]);

        $this->assertSame('subscope_selected', data_get($spec, 'frontend_app_scope.status'));
        $this->assertSame('apps/web', data_get($spec, 'frontend_app_scope.relative_name'));
        $this->assertSame(hash('sha256', 'apps/web'), data_get($spec, 'frontend_app_scope.relative_name_hash'));
        $this->assertTrue((bool) data_get($spec, 'frontend_app_scope.repo_workspace_remains_primary'));
        $this->assertStringNotContainsString($workspace.'/apps/web', json_encode($spec, JSON_THROW_ON_ERROR));
    }
}
