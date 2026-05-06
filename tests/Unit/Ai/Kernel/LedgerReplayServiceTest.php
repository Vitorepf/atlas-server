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
        $this->assertSame('breach', data_get($report, 'review_signal.status'));
        $this->assertSame('high', data_get($report, 'review_signal.severity'));
        $this->assertTrue((bool) data_get($report, 'review_signal.review_required'));
        $this->assertSame('open_reviewable_slo_regression_proposal', data_get($report, 'review_signal.recommended_action'));
        $this->assertContains('stage_failed', data_get($report, 'review_signal.reasons'));
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
        $this->assertSame('breach', data_get($report, 'review_signal.status'));
        $this->assertSame('high', data_get($report, 'review_signal.severity'));
        $this->assertTrue((bool) data_get($report, 'review_signal.review_required'));
        $this->assertSame('open_reviewable_slo_regression_proposal', data_get($report, 'review_signal.recommended_action'));
        $this->assertContains('slo_breach_detected', data_get($report, 'review_signal.reasons'));
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
        $this->assertSame('warning', data_get($report, 'review_signal.status'));
        $this->assertSame('medium', data_get($report, 'review_signal.severity'));
        $this->assertTrue((bool) data_get($report, 'review_signal.review_required'));
        $this->assertSame('open_reviewable_repair_loop_human_review_proposal', data_get($report, 'review_signal.recommended_action'));
        $this->assertContains('failure_domain_requires_human_review', data_get($report, 'review_signal.reasons'));
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
        $this->assertSame('warning', data_get($report, 'review_signal.status'));
        $this->assertSame('open_reviewable_repair_loop_human_review_proposal', data_get($report, 'review_signal.recommended_action'));
        $this->assertSame('env_repair_filter_human', $report['recent_events'][0]['envelope_id']);
    }

    public function test_kernel_pipeline_report_projects_acceptance_and_rejection_for_envelope(): void
    {
        $this->recordKernelPipelineEvent(
            eventId: '01HKERNELREPLAY000000000001',
            envelopeId: 'env_kernel_replay',
            type: LedgerEventType::KernelPipelineAccepted,
            status: 'accepted',
            surfaceId: 'atlas_cli_dev',
            flow: 'programming.dev',
            inputMode: 'one_shot',
        );
        $this->recordKernelPipelineEvent(
            eventId: '01HKERNELREPLAY000000000002',
            envelopeId: 'env_kernel_replay',
            type: LedgerEventType::KernelPipelineRejected,
            status: 'rejected',
            surfaceId: 'atlas_ai_chat',
            flow: 'programming.dev',
            inputMode: 'declared_dev_plan',
            violations: ['kernel_pipeline.canonical_flow_hash does not match the canonical kernel flow.'],
        );
        $this->recordKernelPipelineEvent(
            eventId: '01HKERNELREPLAY000000000003',
            envelopeId: 'other_env',
            type: LedgerEventType::KernelPipelineAccepted,
            status: 'accepted',
            surfaceId: 'atlas_ai_chat',
            flow: 'programming.forge',
            inputMode: 'chat_dev_auto_plan',
        );

        $report = app(AtlasLedgerReplayService::class)->kernelPipelineReportForEnvelope('env_kernel_replay');

        $this->assertSame('env_kernel_replay', $report['envelope_id']);
        $this->assertSame(2, $report['kernel_pipeline_event_count']);
        $this->assertSame(1, $report['accepted_count']);
        $this->assertSame(1, $report['rejected_count']);
        $this->assertTrue($report['has_rejections']);
        $this->assertSame('rejected', $report['latest_status']);
        $this->assertSame(['accepted' => 1, 'rejected' => 1], $report['status_counts']);
        $this->assertSame(['atlas_cli_dev' => 1, 'atlas_ai_chat' => 1], $report['surface_counts']);
        $this->assertSame(['atlas.ai_chat.kernel_pipeline_guard' => 2], $report['emitter_stage_counts']);
        $this->assertSame(['KernelPipelineDevPlanBuilder' => 2], $report['surface_contract_source_counts']);
        $this->assertSame(['programming.dev' => 2], $report['flow_counts']);
        $this->assertSame(1, $report['violation_counts']['kernel_pipeline.canonical_flow_hash does not match the canonical kernel flow.']);
        $this->assertSame('breach', data_get($report, 'health.status'));
        $this->assertSame(0.5, data_get($report, 'health.rejection_rate'));
        $this->assertTrue((bool) data_get($report, 'health.review_required'));
        $this->assertSame(['kernel_pipeline_rejection_rate_above_breach_threshold'], data_get($report, 'health.reasons'));
        $this->assertSame('breach', data_get($report, 'review_signal.status'));
        $this->assertSame('high', data_get($report, 'review_signal.severity'));
        $this->assertTrue((bool) data_get($report, 'review_signal.review_required'));
        $this->assertSame('open_reviewable_kernel_pipeline_contract_proposal', data_get($report, 'review_signal.recommended_action'));
        $this->assertContains('kernel_pipeline.canonical_flow_hash does not match the canonical kernel flow.', data_get($report, 'review_signal.reasons'));
        $this->assertSame('KernelPipelineDevPlanBuilder', $report['events'][1]['surface_contract_source']);
        $this->assertSame('declared_dev_plan', $report['events'][1]['input_mode']);
    }

    public function test_kernel_pipeline_window_report_filters_by_surface_and_status(): void
    {
        $this->recordKernelPipelineEvent(
            eventId: '01HKERNELWINDOW000000000001',
            envelopeId: 'env_kernel_window_a',
            type: LedgerEventType::KernelPipelineAccepted,
            status: 'accepted',
            surfaceId: 'atlas_cli_dev',
            flow: 'programming.dev',
            inputMode: 'one_shot',
        );
        $this->recordKernelPipelineEvent(
            eventId: '01HKERNELWINDOW000000000002',
            envelopeId: 'env_kernel_window_b',
            type: LedgerEventType::KernelPipelineRejected,
            status: 'rejected',
            surfaceId: 'atlas_ai_chat',
            flow: 'programming.dev',
            inputMode: 'declared_dev_plan',
            violations: ['kernel_pipeline.stage_order must match the canonical kernel stage order.'],
        );
        $this->recordKernelPipelineEvent(
            eventId: '01HKERNELOLD00000000000001',
            envelopeId: 'env_kernel_old',
            type: LedgerEventType::KernelPipelineRejected,
            status: 'rejected',
            surfaceId: 'atlas_ai_chat',
            flow: 'programming.dev',
            inputMode: 'declared_dev_plan',
            violations: ['old'],
            occurredAt: now()->subDays(2),
        );

        $report = app(AtlasLedgerReplayService::class)->kernelPipelineReportForWindow(
            now()->subHour(),
            now()->addMinute(),
            [
                'status' => 'rejected',
                'surface_id' => 'atlas_ai_chat',
                'surface_contract_source' => 'KernelPipelineDevPlanBuilder',
            ],
        );

        $this->assertTrue($report['available']);
        $this->assertSame([
            'status' => 'rejected',
            'surface_id' => 'atlas_ai_chat',
            'surface_contract_source' => 'KernelPipelineDevPlanBuilder',
        ], $report['filters']);
        $this->assertSame(1, $report['kernel_pipeline_event_count']);
        $this->assertSame(1, $report['envelope_count']);
        $this->assertSame(0, $report['accepted_count']);
        $this->assertSame(1, $report['rejected_count']);
        $this->assertTrue($report['has_rejections']);
        $this->assertSame('breach', data_get($report, 'health.status'));
        $this->assertSame(1.0, data_get($report, 'health.rejection_rate'));
        $this->assertSame('breach', data_get($report, 'review_signal.status'));
        $this->assertSame('high', data_get($report, 'review_signal.severity'));
        $this->assertSame('open_reviewable_kernel_pipeline_contract_proposal', data_get($report, 'review_signal.recommended_action'));
        $this->assertSame('env_kernel_window_b', $report['recent_events'][0]['envelope_id']);
        $this->assertSame(['atlas.ai_chat.kernel_pipeline_guard' => 1], $report['emitter_stage_counts']);
        $this->assertSame(['declared_dev_plan' => 1], $report['input_mode_counts']);
    }

    public function test_self_improvement_schedule_window_report_projects_schedule_health_events(): void
    {
        $this->recordSelfImprovementScheduleEvent(
            eventId: '01HSCHEDREPLAY000000000001',
            envelopeId: 'self_improvement_run:ok',
            flow: 'self_improvement.nightly_review',
            healthStatus: 'healthy',
            schedulerStatus: 'registered',
            issues: [],
            registeredCommandCount: 4,
            planHash: 'plan-hash-ok',
        );
        $this->recordSelfImprovementScheduleEvent(
            eventId: '01HSCHEDREPLAY000000000002',
            envelopeId: 'self_improvement_run:warning',
            flow: 'self_improvement.weekly_architecture_audit',
            healthStatus: 'warning',
            schedulerStatus: 'registered',
            issues: ['invalid_self_improvement_flows_configured'],
            registeredCommandCount: 1,
            invalidFlowCount: 1,
            planHash: 'plan-hash-warning',
        );
        $this->recordSelfImprovementScheduleEvent(
            eventId: '01HSCHEDOLD00000000000001',
            envelopeId: 'self_improvement_run:old',
            flow: 'self_improvement.nightly_review',
            healthStatus: 'warning',
            schedulerStatus: 'skipped',
            issues: ['old'],
            registeredCommandCount: 0,
            occurredAt: now()->subDays(2),
        );

        $report = app(AtlasLedgerReplayService::class)->selfImprovementScheduleReportForWindow(now()->subHour(), now()->addMinute());

        $this->assertTrue($report['available']);
        $this->assertSame(2, $report['schedule_observation_count']);
        $this->assertSame(2, $report['envelope_count']);
        $this->assertSame(['healthy' => 1, 'warning' => 1], $report['health_status_counts']);
        $this->assertSame(['registered' => 2], $report['scheduler_status_counts']);
        $this->assertSame(1, $report['issue_counts']['invalid_self_improvement_flows_configured']);
        $this->assertSame(1, $report['warning_count']);
        $this->assertSame('warning', $report['latest_health_status']);
        $this->assertSame('registered', $report['latest_scheduler_status']);
        $this->assertSame('plan-hash-warning', $report['latest_plan_hash']);
        $this->assertTrue($report['review_required']);
        $this->assertSame('warning', data_get($report, 'health.status'));
        $this->assertContains('invalid_self_improvement_flows_configured', data_get($report, 'health.reasons'));
        $this->assertSame('warning', data_get($report, 'review_signal.status'));
        $this->assertSame('medium', data_get($report, 'review_signal.severity'));
        $this->assertTrue((bool) data_get($report, 'review_signal.review_required'));
        $this->assertSame('open_reviewable_self_improvement_schedule_proposal', data_get($report, 'review_signal.recommended_action'));
        $this->assertSame('self_improvement_run:warning', $report['recent_events'][0]['envelope_id']);
        $this->assertSame('self_improvement.weekly_architecture_audit', $report['recent_events'][0]['flow']);
        $this->assertSame(['daily' => 1], $report['recent_events'][0]['cadence_counts']);
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

    /**
     * @param  array<int,string>  $violations
     */
    private function recordKernelPipelineEvent(
        string $eventId,
        string $envelopeId,
        LedgerEventType $type,
        string $status,
        string $surfaceId,
        string $flow,
        string $inputMode,
        array $violations = [],
        mixed $occurredAt = null,
    ): void {
        AtlasLedgerEvent::query()->create([
            'event_id' => $eventId,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_test',
            'operator_id' => 'operator_test',
            'envelope_id' => $envelopeId,
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => 'pipe_test',
            'causation_id' => null,
            'event_type' => $type->value,
            'emitter_stage' => 'atlas.ai_chat.kernel_pipeline_guard',
            'emitter_version' => 'atlas.ai_chat.kernel_pipeline_guard.v1',
            'payload' => [
                'status' => $status,
                'violations' => $violations,
                'pipeline' => [
                    'pipeline_id' => 'pipe_test',
                    'schema_version' => 'atlas.kernel.pipeline.scaffold.v1',
                    'mode' => 'scaffold_dry_run',
                    'stage_count' => 14,
                    'canonical_flow_hash' => 'flow-hash',
                    'provider_execution_allowed' => false,
                    'runtime_execution_allowed' => false,
                ],
                'surface' => [
                    'surface_id' => $surfaceId,
                    'binding_surface' => $surfaceId,
                    'command' => 'atlas:ai:chat',
                    'input_mode' => $inputMode,
                ],
                'surface_contract' => [
                    'required' => true,
                    'source' => 'KernelPipelineDevPlanBuilder',
                    'surface_must_not_decide' => true,
                    'provider_execution_blocked_until_runtime_migration' => true,
                    'runtime_execution_blocked_until_runtime_migration' => true,
                ],
                'routing' => [
                    'domain' => 'programming',
                    'flow' => $flow,
                    'runtime' => $flow === 'programming.forge' ? 'engineering_harness' : 'dev_repair_executor',
                ],
            ],
            'payload_hash' => hash('sha256', $eventId),
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    /**
     * @param  array<int,string>  $issues
     */
    private function recordSelfImprovementScheduleEvent(
        string $eventId,
        string $envelopeId,
        string $flow,
        string $healthStatus,
        string $schedulerStatus,
        array $issues,
        int $registeredCommandCount,
        int $invalidFlowCount = 0,
        string $planHash = 'plan-hash',
        mixed $occurredAt = null,
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
            'event_type' => LedgerEventType::SelfImprovementScheduleObserved->value,
            'emitter_stage' => 'atlas.self_improvement',
            'emitter_version' => 'self-improvement-runtime-v1',
            'payload' => [
                'flow' => $flow,
                'schedule_health' => [
                    'schema_version' => 1,
                    'status' => 'ok',
                    'health_status' => $healthStatus,
                    'issues' => $issues,
                    'enabled' => true,
                    'schedulable' => $schedulerStatus === 'registered',
                    'scheduler_registration' => [
                        'status' => $schedulerStatus,
                        'registered_command_count' => $registeredCommandCount,
                        'skipped_reason' => $schedulerStatus === 'skipped' ? ($issues[0] ?? 'not_schedulable') : null,
                    ],
                    'flow_count' => $registeredCommandCount,
                    'cadence_counts' => ['daily' => $registeredCommandCount],
                    'invalid_flow_count' => $invalidFlowCount,
                    'defaulted' => false,
                    'emit' => false,
                    'plan_hash' => $planHash,
                    'plan_hash_algorithm' => 'sha256',
                    'time' => '02:00',
                    'timezone' => 'America/Sao_Paulo',
                    'next_run_at' => '2026-05-05T05:00:00.000000Z',
                ],
            ],
            'payload_hash' => hash('sha256', $eventId),
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }
}
