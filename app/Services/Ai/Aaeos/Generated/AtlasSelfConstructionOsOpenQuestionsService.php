<?php

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Runtime for the Atlas Self-Construction OS Open Questions v1 doc.
 *
 * The doc is the DECISION INBOX of the Self-Construction OS: 10 open questions
 * (OQ-1 .. OQ-10), each a blocker for runtime promotion. Two load-bearing rules
 * are enforced here, deterministically, in pure memory with no DB:
 *
 *   1. Human-only close rule ("Regras para IA"): "IA nao responde nenhuma
 *      questao por conta propria; pode propor evidencia, nao pode encerrar a
 *      questao." So a question is CLOSED only when:
 *        a. an answer text is present, AND
 *        b. the author role is exactly "operator" (the human) — an answer
 *           authored by "ai"/"assistant"/anything else does NOT close it, AND
 *        c. at least one piece of operator-attached evidence is cited.
 *      Miss any one -> the question stays OPEN and its related runtime stays
 *      disabled. The AI may propose evidence (recorded as proposed_evidence) but
 *      that never closes the question.
 *
 *   2. Promotion-hold quorum (Closing Note, 2026-05-14): "Until at least OQ-1 +
 *      OQ-4 + OQ-5 + OQ-7 are answered with evidence, runtime promotion should
 *      remain on hold." Those four are the mandatory unblock quorum. Even if all
 *      six OTHER questions are closed, promotion stays on hold until every one of
 *      the four mandatory questions is closed by the operator with evidence.
 *
 * Each question, while open, keeps its declared related runtime DISABLED.
 *
 * @see docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-open-questions-v1.md
 */
