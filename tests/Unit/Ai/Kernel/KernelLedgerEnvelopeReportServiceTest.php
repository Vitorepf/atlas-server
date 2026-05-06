<?php

namespace Tests\Unit\Ai\Kernel;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\KernelLedgerEnvelopeInput;
use App\Services\Ai\Kernel\Evidence\KernelLedgerEnvelopeReportService;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KernelLedgerEnvelopeReportServiceTest extends TestCase
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

    public function test_report_projects_envelope_events_with_canonical_filters(): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => '01HLEDGERREPORT000000000001',
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_test',
            'operator_id' => 'operator_test',
            'envelope_id' => 'env_report',
            'receipt_id' => 'rcpt_report',
            'trace_id' => null,
            'correlation_id' => 'env_report',
            'causation_id' => null,
            'event_type' => LedgerEventType::ExecutionStarted->value,
            'emitter_stage' => 'ai.worker',
            'emitter_version' => 'ai-worker-v1',
            'payload' => ['job_id' => 'job-report'],
            'payload_hash' => hash('sha256', 'job-report'),
            'occurred_at' => now(),
        ]);

        $payload = app(KernelLedgerEnvelopeReportService::class)->report(
            envelopeId: 'env_report',
            limit: 9999,
            includeSlo: true,
        );

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(KernelLedgerEnvelopeInput::MAX_EVENT_LIMIT, data_get($payload, 'filters.limit'));
        $this->assertTrue(data_get($payload, 'filters.slo'));
        $this->assertSame(1, $payload['event_count']);
        $this->assertSame(LedgerEventType::ExecutionStarted->value, data_get($payload, 'events.0.event_type'));
        $this->assertSame(['job_id' => 'job-report'], data_get($payload, 'events.0.payload'));
        $this->assertSame(0, data_get($payload, 'slo.observation_count'));
    }

    public function test_report_preserves_shape_when_ledger_table_is_missing(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $payload = app(KernelLedgerEnvelopeReportService::class)->report(
            envelopeId: 'env_missing',
            limit: null,
            includeKernel: true,
        );

        $this->assertSame('ledger_table_missing', $payload['status']);
        $this->assertSame(0, $payload['event_count']);
        $this->assertSame(KernelLedgerEnvelopeInput::DEFAULT_EVENT_LIMIT, data_get($payload, 'filters.limit'));
        $this->assertTrue(data_get($payload, 'filters.kernel'));
        $this->assertSame([], $payload['events']);
    }
}
