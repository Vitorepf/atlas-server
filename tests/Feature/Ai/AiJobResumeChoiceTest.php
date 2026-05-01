<?php

namespace Tests\Feature\Ai;

use App\Models\AiJob;
use App\Models\AiTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiJobResumeChoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.token', 'testing-atlas-token-with-enough-length');
    }

    private function authed(): static
    {
        return $this->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length');
    }

    public function test_resume_with_switch_provider_requeues_with_new_provider(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'switch_provider', 'action' => 'switch_provider', 'provider' => 'claude_cli', 'model' => null],
            ['id' => 'cancel', 'action' => 'cancel'],
        ]);

        $response = $this->authed()->postJson("/ai/jobs/{$job->id}/resume-choice", [
            'option_id' => 'switch_provider',
        ]);

        $response->assertOk();
        $response->assertJsonPath('job.provider_choice_state', 'resolved');
        $job->refresh();
        $this->assertSame('queued', $job->status);
        $this->assertSame('claude_cli', $job->provider);
        $this->assertSame('resolved', data_get($job->metadata, 'provider_choice_state'));
        $this->assertTrue($job->available_at->lessThanOrEqualTo(now()->addSecond()));
    }

    public function test_resume_with_wait_sets_available_at(): void
    {
        $waitUntil = now()->addHours(3)->toIso8601String();

        $job = $this->makePausedJob([
            ['id' => 'wait_for_reset', 'action' => 'wait', 'available_at_iso' => $waitUntil],
            ['id' => 'cancel', 'action' => 'cancel'],
        ]);

        $response = $this->authed()->postJson("/ai/jobs/{$job->id}/resume-choice", [
            'option_id' => 'wait_for_reset',
        ]);

        $response->assertOk();
        $job->refresh();
        $this->assertSame('queued', $job->status);
        $this->assertSame($waitUntil, $job->available_at->toIso8601String());
    }

    public function test_resume_with_cancel_marks_job_cancelled(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'switch_provider', 'action' => 'switch_provider', 'provider' => 'claude_cli'],
            ['id' => 'cancel', 'action' => 'cancel'],
        ]);

        $response = $this->authed()->postJson("/ai/jobs/{$job->id}/resume-choice", [
            'option_id' => 'cancel',
        ]);

        $response->assertOk();
        $job->refresh();
        $this->assertSame('cancelled', $job->status);
    }

    public function test_resume_rejects_unknown_option(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'switch_provider', 'action' => 'switch_provider', 'provider' => 'claude_cli'],
        ]);

        $response = $this->authed()->postJson("/ai/jobs/{$job->id}/resume-choice", [
            'option_id' => 'banana',
        ]);

        $response->assertStatus(422);
        $this->assertSame('AI_JOB_CHOICE_NOT_FOUND', $response->json('error.code'));
    }

    public function test_resume_rejects_when_not_awaiting_choice(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'cancel', 'action' => 'cancel'],
        ]);
        $job->update(['status' => 'queued', 'metadata' => ['provider_choice_state' => 'resolved']]);

        $response = $this->authed()->postJson("/ai/jobs/{$job->id}/resume-choice", [
            'option_id' => 'cancel',
        ]);

        $response->assertStatus(422);
        $this->assertSame('AI_JOB_NOT_AWAITING_CHOICE', $response->json('error.code'));
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
