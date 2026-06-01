<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Dev Efficient Programming Flow v1 · Parte 3 — pure, deterministic
 * decider for the normative slice covering sections 19–23 of the flow doc
 * (Verification & Repair, Forge Escalation, Evidence per Mode, Quality Build
 * Gates).
 *
 * This service does NOT execute anything: no provider call, no command, no
 * codebase mutation, no database. It encodes the documented contract as a closed
 * set of typed decisions so an agent (or any caller) can ask:
 *   - is a claimed completion_state a full success, and may `unverified` become
 *     `passed`? (§19.1 Completion States)
 *   - must this repair loop stop, and for which documented reason? (§19.2)
 *   - which verification command profile applies, and does the verification gate
 *     pass for this run? (§19.3 Verification Command Profiles)
 *   - what is the Forge escalation verdict for these signals/score? (§20)
 *   - what is the minimum evidence for this mode? (§21)
 *   - which quality build gate blocks, and on what evidence? (§23)
 *
 * Documented rules this code actually enforces (one-to-one with the doc):
 *   - §19.1: completion_state is a closed set
 *     {passed, needs_review, failed, blocked, escalate_forge, no_patch_needed};
 *     only `passed` is a full success; `no_patch_needed` depends (full success
 *     iff enough refs); the rest are NOT full success. The hard invariant
 *     enforced in completion_state_gate: `unverified` NEVER becomes `passed`.
 *   - §19.2: the repair loop must stop when any of the six documented conditions
 *     holds (same failure_signature twice; diff growing without need; new scope
 *     appears; failing test needs architecture; required context exceeds budget;
 *     risk becomes R4/R5). When it stops it escalates instead of looping.
 *   - §19.3: three pre-wired verification command profiles (locked 2026-05-16) —
 *     `php_laravel`, `ts_react`, `generic_no_test`, each with its lint + test
 *     command. The verification_gate FAILS if the profile requires a test and
 *     neither a test result nor a `no_test_reason` is present.
 *   - §20: EscalationDecision — hard signals escalate before patch; medium
 *     signals raise an `obra_candidate`; escalation signal score >=7 recommends
 *     `forge_obra` but NEVER auto-creates an Obra (human_action_required), unless
 *     the surface is already inside an active Obra with contracted Forge
 *     execution.
 *   - §21: minimum evidence per mode (read-only, plan-only, patch, repair,
 *     frontend).
 *   - §23: nine quality build gates, every one of which BLOCKS, each with the
 *     evidence it asserts.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-03.md
 */
final class AtlasDevEfficientProgrammingFlowV1Part03Service
{
    /** Stable decision kind this decider emits. */
    public const DECISION_KIND = 'atlas_dev.efficient_programming_flow.v1.part_03';

    /** Verification command profiles locked at this date (Decisao Fechada #4). */
    public const PROFILES_LOCKED_AT = '2026-05-16';

    /**
     * §19.1 — completion states (closed set) and whether each is a FULL success.
     * `no_patch_needed` is success-conditional ("depende"): full success only
     * when there are enough refs to justify no patch.
     *
     * @var array<string, 'yes'|'no'|'depends'>
     */
    public const COMPLETION_STATES = [
        'passed' => 'yes',
        'needs_review' => 'no',
        'failed' => 'no',
        'blocked' => 'no',
        'escalate_forge' => 'no',
        'no_patch_needed' => 'depends',
    ];

    /**
     * §19.2 — the six documented conditions under which the repair loop must
     * stop and escalate instead of attempting another patch. Order is the
     * doc's bullet order.
     *
     * @var list<string>
     */
    public const REPAIR_STOP_CONDITIONS = [
        'same_failure_signature_twice',
        'diff_growing_without_need',
        'new_scope_appeared',
        'failing_test_needs_architecture',
        'required_context_exceeds_budget',
        'risk_became_r4_or_r5',
    ];

    /**
     * §20 — escalation signal score at or above which `forge_obra` is RECOMMENDED
     * (but never auto-created without a human).
     */
    public const FORGE_OBRA_SCORE = 7;

    /** §20 — escalation signal score at or above which an `obra_candidate` preview is generated. */
    public const OBRA_CANDIDATE_SCORE = 4;

