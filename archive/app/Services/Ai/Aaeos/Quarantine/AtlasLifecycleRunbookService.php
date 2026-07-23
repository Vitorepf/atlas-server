<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Engineering Blueprint Lifecycle Runbook decider.
 *
 * Pure, deterministic gate that turns the lifecycle runbook into a contract. It
 * answers three distinct, documented questions and never lies about any of them:
 *
 *  1. Product Lifecycle (doc "Product Lifecycle" table): an ordered 11-step
 *     pipeline that runs from project intent to memory delta —
 *       prepare_blueprint -> create_blueprint -> validate_coverage ->
 *       freeze_blueprint -> generate_tasks -> run_task -> qa_manual ->
 *       deep_review -> postgres_review -> promote_atlas_bench -> memory_delta.
 *     The pipeline is strictly sequential: a step can only be `done` with
 *     verifiable evidence, and the FIRST not-done step (doc order) is the
 *     current/blocking step that gates everything downstream. The lifecycle is
 *     only `complete` when every step is proven — only then may a session
 *     promote a memory delta and claim the work is finished.
 *
 *  2. AI Start Checklist (doc "AI Start Checklist"): five prerequisites —
 *       read_contract, compile_context, confirm_gates, execute_runtime,
 *       produce_evidence — that must ALL be satisfied. A session may not begin
 *       execution until the pre-execution prerequisites are met.
 *
 *  3. Failure Rule (doc "Failure Rule"): "Missing contract, stale blueprint,
 *     absent evidence or blocking gate means the operation should pause or
 *     repair, not claim completion." This is the hard invariant. When any of
 *     those four conditions is present, the operation verdict is `pause` or
 *     `repair` — it is NEVER `complete`. A missing contract, a stale blueprint
 *     or a blocking gate are structural and force `pause`; merely absent
 *     evidence (with structure otherwise intact) forces `repair`. Under any
 *     failure condition `may_claim_completion` is false.
 *
 * Evidence gate (doc frontmatter `forbidden_changes`): "Declarar runtime,
 * maturidade ou prontidao sem evidencia verificavel e gates verdes" is
 * forbidden. So a step asserted `done=true` but carrying NO verifiable evidence
 * is treated as NOT done. Completion can never be claimed on bare assertion.
 *
 * The service is pure: it consumes already-normalized step/checklist/failure
 * results and emits a verdict. It never runs a harness, reads a doc, freezes a
 * blueprint, or touches a DB.
 *
 * @see docs/engineering-knowledge-base/engineering-blueprint/lifecycle-runbook.md
 */
final class AtlasLifecycleRunbookService
{
    /** Stable receipt schema id for the verdict this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.engineering.blueprint_lifecycle_runbook.v1';

    /** Closed set of lifecycle verdicts. */
    public const LIFECYCLE_COMPLETE = 'complete';
    public const LIFECYCLE_IN_PROGRESS = 'in_progress';

    /** Closed set of Failure-Rule operation verdicts. */
    public const OP_COMPLETE = 'complete';
    public const OP_PAUSE = 'pause';
    public const OP_REPAIR = 'repair';

    /**
     * The 11 Product Lifecycle steps, in strict doc-table order. The pipeline is
     * sequential: an earlier not-done step blocks every later one. Each maps to
     * a documented purpose.
     *
     * @var array<string,string>
     */
    public const LIFECYCLE_STEPS = [
        'prepare_blueprint' => 'Draft objective, context, repos, risks and constraints.',
        'create_blueprint' => 'Build deterministic project-level plan from intent and project state.',
        'validate_coverage' => 'Block if inventory, scenarios, phase plan or gates are incomplete.',
        'freeze_blueprint' => 'Hash and freeze immutable version.',
        'generate_tasks' => 'Convert phases into strong task contracts.',
        'run_task' => 'Execute through Programming/Harness with context and gates.',
        'qa_manual' => 'Attach human or runtime evidence.',
        'deep_review' => 'Add categorized findings and confidence.',
        'postgres_review' => 'Validate database risk and query/lock concerns.',
        'promote_atlas_bench' => 'Use run evidence for Atlas-Bench calibration and scoring.',
        'memory_delta' => 'Promote reusable learning through Memory Core policy.',
    ];

    /**
     * The five "AI Start Checklist" prerequisites, in doc order. All must be
     * satisfied before a session may execute through a runtime.
     *
     * @var array<string,string>
     */
    public const START_CHECKLIST = [
        'read_contract' => 'Read task contract and frozen blueprint.',
        'compile_context' => 'Compile context from Open Brain and Engineering Context.',
        'confirm_gates' => 'Confirm gates and expected evidence.',
        'execute_runtime' => 'Execute through the selected runtime.',
        'produce_evidence' => 'Produce evidence packet and memory delta proposal.',
    ];

