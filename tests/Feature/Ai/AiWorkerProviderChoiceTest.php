<?php

namespace Tests\Feature\Ai;

use App\Models\AiJob;
use App\Models\AiTrace;
use App\Models\AiWorkerEvent;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\AiPermissionDecision;
use App\Services\Ai\AiPermissionEngine;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\AiWorker;
use App\Services\Ai\FairClaudePolicy;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineContract;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineStage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiWorkerProviderChoiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createAiWorkerRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropAiWorkerRuntimeTables();

        parent::tearDown();
    }

    public function test_rate_limited_job_pauses_with_choice_options(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'olá',
            'status' => 'queued',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'olá',
            'prompt' => 'olá',
            'available_at' => now()->subSecond(),
            'max_attempts' => 3,
        ]);

        $this->mockProviderManagerWith(new AiProviderResult(
            ok: false,
            output: '',
            command: ['codex'],
            exitCode: 1,
            durationMs: 100,
            stdout: '',
            stderr: "You've hit your usage limit. Try again at May 5th, 2026 10:24 AM.",
            errorCode: 'rate_limited',
            errorMessage: 'usage limit',
            metadata: [
                'provider_reset_at' => '2026-05-05T10:24:00+00:00',
                'reset_hint' => 'May 5th, 2026 10:24 AM',
            ],
        ));

        app(AiWorker::class)->runNext();

        $job->refresh();
        $this->assertSame('awaiting_user_choice', $job->status);
        $this->assertSame('pending', data_get($job->metadata, 'provider_choice_state'));
        $this->assertNotEmpty(data_get($job->metadata, 'choice_options'));
        $this->assertSame('switch_provider', data_get($job->metadata, 'choice_options.0.id'));
        $this->assertSame('claude_cli', data_get($job->metadata, 'choice_options.0.provider'));

        $event = AiWorkerEvent::where('event_type', 'provider_choice_required')
            ->where('ai_job_id', $job->id)
            ->first();
        $this->assertNotNull($event);
    }

    public function test_pause_does_not_consume_extra_attempts(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'olá',
            'status' => 'queued',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'olá',
            'prompt' => 'olá',
            'available_at' => now()->subSecond(),
            'max_attempts' => 3,
        ]);

        $this->mockProviderManagerWith(new AiProviderResult(
            ok: false,
            output: '',
            command: ['codex'],
            exitCode: 1,
            durationMs: 100,
            stdout: '',
            stderr: 'rate limit',
            errorCode: 'rate_limited',
            errorMessage: 'rate limit',
        ));

        app(AiWorker::class)->runNext();

        $job->refresh();
        $this->assertSame(1, $job->attempts, 'Pausa não deve gastar attempts extras.');
    }

    public function test_resolved_choice_followed_by_same_error_falls_through_to_failure(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'olá',
            'status' => 'queued',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'olá',
            'prompt' => 'olá',
            'available_at' => now()->subSecond(),
            'max_attempts' => 1,
            'metadata' => ['provider_choice_state' => 'resolved'],
        ]);

        $this->mockProviderManagerWith(new AiProviderResult(
            ok: false,
            output: '',
            command: ['codex'],
            exitCode: 1,
            durationMs: 100,
            stdout: '',
            stderr: 'rate limit',
            errorCode: 'rate_limited',
            errorMessage: 'rate limit',
        ));

        app(AiWorker::class)->runNext();

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertNotSame('awaiting_user_choice', $job->status);
    }

    public function test_fair_mode_provider_drift_fails_with_explicit_violation(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'olá',
            'status' => 'queued',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'olá',
            'prompt' => 'olá',
            'available_at' => now()->subSecond(),
            'max_attempts' => 1,
            'payload' => [
                'fair_mode' => app(FairClaudePolicy::class)->metadata(),
            ],
        ]);

        app(AiWorker::class)->runNext();

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertSame('fair_mode_violation', $job->error_code);
    }

    public function test_fair_mode_rate_limit_choice_does_not_offer_switch_or_downgrade(): void
    {
        config()->set('atlas.ai.providers.claude_cli.fallback_model', 'claude-haiku');

        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'olá',
            'status' => 'queued',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'agent_slug' => 'orquestrador',
            'provider' => 'claude_cli',
            'model' => 'claude-opus-4-7',
            'input_text' => 'olá',
            'prompt' => 'olá',
            'available_at' => now()->subSecond(),
            'max_attempts' => 3,
            'payload' => [
                'fair_mode' => app(FairClaudePolicy::class)->metadata(),
                'requested_model' => 'claude-opus-4-7',
                'requested_model_alias' => 'opus',
                'requested_model_tier' => 'premium',
            ],
        ]);

        $this->mockProviderManagerWith(new AiProviderResult(
            ok: false,
            output: '',
            command: ['claude'],
            exitCode: 1,
            durationMs: 100,
            stdout: '',
            stderr: 'rate limit',
            errorCode: 'rate_limited',
            errorMessage: 'rate limit',
        ));

        app(AiWorker::class)->runNext();

        $job->refresh();
        $optionIds = collect((array) data_get($job->metadata, 'choice_options'))->pluck('id')->all();
        $this->assertSame('awaiting_user_choice', $job->status);
        $this->assertSame(['cancel', 'retry_same'], $optionIds);
    }

    public function test_worker_persists_claude_invocation_fingerprint_to_job_and_trace(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'olá',
            'status' => 'queued',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'agent_slug' => 'orquestrador',
            'provider' => 'claude_cli',
            'model' => 'claude-opus-4-7',
            'input_text' => 'olá',
            'prompt' => 'olá',
            'available_at' => now()->subSecond(),
            'max_attempts' => 1,
        ]);

        $fingerprint = [
            'schema_version' => 1,
            'provider' => 'claude_cli',
            'model' => 'claude-opus-4-7',
            'prompt_hash' => hash('sha256', 'olá'),
        ];

        $this->mockProviderManagerWith(new AiProviderResult(
            ok: true,
            output: 'ok',
            command: ['claude'],
            exitCode: 0,
            durationMs: 100,
            stdout: '{"result":"ok"}',
            stderr: '',
            metadata: ['claude_invocation_fingerprint' => $fingerprint],
        ));

        app(AiWorker::class)->runNext();

        $this->assertSame($fingerprint, data_get($job->refresh()->metadata, 'claude_invocation_fingerprint'));
        $this->assertSame($fingerprint, data_get($trace->refresh()->metadata, 'claude_invocation_fingerprint'));
    }

    public function test_worker_records_kernel_ledger_events_for_successful_provider_execution(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'implemente com ledger',
            'status' => 'queued',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'implemente com ledger',
            'prompt' => 'prompt sensível',
            'available_at' => now()->subSecond(),
            'max_attempts' => 1,
            'payload' => [
                'decision_receipt' => [
                    'receipt_v2' => [
                        'envelope_id' => 'env_worker_success',
                        'receipt_id' => 'rcpt_worker_success',
                        'schema_version' => 'atlas.decide.v2',
                        'expires_at' => now()->addMinute()->toISOString(),
                        'dry_run' => false,
                        'metadata' => [
                            'tenant_id' => 'tenant_worker',
                            'operator_id' => 'operator_worker',
                        ],
                    ],
                ],
            ],
        ]);

        $this->mockProviderManagerWith(new AiProviderResult(
            ok: true,
            output: 'ok',
            command: ['codex', 'exec'],
            exitCode: 0,
            durationMs: 123,
            stdout: 'ok',
            stderr: '',
        ));

        app(AiWorker::class)->runNext(workerId: 'worker-ledger');

        $events = AtlasLedgerEvent::query()
            ->where('envelope_id', 'env_worker_success')
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get();

        $this->assertSame([
            LedgerEventType::ExecutionStarted->value,
            LedgerEventType::ProviderCalled->value,
            LedgerEventType::SloObserved->value,
            LedgerEventType::ProviderReturned->value,
            LedgerEventType::OperationCompleted->value,
        ], $events->pluck('event_type')->all());
        $this->assertSame('tenant_worker', $events->first()?->tenant_id);
        $this->assertSame('operator_worker', $events->first()?->operator_id);
        $this->assertSame($trace->id, $events->firstWhere('event_type', LedgerEventType::OperationCompleted->value)?->trace_id);
        $this->assertNull(data_get($events->firstWhere('event_type', LedgerEventType::ProviderReturned->value)?->payload, 'stdout'));
        $this->assertNull(data_get($events->firstWhere('event_type', LedgerEventType::ProviderReturned->value)?->payload, 'output'));
        $this->assertSame(hash('sha256', 'ok'), data_get($events->firstWhere('event_type', LedgerEventType::ProviderReturned->value)?->payload, 'response_hash'));
        $this->assertSame('runtime.execute', data_get($events->firstWhere('event_type', LedgerEventType::SloObserved->value)?->payload, 'stage'));
        $this->assertTrue((bool) data_get($events->firstWhere('event_type', LedgerEventType::SloObserved->value)?->payload, 'slo.success'));
        $this->assertSame('succeeded', $job->refresh()->status);
    }

    public function test_worker_blocks_expired_decision_receipt_before_provider_execution(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'execute com receipt expirado',
            'status' => 'queued',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'execute com receipt expirado',
            'prompt' => 'prompt',
            'available_at' => now()->subSecond(),
            'max_attempts' => 1,
            'payload' => [
                'decision_receipt' => [
                    'receipt_v2' => [
                        'envelope_id' => 'env_worker_expired',
                        'receipt_id' => 'rcpt_worker_expired',
                        'schema_version' => 'atlas.decide.v2',
                        'expires_at' => now()->subSecond()->toISOString(),
                        'dry_run' => false,
                    ],
                ],
            ],
        ]);

        $manager = $this->createMock(AiProviderManager::class);
        $manager->expects($this->never())->method('get');
        $this->app->instance(AiProviderManager::class, $manager);

        app(AiWorker::class)->runNext(workerId: 'worker-receipt');

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertSame('decision_receipt_expired', $job->error_code);
        $this->assertSame('failed', $trace->refresh()->status);
        $this->assertSame('decision_receipt_expired', $job->attemptHistory()->first()?->error_code);

        $events = AtlasLedgerEvent::query()
            ->where('envelope_id', 'env_worker_expired')
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get();

        $this->assertSame([
            LedgerEventType::ExecutionStarted->value,
            LedgerEventType::OperationBlocked->value,
            LedgerEventType::OperationFailed->value,
        ], $events->pluck('event_type')->all());
    }

    public function test_worker_blocks_provider_mismatch_decision_receipt_before_provider_execution(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'execute com provider adulterado',
            'status' => 'queued',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'agent_slug' => 'orquestrador',
            'provider' => 'claude_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'execute com provider adulterado',
            'prompt' => 'prompt',
            'available_at' => now()->subSecond(),
            'max_attempts' => 1,
            'payload' => [
                'decision_receipt' => [
                    'receipt_v2' => [
                        'envelope_id' => 'env_worker_provider_mismatch',
                        'receipt_id' => 'rcpt_worker_provider_mismatch',
                        'schema_version' => 'atlas.decide.v2',
                        'expires_at' => now()->addMinute()->toISOString(),
                        'dry_run' => false,
                        'provider_selection' => [
                            'primary' => 'codex_cli',
                            'model' => 'gpt-5.5',
                            'fallbacks' => [],
                        ],
                    ],
                ],
            ],
        ]);

        $manager = $this->createMock(AiProviderManager::class);
        $manager->expects($this->never())->method('get');
        $this->app->instance(AiProviderManager::class, $manager);

        app(AiWorker::class)->runNext(workerId: 'worker-receipt');

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertSame('decision_receipt_provider_mismatch', $job->error_code);
        $this->assertSame('failed', $trace->refresh()->status);
        $this->assertSame('decision_receipt_provider_mismatch', $job->attemptHistory()->first()?->error_code);
        $this->assertSame('codex_cli', data_get($job->metadata, 'decision_receipt_enforcement.expected_provider'));
        $this->assertSame('claude_cli', data_get($job->metadata, 'decision_receipt_enforcement.actual_provider'));

        $events = AtlasLedgerEvent::query()
            ->where('envelope_id', 'env_worker_provider_mismatch')
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get();

        $this->assertSame([
            LedgerEventType::ExecutionStarted->value,
            LedgerEventType::OperationBlocked->value,
            LedgerEventType::OperationFailed->value,
        ], $events->pluck('event_type')->all());
    }

    public function test_programming_provider_execution_blocks_strict_gate_without_evidence_path(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'implemente com gate strict',
            'status' => 'queued',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'implemente com gate strict',
            'prompt' => 'prompt',
            'available_at' => now()->subSecond(),
            'max_attempts' => 3,
            'payload' => [
                'programming_dispatch' => [
                    'status' => 'selected',
                    'dispatch_path' => 'ai_gateway_provider',
                    'executor' => 'simple_provider_execution',
                    'policy_contracts' => [
                        'gates' => [
                            'minimum_gate' => 'strict',
                            'evidence_required' => true,
                        ],
                        'tools' => [
                            'mode' => 'workspace_write',
                            'workspace_write' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $this->mockProviderManagerWith(new AiProviderResult(
            ok: true,
            output: 'provider should not run',
            command: ['codex'],
            exitCode: 0,
            durationMs: 100,
            stdout: 'provider should not run',
            stderr: '',
        ));

        app(AiWorker::class)->runNext();

        $job->refresh();
        $trace->refresh();
        $attempt = $job->attemptHistory()->first();

        $this->assertSame('failed', $job->status);
        $this->assertSame('policy_violation', $job->error_code);
        $this->assertSame('failed', $trace->status);
        $this->assertSame('blocked', data_get($job->metadata, 'programming_completion.status'));
        $this->assertSame('policy_violation', data_get($job->metadata, 'programming_completion.error_code'));
        $this->assertSame('gate_contract_requires_evidence_without_provider_evidence_path', data_get($job->metadata, 'policy_contract_enforcement.blocked_reason'));
        $this->assertSame('policy_violation', $attempt?->error_code);
        $this->assertSame('gate_contract_requires_evidence_without_provider_evidence_path', data_get($attempt?->metadata, 'policy_contract_enforcement.blocked_reason'));
    }

    public function test_worker_blocks_invalid_kernel_pipeline_contract_before_provider_execution(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'execute com kernel pipeline adulterado',
            'status' => 'queued',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'execute com kernel pipeline adulterado',
            'prompt' => 'prompt',
            'available_at' => now()->subSecond(),
            'max_attempts' => 1,
            'payload' => [
                'dev_execution_plan' => [
                    'plan_id' => 'runtime-kernel-invalid',
                    'kernel_pipeline' => [
                        ...$this->validRuntimeKernelPipelinePlan(),
                        'canonical_flow_hash' => 'tampered',
                    ],
                    'kernel_pipeline_contract' => KernelPipelineContract::requiredSurfaceContract('KernelPipelineDevPlanBuilder'),
                ],
            ],
        ]);

        $manager = $this->createMock(AiProviderManager::class);
        $manager->expects($this->never())->method('get');
        $this->app->instance(AiProviderManager::class, $manager);

        app(AiWorker::class)->runNext(workerId: 'worker-kernel-pipeline');

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertSame('kernel_pipeline_contract_violation', $job->error_code);
        $this->assertSame('kernel_pipeline_contract_violation', $job->attemptHistory()->first()?->error_code);
        $this->assertSame('KernelPipelineRuntimeGuard', data_get($job->metadata, 'kernel_pipeline_contract_enforcement.source'));
        $this->assertContains(
            'kernel_pipeline.canonical_flow_hash does not match the canonical kernel flow.',
            data_get($job->metadata, 'kernel_pipeline_contract_enforcement.violations'),
        );
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::KernelPipelineRejected->value,
            'emitter_stage' => 'atlas.ai_worker.kernel_pipeline_runtime_guard',
        ]);
    }

    public function test_worker_blocks_dev_execution_plan_missing_kernel_pipeline_before_provider_execution(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'execute plano dev legado sem kernel pipeline',
            'status' => 'queued',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'execute plano dev legado sem kernel pipeline',
            'prompt' => 'prompt',
            'available_at' => now()->subSecond(),
            'max_attempts' => 1,
            'payload' => [
                'dev_execution_plan' => [
                    'plan_id' => 'runtime-kernel-missing',
                ],
            ],
        ]);

        $manager = $this->createMock(AiProviderManager::class);
        $manager->expects($this->never())->method('get');
        $this->app->instance(AiProviderManager::class, $manager);

        app(AiWorker::class)->runNext(workerId: 'worker-kernel-pipeline');

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertSame('kernel_pipeline_contract_violation', $job->error_code);
        $this->assertContains(
            'kernel_pipeline must be present before programming provider execution.',
            data_get($job->metadata, 'kernel_pipeline_contract_enforcement.violations'),
        );
    }

    public function test_worker_records_accepted_kernel_pipeline_contract_before_provider_execution(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'execute com kernel pipeline valido',
            'status' => 'queued',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'execute com kernel pipeline valido',
            'prompt' => 'prompt',
            'available_at' => now()->subSecond(),
            'max_attempts' => 1,
            'payload' => [
                'dev_execution_plan' => [
                    'plan_id' => 'runtime-kernel-valid',
                    'kernel_pipeline' => $this->validRuntimeKernelPipelinePlan(),
                    'kernel_pipeline_contract' => KernelPipelineContract::requiredSurfaceContract('KernelPipelineDevPlanBuilder'),
                ],
            ],
        ]);

        $this->mockProviderManagerWith(new AiProviderResult(
            ok: true,
            output: 'ok',
            command: ['codex'],
            exitCode: 0,
            durationMs: 100,
            stdout: 'ok',
            stderr: '',
        ));

        app(AiWorker::class)->runNext(workerId: 'worker-kernel-pipeline');

        $job->refresh();
        $this->assertSame('succeeded', $job->status);
        $event = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::KernelPipelineAccepted->value)
            ->where('emitter_stage', 'atlas.ai_worker.kernel_pipeline_runtime_guard')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('accepted', data_get($event?->payload, 'status'));
        $this->assertSame('KernelPipelineDevPlanBuilder', data_get($event?->payload, 'surface_contract.source'));
        $this->assertSame('programming.dev', data_get($event?->payload, 'routing.flow'));
    }

    public function test_programming_provider_execution_forces_read_permission_for_read_only_tool_contract(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'analise sem escrever',
            'status' => 'queued',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'analise sem escrever',
            'prompt' => 'prompt',
            'available_at' => now()->subSecond(),
            'max_attempts' => 1,
            'payload' => [
                'tool_permissions' => [
                    'mode' => 'write',
                    'workspace' => base_path(),
                ],
                'programming_dispatch' => [
                    'status' => 'selected',
                    'dispatch_path' => 'ai_gateway_provider',
                    'executor' => 'simple_provider_execution',
                    'policy_contracts' => [
                        'tools' => [
                            'mode' => 'read_only',
                            'workspace_write' => false,
                        ],
                    ],
                ],
            ],
        ]);

        $this->mockProviderManagerWith(new AiProviderResult(
            ok: true,
            output: 'ok',
            command: ['codex'],
            exitCode: 0,
            durationMs: 100,
            stdout: 'ok',
            stderr: '',
        ));

        app(AiWorker::class)->runNext();

        $job->refresh();

        $this->assertSame('succeeded', $job->status);
        $this->assertSame('read', data_get($job->payload, 'tool_permissions.mode'));
        $this->assertTrue((bool) data_get($job->payload, 'tool_permissions.policy_contract_forced_read_only'));
        $this->assertSame('tool_contract_forces_read_only_provider_runtime', data_get($job->metadata, 'programming_policy_contract_enforcement.provider_runtime.reason'));
        $this->assertSame('read', data_get($job->metadata, 'programming_policy_contract_enforcement.provider_runtime.effective_tool_permission_mode'));
    }

    private function mockProviderManagerWith(AiProviderResult $result): void
    {
        $provider = new class($result) implements AiProvider
        {
            public function __construct(private readonly AiProviderResult $result) {}

            public function key(): string
            {
                return 'codex_cli';
            }

            public function run(AiJob $job, string $prompt): AiProviderResult
            {
                return $this->result;
            }

            public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
            {
                return $this->result;
            }

            public function health(): AiProviderHealthCheck
            {
                return new AiProviderHealthCheck('codex_cli', 'online', 'mock');
            }
        };

        $manager = $this->createMock(AiProviderManager::class);
        $manager->method('get')->willReturn($provider);
        $manager->method('keys')->willReturn(['claude_cli', 'codex_cli']);
        $this->app->instance(AiProviderManager::class, $manager);

        $allowedDecision = new AiPermissionDecision(
            allowed: true,
            mode: 'read',
            workspace: base_path(),
            codexSandbox: 'read-only',
            capabilities: ['read_files'],
            reasons: ['test mock'],
        );

        $permissions = $this->createMock(AiPermissionEngine::class);
        $permissions->method('authorizeJob')->willReturn($allowedDecision);
        $this->app->instance(AiPermissionEngine::class, $permissions);
    }

    public function test_pause_excludes_last_attempted_option_from_new_menu(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'olá',
            'status' => 'queued',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.4-mini',
            'input_text' => 'olá',
            'prompt' => 'olá',
            'available_at' => now()->subSecond(),
            'max_attempts' => 5,
            'metadata' => [
                'provider_choice_last_attempted_option_id' => 'downgrade_model',
            ],
        ]);

        $this->mockProviderManagerWith(new AiProviderResult(
            ok: false,
            output: '',
            command: ['codex'],
            exitCode: 1,
            durationMs: 100,
            stdout: '',
            stderr: 'usage limit',
            errorCode: 'rate_limited',
            errorMessage: 'usage limit',
        ));

        app(AiWorker::class)->runNext();

        $job->refresh();
        $this->assertSame('awaiting_user_choice', $job->status);

        $optionIds = collect((array) data_get($job->metadata, 'choice_options'))
            ->pluck('id')
            ->all();

        $this->assertNotContains('downgrade_model', $optionIds,
            'opção que acabou de falhar não deve reaparecer no menu seguinte');
    }

    private function createAiWorkerRuntimeTables(): void
    {
        $this->dropAiWorkerRuntimeTables();

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

        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->unique();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('source_type')->nullable();
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
            $table->integer('feedback_score')->nullable();
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
    }

    /**
     * @return array<string,mixed>
     */
    private function validRuntimeKernelPipelinePlan(): array
    {
        return [
            'schema_version' => KernelPipelineContract::SCHEMA_VERSION,
            'pipeline_id' => 'pipe_runtime_worker',
            'mode' => KernelPipelineContract::MODE,
            'status' => KernelPipelineContract::STATUS,
            'canonical_flow_hash' => KernelPipelineContract::canonicalFlowHash(),
            'stage_order' => KernelPipelineStage::orderedValues(),
            'stage_count' => count(KernelPipelineStage::orderedValues()),
            'provider_execution_allowed' => false,
            'runtime_execution_allowed' => false,
            'execution_guards' => [
                'dry_run_effective' => true,
                'provider_execution_allowed' => false,
                'runtime_execution_allowed' => false,
                'surface_runtime_migration_allowed' => false,
            ],
            'input' => [
                'surface_id' => 'atlas_ai_chat',
                'safe_hints' => [
                    'flow' => 'programming.dev',
                ],
            ],
            'surface_binding' => [
                'surface' => 'atlas_ai_chat',
                'command' => 'atlas:ai:chat',
                'input_mode' => 'declared_dev_plan',
            ],
        ];
    }

    private function dropAiWorkerRuntimeTables(): void
    {
        foreach ([
            'ai_stream_events',
            'atlas_ledger_events',
            'ai_worker_events',
            'ai_job_attempts',
            'ai_jobs',
            'ai_traces',
            'ai_sessions',
            'ai_threads',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
