<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexRealInvokerPostStartReceiptContractBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerPostStartReceiptContractBuilderTest extends TestCase
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

    public function test_post_start_receipt_contract_is_built_without_starting_codex(): void
    {
        $this->createOperatorStartHandoffRun();

        $result = app(AgentCodexRealInvokerPostStartReceiptContractBuilder::class)
            ->buildPostStartReceiptContract($this->validInput());

        $this->assertSame('codex_real_invoker_post_start_receipt_contract_built', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertSame('codex-real-invoker-post-start-evidence-acceptance-bridge-001', $result['post_start_evidence_acceptance_bridge_id']);
        $this->assertTrue($result['post_start_receipt_contract_built']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['dispatch_allowed']);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-post-start-receipt-contract-001',
        ]);

        $run = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:attempt-001')
            ->firstOrFail();

        $this->assertSame(
            'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            data_get($run->metadata, 'codex_real_invoker_post_start_receipt_contract.post_start_evidence_acceptance_bridge_id')
        );
    }

    public function test_post_start_receipt_contract_is_idempotent_for_same_contract_id(): void
    {
        $this->createOperatorStartHandoffRun();
        $builder = app(AgentCodexRealInvokerPostStartReceiptContractBuilder::class);

        $first = $builder->buildPostStartReceiptContract($this->validInput());
        $second = $builder->buildPostStartReceiptContract($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_post_start_receipt_contract_rejects_duplicate_contract_for_different_id(): void
    {
        $this->createOperatorStartHandoffRun();
        $builder = app(AgentCodexRealInvokerPostStartReceiptContractBuilder::class);
        $builder->buildPostStartReceiptContract($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_receipt_contract_already_built');

        $builder->buildPostStartReceiptContract(array_merge($this->validInput(), [
            'post_start_receipt_contract_id' => 'codex-real-invoker-post-start-receipt-contract-002',
        ]));
    }

    public function test_post_start_receipt_contract_rejects_missing_operator_start_handoff_metadata(): void
    {
        $this->createOperatorStartHandoffRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_operator_start_handoff_missing_or_mismatch');

        app(AgentCodexRealInvokerPostStartReceiptContractBuilder::class)
            ->buildPostStartReceiptContract($this->validInput());
    }

    public function test_post_start_receipt_contract_rejects_missing_external_process_identity_contract_hash(): void
    {
        $this->createOperatorStartHandoffRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_external_process_identity_contract_hash');

        app(AgentCodexRealInvokerPostStartReceiptContractBuilder::class)
            ->buildPostStartReceiptContract(array_merge($this->validInput(), [
                'external_process_identity_contract_hash' => '',
            ]));
    }

    public function test_post_start_receipt_contract_rejects_missing_evidence_acceptance_bridge(): void
    {
        $this->createOperatorStartHandoffRun([
            'metadata' => $this->metadataWithOperatorStartHandoff(['post_start_evidence_acceptance_bridge_id' => null]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('post_start_evidence_acceptance_bridge_id_mismatch');

        app(AgentCodexRealInvokerPostStartReceiptContractBuilder::class)
            ->buildPostStartReceiptContract($this->validInput());
    }

    public function test_post_start_receipt_contract_rejects_handoff_with_process_started_flag(): void
    {
        $this->createOperatorStartHandoffRun([
            'metadata' => $this->metadataWithOperatorStartHandoff(['external_process_started' => true]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('external_process_started_already_true');

        app(AgentCodexRealInvokerPostStartReceiptContractBuilder::class)
            ->buildPostStartReceiptContract($this->validInput());
    }

    public function test_post_start_receipt_contract_rolls_back_metadata_when_ledger_write_fails(): void
    {
        $this->createOperatorStartHandoffRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerPostStartReceiptContractBuilder::class)
                ->buildPostStartReceiptContract($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($run->metadata, 'codex_real_invoker_post_start_receipt_contract'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createOperatorStartHandoffRun(array $overrides = []): void
    {
        AtlasSelfConstructionAgentRun::query()->create(array_merge([
            'run_key' => 'provider-start:attempt-001',
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
