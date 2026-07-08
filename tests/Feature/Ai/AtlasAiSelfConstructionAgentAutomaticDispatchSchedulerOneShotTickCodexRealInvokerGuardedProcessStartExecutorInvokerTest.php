<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorInvoker;
use InvalidArgumentException;
use Tests\Concerns\CreatesSelfConstructionControlPlaneTables;
use Tests\Concerns\MakesCodexRealInvokerPostStartFixtures;
use Tests\Concerns\MakesSelfConstructionAgentRuns;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorInvokerTest extends TestCase
{
    use MakesCodexRealInvokerPostStartFixtures;
    use CreatesSelfConstructionControlPlaneTables;
    use MakesSelfConstructionAgentRuns;

    public function test_invoker_prepares_guarded_process_start_without_starting_codex(): void
    {
        $this->createActivationPreparedRun();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorInvoker::class)
            ->prepareCodexRealInvokerGuardedProcessStart($this->validInput());

        $this->assertSame('one_shot_scheduler_codex_real_invoker_guarded_process_start_prepared', $result['status']);
        $this->assertTrue($result['codex_real_invoker_guarded_process_start_executor_invoked']);
        $this->assertSame(1, $result['codex_real_invoker_guarded_process_start_executor_invocation_count']);
        $this->assertTrue($result['real_invoker_guarded_process_start_prepared']);
        $this->assertTrue($result['executor_enabled']);
        $this->assertTrue($result['process_start_armed']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertSame('activate_signed_one_shot_scheduler_tick_codex_real_invoker_final_process_start_authorization_gate_contract', $result['next_required_slice']);

        $observed = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:attempt-001')
            ->firstOrFail();

        $this->assertSame(
            'real_invoker_guarded_process_start_prepared_disabled_pending_final_start',
            data_get($observed->metadata, 'codex_real_invoker_guarded_process_start.status')
        );
        $this->assertFalse((bool) data_get($observed->metadata, 'codex_real_invoker_guarded_process_start.external_process_started'));
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-guarded-process-start-001',
        ]);
    }

    public function test_invoker_is_idempotent_for_same_guarded_start_id(): void
    {
        $this->createActivationPreparedRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorInvoker::class);

        $first = $invoker->prepareCodexRealInvokerGuardedProcessStart($this->validInput());
        $second = $invoker->prepareCodexRealInvokerGuardedProcessStart($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_invoker_rejects_invalid_dry_run_rehearsal_hash_without_updating_run(): void
    {
        $this->createActivationPreparedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_dry_run_rehearsal_hash');

        try {
            app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorInvoker::class)
                ->prepareCodexRealInvokerGuardedProcessStart(array_merge($this->validInput(), [
                    'dry_run_rehearsal_hash' => 'not-a-hash',
                ]));
        } finally {
            $observed = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($observed->metadata, 'codex_real_invoker_guarded_process_start'));
        }
    }

    public function test_invoker_rejects_missing_supervised_activation_metadata(): void
    {
        $this->createActivationPreparedRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_supervised_start_activation_missing_or_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorInvoker::class)
            ->prepareCodexRealInvokerGuardedProcessStart($this->validInput());
    }

    public function test_invoker_rejects_duplicate_guarded_start_id(): void
    {
        $this->createActivationPreparedRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorInvoker::class);
        $invoker->prepareCodexRealInvokerGuardedProcessStart($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_guarded_process_start_already_prepared');

        $invoker->prepareCodexRealInvokerGuardedProcessStart(array_merge($this->validInput(), [
            'real_invoker_guarded_process_start_id' => 'codex-real-invoker-guarded-process-start-002',
        ]));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createActivationPreparedRun(array $overrides = []): void
    {
        $this->makeAgentRun(array_merge([
            'run_key' => 'provider-start:attempt-001',
            'status' => 'adapter_invocation_prepared',
            'summary' => 'Codex real invoker supervised start activation prepared; process start remains disabled.',
            'metadata' => $this->metadataWithActivation(),
        ], $overrides));
    }

    /**
     * @return array<string,mixed>
     */
    private function metadataWithActivation(): array
    {
        return [
            'codex_real_invoker_supervised_start_activation' => array_merge($this->baseChainProcessStartFirst(), [
                'real_invoker_executor_enablement_id' => 'codex-real-invoker-executor-enable-001',
                'real_invoker_supervised_start_activation_id' => 'codex-real-invoker-supervised-start-activation-001',
                'operator_start_activation_receipt_hash' => str_repeat('a', 64),
                'start_window_hash' => str_repeat('b', 64),
                'process_start_guard_hash' => str_repeat('c', 64),
                'supervisor_observer_hash' => str_repeat('d', 64),
                'pid_guard_hash' => str_repeat('e', 64),
                'cwd_integrity_hash' => str_repeat('f', 64),
                'operator_enablement_receipt_hash' => str_repeat('4', 64),
                'enablement_policy_hash' => str_repeat('6', 64),
                'pre_start_checklist_hash' => str_repeat('7', 64),
                'disable_switch_hash' => str_repeat('8', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'real_invoker_supervised_start_activation_prepared_pending_process_start',
                'real_invoker_supervised_start_activation_prepared' => true,
                'executor_enabled' => true,
                'process_start_armed' => true,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ]),
        ];
    }

    /**
     * @return array<string,string>
     */

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return array_merge($this->baseChainProcessStartFirst(), [
            'run_key' => 'provider-start:attempt-001',
            'real_invoker_executor_enablement_id' => 'codex-real-invoker-executor-enable-001',
            'real_invoker_supervised_start_activation_id' => 'codex-real-invoker-supervised-start-activation-001',
            'real_invoker_guarded_process_start_id' => 'codex-real-invoker-guarded-process-start-001',
            'operator_guarded_start_receipt_hash' => str_repeat('1', 64),
            'process_runner_contract_hash' => str_repeat('2', 64),
            'dry_run_rehearsal_hash' => str_repeat('3', 64),
            'launch_invocation_contract_hash' => str_repeat('4', 64),
            'post_start_observability_hash' => str_repeat('5', 64),
            'revoke_guard_hash' => str_repeat('6', 64),
            'operator_start_activation_receipt_hash' => str_repeat('a', 64),
            'start_window_hash' => str_repeat('b', 64),
            'process_start_guard_hash' => str_repeat('c', 64),
            'supervisor_observer_hash' => str_repeat('d', 64),
            'pid_guard_hash' => str_repeat('e', 64),
            'cwd_integrity_hash' => str_repeat('f', 64),
            'operator_enablement_receipt_hash' => str_repeat('4', 64),
            'enablement_policy_hash' => str_repeat('6', 64),
            'pre_start_checklist_hash' => str_repeat('7', 64),
            'disable_switch_hash' => str_repeat('8', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'prepare_guarded_process_start_without_starting_codex',
        ]);
    }

}
