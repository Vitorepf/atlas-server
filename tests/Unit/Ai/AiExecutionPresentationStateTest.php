<?php

namespace Tests\Unit\Ai;

use App\Models\AiTrace;
use App\Services\Ai\AiExecutionPresentationState;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class AiExecutionPresentationStateTest extends TestCase
{
    public function test_provider_choice_becomes_a_public_attention_state(): void
    {
        $state = app(AiExecutionPresentationState::class)->providerChoice(
            errorCode: 'rate_limited',
            options: [
                ['id' => 'switch_provider', 'label' => 'Migrar para Claude', 'action' => 'switch_provider'],
                ['id' => 'cancel', 'label' => 'Cancelar job', 'action' => 'cancel'],
            ],
            resetAt: '2026-07-14T00:00:00Z',
        );

        $this->assertSame('atlas.execution.presentation.v1', $state['schema']);
        $this->assertSame('attention_required', $state['kind']);
        $this->assertSame('Escolha como continuar', $state['title']);
        $this->assertSame('provider', $state['checkpoint']);
        $this->assertSame('switch_provider', data_get($state, 'actions.0.id'));
        $this->assertSame('primary', data_get($state, 'actions.0.style'));
        $this->assertSame('destructive', data_get($state, 'actions.1.style'));
        $this->assertSame('2026-07-14T00:00:00Z', $state['deadline']);
        $this->assertArrayHasKey('paused_at', $state);
        $this->assertNotSame('', $state['paused_at']);
    }

    public function test_waiting_for_a_provider_reset_becomes_an_external_wait(): void
    {
        $state = app(AiExecutionPresentationState::class)->providerChoiceResolved(
            action: 'wait',
            resultingStatus: 'queued',
            availableAt: '2026-07-14T00:00:00Z',
        );

        $this->assertSame('awaiting_external', $state['kind']);
        $this->assertSame('Aguardando disponibilidade do provedor', $state['title']);
        $this->assertSame('2026-07-14T00:00:00Z', $state['deadline']);
        $this->assertSame([], $state['actions']);
        $this->assertArrayHasKey('paused_at', $state);
        $this->assertNotSame('', $state['paused_at']);
    }

    public function test_automatic_provider_fallback_is_a_public_recovery_without_provider_detail(): void
    {
        $state = app(AiExecutionPresentationState::class)->automaticProviderFallback();

        $this->assertSame('atlas.execution.presentation.v1', $state['schema']);
        $this->assertSame('recovering', $state['kind']);
        $this->assertSame('Execução retomando com alternativa', $state['title']);
        $this->assertSame('provider', $state['checkpoint']);
        $this->assertSame([], $state['actions']);
        $this->assertArrayNotHasKey('error_message', $state);
        $this->assertArrayNotHasKey('provider', $state);
    }

    public function test_stale_worker_recovery_is_public_without_the_timeout_diagnostic(): void
    {
        $state = app(AiExecutionPresentationState::class)->staleWorkerRecovery();

        $this->assertSame('recovering', $state['kind']);
        $this->assertSame('Recuperando execução interrompida', $state['title']);
        $this->assertSame('provider', $state['checkpoint']);
        $this->assertSame([], $state['actions']);
        $this->assertArrayNotHasKey('error_message', $state);
    }

    public function test_terminal_outcomes_have_public_states_without_invented_actions(): void
    {
        $states = app(AiExecutionPresentationState::class);

        $completed = $states->completed();
        $failed = $states->failed();
        $cancelled = $states->cancelled();

        $this->assertSame('completed', $completed['kind']);
        $this->assertSame('evidence', $completed['checkpoint']);
        $this->assertSame([], $completed['actions']);
        $this->assertSame('failed', $failed['kind']);
        $this->assertSame('provider', $failed['checkpoint']);
        $this->assertSame([], $failed['actions']);
        $this->assertSame('failed', $cancelled['kind']);
        $this->assertSame('Sessão encerrada', $cancelled['title']);
        $this->assertSame([], $cancelled['actions']);
    }

    public function test_replanning_state_reports_only_the_confirmed_repair_iteration(): void
    {
        $state = app(AiExecutionPresentationState::class)->replanning(
            currentIteration: 1,
            nextIteration: 2,
            maxIterations: 3,
        );

        $this->assertSame('replanning', $state['kind']);
        $this->assertSame('quality', $state['checkpoint']);
        $this->assertSame('A verificação pediu correção antes de concluir. Próxima tentativa 2 de 3.', $state['detail']);
        $this->assertSame([], $state['actions']);
    }

    public function test_public_timer_accumulates_only_active_execution_across_pause_and_resume(): void
    {
        $states = app(AiExecutionPresentationState::class);
        $trace = new AiTrace;
        $trace->forceFill([
            'created_at' => CarbonImmutable::parse('2026-07-14T00:00:00Z'),
            'metadata' => [],
        ]);

        $paused = $states->withTimer(
            $trace,
            ['schema' => AiExecutionPresentationState::SCHEMA, 'kind' => 'attention_required'],
            timing: 'paused',
            now: CarbonImmutable::parse('2026-07-14T00:00:05Z'),
        );

        $this->assertSame(5000, data_get($paused, 'timer.elapsed_active_ms'));
        $this->assertSame('paused', data_get($paused, 'timer.timing'));
        $this->assertSame('2026-07-14T00:00:05+00:00', data_get($paused, 'timer.paused_at'));

        $trace->metadata = ['presentation_state' => $paused];
        $resumed = $states->withTimer(
            $trace,
            ['schema' => AiExecutionPresentationState::SCHEMA, 'kind' => 'recovering'],
            timing: 'running',
            now: CarbonImmutable::parse('2026-07-14T00:01:05Z'),
        );

        $this->assertSame(5000, data_get($resumed, 'timer.elapsed_active_ms'));
        $this->assertSame('running', data_get($resumed, 'timer.timing'));
        $this->assertSame('2026-07-14T00:01:05+00:00', data_get($resumed, 'timer.running_since'));

        $trace->metadata = ['presentation_state' => $resumed];
        $pausedAgain = $states->withTimer(
            $trace,
            ['schema' => AiExecutionPresentationState::SCHEMA, 'kind' => 'awaiting_external'],
            timing: 'paused',
            now: CarbonImmutable::parse('2026-07-14T00:01:09Z'),
        );

        $this->assertSame(9000, data_get($pausedAgain, 'timer.elapsed_active_ms'));
        $this->assertSame('2026-07-14T00:01:09+00:00', data_get($pausedAgain, 'timer.paused_at'));
    }

    public function test_provider_choice_persists_the_paused_timer_on_the_real_public_state(): void
    {
        $trace = new AiTrace;
        $trace->forceFill([
            'created_at' => CarbonImmutable::parse('2026-07-14T00:00:00Z'),
            'metadata' => [],
        ]);

        $state = app(AiExecutionPresentationState::class)->providerChoice(
            errorCode: 'rate_limited',
            options: [['id' => 'wait', 'label' => 'Aguardar', 'action' => 'wait']],
            trace: $trace,
            now: CarbonImmutable::parse('2026-07-14T00:00:05Z'),
        );

        $this->assertSame('paused', data_get($state, 'timer.timing'));
        $this->assertSame(5000, data_get($state, 'timer.elapsed_active_ms'));
        $this->assertSame('2026-07-14T00:00:05+00:00', $state['paused_at']);
        $this->assertSame($state['paused_at'], data_get($state, 'timer.paused_at'));
    }
}
