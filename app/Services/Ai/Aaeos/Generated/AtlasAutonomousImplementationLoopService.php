<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Support\AiStringListNormalizer;

/**
 * Self-Construction Autonomous Implementation Loop — pure, deterministic loop
 * governor.
 *
 * Turns the documented loop contract into an executable JUDGE. It decides, for
 * a proposed autonomous loop execution:
 *   - which loop stage may run next (the 13 ordered stages, each emitting an
 *     artifact/evidence — "Every loop stage must emit an artifact or evidence");
 *   - whether any Stop Condition fires (8 documented triggers -> stop & review);
 *   - whether the requested slice obeys the Small Slice Rule (prefer the
 *     smallest block that improves maturity; reject "implement all");
 *   - whether the Loop Receipt carries every required field (9 fields);
 *   - whether a Learning proposal may auto-apply (critical learning may NOT).
 *
 * It is read-only: it never edits files, runs gates, mutates policy or applies
 * learning. It only classifies and emits a machine-readable decision.
 *
 * Contract (from the doc):
 *   - "Loop Stages": Observe, Diagnose, Research, Document, Specify, Plan,
 *     Decide, Execute, Validate, Evidence, Drift Check, Learn, Report (ordered).
 *   - "Stage Contracts": each stage has a required output kind.
 *   - "Stop Conditions": stop and ask/review when any of the 8 triggers holds.
 *   - "Small Slice Rule": prefer the smallest block that improves maturity;
 *     "Implement all self-programming runtime." is the Bad example.
 *   - "Loop Receipt Requirements": operation_id, target_capability,
 *     maturity_delta, allowed_files, allowed_commands, required_gates, rollback,
 *     evidence, max_scope.
 *   - "Learning Rule": may generate proposals; "may not apply critical learning
 *     automatically."
 *   - Decisions (frontmatter): "Autonomous construction is a loop with gates,
 *     not an open-ended coding session." / "Every loop stage must emit an
 *     artifact or evidence."
 *
 * @see docs/engineering-knowledge-base/self-construction/autonomous-implementation-loop.md
 */
final class AtlasAutonomousImplementationLoopService
{
    /** Stable evidence schema id this governor emits. */
    public const SCHEMA = 'atlas.self_construction.autonomous_implementation_loop.v1';

    /** Loop decision verdicts (closed set). */
    public const DECISION_PROCEED = 'proceed';
    public const DECISION_STOP = 'stop';

    /**
     * The 13 ordered loop stages (doc "Loop Stages"). Order is load-bearing:
     * a stage may only run when every earlier stage is complete.
     *
     * @var list<string>
     */
    public const STAGES = [
        'observe',
        'diagnose',
        'research',
        'document',
        'specify',
        'plan',
        'decide',
        'execute',
        'validate',
        'evidence',
        'drift_check',
        'learn',
        'report',
    ];

    /**
     * "Stage Contracts" — the required output kind each stage must emit.
     * Mirrors the doc table one-to-one.
     *
     * @var array<string,string>
     */
    public const STAGE_OUTPUT = [
        'observe' => 'gap_metric_failure_request_or_roadmap_item',
        'diagnose' => 'layer_capability_maturity_and_risk',
        'research' => 'source_backed_findings_or_not_needed_reason',
        'document' => 'canonical_doc_or_ap_update_when_durable',
        'specify' => 'meta_sdd_spec_and_acceptance_criteria',
        'plan' => 'technical_plan_and_task_list',
        'decide' => 'decision_receipt_with_scope_gates_and_rollback',
        'execute' => 'patch_or_no_op_with_reason',
        'validate' => 'tests_docs_health_architecture_validation_scans',
        'evidence' => 'diff_command_output_traceability_residual_risk',
        'drift_check' => 'spec_code_doc_mismatch_report',
        'learn' => 'proposal_not_silent_mutation',
        'report' => 'concise_human_readable_closeout',
    ];

    /**
     * The 9 required Loop Receipt fields (doc "Loop Receipt Requirements").
     *
     * @var list<string>
     */
    public const RECEIPT_FIELDS = [
        'operation_id',
        'target_capability',
        'maturity_delta',
        'allowed_files',
        'allowed_commands',
        'required_gates',
        'rollback',
        'evidence',
        'max_scope',
    ];

    /**
     * The 8 Stop Conditions (doc "Stop Conditions"). Each maps a boolean signal
     * key in the context to the documented stop reason. ANY true => stop.
     *
     * @var array<string,string>
     */
    public const STOP_CONDITIONS = [
        'target_context_missing' => 'target context is missing',
        'high_risk_without_human_gate' => 'risk is high and no human gate exists',
        'gates_unavailable' => 'gates are unavailable',
        'rollback_impossible' => 'rollback is impossible',
        'spec_conflicts_with_canonical' => 'spec conflicts with canonical docs',
        'touches_forbidden_files' => 'implementation touches forbidden files',
        'validation_fails_outside_scope' => 'validation fails outside receipt scope',
        'research_sources_conflict' => 'research sources conflict',
    ];

