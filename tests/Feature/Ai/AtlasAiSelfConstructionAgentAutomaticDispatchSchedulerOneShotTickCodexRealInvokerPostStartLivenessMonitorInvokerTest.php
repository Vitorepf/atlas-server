<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvokerTest extends TestCase
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

    public function test_one_shot_scheduler_records_post_start_liveness_without_calling_codex(): void
    {
        $this->createPostStartEvidenceAcceptedRun();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker::class)
            ->recordCodexRealInvokerPostStartLiveness($this->validInput());

        $this->assertSame('one_shot_scheduler_codex_real_invoker_post_start_liveness_recorded', $result['status']);
        $this->assertTrue($result['codex_real_invoker_post_start_liveness_monitor_invoked']);
        $this->assertSame('codex-real-invoker-post-start-liveness-001', $result['post_start_liveness_monitor_id']);
        $this->assertSame('alive', $result['observed_liveness_state']);
        $this->assertTrue($result['post_start_liveness_recorded']);
        $this->assertFalse($result['atlas_process_spawned']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertTrue($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertSame('activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_release_gate_contract', $result['next_required_slice']);

        $run = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:attempt-001')
            ->firstOrFail();

        $this->assertSame('alive', $run->liveness);
        $this->assertSame(
            'post_start_liveness_recorded_pending_dispatch_release',
            data_get($run->metadata, 'codex_real_invoker_post_start_liveness_monitor.status')
        );
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-post-start-liveness-001',
        ]);
    }

    public function test_one_shot_scheduler_post_start_liveness_is_idempotent_for_same_monitor_id(): void
    {
        $this->createPostStartEvidenceAcceptedRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker::class);

        $first = $invoker->recordCodexRealInvokerPostStartLiveness($this->validInput());
        $second = $invoker->recordCodexRealInvokerPostStartLiveness($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_one_shot_scheduler_rejects_invalid_liveness_state(): void
    {
        $this->createPostStartEvidenceAcceptedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_observed_liveness_state');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker::class)
            ->recordCodexRealInvokerPostStartLiveness(array_merge($this->validInput(), [
                'observed_liveness_state' => 'running',
            ]));
    }

    public function test_one_shot_scheduler_rejects_missing_evidence_acceptance_bridge_metadata(): void
    {
        $this->createPostStartEvidenceAcceptedRun([
            'metadata' => $this->metadataWithPostStartEvidenceAccepted(includeBridge: false),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_evidence_acceptance_bridge_missing_or_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker::class)
            ->recordCodexRealInvokerPostStartLiveness($this->validInput());
    }

    public function test_one_shot_scheduler_rejects_invalid_no_provider_call_attestation_hash(): void
    {
        $this->createPostStartEvidenceAcceptedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_no_provider_call_attestation_hash');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker::class)
            ->recordCodexRealInvokerPostStartLiveness(array_merge($this->validInput(), [
                'no_provider_call_attestation_hash' => 'bad-hash',
            ]));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createPostStartEvidenceAcceptedRun(array $overrides = []): void
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
            'summary' => 'Codex real invoker post-start evidence accepted; liveness remains separate.',
            'metadata' => $this->metadataWithPostStartEvidenceAccepted(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $receiptOverrides
     * @param  array<string,mixed>  $bridgeOverrides
     * @return array<string,mixed>
     */
    private function metadataWithPostStartEvidenceAccepted(array $receiptOverrides = [], array $bridgeOverrides = [], bool $includeBridge = true): array
    {
        $metadata = [
            'codex_real_invoker_post_start_evidence_receipt' => array_merge([
                'codex_execution_id' => 'codex-execution-001',
                'manual_start_executor_receipt_id' => 'codex-real-invoker-manual-start-receipt-001',
                'operator_start_handoff_id' => 'codex-real-invoker-operator-start-handoff-001',
                'post_start_receipt_contract_id' => 'codex-real-invoker-post-start-receipt-contract-001',
                'post_start_evidence_receipt_id' => 'codex-real-invoker-post-start-evidence-receipt-001',
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_evidence_receipt_recorded_pending_liveness_monitoring',
                'post_start_evidence_receipt_recorded' => true,
                'operator_external_start_attested' => true,
                'atlas_process_spawned' => false,
                'actual_process_start_allowed' => false,
                'external_process_started' => true,
                'token_spend_allowed' => false,
                'provider_started' => true,
                'dispatch_allowed' => false,
            ], $receiptOverrides),
        ];

        if ($includeBridge) {
            $metadata['codex_real_invoker_post_start_evidence_acceptance_bridge'] = array_merge([
                'post_start_evidence_acceptance_bridge_id' => 'codex-post-start-evidence-acceptance-bridge-001',
                'codex_execution_id' => 'codex-execution-001',
                'manual_start_executor_receipt_id' => 'codex-real-invoker-manual-start-receipt-001',
                'operator_start_handoff_id' => 'codex-real-invoker-operator-start-handoff-001',
                'post_start_receipt_contract_id' => 'codex-real-invoker-post-start-receipt-contract-001',
                'post_start_evidence_receipt_id' => 'codex-real-invoker-post-start-evidence-receipt-001',
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_evidence_acceptance_recorded_pending_liveness_monitoring',
                'post_start_evidence_acceptance_recorded' => true,
                'post_start_receipt_contract_built' => true,
                'post_start_evidence_receipt_recorded' => true,
                'operator_external_start_attested' => true,
                'atlas_process_spawned' => false,
                'actual_process_start_allowed' => false,
                'external_process_started' => true,
                'token_spend_allowed' => false,
                'provider_started' => true,
                'dispatch_allowed' => false,
            ], $bridgeOverrides);
        }

        return $metadata;
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
            'post_start_evidence_acceptance_bridge_id' => 'codex-post-start-evidence-acceptance-bridge-001',
            'post_start_evidence_receipt_id' => 'codex-real-invoker-post-start-evidence-receipt-001',
            'post_start_liveness_monitor_id' => 'codex-real-invoker-post-start-liveness-001',
            'observed_liveness_state' => 'alive',
            'liveness_observation_hash' => str_repeat('1', 64),
            'heartbeat_observation_hash' => str_repeat('2', 64),
            'progress_observation_hash' => str_repeat('3', 64),
            'operator_visibility_attestation_hash' => str_repeat('4', 64),
            'no_provider_call_attestation_hash' => str_repeat('5', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'record_external_liveness_observation_without_calling_codex',
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
