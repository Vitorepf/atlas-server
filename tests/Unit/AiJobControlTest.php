<?php

namespace Tests\Unit;

use App\Http\Controllers\AiJobController;
use App\Models\AiJob;
use App\Models\AiJobAttempt;
use App\Models\AiTrace;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\AiCouncilCoordinator;
use App\Services\Ai\Cli\AtlasCliQualityService;
use App\Services\Ai\AiWorker;
use App\Services\Ai\Programming\AtlasProgrammingOrchestrator;
use App\Services\AuditLogService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\MockInterface;
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

    public function test_worker_programming_dispatch_update_closes_provider_execution_receipt(): void
    {
        $trace = $this->trace([
            'metadata' => [
                'programming_dispatch' => [
                    'status' => 'selected',
                    'dispatch_path' => 'ai_gateway_provider',
                    'executor' => 'dev_repair_executor',
                    'profile_context' => [
                        'programming' => true,
                        'forge' => false,
                    ],
                    'execution_policy' => [
                        'executor_preference' => 'dev_repair_executor',
                        'max_iterations' => 3,
                    ],
                ],
            ],
        ]);
        $job = $this->job($trace, [
            'provider' => 'claude_cli',
            'payload' => [
                'programming_dispatch' => [
                    'status' => 'selected',
                    'dispatch_path' => 'ai_gateway_provider',
                    'executor' => 'dev_repair_executor',
                    'profile_context' => [
                        'programming' => true,
                        'forge' => false,
                    ],
                    'execution_policy' => [
                        'executor_preference' => 'dev_repair_executor',
                        'max_iterations' => 3,
                    ],
                ],
            ],
        ])->load('trace');

        $worker = (new ReflectionClass(AiWorker::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass(AiWorker::class))->getMethod('programmingDispatchUpdate');
        $method->setAccessible(true);

        $executed = $method->invoke($worker, $job, 'executed', 'claude_cli', 'response-hash-1');
        $retrying = $method->invoke($worker, $job, 'retrying', 'claude_cli', null, 'provider_timeout');

        $this->assertSame('executed', data_get($executed, 'programming_dispatch.status'));
        $this->assertSame('claude_cli', data_get($executed, 'programming_dispatch.trace_provider'));
        $this->assertSame('ai_gateway_provider', data_get($executed, 'programming_dispatch.dispatch_path'));
        $this->assertNotNull(data_get($executed, 'programming_dispatch.completed_at'));
        $this->assertSame('passed', data_get($executed, 'programming_completion.status'));
        $this->assertSame('dev_repair_executor', data_get($executed, 'programming_completion.executor'));
        $this->assertTrue((bool) data_get($executed, 'programming_completion.profile_context.programming'));
        $this->assertSame('dev_repair_executor', data_get($executed, 'programming_completion.execution_policy.executor_preference'));
        $this->assertSame('response-hash-1', data_get($executed, 'programming_completion.response_hash'));
        $this->assertSame('retrying', data_get($retrying, 'programming_dispatch.status'));
        $this->assertNull(data_get($retrying, 'programming_dispatch.completed_at'));
        $this->assertSame('retrying', data_get($retrying, 'programming_completion.status'));
        $this->assertSame('provider_timeout', data_get($retrying, 'programming_completion.error_code'));
        $this->assertNotNull(data_get($retrying, 'programming_dispatch.updated_at'));
    }

    public function test_worker_native_dev_repair_executor_enqueues_repair_job_when_quality_fails(): void
    {
        $trace = $this->trace();
        $job = $this->job($trace, [
            'provider' => 'claude_cli',
            'model' => 'claude-sonnet-test',
            'payload' => [
                'workspace_context' => [
                    'workspace' => base_path(),
                ],
                'programming_dispatch' => [
                    'status' => 'selected',
                    'dispatch_path' => 'ai_gateway_provider',
                    'executor' => 'dev_repair_executor',
                ],
                'programming_message_plan' => [
                    'execution_profile' => [
                        'complete' => true,
                        'auto_test' => true,
                        'max_iterations' => 3,
                    ],
                ],
                'programming_repair' => [
                    'enabled' => true,
                    'status' => 'active',
                    'complete_mode' => true,
                    'max_iterations' => 3,
                    'repair_when_status' => ['failed', 'needs_review'],
                    'stop_when_status' => ['passed'],
                ],
            ],
        ])->load('trace');
        $attempt = AiJobAttempt::query()->create([
            'ai_job_id' => $job->id,
            'attempt_number' => 1,
            'worker_id' => 'worker-1',
            'provider' => 'claude_cli',
            'model' => 'claude-sonnet-test',
            'command' => [],
            'prompt_hash' => hash('sha256', $job->prompt),
            'status' => 'succeeded',
            'started_at' => now(),
            'metadata' => [],
        ]);
        $quality = [
            'status' => 'failed',
            'dirty_count' => 1,
            'diff_hash' => 'diff-hash-1',
            'completion_packet' => [
                'tests' => [['command' => 'php artisan test', 'ok' => false]],
                'risks' => ['tests failed'],
            ],
        ];

        $this->mock(AtlasCliQualityService::class, function (MockInterface $mock) use ($quality): void {
            $mock->shouldReceive('evaluate')
                ->once()
                ->withArgs(fn (string $workspace, bool $runTests): bool => $workspace === base_path() && $runTests)
                ->andReturn($quality);
        });
        $this->mock(AtlasProgrammingOrchestrator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('repairPrompt')
                ->once()
                ->with('pedido', Mockery::type('array'), 2, 3)
                ->andReturn('repair prompt 2/3');
        });

        $worker = app(AiWorker::class);
        $method = (new ReflectionClass(AiWorker::class))->getMethod('handleNativeProgrammingRepair');
        $method->setAccessible(true);

        $handled = $method->invoke($worker, $job, $attempt, new AiProviderResult(
            ok: true,
            output: 'saida inicial',
            command: [],
            exitCode: 0,
            durationMs: 123,
            stdout: 'saida inicial',
            stderr: '',
        ), 'response-hash-1');

        $this->assertInstanceOf(AiJob::class, $handled);
        $this->assertSame('queued', $trace->refresh()->status);
        $this->assertSame('repairing', data_get($trace->metadata, 'programming_repair.status'));
        $this->assertSame(2, data_get($trace->metadata, 'programming_repair.next_iteration'));
        $this->assertSame('failed', data_get($trace->metadata, 'programming_completion.status'));
        $this->assertSame('repairing', data_get($trace->metadata, 'programming_completion.repair.status'));
        $this->assertSame(1, data_get($trace->metadata, 'programming_completion.repair.current_iteration'));
        $this->assertSame(2, data_get($trace->metadata, 'programming_completion.repair.next_iteration'));
        $this->assertSame(3, data_get($trace->metadata, 'programming_completion.repair.max_iterations'));
        $this->assertSame('failed', data_get($trace->metadata, 'programming_completion.repair.last_quality_status'));
        $this->assertSame('failed', data_get($trace->metadata, 'programming_completion.repair.history.0.status'));
        $repairJob = AiJob::query()->where('id', '!=', $job->id)->firstOrFail();
        $this->assertSame('queued', $repairJob->status);
        $this->assertSame('repair prompt 2/3', $repairJob->prompt);
        $this->assertSame(2, data_get($repairJob->payload, 'programming_repair.current_iteration'));
        $this->assertSame('failed', data_get($repairJob->payload, 'programming_repair_history.0.status'));
        $this->assertTrue((bool) data_get($repairJob->metadata, 'programming_repair_job'));
    }

    public function test_worker_native_dev_repair_executor_stops_when_quality_worsens(): void
    {
        $trace = $this->trace();
        $job = $this->job($trace, [
            'provider' => 'claude_cli',
            'model' => 'claude-sonnet-test',
            'payload' => [
                'workspace_context' => [
                    'workspace' => base_path(),
                ],
                'programming_dispatch' => [
                    'status' => 'selected',
                    'dispatch_path' => 'ai_gateway_provider',
                    'executor' => 'dev_repair_executor',
                ],
                'programming_message_plan' => [
                    'execution_profile' => [
                        'complete' => true,
                        'auto_test' => true,
                        'max_iterations' => 3,
                    ],
                ],
                'programming_repair' => [
                    'enabled' => true,
                    'status' => 'active',
                    'complete_mode' => true,
                    'current_iteration' => 2,
                    'max_iterations' => 3,
                    'previous_quality_status' => 'needs_review',
                    'repair_when_status' => ['failed', 'needs_review'],
                    'stop_when_status' => ['passed'],
                    'stop_when_quality_worsens' => true,
                ],
                'programming_repair_history' => [
                    [
                        'iteration' => 1,
                        'status' => 'needs_review',
                        'diff_hash' => 'diff-hash-before',
                        'recorded_at' => now()->subMinute()->toJSON(),
                    ],
                ],
            ],
        ])->load('trace');
        $attempt = AiJobAttempt::query()->create([
            'ai_job_id' => $job->id,
            'attempt_number' => 1,
            'worker_id' => 'worker-1',
            'provider' => 'claude_cli',
            'model' => 'claude-sonnet-test',
            'command' => [],
            'prompt_hash' => hash('sha256', $job->prompt),
            'status' => 'succeeded',
            'started_at' => now(),
            'metadata' => [],
        ]);

        $this->mock(AtlasCliQualityService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('evaluate')
                ->once()
                ->andReturn([
                    'status' => 'failed',
                    'dirty_count' => 1,
                    'diff_hash' => 'diff-hash-worse',
                    'completion_packet' => [
                        'tests' => [['command' => 'php artisan test', 'ok' => false]],
                        'risks' => ['quality worsened'],
                    ],
                ]);
        });
        $this->mock(AtlasProgrammingOrchestrator::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('repairPrompt');
        });

        $worker = app(AiWorker::class);
        $method = (new ReflectionClass(AiWorker::class))->getMethod('handleNativeProgrammingRepair');
        $method->setAccessible(true);

        $handled = $method->invoke($worker, $job, $attempt, new AiProviderResult(
            ok: true,
            output: 'saida de reparo pior',
            command: [],
            exitCode: 0,
            durationMs: 123,
            stdout: 'saida de reparo pior',
            stderr: '',
        ), 'response-hash-2');

        $this->assertInstanceOf(AiJob::class, $handled);
        $this->assertSame('failed', $trace->refresh()->status);
        $this->assertSame('stopped', data_get($trace->metadata, 'programming_repair.status'));
        $this->assertSame('quality_gate_worsened', data_get($trace->metadata, 'programming_repair.reason_if_stopped'));
        $this->assertSame('blocked', data_get($trace->metadata, 'programming_completion.status'));
        $this->assertSame('quality_gate_failed', data_get($trace->metadata, 'programming_completion.error_code'));
        $this->assertSame('stopped', data_get($trace->metadata, 'programming_completion.repair.status'));
        $this->assertSame(2, data_get($trace->metadata, 'programming_completion.repair.current_iteration'));
        $this->assertSame('quality_gate_worsened', data_get($trace->metadata, 'programming_completion.repair.reason_if_stopped'));
        $this->assertSame('needs_review', data_get($trace->metadata, 'programming_completion.repair.previous_quality_status'));
        $this->assertSame('needs_review', data_get($trace->metadata, 'programming_completion.repair.history.0.status'));
        $this->assertSame('failed', data_get($trace->metadata, 'programming_completion.repair.history.1.status'));
        $this->assertSame(1, AiJob::query()->count());
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
