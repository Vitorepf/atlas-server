<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AtlasDecideService;
use App\Services\Ai\Kernel\Evidence\ProviderPerformanceProjection;
use App\Services\Ai\Kernel\Provider\ProviderRequestHasher;
use Tests\TestCase;

class AtlasDecideReceiptIntegrationTest extends TestCase
{
    public function test_operational_decision_exposes_v2_receipt_for_auto_model_selection(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => true,
        ]);

        $options = app(AtlasDecideService::class)->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => 'implemente uma melhoria no atlas dev',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'dev',
                'decision_mode' => 'atlas_decide',
                'operator_requested_provider' => 'auto',
            ],
        ]);

        $decision = app(AtlasDecideService::class)->operationalDecision($options)->toArray();

        $this->assertSame('atlas.decide.v2', data_get($decision, 'receipt_v2.schema_version'));
        $this->assertSame('programming', data_get($decision, 'receipt_v2.domain'));
        $this->assertSame('programming.dev', data_get($decision, 'receipt_v2.flow'));
        $this->assertSame('auto_best_allowed', data_get($decision, 'receipt_v2.provider_selection.selection_mode'));
        $this->assertSame('auto_best_allowed', data_get($decision, 'provider_selection.selection_mode'));
        $this->assertSame('atlas_decide', data_get($decision, 'provider_selection.model_selection_authority'));
        $this->assertSame(86, data_get($decision, 'provider_selection.confidence_score'));
        $this->assertSame('high', data_get($decision, 'provider_selection.confidence_band'));
        $this->assertSame('programming', data_get($decision, 'provider_selection.selection_explanation.primary_signals.task_type'));
        $this->assertSame(86, data_get($decision, 'receipt_v2.provider_selection.confidence_score'));
        $this->assertSame('high', data_get($decision, 'receipt_v2.provider_selection.confidence_band'));
        $this->assertSame('policy_heuristic_pending_ap99', data_get($decision, 'receipt_v2.provider_selection.selection_explanation.evidence_level'));
        $this->assertSame('atlas.dynamic_compute_market.v1', data_get($decision, 'receipt_v2.provider_selection.selection_explanation.compute_market.schema_version'));
        $this->assertSame('shadow_advisory', data_get($decision, 'receipt_v2.provider_selection.selection_explanation.compute_market.mode'));
        $this->assertSame('collect_ap99_evidence', data_get($decision, 'receipt_v2.provider_selection.selection_explanation.compute_market.recommendation'));
        $this->assertFalse(data_get($decision, 'receipt_v2.provider_selection.selection_explanation.compute_market.routing_control.changes_provider'));
        $this->assertSame('atlas_decide', data_get($decision, 'receipt_v2.provider_selection.selection_explanation.compute_market.routing_control.routing_authority'));
        $this->assertSame(
            ['auto_best_allowed', 'auto_best_available', 'manual_override'],
            data_get($decision, 'provider_selection.available_selection_modes'),
        );
        $this->assertSame('codex_cli', data_get($decision, 'receipt_v2.provider_selection.primary'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($decision, 'receipt_v2.receipt_hash'));
        $this->assertTrue(data_get($decision, 'kernel_contracts.valid'));
        $this->assertTrue(data_get($decision, 'kernel_contracts.execution_allowed'));
        $this->assertSame([], data_get($decision, 'kernel_contracts.blocking_errors'));
        $this->assertSame('atlas_cli_dev', data_get($decision, 'kernel_contracts.surface.surface_id'));
        $this->assertSame('normalized', data_get($decision, 'kernel_contracts.surface.status'));
        $this->assertSame('prepared', data_get($decision, 'kernel_contracts.provider.status'));
        $this->assertSame('codex_cli', data_get($decision, 'kernel_contracts.provider.provider_id'));
        $this->assertSame(ProviderRequestHasher::HASH_ALGORITHM, data_get($decision, 'kernel_contracts.provider.request_hash_algorithm'));
        $this->assertSame(ProviderRequestHasher::PREPARED_REQUEST_CANONICALIZATION, data_get($decision, 'kernel_contracts.provider.request_hash_canonicalization'));
        $this->assertTrue(data_get($decision, 'kernel_contracts.provider.validation.ok'));
        $this->assertSame([], data_get($decision, 'kernel_contracts.provider.validation.errors'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($decision, 'kernel_contracts.provider.identity_fragment_hash'));
        $this->assertContains(data_get($decision, 'kernel_contracts.provider.identity_fragment_source'), [
            'atlas_ai_master_prompt_projection',
            'atlas_ai_master_prompt_fallback',
        ]);
        $this->assertIsBool(data_get($decision, 'kernel_contracts.provider.identity_fragment_fallback'));
        $this->assertSame(
            data_get($decision, 'kernel_contracts.provider.identity_fragment_hash'),
            data_get($decision, 'receipt_v2.metadata.kernel_contracts.provider.identity_fragment_hash'),
        );
        $this->assertSame(
            data_get($decision, 'kernel_contracts.provider.request_hash_canonicalization'),
            data_get($decision, 'receipt_v2.metadata.kernel_contracts.provider.request_hash_canonicalization'),
        );
    }

    public function test_manual_provider_override_is_recorded_in_v2_receipt(): void
    {
        $options = app(AtlasDecideService::class)->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => 'use claude para esta resposta',
            'provider' => 'claude_cli',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'chat',
                'decision_mode' => 'manual_override',
                'operator_requested_provider' => 'claude_cli',
            ],
        ]);

        $decision = app(AtlasDecideService::class)->operationalDecision($options, selectedProvider: 'claude_cli', selectedModel: 'opus')->toArray();

        $this->assertSame('manual_override', data_get($decision, 'receipt_v2.provider_selection.selection_mode'));
        $this->assertSame('manual_override', data_get($decision, 'provider_selection.selection_mode'));
        $this->assertSame('atlas_decide', data_get($decision, 'provider_selection.model_selection_authority'));
        $this->assertSame(100, data_get($decision, 'provider_selection.confidence_score'));
        $this->assertSame('manual', data_get($decision, 'provider_selection.confidence_band'));
        $this->assertSame('manual_override', data_get($decision, 'receipt_v2.provider_selection.selection_explanation.evidence_level'));
        $this->assertSame('claude_cli', data_get($decision, 'receipt_v2.provider_selection.manual_override.requested_provider'));
        $this->assertSame('claude_cli', data_get($decision, 'receipt_v2.provider_selection.primary'));
        $this->assertSame('opus', data_get($decision, 'receipt_v2.provider_selection.model'));
        $this->assertSame('shadow_advisory', data_get($decision, 'receipt_v2.provider_selection.selection_explanation.compute_market.mode'));
        $this->assertSame('claude_cli', data_get($decision, 'receipt_v2.provider_selection.selection_explanation.compute_market.selected_provider'));
        $this->assertFalse(data_get($decision, 'receipt_v2.provider_selection.selection_explanation.compute_market.routing_control.changes_provider'));
        $this->assertSame('claude_cli', data_get($decision, 'receipt_v2.provider_selection.selection_explanation.compute_market.routing_control.selected_provider_preserved'));
        $this->assertSame('opus', data_get($decision, 'receipt_v2.provider_selection.selection_explanation.compute_market.routing_control.selected_model_preserved'));
        $this->assertSame('atlas_cli_chat', data_get($decision, 'kernel_contracts.surface.surface_id'));
        $this->assertSame('claude_cli', data_get($decision, 'kernel_contracts.provider.provider_id'));
        $this->assertSame('opus', data_get($decision, 'kernel_contracts.provider.model'));
    }

    public function test_dynamic_compute_market_uses_ap99_provider_performance_inside_decision_receipt(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => true,
        ]);

        $this->mock(ProviderPerformanceProjection::class, function ($mock): void {
            $selectedReport = [
                'available' => true,
                'schema_version' => 'atlas.provider_usage.v1',
                'filters' => [
                    'provider_cli' => 'codex_cli',
                    'domain' => 'programming',
                    'flow' => 'programming.dev',
                    'task_type' => 'programming',
                ],
                'event_count' => 1,
                'returned_count' => 1,
                'fallback_count' => 0,
                'success_count' => 0,
                'failure_count' => 1,
                'success_rate' => 0.0,
                'average_latency_seconds' => 130.0,
                'total_tokens' => 2400,
                'average_total_tokens' => 2400.0,
                'total_cost_microusd' => 1200,
                'average_cost_microusd' => 1200.0,
                'costed_event_count' => 1,
                'unknown_cost_count' => 0,
                'cost_confidence_counts' => [
                    'estimated' => 1,
                ],
                'cost_mode_counts' => [
                    'operational_estimate' => 1,
                ],
                'review_signal' => [
                    'status' => 'warning',
                    'severity' => 'medium',
                    'recommended_action' => 'open_reviewable_provider_performance_proposal',
                ],
            ];

            $mock->shouldReceive('reportForWindow')
                ->twice()
                ->andReturn($selectedReport, [
                    ...$selectedReport,
                    'filters' => [
                        'domain' => 'programming',
                        'flow' => 'programming.dev',
                        'task_type' => 'programming',
                    ],
                    'groups' => [],
                ]);
        });

        $options = app(AtlasDecideService::class)->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => 'corrija um bug no atlas dev',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'dev',
                'decision_mode' => 'atlas_decide',
                'operator_requested_provider' => 'auto',
            ],
        ]);

        $decision = app(AtlasDecideService::class)->operationalDecision($options, selectedProvider: 'codex_cli', selectedModel: 'gpt-5.5')->toArray();
        $market = data_get($decision, 'receipt_v2.provider_selection.selection_explanation.compute_market');

        $this->assertSame('atlas.dynamic_compute_market.v1', data_get($market, 'schema_version'));
        $this->assertSame('review_provider_policy_patch', data_get($market, 'recommendation'));
        $this->assertSame('low', data_get($market, 'confidence'));
        $this->assertSame(1, data_get($market, 'score_basis.event_count'));
        $this->assertSame(0.0, data_get($market, 'score_basis.success_rate'));
        $this->assertSame(1, data_get($market, 'score_basis.failure_count'));
        $this->assertSame('available', data_get($market, 'score_basis.cost_status'));
        $this->assertSame(1200.0, data_get($market, 'score_basis.average_cost_microusd'));
        $this->assertSame(['estimated' => 1], data_get($market, 'score_basis.cost_confidence_counts'));
        $this->assertSame('programming.dev', data_get($market, 'dimensions.flow'));
        $this->assertSame('quality_risk', data_get($market, 'risk'));
        $this->assertSame('open_reviewable_provider_performance_proposal', data_get($market, 'recommended_next_action'));
        $this->assertTrue(data_get($market, 'decision_factors.has_quality_risk'));
        $this->assertTrue(data_get($market, 'decision_factors.has_high_latency'));
        $this->assertFalse(data_get($market, 'decision_factors.has_missing_cost'));
        $this->assertSame(
            'review_provider_policy_patch',
            data_get($market, 'decision_factors.precedence.1'),
        );
        $this->assertFalse(data_get($market, 'routing_control.changes_provider'));
        $this->assertSame(['policy_patch', 'decision_receipt'], data_get($market, 'routing_control.provider_change_requires'));
        $this->assertSame('warning', data_get($market, 'explanation.quality_basis.review_signal'));
    }

    public function test_dynamic_compute_market_requests_cost_rates_when_quality_is_ok_but_cost_is_unknown(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => true,
        ]);

        $this->mock(ProviderPerformanceProjection::class, function ($mock): void {
            $selectedReport = [
                'available' => true,
                'schema_version' => 'atlas.provider_usage.v1',
                'filters' => [
                    'provider_cli' => 'codex_cli',
                    'domain' => 'programming',
                    'flow' => 'programming.dev',
                    'task_type' => 'programming',
                ],
                'event_count' => 8,
                'returned_count' => 8,
                'fallback_count' => 0,
                'success_count' => 8,
                'failure_count' => 0,
                'success_rate' => 1.0,
                'average_latency_seconds' => 12.0,
                'total_tokens' => 6400,
                'average_total_tokens' => 800.0,
                'total_cost_microusd' => 0,
                'average_cost_microusd' => null,
                'costed_event_count' => 0,
                'unknown_cost_count' => 8,
                'cost_confidence_counts' => [
                    'unknown' => 8,
                ],
                'cost_mode_counts' => [
                    'unknown' => 8,
                ],
                'review_signal' => [
                    'status' => 'ok',
                    'severity' => 'none',
                    'recommended_action' => 'none',
                ],
            ];

            $mock->shouldReceive('reportForWindow')
                ->twice()
                ->andReturn($selectedReport, [
                    ...$selectedReport,
                    'filters' => [
                        'domain' => 'programming',
                        'flow' => 'programming.dev',
                        'task_type' => 'programming',
                    ],
                    'event_count' => 10,
                    'groups' => [
                        [
                            'provider_cli' => 'codex_cli',
                            'domain' => 'programming',
                            'specialist_profile' => 'unknown',
                            'task_type' => 'programming',
                            'event_count' => 8,
                            'success_rate' => 1.0,
                            'average_latency_seconds' => 12.0,
                            'average_cost_microusd' => null,
                        ],
                        [
                            'provider_cli' => 'claude_cli',
                            'domain' => 'programming',
                            'specialist_profile' => 'unknown',
                            'task_type' => 'programming',
                            'event_count' => 2,
                            'success_rate' => 1.0,
                            'average_latency_seconds' => 6.0,
                            'average_cost_microusd' => null,
                        ],
                    ],
                ]);
        });

        $options = app(AtlasDecideService::class)->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => 'implemente um fluxo de frontend',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'dev',
                'decision_mode' => 'atlas_decide',
                'operator_requested_provider' => 'auto',
            ],
        ]);

        $decision = app(AtlasDecideService::class)->operationalDecision($options, selectedProvider: 'codex_cli', selectedModel: 'gpt-5.5')->toArray();
        $market = data_get($decision, 'receipt_v2.provider_selection.selection_explanation.compute_market');

        $this->assertSame('configure_provider_cost_rates', data_get($market, 'recommendation'));
        $this->assertSame('medium', data_get($market, 'confidence'));
        $this->assertSame('missing_cost_rate_or_unavailable', data_get($market, 'score_basis.cost_status'));
        $this->assertSame(8, data_get($market, 'score_basis.unknown_cost_count'));
        $this->assertSame(['unknown' => 8], data_get($market, 'score_basis.cost_confidence_counts'));
        $this->assertSame('cost_visibility_risk', data_get($market, 'risk'));
        $this->assertSame('configure_provider_cost_rates', data_get($market, 'recommended_next_action'));
        $this->assertSame('selected_provider_cost_rate_missing_or_unavailable', data_get($market, 'recommendation_reason'));
        $this->assertSame('missing_cost_rate_or_unavailable', data_get($market, 'explanation.missing_cost_status.status'));
        $this->assertTrue(data_get($market, 'decision_factors.selected_quality_acceptable'));
        $this->assertTrue(data_get($market, 'decision_factors.has_missing_cost'));
        $this->assertTrue(data_get($market, 'decision_factors.has_market_candidate'));
        $this->assertFalse(data_get($market, 'decision_factors.candidate_has_sufficient_sample'));
        $this->assertSame(
            'configure_provider_cost_rates',
            data_get($market, 'decision_factors.precedence.3'),
        );
        $this->assertFalse(data_get($market, 'routing_control.changes_provider'));
        $this->assertSame(10, data_get($market, 'market_basis.event_count'));
        $this->assertSame(2, data_get($market, 'market_basis.group_count'));
        $this->assertTrue(data_get($market, 'market_basis.candidate_considered'));
    }

    public function test_dynamic_compute_market_recommends_benchmark_when_better_alternative_has_insufficient_sample(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => true,
        ]);

        $this->mock(ProviderPerformanceProjection::class, function ($mock): void {
            $selectedReport = [
                'available' => true,
                'schema_version' => 'atlas.provider_usage.v1',
                'filters' => [
                    'provider_cli' => 'codex_cli',
                    'domain' => 'programming',
                    'flow' => 'programming.dev',
                    'task_type' => 'programming',
                ],
                'event_count' => 12,
                'returned_count' => 12,
                'fallback_count' => 0,
                'success_count' => 12,
                'failure_count' => 0,
                'success_rate' => 1.0,
                'average_latency_seconds' => 24.0,
                'total_tokens' => 12000,
                'average_total_tokens' => 1000.0,
                'total_cost_microusd' => 24000,
                'average_cost_microusd' => 2000.0,
                'costed_event_count' => 12,
                'unknown_cost_count' => 0,
                'cost_confidence_counts' => [
                    'estimated' => 12,
                ],
                'cost_mode_counts' => [
                    'operational_estimate' => 12,
                ],
                'review_signal' => [
                    'status' => 'ok',
                    'severity' => 'none',
                    'recommended_action' => 'none',
                ],
            ];

            $mock->shouldReceive('reportForWindow')
                ->twice()
                ->andReturn($selectedReport, [
                    ...$selectedReport,
                    'filters' => [
                        'domain' => 'programming',
                        'flow' => 'programming.dev',
                        'task_type' => 'programming',
                    ],
                    'event_count' => 14,
                    'groups' => [
                        [
                            'provider_cli' => 'codex_cli',
                            'domain' => 'programming',
                            'specialist_profile' => 'unknown',
                            'task_type' => 'programming',
                            'event_count' => 12,
                            'success_rate' => 1.0,
                            'average_latency_seconds' => 24.0,
                            'average_cost_microusd' => 2000.0,
                        ],
                        [
                            'provider_cli' => 'claude_cli',
                            'domain' => 'programming',
                            'specialist_profile' => 'unknown',
                            'task_type' => 'programming',
                            'event_count' => 2,
                            'success_rate' => 1.0,
                            'average_latency_seconds' => 10.0,
                            'average_cost_microusd' => 800.0,
                        ],
                    ],
                ]);
        });

        $options = app(AtlasDecideService::class)->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => 'otimize um fluxo de build',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'dev',
                'decision_mode' => 'atlas_decide',
                'operator_requested_provider' => 'auto',
            ],
        ]);

        $decision = app(AtlasDecideService::class)->operationalDecision($options, selectedProvider: 'codex_cli', selectedModel: 'gpt-5.5')->toArray();
        $market = data_get($decision, 'receipt_v2.provider_selection.selection_explanation.compute_market');

        $this->assertSame('codex_cli', data_get($decision, 'receipt_v2.provider_selection.primary'));
        $this->assertSame('benchmark_lower_latency_alternative', data_get($market, 'recommendation'));
        $this->assertSame('run_controlled_provider_benchmark_before_policy_change', data_get($market, 'recommended_next_action'));
        $this->assertSame('latency_or_cost_candidate_requires_benchmark_receipt', data_get($market, 'recommendation_reason'));
        $this->assertSame('sample_size_risk', data_get($market, 'risk'));
        $this->assertTrue(data_get($market, 'decision_factors.has_market_candidate'));
        $this->assertFalse(data_get($market, 'decision_factors.candidate_has_sufficient_sample'));
        $this->assertSame('claude_cli', data_get($market, 'benchmark_candidate.provider'));
        $this->assertSame('insufficient', data_get($market, 'benchmark_candidate.sample_status'));
        $this->assertSame(['latency', 'cost'], data_get($market, 'benchmark_candidate.improvement_basis'));
        $this->assertSame(1.0, data_get($market, 'explanation.quality_basis.candidate_success_rate'));
        $this->assertSame(0.0, data_get($market, 'explanation.quality_basis.candidate_success_rate_delta'));
        $this->assertFalse(data_get($market, 'routing_control.changes_provider'));
        $this->assertSame('codex_cli', data_get($market, 'routing_control.selected_provider_preserved'));
        $this->assertSame('gpt-5.5', data_get($market, 'routing_control.selected_model_preserved'));
        $this->assertSame(14, data_get($market, 'market_basis.event_count'));
        $this->assertSame(2, data_get($market, 'market_basis.group_count'));
        $this->assertTrue(data_get($market, 'market_basis.candidate_considered'));
        $this->assertSame(2, data_get($market, 'explanation.sample_size.candidate_event_count'));
        $this->assertSame(-14.0, data_get($market, 'explanation.latency_basis.candidate_latency_delta_seconds'));
        $this->assertSame(-1200.0, data_get($market, 'explanation.cost_basis.candidate_cost_delta_microusd'));
    }

    public function test_dynamic_compute_market_prefers_sufficient_sample_benchmark_candidate(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => true,
        ]);

        $this->mock(ProviderPerformanceProjection::class, function ($mock): void {
            $selectedReport = [
                'available' => true,
                'schema_version' => 'atlas.provider_usage.v1',
                'filters' => [
                    'provider_cli' => 'codex_cli',
                    'domain' => 'programming',
                    'flow' => 'programming.dev',
                    'task_type' => 'programming',
                ],
                'event_count' => 18,
                'returned_count' => 18,
                'fallback_count' => 0,
                'success_count' => 18,
                'failure_count' => 0,
                'success_rate' => 1.0,
                'average_latency_seconds' => 30.0,
                'total_tokens' => 27000,
                'average_total_tokens' => 1500.0,
                'total_cost_microusd' => 36000,
                'average_cost_microusd' => 2000.0,
                'costed_event_count' => 18,
                'unknown_cost_count' => 0,
                'cost_confidence_counts' => [
                    'estimated' => 18,
                ],
                'cost_mode_counts' => [
                    'operational_estimate' => 18,
                ],
                'review_signal' => [
                    'status' => 'ok',
                    'severity' => 'none',
                    'recommended_action' => 'none',
                ],
            ];

            $mock->shouldReceive('reportForWindow')
                ->twice()
                ->andReturn($selectedReport, [
                    ...$selectedReport,
                    'filters' => [
                        'domain' => 'programming',
                        'flow' => 'programming.dev',
                        'task_type' => 'programming',
                    ],
                    'event_count' => 26,
                    'groups' => [
                        [
                            'provider_cli' => 'codex_cli',
                            'domain' => 'programming',
                            'specialist_profile' => 'unknown',
                            'task_type' => 'programming',
                            'event_count' => 18,
                            'success_rate' => 1.0,
                            'average_latency_seconds' => 30.0,
                            'average_cost_microusd' => 2000.0,
                        ],
                        [
                            'provider_cli' => 'claude_cli',
                            'domain' => 'programming',
                            'specialist_profile' => 'unknown',
                            'task_type' => 'programming',
                            'event_count' => 2,
                            'success_rate' => 1.0,
                            'average_latency_seconds' => 8.0,
                            'average_cost_microusd' => 700.0,
                        ],
                        [
                            'provider_cli' => 'gemini_cli',
                            'domain' => 'programming',
                            'specialist_profile' => 'unknown',
                            'task_type' => 'programming',
                            'event_count' => 6,
                            'success_rate' => 0.98,
                            'average_latency_seconds' => 16.0,
                            'average_cost_microusd' => 1200.0,
                        ],
                    ],
                ]);
        });

        $options = app(AtlasDecideService::class)->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => 'avalie uma melhoria de performance',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'dev',
                'decision_mode' => 'atlas_decide',
                'operator_requested_provider' => 'auto',
            ],
        ]);

        $decision = app(AtlasDecideService::class)->operationalDecision($options, selectedProvider: 'codex_cli', selectedModel: 'gpt-5.5')->toArray();
        $market = data_get($decision, 'receipt_v2.provider_selection.selection_explanation.compute_market');

        $this->assertSame('codex_cli', data_get($decision, 'receipt_v2.provider_selection.primary'));
        $this->assertSame('benchmark_lower_latency_alternative', data_get($market, 'recommendation'));
        $this->assertSame('market_opportunity_risk', data_get($market, 'risk'));
        $this->assertTrue(data_get($market, 'decision_factors.has_market_candidate'));
        $this->assertTrue(data_get($market, 'decision_factors.candidate_has_sufficient_sample'));
        $this->assertFalse(data_get($market, 'decision_factors.has_missing_cost'));
        $this->assertSame('gemini_cli', data_get($market, 'benchmark_candidate.provider'));
        $this->assertSame('sufficient', data_get($market, 'benchmark_candidate.sample_status'));
        $this->assertSame(['latency', 'cost'], data_get($market, 'benchmark_candidate.improvement_basis'));
        $this->assertSame(0.98, data_get($market, 'explanation.quality_basis.candidate_success_rate'));
        $this->assertSame(-0.02, data_get($market, 'explanation.quality_basis.candidate_success_rate_delta'));
        $this->assertSame(6, data_get($market, 'explanation.sample_size.candidate_event_count'));
        $this->assertSame(-14.0, data_get($market, 'explanation.latency_basis.candidate_latency_delta_seconds'));
        $this->assertSame(-800.0, data_get($market, 'explanation.cost_basis.candidate_cost_delta_microusd'));
        $this->assertFalse(data_get($market, 'routing_control.changes_provider'));
    }

    public function test_dynamic_compute_market_prefers_higher_quality_when_candidate_samples_are_sufficient(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => true,
        ]);

        $this->mock(ProviderPerformanceProjection::class, function ($mock): void {
            $selectedReport = [
                'available' => true,
                'schema_version' => 'atlas.provider_usage.v1',
                'filters' => [
                    'provider_cli' => 'codex_cli',
                    'domain' => 'programming',
                    'flow' => 'programming.dev',
                    'task_type' => 'programming',
                ],
                'event_count' => 18,
                'returned_count' => 18,
                'fallback_count' => 0,
                'success_count' => 18,
                'failure_count' => 0,
                'success_rate' => 0.96,
                'average_latency_seconds' => 40.0,
                'total_tokens' => 27000,
                'average_total_tokens' => 1500.0,
                'total_cost_microusd' => 36000,
                'average_cost_microusd' => 2000.0,
                'costed_event_count' => 18,
                'unknown_cost_count' => 0,
                'cost_confidence_counts' => [
                    'estimated' => 18,
                ],
                'cost_mode_counts' => [
                    'operational_estimate' => 18,
                ],
                'review_signal' => [
                    'status' => 'ok',
                    'severity' => 'none',
                    'recommended_action' => 'none',
                ],
            ];

            $mock->shouldReceive('reportForWindow')
                ->twice()
                ->andReturn($selectedReport, [
                    ...$selectedReport,
                    'filters' => [
                        'domain' => 'programming',
                        'flow' => 'programming.dev',
                        'task_type' => 'programming',
                    ],
                    'event_count' => 30,
                    'groups' => [
                        [
                            'provider_cli' => 'codex_cli',
                            'domain' => 'programming',
                            'specialist_profile' => 'unknown',
                            'task_type' => 'programming',
                            'event_count' => 18,
                            'success_rate' => 0.96,
                            'average_latency_seconds' => 40.0,
                            'average_cost_microusd' => 2000.0,
                        ],
                        [
                            'provider_cli' => 'claude_cli',
                            'domain' => 'programming',
                            'specialist_profile' => 'unknown',
                            'task_type' => 'programming',
                            'event_count' => 6,
                            'success_rate' => 0.91,
                            'average_latency_seconds' => 10.0,
                            'average_cost_microusd' => 700.0,
                        ],
                        [
                            'provider_cli' => 'gemini_cli',
                            'domain' => 'programming',
                            'specialist_profile' => 'unknown',
                            'task_type' => 'programming',
                            'event_count' => 6,
                            'success_rate' => 0.96,
                            'average_latency_seconds' => 20.0,
                            'average_cost_microusd' => 1300.0,
                        ],
                    ],
                ]);
        });

        $options = app(AtlasDecideService::class)->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => 'compare alternativas para tarefa de arquitetura',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'dev',
                'decision_mode' => 'atlas_decide',
                'operator_requested_provider' => 'auto',
            ],
        ]);

        $decision = app(AtlasDecideService::class)->operationalDecision($options, selectedProvider: 'codex_cli', selectedModel: 'gpt-5.5')->toArray();
        $market = data_get($decision, 'receipt_v2.provider_selection.selection_explanation.compute_market');

        $this->assertSame('benchmark_lower_latency_alternative', data_get($market, 'recommendation'));
        $this->assertSame('market_opportunity_risk', data_get($market, 'risk'));
        $this->assertTrue(data_get($market, 'decision_factors.has_confident_selected_sample'));
        $this->assertTrue(data_get($market, 'decision_factors.has_market_candidate'));
        $this->assertTrue(data_get($market, 'decision_factors.candidate_has_sufficient_sample'));
        $this->assertSame('gemini_cli', data_get($market, 'benchmark_candidate.provider'));
        $this->assertSame('sufficient', data_get($market, 'benchmark_candidate.sample_status'));
        $this->assertSame(0.96, data_get($market, 'explanation.quality_basis.candidate_success_rate'));
        $this->assertSame(0.0, data_get($market, 'explanation.quality_basis.candidate_success_rate_delta'));
        $this->assertSame(-20.0, data_get($market, 'explanation.latency_basis.candidate_latency_delta_seconds'));
        $this->assertSame(-700.0, data_get($market, 'explanation.cost_basis.candidate_cost_delta_microusd'));
        $this->assertFalse(data_get($market, 'routing_control.changes_provider'));
    }

    public function test_dynamic_compute_market_keeps_selected_provider_when_evidence_is_stable(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => true,
        ]);

        $this->mock(ProviderPerformanceProjection::class, function ($mock): void {
            $selectedReport = [
                'available' => true,
                'schema_version' => 'atlas.provider_usage.v1',
                'filters' => [
                    'provider_cli' => 'codex_cli',
                    'domain' => 'programming',
                    'flow' => 'programming.dev',
                    'task_type' => 'programming',
                ],
                'event_count' => 24,
                'returned_count' => 24,
                'fallback_count' => 0,
                'success_count' => 24,
                'failure_count' => 0,
                'success_rate' => 1.0,
                'average_latency_seconds' => 18.0,
                'total_tokens' => 36000,
                'average_total_tokens' => 1500.0,
                'total_cost_microusd' => 36000,
                'average_cost_microusd' => 1500.0,
                'costed_event_count' => 24,
                'unknown_cost_count' => 0,
                'cost_confidence_counts' => [
                    'estimated' => 24,
                ],
                'cost_mode_counts' => [
                    'operational_estimate' => 24,
                ],
                'review_signal' => [
                    'status' => 'ok',
                    'severity' => 'none',
                    'recommended_action' => 'none',
                ],
            ];

            $mock->shouldReceive('reportForWindow')
                ->twice()
                ->andReturn($selectedReport, [
                    ...$selectedReport,
                    'filters' => [
                        'domain' => 'programming',
                        'flow' => 'programming.dev',
                        'task_type' => 'programming',
                    ],
                    'groups' => [
                        [
                            'provider_cli' => 'codex_cli',
                            'domain' => 'programming',
                            'specialist_profile' => 'unknown',
                            'task_type' => 'programming',
                            'event_count' => 24,
                            'success_rate' => 1.0,
                            'average_latency_seconds' => 18.0,
                            'average_cost_microusd' => 1500.0,
                        ],
                        [
                            'provider_cli' => 'claude_cli',
                            'domain' => 'programming',
                            'specialist_profile' => 'unknown',
                            'task_type' => 'programming',
                            'event_count' => 12,
                            'success_rate' => 0.91,
                            'average_latency_seconds' => 32.0,
                            'average_cost_microusd' => 2200.0,
                        ],
                    ],
                ]);
        });

        $options = app(AtlasDecideService::class)->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => 'implemente uma melhoria pequena no atlas dev',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'dev',
                'decision_mode' => 'atlas_decide',
                'operator_requested_provider' => 'auto',
            ],
        ]);

        $decision = app(AtlasDecideService::class)->operationalDecision($options, selectedProvider: 'codex_cli', selectedModel: 'gpt-5.5')->toArray();
        $market = data_get($decision, 'receipt_v2.provider_selection.selection_explanation.compute_market');

        $this->assertSame('codex_cli', data_get($decision, 'receipt_v2.provider_selection.primary'));
        $this->assertSame('keep_selected_provider', data_get($market, 'recommendation'));
        $this->assertSame('low', data_get($market, 'risk'));
        $this->assertSame('high', data_get($market, 'confidence'));
        $this->assertSame('keep_selected_provider_and_continue_monitoring', data_get($market, 'recommended_next_action'));
        $this->assertSame('selected_provider_supported_by_current_ap99_evidence', data_get($market, 'recommendation_reason'));
        $this->assertNull(data_get($market, 'benchmark_candidate'));
        $this->assertTrue(data_get($market, 'decision_factors.selected_quality_acceptable'));
        $this->assertFalse(data_get($market, 'decision_factors.has_quality_risk'));
        $this->assertFalse(data_get($market, 'decision_factors.has_missing_cost'));
        $this->assertFalse(data_get($market, 'decision_factors.has_market_candidate'));
        $this->assertFalse(data_get($market, 'routing_control.changes_provider'));
        $this->assertSame('codex_cli', data_get($market, 'routing_control.selected_provider_preserved'));
        $this->assertSame(2, data_get($market, 'market_basis.group_count'));
        $this->assertFalse(data_get($market, 'market_basis.candidate_considered'));
        $this->assertSame(24, data_get($market, 'explanation.sample_size.selected_event_count'));
        $this->assertSame('available', data_get($market, 'explanation.missing_cost_status.status'));
    }

    public function test_receipt_for_trace_persists_kernel_contracts_for_gateway_metadata(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => true,
        ]);

        $options = app(AtlasDecideService::class)->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => 'corrija um bug no atlas forge',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'forge',
                'decision_mode' => 'atlas_decide',
                'operator_requested_provider' => 'auto',
            ],
        ]);

        $receipt = app(AtlasDecideService::class)->receiptForTrace($options, 'codex_cli', 'gpt-5.5');

        $this->assertSame(86, data_get($receipt, 'confidence_score'));
        $this->assertSame('high', data_get($receipt, 'confidence_band'));
        $this->assertSame('codex_cli', data_get($receipt, 'selection_explanation.selected_provider'));
        $this->assertSame('programming', data_get($receipt, 'selection_explanation.primary_signals.task_type'));
        $this->assertSame('atlas_cli_forge', data_get($receipt, 'kernel_contracts.surface.surface_id'));
        $this->assertTrue(data_get($receipt, 'kernel_contracts.valid'));
        $this->assertTrue(data_get($receipt, 'kernel_contracts.execution_allowed'));
        $this->assertSame('prepared', data_get($receipt, 'kernel_contracts.provider.status'));
        $this->assertSame('codex_cli', data_get($receipt, 'kernel_contracts.provider.provider_id'));
        $this->assertTrue(data_get($receipt, 'kernel_contracts.provider.validation.ok'));
        $this->assertSame(
            data_get($receipt, 'kernel_contracts.provider.request_hash'),
            data_get($receipt, 'receipt_v2.metadata.kernel_contracts.provider.request_hash'),
        );
        $this->assertSame(
            data_get($receipt, 'kernel_contracts.provider.request_hash_algorithm'),
            data_get($receipt, 'receipt_v2.metadata.kernel_contracts.provider.request_hash_algorithm'),
        );
    }

    public function test_provider_contract_receipt_preserves_validator_warnings(): void
    {
        $options = app(AtlasDecideService::class)->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => 'rode uma analise com modelo experimental',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'dev',
                'decision_mode' => 'manual_override',
                'operator_requested_provider' => 'codex_cli',
                'requested_provider' => 'codex_cli',
            ],
        ]);

        $receipt = app(AtlasDecideService::class)->receiptForTrace($options, 'codex_cli', 'future-codex-model');

        $this->assertSame('prepared', data_get($receipt, 'kernel_contracts.provider.status'));
        $this->assertTrue(data_get($receipt, 'kernel_contracts.provider.validation.ok'));
        $this->assertContains(
            'model_not_declared_in_supported_models',
            data_get($receipt, 'kernel_contracts.provider.validation.warnings'),
        );
    }

    public function test_council_provider_has_formal_provider_driver_contract(): void
    {
        $options = app(AtlasDecideService::class)->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => 'rode o conselho claude + codex para revisar uma arquitetura critica',
            'provider' => 'claude_codex',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'dev',
                'decision_mode' => 'manual_override',
                'operator_requested_provider' => 'claude_codex',
                'requested_provider' => 'claude_codex',
            ],
        ]);

        $receipt = app(AtlasDecideService::class)->receiptForTrace($options, 'claude_codex', 'council_default');

        $this->assertSame('council_dual_review', $receipt['execution_strategy']);
        $this->assertTrue(data_get($receipt, 'kernel_contracts.valid'));
        $this->assertTrue(data_get($receipt, 'kernel_contracts.execution_allowed'));
        $this->assertSame('prepared', data_get($receipt, 'kernel_contracts.provider.status'));
        $this->assertSame('claude_codex', data_get($receipt, 'kernel_contracts.provider.provider_id'));
        $this->assertSame('council_default', data_get($receipt, 'kernel_contracts.provider.model'));
        $this->assertTrue(data_get($receipt, 'kernel_contracts.provider.validation.ok'));
        $this->assertSame([], data_get($receipt, 'kernel_contracts.provider.validation.errors'));
        $this->assertSame(
            data_get($receipt, 'kernel_contracts.provider.identity_fragment_hash'),
            data_get($receipt, 'receipt_v2.metadata.kernel_contracts.provider.identity_fragment_hash'),
        );
    }

    public function test_unknown_provider_is_blocked_by_kernel_contract_summary(): void
    {
        $options = app(AtlasDecideService::class)->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => 'teste provider externo ainda nao registrado',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'chat',
                'decision_mode' => 'atlas_decide',
                'operator_requested_provider' => 'auto',
            ],
        ]);

        $receipt = app(AtlasDecideService::class)->receiptForTrace($options, 'openai_http', 'gpt-test');

        $this->assertFalse(data_get($receipt, 'kernel_contracts.valid'));
        $this->assertFalse(data_get($receipt, 'kernel_contracts.execution_allowed'));
        $this->assertSame('unregistered_provider_driver', data_get($receipt, 'kernel_contracts.provider.status'));
        $this->assertContains('provider_contract_not_prepared', data_get($receipt, 'kernel_contracts.blocking_errors'));
    }
}
