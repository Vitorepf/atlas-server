<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Models\AtlasSelfConstructionAgentSandboxBinding;
use App\Services\Ai\SelfConstruction\AgentCodexProviderExecutionDriver;
use App\Services\Ai\SelfConstruction\AgentProviderAdapterRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexProviderExecutionDriverTest extends TestCase
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

    public function test_driver_prepares_codex_execution_without_starting_codex(): void
    {
        $this->createActiveSandboxBinding();
        $this->createGuardedRun();

        $result = app(AgentCodexProviderExecutionDriver::class)
            ->prepareCodexExecution($this->validInput());

        $this->assertSame('codex_provider_execution_prepared', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['provider_specific_contract_ready']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['dispatch_allowed']);

        $this->assertDatabaseHas('atlas_self_construction_agent_runs', [
            'run_key' => 'provider-start:attempt-001',
            'status' => 'adapter_invocation_prepared',
            'provider' => 'codex',
        ]);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-execution-001',
        ]);
    }

    public function test_driver_is_idempotent_for_same_codex_execution_id(): void
    {
        $this->createActiveSandboxBinding();
        $this->createGuardedRun();
        $driver = app(AgentCodexProviderExecutionDriver::class);

        $first = $driver->prepareCodexExecution($this->validInput());
        $second = $driver->prepareCodexExecution($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_driver_rejects_missing_execution_guard(): void
    {
        $this->createActiveSandboxBinding();
        $this->createGuardedRun([
            'metadata' => array_merge($this->runMetadata(), [
                'provider_adapter_execution_guard' => null,
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('provider_adapter_execution_guard_missing_or_mismatch');

        app(AgentCodexProviderExecutionDriver::class)
            ->prepareCodexExecution($this->validInput());
    }

    public function test_driver_rejects_non_codex_provider(): void
    {
        $this->createActiveSandboxBinding();
        $this->createGuardedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_provider_adapter_required');

        app(AgentCodexProviderExecutionDriver::class)
            ->prepareCodexExecution(array_merge($this->validInput(), [
                'provider' => 'claude',
                'adapter' => 'claude',
            ]));
    }

    public function test_driver_rejects_arbitrary_shell_command(): void
    {
        $this->createActiveSandboxBinding();
        $this->createGuardedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('arbitrary_shell_not_allowed');

        app(AgentCodexProviderExecutionDriver::class)
            ->prepareCodexExecution(array_merge($this->validInput(), [
                'command' => 'codex --continue && rm -rf /tmp/nope',
            ]));
    }

    public function test_driver_rejects_sandbox_cwd_mismatch(): void
    {
        $this->createActiveSandboxBinding([
            'worktree_path' => '/Users/vitorepf/develop/Atlas/worktrees/other',
        ]);
        $this->createGuardedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sandbox_binding_cwd_mismatch');

        app(AgentCodexProviderExecutionDriver::class)
            ->prepareCodexExecution($this->validInput());
    }

    public function test_driver_rolls_back_metadata_when_ledger_write_fails(): void
    {
        $this->createActiveSandboxBinding();
        $this->createGuardedRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexProviderExecutionDriver::class)
                ->prepareCodexExecution($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($run->metadata, 'codex_provider_execution'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createGuardedRun(array $overrides = []): void
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
            'summary' => 'Provider adapter execution guard recorded; external provider execution remains blocked.',
            'metadata' => $this->runMetadata(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createActiveSandboxBinding(array $overrides = []): void
    {
        AtlasSelfConstructionAgentSandboxBinding::query()->create(array_merge([
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
        ], $overrides));
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return [
            'run_key' => 'provider-start:attempt-001',
            'codex_execution_id' => 'codex-execution-001',
            'execution_guard_id' => 'execution-guard-001',
            'adapter_invocation_id' => 'adapter-invocation-001',
            'provider' => 'codex',
            'adapter' => 'codex',
            'command' => 'codex --continue',
            'cwd' => '/Users/vitorepf/develop/Atlas/worktrees/codex-a',
            'context_pack_hash' => str_repeat('1', 64),
            'continuation_summary_hash' => str_repeat('2', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'max_runtime_minutes' => 240,
            'max_cost_usd' => 25.0,
            'reason' => 'prepare_codex_specific_execution_contract_without_starting_provider',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runMetadata(): array
    {
        return [
            'provider_start_attempt_id' => 'attempt-001',
            'sandbox_binding_key' => 'BINDING-001',
            'adapter' => 'codex',
            'command' => 'codex --continue',
            'cwd' => '/Users/vitorepf/develop/Atlas/worktrees/codex-a',
            'provider_started' => false,
            'adapter_invocation_allowed' => false,
            'adapter_invocation' => $this->adapterInvocationMetadata(),
            'provider_adapter_execution_guard' => $this->executionGuardMetadata(),
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
            'provider_start_attempt_id' => 'attempt-001',
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
    private function executionGuardMetadata(): array
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
