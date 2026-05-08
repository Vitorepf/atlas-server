<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_flow_profiles')) {
            return;
        }

        $now = now();
        $exists = DB::table('ai_flow_profiles')
            ->where('id', 'self_improvement.provider_release_review')
            ->exists();

        DB::table('ai_flow_profiles')->updateOrInsert(
            ['id' => 'self_improvement.provider_release_review'],
            $this->withJsonColumns([
                'id' => 'self_improvement.provider_release_review',
                'domain_id' => 'self_improvement',
                'label' => 'Provider Release Review',
                'status' => 'active',
                'orchestrator' => 'AtlasSelfImprovementOrchestrator',
                'runtime' => 'SelfImprovementRuntime',
                'description' => 'Dedicated Curator review over provider releases, Rivals requirements, skill-pack absorption, Decide signals, and hardcode/provider-channel regression risk.',
                'autonomy' => 'low',
                'background_allowed' => true,
                'requires_human_approval_for_destructive' => true,
                'context_policy' => [
                    'preset' => 'atlas_self_improvement',
                    'require_context_pack' => true,
                    'sources' => [
                        'provider_release_envelopes',
                        'architecture_operations',
                        'engineering_knowledge_base',
                        'provider_performance',
                        'rivals_benchmarks',
                        'code_intelligence',
                    ],
                ],
                'memory_policy' => [
                    'write_scope' => 'proposal_only',
                    'allowed_updates' => ['proposal', 'finding', 'decision_record'],
                    'forbidden_updates' => ['critical_behavior_change', 'provider_policy_change_without_review', 'direct_provider_channel'],
                ],
                'gate_policy' => [
                    'required' => ['evidence_linked', 'proposal_only', 'human_review_required', 'no_provider_hardcode'],
                    'autonomy_ceiling' => 'proposal_only',
                    'destructive_actions_allowed' => false,
                ],
                'execution_policy' => [
                    'executor_preference' => 'provider_release_review_runtime',
                    'proposal_only' => true,
                    'max_autonomy' => 'low',
                ],
                'metadata' => [
                    'schema_version' => 'atlas.self_improvement.provider_release_review.flow.v1',
                    'release_envelope_schema' => 'atlas.provider_release.v1',
                    'decide_signal_schema' => 'atlas.decide.provider_release_signal.v1',
                    'rivals_required_before_policy_promotion' => true,
                    'direct_provider_channel_allowed' => false,
                ],
            ], $now, $exists)
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_flow_profiles')) {
            return;
        }

        DB::table('ai_flow_profiles')
            ->where('id', 'self_improvement.provider_release_review')
            ->delete();
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function withJsonColumns(array $values, mixed $now, bool $exists): array
    {
        foreach (['model_policy', 'context_policy', 'skill_policy', 'tool_policy', 'memory_policy', 'gate_policy', 'execution_policy', 'metadata'] as $column) {
            $values[$column] = json_encode($values[$column] ?? [], JSON_THROW_ON_ERROR);
        }

        $values['updated_at'] = $now;

        if (! $exists) {
            $values['created_at'] = $now;
        }

        return $values;
    }
};
