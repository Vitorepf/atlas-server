<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartDispatchExecutorHandoff;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Concerns\CreatesSelfConstructionControlPlaneTables;
use Tests\Concerns\MakesSelfConstructionAgentRuns;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerPostStartDispatchExecutorHandoffTest extends TestCase
{
    use CreatesSelfConstructionControlPlaneTables;
    use MakesSelfConstructionAgentRuns;

    public function test_post_start_dispatch_executor_handoff_is_prepared_without_dispatching_codex(): void
    {
        $this->createSignedDispatchAuthorizationRun();

        $result = app(AgentCodexRealInvokerPostStartDispatchExecutorHandoff::class)
            ->preparePostStartDispatchExecutorHandoff($this->validInput());

        $this->assertSame('codex_real_invoker_post_start_dispatch_executor_handoff_prepared', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['dispatch_executor_handoff_prepared']);
        $this->assertTrue($result['future_dispatch_authorized']);
        $this->assertSame('codex-real-invoker-post-start-evidence-acceptance-bridge-001', $result['post_start_evidence_acceptance_bridge_id']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertTrue($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertTrue($result['provider_started']);
        $this->assertFalse($result['provider_process_call_allowed']);
        $this->assertFalse($result['dispatch_allowed']);

        $run = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:attempt-001')
            ->firstOrFail();

        $this->assertSame('adapter_invocation_prepared', $run->status);
        $this->assertSame(
            'post_start_dispatch_executor_handoff_prepared_pending_dispatch_use_receipt',
            data_get($run->metadata, 'codex_real_invoker_post_start_dispatch_executor_handoff.status')
        );
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-post-start-dispatch-executor-handoff-001',
        ]);
    }

    public function test_post_start_dispatch_executor_handoff_is_idempotent_for_same_handoff_id(): void
    {
        $this->createSignedDispatchAuthorizationRun();
        $handoff = app(AgentCodexRealInvokerPostStartDispatchExecutorHandoff::class);

        $first = $handoff->preparePostStartDispatchExecutorHandoff($this->validInput());
        $second = $handoff->preparePostStartDispatchExecutorHandoff($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_post_start_dispatch_executor_handoff_rejects_duplicate_handoff_for_different_id(): void
    {
        $this->createSignedDispatchAuthorizationRun();
        $handoff = app(AgentCodexRealInvokerPostStartDispatchExecutorHandoff::class);
        $handoff->preparePostStartDispatchExecutorHandoff($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_dispatch_executor_handoff_already_prepared');

        $handoff->preparePostStartDispatchExecutorHandoff(array_merge($this->validInput(), [
            'dispatch_executor_handoff_id' => 'codex-real-invoker-post-start-dispatch-executor-handoff-002',
        ]));
    }

    public function test_post_start_dispatch_executor_handoff_rejects_missing_signed_dispatch_authorization_metadata(): void
    {
        $this->createSignedDispatchAuthorizationRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_signed_dispatch_authorization_missing_or_mismatch');

        app(AgentCodexRealInvokerPostStartDispatchExecutorHandoff::class)
            ->preparePostStartDispatchExecutorHandoff($this->validInput());
    }

    public function test_post_start_dispatch_executor_handoff_rejects_authorization_with_non_alive_liveness(): void
    {
        $this->createSignedDispatchAuthorizationRun([
            'metadata' => $this->metadataWithSignedDispatchAuthorization(['observed_liveness_state' => 'stale']),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_liveness_not_alive');

        app(AgentCodexRealInvokerPostStartDispatchExecutorHandoff::class)
            ->preparePostStartDispatchExecutorHandoff($this->validInput());
    }

    public function test_post_start_dispatch_executor_handoff_rejects_authorization_without_evidence_acceptance_bridge(): void
    {
        $this->createSignedDispatchAuthorizationRun([
            'metadata' => $this->metadataWithSignedDispatchAuthorization(['post_start_evidence_acceptance_bridge_id' => null]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('post_start_evidence_acceptance_bridge_id_mismatch');

        app(AgentCodexRealInvokerPostStartDispatchExecutorHandoff::class)
            ->preparePostStartDispatchExecutorHandoff($this->validInput());
    }

    public function test_post_start_dispatch_executor_handoff_rejects_missing_executor_workspace_hash(): void
    {
        $this->createSignedDispatchAuthorizationRun();

        $input = $this->validInput();
        unset($input['executor_workspace_hash']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_executor_workspace_hash');

        app(AgentCodexRealInvokerPostStartDispatchExecutorHandoff::class)
            ->preparePostStartDispatchExecutorHandoff($input);
    }

    public function test_post_start_dispatch_executor_handoff_rejects_authorization_with_dispatch_allowed(): void
    {
        $this->createSignedDispatchAuthorizationRun([
            'metadata' => $this->metadataWithSignedDispatchAuthorization(['dispatch_allowed' => true]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispatch_allowed_already_true');

        app(AgentCodexRealInvokerPostStartDispatchExecutorHandoff::class)
            ->preparePostStartDispatchExecutorHandoff($this->validInput());
    }

    public function test_post_start_dispatch_executor_handoff_rolls_back_metadata_when_ledger_write_fails(): void
    {
        $this->createSignedDispatchAuthorizationRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerPostStartDispatchExecutorHandoff::class)
                ->preparePostStartDispatchExecutorHandoff($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($run->metadata, 'codex_real_invoker_post_start_dispatch_executor_handoff'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createSignedDispatchAuthorizationRun(array $overrides = []): void
    {
        $this->makeAgentRun(array_merge([
            'run_key' => 'provider-start:attempt-001',
            'status' => 'adapter_invocation_prepared',
            'summary' => 'Codex real invoker post-start signed dispatch authorization recorded; dispatch remains disabled pending executor handoff.',
            'metadata' => $this->metadataWithSignedDispatchAuthorization(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $authorizationOverrides
     * @return array<string,mixed>
     */
    private function metadataWithSignedDispatchAuthorization(array $authorizationOverrides = []): array
    {
        return [
            'codex_real_invoker_post_start_signed_dispatch_authorization' => array_merge([
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
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_signed_dispatch_authorized_pending_dispatch_executor',
                'signed_dispatch_authorization_recorded' => true,
                'future_dispatch_authorized' => true,
                'dispatch_release_gate_ready' => true,
                'observed_liveness_state' => 'alive',
                'atlas_process_spawned' => false,
                'actual_process_start_allowed' => false,
                'external_process_started' => true,
                'token_spend_allowed' => false,
                'provider_started' => true,
                'provider_process_call_allowed' => false,
                'dispatch_allowed' => false,
            ], $authorizationOverrides),
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
            'dispatch_executor_handoff_id' => 'codex-real-invoker-post-start-dispatch-executor-handoff-001',
            'signed_dispatch_receipt_hash' => str_repeat('a', 64),
            'human_dispatch_signature_hash' => str_repeat('b', 64),
            'signed_dispatch_policy_hash' => str_repeat('4', 64),
            'dispatch_window_hash' => str_repeat('c', 64),
            'dispatch_scope_hash' => str_repeat('1', 64),
            'continuation_summary_hash' => str_repeat('2', 64),
            'context_pack_hash' => str_repeat('3', 64),
            'dispatch_replay_guard_hash' => str_repeat('d', 64),
            'dispatch_kill_switch_hash' => str_repeat('e', 64),
            'executor_handoff_packet_hash' => str_repeat('6', 64),
            'executor_workspace_hash' => str_repeat('7', 64),
            'executor_scope_lock_hash' => str_repeat('8', 64),
            'no_direct_provider_call_attestation_hash' => str_repeat('5', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'prepare_dispatch_executor_handoff_without_dispatching_codex',
        ];
    }

    public function test_build_handoff_envelope_ready_with_all_required_fields(): void
    {
        $result = app(AgentCodexRealInvokerPostStartDispatchExecutorHandoff::class)->buildHandoffEnvelope([
            'task_scope' => ['app/Foo.php', 'tests/FooTest.php'],
            'proof_requirements' => ['php artisan test --filter=FooTest'],
            'lease_id' => 'lease-1',
            'lease_scope' => ['tests/FooTest.php', 'app/Foo.php'],
            'worker_id' => 'worker-vipvtpwy',
            'worker_routing_evidence' => ['fit_score' => 0.8],
        ]);

        $this->assertTrue($result['handoff_ready']);
        $this->assertSame([], $result['missing_fields']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['handoff_digest']);
    }

    public function test_build_handoff_envelope_rejects_missing_required_fields(): void
    {
        $result = app(AgentCodexRealInvokerPostStartDispatchExecutorHandoff::class)->buildHandoffEnvelope([
            'task_scope' => ['app/Foo.php'],
        ]);

        $this->assertFalse($result['handoff_ready']);
        $this->assertContains('proof_requirements', $result['missing_fields']);
        $this->assertContains('lease_id', $result['missing_fields']);
        $this->assertContains('worker_id', $result['missing_fields']);
        $this->assertContains('worker_routing_evidence', $result['missing_fields']);
        $this->assertNull($result['handoff_digest']);
    }

    public function test_build_handoff_envelope_rejects_contradictory_lease_scope(): void
    {
        $result = app(AgentCodexRealInvokerPostStartDispatchExecutorHandoff::class)->buildHandoffEnvelope([
            'task_scope' => ['app/Foo.php'],
            'proof_requirements' => ['php artisan test --filter=FooTest'],
            'lease_id' => 'lease-1',
            'lease_scope' => ['app/Bar.php'],
            'worker_id' => 'worker-vipvtpwy',
            'worker_routing_evidence' => ['fit_score' => 0.8],
        ]);

        $this->assertFalse($result['handoff_ready']);
        $this->assertSame(['lease_scope_contradicts_task_scope'], $result['missing_fields']);
        $this->assertNull($result['handoff_digest']);
    }

    public function test_build_handoff_envelope_digest_is_stable_for_identical_input(): void
    {
        $writer = app(AgentCodexRealInvokerPostStartDispatchExecutorHandoff::class);
        $fields = [
            'task_scope' => ['app/Foo.php'],
            'proof_requirements' => ['php artisan test --filter=FooTest'],
            'lease_id' => 'lease-1',
            'worker_id' => 'worker-vipvtpwy',
            'worker_routing_evidence' => ['fit_score' => 0.8],
        ];

        $first = $writer->buildHandoffEnvelope($fields);
        $second = $writer->buildHandoffEnvelope($fields);

        $this->assertSame($first['handoff_digest'], $second['handoff_digest']);
    }

}
