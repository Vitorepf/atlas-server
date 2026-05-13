<?php

namespace Tests\Feature;

use App\Http\Resources\AtlasTaskEventResource;
use App\Models\AtlasTask;
use App\Services\TaskPlanningService;
use Tests\Concerns\CreatesAtlasTaskTables;
use Tests\TestCase;

class TaskPlanningServiceEventReceiptTest extends TestCase
{
    use CreatesAtlasTaskTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAtlasTaskTables();
    }

    protected function tearDown(): void
    {
        $this->dropAtlasTaskTables();

        parent::tearDown();
    }

    public function test_record_event_persists_local_orchestration_receipt_and_hash_chain(): void
    {
        $task = AtlasTask::query()->create([
            'title' => 'Planejar execução governada',
            'description' => 'Evento deve ser auditável.',
            'status' => 'open',
            'priority' => 'high',
            'domain' => 'atlas',
            'estimated_minutes' => 45,
            'metadata' => [],
        ]);
        $planning = app(TaskPlanningService::class);

        $first = $planning->recordEvent($task, 'scheduled', [
            'planned_for_date' => '2026-05-13',
        ], 'tasks.schedule');
        $second = $planning->recordEvent($task, 'milestone', [
            'status' => 'ready_for_execution',
        ], 'tasks.orchestration');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame('atlas.task_orchestration.event.v1', data_get($first->payload, 'schema_version'));
        $this->assertSame(1, data_get($first->payload, 'event_sequence'));
        $this->assertNull(data_get($first->payload, 'previous_event_id'));
        $this->assertNull(data_get($first->payload, 'previous_event_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($first->payload, 'event_hash'));

        $this->assertSame(2, data_get($second->payload, 'event_sequence'));
        $this->assertSame($first->id, data_get($second->payload, 'previous_event_id'));
        $this->assertSame(data_get($first->payload, 'event_hash'), data_get($second->payload, 'previous_event_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($second->payload, 'event_hash'));
        $this->assertNotSame(data_get($first->payload, 'event_hash'), data_get($second->payload, 'event_hash'));

        $this->assertSame('atlas.task_orchestration.local_event_receipt.v1', data_get($second->payload, 'orchestration_receipt.schema_version'));
        $this->assertSame('open', data_get($second->payload, 'orchestration_receipt.task_status'));
        $this->assertSame('atlas', data_get($second->payload, 'orchestration_receipt.task_domain'));
        $this->assertFalse(data_get($second->payload, 'orchestration_receipt.source_capture_linked'));
        $this->assertFalse(data_get($second->payload, 'orchestration_receipt.provider_dispatch_allowed'));
        $this->assertFalse(data_get($second->payload, 'orchestration_receipt.runtime_execution_allowed'));
        $this->assertFalse(data_get($second->payload, 'orchestration_receipt.agent_control_plane_allowed'));
        $this->assertFalse(data_get($second->payload, 'orchestration_receipt.policy_mutation_allowed'));
        $this->assertFalse(data_get($second->payload, 'orchestration_receipt.auto_completion_allowed'));
        $this->assertTrue(data_get($second->payload, 'orchestration_receipt.operator_review_required_for_external_execution'));
    }

    public function test_task_event_resource_reports_hash_chain_safety_for_planning_events(): void
    {
        $task = AtlasTask::query()->create([
            'title' => 'Expor evento seguro',
            'status' => 'open',
            'priority' => 'normal',
            'domain' => 'atlas',
            'metadata' => [],
        ]);
        $planning = app(TaskPlanningService::class);
        $planning->recordEvent($task, 'scheduled', [], 'tasks.schedule');
        $event = $planning->recordEvent($task, 'deferred', [], 'tasks.defer');

        $payload = (new AtlasTaskEventResource($event))->resolve();

        $this->assertSame('atlas.task_orchestration.event_safety.v1', data_get($payload, 'safety.schema_version'));
        $this->assertTrue(data_get($payload, 'safety.has_event_sequence'));
        $this->assertTrue(data_get($payload, 'safety.has_previous_event_hash'));
        $this->assertTrue(data_get($payload, 'safety.has_event_hash'));
        $this->assertFalse(data_get($payload, 'safety.provider_dispatch_allowed'));
        $this->assertFalse(data_get($payload, 'safety.runtime_execution_allowed'));
    }
}
