<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerGuardedProcessStartExecutor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Concerns\CreatesSelfConstructionControlPlaneTables;
use Tests\Concerns\MakesCodexRealInvokerPostStartFixtures;
use Tests\Concerns\MakesSelfConstructionAgentRuns;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerGuardedProcessStartExecutorTest extends TestCase
{
    use MakesCodexRealInvokerPostStartFixtures;
    use CreatesSelfConstructionControlPlaneTables;
    use MakesSelfConstructionAgentRuns;

    public function test_guarded_process_start_prepares_executor_without_starting_codex(): void
    {
        $this->createActivationPreparedRun();

        $result = app(AgentCodexRealInvokerGuardedProcessStartExecutor::class)
            ->prepareGuardedStart($this->validInput());

        $this->assertSame('codex_real_invoker_guarded_process_start_prepared', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['real_invoker_guarded_process_start_prepared']);
        $this->assertTrue($result['executor_enabled']);
        $this->assertTrue($result['process_start_armed']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['dispatch_allowed']);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-guarded-process-start-001',
        ]);
    }

    public function test_guarded_process_start_is_idempotent_for_same_guarded_start_id(): void
    {
        $this->createActivationPreparedRun();
        $executor = app(AgentCodexRealInvokerGuardedProcessStartExecutor::class);

        $first = $executor->prepareGuardedStart($this->validInput());
        $second = $executor->prepareGuardedStart($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_guarded_process_start_rejects_duplicate_guarded_start_for_different_id(): void
    {
        $this->createActivationPreparedRun();
        $executor = app(AgentCodexRealInvokerGuardedProcessStartExecutor::class);
        $executor->prepareGuardedStart($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_guarded_process_start_already_prepared');

        $executor->prepareGuardedStart(array_merge($this->validInput(), [
            'real_invoker_guarded_process_start_id' => 'codex-real-invoker-guarded-process-start-002',
        ]));
    }

    public function test_guarded_process_start_rejects_missing_activation_metadata(): void
    {
        $this->createActivationPreparedRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_supervised_start_activation_missing_or_mismatch');

        app(AgentCodexRealInvokerGuardedProcessStartExecutor::class)
            ->prepareGuardedStart($this->validInput());
    }

    public function test_guarded_process_start_rejects_missing_dry_run_rehearsal_hash(): void
    {
        $this->createActivationPreparedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_dry_run_rehearsal_hash');

        app(AgentCodexRealInvokerGuardedProcessStartExecutor::class)
            ->prepareGuardedStart(array_merge($this->validInput(), [
                'dry_run_rehearsal_hash' => '',
            ]));
    }

    public function test_guarded_process_start_rejects_activation_with_process_started_flag(): void
    {
        $this->createActivationPreparedRun([
            'metadata' => $this->metadataWithActivation(['external_process_started' => true]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('external_process_started_already_true');

        app(AgentCodexRealInvokerGuardedProcessStartExecutor::class)
            ->prepareGuardedStart($this->validInput());
    }

    public function test_guarded_process_start_rolls_back_metadata_when_ledger_write_fails(): void
    {
        $this->createActivationPreparedRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerGuardedProcessStartExecutor::class)
                ->prepareGuardedStart($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($run->metadata, 'codex_real_invoker_guarded_process_start'));
        }
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
     * @param  array<string,mixed>  $activationOverrides
     * @return array<string,mixed>
     */
    private function metadataWithActivation(array $activationOverrides = []): array
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
            ], $activationOverrides),
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
