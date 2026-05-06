<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiSloCommandTest extends TestCase
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

    public function test_command_summarizes_kernel_slo_window_as_json(): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => '01HSLOCOMMAND00000000000000001',
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_slo',
            'operator_id' => 'operator_slo',
            'envelope_id' => 'env_slo_command',
            'receipt_id' => null,
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
                'violations' => ['latency_above_p95'],
                'dimensions' => [
                    'domain' => 'programming',
                    'surface_id' => 'atlas_cli_dev',
                    'provider' => 'codex_cli',
                    'model' => 'gpt-5.2',
                ],
                'slo' => [
                    'stage' => 'runtime.execute',
                    'duration_ms' => 120000,
                    'success' => true,
                    'status' => 'warning',
                    'severity' => 'high',
                    'violations' => ['latency_above_p95'],
                ],
            ],
            'payload_hash' => hash('sha256', 'slo-command'),
            'occurred_at' => now(),
        ]);
        AtlasLedgerEvent::query()->create([
            'event_id' => '01HSLOCOMMAND00000000000000002',
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_slo',
            'operator_id' => 'operator_slo',
            'envelope_id' => 'env_slo_other',
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => 'env_slo_other',
            'causation_id' => null,
            'event_type' => LedgerEventType::SloObserved->value,
            'emitter_stage' => 'atlas.slo',
            'emitter_version' => 'atlas.slo.v1',
            'payload' => [
                'stage' => 'runtime.execute',
                'status' => 'breach',
                'severity' => 'high',
                'violations' => ['stage_failed'],
                'dimensions' => [
                    'domain' => 'finance',
                    'provider' => 'claude_cli',
                ],
                'slo' => [
                    'stage' => 'runtime.execute',
                    'duration_ms' => 999000,
                    'success' => false,
                    'status' => 'breach',
                    'severity' => 'high',
                    'violations' => ['stage_failed'],
                ],
            ],
            'payload_hash' => hash('sha256', 'slo-command-other'),
            'occurred_at' => now(),
        ]);

        $exit = Artisan::call('atlas:ai:slo', [
            '--hours' => 24,
            '--domain' => 'programming',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(['domain' => 'programming'], $payload['filters']);
        $this->assertSame(['domain' => 'programming'], data_get($payload, 'kernel_slo.filters'));
        $this->assertSame(1, data_get($payload, 'kernel_slo.observation_count'));
        $this->assertSame('warning', data_get($payload, 'kernel_slo.worst_status'));
        $this->assertSame('warning', data_get($payload, 'kernel_slo.review_signal.status'));
        $this->assertSame('medium', data_get($payload, 'kernel_slo.review_signal.severity'));
        $this->assertSame('open_reviewable_slo_drift_proposal', data_get($payload, 'kernel_slo.review_signal.recommended_action'));
        $this->assertSame(['programming' => 1], data_get($payload, 'kernel_slo.dimensions.domain'));
        $this->assertSame(['codex_cli' => 1], data_get($payload, 'kernel_slo.dimensions.provider'));
        $this->assertSame(120000, $payload['kernel_slo']['stages']['runtime.execute']['p95_ms']);
    }

    public function test_command_human_output_includes_slo_review_signal(): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => '01HSLOHUMAN000000000000000001',
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_slo',
            'operator_id' => 'operator_slo',
            'envelope_id' => 'env_slo_human',
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => 'env_slo_human',
            'causation_id' => null,
            'event_type' => LedgerEventType::SloObserved->value,
            'emitter_stage' => 'atlas.slo',
            'emitter_version' => 'atlas.slo.v1',
            'payload' => [
                'stage' => 'runtime.execute',
                'status' => 'breach',
                'severity' => 'high',
                'violations' => ['stage_failed'],
                'dimensions' => [
                    'domain' => 'programming',
                    'provider' => 'codex_cli',
                ],
                'slo' => [
                    'stage' => 'runtime.execute',
                    'duration_ms' => 450000,
                    'success' => false,
                    'status' => 'breach',
                    'severity' => 'high',
                    'violations' => ['stage_failed'],
                ],
            ],
            'payload_hash' => hash('sha256', 'slo-human'),
            'occurred_at' => now(),
        ]);

        $exit = Artisan::call('atlas:ai:slo', ['--hours' => 24]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Review signal', $output);
        $this->assertStringContainsString('breach', $output);
        $this->assertStringContainsString('open_reviewable_slo_regression_proposal', $output);
    }

    public function test_command_reports_unavailable_when_ledger_table_is_missing(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $exit = Artisan::call('atlas:ai:slo', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('ledger_unavailable', $payload['status']);
        $this->assertFalse(data_get($payload, 'kernel_slo.available'));
        $this->assertSame('unknown', data_get($payload, 'kernel_slo.review_signal.status'));
        $this->assertSame('wait_for_slo_evidence', data_get($payload, 'kernel_slo.review_signal.recommended_action'));
    }
}
