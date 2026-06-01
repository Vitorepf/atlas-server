<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Continuity And Session State decider.
 *
 * Pure, deterministic governance decider that turns the canonical continuity
 * contract into runtime. It does NOT manage live sessions (that is the job of
 * the operational AiSession/AiCompaction/AiProviderHandoff services); it
 * answers the doc's four contract questions without any database:
 *
 *   1. Session state -> may a new execution continue the same operational
 *      story, and under which qualifier (resumo / novo receipt / refs
 *      preservadas / human revalidation)?  ("Estados De Sessao" table).
 *   2. Snapshot completeness -> does a continuity snapshot carry the minimum
 *      fields required to act correctly?  ("Contrato Minimo De Session
 *      Snapshot" table). session_id + intent are the hard-required core; the
 *      rest are required-when-applicable.
 *   3. Compaction classification -> for each field, must compaction PRESERVE
 *      it or DROP it by default?  ("Compactacao" preserve / nao-preservar
 *      lists). Raw prompt, unredacted notes, secrets, exploratory thinking and
 *      verbatim provider output are dropped by default.
 *   4. Failure-mode handling -> map a detected continuity failure to its
 *      documented treatment, including whether the session may still act, must
 *      drop to read/plan-only, or must register a violation first.
 *      ("Failure Modes" table).
 *
 * Cross-cutting invariants enforced from the frontmatter `decisions`:
 *   - Continuity is an auditable EXTENSION of the same work, never a blind
 *     replay of chat.
 *   - A provider/surface handoff ALWAYS requires a new receipt/evidence that
 *     points back to the previous session (never silent).
 *   - Compaction preserves intent + decisions + evidence refs + pending risks
 *     + context hash; it does not preserve the raw prompt by default.
 *
 * Output of every method is a strict typed array (no objects, no DB), so the
 * decision is reproducible and provider-safe.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-continuity-session-state.md
 */
class AtlasAiContinuitySessionStateService
{
    // --- Session states (doc "Estados De Sessao") -------------------------
    public const STATE_ACTIVE = 'active';
    public const STATE_PAUSED = 'paused';
    public const STATE_HANDOFF = 'handoff';
    public const STATE_COMPACTED = 'compacted';
    public const STATE_CLOSED = 'closed';
    public const STATE_ARCHIVED = 'archived';

    // --- Continuation verdicts -------------------------------------------
    public const CONTINUE_YES = 'yes';
    public const CONTINUE_YES_WITH_CONDITION = 'yes_with_condition';
    public const CONTINUE_NEW_RELATED_OPERATION_ONLY = 'new_related_operation_only';
    public const CONTINUE_NO_WITHOUT_HUMAN_REVALIDATION = 'no_without_human_revalidation';
    public const CONTINUE_UNKNOWN_STATE = 'unknown_state';

    // --- Compaction field disposition (doc "Compactacao") ----------------
    public const COMPACT_PRESERVE = 'preserve';
    public const COMPACT_DROP_BY_DEFAULT = 'drop_by_default';

    // --- Failure-mode actions (doc "Failure Modes") ----------------------
    public const ACT_REGENERATE_OPEN_BRAIN_OR_FAIL = 'regenerate_open_brain_or_fail_if_required';
    public const ACT_CONTINUE_READ_PLAN_ONLY = 'continue_read_or_plan_only_until_redaction';
    public const ACT_REGISTER_VIOLATION_NEW_RECEIPT = 'register_violation_and_emit_new_receipt';
    public const ACT_GATHER_CONTEXT_BEFORE_EXECUTE = 'request_context_or_run_collection_before_execute';
    public const ACT_DOCS_WIN_SNAPSHOT_SUSPECT = 'canonical_docs_win_snapshot_marked_suspect';

    /**
     * Per-state continuation rule, transcribed verbatim-in-spirit from the
     * "Estados De Sessao" / "Pode continuar?" table. `requires_new_receipt`
     * encodes the handoff invariant; `requires_human` encodes archived.
     *
     * @var array<string,array{verdict:string,condition:?string,requires_new_receipt:bool,requires_human:bool}>
     */
    private const STATE_RULES = [
        self::STATE_ACTIVE => [
            'verdict' => self::CONTINUE_YES,
            'condition' => null,
            'requires_new_receipt' => false,
            'requires_human' => false,
        ],
        self::STATE_PAUSED => [
            'verdict' => self::CONTINUE_YES_WITH_CONDITION,
            'condition' => 'provide_compact_summary_of_intent_and_evidence',
            'requires_new_receipt' => false,
            'requires_human' => false,
        ],
        self::STATE_HANDOFF => [
            'verdict' => self::CONTINUE_YES_WITH_CONDITION,
            'condition' => 'emit_new_receipt_pointing_to_parent_session',
            'requires_new_receipt' => true,
            'requires_human' => false,
        ],
        self::STATE_COMPACTED => [
            'verdict' => self::CONTINUE_YES_WITH_CONDITION,
            'condition' => 'only_if_evidence_refs_preserved',
            'requires_new_receipt' => false,
            'requires_human' => false,
        ],
        self::STATE_CLOSED => [
            'verdict' => self::CONTINUE_NEW_RELATED_OPERATION_ONLY,
            'condition' => 'open_a_new_related_operation_result_already_delivered',
            'requires_new_receipt' => true,
            'requires_human' => false,
        ],
        self::STATE_ARCHIVED => [
            'verdict' => self::CONTINUE_NO_WITHOUT_HUMAN_REVALIDATION,
            'condition' => 'human_revalidation_required_before_reactivation',
            'requires_new_receipt' => true,
            'requires_human' => true,
        ],
    ];

    /**
     * Minimum snapshot fields (doc "Contrato Minimo De Session Snapshot").
     * `session_id` and `intent` are the hard core — without a stable id and a
     * current objective the snapshot cannot anchor continuity at all. The rest
     * are required-when-applicable and reported as advisory completeness.
     *
     * @var list<string>
     */
    private const SNAPSHOT_HARD_REQUIRED = [
        'session_id',
        'intent',
    ];

    /** @var list<string> */
    private const SNAPSHOT_RECOMMENDED = [
        'parent_session_id',
        'surface_id',
        'domain_id',
        'flow_id',
        'context_pack_hash',
        'decision_receipt_id',
        'evidence_refs',
        'pending_questions',
        'pending_risks',
        'provider_safe_summary',
        'privacy_flags',
    ];

    /**
     * Compaction disposition per field (doc "Compactacao").
     * PRESERVE = "Deve preservar"; DROP = "Nao deve preservar por default".
     *
     * @var array<string,string>
     */
    private const COMPACTION_DISPOSITION = [
        // Deve preservar
        'intent' => self::COMPACT_PRESERVE,
        'decisions' => self::COMPACT_PRESERVE,
        'rejected_alternatives' => self::COMPACT_PRESERVE,
        'evidence_refs' => self::COMPACT_PRESERVE,
        'relevant_files_commands_routes' => self::COMPACT_PRESERVE,
        'operator_constraints' => self::COMPACT_PRESERVE,
        'pending_risks_gates' => self::COMPACT_PRESERVE,
        'provider_model_surface' => self::COMPACT_PRESERVE,
        'context_hash' => self::COMPACT_PRESERVE,
        'refresh_or_reuse_reason' => self::COMPACT_PRESERVE,
        // Nao deve preservar por default
        'raw_prompt' => self::COMPACT_DROP_BY_DEFAULT,
        'unredacted_personal_notes' => self::COMPACT_DROP_BY_DEFAULT,
        'secrets_tokens_envs_paths' => self::COMPACT_DROP_BY_DEFAULT,
        'exploratory_thinking_without_decision' => self::COMPACT_DROP_BY_DEFAULT,
        'verbatim_provider_output' => self::COMPACT_DROP_BY_DEFAULT,
    ];

    /**
     * Failure-mode -> documented treatment (doc "Failure Modes" table).
     * `may_act` is false whenever the doc forbids acting before a remediation
     * step (privacy redaction, new receipt, context gathering).
     *
     * @var array<string,array{action:string,may_act:bool,degrade_to:?string}>
     */
    private const FAILURE_MODES = [
        'stale_context_without_hash' => [
            'action' => self::ACT_REGENERATE_OPEN_BRAIN_OR_FAIL,
            'may_act' => false,
            'degrade_to' => null,
        ],
        'session_with_pending_privacy' => [
            'action' => self::ACT_CONTINUE_READ_PLAN_ONLY,
            'may_act' => true,
            'degrade_to' => 'read_or_plan_only',
        ],
        'provider_changed_without_receipt' => [
            'action' => self::ACT_REGISTER_VIOLATION_NEW_RECEIPT,
            'may_act' => false,
            'degrade_to' => null,
        ],
        'evidence_refs_missing' => [
            'action' => self::ACT_GATHER_CONTEXT_BEFORE_EXECUTE,
            'may_act' => false,
            'degrade_to' => null,
        ],
        'compaction_contradicts_canonical_docs' => [
            'action' => self::ACT_DOCS_WIN_SNAPSHOT_SUSPECT,
            'may_act' => false,
            'degrade_to' => null,
        ],
    ];

    /**
     * The ordered steps `atlas continue` must perform (doc "`atlas continue`").
     *
     * @var list<string>
     */
    private const CONTINUE_STEPS = [
        'locate_most_relevant_session_or_run',
        'verify_context_pack_hash_still_valid',
        'regenerate_open_brain_when_docs_code_memory_changed',
        'show_compact_summary_of_what_will_resume',
        'create_new_receipt_when_provider_executor_policy_or_safety_mode_changed',
        'register_continuity_evidence_in_ledger',
    ];

    /**
     * Decide whether a session in a given state may continue, and the qualifier.
     *
     * @return array{
     *   state:string,
     *   known_state:bool,
     *   can_continue:bool,
     *   verdict:string,
     *   condition:?string,
     *   requires_new_receipt:bool,
     *   requires_human_revalidation:bool
     * }
     */
    public function evaluateContinuation(string $state): array
    {
        $normalized = strtolower(trim($state));
        $rule = self::STATE_RULES[$normalized] ?? null;

        if ($rule === null) {
            return [
                'state' => $normalized,
                'known_state' => false,
                'can_continue' => false,
                'verdict' => self::CONTINUE_UNKNOWN_STATE,
                'condition' => 'unknown_state_requires_classification_before_continuation',
                'requires_new_receipt' => false,
                'requires_human_revalidation' => false,
            ];
        }

        $canContinue = ! in_array($rule['verdict'], [
            self::CONTINUE_NO_WITHOUT_HUMAN_REVALIDATION,
            self::CONTINUE_UNKNOWN_STATE,
        ], true);

        return [
            'state' => $normalized,
            'known_state' => true,
            'can_continue' => $canContinue,
            'verdict' => $rule['verdict'],
            'condition' => $rule['condition'],
            'requires_new_receipt' => $rule['requires_new_receipt'],
            'requires_human_revalidation' => $rule['requires_human'],
        ];
    }

    /**
     * Validate a continuity snapshot against the minimum-snapshot contract.
     * `valid` is true only when every hard-required field is present and
     * non-empty. Recommended fields are reported but never block.
     *
     * @param array<string,mixed> $snapshot
     * @return array{
     *   valid:bool,
     *   missing_required:list<string>,
     *   missing_recommended:list<string>,
     *   present_count:int,
     *   completeness:float
     * }
     */
    public function validateSnapshot(array $snapshot): array
    {
        $missingRequired = [];
        foreach (self::SNAPSHOT_HARD_REQUIRED as $field) {
            if ($this->isEmptyField($snapshot[$field] ?? null)) {
                $missingRequired[] = $field;
            }
        }

        $missingRecommended = [];
        foreach (self::SNAPSHOT_RECOMMENDED as $field) {
            if ($this->isEmptyField($snapshot[$field] ?? null)) {
                $missingRecommended[] = $field;
            }
        }

        $allFields = array_merge(self::SNAPSHOT_HARD_REQUIRED, self::SNAPSHOT_RECOMMENDED);
        $presentCount = count($allFields) - count($missingRequired) - count($missingRecommended);
        $completeness = round($presentCount / count($allFields), 4);

        return [
            'valid' => $missingRequired === [],
            'missing_required' => $missingRequired,
            'missing_recommended' => $missingRecommended,
            'present_count' => $presentCount,
            'completeness' => $completeness,
        ];
    }

    /**
     * Classify which fields a compaction must preserve vs drop by default, and
     * verify a candidate compacted payload honours the contract. Any preserved
     * field that is absent is a violation; any drop-by-default field that is
     * still present is a leak warning.
     *
     * @param array<string,mixed> $compactedPayload  fields the compaction kept
     * @return array{
     *   must_preserve:list<string>,
     *   drop_by_default:list<string>,
     *   missing_preserved:list<string>,
     *   leaked_dropped:list<string>,
     *   compliant:bool
     * }
     */
    public function classifyCompaction(array $compactedPayload = []): array
    {
        $mustPreserve = [];
        $dropByDefault = [];
        foreach (self::COMPACTION_DISPOSITION as $field => $disposition) {
            if ($disposition === self::COMPACT_PRESERVE) {
                $mustPreserve[] = $field;
            } else {
                $dropByDefault[] = $field;
            }
        }

        $missingPreserved = [];
        foreach ($mustPreserve as $field) {
            if ($this->isEmptyField($compactedPayload[$field] ?? null)) {
                $missingPreserved[] = $field;
            }
        }

        $leakedDropped = [];
        foreach ($dropByDefault as $field) {
            if (! $this->isEmptyField($compactedPayload[$field] ?? null)) {
                $leakedDropped[] = $field;
            }
        }

        return [
            'must_preserve' => $mustPreserve,
            'drop_by_default' => $dropByDefault,
            'missing_preserved' => $missingPreserved,
            'leaked_dropped' => $leakedDropped,
            'compliant' => $missingPreserved === [] && $leakedDropped === [],
        ];
    }

    /**
     * Map a detected failure mode to its documented treatment.
     *
     * @return array{
     *   failure:string,
     *   known:bool,
     *   action:string,
     *   may_act:bool,
     *   degrade_to:?string
     * }
     */
    public function handleFailureMode(string $failure): array
    {
        $normalized = strtolower(trim($failure));
        $mode = self::FAILURE_MODES[$normalized] ?? null;

        if ($mode === null) {
            return [
                'failure' => $normalized,
                'known' => false,
                // Unknown continuity failures are fail-safe: do not act.
                'action' => self::ACT_GATHER_CONTEXT_BEFORE_EXECUTE,
                'may_act' => false,
                'degrade_to' => null,
            ];
        }

        return [
            'failure' => $normalized,
            'known' => true,
            'action' => $mode['action'],
            'may_act' => $mode['may_act'],
            'degrade_to' => $mode['degrade_to'],
        ];
    }

    /**
     * Resolve a full continuation plan for one snapshot: combine the state
     * verdict, snapshot validity, and any detected failure modes into a single
     * gate. `blocked` is true when the state forbids continuation, the snapshot
     * is invalid, or any detected failure forbids acting. Enforces the handoff
     * invariant: a handoff that did not produce a parent-linked receipt is a
     * `provider_changed_without_receipt` violation.
     *
     * @param array<string,mixed> $snapshot
     * @param list<string> $detectedFailures
     * @return array{
     *   state:array<string,mixed>,
     *   snapshot:array<string,mixed>,
     *   failures:list<array<string,mixed>>,
     *   blocked:bool,
     *   may_execute:bool,
     *   required_steps:list<string>,
     *   reasons:list<string>
     * }
     */
    public function resolveContinuation(array $snapshot, array $detectedFailures = []): array
    {
        $state = (string) ($snapshot['state'] ?? '');
        $stateDecision = $this->evaluateContinuation($state);
        $snapshotDecision = $this->validateSnapshot($snapshot);

        $reasons = [];

        // Handoff invariant: a handoff snapshot without a receipt pointing to a
        // parent session is, by the doc, a provider-changed-without-receipt
        // violation — fold it into the detected failures.
        if (
            $stateDecision['requires_new_receipt']
            && ($this->isEmptyField($snapshot['decision_receipt_id'] ?? null)
                || $this->isEmptyField($snapshot['parent_session_id'] ?? null))
        ) {
            if (! in_array('provider_changed_without_receipt', $detectedFailures, true)) {
                $detectedFailures[] = 'provider_changed_without_receipt';
            }
            $reasons[] = 'handoff_requires_parent_linked_receipt';
        }

        $failures = [];
        $failureBlocks = false;
        foreach ($detectedFailures as $failure) {
            $resolved = $this->handleFailureMode((string) $failure);
            $failures[] = $resolved;
            if (! $resolved['may_act']) {
                $failureBlocks = true;
                $reasons[] = 'failure:' . $resolved['failure'];
            }
        }

        if (! $stateDecision['can_continue']) {
            $reasons[] = 'state_forbids_continuation:' . $stateDecision['verdict'];
        }
        if (! $snapshotDecision['valid']) {
            $reasons[] = 'snapshot_incomplete:' . implode(',', $snapshotDecision['missing_required']);
        }

        $blocked = ! $stateDecision['can_continue']
            || ! $snapshotDecision['valid']
            || $failureBlocks;

        return [
            'state' => $stateDecision,
            'snapshot' => $snapshotDecision,
            'failures' => $failures,
            'blocked' => $blocked,
            'may_execute' => ! $blocked,
            'required_steps' => self::CONTINUE_STEPS,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    private function isEmptyField(mixed $value): bool
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
