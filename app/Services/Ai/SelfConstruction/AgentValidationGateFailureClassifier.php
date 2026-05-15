<?php

namespace App\Services\Ai\SelfConstruction;

/**
 * Classifies a failed (or warn/unknown) validation gate evaluation result
 * into a canonical category, severity, recovery class and human-routing hint.
 *
 * The classifier is metadata-only. It does not run gates, write the ledger,
 * call providers, dispatch work or change pointer state. It exists to
 * convert raw evaluator output into a shape the repair builder and the
 * certification service can consume deterministically.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentValidationGateFailureClassifier
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_validation_gate_failure_classification.v1';

    public const MODE = 'read_only_agent_validation_gate_failure_classification';

    public const ALLOWED_CATEGORIES = [
        'lint_error',
        'test_failure',
        'docs_drift',
        'architecture_violation',
        'whitespace_or_merge_marker',
        'scope_violation',
        'evidence_missing',
        'continuation_missing',
        'rollback_missing',
        'inconclusive_signal',
        'non_failure',
        'unknown',
    ];

    /**
     * @param  array<string, mixed>  $gateResult  one element from $evaluation['evaluations']
     * @return array<string, mixed>
     */
    public function classify(array $gateResult): array
    {
        $gateId = (string) ($gateResult['gate_id'] ?? '');
        $status = (string) ($gateResult['observed_status'] ?? '');
        $severity = (string) ($gateResult['severity'] ?? 'unknown');
        $blocking = (bool) ($gateResult['blocking'] ?? false);

        if ($status === 'pass') {
            return $this->wrap($gateResult, 'non_failure', $severity, 'no_repair_required', false, 'gate_passed_no_action');
        }

        if ($status === 'skip') {
            return $this->wrap($gateResult, 'non_failure', $severity, 'no_repair_required', false, 'gate_skipped_by_condition');
        }

        if ($status === 'unknown') {
            return $this->wrap($gateResult, 'inconclusive_signal', $this->bumpSeverity($severity), 'investigate', $blocking, 'no_synthetic_input_or_unsupported_status');
        }

        $category = match ($gateId) {
            'php_lint' => 'lint_error',
            'unit_tests', 'focused_tests' => 'test_failure',
            'docs_health' => 'docs_drift',
            'architecture_validate' => 'architecture_violation',
            'diff_check' => 'whitespace_or_merge_marker',
            'scope_check' => 'scope_violation',
            'evidence_check' => 'evidence_missing',
            'continuation_summary_check' => 'continuation_missing',
            'rollback_plan_check' => 'rollback_missing',
            default => 'unknown',
        };

        $recoveryClass = match ($category) {
            'lint_error' => 'autofix_then_revalidate',
            'test_failure' => 'repair_test_or_implementation_inside_scope',
            'docs_drift' => 'repair_docs_inside_scope',
            'architecture_violation' => 'restore_invariant_inside_layer',
            'whitespace_or_merge_marker' => 'autoclean_whitespace_or_resolve_marker',
            'scope_violation' => 'revert_change_outside_scope',
            'evidence_missing' => 'attach_evidence_to_receipt',
            'continuation_missing' => 'rebuild_continuation_summary',
            'rollback_missing' => 'attach_rollback_plan_to_receipt',
            'unknown' => 'manual_review',
            default => 'manual_review',
        };

        $requiresHuman = match ($category) {
            'scope_violation', 'evidence_missing', 'rollback_missing', 'architecture_violation' => true,
            default => false,
        };

        if ($status === 'warn') {
            $recoveryClass = 'observe_and_proceed';
            $requiresHuman = false;
        }

        return $this->wrap($gateResult, $category, $severity, $recoveryClass, $requiresHuman, $this->signalFor($category, $status));
    }

    /**
     * @param  array<int, array<string, mixed>>  $gateResults
     * @return array<string, mixed>
     */
    public function classifyMany(array $gateResults): array
    {
        $classifications = [];
        $categoryCounts = [];
        foreach ($gateResults as $gr) {
            $c = $this->classify($gr);
            $classifications[] = $c;
            $cat = $c['category'];
            $categoryCounts[$cat] = ($categoryCounts[$cat] ?? 0) + 1;
        }
        ksort($categoryCounts);
        $failureCount = 0;
        foreach ($classifications as $c) {
            if ($c['is_failure_classification']) {
                $failureCount++;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'classified',
            'total_count' => count($classifications),
            'failure_count' => $failureCount,
            'category_counts' => $categoryCounts,
            'classifications' => $classifications,
            'classification_hash' => hash('sha256', (string) json_encode($classifications)),
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

    /** @param array<string, mixed> $gateResult */
    private function wrap(
        array $gateResult,
        string $category,
        string $severity,
        string $recoveryClass,
        bool $requiresHuman,
        string $signal,
    ): array {
        $isFailureClass = ! in_array($category, ['non_failure'], true);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'gate_id' => (string) ($gateResult['gate_id'] ?? ''),
            'gate_type' => (string) ($gateResult['gate_type'] ?? ''),
            'observed_status' => (string) ($gateResult['observed_status'] ?? ''),
            'observed_reason' => (string) ($gateResult['observed_reason'] ?? ''),
            'observed_evidence_artifact' => (string) ($gateResult['observed_evidence_artifact'] ?? ''),
            'category' => $category,
            'severity' => $severity,
            'recovery_class' => $recoveryClass,
            'requires_human_review' => $requiresHuman,
            'signal' => $signal,
            'is_failure_classification' => $isFailureClass,
            'is_terminal' => $category === 'unknown',
            'runtime_safety_all_false' => true,
        ];
    }

    private function bumpSeverity(string $severity): string
    {
        return match ($severity) {
            'low' => 'medium',
            'medium' => 'high',
            default => $severity,
        };
    }

    private function signalFor(string $category, string $status): string
    {
        return $status.':'.$category;
    }
}
