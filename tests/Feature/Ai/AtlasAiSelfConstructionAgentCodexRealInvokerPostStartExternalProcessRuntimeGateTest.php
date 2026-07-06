<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexRealInvokerPostStartExternalProcessRuntimeGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Concerns\CreatesSelfConstructionControlPlaneTables;
use Tests\Concerns\MakesSelfConstructionAgentRuns;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerPostStartExternalProcessRuntimeGateTest extends TestCase
{
    use CreatesSelfConstructionControlPlaneTables;
    use MakesSelfConstructionAgentRuns;

    public function test_post_start_external_process_runtime_prepares_runtime_without_invoking_codex(): void
    {
        $this->createObservedRun();
        $this->createProviderStartRun();

        $result = app(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class)
            ->preparePostStartExternalRuntime($this->validInput());

        $this->assertSame('codex_real_invoker_post_start_external_process_runtime_prepared', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertSame(
            'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            $result['post_start_evidence_acceptance_bridge_id']
        );
        $this->assertTrue($result['post_start_external_process_runtime_prepared']);
        $this->assertTrue($result['external_runtime_driver_prepared']);
        $this->assertTrue($result['process_invocation_required']);
        $this->assertTrue($result['observed_external_process_started']);
        $this->assertTrue($result['observed_provider_started']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_process_call_allowed']);
        $this->assertFalse($result['adapter_invocation_allowed']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);

        $observed = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'observed:codex-real-invoker-001')
            ->firstOrFail();

        $provider = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:attempt-001')
            ->firstOrFail();

        $this->assertSame('post_start_external_process_runtime_prepared_pending_process_invocation', data_get($observed->metadata, 'codex_real_invoker_post_start_external_process_runtime.status'));
        $this->assertSame(
            'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            data_get($observed->metadata, 'codex_real_invoker_post_start_external_process_runtime.post_start_evidence_acceptance_bridge_id')
        );
        $this->assertSame('prepared_pending_process_invocation', data_get($provider->metadata, 'codex_external_process_runtime_driver.status'));
        $this->assertFalse((bool) data_get($provider->metadata, 'codex_external_process_runtime_driver.external_process_started'));
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-runtime-driver-001',
        ]);
    }

    public function test_post_start_external_process_runtime_is_idempotent_for_same_runtime_driver_id(): void
    {
        $this->createObservedRun();
        $this->createProviderStartRun();
        $gate = app(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class);

        $first = $gate->preparePostStartExternalRuntime($this->validInput());
        $second = $gate->preparePostStartExternalRuntime($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_post_start_external_process_runtime_rejects_duplicate_runtime_driver_id(): void
    {
        $this->createObservedRun();
        $this->createProviderStartRun();
        $gate = app(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class);
        $gate->preparePostStartExternalRuntime($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_external_process_runtime_already_recorded');

        $gate->preparePostStartExternalRuntime(array_merge($this->validInput(), [
            'runtime_driver_id' => 'codex-runtime-driver-002',
        ]));
    }

    public function test_post_start_external_process_runtime_rejects_missing_final_spawn_executor_bridge(): void
    {
        $this->createObservedRun(['metadata' => []]);
        $this->createProviderStartRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('post_start_final_process_spawn_executor_gate_id_mismatch');

        app(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class)
            ->preparePostStartExternalRuntime($this->validInput());
    }

    public function test_post_start_external_process_runtime_rejects_final_spawn_without_evidence_acceptance_bridge(): void
    {
        $metadata = $this->observedMetadata();
        $metadata['codex_real_invoker_post_start_final_process_spawn_executor']['post_start_evidence_acceptance_bridge_id'] = null;

        $this->createObservedRun(['metadata' => $metadata]);
        $this->createProviderStartRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('post_start_evidence_acceptance_bridge_id_mismatch');

        app(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class)
            ->preparePostStartExternalRuntime($this->validInput());
    }

    public function test_post_start_external_process_runtime_rejects_bridge_with_dispatch_allowed(): void
    {
        $metadata = $this->observedMetadata();
        $metadata['codex_real_invoker_post_start_final_process_spawn_executor']['dispatch_allowed'] = true;

        $this->createObservedRun(['metadata' => $metadata]);
        $this->createProviderStartRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispatch_allowed_already_true');

        app(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class)
            ->preparePostStartExternalRuntime($this->validInput());
    }

    public function test_post_start_external_process_runtime_rejects_missing_provider_start_run(): void
    {
        $this->createObservedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('agent_run_not_found');

        app(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class)
            ->preparePostStartExternalRuntime($this->validInput());
    }

    public function test_post_start_external_process_runtime_rolls_back_when_ledger_write_fails(): void
    {
        $this->createObservedRun();
        $this->createProviderStartRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class)
                ->preparePostStartExternalRuntime($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $observed = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'observed:codex-real-invoker-001')
                ->firstOrFail();
            $provider = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($observed->metadata, 'codex_real_invoker_post_start_external_process_runtime'));
            $this->assertNull(data_get($provider->metadata, 'codex_external_process_runtime_driver'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createObservedRun(array $overrides = []): void
    {
        $this->makeAgentRun(array_merge([
            'run_key' => 'observed:codex-real-invoker-001',
            'status' => 'adapter_invocation_prepared',
            'summary' => 'Codex real invoker post-start final process spawn executor prepared.',
            'metadata' => $this->observedMetadata(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createProviderStartRun(array $overrides = []): void
    {
        $this->makeAgentRun(array_merge([
            'run_key' => 'provider-start:attempt-001',
            'status' => 'adapter_invocation_prepared',
            'summary' => 'Codex process spawn executor prepared; external process runtime remains disabled.',
            'metadata' => $this->providerMetadata(),
        ], $overrides));
    }

    /**
     * @return array<string,mixed>
     */
    private function observedMetadata(): array
    {
        return [
            'codex_real_invoker_post_start_final_process_spawn_executor' => [
                'post_start_final_process_spawn_executor_gate_id' => 'codex-post-start-final-process-spawn-executor-gate-001',
                'post_start_process_spawn_enablement_gate_id' => 'codex-post-start-process-spawn-enable-gate-001',
                'post_start_supervised_start_gate_id' => 'codex-post-start-supervised-start-gate-001',
                'post_start_process_start_release_gate_id' => 'codex-post-start-process-start-release-gate-001',
                'process_start_release_id' => 'codex-start-release-001',
                'provider_execution_contract_gate_id' => 'codex-post-start-provider-execution-contract-gate-001',
                'codex_execution_id' => 'codex-execution-001',
                'supervised_start_id' => 'codex-supervised-start-001',
                'spawn_enablement_id' => 'codex-spawn-enable-001',
                'spawn_executor_id' => 'codex-spawn-executor-001',
                'adapter_execution_guard_gate_id' => 'codex-post-start-adapter-execution-guard-gate-001',
                'execution_guard_id' => 'adapter-execution-guard-001',
                'adapter_invocation_boundary_gate_id' => 'codex-post-start-adapter-boundary-gate-001',
                'adapter_invocation_id' => 'adapter-invocation-001',
                'provider_start_driver_gate_id' => 'codex-post-start-provider-start-driver-gate-001',
                'provider_start_attempt_id' => 'attempt-001',
                'provider_start_run_key' => 'provider-start:attempt-001',
                'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
                'signed_dispatch_receipt_hash' => str_repeat('f', 64),
                'operator_release_receipt_hash' => str_repeat('a', 64),
                'operator_spawn_receipt_hash' => str_repeat('e', 64),
                'operator_final_spawn_receipt_hash' => str_repeat('1', 64),
                'codex_execution_contract_hash' => str_repeat('b', 64),
                'supervised_start_contract_hash' => str_repeat('c', 64),
                'runtime_supervision_plan_hash' => str_repeat('2', 64),
                'stdout_stderr_sink_hash' => str_repeat('3', 64),
                'liveness_probe_hash' => str_repeat('4', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_final_process_spawn_executor_prepared_pending_external_process_runtime',
                'post_start_final_process_spawn_executor_prepared' => true,
                'process_spawn_enabled' => true,
                'process_spawn_executor_prepared' => true,
                'external_process_runtime_required' => true,
                'observed_external_process_started' => true,
                'observed_provider_started' => true,
                'actual_process_start_allowed' => false,
                'token_spend_allowed' => false,
                'provider_process_call_allowed' => false,
                'adapter_invocation_allowed' => false,
                'adapter_execution_allowed' => false,
                'dispatch_allowed' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function providerMetadata(): array
    {
        return [
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
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'prepared_pending_external_process_runtime',
                'process_spawn_executor_prepared' => true,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return [
            'run_key' => 'observed:codex-real-invoker-001',
            'post_start_external_process_runtime_gate_id' => 'codex-post-start-external-process-runtime-gate-001',
            'post_start_final_process_spawn_executor_gate_id' => 'codex-post-start-final-process-spawn-executor-gate-001',
            'post_start_process_spawn_enablement_gate_id' => 'codex-post-start-process-spawn-enable-gate-001',
            'post_start_supervised_start_gate_id' => 'codex-post-start-supervised-start-gate-001',
            'post_start_process_start_release_gate_id' => 'codex-post-start-process-start-release-gate-001',
            'process_start_release_id' => 'codex-start-release-001',
            'provider_execution_contract_gate_id' => 'codex-post-start-provider-execution-contract-gate-001',
            'codex_execution_id' => 'codex-execution-001',
            'supervised_start_id' => 'codex-supervised-start-001',
            'spawn_enablement_id' => 'codex-spawn-enable-001',
            'spawn_executor_id' => 'codex-spawn-executor-001',
            'runtime_driver_id' => 'codex-runtime-driver-001',
            'adapter_execution_guard_gate_id' => 'codex-post-start-adapter-execution-guard-gate-001',
            'execution_guard_id' => 'adapter-execution-guard-001',
            'adapter_invocation_boundary_gate_id' => 'codex-post-start-adapter-boundary-gate-001',
            'adapter_invocation_id' => 'adapter-invocation-001',
            'provider_start_driver_gate_id' => 'codex-post-start-provider-start-driver-gate-001',
            'provider_start_attempt_id' => 'attempt-001',
            'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            'signed_dispatch_receipt_hash' => str_repeat('f', 64),
            'operator_release_receipt_hash' => str_repeat('a', 64),
            'operator_spawn_receipt_hash' => str_repeat('e', 64),
            'operator_final_spawn_receipt_hash' => str_repeat('1', 64),
            'operator_runtime_receipt_hash' => str_repeat('5', 64),
            'codex_execution_contract_hash' => str_repeat('b', 64),
            'supervised_start_contract_hash' => str_repeat('c', 64),
            'runtime_supervision_plan_hash' => str_repeat('2', 64),
            'stdout_stderr_sink_hash' => str_repeat('3', 64),
            'liveness_probe_hash' => str_repeat('4', 64),
            'process_command_hash' => str_repeat('6', 64),
            'environment_contract_hash' => str_repeat('7', 64),
            'termination_policy_hash' => str_repeat('8', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'prepare_post_start_external_runtime_without_invoking_codex',
        ];
    }


    // ── evaluateRuntimeTrust() ───────────────────────────────────────────────

    private function trustFacts(array $overrides = []): array
    {
        return array_merge([
            'observability' => ['live' => true],
            'command_proof' => [
                'scoped_to_authorized_command' => true,
                'command_hash' => 'cmd-hash-1',
                'authorized_command_hash' => 'cmd-hash-1',
            ],
            'failure_classification' => ['class' => 'none'],
        ], $overrides);
    }

    public function test_runtime_trusted_when_live_scoped_and_classified(): void
    {
        $gate = app(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class);
        $result = $gate->evaluateRuntimeTrust($this->trustFacts());

        $this->assertTrue($result['runtime_trusted']);
        $this->assertNull($result['block_reason']);
        $this->assertNull($result['observability_gap']);
        $this->assertFalse($result['dispatch_allowed']);
    }

    public function test_blocks_unobserved_runtime(): void
    {
        $gate = app(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class);
        $result = $gate->evaluateRuntimeTrust($this->trustFacts(['observability' => ['live' => false]]));

        $this->assertFalse($result['runtime_trusted']);
        $this->assertSame('unobserved_runtime', $result['block_reason']);
        $this->assertSame('runtime_not_observed_live', $result['observability_gap']);
    }

    public function test_blocks_unscoped_command(): void
    {
        $gate = app(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class);
        $result = $gate->evaluateRuntimeTrust($this->trustFacts([
            'command_proof' => [
                'scoped_to_authorized_command' => true,
                'command_hash' => 'cmd-hash-1',
                'authorized_command_hash' => 'cmd-hash-DIFFERENT',
            ],
        ]));

        $this->assertFalse($result['runtime_trusted']);
        $this->assertSame('unscoped_command', $result['block_reason']);
    }

    public function test_blocks_missing_failure_classification(): void
    {
        $gate = app(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class);
        $result = $gate->evaluateRuntimeTrust($this->trustFacts(['failure_classification' => null]));

        $this->assertFalse($result['runtime_trusted']);
        $this->assertSame('missing_failure_classification', $result['block_reason']);
    }

    public function test_unobserved_takes_priority_over_other_blockers(): void
    {
        $gate = app(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class);
        $result = $gate->evaluateRuntimeTrust($this->trustFacts([
            'observability' => ['live' => false],
            'failure_classification' => null,
        ]));

        $this->assertSame('unobserved_runtime', $result['block_reason']);
    }
}
