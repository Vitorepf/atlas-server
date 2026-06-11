<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;
use App\Services\Ai\Aaeos\Support\AtlasAaeosValueNormalizer;

/**
 * Atlas SDD Spec Compiler And Critic.
 *
 * Pure, deterministic enforcement of the doc's distinctive contract: turn raw
 * intent into a fully-fielded operational spec, keep every assumption in an
 * explicit ledger (never hidden), attack the spec with a fixed catalog of
 * critic checks, and collapse the result into exactly ONE of the four
 * documented output states. This decider runs no LLM, mutates no code, calls no
 * provider and touches no database — it emits an auditable verdict that a
 * caller uses to admit a spec to planning, ask the user a short question, block
 * a policy-violating request, or downgrade it to a spike.
 *
 * Concrete rules grounded in the doc:
 *   - "Spec Compiler Output" → the twelve minimum fields. A spec that omits any
 *     of them is incomplete; the missing fields are reported, never invented.
 *   - "Assumption Ledger"    → "Assumptions are never hidden." Each ledger entry
 *     must carry id, text, confidence and evidence. An assumption is treated as
 *     blocking when it is flagged blocking OR its confidence is below the
 *     clarification threshold.
 *   - "Spec Critic Checks"   → the eleven named checks, each classified by the
 *     kind of failure it represents (policy / design conflict / overreach /
 *     missing context) so the output state can be derived deterministically.
 *   - "Output States"        → the closed set {ready_for_plan,
 *     needs_clarification, blocked_by_policy, spike_only} chosen by a fixed
 *     precedence: policy block first, then spike, then clarification, else ready.
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/spec-compiler-and-critic.md
 */
final class AtlasSpecCompilerAndCriticService
{
    /** Stable receipt schema id for the verdicts this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.sdd.spec_compiler_and_critic.v1';

    /**
     * "Spec Compiler Output" — the twelve minimum fields, in doc order.
     *
     * @var list<string>
     */
    public const COMPILER_FIELDS = [
        'raw_user_request',
        'interpreted_goal',
        'non_goals',
        'product_area',
        'business_actor_object_action',
        'requirements',
        'acceptance_criteria',
        'design_system_constraints',
        'security_privacy_constraints',
        'assumptions',
        'blocking_questions',
        'test_strategy',
    ];

    /**
     * "Output States" — the closed set, mapped to a fixed precedence rank. The
     * lower the rank, the stronger the state's claim on the final verdict.
     *
     * @var array<string,int>
     */
    public const OUTPUT_STATES = [
        'blocked_by_policy' => 0,
        'spike_only' => 1,
        'needs_clarification' => 2,
        'ready_for_plan' => 3,
    ];

    public const STATE_READY = 'ready_for_plan';
    public const STATE_CLARIFY = 'needs_clarification';
    public const STATE_BLOCKED = 'blocked_by_policy';
    public const STATE_SPIKE = 'spike_only';

    /**
     * "Spec Critic Checks" — the eleven named checks. Each maps to the failure
     * class that decides how a triggered check influences the output state:
     *   - policy   : a safety/design/architecture rule is violated -> blocked.
     *   - spike    : the request overreaches what a single governed change may
     *                do -> exploration allowed, implementation blocked.
     *   - context  : context/spec is missing or ambiguous -> clarification.
     *
     * @var array<string,string>
     */
    public const CRITIC_CHECKS = [
        'missing_target_file_or_screen' => 'context',
        'missing_business_object' => 'context',
        'design_system_conflict' => 'policy',
        'hardcoded_style_when_token_exists' => 'policy',
        'missing_state_loading_disabled_error_success' => 'context',
        'missing_auth_permission_rule' => 'policy',
        'api_backend_ambiguity' => 'context',
        'overengineering' => 'spike',
        'scope_creep' => 'spike',
        'missing_acceptance_criteria' => 'context',
        'missing_test_strategy' => 'context',
    ];

    /**
     * Confidence at or above this value is NOT blocking on its own; below it an
     * assumption forces clarification unless explicitly resolved. The doc's
     * ledger example carries confidence 0.91 with blocking:false — i.e. high
     * confidence is admissible — so the threshold sits below it.
     */
    public const CLARIFICATION_CONFIDENCE_THRESHOLD = 0.7;

