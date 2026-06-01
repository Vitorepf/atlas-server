<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Self-Construction OS - Completion Roadmap — runtime.
 *
 * Turns the honest, phased completion roadmap doc into deterministic, pure
 * decision logic. The doc deliberately does NOT declare Self-Construction OS
 * complete; it lays out 6 sequential phases with entry/exit criteria, a
 * current maturity estimate and hard prohibitions. This service enforces the
 * doc's contract instead of restating it:
 *
 *  - The phase ladder (section 1) is strictly sequential:
 *      1 Contract Complete -> 2 Certification Complete -> 3 Dry-Run Pilot
 *      Complete -> 4 Runtime Pilot Complete -> 5 Multi-Agent Execution
 *      Complete -> 6 Self-Programming Ready (future, not current).
 *  - "Skipping a phase is forbidden" (section 1, section 11): advancement is
 *    only ever to the immediate next phase, and only when every prior phase is
 *    complete.
 *  - "Returning to an earlier phase after regression is mandatory" (section 1):
 *    a regression signal forces the current phase back to the regressed phase.
 *  - Hard prohibitions on phase exit (section 11): a phase may not exit without
 *    aligned docs + code + tests + evidence + drift checks, may not exit while
 *    runtime_safety_all_false is false, and may not exit over a missing release
 *    dossier reference. Any of these blocks the exit.
 *  - Honest maturity estimate (section 9): the per-phase posture at this doc's
 *    publication, with runtime_safety_all_false = true (provider dispatch
 *    disabled, real runtime does not exist).
 *  - Phase 6 is explicitly "future, not a current target" (section 7): its exit
 *    shape is deliberately undefined, so this service refuses to treat it as an
 *    eligible advancement target.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-completion-roadmap-v1.md
 */
