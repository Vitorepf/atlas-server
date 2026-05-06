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
        $exists = DB::table('ai_domain_profiles')->where('id', 'writing')->exists();

        DB::table('ai_domain_profiles')->updateOrInsert(
            ['id' => 'writing'],
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
        DB::table('ai_domain_profiles')->where('id', 'writing')->delete();
    }

    /**
     * @return array<string,mixed>
     */
    private function domain(): array
    {
        return [
            'id' => 'writing',
            'label' => 'Writing',
            'status' => 'active',
            'default_flow' => 'writing.draft',
            'orchestrator' => 'AtlasWritingOrchestrator',
            'runtime_family' => 'writing',
            'description' => 'Governed drafting, editing, voice review, publication review, and source-aware writing workflows.',
            'autonomy_default' => 'medium',
            'background_allowed' => false,
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => $this->domainGatePolicy(),
            'metadata' => [
                'seed' => 'writing_onboarding_contract',
                'surfaces' => ['cli:atlas ai --domain=writing', 'api:ai/domains', 'app:writing', 'mcp:open_brain'],
                'surface_policy' => [
                    'draft_until_operator_approval' => true,
                    'external_publish_requires_explicit_human_review' => true,
                    'voice_alignment_gate_required' => true,
                ],
                'learning_policy' => [
                    'accepted_voice_feedback_updates_projection' => true,
                    'published_artifact_outcomes_feed_memory' => true,
                    'publication_risk_blocks_feed_self_improvement' => true,
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
            $this->flow('writing.draft', 'Draft', 'WritingRuntime', 'medium', ['brief_clarity', 'audience_fit', 'voice_alignment']),
            $this->flow('writing.edit', 'Edit', 'WritingEditRuntime', 'medium', ['brief_clarity', 'voice_alignment', 'change_rationale']),
            $this->flow('writing.voice_review', 'Voice Review', 'WritingVoiceRuntime', 'low', ['voice_alignment', 'voice_drift_findings', 'operator_review']),
            $this->flow('writing.publish_review', 'Publish Review', 'WritingReviewRuntime', 'low', ['publication_review', 'claim_review', 'human_review_required']),
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
            'domain_id' => 'writing',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasWritingOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Writing flow.",
            'autonomy' => $autonomy,
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['brief_clarity', 'voice_alignment', 'human_review_required'],
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'writing_packet_runtime',
                'draft_until_operator_approval' => true,
                'external_publish_allowed' => false,
                'quality_required' => true,
            ],
            'metadata' => [
                'seed' => 'writing_onboarding_contract',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_accepted_voice_feedback' => true,
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
            'preset' => 'governed_writing_context',
            'require_context_pack' => true,
            'sources' => ['writing_brief', 'target_audience', 'operator_voice_samples', 'source_material', 'style_guide', 'publication_constraints', 'atlas_vault_curated_notes'],
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
            'projection' => 'writing',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_draft', 'operator_voice_feedback', 'published_artifact_outcome'],
                'requires_review_for' => ['voice_rule', 'public_claim', 'sensitive_story'],
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
            'required' => ['brief_clarity', 'audience_fit', 'voice_alignment', 'human_review_required'],
            'publish_requires' => ['operator_approval', 'claim_review', 'sensitive_disclosure_review'],
            'autonomy_ceiling' => 'draft_and_review',
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
