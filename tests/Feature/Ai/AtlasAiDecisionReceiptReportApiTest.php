<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Decision\DecisionReceiptHash;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiDecisionReceiptReportApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_api_replays_decision_receipt_chain_for_envelope(): void
    {
        $first = $this->recordDecisionReceiptEvent('env_api_receipt', 'receipt_api_1');
        $this->recordDecisionReceiptEvent('env_api_receipt', 'receipt_api_2', 'receipt_api_1', $first['chain_hash']);

        $this->getJson('/ai/decision-receipts/report?envelope=env_api_receipt', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('decision_receipt_replay.envelope_id', 'env_api_receipt')
            ->assertJsonPath('decision_receipt_replay.decision_event_count', 2)
            ->assertJsonPath('decision_receipt_replay.invalid_count', 0)
            ->assertJsonPath('decision_receipt_replay.latest_receipt_id', 'receipt_api_2')
            ->assertJsonPath('decision_receipt_replay.review_signal.status', 'ok');
    }

    public function test_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/decision-receipts/report?envelope=env_api_receipt')
            ->assertUnauthorized();
    }

    public function test_api_requires_envelope(): void
    {
        $this->getJson('/ai/decision-receipts/report', $this->headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['envelope']);
    }

    /**
     * @return array<string,mixed>
     */
    private function recordDecisionReceiptEvent(
        string $envelopeId,
        string $receiptId,
        ?string $parentReceiptId = null,
        ?string $parentChainHash = null,
    ): array {
        $payload = [
            'schema_version' => 'atlas.decide.v2',
            'receipt_id' => $receiptId,
            'envelope_id' => $envelopeId,
            'issued_at' => now()->toJSON(),
            'expires_at' => now()->addSeconds(30)->toJSON(),
            'dry_run' => false,
            'signed_by' => 'atlas-decide-v2',
            'provider_selection' => ['provider' => 'codex_cli', 'model' => 'gpt-5.2'],
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'risk' => 'medium',
            'budget' => ['max_cost_usd' => 1.0],
            'quality_gates' => ['tests'],
            'required_gates' => ['tests'],
            'required_evidence' => ['summary'],
            'repair_policy' => ['enabled' => false, 'max_attempts' => 0],
            'inputs_hash' => hash('sha256', 'input-'.$receiptId),
            'parent_receipt_id' => $parentReceiptId,
            'parent_chain_hash' => $parentChainHash,
        ];
        $payload['receipt_hash'] = DecisionReceiptHash::hash([
            'receipt_id' => $payload['receipt_id'],
            'envelope_id' => $payload['envelope_id'],
            'schema_version' => $payload['schema_version'],
            'issued_at' => $payload['issued_at'],
            'expires_at' => $payload['expires_at'],
            'dry_run' => $payload['dry_run'],
            'signed_by' => $payload['signed_by'],
            'inputs_hash' => $payload['inputs_hash'],
            'parent_receipt_id' => $payload['parent_receipt_id'],
        ]);
        $payload['chain_hash'] = DecisionReceiptHash::hash([
            'parent_chain_hash' => $payload['parent_chain_hash'],
            'receipt_hash' => $payload['receipt_hash'],
        ]);

        AtlasLedgerEvent::query()->create([
            'event_id' => 'evt_'.$receiptId,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_decision_receipt_api',
            'operator_id' => 'operator_decision_receipt_api',
            'envelope_id' => $envelopeId,
            'receipt_id' => $receiptId,
            'trace_id' => null,
            'correlation_id' => $envelopeId,
            'causation_id' => null,
            'event_type' => LedgerEventType::DecisionIssued->value,
            'emitter_stage' => 'atlas.decide',
            'emitter_version' => 'atlas-decide-v2',
            'payload' => $payload,
            'payload_hash' => hash('sha256', $receiptId),
            'occurred_at' => now(),
        ]);

        return $payload;
    }
}
