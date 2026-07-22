<?php

namespace App\Services\Ai\DomainProfiles;

use App\Services\Ai\Finance\AtlasFinanceDomainContract;

class EnterpriseProfileBuilder
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public function domains(): array
    {
        return [
            'finance' => [
                'id' => 'finance',
                'label' => 'Finance',
                'status' => 'active',
                'default_flow' => AtlasFinanceDomainContract::FLOW_MARKET_RESEARCH,
                'orchestrator' => 'AtlasFinanceOrchestrator',
                'runtime_family' => 'finance',
                'description' => 'Financial research, portfolio analysis, risk review, thesis planning, macro and issuer review, compliance checks, and backtest planning for analysis/review only.',
                'autonomy_default' => 'low',
                'background_allowed' => false,
                'context_policy' => $this->financeContextPolicy(),
                'tool_policy' => $this->financeToolPolicy(),
                'memory_policy' => $this->financeMemoryPolicy(),
                'gate_policy' => $this->financeDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['cli:atlas ai --domain=finance', 'api:ai/domains', 'app:finance', 'mcp:open_brain'],
                    'surface_policy' => [
                        'analysis_review_only' => true,
                        'market_orders_forbidden' => true,
                        'broker_execution_requires_separate_non_ai_system' => true,
                    ],
                    'learning_policy' => [
                        'reviewed_finance_findings_feed_memory' => true,
                        'compliance_blocks_feed_self_improvement' => true,
                    ],
                ],
            ],
            'marketing' => [
                'id' => 'marketing',
                'label' => 'Marketing',
                'status' => 'active',
                'default_flow' => 'marketing.campaign',
                'orchestrator' => 'AtlasMarketingOrchestrator',
                'runtime_family' => 'marketing',
                'description' => 'Strategy, research, positioning, campaign planning, creative production, channel assets, experiments, analytics, and brand governance.',
                'autonomy_default' => 'medium',
                'background_allowed' => false,
                'context_policy' => $this->marketingContextPolicy(),
                'memory_policy' => $this->marketingMemoryPolicy(),
                'gate_policy' => $this->marketingDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => [
                        'cli:atlas ai --domain=marketing',
                        'api:ai/domains',
                        'app:marketing',
                        'app:atlas engineering',
                        'mcp:open_brain',
                    ],
                    'surface_policy' => [
                        'generation_is_draft_until_operator_approval' => true,
                        'external_publishing_requires_explicit_surface' => true,
                        'brand_and_claim_gates_are_required' => true,
                    ],
                    'learning_policy' => [
                        'campaign_results_feed_memory' => true,
                        'accepted_brand_feedback_updates_projection' => true,
                        'failed_experiments_create_learning_delta' => true,
                    ],
                ],
            ],
            'strategic_decision' => [
                'id' => 'strategic_decision',
                'label' => 'Strategic Decision',
                'status' => 'active',
                'default_flow' => 'strategic_decision.review',
                'orchestrator' => 'AtlasStrategicDecisionOrchestrator',
                'runtime_family' => 'strategic_decision',
                'description' => 'Long-horizon co-strategy domain for major decisions, disagreement, cool-down review, values alignment, counterarguments, regret tracking, and longitudinal pattern discovery.',
                'autonomy_default' => 'low',
                'background_allowed' => false,
                'context_policy' => $this->strategicDecisionContextPolicy(),
                'memory_policy' => $this->strategicDecisionMemoryPolicy(),
                'gate_policy' => $this->strategicDecisionDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['cli:atlas ai --domain=strategic_decision', 'api:ai/domains', 'app:strategy', 'mcp:open_brain'],
                    'surface_policy' => [
                        'advice_is_review_not_command' => true,
                        'cooldown_required_for_high_impact' => true,
                        'operator_agency_preserved' => true,
                        'no_autonomous_life_or_business_commitment' => true,
                    ],
                    'learning_policy' => [
                        'rivals_strategy_cases_required' => true,
                        'regret_reviews_feed_private_memory' => true,
                        'accepted_patterns_feed_self_improvement' => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function flows(): array
    {
        return [
            ...$this->financeStaticFlows(),
            ...$this->marketingStaticFlows(),
            ...$this->strategicDecisionStaticFlows(),
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function financeStaticFlows(): array
    {
        return collect($this->financeContract()->flowDefinitions())
            ->mapWithKeys(fn (array $definition, string $id): array => [
                $id => $this->financeFlow($id, $definition),
            ])
            ->all();
    }

    /**
     * @param  array<string,mixed>  $definition
     * @return array<string,mixed>
     */
    private function financeFlow(string $id, array $definition): array
    {
        $requiresApproval = (bool) ($definition['requires_human_approval'] ?? false);

        return [
            'id' => $id,
            'domain_id' => 'finance',
            'label' => (string) $definition['label'],
            'status' => 'active',
            'orchestrator' => 'AtlasFinanceOrchestrator',
            'runtime' => (string) $definition['runtime'],
            'description' => "{$definition['label']} canonical Finance flow for analysis/review only.",
            'autonomy' => 'low',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->financeContextPolicy(),
            'tool_policy' => $this->financeToolPolicy(),
            'memory_policy' => $this->financeMemoryPolicy(),
            'gate_policy' => [
                'required' => (array) $definition['required_gates'],
                'requires_final_summary' => true,
                'autonomy_ceiling' => AtlasFinanceDomainContract::OUTPUT_MODE,
                'market_execution_allowed' => false,
            ],
            'execution_policy' => [
                'executor_preference' => $requiresApproval ? 'finance_forge_review_runtime' : 'finance_review_runtime',
                'analysis_only' => true,
                'market_execution_allowed' => false,
                'order_generation_allowed' => false,
                'requires_human_approval' => $requiresApproval,
                'required_evidence' => (array) $definition['required_evidence'],
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_reviewed_findings' => true,
                    'feed_self_improvement' => true,
                ],
                'requires_human_approval' => $requiresApproval,
                'forbidden_actions' => AtlasFinanceDomainContract::FORBIDDEN_MARKET_ACTIONS,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function financeContextPolicy(): array
    {
        return [
            'preset' => 'finance_review_context',
            'require_context_pack' => true,
            'sources' => $this->financeContract()->contextSources(),
            'budget' => [
                'max_prompt_tokens' => 14000,
                'reserved_output_tokens' => 4000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function financeToolPolicy(): array
    {
        return $this->financeContract()->toolPolicy();
    }

    /**
     * @return array<string,mixed>
     */
    private function financeMemoryPolicy(): array
    {
        return $this->financeContract()->memoryPolicy();
    }

    /**
     * @return array<string,mixed>
     */
    private function financeDomainGatePolicy(): array
    {
        return $this->financeContract()->domainGatePolicy();
    }

    private function financeContract(): AtlasFinanceDomainContract
    {
        return new AtlasFinanceDomainContract;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function marketingStaticFlows(): array
    {
        return [
            'marketing.strategy' => $this->marketingFlow('marketing.strategy', 'Strategy', 'MarketingStrategyRuntime', 'medium', ['strategy_brief', 'business_goal', 'positioning_hypothesis']),
            'marketing.research' => $this->marketingFlow('marketing.research', 'Research', 'MarketingResearchRuntime', 'medium', ['source_pack', 'competitor_notes', 'audience_insights']),
            'marketing.positioning' => $this->marketingFlow('marketing.positioning', 'Positioning', 'MarketingStrategyRuntime', 'medium', ['positioning_statement', 'differentiation', 'proof_points']),
            'marketing.campaign' => $this->marketingFlow('marketing.campaign', 'Campaign', 'MarketingRuntime', 'medium', ['creative_brief', 'claim_substantiation', 'measurement_plan']),
            'marketing.creative' => $this->marketingFlow('marketing.creative', 'Creative', 'MarketingCreativeRuntime', 'medium', ['creative_variants', 'brand_alignment', 'creative_diversity']),
            'marketing.copywriting' => $this->marketingFlow('marketing.copywriting', 'Copywriting', 'MarketingCopyRuntime', 'medium', ['copy_variants', 'clarity_score', 'claim_substantiation']),
            'marketing.media_plan' => $this->marketingFlow('marketing.media_plan', 'Media Plan', 'MarketingMediaRuntime', 'medium', ['channel_fit', 'budget_rationale', 'measurement_plan']),
            'marketing.landing_page' => $this->marketingFlow('marketing.landing_page', 'Landing Page', 'MarketingAssetRuntime', 'medium', ['offer_clarity', 'conversion_flow', 'claim_substantiation']),
            'marketing.email' => $this->marketingFlow('marketing.email', 'Email', 'MarketingAssetRuntime', 'medium', ['message_match', 'deliverability_review', 'measurement_plan']),
            'marketing.social' => $this->marketingFlow('marketing.social', 'Social', 'MarketingAssetRuntime', 'medium', ['channel_fit', 'creative_variants', 'brand_alignment']),
            'marketing.video_script' => $this->marketingFlow('marketing.video_script', 'Video Script', 'MarketingCreativeRuntime', 'medium', ['hook_strength', 'script_structure', 'claim_substantiation']),
            'marketing.ab_test' => $this->marketingFlow('marketing.ab_test', 'A/B Test', 'MarketingExperimentRuntime', 'medium', ['hypothesis', 'variant_matrix', 'success_metric']),
            'marketing.analytics' => $this->marketingFlow('marketing.analytics', 'Analytics', 'MarketingAnalyticsRuntime', 'medium', ['metrics_snapshot', 'insight_summary', 'next_experiment']),
            'marketing.brand_review' => $this->marketingFlow('marketing.brand_review', 'Brand Review', 'MarketingReviewRuntime', 'low', ['brand_alignment', 'compliance_risk', 'claim_substantiation']),
            'marketing.forge' => $this->marketingFlow('marketing.forge', 'Forge', 'MarketingForgeRuntime', 'high', ['campaign_kit', 'asset_manifest', 'experiment_plan', 'learning_delta']),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function marketingFlow(string $id, string $label, string $runtime, string $autonomy, array $requiredGates): array
    {
        return [
            'id' => $id,
            'domain_id' => 'marketing',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasMarketingOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Marketing flow.",
            'autonomy' => $autonomy,
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->marketingContextPolicy(),
            'skill_policy' => [
                'preset' => 'domain',
                'required_bundles' => ['marketing-strategy', 'brand-review'],
                'require_skill_trace' => true,
            ],
            'tool_policy' => [
                'mode' => 'workspace_read',
                'external_publish' => false,
            ],
            'memory_policy' => $this->marketingMemoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['brand_alignment', 'claim_substantiation', 'audience_fit'],
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => str_ends_with($id, '.forge') ? 'domain_forge_runtime' : 'domain_runtime',
                'quality_required' => true,
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_accepted_assets' => true,
                    'feed_self_improvement' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function marketingContextPolicy(): array
    {
        return [
            'preset' => 'marketing_growth_context',
            'require_context_pack' => true,
            'sources' => [
                'product_offer',
                'icp_personas',
                'brand_voice',
                'competitor_research',
                'campaign_history',
                'performance_metrics',
                'legal_compliance_constraints',
                'asset_library',
            ],
            'budget' => [
                'max_prompt_tokens' => 16000,
                'reserved_output_tokens' => 5000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function marketingMemoryPolicy(): array
    {
        return [
            'projection' => 'marketing',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_campaign', 'performance_snapshot', 'brand_feedback', 'experiment_result'],
                'requires_review_for' => ['brand_voice_rule', 'claim_library', 'audience_insight'],
                'never_auto_publish' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function marketingDomainGatePolicy(): array
    {
        return [
            'required' => ['brand_alignment', 'claim_substantiation', 'audience_fit', 'measurement_plan'],
            'publish_requires' => ['operator_approval', 'compliance_review'],
            'autonomy_ceiling' => 'draft_and_review',
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function strategicDecisionStaticFlows(): array
    {
        return [
            'strategic_decision.review' => $this->strategicDecisionFlow('strategic_decision.review', 'Review', 'StrategicDecisionReviewRuntime', ['decision_frame', 'options_map', 'evidence_pack']),
            'strategic_decision.cooldown' => $this->strategicDecisionFlow('strategic_decision.cooldown', 'Cool-down', 'StrategicDecisionCooldownRuntime', ['impact_classification', 'cooldown_window', 'revisit_trigger']),
            'strategic_decision.values_alignment' => $this->strategicDecisionFlow('strategic_decision.values_alignment', 'Values Alignment', 'StrategicDecisionAlignmentRuntime', ['values_trace', 'tradeoff_map', 'agency_check']),
            'strategic_decision.counterargument' => $this->strategicDecisionFlow('strategic_decision.counterargument', 'Counterargument', 'StrategicDecisionCounterargumentRuntime', ['steelman', 'red_team_review', 'disagreement_summary']),
            'strategic_decision.regret_tracking' => $this->strategicDecisionFlow('strategic_decision.regret_tracking', 'Regret Tracking', 'StrategicDecisionRegretRuntime', ['rivals_case', 'review_horizon', 'regret_score']),
            'strategic_decision.longitudinal_pattern' => $this->strategicDecisionFlow('strategic_decision.longitudinal_pattern', 'Longitudinal Pattern', 'StrategicDecisionPatternRuntime', ['multi_year_signal', 'privacy_review', 'pattern_confidence']),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function strategicDecisionFlow(string $id, string $label, string $runtime, array $requiredGates): array
    {
        return [
            'id' => $id,
            'domain_id' => 'strategic_decision',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasStrategicDecisionOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Strategic Decision flow.",
            'autonomy' => 'low',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->strategicDecisionContextPolicy(),
            'memory_policy' => $this->strategicDecisionMemoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['cooldown_policy', 'multi_perspective_review', 'values_alignment', 'operator_agency'],
                'autonomy_ceiling' => 'review_only',
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'strategic_decision_review_runtime',
                'review_only' => true,
                'commitment_execution_allowed' => false,
                'requires_rivals_strategy_case' => $id !== 'strategic_decision.counterargument',
                'requires_human_approval' => true,
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'link_rivals_strategy_case' => true,
                    'feed_self_improvement' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function strategicDecisionContextPolicy(): array
    {
        return [
            'preset' => 'strategic_decision_context',
            'require_context_pack' => true,
            'sources' => [
                'operator_goals',
                'decision_history',
                'rivals_strategy_cases',
                'values_notes',
                'project_commitments',
                'risk_register',
                'longitudinal_patterns',
                'evidence_ledger',
            ],
            'provider_context_requires_redaction' => true,
            'budget' => [
                'max_prompt_tokens' => 14000,
                'reserved_output_tokens' => 4000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function strategicDecisionMemoryPolicy(): array
    {
        return [
            'projection' => 'strategic_decision',
            'privacy_default' => 'private',
            'provider_safe_default' => false,
            'provider_safe_only_when_redacted' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['reviewed_decision', 'regret_review', 'accepted_longitudinal_pattern'],
                'requires_review_for' => ['identity_level_pattern', 'business_direction', 'life_strategy'],
                'never_auto_apply' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function strategicDecisionDomainGatePolicy(): array
    {
        return [
            'required' => ['cooldown_policy', 'multi_perspective_review', 'values_alignment', 'operator_agency'],
            'high_impact_requires' => ['human_review', 'rivals_strategy_case', 'scheduled_revisit'],
            'autonomy_ceiling' => 'review_only',
            'forbidden' => ['autonomous_commitment', 'auto_financial_execution', 'auto_life_decision'],
        ];
    }
}
