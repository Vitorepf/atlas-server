<?php

namespace Tests\Feature\Ai\ProgrammingRuntime;

use App\Models\AiProgrammingRuntimeTelemetryEvent;
use App\Services\Ai\Compounding\AtlasCompoundingRuntimeService;
use App\Services\Ai\ProgrammingRuntime\Telemetry\ProgrammingRuntimeTelemetryAggregator;
use App\Services\Ai\ProgrammingRuntime\Telemetry\ProgrammingRuntimeTelemetryRecorder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiProgrammingRuntimeTelemetryCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCompoundingSchema();
        $this->bootTelemetrySchema();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_programming_runtime_telemetry_events');
        $this->dropCompoundingSchema();
        parent::tearDown();
    }

    public function test_aggregate_command_outputs_stable_json_with_benchmark_not_run_flag(): void
    {
        app(ProgrammingRuntimeTelemetryRecorder::class)->record([
            'event_name' => 'flow_selected',
            'flow' => 'atlas_dev',
            'selected_core' => 'atlas_dev',
            'run_id' => 'cmd-run',
            'execution_status' => 'passed',
            'context_sufficiency' => 80,
            'evidence_completeness' => 90,
            'certification_status' => 'passed',
            'blocker_count' => 0,
        ]);

        $exitCode = Artisan::call('atlas:ai:programming-runtime-telemetry', [
            '--action' => 'aggregate',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame('atlas.programming.runtime_telemetry.aggregate.v1', $decoded['schema_version']);
        $this->assertSame(1, $decoded['total_events']);
        $this->assertTrue($decoded['claim_policy']['benchmark_not_run']);
        $this->assertFalse($decoded['claim_policy']['rivals_compared']);
        $this->assertSame(['atlas_dev' => 1], $decoded['by_flow']);
        $this->assertSame(['atlas_dev' => 1], $decoded['by_core']);
    }

    public function test_record_command_returns_event_payload(): void
    {
        $exitCode = Artisan::call('atlas:ai:programming-runtime-telemetry', [
            '--action' => 'record',
            '--event-name' => 'flow_selected',
            '--flow' => 'atlas_dev',
            '--core' => 'atlas_dev',
            '--execution-status' => 'passed',
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['recorded']);
        $this->assertSame('flow_selected', $decoded['event']['event_name']);
        $this->assertTrue($decoded['benchmark_not_run']);
        $this->assertSame(1, AiProgrammingRuntimeTelemetryEvent::query()->count());
    }

    public function test_record_command_accepts_cost_and_token_signals(): void
    {
        $exitCode = Artisan::call('atlas:ai:programming-runtime-telemetry', [
            '--action' => 'record',
            '--event-name' => 'l5_7_cost_probe',
            '--flow' => 'loop_auto_merge',
            '--core' => 'atlas_dev',
            '--provider' => 'codex_cli',
            '--run-id' => 'l5-7-test',
            '--execution-status' => 'passed',
            '--duration-ms' => '1234',
            '--tokens-in' => '100',
            '--tokens-out' => '50',
            '--cost-estimate-usd' => '0.12',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['recorded']);
        $this->assertSame('l5_7_cost_probe', $decoded['event']['event_name']);
        $this->assertEqualsWithDelta(0.12, (float) $decoded['event']['cost_estimate_usd'], 0.000001);

        $event = AiProgrammingRuntimeTelemetryEvent::query()->firstOrFail();
        $this->assertSame('loop_auto_merge', $event->flow);
        $this->assertSame('atlas_dev', $event->selected_core);
        $this->assertSame('l5-7-test', $event->run_id);
        $this->assertSame('passed', $event->execution_status);
        $this->assertSame(1234, $event->duration_ms);
        $this->assertEqualsWithDelta(0.12, (float) $event->cost_estimate_usd, 0.000001);
    }

    public function test_record_command_fails_without_event_name(): void
    {
        $exitCode = Artisan::call('atlas:ai:programming-runtime-telemetry', [
            '--action' => 'record',
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_compounding_runtime_emits_runtime_record_completed_telemetry(): void
    {
        app(AtlasCompoundingRuntimeService::class)->recordExecution([
            'run_id' => 'emit-1',
            'flow_id' => 'atlas_dev',
            'outcome_status' => 'passed',
            'flow_quality' => 90,
            'retrieval_quality' => 85,
            'execution_quality' => 92,
            'evidence_quality' => 88,
            'evidence_refs' => ['receipt:emit-1'],
            'test_status' => 'passed',
            'duration_ms' => 4200,
            'cost_estimate_usd' => 0.0123,
            'learning_signal' => [
                'claim' => 'Telemetry emission test.',
                'confidence' => 86,
                'evidence_refs' => ['receipt:emit-1'],
            ],
            'rag_feedback' => [
                'retrieval_receipt_id' => 'retr-emit-1',
                'included_sources' => 5,
                'used_sources' => 5,
                'noise_sources' => 0,
                'missed_required_sources' => [],
                'context_sufficiency' => 88,
                'post_execution_utility' => 85,
            ],
        ]);

        $event = AiProgrammingRuntimeTelemetryEvent::query()
            ->where('event_name', 'runtime_record_completed')
            ->where('run_id', 'emit-1')
            ->firstOrFail();

        $this->assertSame('atlas_dev', $event->flow);
        $this->assertSame('atlas_dev', $event->selected_core);
        $this->assertSame('passed', $event->execution_status);
        $this->assertSame('passed', $event->test_status);
        $this->assertSame('passed', $event->rag_gate_status);
        $this->assertSame(88, $event->context_sufficiency);
        $this->assertSame(88, $event->evidence_completeness);
        $this->assertSame(4200, $event->duration_ms);
        $this->assertEqualsWithDelta(0.0123, (float) $event->cost_estimate_usd, 0.0001);

        $aggregate = app(ProgrammingRuntimeTelemetryAggregator::class)->aggregate();
        $this->assertSame(1, $aggregate['total_events']);
        $this->assertSame(['atlas_dev' => 1], $aggregate['by_flow']);
        $this->assertSame(['atlas_dev' => 1], $aggregate['by_core']);
        $this->assertTrue($aggregate['claim_policy']['benchmark_not_run']);
    }

    public function test_compounding_runtime_does_not_block_when_telemetry_table_missing(): void
    {
        Schema::dropIfExists('ai_programming_runtime_telemetry_events');

        $result = app(AtlasCompoundingRuntimeService::class)->recordExecution([
            'run_id' => 'graceful',
            'flow_id' => 'atlas_dev',
            'outcome_status' => 'passed',
            'flow_quality' => 80,
            'retrieval_quality' => 80,
            'execution_quality' => 80,
            'evidence_quality' => 80,
            'evidence_refs' => ['receipt:graceful'],
            'learning_signal' => [
                'claim' => 'Telemetry missing must not block compounding.',
                'confidence' => 80,
                'evidence_refs' => ['receipt:graceful'],
            ],
        ]);

        $this->assertSame('recorded', $result['status']);
    }

    private function bootCompoundingSchema(): void
    {
        $this->dropCompoundingSchema();
        (require database_path('migrations/2026_05_17_180000_create_ai_compounding_engineering_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_19_030000_strengthen_rag_feedback_and_create_learning_proposals.php'))->up();
    }

    private function bootTelemetrySchema(): void
    {
        Schema::dropIfExists('ai_programming_runtime_telemetry_events');
        (require database_path('migrations/2026_05_19_040000_create_ai_programming_runtime_telemetry_events_table.php'))->up();
    }

    private function dropCompoundingSchema(): void
    {
        foreach ([
            'ai_learning_proposals',
            'ai_temporal_certifications',
            'ai_benchmark_cases',
            'ai_rag_feedback_events',
            'ai_heuristic_updates',
            'ai_compounding_memories',
            'ai_learning_candidates',
            'ai_run_outcomes',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
