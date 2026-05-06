<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiProviderCostRate;
use App\Models\AiToolEvent;
use App\Models\AiTrace;
use App\Services\Ai\Telemetry\AiProviderCostRateService;
use App\Services\Ai\Telemetry\AiTraceMetricAggregator;
use App\Services\Ai\Telemetry\AiTraceMetricAggregatorVersions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fix 7c F3 — pin aggregator's tool diagnostics output.
 *
 * Properties under test:
 *  1. Trace WITHOUT tool events: tools.available = false (distinguishable from "no data")
 *  2. Trace WITH tool events: structured per_tool / risk / failures / denials populated correctly
 *  3. aggregator_version uses the current contract marker
 *  4. tool_events_count metadata is populated when tools are present
 */
class AiTelemetryToolDiagnosticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMinimalSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
            'ai_trace_metric_summaries',
            'ai_outcome_links',
            'ai_provider_cost_rates',
            'ai_router_decisions',
            'ai_tool_events',
            'ai_telemetry_events',
            'ai_stream_events',
            'ai_context_snapshots',
            'ai_quality_actions',
            'ai_quality_evaluations',
            'ai_job_attempts',
            'ai_jobs',
            'ai_traces',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_trace_without_tool_events_returns_unavailable_tools_block(): void
    {
        $trace = $this->seedTrace();

        $summary = app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);

        $this->assertSame(
            ['available' => false],
            $summary->score_components['tools'],
            'Tools block must be {available:false} so the report can distinguish '
                .'"no tools used" from "tool data not captured" without ambiguity.'
        );
    }

    public function test_aggregator_version_uses_current_contract_marker(): void
    {
        $trace = $this->seedTrace();

        $summary = app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);

        $this->assertSame(
            AiTraceMetricAggregatorVersions::CURRENT,
            $summary->metadata['aggregator_version'],
            'The version marker lets the statistical layer discriminate schemas in trend windows.'
        );
    }

    public function test_cost_rate_service_rejects_invalid_effective_window(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('effective_until must not be before effective_from.');

        app(AiProviderCostRateService::class)->upsert([
            'provider' => 'claude_cli',
            'model' => 'tool-diagnostics-invalid-window',
            'input_microusd_per_1k' => 0,
            'output_microusd_per_1k' => 0,
            'effective_from' => '2026-05-06T12:00:00Z',
            'effective_until' => '2026-05-06T11:00:00Z',
        ]);
    }

    public function test_per_tool_diagnostics_count_failures_and_denials_correctly(): void
    {
        $trace = $this->seedTrace();
        $traceId = $trace->id;

        // Mix of tools, statuses, and outcomes:
        $this->seedToolEvent($traceId, 'shell.run', 'medium', 'approved', exitCode: 0, durationMs: 1200);
        $this->seedToolEvent($traceId, 'shell.run', 'medium', 'approved', exitCode: 1, durationMs: 800, error: 'command failed');
        $this->seedToolEvent($traceId, 'shell.run', 'medium', 'denied', exitCode: null, durationMs: 0);
        $this->seedToolEvent($traceId, 'file.read', 'low', 'auto', exitCode: 0, durationMs: 50);
        $this->seedToolEvent($traceId, 'file.read', 'low', 'auto', exitCode: 0, durationMs: 30);
        $this->seedToolEvent($traceId, 'git.apply_patch', 'critical', 'approved', exitCode: 0, durationMs: 200);

        $summary = app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);
        $tools = $summary->score_components['tools'];

        // Top-level totals
        $this->assertTrue($tools['available']);
        $this->assertSame(3, $tools['tools_used']);
        $this->assertSame(6, $tools['tool_calls_total']);
        $this->assertSame(1, $tools['tool_failures'],
            'Exactly 1 failure: shell.run #2 with exit_code=1 AND error string. Same '
            .'row triggers either condition but counts once — failure is per-event, not per-signal.');
        $this->assertSame(1, $tools['permission_denied_count']);
        $this->assertSame(3, $tools['permission_approved_count'],
            '3 approved: 2 shell.run + 1 git.apply_patch. The 2 file.read events are "auto", '
            .'not "approved" — different permission status meaning different consent path.');
        $this->assertSame(2280, $tools['total_duration_ms'], '1200+800+0+50+30+200 = 2280');

        // Risk distribution: all 4 keys must be present even when zero (no null checks downstream)
        $this->assertSame(
            ['low' => 2, 'medium' => 3, 'high' => 0, 'critical' => 1],
            $tools['risk_distribution']
        );

        // Per-tool breakdown
        $perTool = collect($tools['per_tool'])->keyBy('tool');
        $this->assertSame(3, $perTool['shell.run']['count']);
        $this->assertSame(1, $perTool['shell.run']['failures']);
        $this->assertSame(1, $perTool['shell.run']['denied']);
        $this->assertSame(2000, $perTool['shell.run']['duration_ms']);

        $this->assertSame(2, $perTool['file.read']['count']);
        $this->assertSame(0, $perTool['file.read']['failures']);
        $this->assertSame(0, $perTool['file.read']['denied']);

        $this->assertSame(1, $perTool['git.apply_patch']['count']);
    }

    public function test_changed_files_are_deduplicated_across_tool_events(): void
    {
        $trace = $this->seedTrace();
        // Two different tool calls touch overlapping files — must be counted once.
        $this->seedToolEvent($trace->id, 'file.write', 'medium', 'approved',
            changedFiles: ['app/Foo.php', 'app/Bar.php']);
        $this->seedToolEvent($trace->id, 'file.patch', 'medium', 'approved',
            changedFiles: ['app/Foo.php', 'app/Baz.php']);

        $summary = app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);

        $this->assertSame(
            3,
            $summary->score_components['tools']['changed_files_count'],
            '3 distinct files (Foo, Bar, Baz) — Foo.php must dedupe across events.'
        );
    }

    public function test_tool_events_count_metadata_reflects_real_total(): void
    {
        $trace = $this->seedTrace();
        $this->seedToolEvent($trace->id, 'shell.run', 'medium', 'approved');
        $this->seedToolEvent($trace->id, 'file.read', 'low', 'auto');

        $summary = app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);

        $this->assertSame(
            2,
            $summary->metadata['tool_events_count'],
            'tool_events_count enables quick "did this trace use tools?" check '
                .'without unpacking score_components.'
        );
    }

    private function seedTrace(): AiTrace
    {
        $clientId = (string) Str::uuid();
        $trace = AiTrace::query()->create([
            'trace_key' => 'trace_'.Str::uuid(),
            'source_type' => 'app',
            'status' => 'succeeded',
            'operator_input' => 'tool diagnostics test',
            'agent_slug' => 'orquestrador',
            'provider' => 'claude_cli',
            'model' => 'cli-model',
            'skill_versions' => [],
            'context_refs' => [],
            'response_text' => 'ok',
            'latency_ms' => 1000,
            'completed_at' => now(),
            'metadata' => ['app_surface' => 'mobile', 'client_id' => $clientId],
        ]);

        AiJob::query()->create([
            'trace_id' => $trace->id,
            'client_id' => $clientId,
            'kind' => 'interaction',
            'status' => 'succeeded',
            'priority' => 10,
            'agent_slug' => 'orquestrador',
            'provider' => 'claude_cli',
            'model' => 'cli-model',
            'input_text' => 'in',
            'prompt' => 'prompt',
            'context_refs' => [],
            'payload' => [],
            'result_text' => 'out',
            'result_json' => ['usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150]],
            'available_at' => now()->subSeconds(2),
            'reserved_at' => now()->subSecond(),
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
            'attempts' => 1,
            'max_attempts' => 2,
            'timeout_seconds' => 300,
            'metadata' => [],
        ]);

        AiProviderCostRate::query()->create([
            'provider' => 'claude_cli',
            'model' => 'cli-model',
            'input_microusd_per_1k' => 1000,
            'output_microusd_per_1k' => 2000,
            'metadata' => [],
        ]);

        return $trace;
    }

    private function seedToolEvent(
        string $traceId,
        string $tool,
        string $risk,
        string $permissionStatus,
        ?int $exitCode = 0,
        int $durationMs = 100,
        ?string $error = null,
        array $changedFiles = [],
    ): void {
        AiToolEvent::query()->create([
            'event_key' => 'test:'.Str::uuid(),
            'trace_id' => $traceId,
            'tool' => $tool,
            'risk' => $risk,
            'permission_status' => $permissionStatus,
            'input_summary' => [],
            'output_summary' => [],
            'changed_files' => $changedFiles ?: null,
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
            'error' => $error,
            'created_at' => now(),
        ]);
    }

    private function bootMinimalSchema(): void
    {
        foreach ([
            'ai_trace_metric_summaries',
            'ai_outcome_links',
            'ai_provider_cost_rates',
            'ai_router_decisions',
            'ai_tool_events',
            'ai_telemetry_events',
            'ai_stream_events',
            'ai_context_snapshots',
            'ai_quality_actions',
            'ai_quality_evaluations',
            'ai_job_attempts',
            'ai_jobs',
            'ai_traces',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->unique();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('source_type')->default('app');
            $table->string('status')->default('queued');
            $table->text('operator_input');
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('skill_versions')->default('{}');
            $table->json('context_refs')->default('[]');
            $table->text('response_text')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->smallInteger('feedback_score')->nullable();
            $table->string('feedback_action')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_quality_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id');
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('agent_slug')->nullable();
            $table->string('evaluator_version');
            $table->integer('score');
            $table->string('status');
            $table->json('dimensions')->default('{}');
            $table->json('flags')->default('[]');
            $table->json('suggested_actions')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_quality_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('evaluation_id')->nullable();
            $table->uuid('trace_id')->nullable();
            $table->uuid('remediation_trace_id')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('action_type');
            $table->string('status')->default('queued');
            $table->integer('priority')->default(50);
            $table->text('reason');
            $table->json('flags')->default('[]');
            $table->json('payload')->default('{}');
            $table->json('result')->default('{}');
            $table->text('error_message')->nullable();
            $table->string('dedupe_key')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_context_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_hash')->nullable();
            $table->json('context_pack')->default('{}');
            $table->json('messages_included')->default('[]');
            $table->uuid('compaction_id')->nullable();
            $table->uuid('provider_handoff_id')->nullable();
            $table->integer('token_estimate')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('ai_stream_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('ai_job_id')->nullable();
            $table->uuid('ai_job_attempt_id')->nullable();
            $table->integer('sequence');
            $table->string('event_type');
            $table->string('channel')->nullable();
            $table->text('content')->default('');
            $table->json('metadata')->default('{}');
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('ai_job_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ai_job_id');
            $table->integer('attempt_number');
            $table->string('worker_id');
            $table->string('provider');
            $table->string('model')->nullable();
            $table->json('command')->default('[]');
            $table->string('command_hash')->nullable();
            $table->string('prompt_hash');
            $table->string('response_hash')->nullable();
            $table->string('status')->default('processing');
            $table->integer('exit_code')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->text('output_text')->nullable();
            $table->text('stdout_excerpt')->nullable();
            $table->text('stderr_excerpt')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('client_id')->nullable();
            $table->string('kind')->default('interaction');
            $table->string('status')->default('queued');
            $table->smallInteger('priority')->default(50);
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->text('input_text');
            $table->text('prompt');
            $table->json('context_refs')->default('[]');
            $table->json('payload')->default('{}');
            $table->text('result_text')->nullable();
            $table->json('result_json')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(2);
            $table->integer('timeout_seconds')->default(300);
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_router_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable()->index();
            $table->string('mode', 32)->default('direct');
            $table->string('selected_provider', 32);
            $table->string('fallback_provider', 32)->nullable();
            $table->json('signals');
            $table->text('reason');
            $table->boolean('was_overridden')->default(false);
            $table->timestamps();
        });

        Schema::create('ai_tool_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_key', 180)->nullable()->unique();
            $table->uuid('trace_id')->nullable()->index();
            $table->uuid('session_id')->nullable()->index();
            $table->uuid('thread_id')->nullable()->index();
            $table->string('tool', 64);
            $table->string('risk', 16)->default('low');
            $table->string('permission_status', 16)->default('auto');
            $table->string('approval_source', 32)->nullable();
            $table->json('input_summary');
            $table->json('output_summary');
            $table->json('changed_files')->nullable();
            $table->string('checkpoint_id', 128)->nullable();
            $table->integer('exit_code')->nullable();
            $table->integer('duration_ms')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        (require database_path('migrations/2026_05_01_000000_create_ai_telemetry_events_table.php'))->up();
        (require database_path('migrations/2026_05_01_001000_create_ai_metric_summary_tables.php'))->up();
        (require database_path('migrations/2026_05_01_005000_add_router_columns_to_ai_trace_metric_summaries.php'))->up();
    }
}
