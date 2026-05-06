<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiPipelineApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
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
        $this->assertDatabaseCount('atlas_ledger_events', 0);
    }

    public function test_pipeline_api_can_return_scaffold_execution_results(): void
    {
        $response = $this->postJson('/ai/pipeline', [
            'text' => 'execute somente scaffold',
            'execute' => true,
            'surface_id' => 'atlas_app',
            'operator_id' => 'tester',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'executed_scaffold')
            ->assertJsonPath('pipeline.status', 'planned_scaffold')
            ->assertJsonPath('pipeline.dry_run', true)
            ->assertJsonPath('pipeline.provider_execution_attempted', false)
            ->assertJsonPath('pipeline.stage_results.0.stage', 'input')
            ->assertJsonPath('pipeline.stage_results.13.stage', 'output')
            ->assertJsonPath('pipeline.compliance_report.ok', true)
            ->assertJsonPath('ledger_event.event_type', LedgerEventType::KernelPipelineAccepted->value);

        $this->assertStringStartsWith('kernel_pipeline:pipe_', $response->json('ledger_event.envelope_id'));
        $this->assertDatabaseCount('atlas_ledger_events', 1);

        $event = AtlasLedgerEvent::query()->firstOrFail();
        $this->assertSame('atlas.ai_pipeline.scaffold', $event->emitter_stage);
        $this->assertSame('accepted', data_get($event->payload, 'status'));
        $this->assertSame('atlas_app', data_get($event->payload, 'surface.surface_id'));
        $this->assertSame('input', data_get($event->payload, 'pipeline.stage_order.0'));
        $this->assertSame('output', data_get($event->payload, 'pipeline.stage_order.13'));
    }

    public function test_pipeline_api_requires_atlas_token(): void
    {
        $this->postJson('/ai/pipeline', [
            'text' => 'sem token',
        ])->assertUnauthorized();
    }
}
