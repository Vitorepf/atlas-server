<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiStrategicDecisionCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_ledger_events');
        Schema::dropIfExists('atlas_strategy_rivals_reviews');
        Schema::dropIfExists('atlas_strategy_rivals_cases');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_06_120000_create_atlas_strategy_rivals_tables.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        Schema::dropIfExists('atlas_strategy_rivals_reviews');
        Schema::dropIfExists('atlas_strategy_rivals_cases');

        parent::tearDown();
    }

    public function test_command_generates_review_packet_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:strategic-decision', [
            'action' => 'review',
            '--title' => 'Escolher direcao do Atlas',
            '--decision' => 'Priorizar arquitetura-mae',
            '--option' => ['arquitetura-mae', 'features soltas'],
            '--value' => ['qualidade', 'governanca'],
            '--impact' => 'high',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.strategic_decision.review_packet.v1', data_get($payload, 'strategic_decision.schema_version'));
        $this->assertSame('plan_only', data_get($payload, 'strategic_decision.mode'));
        $this->assertTrue(data_get($payload, 'strategic_decision.cooldown.required'));
        $this->assertTrue(data_get($payload, 'strategic_decision.rivals_strategy.recommended'));
        $this->assertNull($payload['decision_receipt']);
        $this->assertNull($payload['ledger']);
        $this->assertNull($payload['rivals_registration']);
        $this->assertDatabaseCount('atlas_ledger_events', 0);
        $this->assertDatabaseCount('atlas_strategy_rivals_cases', 0);
    }

    public function test_command_can_emit_dry_run_receipt_and_ledger_events_when_audit_is_explicit(): void
    {
        $exit = Artisan::call('atlas:ai:strategic-decision', [
            'action' => 'review',
            '--title' => 'Escolher direcao do Atlas',
            '--decision' => 'Priorizar arquitetura-mae',
            '--option' => ['arquitetura-mae', 'features soltas'],
            '--value' => ['qualidade', 'governanca'],
            '--impact' => 'high',
            '--audit' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.decide.v2', data_get($payload, 'decision_receipt.schema_version'));
        $this->assertTrue(data_get($payload, 'decision_receipt.dry_run'));
        $this->assertSame('strategic_decision', data_get($payload, 'decision_receipt.domain'));
        $this->assertSame('strategic_decision.review', data_get($payload, 'decision_receipt.flow'));
        $this->assertSame('atlas.strategic_decision.review.v1', data_get($payload, 'decision_receipt.signed_by'));
        $this->assertSame([
            LedgerEventType::EnvelopeCreated->value,
            LedgerEventType::DecisionIssued->value,
            LedgerEventType::EvidencePacked->value,
            LedgerEventType::OperationCompleted->value,
        ], data_get($payload, 'ledger.events'));
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::DecisionIssued->value,
            'emitter_stage' => 'atlas.strategic_decision.review',
        ]);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::EvidencePacked->value,
            'emitter_stage' => 'atlas.strategic_decision.review',
        ]);
    }

    public function test_command_can_register_rivals_case_only_when_explicit(): void
    {
        $exit = Artisan::call('atlas:ai:strategic-decision', [
            'action' => 'review',
            '--title' => 'Escolher direcao do Atlas',
            '--decision' => 'Priorizar arquitetura-mae',
            '--option' => ['arquitetura-mae', 'features soltas'],
            '--value' => ['qualidade', 'governanca'],
            '--impact' => 'high',
            '--register-rivals' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', data_get($payload, 'rivals_registration.status'));
        $this->assertTrue(data_get($payload, 'rivals_registration.created'));
        $this->assertSame(4, data_get($payload, 'rivals_registration.scheduled_reviews'));
        $this->assertDatabaseHas('atlas_strategy_rivals_cases', [
            'id' => data_get($payload, 'rivals_registration.case_id'),
            'title' => 'Escolher direcao do Atlas',
        ]);
        $this->assertDatabaseCount('atlas_strategy_rivals_reviews', 4);
        $this->assertDatabaseCount('atlas_ledger_events', 0);
    }
}