    /**
     * §19.1 — classify a claimed completion state.
     *
     * Enforces the hard `completion_state_gate` invariant: a run whose verified
     * state is still `unverified` can NEVER be reported as `passed`; the best it
     * may claim is `needs_review`. `no_patch_needed` is a full success only when
     * the read-only/review answer carried enough refs.
     *
     * @param  string  $claimedState     the completion_state the run wants to report
     * @param  string  $verifiedState    the actual verified state ('unverified' until gates prove otherwise)
     * @param  bool  $hasSufficientRefs  for no_patch_needed: were enough refs provided?
     * @return array{
     *   claimed_state:string, known:bool, full_success:bool, allowed:bool,
     *   effective_state:string, reason:string
     * }
     */
    public function classifyCompletion(string $claimedState, string $verifiedState = 'unverified', bool $hasSufficientRefs = false): array
    {
        $known = array_key_exists($claimedState, self::COMPLETION_STATES);

        // Hard invariant first: unverified can never be reported as passed.
        if ($claimedState === 'passed' && $verifiedState === 'unverified') {
            return [
                'claimed_state' => $claimedState,
                'known' => $known,
                'full_success' => false,
                'allowed' => false,
                'effective_state' => 'needs_review',
                'reason' => 'unverified_never_becomes_passed',
            ];
        }

        if (! $known) {
            return [
                'claimed_state' => $claimedState,
                'known' => false,
                'full_success' => false,
                'allowed' => false,
                'effective_state' => 'needs_review',
                'reason' => 'unknown_completion_state',
            ];
        }

        $successFlag = self::COMPLETION_STATES[$claimedState];
        $fullSuccess = $successFlag === 'yes'
            || ($successFlag === 'depends' && $hasSufficientRefs);

        $reason = match ($successFlag) {
            'yes' => 'passed_is_full_success',
            'depends' => $hasSufficientRefs
                ? 'no_patch_needed_with_sufficient_refs'
                : 'no_patch_needed_lacks_sufficient_refs',
            default => 'state_is_not_full_success',
        };

        return [
            'claimed_state' => $claimedState,
            'known' => true,
            'full_success' => $fullSuccess,
            'allowed' => true,
            'effective_state' => $claimedState,
            'reason' => $reason,
        ];
    }

    /**
     * §19.2 — given the observed repair conditions, decide whether to continue
     * the loop or stop+escalate, and list every documented stop reason that fired.
     *
     * @param  array<string,mixed>  $signals
     *         same_failure_signature_twice    : bool
     *         diff_growing_without_need        : bool
     *         new_scope_appeared               : bool
     *         failing_test_needs_architecture  : bool
     *         required_context_exceeds_budget  : bool
     *         risk_level                       : string  (R4/R5 trips risk_became_r4_or_r5)
     * @return array{action:string, must_stop:bool, stop_reasons:list<string>, reason:string}
     */
    public function repairLoopDecision(array $signals): array
    {
        $risk = strtoupper(is_string($signals['risk_level'] ?? null) ? trim((string) $signals['risk_level']) : '');
        $riskTripped = in_array($risk, ['R4', 'R5'], true);

        $fired = [];
        if (($signals['same_failure_signature_twice'] ?? false) === true) {
            $fired[] = 'same_failure_signature_twice';
        }
        if (($signals['diff_growing_without_need'] ?? false) === true) {
            $fired[] = 'diff_growing_without_need';
        }
        if (($signals['new_scope_appeared'] ?? false) === true) {
            $fired[] = 'new_scope_appeared';
        }
        if (($signals['failing_test_needs_architecture'] ?? false) === true) {
            $fired[] = 'failing_test_needs_architecture';
        }
        if (($signals['required_context_exceeds_budget'] ?? false) === true) {
            $fired[] = 'required_context_exceeds_budget';
        }
        if ($riskTripped) {
            $fired[] = 'risk_became_r4_or_r5';
        }

        $mustStop = $fired !== [];

        return [
            'action' => $mustStop ? 'stop_and_escalate' : 'continue_repair',
            'must_stop' => $mustStop,
            'stop_reasons' => $fired,
            'reason' => $mustStop
                ? 'one_or_more_documented_stop_conditions_fired'
                : 'no_documented_stop_condition_fired',
        ];
    }

    /**
     * §19.3 — the three pre-wired verification command profiles.
     *
     * @return array<string, array{lint:list<string>, test:string, requires_test:bool, applies_to:list<string>}>
     */
    public function verificationProfiles(): array
    {
        return [
            'php_laravel' => [
                'lint' => ['composer lint', 'vendor/bin/pint --test'],
                'test' => 'composer test -- --filter=<class>',
                'requires_test' => true,
                'applies_to' => ['atlas-server'],
            ],
            'ts_react' => [
                'lint' => ['eslint <files>', 'tsc -b'],
                'test' => 'pnpm test --filter=<glob>',
                'requires_test' => true,
                'applies_to' => ['atlas-desktop', 'atlas-app'],
            ],
            'generic_no_test' => [
                'lint' => ['git diff --stat'],
                'test' => '',
                'requires_test' => false,
                'applies_to' => ['docs', 'scripts', 'configs'],
            ],
        ];
    }

    /**
     * §19.3 — lookup a single profile by id (or null if unknown).
     *
     * @return array{lint:list<string>, test:string, requires_test:bool, applies_to:list<string>}|null
     */
    public function verificationProfile(string $profile): ?array
    {
        return $this->verificationProfiles()[$profile] ?? null;
    }

