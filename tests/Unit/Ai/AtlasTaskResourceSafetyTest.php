<?php

namespace Tests\Unit\Ai;

use App\Http\Resources\AtlasTaskEventResource;
use App\Http\Resources\AtlasTaskResource;
use App\Models\AtlasTask;
use App\Models\AtlasTaskEvent;
use Tests\TestCase;

final class AtlasTaskResourceSafetyTest extends TestCase
{
    public function test_task_resource_exposes_read_model_safety_without_authorizing_execution(): void
    {
        $task = new AtlasTask;
        $task->id = '00000000-0000-0000-0000-000000000901';
        $task->title = 'Implementar contrato de task';
        $task->status = 'open';
        $task->priority = 'high';
        $task->planning_status = 'planned';
        $task->source_capture_id = '00000000-0000-0000-0000-000000000902';
        $task->planned_for_date = now()->toImmutable();
        $task->metadata = [
            'engineering_contract' => ['goal' => 'Contrato auditavel'],
            'latest_engineering_run' => ['status' => 'needs_review'],
        ];

        $payload = (new AtlasTaskResource($task))->resolve();

        $this->assertSame('atlas.task_orchestration.task_safety.v1', data_get($payload, 'safety.schema_version'));
        $this->assertTrue(data_get($payload, 'safety.read_model_only'));
        $this->assertFalse(data_get($payload, 'safety.provider_dispatch_allowed'));
        $this->assertFalse(data_get($payload, 'safety.runtime_execution_allowed'));
        $this->assertFalse(data_get($payload, 'safety.policy_mutation_allowed'));
        $this->assertFalse(data_get($payload, 'safety.auto_complete_allowed'));
        $this->assertTrue(data_get($payload, 'safety.source_capture_linked'));
        $this->assertTrue(data_get($payload, 'safety.has_engineering_contract'));
        $this->assertTrue(data_get($payload, 'safety.has_latest_engineering_run'));
        $this->assertTrue(data_get($payload, 'safety.has_schedule'));
        $this->assertSame('open', data_get($payload, 'safety.status'));
        $this->assertSame('planned', data_get($payload, 'safety.planning_status'));
    }

    public function test_task_event_resource_exposes_hash_chain_safety_without_runtime_authority(): void
    {
        $event = new AtlasTaskEvent;
        $event->id = '00000000-0000-0000-0000-000000000903';
        $event->task_id = '00000000-0000-0000-0000-000000000901';
        $event->event_type = 'milestone';
        $event->source = 'open_brain_mcp';
        $event->payload = [
            'schema_version' => 'atlas.task_orchestration.event.v1',
            'event_sequence' => 2,
            'previous_event_hash' => str_repeat('a', 64),
            'event_hash' => str_repeat('b', 64),
            'provider_dispatched' => false,
            'runtime_executed' => false,
            'orchestration_receipt' => [
                'schema_version' => 'atlas.task_orchestration.local_event_receipt.v1',
                'provider_dispatch_allowed' => false,
                'runtime_execution_allowed' => false,
                'agent_control_plane_allowed' => false,
                'policy_mutation_allowed' => false,
                'auto_completion_allowed' => false,
                'operator_review_required_for_external_execution' => true,
            ],
        ];

        $payload = (new AtlasTaskEventResource($event))->resolve();

        $this->assertSame('atlas.task_orchestration.event_safety.v1', data_get($payload, 'safety.schema_version'));
        $this->assertTrue(data_get($payload, 'safety.audit_trail_event'));
        $this->assertTrue(data_get($payload, 'safety.payload_api_only'));
        $this->assertFalse(data_get($payload, 'safety.provider_dispatch_allowed'));
        $this->assertFalse(data_get($payload, 'safety.runtime_execution_allowed'));
        $this->assertFalse(data_get($payload, 'safety.policy_mutation_allowed'));
        $this->assertFalse(data_get($payload, 'safety.raw_provider_output_exposed'));
        $this->assertTrue(data_get($payload, 'safety.has_event_sequence'));
        $this->assertTrue(data_get($payload, 'safety.has_previous_event_hash'));
        $this->assertTrue(data_get($payload, 'safety.has_event_hash'));
        $this->assertTrue(data_get($payload, 'safety.has_local_orchestration_receipt'));
        $this->assertSame('atlas.task_orchestration.local_event_receipt.v1', data_get($payload, 'safety.local_receipt_schema_version'));
        $this->assertTrue(data_get($payload, 'safety.local_receipt_complete'));
        $this->assertFalse(data_get($payload, 'safety.unsafe_local_receipt'));
        $this->assertFalse(data_get($payload, 'safety.receipt_agent_control_plane_allowed'));
        $this->assertFalse(data_get($payload, 'safety.receipt_auto_completion_allowed'));
        $this->assertTrue(data_get($payload, 'safety.receipt_operator_review_required_for_external_execution'));
        $this->assertSame('milestone', data_get($payload, 'safety.event_type'));
        $this->assertSame('open_brain_mcp', data_get($payload, 'safety.source'));
    }
}
