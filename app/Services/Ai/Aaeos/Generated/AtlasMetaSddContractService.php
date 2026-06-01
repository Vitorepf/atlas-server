<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Self-Construction Meta-SDD Contract — pure, deterministic governor.
 *
 * Meta-SDD is the SDD of Atlas itself, deliberately stricter than ordinary SDD
 * "because the system being changed is the system that decides future changes".
 * This service turns the documented contract into runtime decisions a
 * self-construction loop can call BEFORE it changes Atlas core. It is read-only:
 * it validates a meta-spec, classifies the target layer, gates the required
 * questions, sequences the Meta-SDD flow, enforces the prohibitions and decides
 * whether a change may take the lightweight "Minimal Meta-SDD" path. It never
 * edits files, runs a provider, writes evidence, promotes maturity or relaxes a
 * gate.
 *
 * Six documented surfaces are implemented:
 *
 *   1. Required Meta-Spec Fields ("Required Meta-Spec Fields"). The 14 meta_spec
 *      fields. `validateMetaSpec()` reports exactly which are missing and is
 *      `complete` only when every field is present and non-empty — an incomplete
 *      meta-spec can never be declared ready.
 *
 *   2. Layer Classification ("Layer Classification"). The L0..L7 ladder every
 *      Meta-SDD must classify the target against. `classifyLayer()` resolves one
 *      layer to its documented scope and flags whether it touches autonomy /
 *      self-programming (L7), which the prohibitions treat as highest-risk.
 *
 *   3. Required Questions ("Required Questions"). The 9 questions that must be
 *      answered before implementation. `answerRequiredQuestions()` is `answered`
 *      only when all 9 have a non-empty answer; any blank question blocks.
 *
 *   4. Meta-SDD Flow ("Meta-SDD Flow"). The 12 strictly ordered stages from
 *      `gap` to `maturity update proposal`. `nextFlowStage()` returns the single
 *      next stage given completed ones and refuses to skip ahead (e.g. jumping to
 *      implementation before docs update / meta-spec / review exist).
 *
 *   5. Prohibitions ("Prohibitions"). The 5 hard "No ..." rules. The doc is
 *      categorical, so `evaluateProhibitions()` returns `allowed = false` the
 *      moment ANY prohibition is violated (e.g. core change from plain user
 *      intent, hidden maturity promotion, risky change without rollback,
 *      self-programming expansion on stale context).
 *
 *   6. Minimal Meta-SDD Exception ("Minimal Meta-SDD Exception"). The 4
 *      conditions that must ALL hold for a tiny doc fix to use the lightweight
 *      spec. `qualifiesForMinimalException()` requires every condition true; a
 *      single false condition forces the full Meta-SDD. Even on the minimal path
 *      the doc still demands evidence, so `evidence_still_required` is always
 *      true.
 *
 * And the top-level gate `gate()`: a change may proceed only when the meta-spec
 * is complete, no prohibition is violated, the required questions are answered,
 * and — for anything that is not a qualifying minimal exception — a rollback
 * strategy exists for risky targets. It emits the bounded verdict + the reasons,
 * never an action.
 *
 * @see docs/engineering-knowledge-base/self-construction/meta-sdd-contract.md
 */
final class AtlasMetaSddContractService
{
    /** Canonical schema id for this governor's envelopes. */
    public const SCHEMA = 'atlas.self_construction.meta_sdd_contract.v1';

    /**
     * Required meta_spec fields ("Required Meta-Spec Fields"), in documented
     * order. Every field must be present and non-empty for the spec to be
     * complete.
     *
     * @var list<string>
     */
    public const META_SPEC_FIELDS = [
        'id',
        'title',
        'target_layer',
        'target_capability',
        'current_maturity',
        'target_maturity',
        'problem',
        'goal',
        'non_goals',
        'dependencies',
        'affected_authority_docs',
        'affected_runtime_components',
        'risk_level',
        'autonomy_allowed',
        'rollback_strategy',
        'evidence_required',
    ];

    // --- Layer keys (closed set; "Layer Classification", documented order). ----
    public const LAYER_L0 = 'l0';
    public const LAYER_L1 = 'l1';
    public const LAYER_L2 = 'l2';
    public const LAYER_L3 = 'l3';
    public const LAYER_L4 = 'l4';
    public const LAYER_L5 = 'l5';
    public const LAYER_L6 = 'l6';
    public const LAYER_L7 = 'l7';

