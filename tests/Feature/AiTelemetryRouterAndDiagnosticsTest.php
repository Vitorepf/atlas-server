<?php

namespace Tests\Feature;

use App\Models\AiDecision;
use App\Models\AiJob;
use App\Models\AiProviderCostRate;
use App\Models\AiRouterDecision;
use App\Models\AiSpecialistFlowExecution;
use App\Models\AiTelemetryEvent;
use App\Models\AiTrace;
use App\Models\AiTraceMetricSummary;
use App\Services\Ai\Telemetry\AiTelemetryScorecardService;
use App\Services\Ai\Telemetry\AiTraceMetricAggregator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fix 7a + 7b: aggregator now consumes ai_router_decisions and surfaces
 * event_phase / numeric_value diagnostics. These tests pin the contract:
 *   - Router columns populated on summary
 *   - Scorecard exposes by_router_mode + router_override_rate
 *   - score_components.router carries the decision attribution
 *   - score_components.diagnostics.events_by_phase counts events by phase
 *   - score_components.diagnostics.numeric_signals captures CLI numeric metrics
 */
class AiTelemetryRouterAndDiagnosticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMinimalSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
            'ai_trace_metric_summaries',
            'ai_outcome_links',
            'ai_provider_cost_rates',
            'ai_specialist_flow_executions',
            'ai_decisions',
            'ai_router_decisions',
            'ai_telemetry_events',
            'ai_stream_events',
            'ai_context_snapshots',
            'ai_quality_actions',
            'ai_quality_evaluations',
            'ai_job_attempts',
            'ai_jobs',
            'ai_traces',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_aggregator_populates_router_columns_from_router_decision(): void
    {
        $trace = $this->seedTrace('openai', 'gpt-4o', 1000, 200);
        $this->seedRate('openai', 'gpt-4o', 5000, 15000);

        AiRouterDecision::query()->create([
            'trace_id' => $trace->id,
            'mode' => 'council',
            'selected_provider' => 'openai',
            'fallback_provider' => 'anthropic',
            'signals' => ['critical' => true, 'execution_policy' => 'dual_review'],
            'reason' => 'Critical task requested council review.',
            'was_overridden' => false,
        ]);

        $summary = app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);

        $this->assertSame('council', $summary->router_mode);
        $this->assertSame('openai', $summary->router_selected_provider);
        $this->assertSame('anthropic', $summary->router_fallback_provider);
        $this->assertFalse($summary->router_was_overridden);
    }

    public function test_aggregator_handles_trace_without_router_decision(): void
    {
        $trace = $this->seedTrace('openai', 'gpt-4o', 100, 50);
        $this->seedRate('openai', 'gpt-4o', 5000, 15000);

        $summary = app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);

        $this->assertNull($summary->router_mode,
            'Trace without router decision must produce null router_mode (not crash).');
        $this->assertFalse($summary->router_was_overridden,
            'router_was_overridden defaults to false when no decision exists.');

        $components = $summary->score_components;
        $this->assertSame(false, $components['router']['available'] ?? null,
            'score_components.router.available must be false when the relation is missing.');
    }

    public function test_router_diagnostics_in_score_components_carry_signals_and_reason(): void
    {
        $trace = $this->seedTrace('claude_cli', 'cli-model', 500, 100);
        $this->seedRate('claude_cli', 'cli-model', 1000, 2000);

        AiRouterDecision::query()->create([
            'trace_id' => $trace->id,
            'mode' => 'fast',
            'selected_provider' => 'claude_cli',
            'fallback_provider' => null,
            'signals' => ['provider_online' => true, 'requested_provider' => 'auto'],
            'reason' => 'Fast path: provider online, no critical signals.',
            'was_overridden' => true,
        ]);

        $summary = app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);
        $router = $summary->score_components['router'];

        $this->assertTrue($router['available']);
        $this->assertSame('fast', $router['mode']);
        $this->assertSame('claude_cli', $router['selected_provider']);
        $this->assertNull($router['fallback_provider']);
        $this->assertTrue($router['was_overridden']);
        $this->assertStringContainsString('Fast path', $router['reason']);
        $this->assertSame(['provider_online' => true, 'requested_provider' => 'auto'], $router['signals']);
    }

    public function test_specialist_flow_runtime_is_projected_into_score_components(): void
    {
        $trace = $this->seedTrace('claude_cli', 'cli-model', 500, 100);
        $this->seedRate('claude_cli', 'cli-model', 1000, 2000);

        AiJob::query()
            ->where('trace_id', $trace->id)
            ->firstOrFail()
            ->update([
                'payload' => [
                    'specialist_flow_runtime' => [
                        'schema_version' => 'atlas.ai.specialist_flow_runtime.v1',
                        'flow_id' => 'atlas_explain',
                        'owner' => 'atlas_explain',
                        'execution_mode' => 'read_only_explanation',
                        'side_effect_policy' => 'read_only_until_confirmed',
                        'workspace_present' => false,
                        'delegation' => [
                            'status' => 'not_delegated',
                            'reason' => 'flow_matches_router_decision',
                        ],
                        'required_evidence' => ['router_decision', 'context_refs_or_scope_statement'],
                        'output_contract' => ['plain_language_explanation'],
                        'forbidden_actions' => ['modify_workspace'],
                        'receipt' => [
                            'schema_version' => 'atlas.ai.specialist_flow_receipt.v1',
                            'receipt_id' => 'sfr_1234567890abcdef1234567890abcdef',
                            'contract_hash' => str_repeat('a', 64),
                            'issued_by' => 'atlas.ai.specialist_flow_runtime.v1',
                            'flow_id' => 'atlas_explain',
                            'owner' => 'atlas_explain',
                            'execution_mode' => 'read_only_explanation',
                            'delegation_status' => 'not_delegated',
                            'required_evidence' => ['router_decision', 'context_refs_or_scope_statement'],
                        ],
                    ],
                    'specialist_flow_execution' => [
                        'schema_version' => 'atlas.ai.specialist_flow_execution.v1',
                        'status' => 'ready_for_provider',
                        'handler_id' => 'atlas_explain_read_only_handler',
                        'handler_version' => 'v1',
                        'runtime_receipt_id' => 'sfr_1234567890abcdef1234567890abcdef',
                        'runtime_contract_hash' => str_repeat('a', 64),
                        'audit_checks' => ['no_side_effect_claims'],
                        'response_shape' => ['plain_language_explanation'],
                    ],
                ],
            ]);

        $summary = app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);
        $specialist = $summary->score_components['specialist_flow'];

        $this->assertTrue($specialist['available']);
        $this->assertSame('atlas.ai.specialist_flow_runtime.v1', $specialist['schema_version']);
        $this->assertSame('atlas_explain', $specialist['flow_id']);
        $this->assertSame('read_only_explanation', $specialist['execution_mode']);
        $this->assertSame('not_delegated', data_get($specialist, 'delegation.status'));
        $this->assertSame(['router_decision', 'context_refs_or_scope_statement'], $specialist['required_evidence']);
        $this->assertSame('atlas.ai.specialist_flow_receipt.v1', data_get($specialist, 'receipt.schema_version'));
        $this->assertSame(str_repeat('a', 64), data_get($specialist, 'receipt.contract_hash'));
        $this->assertSame('atlas.ai.specialist_flow_execution.v1', data_get($specialist, 'execution.schema_version'));
        $this->assertSame('atlas_explain_read_only_handler', data_get($specialist, 'execution.handler_id'));
        $this->assertSame(['no_side_effect_claims'], data_get($specialist, 'execution.audit_checks'));
    }

    public function test_specialist_flow_diagnostics_prefer_persisted_execution_record(): void
    {
        $trace = $this->seedTrace('claude_cli', 'cli-model', 500, 100);
        $this->seedRate('claude_cli', 'cli-model', 1000, 2000);

        AiSpecialistFlowExecution::query()->create([
            'trace_id' => $trace->id,
            'runtime_schema_version' => 'atlas.ai.specialist_flow_runtime.v1',
            'execution_schema_version' => 'atlas.ai.specialist_flow_execution.v1',
            'flow_id' => 'atlas_explain',
            'handler_id' => 'atlas_explain_read_only_handler',
            'handler_version' => 'v1',
            'status' => 'ready_for_provider',
            'runtime_receipt_id' => 'sfr_persisted',
            'runtime_contract_hash' => str_repeat('f', 64),
            'delegation_status' => 'not_delegated',
            'runtime_payload' => [
                'owner' => 'atlas_explain',
                'execution_mode' => 'read_only_explanation',
                'side_effect_policy' => 'read_only_until_confirmed',
                'workspace_present' => false,
                'required_evidence' => ['router_decision'],
                'output_contract' => ['plain_language_explanation'],
            ],
            'receipt' => ['receipt_id' => 'sfr_persisted'],
            'delegation' => ['status' => 'not_delegated'],
            'audit_checks' => ['no_side_effect_claims'],
            'response_shape' => ['plain_language_explanation'],
        ]);

        $summary = app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);
        $specialist = $summary->score_components['specialist_flow'];

        $this->assertSame('ai_specialist_flow_executions', $specialist['source']);
        $this->assertSame('atlas_explain', $specialist['flow_id']);
        $this->assertSame('atlas_explain_read_only_handler', data_get($specialist, 'execution.handler_id'));
        $this->assertSame('sfr_persisted', data_get($specialist, 'execution.runtime_receipt_id'));
    }

    public function test_atlas_decide_diagnostics_capture_multi_stage_execution(): void
    {
        $trace = $this->seedTrace('claude_cli', 'claude-sonnet-4-6', 900, 180);
        $executor = AiJob::query()->where('trace_id', $trace->id)->firstOrFail();
        $this->seedRate('claude_cli', 'claude-sonnet-4-6', 1000, 2000);
        $this->seedRate('gemini_cli', 'gemini-3.1-pro-preview', 1000, 2000);

        AiDecision::query()->create([
            'trace_id' => $trace->id,
            'policy_version' => 'atlas-decide-v1',
            'decision_mode' => 'atlas_decide',
            'route_mode' => 'dev',
            'task_type' => 'programming',
            'risk_level' => 'high',
            'context_strategy' => 'gemini_scout_then_executor',
            'execution_strategy' => 'scout_then_execute_planned',
            'selected_provider' => 'claude_cli',
            'selected_model' => 'claude-sonnet-4-6',
            'fallback_provider' => null,
            'operator_requested_provider' => 'auto',
            'requested_provider' => null,
            'was_overridden' => false,
            'confidence_score' => 84,
            'signals' => ['atlas_workflow_mode' => 'dev'],
            'candidates' => [],
            'constraints' => [],
            'metrics_snapshot' => [],
            'task_profile' => ['wide_code_context_signal' => true, 'context_pressure' => 'long'],
            'execution_graph' => [
                'activation_status' => 'active_multi_stage',
                'quality_gates' => ['diff_scope_review', 'tests_or_static_review'],
            ],
            'reason' => 'Atlas Decide selecionou scout antes do executor.',
        ]);

        $scout = AiJob::query()->create([
            'trace_id' => $trace->id,
            'client_id' => (string) Str::uuid(),
            'kind' => 'context_scout',
            'status' => 'succeeded',
            'priority' => 5,
            'agent_slug' => 'orquestrador',
            'provider' => 'gemini_cli',
            'model' => 'gemini-3.1-pro-preview',
            'input_text' => 'map repo',
            'prompt' => 'prompt',
            'context_refs' => [],
            'payload' => [
                'atlas_decide_execution' => [
                    'strategy' => 'scout_then_execute',
                    'atlas_decide_stage' => 'context_scout',
                    'dependency_state' => 'source',
                    'dependent_job_id' => $executor->id,
                ],
            ],
            'result_text' => 'brief',
            'result_json' => ['usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150]],
            'available_at' => now()->subSeconds(4),
            'reserved_at' => now()->subSeconds(3),
            'started_at' => now()->subSeconds(3),
            'finished_at' => now()->subSeconds(2),
            'attempts' => 1,
            'max_attempts' => 2,
            'timeout_seconds' => 300,
            'metadata' => [
                'atlas_decide_stage' => 'context_scout',
                'dependent_job_id' => $executor->id,
            ],
        ]);

        $executor->forceFill([
            'metadata' => [
                'atlas_decide_stage' => 'primary_executor',
                'dependency_state' => 'satisfied',
                'dependency_job_id' => $scout->id,
                'atlas_decide_execution' => [
                    'strategy' => 'scout_then_execute',
                    'atlas_decide_stage' => 'primary_executor',
                    'dependency_state' => 'satisfied',
                    'dependency_job_id' => $scout->id,
                ],
            ],
        ])->save();
        $trace->forceFill([
            'metadata' => [
                'app_surface' => 'mobile',
                'atlas_decide_execution' => [
                    'strategy' => 'scout_then_execute',
                    'activation_status' => 'active_multi_stage',
                    'dependency_state' => 'satisfied',
                    'context_scout_job_id' => $scout->id,
                ],
            ],
        ])->save();

        $summary = app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);
        $atlas = $summary->score_components['atlas_decide'];

        $this->assertTrue($atlas['available']);
        $this->assertTrue($atlas['decision_available']);
        $this->assertSame('gemini_scout_then_executor', $atlas['context_strategy']);
        $this->assertSame('scout_then_execute_planned', $atlas['execution_strategy']);
        $this->assertSame('active_multi_stage', $atlas['activation_status']);
        $this->assertSame('satisfied', $atlas['dependency_state']);
        $this->assertTrue($atlas['scout_enabled']);
        $this->assertSame('gemini_cli', $atlas['scout_provider']);
        $this->assertSame('claude_cli', $atlas['executor_provider']);
        $this->assertContains('gemini_cli', $atlas['providers_used']);
        $this->assertContains('claude_cli', $atlas['providers_used']);
        $this->assertSame('context_scout', $atlas['provider_sequence'][0]['stage']);
        $this->assertSame('primary_executor', $atlas['provider_sequence'][1]['stage']);
    }

    public function test_atlas_decide_diagnostics_do_not_invent_gemini_scout_for_single_stage_decision(): void
    {
        $trace = $this->seedTrace('claude_cli', 'claude-sonnet-4-6', 300, 80);
        $this->seedRate('claude_cli', 'claude-sonnet-4-6', 1000, 2000);

        AiDecision::query()->create([
            'trace_id' => $trace->id,
            'policy_version' => 'atlas-decide-v1',
            'decision_mode' => 'atlas_decide',
            'route_mode' => 'direct',
            'task_type' => 'general',
            'risk_level' => 'low',
            'context_strategy' => 'direct_context_pack',
            'execution_strategy' => 'single_provider_response',
            'selected_provider' => 'claude_cli',
            'selected_model' => 'claude-sonnet-4-6',
            'fallback_provider' => null,
            'operator_requested_provider' => 'auto',
            'requested_provider' => null,
            'was_overridden' => false,
            'confidence_score' => 74,
            'signals' => [],
            'candidates' => [],
            'constraints' => [],
            'metrics_snapshot' => [],
            'task_profile' => ['task_type' => 'general', 'context_pressure' => 'normal'],
            'execution_graph' => ['activation_status' => 'active_single_provider'],
            'reason' => 'Atlas Decide selecionou o provider padrao.',
        ]);

        $summary = app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);
        $atlas = $summary->score_components['atlas_decide'];

        $this->assertTrue($atlas['available']);
        $this->assertFalse($atlas['scout_enabled']);
        $this->assertNull($atlas['scout_provider']);
        $this->assertSame(['claude_cli'], $atlas['providers_used']);
        $this->assertFalse($atlas['degraded']);
    }

    public function test_atlas_decide_diagnostics_do_not_count_blocked_scout_as_executed(): void
    {
        $trace = $this->seedTrace('claude_cli', 'claude-sonnet-4-6', 300, 80);
        $this->seedRate('claude_cli', 'claude-sonnet-4-6', 1000, 2000);

        AiDecision::query()->create([
            'trace_id' => $trace->id,
            'policy_version' => 'atlas-decide-v1',
            'decision_mode' => 'atlas_decide',
            'route_mode' => 'dev',
            'task_type' => 'programming',
            'risk_level' => 'high',
            'context_strategy' => 'gemini_scout_then_executor',
            'execution_strategy' => 'scout_then_execute_planned',
            'selected_provider' => 'claude_cli',
            'selected_model' => 'claude-sonnet-4-6',
            'fallback_provider' => 'claude_cli',
            'operator_requested_provider' => 'auto',
            'requested_provider' => null,
            'was_overridden' => false,
            'confidence_score' => 74,
            'signals' => ['atlas_workflow_mode' => 'dev'],
            'candidates' => [],
            'constraints' => [],
            'metrics_snapshot' => [],
            'task_profile' => ['task_type' => 'programming', 'context_pressure' => 'long'],
            'execution_graph' => [
                'activation_status' => 'blocked_by_runtime_settings',
                'activation_blocked_reason' => 'gemini_auto_disabled',
            ],
            'reason' => 'Atlas Decide planejou scout, mas runtime bloqueou Gemini automatico.',
        ]);
        $trace->forceFill([
            'metadata' => [
                'atlas_decide_execution' => [
                    'strategy' => 'single_stage',
                    'activation_status' => 'blocked_by_runtime_settings',
                    'blocked_reason' => 'gemini_auto_disabled',
                    'dependency_state' => 'none',
                ],
            ],
        ])->save();

        $summary = app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);
        $atlas = $summary->score_components['atlas_decide'];

        $this->assertTrue($atlas['available']);
        $this->assertTrue($atlas['scout_planned']);
        $this->assertFalse($atlas['scout_enabled']);
        $this->assertSame('gemini_cli', $atlas['scout_planned_provider']);
        $this->assertNull($atlas['scout_provider']);
        $this->assertSame(['claude_cli'], $atlas['providers_used']);
    }

    public function test_scorecard_aggregates_by_router_mode_and_reports_override_rate(): void
    {
        // Seed 3 traces: 2 council (1 overridden), 1 fast (not overridden)
        $council1 = $this->seedTrace('openai', 'gpt-4o', 100, 50);
        $council2 = $this->seedTrace('openai', 'gpt-4o', 100, 50);
        $fast = $this->seedTrace('claude_cli', 'cli-model', 100, 50);
        $this->seedRate('openai', 'gpt-4o', 5000, 15000);
        $this->seedRate('claude_cli', 'cli-model', 1000, 2000);

        AiRouterDecision::query()->create([
            'trace_id' => $council1->id, 'mode' => 'council',
            'selected_provider' => 'openai', 'signals' => [],
            'reason' => 'r', 'was_overridden' => false,
        ]);
        AiRouterDecision::query()->create([
            'trace_id' => $council2->id, 'mode' => 'council',
            'selected_provider' => 'openai', 'signals' => [],
            'reason' => 'r', 'was_overridden' => true,
        ]);
        AiRouterDecision::query()->create([
            'trace_id' => $fast->id, 'mode' => 'fast',
            'selected_provider' => 'claude_cli', 'signals' => [],
            'reason' => 'r', 'was_overridden' => false,
        ]);

        $aggregator = app(AiTraceMetricAggregator::class);
        $aggregator->recomputeTrace($council1->id);
        $aggregator->recomputeTrace($council2->id);
        $aggregator->recomputeTrace($fast->id);

        $scorecard = app(AiTelemetryScorecardService::class)
            ->build(now()->subHour(), now()->addMinute());

        $this->assertArrayHasKey('by_router_mode', $scorecard,
            'Scorecard must expose by_router_mode breakdown for diagnostic queries.');

        $modes = collect($scorecard['by_router_mode'])->keyBy('bucket');
        $this->assertSame(2, $modes['council']['traces']);
        $this->assertSame(1, $modes['fast']['traces']);

        $this->assertEqualsWithDelta(
            1 / 3,
            $scorecard['totals']['router_override_rate'],
            0.0001,
            'router_override_rate = 1 overridden / 3 total = 0.3333...'
        );
    }

    public function test_scorecard_reports_atlas_decide_strategy_metrics(): void
    {
        $this->seedAtlasDecideMetricSummary(
            executionStrategy: 'scout_then_execute_planned',
            contextStrategy: 'gemini_scout_then_executor',
            selectedProvider: 'claude_cli',
            scoutProvider: 'gemini_cli',
            providersUsed: ['gemini_cli', 'claude_cli'],
            scoutEnabled: true,
            degraded: false,
            quality: 82,
            efficiency: 74,
        );
        $this->seedAtlasDecideMetricSummary(
            executionStrategy: 'single_provider_long_context',
            contextStrategy: 'gemini_long_context_or_multimodal',
            selectedProvider: 'gemini_cli',
            scoutProvider: null,
            providersUsed: ['gemini_cli'],
            scoutEnabled: false,
            degraded: true,
            quality: 61,
            efficiency: 58,
        );

        $scorecard = app(AiTelemetryScorecardService::class)
            ->build(now()->subHour(), now()->addMinute());

        $this->assertTrue($scorecard['atlas_decide']['available']);
        $this->assertSame(2, $scorecard['atlas_decide']['traces']);
        $this->assertSame(1, $scorecard['atlas_decide']['multi_stage_count']);
        $this->assertSame(1, $scorecard['atlas_decide']['degraded_count']);
        $this->assertSame(2, $scorecard['totals']['atlas_decide_trace_count']);
        $this->assertSame(1, $scorecard['totals']['atlas_decide_multi_stage_count']);

        $byExecution = collect($scorecard['by_atlas_decide_execution_strategy'])->keyBy('bucket');
        $this->assertSame(1, $byExecution['scout_then_execute_planned']['traces']);
        $this->assertSame(['gemini_cli', 'claude_cli'], $byExecution['scout_then_execute_planned']['providers_used']);

        $byContext = collect($scorecard['by_atlas_decide_context_strategy'])->keyBy('bucket');
        $this->assertSame(1, $byContext['gemini_long_context_or_multimodal']['traces']);
        $this->assertSame(1.0, $byContext['gemini_long_context_or_multimodal']['degraded_rate']);
    }

    public function test_diagnostics_groups_events_by_phase(): void
    {
        $trace = $this->seedTrace('openai', 'gpt-4o', 100, 50);
        $this->seedRate('openai', 'gpt-4o', 5000, 15000);

        // Seed events spanning multiple phases
        $this->seedEvent($trace, 'message_send_pressed', 'client', null, null);
        $this->seedEvent($trace, 'interaction_accepted', 'server', null, null);
        $this->seedEvent($trace, 'trace_visible_in_ui', 'client', null, null);
        $this->seedEvent($trace, 'job_enqueued', 'server', null, null);

        $summary = app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);
        $diagnostics = $summary->score_components['diagnostics'];

        $this->assertArrayHasKey('events_by_phase', $diagnostics);
        $this->assertSame(2, $diagnostics['events_by_phase']['client'],
            'Two events with phase=client must be counted as 2.');
        $this->assertSame(2, $diagnostics['events_by_phase']['server'],
            'Two events with phase=server must be counted as 2.');
    }

    public function test_diagnostics_captures_numeric_signals(): void
    {
        $trace = $this->seedTrace('claude_cli', 'cli-model', 500, 100);
        $this->seedRate('claude_cli', 'cli-model', 1000, 2000);

        // Numeric_value-bearing event (CLI input chars pattern)
        $this->seedEvent($trace, 'cli_interaction_submitted', 'cli', 1234, 'chars');
        // Non-numeric event — must be excluded
        $this->seedEvent($trace, 'job_enqueued', 'server', null, null);

        $summary = app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);
        $signals = $summary->score_components['diagnostics']['numeric_signals'];

        $this->assertCount(1, $signals,
            'Only the event with numeric_value populated should appear in numeric_signals.');
        $this->assertSame('cli_interaction_submitted', $signals[0]['event_name']);
        $this->assertSame('chars', $signals[0]['unit']);
        // numeric_value goes through json_encode round-trip in score_components, which
        // erases int-vs-float distinction (JSON has one number type). Value compared
        // loosely for that reason.
        $this->assertEquals(1234, $signals[0]['value']);
    }

    private function seedTrace(string $provider, string $model, int $promptTokens, int $completionTokens): AiTrace
    {
        $clientId = (string) Str::uuid();
        $trace = AiTrace::query()->create([
            'trace_key' => 'trace_'.Str::uuid(),
            'source_type' => 'app',
            'status' => 'succeeded',
            'operator_input' => 'router test',
            'agent_slug' => 'orquestrador',
            'provider' => $provider,
            'model' => $model,
            'skill_versions' => [],
            'context_refs' => [],
            'response_text' => 'ok',
            'latency_ms' => 1000,
            'completed_at' => now(),
            'metadata' => ['app_surface' => 'mobile', 'client_id' => $clientId],
        ]);

        AiJob::query()->create([
            'trace_id' => $trace->id,
            'client_id' => $clientId,
            'kind' => 'interaction',
            'status' => 'succeeded',
            'priority' => 10,
            'agent_slug' => 'orquestrador',
            'provider' => $provider,
            'model' => $model,
            'input_text' => 'in',
            'prompt' => 'prompt',
            'context_refs' => [],
            'payload' => [],
            'result_text' => 'out',
            'result_json' => [
                'usage' => [
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'total_tokens' => $promptTokens + $completionTokens,
                ],
            ],
            'available_at' => now()->subSeconds(2),
            'reserved_at' => now()->subSecond(),
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
            'attempts' => 1,
            'max_attempts' => 2,
            'timeout_seconds' => 300,
            'metadata' => [],
        ]);

        return $trace;
    }

    private function seedRate(string $provider, string $model, int $inputMicrousdPer1k, int $outputMicrousdPer1k): void
    {
        AiProviderCostRate::query()->create([
            'provider' => $provider,
            'model' => $model,
            'input_microusd_per_1k' => $inputMicrousdPer1k,
            'output_microusd_per_1k' => $outputMicrousdPer1k,
            'metadata' => [],
        ]);
    }

    private function seedEvent(AiTrace $trace, string $eventName, string $eventPhase, ?float $numericValue, ?string $unit): void
    {
        AiTelemetryEvent::query()->create([
            'event_key' => 'test:'.$eventName.':'.Str::uuid(),
            'trace_id' => $trace->id,
            'thread_id' => $trace->thread_id,
            'session_id' => $trace->session_id,
            'surface' => 'mobile',
            'runtime' => 'ios',
            'provider' => $trace->provider,
            'model' => $trace->model,
            'agent_slug' => $trace->agent_slug,
            'event_name' => $eventName,
            'event_phase' => $eventPhase,
            'received_at' => now(),
            'numeric_value' => $numericValue,
            'unit' => $unit,
            'metadata' => [],
            'privacy' => [],
        ]);
    }

    /**
     * @param  array<int,string>  $providersUsed
     */
    private function seedAtlasDecideMetricSummary(
        string $executionStrategy,
        string $contextStrategy,
        string $selectedProvider,
        ?string $scoutProvider,
        array $providersUsed,
        bool $scoutEnabled,
        bool $degraded,
        int $quality,
        int $efficiency,
    ): void {
        AiTraceMetricSummary::query()->create([
            'trace_id' => (string) Str::uuid(),
            'surface' => 'mobile',
            'runtime' => 'ios',
            'provider' => $selectedProvider,
            'model' => 'test-model',
            'agent_slug' => 'orquestrador',
            'task_type' => 'programming',
            'status' => 'succeeded',
            'total_latency_ms' => 1000,
            'cost_microusd' => 100,
            'cost_confidence' => 'estimated',
            'cost_mode' => 'operational_estimate',
            'final_quality_score' => $quality,
            'final_efficiency_score' => $efficiency,
            'first_pass_success' => ! $degraded,
            'needed_remediation' => $degraded,
            'reask_detected' => false,
            'provider_switched_after_response' => false,
            'score_components' => [
                'atlas_decide' => [
                    'available' => true,
                    'execution_strategy' => $executionStrategy,
                    'context_strategy' => $contextStrategy,
                    'selected_provider' => $selectedProvider,
                    'scout_provider' => $scoutProvider,
                    'providers_used' => $providersUsed,
                    'scout_enabled' => $scoutEnabled,
                    'degraded' => $degraded,
                ],
            ],
            'metadata' => ['aggregator_version' => 'ai_trace_metric_aggregator_v3'],
            'computed_at' => now(),
        ]);
    }

    private function bootMinimalSchema(): void
    {
        foreach ([
            'ai_trace_metric_summaries',
            'ai_outcome_links',
            'ai_provider_cost_rates',
            'ai_specialist_flow_executions',
            'ai_router_decisions',
            'ai_telemetry_events',
            'ai_stream_events',
            'ai_context_snapshots',
            'ai_quality_actions',
            'ai_quality_evaluations',
            'ai_job_attempts',
            'ai_jobs',
            'ai_traces',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->unique();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('source_type')->default('app');
            $table->string('status')->default('queued');
            $table->text('operator_input');
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('skill_versions')->default('{}');
            $table->json('context_refs')->default('[]');
            $table->text('response_text')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->smallInteger('feedback_score')->nullable();
            $table->string('feedback_action')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_router_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable()->index();
            $table->string('mode', 32)->default('direct');
            $table->string('selected_provider', 32);
            $table->string('fallback_provider', 32)->nullable();
            $table->json('signals');
            $table->text('reason');
            $table->boolean('was_overridden')->default(false);
            $table->timestamps();
        });

        Schema::create('ai_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable()->index();
            $table->uuid('router_decision_id')->nullable()->index();
            $table->string('policy_version', 32)->default('atlas-decide-v1');
            $table->string('decision_mode', 32)->default('atlas_decide');
            $table->string('route_mode', 64)->nullable();
            $table->string('task_type', 80)->nullable();
            $table->string('risk_level', 40)->nullable();
            $table->string('context_strategy', 64)->nullable();
            $table->string('execution_strategy', 64)->nullable();
            $table->string('selected_provider', 32);
            $table->string('selected_model', 120)->nullable();
            $table->string('fallback_provider', 32)->nullable();
            $table->string('operator_requested_provider', 32)->default('auto');
            $table->string('requested_provider', 32)->nullable();
            $table->boolean('was_overridden')->default(false);
            $table->unsignedTinyInteger('confidence_score')->nullable();
            $table->json('signals');
            $table->json('candidates')->nullable();
            $table->json('constraints')->nullable();
            $table->json('metrics_snapshot')->nullable();
            $table->json('task_profile')->nullable();
            $table->json('execution_graph')->nullable();
            $table->text('reason');
            $table->timestamps();
        });

        Schema::create('ai_specialist_flow_executions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable()->index();
            $table->uuid('router_decision_id')->nullable()->index();
            $table->string('runtime_schema_version', 80)->nullable();
            $table->string('execution_schema_version', 80)->nullable();
            $table->string('flow_id', 80);
            $table->string('handler_id', 120)->nullable();
            $table->string('handler_version', 32)->nullable();
            $table->string('status', 40)->default('ready_for_provider');
            $table->string('runtime_receipt_id', 80)->nullable();
            $table->string('runtime_contract_hash', 64)->nullable();
            $table->string('delegation_status', 64)->nullable();
            $table->string('delegation_target_flow_id', 80)->nullable();
            $table->json('runtime_payload')->nullable();
            $table->json('execution_payload')->nullable();
            $table->json('receipt')->nullable();
            $table->json('delegation')->nullable();
            $table->json('audit_checks')->nullable();
            $table->json('response_shape')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_quality_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id');
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('agent_slug')->nullable();
            $table->string('evaluator_version');
            $table->integer('score');
            $table->string('status');
            $table->json('dimensions')->default('{}');
            $table->json('flags')->default('[]');
            $table->json('suggested_actions')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_quality_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('evaluation_id')->nullable();
            $table->uuid('trace_id')->nullable();
            $table->uuid('remediation_trace_id')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('action_type');
            $table->string('status')->default('queued');
            $table->integer('priority')->default(50);
            $table->text('reason');
            $table->json('flags')->default('[]');
            $table->json('payload')->default('{}');
            $table->json('result')->default('{}');
            $table->text('error_message')->nullable();
            $table->string('dedupe_key')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_context_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_hash')->nullable();
            $table->json('context_pack')->default('{}');
            $table->json('messages_included')->default('[]');
            $table->uuid('compaction_id')->nullable();
            $table->uuid('provider_handoff_id')->nullable();
            $table->integer('token_estimate')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('ai_stream_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('ai_job_id')->nullable();
            $table->uuid('ai_job_attempt_id')->nullable();
            $table->integer('sequence');
            $table->string('event_type');
            $table->string('channel')->nullable();
            $table->text('content')->default('');
            $table->json('metadata')->default('{}');
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('ai_job_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ai_job_id');
            $table->integer('attempt_number');
            $table->string('worker_id');
            $table->string('provider');
            $table->string('model')->nullable();
            $table->json('command')->default('[]');
            $table->string('command_hash')->nullable();
            $table->string('prompt_hash');
            $table->string('response_hash')->nullable();
            $table->string('status')->default('processing');
            $table->integer('exit_code')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->text('output_text')->nullable();
            $table->text('stdout_excerpt')->nullable();
            $table->text('stderr_excerpt')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('client_id')->nullable();
            $table->string('kind')->default('interaction');
            $table->string('status')->default('queued');
            $table->smallInteger('priority')->default(50);
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->text('input_text');
            $table->text('prompt');
            $table->json('context_refs')->default('[]');
            $table->json('payload')->default('{}');
            $table->text('result_text')->nullable();
            $table->json('result_json')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(2);
            $table->integer('timeout_seconds')->default(300);
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        (require database_path('migrations/2026_05_01_000000_create_ai_telemetry_events_table.php'))->up();
        (require database_path('migrations/2026_05_01_001000_create_ai_metric_summary_tables.php'))->up();
        (require database_path('migrations/2026_05_01_005000_add_router_columns_to_ai_trace_metric_summaries.php'))->up();
    }
}