final class AtlasSelfConstructionOsOpenQuestionsService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.self_construction_os_open_questions.v1';

    public const MODE = 'human_decision_inbox_read_only';

    /** The canonical position date recorded in the Closing Note. */
    public const POSITION_DATE = '2026-05-14';

    /** Only this author role can close a question (the human operator). */
    public const CLOSING_AUTHOR_ROLE = 'operator';

    /**
     * The four questions the Closing Note names as the mandatory quorum that
     * must ALL be closed (with evidence) before runtime promotion can leave hold.
     *
     * @var array<int, string>
     */
    public const PROMOTION_HOLD_QUORUM = ['OQ-1', 'OQ-4', 'OQ-5', 'OQ-7'];

    /**
     * The 10 open questions. `runtime` is the cell that stays disabled while the
     * question is open. `risk` is the SC-OS-R-XXX the question unblocks (empty
     * when the doc cites none). `evidence_required` is the doc's "Evidence
     * required to close" line, normalized to a key.
     *
     * @var array<string, array{title:string, runtime:string, risk:string, evidence_required:string}>
     */
    public const QUESTIONS = [
        'OQ-1' => [
            'title' => 'When can runtime real begin?',
            'runtime' => 'first_runtime_cell_promotion',
            'risk' => '',
            'evidence_required' => 'signed_promotion_record_plus_dossier_hash_plus_kill_switch_test',
        ],
        'OQ-2' => [
            'title' => 'Which provider runs first?',
            'runtime' => 'provider_selection',
            'risk' => '',
            'evidence_required' => 'operator_signed_selection_record_provider_model_adapter_workspace_budget',
        ],
        'OQ-3' => [
            'title' => 'What is the initial budget?',
            'runtime' => 'budget_guard',
            'risk' => 'SC-OS-R-004',
            'evidence_required' => 'budget_receipt_token_limit_usd_limit_time_window_revocation',
        ],
        'OQ-4' => [
            'title' => 'What is the kill switch?',
            'runtime' => 'kill_switch',
            'risk' => '',
            'evidence_required' => 'command_plus_ui_binding_plus_test_cuts_in_flight_dispatch',
        ],
        'OQ-5' => [
            'title' => 'What is workspace isolation?',
            'runtime' => 'workspace_isolation',
            'risk' => 'SC-OS-R-014',
            'evidence_required' => 'worktree_provisioner_plus_scope_lock_runtime_plus_parallel_write_test',
        ],
        'OQ-6' => [
            'title' => 'What is the merge policy?',
            'runtime' => 'merge_policy',
            'risk' => '',
            'evidence_required' => 'merge_policy_doc_plus_auto_merge_path_test',
        ],
        'OQ-7' => [
            'title' => 'Which UI surface is mandatory?',
            'runtime' => 'mandatory_ui_surface',
            'risk' => '',
            'evidence_required' => 'panel_design_doc_plus_read_only_build_plus_desktop_smoke_test',
        ],
        'OQ-8' => [
            'title' => 'How to integrate Forge Activation with Agent Control Plane?',
            'runtime' => 'forge_activation_integration',
            'risk' => '',
            'evidence_required' => 'integration_contract_ownership_boundaries_plus_no_overlap_smoke_run',
        ],
        'OQ-9' => [
            'title' => 'When does self-programming become the goal?',
            'runtime' => 'self_programming',
            'risk' => 'SC-OS-R-015',
            'evidence_required' => 'operator_decision_plus_eight_self_programming_preconditions_with_evidence',
        ],
        'OQ-10' => [
            'title' => 'What declares Self-Construction OS complete?',
            'runtime' => 'os_completion_declaration',
            'risk' => '',
            'evidence_required' => 'future_self_construction_os_complete_artifact_citing_eight_items',
        ],
    ];

    /**
     * Evaluate one question against an operator submission. PURE — no I/O, no DB.
     *
     * A question is CLOSED only when an answer is present AND the author is the
     * operator AND at least one evidence item is cited. Anything else leaves it
     * open and the related runtime disabled. An AI-authored answer never closes.
     *
     * @param  array<string, mixed>  $submission {
     *   answer?:string, author_role?:string, evidence?:array<int,string>,
     *   proposed_evidence?:array<int,string>
     * }
     * @return array{
     *   id:string,
     *   title:string,
     *   related_runtime:string,
     *   related_risk:string,
     *   has_answer:bool,
     *   author_role:string,
     *   authored_by_operator:bool,
     *   evidence_count:int,
     *   proposed_evidence_count:int,
     *   closed:bool,
     *   runtime_disabled:bool,
     *   blocking_reason:string
     * }
     */
    public function evaluateQuestion(string $id, array $submission = []): array
    {
        $normalizedId = $this->normalizeId($id);
        $meta = self::QUESTIONS[$normalizedId] ?? null;

        if ($meta === null) {
            return [
                'id' => $id,
                'title' => '',
                'related_runtime' => '',
                'related_risk' => '',
                'has_answer' => false,
                'author_role' => '',
                'authored_by_operator' => false,
                'evidence_count' => 0,
                'proposed_evidence_count' => 0,
                'closed' => false,
                'runtime_disabled' => true,
                'blocking_reason' => 'unknown_question_not_in_decision_inbox',
            ];
        }

        $answer = $submission['answer'] ?? null;
        $hasAnswer = is_string($answer) && trim($answer) !== '';

        $authorRole = strtolower(trim((string) ($submission['author_role'] ?? '')));
        $authoredByOperator = $authorRole === self::CLOSING_AUTHOR_ROLE;

        $evidence = $this->normalizeList($submission['evidence'] ?? []);
        $proposed = $this->normalizeList($submission['proposed_evidence'] ?? []);

        // Human-only close rule: all three conditions required.
        $closed = $hasAnswer && $authoredByOperator && $evidence !== [];

        $blockingReason = match (true) {
            $closed => 'closed_by_operator_with_evidence',
            ! $hasAnswer => 'no_answer_recorded',
            ! $authoredByOperator => 'answer_not_authored_by_operator_ai_cannot_close',
            $evidence === [] => 'answer_present_but_no_operator_evidence_attached',
            default => 'open',
        };

        return [
            'id' => $normalizedId,
            'title' => $meta['title'],
            'related_runtime' => $meta['runtime'],
            'related_risk' => $meta['risk'],
            'has_answer' => $hasAnswer,
            'author_role' => $authorRole,
            'authored_by_operator' => $authoredByOperator,
            'evidence_count' => count($evidence),
            'proposed_evidence_count' => count($proposed),
            'closed' => $closed,
            // While open the doc says the related runtime stays disabled.
            'runtime_disabled' => ! $closed,
            'blocking_reason' => $blockingReason,
        ];
    }

    /**
     * Decide whether runtime promotion may leave HOLD. Per the Closing Note,
     * promotion stays on hold until ALL of OQ-1 + OQ-4 + OQ-5 + OQ-7 are closed
     * (operator-answered with evidence). Other questions do not lift the hold.
     * PURE.
     *
     * @param  array<string, array<string,mixed>>  $submissions  keyed by question id
     * @return array{
     *   promotion_on_hold:bool,
     *   quorum:array<int,string>,
     *   quorum_closed:array<int,string>,
     *   quorum_open:array<int,string>,
     *   quorum_satisfied:bool,
     *   reason:string
     * }
     */
    public function evaluatePromotionHold(array $submissions = []): array
    {
        $closed = [];
        $open = [];

        foreach (self::PROMOTION_HOLD_QUORUM as $qid) {
            $result = $this->evaluateQuestion($qid, $submissions[$qid] ?? []);
            if ($result['closed']) {
                $closed[] = $qid;
            } else {
                $open[] = $qid;
            }
        }

        $quorumSatisfied = $open === [];

        return [
            'promotion_on_hold' => ! $quorumSatisfied,
            'quorum' => self::PROMOTION_HOLD_QUORUM,
            'quorum_closed' => $closed,
            'quorum_open' => $open,
            'quorum_satisfied' => $quorumSatisfied,
            'reason' => $quorumSatisfied
                ? 'mandatory_quorum_closed_promotion_may_leave_hold'
                : 'mandatory_quorum_incomplete_promotion_stays_on_hold',
        ];
    }

    /**
     * Full decision-inbox snapshot. By default (no submissions) every one of the
     * 10 questions is OPEN, every related runtime is disabled, and promotion is
     * on hold — exactly as the doc states for read-only. A caller MAY pass a map
     * of question id => operator submission; only questions whose submission
     * passes the human-only close rule flip to closed.
     *
     * @param  array<string, array<string,mixed>>  $submissions  keyed by question id
     * @return array<string, mixed>
     */
    public function snapshot(array $submissions = []): array
    {
        $questions = [];
        $closedIds = [];
        $disabledRuntimes = [];

        foreach (array_keys(self::QUESTIONS) as $qid) {
            $result = $this->evaluateQuestion($qid, $submissions[$qid] ?? []);
            if ($result['closed']) {
                $closedIds[] = $qid;
            } else {
                $disabledRuntimes[] = $result['related_runtime'];
            }
            $questions[] = $result;
        }

        $hold = $this->evaluatePromotionHold($submissions);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'position_date' => self::POSITION_DATE,
            'question_count' => count(self::QUESTIONS),
            'closed_count' => count($closedIds),
            'open_count' => count(self::QUESTIONS) - count($closedIds),
            'closed_questions' => $closedIds,
            'disabled_runtimes' => $disabledRuntimes,
            // Doc invariant: with no operator decision recorded, every question is
            // open and the AI cannot close any of them.
            'all_open' => $closedIds === [],
            'promotion_on_hold' => $hold['promotion_on_hold'],
            'promotion_hold' => $hold,
            'questions' => $questions,
            'close_rule' => 'A question closes ONLY when answered by author_role="operator" with at least one evidence item. AI may propose evidence but can never close a question.',
        ];
    }

    private function normalizeId(string $id): string
    {
        $upper = strtoupper(trim($id));
        // Accept "oq-1", "OQ1", "1" -> "OQ-1".
        if (preg_match('/^OQ-?(\d{1,2})$/', $upper, $m) === 1) {
            return 'OQ-'.((int) $m[1]);
        }
        if (preg_match('/^(\d{1,2})$/', $upper, $m) === 1) {
            return 'OQ-'.((int) $m[1]);
        }

        return $upper;
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function normalizeList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return array_values($out);
    }
}
