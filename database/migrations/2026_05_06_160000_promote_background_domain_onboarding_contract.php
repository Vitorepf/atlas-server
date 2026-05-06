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
        $exists = DB::table('ai_domain_profiles')->where('id', 'background')->exists();

        DB::table('ai_domain_profiles')->updateOrInsert(
            ['id' => 'background'],
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
        DB::table('ai_domain_profiles')->where('id', 'background')->delete();
    }

    /**
     * @return array<string,mixed>
     */
    private function domain(): array
    {
        return [
            'id' => 'background',
            'label' => 'Background Safety',
            'status' => 'active',
            'default_flow' => 'background.safe',
            'orchestrator' => 'BackgroundSafetyOrchestrator',
            'runtime_family' => 'background',
            'description' => 'Background safety review, readiness, schedule and permission governance for cron/heartbeat/daemon work without starting jobs or changing schedules.',
            'autonomy_default' => 'low',
            'background_allowed' => true,
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => $this->domainGatePolicy(),
            'metadata' => [
                'seed' => 'background_onboarding_contract',
                'surfaces' => ['scheduler', 'cli:atlas automation', 'api:automations', 'app:atlas engineering', 'mcp:open_brain'],
                'surface_policy' => [
                    'background_review_only' => true,
                    'explicit_operator_approval_required' => true,
                    'stop_conditions_required' => true,
                ],
                'learning_policy' => [
                    'accepted_background_reviews_feed_memory' => true,
                    'repeated_background_risks_feed_self_improvement' => true,
                    'unreviewed_background_actions_never_promote' => true,
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
            $this->flow('background.safe', 'Safe Background', 'BackgroundSafetyRuntime', ['background_scope', 'review_only', 'stop_conditions']),
            $this->flow('background.readiness_review', 'Readiness Review', 'BackgroundReadinessRuntime', ['readiness_score', 'missing_controls', 'approval_requirements']),
            $this->flow('background.schedule_review', 'Schedule Review', 'BackgroundScheduleRuntime', ['schedule_declared', 'cadence_review', 'stop_conditions']),
            $this->flow('background.permission_review', 'Permission Review', 'BackgroundPermissionRuntime', ['permission_review', 'least_privilege', 'operator_approval_needed']),
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
            'domain_id' => 'background',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'BackgroundSafetyOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Background Safety flow for review-only background governance.",
            'autonomy' => 'low',
            'background_allowed' => true,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['background_scope', 'review_only', 'stop_conditions', 'human_review_required'],
                'autonomy_ceiling' => 'review_only',
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'background_safety_packet_runtime',
                'review_only' => true,
                'start_jobs_allowed' => false,
                'schedule_mutation_allowed' => false,
                'permission_escalation_allowed' => false,
                'unbounded_loop_allowed' => false,
                'required_evidence' => ['background_packet', 'permission_review', 'stop_conditions'],
            ],
            'metadata' => [
                'seed' => 'background_onboarding_contract',
                'surfaces' => ['scheduler', 'cli', 'api', 'app', 'mcp'],
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
            'preset' => 'background_safety_context',
            'require_context_pack' => true,
            'sources' => ['job_scope', 'trigger', 'schedule', 'permissions', 'stop_conditions', 'evidence_ledger', 'automation_history', 'operator_constraints'],
            'budget' => [
                'max_prompt_tokens' => 10000,
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
            'projection' => 'background',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_background_review', 'reviewed_schedule_risk', 'permission_boundary_improvement'],
                'requires_review_for' => ['new_background_policy', 'schedule_change_rule', 'permission_policy_change'],
                'never_auto_promote_unreviewed_background_actions' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function domainGatePolicy(): array
    {
        return [
            'required' => ['background_scope', 'review_only', 'stop_conditions', 'human_review_required'],
            'background_execution_requires' => ['separate_decision_receipt', 'operator_approval', 'bounded_schedule', 'stop_conditions'],
            'autonomy_ceiling' => 'review_only',
            'start_jobs_allowed' => false,
            'schedule_mutation_allowed' => false,
            'permission_escalation_allowed' => false,
            'unbounded_loop_allowed' => false,
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
