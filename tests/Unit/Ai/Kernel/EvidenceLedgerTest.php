<?php

namespace Tests\Unit\Ai\Kernel;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EvidenceLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->migrateLedger();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_records_decision_receipt_as_append_only_events(): void
    {
        $receipt = $this->receipt();

        $result = app(AtlasEvidenceLedger::class)->recordDecisionIssued($receipt, [
            'tenant_id' => 'tenant-a',
            'operator_id' => 'operator-a',
        ]);

        $this->assertInstanceOf(AtlasLedgerEvent::class, $result['envelope_created']);
        $this->assertInstanceOf(AtlasLedgerEvent::class, $result['decision_issued']);
        $this->assertSame(LedgerEventType::EnvelopeCreated->value, $result['envelope_created']->event_type);
        $this->assertSame(LedgerEventType::DecisionIssued->value, $result['decision_issued']->event_type);
        $this->assertSame($result['envelope_created']->event_id, $result['decision_issued']->causation_id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['decision_issued']->payload_hash);
        $this->assertDatabaseCount('atlas_ledger_events', 2);
    }

    public function test_events_for_envelope_replays_in_order(): void
    {
        $receipt = $this->receipt();

        app(AtlasEvidenceLedger::class)->recordDecisionIssued($receipt, [
            'tenant_id' => 'tenant-a',
            'operator_id' => 'operator-a',
        ]);

        $events = app(AtlasEvidenceLedger::class)->eventsForEnvelope('env_01');

        $this->assertCount(2, $events);
        $this->assertSame('ENVELOPE_CREATED', $events[0]['event_type']);
        $this->assertSame('DECISION_ISSUED', $events[1]['event_type']);
        $this->assertSame('tenant-a', $events[1]['tenant_id']);
        $this->assertSame('operator-a', $events[1]['operator_id']);
    }

    public function test_payload_hash_is_canonical_for_same_payload_order(): void
    {
        $ledger = app(AtlasEvidenceLedger::class);

        $first = $ledger->record(LedgerEventType::ContextComposed, [
            'b' => ['z' => 1, 'a' => 2],
            'a' => 'value',
        ], [
            'tenant_id' => 'tenant-a',
            'operator_id' => 'operator-a',
            'envelope_id' => 'env_02',
        ]);

        $second = $ledger->record(LedgerEventType::ContextComposed, [
            'a' => 'value',
            'b' => ['a' => 2, 'z' => 1],
        ], [
            'tenant_id' => 'tenant-a',
            'operator_id' => 'operator-a',
            'envelope_id' => 'env_02',
        ]);

        $this->assertSame($first?->payload_hash, $second?->payload_hash);
    }

    /**
     * @return array<string,mixed>
     */
    private function receipt(): array
    {
        return [
            'receipt_id' => 'receipt_01',
            'envelope_id' => 'env_01',
            'schema_version' => 'atlas.decide.v2',
            'issued_at' => '2026-05-05T01:00:00.000000Z',
            'expires_at' => '2026-05-05T01:00:30.000000Z',
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'risk' => 'medium',
            'provider_selection' => ['primary' => 'codex_cli', 'selection_mode' => 'auto_best_allowed'],
            'budgets' => [],
            'required_gates' => ['tests'],
            'required_evidence' => ['provider_selection'],
            'repair_policy' => ['enabled' => true, 'max_attempts' => 2],
            'inputs_hash' => hash('sha256', 'input'),
            'receipt_hash' => hash('sha256', 'receipt'),
            'chain_hash' => hash('sha256', 'chain'),
        ];
    }

    private function migrateLedger(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }
}
