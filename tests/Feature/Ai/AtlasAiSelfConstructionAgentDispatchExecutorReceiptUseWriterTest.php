<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentDispatchReceipt;
use App\Services\Ai\SelfConstruction\AgentDispatchExecutorReceiptUseWriter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentDispatchExecutorReceiptUseWriterTest extends TestCase
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

    public function test_writer_marks_one_pending_receipt_used_without_starting_provider(): void
    {
        $receipt = $this->createReceipt();

        $result = app(AgentDispatchExecutorReceiptUseWriter::class)
            ->markReceiptUsedAtomically($this->validInput());

        $this->assertSame('receipt_marked_used', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertFalse($result['provider_start_allowed_after_mark']);
        $this->assertFalse($result['dispatch_allowed']);

        $this->assertDatabaseHas('atlas_self_construction_agent_dispatch_receipts', [
            'id' => $receipt->id,
            'status' => 'used_pending_provider_start',
            'receipt_hash' => str_repeat('a', 64),
        ]);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'DISPATCH-RECEIPT-001',
        ]);

        $receipt->refresh();

        $this->assertNotNull($receipt->used_at);
        $this->assertSame('attempt-001', data_get($receipt->payload, 'receipt_use.provider_start_attempt_id'));
        $this->assertFalse(data_get($receipt->payload, 'receipt_use.provider_start_side_effect_performed'));
    }

    public function test_writer_is_idempotent_for_same_provider_start_attempt(): void
    {
        $this->createReceipt();
        $writer = app(AgentDispatchExecutorReceiptUseWriter::class);

        $first = $writer->markReceiptUsedAtomically($this->validInput());
        $second = $writer->markReceiptUsedAtomically($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['receipt_id'], $second['receipt_id']);
        $this->assertDatabaseCount('atlas_self_construction_agent_dispatch_receipts', 1);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_writer_rejects_already_used_receipt_for_different_attempt(): void
    {
        $this->createReceipt();
        $writer = app(AgentDispatchExecutorReceiptUseWriter::class);
        $writer->markReceiptUsedAtomically($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispatch_receipt_already_used');

        $writer->markReceiptUsedAtomically(array_merge($this->validInput(), [
            'provider_start_attempt_id' => 'attempt-002',
        ]));
    }

    public function test_writer_rejects_expired_receipt(): void
    {
        $this->createReceipt([
            'expires_at' => CarbonImmutable::now()->subMinute(),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispatch_receipt_expired');

        app(AgentDispatchExecutorReceiptUseWriter::class)
            ->markReceiptUsedAtomically($this->validInput());
    }

    public function test_writer_rejects_wrong_packet_or_provider(): void
    {
        $this->createReceipt();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispatch_receipt_packet_mismatch');

        app(AgentDispatchExecutorReceiptUseWriter::class)
            ->markReceiptUsedAtomically(array_merge($this->validInput(), [
                'packet_id' => 'OTHER-PACKET',
            ]));
    }

    public function test_writer_requires_ledger_table_for_same_transaction_contract(): void
    {
        $this->createReceipt();
        Schema::dropIfExists('atlas_ledger_events');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('append_only_ledger_table_missing');

        app(AgentDispatchExecutorReceiptUseWriter::class)
            ->markReceiptUsedAtomically($this->validInput());
    }

    public function test_writer_rolls_back_receipt_use_when_ledger_write_fails(): void
    {
        $receipt = $this->createReceipt();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentDispatchExecutorReceiptUseWriter::class)
                ->markReceiptUsedAtomically($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $receipt->refresh();

            $this->assertNull($receipt->used_at);
            $this->assertSame('signed_pending_dispatch', $receipt->status);
        }
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
                'source' => 'test',
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
            'reason' => 'future_provider_start_guard',
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
