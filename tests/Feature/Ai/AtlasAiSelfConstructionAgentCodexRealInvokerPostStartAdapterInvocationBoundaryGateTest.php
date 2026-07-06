<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentHeartbeat;
use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Concerns\CreatesSelfConstructionControlPlaneTables;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerPostStartAdapterInvocationBoundaryGateTest extends TestCase
{
    use CreatesSelfConstructionControlPlaneTables;

    public function test_post_start_adapter_invocation_boundary_is_prepared_without_calling_codex(): void
    {
        $this->createPrerequisites();

        $result = app(AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class)
            ->preparePostStartAdapterInvocationBoundary($this->validInput());

        $this->assertSame('codex_real_invoker_post_start_adapter_invocation_boundary_prepared', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['observed_external_process_started']);
        $this->assertTrue($result['observed_provider_started']);
        $this->assertFalse($result['boundary_external_process_started']);
        $this->assertFalse($result['boundary_provider_started']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_process_call_allowed']);
        $this->assertFalse($result['adapter_invocation_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertSame('adapter_invocation_prepared', data_get($result, 'adapter_invocation_boundary_result.status'));
        $this->assertSame(
            'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            $result['post_start_evidence_acceptance_bridge_id']
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

    public function test_post_start_adapter_invocation_boundary_is_idempotent_for_same_boundary(): void
    {
        $this->createPrerequisites();
        $gate = app(AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class);

        $first = $gate->preparePostStartAdapterInvocationBoundary($this->validInput());
        $second = $gate->preparePostStartAdapterInvocationBoundary($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_post_start_adapter_invocation_boundary_rejects_duplicate_boundary(): void
    {
        $this->createPrerequisites();
        $gate = app(AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class);
        $gate->preparePostStartAdapterInvocationBoundary($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_adapter_invocation_boundary_already_prepared');

        $gate->preparePostStartAdapterInvocationBoundary(array_merge($this->validInput(), [
            'adapter_invocation_id' => 'adapter-invocation-002',
        ]));
    }

    public function test_post_start_adapter_invocation_boundary_rejects_missing_provider_start_driver_metadata(): void
    {
        $this->createPrerequisites([
            'observed_run' => ['metadata' => []],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_provider_start_driver_missing_or_mismatch');

        app(AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class)
            ->preparePostStartAdapterInvocationBoundary($this->validInput());
    }

    public function test_post_start_adapter_invocation_boundary_rejects_provider_start_driver_with_adapter_allowed(): void
    {
        $this->createPrerequisites([
            'observed_run' => [
                'metadata' => $this->metadataWithProviderStartDriver(['adapter_invocation_allowed' => true]),
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('adapter_invocation_allowed_already_true');

        app(AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class)
            ->preparePostStartAdapterInvocationBoundary($this->validInput());
    }

    public function test_post_start_adapter_invocation_boundary_rejects_provider_start_driver_without_evidence_acceptance_bridge(): void
    {
        $this->createPrerequisites([
            'observed_run' => [
                'metadata' => $this->metadataWithProviderStartDriver([
                    'post_start_evidence_acceptance_bridge_id' => null,
                ]),
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('post_start_evidence_acceptance_bridge_id_mismatch');

        app(AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class)
            ->preparePostStartAdapterInvocationBoundary($this->validInput());
    }

    public function test_post_start_adapter_invocation_boundary_rejects_missing_pre_start_heartbeat(): void
    {
        $this->createPrerequisites(skipProviderStartHeartbeat: true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pre_start_heartbeat_missing');

        app(AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class)
            ->preparePostStartAdapterInvocationBoundary($this->validInput());
    }

    public function test_post_start_adapter_invocation_boundary_rolls_back_observed_run_when_boundary_fails(): void
    {
        $this->createPrerequisites();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class)
                ->preparePostStartAdapterInvocationBoundary($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $observedRun = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'codex-post-start-observed-run-001')
                ->firstOrFail();

            $providerRun = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:codex-post-start-attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($observedRun->metadata, 'codex_real_invoker_post_start_adapter_invocation_boundary'));
            $this->assertSame('pre_start_guarded', $providerRun->status);
        }
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


    // ── enforceContextBoundary() ─────────────────────────────────────────────

    public function test_clean_context_with_only_allowed_fields_is_boundary_clean(): void
    {
        $gate = app(AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class);
        $result = $gate->enforceContextBoundary([
            'task_id' => 't-1',
            'lease_id' => 'l-1',
            'allowed_files' => ['app/Foo.php'],
            'acceptance_criteria' => ['green tests'],
            'required_evidence' => ['tests_or_gates_result'],
        ]);

        $this->assertTrue($result['boundary_clean']);
        $this->assertSame([], $result['redacted_fields']);
        $this->assertNull($result['blocked_reason']);
    }

    public function test_redacts_unrelated_queue_state(): void
    {
        $gate = app(AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class);
        $result = $gate->enforceContextBoundary([
            'task_id' => 't-1',
            'lease_id' => 'l-1',
            'unrelated_queue_state' => ['other-task' => 'queued'],
        ]);

        $this->assertFalse($result['boundary_clean']);
        $this->assertContains('unrelated_queue_state', $result['redacted_fields']);
        $this->assertArrayNotHasKey('unrelated_queue_state', $result['filtered_context']);
    }

    public function test_redacts_internal_prompts(): void
    {
        $gate = app(AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class);
        $result = $gate->enforceContextBoundary([
            'task_id' => 't-1',
            'lease_id' => 'l-1',
            'internal_prompt' => 'system prompt text',
        ]);

        $this->assertContains('internal_prompt', $result['redacted_fields']);
        $this->assertArrayNotHasKey('internal_prompt', $result['filtered_context']);
    }

    public function test_blocked_when_task_id_or_lease_id_missing(): void
    {
        $gate = app(AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class);
        $result = $gate->enforceContextBoundary(['allowed_files' => []]);

        $this->assertFalse($result['boundary_clean']);
        $this->assertNotNull($result['blocked_reason']);
        $this->assertSame([], $result['filtered_context']);
    }

    public function test_filtered_context_preserves_allowed_field_values(): void
    {
        $gate = app(AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class);
        $result = $gate->enforceContextBoundary([
            'task_id' => 't-1',
            'lease_id' => 'l-1',
            'allowed_files' => ['app/Foo.php', 'app/Bar.php'],
            'other_workspace_state' => 'leak',
        ]);

        $this->assertSame(['app/Foo.php', 'app/Bar.php'], $result['filtered_context']['allowed_files']);
        $this->assertArrayNotHasKey('other_workspace_state', $result['filtered_context']);
    }
}
