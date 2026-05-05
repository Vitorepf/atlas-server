<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiRepairReportApiTest extends TestCase
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

    public function test_repair_report_api_returns_window_summary(): void
    {
        $this->recordRepair('01HREPAIRREPORTAPI000000001', 'env_repair_api_a', LedgerEventType::RepairInitiated, 'repair_allowed', 'rerun_harness', ['repair_planned']);
        $this->recordRepair('01HREPAIRREPORTAPI000000002', 'env_repair_api_b', LedgerEventType::RepairCompleted, 'repair_allowed', 'rerun_harness', ['execution_blocked_by_dry_run']);

        $response = $this->getJson('/ai/repair/report?hours=24', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('hours', 24)
            ->assertJsonPath('kernel_repair.available', true)
            ->assertJsonPath('kernel_repair.repair_event_count', 2)
            ->assertJsonPath('kernel_repair.initiated_count', 1)
            ->assertJsonPath('kernel_repair.completed_count', 1)
            ->assertJsonPath('kernel_repair.latest_status', 'repair_allowed')
            ->assertJsonPath('kernel_repair.latest_strategy', 'rerun_harness');

        $this->assertSame(['rerun_harness' => 2], $response->json('kernel_repair.strategy_counts'));
    }

    public function test_repair_report_api_filters_window_summary(): void
    {
        $this->recordRepair('01HREPAIRREPORTAPIFILTER001', 'env_repair_api_filter_a', LedgerEventType::RepairInitiated, 'repair_allowed', 'rerun_harness', ['repair_planned']);
        $this->recordRepair('01HREPAIRREPORTAPIFILTER002', 'env_repair_api_filter_b', LedgerEventType::RepairInitiated, 'needs_human_review', 'human_review', ['failure_domain_requires_human_review']);

        $response = $this->getJson('/ai/repair/report?hours=24&status=needs_human_review&strategy=human_review&failure_domain=compliance.violation', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('filters.status', 'needs_human_review')
            ->assertJsonPath('filters.strategy', 'human_review')
            ->assertJsonPath('filters.failure_domain', 'compliance.violation')
            ->assertJsonPath('kernel_repair.repair_event_count', 1)
            ->assertJsonPath('kernel_repair.envelope_count', 1)
            ->assertJsonPath('kernel_repair.latest_status', 'needs_human_review')
            ->assertJsonPath('kernel_repair.latest_strategy', 'human_review');

        $this->assertSame(['human_review' => 1], $response->json('kernel_repair.strategy_counts'));
        $this->assertSame('env_repair_api_filter_b', $response->json('kernel_repair.recent_events.0.envelope_id'));
    }

    public function test_repair_report_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/repair/report')
            ->assertUnauthorized();
    }

    public function test_repair_report_api_returns_service_unavailable_when_ledger_table_is_missing(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $this->getJson('/ai/repair/report', $this->headers)
            ->assertStatus(503)
            ->assertJsonPath('status', 'ledger_unavailable')
            ->assertJsonPath('kernel_repair.available', false);
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
