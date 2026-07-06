<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverInvoker;
use InvalidArgumentException;
use Tests\Concerns\CreatesSelfConstructionControlPlaneTables;
use Tests\Concerns\MakesSelfConstructionAgentRuns;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverInvokerTest extends TestCase
{
    use CreatesSelfConstructionControlPlaneTables;
    use MakesSelfConstructionAgentRuns;

    public function test_invoker_prepares_external_process_runtime_driver_without_starting_codex(): void
    {
        $this->createSpawnExecutorPreparedRun();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverInvoker::class)
            ->prepareCodexExternalProcessRuntime($this->validInput());

        $this->assertSame('one_shot_scheduler_codex_external_process_runtime_driver_prepared', $result['status']);
        $this->assertTrue($result['codex_external_process_runtime_driver_invoked']);
        $this->assertSame(1, $result['codex_external_process_runtime_driver_invocation_count']);
        $this->assertTrue($result['external_runtime_driver_prepared']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertSame(
            'activate_signed_one_shot_scheduler_tick_codex_process_invocation_authorization_contract',
            $result['next_required_slice']
        );

        $observed = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:attempt-001')
            ->firstOrFail();

        $this->assertSame(
            'prepared_pending_process_invocation',
            data_get($observed->metadata, 'codex_external_process_runtime_driver.status')
        );
        $this->assertFalse(data_get($observed->metadata, 'codex_external_process_runtime_driver.external_process_started'));
        $this->assertFalse(data_get($observed->metadata, 'codex_external_process_runtime_driver.token_spend_allowed'));
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-runtime-driver-001',
        ]);
    }

    public function test_invoker_is_idempotent_for_same_runtime_driver_id(): void
    {
        $this->createSpawnExecutorPreparedRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverInvoker::class);

        $first = $invoker->prepareCodexExternalProcessRuntime($this->validInput());
        $second = $invoker->prepareCodexExternalProcessRuntime($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertFalse($second['provider_started']);
        $this->assertDatabaseCount('atlas_self_construction_agent_runs', 1);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_invoker_rejects_invalid_environment_contract_hash_without_updating_run(): void
    {
        $this->createSpawnExecutorPreparedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_environment_contract_hash');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverInvoker::class)
            ->prepareCodexExternalProcessRuntime(array_merge($this->validInput(), [
                'environment_contract_hash' => 'not-a-hash',
            ]));
    }

    public function test_invoker_rejects_missing_spawn_executor_metadata(): void
    {
        $this->createSpawnExecutorPreparedRun([
            'metadata' => [],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_process_spawn_executor_missing_or_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverInvoker::class)
            ->prepareCodexExternalProcessRuntime($this->validInput());
    }

    public function test_invoker_rejects_duplicate_runtime_driver_for_different_id(): void
    {
        $this->createSpawnExecutorPreparedRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverInvoker::class);
        $invoker->prepareCodexExternalProcessRuntime($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_external_process_runtime_driver_already_prepared');

        $invoker->prepareCodexExternalProcessRuntime(array_merge($this->validInput(), [
            'runtime_driver_id' => 'codex-runtime-driver-002',
        ]));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createSpawnExecutorPreparedRun(array $overrides = []): void
    {
        $this->makeAgentRun(array_merge([
            'run_key' => 'provider-start:attempt-001',
            'status' => 'adapter_invocation_prepared',
            'summary' => 'Codex process spawn executor prepared; external process runtime remains disabled.',
            'metadata' => [
                'codex_process_spawn_executor' => [
                    'spawn_executor_id' => 'codex-spawn-executor-001',
                    'spawn_enablement_id' => 'codex-spawn-enable-001',
                    'supervised_start_id' => 'codex-supervised-start-001',
                    'process_start_release_id' => 'codex-start-release-001',
                    'codex_execution_id' => 'codex-execution-001',
                    'operator_final_spawn_receipt_hash' => str_repeat('1', 64),
                    'runtime_supervision_plan_hash' => str_repeat('2', 64),
                    'stdout_stderr_sink_hash' => str_repeat('3', 64),
                    'liveness_probe_hash' => str_repeat('4', 64),
                    'adapter' => 'codex',
                    'status' => 'prepared_pending_external_process_runtime',
                    'process_spawn_executor_prepared' => true,
                    'external_process_started' => false,
                    'token_spend_allowed' => false,
                    'provider_started' => false,
                    'dispatch_allowed' => false,
                ],
            ],
        ], $overrides));
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return [
            'run_key' => 'provider-start:attempt-001',
            'codex_execution_id' => 'codex-execution-001',
            'process_start_release_id' => 'codex-start-release-001',
            'supervised_start_id' => 'codex-supervised-start-001',
            'spawn_enablement_id' => 'codex-spawn-enable-001',
            'spawn_executor_id' => 'codex-spawn-executor-001',
            'runtime_driver_id' => 'codex-runtime-driver-001',
            'operator_runtime_receipt_hash' => str_repeat('5', 64),
            'process_command_hash' => str_repeat('6', 64),
            'environment_contract_hash' => str_repeat('7', 64),
            'termination_policy_hash' => str_repeat('8', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'one_shot_scheduler_prepare_external_runtime_without_invoking_codex',
        ];
    }

}
