<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Implementation Planning And Rollout decider.
 *
 * Pure, deterministic implementation of the doc that governs how source-backed
 * documentation becomes small, reversible implementation blocks. It turns four
 * documented contracts into an enforced gate and never lets a block promote
 * while it is ill-shaped, out of rollout order, sitting on a stop condition, or
 * not actually done.
 *
 * Contracts enforced (each maps to a section of the doc):
 *
 *   "Block Shape": every block MUST declare nine fields — objective, owner
 *   files, hot files to avoid, allowed files, forbidden changes, test command,
 *   architecture/doc command (when applicable), rollback plan, success metric.
 *   A block missing any required field is not well-formed and cannot start.
 *
 *   "Rollout Order": seven ordered steps —
 *     1 cold_tests_and_guardrails
 *     2 read_only_scanners_and_reports
 *     3 dto_schema_contracts
 *     4 runtime_adapters_behind_fail_closed_gates
 *     5 surface_exposure
 *     6 promotion_metrics
 *     7 automation
 *   A step may only begin once every earlier step is complete. This is the
 *   doc's invariant: it "avoids making autonomy powerful before it is
 *   observable and reversible" — surface exposure (5) and automation (7) can
 *   never precede cold tests (1) or fail-closed runtime adapters (4).
 *
 *   "Stop Conditions": stop and report when any of six conditions holds —
 *   hotter ownership than planned, weaker source basis, validation needing
 *   forbidden temp files, runtime bypassing Kernel/Policy/Receipt/Ledger, docs
 *   and code disagree, or failure in a hot file owned by another active worker.
 *   Stop conditions are absolute: they override an otherwise valid block.
 *
 *   "Done Definition": a block is done only when all five hold — delta scoped,
 *   tests pass (or failure explained with cause), `git diff --check` passes,
 *   docs-health passes if docs changed, architecture validation passes when
 *   structural contracts changed.
 *
 * The service NEVER runs a test, calls git, reads a doc, mutates a codebase or
 * touches the database. It consumes already-normalized facts and emits a single
 * verdict plus an audit receipt; callers decide whether to start the step,
 * promote the block, or stop and report.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/implementation-planning-and-rollout.md
 */
final class AtlasImplementationPlanningAndRolloutService
{
    /** Stable receipt schema id for the verdict this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.research.implementation_planning_rollout.v1';

    /** Closed set of verdicts. */
    public const VERDICT_PROCEED = 'proceed';
    public const VERDICT_BLOCKED = 'blocked';
    public const VERDICT_STOP = 'stop';

    /** Required next action per verdict. */
    public const NEXT_EXECUTE_BLOCK = 'execute_block';
    public const NEXT_FIX_BLOCK = 'fix_block';
    public const NEXT_STOP_AND_REPORT = 'stop_and_report';

    /**
     * The nine "Block Shape" fields, in doc order. The architecture/doc command
     * is conditional ("when applicable") and is the only optional field.
     *
     * @var array<string,string>
     */
    private const BLOCK_SHAPE_FIELDS = [
        'objective' => 'State the block objective.',
        'owner_files' => 'List the owner files for the block.',
        'hot_files_to_avoid' => 'List hot files to avoid.',
        'allowed_files' => 'List allowed files.',
        'forbidden_changes' => 'Declare forbidden changes.',
        'test_command' => 'Provide the test command.',
        'architecture_doc_command' => 'Provide the architecture/doc command when applicable.',
        'rollback_plan' => 'Provide the rollback plan.',
        'success_metric' => 'Provide the success metric.',
    ];

    /**
     * Block Shape field that is only required when applicable; all others are
     * mandatory for a well-formed block.
     *
     * @var list<string>
     */
    private const CONDITIONAL_SHAPE_FIELDS = [
        'architecture_doc_command',
    ];

