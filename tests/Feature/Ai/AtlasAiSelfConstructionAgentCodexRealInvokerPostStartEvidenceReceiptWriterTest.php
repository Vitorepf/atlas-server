<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexRealInvokerPostStartEvidenceReceiptWriter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerPostStartEvidenceReceiptWriterTest extends TestCase
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

    public function test_post_start_evidence_receipt_is_recorded_without_starting_codex(): void
    {
        $this->createPostStartReceiptContractRun();

        $result = app(AgentCodexRealInvokerPostStartEvidenceReceiptWriter::class)
            ->writePostStartEvidenceReceipt($this->validInput());

        $this->assertSame('codex_real_invoker_post_start_evidence_receipt_recorded', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['post_start_evidence_receipt_recorded']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertTrue($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertTrue($result['provider_started']);
        $this->assertFalse($result['dispatch_allowed']);

        $run = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:attempt-001')
            ->firstOrFail();

        $this->assertSame('adapter_invocation_prepared', $run->status);
        $this->assertFalse((bool) data_get($run->metadata, 'codex_real_invoker_post_start_evidence_receipt.atlas_process_spawned'));
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-post-start-evidence-receipt-001',
        ]);
    }

    public function test_post_start_evidence_receipt_is_idempotent_for_same_receipt_id(): void
    {
        $this->createPostStartReceiptContractRun();
        $writer = app(AgentCodexRealInvokerPostStartEvidenceReceiptWriter::class);

        $first = $writer->writePostStartEvidenceReceipt($this->validInput());
        $second = $writer->writePostStartEvidenceReceipt($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_post_start_evidence_receipt_rejects_duplicate_receipt_for_different_id(): void
    {
        $this->createPostStartReceiptContractRun();
        $writer = app(AgentCodexRealInvokerPostStartEvidenceReceiptWriter::class);
        $writer->writePostStartEvidenceReceipt($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_evidence_receipt_already_written');

        $writer->writePostStartEvidenceReceipt(array_merge($this->validInput(), [
            'post_start_evidence_receipt_id' => 'codex-real-invoker-post-start-evidence-receipt-002',
        ]));
    }

    public function test_post_start_evidence_receipt_rejects_missing_post_start_contract_metadata(): void
    {
        $this->createPostStartReceiptContractRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_receipt_contract_missing_or_mismatch');

        app(AgentCodexRealInvokerPostStartEvidenceReceiptWriter::class)
            ->writePostStartEvidenceReceipt($this->validInput());
    }

    public function test_post_start_evidence_receipt_rejects_missing_no_spawn_attestation_hash(): void
    {
        $this->createPostStartReceiptContractRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_no_atlas_process_spawn_attestation_hash');

        app(AgentCodexRealInvokerPostStartEvidenceReceiptWriter::class)
            ->writePostStartEvidenceReceipt(array_merge($this->validInput(), [
                'no_atlas_process_spawn_attestation_hash' => '',
            ]));
    }

    public function test_post_start_evidence_receipt_rejects_contract_with_dispatch_allowed(): void
    {
        $this->createPostStartReceiptContractRun([
            'metadata' => $this->metadataWithPostStartReceiptContract(['dispatch_allowed' => true]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispatch_allowed_already_true');

        app(AgentCodexRealInvokerPostStartEvidenceReceiptWriter::class)
            ->writePostStartEvidenceReceipt($this->validInput());
    }

    public function test_post_start_evidence_receipt_rolls_back_metadata_when_ledger_write_fails(): void
    {
        $this->createPostStartReceiptContractRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerPostStartEvidenceReceiptWriter::class)
                ->writePostStartEvidenceReceipt($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($run->metadata, 'codex_real_invoker_post_start_evidence_receipt'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createPostStartReceiptContractRun(array $overrides = []): void
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
