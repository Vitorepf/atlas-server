<?php

namespace Tests\Feature\Ai\ProgrammingRuntime;

use App\Models\AiJob;
use App\Models\AiProgrammingRuntimeTelemetryEvent;
use App\Services\Ai\AiWorker;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\ProgrammingRuntime\Telemetry\ProgrammingRuntimeTelemetryAggregator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Gate for ATLAS REDONDO SLICE 1: the native programming-repair loop
 * (AiWorker::recordLedgerEvent) must mirror its lifecycle events into the
 * dedicated Programming Runtime telemetry read-model, so the aggregate stops
 * reporting "0 repair runs" while the loop actually ran.
 */
class NativeRepairTelemetryMirrorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLedgerSchema();
        $this->bootTelemetrySchema();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_programming_runtime_telemetry_events');
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

    public function test_native_repair_ledger_event_mirrors_into_dedicated_read_model(): void
    {
        $job = $this->makeJob();

        // Real seam: the same private helper every repair emit routes through.
        $this->invokeRecordLedgerEvent(LedgerEventType::RepairInitiated, $job, [
            'quality_status' => 'failed',
            'current_iteration' => 1,
            'next_iteration' => 2,
            'max_iterations' => 3,
            'reason' => 'quality_gate_requested_repair',
        ]);

        // Producer + evidence: the dedicated read-model now has a real repair row.
        $event = AiProgrammingRuntimeTelemetryEvent::query()->first();
        $this->assertNotNull($event, 'repair loop must write to the dedicated telemetry read-model');
        $this->assertSame('repair_attempt_recorded', $event->event_name);
        $this->assertSame('atlas_dev', $event->selected_core);
        $this->assertSame('in_progress', $event->execution_status);
        $this->assertSame(1, $event->repair_attempt_count);
        $this->assertSame('REPAIR_INITIATED', data_get($event->metadata, 'ledger_event_type'));

        // The mirror lives on the REAL path: the ledger event was written too.
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::RepairInitiated->value,
        ]);

        // Consumer + outcome: the aggregate stops reporting 0 repair runs.
        $aggregate = app(ProgrammingRuntimeTelemetryAggregator::class)->aggregate();
        $this->assertGreaterThanOrEqual(1, $aggregate['total_events']);
        $this->assertSame(1, $aggregate['by_event_name']['repair_attempt_recorded'] ?? 0);
        $this->assertArrayHasKey('1', $aggregate['by_repair_count_bucket']);
    }

    public function test_completed_repair_maps_status_and_non_repair_event_is_ignored(): void
    {
        $job = $this->makeJob();

        // Terminal exhausted repair → 'failed'.
        $this->invokeRecordLedgerEvent(LedgerEventType::RepairCompleted, $job, [
            'repair_status' => 'exhausted',
            'current_iteration' => 3,
            'max_iterations' => 3,
        ]);
        // Non-repair lifecycle event → NO telemetry mirror (selective, no pollution).
        $this->invokeRecordLedgerEvent(LedgerEventType::ProviderCalled, $job, [
            'provider' => 'codex_cli',
        ]);

        $events = AiProgrammingRuntimeTelemetryEvent::query()->get();
        $this->assertCount(1, $events, 'only the repair lifecycle event is mirrored');
        $this->assertSame('failed', $events->first()->execution_status);
        $this->assertSame(3, $events->first()->repair_attempt_count);
    }

    private function makeJob(): AiJob
    {
        $job = new AiJob([
            'kind' => 'interaction',
            'status' => 'processing',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'implemente',
            'prompt' => 'prompt',
        ]);
        $job->id = (string) Str::uuid();
        $job->trace_id = (string) Str::uuid();

        return $job;
    }

    private function invokeRecordLedgerEvent(LedgerEventType $type, AiJob $job, array $payload): void
    {
        $worker = app(AiWorker::class);
        $method = new ReflectionMethod($worker, 'recordLedgerEvent');
        $method->setAccessible(true);
        $method->invoke($worker, $type, $job, null, $payload, 'worker-test');
    }

    private function bootTelemetrySchema(): void
    {
        Schema::dropIfExists('ai_programming_runtime_telemetry_events');
        (require database_path('migrations/2026_05_19_040000_create_ai_programming_runtime_telemetry_events_table.php'))->up();
    }

    private function bootLedgerSchema(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 32)->primary();
            $table->string('schema_version', 40)->default('atlas.ledger_event.v1');
            $table->string('tenant_id', 120)->index();
            $table->string('operator_id', 120)->index();
            $table->string('envelope_id', 80)->index();
            $table->string('receipt_id', 80)->nullable()->index();
            $table->uuid('trace_id')->nullable()->index();
            $table->string('correlation_id', 120)->index();
            $table->string('causation_id', 80)->nullable()->index();
            $table->string('event_type', 80)->index();
            $table->string('emitter_stage', 120)->index();
            $table->string('emitter_version', 80);
            $table->json('payload');
            $table->string('payload_hash', 64)->index();
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
        });
    }
}