    /**
     * The seven "Rollout Order" steps, in mandatory order (index = order rank).
     *
     * @var list<string>
     */
    private const ROLLOUT_ORDER = [
        'cold_tests_and_guardrails',
        'read_only_scanners_and_reports',
        'dto_schema_contracts',
        'runtime_adapters_behind_fail_closed_gates',
        'surface_exposure',
        'promotion_metrics',
        'automation',
    ];

    /**
     * The six "Stop Conditions", in doc order. Each maps to a boolean flag the
     * caller raises when the condition is observed.
     *
     * @var array<string,string>
     */
    private const STOP_CONDITIONS = [
        'touches_hotter_ownership_than_planned' => 'implementation touches hotter ownership than planned',
        'source_basis_weaker_than_expected' => 'source basis is weaker than expected',
        'validation_requires_forbidden_temp_files' => 'validation would require forbidden temporary files',
        'runtime_bypasses_kernel_policy_receipt_ledger' => 'runtime change would bypass Kernel/Policy/Receipt/Ledger',
        'docs_and_code_disagree' => 'docs and code disagree',
        'failure_in_hot_file_owned_by_another_worker' => 'failure is in a hot file owned by another active worker',
    ];

    /**
     * The five "Done Definition" conditions, in doc order. Each maps to a
     * boolean flag the caller asserts true once satisfied. Two are conditional:
     * docs-health only applies if docs changed, architecture validation only
     * applies when structural contracts changed.
     *
     * @var array<string,string>
     */
    private const DONE_CONDITIONS = [
        'delta_scoped' => 'code/doc delta is scoped',
        'tests_pass_or_explained' => 'tests pass or failure is explained with cause',
        'git_diff_check_passes' => 'git diff --check passes',
        'docs_health_passes' => 'docs-health passes if docs changed',
        'architecture_validation_passes' => 'architecture validation passes when structural contracts changed',
    ];

    /**
     * Evaluate one implementation block at a given rollout step and return the
     * single verdict.
     *
     * @param array<string,mixed> $block
     *   shape           : array<string,mixed> the nine Block Shape fields (see
     *                     BLOCK_SHAPE_FIELDS keys). A field is "declared" when it
     *                     is a non-empty string or non-empty array. Missing or
     *                     empty mandatory fields make the block ill-shaped.
     *   step            : string  the rollout step being attempted (one of
     *                     ROLLOUT_ORDER); unknown/missing => out of order.
     *   completed_steps : list<string>  rollout steps already completed; a step
     *                     may only start when ALL earlier steps are present.
     *   stop_conditions : array<string,bool>  the six Stop-Condition flags (see
     *                     STOP_CONDITIONS keys); missing => false.
     *   done            : array<string,bool>  the Done-Definition flags (see
     *                     DONE_CONDITIONS keys); missing => false. Informational
     *                     for a mid-rollout step; required for `automation`
     *                     promotion.
     *   docs_changed        : bool  whether docs changed (gates docs-health).
     *   structural_contracts_changed : bool  whether structural contracts
     *                     changed (gates architecture validation).
     *
     * @return array<string,mixed> verdict + breakdowns + receipt
     */
    public function evaluate(array $block): array
    {
        $shape = $this->evaluateShape($block['shape'] ?? []);
        $order = $this->evaluateOrder(
            (string) ($block['step'] ?? ''),
            $block['completed_steps'] ?? [],
        );
        $stop = $this->evaluateStopConditions($block['stop_conditions'] ?? []);
        $done = $this->evaluateDone(
            $block['done'] ?? [],
            (bool) ($block['docs_changed'] ?? false),
            (bool) ($block['structural_contracts_changed'] ?? false),
        );

        $reasons = [];
        $verdict = null;

        // --- Rule 1: Stop Conditions are absolute and override everything. ---
        // "Stop and report when: ... runtime change would bypass
        // Kernel/Policy/Receipt/Ledger; docs and code disagree; ...".
        if ($stop['triggered'] !== []) {
            $verdict = self::VERDICT_STOP;
            foreach ($stop['triggered'] as $key) {
                $reasons[] = 'stop:' . $key;
            }
        }

        // --- Rule 2: the block must be well-formed (Block Shape). ---
        // "Every block must declare: objective; owner files; ...".
        if ($verdict === null && ! $shape['well_formed']) {
            $verdict = self::VERDICT_BLOCKED;
            foreach ($shape['missing'] as $key) {
                $reasons[] = 'shape_missing:' . $key;
            }
        }

        // --- Rule 3: the rollout step must be in order. ---
        // A step may only begin once every earlier step is complete.
        if ($verdict === null && ! $order['in_order']) {
            $verdict = self::VERDICT_BLOCKED;
            $reasons[] = 'rollout_out_of_order:' . $order['step'];
            foreach ($order['missing_prerequisites'] as $key) {
                $reasons[] = 'prerequisite_incomplete:' . $key;
            }
        }

        // --- Rule 4: promoting the final step (automation) requires Done. ---
        // "Automation" is the last rollout step; it must not turn on while the
        // block is not actually done.
        if ($verdict === null
            && $order['step'] === self::ROLLOUT_FINAL_STEP
            && ! $done['done']) {
            $verdict = self::VERDICT_BLOCKED;
            foreach ($done['unmet'] as $key) {
                $reasons[] = 'done_unmet:' . $key;
            }
        }

        // --- Rule 5: shaped + in order + no stop (+ done if final) => proceed. ---
        if ($verdict === null) {
            $verdict = self::VERDICT_PROCEED;
            $reasons[] = 'block_ready';
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'verdict' => $verdict,
            'required_next_action' => $this->requiredNextAction($verdict),
            'may_execute_step' => $verdict === self::VERDICT_PROCEED,
            'shape' => $shape,
            'rollout' => $order,
            'stop_conditions' => $stop,
            'done' => $done,
            'auditable' => true,
            'reasons' => $reasons,
        ];
    }

