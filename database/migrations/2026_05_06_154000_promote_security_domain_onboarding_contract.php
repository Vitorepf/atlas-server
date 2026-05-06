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
        $exists = DB::table('ai_domain_profiles')->where('id', 'security')->exists();

        DB::table('ai_domain_profiles')->updateOrInsert(
            ['id' => 'security'],
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
        DB::table('ai_domain_profiles')->where('id', 'security')->delete();
    }

    /**
     * @return array<string,mixed>
     */
    private function domain(): array
    {
        return [
            'id' => 'security',
            'label' => 'Security',
            'status' => 'active',
            'default_flow' => 'security.threat_review',
            'orchestrator' => 'AtlasSecurityOrchestrator',
            'runtime_family' => 'security',
            'description' => 'Defensive security review domain for threat review, privacy review, compliance review, and incident review without exploit execution, secret access, or network scanning.',
            'autonomy_default' => 'low',
            'background_allowed' => false,
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => $this->domainGatePolicy(),
            'metadata' => [
                'seed' => 'security_onboarding_contract',
                'surfaces' => ['cli:atlas ai --domain=security', 'api:ai/domains', 'app:security', 'mcp:open_brain'],
                'surface_policy' => [
                    'defensive_review_only' => true,
                    'programming_security_executes_code_security_harness_not_this_domain' => true,
                    'secret_access_forbidden' => true,
                ],
                'learning_policy' => [
                    'accepted_security_findings_feed_memory' => true,
                    'control_gaps_feed_self_improvement' => true,
                    'incidents_feed_postmortem_review' => true,
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
            $this->flow('security.threat_review', 'Threat Review', 'SecurityThreatRuntime', ['security_scope', 'defensive_only', 'risk_register']),
            $this->flow('security.privacy_review', 'Privacy Review', 'SecurityPrivacyRuntime', ['data_classification', 'privacy_risk', 'redaction_plan']),
            $this->flow('security.compliance_review', 'Compliance Review', 'SecurityComplianceRuntime', ['control_mapping', 'evidence_refs', 'human_review_required']),
            $this->flow('security.incident_review', 'Incident Review', 'SecurityIncidentRuntime', ['incident_scope', 'timeline', 'postmortem_actions']),
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
            'domain_id' => 'security',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasSecurityOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Security flow for defensive review only.",
            'autonomy' => 'low',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['security_scope', 'defensive_only', 'human_review_required'],
                'autonomy_ceiling' => 'defensive_review_only',
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'security_packet_runtime',
                'defensive_review_only' => true,
                'exploit_execution_allowed' => false,
                'network_scan_allowed' => false,
                'secret_access_allowed' => false,
            ],
            'metadata' => [
                'seed' => 'security_onboarding_contract',
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
            'preset' => 'defensive_security_context',
            'require_context_pack' => true,
            'sources' => ['asset_inventory', 'scope_boundary', 'control_catalog', 'threat_model', 'evidence_ledger', 'privacy_classification', 'incident_notes', 'operator_constraints'],
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
            'projection' => 'security',
            'provider_safe_default' => false,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_security_finding', 'verified_control_gap', 'reviewed_incident_pattern'],
                'requires_review_for' => ['new_security_rule', 'privacy_policy_change', 'credential_or_secret_context'],
                'never_auto_promote_secrets_or_unredacted_sensitive_data' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function domainGatePolicy(): array
    {
        return [
            'required' => ['security_scope', 'defensive_only', 'human_review_required'],
            'high_risk_requires' => ['operator_approval', 'evidence_refs', 'redaction_review'],
            'autonomy_ceiling' => 'defensive_review_only',
            'exploit_execution_allowed' => false,
            'network_scan_allowed' => false,
            'secret_access_allowed' => false,
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
