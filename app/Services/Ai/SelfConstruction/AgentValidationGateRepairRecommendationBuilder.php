<?php

namespace App\Services\Ai\SelfConstruction;

/**
 * Builds a deterministic repair recommendation from a classified validation
 * gate failure. The recommendation is a plan in metadata form: ordered
 * steps, allowed files, forbidden operations, the evidence the next attempt
 * must produce, and an escalation path if the recommendation cannot run.
 *
 * The builder does not execute repairs. It does not patch code, run
 * commands, write the ledger, dispatch work or call providers. It exists
 * so that another AI session or a human operator can pick up a failed
 * gate and act with bounded scope.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentValidationGateRepairRecommendationBuilder
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_validation_gate_repair_recommendation.v1';

    public const MODE = 'read_only_agent_validation_gate_repair_recommendation';

    /**
     * @param  array<string, mixed>  $classification  output of AgentValidationGateFailureClassifier::classify()
     * @param  array<string, mixed>  $gateResult  original evaluator result for the same gate
     * @param  array<string, mixed>  $context  allowed_files, forbidden_files, focused_filter
     * @return array<string, mixed>
     */
    public function recommend(array $classification, array $gateResult, array $context = []): array
    {
        $gateId = (string) ($classification['gate_id'] ?? $gateResult['gate_id'] ?? '');
        $category = (string) ($classification['category'] ?? 'unknown');
        $recoveryClass = (string) ($classification['recovery_class'] ?? 'manual_review');
        $severity = (string) ($classification['severity'] ?? 'medium');
        $requiresHuman = (bool) ($classification['requires_human_review'] ?? false);
        $allowedFiles = $this->normalize($context['allowed_files'] ?? []);
        $forbiddenFiles = $this->normalize($context['forbidden_files'] ?? []);

        if (! $this->isFailureClassification($classification)) {
            return $this->wrap(
                gateId: $gateId,
                category: $category,
                recoveryClass: $recoveryClass,
                severity: $severity,
                requiresHuman: false,
                steps: ['no_repair_required'],
                allowedFiles: $allowedFiles,
                forbiddenOps: ['no_runtime_action'],
                evidenceNeeded: ['none'],
                escalation: 'none',
                status: 'no_repair_required',
            );
        }

        [$steps, $evidence, $forbiddenOps, $escalation] = $this->buildPlan($category, $gateId, $gateResult, $allowedFiles, $forbiddenFiles, $requiresHuman);

        return $this->wrap(
            gateId: $gateId,
            category: $category,
            recoveryClass: $recoveryClass,
            severity: $severity,
            requiresHuman: $requiresHuman,
            steps: $steps,
            allowedFiles: $allowedFiles,
            forbiddenOps: $forbiddenOps,
            evidenceNeeded: $evidence,
            escalation: $escalation,
            status: 'recommendation_ready',
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $classifications
     * @param  array<int, array<string, mixed>>  $gateResults
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function recommendMany(array $classifications, array $gateResults, array $context = []): array
    {
        $byGateId = [];
        foreach ($gateResults as $gr) {
            $id = (string) ($gr['gate_id'] ?? '');
            if ($id !== '') {
                $byGateId[$id] = $gr;
            }
        }
        $recommendations = [];
        foreach ($classifications as $c) {
            $id = (string) ($c['gate_id'] ?? '');
            $gr = $byGateId[$id] ?? [];
            $recommendations[] = $this->recommend($c, $gr, $context);
        }
        $repairCount = 0;
        $humanCount = 0;
        foreach ($recommendations as $r) {
            if ($r['status'] === 'recommendation_ready') {
                $repairCount++;
            }
            if ($r['requires_human_review']) {
                $humanCount++;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'recommendations_built',
            'total_count' => count($recommendations),
            'repair_count' => $repairCount,
            'human_required_count' => $humanCount,
            'recommendations' => $recommendations,
            'recommendation_hash' => hash('sha256', (string) json_encode($recommendations)),
            'runtime_safety' => [
                'runtime_safety_all_false' => true,
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
            ],
        ];
    }

    /** @param array<string, mixed> $c */
    private function isFailureClassification(array $c): bool
    {
        return (bool) ($c['is_failure_classification'] ?? false);
    }

    /**
     * @param  array<int, string>  $allowedFiles
     * @param  array<int, string>  $forbiddenFiles
     * @return array{0: array<int, string>, 1: array<int, string>, 2: array<int, string>, 3: string}
     */
    private function buildPlan(
        string $category,
        string $gateId,
        array $gateResult,
        array $allowedFiles,
        array $forbiddenFiles,
        bool $requiresHuman,
    ): array {
        $forbiddenOps = $this->baseForbiddenOps();
        $evidence = ['rerun_dry_run_evaluator', 'attach_observed_evidence_artifact_hash'];
        $escalation = $requiresHuman ? 'human_review_before_retry' : 'retry_inside_same_session';

        $steps = match ($category) {
            'lint_error' => [
                'read_php_lint_error_message_from_synthetic_input',
                'fix_syntax_error_in_listed_file_inside_allowed_scope',
                'rerun_focused_php_lint_dry_run',
            ],
            'test_failure' => [
                'identify_failing_test_id_and_assertion',
                'repair_test_or_implementation_inside_allowed_scope',
                'rerun_focused_test_dry_run',
            ],
            'docs_drift' => [
                'read_docs_health_violation_list',
                'restore_canonical_sections_or_frontmatter_inside_allowed_doc',
                'rerun_docs_health_dry_run',
            ],
            'architecture_violation' => [
                'open_layer_report_in_architecture_validate_payload',
                'restore_invariant_inside_layer_boundary',
                'request_human_review_before_promotion',
            ],
            'whitespace_or_merge_marker' => [
                'autoclean_trailing_whitespace_or_unresolved_marker',
                'reconfirm_diff_check_dry_run',
            ],
            'scope_violation' => [
                'identify_forbidden_or_unknown_files_touched',
                'revert_change_outside_allowed_files',
                'request_human_review_of_scope_decision',
            ],
            'evidence_missing' => [
                'attach_required_evidence_hash_to_receipt',
                'rerun_evidence_check_dry_run',
                'request_human_acknowledgement_of_evidence',
            ],
            'continuation_missing' => [
                'rebuild_continuation_summary_for_next_session',
                'persist_summary_inside_session_artifact',
            ],
            'rollback_missing' => [
                'attach_rollback_plan_to_receipt',
                'verify_rollback_steps_inside_allowed_scope',
                'request_human_signature_of_rollback_section',
            ],
            'inconclusive_signal' => [
                'investigate_why_synthetic_input_missing_or_unsupported',
                'either_provide_input_or_skip_via_skip_unless',
            ],
            default => [
                'manual_review',
            ],
        };

        if ($category === 'scope_violation') {
            $forbiddenOps[] = 'edit_outside_allowed_files';
            $forbiddenOps[] = 'edit_inside_forbidden_files';
        }

        if ($category === 'evidence_missing' || $category === 'rollback_missing') {
            $evidence[] = 'attach_receipt_hash_for_evidence_chain';
        }

        return [array_values($steps), array_values(array_unique($evidence)), array_values(array_unique($forbiddenOps)), $escalation];
    }

    /** @return array<int, string> */
    private function baseForbiddenOps(): array
    {
        return [
            'no_real_command_execution',
            'no_provider_call',
            'no_token_spend',
            'no_dispatch_runtime',
            'no_ledger_write',
            'no_self_programming',
            'no_pointer_mutation',
        ];
    }

    /**
     * @param  array<int, string>  $steps
     * @param  array<int, string>  $allowedFiles
     * @param  array<int, string>  $forbiddenOps
     * @param  array<int, string>  $evidenceNeeded
     * @return array<string, mixed>
     */
    private function wrap(
        string $gateId,
        string $category,
        string $recoveryClass,
        string $severity,
        bool $requiresHuman,
        array $steps,
        array $allowedFiles,
        array $forbiddenOps,
        array $evidenceNeeded,
        string $escalation,
        string $status,
    ): array {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'gate_id' => $gateId,
            'category' => $category,
            'recovery_class' => $recoveryClass,
            'severity' => $severity,
            'requires_human_review' => $requiresHuman,
            'steps' => $steps,
            'allowed_files' => $allowedFiles,
            'forbidden_ops' => $forbiddenOps,
            'evidence_needed' => $evidenceNeeded,
            'escalation' => $escalation,
            'recommendation_hash' => hash('sha256', (string) json_encode([
                'gate_id' => $gateId,
                'category' => $category,
                'steps' => $steps,
                'allowed' => $allowedFiles,
                'forbidden' => $forbiddenOps,
                'evidence' => $evidenceNeeded,
            ])),
            'runtime_safety' => [
                'runtime_safety_all_false' => true,
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
            ],
        ];

        return $payload;
    }

    /**
     * @param  mixed  $files
     * @return array<int, string>
     */
    private function normalize($files): array
    {
        if (! is_array($files)) {
            return [];
        }
        $out = [];
        foreach ($files as $f) {
            if (is_string($f) && $f !== '') {
                $out[] = $f;
            }
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }
}
