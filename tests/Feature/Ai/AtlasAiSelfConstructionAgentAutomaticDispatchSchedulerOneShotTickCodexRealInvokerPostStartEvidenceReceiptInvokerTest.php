<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker;
use InvalidArgumentException;
use Tests\Concerns\CreatesSelfConstructionControlPlaneTables;
use Tests\Concerns\MakesSelfConstructionAgentRuns;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvokerTest extends TestCase
{
    use CreatesSelfConstructionControlPlaneTables;
    use MakesSelfConstructionAgentRuns;

    public function test_one_shot_scheduler_records_post_start_evidence_receipt_without_spawning_codex(): void
    {
        $this->createPostStartReceiptContractRun();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker::class)
            ->writeCodexRealInvokerPostStartEvidenceReceipt($this->validInput());

        $this->assertSame('one_shot_scheduler_codex_real_invoker_post_start_evidence_receipt_recorded', $result['status']);
        $this->assertTrue($result['codex_real_invoker_post_start_evidence_receipt_invoked']);
        $this->assertSame('codex-real-invoker-post-start-evidence-receipt-001', $result['post_start_evidence_receipt_id']);
        $this->assertTrue($result['post_start_evidence_receipt_recorded']);
        $this->assertTrue($result['external_process_evidence_accepted']);
        $this->assertTrue($result['external_process_started']);
        $this->assertFalse($result['atlas_process_spawned']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertSame('activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract', $result['next_required_slice']);

        $run = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:attempt-001')
            ->firstOrFail();

        $this->assertSame('adapter_invocation_prepared', $run->status);
        $this->assertSame(
            'codex-real-invoker-post-start-evidence-receipt-001',
            data_get($run->metadata, 'codex_real_invoker_post_start_evidence_receipt.post_start_evidence_receipt_id')
        );
        $this->assertFalse((bool) data_get($run->metadata, 'codex_real_invoker_post_start_evidence_receipt.atlas_process_spawned'));
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-post-start-evidence-receipt-001',
        ]);
    }

    public function test_one_shot_scheduler_post_start_evidence_receipt_is_idempotent_for_same_receipt_id(): void
    {
        $this->createPostStartReceiptContractRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker::class);

        $first = $invoker->writeCodexRealInvokerPostStartEvidenceReceipt($this->validInput());
        $second = $invoker->writeCodexRealInvokerPostStartEvidenceReceipt($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_one_shot_scheduler_rejects_invalid_post_start_liveness_probe_hash(): void
    {
        $this->createPostStartReceiptContractRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_post_start_liveness_probe_hash');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker::class)
            ->writeCodexRealInvokerPostStartEvidenceReceipt(array_merge($this->validInput(), [
                'post_start_liveness_probe_hash' => 'bad-hash',
            ]));
    }

    public function test_one_shot_scheduler_rejects_missing_post_start_receipt_contract_metadata(): void
    {
        $this->createPostStartReceiptContractRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_receipt_contract_missing_or_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker::class)
            ->writeCodexRealInvokerPostStartEvidenceReceipt($this->validInput());
    }

    public function test_one_shot_scheduler_rejects_duplicate_post_start_evidence_receipt_for_different_id(): void
    {
        $this->createPostStartReceiptContractRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker::class);
        $invoker->writeCodexRealInvokerPostStartEvidenceReceipt($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_evidence_receipt_already_written');

        $invoker->writeCodexRealInvokerPostStartEvidenceReceipt(array_merge($this->validInput(), [
            'post_start_evidence_receipt_id' => 'codex-real-invoker-post-start-evidence-receipt-002',
        ]));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createPostStartReceiptContractRun(array $overrides = []): void
    {
        $this->makeAgentRun(array_merge([
            'run_key' => 'provider-start:attempt-001',
            'status' => 'adapter_invocation_prepared',
            'summary' => 'Codex real invoker post-start receipt contract built; no external process evidence has been accepted.',
            'metadata' => $this->metadataWithPostStartReceiptContract(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $contractOverrides
     * @return array<string,mixed>
     */
    private function metadataWithPostStartReceiptContract(array $contractOverrides = []): array
    {
        return [
            'codex_real_invoker_post_start_receipt_contract' => array_merge([
                'codex_execution_id' => 'codex-execution-001',
                'real_invoker_start_execution_gate_id' => 'codex-real-invoker-start-execution-gate-001',
                'real_invoker_process_starter_readiness_gate_id' => 'codex-real-invoker-process-starter-readiness-001',
                'manual_start_executor_receipt_id' => 'codex-real-invoker-manual-start-receipt-001',
                'operator_start_handoff_id' => 'codex-real-invoker-operator-start-handoff-001',
                'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
                'post_start_receipt_contract_id' => 'codex-real-invoker-post-start-receipt-contract-001',
                'external_process_identity_contract_hash' => str_repeat('1', 64),
                'startup_evidence_contract_hash' => str_repeat('2', 64),
                'terminal_pid_capture_contract_hash' => str_repeat('3', 64),
                'post_start_cost_meter_contract_hash' => str_repeat('4', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_receipt_contract_ready_pending_external_start_evidence',
                'post_start_receipt_contract_built' => true,
                'operator_start_handoff_built' => true,
                'manual_operator_start_required' => true,
                'actual_process_start_allowed' => false,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], $contractOverrides),
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
            'manual_start_executor_receipt_id' => 'codex-real-invoker-manual-start-receipt-001',
            'operator_start_handoff_id' => 'codex-real-invoker-operator-start-handoff-001',
            'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            'post_start_receipt_contract_id' => 'codex-real-invoker-post-start-receipt-contract-001',
            'post_start_evidence_receipt_id' => 'codex-real-invoker-post-start-evidence-receipt-001',
            'external_process_identity_contract_hash' => str_repeat('1', 64),
            'startup_evidence_contract_hash' => str_repeat('2', 64),
            'terminal_pid_capture_contract_hash' => str_repeat('3', 64),
            'post_start_cost_meter_contract_hash' => str_repeat('4', 64),
            'external_process_identity_evidence_hash' => str_repeat('5', 64),
            'startup_evidence_hash' => str_repeat('6', 64),
            'terminal_pid_capture_hash' => str_repeat('7', 64),
            'post_start_liveness_probe_hash' => str_repeat('8', 64),
            'post_start_cost_meter_evidence_hash' => str_repeat('9', 64),
            'operator_external_start_attestation_hash' => str_repeat('a', 64),
            'no_atlas_process_spawn_attestation_hash' => str_repeat('b', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'record_operator_external_start_evidence_without_atlas_process_spawn',
        ];
    }

}
