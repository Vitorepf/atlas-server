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

        foreach ($this->flows() as $flow) {
            $exists = DB::table('ai_flow_profiles')->where('id', $flow['id'])->exists();

            DB::table('ai_flow_profiles')->updateOrInsert(
                ['id' => $flow['id']],
                $this->withJsonColumns($flow, $now, $exists)
            );
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
     * @return array<int,array<string,mixed>>
     */
    private function flows(): array
    {
        return [
            $this->flow('programming.repair', 'Repair', 'ProviderExecution', 'medium', [
                'executor_preference' => 'dev_repair_executor',
                'quality_required' => true,
                'auto_test' => true,
                'max_iterations' => 3,
            ], ['required' => ['dev_quality_gate', 'repair_evidence']]),
            $this->flow('programming.review', 'Review', 'ProviderExecution', 'low', [
                'executor_preference' => 'simple_provider_execution',
            ], ['required' => ['review_findings', 'risk_summary']], [
                'mode' => 'read_only',
                'workspace_write' => false,
            ], ['required_bundles' => ['code-reviewer'], 'require_skill_trace' => true]),
            $this->flow('programming.refactor', 'Refactor', 'ProviderExecution', 'medium', [
                'executor_preference' => 'dev_repair_executor',
                'quality_required' => true,
                'auto_test' => true,
            ], ['required' => ['change_scope', 'quality_evidence']]),
            $this->flow('programming.qa', 'QA', 'EngineeringHarness', 'medium', [
                'executor_preference' => 'engineering_harness',
                'quality_required' => true,
                'harness_required' => true,
            ], ['required' => ['test_evidence', 'regression_risk']]),
            $this->flow('programming.security', 'Security', 'EngineeringHarness', 'low', [
                'executor_preference' => 'engineering_harness',
                'quality_required' => true,
                'harness_required' => true,
            ], ['required' => ['security_evidence', 'risk_acceptance']]),
            $this->flow('programming.database', 'Database', 'EngineeringHarness', 'medium', [
                'executor_preference' => 'engineering_harness',
                'quality_required' => true,
                'harness_required' => true,
            ], ['required' => ['migration_safety', 'rollback_plan']]),
            $this->flow('programming.visual', 'Visual', 'EngineeringHarness', 'medium', [
                'executor_preference' => 'engineering_harness',
                'quality_required' => true,
                'harness_required' => true,
            ], ['required' => ['visual_evidence', 'responsive_check']]),
        ];
    }

    /**
     * @param  array<string,mixed>  $executionPolicy
     * @param  array<string,mixed>  $gatePolicy
     * @param  array<string,mixed>  $toolPolicy
     * @param  array<string,mixed>  $skillPolicy
     * @return array<string,mixed>
     */
    private function flow(
        string $id,
        string $label,
        string $runtime,
        string $autonomy,
        array $executionPolicy,
        array $gatePolicy,
        array $toolPolicy = [],
        array $skillPolicy = [],
    ): array {
        return [
            'id' => $id,
            'domain_id' => 'programming',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasProgrammingOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Programming flow.",
            'autonomy' => $autonomy,
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'skill_policy' => $skillPolicy ?: [
                'preset' => 'domain',
                'required_bundles' => ['dev-quality-gate'],
                'require_skill_trace' => true,
            ],
            'tool_policy' => $toolPolicy,
            'gate_policy' => $gatePolicy,
            'execution_policy' => $executionPolicy,
            'metadata' => ['seed' => 'programming_domain_flows'],
        ];
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
