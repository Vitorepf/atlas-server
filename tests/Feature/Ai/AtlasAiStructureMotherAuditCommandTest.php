<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\AtlasMemoryQualityService;
use App\Services\Ai\Capture\CaptureInboxPipelineReadModel;
use App\Services\Ai\Kernel\Architecture\AtlasQualitativeLevelsReadModel;
use App\Services\Ai\Kernel\Architecture\AtlasRivalsStrategyReadModel;
use App\Services\Ai\Kernel\Architecture\AtlasStructureMotherAuditReadModel;
use App\Services\Ai\Mobile\ProactiveLayerReadModel;
use App\Services\Ai\Runtime\ToolActionRuntimeReadModel;
use App\Services\Ai\Scheduling\LongRunningWorkReadModel;
use App\Services\Ai\Tasks\TaskOrchestrationReadModel;
use App\Services\Ai\Telemetry\AiProviderCostRateService;
use App\Services\Engineering\EngineeringDocumentationHealthService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class AtlasAiStructureMotherAuditCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createOpenBrainTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_open_brain_access_logs');
        Schema::dropIfExists('atlas_engineering_code_modules');
        Schema::dropIfExists('atlas_engineering_knowledge_items');
        Mockery::close();

        parent::tearDown();
    }

    public function test_command_reports_blocked_when_rivals_and_proactive_human_review_are_not_ready(): void
    {
        $this->bindAuditService(rivalsReady: false, criticalInsights: 2);

        $exit = Artisan::call('atlas:ai:structure-mother-audit', [
            '--hours' => 720,
            '--workspace' => base_path(),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.structure_mother_audit.v1', data_get($payload, 'structure_mother_audit.schema_version'));
        $this->assertSame('operational_blocked', data_get($payload, 'structure_mother_audit.status'));
        $this->assertFalse(data_get($payload, 'structure_mother_audit.complete'));
        $this->assertTrue(data_get($payload, 'structure_mother_audit.implementation_complete'));
        $this->assertFalse(data_get($payload, 'structure_mother_audit.completion_gate.update_goal_allowed'));
        $this->assertSame(8, data_get($payload, 'structure_mother_audit.summary.ready_count'));
        $this->assertSame(0, data_get($payload, 'structure_mother_audit.summary.blocked_count'));
        $this->assertContains('waiting_for_real_scored_revisit', collect(data_get($payload, 'structure_mother_audit.blockers'))->pluck('blocker')->all());
        $this->assertContains('critical_proactive_insights_require_operator_review', collect(data_get($payload, 'structure_mother_audit.blockers'))->pluck('blocker')->all());
        $this->assertTrue(data_get($payload, 'structure_mother_audit.rules.self_construction_control_plane_excluded'));
        $this->assertCount(11, data_get($payload, 'structure_mother_audit.completion_checklist'));
        $completionGate = collect(data_get($payload, 'structure_mother_audit.completion_checklist'))->firstWhere('artifact', 'structure_mother_audit.completion_gate');
        $this->assertSame(
            'Goal completion may be marked only when all eight modules are ready and operational blockers are clear',
            data_get($completionGate, 'requirement'),
        );
        $this->assertSame('blocked', data_get($completionGate, 'status'));
        $this->assertFalse(data_get($completionGate, 'evidence.update_goal_allowed'));
        $this->assertSame(
            'atlas.structure_mother.prompt_to_artifact_checklist.v1',
            data_get($payload, 'structure_mother_audit.prompt_to_artifact_checklist.schema_version'),
        );
        $this->assertCount(8, data_get($payload, 'structure_mother_audit.prompt_to_artifact_checklist.modules'));
        $this->assertFalse(data_get($payload, 'structure_mother_audit.prompt_to_artifact_checklist.result.complete'));
        $this->assertContains(
            'php artisan atlas:ai:architecture-validate --json',
            data_get($payload, 'structure_mother_audit.prompt_to_artifact_checklist.validation_commands'),
        );
        $namedFiles = collect(data_get($payload, 'structure_mother_audit.prompt_to_artifact_checklist.named_files'));
        $this->assertTrue($namedFiles->every(fn (array $file): bool => $file['status'] === 'covered'));
        $this->assertSame(
            'covered',
            data_get($payload, 'structure_mother_audit.prompt_to_artifact_checklist.rules.0.status'),
        );
        $this->assertSame('pending_operator_or_calendar_action', data_get($payload, 'structure_mother_audit.operator_action_plan.status'));
        $this->assertSame('atlas.structure_mother.enterprise_closure_plan.v1', data_get($payload, 'structure_mother_audit.enterprise_closure_plan.schema_version'));
        $this->assertFalse(data_get($payload, 'structure_mother_audit.enterprise_closure_plan.completion_claim_allowed'));
        $closureItems = collect(data_get($payload, 'structure_mother_audit.enterprise_closure_plan.items'));
        $this->assertContains('rivals_p4_real_review', $closureItems->pluck('id')->all());
        $this->assertContains('rivals_programming_real_battery', $closureItems->pluck('id')->all());
        $this->assertContains('frontend_design_harness_enterprise_runs', $closureItems->pluck('id')->all());
        $this->assertContains('p6_p7_advanced_readiness', $closureItems->pluck('id')->all());
        $batteryAction = $closureItems->firstWhere('id', 'rivals_programming_real_battery');
        $this->assertTrue(data_get($batteryAction, 'external_cost_possible'));
        $this->assertContains('operator_cost_acknowledged', data_get($batteryAction, 'required_evidence'));
        $this->assertContains('same_model', data_get($batteryAction, 'allowed_modes'));
        $this->assertSame(
            'atlas.structure_mother.rivals_programming_execution_guard.v1',
            data_get($batteryAction, 'execution_guard.schema_version'),
        );
        $this->assertTrue(data_get($batteryAction, 'execution_guard.no_provider_call_in_structure_mother_audit'));
        $this->assertStringContainsString(
            'rivals runbook',
            data_get($batteryAction, 'safe_preflight_commands.runbook'),
        );
        $this->assertStringContainsString(
            '--confirm-provider-cost',
            data_get($batteryAction, 'cost_acknowledged_execution_commands.quick'),
        );
        $frontendAction = $closureItems->firstWhere('id', 'frontend_design_harness_enterprise_runs');
        $this->assertSame(
            'atlas.structure_mother.frontend_design_harness_execution_guard.v1',
            data_get($frontendAction, 'execution_guard.schema_version'),
        );
        $this->assertTrue(data_get($frontendAction, 'execution_guard.provider_neutral'));
        $this->assertTrue(data_get($frontendAction, 'execution_guard.screenshot_alone_is_insufficient'));
        $this->assertStringContainsString(
            'atlas:engineering:visual-smoke',
            data_get($frontendAction, 'local_execution_commands.visual_smoke_dry_run'),
        );
        $this->assertTrue(data_get($payload, 'structure_mother_audit.enterprise_closure_plan.rules.do_not_loop_on_calendar_blockers'));
        $this->assertTrue(data_get($payload, 'structure_mother_audit.enterprise_closure_plan.rules.do_not_spend_external_provider_cost_without_operator_approval'));
        $this->assertSame(5, data_get($payload, 'structure_mother_audit.operator_action_plan.action_count'));
        $this->assertSame('atlas.structure_mother.operator_action_summary.v1', data_get($payload, 'structure_mother_audit.operator_action_plan.action_summary.schema_version'));
        $this->assertSame('operator_action_available_now', data_get($payload, 'structure_mother_audit.operator_action_plan.action_summary.status'));
        $this->assertSame(4, data_get($payload, 'structure_mother_audit.operator_action_plan.action_summary.actionable_now_count'));
        $this->assertSame(1, data_get($payload, 'structure_mother_audit.operator_action_plan.action_summary.calendar_wait_count'));
        $this->assertContains('review_critical_proactive_insights', data_get($payload, 'structure_mother_audit.operator_action_plan.action_summary.next_action_ids'));
        $this->assertContains('record_real_rivals_review_when_due', collect(data_get($payload, 'structure_mother_audit.operator_action_plan.actions'))->pluck('id')->all());
        $this->assertContains('approve_rivals_programming_real_battery', collect(data_get($payload, 'structure_mother_audit.operator_action_plan.actions'))->pluck('id')->all());
        $this->assertContains('run_frontend_design_harness_enterprise_receipts', collect(data_get($payload, 'structure_mother_audit.operator_action_plan.actions'))->pluck('id')->all());
        $this->assertContains('review_critical_proactive_insights', collect(data_get($payload, 'structure_mother_audit.operator_action_plan.actions'))->pluck('id')->all());
        $this->assertContains('configure_missing_provider_cost_rates', collect(data_get($payload, 'structure_mother_audit.operator_action_plan.actions'))->pluck('id')->all());
        $rivalsAction = collect(data_get($payload, 'structure_mother_audit.operator_action_plan.actions'))->firstWhere('id', 'record_real_rivals_review_when_due');
        $this->assertSame('/ai/rivals-strategy/review', data_get($rivalsAction, 'api.endpoint'));
        $this->assertSame('POST', data_get($rivalsAction, 'api.method'));
        $this->assertFalse(data_get($rivalsAction, 'actionable_now'));
        $this->assertTrue(data_get($rivalsAction, 'calendar_wait_required'));
        $this->assertFalse(data_get($rivalsAction, 'api.synthetic_scores_allowed'));
        $batteryOperatorAction = collect(data_get($payload, 'structure_mother_audit.operator_action_plan.actions'))->firstWhere('id', 'approve_rivals_programming_real_battery');
        $this->assertTrue(data_get($batteryOperatorAction, 'actionable_now'));
        $this->assertTrue(data_get($batteryOperatorAction, 'external_provider_cost_possible'));
        $this->assertFalse(data_get($batteryOperatorAction, 'agent_auto_execute_allowed'));
        $this->assertStringContainsString('rivals runbook', data_get($batteryOperatorAction, 'safe_preflight_commands.runbook'));
        $this->assertStringContainsString('--confirm-provider-cost', data_get($batteryOperatorAction, 'cost_acknowledged_execution_commands.quick'));
        $frontendOperatorAction = collect(data_get($payload, 'structure_mother_audit.operator_action_plan.actions'))->firstWhere('id', 'run_frontend_design_harness_enterprise_receipts');
        $this->assertTrue(data_get($frontendOperatorAction, 'actionable_now'));
        $this->assertFalse(data_get($frontendOperatorAction, 'external_provider_cost_possible'));
        $this->assertTrue(data_get($frontendOperatorAction, 'can_be_deferred_by_operator_decision'));
        $this->assertContains('design_5d_review', data_get($frontendOperatorAction, 'required_receipts'));
        $this->assertTrue(data_get($rivalsAction, 'api.review_due_at_required'));
        $this->assertSame('atlas.rivals_strategy.review_recording.v1', data_get($rivalsAction, 'api.recording_schema_version'));
        $proactiveAction = collect(data_get($payload, 'structure_mother_audit.operator_action_plan.actions'))->firstWhere('id', 'review_critical_proactive_insights');
        $this->assertSame('php artisan atlas:cli:inbox review-critical', data_get($proactiveAction, 'review_command'));
        $this->assertSame('php artisan atlas:cli:inbox review-critical --json', data_get($proactiveAction, 'review_command_json'));
        $this->assertStringContainsString('atlas:cli:inbox show', data_get($proactiveAction, 'item_commands.0.show'));
        $this->assertTrue(data_get($proactiveAction, 'actionable_now'));
        $this->assertFalse(data_get($proactiveAction, 'calendar_wait_required'));
        $this->assertSame('/v1/mobile/inbox/critical-review', data_get($proactiveAction, 'api.review.endpoint'));
        $this->assertSame('/v1/mobile/inbox/{inbox_item_id}/respond', data_get($proactiveAction, 'api.respond.endpoint_template'));
        $this->assertSame('inbox.action.completed', data_get($proactiveAction, 'api.respond.receipt_event_type'));
        $this->assertSame('atlas.inbox_action.receipt.v1', data_get($proactiveAction, 'api.respond.ledger_schema_version'));
        $this->assertFalse(data_get($proactiveAction, 'api.agent_auto_resolve_allowed'));
        $costRateAction = collect(data_get($payload, 'structure_mother_audit.operator_action_plan.actions'))->firstWhere('id', 'configure_missing_provider_cost_rates');
        $this->assertTrue(data_get($costRateAction, 'agent_may_not_infer_prices'));
        $this->assertStringContainsString('--input-microusd=<current_input_microusd_per_1k>', data_get($costRateAction, 'missing_rates.0.configure_command'));
        $this->assertTrue(data_get($payload, 'structure_mother_audit.operator_action_plan.rules.agent_may_not_fabricate_rivals_scores'));
        $this->assertTrue(data_get($payload, 'structure_mother_audit.operator_action_plan.rules.agent_may_not_infer_provider_prices'));
    }

    public function test_command_marks_completion_gate_passed_only_when_all_modules_are_ready(): void
    {
        $this->bindAuditService(rivalsReady: true, criticalInsights: 0);

        Artisan::call('atlas:ai:structure-mother-audit', [
            '--hours' => 720,
            '--workspace' => base_path(),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('complete', data_get($payload, 'structure_mother_audit.status'));
        $this->assertTrue(data_get($payload, 'structure_mother_audit.complete'));
        $this->assertTrue(data_get($payload, 'structure_mother_audit.completion_gate.update_goal_allowed'));
        $this->assertSame(8, data_get($payload, 'structure_mother_audit.summary.ready_count'));
        $this->assertSame([], data_get($payload, 'structure_mother_audit.blockers'));
        $this->assertTrue(collect(data_get($payload, 'structure_mother_audit.completion_checklist'))->every(fn (array $item): bool => (bool) $item['passed']));
        $this->assertTrue(data_get($payload, 'structure_mother_audit.prompt_to_artifact_checklist.result.complete'));
        $this->assertTrue(data_get($payload, 'structure_mother_audit.prompt_to_artifact_checklist.result.update_goal_allowed'));
        $this->assertSame('clear', data_get($payload, 'structure_mother_audit.operator_action_plan.status'));
        $this->assertSame(0, data_get($payload, 'structure_mother_audit.operator_action_plan.action_count'));
    }

    public function test_operator_action_plan_includes_pending_push_replay_when_delivery_attempts_are_missing(): void
    {
        $this->bindAuditService(rivalsReady: false, criticalInsights: 1, pushReplayPending: true);

        Artisan::call('atlas:ai:structure-mother-audit', [
            '--hours' => 720,
            '--workspace' => base_path(),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $actions = collect(data_get($payload, 'structure_mother_audit.operator_action_plan.actions'));
        $pushAction = $actions->firstWhere('id', 'replay_pending_mobile_push_dispatches');

        $this->assertContains('push_requested_without_delivery_attempt', collect(data_get($payload, 'structure_mother_audit.blockers'))->pluck('blocker')->all());
        $this->assertSame('operator_push_replay', data_get($pushAction, 'type'));
        $this->assertFalse(data_get($pushAction, 'agent_auto_dispatch_allowed'));
        $this->assertTrue(data_get($pushAction, 'external_notification_possible'));
        $this->assertSame('php artisan atlas:cli:mobile replay-push --json', data_get($pushAction, 'dry_run_command'));
        $this->assertSame("php artisan atlas:cli:mobile replay-push --apply --confirm-external-dispatch --reason='<operator evidence summary>' --json", data_get($pushAction, 'apply_command'));
        $this->assertSame("php artisan atlas:cli:mobile replay-push --apply --confirm-external-dispatch --reason='<operator evidence summary>' --json", data_get($pushAction, 'canonical_apply_command'));
        $this->assertSame('POST', data_get($pushAction, 'api.method'));
        $this->assertSame('/ai/mobile/push/replay', data_get($pushAction, 'api.endpoint'));
        $this->assertTrue(data_get($pushAction, 'api.prior_dry_run_required'));
        $this->assertTrue(data_get($pushAction, 'api.apply_requires_prior_dry_run'));
        $this->assertSame(15, data_get($pushAction, 'api.prior_dry_run_max_age_minutes'));
        $this->assertTrue(data_get($pushAction, 'api.prior_dry_run_candidate_required'));
        $this->assertTrue(data_get($pushAction, 'api.confirmation_required'));
        $this->assertTrue(data_get($pushAction, 'api.operator_reason_required'));
        $this->assertSame('mobile.push_replay.requested', data_get($pushAction, 'api.receipt_event_type'));
        $this->assertFalse(data_get($pushAction, 'api.raw_push_tokens_exposed'));
        $this->assertSame(1, data_get($pushAction, 'diagnostics.push_token_device_count'));
    }

    public function test_human_output_lists_operator_action_plan_without_json(): void
    {
        $this->bindAuditService(rivalsReady: false, criticalInsights: 1, pushReplayPending: true);

        $exit = Artisan::call('atlas:ai:structure-mother-audit', [
            '--hours' => 720,
            '--workspace' => base_path(),
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Structure Mother Audit', $output);
        $this->assertStringContainsString('Operator actions', $output);
        $this->assertStringContainsString('Actionable now', $output);
        $this->assertStringContainsString('review_critical_proactive_insights', $output);
        $this->assertStringContainsString('php artisan atlas:cli:inbox review-critical', $output);
        $this->assertStringContainsString('replay_pending_mobile_push_dispatches', $output);
        $this->assertStringContainsString('php artisan atlas:cli:mobile replay-push --json', $output);
        $this->assertStringContainsString('record_real_rivals_review_when_due', $output);
        $this->assertStringContainsString('Safety rules', $output);
        $this->assertStringNotContainsString('"structure_mother_audit"', $output);
    }

    public function test_api_exposes_structure_mother_audit_operator_action_plan(): void
    {
        config()->set('atlas.token', 'testing-atlas-token-with-enough-length');
        $this->bindAuditService(rivalsReady: false, criticalInsights: 1, pushReplayPending: true);

        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->getJson('/ai/structure-mother-audit?hours=720&workspace='.urlencode(base_path()))
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('structure_mother_audit.schema_version', 'atlas.structure_mother_audit.v1')
            ->assertJsonPath('structure_mother_audit.operator_action_plan.schema_version', 'atlas.structure_mother.operator_action_plan.v1')
            ->assertJsonPath('structure_mother_audit.operator_action_plan.status', 'pending_operator_or_calendar_action');
    }

    public function test_api_requires_atlas_token(): void
    {
        config()->set('atlas.token', 'testing-atlas-token-with-enough-length');
        $this->bindAuditService(rivalsReady: false, criticalInsights: 1, pushReplayPending: true);

        $this
            ->withHeader('X-Atlas-Token', 'wrong-token-with-enough-length')
            ->getJson('/ai/structure-mother-audit?hours=720&workspace='.urlencode(base_path()))
            ->assertUnauthorized()
            ->assertJsonPath('error.message', 'Invalid or missing X-Atlas-Token.');
    }

    private function bindAuditService(bool $rivalsReady, int $criticalInsights, bool $pushReplayPending = false): void
    {
        $this->app->instance(AtlasStructureMotherAuditReadModel::class, new AtlasStructureMotherAuditReadModel(
            $this->memoryQuality(),
            $this->documentation(),
            $this->captureInbox(),
            $this->tasks(),
            $this->tools(),
            $this->longRunningWork(),
            $this->rivals($rivalsReady),
            $this->proactive($criticalInsights, $pushReplayPending),
            $this->qualitativeLevels($rivalsReady),
            $this->costRates($rivalsReady),
        ));
    }

    private function memoryQuality(): AtlasMemoryQualityService
    {
        $mock = Mockery::mock(AtlasMemoryQualityService::class);
        $mock->shouldReceive('scorecard')->andReturn([
            'status' => 'ready',
            'score' => 97,
            'counts' => ['active' => 10, 'provider_safe_active' => 10],
            'issues' => [],
            'latest_snapshot' => ['status' => 'ready'],
        ]);

        return $mock;
    }

    private function documentation(): EngineeringDocumentationHealthService
    {
        $mock = Mockery::mock(EngineeringDocumentationHealthService::class);
        $mock->shouldReceive('report')->andReturn([
            'status' => 'ok',
            'summary' => [
                'doc_count' => 445,
                'required_missing_count' => 0,
                'frontmatter_violation_count' => 0,
                'oversized_count' => 0,
            ],
        ]);

        return $mock;
    }

    private function captureInbox(): CaptureInboxPipelineReadModel
    {
        $mock = Mockery::mock(CaptureInboxPipelineReadModel::class);
        $mock->shouldReceive('report')->andReturn([
            'status' => 'ok',
            'capture_count' => 1,
            'quarantined_capture_count' => 1,
            'missing_quarantine_count' => 0,
            'content_intelligence_count' => 1,
            'missing_content_intelligence_count' => 0,
            'unsafe_capture_count' => 0,
            'proposal_backlink_gap_count' => 0,
            'review_signal' => ['reasons' => []],
        ]);

        return $mock;
    }

    private function tasks(): TaskOrchestrationReadModel
    {
        $mock = Mockery::mock(TaskOrchestrationReadModel::class);
        $mock->shouldReceive('report')->andReturn([
            'status' => 'ok',
            'task_count' => 4,
            'event_count' => 4,
            'missing_receipt_count' => 0,
            'incomplete_receipt_count' => 0,
            'unsafe_receipt_count' => 0,
            'external_execution_review_required_count' => 0,
            'review_signal' => ['reasons' => []],
        ]);

        return $mock;
    }

    private function tools(): ToolActionRuntimeReadModel
    {
        $mock = Mockery::mock(ToolActionRuntimeReadModel::class);
        $mock->shouldReceive('report')->andReturn([
            'status' => 'ok',
            'definition_count' => 61,
            'ready_installation_count' => 9,
            'run_count' => 9,
            'failed_required_evidence_count' => 0,
            'open_blocking_finding_count' => 0,
            'latest_missing_action_runtime_contract_count' => 0,
            'review_signal' => ['reasons' => []],
        ]);

        return $mock;
    }

    private function longRunningWork(): LongRunningWorkReadModel
    {
        $mock = Mockery::mock(LongRunningWorkReadModel::class);
        $mock->shouldReceive('report')->andReturn([
            'status' => 'ok',
            'scheduled_task_count' => 0,
            'due_task_count' => 0,
            'overdue_task_count' => 0,
            'recent_run_count' => 0,
            'unsafe_autonomy_receipt_count' => 0,
            'review_signal' => ['reasons' => []],
        ]);

        return $mock;
    }

    private function rivals(bool $ready): AtlasRivalsStrategyReadModel
    {
        $mock = Mockery::mock(AtlasRivalsStrategyReadModel::class);
        $mock->shouldReceive('report')->andReturn([
            'available' => true,
            'case_count' => 1,
            'scheduled_review_count' => 4,
            'scored_review_count' => $ready ? 1 : 0,
            'average_agency_score' => $ready ? 88 : null,
            'p4_promotion_readiness' => [
                'status' => $ready ? 'ready' : 'blocked',
                'reason' => $ready ? 'scored_review_with_healthy_agency_available' : 'waiting_for_real_scored_revisit',
            ],
        ]);

        return $mock;
    }

    private function proactive(int $criticalInsights, bool $pushReplayPending = false): ProactiveLayerReadModel
    {
        $mock = Mockery::mock(ProactiveLayerReadModel::class);
        $mock->shouldReceive('report')->andReturn([
            'status' => $criticalInsights > 0 ? 'warning' : 'ok',
            'run_count' => 1,
            'failed_run_count' => 0,
            'insight_item_count' => $criticalInsights,
            'active_insight_item_count' => $criticalInsights,
            'critical_insight_item_count' => $criticalInsights,
            'active_critical_insight_item_count' => $criticalInsights,
            'push_requested_insight_count' => $pushReplayPending ? 2 : 0,
            'push_delivery_count' => 0,
            'mobile_push_configuration' => [
                'pending_dispatch_commands' => [
                    'dry_run' => 'php artisan atlas:cli:mobile replay-push --json',
                    'apply' => 'php artisan atlas:cli:mobile replay-push --apply --json',
                ],
                'delivery_diagnostics' => [
                    'push_token_device_count' => $pushReplayPending ? 1 : 0,
                    'granted_push_device_count' => $pushReplayPending ? 1 : 0,
                    'raw_push_token_exposed' => false,
                    'raw_device_id_exposed' => false,
                ],
            ],
            'critical_review_contract' => [
                'critical_active_count' => $criticalInsights,
                'agent_resolution_allowed' => false,
                'auto_dismiss_allowed' => false,
                'operator_review_plan' => [
                    'item_commands' => $criticalInsights > 0 ? [
                        ['show' => 'php artisan atlas:cli:inbox show critical-id --json'],
                    ] : [],
                ],
            ],
            'review_signal' => [
                'reasons' => array_values(array_filter([
                    $criticalInsights > 0 ? 'critical_proactive_insights_active' : null,
                    $pushReplayPending ? 'push_requested_without_delivery_attempt' : null,
                ])),
            ],
        ]);

        return $mock;
    }

    private function qualitativeLevels(bool $rivalsReady): AtlasQualitativeLevelsReadModel
    {
        $mock = Mockery::mock(AtlasQualitativeLevelsReadModel::class);
        $mock->shouldReceive('report')->andReturn([
            'current_level' => $rivalsReady ? 'P4' : 'P3',
            'next_level' => $rivalsReady ? 'P5' : 'P4',
            'next_level_blockers' => $rivalsReady ? [] : ['P4+ needs scored Rivals benchmark with healthy agency.'],
        ]);

        return $mock;
    }

    private function costRates(bool $rivalsReady): AiProviderCostRateService
    {
        $mock = Mockery::mock(AiProviderCostRateService::class);
        $mock->shouldReceive('missingRates')->andReturn($rivalsReady ? [] : [
            [
                'provider' => 'claude_cli',
                'model' => 'claude-sonnet-4-6',
                'reason' => 'missing_active_cost_rate',
                'can_import_rate' => true,
                'traces' => 12,
            ],
        ]);

        return $mock;
    }

    private function createOpenBrainTables(): void
    {
        Schema::dropIfExists('atlas_open_brain_access_logs');
        Schema::dropIfExists('atlas_engineering_code_modules');
        Schema::dropIfExists('atlas_engineering_knowledge_items');

        Schema::create('atlas_engineering_knowledge_items', function ($table): void {
            $table->uuid('id')->primary();
        });
        Schema::create('atlas_engineering_code_modules', function ($table): void {
            $table->uuid('id')->primary();
        });
        Schema::create('atlas_open_brain_access_logs', function ($table): void {
            $table->uuid('id')->primary();
        });
    }
}
