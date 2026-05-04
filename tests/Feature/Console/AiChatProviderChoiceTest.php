<?php

namespace Tests\Feature\Console;

use App\Models\AiJob;
use App\Models\AiTrace;
use App\Services\Ai\AiProviderChoiceResolver;
use App\Services\Ai\FairClaudePolicy;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAiJobChoiceTables;
use Tests\TestCase;

class AiChatProviderChoiceTest extends TestCase
{
    use CreatesAiJobChoiceTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAiJobChoiceTables();
    }

    protected function tearDown(): void
    {
        $this->dropAiJobChoiceTables();

        parent::tearDown();
    }

    public function test_operator_picking_switch_provider_requeues_job(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'olá',
            'status' => 'processing',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'awaiting_user_choice',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'olá',
            'prompt' => 'olá',
            'available_at' => now()->addYear(),
            'metadata' => [
                'provider_choice_state' => 'pending',
                'provider_choice_error_code' => 'rate_limited',
                'reset_hint' => 'May 5th, 2026 10:24 AM',
                'choice_options' => [
                    ['id' => 'switch_provider', 'action' => 'switch_provider', 'provider' => 'claude_cli', 'model' => null, 'label' => 'Migrar para Claude', 'description' => ''],
                    ['id' => 'cancel', 'action' => 'cancel', 'label' => 'Cancelar', 'description' => ''],
                ],
            ],
        ]);

        app(AiProviderChoiceResolver::class)->resolve($job->refresh(), 'switch_provider');

        $job->refresh();
        $this->assertSame('queued', $job->status);
        $this->assertSame('claude_cli', $job->provider);
        $this->assertNull(data_get($job->metadata, 'provider_choice_state'));
    }

    public function test_operator_picking_retry_same_resets_choice_state(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'olá',
            'status' => 'processing',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'awaiting_user_choice',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'olá',
            'prompt' => 'olá',
            'available_at' => now()->addYear(),
            'metadata' => [
                'provider_choice_state' => 'pending',
                'choice_options' => [
                    ['id' => 'retry_same', 'action' => 'retry_same', 'provider' => 'codex_cli', 'model' => null, 'label' => 'Tentar de novo', 'description' => ''],
                ],
            ],
        ]);

        app(AiProviderChoiceResolver::class)->resolve($job->refresh(), 'retry_same');

        $job->refresh();
        $this->assertSame('queued', $job->status);
        $this->assertNull(data_get($job->metadata, 'provider_choice_state'),
            'retry_same deve resetar o flag para permitir nova pausa.');
    }

    public function test_fair_mode_rejects_tampered_switch_provider_choice(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'olá',
            'status' => 'processing',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'awaiting_user_choice',
            'agent_slug' => 'orquestrador',
            'provider' => 'claude_cli',
            'model' => 'claude-opus-4-7',
            'input_text' => 'olá',
            'prompt' => 'olá',
            'available_at' => now()->addYear(),
            'payload' => [
                'fair_mode' => app(FairClaudePolicy::class)->metadata(),
            ],
            'metadata' => [
                'provider_choice_state' => 'pending',
                'choice_options' => [
                    ['id' => 'switch_provider', 'action' => 'switch_provider', 'provider' => 'codex_cli', 'model' => null],
                    ['id' => 'retry_same', 'action' => 'retry_same', 'provider' => 'claude_cli', 'model' => null],
                ],
            ],
        ]);

        $this->expectException(\App\Services\Ai\AiProviderChoiceException::class);

        app(AiProviderChoiceResolver::class)->resolve($job->refresh(), 'switch_provider');
    }

    public function test_chat_manual_provider_fails_before_enqueue_when_app_blocks_manual_use(): void
    {
        config([
            'atlas.ai.providers.gemini_cli.allow_manual' => false,
        ]);

        $exitCode = Artisan::call('atlas:ai:chat', [
            'input' => 'analise sem executar',
            '--provider' => 'gemini_cli',
            '--no-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame(false, data_get($payload, 'ok'));
        $this->assertSame('atlas_manual_provider_blocked', data_get($payload, 'error'));
        $this->assertSame('gemini_cli', data_get($payload, 'provider'));
    }

    public function test_chat_claude_only_rejects_codex_provider_before_enqueue(): void
    {
        $exitCode = Artisan::call('atlas:ai:chat', [
            'input' => 'implemente sem executar',
            '--claude-only' => true,
            '--provider' => 'codex_cli',
            '--no-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse(data_get($payload, 'ok'));
        $this->assertSame('fair_mode_violation', data_get($payload, 'error'));
        $this->assertSame('codex_cli', data_get($payload, 'details.provider'));
    }
}
