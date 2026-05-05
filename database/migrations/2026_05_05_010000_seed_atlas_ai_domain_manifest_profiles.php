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

        foreach ($this->domains() as $domain) {
            $exists = DB::table('ai_domain_profiles')->where('id', $domain['id'])->exists();

            DB::table('ai_domain_profiles')->updateOrInsert(
                ['id' => $domain['id']],
                $this->withJsonColumns($domain, $now, domain: true, exists: $exists)
            );
        }

        foreach ($this->flows() as $flow) {
            $exists = DB::table('ai_flow_profiles')->where('id', $flow['id'])->exists();

            DB::table('ai_flow_profiles')->updateOrInsert(
                ['id' => $flow['id']],
                $this->withJsonColumns($flow, $now, domain: false, exists: $exists)
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_domain_profiles') || ! Schema::hasTable('ai_flow_profiles')) {
            return;
        }

        DB::table('ai_flow_profiles')
            ->whereIn('id', array_column($this->flows(), 'id'))
            ->delete();

        DB::table('ai_domain_profiles')
            ->whereIn('id', ['marketing', 'self_improvement'])
            ->delete();
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function withJsonColumns(array $values, mixed $now, bool $domain, bool $exists): array
    {
        $jsonColumns = $domain
            ? ['model_policy', 'context_policy', 'skill_policy', 'tool_policy', 'memory_policy', 'gate_policy', 'metadata']
            : ['model_policy', 'context_policy', 'skill_policy', 'tool_policy', 'memory_policy', 'gate_policy', 'execution_policy', 'metadata'];

        foreach ($jsonColumns as $column) {
            $values[$column] = json_encode($values[$column] ?? [], JSON_THROW_ON_ERROR);
        }

        $values['updated_at'] = $now;

        if (! $exists) {
            $values['created_at'] = $now;
        }

        return $values;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function domains(): array
    {
        return [
            [
                'id' => 'marketing',
                'label' => 'Marketing',
                'status' => 'active',
                'default_flow' => 'marketing.campaign',
                'orchestrator' => 'AtlasMarketingOrchestrator',
                'runtime_family' => 'marketing',
                'description' => 'Strategy, campaigns, creative, copy, media planning, analytics, and brand review.',
                'autonomy_default' => 'medium',
                'background_allowed' => false,
                'gate_policy' => ['required' => ['brand_alignment', 'claim_substantiation', 'audience_fit']],
                'metadata' => ['seed' => 'domain_manifest_profiles'],
            ],
            [
                'id' => 'self_improvement',
                'label' => 'Self Improvement',
                'status' => 'active',
                'default_flow' => 'self_improvement.nightly_review',
                'orchestrator' => 'AtlasSelfImprovementOrchestrator',
                'runtime_family' => 'self_improvement',
                'description' => 'Scheduled Atlas self-review, gap detection, proposals, benchmark review, and safe process evolution.',
                'autonomy_default' => 'low',
                'background_allowed' => true,
                'gate_policy' => ['required' => ['risk_classification', 'evidence_link', 'operator_approval_for_medium_plus']],
                'metadata' => ['seed' => 'domain_manifest_profiles'],
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function flows(): array
    {
        return [
            $this->flow('health.review', 'health', 'Health Review', 'AtlasHealthOrchestrator', 'HealthRuntime', 'low', false),
            $this->flow('learning.plan', 'learning', 'Learning Plan', 'AtlasLearningOrchestrator', 'LearningRuntime', 'medium', true),
            $this->flow('writing.draft', 'writing', 'Draft', 'AtlasWritingOrchestrator', 'WritingRuntime', 'medium', false),
            $this->flow('qa.regression_review', 'qa', 'Regression Review', 'AtlasQaOrchestrator', 'QaRuntime', 'medium', false),
            $this->flow('security.threat_review', 'security', 'Threat Review', 'AtlasSecurityOrchestrator', 'SecurityRuntime', 'low', false),
            $this->flow('operations.diagnostic', 'operations', 'Diagnostic', 'AtlasOperationsOrchestrator', 'OperationsRuntime', 'medium', true),
            [
                'id' => 'marketing.campaign',
                'domain_id' => 'marketing',
                'label' => 'Campaign',
                'status' => 'active',
                'orchestrator' => 'AtlasMarketingOrchestrator',
                'runtime' => 'MarketingRuntime',
                'description' => 'End-to-end campaign flow covering strategy, claims, creative, channel plan, launch assets, and analytics loop.',
                'autonomy' => 'medium',
                'background_allowed' => false,
                'requires_human_approval_for_destructive' => true,
                'gate_policy' => ['required' => ['brand_alignment', 'claim_substantiation', 'audience_fit']],
                'execution_policy' => ['executor_preference' => 'domain_runtime'],
                'metadata' => ['seed' => 'domain_manifest_profiles'],
            ],
            [
                'id' => 'self_improvement.nightly_review',
                'domain_id' => 'self_improvement',
                'label' => 'Nightly Review',
                'status' => 'active',
                'orchestrator' => 'AtlasSelfImprovementOrchestrator',
                'runtime' => 'SelfImprovementRuntime',
                'description' => 'Nightly self-review that detects gaps, drift, repeated failures, missing docs, and safe improvement proposals.',
                'autonomy' => 'low',
                'background_allowed' => true,
                'requires_human_approval_for_destructive' => true,
                'gate_policy' => ['required' => ['risk_classification', 'evidence_link', 'operator_approval_for_medium_plus']],
                'execution_policy' => ['executor_preference' => 'scheduled_self_improvement'],
                'metadata' => ['seed' => 'domain_manifest_profiles'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function flow(
        string $id,
        string $domainId,
        string $label,
        string $orchestrator,
        string $runtime,
        string $autonomy,
        bool $backgroundAllowed,
    ): array {
        return [
            'id' => $id,
            'domain_id' => $domainId,
            'label' => $label,
            'status' => 'active',
            'orchestrator' => $orchestrator,
            'runtime' => $runtime,
            'description' => "{$label} canonical domain flow.",
            'autonomy' => $autonomy,
            'background_allowed' => $backgroundAllowed,
            'requires_human_approval_for_destructive' => true,
            'metadata' => ['seed' => 'domain_manifest_profiles'],
        ];
    }
};
