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
        $exists = DB::table('ai_domain_profiles')->where('id', 'health')->exists();

        DB::table('ai_domain_profiles')->updateOrInsert(
            ['id' => 'health'],
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
        DB::table('ai_domain_profiles')->where('id', 'health')->delete();
    }

    /**
     * @return array<string,mixed>
     */
    private function domain(): array
    {
        return [
            'id' => 'health',
            'label' => 'Health',
            'status' => 'active',
            'default_flow' => 'health.review',
            'orchestrator' => 'AtlasHealthOrchestrator',
            'runtime_family' => 'health',
            'description' => 'Non-clinical wellness review, routine review, recovery review and safety review without diagnosis, treatment, dosage or emergency decisions.',
            'autonomy_default' => 'low',
            'background_allowed' => false,
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => $this->domainGatePolicy(),
            'metadata' => [
                'seed' => 'health_onboarding_contract',
                'surfaces' => ['cli:atlas ai --domain=health', 'api:ai/domains', 'app:health', 'mcp:open_brain'],
                'surface_policy' => [
                    'non_clinical_review_only' => true,
                    'professional_review_required_for_risk_flags' => true,
                    'medical_decisions_forbidden' => true,
                ],
                'learning_policy' => [
                    'promote_only_reviewed_wellness_patterns' => true,
                    'never_promote_medical_decisions' => true,
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
            $this->flow('health.review', 'Health Review', 'HealthReviewRuntime', ['health_scope', 'non_clinical_boundary', 'professional_review_notice']),
            $this->flow('health.routine_review', 'Routine Review', 'HealthRoutineRuntime', ['routine_observations', 'constraints', 'professional_review_notice']),
            $this->flow('health.recovery_review', 'Recovery Review', 'HealthRecoveryRuntime', ['recovery_considerations', 'risk_flags', 'non_clinical_next_steps']),
            $this->flow('health.safety_review', 'Safety Review', 'HealthSafetyRuntime', ['red_flags', 'escalation_notice', 'do_not_delay_care']),
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
            'domain_id' => 'health',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasHealthOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Health flow for non-clinical wellness review only.",
            'autonomy' => 'low',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['health_scope', 'non_clinical_boundary', 'professional_review_notice'],
                'autonomy_ceiling' => 'non_clinical_review_only',
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'health_review_packet_runtime',
                'non_clinical_review_only' => true,
                'diagnosis_allowed' => false,
                'treatment_allowed' => false,
                'dosage_change_allowed' => false,
                'emergency_decision_allowed' => false,
                'professional_care_replacement_allowed' => false,
            ],
            'metadata' => [
                'seed' => 'health_onboarding_contract',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_reviewed_wellness_patterns' => true,
                    'never_promote_medical_decisions' => true,
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
            'preset' => 'health_non_clinical_context',
            'require_context_pack' => true,
            'sources' => ['topic', 'goal', 'signals', 'constraints', 'risk_flags', 'operator_notes', 'evidence_refs'],
            'budget' => [
                'max_prompt_tokens' => 8000,
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
            'projection' => 'health',
            'provider_safe_default' => false,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['reviewed_wellness_pattern', 'accepted_routine_observation'],
                'requires_review_for' => ['health_related_memory', 'sensitive_personal_data', 'risk_flag_pattern'],
                'never_auto_promote_medical_decisions' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function domainGatePolicy(): array
    {
        return [
            'required' => ['health_scope', 'non_clinical_boundary', 'professional_review_notice'],
            'risk_flags_require' => ['professional_review_notice', 'do_not_delay_care', 'no_self_treatment'],
            'autonomy_ceiling' => 'non_clinical_review_only',
            'diagnosis_allowed' => false,
            'treatment_allowed' => false,
            'dosage_change_allowed' => false,
            'emergency_decision_allowed' => false,
            'professional_care_replacement_allowed' => false,
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
