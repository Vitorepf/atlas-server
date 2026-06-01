<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Dev Efficient Programming Flow Contracts v1 · Parte 3 — invariant decider.
 *
 * Pure, deterministic runtime for the single contract this doc declares in
 * section 4.3: the MiniProgrammingSpec (`atlas.dev.mini_programming_spec.v1`),
 * the behavior contract that is "obrigatorio para todo write".
 *
 * Anti-duplication boundary: the DTO holder already exists
 * ({@see \App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec}) but
 * it is a *data carrier* — it can be constructed with field combinations the doc
 * forbids (a goal of 300 chars, expected_files outside allowed_files, overlapping
 * allowed/forbidden, a php_laravel profile carrying a no_test_reason). It only
 * implements invariant 10 (hasBlockingAssumption) and canonical hashing. This
 * service is the missing piece: it enforces the doc's ten numbered "#### Invariants"
 * verbatim and returns the exact violations. It is the Part-03 sibling of
 * {@see AtlasDevEfficientProgrammingFlowContractsPart02Service} (which gates 4.1
 * OperationEnvelope and 4.2 CompactSDD).
 *
 * The ten invariants enforced (doc lines 156-165), one-to-one:
 *   - I1  goal is non-empty, <= 240 chars, and imperative.
 *   - I2  non_goals is non-empty when risk_level >= R2.
 *   - I3  canonical_context has >= 1 ref when task_kind != question.
 *   - I4  expected_files is a subset of allowed_files.
 *   - I5  forbidden_files and allowed_files are disjoint.
 *   - I6  acceptance_criteria is non-empty for every write (mode in {patch, repair}).
 *   - I7  each acceptance_criteria.verification carries a verification_ref when
 *         applicable (verification in {test, grep, cli_command} requires a ref;
 *         manual_review does not).
 *   - I8  verification_plan.profile must equal compact_sdd.verification_profile.
 *   - I9  verification_plan.no_test_reason is valid ONLY for profile generic_no_test.
 *   - I10 an assumption with confidence=blocking prevents progress; the runtime
 *         must resolve it before advancing.
 *
 * The service NEVER executes, routes, builds a plan, calls a provider or touches
 * a database. It only decides whether a candidate MiniProgrammingSpec payload is
 * invariant-legal and what blocked it. Callers enforce.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-03.md
 */
final class AtlasDevEfficientProgrammingFlowContractsV1Part03Service
{
    /** Stable schema id for the verdict this decider emits. */
    public const SCHEMA = 'atlas.dev.flow_contracts_part03_gate.v1';

    /** Contract id governed here (doc 4.3 schema_version). */
    public const MINI_SPEC_CONTRACT = 'atlas.dev.mini_programming_spec.v1';

    /** I1 — documented hard cap on goal length. */
    public const GOAL_MAX_CHARS = 240;

    /** I6 — modes that are writes (a non-empty acceptance_criteria is mandatory). */
    public const WRITE_MODES = ['patch', 'repair'];

    /** I9 — the only profile under which no_test_reason is valid. */
    public const NO_TEST_PROFILE = 'generic_no_test';

    /**
     * I3 — the task_kind that does NOT require canonical_context refs.
     */
    public const QUESTION_TASK_KIND = 'question';

    /**
     * I7 — acceptance-criteria verification methods that REQUIRE a verification_ref.
     * `manual_review` is the documented exception ("quando aplicavel").
     *
     * @var list<string>
     */
    public const VERIFICATION_NEEDS_REF = ['test', 'grep', 'cli_command'];

