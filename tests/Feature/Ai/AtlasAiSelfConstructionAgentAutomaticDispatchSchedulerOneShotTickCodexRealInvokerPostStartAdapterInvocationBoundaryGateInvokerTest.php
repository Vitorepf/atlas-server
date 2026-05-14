<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentHeartbeat;
use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateInvoker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateInvokerTest extends TestCase
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

    public function test_scheduler_invoker_prepares_post_start_adapter_invocation_boundary_without_calling_codex(): void
    {
        $this->createPrerequisites();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateInvoker::class)
            ->prepareCodexRealInvokerPostStartAdapterInvocationBoundaryGate($this->validInput());

        $this->assertSame('one_shot_scheduler_codex_real_invoker_post_start_adapter_invocation_boundary_prepared', $result['status']);
        $this->assertTrue($result['codex_real_invoker_post_start_adapter_invocation_boundary_gate_invoked']);
        $this->assertSame(1, $result['codex_real_invoker_post_start_adapter_invocation_boundary_gate_invocation_count']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['observed_external_process_started']);
        $this->assertTrue($result['observed_provider_started']);
        $this->assertFalse($result['boundary_external_process_started']);
        $this->assertFalse($result['boundary_provider_started']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['provider_process_call_allowed']);
        $this->assertFalse($result['adapter_invocation_allowed']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertSame('codex_real_invoker_post_start_adapter_invocation_boundary_prepared', data_get($result, 'codex_real_invoker_post_start_adapter_invocation_boundary_gate_result.status'));
        $this->assertSame('adapter_invocation_prepared', data_get($result, 'adapter_invocation_boundary_result.status'));
        $this->assertSame(
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract',
            $result['next_required_slice']
        );

        $observedRun = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'codex-post-start-observed-run-001')
            ->firstOrFail();

        $this->assertSame(
            'post_start_adapter_invocation_boundary_prepared_pending_adapter_execution_guard',
            data_get($observedRun->metadata, 'codex_real_invoker_post_start_adapter_invocation_boundary.status')
        );

        $this->assertDatabaseHas('atlas_self_construction_agent_runs', [
            'run_key' => 'provider-start:codex-post-start-attempt-001',
            'status' => 'adapter_invocation_prepared',
        ]);
    }

    public function test_scheduler_invoker_is_idempotent_for_same_adapter_invocation_boundary(): void
    {
        $this->createPrerequisites();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateInvoker::class);

        $first = $invoker->prepareCodexRealInvokerPostStartAdapterInvocationBoundaryGate($this->validInput());
        $second = $invoker->prepareCodexRealInvokerPostStartAdapterInvocationBoundaryGate($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_scheduler_invoker_rejects_missing_provider_start_driver_metadata(): void
    {
        $this->createPrerequisites([
            'observed_run' => ['metadata' => []],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_provider_start_driver_missing_or_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateInvoker::class)
            ->prepareCodexRealInvokerPostStartAdapterInvocationBoundaryGate($this->validInput());
    }

    public function test_scheduler_invoker_rejects_provider_start_driver_with_adapter_invocation_allowed(): void
    {
        $this->createPrerequisites([
            'observed_run' => [
                'metadata' => $this->metadataWithProviderStartDriver(['adapter_invocation_allowed' => true]),
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('adapter_invocation_allowed_already_true');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateInvoker::class)
            ->prepareCodexRealInvokerPostStartAdapterInvocationBoundaryGate($this->validInput());
    }

    public function test_scheduler_invoker_rejects_missing_pre_start_heartbeat(): void
    {
        $this->createPrerequisites(skipProviderStartHeartbeat: true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pre_start_heartbeat_missing');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateInvoker::class)
            ->prepareCodexRealInvokerPostStartAdapterInvocationBoundaryGate($this->validInput());
    }

    public function test_scheduler_invoker_rejects_invalid_context_hash(): void
    {
        $this->createPrerequisites();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_context_pack_hash');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateInvoker::class)
            ->prepareCodexRealInvokerPostStartAdapterInvocationBoundaryGate(array_merge($this->validInput(), [
                'context_pack_hash' => str_repeat('z', 64),
            ]));
    }

    /**
     * @param  array<string,array<string,mixed>>  $overrides
     */
    private function createPrerequisites(array $overrides = [], bool $skipProviderStartHeartbeat = false): void
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
            'summary' => 'Codex real invoker post-start provider start driver prepared; adapter invocation remains disabled.',
            'metadata' => $this->metadataWithProviderStartDriver(),
        ], $overrides['observed_run'] ?? []));

        $providerRun = AtlasSelfConstructionAgentRun::query()->create([
            'run_key' => 'provider-start:codex-post-start-attempt-001',
            'packet_id' => 'AP-001',
            'reservation_id' => null,
            'actor' => 'codex-a',
            'provider' => 'codex',
            'provider_role' => 'implementation',
            'session_id' => 'session-a',
            'workspace_id' => 'atlas-self-construction-forge-workspace',
            'obra_id' => 'atlas-self-construction-os',
            'status' => 'pre_start_guarded',
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
            'summary' => 'Provider start preflight registered; adapter invocation remains disabled.',
            'metadata' => [
                'provider_start_attempt_id' => 'codex-post-start-attempt-001',
                'receipt_hash' => str_repeat('a', 64),
                'executor_contract_hash' => str_repeat('b', 64),
                'executor_release_authorization_hash' => str_repeat('c', 64),
                'sandbox_binding_key' => 'BINDING-001',
                'adapter' => 'codex',
                'command' => 'codex --continue',
                'cwd' => '/Users/vitorepf/develop/Atlas/worktrees/codex-a',
                'max_cost_usd' => 5,
                'reason' => 'future_provider_start_guard',
                'provider_started' => false,
                'adapter_invocation_allowed' => false,
            ],
        ]);

        if ($skipProviderStartHeartbeat) {
            return;
        }

        AtlasSelfConstructionAgentHeartbeat::query()->create([
            'agent_run_id' => $providerRun->id,
            'heartbeat_key' => 'provider-start:codex-post-start-attempt-001:heartbeat:pre-start',
            'sequence' => 1,
            'status' => 'alive',
            'signal' => 'pre_start_guard',
            'occurred_at' => CarbonImmutable::now(),
            'metadata' => [
                'provider_start_attempt_id' => 'codex-post-start-attempt-001',
                'provider_start_side_effect_performed' => false,
                'adapter_invocation_allowed' => false,
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $providerStartOverrides
     * @return array<string,mixed>
     */
    private function metadataWithProviderStartDriver(array $providerStartOverrides = []): array
    {
        return [
            'codex_real_invoker_post_start_provider_start_driver' => array_merge([
                'provider_start_driver_gate_id' => 'codex-real-invoker-post-start-provider-start-driver-gate-001',
                'provider_start_attempt_id' => 'codex-post-start-attempt-001',
                'dispatch_executor_handoff_id' => 'codex-real-invoker-post-start-dispatch-executor-handoff-001',
                'signed_dispatch_authorization_id' => 'codex-real-invoker-post-start-signed-dispatch-auth-001',
                'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
                'signed_dispatch_receipt_hash' => str_repeat('a', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_provider_start_driver_prepared_pending_adapter_invocation_boundary',
                'post_start_provider_start_driver_prepared' => true,
                'dispatch_receipt_used' => true,
                'observed_external_process_started' => true,
                'observed_provider_started' => true,
                'driver_provider_started' => false,
                'actual_process_start_allowed' => false,
                'token_spend_allowed' => false,
                'provider_process_call_allowed' => false,
                'adapter_invocation_allowed' => false,
                'dispatch_allowed' => false,
            ], $providerStartOverrides),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return [
            'run_key' => 'codex-post-start-observed-run-001',
            'adapter_invocation_boundary_gate_id' => 'codex-real-invoker-post-start-adapter-invocation-boundary-gate-001',
            'adapter_invocation_id' => 'adapter-invocation-001',
            'provider_start_driver_gate_id' => 'codex-real-invoker-post-start-provider-start-driver-gate-001',
            'provider_start_attempt_id' => 'codex-post-start-attempt-001',
            'dispatch_executor_handoff_id' => 'codex-real-invoker-post-start-dispatch-executor-handoff-001',
            'signed_dispatch_authorization_id' => 'codex-real-invoker-post-start-signed-dispatch-auth-001',
            'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            'signed_dispatch_receipt_hash' => str_repeat('a', 64),
            'command' => 'codex --continue',
            'cwd' => '/Users/vitorepf/develop/Atlas/worktrees/codex-a',
            'context_pack_hash' => str_repeat('1', 64),
            'continuation_summary_hash' => str_repeat('2', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'max_runtime_minutes' => 30,
            'max_cost_usd' => 5,
            'reason' => 'prepare_post_start_adapter_boundary_without_calling_codex',
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
