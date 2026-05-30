<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-808 / AP-810 slice LHL-06 — Chaos & Fault Injection certification.
 *
 * Read-only. Provider-free. Deterministic. Fixture-driven.
 *
 * This service NEVER runs the loop, never invokes a provider, never merges,
 * never deletes a branch/worktree, never touches git. It DIAGNOSES: for each
 * injected fault it asserts the loop's only acceptable safe outcome and refuses
 * to certify if any fault could have produced a false success.
 *
 * Honesty rules (operator does not accept false claims):
 *   - every injected fault MUST resolve to one safe outcome in
 *     {preflight_block, valid_blocked_cycle, bounded_retry, cleanup_with_receipt,
 *      critical_violation_stop} — never a successful/merged cycle;
 *   - a blocked cycle is never success; a sandbox-only commit is never a merge;
 *     plan-only Forge is never implementation; recovery/filler is never progress;
 *   - any observed outcome that claims success/merge/implementation under a fault
 *     is recorded as a false_success and FAILS the certification;
 *   - chaos certification gates the 24h run: status=fail blocks promotion.
 *
 * Composition is via INPUT SEAMS only. A caller may pass observed per-fault
 * outcomes (e.g. replayed from a chaos test fixture / flight recorder) via
 * `$input['observations']`; absent observations, the diagnostic default models
 * the contractually-mandated safe outcome for each fault (observed == expected).
 *
 * Contract: docs/ap/AP-808-loop-assurance-kernel-and-chaos-certification-contract.md (Part 3)
 *           docs/ap/AP-810 slice LHL-06.
 */
