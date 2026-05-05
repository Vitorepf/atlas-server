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
            ->whereIn('id', array_values(array_diff(array_column($this->flows(), 'id'), ['self_improvement.nightly_review'])))
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

    /**
     * @return array<int,array<string,mixed>>
     */
    private function flows(): array
    {
        return [
            $this->flow('self_improvement.nightly_review', 'Nightly Review', 'scheduled_self_improvement', 'Daily evidence-led review that detects gaps, drift, repeated failures, and safe improvement proposals.'),
            $this->flow('self_improvement.weekly_architecture_audit', 'Weekly Architecture Audit', 'architecture_audit_runtime', 'Weekly kernel and domain architecture audit over contracts, docs, runtime evidence, and code intelligence.'),
            $this->flow('self_improvement.capability_gap_scan', 'Capability Gap Scan', 'capability_gap_runtime', 'Surface and capability scan that finds missing shared capabilities, weak adoption, and duplicated behavior.'),
            $this->flow('self_improvement.benchmark_review', 'Benchmark Review', 'benchmark_review_runtime', 'Benchmark corpus review that converts regressions and weak cases into improvement proposals.'),
            $this->flow('self_improvement.memory_quality_review', 'Memory Quality Review', 'memory_quality_runtime', 'Memory quality review over source safety, trend drivers, stale knowledge, and accepted learning promotion.'),
            $this->flow('self_improvement.tool_runtime_review', 'Tool Runtime Review', 'tool_runtime_review_runtime', 'Super Tool Runtime review over evidence freshness, gate blocks, authority overlap, and missing sensors.'),
            $this->flow('self_improvement.repair_loop_review', 'Repair Loop Review', 'repair_loop_review_runtime', 'Dedicated Repair Loop review over repair decisions, blocked/exhausted states, human review recurrence, strategies, reasons, and emitter stages.'),
            $this->flow('self_improvement.domain_learning_review', 'Domain Learning Review', 'domain_learning_runtime', 'Cross-domain learning review that checks whether domain outcomes are feeding memory, docs, and gates.'),
            $this->flow('self_improvement.docs_drift_review', 'Docs Drift Review', 'docs_drift_runtime', 'Documentation drift review comparing kernel contracts, KB docs, code intelligence, and domain manifests.'),
            $this->flow('self_improvement.provider_performance_review', 'Provider Performance Review', 'provider_performance_runtime', 'Provider and model performance review over failures, routing decisions, benchmark outcomes, and cost signals.'),
            $this->flow('self_improvement.proposal_generation', 'Proposal Generation', 'proposal_generation_runtime', 'Final proposal synthesis flow that emits bounded, reviewable improvement proposals linked to evidence.'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function flow(string $id, string $label, string $executor, string $description): array
    {
        return [
            'id' => $id,
            'domain_id' => 'self_improvement',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasSelfImprovementOrchestrator',
            'runtime' => 'SelfImprovementRuntime',
            'description' => $description,
            'autonomy' => 'low',
            'background_allowed' => true,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->contextPolicy(),
            'memory_policy' => $this->memoryPolicy(),
            'gate_policy' => $this->gatePolicy(),
            'execution_policy' => [
                'executor_preference' => $executor,
                'proposal_only' => true,
                'max_autonomy' => 'low',
            ],
            'metadata' => $this->flowMetadata(),
        ];
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
                'provider_performance',
                'benchmark_corpus',
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
            'seed' => 'self_improvement_multi_flow_contract',
            'surfaces' => [
                'scheduler:daily-02:00',
                'cli:atlas:ai:self-improve',
                'api:ai/domains',
                'api:mobile/inbox',
                'app:atlas engineering',
            ],
            'nightly_cycle' => [
                'default_flow' => 'self_improvement.nightly_review',
                'proposal_flow' => 'self_improvement.proposal_generation',
                'all_changes_are_review_first' => true,
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
            'seed' => 'self_improvement_multi_flow_contract',
            'surfaces' => ['scheduler', 'cli', 'api', 'app'],
            'learning' => [
                'record_findings' => true,
                'emit_safe_review_proposals' => true,
                'feed_domain_onboarding' => true,
            ],
        ];
    }
};
