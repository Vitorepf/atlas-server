<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartSupervisedStartExecutorGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Concerns\CreatesSelfConstructionControlPlaneTables;
use Tests\Concerns\MakesSelfConstructionAgentRuns;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerPostStartSupervisedStartExecutorGateTest extends TestCase
{
    use CreatesSelfConstructionControlPlaneTables;
    use MakesSelfConstructionAgentRuns;

    public function test_post_start_supervised_start_prepares_executor_without_starting_codex(): void
    {
        $this->createObservedRun();
        $this->createProviderStartRun();

        $result = app(AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class)
            ->preparePostStartSupervisedStart($this->validInput());

        $this->assertSame('codex_real_invoker_post_start_supervised_start_prepared', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertSame(
            'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            $result['post_start_evidence_acceptance_bridge_id']
        );
        $this->assertTrue($result['post_start_supervised_start_prepared']);
        $this->assertTrue($result['supervised_start_prepared']);
        $this->assertTrue($result['spawn_enablement_required']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_process_call_allowed']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);

        $observed = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'observed:codex-real-invoker-001')
            ->firstOrFail();

        $provider = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:attempt-001')
            ->firstOrFail();

        $this->assertSame('post_start_supervised_start_prepared_pending_spawn_enablement', data_get($observed->metadata, 'codex_real_invoker_post_start_supervised_start.status'));
        $this->assertSame(
            'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            data_get($observed->metadata, 'codex_real_invoker_post_start_supervised_start.post_start_evidence_acceptance_bridge_id')
        );
        $this->assertSame('prepared_pending_process_spawn_enablement_contract', data_get($provider->metadata, 'codex_supervised_start.status'));
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-supervised-start-001',
        ]);
    }

    public function test_post_start_supervised_start_is_idempotent_for_same_start_id(): void
    {
        $this->createObservedRun();
        $this->createProviderStartRun();
        $gate = app(AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class);

        $first = $gate->preparePostStartSupervisedStart($this->validInput());
        $second = $gate->preparePostStartSupervisedStart($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_post_start_supervised_start_rejects_duplicate_start_id(): void
    {
        $this->createObservedRun();
        $this->createProviderStartRun();
        $gate = app(AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class);
        $gate->preparePostStartSupervisedStart($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_supervised_start_already_recorded');

        $gate->preparePostStartSupervisedStart(array_merge($this->validInput(), [
            'supervised_start_id' => 'codex-supervised-start-002',
        ]));
    }

    public function test_post_start_supervised_start_rejects_missing_process_start_release(): void
    {
        $this->createObservedRun(['metadata' => []]);
        $this->createProviderStartRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('post_start_process_start_release_gate_id_mismatch');

        app(AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class)
            ->preparePostStartSupervisedStart($this->validInput());
    }

    public function test_post_start_supervised_start_rejects_release_without_evidence_acceptance_bridge(): void
    {
        $metadata = $this->observedMetadata();
        $metadata['codex_real_invoker_post_start_process_start_release']['post_start_evidence_acceptance_bridge_id'] = null;

        $this->createObservedRun(['metadata' => $metadata]);
        $this->createProviderStartRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('post_start_evidence_acceptance_bridge_id_mismatch');

        app(AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class)
            ->preparePostStartSupervisedStart($this->validInput());
    }

    public function test_post_start_supervised_start_rejects_release_with_dispatch_allowed(): void
    {
        $metadata = $this->observedMetadata();
        $metadata['codex_real_invoker_post_start_process_start_release']['dispatch_allowed'] = true;

        $this->createObservedRun(['metadata' => $metadata]);
        $this->createProviderStartRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispatch_allowed_already_true');

        app(AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class)
            ->preparePostStartSupervisedStart($this->validInput());
    }

    public function test_post_start_supervised_start_rejects_missing_provider_release_run(): void
    {
        $this->createObservedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('agent_run_not_found');

        app(AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class)
            ->preparePostStartSupervisedStart($this->validInput());
    }

    public function test_post_start_supervised_start_rolls_back_when_ledger_write_fails(): void
    {
        $this->createObservedRun();
        $this->createProviderStartRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class)
                ->preparePostStartSupervisedStart($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $observed = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'observed:codex-real-invoker-001')
                ->firstOrFail();
            $provider = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($observed->metadata, 'codex_real_invoker_post_start_supervised_start'));
            $this->assertNull(data_get($provider->metadata, 'codex_supervised_start'));
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
            'summary' => 'Codex real invoker post-start process start release authorized.',
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
            'summary' => 'Codex process start release authorized; supervised start executor remains disabled.',
            'metadata' => [
                'codex_process_start_release' => [
                    'process_start_release_id' => 'codex-start-release-001',
                    'codex_execution_id' => 'codex-execution-001',
                    'operator_release_receipt_hash' => str_repeat('a', 64),
                    'adapter' => 'codex',
                    'status' => 'authorized_pending_supervised_start_executor',
                    'process_start_release_authorized' => true,
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
    private function observedMetadata(): array
    {
        return [
            'codex_real_invoker_post_start_process_start_release' => [
                'post_start_process_start_release_gate_id' => 'codex-post-start-process-start-release-gate-001',
                'process_start_release_id' => 'codex-start-release-001',
                'provider_execution_contract_gate_id' => 'codex-post-start-provider-execution-contract-gate-001',
                'codex_execution_id' => 'codex-execution-001',
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
                'codex_execution_contract_hash' => str_repeat('b', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_process_start_release_authorized_pending_supervised_start_executor',
                'post_start_process_start_release_authorized' => true,
                'post_start_provider_execution_contract_prepared' => true,
                'process_start_release_authorized' => true,
                'supervised_start_executor_required' => true,
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
    private function validInput(): array
    {
        return [
            'run_key' => 'observed:codex-real-invoker-001',
            'post_start_supervised_start_gate_id' => 'codex-post-start-supervised-start-gate-001',
            'post_start_process_start_release_gate_id' => 'codex-post-start-process-start-release-gate-001',
            'process_start_release_id' => 'codex-start-release-001',
            'provider_execution_contract_gate_id' => 'codex-post-start-provider-execution-contract-gate-001',
            'codex_execution_id' => 'codex-execution-001',
            'supervised_start_id' => 'codex-supervised-start-001',
            'adapter_execution_guard_gate_id' => 'codex-post-start-adapter-execution-guard-gate-001',
            'execution_guard_id' => 'adapter-execution-guard-001',
            'adapter_invocation_boundary_gate_id' => 'codex-post-start-adapter-boundary-gate-001',
            'adapter_invocation_id' => 'adapter-invocation-001',
            'provider_start_driver_gate_id' => 'codex-post-start-provider-start-driver-gate-001',
            'provider_start_attempt_id' => 'attempt-001',
            'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            'signed_dispatch_receipt_hash' => str_repeat('f', 64),
            'operator_release_receipt_hash' => str_repeat('a', 64),
            'codex_execution_contract_hash' => str_repeat('b', 64),
            'stdout_stderr_sanitizer_hash' => str_repeat('c', 64),
            'ready_probe_plan_hash' => str_repeat('d', 64),
            'rollback_plan_hash' => str_repeat('e', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'prepare_post_start_supervised_start_without_spawning_codex',
        ];
    }

}
