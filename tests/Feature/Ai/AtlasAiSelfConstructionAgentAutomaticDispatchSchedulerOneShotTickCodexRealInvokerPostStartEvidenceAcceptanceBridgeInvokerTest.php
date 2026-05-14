<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeInvoker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeInvokerTest extends TestCase
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

    public function test_one_shot_scheduler_accepts_post_start_evidence_without_spawning_codex(): void
    {
        $this->createPostStartOperatorHandoffRun();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeInvoker::class)
            ->acceptCodexRealInvokerPostStartEvidence($this->validInput());

        $this->assertSame('one_shot_scheduler_codex_real_invoker_post_start_evidence_acceptance_recorded', $result['status']);
        $this->assertTrue($result['codex_real_invoker_post_start_evidence_acceptance_bridge_invoked']);
        $this->assertSame('codex-post-start-evidence-acceptance-bridge-001', $result['post_start_evidence_acceptance_bridge_id']);
        $this->assertTrue($result['post_start_receipt_contract_built']);
        $this->assertTrue($result['post_start_evidence_receipt_recorded']);
        $this->assertTrue($result['post_start_evidence_acceptance_recorded']);
        $this->assertTrue($result['external_process_evidence_accepted']);
        $this->assertTrue($result['external_process_started']);
        $this->assertFalse($result['atlas_process_spawned']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertSame('activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_liveness_monitor_contract', $result['next_required_slice']);

        $run = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'observed-codex-run-001')
            ->firstOrFail();

        $this->assertSame(
            'post_start_evidence_acceptance_recorded_pending_liveness_monitoring',
            data_get($run->metadata, 'codex_real_invoker_post_start_evidence_acceptance_bridge.status')
        );
        $this->assertFalse((bool) data_get($run->metadata, 'codex_real_invoker_post_start_evidence_acceptance_bridge.atlas_process_spawned'));
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-post-start-evidence-acceptance-bridge-001',
        ]);
    }

    public function test_one_shot_scheduler_post_start_evidence_acceptance_is_idempotent_for_same_bridge_id(): void
    {
        $this->createPostStartOperatorHandoffRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeInvoker::class);

        $first = $invoker->acceptCodexRealInvokerPostStartEvidence($this->validInput());
        $second = $invoker->acceptCodexRealInvokerPostStartEvidence($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 3);
    }

    public function test_one_shot_scheduler_rejects_invalid_no_spawn_attestation_hash(): void
    {
        $this->createPostStartOperatorHandoffRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_no_atlas_process_spawn_attestation_hash');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeInvoker::class)
            ->acceptCodexRealInvokerPostStartEvidence(array_merge($this->validInput(), [
                'no_atlas_process_spawn_attestation_hash' => 'bad-hash',
            ]));
    }

    public function test_one_shot_scheduler_rejects_missing_post_start_operator_handoff_metadata(): void
    {
        $this->createPostStartOperatorHandoffRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('post_start_operator_start_handoff_id_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeInvoker::class)
            ->acceptCodexRealInvokerPostStartEvidence($this->validInput());
    }

    public function test_one_shot_scheduler_rejects_duplicate_acceptance_bridge_for_different_id(): void
    {
        $this->createPostStartOperatorHandoffRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeInvoker::class);
        $invoker->acceptCodexRealInvokerPostStartEvidence($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_evidence_acceptance_bridge_already_recorded');

        $invoker->acceptCodexRealInvokerPostStartEvidence(array_merge($this->validInput(), [
            'post_start_evidence_acceptance_bridge_id' => 'codex-post-start-evidence-acceptance-bridge-002',
        ]));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createPostStartOperatorHandoffRun(array $overrides = []): void
    {
        AtlasSelfConstructionAgentRun::query()->create(array_merge([
            'run_key' => 'observed-codex-run-001',
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
            'summary' => 'Codex real invoker post-start operator handoff built; post-start receipt contract remains separate.',
            'metadata' => $this->metadataWithPostStartOperatorHandoff(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $handoffOverrides
     * @return array<string,mixed>
     */
    private function metadataWithPostStartOperatorHandoff(array $handoffOverrides = []): array
    {
        return [
            'codex_real_invoker_post_start_operator_start_handoff' => array_merge([
                'post_start_operator_start_handoff_id' => 'codex-post-start-operator-handoff-001',
                'post_start_evidence_acceptance_bridge_id' => 'codex-post-start-evidence-acceptance-bridge-001',
                'operator_start_handoff_id' => 'codex-real-invoker-operator-start-handoff-001',
                'manual_start_executor_receipt_id' => 'codex-real-invoker-manual-start-receipt-001',
                'real_invoker_process_starter_readiness_gate_id' => 'codex-real-invoker-process-starter-readiness-001',
                'real_invoker_start_execution_gate_id' => 'codex-real-invoker-start-execution-gate-001',
                'codex_execution_id' => 'codex-execution-001',
                'handoff_packet_hash' => str_repeat('7', 64),
                'operator_runbook_hash' => str_repeat('8', 64),
                'external_terminal_handoff_hash' => str_repeat('9', 64),
                'post_start_liveness_probe_contract_hash' => str_repeat('d', 64),
                'post_start_receipt_contract_hash' => str_repeat('e', 64),
                'failure_escalation_contract_hash' => str_repeat('f', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_operator_start_handoff_ready_pending_post_start_receipt_contract',
                'post_start_operator_start_handoff_built' => true,
                'operator_start_handoff_built' => true,
                'manual_operator_start_required' => true,
                'observed_external_process_started' => true,
                'observed_provider_started' => true,
                'actual_process_start_allowed' => false,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_process_call_allowed' => false,
                'provider_started' => false,
                'adapter_invocation_allowed' => false,
                'adapter_execution_allowed' => false,
                'dispatch_allowed' => false,
            ], $handoffOverrides),
            'codex_real_invoker_operator_start_handoff' => array_merge([
                'operator_start_handoff_id' => 'codex-real-invoker-operator-start-handoff-001',
                'post_start_evidence_acceptance_bridge_id' => 'codex-post-start-evidence-acceptance-bridge-001',
                'manual_start_executor_receipt_id' => 'codex-real-invoker-manual-start-receipt-001',
                'real_invoker_process_starter_readiness_gate_id' => 'codex-real-invoker-process-starter-readiness-001',
                'real_invoker_start_execution_gate_id' => 'codex-real-invoker-start-execution-gate-001',
                'codex_execution_id' => 'codex-execution-001',
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
            ], Arr::except($handoffOverrides, ['post_start_operator_start_handoff_id', 'observed_external_process_started', 'observed_provider_started', 'provider_process_call_allowed', 'adapter_invocation_allowed', 'adapter_execution_allowed'])),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return [
            'run_key' => 'observed-codex-run-001',
            'post_start_evidence_acceptance_bridge_id' => 'codex-post-start-evidence-acceptance-bridge-001',
            'post_start_operator_start_handoff_id' => 'codex-post-start-operator-handoff-001',
            'codex_execution_id' => 'codex-execution-001',
            'real_invoker_process_starter_readiness_gate_id' => 'codex-real-invoker-process-starter-readiness-001',
            'real_invoker_start_execution_gate_id' => 'codex-real-invoker-start-execution-gate-001',
            'manual_start_executor_receipt_id' => 'codex-real-invoker-manual-start-receipt-001',
            'operator_start_handoff_id' => 'codex-real-invoker-operator-start-handoff-001',
            'post_start_receipt_contract_id' => 'codex-real-invoker-post-start-receipt-contract-001',
            'post_start_evidence_receipt_id' => 'codex-real-invoker-post-start-evidence-receipt-001',
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
            'external_process_identity_evidence_hash' => str_repeat('5', 64),
            'startup_evidence_hash' => str_repeat('6', 64),
            'terminal_pid_capture_hash' => str_repeat('7', 64),
            'post_start_liveness_probe_hash' => str_repeat('8', 64),
            'post_start_cost_meter_evidence_hash' => str_repeat('9', 64),
            'operator_external_start_attestation_hash' => str_repeat('a', 64),
            'no_atlas_process_spawn_attestation_hash' => str_repeat('b', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'accept_observed_external_start_evidence_without_atlas_process_spawn',
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