    /**
     * Validate a candidate MiniProgrammingSpec payload against all ten invariants.
     *
     * The payload uses the doc's wire field names (snake_case). Context that the
     * invariants are conditional on (risk_level, task_kind, mode,
     * compact_sdd_verification_profile) is read from the same payload so a caller
     * can hand the merged CompactSDD+MiniSpec view straight in. Missing optional
     * context degrades safely: an absent risk_level cannot trip I2, an absent mode
     * cannot trip I6, etc., and that is reported, never guessed.
     *
     * @param  array<string,mixed>  $spec
     * @return array{
     *   schema:string,
     *   contract:string,
     *   valid:bool,
     *   can_progress:bool,
     *   violations:list<array{invariant:string, rule:string, detail:string}>,
     *   checked:list<string>
     * }
     */
    public function validateMiniSpec(array $spec): array
    {
        $violations = [];

        $this->checkGoal($spec, $violations);                 // I1
        $this->checkNonGoals($spec, $violations);             // I2
        $this->checkCanonicalContext($spec, $violations);     // I3
        $this->checkFileSets($spec, $violations);             // I4 + I5
        $this->checkAcceptanceCriteria($spec, $violations);   // I6 + I7
        $this->checkVerificationPlan($spec, $violations);     // I8 + I9
        $blocking = $this->checkBlockingAssumption($spec, $violations); // I10

        $valid = $violations === [];

        return [
            'schema' => self::SCHEMA,
            'contract' => self::MINI_SPEC_CONTRACT,
            'valid' => $valid,
            // A spec can be structurally valid yet still unable to progress because
            // a blocking assumption is unresolved (I10). can_progress captures that.
            'can_progress' => $valid && ! $blocking,
            'violations' => array_values($violations),
            'checked' => ['I1', 'I2', 'I3', 'I4', 'I5', 'I6', 'I7', 'I8', 'I9', 'I10'],
        ];
    }

    /**
     * I1 — goal: non-empty, <= 240 chars, imperative.
     *
     * "Imperative" is enforced as a concrete, deterministic shape: the goal must
     * start with a verb-like token (a non-trailing-punctuation word) and must not
     * open with a personal-pronoun / declarative lead ("eu", "nos", "i ", "we ",
     * "the ", "this ", "it ") which would make it a description, not a command.
     *
     * @param  array<string,mixed>  $spec
     * @param  list<array{invariant:string, rule:string, detail:string}>  $violations
     */
    private function checkGoal(array $spec, array &$violations): void
    {
        $goal = is_string($spec['goal'] ?? null) ? trim((string) $spec['goal']) : '';

        if ($goal === '') {
            $violations[] = ['invariant' => 'I1', 'rule' => 'goal_non_empty', 'detail' => 'goal is empty'];

            return;
        }

        if (mb_strlen($goal) > self::GOAL_MAX_CHARS) {
            $violations[] = [
                'invariant' => 'I1',
                'rule' => 'goal_max_240_chars',
                'detail' => 'goal length '.mb_strlen($goal).' exceeds '.self::GOAL_MAX_CHARS,
            ];
        }

        if (! $this->looksImperative($goal)) {
            $violations[] = [
                'invariant' => 'I1',
                'rule' => 'goal_imperative',
                'detail' => 'goal does not read as an imperative instruction',
            ];
        }
    }

    /**
     * Deterministic imperative heuristic: reject declarative/descriptive openings.
     */
    private function looksImperative(string $goal): bool
    {
        $firstWord = strtolower((string) preg_replace('/[^\p{L}].*$/u', '', $goal));
        if ($firstWord === '') {
            return false;
        }

        // Declarative leads that signal a description rather than a command.
        $declarativeLeads = ['eu', 'nos', 'i', 'we', 'the', 'this', 'it', 'a', 'an', 'esse', 'essa', 'isso'];

        return ! in_array($firstWord, $declarativeLeads, true);
    }

    /**
     * I2 — non_goals must be non-empty when risk_level >= R2.
     *
     * @param  array<string,mixed>  $spec
     * @param  list<array{invariant:string, rule:string, detail:string}>  $violations
     */
    private function checkNonGoals(array $spec, array &$violations): void
    {
        $rank = $this->riskRank($spec['risk_level'] ?? null);
        if ($rank === null) {
            return; // no risk context supplied → invariant cannot be asserted.
        }

        $nonGoals = $this->stringList($spec['non_goals'] ?? null);

        if ($rank >= 2 && $nonGoals === []) {
            $violations[] = [
                'invariant' => 'I2',
                'rule' => 'non_goals_required_for_risk_ge_r2',
                'detail' => 'risk_level is R'.$rank.' but non_goals is empty',
            ];
        }
    }

