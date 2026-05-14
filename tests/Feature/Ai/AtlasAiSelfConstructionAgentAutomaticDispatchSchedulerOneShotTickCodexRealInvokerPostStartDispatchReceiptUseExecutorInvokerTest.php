<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentDispatchReceipt;
use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorInvoker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorInvokerTest extends TestCase
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

    public function test_one_shot_scheduler_marks_post_start_dispatch_receipt_used_without_starting_provider(): void
    {
        $this->createDispatchExecutorHandoffRun();
        $this->createReceipt();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorInvoker::class)
            ->executeCodexRealInvokerPostStartDispatchReceiptUse($this->validInput());

        $this->assertSame('one_shot_scheduler_codex_real_invoker_post_start_dispatch_receipt_used', $result['status']);
        $this->assertTrue($result['codex_real_invoker_post_start_dispatch_receipt_use_executor_invoked']);
        $this->assertSame('codex-post-start-attempt-001', $result['provider_start_attempt_id']);
        $this->assertSame('codex-real-invoker-post-start-dispatch-executor-handoff-001', $result['dispatch_executor_handoff_id']);
        $this->assertTrue($result['dispatch_receipt_used']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertTrue($result['external_process_started']);
        $this->assertTrue($result['provider_started']);
        $this->assertFalse($result['provider_start_allowed_after_mark']);
        $this->assertFalse($result['provider_process_call_allowed']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertSame('receipt_marked_used', data_get($result, 'receipt_use_result.status'));
        $this->assertSame('activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract', $result['next_required_slice']);

        $receipt = AtlasSelfConstructionAgentDispatchReceipt::query()
            ->where('receipt_hash', str_repeat('a', 64))
            ->firstOrFail();

        $this->assertSame('used_pending_provider_start', $receipt->status);
        $this->assertFalse(data_get($receipt->payload, 'receipt_use.provider_start_side_effect_performed'));

        $run = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:attempt-001')
            ->firstOrFail();

        $this->assertSame(
            'post_start_dispatch_receipt_used_pending_provider_start_driver',
            data_get($run->metadata, 'codex_real_invoker_post_start_dispatch_receipt_use.status')
        );
    }

    public function test_one_shot_scheduler_post_start_dispatch_receipt_use_is_idempotent_for_same_attempt(): void
    {
        $this->createDispatchExecutorHandoffRun();
        $this->createReceipt();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorInvoker::class);

        $first = $invoker->executeCodexRealInvokerPostStartDispatchReceiptUse($this->validInput());
        $second = $invoker->executeCodexRealInvokerPostStartDispatchReceiptUse($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_self_construction_agent_dispatch_receipts', 1);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_one_shot_scheduler_rejects_missing_dispatch_executor_handoff_metadata(): void
    {
        $this->createDispatchExecutorHandoffRun(['metadata' => []]);
        $this->createReceipt();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_dispatch_executor_handoff_missing_or_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorInvoker::class)
            ->executeCodexRealInvokerPostStartDispatchReceiptUse($this->validInput());
    }

    public function test_one_shot_scheduler_rejects_handoff_with_dispatch_allowed(): void
    {
        $this->createDispatchExecutorHandoffRun([
            'metadata' => $this->metadataWithDispatchExecutorHandoff(['dispatch_allowed' => true]),
        ]);
        $this->createReceipt();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispatch_allowed_already_true');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorInvoker::class)
            ->executeCodexRealInvokerPostStartDispatchReceiptUse($this->validInput());
    }

    public function test_one_shot_scheduler_rejects_invalid_executor_contract_hash(): void
    {
        $this->createDispatchExecutorHandoffRun();
        $this->createReceipt();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_executor_contract_hash');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorInvoker::class)
            ->executeCodexRealInvokerPostStartDispatchReceiptUse(array_merge($this->validInput(), [
                'executor_contract_hash' => 'not-a-hash',
            ]));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createDispatchExecutorHandoffRun(array $overrides = []): void
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
            'summary' => 'Codex real invoker post-start dispatch executor handoff prepared; dispatch remains disabled pending receipt-use executor.',
            'metadata' => $this->metadataWithDispatchExecutorHandoff(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createReceipt(array $overrides = []): AtlasSelfConstructionAgentDispatchReceipt
    {
        return AtlasSelfConstructionAgentDispatchReceipt::query()->create(array_merge([
            'receipt_key' => 'DISPATCH-RECEIPT-001',
            'packet_id' => 'AP-001',
            'provider' => 'codex',
            'provider_role' => 'implementation',
            'decision' => 'approve_dispatch_once',
            'status' => 'signed_pending_dispatch',
            'signed_by' => 'vitor',
            'signed_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addHour(),
            'dispatch_envelope_hash' => str_repeat('1', 64),
            'adapter_contract_hash' => str_repeat('2', 64),
            'receipt_hash' => str_repeat('a', 64),
            'payload' => [
                'source' => 'codex_real_invoker_post_start_dispatch_receipt_use_executor_invoker_test',
                'provider_start_allowed' => false,
            ],
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $handoffOverrides
     * @return array<string,mixed>
     */
    private function metadataWithDispatchExecutorHandoff(array $handoffOverrides = []): array
    {
        return [
            'codex_real_invoker_post_start_dispatch_executor_handoff' => array_merge([
                'dispatch_executor_handoff_id' => 'codex-real-invoker-post-start-dispatch-executor-handoff-001',
                'signed_dispatch_authorization_id' => 'codex-real-invoker-post-start-signed-dispatch-auth-001',
                'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
                'signed_dispatch_receipt_hash' => str_repeat('a', 64),
                'executor_handoff_packet_hash' => str_repeat('6', 64),
                'executor_workspace_hash' => str_repeat('7', 64),
                'executor_scope_lock_hash' => str_repeat('8', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_dispatch_executor_handoff_prepared_pending_dispatch_use_receipt',
                'dispatch_executor_handoff_prepared' => true,
                'future_dispatch_authorized' => true,
                'actual_process_start_allowed' => false,
                'external_process_started' => true,
                'token_spend_allowed' => false,
                'provider_started' => true,
                'provider_process_call_allowed' => false,
                'dispatch_allowed' => false,
            ], $handoffOverrides),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return [
            'run_key' => 'provider-start:attempt-001',
            'dispatch_executor_handoff_id' => 'codex-real-invoker-post-start-dispatch-executor-handoff-001',
            'signed_dispatch_authorization_id' => 'codex-real-invoker-post-start-signed-dispatch-auth-001',
            'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            'signed_dispatch_receipt_hash' => str_repeat('a', 64),
            'executor_contract_hash' => str_repeat('b', 64),
            'executor_release_authorization_hash' => str_repeat('c', 64),
            'executor_handoff_packet_hash' => str_repeat('6', 64),
            'executor_workspace_hash' => str_repeat('7', 64),
            'executor_scope_lock_hash' => str_repeat('8', 64),
            'provider_start_attempt_id' => 'codex-post-start-attempt-001',
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'mark_post_start_dispatch_receipt_used_without_starting_codex',
        ];
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('atlas_self_construction_agent_dispatch_receipts');
        Schema::dropIfExists('atlas_self_construction_agent_wakeup_items');
        Schema::dropIfExists('atlas_self_construction_agent_work_products');
        Schema::dropIfExists('atlas_self_construction_agent_cost_events');
        Schema::dropIfExists('atlas_self_construction_agent_heartbeats');
        Schema::dropIfExists('atlas_self_construction_agent_runs');
        Schema::dropIfExists('atlas_ledger_events');
    }
}
