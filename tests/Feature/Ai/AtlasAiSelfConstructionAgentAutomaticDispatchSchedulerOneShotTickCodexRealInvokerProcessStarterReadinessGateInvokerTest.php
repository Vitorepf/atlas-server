<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateInvoker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateInvokerTest extends TestCase
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

    public function test_invoker_prepares_process_starter_without_starting_codex(): void
    {
        $this->createStartExecutionGateRun();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateInvoker::class)
            ->prepareCodexRealInvokerProcessStarter($this->validInput());

        $this->assertSame('one_shot_scheduler_codex_real_invoker_process_starter_readiness_gate_prepared', $result['status']);
        $this->assertTrue($result['codex_real_invoker_process_starter_readiness_gate_invoked']);
        $this->assertSame(1, $result['codex_real_invoker_process_starter_readiness_gate_invocation_count']);
        $this->assertTrue($result['real_invoker_process_starter_readiness_gate_prepared']);
        $this->assertTrue($result['start_execution_authorized']);
        $this->assertTrue($result['process_starter_ready']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertSame('activate_signed_one_shot_scheduler_tick_codex_real_invoker_manual_start_executor_receipt_contract', $result['next_required_slice']);

        $observed = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:attempt-001')
            ->firstOrFail();

        $this->assertSame(
            'real_invoker_process_starter_ready_pending_manual_start_executor',
            data_get($observed->metadata, 'codex_real_invoker_process_starter_readiness_gate.status')
        );
        $this->assertTrue((bool) data_get($observed->metadata, 'codex_real_invoker_process_starter_readiness_gate.process_starter_ready'));
        $this->assertFalse((bool) data_get($observed->metadata, 'codex_real_invoker_process_starter_readiness_gate.actual_process_start_allowed'));
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-process-starter-readiness-001',
        ]);
    }

    public function test_invoker_is_idempotent_for_same_readiness_id(): void
    {
        $this->createStartExecutionGateRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateInvoker::class);

        $first = $invoker->prepareCodexRealInvokerProcessStarter($this->validInput());
        $second = $invoker->prepareCodexRealInvokerProcessStarter($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_invoker_rejects_invalid_supervisor_binding_hash_without_updating_run(): void
    {
        $this->createStartExecutionGateRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_supervisor_binding_hash');

        try {
            app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateInvoker::class)
                ->prepareCodexRealInvokerProcessStarter(array_merge($this->validInput(), [
                    'supervisor_binding_hash' => 'not-a-hash',
                ]));
        } finally {
            $observed = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($observed->metadata, 'codex_real_invoker_process_starter_readiness_gate'));
        }
    }

    public function test_invoker_rejects_missing_start_execution_gate_metadata(): void
    {
        $this->createStartExecutionGateRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_start_execution_gate_missing_or_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateInvoker::class)
            ->prepareCodexRealInvokerProcessStarter($this->validInput());
    }

    public function test_invoker_rejects_duplicate_readiness_id(): void
    {
        $this->createStartExecutionGateRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateInvoker::class);
        $invoker->prepareCodexRealInvokerProcessStarter($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_process_starter_readiness_gate_already_prepared');

        $invoker->prepareCodexRealInvokerProcessStarter(array_merge($this->validInput(), [
            'real_invoker_process_starter_readiness_gate_id' => 'codex-real-invoker-process-starter-readiness-002',
        ]));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createStartExecutionGateRun(array $overrides = []): void
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
            'summary' => 'Codex real invoker start execution gate authorized; actual process start remains disabled.',
            'metadata' => $this->metadataWithStartExecutionGate(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $gateOverrides
     * @return array<string,mixed>
     */
    private function metadataWithStartExecutionGate(array $gateOverrides = []): array
    {
        return [
            'codex_real_invoker_start_execution_gate' => array_merge([
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
                'operator_execution_gate_receipt_hash' => str_repeat('1', 64),
                'execution_gate_policy_hash' => str_repeat('2', 64),
                'execution_window_hash' => str_repeat('3', 64),
                'preflight_snapshot_hash' => str_repeat('4', 64),
                'rollback_readiness_hash' => str_repeat('5', 64),
                'human_start_signature_hash' => str_repeat('6', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'real_invoker_start_execution_authorized_pending_process_starter',
                'real_invoker_start_execution_gate_authorized' => true,
                'start_envelope_ready' => true,
                'start_execution_authorized' => true,
                'actual_process_start_allowed' => false,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], $gateOverrides),
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
            'real_invoker_process_starter_readiness_gate_id' => 'codex-real-invoker-process-starter-readiness-001',
            'operator_execution_gate_receipt_hash' => str_repeat('1', 64),
            'execution_gate_policy_hash' => str_repeat('2', 64),
            'execution_window_hash' => str_repeat('3', 64),
            'preflight_snapshot_hash' => str_repeat('4', 64),
            'rollback_readiness_hash' => str_repeat('5', 64),
            'human_start_signature_hash' => str_repeat('6', 64),
            'process_starter_manifest_hash' => str_repeat('7', 64),
            'supervisor_binding_hash' => str_repeat('8', 64),
            'liveness_monitor_binding_hash' => str_repeat('9', 64),
            'cancellation_contract_hash' => str_repeat('a', 64),
            'output_capture_contract_hash' => str_repeat('b', 64),
            'cost_meter_contract_hash' => str_repeat('c', 64),
            'start_replay_guard_hash' => str_repeat('d', 64),
            'operator_process_starter_signature_hash' => str_repeat('e', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'prepare_process_starter_readiness_without_starting_codex',
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
