<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentWakeupItem;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_self_construction_agent_dispatch_receipts');
        Schema::dropIfExists('atlas_self_construction_agent_wakeup_items');
        Schema::dropIfExists('atlas_self_construction_agent_work_products');
        Schema::dropIfExists('atlas_self_construction_agent_cost_events');
        Schema::dropIfExists('atlas_self_construction_agent_heartbeats');
        Schema::dropIfExists('atlas_self_construction_agent_runs');
        Schema::dropIfExists('atlas_ledger_events');

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php'))->up();

        AtlasSelfConstructionAgentWakeupItem::query()->create([
            'wakeup_key' => 'WAKEUP-001',
            'packet_id' => 'AP-001',
            'actor' => 'codex-a',
            'provider' => 'codex',
            'reason' => 'resume_provider_session',
            'priority' => 'high',
            'status' => 'queued',
            'scheduled_for' => CarbonImmutable::now()->subMinute(),
            'payload' => ['source' => 'test'],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_self_construction_agent_dispatch_receipts');
        Schema::dropIfExists('atlas_self_construction_agent_wakeup_items');
        Schema::dropIfExists('atlas_self_construction_agent_work_products');
        Schema::dropIfExists('atlas_self_construction_agent_cost_events');
        Schema::dropIfExists('atlas_self_construction_agent_heartbeats');
        Schema::dropIfExists('atlas_self_construction_agent_runs');
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_writer_persists_release_receipt_and_ledger_event_without_claiming_or_dispatching(): void
    {
        $result = app(AgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriter::class)
            ->persistSignedReleaseReceipt($this->validInput());

        $this->assertTrue($result['created']);
        $this->assertFalse($result['claim_allowed']);
        $this->assertFalse($result['dispatch_receipt_write_allowed']);
        $this->assertFalse($result['provider_start_allowed']);
        $this->assertFalse($result['self_programming_allowed']);

        $this->assertDatabaseHas('atlas_self_construction_agent_dispatch_receipts', [
            'receipt_key' => 'SCHEDULER-RELEASE-001',
            'decision' => 'approve_scheduler_claim_and_receipt_once',
            'status' => 'release_authorized_pending_one_shot_tick',
            'receipt_hash' => str_repeat('a', 64),
        ]);

        $wakeup = AtlasSelfConstructionAgentWakeupItem::query()
            ->where('wakeup_key', 'WAKEUP-001')
            ->firstOrFail();

        $this->assertSame('queued', $wakeup->status);
        $this->assertNull($wakeup->claimed_at);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'SCHEDULER-RELEASE-001',
        ]);
    }

    public function test_writer_is_idempotent_for_same_receipt_hash(): void
    {
        $writer = app(AgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriter::class);

        $first = $writer->persistSignedReleaseReceipt($this->validInput());
        $second = $writer->persistSignedReleaseReceipt($this->validInput());

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['receipt_id'], $second['receipt_id']);
        $this->assertDatabaseCount('atlas_self_construction_agent_dispatch_receipts', 1);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_writer_rejects_duplicate_release_receipt_key_with_different_hash(): void
    {
        $writer = app(AgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriter::class);
        $writer->persistSignedReleaseReceipt($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate_release_receipt_key');

        $writer->persistSignedReleaseReceipt(array_merge($this->validInput(), [
            'receipt_hash' => str_repeat('b', 64),
        ]));
    }

    public function test_writer_rejects_missing_signature_metadata(): void
    {
        $input = $this->validInput();
        unset($input['signed_by']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_signed_by');

        app(AgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriter::class)
            ->persistSignedReleaseReceipt($input);
    }

    public function test_writer_rejects_expired_signed_receipt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expired_signed_receipt');

        app(AgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriter::class)
            ->persistSignedReleaseReceipt(array_merge($this->validInput(), [
                'expires_at' => CarbonImmutable::now()->subMinute()->toIso8601String(),
            ]));
    }

    public function test_writer_rejects_stale_or_invalid_hashes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_source_persistence_writer_preflight_hash');

        app(AgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriter::class)
            ->persistSignedReleaseReceipt(array_merge($this->validInput(), [
                'source_persistence_writer_preflight_hash' => 'not-a-hash',
            ]));
    }

    public function test_writer_rejects_non_queued_wakeup_without_mutating_it(): void
    {
        AtlasSelfConstructionAgentWakeupItem::query()
            ->where('wakeup_key', 'WAKEUP-001')
            ->update(['status' => 'claimed', 'claimed_at' => CarbonImmutable::now()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('selected_wakeup_item_not_queued');

        app(AgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriter::class)
            ->persistSignedReleaseReceipt($this->validInput());
    }

    public function test_writer_requires_ledger_table_for_same_transaction_contract(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('append_only_ledger_table_missing');

        app(AgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriter::class)
            ->persistSignedReleaseReceipt($this->validInput());
    }

    public function test_writer_rolls_back_receipt_when_ledger_write_fails(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriter::class)
                ->persistSignedReleaseReceipt($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $this->assertDatabaseCount('atlas_self_construction_agent_dispatch_receipts', 0);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return [
            'receipt_key' => 'SCHEDULER-RELEASE-001',
            'packet_id' => 'AP-001',
            'provider' => 'codex',
            'provider_role' => 'implementation',
            'decision' => 'approve_scheduler_claim_and_receipt_once',
            'signed_by' => 'vitor',
            'signed_at' => CarbonImmutable::now()->toIso8601String(),
            'expires_at' => CarbonImmutable::now()->addHour()->toIso8601String(),
            'selected_wakeup_key' => 'WAKEUP-001',
            'dispatch_envelope_hash' => str_repeat('1', 64),
            'scope_hash' => str_repeat('2', 64),
            'source_release_template_hash' => str_repeat('3', 64),
            'source_release_receipt_draft_hash' => str_repeat('4', 64),
            'source_validation_preflight_hash' => str_repeat('5', 64),
            'source_persistence_contract_hash' => str_repeat('6', 64),
            'source_persistence_writer_preflight_hash' => str_repeat('7', 64),
            'receipt_hash' => str_repeat('a', 64),
            'payload' => [
                'source' => 'test',
                'claim_allowed' => false,
                'dispatch_receipt_write_allowed' => false,
                'provider_start_allowed' => false,
            ],
        ];
    }
}