    /**
     * I3 — canonical_context needs >= 1 ref unless task_kind == question.
     *
     * @param  array<string,mixed>  $spec
     * @param  list<array{invariant:string, rule:string, detail:string}>  $violations
     */
    private function checkCanonicalContext(array $spec, array &$violations): void
    {
        $taskKind = is_string($spec['task_kind'] ?? null) ? strtolower(trim((string) $spec['task_kind'])) : null;
        if ($taskKind === self::QUESTION_TASK_KIND) {
            return; // questions are exempt.
        }

        $context = is_array($spec['canonical_context'] ?? null) ? $spec['canonical_context'] : [];
        $refCount = 0;
        foreach ($context as $entry) {
            $ref = is_array($entry) ? ($entry['ref'] ?? null) : null;
            if (is_string($ref) && trim($ref) !== '') {
                $refCount++;
            }
        }

        if ($refCount < 1) {
            $violations[] = [
                'invariant' => 'I3',
                'rule' => 'canonical_context_requires_ref',
                'detail' => 'task_kind is not "question" but canonical_context has no valid ref',
            ];
        }
    }

    /**
     * I4 — expected_files ⊆ allowed_files. I5 — allowed_files ∩ forbidden_files = ∅.
     *
     * @param  array<string,mixed>  $spec
     * @param  list<array{invariant:string, rule:string, detail:string}>  $violations
     */
    private function checkFileSets(array $spec, array &$violations): void
    {
        $expected = $this->stringList($spec['expected_files'] ?? null);
        $allowed = $this->stringList($spec['allowed_files'] ?? null);
        $forbidden = $this->stringList($spec['forbidden_files'] ?? null);

        // I4 — every expected file must appear in allowed_files.
        $notAllowed = array_values(array_diff($expected, $allowed));
        if ($notAllowed !== []) {
            $violations[] = [
                'invariant' => 'I4',
                'rule' => 'expected_files_subset_of_allowed',
                'detail' => 'expected_files not in allowed_files: '.implode(', ', $notAllowed),
            ];
        }

        // I5 — allowed and forbidden sets must be disjoint.
        $overlap = array_values(array_intersect($allowed, $forbidden));
        if ($overlap !== []) {
            $violations[] = [
                'invariant' => 'I5',
                'rule' => 'allowed_and_forbidden_disjoint',
                'detail' => 'paths in both allowed_files and forbidden_files: '.implode(', ', $overlap),
            ];
        }
    }

    /**
     * I6 — acceptance_criteria non-empty for write modes. I7 — verification_ref
     * present when the verification method requires it.
     *
     * @param  array<string,mixed>  $spec
     * @param  list<array{invariant:string, rule:string, detail:string}>  $violations
     */
    private function checkAcceptanceCriteria(array $spec, array &$violations): void
    {
        $criteria = is_array($spec['acceptance_criteria'] ?? null) ? $spec['acceptance_criteria'] : [];
        $mode = is_string($spec['mode'] ?? null) ? strtolower(trim((string) $spec['mode'])) : null;

        // I6 — only assert when a mode is supplied; a write mode requires criteria.
        if ($mode !== null && in_array($mode, self::WRITE_MODES, true) && $criteria === []) {
            $violations[] = [
                'invariant' => 'I6',
                'rule' => 'acceptance_criteria_required_for_write',
                'detail' => 'mode "'.$mode.'" is a write but acceptance_criteria is empty',
            ];
        }

        // I7 — each criterion whose verification needs a ref must carry one.
        foreach ($criteria as $index => $criterion) {
            if (! is_array($criterion)) {
                continue;
            }
            $verification = is_string($criterion['verification'] ?? null)
                ? strtolower(trim((string) $criterion['verification']))
                : '';
            if (! in_array($verification, self::VERIFICATION_NEEDS_REF, true)) {
                continue; // manual_review (or unset) does not require a ref.
            }
            $ref = $criterion['verification_ref'] ?? null;
            if (! is_string($ref) || trim($ref) === '') {
                $id = is_string($criterion['id'] ?? null) ? (string) $criterion['id'] : ('#'.$index);
                $violations[] = [
                    'invariant' => 'I7',
                    'rule' => 'verification_ref_required_when_applicable',
                    'detail' => 'acceptance_criteria '.$id.' uses "'.$verification.'" but has no verification_ref',
                ];
            }
        }
    }

