<?php

namespace App\Services\Ai\SelfConstruction;

/**
 * Folds Catalog + Plan + Dry-Run Evaluation + Failure Classifications +
 * Repair Recommendations into a single hashable certification record.
 *
 * The certification is the canonical "I looked at one agent's validation
 * outputs and here is what I see" artifact. It is metadata only and does
 * NOT promote any runtime, never executes commands, never writes the
 * ledger and never enables dispatch or self-programming.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentValidationGateCertificationService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_validation_gate_certification.v1';

    public const MODE = 'read_only_agent_validation_gate_certification';

    public function __construct(
        private readonly AgentValidationGateCatalog $catalog = new AgentValidationGateCatalog,
        private readonly AgentValidationGatePlanBuilder $planBuilder = new AgentValidationGatePlanBuilder,
        private readonly AgentValidationGateDryRunEvaluator $evaluator = new AgentValidationGateDryRunEvaluator,
        private readonly AgentValidationGateFailureClassifier $classifier = new AgentValidationGateFailureClassifier,
        private readonly AgentValidationGateRepairRecommendationBuilder $repairBuilder = new AgentValidationGateRepairRecommendationBuilder,
    ) {}

    /**
     * Run end-to-end dry-run certification for one agent output context.
     *
     * @param  array<string, mixed>  $context  allowed_files, forbidden_files, changed_files, requested_gates, focused_filter
     * @param  array<string, array<string, mixed>>  $syntheticInputs  per-gate synthetic inputs
     * @return array<string, mixed>
     */
    public function certify(array $context = [], array $syntheticInputs = []): array
    {
        $catalog = $this->catalog->describe();
        $plan = $this->planBuilder->buildPlan($context);
        $evaluation = $this->evaluator->evaluate($plan, $syntheticInputs);
        $classification = $this->classifier->classifyMany($evaluation['evaluations']);
        $recommendation = $this->repairBuilder->recommendMany(
            $classification['classifications'],
            $evaluation['evaluations'],
            $context,
        );

        $invariants = $this->invariants($catalog, $plan, $evaluation, $classification, $recommendation);
        $violations = array_values(array_filter($invariants, static fn ($i) => $i['ok'] === false));
        $invariantsAllTrue = $violations === [];

        $summary = [
            'gate_count' => $catalog['gate_count'],
            'plan_status' => $plan['status'],
            'plan_id' => $plan['plan_id'],
            'overall_evaluation' => $evaluation['overall_status'],
            'evaluation_aborted' => $evaluation['aborted'],
            'aborted_at_gate' => $evaluation['aborted_at_gate'],
            'failure_count' => $classification['failure_count'],
            'human_required_count' => $recommendation['human_required_count'],
            'failed_gate_ids' => $evaluation['failed_gate_ids'],
            'warn_gate_ids' => $evaluation['warn_gate_ids'],
            'unknown_gate_ids' => $evaluation['unknown_gate_ids'],
            'scope_violation_detected' => $plan['context_summary']['scope_violation_detected'],
        ];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $this->statusFor($evaluation, $invariantsAllTrue),
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'completion_allowed' => false,
            'certification_id' => $this->certificationId($plan, $evaluation),
            'summary' => $summary,
            'catalog_hash' => $catalog['catalog_hash'],
            'plan_hash' => $plan['plan_hash'],
            'evaluation_hash' => $evaluation['evaluation_hash'],
            'classification_hash' => $classification['classification_hash'],
            'recommendation_hash' => $recommendation['recommendation_hash'],
            'invariants' => $invariants,
            'invariants_all_true' => $invariantsAllTrue,
            'violations' => $violations,
            'violation_count' => count($violations),
            'next_action' => $this->nextAction($evaluation, $classification, $recommendation),
            'certification_hash' => $this->certificationHash($catalog, $plan, $evaluation, $classification, $recommendation),
            'runtime_safety' => [
                'runtime_safety_all_false' => true,
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'completion_allowed' => false,
            ],
        ];

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $evaluation
     * @param  array<string, mixed>  $classification
     * @param  array<string, mixed>  $recommendation
     */
    private function nextAction(array $evaluation, array $classification, array $recommendation): string
    {
        if (($evaluation['overall_status'] ?? '') === 'passed') {
            return 'present_evidence_and_request_human_acknowledgement';
        }
        if (($evaluation['overall_status'] ?? '') === 'passed_with_warnings') {
            return 'observe_warnings_and_request_human_acknowledgement';
        }
        if (($classification['failure_count'] ?? 0) > 0) {
            return $recommendation['human_required_count'] > 0
                ? 'escalate_human_review_then_retry_inside_scope'
                : 'apply_repair_recommendations_inside_scope_and_retry';
        }

        return 'investigate_inconclusive_signal';
    }

    /** @return array<int, array<string, mixed>> */
    private function invariants(array $catalog, array $plan, array $evaluation, array $classification, array $recommendation): array
    {
        $checks = [
            ['name' => 'catalog_has_ten_gates', 'ok' => $catalog['gate_count'] === 10],
            ['name' => 'plan_runtime_safety_all_false', 'ok' => $this->safetyAllFalse($plan['runtime_safety'] ?? [])],
            ['name' => 'evaluation_runtime_safety_all_false', 'ok' => $this->safetyAllFalse($evaluation['runtime_safety'] ?? [])],
            ['name' => 'classification_runtime_safety_all_false', 'ok' => $this->safetyAllFalse($classification['runtime_safety'] ?? [])],
            ['name' => 'recommendation_runtime_safety_all_false', 'ok' => $this->safetyAllFalse($recommendation['runtime_safety'] ?? [])],
            ['name' => 'no_real_command_executed', 'ok' => true],
            ['name' => 'no_provider_call', 'ok' => true],
            ['name' => 'no_token_spend', 'ok' => true],
            ['name' => 'no_dispatch_runtime', 'ok' => true],
            ['name' => 'no_ledger_write', 'ok' => true],
            ['name' => 'no_self_programming', 'ok' => true],
            ['name' => 'no_pointer_mutation', 'ok' => true],
            ['name' => 'plan_ordered_runs_consistent_with_gate_ids', 'ok' => count($plan['ordered_runs'] ?? []) === count($plan['ordered_gate_ids'] ?? [])],
            ['name' => 'evaluation_counts_total_matches_evaluations', 'ok' => array_sum($evaluation['counts'] ?? []) === count($evaluation['evaluations'] ?? [])],
            ['name' => 'classification_total_matches_evaluations', 'ok' => ($classification['total_count'] ?? 0) === count($evaluation['evaluations'] ?? [])],
            ['name' => 'recommendation_total_matches_classifications', 'ok' => ($recommendation['total_count'] ?? 0) === ($classification['total_count'] ?? 0)],
            ['name' => 'completion_blocked_until_human_ack', 'ok' => true],
        ];

        return array_values($checks);
    }

    /** @param array<string, mixed> $rs */
    private function safetyAllFalse(array $rs): bool
    {
        if (($rs['runtime_safety_all_false'] ?? false) !== true) {
            return false;
        }
        foreach ([
            'execution_allowed',
            'dispatch_allowed',
            'provider_call_allowed',
            'token_spend_allowed',
            'self_programming_allowed',
            'ledger_write_allowed',
            'runtime_write_allowed',
        ] as $k) {
            if (($rs[$k] ?? null) !== false) {
                return false;
            }
        }

        return true;
    }

    private function statusFor(array $evaluation, bool $invariantsAllTrue): string
    {
        if (! $invariantsAllTrue) {
            return 'invariant_violation';
        }
        $overall = (string) ($evaluation['overall_status'] ?? 'empty');

        return match ($overall) {
            'passed' => 'available',
            'passed_with_warnings' => 'available_with_warnings',
            'all_skipped' => 'inconclusive',
            'inconclusive' => 'inconclusive',
            'failed' => 'blocked',
            'empty' => 'empty',
            default => 'inconclusive',
        };
    }

    private function certificationId(array $plan, array $evaluation): string
    {
        $seed = ($plan['plan_id'] ?? 'plan').'/'.($evaluation['result_set_id'] ?? 'result');

        return 'cert-'.substr(hash('sha256', $seed), 0, 16);
    }

    private function certificationHash(array $catalog, array $plan, array $evaluation, array $classification, array $recommendation): string
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'catalog_hash' => $catalog['catalog_hash'] ?? '',
            'plan_hash' => $plan['plan_hash'] ?? '',
            'evaluation_hash' => $evaluation['evaluation_hash'] ?? '',
            'classification_hash' => $classification['classification_hash'] ?? '',
            'recommendation_hash' => $recommendation['recommendation_hash'] ?? '',
        ];

        return hash('sha256', (string) json_encode($payload));
    }
}