final class AtlasSelfConstructionOsCompletionRoadmapService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.completion_roadmap.v1';

    /**
     * The 6 phases in their fixed, sequential order (section 1). The order index
     * is canonical: advancement may only ever target order N+1, never a skip.
     *
     * @var list<array{order:int,key:string,name:string,posture:string,current_target:bool}>
     */
    public const PHASES = [
        ['order' => 1, 'key' => 'contract_complete', 'name' => 'Contract Complete', 'posture' => 'in_progress', 'current_target' => true],
        ['order' => 2, 'key' => 'certification_complete', 'name' => 'Certification Complete', 'posture' => 'in_progress', 'current_target' => true],
        ['order' => 3, 'key' => 'dry_run_pilot_complete', 'name' => 'Dry-Run Pilot Complete', 'posture' => 'early', 'current_target' => true],
        ['order' => 4, 'key' => 'runtime_pilot_complete', 'name' => 'Runtime Pilot Complete', 'posture' => 'not_started', 'current_target' => true],
        ['order' => 5, 'key' => 'multi_agent_execution_complete', 'name' => 'Multi-Agent Execution Complete', 'posture' => 'not_started', 'current_target' => true],
        // Phase 6 is explicitly future and NOT a current target (section 7).
        ['order' => 6, 'key' => 'self_programming_ready', 'name' => 'Self-Programming Ready', 'posture' => 'future', 'current_target' => false],
    ];

    /**
     * The exit-gate facts every phase must satisfy to be allowed to exit
     * (section 11 Hard prohibitions). Keyed by the boolean fact the caller must
     * provide; value is the documented prohibition that fires when it is false.
     *
     * @var array<string,string>
     */
    public const EXIT_REQUIREMENTS = [
        'docs_aligned' => 'no_phase_complete_without_aligned_docs',
        'code_aligned' => 'no_phase_complete_without_aligned_code',
        'tests_aligned' => 'no_phase_complete_without_aligned_tests',
        'evidence_aligned' => 'no_phase_complete_without_aligned_evidence',
        'drift_checks_aligned' => 'no_phase_complete_without_drift_checks',
        'release_dossier_ref_present' => 'no_phase_exit_over_missing_release_dossier_reference',
    ];

    /**
     * Resolve a phase by its order index (1..6).
     *
     * @return array{order:int,key:string,name:string,posture:string,current_target:bool}|null
     */
    private function phaseByOrder(int $order): ?array
    {
        foreach (self::PHASES as $phase) {
            if ($phase['order'] === $order) {
                return $phase;
            }
        }

        return null;
    }

    /**
     * Resolve a phase by its key.
     *
     * @return array{order:int,key:string,name:string,posture:string,current_target:bool}|null
     */
    private function phaseByKey(string $key): ?array
    {
        $needle = strtolower(trim($key));
        foreach (self::PHASES as $phase) {
            if ($phase['key'] === $needle) {
                return $phase;
            }
        }

        return null;
    }

    /**
     * Evaluate whether a given phase is allowed to EXIT (be declared complete),
     * enforcing section 11 hard prohibitions and the runtime_safety_all_false
     * floor.
     *
     * Blockers fire when:
     *  - any of docs/code/tests/evidence/drift alignment is false,
     *  - the release dossier reference is missing,
     *  - runtime_safety_all_false is false (no phase exits with it false).
     *
     * @param  array{
     *   docs_aligned?:bool,
     *   code_aligned?:bool,
     *   tests_aligned?:bool,
     *   evidence_aligned?:bool,
     *   drift_checks_aligned?:bool,
     *   release_dossier_ref_present?:bool,
     *   runtime_safety_all_false?:bool
     * }  $facts
     * @return array{
     *   schema_version:string,
     *   phase_key:string,
     *   phase_order:int,
     *   may_exit:bool,
     *   blockers:list<string>
     * }
     */
    public function evaluatePhaseExit(string $phaseKey, array $facts): array
    {
        $phase = $this->phaseByKey($phaseKey);
        if ($phase === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'phase_key' => strtolower(trim($phaseKey)),
                'phase_order' => 0,
                'may_exit' => false,
                'blockers' => ['unknown_phase_outside_documented_ladder'],
            ];
        }

        $blockers = [];

        // Section 11: aligned docs + code + tests + evidence + drift, plus release
        // dossier reference. Each missing fact is its own documented prohibition.
        foreach (self::EXIT_REQUIREMENTS as $factKey => $prohibition) {
            if (! (bool) ($facts[$factKey] ?? false)) {
                $blockers[] = $prohibition;
            }
        }

        // Section 11 + section 9: no phase exits with runtime_safety_all_false false.
        // The roadmap states this flag is TRUE today, so an exit attempt that
        // reports it false is blocked.
        $runtimeSafetyAllFalse = (bool) ($facts['runtime_safety_all_false'] ?? false);
        if (! $runtimeSafetyAllFalse) {
            $blockers[] = 'no_phase_exits_with_runtime_safety_all_false_false';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'phase_key' => $phase['key'],
            'phase_order' => $phase['order'],
            'may_exit' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * Decide whether a proposed advancement from $fromPhaseKey to $toPhaseKey is
     * permitted by the sequential, no-skip rule (section 1, section 11).
     *
     * Rules enforced:
     *  - The target must be exactly one phase ahead of the source (order+1).
     *    Any larger jump is a forbidden skip.
     *  - Advancing backwards or staying put is not an "advance".
     *  - Phase 6 (Self-Programming Ready) is NOT a current target (section 7),
     *    so advancement INTO it is refused as out-of-scope-for-now.
     *  - Every prior phase (orders < target) must be reported complete; an
     *    incomplete predecessor blocks the advance (you cannot exit a phase you
     *    have not completed).
     *
     * @param  list<string>  $completedPhaseKeys  phase keys already exited/complete
     * @return array{
     *   schema_version:string,
     *   from_phase:?string,
     *   to_phase:?string,
     *   verdict:string,
     *   allowed:bool,
     *   reason:?string
     * }
     */
    public function evaluateAdvancement(string $fromPhaseKey, string $toPhaseKey, array $completedPhaseKeys = []): array
    {
        $from = $this->phaseByKey($fromPhaseKey);
        $to = $this->phaseByKey($toPhaseKey);

        if ($from === null || $to === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'from_phase' => $from['key'] ?? null,
                'to_phase' => $to['key'] ?? null,
                'verdict' => 'rejected',
                'allowed' => false,
                'reason' => 'unknown_phase_outside_documented_ladder',
            ];
        }

        // Must move strictly forward.
        if ($to['order'] <= $from['order']) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'from_phase' => $from['key'],
                'to_phase' => $to['key'],
                'verdict' => 'rejected',
                'allowed' => false,
                'reason' => 'not_a_forward_advancement',
            ];
        }

        // No skipping: target must be exactly the next phase.
        if ($to['order'] !== $from['order'] + 1) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'from_phase' => $from['key'],
                'to_phase' => $to['key'],
                'verdict' => 'rejected',
                'allowed' => false,
                'reason' => 'forbidden_phase_skip',
            ];
        }

        // Phase 6 is future and not a current target (section 7).
        if (! $to['current_target']) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'from_phase' => $from['key'],
                'to_phase' => $to['key'],
                'verdict' => 'rejected',
                'allowed' => false,
                'reason' => 'target_phase_not_a_current_target',
            ];
        }

        // Every prior phase must be complete before this exit (sequential gate).
        $completed = array_map(static fn (string $k): string => strtolower(trim($k)), $completedPhaseKeys);
        foreach (self::PHASES as $phase) {
            if ($phase['order'] < $to['order'] && ! in_array($phase['key'], $completed, true)) {
                return [
                    'schema_version' => self::SCHEMA_VERSION,
                    'from_phase' => $from['key'],
                    'to_phase' => $to['key'],
                    'verdict' => 'blocked',
                    'allowed' => false,
                    'reason' => 'prior_phase_incomplete:' . $phase['key'],
                ];
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'from_phase' => $from['key'],
            'to_phase' => $to['key'],
            'verdict' => 'allowed',
            'allowed' => true,
            'reason' => null,
        ];
    }

    /**
     * Apply a regression signal (section 1: "Returning to an earlier phase after
     * regression is mandatory"). When a phase regresses, the current phase is
     * forced back to the regressed phase. A regression that points forward or to
     * the same phase is not a regression and is ignored (no false rollback).
     *
     * @return array{
     *   schema_version:string,
     *   current_phase:?string,
     *   regressed_phase:?string,
     *   forced_return:bool,
     *   resulting_phase:?string,
     *   reason:?string
     * }
     */
    public function applyRegression(string $currentPhaseKey, string $regressedPhaseKey): array
    {
        $current = $this->phaseByKey($currentPhaseKey);
        $regressed = $this->phaseByKey($regressedPhaseKey);

        if ($current === null || $regressed === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'current_phase' => $current['key'] ?? null,
                'regressed_phase' => $regressed['key'] ?? null,
                'forced_return' => false,
                'resulting_phase' => $current['key'] ?? null,
                'reason' => 'unknown_phase_outside_documented_ladder',
            ];
        }

        // A genuine regression points to an EARLIER phase than the current one.
        if ($regressed['order'] >= $current['order']) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'current_phase' => $current['key'],
                'regressed_phase' => $regressed['key'],
                'forced_return' => false,
                'resulting_phase' => $current['key'],
                'reason' => 'not_a_regression_no_rollback',
            ];
        }

        // Mandatory return: drop back to the regressed phase.
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'current_phase' => $current['key'],
            'regressed_phase' => $regressed['key'],
            'forced_return' => true,
            'resulting_phase' => $regressed['key'],
            'reason' => 'mandatory_return_to_earlier_phase_after_regression',
        ];
    }

    /**
     * The honest maturity estimate (section 9) as a read model, plus the
     * runtime_safety_all_false floor the doc asserts is true.
     *
     * @return array{
     *   schema_version:string,
     *   runtime_safety_all_false:bool,
     *   provider_dispatch_disabled:bool,
     *   real_runtime_exists:bool,
     *   phases:list<array{order:int,key:string,name:string,posture:string,current_target:bool}>
     * }
     */
    public function maturity(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            // Section 9: "runtime_safety_all_false is true. Provider dispatch is
            // disabled. Real runtime does not exist."
            'runtime_safety_all_false' => true,
            'provider_dispatch_disabled' => true,
            'real_runtime_exists' => false,
            'phases' => self::PHASES,
        ];
    }

    /**
     * Primary entry point: a single governance snapshot of the roadmap contract,
     * with worked examples that demonstrate each enforced rule.
     *
     * @return array{
     *   schema_version:string,
     *   maturity:array<string,mixed>,
     *   exit_requirements:array<string,string>,
     *   skip_example:array<string,mixed>,
     *   next_advance_example:array<string,mixed>,
     *   regression_example:array<string,mixed>,
     *   exit_gate_example:array<string,mixed>
     * }
     */
    public function snapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'maturity' => $this->maturity(),
            'exit_requirements' => self::EXIT_REQUIREMENTS,
            // Worked example: jumping Contract Complete -> Dry-Run Pilot is a forbidden skip.
            'skip_example' => $this->evaluateAdvancement('contract_complete', 'dry_run_pilot_complete'),
            // Worked example: Contract Complete -> Certification Complete is the legal next step
            // once Contract Complete is done.
            'next_advance_example' => $this->evaluateAdvancement(
                'contract_complete',
                'certification_complete',
                ['contract_complete'],
            ),
            // Worked example: a regression in Phase 1 while sitting in Phase 3 forces a return to Phase 1.
            'regression_example' => $this->applyRegression('dry_run_pilot_complete', 'contract_complete'),
            // Worked example: an exit attempt missing the release dossier and with
            // runtime safety reported false is blocked.
            'exit_gate_example' => $this->evaluatePhaseExit('contract_complete', [
                'docs_aligned' => true,
                'code_aligned' => true,
                'tests_aligned' => true,
                'evidence_aligned' => true,
                'drift_checks_aligned' => true,
                'release_dossier_ref_present' => false,
                'runtime_safety_all_false' => false,
            ]),
        ];
    }
}
