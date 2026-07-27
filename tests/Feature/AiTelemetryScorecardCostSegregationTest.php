<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiProviderCostRate;
use App\Models\AiTrace;
use App\Services\Ai\Telemetry\AiTelemetryScorecardService;
use App\Services\Ai\Telemetry\AiTraceMetricAggregator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiTelemetryScorecardCostSegregationTest extends TestCase
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

    public function test_scorecard_segregates_cost_by_mode_and_keeps_legacy_total(): void
    {
        // Seed 3 traces with distinct cost modes:
        //   - claude_cli (operational_estimate): 1000 input + 200 output @ 1000/2000 → 1400 microusd
        //   - openai     (metered_estimate):     2000 input + 500 output @ 5000/15000 → 17500 microusd
        //   - bedrock    (unknown):              no rate configured → null cost / unknown mode
        $cliTrace = $this->seedTrace('claude_cli', 'cli-model', 1000, 200);
        $openaiTrace = $this->seedTrace('openai', 'gpt-4o', 2000, 500);
        $bedrockTrace = $this->seedTrace('bedrock', 'unknown-model', 100, 50);

        $this->seedRate('claude_cli', 'cli-model', 1000, 2000);
        $this->seedRate('openai', 'gpt-4o', 5000, 15000);
        // No rate for bedrock → cost stays null, cost_mode='unknown'

        $aggregator = app(AiTraceMetricAggregator::class);
        $aggregator->recomputeTrace($cliTrace->id);
        $aggregator->recomputeTrace($openaiTrace->id);
        $aggregator->recomputeTrace($bedrockTrace->id);

        $scorecard = app(AiTelemetryScorecardService::class)
            ->build(now()->subHour(), now()->addMinute());

        $totals = $scorecard['totals'];

        // Legacy field preserved (sum across all cost modes regardless of confidence).
        $this->assertSame(
            1400 + 17500,
            $totals['cost_microusd_sum'],
            'Legacy cost_microusd_sum must remain consistent (sum of all costs, null treated as 0).'
        );

        // New segregated fields — the meat of Fix 4.
        $this->assertArrayHasKey('metered_estimate_cost_microusd_sum', $totals,
            'New key: real-billing-backed cost (provider tokens × configured rate).');
        $this->assertSame(17500, $totals['metered_estimate_cost_microusd_sum']);

        $this->assertArrayHasKey('operational_estimate_cost_microusd_sum', $totals,
            'New key: CLI operational placeholder cost (different unit from API spend).');
        $this->assertSame(1400, $totals['operational_estimate_cost_microusd_sum']);

        $this->assertArrayHasKey('unknown_cost_microusd_sum', $totals,
            'New key: spend that has no rate configured (typically 0 since cost stays null).');
        $this->assertSame(0, $totals['unknown_cost_microusd_sum']);

        // Sanity: segregated parts sum to the legacy total (no double-counting, no leakage).
        $segregatedSum = $totals['metered_estimate_cost_microusd_sum']
            + $totals['operational_estimate_cost_microusd_sum']
            + $totals['unknown_cost_microusd_sum'];
        $this->assertSame(
            $totals['cost_microusd_sum'],
            $segregatedSum,
            'Segregated cost sums must equal the legacy total — invariant for backward compatibility.'
        );
    }

    private function seedTrace(string $provider, string $model, int $promptTokens, int $completionTokens): AiTrace
    {
        $trace = AiTrace::query()->create([
            'trace_key' => 'trace_'.Str::uuid(),
            'source_type' => 'app',
            'status' => 'succeeded',
            'operator_input' => 'Cost segregation test trace',
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

        return $trace;
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
