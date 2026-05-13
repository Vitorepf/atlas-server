<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentDispatchReceipt;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseInvoker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseInvokerTest extends TestCase
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

    public function test_invoker_marks_one_signed_pending_dispatch_receipt_used_without_starting_provider(): void
    {
        $receipt = $this->createReceipt();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseInvoker::class)
            ->markSignedDispatchReceiptUsed($this->validInput());

        $this->assertSame('one_shot_scheduler_dispatch_receipt_used', $result['status']);
        $this->assertTrue($result['receipt_use_invoked']);
        $this->assertSame(1, $result['receipt_use_writer_invocation_count']);
        $this->assertSame('receipt_marked_used', data_get($result, 'receipt_use_result.status'));
        $this->assertFalse($result['idempotent']);
        $this->assertSame('used_pending_provider_start', $result['new_status']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_start_allowed']);
        $this->assertFalse($result['adapter_invocation_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertSame('activate_signed_one_shot_scheduler_tick_provider_start_driver_release_contract', $result['next_required_slice']);

        $this->assertDatabaseHas('atlas_self_construction_agent_dispatch_receipts', [
            'id' => $receipt->id,
            'status' => 'used_pending_provider_start',
            'receipt_hash' => str_repeat('a', 64),
        ]);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'SCHEDULER-DISPATCH-001',
        ]);

        $receipt->refresh();

        $this->assertNotNull($receipt->used_at);
        $this->assertSame('attempt-001', data_get($receipt->payload, 'receipt_use.provider_start_attempt_id'));
        $this->assertFalse(data_get($receipt->payload, 'receipt_use.provider_start_side_effect_performed'));
    }

    public function test_invoker_is_idempotent_for_same_provider_start_attempt(): void
    {
        $this->createReceipt();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseInvoker::class);

        $first = $invoker->markSignedDispatchReceiptUsed($this->validInput());
        $second = $invoker->markSignedDispatchReceiptUsed($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame(1, $second['receipt_use_writer_invocation_count']);
        $this->assertSame($first['receipt_id'], $second['receipt_id']);
        $this->assertFalse($second['provider_start_allowed']);
        $this->assertDatabaseCount('atlas_self_construction_agent_dispatch_receipts', 1);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_invoker_rejects_invalid_receipt_hash_without_marking_receipt_used(): void
    {
        $this->createReceipt();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_receipt_hash');

        app(AgentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseInvoker::class)
            ->markSignedDispatchReceiptUsed(array_merge($this->validInput(), [
                'receipt_hash' => 'not-a-hash',
            ]));
    }

    public function test_invoker_rejects_packet_mismatch_without_starting_provider(): void
    {
        $this->createReceipt();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispatch_receipt_packet_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseInvoker::class)
            ->markSignedDispatchReceiptUsed(array_merge($this->validInput(), [
                'packet_id' => 'OTHER-PACKET',
            ]));
    }

    public function test_invoker_rejects_provider_mismatch_without_starting_provider(): void
    {
        $this->createReceipt();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispatch_receipt_provider_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseInvoker::class)
            ->markSignedDispatchReceiptUsed(array_merge($this->validInput(), [
                'provider' => 'claude',
            ]));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createReceipt(array $overrides = []): AtlasSelfConstructionAgentDispatchReceipt
    {
        return AtlasSelfConstructionAgentDispatchReceipt::query()->create(array_merge([
            'receipt_key' => 'SCHEDULER-DISPATCH-001',
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
                'source' => 'one_shot_scheduler_dispatch_receipt_use_invoker_test',
                'provider_start_allowed' => false,
            ],
        ], $overrides));
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return [
            'receipt_hash' => str_repeat('a', 64),
            'executor_contract_hash' => str_repeat('b', 64),
            'executor_release_authorization_hash' => str_repeat('c', 64),
            'provider_start_attempt_id' => 'attempt-001',
            'actor' => 'codex-a',
            'session' => 'session-a',
            'packet_id' => 'AP-001',
            'provider' => 'codex',
            'reason' => 'one_shot_scheduler_future_provider_start_guard',
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
