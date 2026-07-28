<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use Carbon\CarbonImmutable;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateInvoker;
use InvalidArgumentException;
use Tests\Concerns\CreatesSelfConstructionControlPlaneTables;
use Tests\Concerns\MakesSelfConstructionAgentRuns;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateInvokerTest extends TestCase
{
    use CreatesSelfConstructionControlPlaneTables;
    use MakesSelfConstructionAgentRuns;

    public function test_one_shot_scheduler_records_post_start_signed_dispatch_authorization_without_dispatching_codex(): void
    {
        $this->createDispatchReleaseGateRun();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateInvoker::class)
            ->authorizeCodexRealInvokerPostStartSignedDispatch($this->validInput());

        $this->assertSame('one_shot_scheduler_codex_real_invoker_post_start_signed_dispatch_authorization_recorded', $result['status']);
        $this->assertTrue($result['codex_real_invoker_post_start_signed_dispatch_authorization_gate_invoked']);
        $this->assertSame('codex-real-invoker-post-start-signed-dispatch-auth-001', $result['signed_dispatch_authorization_id']);
        $this->assertSame('codex-real-invoker-post-start-dispatch-release-gate-001', $result['dispatch_release_gate_id']);
        $this->assertTrue($result['signed_dispatch_authorization_recorded']);
        $this->assertTrue($result['future_dispatch_authorized']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertTrue($result['external_process_started']);
        $this->assertTrue($result['provider_started']);
        $this->assertFalse($result['provider_process_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertSame('activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_executor_handoff_contract', $result['next_required_slice']);

        $run = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:attempt-001')
            ->firstOrFail();

        $this->assertSame(
            'post_start_signed_dispatch_authorized_pending_dispatch_executor',
            data_get($run->metadata, 'codex_real_invoker_post_start_signed_dispatch_authorization.status')
        );
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-post-start-signed-dispatch-auth-001',
        ]);
    }

    public function test_one_shot_scheduler_post_start_signed_dispatch_authorization_is_idempotent_for_same_authorization_id(): void
    {
        $this->createDispatchReleaseGateRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateInvoker::class);

        $first = $invoker->authorizeCodexRealInvokerPostStartSignedDispatch($this->validInput());
        $second = $invoker->authorizeCodexRealInvokerPostStartSignedDispatch($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_one_shot_scheduler_rejects_missing_dispatch_release_gate_metadata(): void
    {
        $this->createDispatchReleaseGateRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_dispatch_release_gate_missing_or_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateInvoker::class)
            ->authorizeCodexRealInvokerPostStartSignedDispatch($this->validInput());
    }

    public function test_one_shot_scheduler_rejects_release_gate_with_non_alive_liveness(): void
    {
        $this->createDispatchReleaseGateRun([
            'metadata' => $this->metadataWithDispatchReleaseGate(['observed_liveness_state' => 'stale']),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_liveness_not_alive');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateInvoker::class)
            ->authorizeCodexRealInvokerPostStartSignedDispatch($this->validInput());
    }

    public function test_one_shot_scheduler_rejects_invalid_human_dispatch_signature_hash(): void
    {
        $this->createDispatchReleaseGateRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_human_dispatch_signature_hash');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateInvoker::class)
            ->authorizeCodexRealInvokerPostStartSignedDispatch(array_merge($this->validInput(), [
                'human_dispatch_signature_hash' => 'not-a-hash',
            ]));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createDispatchReleaseGateRun(array $overrides = []): void
    {
        $this->makeAgentRun(array_merge([
            'run_key' => 'provider-start:attempt-001',
            'reservation_id' => 'reservation-001',
            'status' => 'adapter_invocation_prepared',
            'summary' => 'Codex real invoker post-start dispatch release gate prepared; dispatch remains disabled pending signed authorization.',
            'metadata' => $this->metadataWithDispatchReleaseGate(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $gateOverrides
     * @return array<string,mixed>
     */
    private function metadataWithDispatchReleaseGate(array $gateOverrides = []): array
    {
        return [
            'codex_real_invoker_post_start_dispatch_release_gate' => array_merge([
                'codex_execution_id' => 'codex-execution-001',
                'manual_start_executor_receipt_id' => 'codex-real-invoker-manual-start-receipt-001',
                'operator_start_handoff_id' => 'codex-real-invoker-operator-start-handoff-001',
                'post_start_receipt_contract_id' => 'codex-real-invoker-post-start-receipt-contract-001',
                'post_start_evidence_receipt_id' => 'codex-real-invoker-post-start-evidence-receipt-001',
                'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
                'post_start_liveness_monitor_id' => 'codex-real-invoker-post-start-liveness-001',
                'dispatch_release_gate_id' => 'codex-real-invoker-post-start-dispatch-release-gate-001',
                'dispatch_scope_hash' => str_repeat('1', 64),
                'continuation_summary_hash' => str_repeat('2', 64),
                'context_pack_hash' => str_repeat('3', 64),
                'signed_dispatch_policy_hash' => str_repeat('4', 64),
                'no_direct_provider_call_attestation_hash' => str_repeat('5', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_dispatch_release_gate_ready_pending_signed_dispatch_authorization',
                'dispatch_release_gate_ready' => true,
                'future_dispatch_release_candidate' => true,
                'observed_liveness_state' => 'alive',
                'atlas_process_spawned' => false,
                'actual_process_start_allowed' => false,
                'external_process_started' => true,
                'token_spend_allowed' => false,
                'provider_started' => true,
                'provider_process_call_allowed' => false,
                'dispatch_allowed' => false,
            ], $gateOverrides),
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
            'manual_start_executor_receipt_id' => 'codex-real-invoker-manual-start-receipt-001',
            'operator_start_handoff_id' => 'codex-real-invoker-operator-start-handoff-001',
            'post_start_receipt_contract_id' => 'codex-real-invoker-post-start-receipt-contract-001',
            'post_start_evidence_receipt_id' => 'codex-real-invoker-post-start-evidence-receipt-001',
            'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            'post_start_liveness_monitor_id' => 'codex-real-invoker-post-start-liveness-001',
            'dispatch_release_gate_id' => 'codex-real-invoker-post-start-dispatch-release-gate-001',
            'signed_dispatch_authorization_id' => 'codex-real-invoker-post-start-signed-dispatch-auth-001',
            'signed_dispatch_receipt_hash' => str_repeat('a', 64),
            'human_dispatch_signature_hash' => str_repeat('b', 64),
            'signed_dispatch_policy_hash' => str_repeat('4', 64),
            'dispatch_window_hash' => str_repeat('c', 64),
            'dispatch_scope_hash' => str_repeat('1', 64),
            'continuation_summary_hash' => str_repeat('2', 64),
            'context_pack_hash' => str_repeat('3', 64),
            'dispatch_replay_guard_hash' => str_repeat('d', 64),
            'dispatch_kill_switch_hash' => str_repeat('e', 64),
            'no_direct_provider_call_attestation_hash' => str_repeat('5', 64),
            // A amarra da assinatura humana: task, lease, worker e escopo têm de
            // bater com a linha do run, senão a assinatura vale para outra coisa.
            'task_id' => 'AP-001',
            'lease_id' => 'reservation-001',
            'worker_id' => 'codex-a',
            'allowed_scope_hash' => str_repeat('d', 64),
            'signature_issued_at' => CarbonImmutable::now()->toIso8601String(),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'record_signed_dispatch_authorization_without_dispatching_codex',
        ];
    }

}
