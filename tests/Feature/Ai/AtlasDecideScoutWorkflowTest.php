<?php

namespace Tests\Feature\Ai;

use App\Models\AiJob;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\AiPermissionDecision;
use App\Services\Ai\AiPermissionEngine;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\AiWorker;
use App\Services\Ai\FairClaudePolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasDecideScoutWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createAiRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropAiRuntimeTables();

        parent::tearDown();
    }

    public function test_atlas_decide_runs_gemini_context_scout_before_primary_executor(): void
    {
        $this->configureScoutRuntime();
        $this->mockProviders([
            'gemini_cli' => $this->success('SCOUT DIGEST: leia MemoryService antes de editar.'),
            'codex_cli' => $this->success('FINAL ANSWER: refatoracao planejada.'),
        ]);

        $trace = app(AiGatewayService::class)->enqueueInteraction(
            'Refatore o modulo de memoria depois de revisar um contexto grande',
            [
                'source_type' => 'app',
                'include_semantic_context' => false,
                'payload' => [
                    'decision_mode' => 'atlas_decide',
                    'operator_requested_provider' => 'auto',
                    'atlas_workflow_mode' => 'dev',
                ],
            ],
        );

        $jobs = AiJob::query()->where('trace_id', $trace->id)->get();
        $this->assertCount(2, $jobs);

        $scout = $jobs->first(fn (AiJob $job): bool => data_get($job->metadata, 'atlas_decide_stage') === 'context_scout');
        $executor = $jobs->first(fn (AiJob $job): bool => data_get($job->metadata, 'atlas_decide_stage') === 'primary_executor');

        $this->assertNotNull($scout);
        $this->assertNotNull($executor);
        $this->assertSame('gemini_cli', $scout->provider);
        $this->assertSame('codex_cli', $executor->provider);
        $this->assertSame('pending', data_get($executor->metadata, 'dependency_state'));
        $this->assertTrue($executor->available_at->isFuture());
        $this->assertSame(
            data_get($trace->metadata, 'decision_receipt.receipt_v2.receipt_id'),
            data_get($scout->metadata, 'decision_receipt.receipt_v2.receipt_id'),
        );
        $this->assertSame(
            data_get($trace->metadata, 'decision_receipt.receipt_v2.receipt_id'),
            data_get($executor->payload, 'decision_receipt.receipt_v2.receipt_id'),
        );
        $this->assertTrue(data_get($scout->metadata, 'decision_receipt.kernel_contracts.valid'));
        $this->assertTrue(data_get($executor->payload, 'decision_receipt.kernel_contracts.valid'));

        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->getJson('/ai/interactions/'.$trace->id)
            ->assertOk()
            ->assertJsonPath('trace.atlas_decide_execution.strategy', 'scout_then_execute')
            ->assertJsonPath('trace.jobs.0.atlas_decide_execution.strategy', fn (mixed $value): bool => is_string($value) && $value !== '')
            ->assertJsonFragment(['atlas_decide_stage' => 'context_scout'])
            ->assertJsonFragment(['dependency_state' => 'pending']);

        app(AiWorker::class)->runNext('gemini_cli', 'test-gemini-worker');

        $scout->refresh();
        $executor->refresh();
        $trace->refresh();

        $this->assertSame('succeeded', $scout->status);
        $this->assertSame('satisfied', data_get($executor->metadata, 'dependency_state'));
        $this->assertFalse($executor->available_at->isFuture());
        $this->assertStringContainsString('SCOUT DIGEST', $executor->prompt);
        $this->assertSame('queued', $trace->status);

        app(AiWorker::class)->runNext('codex_cli', 'test-codex-worker');

        $trace->refresh();
        $executor->refresh();

        $this->assertSame('succeeded', $executor->status);
        $this->assertSame('succeeded', $trace->status);
        $this->assertSame('FINAL ANSWER: refatoracao planejada.', $trace->response_text);
    }

    public function test_fair_claude_mode_never_enqueues_atlas_decide_scout_even_for_automatic_dev_payload(): void
    {
        $this->configureScoutRuntime();

        $trace = app(AiGatewayService::class)->enqueueInteraction(
            'Refatore o modulo de memoria depois de revisar um contexto grande',
            [
                'source_type' => 'system',
                'include_semantic_context' => false,
                'payload' => [
                    'automatic' => true,
                    'decision_mode' => 'manual_override',
                    'operator_requested_provider' => 'claude_cli',
                    'requested_provider' => 'claude_cli',
                    'atlas_workflow_mode' => 'dev',
                    'fair_mode' => app(FairClaudePolicy::class)->metadata(),
                    'requested_model' => 'claude-opus-4-7',
                    'requested_model_alias' => 'opus',
                    'requested_model_tier' => 'premium',
                    'ai_policy_override' => app(FairClaudePolicy::class)->runtimeOverride([
                        'model' => 'claude-opus-4-7',
                        'label' => 'Claude Opus 4.7',
                        'tier' => 'premium',
                    ]),
                ],
            ],
        );

        $jobs = AiJob::query()->where('trace_id', $trace->id)->get();
        $this->assertCount(1, $jobs);

        $job = $jobs->first();
        $this->assertSame('claude_cli', $job->provider);
        $this->assertSame('claude-opus-4-7', $job->model);
        $this->assertTrue((bool) data_get($job->payload, 'atlas_decide.disabled_by_fair_mode'));
        $this->assertFalse((bool) data_get($job->payload, 'atlas_decide.scout_enabled'));
        $this->assertSame('fair_mode_single_provider', data_get($job->payload, 'atlas_decide.execution_graph_activation_status'));
        $this->assertSame('fair_mode_atlas_decide_disabled', data_get($job->payload, 'atlas_decide.execution_graph_blocked_reason'));
        $this->assertSame('single_stage', data_get($job->payload, 'atlas_decide_execution.strategy'));
        $this->assertNull(data_get($job->payload, 'atlas_decide_execution.dependency_provider'));
    }

    public function test_gemini_scout_quota_falls_back_to_claude_before_releasing_executor(): void
    {
        $this->configureScoutRuntime();
        $this->mockProviders([
            'gemini_cli' => new AiProviderResult(
                ok: false,
                output: '',
                command: ['gemini'],
                exitCode: 1,
                durationMs: 50,
                stdout: '',
                stderr: 'quota exceeded',
                errorCode: 'rate_limited',
                errorMessage: 'quota exceeded',
            ),
            'claude_cli' => $this->success('CLAUDE SCOUT FALLBACK: use contexto reduzido.'),
            'codex_cli' => $this->success('FINAL VIA CODEX'),
        ]);

        $trace = app(AiGatewayService::class)->enqueueInteraction(
            'Refatore o modulo de memoria depois de revisar um contexto grande',
            [
                'source_type' => 'app',
                'include_semantic_context' => false,
                'payload' => [
                    'decision_mode' => 'atlas_decide',
                    'operator_requested_provider' => 'auto',
                    'atlas_workflow_mode' => 'dev',
                ],
            ],
        );

        app(AiWorker::class)->runNext('gemini_cli', 'test-gemini-worker');

        $scout = AiJob::query()
            ->where('trace_id', $trace->id)
            ->where('kind', 'analysis')
            ->firstOrFail();
        $this->assertSame('queued', $scout->status);
        $this->assertSame('claude_cli', $scout->provider);
        $this->assertTrue((bool) data_get($scout->metadata, 'gemini_fallback_attempted'));

        app(AiWorker::class)->runNext('claude_cli', 'test-claude-worker');

        $executor = AiJob::query()
            ->where('trace_id', $trace->id)
            ->where('kind', 'interaction')
            ->firstOrFail();

        $this->assertSame('satisfied', data_get($executor->metadata, 'dependency_state'));
        $this->assertStringContainsString('CLAUDE SCOUT FALLBACK', $executor->prompt);

        app(AiWorker::class)->runNext('codex_cli', 'test-codex-worker');

        $this->assertSame('succeeded', $trace->refresh()->status);
        $this->assertSame('FINAL VIA CODEX', $trace->response_text);
    }

    public function test_scout_final_failure_releases_executor_with_degraded_brief(): void
    {
        $this->configureScoutRuntime();
        $this->mockProviders([
            'gemini_cli' => new AiProviderResult(
                ok: false,
                output: '',
                command: ['gemini'],
                exitCode: 1,
                durationMs: 50,
                stdout: '',
                stderr: 'transient provider failure',
                errorCode: 'provider_exception',
                errorMessage: 'transient provider failure',
            ),
            'codex_cli' => $this->success('FINAL AFTER DEGRADED SCOUT'),
        ]);

        $trace = app(AiGatewayService::class)->enqueueInteraction(
            'Refatore o modulo de memoria depois de revisar um contexto grande',
            [
                'source_type' => 'app',
                'include_semantic_context' => false,
                'payload' => [
                    'decision_mode' => 'atlas_decide',
                    'operator_requested_provider' => 'auto',
                    'atlas_workflow_mode' => 'dev',
                ],
            ],
        );

        app(AiWorker::class)->runNext('gemini_cli', 'test-gemini-worker');
        AiJob::query()
            ->where('trace_id', $trace->id)
            ->where('kind', 'analysis')
            ->update(['available_at' => now()->subSecond()]);
        app(AiWorker::class)->runNext('gemini_cli', 'test-gemini-worker');

        $scout = AiJob::query()
            ->where('trace_id', $trace->id)
            ->where('kind', 'analysis')
            ->firstOrFail();
        $executor = AiJob::query()
            ->where('trace_id', $trace->id)
            ->where('kind', 'interaction')
            ->firstOrFail();

        $this->assertSame('failed', $scout->status);
        $this->assertSame('degraded', data_get($executor->metadata, 'dependency_state'));
        $this->assertFalse($executor->available_at->isFuture());
        $this->assertStringContainsString('context scout degradado', $executor->prompt);
        $this->assertSame('queued', $trace->refresh()->status);

        app(AiWorker::class)->runNext('codex_cli', 'test-codex-worker');

        $this->assertSame('succeeded', $trace->refresh()->status);
        $this->assertSame('FINAL AFTER DEGRADED SCOUT', $trace->response_text);
    }

    public function test_gemini_scout_policy_violation_does_not_fallback_to_claude(): void
    {
        $this->configureScoutRuntime();
        $this->mockProviders([
            'gemini_cli' => new AiProviderResult(
                ok: false,
                output: '',
                command: ['gemini'],
                exitCode: 1,
                durationMs: 50,
                stdout: '',
                stderr: 'policy violation',
                errorCode: 'policy_violation',
                errorMessage: 'policy violation',
            ),
            'claude_cli' => $this->success('CLAUDE SHOULD NOT RUN'),
            'codex_cli' => $this->success('FINAL AFTER POLICY DEGRADED SCOUT'),
        ]);

        $trace = app(AiGatewayService::class)->enqueueInteraction(
            'Refatore o modulo de memoria depois de revisar um contexto grande',
            [
                'source_type' => 'app',
                'include_semantic_context' => false,
                'payload' => [
                    'decision_mode' => 'atlas_decide',
                    'operator_requested_provider' => 'auto',
                    'atlas_workflow_mode' => 'dev',
                ],
            ],
        );

        app(AiWorker::class)->runNext('gemini_cli', 'test-gemini-worker');

        $scout = AiJob::query()
            ->where('trace_id', $trace->id)
            ->where('kind', 'analysis')
            ->firstOrFail();
        $executor = AiJob::query()
            ->where('trace_id', $trace->id)
            ->where('kind', 'interaction')
            ->firstOrFail();

        $this->assertSame('failed', $scout->status);
        $this->assertSame('gemini_cli', $scout->provider);
        $this->assertNotSame('claude_cli', $scout->provider);
        $this->assertSame('degraded', data_get($executor->metadata, 'dependency_state'));
        $this->assertStringContainsString('policy_violation', $executor->prompt);

        app(AiWorker::class)->runNext('codex_cli', 'test-codex-worker');

        $this->assertSame('succeeded', $trace->refresh()->status);
        $this->assertSame('FINAL AFTER POLICY DEGRADED SCOUT', $trace->response_text);
    }

    /**
     * @param  array<string,AiProviderResult>  $results
     */
    private function mockProviders(array $results): void
    {
        $manager = $this->createMock(AiProviderManager::class);
        $manager->method('get')->willReturnCallback(
            fn (?string $provider = null): AiProvider => $this->provider($provider ?: 'claude_cli', $results[$provider ?: 'claude_cli'] ?? $this->success('OK')),
        );
        $manager->method('keys')->willReturn(['claude_cli', 'codex_cli', 'gemini_cli']);
        $this->app->instance(AiProviderManager::class, $manager);

        $permissions = $this->createMock(AiPermissionEngine::class);
        $permissions->method('authorizeJob')->willReturn(new AiPermissionDecision(
            allowed: true,
            mode: 'danger',
            workspace: base_path(),
            codexSandbox: 'danger-full-access',
            capabilities: ['read_files', 'write_files', 'run_commands'],
            reasons: ['test'],
        ));
        $this->app->instance(AiPermissionEngine::class, $permissions);
    }

    private function provider(string $key, AiProviderResult $result): AiProvider
    {
        return new class($key, $result) implements AiProvider
        {
            public function __construct(private readonly string $key, private readonly AiProviderResult $result) {}

            public function key(): string
            {
                return $this->key;
            }

            public function run(AiJob $job, string $prompt): AiProviderResult
            {
                return $this->result;
            }

            public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
            {
                $onEvent?->__invoke(['type' => 'response', 'content' => $this->result->output]);

                return $this->result;
            }

            public function health(): AiProviderHealthCheck
            {
                return new AiProviderHealthCheck($this->key, 'online', 'mock');
            }
        };
    }

    private function success(string $output): AiProviderResult
    {
        return new AiProviderResult(
            ok: true,
            output: $output,
            command: ['mock'],
            exitCode: 0,
            durationMs: 25,
            stdout: $output,
            stderr: '',
        );
    }

    private function configureScoutRuntime(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.max_attempts' => 1,
            'atlas.ai.atlas_decide.scout_timeout_seconds' => 600,
            'atlas.ai.providers.codex_cli.allow_auto' => true,
            'atlas.ai.providers.codex_cli.allow_manual' => true,
            'atlas.ai.providers.gemini_cli.allow_auto' => true,
            'atlas.ai.providers.gemini_cli.allow_manual' => true,
        ]);
    }

    private function createAiRuntimeTables(): void
    {
        $this->dropAiRuntimeTables();

        Schema::create('ai_threads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('title');
            $table->text('summary')->nullable();
            $table->string('status')->default('active');
            $table->string('surface')->default('app');
            $table->string('workspace')->nullable();
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->uuid('last_trace_id')->nullable();
            $table->string('last_provider')->nullable();
            $table->integer('message_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->string('status')->default('active');
            $table->text('purpose')->nullable();
            $table->string('provider_primary')->nullable();
            $table->string('provider_last')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('message_count')->default(0);
            $table->integer('token_estimate')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->uuid('trace_id')->nullable();
            $table->integer('position');
            $table->string('role');
            $table->string('status')->default('final');
            $table->text('content');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('agent_slug')->nullable();
            $table->integer('token_estimate')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_session_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->uuid('session_id')->nullable();
            $table->integer('version')->default(1);
            $table->boolean('active')->default(true);
            $table->text('objective')->nullable();
            $table->text('current_phase')->nullable();
            $table->text('current_topic')->nullable();
            $table->text('user_position')->nullable();
            $table->json('decisions')->nullable();
            $table->json('open_loops')->nullable();
            $table->json('next_steps')->nullable();
            $table->json('relevant_artifacts')->nullable();
            $table->json('constraints')->nullable();
            $table->json('provider_context')->nullable();
            $table->json('quality_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_compactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->uuid('session_id')->nullable();
            $table->string('reason');
            $table->integer('source_position_start')->nullable();
            $table->integer('source_position_end')->nullable();
            $table->integer('source_message_count')->default(0);
            $table->text('summary');
            $table->json('structured_state')->nullable();
            $table->integer('token_estimate_before')->nullable();
            $table->integer('token_estimate_after')->nullable();
            $table->string('quality_gate_status')->default('passed');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_provider_handoffs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->uuid('session_id')->nullable();
            $table->string('from_provider')->nullable();
            $table->string('to_provider');
            $table->string('reason')->default('provider_switch');
            $table->text('brief_text');
            $table->json('brief_json')->nullable();
            $table->uuid('compaction_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

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

        Schema::create('ai_worker_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('worker_id');
            $table->string('provider')->nullable();
            $table->uuid('ai_job_id')->nullable();
            $table->uuid('ai_job_attempt_id')->nullable();
            $table->string('event_type');
            $table->string('severity')->default('info');
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->nullable();
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
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('ai_context_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_hash')->nullable();
            $table->json('context_pack')->nullable();
            $table->json('messages_included')->nullable();
            $table->uuid('compaction_id')->nullable();
            $table->uuid('provider_handoff_id')->nullable();
            $table->integer('token_estimate')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function dropAiRuntimeTables(): void
    {
        foreach ([
            'ai_context_snapshots',
            'ai_stream_events',
            'ai_worker_events',
            'ai_job_attempts',
            'ai_jobs',
            'ai_traces',
            'ai_provider_handoffs',
            'ai_compactions',
            'ai_session_states',
            'ai_messages',
            'ai_sessions',
            'ai_threads',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
