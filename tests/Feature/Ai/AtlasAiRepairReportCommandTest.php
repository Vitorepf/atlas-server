<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiRepairReportCommandTest extends TestCase
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

    public function test_command_summarizes_kernel_repair_window_as_json(): void
    {
        $this->recordRepair('01HREPAIRREPORTCMD000000001', 'env_repair_cmd_a', LedgerEventType::RepairInitiated, 'repair_allowed', 'rerun_harness', ['repair_planned']);
        $this->recordRepair('01HREPAIRREPORTCMD000000002', 'env_repair_cmd_b', LedgerEventType::RepairInitiated, 'needs_human_review', 'human_review', ['failure_domain_requires_human_review']);

        $exit = Artisan::call('atlas:ai:repair-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(24, $payload['hours']);
        $this->assertSame(2, data_get($payload, 'kernel_repair.repair_event_count'));
        $this->assertSame(2, data_get($payload, 'kernel_repair.envelope_count'));
        $this->assertSame('needs_human_review', data_get($payload, 'kernel_repair.latest_status'));
        $this->assertSame('human_review', data_get($payload, 'kernel_repair.latest_strategy'));
        $this->assertTrue((bool) data_get($payload, 'kernel_repair.requires_human_review'));
        $this->assertSame(['rerun_harness' => 1, 'human_review' => 1], data_get($payload, 'kernel_repair.strategy_counts'));
    }

    public function test_command_filters_kernel_repair_report_as_json(): void
    {
        $this->recordRepair('01HREPAIRREPORTCMDFILTER001', 'env_repair_cmd_filter_a', LedgerEventType::RepairInitiated, 'repair_allowed', 'rerun_harness', ['repair_planned']);
        $this->recordRepair('01HREPAIRREPORTCMDFILTER002', 'env_repair_cmd_filter_b', LedgerEventType::RepairInitiated, 'needs_human_review', 'human_review', ['failure_domain_requires_human_review']);

        $exit = Artisan::call('atlas:ai:repair-report', [
            '--hours' => 24,
            '--strategy' => 'human_review',
            '--failure-domain' => 'compliance.violation',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame([
            'strategy' => 'human_review',
            'failure_domain' => 'compliance.violation',
        ], $payload['filters']);
        $this->assertSame($payload['filters'], data_get($payload, 'kernel_repair.filters'));
        $this->assertSame(1, data_get($payload, 'kernel_repair.repair_event_count'));
        $this->assertSame(['human_review' => 1], data_get($payload, 'kernel_repair.strategy_counts'));
        $this->assertSame('env_repair_cmd_filter_b', data_get($payload, 'kernel_repair.recent_events.0.envelope_id'));
    }

    public function test_command_reports_unavailable_when_ledger_table_is_missing(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $exit = Artisan::call('atlas:ai:repair-report', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('ledger_unavailable', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'kernel_repair.available'));
    }

    /**
     * @param  array<int,string>  $reasons
     */
    private function recordRepair(string $eventId, string $envelopeId, LedgerEventType $type, string $status, string $strategy, array $reasons): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => $eventId,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_repair_report',
            'operator_id' => 'operator_repair_report',
            'envelope_id' => $envelopeId,
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => $envelopeId,
            'causation_id' => null,
            'event_type' => $type->value,
            'emitter_stage' => 'atlas.repair',
            'emitter_version' => 'atlas.repair.v1',
            'payload' => [
                'failure_classification' => [
                    'failure_domain' => $strategy === 'human_review' ? 'compliance.violation' : 'harness.failed',
                ],
                'decision' => [
                    'status' => $status,
                    'strategy' => $strategy,
                    'reasons' => $reasons,
                ],
                'repair_executed' => false,
            ],
            'payload_hash' => hash('sha256', $eventId),
            'occurred_at' => now(),
        ]);
    }
}