    /**
     * §19.3 — the verification_gate. It FAILS when the declared profile requires
     * a test and neither a test result nor a non-empty `no_test_reason` appears.
     * An unknown profile fails closed. `generic_no_test` does not require a test,
     * so a run with no test but a reason (or none required) passes.
     *
     * @param  string  $profile      profile id declared by the LightTaskContract
     * @param  bool  $testRan        did a test actually run for this gate?
     * @param  string|null  $noTestReason  explicit reason a test was not run
     * @return array{profile:string, known:bool, passes:bool, requires_test:bool, reason:string}
     */
    public function verificationGate(string $profile, bool $testRan, ?string $noTestReason = null): array
    {
        $spec = $this->verificationProfile($profile);
        if ($spec === null) {
            return [
                'profile' => $profile,
                'known' => false,
                'passes' => false,
                'requires_test' => true,
                'reason' => 'unknown_profile_fails_closed',
            ];
        }

        $requiresTest = $spec['requires_test'];
        $hasReason = is_string($noTestReason) && trim($noTestReason) !== '';

        if (! $requiresTest) {
            return [
                'profile' => $profile,
                'known' => true,
                'passes' => true,
                'requires_test' => false,
                'reason' => 'profile_does_not_require_test',
            ];
        }

        if ($testRan) {
            return [
                'profile' => $profile,
                'known' => true,
                'passes' => true,
                'requires_test' => true,
                'reason' => 'test_ran',
            ];
        }

        if ($hasReason) {
            return [
                'profile' => $profile,
                'known' => true,
                'passes' => true,
                'requires_test' => true,
                'reason' => 'no_test_but_explicit_no_test_reason',
            ];
        }

        return [
            'profile' => $profile,
            'known' => true,
            'passes' => false,
            'requires_test' => true,
            'reason' => 'profile_requires_test_but_no_test_and_no_reason',
        ];
    }

    /**
     * §20 — EscalationDecision. Aggregates the documented escalation signals and
     * resolves the target. Hard signals escalate BEFORE any patch. Score >=7
     * recommends `forge_obra` but NEVER auto-creates an Obra: human_action_required
     * stays true unless the surface is already inside an active Obra with
     * contracted Forge execution.
     *
     * @param  array<string,mixed>  $input
     *         obra_declared              : bool   operator declared Obra / long auditable work
     *         hard_signal                : bool   security/auth/billing/PII/migration/>5-6 files/3+ layers/>40k/>=24 msgs/breadth>=6
     *         score                      : int    escalation signal score
     *         inside_active_obra         : bool   surface already inside an active Obra
     *         forge_execution_contracted : bool   operator authorized Forge execution by contract
     * @return array{
     *   target:string, score:int, reasons:list<string>,
     *   obra_candidate:bool, human_action_required:bool
     * }
     */
    public function escalationDecision(array $input): array
    {
        $obraDeclared = ($input['obra_declared'] ?? false) === true;
        $hardSignal = ($input['hard_signal'] ?? false) === true;
        $score = max(0, (int) ($input['score'] ?? 0));
        $insideActiveObra = ($input['inside_active_obra'] ?? false) === true;
        $forgeContracted = ($input['forge_execution_contracted'] ?? false) === true;

        $reasons = [];
        $obraCandidate = false;

        // §20.1 — primary trigger: declared Obra / clearly long auditable work.
        if ($obraDeclared) {
            $reasons[] = 'obra_declared_primary_trigger';
            // Auto-execution only when already inside an active Obra AND contracted.
            $humanRequired = ! ($insideActiveObra && $forgeContracted);

            return [
                'target' => 'forge_obra',
                'score' => $score,
                'reasons' => $reasons,
                'obra_candidate' => true,
                'human_action_required' => $humanRequired,
            ];
        }

        // §20.2 — secondary trigger: hard complexity signal escalates before patch.
        if ($hardSignal) {
            $reasons[] = 'hard_complexity_signal_escalate_before_patch';
        }

        // Medium signals: score >= 4 generates an obra_candidate preview.
        if ($score >= self::OBRA_CANDIDATE_SCORE) {
            $obraCandidate = true;
            $reasons[] = 'score_at_or_above_obra_candidate_threshold';
        }

        // Score >= 7 recommends forge_obra (still needs a human to create the Obra).
        if ($score >= self::FORGE_OBRA_SCORE) {
            $reasons[] = 'score_at_or_above_forge_obra_threshold';
            $humanRequired = ! ($insideActiveObra && $forgeContracted);

            return [
                'target' => 'forge_obra',
                'score' => $score,
                'reasons' => $reasons,
                'obra_candidate' => true,
                'human_action_required' => $humanRequired,
            ];
        }

        if ($hardSignal) {
            return [
                'target' => 'escalate_before_patch',
                'score' => $score,
                'reasons' => $reasons,
                'obra_candidate' => $obraCandidate,
                'human_action_required' => true,
            ];
        }

        if ($obraCandidate) {
            return [
                'target' => 'obra_candidate_preview',
                'score' => $score,
                'reasons' => $reasons,
                'obra_candidate' => true,
                'human_action_required' => true,
            ];
        }

        return [
            'target' => 'atlas_dev_fast_path',
            'score' => $score,
            'reasons' => ['below_all_escalation_thresholds'],
            'obra_candidate' => false,
            'human_action_required' => false,
        ];
    }

