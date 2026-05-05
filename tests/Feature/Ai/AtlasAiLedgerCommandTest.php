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

    public function test_command_can_include_slo_summary_for_envelope(): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => '01HLEDGERCOMMAND0000000000001',
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_test',
            'operator_id' => 'operator_test',
            'envelope_id' => 'env_slo_command',
            'receipt_id' => 'rcpt_slo_command',
            'trace_id' => null,
            'correlation_id' => 'env_slo_command',
            'causation_id' => null,
            'event_type' => LedgerEventType::SloObserved->value,
            'emitter_stage' => 'atlas.slo',
            'emitter_version' => 'atlas.slo.v1',
            'payload' => [
                'stage' => 'runtime.execute',
                'status' => 'ok',
                'severity' => 'medium',
                'violations' => [],
                'slo' => [
                    'stage' => 'runtime.execute',
                    'duration_ms' => 120,
                    'success' => true,
                    'status' => 'ok',
                    'severity' => 'medium',
                    'violations' => [],
                ],
            ],
            'payload_hash' => hash('sha256', 'slo-1'),
            'occurred_at' => now()->subSecond(),
        ]);
        AtlasLedgerEvent::query()->create([
            'event_id' => '01HLEDGERCOMMAND0000000000002',
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_test',
            'operator_id' => 'operator_test',
            'envelope_id' => 'env_slo_command',
            'receipt_id' => 'rcpt_slo_command',
            'trace_id' => null,
            'correlation_id' => 'env_slo_command',
            'causation_id' => null,
            'event_type' => LedgerEventType::SloObserved->value,
            'emitter_stage' => 'atlas.slo',
            'emitter_version' => 'atlas.slo.v1',
            'payload' => [
                'stage' => 'runtime.execute',
                'status' => 'warning',
                'severity' => 'high',
                'violations' => ['p95_budget_exceeded'],
                'slo' => [
                    'stage' => 'runtime.execute',
                    'duration_ms' => 450,
                    'success' => true,
                    'status' => 'warning',
                    'severity' => 'high',
                    'violations' => ['p95_budget_exceeded'],
                ],
            ],
            'payload_hash' => hash('sha256', 'slo-2'),
            'occurred_at' => now(),
        ]);

        $exit = Artisan::call('atlas:ai:ledger', [
            'envelope' => 'env_slo_command',
            '--slo' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(2, $payload['slo']['observation_count']);
        $this->assertSame('warning', $payload['slo']['worst_status']);
        $this->assertSame('high', $payload['slo']['worst_severity']);
        $this->assertSame(2, $payload['slo']['stages']['runtime.execute']['count']);
        $this->assertSame(120, $payload['slo']['stages']['runtime.execute']['p50_ms']);
        $this->assertSame(450, $payload['slo']['stages']['runtime.execute']['p95_ms']);
        $this->assertSame(['p95_budget_exceeded'], $payload['slo']['stages']['runtime.execute']['violations']);
    }

    public function test_command_can_include_repair_summary_for_envelope(): void
    {
        $this->recordRepairEvent('01HLEDGERREPAIR000000000001', LedgerEventType::RepairInitiated, [
            'status' => 'repair_allowed',
            'strategy' => 'retry_provider',
            'reasons' => ['repair_planned'],
        ]);
        $this->recordRepairEvent('01HLEDGERREPAIR000000000002', LedgerEventType::RepairCompleted, [
            'status' => 'repair_allowed',
            'strategy' => 'retry_provider',
            'reasons' => ['execution_blocked_by_dry_run'],
        ], causationId: 'decision-hash-command');

        $exit = Artisan::call('atlas:ai:ledger', [
            'envelope' => 'env_repair_command',
            '--repair' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(2, $payload['repair']['repair_event_count']);
        $this->assertSame(1, $payload['repair']['initiated_count']);
        $this->assertSame(1, $payload['repair']['completed_count']);
        $this->assertSame('repair_allowed', $payload['repair']['latest_status']);
        $this->assertSame('retry_provider', $payload['repair']['latest_strategy']);
        $this->assertSame(['retry_provider' => 2], $payload['repair']['strategy_counts']);
        $this->assertSame('decision-hash-command', $payload['repair']['events'][1]['causation_id']);
    }

    /**
     * @param  array<string,mixed>  $decision
     */
    private function recordRepairEvent(string $eventId, LedgerEventType $type, array $decision, ?string $causationId = null): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => $eventId,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_test',
            'operator_id' => 'operator_test',
            'envelope_id' => 'env_repair_command',
            'receipt_id' => 'rcpt_repair_command',
            'trace_id' => null,
            'correlation_id' => 'env_repair_command',
            'causation_id' => $causationId,
            'event_type' => $type->value,
            'emitter_stage' => 'atlas.repair',
            'emitter_version' => 'atlas.repair.v1',
            'payload' => [
                'failure_classification' => [
                    'failure_domain' => 'provider.timeout',
                ],
                'decision' => $decision,
                'repair_executed' => false,
                'decision_hash' => 'decision-hash-command',
                'result_hash' => $type === LedgerEventType::RepairCompleted ? 'result-hash-command' : null,
            ],
            'payload_hash' => hash('sha256', $eventId),
            'occurred_at' => now(),
        ]);
    }
}
