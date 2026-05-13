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
        $this->assertSame(3, data_get($payload, 'structure_mother_audit.operator_action_plan.action_count'));
        $this->assertContains('record_real_rivals_review_when_due', collect(data_get($payload, 'structure_mother_audit.operator_action_plan.actions'))->pluck('id')->all());
        $this->assertContains('review_critical_proactive_insights', collect(data_get($payload, 'structure_mother_audit.operator_action_plan.actions'))->pluck('id')->all());
        $this->assertContains('configure_missing_provider_cost_rates', collect(data_get($payload, 'structure_mother_audit.operator_action_plan.actions'))->pluck('id')->all());
        $proactiveAction = collect(data_get($payload, 'structure_mother_audit.operator_action_plan.actions'))->firstWhere('id', 'review_critical_proactive_insights');
        $this->assertStringContainsString('atlas:cli:inbox show', data_get($proactiveAction, 'item_commands.0.show'));
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

    private function bindAuditService(bool $rivalsReady, int $criticalInsights): void
    {
        $this->app->instance(AtlasStructureMotherAuditReadModel::class, new AtlasStructureMotherAuditReadModel(
            $this->memoryQuality(),
            $this->documentation(),
            $this->captureInbox(),
            $this->tasks(),
            $this->tools(),
            $this->longRunningWork(),
            $this->rivals($rivalsReady),
            $this->proactive($criticalInsights),
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

    private function proactive(int $criticalInsights): ProactiveLayerReadModel
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
            'push_delivery_count' => 0,
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
                'reasons' => $criticalInsights > 0 ? ['critical_proactive_insights_active'] : [],
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