    /**
     * Layer ladder ("Layer Classification") — L0..L7 with the documented scope of
     * each. The two layers that change what Atlas is allowed to do to itself —
     * Tool Runtime (L6) and Autonomy/Self-Programming (L7) — are the autonomy-
     * sensitive layers the prohibitions guard most tightly.
     *
     * @var array<string,string>
     */
    public const LAYER_LADDER = [
        self::LAYER_L0 => 'documentation/governance',
        self::LAYER_L1 => 'Kernel/Decision/Evidence',
        self::LAYER_L2 => 'Memory/Cognitive Runtime',
        self::LAYER_L3 => 'Research/Self-Improvement',
        self::LAYER_L4 => 'SDD/Programming Harness',
        self::LAYER_L5 => 'Product Surface/UI/API/Mobile/Voice',
        self::LAYER_L6 => 'Tool Runtime/MCP/External Integrations',
        self::LAYER_L7 => 'Autonomy/Self-Programming',
    ];

    /** Layers that touch autonomy / self-programming surface area. */
    public const AUTONOMY_SENSITIVE_LAYERS = [
        self::LAYER_L7,
    ];

    /**
     * Required Questions ("Required Questions"), in documented order. Every
     * question must be answered (non-empty) before implementation.
     *
     * @var array<string,string>
     */
    public const REQUIRED_QUESTIONS = [
        'capability_improved' => 'What exact Atlas capability improves?',
        'existing_law' => 'Which existing law already governs this?',
        'docs_change_first' => 'Which docs must change first?',
        'code_areas_allowed' => 'Which code areas are allowed?',
        'behavior_unchanged' => 'Which current behavior must remain unchanged?',
        'gates_prove_success' => 'Which gates prove success?',
        'evidence_enough' => 'What evidence is enough?',
        'unsafe_failure' => 'What failure would make the change unsafe?',
        'rollback_possible' => 'What rollback is possible?',
    ];

    /**
     * Meta-SDD Flow ("Meta-SDD Flow") — the 12 stages in strict sequence. The
     * list index + 1 is the stage number. No stage may start before all earlier
     * stages are complete.
     *
     * @var list<string>
     */
    public const FLOW_STAGES = [
        'gap',
        'layer_risk_classification',
        'research_if_unstable',
        'docs_update',
        'meta_spec',
        'critic_security_architecture_review',
        'plan_tasks',
        'receipt',
        'small_implementation',
        'gates',
        'evidence',
        'drift_check',
        'maturity_update_proposal',
    ];

    // --- Prohibition keys (closed set; "Prohibitions", documented order). ------
    public const PROHIBITION_CORE_FROM_PLAIN_INTENT = 'core_change_from_plain_intent';
    public const PROHIBITION_QUICK_FIX_NO_META_SDD = 'quick_fix_without_meta_sdd';
    public const PROHIBITION_HIDDEN_MATURITY_PROMOTION = 'hidden_maturity_promotion';
    public const PROHIBITION_RISKY_WITHOUT_ROLLBACK = 'risky_change_without_rollback';
    public const PROHIBITION_SELF_PROGRAMMING_ON_STALE = 'self_programming_expansion_on_stale_context';

    /**
     * Prohibitions ("Prohibitions") keyed to their documented description. ANY
     * violated prohibition forbids the change.
     *
     * @var array<string,string>
     */
    public const PROHIBITIONS = [
        self::PROHIBITION_CORE_FROM_PLAIN_INTENT => 'No Atlas core code change from plain user intent.',
        self::PROHIBITION_QUICK_FIX_NO_META_SDD => 'No "quick fix" for self-construction without at least minimal Meta-SDD.',
        self::PROHIBITION_HIDDEN_MATURITY_PROMOTION => 'No hidden maturity promotion.',
        self::PROHIBITION_RISKY_WITHOUT_ROLLBACK => 'No spec that lacks rollback for risky changes.',
        self::PROHIBITION_SELF_PROGRAMMING_ON_STALE => 'No self-programming expansion when documentation and context are stale.',
    ];

