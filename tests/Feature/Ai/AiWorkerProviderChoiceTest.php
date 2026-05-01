<?php

namespace Tests\Feature\Ai;

use App\Models\AiJob;
use App\Models\AiTrace;
use App\Models\AiWorkerEvent;
use App\Services\Ai\AiPermissionDecision;
use App\Services\Ai\AiPermissionEngine;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\AiWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiWorkerProviderChoiceTest extends TestCase
{
    use RefreshDatabase;

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

        $event = AiWorkerEvent::where('event_type', 'provider_choice_required')->first();
        $this->assertNotNull($event);
        $this->assertSame($job->id, $event->ai_job_id);
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

    private function mockProviderManagerWith(AiProviderResult $result): void
    {
        $provider = new class($result) implements AiProvider {
            public function __construct(private readonly AiProviderResult $result) {}

            public function key(): string { return 'codex_cli'; }

            public function run(\App\Models\AiJob $job, string $prompt): AiProviderResult
            {
                return $this->result;
            }

            public function runStreaming(\App\Models\AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
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
}
