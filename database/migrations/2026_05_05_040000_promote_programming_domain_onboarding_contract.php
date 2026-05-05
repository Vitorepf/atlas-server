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
            ->where('id', 'programming')
            ->update([
                'context_policy' => json_encode($this->contextPolicy(), JSON_THROW_ON_ERROR),
                'memory_policy' => json_encode($this->memoryPolicy(), JSON_THROW_ON_ERROR),
                'gate_policy' => json_encode($this->domainGatePolicy(), JSON_THROW_ON_ERROR),
                'metadata' => json_encode($this->domainMetadata(), JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);

        foreach ($this->flowContracts() as $flowId => $contract) {
            DB::table('ai_flow_profiles')
                ->where('id', $flowId)
                ->update([
                    'context_policy' => json_encode($contract['context_policy'], JSON_THROW_ON_ERROR),
                    'memory_policy' => json_encode($contract['memory_policy'], JSON_THROW_ON_ERROR),
                    'gate_policy' => json_encode($contract['gate_policy'], JSON_THROW_ON_ERROR),
                    'metadata' => json_encode($contract['metadata'], JSON_THROW_ON_ERROR),
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_domain_profiles') || ! Schema::hasTable('ai_flow_profiles')) {
            return;
        }

        DB::table('ai_domain_profiles')
            ->where('id', 'programming')
            ->update([
                'context_policy' => json_encode([], JSON_THROW_ON_ERROR),
                'memory_policy' => json_encode([], JSON_THROW_ON_ERROR),
                'metadata' => json_encode(['seed' => 'programming_onboarding_contract_removed'], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function contextPolicy(): array
    {
        return [
            'preset' => 'programming_open_brain',
            'require_context_pack' => true,
            'require_open_brain' => 'auto',
            'sources' => [
                'workspace_code_intelligence',
                'engineering_knowledge_base',
                'recent_changes',
                'tool_evidence',
                'atlas_memory_registry',
            ],
            'budget' => [
                'max_prompt_tokens' => 18000,
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
            'projection' => 'programming',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_memory_delta', 'harness_evidence', 'quality_gate', 'repair_result'],
                'requires_review_for' => ['architecture_rule', 'security_rule', 'cross_project_preference'],
                'quality_score_required' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function domainGatePolicy(): array
    {
        return [
            'required' => ['context_pack', 'tool_permission_contract', 'final_quality_summary'],
            'release_requires' => ['tests_or_reason', 'diff_scope', 'open_brain_audit'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function domainMetadata(): array
    {
        return [
            'seed' => 'programming_onboarding_contract',
            'surfaces' => [
                'cli:atlas dev',
                'cli:atlas forge',
                'cli:atlas fix',
                'cli:atlas continue',
                'cli:atlas chat --mode=dev',
                'cli:atlas chat --mode=review',
                'cli:atlas chat --mode=debug',
                'api:ai/jobs',
                'app:programming',
                'mcp:open_brain',
            ],
            'surface_policy' => [
                'all_surfaces_must_call_programming_orchestrator' => true,
                'interactive_and_prompt_dev_share_contract' => true,
                'fix_and_continue_are_programming_flows_not_separate_products' => true,
            ],
            'learning_policy' => [
                'nightly_self_improvement_reads_programming_evidence' => true,
                'promote_repeated_repairs_to_memory' => true,
                'detect_missing_surface_capabilities' => true,
            ],
        ];
    }

    /**
     * @return array<string,array<string,array<string,mixed>>>
     */
    private function flowContracts(): array
    {
        return [
            'programming.dev' => $this->flowContract(
                ['context_pack', 'change_summary', 'quality_gate_summary'],
                'simple_provider_execution'
            ),
            'programming.forge' => $this->flowContract(
                ['engineering_harness_run', 'test_evidence', 'artifact_bundle', 'release_gate_summary'],
                'engineering_harness'
            ),
            'programming.repair' => $this->flowContract(
                ['dev_quality_gate', 'repair_evidence'],
                'dev_repair_executor'
            ),
            'programming.review' => $this->flowContract(
                ['review_findings', 'risk_summary'],
                'simple_provider_execution'
            ),
            'programming.refactor' => $this->flowContract(
                ['change_scope', 'quality_evidence'],
                'dev_repair_executor'
            ),
            'programming.qa' => $this->flowContract(
                ['test_evidence', 'regression_risk'],
                'engineering_harness'
            ),
            'programming.security' => $this->flowContract(
                ['security_evidence', 'risk_acceptance'],
                'engineering_harness'
            ),
            'programming.database' => $this->flowContract(
                ['migration_safety', 'rollback_plan'],
                'engineering_harness'
            ),
            'programming.visual' => $this->flowContract(
                ['visual_evidence', 'responsive_check'],
                'engineering_harness'
            ),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,array<string,mixed>>
     */
    private function flowContract(array $requiredGates, string $executor): array
    {
        return [
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'executor' => $executor,
                'requires_final_summary' => true,
            ],
            'metadata' => [
                'seed' => 'programming_onboarding_contract',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_useful_patterns' => true,
                    'feed_self_improvement' => true,
                ],
            ],
        ];
    }
};
