<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexRealInvokerPostStartStartExecutionGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Concerns\CreatesSelfConstructionControlPlaneTables;
use Tests\Concerns\MakesCodexRealInvokerPostStartFixtures;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerPostStartStartExecutionGateTest extends TestCase
{
    use CreatesSelfConstructionControlPlaneTables;
    use MakesCodexRealInvokerPostStartFixtures;

    public function test_post_start_start_execution_gate_authorizes_without_starting_codex(): void
    {
        $this->createObservedEnvelopeRun();
        $this->createProviderEnvelopeRun();

        $result = app(AgentCodexRealInvokerPostStartStartExecutionGate::class)
            ->authorizePostStartStartExecution($this->validInput());

        $this->assertSame('codex_real_invoker_post_start_start_execution_gate_authorized', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertSame('codex-real-invoker-post-start-evidence-acceptance-bridge-001', $result['post_start_evidence_acceptance_bridge_id']);
        $this->assertTrue($result['real_invoker_start_execution_gate_authorized']);
        $this->assertTrue($result['start_execution_authorized']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['dispatch_allowed']);

        $observed = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'observed-codex-run-001')
            ->firstOrFail();

        $this->assertSame(
            'post_start_start_execution_authorized_pending_process_starter_readiness',
            data_get($observed->metadata, 'codex_real_invoker_post_start_start_execution_gate.status')
        );
        $this->assertSame(
            'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            data_get($observed->metadata, 'codex_real_invoker_post_start_start_execution_gate.post_start_evidence_acceptance_bridge_id')
        );

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-start-execution-gate-001',
        ]);
    }

    public function test_post_start_start_execution_gate_is_idempotent_for_same_gate_id(): void
    {
        $this->createObservedEnvelopeRun();
        $this->createProviderEnvelopeRun();
        $gate = app(AgentCodexRealInvokerPostStartStartExecutionGate::class);

        $first = $gate->authorizePostStartStartExecution($this->validInput());
        $second = $gate->authorizePostStartStartExecution($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_post_start_start_execution_gate_rejects_missing_envelope_bridge(): void
    {
        $this->createObservedEnvelopeRun(['metadata' => []]);
        $this->createProviderEnvelopeRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('post_start_process_start_envelope_gate_id_mismatch');

        app(AgentCodexRealInvokerPostStartStartExecutionGate::class)
            ->authorizePostStartStartExecution($this->validInput());
    }

    public function test_post_start_start_execution_gate_rejects_bridge_with_process_started(): void
    {
        $this->createObservedEnvelopeRun([
            'metadata' => $this->observedMetadataWithEnvelope(['external_process_started' => true]),
        ]);
        $this->createProviderEnvelopeRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('external_process_started_already_true');

        app(AgentCodexRealInvokerPostStartStartExecutionGate::class)
            ->authorizePostStartStartExecution($this->validInput());
    }

    public function test_post_start_start_execution_gate_rejects_missing_evidence_acceptance_bridge(): void
    {
        $this->createObservedEnvelopeRun([
            'metadata' => $this->observedMetadataWithEnvelope([
                'post_start_evidence_acceptance_bridge_id' => null,
            ]),
        ]);
        $this->createProviderEnvelopeRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('post_start_evidence_acceptance_bridge_id_mismatch');

        app(AgentCodexRealInvokerPostStartStartExecutionGate::class)
            ->authorizePostStartStartExecution($this->validInput());
    }

    public function test_post_start_start_execution_gate_rejects_missing_provider_start_run(): void
    {
        $this->createObservedEnvelopeRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('agent_run_not_found');

        app(AgentCodexRealInvokerPostStartStartExecutionGate::class)
            ->authorizePostStartStartExecution($this->validInput());
    }

    public function test_post_start_start_execution_gate_rolls_back_when_ledger_write_fails(): void
    {
        $this->createObservedEnvelopeRun();
        $this->createProviderEnvelopeRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerPostStartStartExecutionGate::class)
                ->authorizePostStartStartExecution($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $observed = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'observed-codex-run-001')
                ->firstOrFail();
            $provider = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($observed->metadata, 'codex_real_invoker_post_start_start_execution_gate'));
            $this->assertNull(data_get($provider->metadata, 'codex_real_invoker_start_execution_gate'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createObservedEnvelopeRun(array $overrides = []): void
    {
        AtlasSelfConstructionAgentRun::query()->create(array_merge($this->baseRun('observed-codex-run-001'), [
            'summary' => 'Observed Codex process reached post-start process start envelope; start execution remains required.',
            'metadata' => $this->observedMetadataWithEnvelope(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createProviderEnvelopeRun(array $overrides = []): void
    {
        AtlasSelfConstructionAgentRun::query()->create(array_merge($this->baseRun('provider-start:attempt-001'), [
            'summary' => 'Codex real invoker process start envelope built; actual process start remains disabled.',
            'metadata' => [
                'codex_real_invoker_process_start_envelope' => $this->baseEnvelopeMetadata(),
            ],
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $envelopeOverrides
     * @return array<string,mixed>
     */
    private function observedMetadataWithEnvelope(array $envelopeOverrides = []): array
    {
        return [
            'codex_real_invoker_post_start_process_start_envelope' => array_merge($this->sharedBridgeMetadata(), $this->baseEnvelopeMetadata(), [
                'post_start_process_start_envelope_gate_id' => 'codex-post-start-process-start-envelope-gate-001',
                'post_start_actual_process_start_rehearsal_gate_id' => 'codex-post-start-actual-process-start-rehearsal-gate-001',
                'status' => 'post_start_process_start_envelope_built_pending_start_execution_gate',
                'post_start_process_start_envelope_built' => true,
                'observed_external_process_started' => true,
                'observed_provider_started' => true,
                'provider_process_call_allowed' => false,
                'adapter_invocation_allowed' => false,
                'adapter_execution_allowed' => false,
            ], $envelopeOverrides),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function baseEnvelopeMetadata(): array
    {
        return array_merge($this->baseChain(), [
            'real_invoker_executor_enablement_id' => 'codex-real-invoker-executor-enable-001',
            'real_invoker_supervised_start_activation_id' => 'codex-real-invoker-supervised-start-activation-001',
            'real_invoker_guarded_process_start_id' => 'codex-real-invoker-guarded-process-start-001',
            'real_invoker_final_process_start_authorization_id' => 'codex-real-invoker-final-start-auth-001',
            'real_invoker_actual_process_start_rehearsal_id' => 'codex-real-invoker-actual-start-rehearsal-001',
            'real_invoker_process_start_envelope_id' => 'codex-real-invoker-process-start-envelope-001',
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
            'operator_guarded_start_receipt_hash' => str_repeat('1', 64),
            'process_runner_contract_hash' => str_repeat('2', 64),
            'dry_run_rehearsal_hash' => str_repeat('3', 64),
            'launch_invocation_contract_hash' => str_repeat('4', 64),
            'post_start_observability_hash' => str_repeat('5', 64),
            'revoke_guard_hash' => str_repeat('6', 64),
            'operator_final_start_receipt_hash' => str_repeat('a', 64),
            'final_start_signature_hash' => str_repeat('b', 64),
            'final_start_policy_hash' => str_repeat('c', 64),
            'final_start_window_hash' => str_repeat('d', 64),
            'final_start_replay_guard_hash' => str_repeat('e', 64),
            'final_start_kill_switch_hash' => str_repeat('f', 64),
            'process_start_rehearsal_hash' => str_repeat('1', 64),
            'command_resolution_hash' => str_repeat('2', 64),
            'environment_resolution_hash' => str_repeat('3', 64),
            'cwd_verification_hash' => str_repeat('4', 64),
            'supervisor_dry_run_hash' => str_repeat('5', 64),
            'liveness_probe_rehearsal_hash' => str_repeat('6', 64),
            'process_start_envelope_hash' => str_repeat('7', 64),
            'start_command_hash' => str_repeat('8', 64),
            'start_environment_hash' => str_repeat('9', 64),
            'start_cwd_hash' => str_repeat('a', 64),
            'start_supervisor_hash' => str_repeat('b', 64),
            'start_liveness_contract_hash' => str_repeat('c', 64),
            'provider' => 'codex',
            'adapter' => 'codex',
            'status' => 'real_invoker_process_start_envelope_built_pending_execution_gate',
            'real_invoker_process_start_envelope_built' => true,
            'process_start_rehearsed' => true,
            'start_envelope_ready' => true,
            'actual_process_start_allowed' => false,
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_started' => false,
            'dispatch_allowed' => false,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return array_merge($this->sharedBridgeMetadata(), $this->baseEnvelopeMetadata(), [
            'run_key' => 'observed-codex-run-001',
            'post_start_start_execution_gate_id' => 'codex-post-start-start-execution-gate-001',
            'post_start_process_start_envelope_gate_id' => 'codex-post-start-process-start-envelope-gate-001',
            'post_start_actual_process_start_rehearsal_gate_id' => 'codex-post-start-actual-process-start-rehearsal-gate-001',
            'real_invoker_start_execution_gate_id' => 'codex-real-invoker-start-execution-gate-001',
            'operator_execution_gate_receipt_hash' => str_repeat('1', 64),
            'execution_gate_policy_hash' => str_repeat('2', 64),
            'execution_window_hash' => str_repeat('3', 64),
            'preflight_snapshot_hash' => str_repeat('4', 64),
            'rollback_readiness_hash' => str_repeat('5', 64),
            'human_start_signature_hash' => str_repeat('6', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'authorize_post_start_start_execution_without_starting_codex',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function sharedBridgeMetadata(): array
    {
        return [
            'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            'post_start_final_process_start_authorization_gate_id' => 'codex-post-start-final-process-start-authorization-gate-001',
            'post_start_guarded_process_start_gate_id' => 'codex-post-start-guarded-process-start-gate-001',
            'post_start_supervised_start_activation_gate_id' => 'codex-post-start-supervised-start-activation-gate-001',
            'post_start_executor_enablement_gate_id' => 'codex-post-start-executor-enablement-gate-001',
            'post_start_executor_fresh_release_gate_id' => 'codex-post-start-executor-fresh-release-gate-001',
            'post_start_executor_plan_gate_id' => 'codex-post-start-executor-plan-gate-001',
            'post_start_implementation_boundary_gate_id' => 'codex-post-start-implementation-boundary-gate-001',
            'post_start_signed_real_invoker_release_gate_id' => 'codex-post-start-signed-real-invoker-release-gate-001',
            'post_start_real_invoker_release_preflight_gate_id' => 'codex-post-start-real-invoker-release-preflight-gate-001',
            'post_start_external_process_invoker_dry_run_gate_id' => 'codex-post-start-external-process-invoker-dry-run-gate-001',
            'post_start_process_invocation_authorization_gate_id' => 'codex-post-start-process-invocation-authorization-gate-001',
            'post_start_external_process_runtime_gate_id' => 'codex-post-start-external-process-runtime-gate-001',
            'post_start_final_process_spawn_executor_gate_id' => 'codex-post-start-final-spawn-executor-gate-001',
            'post_start_process_spawn_enablement_gate_id' => 'codex-post-start-spawn-enablement-gate-001',
            'post_start_supervised_start_gate_id' => 'codex-post-start-supervised-start-gate-001',
            'post_start_process_start_release_gate_id' => 'codex-post-start-release-gate-001',
            'provider_execution_contract_gate_id' => 'codex-provider-execution-contract-gate-001',
            'adapter_execution_guard_gate_id' => 'codex-adapter-execution-guard-gate-001',
            'execution_guard_id' => 'codex-execution-guard-001',
            'adapter_invocation_boundary_gate_id' => 'codex-adapter-invocation-boundary-gate-001',
            'adapter_invocation_id' => 'codex-adapter-invocation-001',
            'provider_start_driver_gate_id' => 'codex-provider-start-driver-gate-001',
            'provider_start_attempt_id' => 'attempt-001',
            'signed_dispatch_receipt_hash' => str_repeat('a', 64),
            'operator_release_receipt_hash' => str_repeat('b', 64),
            'operator_spawn_receipt_hash' => str_repeat('c', 64),
            'operator_final_spawn_receipt_hash' => str_repeat('d', 64),
            'operator_runtime_receipt_hash' => str_repeat('e', 64),
            'operator_invocation_receipt_hash' => str_repeat('f', 64),
            'operator_dry_run_receipt_hash' => str_repeat('1', 64),
            'operator_release_preflight_receipt_hash' => str_repeat('2', 64),
            'operator_signed_release_receipt_hash' => str_repeat('9', 64),
            'operator_implementation_boundary_receipt_hash' => str_repeat('b', 64),
            'operator_executor_plan_receipt_hash' => str_repeat('a', 64),
            'signature_verification_report_hash' => str_repeat('a', 64),
            'codex_execution_contract_hash' => str_repeat('3', 64),
            'supervised_start_contract_hash' => str_repeat('4', 64),
            'runtime_supervision_plan_hash' => str_repeat('5', 64),
            'runtime_driver_contract_hash' => str_repeat('8', 64),
            'invoker_contract_hash' => str_repeat('9', 64),
        ];
    }

    public function test_start_execution_allowed_when_fully_wired(): void
    {
        $result = app(AgentCodexRealInvokerPostStartStartExecutionGate::class)->evaluateStartExecution([
            'observable_start_present' => true,
            'liveness_monitor_present' => true,
            'receipt_contract_present' => true,
            'observable_start_id' => 'obs-1',
            'liveness_monitor_id' => 'live-1',
            'receipt_contract_id' => 'receipt-1',
        ]);

        $this->assertTrue($result['start_execution_allowed']);
        $this->assertSame([], $result['missing_wiring']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['proof_digest']);
    }

    public function test_start_execution_blocked_when_evidence_free(): void
    {
        $result = app(AgentCodexRealInvokerPostStartStartExecutionGate::class)->evaluateStartExecution([]);

        $this->assertFalse($result['start_execution_allowed']);
        $this->assertSame(['observable_start', 'liveness_monitor', 'receipt_contract'], $result['missing_wiring']);
        $this->assertNull($result['proof_digest']);
    }

    public function test_start_execution_blocked_when_partially_unobservable(): void
    {
        $result = app(AgentCodexRealInvokerPostStartStartExecutionGate::class)->evaluateStartExecution([
            'observable_start_present' => true,
            'liveness_monitor_present' => false,
            'receipt_contract_present' => true,
        ]);

        $this->assertFalse($result['start_execution_allowed']);
        $this->assertSame(['liveness_monitor'], $result['missing_wiring']);
        $this->assertNull($result['proof_digest']);
    }
}
