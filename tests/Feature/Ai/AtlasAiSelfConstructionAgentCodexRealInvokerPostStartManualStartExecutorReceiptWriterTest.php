<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerPostStartManualStartExecutorReceiptWriterTest extends TestCase
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

    public function test_post_start_manual_start_executor_receipt_is_written_without_starting_codex(): void
    {
        $this->createObservedReadinessRun();
        $this->createProviderReadinessRun();

        $result = app(AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter::class)
            ->writePostStartManualStartExecutorReceipt($this->validInput());

        $this->assertSame('codex_real_invoker_post_start_manual_start_executor_receipt_written', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertSame('codex-real-invoker-post-start-evidence-acceptance-bridge-001', $result['post_start_evidence_acceptance_bridge_id']);
        $this->assertTrue($result['manual_start_executor_receipt_written']);
        $this->assertTrue($result['manual_operator_start_required']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['dispatch_allowed']);

        $observed = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'observed-codex-run-001')
            ->firstOrFail();

        $this->assertSame(
            'post_start_manual_start_executor_receipt_written_pending_operator_handoff',
            data_get($observed->metadata, 'codex_real_invoker_post_start_manual_start_executor_receipt.status')
        );
        $this->assertSame(
            'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            data_get($observed->metadata, 'codex_real_invoker_post_start_manual_start_executor_receipt.post_start_evidence_acceptance_bridge_id')
        );

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-manual-start-receipt-001',
        ]);
    }

    public function test_post_start_manual_start_executor_receipt_is_idempotent_for_same_receipt_id(): void
    {
        $this->createObservedReadinessRun();
        $this->createProviderReadinessRun();
        $writer = app(AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter::class);

        $first = $writer->writePostStartManualStartExecutorReceipt($this->validInput());
        $second = $writer->writePostStartManualStartExecutorReceipt($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_post_start_manual_start_executor_receipt_rejects_missing_readiness_bridge(): void
    {
        $this->createObservedReadinessRun(['metadata' => []]);
        $this->createProviderReadinessRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('post_start_process_starter_readiness_gate_id_mismatch');

        app(AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter::class)
            ->writePostStartManualStartExecutorReceipt($this->validInput());
    }

    public function test_post_start_manual_start_executor_receipt_rejects_missing_evidence_acceptance_bridge(): void
    {
        $this->createObservedReadinessRun([
            'metadata' => $this->observedMetadataWithReadiness(['post_start_evidence_acceptance_bridge_id' => null]),
        ]);
        $this->createProviderReadinessRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('post_start_evidence_acceptance_bridge_id_mismatch');

        app(AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter::class)
            ->writePostStartManualStartExecutorReceipt($this->validInput());
    }

    public function test_post_start_manual_start_executor_receipt_rejects_bridge_with_process_started(): void
    {
        $this->createObservedReadinessRun([
            'metadata' => $this->observedMetadataWithReadiness(['external_process_started' => true]),
        ]);
        $this->createProviderReadinessRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('external_process_started_already_true');

        app(AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter::class)
            ->writePostStartManualStartExecutorReceipt($this->validInput());
    }

    public function test_post_start_manual_start_executor_receipt_rejects_missing_provider_start_run(): void
    {
        $this->createObservedReadinessRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('agent_run_not_found');

        app(AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter::class)
            ->writePostStartManualStartExecutorReceipt($this->validInput());
    }

    public function test_post_start_manual_start_executor_receipt_rolls_back_when_ledger_write_fails(): void
    {
        $this->createObservedReadinessRun();
        $this->createProviderReadinessRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter::class)
                ->writePostStartManualStartExecutorReceipt($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $observed = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'observed-codex-run-001')
                ->firstOrFail();
            $provider = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($observed->metadata, 'codex_real_invoker_post_start_manual_start_executor_receipt'));
            $this->assertNull(data_get($provider->metadata, 'codex_real_invoker_manual_start_executor_receipt'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createObservedReadinessRun(array $overrides = []): void
    {
        AtlasSelfConstructionAgentRun::query()->create(array_merge($this->baseRun('observed-codex-run-001'), [
            'summary' => 'Observed Codex process reached post-start process starter readiness; manual start receipt remains required.',
            'metadata' => $this->observedMetadataWithReadiness(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createProviderReadinessRun(array $overrides = []): void
    {
        AtlasSelfConstructionAgentRun::query()->create(array_merge($this->baseRun('provider-start:attempt-001'), [
            'summary' => 'Codex real invoker process starter readiness prepared; actual process start remains disabled.',
            'metadata' => [
                'codex_real_invoker_process_starter_readiness_gate' => $this->readinessMetadata(),
            ],
        ], $overrides));
    }

    /**
     * @return array<string,mixed>
     */
    private function baseRun(string $runKey): array
    {
        return [
            'run_key' => $runKey,
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
        ];
    }

    /**
     * @param  array<string,mixed>  $readinessOverrides
     * @return array<string,mixed>
     */
    private function observedMetadataWithReadiness(array $readinessOverrides = []): array
    {
        return [
            'codex_real_invoker_post_start_process_starter_readiness_gate' => array_merge($this->readinessMetadata(), [
                'post_start_process_starter_readiness_gate_id' => 'codex-post-start-process-starter-readiness-gate-001',
                'provider_start_attempt_id' => 'attempt-001',
                'status' => 'post_start_process_starter_ready_pending_manual_start_executor',
                'post_start_process_starter_readiness_gate_prepared' => true,
                'observed_external_process_started' => true,
                'observed_provider_started' => true,
                'provider_process_call_allowed' => false,
                'adapter_invocation_allowed' => false,
                'adapter_execution_allowed' => false,
            ], $readinessOverrides),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readinessMetadata(): array
    {
        return [
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
            'real_invoker_process_starter_readiness_gate_id' => 'codex-real-invoker-process-starter-readiness-001',
            'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            'process_starter_manifest_hash' => str_repeat('7', 64),
            'supervisor_binding_hash' => str_repeat('8', 64),
            'liveness_monitor_binding_hash' => str_repeat('9', 64),
            'cancellation_contract_hash' => str_repeat('a', 64),
            'output_capture_contract_hash' => str_repeat('b', 64),
            'cost_meter_contract_hash' => str_repeat('c', 64),
            'start_replay_guard_hash' => str_repeat('d', 64),
            'operator_process_starter_signature_hash' => str_repeat('e', 64),
            'provider' => 'codex',
            'adapter' => 'codex',
            'status' => 'real_invoker_process_starter_ready_pending_manual_start_executor',
            'real_invoker_process_starter_readiness_gate_prepared' => true,
            'start_execution_authorized' => true,
            'process_starter_ready' => true,
            'actual_process_start_allowed' => false,
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_started' => false,
            'dispatch_allowed' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return array_merge($this->readinessMetadata(), [
            'run_key' => 'observed-codex-run-001',
            'post_start_manual_start_executor_receipt_id' => 'codex-post-start-manual-start-receipt-001',
            'manual_start_executor_receipt_id' => 'codex-real-invoker-manual-start-receipt-001',
            'post_start_process_starter_readiness_gate_id' => 'codex-post-start-process-starter-readiness-gate-001',
            'provider_start_attempt_id' => 'attempt-001',
            'manual_start_command_hash' => str_repeat('1', 64),
            'terminal_session_binding_hash' => str_repeat('2', 64),
            'operator_presence_hash' => str_repeat('3', 64),
            'live_supervisor_ack_hash' => str_repeat('4', 64),
            'initial_liveness_probe_hash' => str_repeat('5', 64),
            'kill_switch_ack_hash' => str_repeat('6', 64),
            'output_stream_capture_hash' => str_repeat('a', 64),
            'cost_meter_initial_hash' => str_repeat('b', 64),
            'no_autostart_attestation_hash' => str_repeat('c', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'write_post_start_manual_start_executor_receipt_without_starting_codex',
        ]);
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
