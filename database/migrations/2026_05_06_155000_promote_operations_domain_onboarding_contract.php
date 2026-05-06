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
        $exists = DB::table('ai_domain_profiles')->where('id', 'operations')->exists();

        DB::table('ai_domain_profiles')->updateOrInsert(
            ['id' => 'operations'],
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
        DB::table('ai_domain_profiles')->where('id', 'operations')->delete();
    }

    /**
     * @return array<string,mixed>
     */
    private function domain(): array
    {
        return [
            'id' => 'operations',
            'label' => 'Operations',
            'status' => 'active',
            'default_flow' => 'operations.diagnostic',
            'orchestrator' => 'AtlasOperationsOrchestrator',
            'runtime_family' => 'operations',
            'description' => 'Operational diagnostics, runbook planning, incident review, and readiness review without deploying, restarting, deleting data, or mutating infrastructure.',
            'autonomy_default' => 'low',
            'background_allowed' => false,
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => $this->domainGatePolicy(),
            'metadata' => [
                'seed' => 'operations_onboarding_contract',
                'surfaces' => ['cli:atlas ai --domain=operations', 'api:ai/domains', 'app:operations', 'mcp:open_brain'],
                'surface_policy' => [
                    'diagnostic_only' => true,
                    'operational_action_requires_separate_receipt' => true,
                    'production_mutation_forbidden' => true,
                ],
                'learning_policy' => [
                    'accepted_operations_findings_feed_memory' => true,
                    'incident_reviews_feed_self_improvement' => true,
                    'repeated_alerts_feed_runbook_improvement' => true,
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
            $this->flow('operations.diagnostic', 'Diagnostic', 'OperationsDiagnosticRuntime', ['operations_scope', 'diagnostic_only', 'signal_map']),
            $this->flow('operations.runbook', 'Runbook', 'OperationsRunbookRuntime', ['runbook_steps', 'prechecks', 'human_review_required']),
            $this->flow('operations.incident_review', 'Incident Review', 'OperationsIncidentRuntime', ['incident_scope', 'timeline', 'postmortem_actions']),
            $this->flow('operations.readiness_review', 'Readiness Review', 'OperationsReadinessRuntime', ['readiness_score', 'evidence_refs', 'operator_decision_needed']),
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
            'domain_id' => 'operations',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasOperationsOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Operations flow for diagnostic review only.",
            'autonomy' => 'low',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['operations_scope', 'diagnostic_only', 'human_review_required'],
                'autonomy_ceiling' => 'diagnostic_only',
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'operations_packet_runtime',
                'diagnostic_only' => true,
                'restart_allowed' => false,
                'deploy_allowed' => false,
                'infrastructure_mutation_allowed' => false,
                'data_deletion_allowed' => false,
            ],
            'metadata' => [
                'seed' => 'operations_onboarding_contract',
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
            'preset' => 'operations_diagnostic_context',
            'require_context_pack' => true,
            'sources' => ['system_scope', 'symptoms', 'signals', 'logs_when_provided', 'evidence_ledger', 'runbooks', 'risk_register', 'operator_constraints'],
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
            'projection' => 'operations',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_operations_finding', 'reviewed_incident_pattern', 'runbook_improvement'],
                'requires_review_for' => ['production_runbook_change', 'operational_policy_change', 'critical_incident_pattern'],
                'never_auto_promote_unreviewed_operational_actions' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function domainGatePolicy(): array
    {
        return [
            'required' => ['operations_scope', 'diagnostic_only', 'human_review_required'],
            'operational_action_requires' => ['separate_decision_receipt', 'operator_approval', 'rollback_plan'],
            'autonomy_ceiling' => 'diagnostic_only',
            'restart_allowed' => false,
            'deploy_allowed' => false,
            'infrastructure_mutation_allowed' => false,
            'data_deletion_allowed' => false,
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
