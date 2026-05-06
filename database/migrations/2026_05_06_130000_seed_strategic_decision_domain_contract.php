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
        $exists = DB::table('ai_domain_profiles')->where('id', 'strategic_decision')->exists();

        DB::table('ai_domain_profiles')->updateOrInsert(
            ['id' => 'strategic_decision'],
            $this->withDomainJson(array_merge($this->domain(), [
                'updated_at' => $now,
                ...($exists ? [] : ['created_at' => $now]),
            ]))
        );

        foreach ($this->flows() as $flow) {
            $exists = DB::table('ai_flow_profiles')->where('id', $flow['id'])->exists();

            DB::table('ai_flow_profiles')->updateOrInsert(
                ['id' => $flow['id']],
                $this->withFlowJson(array_merge($flow, [
                    'updated_at' => $now,
                    ...($exists ? [] : ['created_at' => $now]),
                ]))
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_flow_profiles') || ! Schema::hasTable('ai_domain_profiles')) {
            return;
        }

        DB::table('ai_flow_profiles')
            ->whereIn('id', array_column($this->flows(), 'id'))
            ->delete();

        DB::table('ai_domain_profiles')
            ->where('id', 'strategic_decision')
            ->delete();
    }

    /**
     * @return array<string,mixed>
     */
    private function domain(): array
    {
        return [
            'id' => 'strategic_decision',
            'label' => 'Strategic Decision',
            'status' => 'active',
            'default_flow' => 'strategic_decision.review',
            'orchestrator' => 'AtlasStrategicDecisionOrchestrator',
            'runtime_family' => 'strategic_decision',
            'description' => 'Long-horizon co-strategy domain for major decisions, disagreement, cool-down review, values alignment, counterarguments, regret tracking, and longitudinal pattern discovery.',
            'autonomy_default' => 'low',
            'background_allowed' => false,
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => $this->domainGatePolicy(),
            'metadata' => [
                'seed' => 'strategic_decision_domain_contract',
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
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function flows(): array
    {
        return [
            $this->flow('strategic_decision.review', 'Review', 'StrategicDecisionReviewRuntime', ['decision_frame', 'options_map', 'evidence_pack']),
            $this->flow('strategic_decision.cooldown', 'Cool-down', 'StrategicDecisionCooldownRuntime', ['impact_classification', 'cooldown_window', 'revisit_trigger']),
            $this->flow('strategic_decision.values_alignment', 'Values Alignment', 'StrategicDecisionAlignmentRuntime', ['values_trace', 'tradeoff_map', 'agency_check']),
            $this->flow('strategic_decision.counterargument', 'Counterargument', 'StrategicDecisionCounterargumentRuntime', ['steelman', 'red_team_review', 'disagreement_summary']),
            $this->flow('strategic_decision.regret_tracking', 'Regret Tracking', 'StrategicDecisionRegretRuntime', ['rivals_case', 'review_horizon', 'regret_score']),
            $this->flow('strategic_decision.longitudinal_pattern', 'Longitudinal Pattern', 'StrategicDecisionPatternRuntime', ['multi_year_signal', 'privacy_review', 'pattern_confidence']),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function flow(string $id, string $label, string $runtime, array $requiredGates): array
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
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
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
                'seed' => 'strategic_decision_domain_contract',
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
    private function contextPolicy(): array
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
    private function memoryPolicy(): array
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
    private function domainGatePolicy(): array
    {
        return [
            'required' => ['cooldown_policy', 'multi_perspective_review', 'values_alignment', 'operator_agency'],
            'high_impact_requires' => ['human_review', 'rivals_strategy_case', 'scheduled_revisit'],
            'autonomy_ceiling' => 'review_only',
            'forbidden' => ['autonomous_commitment', 'auto_financial_execution', 'auto_life_decision'],
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