    /**
     * Minimal Meta-SDD Exception conditions ("Minimal Meta-SDD Exception"). A
     * tiny documentation fix may use a lightweight spec only if ALL of these
     * hold.
     *
     * @var list<string>
     */
    public const MINIMAL_EXCEPTION_CONDITIONS = [
        'no_runtime_behavior_change',
        'no_authority_order_change',
        'no_policy_autonomy_security_change',
        'docs_health_and_diff_pass',
    ];

    // ---------------------------------------------------------------------
    // 1. Required Meta-Spec Fields
    // ---------------------------------------------------------------------

    /**
     * Validate a meta_spec ("Required Meta-Spec Fields"). Returns the normalized
     * spec, the list of missing fields, and `complete` only when every documented
     * field is present and non-empty. An incomplete meta-spec can never be
     * declared ready.
     *
     * @param array<string,mixed> $metaSpec
     *
     * @return array<string,mixed>
     */
    public function validateMetaSpec(array $metaSpec): array
    {
        $normalized = [];
        $missing = [];

        foreach (self::META_SPEC_FIELDS as $field) {
            $value = $metaSpec[$field] ?? null;
            $normalized[$field] = $value;

            if ($this->isEmptyField($value)) {
                $missing[] = $field;
            }
        }

        $complete = $missing === [];

        return [
            'schema' => self::SCHEMA,
            'surface' => 'meta_spec_fields',
            'complete' => $complete,
            'missing_fields' => $missing,
            'required_fields' => self::META_SPEC_FIELDS,
            'provided_count' => count(self::META_SPEC_FIELDS) - count($missing),
            'meta_spec' => $normalized,
        ];
    }

    // ---------------------------------------------------------------------
    // 2. Layer Classification
    // ---------------------------------------------------------------------

    /**
     * Resolve one documented layer ("Layer Classification") to its scope, flags
     * whether it is autonomy-sensitive (L7 self-programming surface), or null
     * when the key is unknown.
     *
     * @return array<string,mixed>|null
     */
    public function classifyLayer(string $layer): ?array
    {
        $key = $this->normalize($layer);
        if (! isset(self::LAYER_LADDER[$key])) {
            return null;
        }

        return [
            'schema' => self::SCHEMA,
            'surface' => 'layer_classification',
            'layer' => $key,
            'scope' => self::LAYER_LADDER[$key],
            'autonomy_sensitive' => in_array($key, self::AUTONOMY_SENSITIVE_LAYERS, true),
        ];
    }

    // ---------------------------------------------------------------------
    // 3. Required Questions
    // ---------------------------------------------------------------------

    /**
     * Gate the Required Questions ("Required Questions"). Returns `answered` only
     * when all 9 documented questions carry a non-empty answer; otherwise lists
     * the unanswered keys. Unknown keys are reported, never counted as answers.
     *
     * @param array<string,mixed> $answers map of question-key => answer
     *
     * @return array<string,mixed>
     */
    public function answerRequiredQuestions(array $answers): array
    {
        $unanswered = [];
        $unknown = [];

        foreach (array_keys($answers) as $key) {
            $norm = $this->normalize((string) $key);
            if ($norm !== '' && ! array_key_exists($norm, self::REQUIRED_QUESTIONS)) {
                if (! in_array($norm, $unknown, true)) {
                    $unknown[] = $norm;
                }
            }
        }

        foreach (self::REQUIRED_QUESTIONS as $key => $_question) {
            // Accept either the canonical key or a normalized variant.
            $value = $answers[$key] ?? $answers[$this->normalize($key)] ?? null;
            if ($this->isEmptyField($value)) {
                $unanswered[] = $key;
            }
        }

        $answered = $unanswered === [];

        return [
            'schema' => self::SCHEMA,
            'surface' => 'required_questions',
            'answered' => $answered,
            'unanswered' => $unanswered,
            'total_questions' => count(self::REQUIRED_QUESTIONS),
            'answered_count' => count(self::REQUIRED_QUESTIONS) - count($unanswered),
            'unknown_questions' => $unknown,
        ];
    }

    // ---------------------------------------------------------------------
    // 4. Meta-SDD Flow
    // ---------------------------------------------------------------------

