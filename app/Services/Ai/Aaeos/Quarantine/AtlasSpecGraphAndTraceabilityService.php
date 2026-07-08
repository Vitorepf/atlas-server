<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;

/**
 * Atlas SDD Spec Graph & Traceability — pure, deterministic enforcement of the
 * four concrete contracts the doc states for turning "Markdown alone" into an
 * auditable enterprise traceability graph.
 *
 * The doc is explicit that "Markdown alone is insufficient for enterprise SDD"
 * and that "every important requirement must trace to task, file, test and
 * evidence". This service enforces the structured side of that promise without
 * touching the database, a provider or a codebase. It enforces exactly four
 * documented contracts:
 *
 *  1. "Spec Graph" — the linear traceability chain
 *       user_intent -> interpreted_goal -> requirement -> acceptance_criteria
 *       -> plan -> task -> file -> test -> evidence_event -> decision
 *       -> learning_proposal
 *     is exposed as a closed, ordered node catalog. validateChain() pins that a
 *     proposed path follows the documented order with no unknown or out-of-order
 *     links.
 *
 *  2. "Required Questions" — the six questions Atlas MUST be able to answer.
 *     answerQuestions() reports, for one traceability row, which of the six are
 *     answerable, mapping each question to the field(s) (and integrity signals)
 *     that answer it.
 *
 *  3. "Minimum Traceability Row" — the seven-field row schema (spec_id,
 *     requirement_id, acceptance_criteria_id, task_id, file_path, test_path,
 *     evidence_event_id). auditRow() validates a row is complete and returns the
 *     missing fields; an incomplete row is not traceable.
 *
 *  4. "Promotion Rule" — "a feature is not enterprise-complete until critical
 *     requirements have traceable evidence". evaluatePromotion() blocks
 *     promotion when ANY requirement flagged critical lacks a complete row with
 *     an evidence event; non-critical gaps are warnings, never blockers.
 *
 * The service NEVER persists, executes, patches or calls a model. It returns
 * typed verdicts; callers either store an auditable record or stop.
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/spec-graph-and-traceability.md
 */
final class AtlasSpecGraphAndTraceabilityService
{
    /** Stable schema id for the verdicts this service emits. */
    public const SCHEMA = 'atlas.spec_os.spec_graph_and_traceability.v1';

    /** Verdicts (closed set). */
    public const VERDICT_TRACEABLE = 'traceable';
    public const VERDICT_NOT_TRACEABLE = 'not_traceable';
    public const VERDICT_PROMOTABLE = 'promotable';
    public const VERDICT_BLOCKED = 'blocked';
    public const VERDICT_VALID = 'valid';
    public const VERDICT_INVALID = 'invalid';

    /**
     * The Spec Graph chain in documented order. Each link in a valid path must
     * advance to the immediate next node — no skipping, no reordering, no
     * unknown nodes.
     *
     * @var list<string>
     */
    private const GRAPH_CHAIN = [
        'user_intent',
        'interpreted_goal',
        'requirement',
        'acceptance_criteria',
        'plan',
        'task',
        'file',
        'test',
        'evidence_event',
        'decision',
        'learning_proposal',
    ];

    /**
     * The seven fields of the Minimum Traceability Row. A row is traceable only
     * when all seven are present and non-empty.
     *
     * @var list<string>
     */
    private const ROW_FIELDS = [
        'spec_id',
        'requirement_id',
        'acceptance_criteria_id',
        'task_id',
        'file_path',
        'test_path',
        'evidence_event_id',
    ];

    /**
     * The six "Required Questions" from the doc, each mapped to the row field(s)
     * and/or integrity signal(s) that answer it. Keys are stable machine ids;
     * the human prompt is carried for output. Order is documented order.
     *
     * `fields` are row fields that must be present/non-empty. `signals` are
     * boolean integrity facts (see answerQuestions) that must be supplied and
     * must hold for the question to be answerable.
     *
     * @var array<string,array{question:string,fields:list<string>,signals:list<string>}>
     */
    private const REQUIRED_QUESTIONS = [
        'which_test_proves_requirement' => [
            'question' => 'Which test proves this requirement?',
            'fields' => ['requirement_id', 'test_path'],
            'signals' => [],
        ],
        'which_file_implements_criterion' => [
            'question' => 'Which file implements this acceptance criterion?',
            'fields' => ['acceptance_criteria_id', 'file_path'],
            'signals' => [],
        ],
        'which_evidence_proves_gate' => [
            'question' => 'Which evidence proves the gate passed?',
            'fields' => ['evidence_event_id'],
            'signals' => ['gate_passed'],
        ],
        'patch_within_receipt' => [
            'question' => 'Did the patch modify files outside receipt?',
            'fields' => [],
            'signals' => ['patch_within_receipt'],
        ],
        'code_matches_spec' => [
            'question' => 'Did code change without matching spec?',
            'fields' => ['spec_id'],
            'signals' => ['code_matches_spec'],
        ],
        'spec_change_has_test_evidence' => [
            'question' => 'Did spec change without test/evidence?',
            'fields' => ['test_path', 'evidence_event_id'],
            'signals' => ['spec_change_covered'],
        ],
    ];

