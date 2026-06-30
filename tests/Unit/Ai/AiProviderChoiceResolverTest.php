<?php

namespace Tests\Unit\Ai;

use App\Models\AiJob;
use App\Models\AiTrace;
use App\Services\Ai\AiProviderChoiceException;
use App\Services\Ai\AiProviderChoiceResolver;
use Tests\Concerns\CreatesAiJobChoiceTables;
use Tests\TestCase;

class AiProviderChoiceResolverTest extends TestCase
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

    public function test_switch_provider_requeues_with_new_provider(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'switch_provider', 'action' => 'switch_provider', 'provider' => 'claude_cli', 'model' => null],
        ]);

        $result = app(AiProviderChoiceResolver::class)->resolve($job, 'switch_provider');

        $job->refresh();
        $this->assertSame('queued', $job->status);
        $this->assertSame('claude_cli', $job->provider);
        $this->assertNull(data_get($job->metadata, 'provider_choice_state'),
            'requeue actions reset state to null so a new failure can show the menu again');
        $this->assertSame('switch_provider', $result['action']);
    }

    public function test_downgrade_model_keeps_provider_and_changes_model(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'downgrade_model', 'action' => 'downgrade_model', 'provider' => 'codex_cli', 'model' => 'gpt-5-4-mini'],
        ]);

        $result = app(AiProviderChoiceResolver::class)->resolve($job, 'downgrade_model');

        $job->refresh();
        $this->assertSame('queued', $job->status);
        $this->assertSame('codex_cli', $job->provider);
        $this->assertSame('gpt-5-4-mini', $job->model);
        $this->assertNull(data_get($job->metadata, 'provider_choice_state'),
            'requeue actions reset state to null so a new failure can show the menu again');
    }

    public function test_wait_sets_available_at_from_option(): void
    {
        $waitUntil = now()->addHours(3)->toIso8601String();
        $job = $this->makePausedJob([
            ['id' => 'wait_for_reset', 'action' => 'wait', 'available_at_iso' => $waitUntil],
        ]);

        app(AiProviderChoiceResolver::class)->resolve($job, 'wait_for_reset');

        $job->refresh();
        $this->assertSame('queued', $job->status);
        $this->assertSame($waitUntil, $job->available_at->toIso8601String());
    }

    public function test_fail_marks_job_failed_with_login_message(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'login_required', 'action' => 'fail', 'reason' => 'login_required', 'cli_command' => 'codex login'],
        ]);

        app(AiProviderChoiceResolver::class)->resolve($job, 'login_required');

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertSame('login_required', $job->error_code);
        $this->assertStringContainsString('codex login', $job->error_message);
    }

    public function test_cancel_marks_job_cancelled(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'cancel', 'action' => 'cancel'],
        ]);

        app(AiProviderChoiceResolver::class)->resolve($job, 'cancel');

        $job->refresh();
        $this->assertSame('cancelled', $job->status);
    }

    public function test_retry_same_requeues_and_resets_choice_state(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'retry_same', 'action' => 'retry_same', 'provider' => 'codex_cli', 'model' => null],
        ]);

        app(AiProviderChoiceResolver::class)->resolve($job, 'retry_same');

        $job->refresh();
        $this->assertSame('queued', $job->status);
        $this->assertSame('codex_cli', $job->provider);
        $this->assertSame('gpt-5.5', $job->model, 'Modelo original deve ser preservado em retry_same.');
        $this->assertNull(data_get($job->metadata, 'provider_choice_state'),
            'retry_same deve resetar o flag pra null pra permitir nova pausa.');
        $this->assertTrue((bool) data_get($job->metadata, 'provider_choice_resolved_via_retry'));
    }

    public function test_throws_when_job_not_awaiting_choice(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'cancel', 'action' => 'cancel'],
        ]);
        $job->update(['status' => 'queued']);

        $this->expectException(AiProviderChoiceException::class);
        try {
            app(AiProviderChoiceResolver::class)->resolve($job, 'cancel');
        } catch (AiProviderChoiceException $e) {
            $this->assertSame('NOT_AWAITING_CHOICE', $e->errorCode);
            throw $e;
        }
    }

    public function test_throws_when_option_not_found(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'cancel', 'action' => 'cancel'],
        ]);

        $this->expectException(AiProviderChoiceException::class);
        try {
            app(AiProviderChoiceResolver::class)->resolve($job, 'banana');
        } catch (AiProviderChoiceException $e) {
            $this->assertSame('OPTION_NOT_FOUND', $e->errorCode);
            throw $e;
        }
    }

    // ── provider_choice_outcome_receipt ──────────────────────────────────────

    public function test_receipt_present_for_switch_provider(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'switch_provider', 'action' => 'switch_provider', 'provider' => 'claude_cli', 'model' => null],
        ]);

        app(AiProviderChoiceResolver::class)->resolve($job, 'switch_provider');
        $job->refresh();

        $receipt = data_get($job->metadata, 'provider_choice_outcome_receipt');
        $this->assertIsArray($receipt);
        $this->assertSame('switch_provider', $receipt['action']);
        $this->assertSame('switch_provider', $receipt['option_id']);
        $this->assertSame('codex_cli', $receipt['previous_provider']);
        $this->assertSame('claude_cli', $receipt['resulting_provider']);
        $this->assertSame('queued', $receipt['resulting_status']);
        $this->assertFalse($receipt['external_provider_call']);
        $this->assertFalse($receipt['provider_tokens_spent']);
        $this->assertArrayHasKey('resolved_at', $receipt);
    }

    public function test_receipt_present_for_fail(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'login_required', 'action' => 'fail', 'reason' => 'login_required', 'cli_command' => 'codex login'],
        ]);

        app(AiProviderChoiceResolver::class)->resolve($job, 'login_required');
        $job->refresh();

        $receipt = data_get($job->metadata, 'provider_choice_outcome_receipt');
        $this->assertIsArray($receipt);
        $this->assertSame('fail', $receipt['action']);
        $this->assertSame('failed', $receipt['resulting_status']);
        $this->assertFalse($receipt['external_provider_call']);
        $this->assertFalse($receipt['provider_tokens_spent']);
    }

    public function test_receipt_present_for_cancel(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'cancel', 'action' => 'cancel'],
        ]);

        app(AiProviderChoiceResolver::class)->resolve($job, 'cancel');
        $job->refresh();

        $receipt = data_get($job->metadata, 'provider_choice_outcome_receipt');
        $this->assertSame('cancelled', $receipt['resulting_status']);
        $this->assertFalse($receipt['external_provider_call']);
    }

    public function test_receipt_present_for_retry_same(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'retry_same', 'action' => 'retry_same'],
        ]);

        app(AiProviderChoiceResolver::class)->resolve($job, 'retry_same');
        $job->refresh();

        $receipt = data_get($job->metadata, 'provider_choice_outcome_receipt');
        $this->assertSame('retry_same', $receipt['action']);
        $this->assertSame('queued', $receipt['resulting_status']);
    }

    public function test_receipt_previous_fields_capture_original_job_state(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'downgrade_model', 'action' => 'downgrade_model', 'model' => 'gpt-5-4-mini'],
        ]);

        app(AiProviderChoiceResolver::class)->resolve($job, 'downgrade_model');
        $job->refresh();

        $receipt = data_get($job->metadata, 'provider_choice_outcome_receipt');
        $this->assertSame('codex_cli', $receipt['previous_provider']);
        $this->assertSame('gpt-5.5', $receipt['previous_model']);
        $this->assertSame('gpt-5-4-mini', $receipt['resulting_model']);
    }

    private function makePausedJob(array $options): AiJob
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'olá',
            'status' => 'queued',
        ]);

        return AiJob::create([
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
                'choice_options' => $options,
            ],
        ]);
    }
}
