<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_domain_profiles') || ! Schema::hasTable('ai_flow_profiles')) {
            return;
        }

        $now = now();

        DB::table('ai_domain_profiles')->updateOrInsert(
            ['id' => 'marketing'],
            $this->withDomainJson([
                'id' => 'marketing',
                'label' => 'Marketing',
                'status' => 'active',
                'default_flow' => 'marketing.campaign',
                'orchestrator' => 'AtlasMarketingOrchestrator',
                'runtime_family' => 'marketing',
                'description' => 'Strategy, research, positioning, campaign planning, creative production, channel assets, experiments, analytics, and brand governance.',
                'autonomy_default' => 'medium',
                'background_allowed' => false,
                'context_policy' => $this->contextPolicy(),
                'memory_policy' => $this->memoryPolicy(),
                'gate_policy' => $this->domainGatePolicy(),
                'metadata' => $this->domainMetadata(),
                'created_at' => $now,
                'updated_at' => $now,
            ])
        );

        foreach ($this->flows() as $flow) {
            $exists = DB::table('ai_flow_profiles')->where('id', $flow['id'])->exists();
            $values = array_merge($flow, ['updated_at' => $now]);

            if (! $exists) {
                $values['created_at'] = $now;
            }

            DB::table('ai_flow_profiles')->updateOrInsert(['id' => $flow['id']], $this->withFlowJson($values));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_flow_profiles')) {
            return;
        }

        DB::table('ai_flow_profiles')
            ->whereIn('id', array_column($this->flows(), 'id'))
            ->delete();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function flows(): array
    {
        return [
            $this->flow('marketing.strategy', 'Strategy', 'MarketingStrategyRuntime', 'medium', ['strategy_brief', 'business_goal', 'positioning_hypothesis']),
            $this->flow('marketing.research', 'Research', 'MarketingResearchRuntime', 'medium', ['source_pack', 'competitor_notes', 'audience_insights']),
            $this->flow('marketing.positioning', 'Positioning', 'MarketingStrategyRuntime', 'medium', ['positioning_statement', 'differentiation', 'proof_points']),
            $this->flow('marketing.campaign', 'Campaign', 'MarketingRuntime', 'medium', ['creative_brief', 'claim_substantiation', 'measurement_plan']),
            $this->flow('marketing.creative', 'Creative', 'MarketingCreativeRuntime', 'medium', ['creative_variants', 'brand_alignment', 'creative_diversity']),
            $this->flow('marketing.copywriting', 'Copywriting', 'MarketingCopyRuntime', 'medium', ['copy_variants', 'clarity_score', 'claim_substantiation']),
            $this->flow('marketing.media_plan', 'Media Plan', 'MarketingMediaRuntime', 'medium', ['channel_fit', 'budget_rationale', 'measurement_plan']),
            $this->flow('marketing.landing_page', 'Landing Page', 'MarketingAssetRuntime', 'medium', ['offer_clarity', 'conversion_flow', 'claim_substantiation']),
            $this->flow('marketing.email', 'Email', 'MarketingAssetRuntime', 'medium', ['message_match', 'deliverability_review', 'measurement_plan']),
            $this->flow('marketing.social', 'Social', 'MarketingAssetRuntime', 'medium', ['channel_fit', 'creative_variants', 'brand_alignment']),
            $this->flow('marketing.video_script', 'Video Script', 'MarketingCreativeRuntime', 'medium', ['hook_strength', 'script_structure', 'claim_substantiation']),
            $this->flow('marketing.ab_test', 'A/B Test', 'MarketingExperimentRuntime', 'medium', ['hypothesis', 'variant_matrix', 'success_metric']),
            $this->flow('marketing.analytics', 'Analytics', 'MarketingAnalyticsRuntime', 'medium', ['metrics_snapshot', 'insight_summary', 'next_experiment']),
            $this->flow('marketing.brand_review', 'Brand Review', 'MarketingReviewRuntime', 'low', ['brand_alignment', 'compliance_risk', 'claim_substantiation']),
            $this->flow('marketing.forge', 'Forge', 'MarketingForgeRuntime', 'high', ['campaign_kit', 'asset_manifest', 'experiment_plan', 'learning_delta']),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function flow(string $id, string $label, string $runtime, string $autonomy, array $requiredGates): array
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
            'context_policy' => $this->contextPolicy(),
            'skill_policy' => [
                'preset' => 'domain',
                'required_bundles' => ['marketing-strategy', 'brand-review'],
                'require_skill_trace' => true,
            ],
            'tool_policy' => [
                'mode' => 'workspace_read',
                'external_publish' => false,
            ],
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['brand_alignment', 'claim_substantiation', 'audience_fit'],
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => str_ends_with($id, '.forge') ? 'domain_forge_runtime' : 'domain_runtime',
                'quality_required' => true,
            ],
            'metadata' => $this->flowMetadata(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function contextPolicy(): array
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
    private function memoryPolicy(): array
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
    private function domainGatePolicy(): array
    {
        return [
            'required' => ['brand_alignment', 'claim_substantiation', 'audience_fit', 'measurement_plan'],
            'publish_requires' => ['operator_approval', 'compliance_review'],
            'autonomy_ceiling' => 'draft_and_review',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function domainMetadata(): array
    {
        return [
            'seed' => 'marketing_onboarding_contract',
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
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function flowMetadata(): array
    {
        return [
            'seed' => 'marketing_onboarding_contract',
            'surfaces' => ['cli', 'api', 'app', 'mcp'],
            'learning' => [
                'record_evidence' => true,
                'promote_accepted_assets' => true,
                'feed_self_improvement' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function withDomainJson(array $values): array
    {
        foreach (['model_policy', 'context_policy', 'skill_policy', 'tool_policy', 'memory_policy', 'gate_policy', 'metadata'] as $column) {
            $values[$column] = json_encode($values[$column] ?? [], JSON_THROW_ON_ERROR);
        }

        return $values;
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function withFlowJson(array $values): array
    {
        foreach (['model_policy', 'context_policy', 'skill_policy', 'tool_policy', 'memory_policy', 'gate_policy', 'execution_policy', 'metadata'] as $column) {
            $values[$column] = json_encode($values[$column] ?? [], JSON_THROW_ON_ERROR);
        }

        return $values;
    }
};