    /**
     * Learning proposal kinds the loop "may generate" (doc "Learning Rule").
     *
     * @var list<string>
     */
    public const LEARNING_KINDS = [
        'update_sdd_template',
        'add_a_new_gate',
        'improve_source_scoring',
        'update_priority_weights',
        'add_common_failure_pattern',
    ];

    /**
     * Critical learning kinds — these change the safety/governance surface, so
     * the loop "may not apply critical learning automatically." They always
     * require a human gate even though they are valid proposals.
     *
     * @var list<string>
     */
    public const CRITICAL_LEARNING_KINDS = [
        'add_a_new_gate',
    ];

    /**
     * Whole-runtime scope verbs that violate the Small Slice Rule. "Implement
     * all self-programming runtime." is the documented Bad example.
     *
     * @var list<string>
     */
    private const OVERSIZED_SCOPE_NEEDLES = [
        'implement all',
        'all self-programming',
        'all self programming',
        'entire runtime',
        'whole runtime',
        'everything',
        'full self-construction',
        'full self construction',
        'rewrite all',
        'all subsystems',
    ];

    /**
     * Decide the whole loop step in one call: stage gating + stop conditions +
     * small-slice + receipt validity. This is the primary entrypoint.
     *
     * @param array<string,mixed> $request
     *        requested_stage      : string  one of STAGES (default first incomplete)
     *        completed_stages     : list<string>
     *        slice                : array{description?:string, maturity_delta?:int|float|string, ...}
     *        receipt              : array<string,mixed> the loop_receipt
     *        signals              : array<string,bool>  stop-condition signals
     *
     * @return array<string,mixed> the decision evidence document
     */
    public function evaluate(array $request): array
    {
        $completed = $this->normalizeStages($request['completed_stages'] ?? []);
        $requestedStage = $this->normalizeStageName($request['requested_stage'] ?? null)
            ?? $this->nextStage($completed);

        $stage = $this->evaluateStage($requestedStage, $completed);
        $stop = $this->evaluateStopConditions(is_array($request['signals'] ?? null) ? $request['signals'] : []);
        $slice = $this->evaluateSlice(is_array($request['slice'] ?? null) ? $request['slice'] : []);
        $receipt = $this->validateReceipt(is_array($request['receipt'] ?? null) ? $request['receipt'] : []);

        // Decision precedence: a Stop Condition always wins (the doc says
        // "Stop and ask/review when ..."), then stage gating, slice and receipt.
        $blockers = [];
        if ($stop['stop'] === true) {
            $blockers[] = 'stop_condition';
        }
        if ($stage['allowed'] === false) {
            $blockers[] = 'stage_out_of_order';
        }
        if ($slice['within_small_slice'] === false) {
            $blockers[] = 'small_slice_violation';
        }
        if ($receipt['valid'] === false) {
            $blockers[] = 'incomplete_loop_receipt';
        }

        $decision = $blockers === [] ? self::DECISION_PROCEED : self::DECISION_STOP;

        return [
            'schema' => self::SCHEMA,
            'decision' => $decision,
            'requested_stage' => $requestedStage,
            'blockers' => $blockers,
            'stage' => $stage,
            'stop_conditions' => $stop,
            'small_slice' => $slice,
            'loop_receipt' => $receipt,
            'human_review_required' => $stop['stop'] === true,
        ];
    }

    /**
     * The next stage to run given the completed set: the first stage in the
     * canonical order that is not yet complete (or 'report' once all are done).
     *
     * @param list<string> $completedStages
     */
    public function nextStage(array $completedStages): string
    {
        $completed = $this->normalizeStages($completedStages);
        foreach (self::STAGES as $stage) {
            if (! in_array($stage, $completed, true)) {
                return $stage;
            }
        }

        return 'report';
    }

    /**
     * Stage gating: a stage may run only when EVERY earlier stage in the
     * canonical order is complete (gated loop, not an open-ended session).
     * Reports the precise missing prerequisites and the required output kind.
     *
     * @param list<string> $completedStages
     * @return array<string,mixed>
     */
    public function evaluateStage(string $stage, array $completedStages): array
    {
        $stage = $this->normalizeStageName($stage) ?? '';
        $completed = $this->normalizeStages($completedStages);

        if ($stage === '' || ! in_array($stage, self::STAGES, true)) {
            return [
                'stage' => $stage,
                'allowed' => false,
                'recognized' => false,
                'missing_prerequisites' => [],
                'required_output' => null,
                'reason' => 'unknown_stage',
            ];
        }

        $index = (int) array_search($stage, self::STAGES, true);
        $prerequisites = array_slice(self::STAGES, 0, $index);

        $missing = [];
        foreach ($prerequisites as $prereq) {
            if (! in_array($prereq, $completed, true)) {
                $missing[] = $prereq;
            }
        }

        $allowed = $missing === [];

        return [
            'stage' => $stage,
            'allowed' => $allowed,
            'recognized' => true,
            'missing_prerequisites' => $missing,
            'required_output' => self::STAGE_OUTPUT[$stage],
            'reason' => $allowed
                ? 'all_prerequisite_stages_complete'
                : 'earlier_stage_incomplete',
        ];
    }

