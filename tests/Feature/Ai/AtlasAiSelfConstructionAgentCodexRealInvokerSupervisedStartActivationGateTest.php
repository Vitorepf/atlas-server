<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerSupervisedStartActivationGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Concerns\CreatesSelfConstructionControlPlaneTables;
use Tests\Concerns\MakesCodexRealInvokerPostStartFixtures;
use Tests\Concerns\MakesSelfConstructionAgentRuns;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerSupervisedStartActivationGateTest extends TestCase
{
    use MakesCodexRealInvokerPostStartFixtures;
    use CreatesSelfConstructionControlPlaneTables;
    use MakesSelfConstructionAgentRuns;

    public function test_activation_prepares_supervised_start_without_starting_codex(): void
    {
        $this->createExecutorEnabledRun();

        $result = app(AgentCodexRealInvokerSupervisedStartActivationGate::class)
            ->prepareActivation($this->validInput());

        $this->assertSame('codex_real_invoker_supervised_start_activation_prepared', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['real_invoker_supervised_start_activation_prepared']);
        $this->assertTrue($result['executor_enabled']);
        $this->assertTrue($result['process_start_armed']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['dispatch_allowed']);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-supervised-start-activation-001',
        ]);
    }

    public function test_activation_is_idempotent_for_same_activation_id(): void
    {
        $this->createExecutorEnabledRun();
        $gate = app(AgentCodexRealInvokerSupervisedStartActivationGate::class);

        $first = $gate->prepareActivation($this->validInput());
        $second = $gate->prepareActivation($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_activation_rejects_duplicate_activation_for_different_id(): void
    {
        $this->createExecutorEnabledRun();
        $gate = app(AgentCodexRealInvokerSupervisedStartActivationGate::class);
        $gate->prepareActivation($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_supervised_start_activation_already_prepared');

        $gate->prepareActivation(array_merge($this->validInput(), [
            'real_invoker_supervised_start_activation_id' => 'codex-real-invoker-supervised-start-activation-002',
        ]));
    }

    public function test_activation_rejects_missing_executor_enablement_metadata(): void
    {
        $this->createExecutorEnabledRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_executor_enablement_missing_or_mismatch');

        app(AgentCodexRealInvokerSupervisedStartActivationGate::class)
            ->prepareActivation($this->validInput());
    }

    public function test_activation_rejects_missing_process_start_guard_hash(): void
    {
        $this->createExecutorEnabledRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_process_start_guard_hash');

        app(AgentCodexRealInvokerSupervisedStartActivationGate::class)
            ->prepareActivation(array_merge($this->validInput(), [
                'process_start_guard_hash' => '',
            ]));
    }

    public function test_activation_rejects_enablement_with_process_started_flag(): void
    {
        $this->createExecutorEnabledRun([
            'metadata' => $this->metadataWithExecutorEnablement(['external_process_started' => true]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('external_process_started_already_true');

        app(AgentCodexRealInvokerSupervisedStartActivationGate::class)
            ->prepareActivation($this->validInput());
    }

    public function test_activation_rolls_back_metadata_when_ledger_write_fails(): void
    {
        $this->createExecutorEnabledRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerSupervisedStartActivationGate::class)
                ->prepareActivation($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($run->metadata, 'codex_real_invoker_supervised_start_activation'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createExecutorEnabledRun(array $overrides = []): void
    {
        $this->makeAgentRun(array_merge([
            'run_key' => 'provider-start:attempt-001',
            'status' => 'adapter_invocation_prepared',
            'summary' => 'Codex real invoker executor enabled; process start remains disabled.',
            'metadata' => $this->metadataWithExecutorEnablement(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $enablementOverrides
     * @return array<string,mixed>
     */
    private function metadataWithExecutorEnablement(array $enablementOverrides = []): array
    {
        return [
            'codex_real_invoker_executor_enablement' => array_merge($this->baseChainProcessStartFirst(), [
                'real_invoker_executor_enablement_id' => 'codex-real-invoker-executor-enable-001',
                'operator_enablement_receipt_hash' => str_repeat('4', 64),
                'enablement_policy_hash' => str_repeat('6', 64),
                'pre_start_checklist_hash' => str_repeat('7', 64),
                'disable_switch_hash' => str_repeat('8', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'real_invoker_executor_enabled_pending_supervised_start',
                'real_invoker_executor_enabled' => true,
                'executor_enabled' => true,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], $enablementOverrides),
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
            'reason' => 'prepare_supervised_start_activation_without_starting_codex',
        ]);
    }

}