    /**
     * The four "Failure Rule" conditions, in doc order. Any one present forces
     * the operation off the `complete` path and onto pause/repair.
     *
     * @var array<string,string>
     */
    public const FAILURE_CONDITIONS = [
        'missing_contract' => 'Missing task contract.',
        'stale_blueprint' => 'Frozen blueprint is stale relative to current state.',
        'absent_evidence' => 'Required evidence is absent.',
        'blocking_gate' => 'A blocking quality gate is present.',
    ];

    /**
     * Evaluate the ordered Product Lifecycle pipeline.
     *
     * @param array<string,mixed> $state
     *   steps: array<string,mixed> keyed by LIFECYCLE_STEPS keys. Each value may
     *          be a bool (true=done) or an array { done: bool,
     *          evidence: list<string>|string|null }. A missing step => not done.
     *          Per the evidence gate, a bare boolean true carries no evidence and
     *          therefore does NOT count as done.
     *
     * @return array{
     *   schema: string,
     *   verdict: string,
     *   is_complete: bool,
     *   total_steps: int,
     *   done_count: int,
     *   steps: array<string,bool>,
     *   current_step: ?string,
     *   current_step_purpose: ?string,
     *   pending_steps: list<string>,
     *   may_promote_memory_delta: bool,
     *   reasons: list<string>,
     *   auditable: true
     * }
     */
    public function evaluateLifecycle(array $state): array
    {
        $stepsInput = is_array($state['steps'] ?? null) ? $state['steps'] : [];

        $steps = [];
        $pending = [];
        $currentStep = null;

        foreach (array_keys(self::LIFECYCLE_STEPS) as $key) {
            $done = $this->resolveStep($stepsInput[$key] ?? null);
            $steps[$key] = $done;

            if (! $done) {
                $pending[] = $key;
                // The FIRST not-done step (doc order) is the current/blocking
                // step, because the lifecycle is strictly sequential.
                if ($currentStep === null) {
                    $currentStep = $key;
                }
            }
        }

        $doneCount = count(self::LIFECYCLE_STEPS) - count($pending);
        $isComplete = $pending === [];

        $reasons = [];
        if ($isComplete) {
            $reasons[] = 'lifecycle:all_steps_done_with_evidence';
        } else {
            $reasons[] = 'lifecycle:current_step:' . (string) $currentStep;
            foreach ($pending as $key) {
                $reasons[] = 'lifecycle_pending:' . $key;
            }
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'verdict' => $isComplete ? self::LIFECYCLE_COMPLETE : self::LIFECYCLE_IN_PROGRESS,
            'is_complete' => $isComplete,
            'total_steps' => count(self::LIFECYCLE_STEPS),
            'done_count' => $doneCount,
            'steps' => $steps,
            'current_step' => $currentStep,
            'current_step_purpose' => $currentStep !== null ? self::LIFECYCLE_STEPS[$currentStep] : null,
            'pending_steps' => array_values($pending),
            // doc: the very last step is memory_delta — only a fully proven
            // pipeline may promote the reusable learning.
            'may_promote_memory_delta' => $isComplete,
            'reasons' => $reasons,
            'auditable' => true,
        ];
    }

