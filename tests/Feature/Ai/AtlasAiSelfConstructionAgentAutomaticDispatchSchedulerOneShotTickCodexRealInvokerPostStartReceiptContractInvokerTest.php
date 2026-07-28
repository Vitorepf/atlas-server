<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvoker;
use InvalidArgumentException;
use Tests\Concerns\CreatesSelfConstructionControlPlaneTables;
use Tests\Concerns\MakesSelfConstructionAgentRuns;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvokerTest extends TestCase
{
    use CreatesSelfConstructionControlPlaneTables;
    use MakesSelfConstructionAgentRuns;

    public function test_invoker_builds_post_start_receipt_contract_without_accepting_external_process_evidence(): void
    {
        $this->createOperatorStartHandoffRun();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvoker::class)
            ->buildCodexRealInvokerPostStartReceiptContract($this->validInput());

        $this->assertSame('one_shot_scheduler_codex_real_invoker_post_start_receipt_contract_built', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['post_start_receipt_contract_built']);
        $this->assertSame('codex-real-invoker-post-start-receipt-contract-001', $result['post_start_receipt_contract_id']);
        // O bridge id mudou de dono em 594d224e1a: quem o produz é o
        // AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge. Este envelope
        // não o reivindica mais — ecoá-lo devolvia NULL, que se lê como "existe
        // e está vazio" em vez de "não é meu".
        $this->assertArrayNotHasKey('post_start_evidence_acceptance_bridge_id', $result);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['external_process_evidence_accepted']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertSame(
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_receipt_contract',
            $result['next_required_slice']
        );

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-post-start-receipt-contract-001',
        ]);

        $run = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:attempt-001')
            ->firstOrFail();

        $this->assertSame(
            'post_start_receipt_contract_ready_pending_external_start_evidence',
            data_get($run->metadata, 'codex_real_invoker_post_start_receipt_contract.status')
        );
        $this->assertFalse(data_get($run->metadata, 'codex_real_invoker_post_start_receipt_contract.external_process_started'));
    }

    public function test_invoker_is_idempotent_for_same_contract_id(): void
    {
        $this->createOperatorStartHandoffRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvoker::class);

        $first = $invoker->buildCodexRealInvokerPostStartReceiptContract($this->validInput());
        $second = $invoker->buildCodexRealInvokerPostStartReceiptContract($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_invoker_rejects_invalid_external_process_identity_contract_hash_without_updating_run(): void
    {
        $this->createOperatorStartHandoffRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_external_process_identity_contract_hash');

        try {
            app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvoker::class)
                ->buildCodexRealInvokerPostStartReceiptContract(array_merge($this->validInput(), [
                    'external_process_identity_contract_hash' => 'not-a-hash',
                ]));
        } finally {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($run->metadata, 'codex_real_invoker_post_start_receipt_contract'));
        }
    }

    public function test_invoker_rejects_missing_operator_start_handoff_metadata(): void
    {
        $this->createOperatorStartHandoffRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_operator_start_handoff_missing_or_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvoker::class)
            ->buildCodexRealInvokerPostStartReceiptContract($this->validInput());
    }

    public function test_invoker_rejects_duplicate_contract_id(): void
    {
        $this->createOperatorStartHandoffRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvoker::class);
        $invoker->buildCodexRealInvokerPostStartReceiptContract($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_receipt_contract_already_built');

        $invoker->buildCodexRealInvokerPostStartReceiptContract(array_merge($this->validInput(), [
            'post_start_receipt_contract_id' => 'codex-real-invoker-post-start-receipt-contract-002',
        ]));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createOperatorStartHandoffRun(array $overrides = []): void
    {
        $this->makeAgentRun(array_merge([
            'run_key' => 'provider-start:attempt-001',
            'status' => 'adapter_invocation_prepared',
            'summary' => 'Codex real invoker operator start handoff built; operator external start remains manual.',
            'metadata' => $this->metadataWithOperatorStartHandoff(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $handoffOverrides
     * @return array<string,mixed>
     */
    private function metadataWithOperatorStartHandoff(array $handoffOverrides = []): array
    {
        return [
            'codex_real_invoker_operator_start_handoff' => array_merge([
                'codex_execution_id' => 'codex-execution-001',
                'real_invoker_start_execution_gate_id' => 'codex-real-invoker-start-execution-gate-001',
                'real_invoker_process_starter_readiness_gate_id' => 'codex-real-invoker-process-starter-readiness-001',
                'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
                'manual_start_executor_receipt_id' => 'codex-real-invoker-manual-start-receipt-001',
                'operator_start_handoff_id' => 'codex-real-invoker-operator-start-handoff-001',
                'handoff_packet_hash' => str_repeat('7', 64),
                'operator_runbook_hash' => str_repeat('8', 64),
                'external_terminal_handoff_hash' => str_repeat('9', 64),
                'post_start_liveness_probe_contract_hash' => str_repeat('d', 64),
                'post_start_receipt_contract_hash' => str_repeat('e', 64),
                'failure_escalation_contract_hash' => str_repeat('f', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'operator_start_handoff_ready_pending_manual_external_start',
                'operator_start_handoff_built' => true,
                'manual_operator_start_required' => true,
                'actual_process_start_allowed' => false,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], $handoffOverrides),
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
            'real_invoker_start_execution_gate_id' => 'codex-real-invoker-start-execution-gate-001',
            'real_invoker_process_starter_readiness_gate_id' => 'codex-real-invoker-process-starter-readiness-001',
            'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            'manual_start_executor_receipt_id' => 'codex-real-invoker-manual-start-receipt-001',
            'operator_start_handoff_id' => 'codex-real-invoker-operator-start-handoff-001',
            'post_start_receipt_contract_id' => 'codex-real-invoker-post-start-receipt-contract-001',
            'handoff_packet_hash' => str_repeat('7', 64),
            'operator_runbook_hash' => str_repeat('8', 64),
            'external_terminal_handoff_hash' => str_repeat('9', 64),
            'post_start_liveness_probe_contract_hash' => str_repeat('d', 64),
            'post_start_receipt_contract_hash' => str_repeat('e', 64),
            'failure_escalation_contract_hash' => str_repeat('f', 64),
            'external_process_identity_contract_hash' => str_repeat('1', 64),
            'startup_evidence_contract_hash' => str_repeat('2', 64),
            'terminal_pid_capture_contract_hash' => str_repeat('3', 64),
            'post_start_cost_meter_contract_hash' => str_repeat('4', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'build_post_start_receipt_contract_without_starting_codex',
        ];
    }

}
