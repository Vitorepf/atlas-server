<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentHeartbeat;
use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryInvoker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryInvokerTest extends TestCase
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

    public function test_invoker_prepares_adapter_invocation_boundary_without_calling_adapter(): void
    {
        $this->createPreStartRun();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryInvoker::class)
            ->prepareAdapterInvocationBoundary($this->validInput());

        $this->assertSame('one_shot_scheduler_adapter_invocation_boundary_prepared', $result['status']);
        $this->assertTrue($result['adapter_invocation_boundary_invoked']);
        $this->assertSame(1, $result['adapter_invocation_boundary_invocation_count']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertSame('activate_signed_one_shot_scheduler_tick_provider_adapter_execution_guard_release_contract', $result['next_required_slice']);

        $this->assertDatabaseHas('atlas_self_construction_agent_runs', [
            'run_key' => 'provider-start:attempt-001',
            'status' => 'adapter_invocation_prepared',
            'provider' => 'codex',
        ]);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'adapter-invocation-001',
        ]);
    }

    public function test_invoker_is_idempotent_for_same_adapter_invocation(): void
    {
        $this->createPreStartRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryInvoker::class);

        $first = $invoker->prepareAdapterInvocationBoundary($this->validInput());
        $second = $invoker->prepareAdapterInvocationBoundary($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertFalse($second['provider_started']);
        $this->assertDatabaseCount('atlas_self_construction_agent_runs', 1);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_invoker_rejects_invalid_context_pack_hash_without_updating_run(): void
    {
        $this->createPreStartRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_context_pack_hash');

        app(AgentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryInvoker::class)
            ->prepareAdapterInvocationBoundary(array_merge($this->validInput(), [
                'context_pack_hash' => 'not-a-hash',
            ]));
    }

    public function test_invoker_rejects_run_that_is_not_pre_start_guarded(): void
    {
        $this->createPreStartRun(['status' => 'running']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('agent_run_not_pre_start_guarded');

        app(AgentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryInvoker::class)
            ->prepareAdapterInvocationBoundary($this->validInput());
    }

    public function test_invoker_rejects_missing_pre_start_heartbeat(): void
    {
        $this->createPreStartRun(skipHeartbeat: true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pre_start_heartbeat_missing');

        app(AgentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryInvoker::class)
            ->prepareAdapterInvocationBoundary($this->validInput());
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createPreStartRun(array $overrides = [], bool $skipHeartbeat = false): void
    {
        $run = AtlasSelfConstructionAgentRun::query()->create(array_merge([
            'run_key' => 'provider-start:attempt-001',
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
                'provider_start_attempt_id' => 'attempt-001',
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
        ], $overrides));

        if ($skipHeartbeat) {
            return;
        }

        AtlasSelfConstructionAgentHeartbeat::query()->create([
            'agent_run_id' => $run->id,
            'heartbeat_key' => 'provider-start:attempt-001:heartbeat:pre-start',
            'sequence' => 1,
            'status' => 'alive',
            'signal' => 'pre_start_guard',
            'occurred_at' => CarbonImmutable::now(),
            'metadata' => [
                'provider_start_attempt_id' => 'attempt-001',
                'provider_start_side_effect_performed' => false,
                'adapter_invocation_allowed' => false,
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return [
            'run_key' => 'provider-start:attempt-001',
            'adapter_invocation_id' => 'adapter-invocation-001',
            'provider_start_attempt_id' => 'attempt-001',
            'provider' => 'codex',
            'adapter' => 'codex',
            'command' => 'codex --continue',
            'cwd' => '/Users/vitorepf/develop/Atlas/worktrees/codex-a',
            'context_pack_hash' => str_repeat('1', 64),
            'continuation_summary_hash' => str_repeat('2', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'max_runtime_minutes' => 30,
            'max_cost_usd' => 5,
            'reason' => 'one_shot_scheduler_prepare_adapter_boundary_without_external_process',
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