    /**
     * "Spec Compiler Output" — validate that a compiled spec carries the twelve
     * minimum fields with non-empty content. Missing fields are reported; the
     * service never fabricates them.
     *
     * A field counts as "present" when it is a non-blank string OR a non-empty
     * array. (The doc allows a field like `non_goals` to be a list and
     * `interpreted_goal` to be prose.)
     *
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    public function compileSpec(array $spec): array
    {
        $present = [];
        $missing = [];

        foreach (self::COMPILER_FIELDS as $field) {
            if ($this->fieldPresent($spec, $field)) {
                $present[] = $field;
            } else {
                $missing[] = $field;
            }
        }

        $complete = $missing === [];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'check' => 'compiler_output',
            'complete' => $complete,
            'present_fields' => $present,
            'missing_fields' => $missing,
            'required_field_count' => count(self::COMPILER_FIELDS),
            'auditable' => true,
        ];
    }

    /**
     * "Assumption Ledger" — assess a single ledger entry. "Assumptions are never
     * hidden": a well-formed entry must carry id, text, confidence and evidence.
     * An assumption is blocking when it is explicitly flagged blocking OR its
     * confidence falls below the clarification threshold.
     *
     * @param array<string,mixed> $assumption
     *        id         : string
     *        text       : string
     *        confidence : float|int  in [0,1]
     *        evidence   : list<string>
     *        blocking   : bool
     * @return array<string,mixed>
     */
    public function assessAssumption(array $assumption): array
    {
        $id = AtlasAaeosValueNormalizer::trimmedString($assumption['id'] ?? null);
        $text = AtlasAaeosValueNormalizer::trimmedString($assumption['text'] ?? null);
        $confidence = $this->clampConfidence($assumption['confidence'] ?? null);
        $evidence = AtlasAaeosStringListNormalizer::trimmedStrings($assumption['evidence'] ?? null);
        $flaggedBlocking = ($assumption['blocking'] ?? false) === true;

        $schemaIssues = [];
        if ($id === '') {
            $schemaIssues[] = 'missing_id';
        }
        if ($text === '') {
            $schemaIssues[] = 'missing_text';
        }
        if (! is_float($confidence)) {
            $schemaIssues[] = 'missing_confidence';
        }
        if ($evidence === []) {
            // "Assumptions are never hidden" — an assumption with no evidence
            // trail cannot be silently trusted.
            $schemaIssues[] = 'missing_evidence';
        }

        $lowConfidence = is_float($confidence) && $confidence < self::CLARIFICATION_CONFIDENCE_THRESHOLD;
        $blocking = $flaggedBlocking || $lowConfidence;

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'check' => 'assumption_ledger_entry',
            'id' => $id,
            'well_formed' => $schemaIssues === [],
            'schema_issues' => $schemaIssues,
            'confidence' => is_float($confidence) ? $confidence : null,
            'low_confidence' => $lowConfidence,
            'blocking' => $blocking,
            'auditable' => true,
        ];
    }

    /**
     * Assess a whole assumption ledger. Returns the per-entry assessments plus
     * the aggregate signals the state resolver needs.
     *
     * @param list<array<string,mixed>> $assumptions
     * @return array<string,mixed>
     */
    public function assessLedger(array $assumptions): array
    {
        $entries = [];
        $blockingIds = [];
        $malformedIds = [];

        foreach (array_values($assumptions) as $position => $assumption) {
            $assessment = $this->assessAssumption((array) $assumption);
            $entries[] = $assessment;

            $ref = $assessment['id'] !== '' ? $assessment['id'] : "#{$position}";
            if ($assessment['blocking'] === true) {
                $blockingIds[] = $ref;
            }
            if ($assessment['well_formed'] === false) {
                $malformedIds[] = $ref;
            }
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'check' => 'assumption_ledger',
            'count' => count($entries),
            'entries' => $entries,
            'blocking_assumption_ids' => $blockingIds,
            'malformed_assumption_ids' => $malformedIds,
            'has_blocking_assumption' => $blockingIds !== [],
            'auditable' => true,
        ];
    }

    /**
     * "Spec Critic Checks" — run the eleven named checks against a compiled
     * spec. A check is "triggered" (a finding) when the doc's named defect is
     * present. Each triggered check is classified by its failure class so the
     * output state is derivable.
     *
     * Inputs (all booleans; any unknown defaults to "not detected"):
     *   missing_target_file_or_screen
     *   missing_business_object
     *   design_system_conflict
     *   hardcoded_style_when_token_exists
     *   missing_state_loading_disabled_error_success
     *   missing_auth_permission_rule
     *   api_backend_ambiguity
     *   overengineering
     *   scope_creep
     *   missing_acceptance_criteria
     *   missing_test_strategy
     *
     * @param array<string,mixed> $signals
     * @return array<string,mixed>
     */
    public function critique(array $signals): array
    {
        $findings = [];
        $byClass = ['policy' => [], 'spike' => [], 'context' => []];

        foreach (self::CRITIC_CHECKS as $check => $class) {
            if (($signals[$check] ?? false) === true) {
                $findings[] = ['check' => $check, 'class' => $class];
                $byClass[$class][] = $check;
            }
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'check' => 'spec_critic',
            'checks_run' => count(self::CRITIC_CHECKS),
            'findings' => $findings,
            'policy_findings' => $byClass['policy'],
            'spike_findings' => $byClass['spike'],
            'context_findings' => $byClass['context'],
            'clean' => $findings === [],
            'auditable' => true,
        ];
    }

    /**
     * "Output States" — collapse the compiler completeness, the critic findings
     * and the assumption ledger into exactly ONE state, by fixed precedence:
     *
     *   1. blocked_by_policy   — any policy-class critic finding (design/security
     *                            /architecture rule violated).
     *   2. spike_only          — no policy block, but an overreach finding
     *                            (overengineering / scope creep): exploration is
     *                            allowed, implementation blocked.
     *   3. needs_clarification — no policy block and no overreach, but the spec
     *                            is incomplete, a context-class check fired, or a
     *                            blocking assumption is open: a short question is
     *                            required.
     *   4. ready_for_plan      — none of the above: enough context, no blocking
     *                            ambiguity.
     *
     * @param array<string,mixed> $input
     *        compiler   : output of compileSpec()      (optional)
     *        critic     : output of critique()         (optional)
     *        ledger     : output of assessLedger()     (optional)
     * @return array<string,mixed>
     */
    public function resolveState(array $input): array
    {
        $compiler = (array) ($input['compiler'] ?? []);
        $critic = (array) ($input['critic'] ?? []);
        $ledger = (array) ($input['ledger'] ?? []);

        $policyFindings = AtlasAaeosStringListNormalizer::nonEmptyStrings($critic['policy_findings'] ?? []);
        $spikeFindings = AtlasAaeosStringListNormalizer::nonEmptyStrings($critic['spike_findings'] ?? []);
        $contextFindings = AtlasAaeosStringListNormalizer::nonEmptyStrings($critic['context_findings'] ?? []);

        $incomplete = array_key_exists('complete', $compiler)
            ? $compiler['complete'] !== true
            : false;
        $missingFields = AtlasAaeosStringListNormalizer::nonEmptyStrings($compiler['missing_fields'] ?? []);

        $blockingAssumptions = AtlasAaeosStringListNormalizer::nonEmptyStrings($ledger['blocking_assumption_ids'] ?? []);
        $hasBlockingAssumption = ($ledger['has_blocking_assumption'] ?? false) === true
            || $blockingAssumptions !== [];

        $reasons = [];

        if ($policyFindings !== []) {
            $state = self::STATE_BLOCKED;
            foreach ($policyFindings as $f) {
                $reasons[] = "policy:{$f}";
            }
        } elseif ($spikeFindings !== []) {
            $state = self::STATE_SPIKE;
            foreach ($spikeFindings as $f) {
                $reasons[] = "overreach:{$f}";
            }
        } elseif ($incomplete || $contextFindings !== [] || $hasBlockingAssumption) {
            $state = self::STATE_CLARIFY;
            foreach ($missingFields as $f) {
                $reasons[] = "missing_field:{$f}";
            }
            foreach ($contextFindings as $f) {
                $reasons[] = "ambiguity:{$f}";
            }
            foreach ($blockingAssumptions as $a) {
                $reasons[] = "blocking_assumption:{$a}";
            }
        } else {
            $state = self::STATE_READY;
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'check' => 'output_state',
            'state' => $state,
            'precedence_rank' => self::OUTPUT_STATES[$state],
            'reasons' => $reasons,
            'implementation_allowed' => $state === self::STATE_READY,
            'exploration_allowed' => $state === self::STATE_READY || $state === self::STATE_SPIKE,
            'auditable' => true,
        ];
    }

    /**
     * End-to-end convenience: compile, critique, assess the ledger and resolve
     * the state in one auditable envelope.
     *
     * @param array<string,mixed> $spec        the compiled spec fields
     * @param array<string,mixed> $criticSignals
     * @param list<array<string,mixed>> $assumptions
     * @return array<string,mixed>
     */
    public function evaluate(array $spec, array $criticSignals = [], array $assumptions = []): array
    {
        $compiler = $this->compileSpec($spec);
        $critic = $this->critique($criticSignals);
        $ledger = $this->assessLedger($assumptions);
        $state = $this->resolveState([
            'compiler' => $compiler,
            'critic' => $critic,
            'ledger' => $ledger,
        ]);

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'check' => 'evaluate',
            'compiler' => $compiler,
            'critic' => $critic,
            'ledger' => $ledger,
            'output' => $state,
            'auditable' => true,
        ];
    }

    /**
     * A field is "present" when the spec author gave a deliberate answer:
     *   - a non-blank string, OR
     *   - an array (even an empty one — an explicitly empty `non_goals` /
     *     `blocking_questions` list is a real answer, "there are none").
     * A missing key, a blank string or a null is an omission.
     *
     * @param array<string,mixed> $spec
     */
    private function fieldPresent(array $spec, string $field): bool
    {
        if (! array_key_exists($field, $spec)) {
            return false;
        }
        $value = $spec[$field];
        if (is_string($value)) {
            return trim($value) !== '';
        }

        return is_array($value);
    }

    /**
     * @return float|null
     */
    private function clampConfidence(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }
        $f = (float) $value;
        if ($f < 0.0) {
            return 0.0;
        }
        if ($f > 1.0) {
            return 1.0;
        }

        return $f;
    }

}
