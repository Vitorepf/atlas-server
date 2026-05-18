<?php

namespace Tests\Unit\Ai\ProgrammingRuntime\Telemetry;

use App\Models\AiProgrammingRuntimeTelemetryEvent;
use App\Services\Ai\ProgrammingRuntime\Telemetry\ProgrammingRuntimeTelemetryAggregator;
use App\Services\Ai\ProgrammingRuntime\Telemetry\ProgrammingRuntimeTelemetryCanon;
use App\Services\Ai\ProgrammingRuntime\Telemetry\ProgrammingRuntimeTelemetryRecorder;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class ProgrammingRuntimeTelemetryRecorderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelemetrySchema();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_programming_runtime_telemetry_events');
        parent::tearDown();
    }

    public function test_records_event_with_canonical_fields(): void
    {
        $recorder = app(ProgrammingRuntimeTelemetryRecorder::class);

        $event = $recorder->record([
            'event_name' => 'runtime_record_completed',
            'flow' => 'atlas_dev',
            'selected_core' => 'atlas_dev',
            'run_id' => 'run-abc',
            'rag_gate_status' => 'passed',
            'context_sufficiency' => 82,
            'execution_status' => 'passed',
            'test_status' => 'passed',
            'repair_attempt_count' => 0,
            'evidence_completeness' => 90,
            'certification_status' => 'passed',
            'blocker_count' => 0,
            'duration_ms' => 4200,
            'cost_estimate_usd' => 0.0123,
            'metadata' => ['source' => 'unit-test'],
        ]);

        $this->assertNotNull($event);
        $this->assertSame(ProgrammingRuntimeTelemetryCanon::EVENT_SCHEMA_VERSION, $event->schema_version);
        $this->assertSame('runtime_record_completed', $event->event_name);
        $this->assertSame('atlas_dev', $event->flow);
        $this->assertSame('atlas_dev', $event->selected_core);
        $this->assertSame(82, $event->context_sufficiency);
        $this->assertSame(4200, $event->duration_ms);
        $this->assertEqualsWithDelta(0.0123, (float) $event->cost_estimate_usd, 0.0001);
        $this->assertNotSame('', $event->event_hash);
    }

    public function test_redacts_secrets_from_metadata(): void
    {
        $recorder = app(ProgrammingRuntimeTelemetryRecorder::class);

        $event = $recorder->record([
            'event_name' => 'rag_sufficiency_evaluated',
            'flow' => 'atlas_debug',
            'metadata' => [
                'authorization_header' => 'Bearer sk-supersecret-payload-AAAAAAAAAA',
                'api_key' => 'sk-live-AAAABBBBCCCCDDDDEEEE',
                'session_token' => 'st-1234567890ABCDEF',
                'note' => 'Routine evaluation, no secret here.',
            ],
        ]);

        $this->assertNotNull($event);
        $metadata = $event->metadata;
        $this->assertIsArray($metadata);
        // Key-based redaction strips the whole value.
        $this->assertSame('[redacted]', $metadata['api_key'] ?? null);
        $this->assertSame('[redacted]', $metadata['session_token'] ?? null);
        $this->assertSame('[redacted]', $metadata['authorization_header'] ?? null);
        $encoded = json_encode($metadata);
        $this->assertIsString($encoded);
        // No plaintext secret survives in persisted metadata, regardless of redaction path.
        $this->assertStringNotContainsString('sk-supersecret-payload-AAAAAAAAAA', $encoded);
        $this->assertStringNotContainsString('sk-live-AAAABBBBCCCCDDDDEEEE', $encoded);
        $this->assertStringNotContainsString('st-1234567890ABCDEF', $encoded);
    }

    public function test_dedupes_identical_payloads_by_event_hash(): void
    {
        $recorder = app(ProgrammingRuntimeTelemetryRecorder::class);
        $payload = [
            'event_name' => 'flow_selected',
            'flow' => 'atlas_dev',
            'selected_core' => 'atlas_dev',
            'run_id' => 'run-dedupe',
            'execution_status' => 'in_progress',
        ];

        $first = $recorder->record($payload);
        $second = $recorder->record($payload);

        $this->assertSame($first->event_hash, $second->event_hash);
        $this->assertSame(1, AiProgrammingRuntimeTelemetryEvent::query()->count());
    }

    public function test_rejects_unknown_selected_core(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('programming_runtime_telemetry_invalid_selected_core');

        app(ProgrammingRuntimeTelemetryRecorder::class)->record([
            'event_name' => 'flow_selected',
            'selected_core' => 'atlas_unknown_core',
        ]);
    }

    public function test_normalises_unknown_enum_values_to_null(): void
    {
        $event = app(ProgrammingRuntimeTelemetryRecorder::class)->record([
            'event_name' => 'rag_sufficiency_evaluated',
            'rag_gate_status' => 'mystery_status',
            'execution_status' => 'wandering',
            'test_status' => 'unknown',
            'certification_status' => 'definitely_not_real',
        ]);

        $this->assertNotNull($event);
        $this->assertNull($event->rag_gate_status);
        $this->assertNull($event->execution_status);
        $this->assertNull($event->test_status);
        $this->assertNull($event->certification_status);
    }

    public function test_aggregate_buckets_events_by_flow_core_status_blocker_repair_evidence(): void
    {
        $recorder = app(ProgrammingRuntimeTelemetryRecorder::class);
        $recorder->record([
            'event_name' => 'runtime_record_completed',
            'flow' => 'atlas_dev',
            'selected_core' => 'atlas_dev',
            'run_id' => 'run-1',
            'execution_status' => 'passed',
            'rag_gate_status' => 'passed',
            'context_sufficiency' => 85,
            'evidence_completeness' => 88,
            'repair_attempt_count' => 0,
            'blocker_count' => 0,
            'certification_status' => 'passed',
        ]);
        $recorder->record([
            'event_name' => 'runtime_record_completed',
            'flow' => 'atlas_debug',
            'selected_core' => 'atlas_dev',
            'run_id' => 'run-2',
            'execution_status' => 'failed',
            'rag_gate_status' => 'failed_closed',
            'context_sufficiency' => 35,
            'evidence_completeness' => 45,
            'repair_attempt_count' => 2,
            'blocker_count' => 3,
            'certification_status' => 'blocked',
        ]);
        $recorder->record([
            'event_name' => 'forge_escalation_recorded',
            'flow' => 'atlas_forge',
            'selected_core' => 'atlas_forge',
            'run_id' => 'run-3',
            'execution_status' => 'escalated',
            'rag_gate_status' => 'passed',
            'context_sufficiency' => 72,
            'evidence_completeness' => 75,
            'repair_attempt_count' => 0,
            'blocker_count' => 0,
            'certification_status' => 'partial',
        ]);

        $aggregate = app(ProgrammingRuntimeTelemetryAggregator::class)->aggregate();

        $this->assertSame(3, $aggregate['total_events']);
        $this->assertSame(3, $aggregate['distinct_runs']);
        $this->assertSame(['atlas_debug' => 1, 'atlas_dev' => 1, 'atlas_forge' => 1], $aggregate['by_flow']);
        $this->assertSame(['atlas_dev' => 2, 'atlas_forge' => 1], $aggregate['by_core']);
        $this->assertArrayHasKey('passed', $aggregate['by_execution_status']);
        $this->assertArrayHasKey('failed', $aggregate['by_execution_status']);
        $this->assertArrayHasKey('escalated', $aggregate['by_execution_status']);
        $this->assertSame(['0' => 2, '3-5' => 1], $aggregate['by_blocker_bucket']);
        $this->assertSame(['0' => 2, '2-3' => 1], $aggregate['by_repair_count_bucket']);
        $this->assertArrayHasKey('80-100', $aggregate['by_evidence_completeness_bucket']);
        $this->assertArrayHasKey('40-59', $aggregate['by_evidence_completeness_bucket']);
        $this->assertArrayHasKey('60-79', $aggregate['by_evidence_completeness_bucket']);
        $this->assertSame(['blocked' => 1, 'partial' => 1, 'passed' => 1], $aggregate['by_certification_status']);
    }

    public function test_aggregate_always_reports_benchmark_not_run_true(): void
    {
        $emptyAggregate = app(ProgrammingRuntimeTelemetryAggregator::class)->aggregate();
        $this->assertTrue($emptyAggregate['claim_policy']['benchmark_not_run']);
        $this->assertFalse($emptyAggregate['claim_policy']['rivals_compared']);
        $this->assertFalse($emptyAggregate['claim_policy']['allows_external_superiority_claim']);

        app(ProgrammingRuntimeTelemetryRecorder::class)->record([
            'event_name' => 'flow_selected',
            'flow' => 'atlas_dev',
            'selected_core' => 'atlas_dev',
            'run_id' => 'flag-check',
            'execution_status' => 'passed',
        ]);

        $aggregate = app(ProgrammingRuntimeTelemetryAggregator::class)->aggregate();
        $this->assertTrue($aggregate['claim_policy']['benchmark_not_run']);
        $this->assertFalse($aggregate['claim_policy']['rivals_compared']);
        $this->assertFalse($aggregate['claim_policy']['allows_external_superiority_claim']);
    }

    public function test_aggregate_json_shape_is_stable_and_deterministic(): void
    {
        app(ProgrammingRuntimeTelemetryRecorder::class)->record([
            'event_name' => 'flow_selected',
            'flow' => 'atlas_dev',
            'selected_core' => 'atlas_dev',
            'run_id' => 'stable-1',
            'execution_status' => 'passed',
            'rag_gate_status' => 'passed',
            'context_sufficiency' => 85,
            'evidence_completeness' => 88,
            'certification_status' => 'passed',
            'duration_ms' => 1000,
            'blocker_count' => 0,
        ]);

        $first = app(ProgrammingRuntimeTelemetryAggregator::class)->aggregate();
        $second = app(ProgrammingRuntimeTelemetryAggregator::class)->aggregate();

        // top-level keys identical and sorted bucket maps identical
        $this->assertSame(array_keys($first), array_keys($second));
        $this->assertSame($first['by_flow'], $second['by_flow']);
        $this->assertSame($first['by_core'], $second['by_core']);
        $this->assertSame($first['by_execution_status'], $second['by_execution_status']);
        $this->assertSame($first['by_blocker_bucket'], $second['by_blocker_bucket']);
        $this->assertSame($first['by_repair_count_bucket'], $second['by_repair_count_bucket']);
    }

    public function test_returns_null_when_table_is_missing(): void
    {
        Schema::dropIfExists('ai_programming_runtime_telemetry_events');

        $result = app(ProgrammingRuntimeTelemetryRecorder::class)->record([
            'event_name' => 'flow_selected',
            'flow' => 'atlas_dev',
        ]);

        $this->assertNull($result);
    }

    public function test_aggregate_signals_missing_table_with_reason(): void
    {
        Schema::dropIfExists('ai_programming_runtime_telemetry_events');

        $aggregate = app(ProgrammingRuntimeTelemetryAggregator::class)->aggregate();

        $this->assertSame(0, $aggregate['total_events']);
        $this->assertSame('telemetry_table_missing', $aggregate['reason']);
        $this->assertTrue($aggregate['claim_policy']['benchmark_not_run']);
    }

    private function bootTelemetrySchema(): void
    {
        Schema::dropIfExists('ai_programming_runtime_telemetry_events');
        (require database_path('migrations/2026_05_19_040000_create_ai_programming_runtime_telemetry_events_table.php'))->up();
    }
}
