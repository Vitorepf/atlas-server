<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiLedgerCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 32)->primary();
            $table->string('schema_version', 40)->default('atlas.ledger_event.v1');
            $table->string('tenant_id', 120)->index();
            $table->string('operator_id', 120)->index();
            $table->string('envelope_id', 80)->index();
            $table->string('receipt_id', 80)->nullable()->index();
            $table->uuid('trace_id')->nullable()->index();
            $table->string('correlation_id', 120)->index();
            $table->string('causation_id', 80)->nullable()->index();
            $table->string('event_type', 80)->index();
            $table->string('emitter_stage', 120)->index();
            $table->string('emitter_version', 80);
            $table->json('payload');
            $table->string('payload_hash', 64)->index();
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_command_replays_envelope_events_as_json(): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => '01HLEDGERCOMMAND0000000000001',
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_test',
            'operator_id' => 'operator_test',
            'envelope_id' => 'env_command',
            'receipt_id' => 'rcpt_command',
            'trace_id' => null,
            'correlation_id' => 'env_command',
            'causation_id' => null,
            'event_type' => LedgerEventType::ExecutionStarted->value,
            'emitter_stage' => 'ai.worker',
            'emitter_version' => 'ai-worker-v1',
            'payload' => ['job_id' => 'job-1'],
            'payload_hash' => hash('sha256', '{"job_id":"job-1"}'),
            'occurred_at' => now(),
        ]);

        $exit = Artisan::call('atlas:ai:ledger', [
            'envelope' => 'env_command',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('env_command', $payload['envelope_id']);
        $this->assertSame(LedgerEventType::ExecutionStarted->value, $payload['events'][0]['event_type']);
        $this->assertSame(['job_id' => 'job-1'], $payload['events'][0]['payload']);
    }
}
