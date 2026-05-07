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
            ->where('id', 'self_improvement.voice_realtime_review')
            ->exists();

        DB::table('ai_flow_profiles')->updateOrInsert(
            ['id' => 'self_improvement.voice_realtime_review'],
            $this->withJsonColumns([
                'id' => 'self_improvement.voice_realtime_review',
                'domain_id' => 'self_improvement',
                'label' => 'Voice Realtime Review',
                'status' => 'active',
                'orchestrator' => 'AtlasSelfImprovementOrchestrator',
                'runtime' => 'SelfImprovementRuntime',
                'description' => 'Dedicated Voice Realtime review over readiness, runtime certification, Rivals-Voice baseline, VOICE_* evidence, and privacy gates.',
                'autonomy' => 'low',
                'background_allowed' => true,
                'requires_human_approval_for_destructive' => true,
                'context_policy' => [
                    'preset' => 'atlas_self_improvement',
                    'require_context_pack' => true,
                    'sources' => [
                        'atlas_evidence_ledger',
                        'voice_realtime_readiness',
                        'voice_realtime_runtime_certification',
                        'rivals_voice_report',
                        'engineering_knowledge_base',
                        'code_intelligence',
                    ],
                ],
                'memory_policy' => [
                    'write_scope' => 'proposal_only',
                    'allowed_updates' => ['proposal', 'finding', 'decision_record'],
                    'forbidden_updates' => ['critical_behavior_change', 'provider_policy_change_without_review'],
                ],
                'gate_policy' => [
                    'required' => ['evidence_linked', 'proposal_only', 'human_review_required'],
                    'autonomy_ceiling' => 'proposal_only',
                    'destructive_actions_allowed' => false,
                ],
                'execution_policy' => [
                    'executor_preference' => 'voice_realtime_review_runtime',
                    'proposal_only' => true,
                    'max_autonomy' => 'low',
                ],
                'metadata' => [
                    'schema_version' => 'atlas.self_improvement.voice_realtime_review.flow.v1',
                    'dedicated_surface_id' => 'voice_realtime',
                    'rivals_required_before_maturity' => true,
                    'raw_audio_persistence_allowed' => false,
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
            ->where('id', 'self_improvement.voice_realtime_review')
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
