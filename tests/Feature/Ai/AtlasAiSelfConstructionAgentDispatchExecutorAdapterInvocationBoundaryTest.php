<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentHeartbeat;
use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentDispatchExecutorAdapterInvocationBoundary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Concerns\CreatesSelfConstructionControlPlaneTables;
use Tests\Concerns\MakesSelfConstructionAgentRuns;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentDispatchExecutorAdapterInvocationBoundaryTest extends TestCase
{
    use CreatesSelfConstructionControlPlaneTables;
    use MakesSelfConstructionAgentRuns;

    public function test_boundary_prepares_adapter_invocation_without_starting_provider(): void
    {
        $this->createPreStartRun();

        $result = app(AgentDispatchExecutorAdapterInvocationBoundary::class)
            ->prepareInvocation($this->validInput());

        $this->assertSame('adapter_invocation_prepared', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertSame('ADAPTER-CODEX-SELF-CONSTRUCTION-0001', $result['adapter_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['adapter_descriptor_hash']);

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

    public function test_boundary_is_idempotent_for_same_adapter_invocation(): void
    {
        $this->createPreStartRun();
        $boundary = app(AgentDispatchExecutorAdapterInvocationBoundary::class);

        $first = $boundary->prepareInvocation($this->validInput());
        $second = $boundary->prepareInvocation($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_boundary_rejects_run_that_is_not_pre_start_guarded(): void
    {
        $this->createPreStartRun(['status' => 'running']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('agent_run_not_pre_start_guarded');

        app(AgentDispatchExecutorAdapterInvocationBoundary::class)
            ->prepareInvocation($this->validInput());
    }

    public function test_boundary_rejects_missing_pre_start_heartbeat(): void
    {
        $this->createPreStartRun(skipHeartbeat: true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pre_start_heartbeat_missing');

        app(AgentDispatchExecutorAdapterInvocationBoundary::class)
            ->prepareInvocation($this->validInput());
    }

    public function test_boundary_rejects_cwd_mismatch(): void
    {
        $this->createPreStartRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cwd_mismatch');

        app(AgentDispatchExecutorAdapterInvocationBoundary::class)
            ->prepareInvocation(array_merge($this->validInput(), [
                'cwd' => '/Users/vitorepf/develop/Atlas/other-worktree',
            ]));
    }

    public function test_boundary_rejects_unregistered_provider_adapter_pair(): void
    {
        $this->createPreStartRun([
            'provider' => 'unknown-provider',
            'metadata' => array_merge($this->runMetadata(), [
                'adapter' => 'unknown-provider',
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('provider_adapter_not_registered');

        app(AgentDispatchExecutorAdapterInvocationBoundary::class)
            ->prepareInvocation(array_merge($this->validInput(), [
                'provider' => 'unknown-provider',
                'adapter' => 'unknown-provider',
            ]));
    }

    public function test_boundary_rolls_back_run_update_when_ledger_write_fails(): void
    {
        $this->createPreStartRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentDispatchExecutorAdapterInvocationBoundary::class)
                ->prepareInvocation($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $this->assertDatabaseHas('atlas_self_construction_agent_runs', [
                'run_key' => 'provider-start:attempt-001',
                'status' => 'pre_start_guarded',
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createPreStartRun(array $overrides = [], bool $skipHeartbeat = false): void
    {
        $run = $this->makeAgentRun(array_merge([
            'run_key' => 'provider-start:attempt-001',
            'status' => 'pre_start_guarded',
            'summary' => 'Provider start preflight registered; adapter invocation remains disabled.',
            'metadata' => $this->runMetadata(),
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
            'reason' => 'prepare_adapter_boundary_without_external_process',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runMetadata(): array
    {
        return [
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
        ];
    }


    // ── buildProviderSafeInvocationContext() ────────────────────────────────

    public function test_safe_context_includes_only_canonical_fields(): void
    {
        $result = app(AgentDispatchExecutorAdapterInvocationBoundary::class)->buildProviderSafeInvocationContext([
            'task_packet_id' => 'task-1',
            'allowed_files' => ['app/Foo.php'],
            'acceptance_criteria' => ['must pass tests'],
            'required_evidence' => ['test_output'],
        ]);

        $this->assertSame('safe', $result['boundary_status']);
        $this->assertSame([
            'task_packet_id' => 'task-1',
            'allowed_files' => ['app/Foo.php'],
            'acceptance_criteria' => ['must pass tests'],
            'required_evidence' => ['test_output'],
        ], $result['invocation_context']);
        $this->assertSame(0, $result['redaction_summary']['stripped_field_count']);
        $this->assertFalse($result['dispatch_allowed']);
    }

    public function test_safe_context_strips_queue_state_internal_prompt_and_workspace_context(): void
    {
        $result = app(AgentDispatchExecutorAdapterInvocationBoundary::class)->buildProviderSafeInvocationContext([
            'task_packet_id' => 'task-1',
            'allowed_files' => ['app/Foo.php'],
            'acceptance_criteria' => ['must pass tests'],
            'required_evidence' => ['test_output'],
            'queue_lease_id' => 'lease_abc',
            'system_prompt' => 'you are an autonomous agent...',
            'workspace_cwd' => '/secret/path',
            'unrelated_field' => 'noise',
        ]);

        $this->assertSame('safe', $result['boundary_status']);
        $this->assertSame([
            'task_packet_id', 'allowed_files', 'acceptance_criteria', 'required_evidence',
        ], array_keys($result['invocation_context']));
        $this->assertSame(4, $result['redaction_summary']['stripped_field_count']);
        $this->assertContains('queue_lease_id', $result['redaction_summary']['stripped_fields_by_category']['queue_state']);
        $this->assertContains('system_prompt', $result['redaction_summary']['stripped_fields_by_category']['internal_prompt']);
        $this->assertContains('workspace_cwd', $result['redaction_summary']['stripped_fields_by_category']['workspace_context']);
        $this->assertContains('unrelated_field', $result['redaction_summary']['stripped_fields_by_category']['other']);
    }

    public function test_safe_context_flags_incomplete_when_required_field_missing(): void
    {
        $result = app(AgentDispatchExecutorAdapterInvocationBoundary::class)->buildProviderSafeInvocationContext([
            'task_packet_id' => 'task-1',
            'allowed_files' => ['app/Foo.php'],
        ]);

        $this->assertSame('incomplete_context', $result['boundary_status']);
    }
}
