<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Decision\DecisionReceiptHash;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiDecisionReceiptReportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_command_replays_decision_receipt_chain_as_json(): void
    {
        $first = $this->recordDecisionReceiptEvent('env_cli_receipt', 'receipt_cli_1');
        $second = $this->recordDecisionReceiptEvent('env_cli_receipt', 'receipt_cli_2', 'receipt_cli_1', $first['chain_hash']);

        $exit = Artisan::call('atlas:ai:decision-receipt-report', [
            '--envelope' => 'env_cli_receipt',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('env_cli_receipt', data_get($payload, 'decision_receipt_replay.envelope_id'));
        $this->assertSame(2, data_get($payload, 'decision_receipt_replay.decision_event_count'));
        $this->assertSame(0, data_get($payload, 'decision_receipt_replay.invalid_count'));
        $this->assertSame('receipt_cli_2', data_get($payload, 'decision_receipt_replay.latest_receipt_id'));
        $this->assertSame($second['chain_hash'], data_get($payload, 'decision_receipt_replay.latest_chain_hash'));
        $this->assertSame('ok', data_get($payload, 'decision_receipt_replay.review_signal.status'));
    }

    public function test_command_reports_invalid_input_without_envelope(): void
    {
        $exit = Artisan::call('atlas:ai:decision-receipt-report', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('invalid_input', $payload['status']);
        $this->assertSame('envelope_required', $payload['error']);
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
            'tenant_id' => 'tenant_decision_receipt_command',
            'operator_id' => 'operator_decision_receipt_command',
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
