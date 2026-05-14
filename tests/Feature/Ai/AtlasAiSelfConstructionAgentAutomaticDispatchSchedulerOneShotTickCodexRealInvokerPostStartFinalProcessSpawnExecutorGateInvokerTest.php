<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateInvoker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateInvokerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php'))->up();
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    public function test_scheduler_invoker_prepares_post_start_final_process_spawn_executor_without_starting_codex(): void
    {
        $this->createPrerequisites();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateInvoker::class)
            ->prepareCodexRealInvokerPostStartFinalProcessSpawnExecutorGate($this->validInput());

        $this->assertSame('one_shot_scheduler_codex_real_invoker_post_start_final_process_spawn_executor_prepared', $result['status']);
        $this->assertTrue($result['codex_real_invoker_post_start_final_process_spawn_executor_gate_invoked']);
        $this->assertSame(1, $result['codex_real_invoker_post_start_final_process_spawn_executor_gate_invocation_count']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['post_start_final_process_spawn_executor_prepared']);
        $this->assertTrue($result['process_spawn_enabled']);
        $this->assertTrue($result['process_spawn_executor_prepared']);
        $this->assertTrue($result['external_process_runtime_required']);
        $this->assertTrue($result['observed_external_process_started']);
        $this->assertTrue($result['observed_provider_started']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['provider_process_call_allowed']);
        $this->assertFalse($result['adapter_invocation_allowed']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertSame('codex_real_invoker_post_start_final_process_spawn_executor_prepared', data_get($result, 'codex_real_invoker_post_start_final_process_spawn_executor_gate_result.status'));
        $this->assertSame('codex_process_spawn_executor_prepared', data_get($result, 'codex_process_spawn_executor_result.status'));
        $this->assertSame(
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_external_process_runtime_gate_contract',
            $result['next_required_slice']
        );

        $observedRun = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'codex-post-start-observed-run-001')
            ->firstOrFail();

        $providerRun = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:codex-post-start-attempt-001')
            ->firstOrFail();

        $this->assertSame(
            'post_start_final_process_spawn_executor_prepared_pending_external_process_runtime',
            data_get($observedRun->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.status')
        );
        $this->assertSame(
            'prepared_pending_external_process_runtime',
            data_get($providerRun->metadata, 'codex_process_spawn_executor.status')
        );
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-spawn-executor-001',
        ]);
    }

    public function test_scheduler_invoker_is_idempotent_for_same_final_process_spawn_executor(): void
    {
        $this->createPrerequisites();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateInvoker::class);

        $first = $invoker->prepareCodexRealInvokerPostStartFinalProcessSpawnExecutorGate($this->validInput());
        $second = $invoker->prepareCodexRealInvokerPostStartFinalProcessSpawnExecutorGate($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertFalse($second['actual_process_start_allowed']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_scheduler_invoker_rejects_invalid_operator_final_spawn_receipt_hash(): void
    {
        $this->createPrerequisites();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_operator_final_spawn_receipt_hash');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateInvoker::class)
            ->prepareCodexRealInvokerPostStartFinalProcessSpawnExecutorGate(array_merge($this->validInput(), [
                'operator_final_spawn_receipt_hash' => 'not-a-hash',
            ]));
    }

    public function test_scheduler_invoker_rejects_missing_process_spawn_enablement_bridge(): void
    {
        $this->createPrerequisites([
            'observed_run' => ['metadata' => []],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('post_start_process_spawn_enablement_gate_id_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateInvoker::class)
            ->prepareCodexRealInvokerPostStartFinalProcessSpawnExecutorGate($this->validInput());
    }

    public function test_scheduler_invoker_rejects_process_spawn_enablement_bridge_with_dispatch_allowed(): void
    {
        $this->createPrerequisites([
            'observed_run' => [
                'metadata' => $this->observedMetadata(['dispatch_allowed' => true]),
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispatch_allowed_already_true');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateInvoker::class)
            ->prepareCodexRealInvokerPostStartFinalProcessSpawnExecutorGate($this->validInput());
    }

    public function test_scheduler_invoker_rejects_provider_run_without_process_spawn_enablement_metadata(): void
    {
        $this->createPrerequisites([
            'provider_run' => ['metadata' => []],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_process_spawn_enablement_missing_or_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateInvoker::class)
            ->prepareCodexRealInvokerPostStartFinalProcessSpawnExecutorGate($this->validInput());
    }

    /**
     * @param  array<string,array<string,mixed>>  $overrides
     */
    private function createPrerequisites(array $overrides = []): void
    {
        AtlasSelfConstructionAgentRun::query()->create(array_merge([
            'run_key' => 'codex-post-start-observed-run-001',
            'packet_id' => 'AP-001',
            'reservation_id' => null,
            'actor' => 'codex-a',
            'provider' => 'codex',
            'provider_role' => 'implementation',
            'session_id' => 'session-a',
            'workspace_id' => 'atlas-self-construction-forge-workspace',
            'obra_id' => 'atlas-self-construction-os',
            'status' => 'adapter_invocation_prepared',
            'liveness' => 'alive',
            'packet_hash' => null,
            'allowed_files_hash' => str_repeat('d', 64),
            'lease_expires_at' => CarbonImmutable::now()->addMinutes(30),
            'last_heartbeat_at' => CarbonImmutable::now(),
            'started_at' => null,
            'finished_at' => null,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_usd' => 0,
            'completion_evidence_hash' => null,
            'summary' => 'Codex real invoker post-start process spawn enablement recorded.',
            'metadata' => $this->observedMetadata(),
        ], $overrides['observed_run'] ?? []));

        AtlasSelfConstructionAgentRun::query()->create(array_merge([
            'run_key' => 'provider-start:codex-post-start-attempt-001',
            'packet_id' => 'AP-001',
            'reservation_id' => null,
            'actor' => 'codex-a',
            'provider' => 'codex',
            'provider_role' => 'implementation',
            'session_id' => 'session-a',
            'workspace_id' => 'atlas-self-construction-forge-workspace',
            'obra_id' => 'atlas-self-construction-os',
            'status' => 'adapter_invocation_prepared',
            'liveness' => 'alive',
            'packet_hash' => null,
            'allowed_files_hash' => str_repeat('d', 64),
            'lease_expires_at' => CarbonImmutable::now()->addMinutes(30),
            'last_heartbeat_at' => CarbonImmutable::now(),
            'started_at' => null,
            'finished_at' => null,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_usd' => 0,
            'completion_evidence_hash' => null,
            'summary' => 'Codex process spawn enablement recorded; final process spawn executor remains disabled.',
            'metadata' => [
                'codex_process_spawn_enablement' => [
                    'spawn_enablement_id' => 'codex-spawn-enable-001',
                    'supervised_start_id' => 'codex-supervised-start-001',
                    'process_start_release_id' => 'codex-start-release-001',
                    'codex_execution_id' => 'codex-execution-001',
                    'operator_spawn_receipt_hash' => str_repeat('e', 64),
                    'supervised_start_contract_hash' => str_repeat('c', 64),
                    'provider' => 'codex',
                    'adapter' => 'codex',
                    'status' => 'enabled_pending_final_process_spawn_executor',
                    'process_spawn_enabled' => true,
                    'external_process_started' => false,
                    'token_spend_allowed' => false,
                    'provider_started' => false,
                    'dispatch_allowed' => false,
                ],
            ],
        ], $overrides['provider_run'] ?? []));
    }

    /**
     * @param  array<string,mixed>  $enablementOverrides
     * @return array<string,mixed>
     */
    private function observedMetadata(array $enablementOverrides = []): array
    {
        return [
            'codex_real_invoker_post_start_process_spawn_enablement' => array_merge([
                'post_start_process_spawn_enablement_gate_id' => 'codex-real-invoker-post-start-process-spawn-enable-gate-001',
                'post_start_supervised_start_gate_id' => 'codex-real-invoker-post-start-supervised-start-gate-001',
                'post_start_process_start_release_gate_id' => 'codex-real-invoker-post-start-process-start-release-gate-001',
                'process_start_release_id' => 'codex-start-release-001',
                'provider_execution_contract_gate_id' => 'codex-real-invoker-post-start-provider-execution-contract-gate-001',
                'codex_execution_id' => 'codex-execution-001',
                'supervised_start_id' => 'codex-supervised-start-001',
                'spawn_enablement_id' => 'codex-spawn-enable-001',
                'adapter_execution_guard_gate_id' => 'codex-real-invoker-post-start-adapter-execution-guard-gate-001',
                'execution_guard_id' => 'execution-guard-001',
                'adapter_invocation_boundary_gate_id' => 'codex-real-invoker-post-start-adapter-invocation-boundary-gate-001',
                'adapter_invocation_id' => 'adapter-invocation-001',
                'provider_start_driver_gate_id' => 'codex-real-invoker-post-start-provider-start-driver-gate-001',
                'provider_start_attempt_id' => 'codex-post-start-attempt-001',
                'provider_start_run_key' => 'provider-start:codex-post-start-attempt-001',
                'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
                'signed_dispatch_receipt_hash' => str_repeat('a', 64),
                'operator_release_receipt_hash' => str_repeat('b', 64),
                'operator_spawn_receipt_hash' => str_repeat('e', 64),
                'codex_execution_contract_hash' => str_repeat('c', 64),
                'supervised_start_contract_hash' => str_repeat('f', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_process_spawn_enabled_pending_final_process_spawn_executor',
                'post_start_process_spawn_enablement_recorded' => true,
                'post_start_supervised_start_prepared' => true,
                'process_spawn_enabled' => true,
                'final_process_spawn_executor_required' => true,
                'observed_external_process_started' => true,
                'observed_provider_started' => true,
                'actual_process_start_allowed' => false,
                'token_spend_allowed' => false,
                'provider_process_call_allowed' => false,
                'adapter_invocation_allowed' => false,
                'adapter_execution_allowed' => false,
                'dispatch_allowed' => false,
            ], $enablementOverrides),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return [
            'run_key' => 'codex-post-start-observed-run-001',
            'post_start_final_process_spawn_executor_gate_id' => 'codex-real-invoker-post-start-final-process-spawn-executor-gate-001',
            'post_start_process_spawn_enablement_gate_id' => 'codex-real-invoker-post-start-process-spawn-enable-gate-001',
            'post_start_supervised_start_gate_id' => 'codex-real-invoker-post-start-supervised-start-gate-001',
            'post_start_process_start_release_gate_id' => 'codex-real-invoker-post-start-process-start-release-gate-001',
            'process_start_release_id' => 'codex-start-release-001',
            'provider_execution_contract_gate_id' => 'codex-real-invoker-post-start-provider-execution-contract-gate-001',
            'codex_execution_id' => 'codex-execution-001',
            'supervised_start_id' => 'codex-supervised-start-001',
            'spawn_enablement_id' => 'codex-spawn-enable-001',
            'spawn_executor_id' => 'codex-spawn-executor-001',
            'adapter_execution_guard_gate_id' => 'codex-real-invoker-post-start-adapter-execution-guard-gate-001',
            'execution_guard_id' => 'execution-guard-001',
            'adapter_invocation_boundary_gate_id' => 'codex-real-invoker-post-start-adapter-invocation-boundary-gate-001',
            'adapter_invocation_id' => 'adapter-invocation-001',
            'provider_start_driver_gate_id' => 'codex-real-invoker-post-start-provider-start-driver-gate-001',
            'provider_start_attempt_id' => 'codex-post-start-attempt-001',
            'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            'signed_dispatch_receipt_hash' => str_repeat('a', 64),
            'operator_release_receipt_hash' => str_repeat('b', 64),
            'operator_spawn_receipt_hash' => str_repeat('e', 64),
            'operator_final_spawn_receipt_hash' => str_repeat('1', 64),
            'codex_execution_contract_hash' => str_repeat('c', 64),
            'supervised_start_contract_hash' => str_repeat('f', 64),
            'runtime_supervision_plan_hash' => str_repeat('2', 64),
            'stdout_stderr_sink_hash' => str_repeat('3', 64),
            'liveness_probe_hash' => str_repeat('4', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'one_shot_scheduler_prepare_post_start_final_process_spawn_executor_without_starting_codex',
        ];
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('atlas_self_construction_agent_wakeup_items');
        Schema::dropIfExists('atlas_self_construction_agent_work_products');
        Schema::dropIfExists('atlas_self_construction_agent_cost_events');
        Schema::dropIfExists('atlas_self_construction_agent_heartbeats');
        Schema::dropIfExists('atlas_self_construction_agent_runs');
        Schema::dropIfExists('atlas_ledger_events');
    }
}
