<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexRealInvokerPostStartAdapterExecutionGuardGate;
use App\Services\Ai\SelfConstruction\AgentProviderAdapterRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerPostStartAdapterExecutionGuardGateTest extends TestCase
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

    public function test_post_start_adapter_execution_guard_blocks_without_calling_codex(): void
    {
        $this->createPrerequisites();

        $result = app(AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class)
            ->blockPostStartAdapterExecution($this->validInput());

        $this->assertSame('codex_real_invoker_post_start_adapter_execution_blocked', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['provider_specific_execution_contract_required']);
        $this->assertTrue($result['observed_external_process_started']);
        $this->assertTrue($result['observed_provider_started']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_process_call_allowed']);
        $this->assertFalse($result['adapter_invocation_allowed']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertSame('provider_adapter_execution_blocked', data_get($result, 'provider_adapter_execution_guard_result.status'));
        $this->assertSame(
            'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            $result['post_start_evidence_acceptance_bridge_id']
        );

        $observedRun = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'codex-post-start-observed-run-001')
            ->firstOrFail();

        $providerRun = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:codex-post-start-attempt-001')
            ->firstOrFail();

        $this->assertSame(
            'post_start_adapter_execution_blocked_pending_codex_provider_execution_contract',
            data_get($observedRun->metadata, 'codex_real_invoker_post_start_adapter_execution_guard.status')
        );
        $this->assertSame(
            'blocked_pending_provider_specific_execution_contract',
            data_get($providerRun->metadata, 'provider_adapter_execution_guard.status')
        );
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'execution-guard-001',
        ]);
    }

    public function test_post_start_adapter_execution_guard_is_idempotent_for_same_guard(): void
    {
        $this->createPrerequisites();
        $gate = app(AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class);

        $first = $gate->blockPostStartAdapterExecution($this->validInput());
        $second = $gate->blockPostStartAdapterExecution($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_post_start_adapter_execution_guard_rejects_duplicate_guard(): void
    {
        $this->createPrerequisites();
        $gate = app(AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class);
        $gate->blockPostStartAdapterExecution($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_adapter_execution_guard_already_recorded');

        $gate->blockPostStartAdapterExecution(array_merge($this->validInput(), [
            'execution_guard_id' => 'execution-guard-002',
        ]));
    }

    public function test_post_start_adapter_execution_guard_rejects_missing_boundary_metadata(): void
    {
        $this->createPrerequisites([
            'observed_run' => ['metadata' => []],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_adapter_invocation_boundary_missing_or_mismatch');

        app(AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class)
            ->blockPostStartAdapterExecution($this->validInput());
    }

    public function test_post_start_adapter_execution_guard_rejects_boundary_with_dispatch_allowed(): void
    {
        $this->createPrerequisites([
            'observed_run' => [
                'metadata' => $this->metadataWithBoundary(['dispatch_allowed' => true]),
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispatch_allowed_already_true');

        app(AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class)
            ->blockPostStartAdapterExecution($this->validInput());
    }

    public function test_post_start_adapter_execution_guard_rejects_boundary_without_evidence_acceptance_bridge(): void
    {
        $this->createPrerequisites([
            'observed_run' => [
                'metadata' => $this->metadataWithBoundary([
                    'post_start_evidence_acceptance_bridge_id' => null,
                ]),
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('post_start_evidence_acceptance_bridge_id_mismatch');

        app(AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class)
            ->blockPostStartAdapterExecution($this->validInput());
    }

    public function test_post_start_adapter_execution_guard_rejects_provider_start_run_without_adapter_invocation(): void
    {
        $this->createPrerequisites([
            'provider_run' => ['metadata' => []],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('adapter_invocation_id_mismatch');

        app(AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class)
            ->blockPostStartAdapterExecution($this->validInput());
    }

    public function test_post_start_adapter_execution_guard_rolls_back_observed_run_when_guard_fails(): void
    {
        $this->createPrerequisites();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class)
                ->blockPostStartAdapterExecution($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $observedRun = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'codex-post-start-observed-run-001')
                ->firstOrFail();

            $providerRun = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:codex-post-start-attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($observedRun->metadata, 'codex_real_invoker_post_start_adapter_execution_guard'));
            $this->assertNull(data_get($providerRun->metadata, 'provider_adapter_execution_guard'));
        }
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
            'summary' => 'Codex real invoker post-start adapter invocation boundary prepared; adapter execution remains disabled.',
            'metadata' => $this->metadataWithBoundary(),
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
            'summary' => 'Adapter invocation boundary prepared; external provider process remains disabled.',
            'metadata' => [
                'provider_start_attempt_id' => 'codex-post-start-attempt-001',
                'adapter' => 'codex',
                'command' => 'codex --continue',
                'cwd' => '/Users/vitorepf/develop/Atlas/worktrees/codex-a',
                'provider_started' => false,
                'adapter_invocation_allowed' => false,
                'adapter_invocation' => $this->adapterInvocationMetadata(),
            ],
        ], $overrides['provider_run'] ?? []));
    }

    /**
     * @param  array<string,mixed>  $boundaryOverrides
     * @return array<string,mixed>
     */
    private function metadataWithBoundary(array $boundaryOverrides = []): array
    {
        return [
            'codex_real_invoker_post_start_adapter_invocation_boundary' => array_merge([
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
                'post_start_adapter_invocation_boundary_prepared' => true,
                'post_start_provider_start_driver_prepared' => true,
                'dispatch_receipt_used' => true,
                'observed_external_process_started' => true,
                'observed_provider_started' => true,
                'boundary_external_process_started' => false,
                'boundary_provider_started' => false,
                'actual_process_start_allowed' => false,
                'token_spend_allowed' => false,
                'provider_process_call_allowed' => false,
                'adapter_invocation_allowed' => false,
                'dispatch_allowed' => false,
            ], $boundaryOverrides),
        ];
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
    private function validInput(): array
    {
        return [
            'run_key' => 'codex-post-start-observed-run-001',
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
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'block_post_start_adapter_execution_without_calling_codex',
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
