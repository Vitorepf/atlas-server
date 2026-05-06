<?php

namespace Tests\Feature\Ai;

use App\Models\AiInboxItem;
use App\Models\AtlasInitiativeRun;
use App\Models\AtlasLedgerEvent;
use App\Models\AtlasOpenBrainAccessLog;
use App\Services\Ai\Kernel\Architecture\AtlasAiArchitectureValidationService;
use App\Services\Ai\Kernel\Architecture\AtlasArchitectureOperationsCatalog;
use App\Services\Ai\Kernel\Architecture\AtlasRivalsStrategyCaseRegistrar;
use App\Services\Ai\Kernel\Architecture\AtlasRivalsStrategyReviewRecorder;
use App\Services\Ai\Kernel\Decision\DecisionReceiptHash;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementInput;
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
        Schema::dropIfExists('atlas_open_brain_access_logs');
        Schema::dropIfExists('atlas_initiative_runs');
        Schema::dropIfExists('atlas_ledger_events');
        Schema::dropIfExists('atlas_strategy_rivals_reviews');
        Schema::dropIfExists('atlas_strategy_rivals_cases');

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

    public function test_command_plan_only_uses_shared_self_improvement_input_contract(): void
    {
        $exit = Artisan::call('atlas:ai:self-improve', [
            '--hours' => 999,
            '--limit' => 999,
            '--plan-only' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('planned', $payload['status']);
        $this->assertSame(
            AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS,
            data_get($payload, 'plan.options.hours'),
        );
        $this->assertSame(
            AtlasSelfImprovementRuntime::MAX_FINDINGS_PER_RUN,
            data_get($payload, 'plan.options.limit'),
        );
        $this->assertSame(AtlasSelfImprovementInput::DEFAULT_FINDINGS_LIMIT, app(AtlasSelfImprovementInput::class)->findingsLimit('bad'));
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

    public function test_provider_performance_review_emits_reviewable_policy_patch_candidate(): void
    {
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::ProviderReturned, [
            'schema_version' => 'atlas.provider_usage.v1',
            'provider_cli' => 'codex_cli',
            'model_name_if_available' => 'gpt-5.5',
            'domain' => 'programming',
            'flow' => 'programming.frontend',
            'task_type' => 'programming',
            'specialist_profile' => 'programming.frontend',
            'risk' => 'high',
            'phase' => 'returned',
            'exit_status' => 'failed',
            'failure_reason' => 'visual_regression',
            'selection_mode' => 'auto_best_allowed',
            'latency_seconds' => 91.2,
            'repair_count' => 2,
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'provider_perf_env_1',
            'correlation_id' => 'provider_perf_env_1',
            'emitter_stage' => 'ai.worker',
            'emitter_version' => 'test',
        ]);
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::ProviderFallback, [
            'schema_version' => 'atlas.provider_usage.v1',
            'provider_cli' => 'codex_cli',
            'model_name_if_available' => 'gpt-5.5',
            'domain' => 'programming',
            'flow' => 'programming.frontend',
            'task_type' => 'programming',
            'specialist_profile' => 'programming.frontend',
            'phase' => 'fallback',
            'exit_status' => 'fallback',
            'failure_reason' => 'provider_timeout',
            'fallback_provider' => 'claude_cli',
            'selection_mode' => 'auto_best_allowed',
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'provider_perf_env_2',
            'correlation_id' => 'provider_perf_env_2',
            'emitter_stage' => 'ai.worker',
            'emitter_version' => 'test',
        ]);

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'provider_performance_review',
            emit: false,
            hours: 24,
            limit: 5,
            filters: [
                'domain' => 'programming',
                'specialist_profile' => 'programming.frontend',
            ],
        );

        $finding = collect($result['findings'])->firstWhere('title', 'Revisar matriz empirica de providers');

        $this->assertIsArray($finding);
        $this->assertSame('atlas.self_improvement.provider_performance.v1', data_get($finding, 'metadata.schema_version'));
        $this->assertSame('open_reviewable_provider_policy_patch', data_get($finding, 'metadata.review_signal.recommended_action'));
        $this->assertSame('proposal_only', data_get($finding, 'metadata.policy_patch_candidate.status'));
        $this->assertSame('atlas_decide_model_selection_policy', data_get($finding, 'metadata.policy_patch_candidate.target'));
        $this->assertSame('programming.frontend', data_get($finding, 'source_refs.0.specialist_profile'));
        $this->assertSame(['programming.frontend' => 2], data_get($finding, 'metadata.specialist_profile_counts'));
        $this->assertSame(2, data_get($finding, 'metadata.unknown_cost_count'));
        $this->assertSame(['unknown' => 2], data_get($finding, 'metadata.cost_confidence_counts'));
        $this->assertSame('run_provider_benchmark', data_get($finding, 'metadata.available_actions.0.id'));
        $this->assertSame('draft_model_selection_policy_patch', data_get($finding, 'metadata.available_actions.1.id'));
        $this->assertSame('configure_provider_cost_rates', data_get($finding, 'metadata.available_actions.2.id'));
        $this->assertSame('run_provider_benchmark', data_get($finding, 'available_actions.0.id'));
    }

    public function test_provider_performance_review_emits_cost_rate_finding_when_quality_is_ok_but_cost_is_unknown(): void
    {
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::ProviderReturned, [
            'schema_version' => 'atlas.provider_usage.v1',
            'provider_cli' => 'codex_cli',
            'model_name_if_available' => 'gpt-5.5',
            'domain' => 'programming',
            'flow' => 'programming.frontend',
            'task_type' => 'programming',
            'specialist_profile' => 'programming.frontend',
            'risk' => 'medium',
            'phase' => 'returned',
            'exit_status' => 'succeeded',
            'failure_reason' => null,
            'selection_mode' => 'auto_best_allowed',
            'latency_seconds' => 8.1,
            'repair_count' => 0,
            'total_tokens' => 800,
            'estimated_tokens' => 800,
            'token_source' => 'estimated_chars',
            'cost_microusd' => null,
            'cost_confidence' => 'unknown',
            'cost_source' => 'missing_cost_rate',
            'cost_mode' => 'unknown',
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'provider_cost_env_1',
            'correlation_id' => 'provider_cost_env_1',
            'emitter_stage' => 'ai.worker',
            'emitter_version' => 'test',
        ]);

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'provider_performance_review',
            emit: false,
            hours: 24,
            limit: 5,
            filters: [
                'domain' => 'programming',
                'specialist_profile' => 'programming.frontend',
            ],
        );

        $policyFinding = collect($result['findings'])->firstWhere('title', 'Revisar matriz empirica de providers');
        $costFinding = collect($result['findings'])->firstWhere('title', 'Configurar rates de custo dos providers');

        $this->assertNull($policyFinding);
        $this->assertIsArray($costFinding);
        $this->assertSame('atlas.self_improvement.provider_cost_rates.v1', data_get($costFinding, 'metadata.schema_version'));
        $this->assertSame('configure_provider_cost_rates', data_get($costFinding, 'metadata.review_signal.recommended_action'));
        $this->assertSame(1, data_get($costFinding, 'metadata.unknown_cost_count'));
        $this->assertSame(['unknown' => 1], data_get($costFinding, 'metadata.cost_confidence_counts'));
        $this->assertSame('configure_provider_cost_rates', data_get($costFinding, 'available_actions.0.id'));
        $this->assertSame('atlas.provider_cost_rates.proposal.v1', data_get($costFinding, 'payload.provider_cost_rates.schema_version'));
        $this->assertSame('codex_cli', data_get($costFinding, 'source_refs.0.provider_cli'));
        $this->assertSame('missing_cost_rate', data_get($costFinding, 'source_refs.0.cost_source'));
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

    public function test_self_improvement_detects_rivals_strategy_cases_without_scores(): void
    {
        app(AtlasRivalsStrategyCaseRegistrar::class)->register([
            'title' => 'Escolher direcao do Atlas',
            'baseline_choice' => 'Decisao direta',
            'atlas_assisted_choice' => 'Revisao estrategica plan-only',
        ]);

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'domain_learning_review',
            emit: false,
            hours: 24,
            limit: 20,
        );

        $finding = collect($result['findings'])->firstWhere(
            'dedupe_key',
            'self-improvement:rivals-strategy:'.sha1('due-reviews:1'),
        );

        $this->assertIsArray($finding);
        $this->assertSame('Registrar revisitas pendentes do Rivals Strategy', $finding['title']);
        $this->assertSame('atlas.self_improvement.rivals_strategy.v1', data_get($finding, 'metadata.schema_version'));
        $this->assertSame('record_due_rivals_strategy_reviews', data_get($finding, 'metadata.review_signal.recommended_action'));
        $this->assertSame(1, data_get($finding, 'metadata.case_count'));
        $this->assertSame(0, data_get($finding, 'metadata.scored_review_count'));
        $this->assertSame(1, data_get($finding, 'metadata.due_review_count'));
        $this->assertSame('rivals_strategy_due_review', data_get($finding, 'source_refs.0.type'));
        $this->assertStringContainsString('record-review', data_get($finding, 'source_refs.0.record_command'));
        $this->assertSame('record_rivals_review', data_get($finding, 'available_actions.0.id'));
        $this->assertSame(1, data_get($finding, 'payload.rivals_strategy.due_review_count'));
        $this->assertStringContainsString('record-review', data_get($finding, 'payload.due_reviews.0.record_command'));
    }

    public function test_self_improvement_emits_rivals_due_review_payload_to_inbox(): void
    {
        app(AtlasRivalsStrategyCaseRegistrar::class)->register([
            'title' => 'Escolher direcao do Atlas',
            'baseline_choice' => 'Decisao direta',
            'atlas_assisted_choice' => 'Revisao estrategica plan-only',
        ]);

        $inboxItem = new AiInboxItem;
        $inboxItem->id = '00000000-0000-0000-0000-000000000321';
        $capturedPayload = null;

        $this->mock(ProposalInboxEmitter::class, function ($mock) use ($inboxItem, &$capturedPayload): void {
            $mock->shouldReceive('emit')
                ->andReturnUsing(function (array $payload) use ($inboxItem, &$capturedPayload): AiInboxItem {
                    if (($payload['dedupe_key'] ?? null) === 'self-improvement:rivals-strategy:'.sha1('due-reviews:1')) {
                        $capturedPayload = $payload;
                    }

                    return $inboxItem;
                });
        });

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'domain_learning_review',
            emit: true,
            hours: 24,
            limit: 20,
        );

        $learningEvent = AtlasLedgerEvent::query()
            ->where('envelope_id', 'self_improvement_run:'.$result['run_id'])
            ->where('event_type', LedgerEventType::LearningProposed->value)
            ->get()
            ->first(fn (AtlasLedgerEvent $event): bool => data_get($event->payload, 'finding.dedupe_key') === 'self-improvement:rivals-strategy:'.sha1('due-reviews:1'));

        $this->assertIsArray($capturedPayload);
        $this->assertSame('record_rivals_review', data_get($capturedPayload, 'available_actions.0.id'));
        $this->assertSame(1, data_get($capturedPayload, 'payload.rivals_strategy.due_review_count'));
        $this->assertStringContainsString('record-review', data_get($capturedPayload, 'payload.due_reviews.0.record_command'));
        $this->assertTrue((bool) data_get($learningEvent?->payload, 'emitted_to_inbox'));
        $this->assertSame($inboxItem->id, data_get($learningEvent?->payload, 'emitted_inbox_item_id'));
    }

    public function test_self_improvement_blocks_p4_when_rivals_agency_score_is_low(): void
    {
        $registration = app(AtlasRivalsStrategyCaseRegistrar::class)->register([
            'title' => 'Escolher direcao do Atlas',
            'baseline_choice' => 'Decisao direta',
            'atlas_assisted_choice' => 'Revisao estrategica plan-only',
        ]);
        app(AtlasRivalsStrategyReviewRecorder::class)->record([
            'case_id' => $registration['case_id'],
            'horizon_days' => 30,
            'regret_score' => 8,
            'alignment_score' => 90,
            'agency_score' => 55,
            'outcome_summary' => 'Resultado bom, mas o operador sentiu baixa agencia.',
        ]);

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'domain_learning_review',
            emit: false,
            hours: 24,
            limit: 20,
        );

        $finding = collect($result['findings'])->firstWhere(
            'dedupe_key',
            'self-improvement:rivals-strategy:'.sha1('agency-low:55'),
        );

        $this->assertIsArray($finding);
        $this->assertSame('Bloquear claims P4 por agency score baixo', $finding['title']);
        $this->assertSame(55.0, data_get($finding, 'metadata.average_agency_score'));
        $this->assertSame('high', data_get($finding, 'metadata.review_signal.severity'));
        $this->assertSame('pause_p4_claims_and_review_operator_agency', data_get($finding, 'metadata.review_signal.recommended_action'));
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

    public function test_self_improvement_domain_onboarding_filter_accepts_absent_scaffolds(): void
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
        $this->assertNull($finding);
        $this->assertSame(0, collect($result['findings'])->where('title', 'Priorizar dominios scaffold no roadmap de habilidades')->count());
    }

    public function test_self_improvement_detects_open_brain_retrieval_required_source_gaps(): void
    {
        AtlasOpenBrainAccessLog::query()->create([
            'surface' => 'atlas_cli_dev',
            'requester' => 'atlas_dev',
            'action' => 'context_injection',
            'status' => 'failed_closed',
            'workspace_hash' => 'workspace-hash',
            'workspace_label' => 'atlas-server',
            'context_pack_hash' => 'context-pack-hash',
            'context_refs_count' => 0,
            'memory_refs_count' => 2,
            'provider_safe' => true,
            'query_json' => ['input_hash' => hash('sha256', 'bug fix with high risk')],
            'result_summary_json' => [
                'warnings' => ['retrieval_required_source_unavailable'],
                'retrieval_plan' => [
                    'schema_version' => 'atlas.open_brain.retrieval_plan_summary.v1',
                    'required_unavailable_sources' => ['evidence_replay'],
                    'review_signal' => [
                        'status' => 'blocking',
                        'severity' => 'high',
                        'reason' => 'required_retrieval_source_unavailable',
                        'recommended_action' => 'refresh_evidence_replay_or_attach_trace_before_retry',
                    ],
                ],
            ],
            'metadata' => ['schema_version' => 'atlas.open_brain.access_log.v1'],
            'accessed_at' => now()->subMinutes(15),
        ]);

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'domain_learning_review',
            emit: false,
            hours: 24,
            limit: 20,
        );

        $finding = collect($result['findings'])->firstWhere(
            'dedupe_key',
            'self-improvement:open-brain-retrieval:'.sha1('refresh_evidence_replay_or_attach_trace_before_retry:evidence_replay'),
        );

        $this->assertSame('self_improvement.domain_learning_review', $result['flow']);
        $this->assertIsArray($finding);
        $this->assertSame('Corrigir fontes obrigatorias ausentes no Open Brain', $finding['title']);
        $this->assertSame('atlas.self_improvement.open_brain_retrieval.v1', data_get($finding, 'metadata.schema_version'));
        $this->assertSame('blocking', data_get($finding, 'metadata.review_signal.status'));
        $this->assertSame('high', data_get($finding, 'metadata.review_signal.severity'));
        $this->assertSame('refresh_evidence_replay_or_attach_trace_before_retry', data_get($finding, 'metadata.review_signal.recommended_action'));
        $this->assertSame(['evidence_replay'], data_get($finding, 'metadata.review_signal.required_unavailable_sources'));
        $this->assertSame(['failed_closed' => 1], data_get($finding, 'metadata.status_counts'));
        $this->assertSame(['evidence_replay' => 1], data_get($finding, 'metadata.required_unavailable_source_counts'));
        $this->assertSame('open_brain_access_log', data_get($finding, 'source_refs.0.type'));
        $this->assertSame('atlas_cli_dev', data_get($finding, 'source_refs.0.surface'));
        $this->assertSame(['evidence_replay'], data_get($finding, 'source_refs.0.required_unavailable_sources'));

        $learningEvent = AtlasLedgerEvent::query()
            ->where('envelope_id', 'self_improvement_run:'.$result['run_id'])
            ->where('event_type', LedgerEventType::LearningProposed->value)
            ->get()
            ->first(fn (AtlasLedgerEvent $event): bool => data_get($event->payload, 'finding.dedupe_key') === $finding['dedupe_key']);

        $this->assertInstanceOf(AtlasLedgerEvent::class, $learningEvent);
        $this->assertSame('atlas.self_improvement.open_brain_retrieval.v1', data_get($learningEvent->payload, 'finding.schema_version'));
        $this->assertSame('blocking', data_get($learningEvent->payload, 'finding.review_signal.status'));
        $this->assertSame('refresh_evidence_replay_or_attach_trace_before_retry', data_get($learningEvent->payload, 'finding.review_signal.recommended_action'));
        $this->assertSame(['open_brain_access_log'], data_get($learningEvent->payload, 'finding.source_types'));
    }

    public function test_learning_proposed_event_links_emitted_inbox_item_to_finding(): void
    {
        AtlasOpenBrainAccessLog::query()->create([
            'surface' => 'atlas_cli_dev',
            'requester' => 'atlas_dev',
            'action' => 'context_injection',
            'status' => 'failed_closed',
            'workspace_hash' => 'workspace-hash',
            'workspace_label' => 'atlas-server',
            'context_pack_hash' => 'context-pack-hash',
            'context_refs_count' => 0,
            'memory_refs_count' => 2,
            'provider_safe' => true,
            'query_json' => ['input_hash' => hash('sha256', 'high risk repair')],
            'result_summary_json' => [
                'warnings' => ['retrieval_required_source_unavailable'],
                'retrieval_plan' => [
                    'required_unavailable_sources' => ['evidence_replay'],
                    'review_signal' => [
                        'status' => 'blocking',
                        'severity' => 'high',
                        'reason' => 'required_retrieval_source_unavailable',
                        'recommended_action' => 'refresh_evidence_replay_or_attach_trace_before_retry',
                    ],
                ],
            ],
            'metadata' => ['schema_version' => 'atlas.open_brain.access_log.v1'],
            'accessed_at' => now()->subMinutes(10),
        ]);

        $inboxItem = new AiInboxItem;
        $inboxItem->id = '00000000-0000-0000-0000-000000000123';

        $this->mock(ProposalInboxEmitter::class, function ($mock) use ($inboxItem): void {
            $mock->shouldReceive('emit')
                ->andReturn($inboxItem);
        });

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'domain_learning_review',
            emit: true,
            hours: 24,
            limit: 20,
        );

        $finding = collect($result['findings'])->first(
            fn (array $finding): bool => str_starts_with((string) ($finding['dedupe_key'] ?? ''), 'self-improvement:open-brain-retrieval:')
        );
        $learningEvent = AtlasLedgerEvent::query()
            ->where('envelope_id', 'self_improvement_run:'.$result['run_id'])
            ->where('event_type', LedgerEventType::LearningProposed->value)
            ->get()
            ->first(fn (AtlasLedgerEvent $event): bool => data_get($event->payload, 'finding.dedupe_key') === $finding['dedupe_key']);
        $completedEvent = AtlasLedgerEvent::query()
            ->where('envelope_id', 'self_improvement_run:'.$result['run_id'])
            ->where('event_type', LedgerEventType::OperationCompleted->value)
            ->firstOrFail();

        $this->assertIsArray($finding);
        $this->assertInstanceOf(AtlasLedgerEvent::class, $learningEvent);
        $this->assertContains($inboxItem->id, $result['emitted_item_ids']);
        $this->assertTrue((bool) data_get($learningEvent->payload, 'emitted'));
        $this->assertTrue((bool) data_get($learningEvent->payload, 'emitted_to_inbox'));
        $this->assertSame($inboxItem->id, data_get($learningEvent->payload, 'emitted_inbox_item_id'));
        $this->assertSame($finding['dedupe_key'], data_get($learningEvent->payload, 'finding.dedupe_key'));
        $this->assertSame(count($result['emitted_item_ids']), data_get($completedEvent->payload, 'emitted_count'));
        $this->assertContains($inboxItem->id, data_get($completedEvent->payload, 'emitted_inbox_item_ids'));
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

    public function test_self_improvement_detects_ledger_projection_drift_from_architecture_validation(): void
    {
        $this->mock(AtlasAiArchitectureValidationService::class, function ($mock): void {
            $mock->shouldReceive('payload')->once()->andReturn([
                'schema_version' => 1,
                'status' => 'ok',
                'kernel' => [
                    'valid' => true,
                    'ledger_projections' => [
                        'drift' => [
                            'schema_version' => 'atlas.ledger_projection_drift.v1',
                            'available' => true,
                            'status' => 'attention_required',
                            'projection_count' => 3,
                            'drifted_count' => 1,
                            'attention_count' => 1,
                            'ledger_latest_occurred_at' => '2026-05-06T04:00:00.000000Z',
                            'projections' => [
                                [
                                    'id' => 'ai_traces',
                                    'table' => 'ai_traces',
                                    'status' => 'drift_detected',
                                    'source_event_count' => 12,
                                    'lag_seconds' => 300,
                                    'needs_attention' => true,
                                ],
                            ],
                        ],
                    ],
                    'static_scan' => [
                        'valid' => true,
                        'summary' => [
                            'total_count' => 40,
                            'passed_count' => 40,
                            'failed_count' => 0,
                            'failed_keys' => [],
                            'violation_count' => 0,
                        ],
                    ],
                ],
                'capabilities' => ['valid' => true],
                'domains' => ['valid' => true],
                'orchestrators' => ['valid' => true],
                'onboarding' => [],
                'validated_at' => '2026-05-06T04:01:00Z',
            ]);
        });

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'weekly_architecture_audit',
            emit: false,
            hours: 24,
            limit: 5,
        );

        $finding = collect($result['findings'])->firstWhere(
            'dedupe_key',
            'self-improvement:ledger-projection-drift:'.sha1('ai_traces:attention_required:1')
        );

        $this->assertSame('self_improvement.weekly_architecture_audit', $result['flow']);
        $this->assertIsArray($finding);
        $this->assertSame('Corrigir drift das projection tables do Evidence Ledger', $finding['title']);
        $this->assertSame('atlas.self_improvement.ledger_projection_drift.v1', data_get($finding, 'metadata.schema_version'));
        $this->assertSame('warning', data_get($finding, 'metadata.review_signal.status'));
        $this->assertSame('open_reviewable_ledger_projection_backfill_proposal', data_get($finding, 'metadata.review_signal.recommended_action'));
        $this->assertSame('run_ledger_projection', data_get($finding, 'available_actions.0.id'));
        $this->assertSame('attention_required', data_get($finding, 'payload.projection_health.status'));
        $this->assertSame(24, data_get($finding, 'payload.ledger_projection.hours'));
        $this->assertSame(500, data_get($finding, 'payload.ledger_projection.limit'));
        $this->assertSame(['ai_traces'], data_get($finding, 'metadata.projection_ids'));
        $this->assertSame('ledger_projection_drift', data_get($finding, 'source_refs.0.type'));
        $this->assertSame(300, data_get($finding, 'source_refs.0.lag_seconds'));
    }

    public function test_self_improvement_detects_oversized_active_documentation_from_architecture_validation(): void
    {
        $this->mock(AtlasAiArchitectureValidationService::class, function ($mock): void {
            $mock->shouldReceive('payload')->once()->andReturn([
                'schema_version' => 1,
                'status' => 'ok',
                'kernel' => [
                    'valid' => true,
                    'ledger_projections' => [
                        'drift' => ['available' => true, 'status' => 'ok', 'attention_count' => 0],
                    ],
                    'static_scan' => [
                        'valid' => true,
                        'summary' => [
                            'total_count' => 136,
                            'passed_count' => 136,
                            'failed_count' => 0,
                            'failed_keys' => [],
                            'violation_count' => 0,
                        ],
                    ],
                ],
                'documentation' => [
                    'valid' => true,
                    'status' => 'ok',
                    'summary' => [
                        'doc_count' => 64,
                        'oversized_count' => 2,
                        'required_missing_count' => 0,
                        'frontmatter_violation_count' => 0,
                    ],
                    'oversized_docs' => [
                        [
                            'path' => 'docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md',
                            'line_count' => 4020,
                            'limit' => 300,
                            'status' => 'split_required',
                            'recommended_action' => 'split this active doc into focused specs before adding new responsibilities',
                        ],
                        [
                            'path' => 'docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md',
                            'line_count' => 3599,
                            'limit' => 300,
                            'status' => 'split_required_grandfathered',
                            'recommended_action' => 'split Kernel APs into focused contract specs before adding new sections',
                        ],
                    ],
                    'violations' => [],
                ],
                'capabilities' => ['valid' => true],
                'domains' => ['valid' => true],
                'orchestrators' => ['valid' => true],
                'onboarding' => [],
                'validated_at' => '2026-05-06T05:00:00Z',
            ]);
        });

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'docs_drift_review',
            emit: false,
            hours: 24,
            limit: 5,
        );

        $finding = collect($result['findings'])->firstWhere(
            'dedupe_key',
            'self-improvement:documentation-health:'.sha1('docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md:1')
        );

        $this->assertIsArray($finding);
        $this->assertSame('Dividir documentacao ativa acima do limite de contexto', $finding['title']);
        $this->assertSame('atlas.self_improvement.documentation_health_gap.v1', data_get($finding, 'metadata.schema_version'));
        $this->assertSame(2, data_get($finding, 'metadata.oversized_count'));
        $this->assertSame(1, data_get($finding, 'metadata.split_required_count'));
        $this->assertSame('split_oversized_active_docs', data_get($finding, 'payload.recommended_action'));
        $this->assertSame('docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md', data_get($finding, 'source_refs.0.id'));
    }

    public function test_self_improvement_emits_ledger_projection_drift_proposal_with_assisted_action(): void
    {
        $this->mock(AtlasAiArchitectureValidationService::class, function ($mock): void {
            $mock->shouldReceive('payload')->once()->andReturn([
                'schema_version' => 1,
                'status' => 'ok',
                'kernel' => [
                    'valid' => true,
                    'ledger_projections' => [
                        'drift' => [
                            'schema_version' => 'atlas.ledger_projection_drift.v1',
                            'available' => true,
                            'status' => 'attention_required',
                            'projection_count' => 3,
                            'drifted_count' => 1,
                            'attention_count' => 1,
                            'ledger_latest_occurred_at' => '2026-05-06T04:00:00.000000Z',
                            'projections' => [
                                [
                                    'id' => 'atlas_tool_runs',
                                    'table' => 'atlas_tool_runs',
                                    'status' => 'missing_projection_rows',
                                    'source_event_count' => 7,
                                    'lag_seconds' => 900,
                                    'needs_attention' => true,
                                ],
                            ],
                        ],
                    ],
                    'static_scan' => [
                        'valid' => true,
                        'summary' => [
                            'total_count' => 40,
                            'passed_count' => 40,
                            'failed_count' => 0,
                            'failed_keys' => [],
                            'violation_count' => 0,
                        ],
                    ],
                ],
                'capabilities' => ['valid' => true],
                'domains' => ['valid' => true],
                'orchestrators' => ['valid' => true],
                'onboarding' => [],
                'validated_at' => '2026-05-06T04:01:00Z',
            ]);
        });

        $inboxItem = new AiInboxItem;
        $inboxItem->id = '00000000-0000-0000-0000-000000000142';
        $capturedPayload = null;

        $this->mock(ProposalInboxEmitter::class, function ($mock) use ($inboxItem, &$capturedPayload): void {
            $mock->shouldReceive('emit')
                ->andReturnUsing(function (array $payload) use ($inboxItem, &$capturedPayload): AiInboxItem {
                    if (($payload['dedupe_key'] ?? null) === 'self-improvement:ledger-projection-drift:'.sha1('atlas_tool_runs:attention_required:1')) {
                        $capturedPayload = $payload;

                        return $inboxItem;
                    }

                    $other = new AiInboxItem;
                    $other->id = '00000000-0000-0000-0000-'.substr(hash('sha256', (string) ($payload['dedupe_key'] ?? 'unknown')), 0, 12);

                    return $other;
                });
        });

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'weekly_architecture_audit',
            emit: true,
            hours: 24,
            limit: 10,
        );

        $learningEvent = AtlasLedgerEvent::query()
            ->where('envelope_id', 'self_improvement_run:'.$result['run_id'])
            ->where('event_type', LedgerEventType::LearningProposed->value)
            ->get()
            ->first(fn (AtlasLedgerEvent $event): bool => data_get($event->payload, 'finding.dedupe_key') === 'self-improvement:ledger-projection-drift:'.sha1('atlas_tool_runs:attention_required:1'));

        $this->assertFalse($result['dry_run']);
        $this->assertContains($inboxItem->id, $result['emitted_item_ids']);
        $this->assertIsArray($capturedPayload);
        $this->assertSame('run_ledger_projection', data_get($capturedPayload, 'available_actions.0.id'));
        $this->assertSame('attention_required', data_get($capturedPayload, 'payload.projection_health.status'));
        $this->assertSame(24, data_get($capturedPayload, 'payload.ledger_projection.hours'));
        $this->assertSame(500, data_get($capturedPayload, 'payload.ledger_projection.limit'));
        $this->assertSame('atlas.self_improvement.ledger_projection_drift.v1', data_get($capturedPayload, 'metadata.schema_version'));
        $this->assertSame('open_reviewable_ledger_projection_backfill_proposal', data_get($capturedPayload, 'metadata.review_signal.recommended_action'));
        $this->assertInstanceOf(AtlasLedgerEvent::class, $learningEvent);
        $this->assertTrue((bool) data_get($learningEvent->payload, 'emitted_to_inbox'));
        $this->assertSame($inboxItem->id, data_get($learningEvent->payload, 'emitted_inbox_item_id'));
        $this->assertSame('atlas.self_improvement.ledger_projection_drift.v1', data_get($learningEvent->payload, 'finding.schema_version'));
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

    public function test_self_improvement_detects_architecture_operations_catalog_drift(): void
    {
        $this->app->instance(AtlasArchitectureOperationsCatalog::class, new AtlasArchitectureOperationsCatalog(commandsOverride: [
            [
                'command' => 'atlas ai architecture-validate',
                'description' => 'Temporary drifted catalog for test.',
            ],
        ]));

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'weekly_architecture_audit',
            emit: false,
            hours: 24,
            limit: 10,
        );

        $finding = collect($result['findings'])->firstWhere(
            'dedupe_key',
            'self-improvement:architecture-operations:'.sha1('arquitetura_mae:atlas ai architecture-operations --json,atlas engineering knowledge docs-health --json,atlas engineering knowledge sync --prune --json,atlas engineering knowledge index-code --prune --json,atlas ai slo --hours=24 --json,atlas ai kernel-pipeline-report --hours=24 --json,atlas ai repair-report --hours=24 --json,atlas ai provider-performance --hours=24 --json,atlas ai qualitative-levels --hours=720 --json,atlas ai rivals-strategy report --hours=8760 --json,atlas ai rivals-strategy due-reviews --due-days=30 --json,atlas ai strategic-decision review --json,atlas ai decision-receipt-report --envelope=<id> --json,atlas ledger replay --envelope=<id> --json,atlas ai ledger-project --limit=500 --json,atlas ai self-improvement-schedule-report --hours=24 --json,atlas ai inbox-action-report --hours=24 --json:1:1')
        );

        $this->assertSame('self_improvement.weekly_architecture_audit', $result['flow']);
        $this->assertIsArray($finding);
        $this->assertSame('Corrigir catalogo operacional da arquitetura mae', $finding['title']);
        $this->assertSame('atlas.self_improvement.architecture_operations.v1', data_get($finding, 'metadata.schema_version'));
        $this->assertSame('arquitetura_mae', data_get($finding, 'metadata.section'));
        $this->assertSame(1, data_get($finding, 'metadata.command_count'));
        $this->assertSame(1, data_get($finding, 'metadata.actual_command_count'));
        $this->assertFalse((bool) data_get($finding, 'metadata.count_mismatch'));
        $this->assertContains('atlas ai architecture-operations --json', data_get($finding, 'metadata.missing_commands'));
        $this->assertContains('atlas engineering knowledge docs-health --json', data_get($finding, 'metadata.missing_commands'));
        $this->assertContains('atlas engineering knowledge sync --prune --json', data_get($finding, 'metadata.missing_commands'));
        $this->assertContains('atlas engineering knowledge index-code --prune --json', data_get($finding, 'metadata.missing_commands'));
        $this->assertContains('atlas ai decision-receipt-report --envelope=<id> --json', data_get($finding, 'metadata.missing_commands'));
        $this->assertContains('atlas ai qualitative-levels --hours=720 --json', data_get($finding, 'metadata.missing_commands'));
        $this->assertContains('atlas ai rivals-strategy report --hours=8760 --json', data_get($finding, 'metadata.missing_commands'));
        $this->assertContains('atlas ai rivals-strategy due-reviews --due-days=30 --json', data_get($finding, 'metadata.missing_commands'));
        $this->assertContains('atlas ai strategic-decision review --json', data_get($finding, 'metadata.missing_commands'));
        $this->assertContains('atlas ledger replay --envelope=<id> --json', data_get($finding, 'metadata.missing_commands'));
        $this->assertContains('atlas ai ledger-project --limit=500 --json', data_get($finding, 'metadata.missing_commands'));
        $this->assertContains('atlas ai inbox-action-report --hours=24 --json', data_get($finding, 'metadata.missing_commands'));
        $this->assertSame('warning', data_get($finding, 'metadata.review_signal.status'));
        $this->assertSame('high', data_get($finding, 'metadata.review_signal.severity'));
        $this->assertSame('restore_architecture_operations_catalog', data_get($finding, 'metadata.review_signal.recommended_action'));
        $this->assertSame('missing_architecture_operation', data_get($finding, 'source_refs.0.type'));
        $this->assertSame('atlas ai architecture-operations --json', data_get($finding, 'source_refs.0.id'));
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

    public function test_self_improvement_detects_schedule_replay_missing_inbox_refs(): void
    {
        config()->set('app.timezone', 'America/Sao_Paulo');
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['nightly_review', 'weekly_architecture_audit', 'repair_loop_review', 'kernel_pipeline_review']);
        config()->set('atlas_ai.self_improvement.time', '02:00');

        $this->recordSelfImprovementScheduleObservation(
            eventId: '01HSELFREPLAYINBOXGAP01',
            envelopeId: 'self_improvement_run:missing_inbox_ref',
            healthStatus: 'healthy',
            schedulerStatus: 'registered',
        );
        $this->recordSelfImprovementCompletion(
            eventId: '01HSELFREPLAYINBOXGAP02',
            envelopeId: 'self_improvement_run:missing_inbox_ref',
            emittedInboxItemIds: ['00000000-0000-0000-0000-000000000998'],
        );

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'weekly_architecture_audit',
            emit: false,
            hours: 24,
            limit: 10,
        );

        $finding = collect($result['findings'])->firstWhere(
            'dedupe_key',
            'self-improvement:schedule-replay-inbox-gap:'.sha1('1:00000000-0000-0000-0000-000000000998')
        );

        $this->assertSame('self_improvement.weekly_architecture_audit', $result['flow']);
        $this->assertIsArray($finding);
        $this->assertSame('Restaurar propostas do Inbox emitidas pelo Self-Improvement', $finding['title']);
        $this->assertSame('atlas.self_improvement.schedule_replay_inbox_gap.v1', data_get($finding, 'metadata.schema_version'));
        $this->assertSame(1, data_get($finding, 'metadata.missing_count'));
        $this->assertSame(['00000000-0000-0000-0000-000000000998'], data_get($finding, 'metadata.emitted_inbox_item_missing_ids'));
        $this->assertFalse((bool) data_get($finding, 'metadata.emitted_inbox_item_hydration_available'));
        $this->assertSame('warning', data_get($finding, 'metadata.review_signal.status'));
        $this->assertSame('medium', data_get($finding, 'metadata.review_signal.severity'));
        $this->assertSame('restore_or_reemit_missing_self_improvement_inbox_items', data_get($finding, 'metadata.review_signal.recommended_action'));
        $this->assertSame('self_improvement_run:missing_inbox_ref', data_get($finding, 'source_refs.0.envelope_id'));
        $this->assertSame(['00000000-0000-0000-0000-000000000998'], data_get($finding, 'source_refs.0.emitted_inbox_item_missing_ids'));
    }

    public function test_self_improvement_emits_schedule_replay_missing_inbox_ref_proposal(): void
    {
        config()->set('app.timezone', 'America/Sao_Paulo');
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['nightly_review', 'weekly_architecture_audit', 'repair_loop_review', 'kernel_pipeline_review']);
        config()->set('atlas_ai.self_improvement.time', '02:00');

        $this->recordSelfImprovementScheduleObservation(
            eventId: '01HSELFREPLAYINBOXEMIT01',
            envelopeId: 'self_improvement_run:missing_inbox_emit',
            healthStatus: 'healthy',
            schedulerStatus: 'registered',
        );
        $this->recordSelfImprovementCompletion(
            eventId: '01HSELFREPLAYINBOXEMIT02',
            envelopeId: 'self_improvement_run:missing_inbox_emit',
            emittedInboxItemIds: ['00000000-0000-0000-0000-000000000997'],
        );

        $inboxItem = new AiInboxItem;
        $inboxItem->id = '00000000-0000-0000-0000-000000000777';
        $targetDedupeKey = 'self-improvement:schedule-replay-inbox-gap:'.sha1('1:00000000-0000-0000-0000-000000000997');
        $targetPayload = null;

        $this->mock(ProposalInboxEmitter::class, function ($mock) use ($inboxItem, $targetDedupeKey, &$targetPayload): void {
            $mock->shouldReceive('emit')
                ->andReturnUsing(function (array $payload) use ($inboxItem, $targetDedupeKey, &$targetPayload): AiInboxItem {
                    if (($payload['dedupe_key'] ?? null) === $targetDedupeKey) {
                        $targetPayload = $payload;

                        return $inboxItem;
                    }

                    $other = new AiInboxItem;
                    $other->id = '00000000-0000-0000-0000-'.substr(hash('sha256', (string) ($payload['dedupe_key'] ?? 'unknown')), 0, 12);

                    return $other;
                });
        });

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'weekly_architecture_audit',
            emit: true,
            hours: 24,
            limit: 10,
        );

        $finding = collect($result['findings'])->firstWhere(
            'dedupe_key',
            'self-improvement:schedule-replay-inbox-gap:'.sha1('1:00000000-0000-0000-0000-000000000997')
        );
        $learningEvent = AtlasLedgerEvent::query()
            ->where('envelope_id', 'self_improvement_run:'.$result['run_id'])
            ->where('event_type', LedgerEventType::LearningProposed->value)
            ->get()
            ->first(fn (AtlasLedgerEvent $event): bool => data_get($event->payload, 'finding.dedupe_key') === data_get($finding, 'dedupe_key'));
        $completedEvent = AtlasLedgerEvent::query()
            ->where('envelope_id', 'self_improvement_run:'.$result['run_id'])
            ->where('event_type', LedgerEventType::OperationCompleted->value)
            ->firstOrFail();

        $this->assertIsArray($finding);
        $this->assertIsArray($targetPayload);
        $this->assertSame('atlas.self_improvement.schedule_replay_inbox_gap.v1', data_get($targetPayload, 'metadata.schema_version'));
        $this->assertSame('restore_or_reemit_missing_self_improvement_inbox_items', data_get($targetPayload, 'metadata.review_signal.recommended_action'));
        $this->assertSame('00000000-0000-0000-0000-000000000997', data_get($targetPayload, 'source_refs.0.emitted_inbox_item_missing_ids.0'));
        $this->assertFalse($result['dry_run']);
        $this->assertContains($inboxItem->id, $result['emitted_item_ids']);
        $this->assertInstanceOf(AtlasLedgerEvent::class, $learningEvent);
        $this->assertTrue((bool) data_get($learningEvent->payload, 'emitted_to_inbox'));
        $this->assertSame($inboxItem->id, data_get($learningEvent->payload, 'emitted_inbox_item_id'));
        $this->assertSame('atlas.self_improvement.schedule_replay_inbox_gap.v1', data_get($learningEvent->payload, 'finding.schema_version'));
        $this->assertSame('restore_or_reemit_missing_self_improvement_inbox_items', data_get($learningEvent->payload, 'finding.review_signal.recommended_action'));
        $this->assertSame(count($result['emitted_item_ids']), data_get($completedEvent->payload, 'emitted_count'));
        $this->assertContains($inboxItem->id, data_get($completedEvent->payload, 'emitted_inbox_item_ids'));
    }

    public function test_self_improvement_detects_inbox_action_replay_patch_review_gap(): void
    {
        $this->recordInboxActionEvent(
            eventId: '01HINBOXACTIONGAP000000001',
            inboxItemId: 'inbox-action-gap-1',
            action: 'review_patch',
            actorType: 'operator_cli',
            category: 'self_improvement',
            severity: 'high',
            recommendedAction: 'review_without_patch_context',
        );

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'self_improvement.weekly_architecture_audit',
            emit: false,
            hours: 24,
            limit: 10,
            filters: ['action' => 'review_patch'],
        );

        $finding = collect($result['findings'])
            ->firstWhere('dedupe_key', 'self-improvement:inbox-action-replay:'.sha1('24:review_patch_action_without_diff_refs'));

        $this->assertIsArray($finding);
        $this->assertSame('atlas.self_improvement.inbox_action_replay_gap.v1', data_get($finding, 'metadata.schema_version'));
        $this->assertSame(1, data_get($finding, 'metadata.reviewed_patch_count'));
        $this->assertSame(0, data_get($finding, 'metadata.with_diff_refs_count'));
        $this->assertSame('warning', data_get($finding, 'metadata.review_signal.status'));
        $this->assertSame('medium', data_get($finding, 'metadata.review_signal.severity'));
        $this->assertSame('open_reviewable_inbox_action_evidence_proposal', data_get($finding, 'metadata.review_signal.recommended_action'));
        $this->assertContains('review_patch_action_without_diff_refs', data_get($finding, 'metadata.review_signal.reasons'));
        $this->assertSame('inbox-action-gap-1', data_get($finding, 'source_refs.0.inbox_item_id'));
        $this->assertSame(['action' => 'review_patch'], data_get($finding, 'metadata.filters'));
    }

    public function test_self_improvement_detects_rivals_review_action_without_scores(): void
    {
        $this->recordInboxActionEvent(
            eventId: '01HINBOXRIVALSNOSCORE0001',
            inboxItemId: 'inbox-rivals-no-score-1',
            action: 'record_rivals_review',
            actorType: 'operator_cli',
            category: 'self_improvement',
            severity: 'medium',
            recommendedAction: 'record_due_rivals_strategy_reviews',
        );

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'self_improvement.weekly_architecture_audit',
            emit: false,
            hours: 24,
            limit: 10,
            filters: ['action' => 'record_rivals_review'],
        );

        $finding = collect($result['findings'])
            ->firstWhere('dedupe_key', 'self-improvement:inbox-action-replay:'.sha1('24:record_rivals_review_action_without_scores'));

        $this->assertIsArray($finding);
        $this->assertSame('Corrigir revisao do Rivals Strategy sem scores humanos', $finding['title']);
        $this->assertSame('atlas.self_improvement.inbox_action_replay_gap.v1', data_get($finding, 'metadata.schema_version'));
        $this->assertSame('record_rivals_review_action_without_scores', data_get($finding, 'metadata.gap_type'));
        $this->assertSame(1, data_get($finding, 'metadata.rivals_review_recorded_count'));
        $this->assertSame(0, data_get($finding, 'metadata.rivals_review_with_scores_count'));
        $this->assertContains('record_rivals_review_action_without_scores', data_get($finding, 'metadata.review_signal.reasons'));
        $this->assertSame('record_rivals_review', data_get($finding, 'source_refs.0.action'));
        $this->assertSame('record_due_rivals_strategy_reviews', data_get($finding, 'source_refs.0.recommended_action'));
        $this->assertNull(data_get($finding, 'source_refs.0.rivals_agency_score'));
        $this->assertSame(['action' => 'record_rivals_review'], data_get($finding, 'metadata.filters'));
    }

    public function test_self_improvement_detects_decision_receipt_replay_hash_gap(): void
    {
        $this->recordDecisionReceiptEvent(
            eventId: '01HDECISIONSELFGAP00000001',
            envelopeId: 'env_decision_self_gap',
            receiptId: 'receipt_self_gap',
            payloadOverrides: [
                'chain_hash' => 'tampered-self-improvement-chain',
            ],
        );

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'self_improvement.weekly_architecture_audit',
            emit: false,
            hours: 24,
            limit: 10,
            filters: ['domain' => 'programming', 'provider' => 'codex_cli'],
        );

        $finding = collect($result['findings'])
            ->firstWhere('dedupe_key', 'self-improvement:decision-receipt-replay:'.sha1('env_decision_self_gap:decision_receipt_chain_hash_mismatch'));

        $this->assertIsArray($finding);
        $this->assertSame('atlas.self_improvement.decision_receipt_replay_gap.v1', data_get($finding, 'metadata.schema_version'));
        $this->assertSame(1, data_get($finding, 'metadata.envelope_count'));
        $this->assertSame(1, data_get($finding, 'metadata.decision_event_count'));
        $this->assertSame(1, data_get($finding, 'metadata.invalid_count'));
        $this->assertSame('breach', data_get($finding, 'metadata.review_signal.status'));
        $this->assertSame('high', data_get($finding, 'metadata.review_signal.severity'));
        $this->assertSame('open_reviewable_decision_receipt_replay_proposal', data_get($finding, 'metadata.review_signal.recommended_action'));
        $this->assertContains('decision_receipt_chain_hash_mismatch', data_get($finding, 'metadata.review_signal.reasons'));
        $this->assertSame('env_decision_self_gap', data_get($finding, 'source_refs.0.envelope_id'));
        $this->assertSame('receipt_self_gap', data_get($finding, 'source_refs.0.receipt_id'));
        $this->assertSame('mismatch', data_get($finding, 'source_refs.0.chain_integrity_status'));
        $this->assertSame(['domain' => 'programming', 'provider' => 'codex_cli'], data_get($finding, 'metadata.filters'));
    }

    public function test_self_improvement_emits_decision_receipt_replay_hash_gap_proposal(): void
    {
        $this->recordDecisionReceiptEvent(
            eventId: '01HDECISIONSELFEMIT0000001',
            envelopeId: 'env_decision_self_emit',
            receiptId: 'receipt_self_emit',
            payloadOverrides: [
                'receipt_hash' => 'tampered-self-improvement-receipt',
            ],
        );

        $inboxItem = new AiInboxItem;
        $inboxItem->id = '00000000-0000-0000-0000-000000000776';
        $targetPayload = null;

        $this->mock(ProposalInboxEmitter::class, function ($mock) use ($inboxItem, &$targetPayload): void {
            $mock->shouldReceive('emit')
                ->andReturnUsing(function (array $payload) use ($inboxItem, &$targetPayload): AiInboxItem {
                    if (data_get($payload, 'metadata.schema_version') === 'atlas.self_improvement.decision_receipt_replay_gap.v1') {
                        $targetPayload = $payload;
                    }

                    return $inboxItem;
                });
        });

        $result = app(AtlasSelfImprovementRuntime::class)->nightlyReview(
            flow: 'self_improvement.weekly_architecture_audit',
            emit: true,
            hours: 24,
            limit: 10,
            filters: ['domain' => 'programming', 'provider' => 'codex_cli'],
        );

        $learningEvent = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::LearningProposed->value)
            ->where('envelope_id', 'like', 'self_improvement_run:%')
            ->get()
            ->first(fn (AtlasLedgerEvent $event): bool => data_get($event->payload, 'finding.schema_version') === 'atlas.self_improvement.decision_receipt_replay_gap.v1');
        $completedEvent = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::OperationCompleted->value)
            ->where('envelope_id', 'like', 'self_improvement_run:%')
            ->latest('occurred_at')
            ->first();

        $this->assertSame(false, $result['dry_run']);
        $this->assertContains($inboxItem->id, $result['emitted_item_ids']);
        $this->assertIsArray($targetPayload);
        $this->assertStringStartsWith('self-improvement:decision-receipt-replay:', $targetPayload['dedupe_key']);
        $this->assertSame('atlas.self_improvement.decision_receipt_replay_gap.v1', data_get($targetPayload, 'metadata.schema_version'));
        $this->assertSame('open_reviewable_decision_receipt_replay_proposal', data_get($targetPayload, 'metadata.review_signal.recommended_action'));
        $this->assertContains('decision_receipt_hash_mismatch', data_get($targetPayload, 'metadata.review_signal.reasons'));
        $this->assertSame('env_decision_self_emit', data_get($targetPayload, 'source_refs.0.envelope_id'));
        $this->assertSame('receipt_self_emit', data_get($targetPayload, 'source_refs.0.receipt_id'));
        $this->assertSame('mismatch', data_get($targetPayload, 'source_refs.0.receipt_integrity_status'));
        $this->assertTrue((bool) data_get($learningEvent?->payload, 'emitted_to_inbox'));
        $this->assertSame($inboxItem->id, data_get($learningEvent?->payload, 'emitted_inbox_item_id'));
        $this->assertSame('atlas.self_improvement.decision_receipt_replay_gap.v1', data_get($learningEvent?->payload, 'finding.schema_version'));
        $this->assertContains($inboxItem->id, data_get($completedEvent?->payload, 'emitted_inbox_item_ids'));
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

        $this->assertNull($finding);
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

        Schema::create('atlas_open_brain_access_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('surface', 80)->index();
            $table->string('requester', 120)->nullable()->index();
            $table->string('action', 80)->index();
            $table->string('status', 40)->index();
            $table->string('workspace_hash', 64)->nullable()->index();
            $table->string('workspace_label', 160)->nullable();
            $table->string('context_pack_hash', 64)->nullable()->index();
            $table->unsignedInteger('context_refs_count')->default(0);
            $table->unsignedInteger('memory_refs_count')->default(0);
            $table->boolean('provider_safe')->default(true);
            $table->json('query_json')->nullable();
            $table->json('result_summary_json')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('accessed_at')->nullable()->index();
            $table->timestamps();
        });

        (require database_path('migrations/2026_05_06_120000_create_atlas_strategy_rivals_tables.php'))->up();
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

    /**
     * @param  array<int,string>  $emittedInboxItemIds
     */
    private function recordSelfImprovementCompletion(string $eventId, string $envelopeId, array $emittedInboxItemIds): void
    {
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
            'event_type' => LedgerEventType::OperationCompleted->value,
            'emitter_stage' => 'atlas.self_improvement',
            'emitter_version' => 'test',
            'payload' => [
                'flow' => 'self_improvement.weekly_architecture_audit',
                'finding_count' => count($emittedInboxItemIds),
                'emitted_count' => count($emittedInboxItemIds),
                'emitted_inbox_item_ids' => $emittedInboxItemIds,
            ],
            'payload_hash' => hash('sha256', $eventId),
            'occurred_at' => now()->subHour(),
        ]);
    }

    private function recordInboxActionEvent(
        string $eventId,
        string $inboxItemId,
        string $action,
        string $actorType,
        string $category,
        string $severity,
        string $recommendedAction,
    ): void {
        AtlasLedgerEvent::query()->create([
            'event_id' => $eventId,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'default',
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
                    'id' => null,
                ],
                'result' => [
                    'payload' => [
                        'action' => $action,
                        'diff_refs' => [],
                    ],
                ],
                'review_signal' => [
                    'status' => 'warning',
                    'severity' => $severity,
                    'recommended_action' => $recommendedAction,
                ],
                'recommended_action' => $recommendedAction,
            ],
            'payload_hash' => hash('sha256', $eventId),
            'occurred_at' => now()->subHour(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $payloadOverrides
     */
    private function recordDecisionReceiptEvent(
        string $eventId,
        string $envelopeId,
        string $receiptId,
        ?string $parentReceiptId = null,
        ?string $parentChainHash = null,
        array $payloadOverrides = [],
    ): array {
        $payload = [
            'schema_version' => 'atlas.decide.v2',
            'receipt_id' => $receiptId,
            'envelope_id' => $envelopeId,
            'issued_at' => now()->toJSON(),
            'expires_at' => now()->addSeconds(30)->toJSON(),
            'dry_run' => false,
            'signed_by' => 'atlas-decide-v2',
            'provider_selection' => ['primary' => 'codex_cli', 'provider' => 'codex_cli', 'model' => 'gpt-5.2'],
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'risk' => 'medium',
            'budget' => ['max_cost_usd' => 1.0],
            'quality_gates' => ['tests'],
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
            'tenant_id' => 'default',
            'operator_id' => 'atlas_decide',
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
            'occurred_at' => now()->subHour(),
        ]);

        return $payload;
    }
}
