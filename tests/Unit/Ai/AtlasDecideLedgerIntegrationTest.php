<?php

namespace Tests\Unit\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\AtlasDecideService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasDecideLedgerIntegrationTest extends TestCase
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

    public function test_operational_decision_emits_ledger_events_when_ledger_table_exists(): void
    {
        $decision = app(AtlasDecideService::class)->operationalDecision([
            'mode' => 'dev',
            'task' => 'implement',
            'input_text' => 'implemente uma melhoria no atlas dev',
            'workspace' => base_path(),
            'payload' => [
                'atlas_workflow_mode' => 'dev',
                'operator_id' => 'vitor',
                'tenant_id' => 'atlas-local',
                'thread_id' => 'thread-1',
            ],
        ])->toArray();

        $envelopeId = (string) data_get($decision, 'receipt_v2.envelope_id');
        $receiptId = (string) data_get($decision, 'receipt_v2.receipt_id');

        $this->assertNotSame('', $envelopeId);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'envelope_id' => $envelopeId,
            'receipt_id' => $receiptId,
            'event_type' => 'DECISION_ISSUED',
            'tenant_id' => 'atlas-local',
            'operator_id' => 'vitor',
        ]);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'envelope_id' => $envelopeId,
            'event_type' => 'SLO_OBSERVED',
            'emitter_stage' => 'atlas.slo',
            'tenant_id' => 'atlas-local',
            'operator_id' => 'vitor',
        ]);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'envelope_id' => 'provider_prepare_pre_envelope',
            'event_type' => 'SLO_OBSERVED',
            'emitter_stage' => 'atlas.slo',
            'tenant_id' => 'default',
            'operator_id' => 'system',
        ]);
        $providerPrepareEvent = AtlasLedgerEvent::query()
            ->where('envelope_id', 'provider_prepare_pre_envelope')
            ->where('event_type', 'SLO_OBSERVED')
            ->first();
        $this->assertSame('provider.prepare', data_get($providerPrepareEvent?->payload, 'stage'));

        $events = AtlasLedgerEvent::query()
            ->where('envelope_id', $envelopeId)
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->pluck('event_type')
            ->all();

        $this->assertSame(['ENVELOPE_CREATED', 'SLO_OBSERVED', 'DECISION_ISSUED'], $events);
    }

    private function migrateLedger(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }
}
