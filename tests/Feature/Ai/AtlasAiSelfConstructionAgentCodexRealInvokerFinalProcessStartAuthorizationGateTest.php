<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexRealInvokerFinalProcessStartAuthorizationGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Concerns\CreatesSelfConstructionControlPlaneTables;
use Tests\Concerns\MakesSelfConstructionAgentRuns;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerFinalProcessStartAuthorizationGateTest extends TestCase
{
    use CreatesSelfConstructionControlPlaneTables;
    use MakesSelfConstructionAgentRuns;

    public function test_final_process_start_authorization_records_receipt_without_starting_codex(): void
    {
        $this->createGuardedStartRun();

        $result = app(AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class)
            ->authorizeFinalStart($this->validInput());

        $this->assertSame('codex_real_invoker_final_process_start_authorization_prepared', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['real_invoker_final_process_start_authorization_prepared']);
        $this->assertTrue($result['final_process_start_authorized']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['dispatch_allowed']);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-final-start-auth-001',
        ]);
    }

    public function test_final_process_start_authorization_is_idempotent_for_same_authorization_id(): void
    {
        $this->createGuardedStartRun();
        $gate = app(AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class);

        $first = $gate->authorizeFinalStart($this->validInput());
        $second = $gate->authorizeFinalStart($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_final_process_start_authorization_rejects_duplicate_authorization_for_different_id(): void
    {
        $this->createGuardedStartRun();
        $gate = app(AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class);
        $gate->authorizeFinalStart($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_final_process_start_authorization_already_prepared');

        $gate->authorizeFinalStart(array_merge($this->validInput(), [
            'real_invoker_final_process_start_authorization_id' => 'codex-real-invoker-final-start-auth-002',
        ]));
    }

    public function test_final_process_start_authorization_rejects_missing_guarded_start_metadata(): void
    {
        $this->createGuardedStartRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_guarded_process_start_missing_or_mismatch');

        app(AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class)
            ->authorizeFinalStart($this->validInput());
    }

    public function test_final_process_start_authorization_rejects_missing_final_signature_hash(): void
    {
        $this->createGuardedStartRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_final_start_signature_hash');

        app(AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class)
            ->authorizeFinalStart(array_merge($this->validInput(), [
                'final_start_signature_hash' => '',
            ]));
    }

    public function test_final_process_start_authorization_rejects_guarded_start_with_actual_start_allowed_flag(): void
    {
        $this->createGuardedStartRun([
            'metadata' => $this->metadataWithGuardedStart(['actual_process_start_allowed' => true]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('actual_process_start_allowed_already_true');

        app(AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class)
            ->authorizeFinalStart($this->validInput());
    }

    public function test_final_process_start_authorization_rolls_back_metadata_when_ledger_write_fails(): void
    {
        $this->createGuardedStartRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class)
                ->authorizeFinalStart($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($run->metadata, 'codex_real_invoker_final_process_start_authorization'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createGuardedStartRun(array $overrides = []): void
    {
        $this->makeAgentRun(array_merge([
            'run_key' => 'provider-start:attempt-001',
            'status' => 'adapter_invocation_prepared',
            'summary' => 'Codex real invoker guarded process start prepared; actual process start remains disabled.',
            'metadata' => $this->metadataWithGuardedStart(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $guardedStartOverrides
     * @return array<string,mixed>
     */
    private function metadataWithGuardedStart(array $guardedStartOverrides = []): array
    {
        return [
            'codex_real_invoker_guarded_process_start' => array_merge([
                'codex_execution_id' => 'codex-execution-001',
                'real_invoker_executor_plan_id' => 'codex-real-invoker-executor-plan-001',
                'real_invoker_executor_fresh_release_id' => 'codex-real-invoker-fresh-release-001',
                'real_invoker_executor_enablement_id' => 'codex-real-invoker-executor-enable-001',
                'real_invoker_supervised_start_activation_id' => 'codex-real-invoker-supervised-start-activation-001',
                'real_invoker_guarded_process_start_id' => 'codex-real-invoker-guarded-process-start-001',
                'operator_guarded_start_receipt_hash' => str_repeat('1', 64),
                'process_runner_contract_hash' => str_repeat('2', 64),
                'dry_run_rehearsal_hash' => str_repeat('3', 64),
                'launch_invocation_contract_hash' => str_repeat('4', 64),
                'post_start_observability_hash' => str_repeat('5', 64),
                'revoke_guard_hash' => str_repeat('6', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'real_invoker_guarded_process_start_prepared_disabled_pending_final_start',
                'real_invoker_guarded_process_start_prepared' => true,
                'executor_enabled' => true,
                'process_start_armed' => true,
                'actual_process_start_allowed' => false,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], $guardedStartOverrides),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return [
            'run_key' => 'provider-start:attempt-001',
            'codex_execution_id' => 'codex-execution-001',
            'real_invoker_executor_plan_id' => 'codex-real-invoker-executor-plan-001',
            'real_invoker_executor_fresh_release_id' => 'codex-real-invoker-fresh-release-001',
            'real_invoker_executor_enablement_id' => 'codex-real-invoker-executor-enable-001',
            'real_invoker_supervised_start_activation_id' => 'codex-real-invoker-supervised-start-activation-001',
            'real_invoker_guarded_process_start_id' => 'codex-real-invoker-guarded-process-start-001',
            'real_invoker_final_process_start_authorization_id' => 'codex-real-invoker-final-start-auth-001',
            'operator_final_start_receipt_hash' => str_repeat('a', 64),
            'final_start_signature_hash' => str_repeat('b', 64),
            'final_start_policy_hash' => str_repeat('c', 64),
            'final_start_window_hash' => str_repeat('d', 64),
            'final_start_replay_guard_hash' => str_repeat('e', 64),
            'final_start_kill_switch_hash' => str_repeat('f', 64),
            'operator_guarded_start_receipt_hash' => str_repeat('1', 64),
            'process_runner_contract_hash' => str_repeat('2', 64),
            'dry_run_rehearsal_hash' => str_repeat('3', 64),
            'launch_invocation_contract_hash' => str_repeat('4', 64),
            'post_start_observability_hash' => str_repeat('5', 64),
            'revoke_guard_hash' => str_repeat('6', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'record_final_process_start_authorization_without_starting_codex',
        ];
    }

}
