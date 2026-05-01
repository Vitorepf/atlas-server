<?php

namespace Tests\Feature\Console;

use App\Models\AiJob;
use App\Models\AiTrace;
use App\Services\Ai\AiProviderChoiceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiChatProviderChoiceTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertSame('resolved', data_get($job->metadata, 'provider_choice_state'));
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
}