    /**
     * Return the single next flow stage ("Meta-SDD Flow") given completed stages,
     * enforcing the documented strict order. Stage N may only be taken once
     * stages 1..N-1 are all complete. A completed stage that is out of order
     * (a later stage done while an earlier one is still pending — e.g. starting
     * implementation before the meta-spec exists) makes the sequence invalid and
     * the verdict refuses to advance.
     *
     * @param list<string> $completed completed flow-stage keys
     *
     * @return array<string,mixed>
     */
    public function nextFlowStage(array $completed): array
    {
        $done = [];
        $unknown = [];
        foreach ($completed as $stage) {
            if (! is_string($stage)) {
                continue;
            }
            $key = $this->normalize($stage);
            if ($key === '') {
                continue;
            }
            if (! in_array($key, self::FLOW_STAGES, true)) {
                if (! in_array($key, $unknown, true)) {
                    $unknown[] = $key;
                }

                continue;
            }
            if (! in_array($key, $done, true)) {
                $done[] = $key;
            }
        }

        // Walk the canonical order: the first stage not yet done is the next one.
        // A stage done AFTER a not-yet-done stage means the sequence was broken.
        $nextStage = null;
        $nextIndex = null;
        $ordered = true;
        $expectedSatisfied = true;

        foreach (self::FLOW_STAGES as $index => $stage) {
            $isDone = in_array($stage, $done, true);
            if ($isDone) {
                if (! $expectedSatisfied) {
                    $ordered = false;
                }

                continue;
            }
            $expectedSatisfied = false;
            if ($nextStage === null) {
                $nextStage = $stage;
                $nextIndex = $index;
            }
        }

        $allComplete = $nextStage === null && $ordered;

        return [
            'schema' => self::SCHEMA,
            'surface' => 'meta_sdd_flow',
            'ordered' => $ordered,
            'completed_stages' => $done,
            'next_stage' => $ordered ? $nextStage : null,
            'next_stage_number' => ($ordered && $nextIndex !== null) ? $nextIndex + 1 : null,
            'all_complete' => $allComplete,
            'total_stages' => count(self::FLOW_STAGES),
            'unknown_stages' => $unknown,
        ];
    }

    // ---------------------------------------------------------------------
    // 5. Prohibitions — ANY violated forbids the change
    // ---------------------------------------------------------------------

    /**
     * Evaluate the Prohibitions ("Prohibitions"). The doc is categorical: ANY
     * violated prohibition forbids the change. Accepts either a list of violated
     * keys (all treated as violated) or a map of key => bool. Unknown keys are
     * reported, never silently treated as safe.
     *
     * @param array<int|string,mixed> $violations
     *
     * @return array<string,mixed>
     */
    public function evaluateProhibitions(array $violations): array
    {
        $violated = [];
        $unknown = [];

        foreach ($violations as $key => $value) {
            if (is_int($key)) {
                $pKey = is_string($value) ? $this->normalize($value) : '';
                $isViolated = true;
            } else {
                $pKey = $this->normalize((string) $key);
                $isViolated = (bool) $value;
            }

            if ($pKey === '') {
                continue;
            }
            if (! isset(self::PROHIBITIONS[$pKey])) {
                if (! in_array($pKey, $unknown, true)) {
                    $unknown[] = $pKey;
                }

                continue;
            }
            if ($isViolated && ! in_array($pKey, $violated, true)) {
                $violated[] = $pKey;
            }
        }

        $allowed = $violated === [];

        return [
            'schema' => self::SCHEMA,
            'surface' => 'prohibitions',
            'allowed' => $allowed,
            'blocked' => ! $allowed,
            'violated' => $violated,
            'violated_reasons' => array_values(array_map(
                static fn (string $p): string => self::PROHIBITIONS[$p],
                $violated,
            )),
            'unknown_prohibitions' => $unknown,
        ];
    }

    // ---------------------------------------------------------------------
    // 6. Minimal Meta-SDD Exception — ALL conditions must hold
    // ---------------------------------------------------------------------

