<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateInvoker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateInvokerTest extends TestCase
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

    public function test_scheduler_invoker_authorizes_post_start_executor_fresh_release_without_enabling_executor(): void
    {
        $this->createPrerequisites();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateInvoker::class)
            ->authorizeCodexRealInvokerPostStartExecutorFreshReleaseGate($this->validInput());

        $this->assertSame('one_shot_scheduler_codex_real_invoker_post_start_executor_fresh_release_authorized', $result['status']);
        $this->assertTrue($result['codex_real_invoker_post_start_executor_fresh_release_gate_invoked']);
        $this->assertSame(1, $result['codex_real_invoker_post_start_executor_fresh_release_gate_invocation_count']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['post_start_executor_fresh_release_recorded']);
        $this->assertTrue($result['real_invoker_executor_fresh_release_authorized']);
        $this->assertTrue($result['executor_enablement_required']);
        $this->assertTrue($result['observed_external_process_started']);
        $this->assertTrue($result['observed_provider_started']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['provider_process_call_allowed']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['executor_enabled']);
        $this->assertFalse($result['adapter_invocation_allowed']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertSame('codex_real_invoker_post_start_executor_fresh_release_recorded', data_get($result, 'codex_real_invoker_post_start_executor_fresh_release_gate_result.status'));
        $this->assertSame('codex_real_invoker_executor_fresh_release_authorized', data_get($result, 'codex_real_invoker_executor_fresh_release_result.status'));
        $this->assertSame(
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_enablement_gate_contract',
            $result['next_required_slice']
        );

        $observedRun = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'codex-post-start-observed-run-001')
            ->firstOrFail();

        $providerRun = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:codex-post-start-attempt-001')
            ->firstOrFail();

        $this->assertSame(
            'post_start_executor_fresh_release_authorized_pending_executor_enablement',
            data_get($observedRun->metadata, 'codex_real_invoker_post_start_executor_fresh_release.status')
        );
        $this->assertSame(
            'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            data_get($observedRun->metadata, 'codex_real_invoker_post_start_executor_fresh_release.post_start_evidence_acceptance_bridge_id')
        );
        $this->assertSame(
            'real_invoker_executor_fresh_release_authorized_pending_enablement',
            data_get($providerRun->metadata, 'codex_real_invoker_executor_fresh_release.status')
        );
        $this->assertFalse((bool) data_get($providerRun->metadata, 'codex_real_invoker_executor_fresh_release.executor_enabled'));
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-fresh-release-001',
        ]);
    }

    public function test_scheduler_invoker_is_idempotent_for_same_post_start_executor_fresh_release(): void
    {
        $this->createPrerequisites();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateInvoker::class);

        $first = $invoker->authorizeCodexRealInvokerPostStartExecutorFreshReleaseGate($this->validInput());
        $second = $invoker->authorizeCodexRealInvokerPostStartExecutorFreshReleaseGate($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertFalse($second['executor_enabled']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_scheduler_invoker_rejects_invalid_plan_revalidation_report_hash(): void
    {
        $this->createPrerequisites();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_plan_revalidation_report_hash');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateInvoker::class)
            ->authorizeCodexRealInvokerPostStartExecutorFreshReleaseGate(array_merge($this->validInput(), [
                'plan_revalidation_report_hash' => 'not-a-hash',
            ]));
    }

    public function test_scheduler_invoker_rejects_missing_executor_plan_bridge(): void
    {
        $this->createPrerequisites([
            'observed_run' => ['metadata' => []],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('post_start_executor_plan_gate_id_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateInvoker::class)
            ->authorizeCodexRealInvokerPostStartExecutorFreshReleaseGate($this->validInput());
    }

    public function test_scheduler_invoker_rejects_executor_plan_with_executor_enabled(): void
    {
        $this->createPrerequisites([
            'observed_run' => [
                'metadata' => $this->observedMetadata(['executor_enabled' => true]),
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('executor_enabled_already_true');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateInvoker::class)
            ->authorizeCodexRealInvokerPostStartExecutorFreshReleaseGate($this->validInput());
    }

    public function test_scheduler_invoker_rejects_missing_provider_start_executor_plan_run(): void
    {
        $this->createPrerequisites([
            'skip_provider_run' => ['yes' => true],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('agent_run_not_found');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateInvoker::class)
            ->authorizeCodexRealInvokerPostStartExecutorFreshReleaseGate($this->validInput());
    }

    /**
     * @param  array<string,array<string,mixed>>  $overrides
     */
    private function createPrerequisites(array $overrides = []): void
    {
        AtlasSelfConstructionAgentRun::query()->create(array_merge([
            'run_key' => 'codex-post-start-observed-run-001',
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
            'summary' => 'Codex real invoker post-start executor plan prepared; executor fresh release remains required.',
            'metadata' => $this->observedMetadata(),
        ], $overrides['observed_run'] ?? []));

        if (($overrides['skip_provider_run'] ?? []) !== ['yes' => true]) {
            AtlasSelfConstructionAgentRun::query()->create(array_merge([
                'run_key' => 'provider-start:codex-post-start-attempt-001',
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
                'summary' => 'Codex real invoker executor plan prepared; executor remains disabled.',
                'metadata' => $this->providerMetadata(),
            ], $overrides['provider_run'] ?? []));
        }
    }

    /**
     * @param  array<string,mixed>  $planOverrides
     * @return array<string,mixed>
     */
    private function observedMetadata(array $planOverrides = []): array
    {
        return [
            'codex_real_invoker_post_start_executor_plan' => array_merge($this->sharedBridgeMetadata(), $this->executorPlanMetadata(), [
                'post_start_executor_plan_gate_id' => 'codex-real-invoker-post-start-executor-plan-gate-001',
                'status' => 'post_start_executor_plan_prepared_pending_executor_fresh_release',
                'post_start_executor_plan_recorded' => true,
                'real_invoker_executor_plan_prepared' => true,
                'executor_fresh_release_required' => true,
                'observed_external_process_started' => true,
                'observed_provider_started' => true,
                'actual_process_start_allowed' => false,
                'provider_process_call_allowed' => false,
                'adapter_invocation_allowed' => false,
                'adapter_execution_allowed' => false,
            ], $planOverrides),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function providerMetadata(): array
    {
        return [
            'codex_real_invoker_executor_plan' => $this->executorPlanMetadata([
                'status' => 'real_invoker_executor_plan_prepared_disabled_pending_fresh_release',
                'fresh_release_required_before_start' => true,
            ]),
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function executorPlanMetadata(array $overrides = []): array
    {
        return array_merge([
            'real_invoker_executor_plan_id' => 'codex-real-invoker-executor-plan-001',
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
            'operator_executor_plan_receipt_hash' => str_repeat('a', 64),
            'real_invoker_contract_hash' => str_repeat('2', 64),
            'release_policy_hash' => str_repeat('5', 64),
            'implementation_plan_hash' => str_repeat('c', 64),
            'executor_binary_contract_hash' => str_repeat('9', 64),
            'executor_observability_contract_hash' => str_repeat('1', 64),
            'process_command_hash' => str_repeat('6', 64),
            'environment_contract_hash' => str_repeat('7', 64),
            'termination_policy_hash' => str_repeat('8', 64),
            'stdout_stderr_sink_hash' => str_repeat('3', 64),
            'liveness_probe_hash' => str_repeat('4', 64),
            'rollback_plan_hash' => str_repeat('3', 64),
            'max_runtime_policy_hash' => str_repeat('4', 64),
            'provider' => 'codex',
            'adapter' => 'codex',
            'real_invoker_executor_plan_prepared' => true,
            'executor_enabled' => false,
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_started' => false,
            'dispatch_allowed' => false,
        ], $overrides);
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return array_merge($this->sharedBridgeMetadata(), $this->executorPlanMetadata(), [
            'run_key' => 'codex-post-start-observed-run-001',
            'post_start_executor_fresh_release_gate_id' => 'codex-real-invoker-post-start-executor-fresh-release-gate-001',
            'post_start_executor_plan_gate_id' => 'codex-real-invoker-post-start-executor-plan-gate-001',
            'real_invoker_executor_fresh_release_id' => 'codex-real-invoker-fresh-release-001',
            'operator_fresh_release_receipt_hash' => str_repeat('b', 64),
            'plan_revalidation_report_hash' => str_repeat('0', 64),
            'freshness_window_hash' => str_repeat('a', 64),
            'final_human_signature_hash' => str_repeat('d', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'authorize_post_start_executor_fresh_release_without_enabling_executor',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function sharedBridgeMetadata(): array
    {
        return [
            'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            'post_start_implementation_boundary_gate_id' => 'codex-real-invoker-post-start-implementation-boundary-gate-001',
            'post_start_signed_real_invoker_release_gate_id' => 'codex-real-invoker-post-start-signed-release-gate-001',
            'post_start_real_invoker_release_preflight_gate_id' => 'codex-real-invoker-post-start-release-preflight-gate-001',
            'post_start_external_process_invoker_dry_run_gate_id' => 'codex-real-invoker-post-start-external-process-invoker-dry-run-gate-001',
            'post_start_process_invocation_authorization_gate_id' => 'codex-real-invoker-post-start-process-invocation-authorization-gate-001',
            'post_start_external_process_runtime_gate_id' => 'codex-real-invoker-post-start-external-process-runtime-gate-001',
            'post_start_final_process_spawn_executor_gate_id' => 'codex-real-invoker-post-start-final-process-spawn-executor-gate-001',
            'post_start_process_spawn_enablement_gate_id' => 'codex-real-invoker-post-start-process-spawn-enable-gate-001',
            'post_start_supervised_start_gate_id' => 'codex-real-invoker-post-start-supervised-start-gate-001',
            'post_start_process_start_release_gate_id' => 'codex-real-invoker-post-start-process-start-release-gate-001',
            'process_start_release_id' => 'codex-start-release-001',
            'provider_execution_contract_gate_id' => 'codex-real-invoker-post-start-provider-execution-contract-gate-001',
            'adapter_execution_guard_gate_id' => 'codex-real-invoker-post-start-adapter-execution-guard-gate-001',
            'execution_guard_id' => 'execution-guard-001',
            'adapter_invocation_boundary_gate_id' => 'codex-real-invoker-post-start-adapter-invocation-boundary-gate-001',
            'adapter_invocation_id' => 'adapter-invocation-001',
            'provider_start_driver_gate_id' => 'codex-real-invoker-post-start-provider-start-driver-gate-001',
            'provider_start_attempt_id' => 'codex-post-start-attempt-001',
            'signed_dispatch_receipt_hash' => str_repeat('a', 64),
            'operator_release_receipt_hash' => str_repeat('b', 64),
            'operator_spawn_receipt_hash' => str_repeat('e', 64),
            'operator_final_spawn_receipt_hash' => str_repeat('1', 64),
            'operator_runtime_receipt_hash' => str_repeat('5', 64),
            'operator_invocation_receipt_hash' => str_repeat('9', 64),
            'operator_dry_run_receipt_hash' => str_repeat('0', 64),
            'operator_release_preflight_receipt_hash' => str_repeat('1', 64),
            'operator_signed_release_receipt_hash' => str_repeat('9', 64),
            'operator_implementation_boundary_receipt_hash' => str_repeat('b', 64),
            'signature_verification_report_hash' => str_repeat('a', 64),
            'codex_execution_contract_hash' => str_repeat('c', 64),
            'supervised_start_contract_hash' => str_repeat('f', 64),
            'runtime_supervision_plan_hash' => str_repeat('2', 64),
            'runtime_driver_contract_hash' => str_repeat('d', 64),
            'invoker_contract_hash' => str_repeat('a', 64),
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
