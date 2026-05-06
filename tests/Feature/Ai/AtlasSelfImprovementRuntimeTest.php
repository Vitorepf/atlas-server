<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasInitiativeRun;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Architecture\AtlasAiArchitectureValidationService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementRuntime;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasSelfImprovementRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_initiative_runs');
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_nightly_review_detects_ledger_gaps_and_records_its_own_cycle(): void
    {
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::ExecutionStarted, [
            'envelope_id' => 'env_without_terminal',
            'job_id' => 'job-1',
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'env_without_terminal',
            'correlation_id' => 'env_without_terminal',
            'emitter_stage' => 'ai.worker',
            'emitter_version' => 'test',
        ]);
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::GateBlocked, [
            'envelope_id' => 'engineering_run:blocked',
            'gate_type' => 'tool_runtime',
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'engineering_run:blocked',
            'correlation_id' => 'engineering_run:blocked',
            'emitter_stage' => 'atlas.tools.gate',
            'emitter_version' => 'test',
        ]);

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(emit: false, hours: 24, limit: 5);

        $this->assertTrue($result['ok']);
        $this->assertNotNull($result['run_id']);
        $this->assertTrue($result['dry_run'], 'Dry-run should be true when emit=false.');
        $this->assertGreaterThanOrEqual(2, count($result['findings']));
        $this->assertDatabaseHas('atlas_initiative_runs', [
            'id' => $result['run_id'],
            'kind' => 'self_improvement_nightly_review',
            'status' => 'succeeded',
        ]);

        $cycleEvents = AtlasLedgerEvent::query()
            ->where('envelope_id', 'self_improvement_run:'.$result['run_id'])
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->pluck('event_type')
            ->all();

        $this->assertContains(LedgerEventType::ExecutionStarted->value, $cycleEvents);
        $this->assertContains(LedgerEventType::SelfImprovementScheduleObserved->value, $cycleEvents);
        $this->assertContains(LedgerEventType::LearningProposed->value, $cycleEvents);
        $this->assertContains(LedgerEventType::OperationCompleted->value, $cycleEvents);

        $scheduleEvent = AtlasLedgerEvent::query()
            ->where('envelope_id', 'self_improvement_run:'.$result['run_id'])
            ->where('event_type', LedgerEventType::SelfImprovementScheduleObserved->value)
            ->firstOrFail();

        $this->assertSame('self_improvement.nightly_review', data_get($scheduleEvent->payload, 'flow'));
        $this->assertIsArray(data_get($scheduleEvent->payload, 'schedule_health'));
        $this->assertArrayHasKey('health_status', data_get($scheduleEvent->payload, 'schedule_health'));
        $this->assertArrayHasKey('scheduler_registration', data_get($scheduleEvent->payload, 'schedule_health'));
    }

    public function test_nightly_review_uses_explicit_autonomous_window_and_limit_contract(): void
    {
        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            emit: false,
            hours: 999,
            limit: 999,
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS, $result['hours']);

        $started = AtlasLedgerEvent::query()
            ->where('envelope_id', 'self_improvement_run:'.$result['run_id'])
            ->where('event_type', LedgerEventType::ExecutionStarted->value)
            ->firstOrFail();

        $this->assertSame(AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS, data_get($started->payload, 'hours'));
        $this->assertSame(AtlasSelfImprovementRuntime::MAX_FINDINGS_PER_RUN, data_get($started->payload, 'limit'));
    }

    public function test_command_outputs_json_payload(): void
    {
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::OperationFailed, [
            'envelope_id' => 'env_failed',
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'env_failed',
            'correlation_id' => 'env_failed',
            'emitter_stage' => 'ai.worker',
            'emitter_version' => 'test',
        ]);

        $exit = Artisan::call('atlas:ai:self-improve', [
            '--hours' => 24,
            '--limit' => 3,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('completed', $payload['status']);
        $this->assertSame('AtlasSelfImprovementOrchestrator', $payload['orchestrator']);
        $this->assertSame('self_improvement.nightly_review', data_get($payload, 'plan.flow'));
        $this->assertTrue(data_get($payload, 'runtime.ok'));
        $this->assertSame(24, data_get($payload, 'runtime.hours'));
        $this->assertNotEmpty(data_get($payload, 'runtime.findings'));
        $this->assertNotEmpty($payload['evidence_refs']);
    }

    public function test_command_can_run_specialized_self_improvement_flow(): void
    {
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::GateBlocked, [
            'envelope_id' => 'engineering_run:tool_gate',
            'gate_type' => 'tool_runtime',
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'engineering_run:tool_gate',
            'correlation_id' => 'engineering_run:tool_gate',
            'emitter_stage' => 'atlas.tools.gate',
            'emitter_version' => 'test',
        ]);

        $exit = Artisan::call('atlas:ai:self-improve', [
            '--flow' => 'tool_runtime_review',
            '--hours' => 24,
            '--limit' => 3,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('completed', $payload['status']);
        $this->assertSame('self_improvement.tool_runtime_review', data_get($payload, 'plan.flow'));
        $this->assertSame('self_improvement.tool_runtime_review', data_get($payload, 'runtime.flow'));
        $this->assertDatabaseHas('atlas_initiative_runs', [
            'id' => data_get($payload, 'runtime.run_id'),
            'kind' => 'self_improvement_tool_runtime_review',
            'status' => 'succeeded',
        ]);
    }

    public function test_self_improvement_detects_kernel_slo_drift_from_replay_service(): void
    {
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::SloObserved, [
            'stage' => 'runtime.execute',
            'status' => 'breach',
            'severity' => 'high',
            'violations' => ['stage_failed'],
            'dimensions' => [
                'domain' => 'programming',
                'surface_id' => 'atlas_cli_dev',
                'provider' => 'codex_cli',
                'model' => 'gpt-5.2',
            ],
            'slo' => [
                'stage' => 'runtime.execute',
                'duration_ms' => 450000,
                'success' => false,
                'status' => 'breach',
                'severity' => 'high',
                'violations' => ['stage_failed'],
            ],
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'env_slo_breach',
            'correlation_id' => 'env_slo_breach',
            'emitter_stage' => 'atlas.slo',
            'emitter_version' => 'test',
        ]);
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::SloObserved, [
            'stage' => 'runtime.execute',
            'status' => 'breach',
            'severity' => 'high',
            'violations' => ['stage_failed'],
            'dimensions' => [
                'domain' => 'finance',
                'surface_id' => 'atlas_api',
                'provider' => 'claude_cli',
                'model' => 'claude-sonnet',
            ],
            'slo' => [
                'stage' => 'runtime.execute',
                'duration_ms' => 999000,
                'success' => false,
                'status' => 'breach',
                'severity' => 'high',
                'violations' => ['stage_failed'],
            ],
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'env_slo_breach_finance',
            'correlation_id' => 'env_slo_breach_finance',
            'emitter_stage' => 'atlas.slo',
            'emitter_version' => 'test',
        ]);

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'provider_performance_review',
            emit: false,
            hours: 24,
            limit: 5,
            filters: ['domain' => 'programming', 'provider' => 'codex_cli'],
        );

        $finding = collect($result['findings'])->firstWhere('dedupe_key', 'self-improvement:slo-drift:'.sha1('runtime.execute:breach:stage_failed'));

        $this->assertIsArray($finding);
        $this->assertSame('Investigar SLO drift em runtime.execute', $finding['title']);
        $this->assertSame('runtime.execute', data_get($finding, 'metadata.stage'));
        $this->assertSame('breach', data_get($finding, 'metadata.status'));
        $this->assertSame(1, data_get($finding, 'metadata.failure_count'));
        $this->assertSame('breach', data_get($finding, 'metadata.review_signal.status'));
        $this->assertSame('high', data_get($finding, 'metadata.review_signal.severity'));
        $this->assertSame('open_reviewable_slo_regression_proposal', data_get($finding, 'metadata.review_signal.recommended_action'));
        $this->assertSame(['domain' => 'programming', 'provider' => 'codex_cli'], $result['filters']);
        $this->assertSame(['domain' => 'programming', 'provider' => 'codex_cli'], data_get($finding, 'metadata.filters'));
        $this->assertSame(['programming' => 1], data_get($finding, 'metadata.dimensions.domain'));
        $this->assertSame(['codex_cli' => 1], data_get($finding, 'metadata.dimensions.provider'));
        $this->assertSame('env_slo_breach', data_get($finding, 'source_refs.0.envelope_id'));
        $this->assertSame('atlas_cli_dev', data_get($finding, 'source_refs.0.dimensions.surface_id'));
    }

    public function test_self_improvement_detects_repair_loop_patterns_from_replay_service(): void
    {
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::RepairInitiated, [
            'failure_classification' => [
                'failure_domain' => 'compliance.violation',
            ],
            'decision' => [
                'status' => 'needs_human_review',
                'strategy' => 'human_review',
                'next_attempt' => 1,
                'reasons' => ['failure_domain_requires_human_review'],
            ],
            'repair_executed' => false,
            'decision_hash' => 'repair-human-review-decision',
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'env_repair_human_review',
            'correlation_id' => 'env_repair_human_review',
            'emitter_stage' => 'atlas.repair',
            'emitter_version' => 'test',
        ]);

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'tool_runtime_review',
            emit: false,
            hours: 24,
            limit: 5,
        );

        $finding = collect($result['findings'])->firstWhere('dedupe_key', 'self-improvement:repair-loop:'.sha1('human-review'));

        $this->assertIsArray($finding);
        $this->assertSame('Reduzir repairs que exigem revisao humana', $finding['title']);
        $this->assertTrue((bool) data_get($finding, 'metadata.requires_human_review'));
        $this->assertSame(['human_review' => 1], data_get($finding, 'metadata.strategy_counts'));
        $this->assertSame(['needs_human_review' => 1], data_get($finding, 'metadata.status_counts'));
        $this->assertSame('warning', data_get($finding, 'metadata.review_signal.status'));
        $this->assertSame('medium', data_get($finding, 'metadata.review_signal.severity'));
        $this->assertSame('open_reviewable_repair_loop_human_review_proposal', data_get($finding, 'metadata.review_signal.recommended_action'));
        $this->assertSame('env_repair_human_review', data_get($finding, 'source_refs.0.envelope_id'));
        $this->assertSame('compliance.violation', data_get($finding, 'source_refs.0.failure_domain'));
    }

    public function test_self_improvement_filters_repair_loop_patterns(): void
    {
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::RepairInitiated, [
            'failure_classification' => [
                'failure_domain' => 'harness.failed',
            ],
            'decision' => [
                'status' => 'repair_allowed',
                'strategy' => 'rerun_harness',
                'next_attempt' => 1,
                'reasons' => ['repair_planned'],
            ],
            'repair_executed' => false,
            'decision_hash' => 'repair-harness-decision',
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'env_repair_harness',
            'correlation_id' => 'env_repair_harness',
            'emitter_stage' => 'engineering_harness.repair',
            'emitter_version' => 'test',
        ]);
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::RepairInitiated, [
            'failure_classification' => [
                'failure_domain' => 'compliance.violation',
            ],
            'decision' => [
                'status' => 'needs_human_review',
                'strategy' => 'human_review',
                'next_attempt' => 1,
                'reasons' => ['failure_domain_requires_human_review'],
            ],
            'repair_executed' => false,
            'decision_hash' => 'repair-human-filter-decision',
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'env_repair_human_filter',
            'correlation_id' => 'env_repair_human_filter',
            'emitter_stage' => 'atlas.repair',
            'emitter_version' => 'test',
        ]);

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'tool_runtime_review',
            emit: false,
            hours: 24,
            limit: 5,
            filters: [
                'strategy' => 'human_review',
                'failure_domain' => 'compliance.violation',
            ],
        );

        $finding = collect($result['findings'])->firstWhere('dedupe_key', 'self-improvement:repair-loop:'.sha1('human-review'));

        $this->assertSame([
            'strategy' => 'human_review',
            'failure_domain' => 'compliance.violation',
        ], $result['filters']);
        $this->assertIsArray($finding);
        $this->assertSame(['strategy' => 'human_review', 'failure_domain' => 'compliance.violation'], data_get($finding, 'metadata.filters'));
        $this->assertSame(1, data_get($finding, 'metadata.repair_event_count'));
        $this->assertSame(['human_review' => 1], data_get($finding, 'metadata.strategy_counts'));
        $this->assertSame('env_repair_human_filter', data_get($finding, 'source_refs.0.envelope_id'));
    }

    public function test_self_improvement_repair_loop_review_is_dedicated_to_repair_evidence(): void
    {
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::GateBlocked, [
            'envelope_id' => 'env_gate_blocked_should_not_leak',
            'gate_type' => 'tool_runtime',
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'env_gate_blocked_should_not_leak',
            'correlation_id' => 'env_gate_blocked_should_not_leak',
            'emitter_stage' => 'atlas.tools.gate',
            'emitter_version' => 'test',
        ]);
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::RepairInitiated, [
            'failure_classification' => [
                'failure_domain' => 'harness.failed',
            ],
            'decision' => [
                'status' => 'repair_allowed',
                'strategy' => 'rerun_harness',
                'next_attempt' => 1,
                'reasons' => ['repair_planned'],
            ],
            'repair_executed' => false,
            'decision_hash' => 'repair-loop-dedicated-a',
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'env_repair_loop_dedicated_a',
            'correlation_id' => 'env_repair_loop_dedicated_a',
            'emitter_stage' => 'engineering_harness.repair',
            'emitter_version' => 'test',
        ]);
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::RepairInitiated, [
            'failure_classification' => [
                'failure_domain' => 'harness.failed',
            ],
            'decision' => [
                'status' => 'repair_allowed',
                'strategy' => 'rerun_harness',
                'next_attempt' => 1,
                'reasons' => ['repair_planned'],
            ],
            'repair_executed' => false,
            'decision_hash' => 'repair-loop-dedicated-b',
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'env_repair_loop_dedicated_b',
            'correlation_id' => 'env_repair_loop_dedicated_b',
            'emitter_stage' => 'engineering_harness.repair',
            'emitter_version' => 'test',
        ]);

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'repair_loop_review',
            emit: false,
            hours: 24,
            limit: 5,
            filters: ['strategy' => 'rerun_harness'],
        );

        $this->assertSame('self_improvement.repair_loop_review', $result['flow']);
        $this->assertSame(['strategy' => 'rerun_harness'], $result['filters']);
        $this->assertCount(1, $result['findings']);
        $this->assertSame('Investigar repair recorrente rerun_harness', data_get($result, 'findings.0.title'));
        $this->assertSame(['strategy' => 'rerun_harness'], data_get($result, 'findings.0.metadata.filters'));
        $this->assertSame(2, data_get($result, 'findings.0.metadata.repair_event_count'));
    }

    public function test_self_improvement_detects_kernel_pipeline_contract_drift_from_replay_service(): void
    {
        $this->recordKernelPipeline('01HKPIPESELFIMPROVE00000000001', 'env_kernel_pipeline_ok', LedgerEventType::KernelPipelineAccepted, 'accepted', 'atlas_cli_dev', 'one_shot');
        $this->recordKernelPipeline('01HKPIPESELFIMPROVE00000000002', 'env_kernel_pipeline_rejected', LedgerEventType::KernelPipelineRejected, 'rejected', 'atlas_ai_chat', 'declared_dev_plan', ['canonical_flow_hash_mismatch']);

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'kernel_pipeline_review',
            emit: false,
            hours: 24,
            limit: 5,
        );

        $finding = collect($result['findings'])->firstWhere('dedupe_key', 'self-improvement:kernel-pipeline:'.sha1('rejected-contracts'));

        $this->assertSame('self_improvement.kernel_pipeline_review', $result['flow']);
        $this->assertIsArray($finding);
        $this->assertSame('Corrigir surfaces rejeitadas pelo Kernel Pipeline', $finding['title']);
        $this->assertSame(2, data_get($finding, 'metadata.kernel_pipeline_event_count'));
        $this->assertSame(1, data_get($finding, 'metadata.rejected_count'));
        $this->assertTrue((bool) data_get($finding, 'metadata.has_rejections'));
        $this->assertSame(['atlas_cli_dev' => 1, 'atlas_ai_chat' => 1], data_get($finding, 'metadata.surface_counts'));
        $this->assertSame(['atlas.ai_chat.kernel_pipeline_guard' => 2], data_get($finding, 'metadata.emitter_stage_counts'));
        $this->assertSame(['canonical_flow_hash_mismatch' => 1], data_get($finding, 'metadata.violation_counts'));
        $this->assertSame('breach', data_get($finding, 'metadata.health.status'));
        $this->assertSame(0.5, data_get($finding, 'metadata.health.rejection_rate'));
        $this->assertTrue((bool) data_get($finding, 'metadata.health.review_required'));
        $this->assertSame('breach', data_get($finding, 'metadata.review_signal.status'));
        $this->assertSame('high', data_get($finding, 'metadata.review_signal.severity'));
        $this->assertSame('open_reviewable_kernel_pipeline_contract_proposal', data_get($finding, 'metadata.review_signal.recommended_action'));
        $this->assertSame('env_kernel_pipeline_rejected', data_get($finding, 'source_refs.0.envelope_id'));
        $this->assertSame('atlas_ai_chat', data_get($finding, 'source_refs.0.surface_id'));
        $this->assertSame('atlas.ai_chat.kernel_pipeline_guard', data_get($finding, 'source_refs.0.emitter_stage'));
    }

    public function test_self_improvement_detects_domain_onboarding_scaffolds_from_catalog(): void
    {
        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'domain_learning_review',
            emit: false,
            hours: 24,
            limit: 5,
            filters: ['onboarding_status' => 'scaffold'],
        );

        $finding = collect($result['findings'])->first(
            fn (array $finding): bool => str_starts_with((string) ($finding['dedupe_key'] ?? ''), 'self-improvement:domain-onboarding:')
        );

        $this->assertSame('self_improvement.domain_learning_review', $result['flow']);
        $this->assertSame(['onboarding_status' => 'scaffold'], $result['filters']);
        $this->assertIsArray($finding);
        $this->assertSame('Priorizar dominios scaffold no roadmap de habilidades', $finding['title']);
        $this->assertGreaterThanOrEqual(1, data_get($finding, 'metadata.scaffold_domains'));
        $this->assertSame(['scaffold' => data_get($finding, 'metadata.domain_count')], data_get($finding, 'metadata.onboarding_status_counts'));
        $this->assertSame('scaffold', data_get($finding, 'source_refs.0.onboarding_status'));
        $this->assertContains('maturity_gate', data_get($finding, 'source_refs.0.missing_phases'));
    }

    public function test_self_improvement_detects_architecture_validation_regressions_from_shared_service(): void
    {
        $this->mock(AtlasAiArchitectureValidationService::class, function ($mock): void {
            $mock->shouldReceive('payload')->once()->andReturn([
                'schema_version' => 1,
                'status' => 'failed',
                'kernel' => [
                    'valid' => false,
                    'static_scan' => [
                        'valid' => false,
                        'summary' => [
                            'total_count' => 40,
                            'passed_count' => 39,
                            'failed_count' => 1,
                            'valid_keys' => ['ap1_surface_provider_bypass'],
                            'failed_keys' => ['ap40_architecture_validation_mcp_tool'],
                            'violation_count' => 2,
                        ],
                        'ap40_architecture_validation_mcp_tool' => [
                            'valid' => false,
                            'violations' => [
                                'MCP lost atlas_architecture_validate',
                                'MCP stopped using AtlasAiArchitectureValidationService',
                            ],
                        ],
                    ],
                ],
                'capabilities' => ['valid' => true],
                'domains' => ['valid' => true],
                'orchestrators' => ['valid' => true],
                'onboarding' => [
                    'ready_domains' => 4,
                    'scaffold_domains' => 10,
                    'executable_incomplete_domains' => 0,
                ],
                'validated_at' => '2026-05-05T00:00:00Z',
            ]);
        });

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'weekly_architecture_audit',
            emit: false,
            hours: 24,
            limit: 5,
            filters: ['status' => 'failed', 'surface_id' => 'atlas_mcp_readonly'],
        );

        $finding = collect($result['findings'])->firstWhere(
            'dedupe_key',
            'self-improvement:architecture-validation:'.sha1('ap40_architecture_validation_mcp_tool:2:failed')
        );

        $this->assertSame('self_improvement.weekly_architecture_audit', $result['flow']);
        $this->assertIsArray($finding);
        $this->assertSame('Corrigir regressao na arquitetura mae do Atlas AI', $finding['title']);
        $this->assertSame('failed', data_get($finding, 'metadata.status'));
        $this->assertSame(['ap40_architecture_validation_mcp_tool'], data_get($finding, 'metadata.failed_keys'));
        $this->assertSame(2, data_get($finding, 'metadata.violation_count'));
        $this->assertFalse((bool) data_get($finding, 'metadata.kernel_valid'));
        $this->assertSame('ap40_architecture_validation_mcp_tool', data_get($finding, 'source_refs.0.id'));
        $this->assertSame([
            'status' => 'failed',
            'surface_id' => 'atlas_mcp_readonly',
        ], data_get($finding, 'metadata.filters'));
    }

    public function test_self_improvement_detects_unhealthy_recurring_schedule(): void
    {
        config()->set('app.timezone', 'America/Sao_Paulo');
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['unknown_flow', 'repair_loop_review']);
        config()->set('atlas_ai.self_improvement.time', '02:00');

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'weekly_architecture_audit',
            emit: false,
            hours: 24,
            limit: 10,
        );

        $finding = collect($result['findings'])->firstWhere(
            'dedupe_key',
            'self-improvement:schedule-health:'.sha1('warning:registered:invalid_self_improvement_flows_configured')
        );

        $this->assertSame('self_improvement.weekly_architecture_audit', $result['flow']);
        $this->assertIsArray($finding);
        $this->assertSame('Corrigir schedule recorrente do Self-Improvement', $finding['title']);
        $this->assertSame('warning', data_get($finding, 'metadata.health_status'));
        $this->assertSame(['invalid_self_improvement_flows_configured'], data_get($finding, 'metadata.issues'));
        $this->assertSame(1, data_get($finding, 'metadata.invalid_flow_count'));
        $this->assertSame('registered', data_get($finding, 'metadata.scheduler_registration.status'));
        $this->assertSame(['daily' => 1], data_get($finding, 'metadata.cadence_counts'));
        $this->assertSame('invalid_self_improvement_flows_configured', data_get($finding, 'source_refs.0.id'));
    }

    public function test_self_improvement_detects_schedule_replay_drift(): void
    {
        config()->set('app.timezone', 'America/Sao_Paulo');
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['nightly_review', 'weekly_architecture_audit', 'repair_loop_review', 'kernel_pipeline_review']);
        config()->set('atlas_ai.self_improvement.time', '02:00');

        $this->recordSelfImprovementScheduleObservation(
            eventId: '01HSELFREPLAYSCHEDULEWARN01',
            envelopeId: 'self_improvement_run:schedule_warn',
            healthStatus: 'warning',
            schedulerStatus: 'registered',
            issues: ['invalid_self_improvement_flows_configured'],
            invalidFlowCount: 1,
        );

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'weekly_architecture_audit',
            emit: false,
            hours: 24,
            limit: 10,
        );

        $finding = collect($result['findings'])->firstWhere(
            'dedupe_key',
            'self-improvement:schedule-replay:'.sha1('1:invalid_self_improvement_flows_configured')
        );

        $this->assertSame('self_improvement.weekly_architecture_audit', $result['flow']);
        $this->assertIsArray($finding);
        $this->assertSame('Investigar drift recorrente no schedule do Self-Improvement', $finding['title']);
        $this->assertSame(1, data_get($finding, 'metadata.warning_count'));
        $this->assertSame(['invalid_self_improvement_flows_configured' => 1], data_get($finding, 'metadata.issue_counts'));
        $this->assertSame(['warning' => 1, 'healthy' => 1], data_get($finding, 'metadata.health_status_counts'));
        $this->assertTrue((bool) data_get($finding, 'metadata.review_required'));
        $this->assertSame('warning', data_get($finding, 'metadata.review_signal.status'));
        $this->assertSame('medium', data_get($finding, 'metadata.review_signal.severity'));
        $this->assertSame('open_reviewable_self_improvement_schedule_proposal', data_get($finding, 'metadata.review_signal.recommended_action'));
        $this->assertSame('self_improvement_run:schedule_warn', data_get($finding, 'source_refs.0.envelope_id'));
        $this->assertSame('warning', data_get($finding, 'source_refs.0.health_status'));
    }

    public function test_self_improvement_command_accepts_kernel_pipeline_filters(): void
    {
        $this->recordKernelPipeline('01HKPIPESELFCOMMAND0000000001', 'env_kernel_pipeline_command_ok', LedgerEventType::KernelPipelineAccepted, 'accepted', 'atlas_cli_dev', 'one_shot');
        $this->recordKernelPipeline('01HKPIPESELFCOMMAND0000000002', 'env_kernel_pipeline_command_bad', LedgerEventType::KernelPipelineRejected, 'rejected', 'atlas_ai_chat', 'declared_dev_plan', ['stage_order_mismatch']);

        $exit = Artisan::call('atlas:ai:self-improve', [
            '--flow' => 'kernel_pipeline_review',
            '--kernel-status' => 'rejected',
            '--surface' => 'atlas_ai_chat',
            '--kernel-input-mode' => 'declared_dev_plan',
            '--hours' => 24,
            '--limit' => 3,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame([
            'surface_id' => 'atlas_ai_chat',
            'status' => 'rejected',
            'input_mode' => 'declared_dev_plan',
        ], data_get($payload, 'plan.options.filters'));
        $this->assertSame('self_improvement.kernel_pipeline_review', data_get($payload, 'plan.flow'));
        $this->assertSame('kernel_pipeline_review_runtime', data_get($payload, 'plan.execution_policy.executor_preference'));
        $this->assertSame([
            'surface_id' => 'atlas_ai_chat',
            'status' => 'rejected',
            'input_mode' => 'declared_dev_plan',
        ], data_get($payload, 'runtime.filters'));
        $this->assertSame(1, data_get($payload, 'runtime.findings.0.metadata.kernel_pipeline_event_count'));
        $this->assertSame(['stage_order_mismatch' => 1], data_get($payload, 'runtime.findings.0.metadata.violation_counts'));
        $this->assertSame('env_kernel_pipeline_command_bad', data_get($payload, 'runtime.findings.0.source_refs.0.envelope_id'));
    }

    public function test_self_improvement_command_accepts_domain_onboarding_filter(): void
    {
        $exit = Artisan::call('atlas:ai:self-improve', [
            '--flow' => 'domain_learning_review',
            '--onboarding-status' => 'scaffold',
            '--hours' => 24,
            '--limit' => 3,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['onboarding_status' => 'scaffold'], data_get($payload, 'plan.options.filters'));
        $this->assertSame(['onboarding_status' => 'scaffold'], data_get($payload, 'runtime.filters'));
        $this->assertSame('self_improvement.domain_learning_review', data_get($payload, 'plan.flow'));
        $finding = collect(data_get($payload, 'runtime.findings', []))->first(
            fn (array $finding): bool => str_starts_with((string) ($finding['dedupe_key'] ?? ''), 'self-improvement:domain-onboarding:')
        );

        $this->assertIsArray($finding);
        $this->assertSame('Priorizar dominios scaffold no roadmap de habilidades', $finding['title']);
        $this->assertSame('scaffold', data_get($finding, 'source_refs.0.onboarding_status'));
    }

    public function test_self_improvement_command_accepts_slo_dimension_filters(): void
    {
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::SloObserved, [
            'stage' => 'runtime.execute',
            'status' => 'warning',
            'severity' => 'high',
            'violations' => ['latency_above_p95'],
            'dimensions' => [
                'domain' => 'programming',
                'surface_id' => 'atlas_cli_dev',
                'provider' => 'codex_cli',
            ],
            'slo' => [
                'stage' => 'runtime.execute',
                'duration_ms' => 120000,
                'success' => true,
                'status' => 'warning',
                'severity' => 'high',
                'violations' => ['latency_above_p95'],
            ],
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'env_slo_filtered_command',
            'correlation_id' => 'env_slo_filtered_command',
            'emitter_stage' => 'atlas.slo',
            'emitter_version' => 'test',
        ]);

        $exit = Artisan::call('atlas:ai:self-improve', [
            '--flow' => 'provider_performance_review',
            '--domain' => 'programming',
            '--provider' => 'codex_cli',
            '--hours' => 24,
            '--limit' => 3,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['domain' => 'programming', 'provider' => 'codex_cli'], data_get($payload, 'plan.options.filters'));
        $this->assertSame(['domain' => 'programming', 'provider' => 'codex_cli'], data_get($payload, 'runtime.filters'));
        $this->assertSame(['codex_cli' => 1], data_get($payload, 'runtime.findings.0.metadata.dimensions.provider'));
    }

    public function test_self_improvement_command_accepts_repair_loop_filters(): void
    {
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::RepairInitiated, [
            'failure_classification' => [
                'failure_domain' => 'compliance.violation',
            ],
            'decision' => [
                'status' => 'needs_human_review',
                'strategy' => 'human_review',
                'next_attempt' => 1,
                'reasons' => ['failure_domain_requires_human_review'],
            ],
            'repair_executed' => false,
            'decision_hash' => 'repair-command-filter-decision',
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'env_repair_command_filter',
            'correlation_id' => 'env_repair_command_filter',
            'emitter_stage' => 'atlas.repair',
            'emitter_version' => 'test',
        ]);

        $exit = Artisan::call('atlas:ai:self-improve', [
            '--flow' => 'repair_loop_review',
            '--repair-strategy' => 'human_review',
            '--failure-domain' => 'compliance.violation',
            '--hours' => 24,
            '--limit' => 3,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame([
            'strategy' => 'human_review',
            'failure_domain' => 'compliance.violation',
        ], data_get($payload, 'plan.options.filters'));
        $this->assertSame([
            'strategy' => 'human_review',
            'failure_domain' => 'compliance.violation',
        ], data_get($payload, 'runtime.filters'));
        $this->assertSame('self_improvement.repair_loop_review', data_get($payload, 'plan.flow'));
        $this->assertSame('repair_loop_review_runtime', data_get($payload, 'plan.execution_policy.executor_preference'));
        $this->assertSame(['human_review' => 1], data_get($payload, 'runtime.findings.0.metadata.strategy_counts'));
        $this->assertSame('env_repair_command_filter', data_get($payload, 'runtime.findings.0.source_refs.0.envelope_id'));
    }

    public function test_command_lists_supported_flows_and_can_render_plan_only(): void
    {
        $listExit = Artisan::call('atlas:ai:self-improve', [
            '--list-flows' => true,
            '--json' => true,
        ]);
        $listPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $listExit);
        $this->assertSame('ok', $listPayload['status']);
        $this->assertSame(12, $listPayload['count']);
        $this->assertContains('self_improvement.provider_performance_review', $listPayload['flows']);
        $this->assertContains('self_improvement.repair_loop_review', $listPayload['flows']);
        $this->assertContains('self_improvement.kernel_pipeline_review', $listPayload['flows']);

        $planExit = Artisan::call('atlas:ai:self-improve', [
            '--flow' => 'provider_performance_review',
            '--domain' => 'programming',
            '--hours' => 72,
            '--limit' => 7,
            '--plan-only' => true,
            '--json' => true,
        ]);
        $planPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $planExit);
        $this->assertSame('planned', $planPayload['status']);
        $this->assertSame('self_improvement.provider_performance_review', data_get($planPayload, 'plan.flow'));
        $this->assertSame(72, data_get($planPayload, 'plan.options.hours'));
        $this->assertSame(7, data_get($planPayload, 'plan.options.limit'));
        $this->assertSame(['domain' => 'programming'], data_get($planPayload, 'plan.options.filters'));
        $this->assertSame('provider_performance_runtime', data_get($planPayload, 'plan.execution_policy.executor_preference'));
        $this->assertSame(0, AtlasInitiativeRun::query()->count(), 'Plan-only must not create initiative runs.');
    }

    public function test_command_can_render_recurring_schedule_plan(): void
    {
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['nightly_review', 'weekly_architecture_audit', 'repair_loop_review', 'kernel_pipeline_review']);
        config()->set('atlas_ai.self_improvement.time', '02:00');
        config()->set('atlas_ai.self_improvement.hours', 24);
        config()->set('atlas_ai.self_improvement.limit', 5);
        config()->set('atlas_ai.self_improvement.emit', false);

        $exit = Artisan::call('atlas:ai:self-improve', [
            '--schedule-plan' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertTrue((bool) $payload['enabled']);
        $this->assertSame('02:00', $payload['time']);
        $this->assertSame(config('app.timezone'), $payload['timezone']);
        $this->assertArrayHasKey('next_run_at', $payload);
        $this->assertTrue($payload['schedulable']);
        $this->assertSame('registered', data_get($payload, 'scheduler_registration.status'));
        $this->assertSame(4, data_get($payload, 'scheduler_registration.registered_command_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['plan_hash']);
        $this->assertSame('sha256', $payload['plan_hash_algorithm']);
        $this->assertSame(['daily' => 3, 'weekly' => 1], $payload['cadence_counts']);
        $this->assertSame(4, $payload['count']);
        $this->assertSame(['nightly_review', 'weekly_architecture_audit', 'repair_loop_review', 'kernel_pipeline_review'], $payload['configured_flows']);
        $this->assertSame([], $payload['invalid_flows']);
        $this->assertFalse($payload['defaulted']);
        $this->assertSame('healthy', data_get($payload, 'health.status'));
        $this->assertSame(['nightly_review', 'weekly_architecture_audit', 'repair_loop_review', 'kernel_pipeline_review'], $payload['flows']);
        $this->assertSame('atlas:ai:self-improve --flow=weekly_architecture_audit --hours=24 --limit=5 --json', data_get($payload, 'commands.1.command'));
        $this->assertSame('weekly', data_get($payload, 'commands.1.cadence'));
        $this->assertSame(1, data_get($payload, 'commands.1.week_day'));
        $this->assertSame('atlas:ai:self-improve --flow=repair_loop_review --hours=24 --limit=5 --json', data_get($payload, 'commands.2.command'));
        $this->assertSame('daily', data_get($payload, 'commands.2.cadence'));
        $this->assertSame('atlas:ai:self-improve --flow=kernel_pipeline_review --hours=24 --limit=5 --json', data_get($payload, 'commands.3.command'));
        $this->assertSame('daily', data_get($payload, 'commands.3.cadence'));
        $this->assertSame(0, AtlasInitiativeRun::query()->count(), 'Schedule-plan must not create initiative runs.');
    }

    public function test_schedule_plan_reports_invalid_configured_flows(): void
    {
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['unknown_flow', 'self_improvement.repair_loop_review']);
        config()->set('atlas_ai.self_improvement.time', '02:00');
        config()->set('atlas_ai.self_improvement.hours', 24);
        config()->set('atlas_ai.self_improvement.limit', 5);
        config()->set('atlas_ai.self_improvement.emit', false);

        $exit = Artisan::call('atlas:ai:self-improve', [
            '--schedule-plan' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(['unknown_flow', 'self_improvement.repair_loop_review'], $payload['configured_flows']);
        $this->assertSame(['unknown_flow'], $payload['invalid_flows']);
        $this->assertFalse($payload['defaulted']);
        $this->assertSame('warning', data_get($payload, 'health.status'));
        $this->assertSame(['invalid_self_improvement_flows_configured'], data_get($payload, 'health.issues'));
        $this->assertSame(['repair_loop_review'], $payload['flows']);
        $this->assertSame('atlas:ai:self-improve --flow=repair_loop_review --hours=24 --limit=5 --json', data_get($payload, 'commands.0.command'));
    }

    public function test_schedule_plan_can_fail_on_unhealthy_schedule_for_ci(): void
    {
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['unknown_flow', 'repair_loop_review']);
        config()->set('atlas_ai.self_improvement.time', '02:00');
        config()->set('atlas_ai.self_improvement.hours', 24);
        config()->set('atlas_ai.self_improvement.limit', 5);
        config()->set('atlas_ai.self_improvement.emit', false);

        $exit = Artisan::call('atlas:ai:self-improve', [
            '--schedule-plan' => true,
            '--fail-on-schedule-warning' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('warning', data_get($payload, 'health.status'));
        $this->assertSame(['invalid_self_improvement_flows_configured'], data_get($payload, 'health.issues'));
        $this->assertSame(0, AtlasInitiativeRun::query()->count(), 'Schedule health check must not create initiative runs.');
    }

    public function test_schedule_plan_fail_on_warning_passes_when_schedule_is_healthy(): void
    {
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['nightly_review', 'weekly_architecture_audit', 'repair_loop_review', 'kernel_pipeline_review']);
        config()->set('atlas_ai.self_improvement.time', '02:00');
        config()->set('atlas_ai.self_improvement.hours', 24);
        config()->set('atlas_ai.self_improvement.limit', 5);
        config()->set('atlas_ai.self_improvement.emit', false);

        $exit = Artisan::call('atlas:ai:self-improve', [
            '--schedule-plan' => true,
            '--fail-on-schedule-warning' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('healthy', data_get($payload, 'health.status'));
        $this->assertSame(0, AtlasInitiativeRun::query()->count(), 'Schedule health check must not create initiative runs.');
    }

    public function test_command_can_render_compact_schedule_health(): void
    {
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['unknown_flow', 'repair_loop_review']);
        config()->set('atlas_ai.self_improvement.time', '02:00');
        config()->set('atlas_ai.self_improvement.hours', 24);
        config()->set('atlas_ai.self_improvement.limit', 5);
        config()->set('atlas_ai.self_improvement.emit', false);

        $exit = Artisan::call('atlas:ai:self-improve', [
            '--schedule-health' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('warning', data_get($payload, 'health.status'));
        $this->assertSame(1, $payload['flow_count']);
        $this->assertSame(1, $payload['invalid_flow_count']);
        $this->assertTrue($payload['schedulable']);
        $this->assertSame('registered', data_get($payload, 'scheduler_registration.status'));
        $this->assertSame(1, data_get($payload, 'scheduler_registration.registered_command_count'));
        $this->assertSame(config('app.timezone'), $payload['timezone']);
        $this->assertArrayHasKey('next_run_at', $payload);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['plan_hash']);
        $this->assertSame('sha256', $payload['plan_hash_algorithm']);
        $this->assertArrayNotHasKey('commands', $payload);
        $this->assertArrayNotHasKey('configured_flows', $payload);
        $this->assertSame(0, AtlasInitiativeRun::query()->count(), 'Schedule-health must not create initiative runs.');
    }

    public function test_schedule_health_can_fail_on_unhealthy_schedule_for_ci(): void
    {
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['unknown_flow', 'repair_loop_review']);
        config()->set('atlas_ai.self_improvement.time', '02:00');
        config()->set('atlas_ai.self_improvement.hours', 24);
        config()->set('atlas_ai.self_improvement.limit', 5);
        config()->set('atlas_ai.self_improvement.emit', false);

        $exit = Artisan::call('atlas:ai:self-improve', [
            '--schedule-health' => true,
            '--fail-on-schedule-warning' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('warning', data_get($payload, 'health.status'));
        $this->assertSame(0, AtlasInitiativeRun::query()->count(), 'Schedule health gate must not create initiative runs.');
    }

    public function test_schedule_health_human_output_includes_scheduler_registration(): void
    {
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['nightly_review', 'weekly_architecture_audit', 'repair_loop_review', 'kernel_pipeline_review']);
        config()->set('atlas_ai.self_improvement.time', '02:00');
        config()->set('atlas_ai.self_improvement.hours', 24);
        config()->set('atlas_ai.self_improvement.limit', 5);
        config()->set('atlas_ai.self_improvement.emit', false);

        $exit = Artisan::call('atlas:ai:self-improve', [
            '--schedule-health' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Schedulable', $output);
        $this->assertStringContainsString('Scheduler registration', $output);
        $this->assertStringContainsString('Registered commands', $output);
        $this->assertStringContainsString('registered', $output);
        $this->assertStringContainsString('4', $output);
    }

    public function test_schedule_plan_human_output_includes_skipped_scheduler_registration_reason(): void
    {
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['nightly_review', 'weekly_architecture_audit', 'repair_loop_review', 'kernel_pipeline_review']);
        config()->set('atlas_ai.self_improvement.time', '25:99');
        config()->set('atlas_ai.self_improvement.hours', 24);
        config()->set('atlas_ai.self_improvement.limit', 5);
        config()->set('atlas_ai.self_improvement.emit', false);

        $exit = Artisan::call('atlas:ai:self-improve', [
            '--schedule-plan' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Schedulable', $output);
        $this->assertStringContainsString('Scheduler registration', $output);
        $this->assertStringContainsString('Skipped reason', $output);
        $this->assertStringContainsString('skipped', $output);
        $this->assertStringContainsString('invalid_self_improvement_schedule_time', $output);
    }

    private function createTables(): void
    {
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

        Schema::create('atlas_initiative_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind', 48);
            $table->string('status', 24)->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('scope')->default('{}');
            $table->json('findings')->default('[]');
            $table->json('emitted_inbox_item_ids')->default('[]');
            $table->text('error_message')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }

    /**
     * @param  array<int,string>  $violations
     */
    private function recordKernelPipeline(
        string $eventId,
        string $envelopeId,
        LedgerEventType $type,
        string $status,
        string $surfaceId,
        string $inputMode,
        array $violations = [],
    ): void {
        AtlasLedgerEvent::query()->create([
            'event_id' => $eventId,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => $envelopeId,
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => $envelopeId,
            'causation_id' => null,
            'event_type' => $type->value,
            'emitter_stage' => 'atlas.ai_chat.kernel_pipeline_guard',
            'emitter_version' => 'test',
            'payload' => [
                'status' => $status,
                'pipeline' => [
                    'pipeline_id' => 'atlas.run.kernel_pipeline.v1',
                    'schema_version' => 'atlas.kernel_pipeline.v1',
                    'mode' => 'scaffold',
                    'stage_count' => 10,
                    'canonical_flow_hash' => hash('sha256', 'kernel-pipeline-self-improve'),
                    'provider_execution_allowed' => false,
                    'runtime_execution_allowed' => false,
                ],
                'surface' => [
                    'surface_id' => $surfaceId,
                    'binding_surface' => $surfaceId === 'atlas_cli_dev' ? 'atlas:cli:dev' : 'atlas:ai:chat',
                    'command' => $surfaceId === 'atlas_cli_dev' ? 'atlas dev' : 'atlas:ai:chat --dev',
                    'input_mode' => $inputMode,
                ],
                'routing' => [
                    'domain' => 'programming',
                    'flow' => 'programming.dev',
                    'runtime' => 'scaffold',
                ],
                'violations' => $violations,
            ],
            'payload_hash' => hash('sha256', $eventId),
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param  array<int,string>  $issues
     */
    private function recordSelfImprovementScheduleObservation(
        string $eventId,
        string $envelopeId,
        string $healthStatus,
        string $schedulerStatus,
        array $issues = [],
        int $invalidFlowCount = 0,
    ): void {
        AtlasLedgerEvent::query()->create([
            'event_id' => $eventId,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'default',
            'operator_id' => 'atlas_self_improvement',
            'envelope_id' => $envelopeId,
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => $envelopeId,
            'causation_id' => null,
            'event_type' => LedgerEventType::SelfImprovementScheduleObserved->value,
            'emitter_stage' => 'atlas.self_improvement',
            'emitter_version' => 'test',
            'payload' => [
                'flow' => 'self_improvement.weekly_architecture_audit',
                'schedule_health' => [
                    'schema_version' => 'atlas.self_improvement.schedule.v1',
                    'status' => $healthStatus === 'healthy' ? 'ok' : 'warning',
                    'health_status' => $healthStatus,
                    'issues' => $issues,
                    'enabled' => true,
                    'schedulable' => true,
                    'scheduler_registration' => [
                        'status' => $schedulerStatus,
                        'registered_command_count' => $schedulerStatus === 'registered' ? 4 : 0,
                    ],
                    'flow_count' => 4,
                    'cadence_counts' => ['daily' => 3, 'weekly' => 1],
                    'invalid_flow_count' => $invalidFlowCount,
                    'defaulted' => false,
                    'emit' => false,
                    'plan_hash' => hash('sha256', $eventId),
                    'plan_hash_algorithm' => 'sha256',
                    'time' => '02:00',
                    'timezone' => 'America/Sao_Paulo',
                    'next_run_at' => now()->addDay()->toJSON(),
                ],
            ],
            'payload_hash' => hash('sha256', $eventId),
            'occurred_at' => now()->subHours(2),
        ]);
    }
}
