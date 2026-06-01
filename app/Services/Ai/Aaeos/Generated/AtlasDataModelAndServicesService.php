<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas SDD Data Model & Services integrity service.
 *
 * Pure, deterministic enforcement of the three concrete contracts the doc
 * states for making Atlas SDD executable and auditable. The doc is explicit
 * that "Markdown is useful for humans, but runtime SDD must store structured
 * records" — so this service validates the structured-record side of that
 * promise without touching the database, a provider or a codebase.
 *
 * It enforces exactly three documented contracts:
 *
 *  1. "Required Questions" — the data model MUST be able to answer seven
 *     traceability questions for any executed operation. Given a flattened
 *     traceability projection (one operation's records joined together), this
 *     service reports which of the seven are answerable and refuses to call an
 *     operation auditable until all seven are.
 *
 *  2. "Prohibitions" — five hard rules that no SDD runtime may violate. Given
 *     an execution descriptor this service returns the violated prohibitions
 *     (e.g. a write that bypasses the Decision Receipt, a file written outside
 *     receipt scope, generated Markdown treated as authoritative without a
 *     matching structured record, a failed gate hidden by mutating the spec
 *     after execution, a learning proposal mutating core policy without review).
 *
 *  3. "Service Boundaries" — the eleven canonical services and their single
 *     responsibility. Exposed as a closed catalog so callers and tests can pin
 *     the documented orchestration shape and detect missing/extra stages.
 *
 * The service NEVER persists, executes, patches or calls a model. It returns
 * typed verdicts; callers either store an auditable record or stop.
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/data-model-and-services.md
 */
final class AtlasDataModelAndServicesService
{
    /** Stable schema id for the audit verdicts this service emits. */
    public const SCHEMA = 'atlas.spec_os.data_model_and_services.v1';

    /** Verdicts (closed set). */
    public const VERDICT_AUDITABLE = 'auditable';
    public const VERDICT_NOT_AUDITABLE = 'not_auditable';
    public const VERDICT_ALLOWED = 'allowed';
    public const VERDICT_BLOCKED = 'blocked';

    /**
     * The seven "Required Questions" from the doc, each mapped to the
     * traceability field(s) that answer it. A question is answerable only when
     * its required field(s) are present and non-empty in the projection.
     *
     * Keys are stable machine ids; the human prompt is carried for output.
     *
     * @var array<string,array{question:string,fields:list<string>}>
     */
    private const REQUIRED_QUESTIONS = [
        'which_request' => [
            'question' => 'which request produced this change',
            'fields' => ['operation_id'],
        ],
        'which_requirement' => [
            'question' => 'which requirement justified this file edit',
            'fields' => ['requirement_id', 'file_path'],
        ],
        'which_acceptance_criterion' => [
            'question' => 'which acceptance criterion this test proves',
            'fields' => ['acceptance_criteria_id', 'test_path'],
        ],
        'which_receipt' => [
            'question' => 'which Decision Receipt authorized the action',
            'fields' => ['decision_receipt_id'],
        ],
        'which_evidence' => [
            'question' => 'which evidence event proves the gate result',
            'fields' => ['evidence_event_id'],
        ],
        'which_assumptions' => [
            'question' => 'which assumptions were active at execution time',
            'fields' => ['assumption_ids'],
        ],
        'drift_status' => [
            'question' => 'whether code, tests and spec drifted after implementation',
            'fields' => ['drift_status'],
        ],
    ];

    /** Closed set of allowed drift verdicts. */
    private const DRIFT_STATES = ['clean', 'drifted', 'unknown'];

    /**
     * The five documented prohibitions, each with a machine id and the human
     * rule it encodes. Order is stable for deterministic output.
     *
     * @var array<string,string>
     */
    private const PROHIBITIONS = [
        'receipt_bypassed' => 'No controller or worker may bypass Decision Receipt for SDD execution.',
        'write_outside_receipt_scope' => 'No direct write to code outside receipt scope.',
        'markdown_without_structured_record' => 'No SDD runtime may treat generated Markdown as authoritative without matching structured record.',
        'failed_gate_hidden_by_spec_mutation' => 'No failed gate may be hidden by changing the spec after execution.',
        'learning_mutated_core_policy_unreviewed' => 'No learning proposal may mutate core policy without review.',
    ];

    /**
     * The eleven canonical Service Boundaries (Intent Router ... Learning
     * Signals), in documented pipeline order, each with its single
     * responsibility. This is the authoritative orchestration shape.
     *
     * @var array<string,string>
     */
    private const SERVICE_BOUNDARIES = [
        'intent_router' => 'Classify request, domain, risk and required harness.',
        'context_builder' => 'Build context pack from docs, repo, specs, memory and code intelligence.',
        'spec_compiler' => 'Produce operational spec, assumptions and acceptance criteria.',
        'spec_critic' => 'Find ambiguity, missing rules, design conflicts and security risk.',
        'plan_compiler' => 'Convert spec into technical approach.',
        'task_compiler' => 'Produce ordered, scoped tasks with dependencies and allowed files.',
        'decision_engine' => 'Create receipt with actions, tools, files, gates and rollback.',
        'runtime_executor' => 'Execute only inside receipt boundaries.',
        'quality_gate_runner' => 'Run required tests, linters, type checks and audits.',
        'repair_loop' => 'Repair only failures inside receipt scope.',
        'evidence_ledger' => 'Append diff, tests, gates and traceability proof.',
        'learning_signals' => 'Propose template/policy updates without auto-changing critical behavior.',
    ];

    // ---------------------------------------------------------------------
    // Contract 1 — Required Questions answerability.
    // ---------------------------------------------------------------------

    /**
     * Audit one operation's traceability projection against the seven Required
     * Questions. The doc requires the model to answer ALL of them; an operation
     * is "auditable" only when every question is answerable.
     *
     * @param  array<string,mixed>  $trace
     *         A flattened projection of atlas_spec_traceability joined with the
     *         records it references. Recognised keys:
     *           operation_id, requirement_id, file_path, acceptance_criteria_id,
     *           test_path, decision_receipt_id, evidence_event_id,
     *           assumption_ids (list), drift_status (clean|drifted|unknown).
     *
     * @return array{
     *   schema:string,
     *   verdict:string,
     *   auditable:bool,
     *   answered:list<string>,
     *   unanswered:list<string>,
     *   answered_count:int,
     *   total_questions:int,
     *   detail:array<string,array{question:string,answerable:bool,missing_fields:list<string>}>
     * }
     */
    public function auditTraceability(array $trace): array
    {
        $detail = [];
        $answered = [];
        $unanswered = [];

        foreach (self::REQUIRED_QUESTIONS as $id => $spec) {
            $missing = [];
            foreach ($spec['fields'] as $field) {
                if (! $this->fieldAnswerable($trace, $field)) {
                    $missing[] = $field;
                }
            }
            $answerable = $missing === [];
            $detail[$id] = [
                'question' => $spec['question'],
                'answerable' => $answerable,
                'missing_fields' => $missing,
            ];
            if ($answerable) {
                $answered[] = $id;
            } else {
                $unanswered[] = $id;
            }
        }

        $auditable = $unanswered === [];

        return [
            'schema' => self::SCHEMA,
            'verdict' => $auditable ? self::VERDICT_AUDITABLE : self::VERDICT_NOT_AUDITABLE,
            'auditable' => $auditable,
            'answered' => $answered,
            'unanswered' => $unanswered,
            'answered_count' => count($answered),
            'total_questions' => count(self::REQUIRED_QUESTIONS),
            'detail' => $detail,
        ];
    }

    // ---------------------------------------------------------------------
    // Contract 2 — Prohibitions gate.
    // ---------------------------------------------------------------------

    /**
     * Gate one SDD execution descriptor against the five documented
     * prohibitions. Returns the list of violated prohibitions; an empty list
     * means the execution honoured every prohibition.
     *
     * @param  array<string,mixed>  $execution
     *         Recognised keys (all optional; absence is treated conservatively
     *         as "the safe state" so callers only flag what they can prove):
     *           has_decision_receipt : bool — was a receipt present at execution?
     *           wrote_code           : bool — did the execution write code?
     *           files_outside_scope  : list<string> — written files that fell
     *                                  outside the receipt's allowed scope.
     *           markdown_authoritative : bool — was generated Markdown treated as
     *                                  authoritative...
     *           has_structured_record  : bool — ...without a matching structured
     *                                  record? (violation only when authoritative
     *                                  AND no structured record).
     *           gate_failed            : bool — did a required gate fail?
     *           spec_mutated_after_execution : bool — was the spec changed after
     *                                  execution (hiding the failed gate)?
     *           learning_mutates_core_policy : bool — does a learning proposal
     *                                  mutate core policy?
     *           learning_reviewed            : bool — was that proposal reviewed?
     *
     * @return array{
     *   schema:string,
     *   verdict:string,
     *   allowed:bool,
     *   violations:list<array{id:string,rule:string}>,
     *   violation_ids:list<string>
     * }
     */
    public function checkProhibitions(array $execution): array
    {
        $violated = [];

        // P1 — bypassing the Decision Receipt for execution.
        $wroteCode = (bool) ($execution['wrote_code'] ?? false);
        $ranCommands = (bool) ($execution['ran_commands'] ?? false);
        $hasReceipt = (bool) ($execution['has_decision_receipt'] ?? false);
        if (($wroteCode || $ranCommands) && ! $hasReceipt) {
            $violated[] = 'receipt_bypassed';
        }

        // P2 — direct write to code outside receipt scope.
        $outside = $this->stringList($execution['files_outside_scope'] ?? []);
        if ($outside !== []) {
            $violated[] = 'write_outside_receipt_scope';
        }

        // P3 — Markdown treated as authoritative without a matching structured record.
        $mdAuthoritative = (bool) ($execution['markdown_authoritative'] ?? false);
        $hasStructured = (bool) ($execution['has_structured_record'] ?? false);
        if ($mdAuthoritative && ! $hasStructured) {
            $violated[] = 'markdown_without_structured_record';
        }

        // P4 — failed gate hidden by mutating the spec after execution.
        $gateFailed = (bool) ($execution['gate_failed'] ?? false);
        $specMutatedAfter = (bool) ($execution['spec_mutated_after_execution'] ?? false);
        if ($gateFailed && $specMutatedAfter) {
            $violated[] = 'failed_gate_hidden_by_spec_mutation';
        }

        // P5 — learning proposal mutating core policy without review.
        $learningMutatesCore = (bool) ($execution['learning_mutates_core_policy'] ?? false);
        $learningReviewed = (bool) ($execution['learning_reviewed'] ?? false);
        if ($learningMutatesCore && ! $learningReviewed) {
            $violated[] = 'learning_mutated_core_policy_unreviewed';
        }

        $violations = array_map(
            fn (string $id): array => ['id' => $id, 'rule' => self::PROHIBITIONS[$id]],
            $violated,
        );

        return [
            'schema' => self::SCHEMA,
            'verdict' => $violated === [] ? self::VERDICT_ALLOWED : self::VERDICT_BLOCKED,
            'allowed' => $violated === [],
            'violations' => $violations,
            'violation_ids' => $violated,
        ];
    }

    // ---------------------------------------------------------------------
    // Contract 3 — Service Boundaries catalog + pipeline-shape check.
    // ---------------------------------------------------------------------

    /**
     * The canonical eleven (twelve including the renderer? — the doc table lists
     * exactly these) Service Boundaries in documented order with their single
     * responsibility.
     *
     * @return array<string,string>
     */
    public function serviceBoundaries(): array
    {
        return self::SERVICE_BOUNDARIES;
    }

    /**
     * Verify a proposed pipeline against the canonical Service Boundaries:
     * is every required stage present, in order, with nothing unknown injected?
     *
     * @param  list<string>  $stages  the stage ids a pipeline declares it runs.
     *
     * @return array{
     *   schema:string,
     *   verdict:string,
     *   complete:bool,
     *   ordered:bool,
     *   missing:list<string>,
     *   unknown:list<string>,
     *   expected_order:list<string>
     * }
     */
    public function validatePipelineShape(array $stages): array
    {
        $canonical = array_keys(self::SERVICE_BOUNDARIES);
        $declared = $this->stringList($stages);

        $missing = array_values(array_diff($canonical, $declared));
        $unknown = array_values(array_diff($declared, $canonical));

        // Ordered = the canonical stages that ARE declared appear in canonical
        // relative order (ignoring unknown extras).
        $declaredCanonicalOnly = array_values(array_filter(
            $declared,
            static fn (string $s): bool => in_array($s, $canonical, true),
        ));
        $expectedRelative = array_values(array_filter(
            $canonical,
            static fn (string $s): bool => in_array($s, $declaredCanonicalOnly, true),
        ));
        $ordered = $declaredCanonicalOnly === $expectedRelative;

        $complete = $missing === [] && $unknown === [] && $ordered;

        return [
            'schema' => self::SCHEMA,
            'verdict' => $complete ? self::VERDICT_ALLOWED : self::VERDICT_BLOCKED,
            'complete' => $complete,
            'ordered' => $ordered,
            'missing' => $missing,
            'unknown' => $unknown,
            'expected_order' => $canonical,
        ];
    }

    // ---------------------------------------------------------------------
    // Helpers.
    // ---------------------------------------------------------------------

    /**
     * A traceability field is "answerable" when present and non-empty.
     * `drift_status` additionally must be a known state AND not "unknown" — an
     * unknown drift result does not answer "did code/tests/spec drift?".
     * `assumption_ids` is satisfied by a non-empty list.
     */
    private function fieldAnswerable(array $trace, string $field): bool
    {
        $value = $trace[$field] ?? null;

        if ($field === 'drift_status') {
            return is_string($value)
                && in_array($value, self::DRIFT_STATES, true)
                && $value !== 'unknown';
        }

        if ($field === 'assumption_ids') {
            return $this->stringList($value) !== [];
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_int($value)) {
            return true;
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $v): string => is_string($v) ? trim($v) : (is_int($v) ? (string) $v : ''),
                $values,
            ),
            static fn (string $v): bool => $v !== '',
        ));
    }
}