    /**
     * The integrity signals consumed by answerQuestions(). Each is a boolean the
     * caller supplies from runtime facts (receipt scope check, spec/code diff,
     * gate result). A signal that is absent is treated as "not proven", so the
     * question it gates is NOT answerable — the doc requires positive proof.
     *
     * @var list<string>
     */
    private const INTEGRITY_SIGNALS = [
        'gate_passed',
        'patch_within_receipt',
        'code_matches_spec',
        'spec_change_covered',
    ];

    // ---------------------------------------------------------------------
    // Contract 1 — Spec Graph chain.
    // ---------------------------------------------------------------------

    /**
     * The canonical Spec Graph chain in documented order.
     *
     * @return list<string>
     */
    public function graphChain(): array
    {
        return self::GRAPH_CHAIN;
    }

    /**
     * The node that must immediately follow $node in the chain, or null if
     * $node is unknown or is the terminal node (learning_proposal).
     */
    public function nextNode(string $node): ?string
    {
        $index = array_search($node, self::GRAPH_CHAIN, true);
        if ($index === false) {
            return null;
        }
        $next = $index + 1;

        return $next < count(self::GRAPH_CHAIN) ? self::GRAPH_CHAIN[$next] : null;
    }

    /**
     * Validate that a proposed path through the graph follows the documented
     * order: every node is known, the path starts at or after user_intent, and
     * each step advances by exactly one canonical position (strictly increasing,
     * no gaps, no repeats, no reordering).
     *
     * @param  list<string>  $path  ordered node ids a traversal declares.
     *
     * @return array{
     *   schema:string,
     *   verdict:string,
     *   valid:bool,
     *   length:int,
     *   unknown_nodes:list<string>,
     *   first_break:?array{from:string,to:string,reason:string},
     *   expected_chain:list<string>
     * }
     */
    public function validateChain(array $path): array
    {
        $clean = AtlasAaeosStringListNormalizer::trimmedStringOrIntValues($path);
        $unknown = array_values(array_filter(
            $clean,
            static fn (string $n): bool => ! in_array($n, self::GRAPH_CHAIN, true),
        ));

        $firstBreak = null;
        if ($unknown === []) {
            $prevIndex = -1;
            $prevNode = null;
            foreach ($clean as $node) {
                $index = (int) array_search($node, self::GRAPH_CHAIN, true);
                if ($prevNode !== null && $index !== $prevIndex + 1) {
                    $reason = $index <= $prevIndex ? 'not_advancing' : 'skipped_node';
                    $firstBreak = ['from' => $prevNode, 'to' => $node, 'reason' => $reason];
                    break;
                }
                $prevIndex = $index;
                $prevNode = $node;
            }
        }

        $valid = $unknown === [] && $firstBreak === null && $clean !== [];

        return [
            'schema' => self::SCHEMA,
            'verdict' => $valid ? self::VERDICT_VALID : self::VERDICT_INVALID,
            'valid' => $valid,
            'length' => count($clean),
            'unknown_nodes' => $unknown,
            'first_break' => $firstBreak,
            'expected_chain' => self::GRAPH_CHAIN,
        ];
    }

    // ---------------------------------------------------------------------
    // Contract 2 — Required Questions answerability.
    // ---------------------------------------------------------------------

    /**
     * The six Required Questions, machine id => human prompt, in documented
     * order.
     *
     * @return array<string,string>
     */
    public function requiredQuestions(): array
    {
        return array_map(
            static fn (array $q): string => $q['question'],
            self::REQUIRED_QUESTIONS,
        );
    }

