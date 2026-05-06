<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasLedgerReplayCommandTest extends TestCase
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

    public function test_command_replays_envelope_from_named_option_as_json(): void
    {
        $this->recordEvent('01HLEDGERREPLAYCMD00000001', LedgerEventType::ExecutionStarted, [
            'job_id' => 'job-ledger-replay',
        ]);
        $this->recordEvent('01HLEDGERREPLAYCMD00000002', LedgerEventType::OperationCompleted, [
            'status' => 'completed',
        ]);

        $exit = Artisan::call('atlas:ledger:replay', [
            '--envelope' => 'env_ledger_replay_command',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('env_ledger_replay_command', $payload['envelope_id']);
        $this->assertSame(2, $payload['event_count']);
        $this->assertSame(LedgerEventType::ExecutionStarted->value, data_get($payload, 'events.0.event_type'));
        $this->assertSame(['job_id' => 'job-ledger-replay'], data_get($payload, 'events.0.payload'));
    }

    public function test_command_requires_envelope_option(): void
    {
        $exit = Artisan::call('atlas:ledger:replay', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('invalid_input', $payload['status']);
        $this->assertSame('envelope_required', $payload['error']);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function recordEvent(string $eventId, LedgerEventType $type, array $payload): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => $eventId,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_ledger_replay_command',
            'operator_id' => 'operator_ledger_replay_command',
            'envelope_id' => 'env_ledger_replay_command',
            'receipt_id' => 'receipt_ledger_replay_command',
            'trace_id' => null,
            'correlation_id' => 'env_ledger_replay_command',
            'causation_id' => null,
            'event_type' => $type->value,
            'emitter_stage' => 'atlas.kernel',
            'emitter_version' => 'atlas-kernel-test',
            'payload' => $payload,
            'payload_hash' => hash('sha256', $eventId),
            'occurred_at' => now(),
        ]);
    }
}
