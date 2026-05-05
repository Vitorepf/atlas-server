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
        $exists = DB::table('ai_domain_profiles')->where('id', 'personal_development')->exists();

        DB::table('ai_domain_profiles')->updateOrInsert(
            ['id' => 'personal_development'],
            $this->withDomainJson($this->domain($now, $exists))
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
     * @return array<string,mixed>
     */
    private function domain(mixed $now, bool $exists): array
    {
        $values = [
            'id' => 'personal_development',
            'label' => 'Personal Development',
            'status' => 'active',
            'default_flow' => 'personal_development.reflect',
            'orchestrator' => 'AtlasPersonalDevelopmentOrchestrator',
            'runtime_family' => 'personal_development',
            'description' => 'Private, non-clinical planning and reflection for habits, routines, focus, energy, learning, personal performance, and life review.',
            'autonomy_default' => 'low',
            'background_allowed' => false,
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => $this->domainGatePolicy(),
            'metadata' => $this->domainMetadata(),
            'updated_at' => $now,
        ];

        if (! $exists) {
            $values['created_at'] = $now;
        }

        return $values;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function flows(): array
    {
        return [
            $this->flow('personal_development.reflect', 'Reflect', 'reflection_runtime', 'Guided non-clinical reflection that turns observations into evidence and small experiments.', ['privacy_review', 'non_clinical_language']),
            $this->flow('personal_development.daily_review', 'Daily Review', 'daily_review_runtime', 'Daily review of routine evidence, focus, energy, commitments, and next experiment.', ['privacy_review', 'evidence_link']),
            $this->flow('personal_development.weekly_review', 'Weekly Review', 'weekly_review_runtime', 'Weekly review across goals, routines, learning, energy, and personal operating rhythm.', ['privacy_review', 'evidence_link']),
            $this->flow('personal_development.habit_design', 'Habit Design', 'habit_design_runtime', 'Habit design flow that drafts cues, friction changes, minimum viable routines, and review checkpoints.', ['privacy_review', 'routine_experiment']),
            $this->flow('personal_development.focus_plan', 'Focus Plan', 'focus_plan_runtime', 'Focus planning flow for attention budget, priority evidence, focus blocks, and interruption policy.', ['privacy_review', 'attention_budget']),
            $this->flow('personal_development.learning_plan', 'Learning Plan', 'learning_plan_runtime', 'Learning plan flow for skill goals, practice loops, evidence, and lightweight review cadence.', ['privacy_review', 'learning_evidence']),
            $this->flow('personal_development.energy_review', 'Energy Review', 'energy_review_runtime', 'Non-clinical energy review focused on routine patterns, load, recovery evidence, and experiments.', ['privacy_review', 'non_clinical_language', 'evidence_link']),
            $this->flow('personal_development.goal_decomposition', 'Goal Decomposition', 'goal_decomposition_runtime', 'Goal decomposition flow that breaks outcomes into projects, next actions, risks, and review evidence.', ['privacy_review', 'bounded_plan']),
            $this->flow('personal_development.recovery_plan', 'Recovery Plan', 'recovery_plan_runtime', 'Non-clinical recovery plan for workload, rest routines, boundaries, and review checkpoints.', ['privacy_review', 'non_clinical_language', 'human_review_for_sensitive']),
            $this->flow('personal_development.forge', 'Forge', 'personal_development_forge_runtime', 'Integrated personal operating plan across goals, habits, focus, learning, energy, recovery, and review loops.', ['privacy_review', 'human_approval', 'non_clinical_language', 'no_auto_calendar_or_task_changes'], true),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function flow(string $id, string $label, string $executor, string $description, array $requiredGates, bool $approvalRequired = false): array
    {
        return [
            'id' => $id,
            'domain_id' => 'personal_development',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasPersonalDevelopmentOrchestrator',
            'runtime' => 'PersonalDevelopmentRuntime',
            'description' => $description,
            'autonomy' => 'low',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['privacy_review', 'non_clinical_language', 'no_diagnosis'],
                'sensitive_requires' => ['human_review'],
                'autonomy_ceiling' => 'plan_only',
            ],
            'execution_policy' => [
                'executor_preference' => $executor,
                'plan_and_artifacts_only' => true,
                'calendar_mutation' => false,
                'task_mutation' => false,
                'forge_requires_human_approval' => $approvalRequired,
                'sensitive_recommendations_require_review' => true,
            ],
            'metadata' => $this->flowMetadata($approvalRequired),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function contextPolicy(): array
    {
        return [
            'preset' => 'personal_development_private_context',
            'require_context_pack' => true,
            'sources' => [
                'operator_goals',
                'habit_notes',
                'daily_review_notes',
                'weekly_review_notes',
                'focus_logs',
                'learning_notes',
                'energy_observations',
                'routine_experiments',
            ],
            'provider_context_requires_redaction' => true,
            'budget' => [
                'max_prompt_tokens' => 10000,
                'reserved_output_tokens' => 3000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function memoryPolicy(): array
    {
        return [
            'projection' => 'personal_development',
            'privacy_default' => 'private',
            'provider_safe_default' => false,
            'provider_safe_only_when_redacted' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_reflection', 'routine_experiment_result', 'reviewed_goal_change'],
                'requires_review_for' => ['sensitive_recommendation', 'identity_level_claim', 'life_review_summary'],
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
            'required' => ['privacy_review', 'non_clinical_language', 'no_diagnosis', 'evidence_link'],
            'sensitive_requires' => ['human_review'],
            'autonomy_ceiling' => 'plan_only',
            'forbidden' => ['medical_treatment', 'psychological_diagnosis', 'automatic_calendar_or_task_mutation'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function domainMetadata(): array
    {
        return [
            'seed' => 'personal_development_domain_contract',
            'surfaces' => ['api:ai/domains', 'app:personal_development', 'mcp:open_brain'],
            'scope' => ['habits', 'routine', 'focus', 'energy', 'learning', 'personal_performance', 'life_review'],
            'safety' => [
                'non_clinical' => true,
                'private_by_default' => true,
                'runtime_does_not_mutate_agenda_or_tasks' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function flowMetadata(bool $approvalRequired): array
    {
        return [
            'seed' => 'personal_development_domain_contract',
            'approval_required' => $approvalRequired,
            'artifacts' => ['structured_plan', 'review_prompts'],
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
