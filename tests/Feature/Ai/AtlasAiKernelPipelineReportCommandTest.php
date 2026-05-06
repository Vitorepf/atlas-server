<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiKernelPipelineReportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_command_summarizes_kernel_pipeline_window_as_json(): void
    {
        $this->recordKernelPipeline('01HKERNELPIPELINECMD000001', 'env_kernel_pipeline_cmd_a', LedgerEventType::KernelPipelineAccepted, 'accepted', 'atlas_cli_dev', 'one_shot');
        $this->recordKernelPipeline('01HKERNELPIPELINECMD000002', 'env_kernel_pipeline_cmd_b', LedgerEventType::KernelPipelineRejected, 'rejected', 'atlas_ai_chat', 'declared_dev_plan', ['stage_order_mismatch']);

        $exit = Artisan::call('atlas:ai:kernel-pipeline-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(24, $payload['hours']);
        $this->assertSame(2, data_get($payload, 'kernel_pipeline.kernel_pipeline_event_count'));
        $this->assertSame(2, data_get($payload, 'kernel_pipeline.envelope_count'));
        $this->assertSame(1, data_get($payload, 'kernel_pipeline.accepted_count'));
        $this->assertSame(1, data_get($payload, 'kernel_pipeline.rejected_count'));
        $this->assertSame('rejected', data_get($payload, 'kernel_pipeline.latest_status'));
        $this->assertTrue((bool) data_get($payload, 'kernel_pipeline.has_rejections'));
        $this->assertSame('breach', data_get($payload, 'kernel_pipeline.health.status'));
        $this->assertSame(0.5, data_get($payload, 'kernel_pipeline.health.rejection_rate'));
        $this->assertTrue((bool) data_get($payload, 'kernel_pipeline.health.review_required'));
        $this->assertSame('breach', data_get($payload, 'kernel_pipeline.review_signal.status'));
        $this->assertSame('high', data_get($payload, 'kernel_pipeline.review_signal.severity'));
        $this->assertSame('open_reviewable_kernel_pipeline_contract_proposal', data_get($payload, 'kernel_pipeline.review_signal.recommended_action'));
        $this->assertSame(['atlas_cli_dev' => 1, 'atlas_ai_chat' => 1], data_get($payload, 'kernel_pipeline.surface_counts'));
        $this->assertSame(['atlas.kernel.pipeline' => 2], data_get($payload, 'kernel_pipeline.emitter_stage_counts'));
        $this->assertSame(['KernelPipelineDevPlanBuilder' => 2], data_get($payload, 'kernel_pipeline.surface_contract_source_counts'));
        $this->assertSame(['stage_order_mismatch' => 1], data_get($payload, 'kernel_pipeline.violation_counts'));
    }

    public function test_command_filters_kernel_pipeline_report_as_json(): void
    {
        $this->recordKernelPipeline('01HKERNELPIPELINECMDFILTER1', 'env_kernel_pipeline_cmd_filter_a', LedgerEventType::KernelPipelineAccepted, 'accepted', 'atlas_cli_dev', 'one_shot');
        $this->recordKernelPipeline(
            '01HKERNELPIPELINECMDFILTER2',
            'env_kernel_pipeline_cmd_filter_b',
            LedgerEventType::KernelPipelineRejected,
            'rejected',
            'atlas_ai_chat',
            'declared_dev_plan',
            ['stage_order_mismatch'],
            'atlas.ai_worker.kernel_pipeline_runtime_guard',
        );

        $exit = Artisan::call('atlas:ai:kernel-pipeline-report', [
            '--hours' => 24,
            '--status' => 'rejected',
            '--surface' => 'atlas_ai_chat',
            '--input-mode' => 'declared_dev_plan',
            '--contract-source' => 'KernelPipelineDevPlanBuilder',
            '--emitter-stage' => 'atlas.ai_worker.kernel_pipeline_runtime_guard',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame([
            'status' => 'rejected',
            'surface_id' => 'atlas_ai_chat',
            'input_mode' => 'declared_dev_plan',
            'surface_contract_source' => 'KernelPipelineDevPlanBuilder',
            'emitter_stage' => 'atlas.ai_worker.kernel_pipeline_runtime_guard',
        ], $payload['filters']);
        $this->assertSame($payload['filters'], data_get($payload, 'kernel_pipeline.filters'));
        $this->assertSame(1, data_get($payload, 'kernel_pipeline.kernel_pipeline_event_count'));
        $this->assertSame(['atlas.ai_worker.kernel_pipeline_runtime_guard' => 1], data_get($payload, 'kernel_pipeline.emitter_stage_counts'));
        $this->assertSame('env_kernel_pipeline_cmd_filter_b', data_get($payload, 'kernel_pipeline.recent_events.0.envelope_id'));
    }

    public function test_command_human_output_includes_kernel_pipeline_review_signal(): void
    {
        $this->recordKernelPipeline('01HKERNELPIPELINECMDHUMAN01', 'env_kernel_pipeline_cmd_human', LedgerEventType::KernelPipelineRejected, 'rejected', 'atlas_ai_chat', 'declared_dev_plan', ['stage_order_mismatch']);

        $exit = Artisan::call('atlas:ai:kernel-pipeline-report', [
            '--hours' => 24,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Review signal', $output);
        $this->assertStringContainsString('Review severity', $output);
        $this->assertStringContainsString('Recommended action', $output);
        $this->assertStringContainsString('open_reviewable_kernel_pipeline_contract_proposal', $output);
    }

    public function test_command_reports_unavailable_when_ledger_table_is_missing(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $exit = Artisan::call('atlas:ai:kernel-pipeline-report', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('ledger_unavailable', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'kernel_pipeline.available'));
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
