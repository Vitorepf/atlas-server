<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Models\AtlasSelfConstructionAgentSandboxBinding;
use App\Services\Ai\SelfConstruction\AgentCodexRealInvokerPostStartProviderExecutionContractGate;
use App\Services\Ai\SelfConstruction\AgentProviderAdapterRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProviderExecutionContractGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php'))->up();
        (require database_path('migrations/2026_05_12_030000_create_atlas_self_construction_agent_sandbox_bindings_table.php'))->up();
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    public function test_post_start_provider_execution_contract_prepares_codex_contract_without_starting_codex(): void
    {
        $this->createPrerequisites();

        $result = app(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class)
            ->preparePostStartProviderExecutionContract($this->validInput());

        $this->assertSame('codex_real_invoker_post_start_provider_execution_contract_prepared', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertSame(
            'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            $result['post_start_evidence_acceptance_bridge_id']
        );
        $this->assertTrue($result['provider_specific_execution_contract_ready']);
        $this->assertTrue($result['observed_external_process_started']);
        $this->assertTrue($result['observed_provider_started']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_process_call_allowed']);
        $this->assertFalse($result['adapter_invocation_allowed']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertSame('codex_provider_execution_prepared', data_get($result, 'codex_provider_execution_result.status'));

        $observedRun = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'codex-post-start-observed-run-001')
            ->firstOrFail();

        $providerRun = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:codex-post-start-attempt-001')
            ->firstOrFail();

        $this->assertSame(
            'post_start_codex_provider_execution_contract_prepared_pending_process_start_release',
            data_get($observedRun->metadata, 'codex_real_invoker_post_start_provider_execution_contract.status')
        );
        $this->assertSame(
            'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            data_get($observedRun->metadata, 'codex_real_invoker_post_start_provider_execution_contract.post_start_evidence_acceptance_bridge_id')
        );
        $this->assertSame(
            'prepared_pending_explicit_codex_process_release',
            data_get($providerRun->metadata, 'codex_provider_execution.status')
        );
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-execution-001',
        ]);
    }

    public function test_post_start_provider_execution_contract_is_idempotent_for_same_codex_execution(): void
    {
        $this->createPrerequisites();
        $gate = app(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class);

        $first = $gate->preparePostStartProviderExecutionContract($this->validInput());
        $second = $gate->preparePostStartProviderExecutionContract($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_post_start_provider_execution_contract_rejects_duplicate_codex_execution(): void
    {
        $this->createPrerequisites();
        $gate = app(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class);
        $gate->preparePostStartProviderExecutionContract($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_provider_execution_contract_already_recorded');

        $gate->preparePostStartProviderExecutionContract(array_merge($this->validInput(), [
            'codex_execution_id' => 'codex-execution-002',
        ]));
    }

    public function test_post_start_provider_execution_contract_rejects_missing_adapter_execution_guard(): void
    {
        $this->createPrerequisites([
            'observed_run' => ['metadata' => []],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_adapter_execution_guard_missing_or_mismatch');

        app(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class)
            ->preparePostStartProviderExecutionContract($this->validInput());
    }

    public function test_post_start_provider_execution_contract_rejects_guard_without_evidence_acceptance_bridge(): void
    {
        $this->createPrerequisites([
            'observed_run' => [
                'metadata' => $this->observedMetadata([
                    'post_start_evidence_acceptance_bridge_id' => null,
                ]),
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('post_start_evidence_acceptance_bridge_id_mismatch');

        app(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class)
            ->preparePostStartProviderExecutionContract($this->validInput());
    }

    public function test_post_start_provider_execution_contract_rejects_guard_with_dispatch_allowed(): void
    {
        $this->createPrerequisites([
            'observed_run' => [
                'metadata' => $this->observedMetadata(['dispatch_allowed' => true]),
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispatch_allowed_already_true');

        app(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class)
            ->preparePostStartProviderExecutionContract($this->validInput());
    }

    public function test_post_start_provider_execution_contract_rejects_missing_sandbox_binding(): void
    {
        $this->createPrerequisites(createSandboxBinding: false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sandbox_binding_not_found');

        app(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class)
            ->preparePostStartProviderExecutionContract($this->validInput());
    }

    public function test_post_start_provider_execution_contract_rolls_back_when_ledger_write_fails(): void
    {
        $this->createPrerequisites();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class)
                ->preparePostStartProviderExecutionContract($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $observedRun = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'codex-post-start-observed-run-001')
                ->firstOrFail();

            $providerRun = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:codex-post-start-attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($observedRun->metadata, 'codex_real_invoker_post_start_provider_execution_contract'));
            $this->assertNull(data_get($providerRun->metadata, 'codex_provider_execution'));
        }
    }

    /**
     * @param  array<string,array<string,mixed>>  $overrides
     */
    private function createPrerequisites(array $overrides = [], bool $createSandboxBinding = true): void
    {
        if ($createSandboxBinding) {
            $this->createActiveSandboxBinding();
        }

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
            'summary' => 'Codex real invoker post-start adapter execution guard recorded; provider-specific execution remains blocked.',
            'metadata' => $this->observedMetadata(),
        ], $overrides['observed_run'] ?? []));

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
            'summary' => 'Provider adapter execution guard recorded; external provider execution remains blocked.',
            'metadata' => [
                'provider_start_attempt_id' => 'codex-post-start-attempt-001',
                'sandbox_binding_key' => 'BINDING-001',
                'adapter' => 'codex',
                'command' => 'codex --continue',
                'cwd' => '/Users/vitorepf/develop/Atlas/worktrees/codex-a',
                'provider_started' => false,
                'adapter_invocation_allowed' => false,
                'adapter_invocation' => $this->adapterInvocationMetadata(),
                'provider_adapter_execution_guard' => $this->providerAdapterExecutionGuardMetadata(),
            ],
        ], $overrides['provider_run'] ?? []));
    }

    /**
     * @param  array<string,mixed>  $guardOverrides
     * @return array<string,mixed>
     */
    private function observedMetadata(array $guardOverrides = []): array
    {
        return [
            'codex_real_invoker_post_start_adapter_invocation_boundary' => [
                'adapter_invocation_boundary_gate_id' => 'codex-real-invoker-post-start-adapter-invocation-boundary-gate-001',
                'adapter_invocation_id' => 'adapter-invocation-001',
                'provider_start_driver_gate_id' => 'codex-real-invoker-post-start-provider-start-driver-gate-001',
                'provider_start_attempt_id' => 'codex-post-start-attempt-001',
                'provider_start_run_key' => 'provider-start:codex-post-start-attempt-001',
                'dispatch_executor_handoff_id' => 'codex-real-invoker-post-start-dispatch-executor-handoff-001',
                'signed_dispatch_authorization_id' => 'codex-real-invoker-post-start-signed-dispatch-auth-001',
                'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
                'signed_dispatch_receipt_hash' => str_repeat('a', 64),
                'context_pack_hash' => str_repeat('1', 64),
                'continuation_summary_hash' => str_repeat('2', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_adapter_invocation_boundary_prepared_pending_adapter_execution_guard',
            ],
            'codex_real_invoker_post_start_adapter_execution_guard' => array_merge([
                'adapter_execution_guard_gate_id' => 'codex-real-invoker-post-start-adapter-execution-guard-gate-001',
                'execution_guard_id' => 'execution-guard-001',
                'adapter_invocation_boundary_gate_id' => 'codex-real-invoker-post-start-adapter-invocation-boundary-gate-001',
                'adapter_invocation_id' => 'adapter-invocation-001',
                'provider_start_driver_gate_id' => 'codex-real-invoker-post-start-provider-start-driver-gate-001',
                'provider_start_attempt_id' => 'codex-post-start-attempt-001',
                'provider_start_run_key' => 'provider-start:codex-post-start-attempt-001',
                'dispatch_executor_handoff_id' => 'codex-real-invoker-post-start-dispatch-executor-handoff-001',
                'signed_dispatch_authorization_id' => 'codex-real-invoker-post-start-signed-dispatch-auth-001',
                'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
                'signed_dispatch_receipt_hash' => str_repeat('a', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_adapter_execution_blocked_pending_codex_provider_execution_contract',
                'post_start_adapter_execution_guard_recorded' => true,
                'post_start_adapter_invocation_boundary_prepared' => true,
                'post_start_provider_start_driver_prepared' => true,
                'dispatch_receipt_used' => true,
                'observed_external_process_started' => true,
                'observed_provider_started' => true,
                'actual_process_start_allowed' => false,
                'token_spend_allowed' => false,
                'provider_process_call_allowed' => false,
                'adapter_invocation_allowed' => false,
                'adapter_execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_specific_execution_contract_required' => true,
                'provider_adapter_execution_guard_result' => [
                    'status' => 'provider_adapter_execution_blocked',
                    'external_process_started' => false,
                    'token_spend_allowed' => false,
                    'provider_started' => false,
                    'dispatch_allowed' => false,
                ],
            ], $guardOverrides),
        ];
    }

    private function createActiveSandboxBinding(): void
    {
        AtlasSelfConstructionAgentSandboxBinding::query()->create([
            'binding_key' => 'BINDING-001',
            'receipt_hash' => str_repeat('a', 64),
            'receipt_key' => 'dispatch-receipt-001',
            'packet_id' => 'AP-001',
            'provider' => 'codex',
            'provider_role' => 'implementation',
            'status' => 'active',
            'workspace_root' => '/Users/vitorepf/develop/Atlas',
            'worktree_path' => '/Users/vitorepf/develop/Atlas/worktrees/codex-a',
            'branch' => 'self-construction/codex-a',
            'executor_contract_hash' => str_repeat('b', 64),
            'executor_release_authorization_hash' => str_repeat('c', 64),
            'allowed_files_hash' => str_repeat('d', 64),
            'forbidden_scope_hash' => str_repeat('e', 64),
            'scope_validator_hash' => str_repeat('f', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'payload' => [],
            'activated_at' => CarbonImmutable::now(),
            'released_at' => null,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function adapterInvocationMetadata(): array
    {
        $registry = app(AgentProviderAdapterRegistry::class);
        $descriptor = $registry->resolve('codex', 'codex');

        return [
            'adapter_invocation_id' => 'adapter-invocation-001',
            'provider_start_attempt_id' => 'codex-post-start-attempt-001',
            'provider' => 'codex',
            'adapter' => 'codex',
            'adapter_id' => $descriptor['adapter_id'],
            'adapter_descriptor_hash' => $registry->descriptorHash($descriptor),
            'command' => 'codex --continue',
            'cwd' => '/Users/vitorepf/develop/Atlas/worktrees/codex-a',
            'context_pack_hash' => str_repeat('1', 64),
            'continuation_summary_hash' => str_repeat('2', 64),
            'status' => 'prepared_pending_external_invocation',
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_started' => false,
            'dispatch_allowed' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function providerAdapterExecutionGuardMetadata(): array
    {
        $registry = app(AgentProviderAdapterRegistry::class);
        $descriptor = $registry->resolve('codex', 'codex');

        return [
            'execution_guard_id' => 'execution-guard-001',
            'adapter_invocation_id' => 'adapter-invocation-001',
            'provider' => 'codex',
            'adapter' => 'codex',
            'adapter_id' => $descriptor['adapter_id'],
            'adapter_descriptor_hash' => $registry->descriptorHash($descriptor),
            'status' => 'blocked_pending_provider_specific_execution_contract',
            'blocked_by' => 'provider_specific_execution_contract_missing',
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_started' => false,
            'dispatch_allowed' => false,
            'recorded_by' => 'codex-a',
            'recorded_session' => 'session-a',
            'reason' => 'provider_specific_execution_contract_not_ready',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return [
            'run_key' => 'codex-post-start-observed-run-001',
            'provider_execution_contract_gate_id' => 'codex-real-invoker-post-start-provider-execution-contract-gate-001',
            'codex_execution_id' => 'codex-execution-001',
            'adapter_execution_guard_gate_id' => 'codex-real-invoker-post-start-adapter-execution-guard-gate-001',
            'execution_guard_id' => 'execution-guard-001',
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
            'max_runtime_minutes' => 240,
            'max_cost_usd' => 25.0,
            'reason' => 'prepare_codex_provider_execution_contract_without_starting_provider',
        ];
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('atlas_self_construction_agent_sandbox_bindings');
        Schema::dropIfExists('atlas_self_construction_agent_wakeup_items');
        Schema::dropIfExists('atlas_self_construction_agent_work_products');
        Schema::dropIfExists('atlas_self_construction_agent_cost_events');
        Schema::dropIfExists('atlas_self_construction_agent_heartbeats');
        Schema::dropIfExists('atlas_self_construction_agent_runs');
        Schema::dropIfExists('atlas_ledger_events');
    }
}
