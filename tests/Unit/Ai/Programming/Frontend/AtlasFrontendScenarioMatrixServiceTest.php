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
}
