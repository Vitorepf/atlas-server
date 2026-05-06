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
        $exists = DB::table('ai_domain_profiles')->where('id', 'general')->exists();

        DB::table('ai_domain_profiles')->updateOrInsert(
            ['id' => 'general'],
            $this->withDomainJson(array_merge($this->domain(), [
                'updated_at' => $now,
                ...($exists ? [] : ['created_at' => $now]),
            ]))
        );

        $exists = DB::table('ai_flow_profiles')->where('id', 'general.answer')->exists();

        DB::table('ai_flow_profiles')->updateOrInsert(
            ['id' => 'general.answer'],
            $this->withFlowJson(array_merge($this->flow(), [
                'updated_at' => $now,
                ...($exists ? [] : ['created_at' => $now]),
            ]))
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_flow_profiles') || ! Schema::hasTable('ai_domain_profiles')) {
            return;
        }

        DB::table('ai_flow_profiles')->where('id', 'general.answer')->delete();
        DB::table('ai_domain_profiles')->where('id', 'general')->delete();
    }

    /**
     * @return array<string,mixed>
     */
    private function domain(): array
    {
        return [
            'id' => 'general',
            'label' => 'General Answer',
            'status' => 'active',
            'default_flow' => 'general.answer',
            'orchestrator' => 'StandardResponseOrchestrator',
            'runtime_family' => 'conversation',
            'description' => 'Governed answer and triage fallback for simple questions; specialized work must hand off to the owning Atlas domain through Decide.',
            'autonomy_default' => 'low',
            'background_allowed' => false,
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => $this->domainGatePolicy(),
            'metadata' => [
                'seed' => 'general_onboarding_contract',
                'surfaces' => ['cli:atlas ask', 'api:ai/chat', 'app:chat', 'mcp:open_brain'],
                'surface_policy' => [
                    'answer_or_triage_only' => true,
                    'specialized_requests_must_handoff' => true,
                    'no_policy_bypass' => true,
                ],
                'learning_policy' => [
                    'promote_only_reviewed_general_guidance' => true,
                    'handoff_misses_feed_intent_router' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function flow(): array
    {
        return [
            'id' => 'general.answer',
            'domain_id' => 'general',
            'label' => 'Answer',
            'status' => 'active',
            'orchestrator' => 'StandardResponseOrchestrator',
            'runtime' => 'StandardAiResponse',
            'description' => 'Governed answer and triage fallback; not a substitute for specialized Atlas domains.',
            'autonomy' => 'low',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => [
                'required' => ['question_present', 'answer_or_triage_only', 'domain_handoff_review'],
                'global_required' => ['no_policy_bypass', 'no_destructive_action'],
                'autonomy_ceiling' => 'answer_or_triage_only',
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'general_answer_packet_runtime',
                'answer_or_triage_only' => true,
                'specialized_work_allowed' => false,
                'destructive_action_allowed' => false,
                'tool_execution_allowed' => false,
                'provider_override_allowed' => false,
            ],
            'metadata' => [
                'seed' => 'general_onboarding_contract',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_reviewed_guidance' => true,
                    'feed_intent_router' => true,
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
            'preset' => 'general_answer_context',
            'require_context_pack' => false,
            'sources' => ['conversation_context', 'explicit_user_context', 'surface_hints', 'domain_catalog'],
            'budget' => [
                'max_prompt_tokens' => 6000,
                'reserved_output_tokens' => 2000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function memoryPolicy(): array
    {
        return [
            'projection' => 'general',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_general_guidance', 'reviewed_intent_handoff'],
                'requires_review_for' => ['new_general_policy', 'domain_handoff_rule'],
                'never_auto_promote_specialized_advice' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function domainGatePolicy(): array
    {
        return [
            'required' => ['question_present', 'answer_or_triage_only', 'domain_handoff_review'],
            'specialized_work_requires' => ['atlas_decide', 'domain_profile', 'flow_profile'],
            'autonomy_ceiling' => 'answer_or_triage_only',
            'destructive_action_allowed' => false,
            'tool_execution_allowed' => false,
            'provider_override_allowed' => false,
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