    /**
     * Audit whether the six Required Questions are answerable for one operation,
     * given its traceability row and integrity signals. A question is answerable
     * only when every required row field is present/non-empty AND every gating
     * integrity signal is positively proven (true). The doc demands all six be
     * answerable; `all_answerable` is true only then.
     *
     * @param  array<string,mixed>  $row      a Minimum Traceability Row (see ROW_FIELDS).
     * @param  array<string,bool>   $signals  integrity signals (see INTEGRITY_SIGNALS);
     *                                         any absent signal is treated as not proven.
     *
     * @return array{
     *   schema:string,
     *   all_answerable:bool,
     *   answered:list<string>,
     *   unanswered:list<string>,
     *   answered_count:int,
     *   total_questions:int,
     *   detail:array<string,array{question:string,answerable:bool,missing_fields:list<string>,unproven_signals:list<string>}>
     * }
     */
    public function answerQuestions(array $row, array $signals = []): array
    {
        $detail = [];
        $answered = [];
        $unanswered = [];

        foreach (self::REQUIRED_QUESTIONS as $id => $spec) {
            $missingFields = [];
            foreach ($spec['fields'] as $field) {
                if (! $this->fieldPresent($row, $field)) {
                    $missingFields[] = $field;
                }
            }

            $unprovenSignals = [];
            foreach ($spec['signals'] as $signal) {
                if (($signals[$signal] ?? false) !== true) {
                    $unprovenSignals[] = $signal;
                }
            }

            $answerable = $missingFields === [] && $unprovenSignals === [];
            $detail[$id] = [
                'question' => $spec['question'],
                'answerable' => $answerable,
                'missing_fields' => $missingFields,
                'unproven_signals' => $unprovenSignals,
            ];
            if ($answerable) {
                $answered[] = $id;
            } else {
                $unanswered[] = $id;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'all_answerable' => $unanswered === [],
            'answered' => $answered,
            'unanswered' => $unanswered,
            'answered_count' => count($answered),
            'total_questions' => count(self::REQUIRED_QUESTIONS),
            'detail' => $detail,
        ];
    }

    // ---------------------------------------------------------------------
    // Contract 3 — Minimum Traceability Row schema.
    // ---------------------------------------------------------------------

    /**
     * The seven required field names of a Minimum Traceability Row.
     *
     * @return list<string>
     */
    public function rowFields(): array
    {
        return self::ROW_FIELDS;
    }

    /**
     * Validate one Minimum Traceability Row: it is traceable only when every one
     * of the seven fields is present and non-empty. Returns the missing fields.
     *
     * @param  array<string,mixed>  $row
     *
     * @return array{
     *   schema:string,
     *   verdict:string,
     *   traceable:bool,
     *   present_fields:list<string>,
     *   missing_fields:list<string>,
     *   required_fields:list<string>
     * }
     */
    public function auditRow(array $row): array
    {
        $present = [];
        $missing = [];
        foreach (self::ROW_FIELDS as $field) {
            if ($this->fieldPresent($row, $field)) {
                $present[] = $field;
            } else {
                $missing[] = $field;
            }
        }

        $traceable = $missing === [];

        return [
            'schema' => self::SCHEMA,
            'verdict' => $traceable ? self::VERDICT_TRACEABLE : self::VERDICT_NOT_TRACEABLE,
            'traceable' => $traceable,
            'present_fields' => $present,
            'missing_fields' => $missing,
            'required_fields' => self::ROW_FIELDS,
        ];
    }

    // ---------------------------------------------------------------------
    // Contract 4 — Promotion Rule.
    // ---------------------------------------------------------------------

    /**
     * Evaluate the Promotion Rule across a feature's requirements: "a feature is
     * not enterprise-complete until critical requirements have traceable
     * evidence." Promotion is BLOCKED when any requirement flagged critical does
     * not have a complete, evidence-bearing traceability row. Non-critical
     * requirements that lack traceability are reported as warnings only — they
     * never block promotion.
     *
     * A requirement "has traceable evidence" when its row passes auditRow()
     * (all seven fields present) — which already requires a non-empty
     * evidence_event_id.
     *
     * @param  list<array{requirement_id?:string,critical?:bool,row?:array<string,mixed>}>  $requirements
     *
     * @return array{
     *   schema:string,
     *   verdict:string,
     *   enterprise_complete:bool,
     *   promotion_blocked:bool,
     *   total:int,
     *   critical_total:int,
     *   critical_traced:int,
     *   blocking_requirements:list<string>,
     *   warning_requirements:list<string>
     * }
     */
    public function evaluatePromotion(array $requirements): array
    {
        $criticalTotal = 0;
        $criticalTraced = 0;
        $blocking = [];
        $warnings = [];

        foreach ($requirements as $i => $req) {
            $reqId = is_array($req) && isset($req['requirement_id']) && is_string($req['requirement_id'])
                ? $req['requirement_id']
                : 'req#' . $i;
            $critical = is_array($req) && (bool) ($req['critical'] ?? false);
            $row = is_array($req) && is_array($req['row'] ?? null) ? $req['row'] : [];

            $traced = $this->auditRow($row)['traceable'];

            if ($critical) {
                $criticalTotal++;
                if ($traced) {
                    $criticalTraced++;
                } else {
                    // A critical requirement without traceable evidence blocks promotion.
                    $blocking[] = $reqId;
                }
            } elseif (! $traced) {
                $warnings[] = $reqId;
            }
        }

        $promotionBlocked = $blocking !== [];
        $enterpriseComplete = ! $promotionBlocked;

        return [
            'schema' => self::SCHEMA,
            'verdict' => $enterpriseComplete ? self::VERDICT_PROMOTABLE : self::VERDICT_BLOCKED,
            'enterprise_complete' => $enterpriseComplete,
            'promotion_blocked' => $promotionBlocked,
            'total' => count($requirements),
            'critical_total' => $criticalTotal,
            'critical_traced' => $criticalTraced,
            'blocking_requirements' => $blocking,
            'warning_requirements' => $warnings,
        ];
    }

    // ---------------------------------------------------------------------
    // Helpers.
    // ---------------------------------------------------------------------

    /**
     * A row field is present when it is a non-empty string (after trim) or a
     * positive-length non-empty value. Blank, whitespace-only and null fail.
     */
    private function fieldPresent(array $row, string $field): bool
    {
        $value = $row[$field] ?? null;

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

}
