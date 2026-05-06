<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiKernelPipelineReportApiTest extends TestCase
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

    public function test_kernel_pipeline_report_api_returns_window_summary(): void
    {
        $this->recordKernelPipeline('01HKERNELPIPELINEAPI000001', 'env_kernel_pipeline_api_a', LedgerEventType::KernelPipelineAccepted, 'accepted', 'atlas_cli_dev', 'one_shot');
        $this->recordKernelPipeline('01HKERNELPIPELINEAPI000002', 'env_kernel_pipeline_api_b', LedgerEventType::KernelPipelineRejected, 'rejected', 'atlas_ai_chat', 'declared_dev_plan', ['canonical_flow_hash_mismatch']);

        $response = $this->getJson('/ai/kernel-pipeline/report?hours=24', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('hours', 24)
            ->assertJsonPath('kernel_pipeline.available', true)
            ->assertJsonPath('kernel_pipeline.kernel_pipeline_event_count', 2)
            ->assertJsonPath('kernel_pipeline.envelope_count', 2)
            ->assertJsonPath('kernel_pipeline.accepted_count', 1)
            ->assertJsonPath('kernel_pipeline.rejected_count', 1)
            ->assertJsonPath('kernel_pipeline.latest_status', 'rejected')
            ->assertJsonPath('kernel_pipeline.has_rejections', true)
            ->assertJsonPath('kernel_pipeline.health.status', 'breach')
            ->assertJsonPath('kernel_pipeline.health.rejection_rate', 0.5)
            ->assertJsonPath('kernel_pipeline.health.review_required', true)
            ->assertJsonPath('kernel_pipeline.review_signal.status', 'breach')
            ->assertJsonPath('kernel_pipeline.review_signal.severity', 'high')
            ->assertJsonPath('kernel_pipeline.review_signal.recommended_action', 'open_reviewable_kernel_pipeline_contract_proposal');

        $this->assertSame(['atlas_cli_dev' => 1, 'atlas_ai_chat' => 1], $response->json('kernel_pipeline.surface_counts'));
        $this->assertSame(['atlas.kernel.pipeline' => 2], $response->json('kernel_pipeline.emitter_stage_counts'));
        $this->assertSame(['KernelPipelineDevPlanBuilder' => 2], $response->json('kernel_pipeline.surface_contract_source_counts'));
        $this->assertSame(['canonical_flow_hash_mismatch' => 1], $response->json('kernel_pipeline.violation_counts'));
    }

    public function test_kernel_pipeline_report_api_filters_window_summary(): void
    {
        $this->recordKernelPipeline('01HKERNELPIPELINEAPIFILTER1', 'env_kernel_pipeline_api_filter_a', LedgerEventType::KernelPipelineAccepted, 'accepted', 'atlas_cli_dev', 'one_shot');
        $this->recordKernelPipeline(
            '01HKERNELPIPELINEAPIFILTER2',
            'env_kernel_pipeline_api_filter_b',
            LedgerEventType::KernelPipelineRejected,
            'rejected',
            'atlas_ai_chat',
            'declared_dev_plan',
            ['canonical_flow_hash_mismatch'],
            'atlas.ai_worker.kernel_pipeline_runtime_guard',
        );

        $response = $this->getJson('/ai/kernel-pipeline/report?hours=24&status=rejected&surface=atlas_ai_chat&input_mode=declared_dev_plan&contract_source=KernelPipelineDevPlanBuilder&emitter_stage=atlas.ai_worker.kernel_pipeline_runtime_guard', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('filters.status', 'rejected')
            ->assertJsonPath('filters.surface_id', 'atlas_ai_chat')
            ->assertJsonPath('filters.input_mode', 'declared_dev_plan')
            ->assertJsonPath('filters.surface_contract_source', 'KernelPipelineDevPlanBuilder')
            ->assertJsonPath('filters.emitter_stage', 'atlas.ai_worker.kernel_pipeline_runtime_guard')
            ->assertJsonPath('kernel_pipeline.kernel_pipeline_event_count', 1)
            ->assertJsonPath('kernel_pipeline.envelope_count', 1)
            ->assertJsonPath('kernel_pipeline.latest_status', 'rejected');

        $this->assertSame(['atlas.ai_worker.kernel_pipeline_runtime_guard' => 1], $response->json('kernel_pipeline.emitter_stage_counts'));
        $this->assertSame('env_kernel_pipeline_api_filter_b', $response->json('kernel_pipeline.recent_events.0.envelope_id'));
    }

    public function test_kernel_pipeline_report_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/kernel-pipeline/report')
            ->assertUnauthorized();
    }

    public function test_kernel_pipeline_report_api_returns_service_unavailable_when_ledger_table_is_missing(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $this->getJson('/ai/kernel-pipeline/report', $this->headers)
            ->assertStatus(503)
            ->assertJsonPath('status', 'ledger_unavailable')
            ->assertJsonPath('kernel_pipeline.available', false);
    }

    /**
     * @param  array<int,string>  $violations
     */
    private function recordKernelPipeline(
        string $eventId,
        string $envelopeId,
        LedgerEventType $type,
        string $status,
        string $surfaceId,
        string $inputMode,
        array $violations = [],
        string $emitterStage = 'atlas.kernel.pipeline',
    ): void {
        AtlasLedgerEvent::query()->create([
            'event_id' => $eventId,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_kernel_pipeline_report',
            'operator_id' => 'operator_kernel_pipeline_report',
            'envelope_id' => $envelopeId,
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => $envelopeId,
            'causation_id' => null,
            'event_type' => $type->value,
            'emitter_stage' => $emitterStage,
            'emitter_version' => 'atlas.kernel.pipeline.v1',
            'payload' => [
                'status' => $status,
                'pipeline' => [
                    'pipeline_id' => 'atlas.run.kernel_pipeline.v1',
                    'schema_version' => 'atlas.kernel_pipeline.v1',
                    'mode' => 'scaffold',
                    'stage_count' => 10,
                    'canonical_flow_hash' => hash('sha256', 'kernel-pipeline-report-flow'),
                    'provider_execution_allowed' => false,
                    'runtime_execution_allowed' => false,
                ],
                'surface' => [
                    'surface_id' => $surfaceId,
                    'binding_surface' => $surfaceId === 'atlas_cli_dev' ? 'atlas:cli:dev' : 'atlas:ai:chat',
                    'command' => $surfaceId === 'atlas_cli_dev' ? 'atlas dev' : 'atlas:ai:chat --dev',
                    'input_mode' => $inputMode,
                ],
                'surface_contract' => [
                    'required' => true,
                    'source' => 'KernelPipelineDevPlanBuilder',
                    'surface_must_not_decide' => true,
                    'provider_execution_blocked_until_runtime_migration' => true,
                    'runtime_execution_blocked_until_runtime_migration' => true,
                ],
                'routing' => [
                    'domain' => 'programming',
                    'flow' => 'programming.dev',
                    'runtime' => 'scaffold',
                ],
                'violations' => $violations,
            ],
            'payload_hash' => hash('sha256', $eventId),
            'occurred_at' => now(),
        ]);
    }
}
