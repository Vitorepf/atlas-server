<?php

namespace Tests\Unit\Ai\Kernel\Pipeline;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineContract;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineAuditService;
use App\Services\Ai\Kernel\Pipeline\PipelineInput;
use App\Services\Ai\Kernel\Pipeline\ScaffoldAtlasKernelPipeline;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KernelPipelineAuditServiceTest extends TestCase
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

    public function test_records_scaffold_execution_as_kernel_pipeline_event(): void
    {
        $result = app(ScaffoldAtlasKernelPipeline::class)->execute(PipelineInput::fromArray([
            'text' => 'audite scaffold',
            'surface_id' => 'atlas_cli',
            'operator_id' => 'tester',
            'hints' => [
                'domain' => 'programming',
                'flow' => 'programming.dev',
            ],
        ]));

        $event = app(KernelPipelineAuditService::class)->recordScaffoldExecution(
            result: $result,
            emitterStage: 'atlas.test.scaffold',
            emitterVersion: 'atlas.test.scaffold.v1',
        );

        $this->assertInstanceOf(AtlasLedgerEvent::class, $event);
        $this->assertSame(LedgerEventType::KernelPipelineAccepted->value, $event->event_type);
        $this->assertSame('atlas.test.scaffold', $event->emitter_stage);
        $this->assertSame('accepted', data_get($event->payload, 'status'));
        $this->assertSame('atlas_cli', data_get($event->payload, 'surface.surface_id'));
        $this->assertSame('programming.dev', data_get($event->payload, 'routing.flow'));
        $this->assertSame('input', data_get($event->payload, 'pipeline.stage_order.0'));
        $this->assertSame('output', data_get($event->payload, 'pipeline.stage_order.13'));
    }

    public function test_event_payload_is_safe_and_compact(): void
    {
        $result = app(ScaffoldAtlasKernelPipeline::class)->execute(PipelineInput::fromArray([
            'text' => 'nao vazar texto bruto',
            'surface_id' => 'atlas_api',
        ]));

        $event = app(KernelPipelineAuditService::class)->recordScaffoldExecution($result);
        $payload = app(KernelPipelineAuditService::class)->eventPayload($event);

        $this->assertSame([
            'event_id',
            'event_type',
            'envelope_id',
            'payload_hash',
        ], array_keys($payload));
        $this->assertSame(LedgerEventType::KernelPipelineAccepted->value, $payload['event_type']);
        $this->assertStringStartsWith('kernel_pipeline:pipe_', $payload['envelope_id']);
        $this->assertArrayNotHasKey('payload', $payload);
    }

    public function test_records_accepted_and_rejected_existing_plans_with_shared_context_defaults(): void
    {
        $plan = app(ScaffoldAtlasKernelPipeline::class)->plan(PipelineInput::fromArray([
            'text' => 'validar plano existente',
            'surface_id' => 'atlas_ai_chat',
            'operator_id' => 'tester',
        ]));

        $accepted = app(KernelPipelineAuditService::class)->recordAcceptedPlan($plan, [
            'emitter_stage' => 'atlas.ai_chat.kernel_pipeline_guard',
            'surface_contract' => KernelPipelineContract::requiredSurfaceContract('KernelPipelineDevPlanBuilder'),
        ]);
        $rejected = app(KernelPipelineAuditService::class)->recordRejectedPlan($plan, [
            'kernel_pipeline.stage_order must match the canonical kernel stage order.',
        ], [
            'emitter_stage' => 'atlas.ai_chat.kernel_pipeline_guard',
            'surface_contract' => KernelPipelineContract::requiredSurfaceContract('KernelPipelineDevPlanBuilder'),
        ]);

        $this->assertSame(LedgerEventType::KernelPipelineAccepted->value, $accepted->event_type);
        $this->assertSame(LedgerEventType::KernelPipelineRejected->value, $rejected->event_type);
        $this->assertSame('kernel_pipeline:'.$plan['pipeline_id'], $accepted->envelope_id);
        $this->assertSame($accepted->envelope_id, $rejected->envelope_id);
        $this->assertSame('accepted', data_get($accepted->payload, 'status'));
        $this->assertSame('rejected', data_get($rejected->payload, 'status'));
        $this->assertSame([
            'kernel_pipeline.stage_order must match the canonical kernel stage order.',
        ], data_get($rejected->payload, 'violations'));
        $this->assertSame('KernelPipelineDevPlanBuilder', data_get($accepted->payload, 'surface_contract.source'));
        $this->assertTrue(data_get($rejected->payload, 'surface_contract.provider_execution_blocked_until_runtime_migration'));
    }
}