    /**
     * Stop Conditions: ANY true signal forces stop & human review. Returns the
     * fired conditions with their documented reasons.
     *
     * @param array<string,mixed> $signals
     * @return array<string,mixed>
     */
    public function evaluateStopConditions(array $signals): array
    {
        $fired = [];
        foreach (self::STOP_CONDITIONS as $key => $reason) {
            if (($signals[$key] ?? false) === true) {
                $fired[] = ['condition' => $key, 'reason' => $reason];
            }
        }

        return [
            'stop' => $fired !== [],
            'fired' => $fired,
            'fired_count' => count($fired),
            'next_action' => $fired !== [] ? 'stop_and_request_review' : 'continue',
        ];
    }

    /**
     * Small Slice Rule: the loop must prefer the smallest block that improves
     * maturity. A slice is rejected when:
     *   - its description requests whole-runtime/"implement all" scope, OR
     *   - it does not improve maturity (maturity_delta <= 0).
     *
     * @param array<string,mixed> $slice
     *        description    : string
     *        maturity_delta : int|float|string  (>0 means it improves maturity)
     * @return array<string,mixed>
     */
    public function evaluateSlice(array $slice): array
    {
        $description = is_string($slice['description'] ?? null) ? trim($slice['description']) : '';
        $needle = strtolower($description);

        $oversizedHit = null;
        foreach (self::OVERSIZED_SCOPE_NEEDLES as $phrase) {
            if ($needle !== '' && str_contains($needle, $phrase)) {
                $oversizedHit = $phrase;
                break;
            }
        }

        $maturityDelta = $this->toNumber($slice['maturity_delta'] ?? null);
        $improvesMaturity = $maturityDelta !== null && $maturityDelta > 0;

        $reasons = [];
        if ($oversizedHit !== null) {
            $reasons[] = 'oversized_scope:' . $oversizedHit;
        }
        if (! $improvesMaturity) {
            $reasons[] = 'does_not_improve_maturity';
        }

        return [
            'within_small_slice' => $reasons === [],
            'improves_maturity' => $improvesMaturity,
            'oversized_scope' => $oversizedHit !== null,
            'reasons' => $reasons,
        ];
    }

    /**
     * Loop Receipt validity: every one of the 9 required fields must be present
     * AND non-empty. Reports the precise missing/empty fields.
     *
     * @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    public function validateReceipt(array $receipt): array
    {
        $missing = [];
        foreach (self::RECEIPT_FIELDS as $field) {
            if (! array_key_exists($field, $receipt) || $this->isEmptyValue($receipt[$field])) {
                $missing[] = $field;
            }
        }

        return [
            'valid' => $missing === [],
            'required_fields' => self::RECEIPT_FIELDS,
            'missing_fields' => $missing,
            'present_count' => count(self::RECEIPT_FIELDS) - count($missing),
        ];
    }

    /**
     * Learning Rule: the loop may EMIT a learning proposal, but it may NOT apply
     * critical learning automatically. A proposal of a recognized kind is
     * accepted; whether it can auto-apply depends on criticality.
     *
     * @param array<string,mixed> $proposal
     *        kind : string one of LEARNING_KINDS
     * @return array<string,mixed>
     */
    public function evaluateLearningProposal(array $proposal): array
    {
        $kind = is_string($proposal['kind'] ?? null) ? strtolower(trim($proposal['kind'])) : '';
        $recognized = in_array($kind, self::LEARNING_KINDS, true);
        $isCritical = in_array($kind, self::CRITICAL_LEARNING_KINDS, true);

        // Only a recognized, non-critical proposal may auto-apply. Critical
        // learning is always held for a human gate; unknown kinds never apply.
        $autoApplyAllowed = $recognized && ! $isCritical;

        return [
            'kind' => $kind,
            'recognized' => $recognized,
            'is_critical' => $isCritical,
            'may_emit_proposal' => $recognized,
            'auto_apply_allowed' => $autoApplyAllowed,
            'required_action' => $autoApplyAllowed
                ? 'emit_proposal'
                : ($recognized ? 'emit_proposal_and_require_human_gate' : 'reject_unknown_learning_kind'),
        ];
    }

    /**
     * Convenience predicate: may this loop step proceed autonomously?
     */
    public function mayProceed(array $request): bool
    {
        return $this->evaluate($request)['decision'] === self::DECISION_PROCEED;
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * @param mixed $stages
     * @return list<string>
     */
    private function normalizeStages(mixed $stages): array
    {
        if (! is_array($stages)) {
            return [];
        }

        $clean = [];
        foreach ($stages as $stage) {
            $name = $this->normalizeStageName($stage);
            if ($name !== null && in_array($name, self::STAGES, true)) {
                $clean[] = $name;
            }
        }

        return AiStringListNormalizer::uniqueStrings($clean);
    }

    private function normalizeStageName(mixed $stage): ?string
    {
        if (! is_string($stage) || trim($stage) === '') {
            return null;
        }

        // Accept "Drift Check" / "drift-check" as drift_check, etc.
        $key = strtolower(trim($stage));
        $key = (string) preg_replace('/[\s\-]+/', '_', $key);

        return $key;
    }

    private function toNumber(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }
}