    /** The last rollout step (automation) — gated on the Done Definition. */
    private const ROLLOUT_FINAL_STEP = 'automation';

    /**
     * Convenience predicate for the rollout driver: may this block start the
     * given step now? Only a proceed verdict allows it.
     *
     * @param array<string,mixed> $block
     */
    public function mayExecuteStep(array $block): bool
    {
        return $this->evaluate($block)['verdict'] === self::VERDICT_PROCEED;
    }

    /**
     * The canonical rollout order. Exposed so a planner can render or sort the
     * documented sequence without re-deriving it.
     *
     * @return list<string>
     */
    public function rolloutOrder(): array
    {
        return self::ROLLOUT_ORDER;
    }

    /**
     * Evaluate the Block Shape. A field counts as declared when it is a
     * non-empty string or a non-empty array. The architecture/doc command is
     * the only optional field ("when applicable").
     *
     * @param mixed $shape
     * @return array<string,mixed>
     */
    private function evaluateShape(mixed $shape): array
    {
        $shape = is_array($shape) ? $shape : [];
        $fields = [];
        $missing = [];

        foreach (array_keys(self::BLOCK_SHAPE_FIELDS) as $key) {
            $declared = $this->fieldDeclared($shape[$key] ?? null);
            $fields[$key] = $declared;

            if (! $declared && ! in_array($key, self::CONDITIONAL_SHAPE_FIELDS, true)) {
                $missing[] = $key;
            }
        }

        $mandatoryTotal = count(self::BLOCK_SHAPE_FIELDS) - count(self::CONDITIONAL_SHAPE_FIELDS);

        return [
            'fields' => $fields,
            'mandatory_total' => $mandatoryTotal,
            'declared_count' => $mandatoryTotal - count($missing),
            'missing' => array_values($missing),
            'well_formed' => $missing === [],
        ];
    }

