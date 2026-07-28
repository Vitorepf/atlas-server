<?php

namespace Tests\Unit\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptInvoker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves AgentDispatchExecutorReleaseAuthorizationPersistenceWriter is wired into a real call path:
 * AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptInvoker now
 * persists an optional signed release_authorization payload through it. It is no longer an orphan.
 */
class AgentDispatchExecutorReleaseAuthorizationPersistenceWriterWiringWiredTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php'))->up();
        (require database_path('migrations/2026_05_12_020000_create_atlas_self_construction_agent_dispatch_executor_release_authorizations_table.php'))->up();
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    public function test_invoker_persists_release_authorization_when_supplied(): void
    {
        $this->createProcessStarterReadinessRun();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptInvoker::class)
            ->writeCodexRealInvokerManualStartExecutorReceipt(array_merge($this->validInput(), [
                'release_authorization' => $this->releaseAuthorizationInput(),
            ]));

        $this->assertTrue($result['release_authorization_persisted']);
        $this->assertSame('persisted', $result['release_authorization_result']['status']);
        $this->assertTrue($result['release_authorization_result']['created']);

        $this->assertDatabaseHas('atlas_self_construction_agent_dispatch_authorizations', [
            'authorization_key' => 'release-auth-key-001',
            'decision' => 'approve_release_once',
        ]);
    }

    public function test_invoker_skips_release_authorization_when_absent(): void
    {
        $this->createProcessStarterReadinessRun();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptInvoker::class)
            ->writeCodexRealInvokerManualStartExecutorReceipt($this->validInput());

        $this->assertFalse($result['release_authorization_persisted']);
        $this->assertNull($result['release_authorization_result']);
        $this->assertDatabaseCount('atlas_self_construction_agent_dispatch_authorizations', 0);
    }

    /** @return array<string,mixed> */
    private function releaseAuthorizationInput(): array
    {
        return [
            'authorization_key' => 'release-auth-key-001',
            'receipt_key' => 'receipt-key-001',
            'authorization_id' => 'authorization-001',
            'decision' => 'approve_release_once',
            'signed_by' => 'operator-a',
            'signed_at' => CarbonImmutable::now()->toIso8601String(),
            'expires_at' => CarbonImmutable::now()->addHour()->toIso8601String(),
            'signed_receipt_template_hash' => str_repeat('1', 64),
            'signed_receipt_preflight_hash' => str_repeat('2', 64),
            'persistence_template_hash' => str_repeat('3', 64),
            'persistence_preflight_hash' => str_repeat('4', 64),
            'external_signature_validation_report_hash' => str_repeat('5', 64),
            'signed_receipt_hash' => str_repeat('6', 64),
            'payload' => ['note' => 'wiring-proof'],
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createProcessStarterReadinessRun(array $overrides = []): void
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
            'summary' => 'Codex real invoker process starter readiness prepared; actual process start remains disabled.',
            'metadata' => $this->metadataWithProcessStarterReadiness(),
        ], $overrides));
    }

    /** @return array<string,mixed> */
    private function metadataWithProcessStarterReadiness(): array
    {
        return [
            'codex_real_invoker_process_starter_readiness_gate' => [
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
            ],
        ];
    }

    /** @return array<string,mixed> */
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
            'real_invoker_process_starter_readiness_gate_id' => 'codex-real-invoker-process-starter-readiness-001',
            'manual_start_executor_receipt_id' => 'codex-real-invoker-manual-start-receipt-001',
            'process_starter_manifest_hash' => str_repeat('7', 64),
            'supervisor_binding_hash' => str_repeat('8', 64),
            'liveness_monitor_binding_hash' => str_repeat('9', 64),
            'cancellation_contract_hash' => str_repeat('a', 64),
            'output_capture_contract_hash' => str_repeat('b', 64),
            'cost_meter_contract_hash' => str_repeat('c', 64),
            'start_replay_guard_hash' => str_repeat('d', 64),
            'operator_process_starter_signature_hash' => str_repeat('e', 64),
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
            'reason' => 'write_manual_start_executor_receipt_without_starting_codex',
        ];
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('atlas_self_construction_agent_dispatch_authorizations');
        Schema::dropIfExists('atlas_self_construction_agent_wakeup_items');
        Schema::dropIfExists('atlas_self_construction_agent_work_products');
        Schema::dropIfExists('atlas_self_construction_agent_cost_events');
        Schema::dropIfExists('atlas_self_construction_agent_heartbeats');
        Schema::dropIfExists('atlas_self_construction_agent_runs');
        Schema::dropIfExists('atlas_ledger_events');
    }
}
