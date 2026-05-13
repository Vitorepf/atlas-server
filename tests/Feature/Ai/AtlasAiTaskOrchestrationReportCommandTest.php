<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasTask;
use App\Models\AtlasTaskEvent;
use App\Services\TaskPlanningService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAtlasTaskTables;
use Tests\TestCase;

class AtlasAiTaskOrchestrationReportCommandTest extends TestCase
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

    public function test_command_reports_task_lifecycle_receipts_without_execution_authority(): void
    {
        $task = AtlasTask::query()->create([
            'title' => 'Planejar orquestracao local',
            'description' => 'Sem provider.',
            'status' => 'open',
            'priority' => 'high',
            'domain' => 'atlas',
            'metadata' => [],
        ]);
        app(TaskPlanningService::class)->recordEvent($task, 'scheduled', [], 'tasks.schedule');

        $exit = Artisan::call('atlas:ai:task-orchestration-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.task_orchestration_report.v1', data_get($payload, 'task_orchestration.schema_version'));
        $this->assertSame('ok', data_get($payload, 'task_orchestration.status'));
        $this->assertSame(1, data_get($payload, 'task_orchestration.task_count'));
        $this->assertSame(1, data_get($payload, 'task_orchestration.event_count'));
        $this->assertSame(1, data_get($payload, 'task_orchestration.receipt_event_count'));
        $this->assertSame(1, data_get($payload, 'task_orchestration.hashed_event_count'));
        $this->assertSame(0, data_get($payload, 'task_orchestration.unsafe_event_receipt_count'));
        $this->assertSame('atlas.task_orchestration.handoff_contract.v1', data_get($payload, 'task_orchestration.handoff_contract.schema_version'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'task_orchestration.handoff_contract.contract_hash'));
        $this->assertFalse(data_get($payload, 'task_orchestration.handoff_contract.provider_dispatch_allowed_by_report'));
        $this->assertFalse(data_get($payload, 'task_orchestration.handoff_contract.runtime_execution_allowed_by_report'));
        $this->assertFalse(data_get($payload, 'task_orchestration.handoff_contract.agent_control_plane_allowed_by_report'));
        $this->assertFalse(data_get($payload, 'task_orchestration.handoff_contract.auto_completion_allowed_by_report'));
        $this->assertTrue(data_get($payload, 'task_orchestration.handoff_contract.operator_review_required_for_external_execution'));
        $this->assertContains('runtime_invocation_contract_when_runtime_is_needed', data_get($payload, 'task_orchestration.handoff_contract.required_before_provider_runtime_or_agent_handoff'));
        $this->assertFalse(data_get($payload, 'task_orchestration.writes'));
        $this->assertSame('continue_task_orchestration_monitoring', data_get($payload, 'task_orchestration.review_signal.recommended_action'));
    }

    public function test_command_warns_when_event_receipts_are_missing(): void
    {
        $task = AtlasTask::query()->create([
            'title' => 'Evento legado',
            'status' => 'open',
            'priority' => 'normal',
            'domain' => 'atlas',
            'metadata' => [],
        ]);
        AtlasTaskEvent::query()->create([
            'task_id' => $task->id,
            'event_type' => 'legacy',
            'source' => 'test',
            'payload' => ['legacy' => true],
            'occurred_at' => now(),
        ]);

        $exit = Artisan::call('atlas:ai:task-orchestration-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('warning', data_get($payload, 'task_orchestration.status'));
        $this->assertSame(1, data_get($payload, 'task_orchestration.missing_receipt_event_count'));
        $this->assertSame('refresh_task_events_through_task_planning_service', data_get($payload, 'task_orchestration.review_signal.recommended_action'));
    }

    public function test_command_treats_legacy_incomplete_receipt_as_refresh_warning_not_unsafe_authority(): void
    {
        $task = AtlasTask::query()->create([
            'title' => 'Evento legado parcial',
            'status' => 'open',
            'priority' => 'normal',
            'domain' => 'atlas',
            'metadata' => [],
        ]);
        AtlasTaskEvent::query()->create([
            'task_id' => $task->id,
            'event_type' => 'milestone',
            'source' => 'test',
            'payload' => [
                'schema_version' => 'atlas.task_orchestration.event.v1',
                'event_sequence' => 1,
                'event_hash' => str_repeat('b', 64),
                'orchestration_receipt' => [
                    'schema_version' => 'atlas.task_orchestration.local_event_receipt.v1',
                    'provider_dispatch_allowed' => false,
                    'runtime_execution_allowed' => false,
                    'policy_mutation_allowed' => false,
                    'auto_completion_allowed' => false,
                ],
            ],
            'occurred_at' => now(),
        ]);

        $exit = Artisan::call('atlas:ai:task-orchestration-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('warning', data_get($payload, 'task_orchestration.status'));
        $this->assertSame(0, data_get($payload, 'task_orchestration.unsafe_event_receipt_count'));
        $this->assertSame(1, data_get($payload, 'task_orchestration.incomplete_event_receipt_count'));
        $this->assertSame('refresh_task_events_through_task_planning_service', data_get($payload, 'task_orchestration.review_signal.recommended_action'));
    }

    public function test_local_engineering_contract_does_not_count_as_external_handoff_review(): void
    {
        AtlasTask::query()->create([
            'title' => 'Contrato tecnico local',
            'status' => 'open',
            'priority' => 'normal',
            'domain' => 'atlas',
            'execution_mode' => 'quick_win',
            'metadata' => [
                'engineering_contract' => [
                    'goal' => 'Validar contrato local sem provider handoff.',
                ],
            ],
        ]);

        $exit = Artisan::call('atlas:ai:task-orchestration-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(0, data_get($payload, 'task_orchestration.external_review_required_task_count'));
        $this->assertSame('continue_task_orchestration_monitoring', data_get($payload, 'task_orchestration.review_signal.recommended_action'));
    }

    public function test_provider_runtime_or_agent_handoff_modes_require_external_review(): void
    {
        AtlasTask::query()->create([
            'title' => 'Provider handoff pendente',
            'status' => 'open',
            'priority' => 'normal',
            'domain' => 'atlas',
            'execution_mode' => 'provider',
            'metadata' => [],
        ]);

        $exit = Artisan::call('atlas:ai:task-orchestration-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(1, data_get($payload, 'task_orchestration.external_review_required_task_count'));
        $this->assertSame('review_task_execution_receipts_before_provider_or_runtime_handoff', data_get($payload, 'task_orchestration.review_signal.recommended_action'));
    }

    public function test_command_warns_when_event_receipt_allows_external_authority(): void
    {
        $task = AtlasTask::query()->create([
            'title' => 'Evento inseguro',
            'status' => 'open',
            'priority' => 'normal',
            'domain' => 'atlas',
            'metadata' => [],
        ]);
        AtlasTaskEvent::query()->create([
            'task_id' => $task->id,
            'event_type' => 'milestone',
            'source' => 'test',
            'payload' => [
                'schema_version' => 'atlas.task_orchestration.event.v1',
                'event_sequence' => 1,
                'event_hash' => str_repeat('a', 64),
                'orchestration_receipt' => [
                    'schema_version' => 'atlas.task_orchestration.local_event_receipt.v1',
                    'provider_dispatch_allowed' => true,
                    'runtime_execution_allowed' => false,
                    'agent_control_plane_allowed' => false,
                    'policy_mutation_allowed' => false,
                    'auto_completion_allowed' => false,
                    'operator_review_required_for_external_execution' => true,
                ],
            ],
            'occurred_at' => now(),
        ]);

        $exit = Artisan::call('atlas:ai:task-orchestration-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('warning', data_get($payload, 'task_orchestration.status'));
        $this->assertSame(1, data_get($payload, 'task_orchestration.unsafe_event_receipt_count'));
        $this->assertSame('inspect_task_orchestration_event_receipts', data_get($payload, 'task_orchestration.review_signal.recommended_action'));
    }
}
