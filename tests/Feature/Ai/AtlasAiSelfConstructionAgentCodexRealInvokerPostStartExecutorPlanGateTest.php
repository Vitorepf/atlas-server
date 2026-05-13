<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexRealInvokerPostStartExecutorPlanGate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerPostStartExecutorPlanGateTest extends TestCase
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

    public function test_post_start_executor_plan_records_without_enabling_executor(): void
    {
        $this->createObservedImplementationBoundaryRun();
        $this->createProviderImplementationBoundaryRun();

        $result = app(AgentCodexRealInvokerPostStartExecutorPlanGate::class)
            ->preparePostStartExecutorPlan($this->validInput());

        $this->assertSame('codex_real_invoker_post_start_executor_plan_recorded', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['real_invoker_executor_plan_prepared']);
        $this->assertTrue($result['executor_fresh_release_required']);
        $this->assertTrue($result['observed_external_process_started']);
        $this->assertFalse($result['executor_enabled']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['dispatch_allowed']);

        $observed = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'observed-codex-run-001')
            ->firstOrFail();

        $this->assertSame(
            'post_start_executor_plan_prepared_pending_executor_fresh_release',
            data_get($observed->metadata, 'codex_real_invoker_post_start_executor_plan.status')
        );

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-executor-plan-001',
        ]);
    }

    public function test_post_start_executor_plan_is_idempotent_for_same_plan_id(): void
    {
        $this->createObservedImplementationBoundaryRun();
        $this->createProviderImplementationBoundaryRun();
        $gate = app(AgentCodexRealInvokerPostStartExecutorPlanGate::class);

        $first = $gate->preparePostStartExecutorPlan($this->validInput());
        $second = $gate->preparePostStartExecutorPlan($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_post_start_executor_plan_rejects_duplicate_plan_id(): void
    {
        $this->createObservedImplementationBoundaryRun();
        $this->createProviderImplementationBoundaryRun();
        $gate = app(AgentCodexRealInvokerPostStartExecutorPlanGate::class);
        $gate->preparePostStartExecutorPlan($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_executor_plan_already_recorded');

        $gate->preparePostStartExecutorPlan(array_merge($this->validInput(), [
            'real_invoker_executor_plan_id' => 'codex-real-invoker-executor-plan-002',
        ]));
    }

    public function test_post_start_executor_plan_rejects_missing_implementation_boundary_bridge(): void
    {
        $this->createObservedImplementationBoundaryRun(['metadata' => []]);
        $this->createProviderImplementationBoundaryRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('post_start_implementation_boundary_gate_id_mismatch');

        app(AgentCodexRealInvokerPostStartExecutorPlanGate::class)
            ->preparePostStartExecutorPlan($this->validInput());
    }

    public function test_post_start_executor_plan_rejects_bridge_with_dispatch_allowed(): void
    {
        $this->createObservedImplementationBoundaryRun([
            'metadata' => $this->observedMetadataWithImplementationBoundary(['dispatch_allowed' => true]),
        ]);
        $this->createProviderImplementationBoundaryRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispatch_allowed_already_true');

        app(AgentCodexRealInvokerPostStartExecutorPlanGate::class)
            ->preparePostStartExecutorPlan($this->validInput());
    }

    public function test_post_start_executor_plan_rejects_missing_provider_start_run(): void
    {
        $this->createObservedImplementationBoundaryRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('agent_run_not_found');

        app(AgentCodexRealInvokerPostStartExecutorPlanGate::class)
            ->preparePostStartExecutorPlan($this->validInput());
    }

    public function test_post_start_executor_plan_rolls_back_when_ledger_write_fails(): void
    {
        $this->createObservedImplementationBoundaryRun();
        $this->createProviderImplementationBoundaryRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerPostStartExecutorPlanGate::class)
                ->preparePostStartExecutorPlan($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $observed = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'observed-codex-run-001')
                ->firstOrFail();
            $provider = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($observed->metadata, 'codex_real_invoker_post_start_executor_plan'));
            $this->assertNull(data_get($provider->metadata, 'codex_real_invoker_executor_plan'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createObservedImplementationBoundaryRun(array $overrides = []): void
    {
        AtlasSelfConstructionAgentRun::query()->create(array_merge($this->baseRun('observed-codex-run-001'), [
            'summary' => 'Observed Codex process reached post-start implementation boundary; executor plan remains required.',
            'metadata' => $this->observedMetadataWithImplementationBoundary(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createProviderImplementationBoundaryRun(array $overrides = []): void
    {
        AtlasSelfConstructionAgentRun::query()->create(array_merge($this->baseRun('provider-start:attempt-001'), [
            'summary' => 'Codex real invoker implementation boundary prepared; executor remains disabled.',
            'metadata' => $this->providerMetadataWithImplementationBoundary(),
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
     * @param  array<string,mixed>  $boundaryOverrides
     * @return array<string,mixed>
     */
    private function observedMetadataWithImplementationBoundary(array $boundaryOverrides = []): array
    {
        return [
            'codex_real_invoker_post_start_implementation_boundary' => array_merge($this->sharedBridgeMetadata(), [
                'post_start_implementation_boundary_gate_id' => 'codex-post-start-implementation-boundary-gate-001',
                'real_invoker_implementation_boundary_id' => 'codex-real-invoker-boundary-001',
                'operator_implementation_boundary_receipt_hash' => str_repeat('b', 64),
                'implementation_plan_hash' => str_repeat('c', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_implementation_boundary_prepared_pending_executor_plan',
                'post_start_implementation_boundary_recorded' => true,
                'real_invoker_implementation_boundary_prepared' => true,
                'executor_plan_required' => true,
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
            ], $boundaryOverrides),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function providerMetadataWithImplementationBoundary(): array
    {
        return [
            'codex_real_invoker_implementation_boundary' => [
                'real_invoker_implementation_boundary_id' => 'codex-real-invoker-boundary-001',
                'signed_real_invoker_release_id' => 'codex-signed-real-invoker-release-001',
                'real_invoker_release_preflight_id' => 'codex-real-invoker-preflight-001',
                'dry_run_id' => 'codex-invoker-dry-run-001',
                'invocation_authorization_id' => 'codex-invocation-auth-001',
                'runtime_driver_id' => 'codex-runtime-driver-001',
                'spawn_executor_id' => 'codex-spawn-executor-001',
                'spawn_enablement_id' => 'codex-spawn-enable-001',
                'supervised_start_id' => 'codex-supervised-start-001',
                'process_start_release_id' => 'codex-start-release-001',
                'codex_execution_id' => 'codex-execution-001',
                'operator_implementation_boundary_receipt_hash' => str_repeat('b', 64),
                'real_invoker_contract_hash' => str_repeat('2', 64),
                'release_policy_hash' => str_repeat('5', 64),
                'implementation_plan_hash' => str_repeat('c', 64),
                'process_command_hash' => str_repeat('6', 64),
                'environment_contract_hash' => str_repeat('7', 64),
                'termination_policy_hash' => str_repeat('8', 64),
                'stdout_stderr_sink_hash' => str_repeat('6', 64),
                'liveness_probe_hash' => str_repeat('7', 64),
                'rollback_plan_hash' => str_repeat('3', 64),
                'max_runtime_policy_hash' => str_repeat('4', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'real_invoker_implementation_boundary_prepared_pending_executor',
                'real_invoker_implementation_boundary_prepared' => true,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return array_merge($this->sharedBridgeMetadata(), [
            'run_key' => 'observed-codex-run-001',
            'post_start_executor_plan_gate_id' => 'codex-post-start-executor-plan-gate-001',
            'post_start_implementation_boundary_gate_id' => 'codex-post-start-implementation-boundary-gate-001',
            'real_invoker_implementation_boundary_id' => 'codex-real-invoker-boundary-001',
            'real_invoker_executor_plan_id' => 'codex-real-invoker-executor-plan-001',
            'operator_implementation_boundary_receipt_hash' => str_repeat('b', 64),
            'operator_executor_plan_receipt_hash' => str_repeat('a', 64),
            'implementation_plan_hash' => str_repeat('c', 64),
            'executor_binary_contract_hash' => str_repeat('9', 64),
            'executor_observability_contract_hash' => str_repeat('1', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'prepare_post_start_executor_plan_without_enabling_executor',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function sharedBridgeMetadata(): array
    {
        return [
            'post_start_signed_real_invoker_release_gate_id' => 'codex-post-start-signed-real-invoker-release-gate-001',
            'post_start_real_invoker_release_preflight_gate_id' => 'codex-post-start-real-invoker-release-preflight-gate-001',
            'post_start_external_process_invoker_dry_run_gate_id' => 'codex-post-start-external-process-invoker-dry-run-gate-001',
            'post_start_process_invocation_authorization_gate_id' => 'codex-post-start-process-invocation-authorization-gate-001',
            'post_start_external_process_runtime_gate_id' => 'codex-post-start-external-process-runtime-gate-001',
            'post_start_final_process_spawn_executor_gate_id' => 'codex-post-start-final-spawn-executor-gate-001',
            'post_start_process_spawn_enablement_gate_id' => 'codex-post-start-spawn-enablement-gate-001',
            'post_start_supervised_start_gate_id' => 'codex-post-start-supervised-start-gate-001',
            'post_start_process_start_release_gate_id' => 'codex-post-start-release-gate-001',
            'process_start_release_id' => 'codex-start-release-001',
            'provider_execution_contract_gate_id' => 'codex-provider-execution-contract-gate-001',
            'codex_execution_id' => 'codex-execution-001',
            'supervised_start_id' => 'codex-supervised-start-001',
            'spawn_enablement_id' => 'codex-spawn-enable-001',
            'spawn_executor_id' => 'codex-spawn-executor-001',
            'runtime_driver_id' => 'codex-runtime-driver-001',
            'invocation_authorization_id' => 'codex-invocation-auth-001',
            'dry_run_id' => 'codex-invoker-dry-run-001',
            'real_invoker_release_preflight_id' => 'codex-real-invoker-preflight-001',
            'signed_real_invoker_release_id' => 'codex-signed-real-invoker-release-001',
            'adapter_execution_guard_gate_id' => 'codex-adapter-execution-guard-gate-001',
            'execution_guard_id' => 'codex-execution-guard-001',
            'adapter_invocation_boundary_gate_id' => 'codex-adapter-invocation-boundary-gate-001',
            'adapter_invocation_id' => 'codex-adapter-invocation-001',
            'provider_start_driver_gate_id' => 'codex-provider-start-driver-gate-001',
            'provider_start_attempt_id' => 'attempt-001',
            'signed_dispatch_receipt_hash' => str_repeat('a', 64),
            'operator_release_receipt_hash' => str_repeat('b', 64),
            'operator_spawn_receipt_hash' => str_repeat('c', 64),
            'operator_final_spawn_receipt_hash' => str_repeat('d', 64),
            'operator_runtime_receipt_hash' => str_repeat('e', 64),
            'operator_invocation_receipt_hash' => str_repeat('f', 64),
            'operator_dry_run_receipt_hash' => str_repeat('1', 64),
            'operator_release_preflight_receipt_hash' => str_repeat('2', 64),
            'operator_signed_release_receipt_hash' => str_repeat('9', 64),
            'signature_verification_report_hash' => str_repeat('a', 64),
            'codex_execution_contract_hash' => str_repeat('3', 64),
            'supervised_start_contract_hash' => str_repeat('4', 64),
            'runtime_supervision_plan_hash' => str_repeat('5', 64),
            'stdout_stderr_sink_hash' => str_repeat('6', 64),
            'liveness_probe_hash' => str_repeat('7', 64),
            'runtime_driver_contract_hash' => str_repeat('8', 64),
            'invoker_contract_hash' => str_repeat('9', 64),
            'real_invoker_contract_hash' => str_repeat('2', 64),
            'release_policy_hash' => str_repeat('5', 64),
            'process_command_hash' => str_repeat('6', 64),
            'environment_contract_hash' => str_repeat('7', 64),
            'termination_policy_hash' => str_repeat('8', 64),
            'rollback_plan_hash' => str_repeat('3', 64),
            'max_runtime_policy_hash' => str_repeat('4', 64),
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
