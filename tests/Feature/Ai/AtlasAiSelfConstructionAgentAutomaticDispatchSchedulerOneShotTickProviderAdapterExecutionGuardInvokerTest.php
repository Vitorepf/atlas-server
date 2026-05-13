<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardInvoker;
use App\Services\Ai\SelfConstruction\AgentProviderAdapterRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardInvokerTest extends TestCase
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

    public function test_invoker_blocks_provider_adapter_execution_without_calling_adapter(): void
    {
        $this->createAdapterPreparedRun();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardInvoker::class)
            ->blockProviderAdapterExecution($this->validInput());

        $this->assertSame('one_shot_scheduler_provider_adapter_execution_guard_blocked', $result['status']);
        $this->assertTrue($result['provider_adapter_execution_guard_invoked']);
        $this->assertSame(1, $result['provider_adapter_execution_guard_invocation_count']);
        $this->assertSame('provider_specific_execution_contract_missing', $result['blocked_by']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertSame('activate_signed_one_shot_scheduler_tick_provider_specific_execution_contract_release', $result['next_required_slice']);

        $this->assertDatabaseHas('atlas_self_construction_agent_runs', [
            'run_key' => 'provider-start:attempt-001',
            'status' => 'adapter_invocation_prepared',
            'provider' => 'codex',
        ]);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'execution-guard-001',
        ]);
    }

    public function test_invoker_is_idempotent_for_same_execution_guard(): void
    {
        $this->createAdapterPreparedRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardInvoker::class);

        $first = $invoker->blockProviderAdapterExecution($this->validInput());
        $second = $invoker->blockProviderAdapterExecution($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertFalse($second['provider_started']);
        $this->assertDatabaseCount('atlas_self_construction_agent_runs', 1);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_invoker_rejects_missing_execution_guard_id_without_updating_run(): void
    {
        $this->createAdapterPreparedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_execution_guard_id');

        app(AgentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardInvoker::class)
            ->blockProviderAdapterExecution(array_merge($this->validInput(), [
                'execution_guard_id' => '',
            ]));
    }

    public function test_invoker_rejects_run_that_is_not_adapter_invocation_prepared(): void
    {
        $this->createAdapterPreparedRun(['status' => 'pre_start_guarded']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('agent_run_not_adapter_invocation_prepared');

        app(AgentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardInvoker::class)
            ->blockProviderAdapterExecution($this->validInput());
    }

    public function test_invoker_rejects_adapter_invocation_mismatch(): void
    {
        $this->createAdapterPreparedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('adapter_invocation_id_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardInvoker::class)
            ->blockProviderAdapterExecution(array_merge($this->validInput(), [
                'adapter_invocation_id' => 'adapter-invocation-other',
            ]));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createAdapterPreparedRun(array $overrides = []): void
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
            'summary' => 'Adapter invocation boundary prepared; provider adapter execution guard not recorded yet.',
            'metadata' => $this->runMetadata(),
        ], $overrides));
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return [
            'run_key' => 'provider-start:attempt-001',
            'execution_guard_id' => 'execution-guard-001',
            'adapter_invocation_id' => 'adapter-invocation-001',
            'provider' => 'codex',
            'adapter' => 'codex',
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'one_shot_scheduler_block_provider_adapter_execution_until_provider_specific_contract',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runMetadata(): array
    {
        return [
            'provider_start_attempt_id' => 'attempt-001',
            'adapter' => 'codex',
            'command' => 'codex --continue',
            'cwd' => '/Users/vitorepf/develop/Atlas/worktrees/codex-a',
            'provider_started' => false,
            'adapter_invocation_allowed' => false,
            'adapter_invocation' => $this->adapterInvocationMetadata(),
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