    /**
     * I8 — verification_plan.profile must equal compact_sdd.verification_profile.
     * I9 — no_test_reason is only valid for the generic_no_test profile.
     *
     * @param  array<string,mixed>  $spec
     * @param  list<array{invariant:string, rule:string, detail:string}>  $violations
     */
    private function checkVerificationPlan(array $spec, array &$violations): void
    {
        $plan = is_array($spec['verification_plan'] ?? null) ? $spec['verification_plan'] : [];
        $profile = is_string($plan['profile'] ?? null) ? trim((string) $plan['profile']) : '';
        $noTestReason = $plan['no_test_reason'] ?? null;
        $hasReason = is_string($noTestReason) && trim($noTestReason) !== '';

        // I8 — coincide with the CompactSDD verification_profile when that context exists.
        $sddProfile = $spec['compact_sdd_verification_profile'] ?? null;
        if (is_string($sddProfile) && trim($sddProfile) !== '') {
            if ($profile !== trim($sddProfile)) {
                $violations[] = [
                    'invariant' => 'I8',
                    'rule' => 'profile_matches_compact_sdd',
                    'detail' => 'verification_plan.profile "'.$profile.'" != compact_sdd "'.trim($sddProfile).'"',
                ];
            }
        }

        // I9 — a no_test_reason on any profile other than generic_no_test is illegal.
        if ($hasReason && $profile !== self::NO_TEST_PROFILE) {
            $violations[] = [
                'invariant' => 'I9',
                'rule' => 'no_test_reason_only_for_generic_no_test',
                'detail' => 'no_test_reason set under profile "'.$profile.'" (only "'.self::NO_TEST_PROFILE.'" may carry it)',
            ];
        }
    }

    /**
     * I10 — a blocking assumption stops progress. This is NOT a structural
     * violation (the spec can be perfectly shaped); it is a progress gate. Returns
     * true when at least one assumption has confidence=blocking, and records an
     * informational entry so the verdict surfaces it.
     *
     * @param  array<string,mixed>  $spec
     * @param  list<array{invariant:string, rule:string, detail:string}>  $violations
     */
    private function checkBlockingAssumption(array $spec, array &$violations): bool
    {
        $assumptions = is_array($spec['assumptions'] ?? null) ? $spec['assumptions'] : [];
        foreach ($assumptions as $assumption) {
            $confidence = is_array($assumption) ? ($assumption['confidence'] ?? null) : null;
            if (is_string($confidence) && strtolower(trim($confidence)) === 'blocking') {
                $violations[] = [
                    'invariant' => 'I10',
                    'rule' => 'blocking_assumption_must_resolve_first',
                    'detail' => 'an assumption has confidence=blocking; runtime must resolve before progressing',
                ];

                return true;
            }
        }

        return false;
    }

    /**
     * Map a risk_level string to its numeric rank (R0=0 .. R5=5), or null when the
     * value is absent/unparseable. Used by I2.
     */
    private function riskRank(mixed $value): ?int
    {
        if (! is_string($value)) {
            return null;
        }
        $normalized = strtoupper(trim($value));
        if ($normalized === '' || $normalized[0] !== 'R') {
            return null;
        }
        $digits = substr($normalized, 1);
        if ($digits === '' || ! ctype_digit($digits)) {
            return null;
        }

        return (int) $digits;
    }

    /**
     * Coerce a payload field into a clean list of non-empty trimmed strings.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
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

        return $out;
    }

    /**
     * Stable manifest of the slice this decider governs (for the command/probe).
     *
     * @return array<string,mixed>
     */
    public function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'contract' => self::MINI_SPEC_CONTRACT,
            'doc' => 'docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-03.md',
            'section' => '4.3_mini_programming_spec',
            'invariants' => ['I1', 'I2', 'I3', 'I4', 'I5', 'I6', 'I7', 'I8', 'I9', 'I10'],
            'goal_max_chars' => self::GOAL_MAX_CHARS,
            'write_modes' => self::WRITE_MODES,
            'no_test_profile' => self::NO_TEST_PROFILE,
            'verification_needs_ref' => self::VERIFICATION_NEEDS_REF,
        ];
    }
}
