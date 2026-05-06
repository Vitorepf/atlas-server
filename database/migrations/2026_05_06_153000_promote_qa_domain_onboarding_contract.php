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
        $exists = DB::table('ai_domain_profiles')->where('id', 'qa')->exists();

        DB::table('ai_domain_profiles')->updateOrInsert(
            ['id' => 'qa'],
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
        DB::table('ai_domain_profiles')->where('id', 'qa')->delete();
    }

    /**
     * @return array<string,mixed>
     */
    private function domain(): array
    {
        return [
            'id' => 'qa',
            'label' => 'QA',
            'status' => 'active',
            'default_flow' => 'qa.regression_review',
            'orchestrator' => 'AtlasQaOrchestrator',
            'runtime_family' => 'qa',
            'description' => 'Cross-domain quality assurance for regression review, acceptance review, evidence audit, and release readiness without executing tests or overriding domain gates.',
            'autonomy_default' => 'low',
            'background_allowed' => false,
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => $this->domainGatePolicy(),
            'metadata' => [
                'seed' => 'qa_onboarding_contract',
                'surfaces' => ['cli:atlas ai --domain=qa', 'api:ai/domains', 'app:qa', 'mcp:open_brain'],
                'surface_policy' => [
                    'cross_domain_review_only' => true,
                    'programming_qa_executes_code_tests_not_this_domain' => true,
                    'domain_gates_cannot_be_overridden' => true,
                ],
                'learning_policy' => [
                    'accepted_qa_findings_feed_memory' => true,
                    'evidence_gaps_feed_self_improvement' => true,
                    'repeated_regressions_feed_domain_gates' => true,
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
            $this->flow('qa.regression_review', 'Regression Review', 'QaRegressionRuntime', ['qa_scope', 'acceptance_criteria', 'risk_review']),
            $this->flow('qa.acceptance_review', 'Acceptance Review', 'QaAcceptanceRuntime', ['acceptance_criteria', 'criteria_coverage', 'human_review_required']),
            $this->flow('qa.evidence_audit', 'Evidence Audit', 'QaEvidenceRuntime', ['evidence_refs', 'traceability_map', 'uncertainty_statement']),
            $this->flow('qa.release_readiness', 'Release Readiness', 'QaReleaseRuntime', ['release_risk', 'blocking_findings', 'go_no_go_recommendation']),
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
            'domain_id' => 'qa',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasQaOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical QA flow for cross-domain review only.",
            'autonomy' => 'low',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['qa_scope', 'acceptance_criteria', 'human_review_required'],
                'autonomy_ceiling' => 'review_only',
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'qa_packet_runtime',
                'review_only' => true,
                'test_execution_allowed' => false,
                'deploy_allowed' => false,
                'domain_gate_override_allowed' => false,
            ],
            'metadata' => [
                'seed' => 'qa_onboarding_contract',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_reviewed_findings' => true,
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
            'preset' => 'cross_domain_qa_context',
            'require_context_pack' => true,
            'sources' => ['operation_envelope', 'decision_receipt', 'acceptance_criteria', 'evidence_ledger', 'domain_gate_results', 'test_artifacts_when_available', 'risk_register', 'operator_constraints'],
            'budget' => [
                'max_prompt_tokens' => 12000,
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
            'projection' => 'qa',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_qa_finding', 'verified_regression_pattern', 'release_readiness_review'],
                'requires_review_for' => ['new_quality_rule', 'domain_gate_change', 'release_policy_change'],
                'never_auto_promote_unverified_quality_claims' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function domainGatePolicy(): array
    {
        return [
            'required' => ['qa_scope', 'acceptance_criteria', 'evidence_refs', 'risk_review', 'human_review_required'],
            'release_requires' => ['blocking_findings', 'go_no_go_recommendation', 'operator_approval'],
            'autonomy_ceiling' => 'review_only',
            'test_execution_allowed' => false,
            'deploy_allowed' => false,
            'domain_gate_override_allowed' => false,
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
