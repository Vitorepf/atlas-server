<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Dev Efficient Programming Flow Runbook v1 · Parte 2 — pure, deterministic
 * decider for the implementation-slice contract documented in sections
 * 6.2 (PRs Sugeridos), 6.3 (DoD da Fatia 0), 6.4 (Fixtures) and 7.1 (Fatia 1).
 *
 * This service does NOT execute anything and never touches a provider, the
 * filesystem or a DB. It encodes the normative invariants the runbook embeds in
 * its PR plan as a closed set of typed verdicts so an agent (or any caller) can
 * ask, without re-reading prose:
 *   - is this CompactSdd risk/mode pairing legal? (PR 0.2 invariant 1)
 *   - is this `surface_id` one of the 4 canonical Operation envelopes? (PR 0.2 DoD)
 *   - may a VerificationReceipt declare completion.status=passed? (PR 0.4 inv. 1+2)
 *   - given a FailureCapsule, is the next decision retry or escalate? (PR 0.4)
 *   - does this DTO hash correctly ignore its own `<entity>_hash` field? (PR 0.2 test 5)
 *   - is Fatia 0 actually green, i.e. do all 7 DoD conditions hold? (6.3)
 *
 * Documented rules this code actually enforces (one-to-one with the doc):
 *   - PR 0.2 invariant 1 (CompactSdd, the doc's worked example, lines 216-226):
 *     a CompactSdd at risk_level R4 or R5 MUST have mode=escalate_preview. Any
 *     other mode (patch, fast_path, ...) is invalid with the exact documented
 *     violation string "R4|R5 requires mode=escalate_preview".
 *   - PR 0.2 DoD: OperationEnvelopeValidator covers exactly the 4 canonical
 *     surface_id values: atlas_desktop_ai, atlas_cli_dev, atlas_app,
 *     atlas_api_interaction. Anything else is a non-canonical surface.
 *   - PR 0.4 VerificationReceipt invariant 1: completion.status=passed requires
 *     ALL required gates passed AND scope_guard passed AND (tests ran OR a
 *     no_patch_reason is given). Invariant 2: passed + non-empty honesty_flags
 *     is invalid (you cannot be "passed" while flagging honesty problems).
 *   - PR 0.4 FailureCapsule: decision=retry is only legal while
 *     attempt_index < max_attempts (from the task_contract in context); once the
 *     attempt cap is reached the only legal decision is escalate. The
 *     failure_signature is deterministic for a given (gate, reason, signal) tuple.
 *   - PR 0.2 test 5 (hash ignores own hash field): two otherwise-identical DTO
 *     payloads that differ ONLY in their `<entity>_hash` field recompute to the
 *     SAME canonical hash; the self-hash field is stripped before hashing.
 *   - 6.3 DoD da Fatia 0: the slice is green only when all 7 conditions hold
 *     (tests green, lint clean, phpstan clean, coverage >= 95% in Schemas/,
 *     index-code recognises the DTOs, provider-safe memory updated if needed,
 *     and NO real provider / no forbidden-vocabulary usage). A single failing
 *     condition keeps the slice not-green and names the offenders.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-02.md
 */
final class AtlasDevEfficientProgrammingFlowRunbookV1Part02Service
{
    /** Stable decision kind this decider emits. */
    public const DECISION_KIND = 'atlas_dev.efficient_programming_flow.runbook.v1.part_02';

    /**
     * PR 0.2 DoD — the 4 canonical surface_id values an OperationEnvelope may carry.
     * Closed set; OperationEnvelopeValidator must cover exactly these.
     */
    public const CANONICAL_SURFACE_IDS = [
        'atlas_desktop_ai',
        'atlas_cli_dev',
        'atlas_app',
        'atlas_api_interaction',
    ];

    /** PR 0.2 invariant 1 — risk levels that force mode=escalate_preview. */
    public const ESCALATE_PREVIEW_RISK_LEVELS = ['R4', 'R5'];

    /** PR 0.2 invariant 1 — the mode forced for the high-risk levels above. */
    public const FORCED_HIGH_RISK_MODE = 'escalate_preview';

    /** PR 0.2 invariant 1 — exact violation string the validator must emit. */
    public const VIOLATION_R4_R5_MODE = 'R4|R5 requires mode=escalate_preview';

    /** 6.3 — the 7 conditions that, all-true, make Fatia 0 green. Closed set, ordered. */
    public const FATIA0_DOD_CONDITIONS = [
        'tests_green',          // 1. composer test --filter=AtlasDev/Schemas -> 0 failures
        'lint_clean',           // 2. pint --test on AtlasDev/ -> 0 issues
        'phpstan_clean',        // 3. no new phpstan/larastan errors in Schemas/
        'coverage_min_95',      // 4. >= 95% coverage in Schemas/
        'index_code_ok',        // 5. index-code recognises the new DTOs, does not break
        'provider_safe_memory_ok', // 6. provider-safe memory updated if needed
        'no_real_provider',     // 7. no claude_cli / no real provider / no forbidden vocabulary
    ];

    /**
     * PR 0.2 invariant 1 (CompactSdd) — is a (risk_level, mode) pairing legal?
     * R4 and R5 force mode=escalate_preview; any other mode at those levels is
     * invalid with the documented violation string.
     *
     * @return array{
     *   risk_level:string, mode:string, valid:bool,
     *   forced_mode:?string, violations:list<string>, reason:string
     * }
     */
    public function evaluateCompactSddRiskMode(string $riskLevel, string $mode): array
    {
        $highRisk = in_array($riskLevel, self::ESCALATE_PREVIEW_RISK_LEVELS, true);

        if (! $highRisk) {
            return [
                'risk_level' => $riskLevel,
                'mode' => $mode,
                'valid' => true,
                'forced_mode' => null,
                'violations' => [],
                'reason' => 'risk_level_below_r4_no_mode_constraint',
            ];
        }

        $valid = $mode === self::FORCED_HIGH_RISK_MODE;

        return [
            'risk_level' => $riskLevel,
            'mode' => $mode,
            'valid' => $valid,
            'forced_mode' => self::FORCED_HIGH_RISK_MODE,
            'violations' => $valid ? [] : [self::VIOLATION_R4_R5_MODE],
            'reason' => $valid ? 'high_risk_mode_is_escalate_preview' : 'high_risk_requires_escalate_preview',
        ];
    }

    /**
     * PR 0.2 DoD — is this surface_id one of the 4 canonical Operation envelopes?
     *
     * @return array{surface_id:string,canonical:bool,allowed:list<string>,reason:string}
     */
    public function evaluateSurfaceId(string $surfaceId): array
    {
        $canonical = in_array($surfaceId, self::CANONICAL_SURFACE_IDS, true);

        return [
            'surface_id' => $surfaceId,
            'canonical' => $canonical,
            'allowed' => self::CANONICAL_SURFACE_IDS,
            'reason' => $canonical ? 'canonical_surface' : 'non_canonical_surface',
        ];
    }

    /**
     * PR 0.4 VerificationReceipt invariants 1 & 2 — may completion.status be `passed`?
     *
     * Invariant 1: passed requires all required gates passed AND scope_guard passed
     * AND (tests ran OR a no_patch_reason is present).
     * Invariant 2: passed + non-empty honesty_flags is invalid.
     *
     * @param  list<string>  $requiredGates  gate names that must all be `passed`
     * @param  array<string,string>  $gateStatuses  gate name => status
     * @param  string  $scopeGuardStatus  scope_guard status (`passed` required)
     * @param  bool  $testsRan  whether the verification ran tests
     * @param  ?string  $noPatchReason  reason there is no patch (read-only/no_patch_needed)
     * @param  list<string>  $honestyFlags  any honesty flags raised
     * @return array{
     *   status:string, valid:bool, violations:list<string>,
     *   missing_gates:list<string>, scope_guard_passed:bool,
     *   tests_or_no_patch:bool, honesty_clean:bool, reason:string
     * }
     */
    public function evaluateVerificationCompletion(
        array $requiredGates,
        array $gateStatuses,
        string $scopeGuardStatus,
        bool $testsRan,
        ?string $noPatchReason = null,
        array $honestyFlags = []
    ): array {
        $missing = [];
        foreach ($requiredGates as $gate) {
            if (($gateStatuses[$gate] ?? null) !== 'passed') {
                $missing[] = $gate;
            }
        }

        $scopeGuardPassed = $scopeGuardStatus === 'passed';
        $hasNoPatchReason = $noPatchReason !== null && $noPatchReason !== '';
        $testsOrNoPatch = $testsRan || $hasNoPatchReason;
        $honestyClean = $honestyFlags === [];

        $violations = [];
        if ($missing !== []) {
            $violations[] = 'required_gate_not_passed';
        }
        if (! $scopeGuardPassed) {
            $violations[] = 'scope_guard_not_passed';
        }
        if (! $testsOrNoPatch) {
            $violations[] = 'no_tests_and_no_no_patch_reason';
        }
        if (! $honestyClean) {
            // Invariant 2: passed cannot coexist with honesty flags.
            $violations[] = 'passed_with_honesty_flag';
        }

        $valid = $violations === [];

        return [
            'status' => $valid ? 'passed' : 'needs_review',
            'valid' => $valid,
            'violations' => $violations,
            'missing_gates' => $missing,
            'scope_guard_passed' => $scopeGuardPassed,
            'tests_or_no_patch' => $testsOrNoPatch,
            'honesty_clean' => $honestyClean,
            'reason' => $valid
                ? 'all_gates_passed_scope_clean_evidence_present_no_honesty_flag'
                : 'completion_cannot_be_passed',
        ];
    }

    /**
     * PR 0.4 FailureCapsule — given the current attempt and the task_contract cap,
     * decide the next failure decision. retry only while attempt_index < max_attempts;
     * once the cap is reached the only legal move is escalate.
     *
     * @return array{
     *   attempt_index:int, max_attempts:int, retry_allowed:bool,
     *   decision:string, attempts_remaining:int, reason:string
     * }
     */
    public function failureCapsuleDecision(int $attemptIndex, int $maxAttempts): array
    {
        $retryAllowed = $attemptIndex < $maxAttempts;
        $remaining = max(0, $maxAttempts - $attemptIndex);

        return [
            'attempt_index' => $attemptIndex,
            'max_attempts' => $maxAttempts,
            'retry_allowed' => $retryAllowed,
            'decision' => $retryAllowed ? 'retry' : 'escalate',
            'attempts_remaining' => $remaining,
            'reason' => $retryAllowed
                ? 'attempt_index_below_max_attempts'
                : 'attempt_cap_reached_escalate',
        ];
    }

    /**
     * PR 0.4 FailureCapsule — deterministic failure_signature for a failure tuple.
     * Same (gate, reason, signal) always yields the same signature; the signature
     * never depends on volatile data (timestamps, attempt counters).
     */
    public function failureSignature(string $gate, string $reason, string $signal): string
    {
        $canonical = strtolower(trim($gate)).'|'.strtolower(trim($reason)).'|'.strtolower(trim($signal));

        return 'fc_'.substr(hash('sha256', $canonical), 0, 32);
    }

    /**
     * PR 0.2 test 5 — canonical hash that IGNORES the DTO's own `<entity>_hash`
     * field. Two payloads that differ only in that self-hash field hash equal.
     * The payload is normalised (self-hash stripped, keys sorted) before hashing.
     *
     * @param  array<string,mixed>  $payload  the DTO as an associative array
     * @param  string  $selfHashField  the field to strip (e.g. 'sdd_hash')
     */
    public function canonicalHash(array $payload, string $selfHashField): string
    {
        unset($payload[$selfHashField]);
        $this->ksortRecursive($payload);

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'h_'.substr(hash('sha256', (string) $json), 0, 40);
    }

    /**
     * PR 0.2 test 5 — convenience: do two payloads recompute to the same hash
     * once their self-hash field is ignored?
     *
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    public function hashesMatchIgnoringSelfHash(array $a, array $b, string $selfHashField): bool
    {
        return $this->canonicalHash($a, $selfHashField) === $this->canonicalHash($b, $selfHashField);
    }

    /**
     * 6.3 — is Fatia 0 green? Green only when all 7 DoD conditions are true.
     * Names any unmet condition; never reports green while one is missing.
     *
     * @param  array<string,bool>  $conditions  condition name => satisfied
     * @return array{
     *   green:bool, required:list<string>, satisfied:list<string>,
     *   unmet:list<string>, reason:string
     * }
     */
    public function evaluateFatia0Dod(array $conditions): array
    {
        $satisfied = [];
        $unmet = [];

        foreach (self::FATIA0_DOD_CONDITIONS as $condition) {
            if (($conditions[$condition] ?? false) === true) {
                $satisfied[] = $condition;
            } else {
                $unmet[] = $condition;
            }
        }

        $green = $unmet === [];

        return [
            'green' => $green,
            'required' => self::FATIA0_DOD_CONDITIONS,
            'satisfied' => $satisfied,
            'unmet' => $unmet,
            'reason' => $green ? 'all_seven_dod_conditions_met' : 'dod_conditions_unmet',
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
            'doc' => 'docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-02.md',
            'sections' => [
                '6_2_prs_sugeridos',
                '6_3_dod_fatia_0',
                '6_4_fixtures',
                '7_1_fatia_1_objetivo',
            ],
            'canonical_surface_ids' => self::CANONICAL_SURFACE_IDS,
            'escalate_preview_risk_levels' => self::ESCALATE_PREVIEW_RISK_LEVELS,
            'forced_high_risk_mode' => self::FORCED_HIGH_RISK_MODE,
            'fatia0_dod_conditions' => self::FATIA0_DOD_CONDITIONS,
        ];
    }

    /**
     * Recursively ksort an array in place so canonical hashing is order-stable.
     *
     * @param  array<array-key,mixed>  $arr
     */
    private function ksortRecursive(array &$arr): void
    {
        foreach ($arr as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
        unset($value);
        ksort($arr);
    }
}