    /**
     * Evaluate the AI Start Checklist: are the pre-execution prerequisites met?
     *
     * @param array<string,mixed> $checklist
     *   keyed by START_CHECKLIST keys, each a bool. Missing => not satisfied.
     *
     * @return array{
     *   schema: string,
     *   ready_to_execute: bool,
     *   total: int,
     *   satisfied_count: int,
     *   items: array<string,bool>,
     *   missing: list<string>,
     *   reasons: list<string>,
     *   auditable: true
     * }
     */
    public function evaluateStartChecklist(array $checklist): array
    {
        $items = [];
        $missing = [];

        foreach (array_keys(self::START_CHECKLIST) as $key) {
            $done = (bool) ($checklist[$key] ?? false);
            $items[$key] = $done;
            if (! $done) {
                $missing[] = $key;
            }
        }

        $ready = $missing === [];
        $reasons = $ready
            ? ['start_checklist:ready']
            : array_map(static fn (string $k): string => 'start_checklist_missing:' . $k, $missing);

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'ready_to_execute' => $ready,
            'total' => count(self::START_CHECKLIST),
            'satisfied_count' => count(self::START_CHECKLIST) - count($missing),
            'items' => $items,
            'missing' => array_values($missing),
            'reasons' => $reasons,
            'auditable' => true,
        ];
    }

    /**
     * Apply the Failure Rule to one operation.
     *
     * Doc: "Missing contract, stale blueprint, absent evidence or blocking gate
     * means the operation should pause or repair, not claim completion."
     *
     * Decision:
     *  - Any failure condition present => verdict is pause or repair, and
     *    `may_claim_completion` is false (the operation must NOT claim done).
     *  - Structural conditions (missing_contract, stale_blueprint,
     *    blocking_gate) force `pause` — the operation cannot proceed at all.
     *  - When the only condition is `absent_evidence` (structure intact, work
     *    otherwise done) the operation should `repair` — go collect/attach the
     *    missing evidence — rather than pause wholesale.
     *  - No failure condition present => the operation may `complete`.
     *
     * @param array<string,mixed> $signals
     *   missing_contract: bool, stale_blueprint: bool, absent_evidence: bool,
     *   blocking_gate: bool. Each missing => false (condition not present).
     *
     * @return array{
     *   schema: string,
     *   verdict: string,
     *   may_claim_completion: bool,
     *   triggered_conditions: list<string>,
     *   has_structural_failure: bool,
     *   reasons: list<string>,
     *   auditable: true
     * }
     */
    public function applyFailureRule(array $signals): array
    {
        $triggered = [];
        foreach (array_keys(self::FAILURE_CONDITIONS) as $key) {
            if ((bool) ($signals[$key] ?? false)) {
                $triggered[] = $key;
            }
        }

        // Structural conditions force a pause: the operation cannot legitimately
        // proceed until they are resolved.
        $structural = ['missing_contract', 'stale_blueprint', 'blocking_gate'];
        $hasStructural = array_intersect($structural, $triggered) !== [];

        if ($triggered === []) {
            $verdict = self::OP_COMPLETE;
            $mayClaimCompletion = true;
            $reasons = ['failure_rule:no_failure_condition'];
        } elseif ($hasStructural) {
            // doc: "should pause ... not claim completion".
            $verdict = self::OP_PAUSE;
            $mayClaimCompletion = false;
            $reasons = array_map(static fn (string $k): string => 'pause:' . $k, $triggered);
        } else {
            // Only absent_evidence (or otherwise non-structural) => repair.
            // doc: "should ... repair, not claim completion".
            $verdict = self::OP_REPAIR;
            $mayClaimCompletion = false;
            $reasons = array_map(static fn (string $k): string => 'repair:' . $k, $triggered);
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'verdict' => $verdict,
            'may_claim_completion' => $mayClaimCompletion,
            'triggered_conditions' => array_values($triggered),
            'has_structural_failure' => $hasStructural,
            'reasons' => $reasons,
            'auditable' => true,
        ];
    }

    /**
     * Full lifecycle assessment combining all three contracts. The work is only
     * truly "done" when: the start checklist was ready, the ordered lifecycle is
     * complete, AND the Failure Rule permits claiming completion.
     *
     * @param array<string,mixed> $state
     *   { steps: ..., start_checklist: ..., failure_signals: ... }
     * @return array<string,mixed>
     */
    public function assess(array $state): array
    {
        $lifecycle = $this->evaluateLifecycle($state);
        $start = $this->evaluateStartChecklist(
            is_array($state['start_checklist'] ?? null) ? $state['start_checklist'] : []
        );
        $failure = $this->applyFailureRule(
            is_array($state['failure_signals'] ?? null) ? $state['failure_signals'] : []
        );

        $done = $start['ready_to_execute']
            && $lifecycle['is_complete']
            && $failure['may_claim_completion'];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'lifecycle' => $lifecycle,
            'start_checklist' => $start,
            'failure_rule' => $failure,
            'work_done' => $done,
            // Mirrors the Failure Rule: never report done while a failure
            // condition blocks claiming completion.
            'overall_state' => $done
                ? self::LIFECYCLE_COMPLETE
                : ($failure['may_claim_completion'] ? self::LIFECYCLE_IN_PROGRESS : $failure['verdict']),
            'may_claim_completion' => $done,
            'auditable' => true,
        ];
    }

    /**
     * Convenience predicate for the lifecycle driver: may this operation claim
     * completion? Only when no Failure-Rule condition blocks it AND the ordered
     * lifecycle is fully proven.
     *
     * @param array<string,mixed> $state
     */
    public function mayClaimCompletion(array $state): bool
    {
        return $this->assess($state)['may_claim_completion'];
    }

    /**
     * Resolve one lifecycle step to a done boolean.
     *
     * Evidence gate: a step is only done when done=true AND it carries at least
     * one verifiable evidence ref. A bare boolean true carries no evidence and
     * is therefore NOT accepted as proof (only an explicit structure with
     * evidence proves a step). This enforces "no readiness claim without
     * verifiable evidence".
     *
     * @param mixed $entry
     */
    private function resolveStep(mixed $entry): bool
    {
        if (is_bool($entry)) {
            // Bare boolean carries no evidence => never counts as done.
            return false;
        }
        if (! is_array($entry)) {
            return false;
        }

        $doneFlag = (bool) ($entry['done'] ?? false);
        $hasEvidence = $this->hasEvidence($entry['evidence'] ?? null);

        return $doneFlag && $hasEvidence;
    }

    /**
     * A step carries verifiable evidence when it provides at least one non-empty
     * evidence ref (a path, command, receipt id or report).
     *
     * @param mixed $evidence
     */
    private function hasEvidence(mixed $evidence): bool
    {
        if (is_string($evidence)) {
            return trim($evidence) !== '';
        }
        if (is_array($evidence)) {
            foreach ($evidence as $ref) {
                if (is_string($ref) && trim($ref) !== '') {
                    return true;
                }
            }
        }

        return false;
    }
}
