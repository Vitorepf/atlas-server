<?php

namespace Tests\Unit;

use App\Models\AiQualityEvaluation;
use App\Models\AiTrace;
use App\Services\Ai\AiQualityEvaluator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies AiQualityEvaluator behavior across terminal trace statuses.
 *
 * The evaluator's internal gate (AiQualityEvaluator.php:27) skips ONLY when
 * the response is empty AND status != succeeded. Anything else gets evaluated.
 * These tests pin that contract so the worker integration in failure paths
 * (added in this change) does not produce silent gaps.
 */
class AiQualityEvaluatorTerminalStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createAiQualityTables();
    }

    protected function tearDown(): void
    {
        $this->dropAiQualityTables();
        parent::tearDown();
    }

    public function test_failed_trace_with_response_text_is_evaluated(): void
    {
        $trace = AiTrace::query()->create([
            'trace_key' => 'trace_failed_with_response',
            'thread_id' => '9f37f204-98fd-4c2f-bf80-94499971aa01',
            'session_id' => '9f37f204-98fd-4c2f-bf80-94499971aa02',
            'source_type' => 'manual',
            'status' => 'failed',
            'operator_input' => 'rode os testes',
            'agent_slug' => 'desenvolvedor',
            'provider' => 'codex_cli',
            'response_text' => 'Error: rate limit exceeded after 3 attempts.',
            'completed_at' => now(),
            'metadata' => [
                'last_error_code' => 'rate_limited',
                'task_request' => ['task_type' => 'dev'],
            ],
        ]);

        $evaluation = app(AiQualityEvaluator::class)->evaluateTrace($trace);

        $this->assertNotNull(
            $evaluation,
            'Failed traces with non-empty response_text must produce an evaluation. '
                .'Without this, the worker failure path has no quality signal and the '
                .'aggregator falls back to the static "failed → 20" placeholder.'
        );
        $this->assertSame(1, AiQualityEvaluation::query()->where('trace_id', $trace->id)->count());
    }

    public function test_cancelled_trace_with_response_text_is_evaluated(): void
    {
        $trace = AiTrace::query()->create([
            'trace_key' => 'trace_cancelled_with_partial',
            'thread_id' => '9f37f204-98fd-4c2f-bf80-94499971aa03',
            'session_id' => '9f37f204-98fd-4c2f-bf80-94499971aa04',
            'source_type' => 'manual',
            'status' => 'cancelled',
            'operator_input' => 'me explica X',
            'agent_slug' => 'orquestrador',
            'provider' => 'claude_cli',
            'response_text' => 'Resposta parcial: comecei a explicar mas',
            'completed_at' => now(),
            'metadata' => [],
        ]);

        $evaluation = app(AiQualityEvaluator::class)->evaluateTrace($trace);

        $this->assertNotNull($evaluation);
        $this->assertSame(1, AiQualityEvaluation::query()->where('trace_id', $trace->id)->count());
    }

    public function test_failed_trace_with_empty_response_is_skipped(): void
    {
        // Documented gate at AiQualityEvaluator:27 — empty + non-succeeded returns null
        // because there is literally nothing to assess. The aggregator's fallback
        // (failed → 20) is the right answer for this case.
        $trace = AiTrace::query()->create([
            'trace_key' => 'trace_failed_empty',
            'thread_id' => '9f37f204-98fd-4c2f-bf80-94499971aa05',
            'session_id' => '9f37f204-98fd-4c2f-bf80-94499971aa06',
            'source_type' => 'manual',
            'status' => 'failed',
            'operator_input' => 'a',
            'agent_slug' => 'orquestrador',
            'provider' => 'claude_cli',
            'response_text' => '',
            'completed_at' => now(),
            'metadata' => [],
        ]);

        $evaluation = app(AiQualityEvaluator::class)->evaluateTrace($trace);

        $this->assertNull(
            $evaluation,
            'Empty response on a non-succeeded trace yields no evaluation by design — '
                .'no signal to assess. Aggregator fallback handles the score.'
        );
        $this->assertSame(0, AiQualityEvaluation::query()->where('trace_id', $trace->id)->count());
    }

    public function test_succeeded_trace_with_empty_response_is_evaluated_as_low_quality(): void
    {
        // Empty + succeeded is a real bug pattern: the system reported success but produced
        // nothing. Must be caught — the evaluator flags 'empty_response' (critical).
        $trace = AiTrace::query()->create([
            'trace_key' => 'trace_succeeded_empty',
            'thread_id' => '9f37f204-98fd-4c2f-bf80-94499971aa07',
            'session_id' => '9f37f204-98fd-4c2f-bf80-94499971aa08',
            'source_type' => 'manual',
            'status' => 'succeeded',
            'operator_input' => 'me explica X',
            'agent_slug' => 'orquestrador',
            'provider' => 'claude_cli',
            'response_text' => '',
            'completed_at' => now(),
            'metadata' => [],
        ]);

        $evaluation = app(AiQualityEvaluator::class)->evaluateTrace($trace);

        $this->assertNotNull($evaluation);
        $this->assertContains(
            'empty_response',
            collect($evaluation->flags)->pluck('code')->all(),
            'Succeeded trace with empty response is a silent failure — must be flagged.'
        );
    }

    private function createAiQualityTables(): void
    {
        $this->dropAiQualityTables();

        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('status')->default('queued');
            $table->text('operator_input')->nullable();
            $table->string('intent')->nullable();
            $table->string('agent_slug')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('skill_versions')->nullable();
            $table->json('context_refs')->nullable();
            $table->text('response_text')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->integer('feedback_score')->nullable();
            $table->string('feedback_action')->nullable();
            $table->text('feedback_comment')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_quality_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('agent_slug')->nullable();
            $table->string('evaluator_version')->default('heuristic-v1');
            $table->unsignedTinyInteger('score');
            $table->string('status');
            $table->json('dimensions')->nullable();
            $table->json('flags')->nullable();
            $table->json('suggested_actions')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function dropAiQualityTables(): void
    {
        foreach (['ai_quality_evaluations', 'ai_traces'] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
