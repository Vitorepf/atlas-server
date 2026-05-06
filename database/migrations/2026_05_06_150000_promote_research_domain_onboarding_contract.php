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
        $exists = DB::table('ai_domain_profiles')->where('id', 'research')->exists();

        DB::table('ai_domain_profiles')->updateOrInsert(
            ['id' => 'research'],
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
        DB::table('ai_domain_profiles')->where('id', 'research')->delete();
    }

    /**
     * @return array<string,mixed>
     */
    private function domain(): array
    {
        return [
            'id' => 'research',
            'label' => 'Research',
            'status' => 'active',
            'default_flow' => 'research.quick',
            'orchestrator' => 'AtlasResearchOrchestrator',
            'runtime_family' => 'research',
            'description' => 'Grounded research, source discovery, synthesis, contradiction checks, uncertainty and citation governance.',
            'autonomy_default' => 'medium',
            'background_allowed' => true,
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => $this->domainGatePolicy(),
            'metadata' => [
                'seed' => 'research_onboarding_contract',
                'surfaces' => ['cli:atlas ai --domain=research', 'api:ai/domains', 'app:research', 'mcp:open_brain'],
                'surface_policy' => [
                    'research_outputs_are_source_grounded' => true,
                    'memory_promotion_requires_review' => true,
                    'uncertainty_must_be_explicit' => true,
                ],
                'learning_policy' => [
                    'accepted_syntheses_feed_memory' => true,
                    'contradiction_findings_feed_self_improvement' => true,
                    'source_quality_updates_require_review' => true,
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
            $this->flow('research.quick', 'Quick Research', 'medium', ['research_question', 'source_pack', 'citation_policy', 'uncertainty_statement']),
            $this->flow('research.super', 'Super Research', 'high', ['research_question', 'source_pack', 'citation_policy', 'uncertainty_statement', 'contradiction_check']),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function flow(string $id, string $label, string $autonomy, array $requiredGates): array
    {
        return [
            'id' => $id,
            'domain_id' => 'research',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasResearchOrchestrator',
            'runtime' => 'ResearchRuntime',
            'description' => "{$label} canonical Research flow.",
            'autonomy' => $autonomy,
            'background_allowed' => true,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['citation_policy', 'uncertainty_statement'],
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'research_packet_runtime',
                'source_grounded' => true,
                'memory_promotion' => 'proposal_only',
            ],
            'metadata' => [
                'seed' => 'research_onboarding_contract',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_reviewed_syntheses' => true,
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
            'preset' => 'research_context',
            'require_context_pack' => true,
            'sources' => ['source_pack', 'engineering_kb', 'atlas_vault_curated', 'evidence_ledger', 'content_intelligence'],
            'freshness_required_for_unstable_topics' => true,
            'budget' => [
                'max_prompt_tokens' => 18000,
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
            'projection' => 'research',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['reviewed_synthesis', 'source_quality_review', 'accepted_contradiction'],
                'requires_review_for' => ['source_reputation', 'domain_learning', 'atlas_memory_candidate'],
                'never_auto_promote_raw_content' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function domainGatePolicy(): array
    {
        return [
            'required' => ['research_question', 'source_pack', 'citation_policy', 'uncertainty_statement'],
            'super_research_requires' => ['contradiction_check', 'source_diversity', 'freshness_review'],
            'autonomy_ceiling' => 'source_grounded_proposal',
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
