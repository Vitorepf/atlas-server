<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiProviderCostRate;
use App\Models\AiTrace;
use App\Models\AiTraceMetricSummary;
use App\Services\Ai\Telemetry\AiCostEstimator;
use App\Services\Ai\Telemetry\AiTelemetryScorecardService;
use App\Services\Ai\Telemetry\AiTraceMetricAggregator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiTelemetryCostConfidenceRenameTest extends TestCase
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

    public function test_cost_estimator_writes_metered_not_actual_when_provider_tokens_and_real_provider(): void
    {
        $trace = $this->seedTrace('openai', 'gpt-4o', 1000, 200);
        $this->seedRate('openai', 'gpt-4o', 5000, 15000);

        $estimate = app(AiCostEstimator::class)->estimate($trace, $trace->jobs->first());

        $this->assertSame(
            'metered',
            $estimate['cost_confidence'],
            "cost_confidence='metered' is the new vocabulary. Legacy 'actual' was misleading "
                ."because the value is tokens × manually-configured rate, not provider invoice."
        );
    }

    public function test_cost_estimator_keeps_estimated_for_cli_providers(): void
    {
        $trace = $this->seedTrace('claude_cli', 'cli-model', 1000, 200);
        $this->seedRate('claude_cli', 'cli-model', 1000, 2000);

        $estimate = app(AiCostEstimator::class)->estimate($trace, $trace->jobs->first());

        $this->assertSame(
            'estimated',
            $estimate['cost_confidence'],
            'CLI providers must continue to be marked estimated — no semantic change for CLI.'
        );
    }

    public function test_scorecard_exposes_metered_cost_count_alongside_legacy_actual(): void
    {
        // Seed: 1 openai trace (will produce cost_confidence='metered') and 1 cli (estimated).
        $openai = $this->seedTrace('openai', 'gpt-4o', 1000, 200);
        $cli = $this->seedTrace('claude_cli', 'cli-model', 500, 100);
        $this->seedRate('openai', 'gpt-4o', 5000, 15000);
        $this->seedRate('claude_cli', 'cli-model', 1000, 2000);

        $aggregator = app(AiTraceMetricAggregator::class);
        $aggregator->recomputeTrace($openai->id);
        $aggregator->recomputeTrace($cli->id);

        $scorecard = app(AiTelemetryScorecardService::class)
            ->build(now()->subHour(), now()->addMinute());

        $totals = $scorecard['totals'];

        $this->assertArrayHasKey('metered_cost_count', $totals,
            'New vocabulary: metered_cost_count counts traces with cost_confidence=metered.');
        $this->assertSame(1, $totals['metered_cost_count'],
            'One openai trace should be counted as metered (provider tokens + rate).');

        $this->assertArrayHasKey('actual_cost_count', $totals,
            'Legacy actual_cost_count must remain as alias for back-compat with existing consumers.');
        $this->assertSame(
            $totals['metered_cost_count'],
            $totals['actual_cost_count'],
            'During the transition, legacy actual_cost_count must equal metered_cost_count.'
        );
    }

    public function test_scorecard_counts_legacy_actual_rows_as_metered_for_back_compat(): void
    {
        // Simulate a row written before the migration ran — cost_confidence='actual' in DB.
        // The scorecard must still count it correctly (in metered_cost_count) so the report
        // is consistent during the migration window.
        $trace = $this->seedTrace('openai', 'gpt-4o', 1000, 200);
        $this->seedRate('openai', 'gpt-4o', 5000, 15000);
        app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);

        // Force the row back to legacy 'actual' to mimic pre-migration state.
        AiTraceMetricSummary::query()
            ->where('trace_id', $trace->id)
            ->update(['cost_confidence' => 'actual']);

        $totals = app(AiTelemetryScorecardService::class)
            ->build(now()->subHour(), now()->addMinute())['totals'];

        $this->assertSame(1, $totals['metered_cost_count'],
            'Legacy rows with cost_confidence=actual must still count as metered in the scorecard.');
    }

    private function seedTrace(string $provider, string $model, int $promptTokens, int $completionTokens): AiTrace
    {
        $trace = AiTrace::query()->create([
            'trace_key' => 'trace_'.Str::uuid(),
            'source_type' => 'app',
            'status' => 'succeeded',
            'operator_input' => 'cost confidence rename test',
            'agent_slug' => 'orquestrador',
            'provider' => $provider,
            'model' => $model,
            'skill_versions' => [],
            'context_refs' => [],
            'response_text' => 'ok',
            'latency_ms' => 1000,
            'completed_at' => now(),
            'metadata' => [
                'app_surface' => 'mobile',
                'client_id' => (string) Str::uuid(),
            ],
        ]);

        AiJob::query()->create([
            'trace_id' => $trace->id,
            'client_id' => (string) Str::uuid(),
            'kind' => 'interaction',
            'status' => 'succeeded',
            'priority' => 10,
            'agent_slug' => 'orquestrador',
            'provider' => $provider,
            'model' => $model,
            'input_text' => 'in',
            'prompt' => 'prompt',
            'context_refs' => [],
            'payload' => [],
            'result_text' => 'out',
            'result_json' => [
                'usage' => [
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'total_tokens' => $promptTokens + $completionTokens,
                ],
            ],
            'available_at' => now()->subSeconds(2),
            'reserved_at' => now()->subSecond(),
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
            'attempts' => 1,
            'max_attempts' => 2,
            'timeout_seconds' => 300,
            'metadata' => [],
        ]);

        return $trace->fresh(['jobs']);
    }

    private function seedRate(string $provider, string $model, int $inputMicrousdPer1k, int $outputMicrousdPer1k): void
    {
        AiProviderCostRate::query()->create([
            'provider' => $provider,
            'model' => $model,
            'input_microusd_per_1k' => $inputMicrousdPer1k,
            'output_microusd_per_1k' => $outputMicrousdPer1k,
            'metadata' => [],
        ]);
    }

    private function bootMinimalSchema(): void
    {
        foreach ([
            'ai_trace_metric_summaries',
            'ai_outcome_links',
            'ai_provider_cost_rates',
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

        (require database_path('migrations/2026_05_01_000000_create_ai_telemetry_events_table.php'))->up();
        (require database_path('migrations/2026_05_01_001000_create_ai_metric_summary_tables.php'))->up();
    }
}
