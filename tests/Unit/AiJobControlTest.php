<?php

namespace Tests\Unit;

use App\Http\Controllers\AiJobController;
use App\Models\AiJob;
use App\Models\AiJobAttempt;
use App\Models\AiTrace;
use App\Services\Ai\AiCouncilCoordinator;
use App\Services\Ai\AiWorker;
use App\Services\AuditLogService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

class AiJobControlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createAiJobTables();
    }

    protected function tearDown(): void
    {
        $this->dropAiJobTables();

        parent::tearDown();
    }

    public function test_retry_clears_stale_runtime_state_and_extends_attempt_budget(): void
    {
        $trace = $this->trace([
            'status' => 'failed',
            'provider' => 'claude_cli',
            'response_hash' => 'old-hash',
            'response_text' => 'resposta antiga',
            'latency_ms' => 1234,
            'completed_at' => now(),
        ]);

        $job = $this->job($trace, [
            'status' => 'failed',
            'provider' => 'claude_cli',
            'attempts' => 2,
            'max_attempts' => 2,
            'reserved_at' => now(),
            'started_at' => now(),
            'finished_at' => now(),
            'worker_id' => 'worker-1',
            'result_text' => 'resultado velho',
            'result_json' => ['old' => true],
            'error_code' => 'provider_exception',
            'error_message' => 'falhou',
        ]);

        app(AiJobController::class)->retry($job, app(AuditLogService::class));

        $job->refresh();
        $trace->refresh();

        $this->assertSame('queued', $job->status);
        $this->assertSame(3, $job->max_attempts);
        $this->assertNull($job->reserved_at);
        $this->assertNull($job->started_at);
        $this->assertNull($job->finished_at);
        $this->assertNull($job->worker_id);
        $this->assertNull($job->result_text);
        $this->assertSame([], $job->result_json);
        $this->assertNull($job->error_code);
        $this->assertNull($job->error_message);
        $this->assertSame('queued', $trace->status);
        $this->assertNull($trace->response_hash);
        $this->assertNull($trace->response_text);
        $this->assertNull($trace->latency_ms);
        $this->assertNull($trace->completed_at);
    }

    public function test_cancel_council_job_keeps_partial_success_when_one_provider_finished(): void
    {
        $trace = $this->trace([
            'status' => 'processing',
            'provider' => 'claude_codex',
        ]);
        $this->job($trace, [
            'kind' => 'council',
            'status' => 'succeeded',
            'provider' => 'claude_cli',
            'result_text' => 'Leitura pronta do Claude.',
            'started_at' => now()->subSeconds(4),
            'finished_at' => now()->subSecond(),
        ]);
        $processing = $this->job($trace, [
            'kind' => 'council',
            'status' => 'processing',
            'provider' => 'codex_cli',
            'reserved_at' => now(),
            'started_at' => now(),
            'worker_id' => 'worker-2',
        ]);

        app(AiJobController::class)->cancel($processing, app(AuditLogService::class), app(AiCouncilCoordinator::class));

        $trace->refresh();
        $processing->refresh();

        $this->assertSame('cancelled', $processing->status);
        $this->assertNull($processing->worker_id);
        $this->assertSame('succeeded', $trace->status);
        $this->assertStringContainsString('Leitura pronta do Claude.', (string) $trace->response_text);
        $this->assertStringContainsString('Cancelado pelo operador.', (string) $trace->response_text);
    }

    public function test_cancel_council_without_success_marks_trace_cancelled_not_failed(): void
    {
        $trace = $this->trace([
            'status' => 'processing',
            'provider' => 'claude_codex',
        ]);
        $queued = $this->job($trace, [
            'kind' => 'council',
            'status' => 'queued',
            'provider' => 'claude_cli',
        ]);
        $this->job($trace, [
            'kind' => 'council',
            'status' => 'processing',
            'provider' => 'codex_cli',
            'reserved_at' => now(),
            'started_at' => now(),
            'worker_id' => 'worker-3',
        ]);

        app(AiJobController::class)->cancel($queued, app(AuditLogService::class), app(AiCouncilCoordinator::class));

        $this->assertSame('cancelled', $trace->refresh()->status);
        $this->assertSame('cancelled', AiJob::query()->where('trace_id', $trace->id)->where('provider', 'claude_cli')->firstOrFail()->status);
        $this->assertSame('cancelled', AiJob::query()->where('trace_id', $trace->id)->where('provider', 'codex_cli')->firstOrFail()->status);
    }

    public function test_worker_attempt_creation_uses_history_when_counter_was_reopened(): void
    {
        $trace = $this->trace();
        $job = $this->job($trace, [
            'attempts' => 0,
            'max_attempts' => 1,
        ]);

        AiJobAttempt::query()->create([
            'ai_job_id' => $job->id,
            'attempt_number' => 1,
            'worker_id' => 'previous-worker',
            'provider' => 'claude_cli',
            'model' => 'claude-sonnet-test',
            'command' => ['claude'],
            'prompt_hash' => hash('sha256', $job->prompt),
            'status' => 'failed',
            'error_code' => 'cli_error',
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subMinute(),
            'metadata' => [],
        ]);

        $worker = (new ReflectionClass(AiWorker::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass(AiWorker::class))->getMethod('createAttempt');
        $method->setAccessible(true);

        $attempt = $method->invoke($worker, $job, 'worker-2', 'claude_cli');

        $this->assertSame(2, $attempt->attempt_number);
        $this->assertSame(2, $job->refresh()->attempts);
    }

    private function trace(array $overrides = []): AiTrace
    {
        return AiTrace::query()->create(array_merge([
            'trace_key' => 'trace_'.str_replace('.', '', uniqid('', true)),
            'source_type' => 'app',
            'status' => 'queued',
            'operator_input' => 'pedido',
            'agent_slug' => 'orquestrador',
            'provider' => 'claude_cli',
            'skill_versions' => [],
            'context_refs' => [],
            'metadata' => [],
        ], $overrides));
    }

    private function job(AiTrace $trace, array $overrides = []): AiJob
    {
        return AiJob::query()->create(array_merge([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'priority' => 50,
            'agent_slug' => 'orquestrador',
            'provider' => 'claude_cli',
            'input_text' => 'pedido',
            'prompt' => 'prompt',
            'context_refs' => [],
            'payload' => [],
            'result_json' => [],
            'available_at' => now(),
            'attempts' => 0,
            'max_attempts' => 2,
            'timeout_seconds' => 300,
            'metadata' => [],
        ], $overrides));
    }

    private function createAiJobTables(): void
    {
        $this->dropAiJobTables();

        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->unique();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('source_type')->default('manual');
            $table->uuid('source_id')->nullable();
            $table->string('status')->default('queued');
            $table->text('operator_input');
            $table->string('intent')->nullable();
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('skill_versions')->nullable();
            $table->json('context_refs')->nullable();
            $table->string('prompt_hash')->nullable();
            $table->string('response_hash')->nullable();
            $table->text('response_text')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->smallInteger('feedback_score')->nullable();
            $table->string('feedback_action')->nullable();
            $table->text('feedback_comment')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
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
            $table->json('context_refs')->nullable();
            $table->json('payload')->nullable();
            $table->text('result_text')->nullable();
            $table->json('result_json')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(2);
            $table->integer('timeout_seconds')->default(300);
            $table->string('worker_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_job_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ai_job_id');
            $table->integer('attempt_number');
            $table->string('worker_id');
            $table->string('provider');
            $table->string('model')->nullable();
            $table->json('command')->nullable();
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
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function dropAiJobTables(): void
    {
        foreach (['ai_job_attempts', 'ai_jobs', 'ai_traces'] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
