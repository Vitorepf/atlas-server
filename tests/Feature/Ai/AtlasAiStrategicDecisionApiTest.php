<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiStrategicDecisionApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
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

    public function test_api_generates_review_packet(): void
    {
        $this->postJson('/ai/strategic-decision/review', [
            'title' => 'Escolher direcao do Atlas',
            'decision' => 'Priorizar arquitetura-mae',
            'options' => ['arquitetura-mae', 'features soltas'],
            'values' => ['qualidade', 'governanca'],
            'impact' => 'high',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('strategic_decision.schema_version', 'atlas.strategic_decision.review_packet.v1')
            ->assertJsonPath('strategic_decision.mode', 'plan_only')
            ->assertJsonPath('strategic_decision.rules.no_external_side_effects', true)
            ->assertJsonPath('decision_receipt', null)
            ->assertJsonPath('ledger', null)
            ->assertJsonPath('rivals_registration', null);

        $this->assertDatabaseCount('atlas_ledger_events', 0);
        $this->assertDatabaseCount('atlas_strategy_rivals_cases', 0);
    }

    public function test_api_can_emit_audited_review_packet_when_requested(): void
    {
        $this->postJson('/ai/strategic-decision/review', [
            'title' => 'Escolher direcao do Atlas',
            'decision' => 'Priorizar arquitetura-mae',
            'options' => ['arquitetura-mae', 'features soltas'],
            'values' => ['qualidade', 'governanca'],
            'impact' => 'high',
            'audit' => true,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('decision_receipt.schema_version', 'atlas.decide.v2')
            ->assertJsonPath('decision_receipt.dry_run', true)
            ->assertJsonPath('ledger.events.0', LedgerEventType::EnvelopeCreated->value)
            ->assertJsonPath('ledger.events.1', LedgerEventType::DecisionIssued->value)
            ->assertJsonPath('ledger.events.2', LedgerEventType::EvidencePacked->value)
            ->assertJsonPath('ledger.events.3', LedgerEventType::OperationCompleted->value);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::OperationCompleted->value,
            'emitter_stage' => 'atlas.strategic_decision.review',
        ]);
    }

    public function test_api_can_register_rivals_case_when_explicit(): void
    {
        $this->postJson('/ai/strategic-decision/review', [
            'title' => 'Escolher direcao do Atlas',
            'decision' => 'Priorizar arquitetura-mae',
            'options' => ['arquitetura-mae', 'features soltas'],
            'values' => ['qualidade', 'governanca'],
            'impact' => 'high',
            'register_rivals' => true,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('rivals_registration.status', 'ok')
            ->assertJsonPath('rivals_registration.created', true)
            ->assertJsonPath('rivals_registration.scheduled_reviews', 4);

        $this->assertDatabaseCount('atlas_strategy_rivals_cases', 1);
        $this->assertDatabaseCount('atlas_strategy_rivals_reviews', 4);
        $this->assertDatabaseCount('atlas_ledger_events', 0);
    }

    public function test_api_requires_atlas_token(): void
    {
        $this->postJson('/ai/strategic-decision/review')
            ->assertUnauthorized();
    }
}
