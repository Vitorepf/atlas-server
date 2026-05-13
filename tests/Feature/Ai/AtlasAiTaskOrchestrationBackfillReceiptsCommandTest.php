<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasTask;
use App\Models\AtlasTaskEvent;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAtlasTaskTables;
use Tests\TestCase;

class AtlasAiTaskOrchestrationBackfillReceiptsCommandTest extends TestCase
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

    public function test_command_dry_run_reports_repairs_without_writing(): void
    {
        $event = $this->legacyEvent();

        $exit = Artisan::call('atlas:ai:task-orchestration-backfill-receipts', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(1, data_get($payload, 'task_orchestration_backfill.repair_count'));
        $this->assertTrue(data_get($payload, 'task_orchestration_backfill.dry_run'));
        $this->assertSame(['legacy' => true], $event->refresh()->payload);
    }

    public function test_command_write_backfills_receipt_hash_and_safe_authority_flags(): void
    {
        $event = $this->legacyEvent();

        $exit = Artisan::call('atlas:ai:task-orchestration-backfill-receipts', [
            '--hours' => 24,
            '--write' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(1, data_get($payload, 'task_orchestration_backfill.repair_count'));
        $this->assertFalse(data_get($payload, 'task_orchestration_backfill.dry_run'));

        $event->refresh();
        $this->assertSame('atlas.task_orchestration.event.v1', data_get($event->payload, 'schema_version'));
        $this->assertSame('atlas.task_orchestration.local_event_receipt.v1', data_get($event->payload, 'orchestration_receipt.schema_version'));
        $this->assertSame(1, data_get($event->payload, 'event_sequence'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($event->payload, 'event_hash'));
        $this->assertFalse(data_get($event->payload, 'orchestration_receipt.provider_dispatch_allowed'));
        $this->assertFalse(data_get($event->payload, 'orchestration_receipt.runtime_execution_allowed'));
        $this->assertFalse(data_get($event->payload, 'orchestration_receipt.agent_control_plane_allowed'));
        $this->assertTrue(data_get($event->payload, 'orchestration_receipt.operator_review_required_for_external_execution'));
    }

    public function test_command_dry_run_is_idempotent_after_backfill_write(): void
    {
        $event = $this->legacyEvent();

        Artisan::call('atlas:ai:task-orchestration-backfill-receipts', [
            '--hours' => 24,
            '--write' => true,
            '--json' => true,
        ]);

        $writtenPayload = $event->refresh()->payload;
        $this->assertSame(1, data_get(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR), 'task_orchestration_backfill.repair_count'));

        $exit = Artisan::call('atlas:ai:task-orchestration-backfill-receipts', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(0, data_get($payload, 'task_orchestration_backfill.repair_count'));
        $this->assertSame($writtenPayload, $event->refresh()->payload);
    }

    private function legacyEvent(): AtlasTaskEvent
    {
        $task = AtlasTask::query()->create([
            'title' => 'Evento legado',
            'status' => 'open',
            'priority' => 'normal',
            'domain' => 'atlas',
            'metadata' => [],
        ]);

        return AtlasTaskEvent::query()->create([
            'task_id' => $task->id,
            'event_type' => 'created_from_project',
            'source' => 'projects.store',
            'payload' => ['legacy' => true],
            'occurred_at' => now(),
        ]);
    }
}