final class LoopChaosCertificationService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.loop_chaos_certification.v1';

    public const STATUS_PASS = 'pass';

    public const STATUS_FAIL = 'fail';

    /** The default certification profile that must cover the full canonical fault set. */
    public const PROFILE_PRE_24H = 'pre_24h';

    // ---- canonical safe outcomes (the ONLY acceptable resolutions of a fault) ----
    public const OUTCOME_PREFLIGHT_BLOCK = 'preflight_block';

    public const OUTCOME_VALID_BLOCKED_CYCLE = 'valid_blocked_cycle';

    public const OUTCOME_BOUNDED_RETRY = 'bounded_retry';

    public const OUTCOME_CLEANUP_WITH_RECEIPT = 'cleanup_with_receipt';

    public const OUTCOME_CRITICAL_VIOLATION_STOP = 'critical_violation_stop';

    /** Every safe outcome a fault may legitimately resolve to. */
    private const SAFE_OUTCOMES = [
        self::OUTCOME_PREFLIGHT_BLOCK,
        self::OUTCOME_VALID_BLOCKED_CYCLE,
        self::OUTCOME_BOUNDED_RETRY,
        self::OUTCOME_CLEANUP_WITH_RECEIPT,
        self::OUTCOME_CRITICAL_VIOLATION_STOP,
    ];

    /**
     * Observed outcomes that are NEVER acceptable under any injected fault: a fault
     * must never be observed to yield a real successful/merged/implemented cycle.
     * Anything here is a false_success and fails the certification immediately.
     */
    private const FALSE_SUCCESS_OUTCOMES = [
        'success',
        'successful_cycle',
        'valid_success',
        'merged',
        'merge_performed',
        'implemented',
        'completed',
        'recovery_progress',
        'filler_progress',
    ];

    /**
     * Canonical fault set (AP-808 Part 3) => the single safe outcome the loop must
     * produce. Each fault maps to exactly one mandated outcome; the certification
     * verifies the observed outcome matches (or, absent observation, models it).
     *
     * @var array<string,string>
     */
    private const CANONICAL_FAULTS = [
        'provider_timeout' => self::OUTCOME_BOUNDED_RETRY,
        'provider_killed_mid_cycle' => self::OUTCOME_CLEANUP_WITH_RECEIPT,
        'stale_lock_dead_pid' => self::OUTCOME_BOUNDED_RETRY,
        'live_lock' => self::OUTCOME_PREFLIGHT_BLOCK,
        'dirty_worktree' => self::OUTCOME_PREFLIGHT_BLOCK,
        'orphan_sandbox_branch' => self::OUTCOME_CLEANUP_WITH_RECEIPT,
        'lane_head_missing' => self::OUTCOME_PREFLIGHT_BLOCK,
        'lane_head_behind_expected_packet' => self::OUTCOME_PREFLIGHT_BLOCK,
        'test_command_failure' => self::OUTCOME_VALID_BLOCKED_CYCLE,
        'judge_repair_required' => self::OUTCOME_VALID_BLOCKED_CYCLE,
        'merge_conflict' => self::OUTCOME_VALID_BLOCKED_CYCLE,
        'receipt_write_failure' => self::OUTCOME_CRITICAL_VIOLATION_STOP,
        'disk_budget_exceeded' => self::OUTCOME_CRITICAL_VIOLATION_STOP,
        'max_blocked_in_row_exceeded' => self::OUTCOME_CRITICAL_VIOLATION_STOP,
        'kill_switch_during_execution' => self::OUTCOME_CLEANUP_WITH_RECEIPT,
        'corrupted_ledger_tail' => self::OUTCOME_CRITICAL_VIOLATION_STOP,
        'duplicate_packet_selected_after_block' => self::OUTCOME_PREFLIGHT_BLOCK,
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function certify(array $input = []): array
    {
        $area = trim((string) ($input['area'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';
        $profile = trim((string) ($input['profile'] ?? self::PROFILE_PRE_24H)) ?: self::PROFILE_PRE_24H;

        $blockers = [];
        $warnings = [];

        // 1) Resolve the fault set under test. Default = the full canonical set
        //    (required for the pre_24h profile). A caller may narrow it via
        //    `faults` (list of fault ids) — but a profile of pre_24h that does not
        //    cover the full canonical set is itself a blocker (incomplete cert).
        $requestedFaults = $this->resolveRequestedFaults($input);
        $observations = $this->resolveObservations($input);
        $providerTimeoutRecoveryPath = $this->providerTimeoutRecoveryPath(
            $this->providerTimeoutRecoveryPathInput($input, $observations),
        );

        $results = [];
        $falseSuccess = [];
        foreach ($requestedFaults as $faultId) {
            $expected = self::CANONICAL_FAULTS[$faultId] ?? null;
            $known = $expected !== null;

            if ($faultId === ProviderTimeoutRecoveryPathContract::FAULT_ID) {
                $faultResult = $this->evaluateProviderTimeoutFault(
                    $providerTimeoutRecoveryPath,
                    $observations,
                    $expected,
                    $known,
                    $blockers,
                    $falseSuccess,
                );
                $results[] = $faultResult;

                continue;
            }

            // Observed outcome: from the seam when supplied, else model the mandated
            // safe outcome (the contract baseline). An unknown fault has no mandated
            // outcome and cannot be certified safe.
            $observed = array_key_exists($faultId, $observations)
                ? (string) $observations[$faultId]
                : ($known ? $expected : 'unknown_fault');

            $results[] = $this->evaluateFaultRow(
                $faultId,
                $observed,
                $expected,
                $known,
                $blockers,
                $falseSuccess,
            );
        }

        // 2) Profile coverage: pre_24h MUST cover the full canonical fault set.
        $coveredIds = array_map(static fn (array $r): string => (string) $r['fault'], $results);
        $missingForProfile = $this->missingFaultsForProfile($profile, $coveredIds);
        if ($missingForProfile !== []) {
            $blockers[] = 'profile_fault_coverage_incomplete';
            $warnings[] = 'missing_faults_for_profile:'.implode(',', $missingForProfile);
        }

        $allOk = $results !== [] && ! in_array(false, array_map(static fn (array $r): bool => (bool) $r['ok'], $results), true);
        $status = ($allOk && $falseSuccess === [] && $blockers === [])
            ? self::STATUS_PASS
            : self::STATUS_FAIL;

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-808',
            'slice_id' => 'LHL-06',
            'status' => $status,
            'certification_id' => 'chaos_'.substr(MissionCanonicalHash::sha256([$area, $focus, $profile, $coveredIds]), 0, 16),
            'area' => $area,
            'focus' => $focus,
            'profile' => $profile,
            'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'faults' => $results,
            'fault_count' => count($results),
            'handled_safely_count' => count(array_filter($results, static fn (array $r): bool => (bool) $r['ok'])),
            'false_success' => array_values(array_unique($falseSuccess)),
            'safe_outcomes' => self::SAFE_OUTCOMES,
            'profile_coverage' => [
                'profile' => $profile,
                'requires_full_canonical_set' => $profile === self::PROFILE_PRE_24H,
                'covered_faults' => array_values($coveredIds),
                'missing_faults' => array_values($missingForProfile),
                'complete' => $missingForProfile === [],
            ],
            'gates_24h' => $status === self::STATUS_PASS,
            'next_action' => $status === self::STATUS_PASS ? 'continue' : 'stop_chaos_certification_failed',
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'claim_policy' => [
                'read_only' => true,
                'runs_loop' => false,
                'runs_provider' => false,
                'runs_merge' => false,
                'deletes_branches' => false,
                'injects_real_faults' => false,
                'fixture_only' => true,
                'false_success_never_passes' => true,
                'blocked_never_dressed_as_ready' => true,
            ],
            'provider_timeout_recovery_path' => $providerTimeoutRecoveryPath,
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->withoutVolatile($payload));

        return $payload;
    }

    /**
     * The canonical fault set this slice certifies (read-only catalog).
     *
     * @return array<string,string>
     */
    public function canonicalFaults(): array
    {
        return self::CANONICAL_FAULTS;
    }

    /**
     * Provider timeout recovery path entry (step 3/3): validates the input seam and
     * maps concrete inputs through {@see ProviderTimeoutRecoveryPathContract}.
     * {@see self::certify()} consumes this evaluation for the provider_timeout fault.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function providerTimeoutRecoveryPath(array $input = []): array
    {
        $this->validateProviderTimeoutRecoveryPathInput($input);

        if ($input === []) {
            return ProviderTimeoutRecoveryPathContract::defaults()->toArray();
        }

        return ProviderTimeoutRecoveryPathContract::fromArray($input)->toArray();
    }

    // ---------- internals ----------

    /**
     * @param  array<string,mixed>  $input
     * @return list<string>  ordered, de-duplicated fault ids under test
     */
    private function resolveRequestedFaults(array $input): array
    {
        if (isset($input['faults']) && is_array($input['faults'])) {
            $requested = [];
            foreach ($input['faults'] as $fault) {
                // Allow either a bare id or a {fault: id} shape.
                if (is_string($fault)) {
                    $id = trim($fault);
                } elseif (is_array($fault) && isset($fault['fault']) && is_string($fault['fault'])) {
                    $id = trim($fault['fault']);
                } else {
                    continue;
                }
                if ($id !== '') {
                    $requested[$id] = true;
                }
            }
            if ($requested !== []) {
                return array_keys($requested);
            }
        }

        // Default: the full canonical set, in canonical order.
        return array_keys(self::CANONICAL_FAULTS);
    }

    /**
     * Observed per-fault outcomes from the seam. Accepts either a map
     * `fault_id => observed_outcome` or the `faults` list-of-objects shape
     * `[{fault, observed_outcome}]` so a flight-recorder/chaos fixture composes directly.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,string>
     */
    private function resolveObservations(array $input): array
    {
        $observations = [];

        if (isset($input['observations']) && is_array($input['observations'])) {
            foreach ($input['observations'] as $faultId => $observed) {
                if (is_string($faultId) && is_string($observed) && trim($faultId) !== '') {
                    $observations[trim($faultId)] = trim($observed);
                }
            }
        }

        if (isset($input['faults']) && is_array($input['faults'])) {
            foreach ($input['faults'] as $fault) {
                if (is_array($fault)
                    && isset($fault['fault'])
                    && is_string($fault['fault'])
                    && isset($fault['observed_outcome'])
                    && is_string($fault['observed_outcome'])) {
                    $observations[trim($fault['fault'])] = trim($fault['observed_outcome']);
                }
            }
        }

        return $observations;
    }

    private function isFalseSuccess(string $observed): bool
    {
        return in_array($observed, self::FALSE_SUCCESS_OUTCOMES, true);
    }

    /**
     * @param  array<string,string>  $observations
     * @return array<string,mixed>
     */
    private function evaluateProviderTimeoutFault(
        array $providerTimeoutRecoveryPath,
        array $observations,
        ?string $expected,
        bool $known,
        array &$blockers,
        array &$falseSuccess,
    ): array {
        $pathInputs = $providerTimeoutRecoveryPath['inputs'];
        $pathOutputs = $providerTimeoutRecoveryPath['outputs'];

        if ($pathInputs['observed_outcome'] !== null) {
            $observed = (string) $pathInputs['observed_outcome'];
            $matchesExpected = $known && (bool) $pathOutputs['observed_outcome_matches_mandate'];
        } else {
            $observed = array_key_exists(ProviderTimeoutRecoveryPathContract::FAULT_ID, $observations)
                ? (string) $observations[ProviderTimeoutRecoveryPathContract::FAULT_ID]
                : ($known ? (string) $expected : 'unknown_fault');
            $matchesExpected = $known && $observed === $expected;
        }

        $isFalseSuccess = $this->isFalseSuccess($observed) || (bool) $pathOutputs['same_stuck_selection'];
        $safeOutcome = in_array($observed, self::SAFE_OUTCOMES, true);
        $ok = $known && $safeOutcome && $matchesExpected && ! $isFalseSuccess;

        if ($pathOutputs['same_stuck_selection']) {
            $falseSuccess[] = ProviderTimeoutRecoveryPathContract::FAULT_ID;
            $blockers[] = 'provider_timeout_same_finding_reselected';
        } elseif ($this->providerTimeoutRecoveryPathAffectsDecision($providerTimeoutRecoveryPath)
            && ! (bool) $pathOutputs['recovery_path_valid']) {
            $blockers[] = 'provider_timeout_recovery_path_invalid';
            $ok = false;
        } elseif ($isFalseSuccess) {
            $falseSuccess[] = ProviderTimeoutRecoveryPathContract::FAULT_ID;
            $blockers[] = 'fault_produced_false_success:'.ProviderTimeoutRecoveryPathContract::FAULT_ID;
        } elseif (! $known) {
            $blockers[] = 'unknown_fault_cannot_certify:'.ProviderTimeoutRecoveryPathContract::FAULT_ID;
        } elseif (! $safeOutcome) {
            $blockers[] = 'fault_resolved_to_unsafe_outcome:'.ProviderTimeoutRecoveryPathContract::FAULT_ID;
        } elseif (! $matchesExpected) {
            $blockers[] = 'fault_outcome_mismatch:'.ProviderTimeoutRecoveryPathContract::FAULT_ID;
        }

        return [
            'fault' => ProviderTimeoutRecoveryPathContract::FAULT_ID,
            'expected_outcome' => $known ? $expected : null,
            'observed_outcome' => $observed,
            'is_false_success' => $isFalseSuccess,
            'ok' => $ok,
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @param  list<string>  $falseSuccess
     * @return array<string,mixed>
     */
    private function evaluateFaultRow(
        string $faultId,
        string $observed,
        ?string $expected,
        bool $known,
        array &$blockers,
        array &$falseSuccess,
    ): array {
        $isFalseSuccess = $this->isFalseSuccess($observed);
        $safeOutcome = in_array($observed, self::SAFE_OUTCOMES, true);
        $matchesExpected = $known && $observed === $expected;
        $ok = $known && $safeOutcome && $matchesExpected && ! $isFalseSuccess;

        if ($isFalseSuccess) {
            $falseSuccess[] = $faultId;
            $blockers[] = 'fault_produced_false_success:'.$faultId;
        } elseif (! $known) {
            $blockers[] = 'unknown_fault_cannot_certify:'.$faultId;
        } elseif (! $safeOutcome) {
            $blockers[] = 'fault_resolved_to_unsafe_outcome:'.$faultId;
        } elseif (! $matchesExpected) {
            $blockers[] = 'fault_outcome_mismatch:'.$faultId;
        }

        return [
            'fault' => $faultId,
            'expected_outcome' => $known ? $expected : null,
            'observed_outcome' => $observed,
            'is_false_success' => $isFalseSuccess,
            'ok' => $ok,
        ];
    }

    /**
     * @param  array<string,string>  $observations
     * @return array<string,mixed>
     */
    private function providerTimeoutRecoveryPathInput(array $input, array $observations): array
    {
        $pathInput = [
            'area_id' => trim((string) ($input['area'] ?? 'agentic_engineering_os')),
            'focus' => trim((string) ($input['focus'] ?? 'dev_forge')),
        ];

        $nested = $input['provider_timeout_recovery_path'] ?? null;
        if (is_array($nested)) {
            $pathInput = array_merge($pathInput, $nested);
        }

        if (! array_key_exists('observed_outcome', $pathInput)
            && array_key_exists(ProviderTimeoutRecoveryPathContract::FAULT_ID, $observations)) {
            $pathInput['observed_outcome'] = $observations[ProviderTimeoutRecoveryPathContract::FAULT_ID];
        }

        return $pathInput;
    }

    /**
     * @param  array<string,mixed>  $providerTimeoutRecoveryPath
     */
    private function providerTimeoutRecoveryPathAffectsDecision(array $providerTimeoutRecoveryPath): bool
    {
        $inputs = $providerTimeoutRecoveryPath['inputs'] ?? [];

        return ($inputs['blocker'] ?? null) !== null
            || ($inputs['same_finding_reselected'] ?? null) !== null
            || (($inputs['finding_key'] ?? '') !== '' && (int) ($inputs['cycle_index'] ?? 0) > 0);
    }

    /**
     * @param  list<string>  $covered
     * @return list<string>  canonical faults missing for the given profile
     */
    private function missingFaultsForProfile(string $profile, array $covered): array
    {
        if ($profile !== self::PROFILE_PRE_24H) {
            return [];
        }

        $missing = [];
        foreach (array_keys(self::CANONICAL_FAULTS) as $faultId) {
            if (! in_array($faultId, $covered, true)) {
                $missing[] = $faultId;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withoutVolatile(array $payload): array
    {
        unset($payload['checked_at'], $payload['report_hash']);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function validateProviderTimeoutRecoveryPathInput(array $input): void
    {
        $allowedKeys = [
            'area_id',
            'focus',
            'finding_key',
            'cycle_index',
            'observed_outcome',
            'blocker',
            'same_finding_reselected',
        ];

        foreach ($input as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                throw new \InvalidArgumentException('Unknown provider timeout recovery path input key: '.$key);
            }

            if ($key === 'cycle_index' && ! is_int($value) && ! (is_string($value) && is_numeric($value))) {
                throw new \InvalidArgumentException('cycle_index must be numeric.');
            }

            if (in_array($key, ['area_id', 'focus', 'finding_key', 'observed_outcome', 'blocker'], true)
                && ! is_string($value)
                && $value !== null) {
                throw new \InvalidArgumentException($key.' must be a string or null.');
            }

            if ($key === 'same_finding_reselected' && ! is_bool($value) && $value !== null) {
                throw new \InvalidArgumentException('same_finding_reselected must be a boolean or null.');
            }
        }
    }
}
