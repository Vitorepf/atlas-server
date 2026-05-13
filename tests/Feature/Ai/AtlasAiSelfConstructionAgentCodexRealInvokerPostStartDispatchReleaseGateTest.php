<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexRealInvokerPostStartDispatchReleaseGate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerPostStartDispatchReleaseGateTest extends TestCase
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

    public function test_post_start_dispatch_release_gate_is_prepared_without_dispatching_codex(): void
    {
        $this->createPostStartLivenessRun();

        $result = app(AgentCodexRealInvokerPostStartDispatchReleaseGate::class)
            ->preparePostStartDispatchRelease($this->validInput());

        $this->assertSame('codex_real_invoker_post_start_dispatch_release_gate_ready', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['dispatch_release_gate_ready']);
        $this->assertTrue($result['future_dispatch_release_candidate']);
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
        $this->assertSame('alive', $run->liveness);
        $this->assertSame(
            'post_start_dispatch_release_gate_ready_pending_signed_dispatch_authorization',
            data_get($run->metadata, 'codex_real_invoker_post_start_dispatch_release_gate.status')
        );
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-post-start-dispatch-release-gate-001',
        ]);
    }

    public function test_post_start_dispatch_release_gate_is_idempotent_for_same_gate_id(): void
    {
        $this->createPostStartLivenessRun();
        $gate = app(AgentCodexRealInvokerPostStartDispatchReleaseGate::class);

        $first = $gate->preparePostStartDispatchRelease($this->validInput());
        $second = $gate->preparePostStartDispatchRelease($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_post_start_dispatch_release_gate_rejects_duplicate_gate_for_different_id(): void
    {
        $this->createPostStartLivenessRun();
        $gate = app(AgentCodexRealInvokerPostStartDispatchReleaseGate::class);
        $gate->preparePostStartDispatchRelease($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_dispatch_release_gate_already_ready');

        $gate->preparePostStartDispatchRelease(array_merge($this->validInput(), [
            'dispatch_release_gate_id' => 'codex-real-invoker-post-start-dispatch-release-gate-002',
        ]));
    }

    public function test_post_start_dispatch_release_gate_rejects_missing_liveness_metadata(): void
    {
        $this->createPostStartLivenessRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_liveness_monitor_missing_or_mismatch');

        app(AgentCodexRealInvokerPostStartDispatchReleaseGate::class)
            ->preparePostStartDispatchRelease($this->validInput());
    }

    public function test_post_start_dispatch_release_gate_rejects_liveness_that_is_not_alive(): void
    {
        $this->createPostStartLivenessRun([
            'liveness' => 'stale',
            'metadata' => $this->metadataWithPostStartLivenessMonitor(['observed_liveness_state' => 'stale']),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_liveness_not_alive');

        app(AgentCodexRealInvokerPostStartDispatchReleaseGate::class)
            ->preparePostStartDispatchRelease($this->validInput());
    }

    public function test_post_start_dispatch_release_gate_rejects_missing_context_pack_hash(): void
    {
        $this->createPostStartLivenessRun();

        $input = $this->validInput();
        unset($input['context_pack_hash']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_context_pack_hash');

        app(AgentCodexRealInvokerPostStartDispatchReleaseGate::class)
            ->preparePostStartDispatchRelease($input);
    }

    public function test_post_start_dispatch_release_gate_rejects_liveness_monitor_with_dispatch_allowed(): void
    {
        $this->createPostStartLivenessRun([
            'metadata' => $this->metadataWithPostStartLivenessMonitor(['dispatch_allowed' => true]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispatch_allowed_already_true');

        app(AgentCodexRealInvokerPostStartDispatchReleaseGate::class)
            ->preparePostStartDispatchRelease($this->validInput());
    }

    public function test_post_start_dispatch_release_gate_rolls_back_metadata_when_ledger_write_fails(): void
    {
        $this->createPostStartLivenessRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerPostStartDispatchReleaseGate::class)
                ->preparePostStartDispatchRelease($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($run->metadata, 'codex_real_invoker_post_start_dispatch_release_gate'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createPostStartLivenessRun(array $overrides = []): void
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
            'summary' => 'Codex real invoker post-start liveness recorded from external observation; dispatch remains disabled.',
            'metadata' => $this->metadataWithPostStartLivenessMonitor(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $monitorOverrides
     * @return array<string,mixed>
     */
    private function metadataWithPostStartLivenessMonitor(array $monitorOverrides = []): array
    {
        return [
            'codex_real_invoker_post_start_liveness_monitor' => array_merge([
                'codex_execution_id' => 'codex-execution-001',
                'manual_start_executor_receipt_id' => 'codex-real-invoker-manual-start-receipt-001',
                'operator_start_handoff_id' => 'codex-real-invoker-operator-start-handoff-001',
                'post_start_receipt_contract_id' => 'codex-real-invoker-post-start-receipt-contract-001',
                'post_start_evidence_receipt_id' => 'codex-real-invoker-post-start-evidence-receipt-001',
                'post_start_liveness_monitor_id' => 'codex-real-invoker-post-start-liveness-001',
                'observed_liveness_state' => 'alive',
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_liveness_recorded_pending_dispatch_release',
                'post_start_liveness_recorded' => true,
                'liveness_source' => 'external_operator_observation',
                'atlas_process_spawned' => false,
                'actual_process_start_allowed' => false,
                'external_process_started' => true,
                'token_spend_allowed' => false,
                'provider_started' => true,
                'provider_process_call_allowed' => false,
                'dispatch_allowed' => false,
            ], $monitorOverrides),
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
            'post_start_liveness_monitor_id' => 'codex-real-invoker-post-start-liveness-001',
            'dispatch_release_gate_id' => 'codex-real-invoker-post-start-dispatch-release-gate-001',
            'dispatch_scope_hash' => str_repeat('1', 64),
            'continuation_summary_hash' => str_repeat('2', 64),
            'context_pack_hash' => str_repeat('3', 64),
            'signed_dispatch_policy_hash' => str_repeat('4', 64),
            'no_direct_provider_call_attestation_hash' => str_repeat('5', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'prepare_future_dispatch_release_gate_without_dispatching_codex',
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