    /**
     * §21 — minimum evidence required per execution mode.
     *
     * @return array<string, list<string>>
     */
    public function evidenceMinimaByMode(): array
    {
        return [
            'read_only' => ['docs_files_read', 'response', 'confidence_limits', 'zero_write'],
            'plan_only' => ['compact_sdd', 'mini_programming_spec', 'light_task_contract', 'reason_not_executing'],
            'patch' => ['diff_hash', 'changed_files', 'scope_guard', 'test_or_command', 'output_hash'],
            'repair' => ['failure_receipt', 'failure_capsule', 'small_patch', 'gate_rerun'],
            'frontend' => ['patch', 'screenshot_or_visual_verification_or_needs_review'],
        ];
    }

    /**
     * §21 — does a mode's evidence bundle satisfy the documented minimum? A
     * missing required evidence key fails the check and is reported.
     *
     * @param  string  $mode
     * @param  list<string>  $providedEvidence
     * @return array{mode:string, known:bool, satisfied:bool, required:list<string>, missing:list<string>}
     */
    public function evidenceCheck(string $mode, array $providedEvidence): array
    {
        $minima = $this->evidenceMinimaByMode();
        if (! array_key_exists($mode, $minima)) {
            return [
                'mode' => $mode,
                'known' => false,
                'satisfied' => false,
                'required' => [],
                'missing' => [],
            ];
        }

        $required = $minima[$mode];
        $provided = [];
        foreach ($providedEvidence as $ref) {
            if (is_string($ref) && trim($ref) !== '') {
                $provided[] = trim($ref);
            }
        }

        $missing = array_values(array_diff($required, $provided));

        return [
            'mode' => $mode,
            'known' => true,
            'satisfied' => $missing === [],
            'required' => $required,
            'missing' => $missing,
        ];
    }

    /**
     * §23 — quality build gates. Every documented gate blocks; each carries the
     * evidence it asserts. These are engineering gates for the runtime build, not
     * a measurement battery.
     *
     * @return array<string, array{blocks:bool, evidence:string}>
     */
    public function qualityBuildGates(): array
    {
        return [
            'contract_schema_tests' => ['blocks' => true, 'evidence' => 'schemas serialize, validate and reject missing fields'],
            'prompt_projection_tests' => ['blocks' => true, 'evidence' => 'prompt contains objective, contract, context, scope, tests and stop conditions'],
            'context_selection_tests' => ['blocks' => true, 'evidence' => 'correct tiers, existing paths, honest missing refs'],
            'scope_guard_tests' => ['blocks' => true, 'evidence' => 'out-of-scope diffs fail'],
            'verification_receipt_tests' => ['blocks' => true, 'evidence' => 'receipts persist status, gates, tests and evidence'],
            'repair_capsule_tests' => ['blocks' => true, 'evidence' => 'repair uses real error and does not widen scope'],
            'escalation_policy_tests' => ['blocks' => true, 'evidence' => 'R4/R5 do not patch in Dev'],
            'no_measurement_leakage_tests' => ['blocks' => true, 'evidence' => 'no flow calls the measurement-team arms/battery'],
            'no_template_pollution_tests' => ['blocks' => true, 'evidence' => 'main doc has no duplicated canonical template before the numbered sections'],
        ];
    }

    /**
     * Stable manifest of the slice this decider governs (for the command/probe).
     *
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return [
            'decision_kind' => self::DECISION_KIND,
            'doc' => 'docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-03.md',
            'sections' => [
                '19_1_completion_states',
                '19_2_repair_loop',
                '19_3_verification_command_profiles',
                '20_forge_escalation',
                '21_evidence_minima_by_mode',
                '23_quality_build_gates',
            ],
            'completion_states' => array_keys(self::COMPLETION_STATES),
            'repair_stop_conditions' => self::REPAIR_STOP_CONDITIONS,
            'verification_profiles' => array_keys($this->verificationProfiles()),
            'profiles_locked_at' => self::PROFILES_LOCKED_AT,
            'forge_obra_score' => self::FORGE_OBRA_SCORE,
            'obra_candidate_score' => self::OBRA_CANDIDATE_SCORE,
        ];
    }
}
