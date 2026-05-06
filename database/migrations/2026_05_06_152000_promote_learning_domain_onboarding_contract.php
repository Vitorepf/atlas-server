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
        $exists = DB::table('ai_domain_profiles')->where('id', 'learning')->exists();

        DB::table('ai_domain_profiles')->updateOrInsert(
            ['id' => 'learning'],
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

        DB::table('ai_flow_profiles')->whereIn('id', array_column($this->flows(), 'id'))->delete();
        DB::table('ai_domain_profiles')->where('id', 'learning')->delete();
    }

    /**
     * @return array<string,mixed>
     */
    private function domain(): array
    {
        return [
            'id' => 'learning',
            'label' => 'Learning',
            'status' => 'active',
            'default_flow' => 'learning.plan',
            'orchestrator' => 'AtlasLearningOrchestrator',
            'runtime_family' => 'learning',
            'description' => 'Human learning domain for study plans, deliberate practice, review, spaced repetition, and mastery evidence without modifying the Core Learning Plane.',
            'autonomy_default' => 'medium',
            'background_allowed' => true,
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => $this->domainGatePolicy(),
            'metadata' => [
                'seed' => 'learning_onboarding_contract',
                'surfaces' => ['cli:atlas ai --domain=learning', 'api:ai/domains', 'app:learning', 'mcp:open_brain'],
                'surface_policy' => [
                    'human_learning_domain_only' => true,
                    'core_learning_plane_not_modified' => true,
                    'calendar_and_task_mutation_forbidden' => true,
                ],
                'learning_policy' => [
                    'accepted_mastery_evidence_feeds_memory' => true,
                    'practice_outcomes_feed_review_packets' => true,
                    'domain_findings_feed_self_improvement' => true,
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
            $this->flow('learning.plan', 'Learning Plan', 'LearningRuntime', 'medium', ['learning_objective', 'skill_scope', 'target_level']),
            $this->flow('learning.practice', 'Practice', 'LearningPracticeRuntime', 'medium', ['practice_loop', 'feedback_loop', 'mastery_rubric']),
            $this->flow('learning.review', 'Review', 'LearningReviewRuntime', 'low', ['outcome_evidence', 'gap_map', 'next_iteration']),
            $this->flow('learning.spaced_review', 'Spaced Review', 'LearningSpacedReviewRuntime', 'low', ['spaced_review', 'retrieval_practice', 'forgetting_risk']),
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
            'domain_id' => 'learning',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasLearningOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Learning flow.",
            'autonomy' => $autonomy,
            'background_allowed' => true,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['learning_objective', 'practice_loop', 'mastery_rubric'],
                'autonomy_ceiling' => 'plan_only',
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'learning_packet_runtime',
                'plan_only_until_operator_acceptance' => true,
                'calendar_mutation' => false,
                'task_mutation' => false,
                'core_learning_plane_mutation' => false,
            ],
            'metadata' => [
                'seed' => 'learning_onboarding_contract',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_reviewed_mastery_evidence' => true,
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
            'preset' => 'human_learning_context',
            'require_context_pack' => true,
            'sources' => ['learning_goal', 'current_skill_profile', 'target_skill_profile', 'source_material', 'practice_history', 'mistake_log', 'retrieval_practice_results', 'atlas_vault_curated_notes'],
            'budget' => [
                'max_prompt_tokens' => 14000,
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
            'projection' => 'learning',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_learning_plan', 'mastery_evidence', 'practice_outcome', 'reviewed_mistake_pattern'],
                'requires_review_for' => ['skill_profile_change', 'long_term_learning_protocol', 'operator_cognitive_pattern'],
                'never_auto_promote_unreviewed_mastery_claims' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function domainGatePolicy(): array
    {
        return [
            'required' => ['learning_objective', 'skill_scope', 'target_level', 'practice_loop', 'mastery_rubric'],
            'review_requires' => ['outcome_evidence', 'gap_map', 'next_iteration'],
            'autonomy_ceiling' => 'plan_only',
            'core_learning_plane_mutation' => false,
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
