<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexRealInvokerPostStartLivenessMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerPostStartLivenessMonitorTest extends TestCase
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

    public function test_post_start_liveness_is_recorded_without_calling_codex(): void
    {
        $this->createPostStartEvidenceReceiptRun();

        $result = app(AgentCodexRealInvokerPostStartLivenessMonitor::class)
            ->recordPostStartLiveness($this->validInput());

        $this->assertSame('codex_real_invoker_post_start_liveness_recorded', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertSame('alive', $result['observed_liveness_state']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertTrue($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertTrue($result['provider_started']);
        $this->assertFalse($result['dispatch_allowed']);

        $run = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:attempt-001')
            ->firstOrFail();

        $this->assertSame('adapter_invocation_prepared', $run->status);
        $this->assertSame('alive', $run->liveness);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-post-start-liveness-001',
        ]);
    }

    public function test_post_start_liveness_is_idempotent_for_same_monitor_id(): void
    {
        $this->createPostStartEvidenceReceiptRun();
        $monitor = app(AgentCodexRealInvokerPostStartLivenessMonitor::class);

        $first = $monitor->recordPostStartLiveness($this->validInput());
        $second = $monitor->recordPostStartLiveness($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_post_start_liveness_rejects_duplicate_monitor_for_different_id(): void
    {
        $this->createPostStartEvidenceReceiptRun();
        $monitor = app(AgentCodexRealInvokerPostStartLivenessMonitor::class);
        $monitor->recordPostStartLiveness($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_liveness_monitor_already_recorded');

        $monitor->recordPostStartLiveness(array_merge($this->validInput(), [
            'post_start_liveness_monitor_id' => 'codex-real-invoker-post-start-liveness-002',
        ]));
    }

    public function test_post_start_liveness_rejects_missing_post_start_evidence_receipt_metadata(): void
    {
        $this->createPostStartEvidenceReceiptRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_evidence_receipt_missing_or_mismatch');

        app(AgentCodexRealInvokerPostStartLivenessMonitor::class)
            ->recordPostStartLiveness($this->validInput());
    }

    public function test_post_start_liveness_rejects_invalid_liveness_state(): void
    {
        $this->createPostStartEvidenceReceiptRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_observed_liveness_state');

        app(AgentCodexRealInvokerPostStartLivenessMonitor::class)
            ->recordPostStartLiveness(array_merge($this->validInput(), [
                'observed_liveness_state' => 'running',
            ]));
    }

    public function test_post_start_liveness_rejects_evidence_receipt_with_dispatch_allowed(): void
    {
        $this->createPostStartEvidenceReceiptRun([
            'metadata' => $this->metadataWithPostStartEvidenceReceipt(['dispatch_allowed' => true]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispatch_allowed_already_true');

        app(AgentCodexRealInvokerPostStartLivenessMonitor::class)
            ->recordPostStartLiveness($this->validInput());
    }

    public function test_post_start_liveness_rolls_back_metadata_when_ledger_write_fails(): void
    {
        $this->createPostStartEvidenceReceiptRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerPostStartLivenessMonitor::class)
                ->recordPostStartLiveness($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($run->metadata, 'codex_real_invoker_post_start_liveness_monitor'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createPostStartEvidenceReceiptRun(array $overrides = []): void
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
            'summary' => 'Codex real invoker post-start evidence receipt recorded; dispatch remains disabled.',
            'metadata' => $this->metadataWithPostStartEvidenceReceipt(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $receiptOverrides
     * @return array<string,mixed>
     */
    private function metadataWithPostStartEvidenceReceipt(array $receiptOverrides = []): array
    {
        return [
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
