<?php

namespace Tests\Unit\Ai\Kernel;

use App\Models\AiInboxItem;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Decision\DecisionReceiptHash;
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
        Schema::dropIfExists('ai_inbox_items');
        (require database_path('migrations/2026_04_30_152000_create_ai_inbox_items_table.php'))->up();
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        Schema::dropIfExists('ai_inbox_items');

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

    public function test_decision_receipt_report_projects_chain_integrity_for_envelope(): void
    {
        $first = $this->recordDecisionReceiptEvent(
            eventId: '01HDECISIONREPLAY0000000001',
            envelopeId: 'env_decision_replay',
            receiptId: 'receipt_decision_1',
            parentReceiptId: null,
            parentChainHash: null,
        );
        $second = $this->recordDecisionReceiptEvent(
            eventId: '01HDECISIONREPLAY0000000002',
            envelopeId: 'env_decision_replay',
            receiptId: 'receipt_decision_2',
            parentReceiptId: 'receipt_decision_1',
            parentChainHash: $first['chain_hash'],
        );
        $this->recordDecisionReceiptEvent(
            eventId: '01HDECISIONOTHER0000000001',
            envelopeId: 'other_env',
            receiptId: 'receipt_other',
            parentReceiptId: null,
            parentChainHash: null,
        );

        $report = app(AtlasLedgerReplayService::class)->decisionReceiptReportForEnvelope('env_decision_replay');

        $this->assertSame('env_decision_replay', $report['envelope_id']);
        $this->assertSame(2, $report['decision_event_count']);
        $this->assertSame(2, $report['valid_receipt_hash_count']);
        $this->assertSame(2, $report['valid_chain_hash_count']);
        $this->assertSame(0, $report['invalid_count']);
        $this->assertSame('receipt_decision_2', $report['latest_receipt_id']);
        $this->assertSame($second['chain_hash'], $report['latest_chain_hash']);
        $this->assertSame('ok', data_get($report, 'review_signal.status'));
        $this->assertSame('ok', data_get($report, 'events.0.receipt_integrity_status'));
        $this->assertSame('ok', data_get($report, 'events.1.chain_integrity_status'));
        $this->assertSame($first['chain_hash'], data_get($report, 'events.1.parent_chain_hash'));
    }

    public function test_decision_receipt_report_flags_hash_mismatch_for_review(): void
    {
        $this->recordDecisionReceiptEvent(
            eventId: '01HDECISIONBAD000000000001',
            envelopeId: 'env_decision_bad',
            receiptId: 'receipt_bad',
            parentReceiptId: null,
            parentChainHash: null,
            payloadOverrides: [
                'chain_hash' => 'tampered-chain-hash',
            ],
        );

        $report = app(AtlasLedgerReplayService::class)->decisionReceiptReportForEnvelope('env_decision_bad');

        $this->assertSame(1, $report['decision_event_count']);
        $this->assertSame(1, $report['valid_receipt_hash_count']);
        $this->assertSame(0, $report['valid_chain_hash_count']);
        $this->assertSame(1, $report['invalid_count']);
        $this->assertSame('breach', data_get($report, 'review_signal.status'));
        $this->assertSame('high', data_get($report, 'review_signal.severity'));
        $this->assertTrue((bool) data_get($report, 'review_signal.review_required'));
        $this->assertSame('open_reviewable_decision_receipt_replay_proposal', data_get($report, 'review_signal.recommended_action'));
        $this->assertContains('decision_receipt_chain_hash_mismatch', data_get($report, 'review_signal.reasons'));
        $this->assertSame('mismatch', data_get($report, 'events.0.chain_integrity_status'));
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
        $this->recordSelfImprovementCompletionEvent(
            eventId: '01HSCHEDDONE000000000001',
            envelopeId: 'self_improvement_run:warning',
            findingCount: 2,
            emittedInboxItemIds: ['00000000-0000-0000-0000-000000000123'],
        );
        AiInboxItem::unguarded(fn (): AiInboxItem => AiInboxItem::query()->create([
            'id' => '00000000-0000-0000-0000-000000000123',
            'user_id' => 'vitor',
            'type' => 'proposal',
            'category' => 'self_improvement',
            'severity' => 'warning',
            'status' => 'unread',
            'title' => 'Review recurring schedule warning',
            'summary' => 'Self-Improvement found schedule drift.',
            'source_type' => 'atlas_self_improvement',
            'source_id' => null,
            'initiator' => 'system',
            'payload' => [
                'proposal_contract' => [
                    'review_signal' => [
                        'status' => 'warning',
                        'severity' => 'medium',
                        'recommended_action' => 'review_schedule_repair',
                    ],
                ],
            ],
            'deep_link' => 'atlas://inbox/00000000-0000-0000-0000-000000000123',
        ]));
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
        $this->assertSame(1, $report['completed_count']);
        $this->assertSame(1, $report['emitted_count']);
        $this->assertSame(['00000000-0000-0000-0000-000000000123'], $report['emitted_inbox_item_ids']);
        $this->assertTrue((bool) $report['emitted_inbox_item_hydration_available']);
        $this->assertSame([], $report['emitted_inbox_item_missing_ids']);
        $this->assertSame('00000000-0000-0000-0000-000000000123', data_get($report, 'emitted_inbox_items.0.id'));
        $this->assertSame('unread', data_get($report, 'emitted_inbox_items.0.status'));
        $this->assertSame('Review recurring schedule warning', data_get($report, 'emitted_inbox_items.0.title'));
        $this->assertSame('atlas://inbox/00000000-0000-0000-0000-000000000123', data_get($report, 'emitted_inbox_items.0.deep_link'));
        $this->assertSame('review_schedule_repair', data_get($report, 'emitted_inbox_items.0.review_signal.recommended_action'));
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
        $this->assertTrue((bool) $report['recent_events'][0]['completed']);
        $this->assertSame(2, $report['recent_events'][0]['finding_count']);
        $this->assertSame(1, $report['recent_events'][0]['emitted_count']);
        $this->assertSame(['00000000-0000-0000-0000-000000000123'], $report['recent_events'][0]['emitted_inbox_item_ids']);
        $this->assertTrue((bool) $report['recent_events'][0]['emitted_inbox_item_hydration_available']);
        $this->assertSame([], $report['recent_events'][0]['emitted_inbox_item_missing_ids']);
        $this->assertSame('00000000-0000-0000-0000-000000000123', data_get($report, 'recent_events.0.emitted_inbox_items.0.id'));
        $this->assertSame('Review recurring schedule warning', data_get($report, 'recent_events.0.emitted_inbox_items.0.title'));
    }

    public function test_self_improvement_schedule_window_report_exposes_missing_inbox_refs(): void
    {
        $this->recordSelfImprovementScheduleEvent(
            eventId: '01HSCHEDMISS000000000001',
            envelopeId: 'self_improvement_run:missing_inbox',
            flow: 'self_improvement.weekly_architecture_audit',
            healthStatus: 'warning',
            schedulerStatus: 'registered',
            issues: ['missing_inbox_ref'],
            registeredCommandCount: 1,
        );
        $this->recordSelfImprovementCompletionEvent(
            eventId: '01HSCHEDMISSDONE00000001',
            envelopeId: 'self_improvement_run:missing_inbox',
            findingCount: 1,
            emittedInboxItemIds: ['00000000-0000-0000-0000-000000000999'],
        );

        $report = app(AtlasLedgerReplayService::class)->selfImprovementScheduleReportForWindow(now()->subHour(), now()->addMinute());

        $this->assertTrue((bool) $report['emitted_inbox_item_hydration_available']);
        $this->assertSame(['00000000-0000-0000-0000-000000000999'], $report['emitted_inbox_item_missing_ids']);
        $this->assertSame([], $report['emitted_inbox_items']);
        $this->assertSame(['00000000-0000-0000-0000-000000000999'], data_get($report, 'recent_events.0.emitted_inbox_item_missing_ids'));
    }

    public function test_inbox_action_window_report_projects_human_review_evidence(): void
    {
        $this->recordInboxActionEvent(
            eventId: '01HINBOXACTION000000000001',
            inboxItemId: 'inbox-action-1',
            action: 'review_patch',
            actorType: 'operator_cli',
            category: 'self_improvement',
            severity: 'medium',
            recommendedAction: 'review_schedule_repair',
            diffRefs: [['path' => 'app/Services/Ai/Mobile/MobilePushService.php']],
        );
        $this->recordInboxActionEvent(
            eventId: '01HINBOXACTION000000000002',
            inboxItemId: 'inbox-action-2',
            action: 'mark_read',
            actorType: 'mobile_device',
            category: 'ops',
            severity: 'low',
            recommendedAction: 'none',
        );
        $this->recordInboxActionEvent(
            eventId: '01HINBOXACTION000000000003',
            inboxItemId: 'inbox-old',
            action: 'review_patch',
            actorType: 'operator_cli',
            category: 'self_improvement',
            severity: 'high',
            recommendedAction: 'review_old',
            occurredAt: now()->subDays(2),
        );

        $report = app(AtlasLedgerReplayService::class)->inboxActionReportForWindow(now()->subHour(), now()->addMinute());

        $this->assertTrue($report['available']);
        $this->assertSame(2, $report['inbox_action_count']);
        $this->assertSame(2, $report['envelope_count']);
        $this->assertSame(['review_patch' => 1, 'mark_read' => 1], $report['action_counts']);
        $this->assertSame(['operator_cli' => 1, 'mobile_device' => 1], $report['actor_type_counts']);
        $this->assertSame(['self_improvement' => 1, 'ops' => 1], $report['category_counts']);
        $this->assertSame(1, $report['reviewed_patch_count']);
        $this->assertSame(1, $report['with_diff_refs_count']);
        $this->assertSame('ok', data_get($report, 'review_signal.status'));
        $this->assertSame('none', data_get($report, 'review_signal.recommended_action'));
        $this->assertSame('mark_read', $report['recent_events'][0]['action']);
        $this->assertSame('review_patch', data_get($report, 'recent_events.1.result_action'));
        $this->assertSame(1, data_get($report, 'recent_events.1.diff_ref_count'));
    }

    public function test_inbox_action_window_report_filters_and_warns_when_patch_review_lacks_diff_refs(): void
    {
        $this->recordInboxActionEvent(
            eventId: '01HINBOXACTIONFILTER000001',
            inboxItemId: 'inbox-action-filter-1',
            action: 'review_patch',
            actorType: 'operator_cli',
            category: 'self_improvement',
            severity: 'high',
            recommendedAction: 'review_without_patch',
        );
        $this->recordInboxActionEvent(
            eventId: '01HINBOXACTIONFILTER000002',
            inboxItemId: 'inbox-action-filter-2',
            action: 'mark_read',
            actorType: 'mobile_device',
            category: 'ops',
            severity: 'low',
            recommendedAction: 'none',
        );

        $report = app(AtlasLedgerReplayService::class)->inboxActionReportForWindow(
            now()->subHour(),
            now()->addMinute(),
            ['action' => 'review_patch', 'actor_type' => 'operator_cli'],
        );

        $this->assertTrue($report['available']);
        $this->assertSame(['action' => 'review_patch', 'actor_type' => 'operator_cli'], $report['filters']);
        $this->assertSame(1, $report['inbox_action_count']);
        $this->assertSame(1, $report['reviewed_patch_count']);
        $this->assertSame(0, $report['with_diff_refs_count']);
        $this->assertSame('warning', data_get($report, 'review_signal.status'));
        $this->assertSame('medium', data_get($report, 'review_signal.severity'));
        $this->assertTrue((bool) data_get($report, 'review_signal.review_required'));
        $this->assertSame('open_reviewable_inbox_action_evidence_proposal', data_get($report, 'review_signal.recommended_action'));
        $this->assertContains('review_patch_action_without_diff_refs', data_get($report, 'review_signal.reasons'));
        $this->assertSame('inbox-action-filter-1', $report['recent_events'][0]['inbox_item_id']);
    }

    public function test_inbox_action_window_report_projects_rivals_review_scores(): void
    {
        $this->recordInboxActionEvent(
            eventId: '01HINBOXACTIONRIVALS00001',
            inboxItemId: 'inbox-rivals-review-1',
            action: 'record_rivals_review',
            actorType: 'operator_cli',
            category: 'self_improvement',
            severity: 'medium',
            recommendedAction: 'record_due_rivals_strategy_reviews',
            rivalsReviewAction: [
                'schema_version' => 'atlas.inbox_action.rivals_review.v1',
                'recorded_review_id' => 'review-rivals-1',
                'case_id' => 'case-rivals-1',
                'horizon_days' => 30,
                'scores' => [
                    'regret' => 9,
                    'alignment' => 92,
                    'agency' => 88,
                ],
                'remaining_due_review_count' => 0,
            ],
        );

        $report = app(AtlasLedgerReplayService::class)->inboxActionReportForWindow(
            now()->subHour(),
            now()->addMinute(),
            ['action' => 'record_rivals_review'],
        );

        $this->assertTrue($report['available']);
        $this->assertSame(1, $report['inbox_action_count']);
        $this->assertSame(1, $report['rivals_review_recorded_count']);
        $this->assertSame(1, $report['rivals_review_with_scores_count']);
        $this->assertSame(['record_rivals_review' => 1], $report['action_counts']);
        $this->assertSame(['record_due_rivals_strategy_reviews' => 1], $report['recommended_action_counts']);
        $this->assertSame('ok', data_get($report, 'review_signal.status'));
        $this->assertSame('none', data_get($report, 'review_signal.recommended_action'));
        $this->assertContains('rivals_strategy_human_scores_recorded', data_get($report, 'review_signal.reasons'));
        $this->assertSame('atlas.inbox_action.rivals_review.v1', data_get($report, 'recent_events.0.rivals_review_schema_version'));
        $this->assertSame('review-rivals-1', data_get($report, 'recent_events.0.rivals_review_id'));
        $this->assertSame(9, data_get($report, 'recent_events.0.rivals_regret_score'));
        $this->assertSame(92, data_get($report, 'recent_events.0.rivals_alignment_score'));
        $this->assertSame(88, data_get($report, 'recent_events.0.rivals_agency_score'));
    }

    public function test_inbox_action_window_report_projects_provider_cost_rate_actions(): void
    {
        $this->recordInboxActionEvent(
            eventId: '01HINBOXACTIONCOSTRATE001',
            inboxItemId: 'inbox-provider-cost-rate-1',
            action: 'configure_provider_cost_rates',
            actorType: 'operator_cli',
            category: 'self_improvement',
            severity: 'medium',
            recommendedAction: 'configure_provider_cost_rates',
            providerCostRateAction: [
                'schema_version' => 'atlas.inbox_action.provider_cost_rates.v1',
                'provider' => 'codex_cli',
                'model' => 'gpt-5.2',
                'input_microusd_per_1k' => 120,
                'output_microusd_per_1k' => 480,
                'currency' => 'USD',
                'effective_from' => '2026-05-06T00:00:00Z',
                'effective_until' => null,
                'applied' => true,
            ],
            upsertedRate: [
                'id' => 77,
                'provider' => 'codex_cli',
                'model' => 'gpt-5.2',
            ],
        );

        $report = app(AtlasLedgerReplayService::class)->inboxActionReportForWindow(
            now()->subHour(),
            now()->addMinute(),
            ['action' => 'configure_provider_cost_rates'],
        );

        $this->assertTrue($report['available']);
        $this->assertSame(1, $report['inbox_action_count']);
        $this->assertSame(1, $report['provider_cost_rate_action_count']);
        $this->assertSame(1, $report['provider_cost_rate_applied_count']);
        $this->assertSame(['codex_cli' => 1], $report['provider_cost_rate_provider_counts']);
        $this->assertSame(['codex_cli:gpt-5.2' => 1], $report['provider_cost_rate_model_counts']);
        $this->assertSame('ok', data_get($report, 'review_signal.status'));
        $this->assertSame('none', data_get($report, 'review_signal.recommended_action'));
        $this->assertContains('provider_cost_rates_configured', data_get($report, 'review_signal.reasons'));
        $this->assertSame('atlas.inbox_action.provider_cost_rates.v1', data_get($report, 'recent_events.0.provider_cost_rate_schema_version'));
        $this->assertSame('codex_cli', data_get($report, 'recent_events.0.provider_cost_rate_provider'));
        $this->assertSame('gpt-5.2', data_get($report, 'recent_events.0.provider_cost_rate_model'));
        $this->assertSame(120, data_get($report, 'recent_events.0.provider_cost_rate_input_microusd'));
        $this->assertSame(480, data_get($report, 'recent_events.0.provider_cost_rate_output_microusd'));
        $this->assertTrue((bool) data_get($report, 'recent_events.0.provider_cost_rate_applied'));
        $this->assertSame(77, data_get($report, 'recent_events.0.provider_cost_rate_id'));
    }

    public function test_inbox_action_window_report_warns_when_provider_cost_rate_action_is_only_previewed(): void
    {
        $this->recordInboxActionEvent(
            eventId: '01HINBOXACTIONCOSTRATE002',
            inboxItemId: 'inbox-provider-cost-rate-preview',
            action: 'configure_provider_cost_rates',
            actorType: 'operator_cli',
            category: 'self_improvement',
            severity: 'medium',
            recommendedAction: 'configure_provider_cost_rates',
            providerCostRateAction: [
                'schema_version' => 'atlas.inbox_action.provider_cost_rates.v1',
                'provider' => 'claude_cli',
                'model' => 'opus',
                'input_microusd_per_1k' => null,
                'output_microusd_per_1k' => null,
                'currency' => 'USD',
                'applied' => false,
            ],
        );

        $report = app(AtlasLedgerReplayService::class)->inboxActionReportForWindow(
            now()->subHour(),
            now()->addMinute(),
            ['action' => 'configure_provider_cost_rates'],
        );

        $this->assertSame(1, $report['provider_cost_rate_action_count']);
        $this->assertSame(0, $report['provider_cost_rate_applied_count']);
        $this->assertSame('warning', data_get($report, 'review_signal.status'));
        $this->assertSame('configure_provider_cost_rates', data_get($report, 'review_signal.recommended_action'));
        $this->assertContains('configure_provider_cost_rates_action_without_applied_rate', data_get($report, 'review_signal.reasons'));
    }

    public function test_inbox_action_window_report_keeps_provider_cost_rate_warning_in_mixed_windows(): void
    {
        $this->recordInboxActionEvent(
            eventId: '01HINBOXACTIONMIXEDRIVALS01',
            inboxItemId: 'inbox-mixed-rivals-review',
            action: 'record_rivals_review',
            actorType: 'operator_cli',
            category: 'self_improvement',
            severity: 'medium',
            recommendedAction: 'record_due_rivals_strategy_reviews',
            rivalsReviewAction: [
                'schema_version' => 'atlas.inbox_action.rivals_review.v1',
                'recorded_review_id' => 'review-mixed-rivals',
                'case_id' => 'case-mixed-rivals',
                'scores' => [
                    'regret' => 4,
                    'alignment' => 95,
                    'agency' => 90,
                ],
            ],
        );
        $this->recordInboxActionEvent(
            eventId: '01HINBOXACTIONMIXEDCOST001',
            inboxItemId: 'inbox-mixed-provider-cost-rate-preview',
            action: 'configure_provider_cost_rates',
            actorType: 'operator_cli',
            category: 'self_improvement',
            severity: 'medium',
            recommendedAction: 'configure_provider_cost_rates',
            providerCostRateAction: [
                'schema_version' => 'atlas.inbox_action.provider_cost_rates.v1',
                'provider' => 'codex_cli',
                'model' => 'gpt-5.5',
                'input_microusd_per_1k' => null,
                'output_microusd_per_1k' => null,
                'currency' => 'USD',
                'applied' => false,
            ],
        );

        $report = app(AtlasLedgerReplayService::class)->inboxActionReportForWindow(now()->subHour(), now()->addMinute());

        $this->assertSame(1, $report['rivals_review_with_scores_count']);
        $this->assertSame(1, $report['provider_cost_rate_action_count']);
        $this->assertSame(0, $report['provider_cost_rate_applied_count']);
        $this->assertSame('warning', data_get($report, 'review_signal.status'));
        $this->assertSame('configure_provider_cost_rates', data_get($report, 'review_signal.recommended_action'));
        $this->assertContains('configure_provider_cost_rates_action_without_applied_rate', data_get($report, 'review_signal.reasons'));
    }

    /**
     * @param  array<string,mixed>  $payloadOverrides
     * @return array<string,mixed>
     */
    private function recordDecisionReceiptEvent(
        string $eventId,
        string $envelopeId,
        string $receiptId,
        ?string $parentReceiptId,
        ?string $parentChainHash,
        array $payloadOverrides = [],
        mixed $occurredAt = null,
    ): array {
        $payload = [
            'envelope_id' => $envelopeId,
            'receipt_id' => $receiptId,
            'schema_version' => 'atlas.decide.v2',
            'issued_at' => '2026-05-05T12:00:00.000000Z',
            'expires_at' => '2026-05-05T12:01:00.000000Z',
            'dry_run' => false,
            'signed_by' => 'atlas.decide.v2',
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'risk' => 'medium',
            'provider_selection' => [
                'primary' => 'codex_cli',
                'model' => 'gpt-5.5',
                'fallbacks' => [],
            ],
            'budgets' => [],
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
        $payload = array_replace_recursive($payload, $payloadOverrides);

        AtlasLedgerEvent::query()->create([
            'event_id' => $eventId,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_test',
            'operator_id' => 'operator_test',
            'envelope_id' => $envelopeId,
            'receipt_id' => $receiptId,
            'trace_id' => null,
            'correlation_id' => $envelopeId,
            'causation_id' => null,
            'event_type' => LedgerEventType::DecisionIssued->value,
            'emitter_stage' => 'atlas.decide',
            'emitter_version' => 'atlas-decide-v2',
            'payload' => $payload,
            'payload_hash' => hash('sha256', $eventId),
            'occurred_at' => $occurredAt ?? now(),
        ]);

        return $payload;
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
     * @param  array<int,array<string,mixed>>  $diffRefs
     */
    private function recordInboxActionEvent(
        string $eventId,
        string $inboxItemId,
        string $action,
        string $actorType,
        string $category,
        string $severity,
        string $recommendedAction,
        array $diffRefs = [],
        array $rivalsReviewAction = [],
        array $providerCostRateAction = [],
        array $upsertedRate = [],
        mixed $occurredAt = null,
    ): void {
        AtlasLedgerEvent::query()->create([
            'event_id' => $eventId,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_test',
            'operator_id' => $actorType,
            'envelope_id' => 'inbox_item:'.$inboxItemId,
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => $inboxItemId,
            'causation_id' => null,
            'event_type' => LedgerEventType::InboxActionRecorded->value,
            'emitter_stage' => 'atlas.inbox',
            'emitter_version' => 'atlas.inbox_action.v1',
            'payload' => [
                'schema_version' => 'atlas.inbox_action.v1',
                'action' => $action,
                'idempotency_key' => 'idem-'.$eventId,
                'inbox_item' => [
                    'id' => $inboxItemId,
                    'type' => 'proposal',
                    'category' => $category,
                    'severity' => $severity,
                    'status' => 'read',
                    'source_type' => 'self_improvement',
                    'source_id' => 'finding-'.$inboxItemId,
                    'dedupe_key' => 'dedupe-'.$inboxItemId,
                ],
                'actor' => [
                    'type' => $actorType,
                    'id' => $actorType === 'mobile_device' ? 'device-1' : null,
                ],
                'result' => [
                    'payload' => [
                        'action' => $action,
                        'diff_refs' => $diffRefs,
                    ],
                    'rivals_review_action' => $rivalsReviewAction,
                    'provider_cost_rate_action' => $providerCostRateAction,
                    'upserted_rate' => $upsertedRate,
                ],
                'proposal_contract' => [
                    'review_signal' => [
                        'status' => $severity === 'high' ? 'warning' : 'ok',
                        'severity' => $severity,
                        'recommended_action' => $recommendedAction,
                    ],
                    'diff_refs' => $diffRefs,
                ],
                'review_signal' => [
                    'status' => $severity === 'high' ? 'warning' : 'ok',
                    'severity' => $severity,
                    'recommended_action' => $recommendedAction,
                ],
                'recommended_action' => $recommendedAction,
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

    /**
     * @param  array<int,string>  $emittedInboxItemIds
     */
    private function recordSelfImprovementCompletionEvent(
        string $eventId,
        string $envelopeId,
        int $findingCount,
        array $emittedInboxItemIds,
        mixed $occurredAt = null,
    ): void {
        AtlasLedgerEvent::query()->create([
            'event_id' => $eventId,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_test',
            'operator_id' => 'atlas_self_improvement',
            'envelope_id' => $envelopeId,
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => $envelopeId,
            'causation_id' => null,
            'event_type' => LedgerEventType::OperationCompleted->value,
            'emitter_stage' => 'atlas.self_improvement',
            'emitter_version' => 'self-improvement-runtime-v1',
            'payload' => [
                'flow' => 'self_improvement.weekly_architecture_audit',
                'finding_count' => $findingCount,
                'emitted_count' => count($emittedInboxItemIds),
                'emitted_inbox_item_ids' => $emittedInboxItemIds,
            ],
            'payload_hash' => hash('sha256', $eventId),
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }
}
