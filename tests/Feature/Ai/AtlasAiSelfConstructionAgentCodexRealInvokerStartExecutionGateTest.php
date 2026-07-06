<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexRealInvokerStartExecutionGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Concerns\CreatesSelfConstructionControlPlaneTables;
use Tests\Concerns\MakesSelfConstructionAgentRuns;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerStartExecutionGateTest extends TestCase
{
    use CreatesSelfConstructionControlPlaneTables;
    use MakesSelfConstructionAgentRuns;

    public function test_start_execution_gate_authorizes_gate_without_starting_codex(): void
    {
        $this->createEnvelopeRun();

        $result = app(AgentCodexRealInvokerStartExecutionGate::class)
            ->authorizeStartExecution($this->validInput());

        $this->assertSame('codex_real_invoker_start_execution_gate_authorized', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['real_invoker_start_execution_gate_authorized']);
        $this->assertTrue($result['start_envelope_ready']);
        $this->assertTrue($result['start_execution_authorized']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['dispatch_allowed']);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-start-execution-gate-001',
        ]);
    }

    public function test_start_execution_gate_is_idempotent_for_same_gate_id(): void
    {
        $this->createEnvelopeRun();
        $gate = app(AgentCodexRealInvokerStartExecutionGate::class);

        $first = $gate->authorizeStartExecution($this->validInput());
        $second = $gate->authorizeStartExecution($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_start_execution_gate_rejects_duplicate_gate_for_different_id(): void
    {
        $this->createEnvelopeRun();
        $gate = app(AgentCodexRealInvokerStartExecutionGate::class);
        $gate->authorizeStartExecution($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_start_execution_gate_already_authorized');

        $gate->authorizeStartExecution(array_merge($this->validInput(), [
            'real_invoker_start_execution_gate_id' => 'codex-real-invoker-start-execution-gate-002',
        ]));
    }

    public function test_start_execution_gate_rejects_missing_envelope_metadata(): void
    {
        $this->createEnvelopeRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_process_start_envelope_missing_or_mismatch');

        app(AgentCodexRealInvokerStartExecutionGate::class)
            ->authorizeStartExecution($this->validInput());
    }

    public function test_start_execution_gate_rejects_missing_human_start_signature_hash(): void
    {
        $this->createEnvelopeRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_human_start_signature_hash');

        app(AgentCodexRealInvokerStartExecutionGate::class)
            ->authorizeStartExecution(array_merge($this->validInput(), [
                'human_start_signature_hash' => '',
            ]));
    }

    public function test_start_execution_gate_rejects_envelope_with_process_started_flag(): void
    {
        $this->createEnvelopeRun([
            'metadata' => $this->metadataWithStartEnvelope(['external_process_started' => true]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('external_process_started_already_true');

        app(AgentCodexRealInvokerStartExecutionGate::class)
            ->authorizeStartExecution($this->validInput());
    }

    public function test_start_execution_gate_rolls_back_metadata_when_ledger_write_fails(): void
    {
        $this->createEnvelopeRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerStartExecutionGate::class)
                ->authorizeStartExecution($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($run->metadata, 'codex_real_invoker_start_execution_gate'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createEnvelopeRun(array $overrides = []): void
    {
        $this->makeAgentRun(array_merge([
            'run_key' => 'provider-start:attempt-001',
            'status' => 'adapter_invocation_prepared',
            'summary' => 'Codex real invoker process start envelope built; actual process start remains disabled.',
            'metadata' => $this->metadataWithStartEnvelope(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $envelopeOverrides
     * @return array<string,mixed>
     */
    private function metadataWithStartEnvelope(array $envelopeOverrides = []): array
    {
        return [
            'codex_real_invoker_process_start_envelope' => array_merge([
                'codex_execution_id' => 'codex-execution-001',
                'real_invoker_executor_plan_id' => 'codex-real-invoker-executor-plan-001',
                'real_invoker_executor_fresh_release_id' => 'codex-real-invoker-fresh-release-001',
                'real_invoker_executor_enablement_id' => 'codex-real-invoker-executor-enable-001',
                'real_invoker_supervised_start_activation_id' => 'codex-real-invoker-supervised-start-activation-001',
                'real_invoker_guarded_process_start_id' => 'codex-real-invoker-guarded-process-start-001',
                'real_invoker_final_process_start_authorization_id' => 'codex-real-invoker-final-start-auth-001',
                'real_invoker_actual_process_start_rehearsal_id' => 'codex-real-invoker-actual-start-rehearsal-001',
                'real_invoker_process_start_envelope_id' => 'codex-real-invoker-process-start-envelope-001',
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
            ], $envelopeOverrides),
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
            'real_invoker_executor_plan_id' => 'codex-real-invoker-executor-plan-001',
            'real_invoker_executor_fresh_release_id' => 'codex-real-invoker-fresh-release-001',
            'real_invoker_executor_enablement_id' => 'codex-real-invoker-executor-enable-001',
            'real_invoker_supervised_start_activation_id' => 'codex-real-invoker-supervised-start-activation-001',
            'real_invoker_guarded_process_start_id' => 'codex-real-invoker-guarded-process-start-001',
            'real_invoker_final_process_start_authorization_id' => 'codex-real-invoker-final-start-auth-001',
            'real_invoker_actual_process_start_rehearsal_id' => 'codex-real-invoker-actual-start-rehearsal-001',
            'real_invoker_process_start_envelope_id' => 'codex-real-invoker-process-start-envelope-001',
            'real_invoker_start_execution_gate_id' => 'codex-real-invoker-start-execution-gate-001',
            'process_start_envelope_hash' => str_repeat('7', 64),
            'start_command_hash' => str_repeat('8', 64),
            'start_environment_hash' => str_repeat('9', 64),
            'start_cwd_hash' => str_repeat('a', 64),
            'start_supervisor_hash' => str_repeat('b', 64),
            'start_liveness_contract_hash' => str_repeat('c', 64),
            'operator_execution_gate_receipt_hash' => str_repeat('1', 64),
            'execution_gate_policy_hash' => str_repeat('2', 64),
            'execution_window_hash' => str_repeat('3', 64),
            'preflight_snapshot_hash' => str_repeat('4', 64),
            'rollback_readiness_hash' => str_repeat('5', 64),
            'human_start_signature_hash' => str_repeat('6', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'authorize_start_execution_gate_without_starting_codex',
        ];
    }


    // ── acceptStartReceipt() ─────────────────────────────────────────────────

    private function receiptInput(array $overrides = []): array
    {
        return array_merge([
            'task_id' => 't-1',
            'lease_id' => 'l-1',
            'worker_id' => 'w-1',
            'allowed_scope' => ['app/Foo.php', 'app/Bar.php'],
            'receipt' => [
                'task_id' => 't-1',
                'lease_id' => 'l-1',
                'worker_id' => 'w-1',
                'scope' => ['app/Foo.php'],
            ],
        ], $overrides);
    }

    public function test_accepts_start_with_matching_receipt_in_scope(): void
    {
        $gate = app(AgentCodexRealInvokerStartExecutionGate::class);
        $result = $gate->acceptStartReceipt($this->receiptInput());

        $this->assertTrue($result['accepted_start']);
        $this->assertNull($result['rejection_reason']);
        $this->assertNotEmpty($result['receipt_digest']);
    }

    public function test_rejects_missing_receipt(): void
    {
        $gate = app(AgentCodexRealInvokerStartExecutionGate::class);
        $result = $gate->acceptStartReceipt($this->receiptInput(['receipt' => null]));

        $this->assertFalse($result['accepted_start']);
        $this->assertSame('missing_receipt', $result['rejection_reason']);
        $this->assertNull($result['receipt_digest']);
    }

    public function test_rejects_mismatched_lease(): void
    {
        $gate = app(AgentCodexRealInvokerStartExecutionGate::class);
        $input = $this->receiptInput();
        $input['receipt']['lease_id'] = 'l-WRONG';

        $result = $gate->acceptStartReceipt($input);

        $this->assertFalse($result['accepted_start']);
        $this->assertSame('mismatched_lease', $result['rejection_reason']);
        $this->assertNotEmpty($result['receipt_digest']);
    }

    public function test_rejects_unscoped_command(): void
    {
        $gate = app(AgentCodexRealInvokerStartExecutionGate::class);
        $input = $this->receiptInput();
        $input['receipt']['scope'] = ['app/NotAllowed.php'];

        $result = $gate->acceptStartReceipt($input);

        $this->assertFalse($result['accepted_start']);
        $this->assertSame('unscoped_command', $result['rejection_reason']);
    }

    public function test_rejects_worker_id_mismatch(): void
    {
        $gate = app(AgentCodexRealInvokerStartExecutionGate::class);
        $input = $this->receiptInput();
        $input['receipt']['worker_id'] = 'w-WRONG';

        $result = $gate->acceptStartReceipt($input);

        $this->assertFalse($result['accepted_start']);
        $this->assertSame('receipt_worker_id_mismatch', $result['rejection_reason']);
    }

    public function test_rejects_task_id_mismatch(): void
    {
        $gate = app(AgentCodexRealInvokerStartExecutionGate::class);
        $input = $this->receiptInput();
        $input['receipt']['task_id'] = 't-WRONG';

        $result = $gate->acceptStartReceipt($input);

        $this->assertFalse($result['accepted_start']);
        $this->assertSame('receipt_task_id_mismatch', $result['rejection_reason']);
    }

    public function test_receipt_digest_is_deterministic_for_same_receipt(): void
    {
        $gate = app(AgentCodexRealInvokerStartExecutionGate::class);
        $a = $gate->acceptStartReceipt($this->receiptInput());
        $b = $gate->acceptStartReceipt($this->receiptInput());

        $this->assertSame($a['receipt_digest'], $b['receipt_digest']);
    }
}
