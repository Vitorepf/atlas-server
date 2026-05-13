<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexRealInvokerStartExecutionGate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerStartExecutionGateTest extends TestCase
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