    /**
     * Decide whether a change qualifies for the Minimal Meta-SDD Exception
     * ("Minimal Meta-SDD Exception"). All 4 documented conditions must hold; a
     * single false condition forces the full Meta-SDD. Even when it qualifies,
     * the doc still demands evidence in the final report, so
     * `evidence_still_required` is always true.
     *
     * @param array<string,mixed> $conditions map of condition-key => bool
     *
     * @return array<string,mixed>
     */
    public function qualifiesForMinimalException(array $conditions): array
    {
        $unmet = [];
        $unknown = [];

        foreach (array_keys($conditions) as $key) {
            $norm = $this->normalize((string) $key);
            if ($norm !== '' && ! in_array($norm, self::MINIMAL_EXCEPTION_CONDITIONS, true)) {
                if (! in_array($norm, $unknown, true)) {
                    $unknown[] = $norm;
                }
            }
        }

        foreach (self::MINIMAL_EXCEPTION_CONDITIONS as $condition) {
            $value = $conditions[$condition] ?? $conditions[$this->normalize($condition)] ?? null;
            if ($value !== true) {
                $unmet[] = $condition;
            }
        }

        $qualifies = $unmet === [];

        return [
            'schema' => self::SCHEMA,
            'surface' => 'minimal_exception',
            'qualifies' => $qualifies,
            'path' => $qualifies ? 'minimal_meta_sdd' : 'full_meta_sdd',
            'unmet_conditions' => $unmet,
            'required_conditions' => self::MINIMAL_EXCEPTION_CONDITIONS,
            'evidence_still_required' => true,
            'unknown_conditions' => $unknown,
        ];
    }

    // ---------------------------------------------------------------------
    // Top-level gate
    // ---------------------------------------------------------------------

    /**
     * Combine the surfaces into one bounded verdict for a proposed Meta-SDD
     * change. A change may proceed only when:
     *   - the meta-spec is complete (all 14 fields), AND
     *   - no prohibition is violated, AND
     *   - all required questions are answered, AND
     *   - for a risky target, a rollback_strategy exists in the meta-spec
     *     (this directly enforces "No spec that lacks rollback for risky
     *     changes"). A change that qualifies for the minimal exception still must
     *     satisfy the spec/prohibition/question gates.
     *
     * The verdict is advisory: it reports `proceed` + the blocking reasons and
     * never performs the change.
     *
     * @param array<string,mixed>     $metaSpec
     * @param array<int|string,mixed> $prohibitionViolations
     * @param array<string,mixed>     $questionAnswers
     * @param array<string,mixed>     $minimalConditions
     *
     * @return array<string,mixed>
     */
    public function gate(
        array $metaSpec,
        array $prohibitionViolations = [],
        array $questionAnswers = [],
        array $minimalConditions = [],
    ): array {
        $spec = $this->validateMetaSpec($metaSpec);
        $prohibitions = $this->evaluateProhibitions($prohibitionViolations);
        $questions = $this->answerRequiredQuestions($questionAnswers);
        $minimal = $this->qualifiesForMinimalException($minimalConditions);

        $reasons = [];

        if (! $spec['complete']) {
            $reasons[] = 'meta_spec_incomplete';
        }
        if (! $prohibitions['allowed']) {
            $reasons[] = 'prohibition_violated';
        }
        if (! $questions['answered']) {
            $reasons[] = 'required_questions_unanswered';
        }

        // "No spec that lacks rollback for risky changes": when the declared
        // risk_level is risky (high/critical) the meta-spec MUST carry a
        // rollback_strategy. This is enforced independently of field presence so
        // a blank rollback on a high-risk change is always blocking.
        $riskLevel = $this->normalize((string) ($metaSpec['risk_level'] ?? ''));
        $isRisky = in_array($riskLevel, ['high', 'critical'], true);
        $hasRollback = ! $this->isEmptyField($metaSpec['rollback_strategy'] ?? null);
        if ($isRisky && ! $hasRollback) {
            $reasons[] = 'risky_change_without_rollback';
        }

        $proceed = $reasons === [];

        return [
            'schema' => self::SCHEMA,
            'surface' => 'meta_sdd_gate',
            'proceed' => $proceed,
            'status' => $proceed ? 'meta_sdd_ready' : 'meta_sdd_blocked',
            'blocking_reasons' => $reasons,
            'is_risky' => $isRisky,
            'requires_rollback' => $isRisky,
            'path' => $minimal['qualifies'] ? 'minimal_meta_sdd' : 'full_meta_sdd',
            'evidence_required' => true,
            'meta_spec' => $spec,
            'prohibitions' => $prohibitions,
            'required_questions' => $questions,
            'minimal_exception' => $minimal,
        ];
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }

    /**
     * Whether a field counts as empty (missing). Null, empty string,
     * whitespace-only string and empty array all count as empty. The boolean
     * `false` is a real value (not empty) so a deliberately-false flag is kept.
     */
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
