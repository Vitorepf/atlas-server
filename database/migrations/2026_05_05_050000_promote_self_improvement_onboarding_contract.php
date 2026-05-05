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

        DB::table('ai_domain_profiles')
            ->where('id', 'self_improvement')
            ->update([
                'context_policy' => json_encode($this->contextPolicy(), JSON_THROW_ON_ERROR),
                'memory_policy' => json_encode($this->memoryPolicy(), JSON_THROW_ON_ERROR),
                'gate_policy' => json_encode($this->gatePolicy(), JSON_THROW_ON_ERROR),
                'metadata' => json_encode($this->domainMetadata(), JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);

        DB::table('ai_flow_profiles')
            ->where('id', 'self_improvement.nightly_review')
            ->update([
                'context_policy' => json_encode($this->contextPolicy(), JSON_THROW_ON_ERROR),
                'memory_policy' => json_encode($this->memoryPolicy(), JSON_THROW_ON_ERROR),
                'gate_policy' => json_encode($this->gatePolicy(), JSON_THROW_ON_ERROR),
                'metadata' => json_encode($this->flowMetadata(), JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_domain_profiles') || ! Schema::hasTable('ai_flow_profiles')) {
            return;
        }

        DB::table('ai_domain_profiles')
            ->where('id', 'self_improvement')
            ->update([
                'context_policy' => json_encode([], JSON_THROW_ON_ERROR),
                'memory_policy' => json_encode([], JSON_THROW_ON_ERROR),
                'metadata' => json_encode(['seed' => 'self_improvement_onboarding_contract_removed'], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function contextPolicy(): array
    {
        return [
            'preset' => 'atlas_self_improvement',
            'require_context_pack' => true,
            'sources' => [
                'atlas_evidence_ledger',
                'architecture_validation',
                'domain_onboarding_scorecards',
                'engineering_knowledge_base',
                'code_intelligence',
                'tool_evidence',
                'memory_quality',
            ],
            'lookback_hours_default' => 24,
            'budget' => [
                'max_prompt_tokens' => 14000,
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
            'projection' => 'self_improvement',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['repeated_failure', 'missing_contract', 'regression_trend', 'operator_accepted_proposal'],
                'requires_review_for' => ['process_change', 'domain_promotion', 'runtime_policy_change'],
                'never_auto_apply' => ['destructive_change', 'provider_secret_change', 'security_policy_relaxation'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function gatePolicy(): array
    {
        return [
            'required' => ['risk_classification', 'evidence_link', 'operator_approval_for_medium_plus'],
            'proposal_requires' => ['reproducible_signal', 'bounded_scope', 'rollback_path'],
            'autonomy_ceiling' => 'proposal_only',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function domainMetadata(): array
    {
        return [
            'seed' => 'self_improvement_onboarding_contract',
            'surfaces' => [
                'scheduler:daily-02:00',
                'cli:atlas:ai:self-improve',
                'api:ai/domains',
                'api:mobile/inbox',
                'app:atlas engineering',
            ],
            'surface_policy' => [
                'nightly_runs_are_review_first' => true,
                'medium_plus_changes_require_operator_approval' => true,
                'proposals_link_back_to_evidence_ledger' => true,
            ],
            'learning_policy' => [
                'read_all_domain_scorecards' => true,
                'open_initiatives_for_missing_contracts' => true,
                'promote_accepted_improvements_to_memory' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function flowMetadata(): array
    {
        return [
            'seed' => 'self_improvement_onboarding_contract',
            'surfaces' => ['scheduler', 'cli', 'api', 'app'],
            'learning' => [
                'record_findings' => true,
                'emit_safe_review_proposals' => true,
                'feed_domain_onboarding' => true,
            ],
        ];
    }
};
