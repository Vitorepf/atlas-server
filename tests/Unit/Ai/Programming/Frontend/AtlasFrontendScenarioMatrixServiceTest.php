<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendScenarioMatrixService;
use Tests\TestCase;

class AtlasFrontendScenarioMatrixServiceTest extends TestCase
{
    public function test_saas_task_compiles_route_viewport_state_matrix(): void
    {
        $payload = app(AtlasFrontendScenarioMatrixService::class)->compile([
            'task' => 'Criar dashboard SaaS com login e estados de loading error success',
            'workspace' => '/tmp/acme',
            'acceptance_criteria' => true,
        ]);

        $this->assertSame(AtlasFrontendScenarioMatrixService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('route_viewport_state_visual_verification_matrix', $payload['matrix_type']);
        $this->assertGreaterThan(0, data_get($payload, 'coverage.scenario_count'));
        $this->assertContains('/dashboard', collect($payload['scenarios'])->pluck('route')->all());
        $this->assertContains('mobile', collect($payload['scenarios'])->pluck('viewport')->all());
        $this->assertContains('keyboard_focus', collect($payload['scenarios'])->pluck('state')->all());
        $this->assertFalse((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['scenario_matrix_hash']);
    }

    public function test_matrix_blocks_when_task_spec_needs_acceptance_context(): void
    {
        $payload = app(AtlasFrontendScenarioMatrixService::class)->compile([
            'task' => 'Fazer um frontend premium incrivel',
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('task_spec_not_ready', $payload['blockers']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.scenario_matrix_is_not_completion_evidence'));
    }

    public function test_matrix_carries_frontend_app_scope_without_changing_workspace(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-scenario-scope-'.bin2hex(random_bytes(4));
        mkdir($workspace.'/apps/web', 0777, true);

        $payload = app(AtlasFrontendScenarioMatrixService::class)->compile([
            'task' => 'Criar dashboard SaaS com login',
            'workspace' => $workspace,
            'frontend_app' => 'apps/web',
            'acceptance_criteria' => true,
        ]);

        $this->assertSame('ready', $payload['status']);
        $this->assertSame('subscope_selected', data_get($payload, 'frontend_app_scope.status'));
        $this->assertSame('apps/web', data_get($payload, 'frontend_app_scope.relative_name'));
        $this->assertTrue((bool) data_get($payload, 'frontend_app_scope.repo_workspace_remains_primary'));
        $this->assertStringNotContainsString($workspace.'/apps/web', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_matrix_blocks_invalid_frontend_app_scope(): void
    {
        $payload = app(AtlasFrontendScenarioMatrixService::class)->compile([
            'task' => 'Criar dashboard SaaS com login',
            'frontend_app' => '../secrets',
            'acceptance_criteria' => true,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('invalid_subscope', data_get($payload, 'frontend_app_scope.status'));
        $this->assertContains('frontend_app_scope_invalid_relative_frontend_app_subscope', $payload['blockers']);
    }
}
