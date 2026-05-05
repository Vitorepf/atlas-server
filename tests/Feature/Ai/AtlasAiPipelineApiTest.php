<?php

namespace Tests\Feature\Ai;

use Tests\TestCase;

class AtlasAiPipelineApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    public function test_pipeline_api_returns_scaffold_plan_without_runtime_execution(): void
    {
        $response = $this->postJson('/ai/pipeline', [
            'text' => 'planeje uma tarefa de programacao',
            'surface_id' => 'atlas_app',
            'operator_id' => 'tester',
            'hints' => [
                'domain' => 'programming',
                'flow' => 'programming.dev',
                'secret_hint' => 'must_not_be_rendered',
            ],
            'metadata' => [
                'raw_prompt' => 'must_not_be_rendered',
            ],
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'planned_scaffold')
            ->assertJsonPath('pipeline.provider_execution_allowed', false)
            ->assertJsonPath('pipeline.runtime_execution_allowed', false)
            ->assertJsonPath('pipeline.input.surface_id', 'atlas_app')
            ->assertJsonPath('pipeline.input.safe_hints.domain', 'programming')
            ->assertJsonPath('pipeline.input.safe_hints.flow', 'programming.dev')
            ->assertJsonPath('pipeline.stages.0.stage', 'input')
            ->assertJsonPath('pipeline.stages.1.stage', 'operation_envelope')
            ->assertJsonPath('pipeline.stages.4.stage', 'decision_receipt')
            ->assertJsonPath('pipeline.stages.13.stage', 'output')
            ->assertJsonPath('compliance.ok', true);

        $this->assertIsString($response->json('pipeline.input.primary_text_hash'));
        $this->assertIsString($response->json('pipeline.input.hints_hash'));
        $this->assertArrayNotHasKey('secret_hint', $response->json('pipeline.input.safe_hints'));
        $this->assertSame(['raw_prompt'], $response->json('pipeline.input.metadata_keys'));
    }

    public function test_pipeline_api_can_return_scaffold_execution_results(): void
    {
        $this->postJson('/ai/pipeline', [
            'text' => 'execute somente scaffold',
            'execute' => true,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'executed_scaffold')
            ->assertJsonPath('pipeline.status', 'planned_scaffold')
            ->assertJsonPath('pipeline.dry_run', true)
            ->assertJsonPath('pipeline.provider_execution_attempted', false)
            ->assertJsonPath('pipeline.stage_results.0.stage', 'input')
            ->assertJsonPath('pipeline.stage_results.13.stage', 'output')
            ->assertJsonPath('pipeline.compliance_report.ok', true);
    }

    public function test_pipeline_api_requires_atlas_token(): void
    {
        $this->postJson('/ai/pipeline', [
            'text' => 'sem token',
        ])->assertUnauthorized();
    }
}