    /**
     * Evaluate the Rollout Order. The step must be a known rollout step and
     * every earlier step in ROLLOUT_ORDER must be present in completed_steps.
     *
     * @param mixed $completedSteps
     * @return array<string,mixed>
     */
    private function evaluateOrder(string $step, mixed $completedSteps): array
    {
        $completed = $this->normalizeStepList($completedSteps);
        $rank = array_search($step, self::ROLLOUT_ORDER, true);

        if ($rank === false) {
            return [
                'step' => $step,
                'rank' => null,
                'known_step' => false,
                'completed_steps' => $completed,
                'missing_prerequisites' => [],
                'in_order' => false,
            ];
        }

        $prerequisites = array_slice(self::ROLLOUT_ORDER, 0, $rank);
        $missing = [];
        foreach ($prerequisites as $prereq) {
            if (! in_array($prereq, $completed, true)) {
                $missing[] = $prereq;
            }
        }

        return [
            'step' => $step,
            'rank' => $rank + 1,
            'known_step' => true,
            'completed_steps' => $completed,
            'missing_prerequisites' => array_values($missing),
            'in_order' => $missing === [],
        ];
    }

    /**
     * Resolve the Stop Conditions from the explicit boolean flags.
     *
     * @param mixed $stopFlags
     * @return array<string,mixed>
     */
    private function evaluateStopConditions(mixed $stopFlags): array
    {
        $stopFlags = is_array($stopFlags) ? $stopFlags : [];
        $triggered = [];

        foreach (array_keys(self::STOP_CONDITIONS) as $key) {
            if ((bool) ($stopFlags[$key] ?? false)) {
                $triggered[] = $key;
            }
        }

        return [
            'definitions' => self::STOP_CONDITIONS,
            'triggered' => array_values($triggered),
            'any' => $triggered !== [],
        ];
    }

    /**
     * Evaluate the Done Definition. The two conditional conditions only apply
     * when their trigger is set: docs-health only when docs changed, and
     * architecture validation only when structural contracts changed. A
     * condition that does not apply is treated as satisfied (not blocking).
     *
     * @param mixed $doneFlags
     * @return array<string,mixed>
     */
    private function evaluateDone(mixed $doneFlags, bool $docsChanged, bool $structuralChanged): array
    {
        $doneFlags = is_array($doneFlags) ? $doneFlags : [];
        $conditions = [];
        $unmet = [];

        foreach (array_keys(self::DONE_CONDITIONS) as $key) {
            $applicable = $this->doneConditionApplicable($key, $docsChanged, $structuralChanged);
            $satisfied = ! $applicable || (bool) ($doneFlags[$key] ?? false);

            $conditions[$key] = [
                'applicable' => $applicable,
                'satisfied' => $satisfied,
            ];

            if ($applicable && ! $satisfied) {
                $unmet[] = $key;
            }
        }

        return [
            'conditions' => $conditions,
            'unmet' => array_values($unmet),
            'done' => $unmet === [],
        ];
    }

    private function doneConditionApplicable(string $key, bool $docsChanged, bool $structuralChanged): bool
    {
        return match ($key) {
            'docs_health_passes' => $docsChanged,
            'architecture_validation_passes' => $structuralChanged,
            default => true,
        };
    }

    /**
     * A Block Shape field is declared when it is a non-empty trimmed string or a
     * non-empty array. Booleans, null, empty strings and empty arrays are not.
     *
     * @param mixed $value
     */
    private function fieldDeclared(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return false;
    }

    /**
     * @param mixed $steps
     * @return list<string>
     */
    private function normalizeStepList(mixed $steps): array
    {
        if (! is_array($steps)) {
            return [];
        }

        $out = [];
        foreach ($steps as $step) {
            if (is_string($step) && $step !== '' && ! in_array($step, $out, true)) {
                $out[] = $step;
            }
        }

        return $out;
    }

    private function requiredNextAction(string $verdict): string
    {
        return match ($verdict) {
            self::VERDICT_PROCEED => self::NEXT_EXECUTE_BLOCK,
            self::VERDICT_STOP => self::NEXT_STOP_AND_REPORT,
            default => self::NEXT_FIX_BLOCK,
        };
    }
}
