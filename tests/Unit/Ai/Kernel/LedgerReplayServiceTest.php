<?php

namespace Tests\Unit\Ai\Kernel;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LedgerReplayServiceTest extends TestCase
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

    public function test_slo_report_projects_envelope_observations_without_mutating_ledger(): void
    {
        $this->recordSloEvent('01HREPLAY000000000000000001', 'env_replay_slo', 'decide.issue', 80, true, 'ok', 'critical', [], null, [
            'domain' => 'programming',
            'surface_id' => 'atlas_cli_dev',
            'provider' => 'codex_cli',
        ]);
        $this->recordSloEvent('01HREPLAY000000000000000002', 'env_replay_slo', 'decide.issue', 420, true, 'warning', 'critical', ['p95_budget_exceeded'], null, [
            'domain' => 'programming',
            'surface_id' => 'atlas_cli_dev',
            'provider' => 'codex_cli',
        ]);
        $this->recordSloEvent('01HREPLAY000000000000000003', 'env_replay_slo', 'runtime.execute', 9000, false, 'breach', 'high', ['stage_failed'], null, [
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'surface_id' => 'atlas_cli_dev',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.2',
        ]);
        $this->recordSloEvent('01HREPLAY000000000000000004', 'other_env', 'runtime.execute', 1, true, 'ok', 'high');

        $report = app(AtlasLedgerReplayService::class)->sloReportForEnvelope('env_replay_slo');

        $this->assertSame('env_replay_slo', $report['envelope_id']);
        $this->assertSame(3, $report['observation_count']);
        $this->assertSame(2, $report['success_count']);
        $this->assertSame(1, $report['failure_count']);
        $this->assertSame('breach', $report['worst_status']);
        $this->assertSame('critical', $report['worst_severity']);
        $this->assertSame(['programming' => 3], $report['dimensions']['domain']);
        $this->assertSame(['atlas_cli_dev' => 3], $report['dimensions']['surface_id']);
        $this->assertSame(['codex_cli' => 3], $report['dimensions']['provider']);
        $this->assertSame(2, $report['stages']['decide.issue']['count']);
        $this->assertSame(80, $report['stages']['decide.issue']['p50_ms']);
        $this->assertSame(420, $report['stages']['decide.issue']['p95_ms']);
        $this->assertSame(['programming' => 2], $report['stages']['decide.issue']['dimensions']['domain']);
        $this->assertSame(['p95_budget_exceeded'], $report['stages']['decide.issue']['violations']);
        $this->assertSame(1, $report['stages']['runtime.execute']['failure_count']);
        $this->assertSame(['gpt-5.2' => 1], $report['stages']['runtime.execute']['dimensions']['model']);
        $this->assertSame(['stage_failed'], $report['stages']['runtime.execute']['violations']);
        $this->assertDatabaseCount('atlas_ledger_events', 4);
    }

    public function test_slo_window_report_aggregates_stages_and_recent_breaches(): void
    {
        $this->recordSloEvent('01HREPLAY000000000000000001', 'env_window_a', 'context.compose', 120, true, 'ok', 'high');
        $this->recordSloEvent('01HREPLAY000000000000000002', 'env_window_a', 'runtime.execute', 1200, true, 'warning', 'high', ['p95_budget_exceeded'], null, [
            'domain' => 'programming',
            'surface_id' => 'atlas_cli_dev',
            'provider' => 'codex_cli',
        ]);
        $this->recordSloEvent('01HREPLAY000000000000000003', 'env_window_b', 'runtime.execute', 3000, false, 'breach', 'high', ['stage_failed'], null, [
            'domain' => 'finance',
            'surface_id' => 'atlas_api',
            'provider' => 'claude_cli',
        ]);
        $this->recordSloEvent('01HREPLAY000000000000000004', 'env_old', 'runtime.execute', 10, true, 'ok', 'high', [], now()->subDays(2));

        $report = app(AtlasLedgerReplayService::class)->sloReportForWindow(now()->subHour(), now()->addMinute());

        $this->assertTrue($report['available']);
        $this->assertSame(3, $report['observation_count']);
        $this->assertSame(2, $report['envelope_count']);
        $this->assertSame(2, $report['success_count']);
        $this->assertSame(1, $report['failure_count']);
        $this->assertSame('breach', $report['worst_status']);
        $this->assertSame(['programming' => 1, 'finance' => 1], $report['dimensions']['domain']);
        $this->assertSame(['codex_cli' => 1, 'claude_cli' => 1], $report['dimensions']['provider']);
        $this->assertSame(2, $report['stages']['runtime.execute']['count']);
        $this->assertSame(1200, $report['stages']['runtime.execute']['p50_ms']);
        $this->assertSame(3000, $report['stages']['runtime.execute']['p95_ms']);
        $this->assertSame(['p95_budget_exceeded', 'stage_failed'], $report['stages']['runtime.execute']['violations']);
        $this->assertCount(2, $report['recent_breaches']);
        $this->assertSame('env_window_b', $report['recent_breaches'][0]['envelope_id']);
        $this->assertSame('finance', $report['recent_breaches'][0]['dimensions']['domain']);
    }

    public function test_repair_report_projects_repair_events_for_envelope(): void
    {
        $this->recordRepairEvent(
            eventId: '01HREPAIRREPLAY000000000001',
            envelopeId: 'env_repair_replay',
            type: LedgerEventType::RepairInitiated,
            status: 'repair_allowed',
            strategy: 'retry_provider',
            reasons: ['repair_planned'],
            failureDomain: 'provider.timeout',
            repairExecuted: false,
            decisionHash: 'decision-hash-01',
        );
        $this->recordRepairEvent(
            eventId: '01HREPAIRREPLAY000000000002',
            envelopeId: 'env_repair_replay',
            type: LedgerEventType::RepairCompleted,
            status: 'repair_allowed',
            strategy: 'retry_provider',
            reasons: ['execution_blocked_by_dry_run'],
            failureDomain: 'provider.timeout',
            repairExecuted: false,
            decisionHash: 'decision-hash-01',
            resultHash: 'result-hash-01',
            causationId: 'decision-hash-01',
        );
        $this->recordRepairEvent(
            eventId: '01HREPAIRREPLAY000000000003',
            envelopeId: 'other_env',
            type: LedgerEventType::RepairInitiated,
            status: 'needs_human_review',
            strategy: 'human_review',
            reasons: ['failure_domain_requires_human_review'],
            failureDomain: 'compliance.violation',
            repairExecuted: false,
            decisionHash: 'decision-hash-other',
        );

        $report = app(AtlasLedgerReplayService::class)->repairReportForEnvelope('env_repair_replay');

        $this->assertSame('env_repair_replay', $report['envelope_id']);
        $this->assertSame(2, $report['repair_event_count']);
        $this->assertSame(1, $report['initiated_count']);
        $this->assertSame(1, $report['completed_count']);
        $this->assertSame(0, $report['executed_count']);
        $this->assertSame(['repair_allowed' => 2], $report['status_counts']);
        $this->assertSame(['retry_provider' => 2], $report['strategy_counts']);
        $this->assertSame(1, $report['reason_counts']['repair_planned']);
        $this->assertSame(1, $report['reason_counts']['execution_blocked_by_dry_run']);
        $this->assertSame('repair_allowed', $report['latest_status']);
        $this->assertSame('retry_provider', $report['latest_strategy']);
        $this->assertFalse($report['requires_human_review']);
        $this->assertSame('REPAIR_COMPLETED', $report['events'][1]['event_type']);
        $this->assertSame('decision-hash-01', $report['events'][1]['causation_id']);
        $this->assertSame('result-hash-01', $report['events'][1]['result_hash']);
    }

    public function test_repair_window_report_aggregates_recent_repair_events(): void
    {
        $this->recordRepairEvent(
            eventId: '01HREPAIRWINDOW000000000001',
            envelopeId: 'env_repair_window_a',
            type: LedgerEventType::RepairInitiated,
            status: 'repair_allowed',
            strategy: 'rerun_harness',
            reasons: ['repair_planned'],
            failureDomain: 'harness.failed',
            repairExecuted: false,
            decisionHash: 'decision-window-a',
        );
        $this->recordRepairEvent(
            eventId: '01HREPAIRWINDOW000000000002',
            envelopeId: 'env_repair_window_b',
            type: LedgerEventType::RepairInitiated,
            status: 'needs_human_review',
            strategy: 'human_review',
            reasons: ['failure_domain_requires_human_review'],
            failureDomain: 'compliance.violation',
            repairExecuted: false,
            decisionHash: 'decision-window-b',
        );
        $this->recordRepairEvent(
            eventId: '01HREPAIRWINDOW000000000003',
            envelopeId: 'env_old_repair',
            type: LedgerEventType::RepairInitiated,
            status: 'repair_allowed',
            strategy: 'retry_provider',
            reasons: ['repair_planned'],
            failureDomain: 'provider.timeout',
            repairExecuted: false,
            decisionHash: 'decision-old',
            occurredAt: now()->subDays(2),
        );

        $report = app(AtlasLedgerReplayService::class)->repairReportForWindow(now()->subHour(), now()->addMinute());

        $this->assertTrue($report['available']);
        $this->assertSame(2, $report['repair_event_count']);
        $this->assertSame(2, $report['envelope_count']);
        $this->assertSame(2, $report['initiated_count']);
        $this->assertSame(0, $report['completed_count']);
        $this->assertSame(['repair_allowed' => 1, 'needs_human_review' => 1], $report['status_counts']);
        $this->assertSame(['rerun_harness' => 1, 'human_review' => 1], $report['strategy_counts']);
        $this->assertSame(1, $report['reason_counts']['failure_domain_requires_human_review']);
        $this->assertTrue($report['requires_human_review']);
        $this->assertSame('env_repair_window_b', $report['recent_events'][0]['envelope_id']);
    }

    public function test_repair_window_report_filters_by_repair_dimensions(): void
    {
        $this->recordRepairEvent(
            eventId: '01HREPAIRFILTER000000000001',
            envelopeId: 'env_repair_filter_harness',
            type: LedgerEventType::RepairInitiated,
            status: 'repair_allowed',
            strategy: 'rerun_harness',
            reasons: ['repair_planned'],
            failureDomain: 'harness.failed',
            repairExecuted: false,
            decisionHash: 'decision-filter-harness',
        );
        $this->recordRepairEvent(
            eventId: '01HREPAIRFILTER000000000002',
            envelopeId: 'env_repair_filter_human',
            type: LedgerEventType::RepairInitiated,
            status: 'needs_human_review',
            strategy: 'human_review',
            reasons: ['failure_domain_requires_human_review'],
            failureDomain: 'compliance.violation',
            repairExecuted: false,
            decisionHash: 'decision-filter-human',
        );

        $report = app(AtlasLedgerReplayService::class)->repairReportForWindow(
            now()->subHour(),
            now()->addMinute(),
            [
                'strategy' => 'human_review',
                'failure_domain' => 'compliance.violation',
                'ignored' => 'nope',
            ],
        );

        $this->assertTrue($report['available']);
        $this->assertSame([
            'strategy' => 'human_review',
            'failure_domain' => 'compliance.violation',
        ], $report['filters']);
        $this->assertSame(1, $report['repair_event_count']);
        $this->assertSame(1, $report['envelope_count']);
        $this->assertSame(['needs_human_review' => 1], $report['status_counts']);
        $this->assertSame(['human_review' => 1], $report['strategy_counts']);
        $this->assertSame('env_repair_filter_human', $report['recent_events'][0]['envelope_id']);
    }

    /**
     * @param  array<int,string>  $violations
     */
    private function recordSloEvent(
        string $eventId,
        string $envelopeId,
        string $stage,
        int $durationMs,
        bool $success,
        string $status,
        string $severity,
        array $violations = [],
        mixed $occurredAt = null,
        array $dimensions = [],
    ): void {
        AtlasLedgerEvent::query()->create([
            'event_id' => $eventId,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_test',
            'operator_id' => 'operator_test',
            'envelope_id' => $envelopeId,
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => $envelopeId,
            'causation_id' => null,
            'event_type' => LedgerEventType::SloObserved->value,
            'emitter_stage' => 'atlas.slo',
            'emitter_version' => 'atlas.slo.v1',
            'payload' => [
                'stage' => $stage,
                'status' => $status,
                'severity' => $severity,
                'violations' => $violations,
                'dimensions' => $dimensions,
                'slo' => [
                    'stage' => $stage,
                    'duration_ms' => $durationMs,
                    'success' => $success,
                    'status' => $status,
                    'severity' => $severity,
                    'violations' => $violations,
                ],
            ],
            'payload_hash' => hash('sha256', $eventId),
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    /**
     * @param  array<int,string>  $reasons
     */
    private function recordRepairEvent(
        string $eventId,
        string $envelopeId,
        LedgerEventType $type,
        string $status,
        string $strategy,
        array $reasons,
        string $failureDomain,
        bool $repairExecuted,
        string $decisionHash,
        ?string $resultHash = null,
        ?string $causationId = null,
        mixed $occurredAt = null,
    ): void {
        AtlasLedgerEvent::query()->create([
            'event_id' => $eventId,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_test',
            'operator_id' => 'operator_test',
            'envelope_id' => $envelopeId,
            'receipt_id' => 'receipt-repair',
            'trace_id' => null,
            'correlation_id' => $envelopeId,
            'causation_id' => $causationId,
            'event_type' => $type->value,
            'emitter_stage' => 'atlas.repair',
            'emitter_version' => 'atlas.repair.v1',
            'payload' => [
                'failure_classification' => [
                    'failure_domain' => $failureDomain,
                ],
                'decision' => [
                    'status' => $status,
                    'strategy' => $strategy,
                    'next_attempt' => 1,
                    'reasons' => $reasons,
                ],
                'repair_executed' => $repairExecuted,
                'decision_hash' => $decisionHash,
                'result_hash' => $resultHash,
            ],
            'payload_hash' => hash('sha256', $eventId),
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }
}
