<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Concerns\CreatesSelfConstructionControlPlaneTables;
use Tests\Concerns\MakesSelfConstructionAgentRuns;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerPostStartRealInvokerReleasePreflightGateTest extends TestCase
{
    use CreatesSelfConstructionControlPlaneTables;
    use MakesSelfConstructionAgentRuns;

    public function test_post_start_real_invoker_release_preflight_records_without_invoking_codex(): void
    {
        $this->createObservedRun();
        $this->createProviderStartRun();

        $result = app(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class)
            ->recordPostStartRealInvokerReleasePreflight($this->validInput());

        $this->assertSame('codex_real_invoker_post_start_real_invoker_release_preflight_recorded', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertSame(
            'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            $result['post_start_evidence_acceptance_bridge_id']
        );
        $this->assertTrue($result['post_start_real_invoker_release_preflight_recorded']);
        $this->assertTrue($result['real_invoker_release_preflight_passed']);
        $this->assertTrue($result['signed_release_gate_required']);
        $this->assertTrue($result['observed_external_process_started']);
        $this->assertTrue($result['observed_provider_started']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_process_call_allowed']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['adapter_invocation_allowed']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);

        $observed = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'observed:codex-real-invoker-001')
            ->firstOrFail();

        $provider = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:attempt-001')
            ->firstOrFail();

        $this->assertSame('post_start_real_invoker_release_preflight_passed_pending_signed_release_gate', data_get($observed->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.status'));
        $this->assertSame(
            'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            data_get($observed->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.post_start_evidence_acceptance_bridge_id')
        );
        $this->assertSame('real_invoker_release_preflight_passed_pending_signed_release', data_get($provider->metadata, 'codex_real_invoker_release_preflight.status'));
        $this->assertFalse((bool) data_get($provider->metadata, 'codex_real_invoker_release_preflight.external_process_started'));
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-preflight-001',
        ]);
    }

    public function test_post_start_real_invoker_release_preflight_is_idempotent_for_same_preflight_id(): void
    {
        $this->createObservedRun();
        $this->createProviderStartRun();
        $gate = app(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class);

        $first = $gate->recordPostStartRealInvokerReleasePreflight($this->validInput());
        $second = $gate->recordPostStartRealInvokerReleasePreflight($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_post_start_real_invoker_release_preflight_rejects_duplicate_preflight_id(): void
    {
        $this->createObservedRun();
        $this->createProviderStartRun();
        $gate = app(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class);
        $gate->recordPostStartRealInvokerReleasePreflight($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_real_invoker_release_preflight_already_recorded');

        $gate->recordPostStartRealInvokerReleasePreflight(array_merge($this->validInput(), [
            'real_invoker_release_preflight_id' => 'codex-real-invoker-preflight-002',
        ]));
    }

    public function test_post_start_real_invoker_release_preflight_rejects_missing_dry_run_bridge(): void
    {
        $this->createObservedRun(['metadata' => []]);
        $this->createProviderStartRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('post_start_external_process_invoker_dry_run_gate_id_mismatch');

        app(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class)
            ->recordPostStartRealInvokerReleasePreflight($this->validInput());
    }

    public function test_post_start_real_invoker_release_preflight_rejects_dry_run_without_evidence_acceptance_bridge(): void
    {
        $metadata = $this->observedMetadata();
        $metadata['codex_real_invoker_post_start_external_process_invoker_dry_run']['post_start_evidence_acceptance_bridge_id'] = null;

        $this->createObservedRun(['metadata' => $metadata]);
        $this->createProviderStartRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('post_start_evidence_acceptance_bridge_id_mismatch');

        app(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class)
            ->recordPostStartRealInvokerReleasePreflight($this->validInput());
    }

    public function test_post_start_real_invoker_release_preflight_rejects_bridge_with_dispatch_allowed(): void
    {
        $metadata = $this->observedMetadata();
        $metadata['codex_real_invoker_post_start_external_process_invoker_dry_run']['dispatch_allowed'] = true;

        $this->createObservedRun(['metadata' => $metadata]);
        $this->createProviderStartRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispatch_allowed_already_true');

        app(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class)
            ->recordPostStartRealInvokerReleasePreflight($this->validInput());
    }

    public function test_post_start_real_invoker_release_preflight_rejects_missing_provider_start_run(): void
    {
        $this->createObservedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('agent_run_not_found');

        app(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class)
            ->recordPostStartRealInvokerReleasePreflight($this->validInput());
    }

    public function test_post_start_real_invoker_release_preflight_rolls_back_when_ledger_write_fails(): void
    {
        $this->createObservedRun();
        $this->createProviderStartRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class)
                ->recordPostStartRealInvokerReleasePreflight($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $observed = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'observed:codex-real-invoker-001')
                ->firstOrFail();
            $provider = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($observed->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight'));
            $this->assertNull(data_get($provider->metadata, 'codex_real_invoker_release_preflight'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createObservedRun(array $overrides = []): void
    {
        $this->makeAgentRun(array_merge([
            'run_key' => 'observed:codex-real-invoker-001',
            'status' => 'adapter_invocation_prepared',
            'summary' => 'Codex real invoker post-start external process invoker dry-run prepared.',
            'metadata' => $this->observedMetadata(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createProviderStartRun(array $overrides = []): void
    {
        $this->makeAgentRun(array_merge([
            'run_key' => 'provider-start:attempt-001',
            'status' => 'adapter_invocation_prepared',
            'summary' => 'Codex external process invoker dry-run prepared; real invoker remains disabled.',
            'metadata' => $this->providerMetadata(),
        ], $overrides));
    }

    /**
     * @return array<string,mixed>
     */
    private function observedMetadata(): array
    {
        return [
            'codex_real_invoker_post_start_external_process_invoker_dry_run' => [
                'post_start_external_process_invoker_dry_run_gate_id' => 'codex-post-start-external-process-invoker-dry-run-gate-001',
                'post_start_process_invocation_authorization_gate_id' => 'codex-post-start-process-invocation-authorization-gate-001',
                'post_start_external_process_runtime_gate_id' => 'codex-post-start-external-process-runtime-gate-001',
                'post_start_final_process_spawn_executor_gate_id' => 'codex-post-start-final-process-spawn-executor-gate-001',
                'post_start_process_spawn_enablement_gate_id' => 'codex-post-start-process-spawn-enable-gate-001',
                'post_start_supervised_start_gate_id' => 'codex-post-start-supervised-start-gate-001',
                'post_start_process_start_release_gate_id' => 'codex-post-start-process-start-release-gate-001',
                'process_start_release_id' => 'codex-start-release-001',
                'provider_execution_contract_gate_id' => 'codex-post-start-provider-execution-contract-gate-001',
                'codex_execution_id' => 'codex-execution-001',
                'supervised_start_id' => 'codex-supervised-start-001',
                'spawn_enablement_id' => 'codex-spawn-enable-001',
                'spawn_executor_id' => 'codex-spawn-executor-001',
                'runtime_driver_id' => 'codex-runtime-driver-001',
                'invocation_authorization_id' => 'codex-invocation-auth-001',
                'dry_run_id' => 'codex-invoker-dry-run-001',
                'adapter_execution_guard_gate_id' => 'codex-post-start-adapter-execution-guard-gate-001',
                'execution_guard_id' => 'adapter-execution-guard-001',
                'adapter_invocation_boundary_gate_id' => 'codex-post-start-adapter-boundary-gate-001',
                'adapter_invocation_id' => 'adapter-invocation-001',
                'provider_start_driver_gate_id' => 'codex-post-start-provider-start-driver-gate-001',
                'provider_start_attempt_id' => 'attempt-001',
                'provider_start_run_key' => 'provider-start:attempt-001',
                'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
                'signed_dispatch_receipt_hash' => str_repeat('f', 64),
                'operator_release_receipt_hash' => str_repeat('a', 64),
                'operator_spawn_receipt_hash' => str_repeat('e', 64),
                'operator_final_spawn_receipt_hash' => str_repeat('1', 64),
                'operator_runtime_receipt_hash' => str_repeat('5', 64),
                'operator_invocation_receipt_hash' => str_repeat('9', 64),
                'operator_dry_run_receipt_hash' => str_repeat('b', 64),
                'codex_execution_contract_hash' => str_repeat('b', 64),
                'supervised_start_contract_hash' => str_repeat('c', 64),
                'runtime_supervision_plan_hash' => str_repeat('2', 64),
                'stdout_stderr_sink_hash' => str_repeat('3', 64),
                'liveness_probe_hash' => str_repeat('4', 64),
                'runtime_driver_contract_hash' => str_repeat('a', 64),
                'invoker_contract_hash' => str_repeat('c', 64),
                'process_command_hash' => str_repeat('6', 64),
                'environment_contract_hash' => str_repeat('7', 64),
                'termination_policy_hash' => str_repeat('8', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_external_process_invoker_dry_run_prepared_pending_real_invoker_execution_gate',
                'post_start_external_process_invoker_dry_run_recorded' => true,
                'external_process_invoker_dry_run_prepared' => true,
                'real_invoker_execution_gate_required' => true,
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
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function providerMetadata(): array
    {
        return [
            'codex_external_process_invoker_dry_run' => [
                'dry_run_id' => 'codex-invoker-dry-run-001',
                'invocation_authorization_id' => 'codex-invocation-auth-001',
                'runtime_driver_id' => 'codex-runtime-driver-001',
                'spawn_executor_id' => 'codex-spawn-executor-001',
                'spawn_enablement_id' => 'codex-spawn-enable-001',
                'supervised_start_id' => 'codex-supervised-start-001',
                'process_start_release_id' => 'codex-start-release-001',
                'codex_execution_id' => 'codex-execution-001',
                'operator_dry_run_receipt_hash' => str_repeat('b', 64),
                'invoker_contract_hash' => str_repeat('c', 64),
                'process_command_hash' => str_repeat('6', 64),
                'environment_contract_hash' => str_repeat('7', 64),
                'termination_policy_hash' => str_repeat('8', 64),
                'stdout_stderr_sink_hash' => str_repeat('3', 64),
                'liveness_probe_hash' => str_repeat('4', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'dry_run_ready_pending_real_invoker_release',
                'external_process_invoker_dry_run_prepared' => true,
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
        return [
            'run_key' => 'observed:codex-real-invoker-001',
            'post_start_real_invoker_release_preflight_gate_id' => 'codex-post-start-real-invoker-release-preflight-gate-001',
            'post_start_external_process_invoker_dry_run_gate_id' => 'codex-post-start-external-process-invoker-dry-run-gate-001',
            'post_start_process_invocation_authorization_gate_id' => 'codex-post-start-process-invocation-authorization-gate-001',
            'post_start_external_process_runtime_gate_id' => 'codex-post-start-external-process-runtime-gate-001',
            'post_start_final_process_spawn_executor_gate_id' => 'codex-post-start-final-process-spawn-executor-gate-001',
            'post_start_process_spawn_enablement_gate_id' => 'codex-post-start-process-spawn-enable-gate-001',
            'post_start_supervised_start_gate_id' => 'codex-post-start-supervised-start-gate-001',
            'post_start_process_start_release_gate_id' => 'codex-post-start-process-start-release-gate-001',
            'process_start_release_id' => 'codex-start-release-001',
            'provider_execution_contract_gate_id' => 'codex-post-start-provider-execution-contract-gate-001',
            'codex_execution_id' => 'codex-execution-001',
            'supervised_start_id' => 'codex-supervised-start-001',
            'spawn_enablement_id' => 'codex-spawn-enable-001',
            'spawn_executor_id' => 'codex-spawn-executor-001',
            'runtime_driver_id' => 'codex-runtime-driver-001',
            'invocation_authorization_id' => 'codex-invocation-auth-001',
            'dry_run_id' => 'codex-invoker-dry-run-001',
            'real_invoker_release_preflight_id' => 'codex-real-invoker-preflight-001',
            'adapter_execution_guard_gate_id' => 'codex-post-start-adapter-execution-guard-gate-001',
            'execution_guard_id' => 'adapter-execution-guard-001',
            'adapter_invocation_boundary_gate_id' => 'codex-post-start-adapter-boundary-gate-001',
            'adapter_invocation_id' => 'adapter-invocation-001',
            'provider_start_driver_gate_id' => 'codex-post-start-provider-start-driver-gate-001',
            'provider_start_attempt_id' => 'attempt-001',
            'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            'signed_dispatch_receipt_hash' => str_repeat('f', 64),
            'operator_release_receipt_hash' => str_repeat('a', 64),
            'operator_spawn_receipt_hash' => str_repeat('e', 64),
            'operator_final_spawn_receipt_hash' => str_repeat('1', 64),
            'operator_runtime_receipt_hash' => str_repeat('5', 64),
            'operator_invocation_receipt_hash' => str_repeat('9', 64),
            'operator_dry_run_receipt_hash' => str_repeat('b', 64),
            'operator_release_preflight_receipt_hash' => str_repeat('1', 64),
            'codex_execution_contract_hash' => str_repeat('b', 64),
            'supervised_start_contract_hash' => str_repeat('c', 64),
            'runtime_supervision_plan_hash' => str_repeat('2', 64),
            'stdout_stderr_sink_hash' => str_repeat('3', 64),
            'liveness_probe_hash' => str_repeat('4', 64),
            'runtime_driver_contract_hash' => str_repeat('a', 64),
            'invoker_contract_hash' => str_repeat('c', 64),
            'real_invoker_contract_hash' => str_repeat('2', 64),
            'process_command_hash' => str_repeat('6', 64),
            'environment_contract_hash' => str_repeat('7', 64),
            'termination_policy_hash' => str_repeat('8', 64),
            'rollback_plan_hash' => str_repeat('3', 64),
            'max_runtime_policy_hash' => str_repeat('4', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'record_post_start_real_invoker_release_preflight_without_invoking_codex',
        ];
    }


    // ── recheckRelease() ─────────────────────────────────────────────────────

    private function freshProofs(string $now): array
    {
        $proofs = [];
        foreach (AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::RECHECKED_PROOF_TYPES as $type) {
            $proofs[$type] = ['generated_at' => $now, 'ttl_minutes' => 10];
        }

        return $proofs;
    }

    public function test_all_fresh_proofs_no_blockers_is_release_ready(): void
    {
        $gate = app(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class);
        $now = '2026-06-30T12:00:00Z';

        $result = $gate->recheckRelease([
            'proofs' => $this->freshProofs($now),
            'current_blockers' => [],
            'now' => $now,
        ]);

        $this->assertTrue($result['release_ready']);
        $this->assertSame([], $result['stale_proofs']);
        $this->assertNull($result['current_blocker']);
    }

    public function test_stale_proof_blocks_release(): void
    {
        $gate = app(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class);
        $proofs = $this->freshProofs('2026-06-30T11:00:00Z');
        $proofs['launch_proof'] = ['generated_at' => '2026-06-30T11:00:00Z', 'ttl_minutes' => 10];

        $result = $gate->recheckRelease([
            'proofs' => $proofs,
            'current_blockers' => [],
            'now' => '2026-06-30T12:00:00Z',
        ]);

        $this->assertFalse($result['release_ready']);
        $this->assertContains('launch_proof', $result['stale_proofs']);
    }

    public function test_missing_proof_counts_as_stale(): void
    {
        $gate = app(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class);
        $proofs = $this->freshProofs('2026-06-30T12:00:00Z');
        unset($proofs['rollback_proof']);

        $result = $gate->recheckRelease([
            'proofs' => $proofs,
            'now' => '2026-06-30T12:00:00Z',
        ]);

        $this->assertFalse($result['release_ready']);
        $this->assertContains('rollback_proof', $result['stale_proofs']);
    }

    public function test_current_blocker_blocks_release_even_with_fresh_proofs(): void
    {
        $gate = app(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class);
        $now = '2026-06-30T12:00:00Z';

        $result = $gate->recheckRelease([
            'proofs' => $this->freshProofs($now),
            'current_blockers' => ['queue_health_degraded'],
            'now' => $now,
        ]);

        $this->assertFalse($result['release_ready']);
        $this->assertSame('queue_health_degraded', $result['current_blocker']);
        $this->assertSame([], $result['stale_proofs']);
    }

    public function test_does_not_trust_earlier_dry_run_proof_past_its_ttl(): void
    {
        // Dry-run proof generated long ago must not be trusted just because it
        // once existed — the gate must recheck CURRENT freshness, not history.
        $gate = app(AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::class);
        $proofs = $this->freshProofs('2026-06-30T12:00:00Z');
        $proofs['queue_health'] = ['generated_at' => '2026-06-29T12:00:00Z', 'ttl_minutes' => 10];

        $result = $gate->recheckRelease([
            'proofs' => $proofs,
            'now' => '2026-06-30T12:00:00Z',
        ]);

        $this->assertFalse($result['release_ready']);
        $this->assertContains('queue_health', $result['stale_proofs']);
    }
}
