<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerPostStartSignedDispatchAuthorizationGateTest extends TestCase
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

    public function test_post_start_signed_dispatch_authorization_is_recorded_without_dispatching_codex(): void
    {
        $this->createDispatchReleaseGateRun();

        $result = app(AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate::class)
            ->authorizePostStartSignedDispatch($this->validInput());

        $this->assertSame('codex_real_invoker_post_start_signed_dispatch_authorization_recorded', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['signed_dispatch_authorization_recorded']);
        $this->assertTrue($result['future_dispatch_authorized']);
        $this->assertSame('codex-real-invoker-post-start-evidence-acceptance-bridge-001', $result['post_start_evidence_acceptance_bridge_id']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertTrue($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertTrue($result['provider_started']);
        $this->assertFalse($result['provider_process_call_allowed']);
        $this->assertFalse($result['dispatch_allowed']);

        $run = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:attempt-001')
            ->firstOrFail();

        $this->assertSame('adapter_invocation_prepared', $run->status);
        $this->assertSame(
            'post_start_signed_dispatch_authorized_pending_dispatch_executor',
            data_get($run->metadata, 'codex_real_invoker_post_start_signed_dispatch_authorization.status')
        );
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-post-start-signed-dispatch-auth-001',
        ]);
    }

    public function test_post_start_signed_dispatch_authorization_is_idempotent_for_same_authorization_id(): void
    {
        $this->createDispatchReleaseGateRun();
        $gate = app(AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate::class);

        $first = $gate->authorizePostStartSignedDispatch($this->validInput());
        $second = $gate->authorizePostStartSignedDispatch($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_post_start_signed_dispatch_authorization_rejects_duplicate_authorization_for_different_id(): void
    {
        $this->createDispatchReleaseGateRun();
        $gate = app(AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate::class);
        $gate->authorizePostStartSignedDispatch($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_signed_dispatch_authorization_already_recorded');

        $gate->authorizePostStartSignedDispatch(array_merge($this->validInput(), [
            'signed_dispatch_authorization_id' => 'codex-real-invoker-post-start-signed-dispatch-auth-002',
        ]));
    }

    public function test_post_start_signed_dispatch_authorization_rejects_missing_dispatch_release_gate_metadata(): void
    {
        $this->createDispatchReleaseGateRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_dispatch_release_gate_missing_or_mismatch');

        app(AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate::class)
            ->authorizePostStartSignedDispatch($this->validInput());
    }

    public function test_post_start_signed_dispatch_authorization_rejects_release_gate_with_non_alive_liveness(): void
    {
        $this->createDispatchReleaseGateRun([
            'metadata' => $this->metadataWithDispatchReleaseGate(['observed_liveness_state' => 'stale']),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_liveness_not_alive');

        app(AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate::class)
            ->authorizePostStartSignedDispatch($this->validInput());
    }

    public function test_post_start_signed_dispatch_authorization_rejects_release_gate_without_evidence_acceptance_bridge(): void
    {
        $this->createDispatchReleaseGateRun([
            'metadata' => $this->metadataWithDispatchReleaseGate(['post_start_evidence_acceptance_bridge_id' => null]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('post_start_evidence_acceptance_bridge_id_mismatch');

        app(AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate::class)
            ->authorizePostStartSignedDispatch($this->validInput());
    }

    public function test_post_start_signed_dispatch_authorization_rejects_missing_signature_hash(): void
    {
        $this->createDispatchReleaseGateRun();

        $input = $this->validInput();
        unset($input['human_dispatch_signature_hash']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_human_dispatch_signature_hash');

        app(AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate::class)
            ->authorizePostStartSignedDispatch($input);
    }

    public function test_post_start_signed_dispatch_authorization_rejects_release_gate_with_dispatch_allowed(): void
    {
        $this->createDispatchReleaseGateRun([
            'metadata' => $this->metadataWithDispatchReleaseGate(['dispatch_allowed' => true]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispatch_allowed_already_true');

        app(AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate::class)
            ->authorizePostStartSignedDispatch($this->validInput());
    }

    public function test_post_start_signed_dispatch_authorization_rolls_back_metadata_when_ledger_write_fails(): void
    {
        $this->createDispatchReleaseGateRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate::class)
                ->authorizePostStartSignedDispatch($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($run->metadata, 'codex_real_invoker_post_start_signed_dispatch_authorization'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createDispatchReleaseGateRun(array $overrides = []): void
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
            'summary' => 'Codex real invoker post-start dispatch release gate prepared; dispatch remains disabled pending signed authorization.',
            'metadata' => $this->metadataWithDispatchReleaseGate(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $gateOverrides
     * @return array<string,mixed>
     */
    private function metadataWithDispatchReleaseGate(array $gateOverrides = []): array
    {
        return [
            'codex_real_invoker_post_start_dispatch_release_gate' => array_merge([
                'codex_execution_id' => 'codex-execution-001',
                'manual_start_executor_receipt_id' => 'codex-real-invoker-manual-start-receipt-001',
                'operator_start_handoff_id' => 'codex-real-invoker-operator-start-handoff-001',
                'post_start_receipt_contract_id' => 'codex-real-invoker-post-start-receipt-contract-001',
                'post_start_evidence_receipt_id' => 'codex-real-invoker-post-start-evidence-receipt-001',
                'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
                'post_start_liveness_monitor_id' => 'codex-real-invoker-post-start-liveness-001',
                'dispatch_release_gate_id' => 'codex-real-invoker-post-start-dispatch-release-gate-001',
                'dispatch_scope_hash' => str_repeat('1', 64),
                'continuation_summary_hash' => str_repeat('2', 64),
                'context_pack_hash' => str_repeat('3', 64),
                'signed_dispatch_policy_hash' => str_repeat('4', 64),
                'no_direct_provider_call_attestation_hash' => str_repeat('5', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_dispatch_release_gate_ready_pending_signed_dispatch_authorization',
                'dispatch_release_gate_ready' => true,
                'future_dispatch_release_candidate' => true,
                'observed_liveness_state' => 'alive',
                'atlas_process_spawned' => false,
                'actual_process_start_allowed' => false,
                'external_process_started' => true,
                'token_spend_allowed' => false,
                'provider_started' => true,
                'provider_process_call_allowed' => false,
                'dispatch_allowed' => false,
            ], $gateOverrides),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return [
            'run_key' => 'provider-start:attempt-001',
            'codex_execution_id' => 'codex-execution-001',
            'manual_start_executor_receipt_id' => 'codex-real-invoker-manual-start-receipt-001',
            'operator_start_handoff_id' => 'codex-real-invoker-operator-start-handoff-001',
            'post_start_receipt_contract_id' => 'codex-real-invoker-post-start-receipt-contract-001',
            'post_start_evidence_receipt_id' => 'codex-real-invoker-post-start-evidence-receipt-001',
            'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            'post_start_liveness_monitor_id' => 'codex-real-invoker-post-start-liveness-001',
            'dispatch_release_gate_id' => 'codex-real-invoker-post-start-dispatch-release-gate-001',
            'signed_dispatch_authorization_id' => 'codex-real-invoker-post-start-signed-dispatch-auth-001',
            'signed_dispatch_receipt_hash' => str_repeat('a', 64),
            'human_dispatch_signature_hash' => str_repeat('b', 64),
            'signed_dispatch_policy_hash' => str_repeat('4', 64),
            'dispatch_window_hash' => str_repeat('c', 64),
            'dispatch_scope_hash' => str_repeat('1', 64),
            'continuation_summary_hash' => str_repeat('2', 64),
            'context_pack_hash' => str_repeat('3', 64),
            'dispatch_replay_guard_hash' => str_repeat('d', 64),
            'dispatch_kill_switch_hash' => str_repeat('e', 64),
            'no_direct_provider_call_attestation_hash' => str_repeat('5', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'record_signed_dispatch_authorization_without_dispatching_codex',
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
