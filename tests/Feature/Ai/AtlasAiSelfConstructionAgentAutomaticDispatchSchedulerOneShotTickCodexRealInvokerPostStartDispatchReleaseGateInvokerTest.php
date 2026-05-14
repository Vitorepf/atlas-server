<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvokerTest extends TestCase
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

    public function test_one_shot_scheduler_prepares_post_start_dispatch_release_without_dispatching_codex(): void
    {
        $this->createPostStartLivenessRun();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker::class)
            ->prepareCodexRealInvokerPostStartDispatchRelease($this->validInput());

        $this->assertSame('one_shot_scheduler_codex_real_invoker_post_start_dispatch_release_gate_ready', $result['status']);
        $this->assertTrue($result['codex_real_invoker_post_start_dispatch_release_gate_invoked']);
        $this->assertSame('codex-real-invoker-post-start-dispatch-release-gate-001', $result['dispatch_release_gate_id']);
        $this->assertTrue($result['dispatch_release_gate_ready']);
        $this->assertTrue($result['future_dispatch_release_candidate']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertTrue($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertTrue($result['provider_started']);
        $this->assertFalse($result['provider_process_call_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertSame('activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract', $result['next_required_slice']);

        $run = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:attempt-001')
            ->firstOrFail();

        $this->assertSame(
            'post_start_dispatch_release_gate_ready_pending_signed_dispatch_authorization',
            data_get($run->metadata, 'codex_real_invoker_post_start_dispatch_release_gate.status')
        );
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-post-start-dispatch-release-gate-001',
        ]);
    }

    public function test_one_shot_scheduler_post_start_dispatch_release_is_idempotent_for_same_gate_id(): void
    {
        $this->createPostStartLivenessRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker::class);

        $first = $invoker->prepareCodexRealInvokerPostStartDispatchRelease($this->validInput());
        $second = $invoker->prepareCodexRealInvokerPostStartDispatchRelease($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_one_shot_scheduler_rejects_liveness_that_is_not_alive(): void
    {
        $this->createPostStartLivenessRun([
            'liveness' => 'stale',
            'metadata' => $this->metadataWithPostStartLivenessMonitor(['observed_liveness_state' => 'stale']),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_liveness_not_alive');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker::class)
            ->prepareCodexRealInvokerPostStartDispatchRelease($this->validInput());
    }

    public function test_one_shot_scheduler_rejects_missing_liveness_monitor_metadata(): void
    {
        $this->createPostStartLivenessRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_liveness_monitor_missing_or_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker::class)
            ->prepareCodexRealInvokerPostStartDispatchRelease($this->validInput());
    }

    public function test_one_shot_scheduler_rejects_invalid_signed_dispatch_policy_hash(): void
    {
        $this->createPostStartLivenessRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_signed_dispatch_policy_hash');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker::class)
            ->prepareCodexRealInvokerPostStartDispatchRelease(array_merge($this->validInput(), [
                'signed_dispatch_policy_hash' => 'not-a-hash',
            ]));
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
                'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
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
            'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
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
