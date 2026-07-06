<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Concerns\CreatesSelfConstructionControlPlaneTables;
use Tests\Concerns\MakesCodexRealInvokerPostStartFixtures;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGateTest extends TestCase
{
    use CreatesSelfConstructionControlPlaneTables;
    use MakesCodexRealInvokerPostStartFixtures;

    public function test_post_start_final_process_start_authorization_records_without_starting_codex(): void
    {
        $this->createObservedGuardedStartRun();
        $this->createProviderGuardedStartRun();

        $result = app(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class)
            ->authorizePostStartFinalProcessStart($this->validInput());

        $this->assertSame('codex_real_invoker_post_start_final_process_start_authorization_prepared', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['real_invoker_final_process_start_authorization_prepared']);
        $this->assertTrue($result['final_process_start_authorized']);
        $this->assertSame('codex-real-invoker-post-start-evidence-acceptance-bridge-001', $result['post_start_evidence_acceptance_bridge_id']);
        $this->assertTrue($result['process_start_armed']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['dispatch_allowed']);

        $observed = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'observed-codex-run-001')
            ->firstOrFail();

        $this->assertSame(
            'post_start_final_process_start_authorized_pending_actual_start_rehearsal',
            data_get($observed->metadata, 'codex_real_invoker_post_start_final_process_start_authorization.status')
        );
        $this->assertSame(
            'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            data_get($observed->metadata, 'codex_real_invoker_post_start_final_process_start_authorization.post_start_evidence_acceptance_bridge_id')
        );

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-final-start-auth-001',
        ]);
    }

    public function test_post_start_final_process_start_authorization_is_idempotent_for_same_authorization_id(): void
    {
        $this->createObservedGuardedStartRun();
        $this->createProviderGuardedStartRun();
        $gate = app(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class);

        $first = $gate->authorizePostStartFinalProcessStart($this->validInput());
        $second = $gate->authorizePostStartFinalProcessStart($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_post_start_final_process_start_authorization_rejects_duplicate_authorization_id(): void
    {
        $this->createObservedGuardedStartRun();
        $this->createProviderGuardedStartRun();
        $gate = app(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class);
        $gate->authorizePostStartFinalProcessStart($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_final_process_start_authorization_already_recorded');

        $gate->authorizePostStartFinalProcessStart(array_merge($this->validInput(), [
            'real_invoker_final_process_start_authorization_id' => 'codex-real-invoker-final-start-auth-002',
        ]));
    }

    public function test_post_start_final_process_start_authorization_rejects_missing_guarded_bridge(): void
    {
        $this->createObservedGuardedStartRun(['metadata' => []]);
        $this->createProviderGuardedStartRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('post_start_guarded_process_start_gate_id_mismatch');

        app(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class)
            ->authorizePostStartFinalProcessStart($this->validInput());
    }

    public function test_post_start_final_process_start_authorization_rejects_missing_evidence_acceptance_bridge(): void
    {
        $this->createObservedGuardedStartRun([
            'metadata' => $this->observedMetadataWithGuardedStart(['post_start_evidence_acceptance_bridge_id' => null]),
        ]);
        $this->createProviderGuardedStartRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('post_start_evidence_acceptance_bridge_id_mismatch');

        app(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class)
            ->authorizePostStartFinalProcessStart($this->validInput());
    }

    public function test_post_start_final_process_start_authorization_rejects_bridge_with_process_started(): void
    {
        $this->createObservedGuardedStartRun([
            'metadata' => $this->observedMetadataWithGuardedStart(['external_process_started' => true]),
        ]);
        $this->createProviderGuardedStartRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('external_process_started_already_true');

        app(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class)
            ->authorizePostStartFinalProcessStart($this->validInput());
    }

    public function test_post_start_final_process_start_authorization_rejects_missing_final_start_signature_hash(): void
    {
        $this->createObservedGuardedStartRun();
        $this->createProviderGuardedStartRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_final_start_signature_hash');

        app(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class)
            ->authorizePostStartFinalProcessStart(array_merge($this->validInput(), [
                'final_start_signature_hash' => '',
            ]));
    }

    public function test_post_start_final_process_start_authorization_rejects_missing_provider_start_run(): void
    {
        $this->createObservedGuardedStartRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('agent_run_not_found');

        app(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class)
            ->authorizePostStartFinalProcessStart($this->validInput());
    }

    public function test_post_start_final_process_start_authorization_rolls_back_when_ledger_write_fails(): void
    {
        $this->createObservedGuardedStartRun();
        $this->createProviderGuardedStartRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class)
                ->authorizePostStartFinalProcessStart($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $observed = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'observed-codex-run-001')
                ->firstOrFail();
            $provider = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($observed->metadata, 'codex_real_invoker_post_start_final_process_start_authorization'));
            $this->assertNull(data_get($provider->metadata, 'codex_real_invoker_final_process_start_authorization'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createObservedGuardedStartRun(array $overrides = []): void
    {
        AtlasSelfConstructionAgentRun::query()->create(array_merge($this->baseRun('observed-codex-run-001'), [
            'summary' => 'Observed Codex process reached post-start guarded process start; final authorization remains required.',
            'metadata' => $this->observedMetadataWithGuardedStart(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createProviderGuardedStartRun(array $overrides = []): void
    {
        AtlasSelfConstructionAgentRun::query()->create(array_merge($this->baseRun('provider-start:attempt-001'), [
            'summary' => 'Codex real invoker guarded process start prepared; actual process start remains disabled.',
            'metadata' => [
                'codex_real_invoker_guarded_process_start' => $this->baseGuardedStartMetadata(),
            ],
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $guardedOverrides
     * @return array<string,mixed>
     */
    private function observedMetadataWithGuardedStart(array $guardedOverrides = []): array
    {
        return [
            'codex_real_invoker_post_start_guarded_process_start' => array_merge($this->sharedBridgeMetadata(), $this->baseGuardedStartMetadata(), [
                'post_start_guarded_process_start_gate_id' => 'codex-post-start-guarded-process-start-gate-001',
                'status' => 'post_start_guarded_process_start_prepared_pending_final_start_authorization',
                'post_start_guarded_process_start_recorded' => true,
                'real_invoker_supervised_start_activation_prepared' => true,
                'observed_external_process_started' => true,
                'observed_provider_started' => true,
                'provider_process_call_allowed' => false,
                'adapter_invocation_allowed' => false,
                'adapter_execution_allowed' => false,
            ], $guardedOverrides),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function baseGuardedStartMetadata(): array
    {
        return array_merge($this->baseChain(), [
            'real_invoker_executor_enablement_id' => 'codex-real-invoker-executor-enable-001',
            'real_invoker_supervised_start_activation_id' => 'codex-real-invoker-supervised-start-activation-001',
            'real_invoker_guarded_process_start_id' => 'codex-real-invoker-guarded-process-start-001',
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
            'provider' => 'codex',
            'adapter' => 'codex',
            'status' => 'real_invoker_guarded_process_start_prepared_disabled_pending_final_start',
            'real_invoker_guarded_process_start_prepared' => true,
            'executor_enabled' => true,
            'process_start_armed' => true,
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
        return array_merge($this->sharedBridgeMetadata(), $this->baseGuardedStartMetadata(), [
            'run_key' => 'observed-codex-run-001',
            'post_start_final_process_start_authorization_gate_id' => 'codex-post-start-final-process-start-authorization-gate-001',
            'real_invoker_final_process_start_authorization_id' => 'codex-real-invoker-final-start-auth-001',
            'operator_final_start_receipt_hash' => str_repeat('a', 64),
            'final_start_signature_hash' => str_repeat('b', 64),
            'final_start_policy_hash' => str_repeat('c', 64),
            'final_start_window_hash' => str_repeat('d', 64),
            'final_start_replay_guard_hash' => str_repeat('e', 64),
            'final_start_kill_switch_hash' => str_repeat('f', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'authorize_post_start_final_process_start_without_starting_codex',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function sharedBridgeMetadata(): array
    {
        return [
            'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
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

    // ── AC1/AC2/AC3: revalidateAuthorization — pure post-start drift check ─────

    public function test_matching_context_is_authorized(): void
    {
        $result = app(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class)->revalidateAuthorization([
            'approved_task_id' => 't1', 'current_task_id' => 't1',
            'approved_lease_id' => 'l1', 'current_lease_id' => 'l1',
            'approved_scope_hash' => 's1', 'current_scope_hash' => 's1',
            'approved_worker_id' => 'w1', 'current_worker_id' => 'w1',
        ]);

        $this->assertSame(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::AUTHORIZATION_STATUS_AUTHORIZED, $result['authorization_status']);
        $this->assertNull($result['drift_reason']);
        $this->assertFalse($result['required_reauthorization']);
    }

    public function test_task_drift_rejects_authorization(): void
    {
        $result = app(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class)->revalidateAuthorization([
            'approved_task_id' => 't1', 'current_task_id' => 't2',
        ]);

        $this->assertSame(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::AUTHORIZATION_STATUS_REJECTED, $result['authorization_status']);
        $this->assertSame(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::DRIFT_REASON_TASK_CONTEXT, $result['drift_reason']);
        $this->assertTrue($result['required_reauthorization']);
    }

    public function test_lease_drift_rejects_authorization(): void
    {
        $result = app(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class)->revalidateAuthorization([
            'approved_task_id' => 't1', 'current_task_id' => 't1',
            'approved_lease_id' => 'l1', 'current_lease_id' => 'l2',
        ]);

        $this->assertSame(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::DRIFT_REASON_LEASE_CONTEXT, $result['drift_reason']);
    }

    public function test_scope_drift_rejects_authorization(): void
    {
        $result = app(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class)->revalidateAuthorization([
            'approved_task_id' => 't1', 'current_task_id' => 't1',
            'approved_lease_id' => 'l1', 'current_lease_id' => 'l1',
            'approved_scope_hash' => 's1', 'current_scope_hash' => 's2',
        ]);

        $this->assertSame(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::DRIFT_REASON_SCOPE_CONTEXT, $result['drift_reason']);
    }

    public function test_worker_drift_rejects_authorization(): void
    {
        $result = app(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class)->revalidateAuthorization([
            'approved_task_id' => 't1', 'current_task_id' => 't1',
            'approved_lease_id' => 'l1', 'current_lease_id' => 'l1',
            'approved_scope_hash' => 's1', 'current_scope_hash' => 's1',
            'approved_worker_id' => 'w1', 'current_worker_id' => 'w2',
        ]);

        $this->assertSame(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::DRIFT_REASON_WORKER_CONTEXT, $result['drift_reason']);
    }

    public function test_task_drift_takes_priority_over_other_drifts(): void
    {
        $result = app(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class)->revalidateAuthorization([
            'approved_task_id' => 't1', 'current_task_id' => 't2',
            'approved_lease_id' => 'l1', 'current_lease_id' => 'l2',
        ]);

        $this->assertSame(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::DRIFT_REASON_TASK_CONTEXT, $result['drift_reason']);
    }

    public function test_revalidate_authorization_is_deterministic(): void
    {
        $gate = app(AgentCodexRealInvokerPostStartFinalProcessStartAuthorizationGate::class);
        $input = ['approved_task_id' => 't1', 'current_task_id' => 't2'];

        $this->assertSame($gate->revalidateAuthorization($input), $gate->revalidateAuthorization($input));
    }
}
