<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-810 / LHL-04 — Deterministic Loop Simulator (AP-808).
 *
 * A PROVIDER-FREE, pure decision-machine that replays the long-horizon loop's
 * decision path across many cycles WITHOUT ever invoking a provider, running the
 * loop, merging, or touching git. It answers one question:
 *
 *   > If we run N cycles of the canonical decision paths, does the loop ever
 *   > violate a core honesty invariant?
 *
 * Each cycle is a deterministic decision record produced from a built-in (or
 * supplied) scenario. Selection across `cycles` iterations is INDEX-BASED
 * (`cycle_index % count(scenarios)`) — there is NO Date/random in the decision
 * path, so the same input always produces the same `report_hash`.
 *
 * Every simulated cycle is checked against the canonical LHL-05 invariants. Any
 * violation increments `critical_violations`; `pass` requires zero. The report
 * NEVER dresses a blocked/refused/plan-only/sandbox-commit cycle as success.
 *
 * Read-only / deterministic / input-seam driven:
 *   - `cycles`            int, default 1000 — how many decision cycles to replay.
 *   - `scenarios`         optional list of scenario records (else built-in set).
 *   - `area` / `focus`    labels only.
 * Real probes: NONE. This service never reads git, never spawns a process.
 *
 * Contract: AP-808; AP-810 build contract slice LHL-04. Invariant ids match
 * LHL-05 (LoopCycleInvariantRegistry) exactly.
 */
final class LoopDeterministicSimulatorService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.loop_simulation.v1';

    public const STATUS_PASS = 'pass';

    public const STATUS_FAIL = 'fail';

    /** DoD default cycle count. */
    public const DEFAULT_CYCLES = 1000;

    /** Hard ceiling so a hostile `cycles` value can never explode the simulation. */
    public const MAX_CYCLES = 100000;

    /**
     * Canonical loop invariants (ids MUST match LHL-05 LoopCycleInvariantRegistry).
     * Each maps to a pure checker in {@see self::checkInvariants()}. A failed check
     * is a CRITICAL violation — blocked is never success.
     */
    public const INVARIANTS = [
        'provider_invoked_implies_preflight_allow',
        'merge_performed_implies_judge_accept',
        'lane_mode_implies_main_unchanged',
        'blocked_implies_not_success',
        'sandbox_commit_only_implies_not_merge',
        'forge_plan_only_implies_not_implementation',
        'recovery_in_factory_max_is_critical_violation',
        'final_clean_implies_zero_locks_and_zero_orphans',
        'same_finding_same_packet_same_blocker_repeated_is_duplicate_spin',
        'packet_completed_implies_merge_performed',
    ];

    /**
     * Single entrypoint. Every key is optional; the diagnostic default replays the
     * canonical built-in scenarios for {@see self::DEFAULT_CYCLES} cycles and never
     * crashes.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function simulate(array $input = []): array
    {
        $input = $this->mergeFixture($input);

        $area = trim((string) ($input['area'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';

        $cycles = $this->normalizeCycles($input['cycles'] ?? self::DEFAULT_CYCLES);
        $scenarios = $this->resolveScenarios($input['scenarios'] ?? null);

        /** @var array<string,array{count:int,outcome:string,merged:int,refused:int,blocked:int,violations:int}> $scenarioSummary */
        $scenarioSummary = [];
        /** @var array<string,int> $outcomeTotals */
        $outcomeTotals = [];
        /** @var list<array<string,mixed>> $violations */
        $violations = [];
        /** @var array<string,int> $invariantChecked */
        $invariantChecked = array_fill_keys(self::INVARIANTS, 0);

        $scenarioCount = count($scenarios);
        $mergedTotal = 0;
        $refusedTotal = 0;
        $blockedTotal = 0;

        for ($cycleIndex = 0; $cycleIndex < $cycles; $cycleIndex++) {
            // DETERMINISTIC index-based selection — no Date, no random.
            $scenario = $scenarios[$cycleIndex % $scenarioCount];
            $cycle = $this->decide($scenario, $cycleIndex);

            $name = $cycle['scenario'];
            $outcome = $cycle['outcome'];

            if (! isset($scenarioSummary[$name])) {
                $scenarioSummary[$name] = [
                    'count' => 0,
                    'outcome' => $outcome,
                    'merged' => 0,
                    'refused' => 0,
                    'blocked' => 0,
                    'violations' => 0,
                ];
            }
            $scenarioSummary[$name]['count']++;
            $outcomeTotals[$outcome] = ($outcomeTotals[$outcome] ?? 0) + 1;

            if ($cycle['merge_performed']) {
                $scenarioSummary[$name]['merged']++;
                $mergedTotal++;
            }
            if ($cycle['refused']) {
                $scenarioSummary[$name]['refused']++;
                $refusedTotal++;
            }
            if ($cycle['blocked']) {
                $scenarioSummary[$name]['blocked']++;
                $blockedTotal++;
            }

            // Check this cycle against every canonical invariant.
            foreach (self::INVARIANTS as $invariantId) {
                $invariantChecked[$invariantId]++;
                $failure = $this->checkInvariant($invariantId, $cycle);
                if ($failure !== null) {
                    $scenarioSummary[$name]['violations']++;
                    // Only retain the FIRST instance per (scenario,invariant) pair so a
                    // 1000-cycle run with a real violation produces a bounded, stable list.
                    $violations[] = [
                        'cycle_index' => $cycleIndex,
                        'scenario' => $name,
                        'invariant' => $invariantId,
                        'reason' => $failure,
                    ];
                }
            }
        }

        $violations = $this->dedupeViolations($violations);
        $criticalViolations = count($violations);
        $status = $criticalViolations === 0 ? self::STATUS_PASS : self::STATUS_FAIL;

        ksort($scenarioSummary);

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-808',
            'slice_id' => 'LHL-04',
            'status' => $status,
            'simulation_id' => 'lsim_'.substr(MissionCanonicalHash::sha256([
                $area,
                $focus,
                $cycles,
                array_keys($scenarioSummary),
            ]), 0, 16),
            'area' => $area,
            'focus' => $focus,
            'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'cycles_run' => $cycles,
            'scenario_count' => $scenarioCount,
            'scenario_summary' => $scenarioSummary,
            'outcome_totals' => $outcomeTotals,
            'merge_total' => $mergedTotal,
            'refused_total' => $refusedTotal,
            'blocked_total' => $blockedTotal,
            'invariant_report' => [
                'checked' => $invariantChecked,
                'invariant_ids' => self::INVARIANTS,
                'violations' => $violations,
            ],
            'critical_violations' => $criticalViolations,
            'next_action' => $status === self::STATUS_PASS ? 'continue' : 'stop_critical_violation',
            'claim_policy' => [
                'read_only' => true,
                'runs_provider' => false,
                'runs_loop' => false,
                'runs_merge' => false,
                'deletes_branches' => false,
                'blocked_never_dressed_as_ready' => true,
            ],
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->withoutVolatile($payload));

        return $payload;
    }

    // ------------------------------------------------------------ decision machine

    /**
     * Turn a scenario template into a concrete, fully-resolved decision record for
     * one cycle. PURE: outcome derives only from the scenario fields + index.
     *
     * @param  array<string,mixed>  $scenario
     * @return array<string,mixed>
     */
    private function decide(array $scenario, int $cycleIndex): array
    {
        $name = trim((string) ($scenario['scenario'] ?? ($scenario['name'] ?? 'unknown')));
        $name = $name === '' ? 'unknown' : $name;

        // Honest, explicit decision facts. Defaults are the SAFE (no-spend, no-merge)
        // values so an under-specified scenario can never silently look like success.
        $preflightAllow = (bool) ($scenario['preflight_allow'] ?? false);
        $providerInvoked = (bool) ($scenario['provider_invoked'] ?? false);
        $judgeVerdict = strtolower(trim((string) ($scenario['judge_verdict'] ?? 'none')));
        $mergePerformed = (bool) ($scenario['merge_performed'] ?? false);
        $mergeTarget = $this->normalizeMergeTarget((string) ($scenario['merge_target'] ?? 'none'));
        $loopMode = strtolower(trim((string) ($scenario['loop_mode'] ?? 'lane'))) ?: 'lane';
        $mainUnchanged = (bool) ($scenario['main_unchanged'] ?? true);
        $sandboxCommitOnly = (bool) ($scenario['sandbox_commit_only'] ?? false);
        $forgePlanOnly = (bool) ($scenario['forge_plan_only'] ?? false);
        $implementationClaimed = (bool) ($scenario['implementation_claimed'] ?? false);
        $isRecovery = (bool) ($scenario['is_recovery'] ?? false);
        $scopeProfile = strtolower(trim((string) ($scenario['scope_profile'] ?? 'factory_max'))) ?: 'factory_max';
        $refused = (bool) ($scenario['refused'] ?? false);
        $blocked = (bool) ($scenario['blocked'] ?? false);
        $outcome = strtolower(trim((string) ($scenario['outcome'] ?? 'blocked'))) ?: 'blocked';
        $success = (bool) ($scenario['success'] ?? ($outcome === 'merged'));
        $packetCompleted = (bool) ($scenario['packet_completed'] ?? false);
        $crossSystem = (bool) ($scenario['cross_system'] ?? false);
        $envelopeArmed = (bool) ($scenario['envelope_armed'] ?? false);
        $finalClean = (bool) ($scenario['final_clean'] ?? false);
        $locksRemaining = (int) ($scenario['locks_remaining'] ?? 0);
        $orphansRemaining = (int) ($scenario['orphans_remaining'] ?? 0);
        $duplicateSpin = (bool) ($scenario['duplicate_spin'] ?? false);
        $findingId = trim((string) ($scenario['finding_id'] ?? ''));
        $packetId = trim((string) ($scenario['packet_id'] ?? ''));
        $blockerReason = trim((string) ($scenario['blocker'] ?? ''));
        $repeatedBlocker = (bool) ($scenario['repeated_blocker'] ?? false);

        return [
            'scenario' => $name,
            'cycle_index' => $cycleIndex,
            'outcome' => $outcome,
            'success' => $success,
            'preflight_allow' => $preflightAllow,
            'provider_invoked' => $providerInvoked,
            'judge_verdict' => $judgeVerdict,
            'merge_performed' => $mergePerformed,
            'merge_target' => $mergeTarget,
            'loop_mode' => $loopMode,
            'main_unchanged' => $mainUnchanged,
            'sandbox_commit_only' => $sandboxCommitOnly,
            'forge_plan_only' => $forgePlanOnly,
            'implementation_claimed' => $implementationClaimed,
            'is_recovery' => $isRecovery,
            'scope_profile' => $scopeProfile,
            'refused' => $refused,
            'blocked' => $blocked,
            'packet_completed' => $packetCompleted,
            'cross_system' => $crossSystem,
            'envelope_armed' => $envelopeArmed,
            'final_clean' => $finalClean,
            'locks_remaining' => $locksRemaining,
            'orphans_remaining' => $orphansRemaining,
            'duplicate_spin' => $duplicateSpin,
            'finding_id' => $findingId,
            'packet_id' => $packetId,
            'blocker' => $blockerReason,
            'repeated_blocker' => $repeatedBlocker,
        ];
    }

    // ------------------------------------------------------------ invariant checkers

    /**
     * @param  array<string,mixed>  $cycle
     * @return string|null  null = passed; non-null = violation reason
     */
    private function checkInvariant(string $invariantId, array $cycle): ?string
    {
        return match ($invariantId) {
            // A provider call is only legitimate AFTER preflight `allow`.
            'provider_invoked_implies_preflight_allow' => ($cycle['provider_invoked'] && ! $cycle['preflight_allow'])
                ? 'provider invoked without preflight allow'
                : null,

            // A merge is only legitimate when the judge ACCEPTED.
            'merge_performed_implies_judge_accept' => ($cycle['merge_performed'] && $cycle['judge_verdict'] !== 'accept')
                ? 'merge performed without judge accept (verdict='.$cycle['judge_verdict'].')'
                : null,

            // Lane-mode work must leave main unchanged.
            'lane_mode_implies_main_unchanged' => ($cycle['loop_mode'] === 'lane' && ! $cycle['main_unchanged'])
                ? 'lane-mode cycle mutated main'
                : null,

            // Blocked is NEVER success.
            'blocked_implies_not_success' => ($cycle['blocked'] && $cycle['success'])
                ? 'blocked cycle counted as success'
                : null,

            // A sandbox commit is NOT a merge.
            'sandbox_commit_only_implies_not_merge' => ($cycle['sandbox_commit_only'] && $cycle['merge_performed'])
                ? 'sandbox-commit-only cycle counted as a merge'
                : null,

            // A plan-only Forge path is NOT implementation.
            'forge_plan_only_implies_not_implementation' => ($cycle['forge_plan_only'] && $cycle['implementation_claimed'])
                ? 'forge plan-only counted as implementation'
                : null,

            // Recovery/starvation/filler must NEVER be admitted in factory_max.
            'recovery_in_factory_max_is_critical_violation' => ($cycle['is_recovery'] && $cycle['scope_profile'] === 'factory_max' && ($cycle['provider_invoked'] || $cycle['success'] || $cycle['merge_performed']))
                ? 'recovery admitted/executed in factory_max'
                : null,

            // A cycle that claims a clean final state must have zero locks + zero orphans.
            'final_clean_implies_zero_locks_and_zero_orphans' => ($cycle['final_clean'] && ((int) $cycle['locks_remaining'] > 0 || (int) $cycle['orphans_remaining'] > 0))
                ? 'final_clean claimed with leftover locks/orphans'
                : null,

            // A repeated (finding,packet,blocker) must be flagged as a duplicate spin.
            'same_finding_same_packet_same_blocker_repeated_is_duplicate_spin' => ($cycle['repeated_blocker'] && ! $cycle['duplicate_spin'])
                ? 'repeated finding/packet/blocker not flagged as duplicate spin'
                : null,

            // A completed packet implies an actual merge happened.
            'packet_completed_implies_merge_performed' => ($cycle['packet_completed'] && ! $cycle['merge_performed'])
                ? 'packet marked completed without a merge'
                : null,

            default => null,
        };
    }

    // ------------------------------------------------------------ scenarios

    /**
     * Resolve the scenario set: explicit override (input seam) or the built-in
     * canonical decision paths. Each built-in scenario is honest: refused/blocked/
     * plan-only paths set success=false, merge_performed=false.
     *
     * @param  mixed  $override
     * @return list<array<string,mixed>>
     */
    private function resolveScenarios($override): array
    {
        if (is_array($override)) {
            $resolved = [];
            foreach ($override as $row) {
                if (is_array($row) && $row !== []) {
                    $resolved[] = $row;
                }
            }
            if ($resolved !== []) {
                return array_values($resolved);
            }
        }

        return $this->builtInScenarios();
    }

    /**
     * The canonical built-in decision paths (LHL-04 spec). Each is invariant-clean
     * by construction — a correct loop never violates a core invariant.
     *
     * @return list<array<string,mixed>>
     */
    private function builtInScenarios(): array
    {
        return [
            // Admissible canonical backlog parent → lane merge after judge accept.
            [
                'scenario' => 'admissible_canonical_backlog_parent',
                'finding_id' => 'AAEOS-100',
                'packet_id' => 'parent',
                'scope_profile' => 'factory_max',
                'preflight_allow' => true,
                'provider_invoked' => true,
                'judge_verdict' => 'accept',
                'loop_mode' => 'lane',
                'main_unchanged' => true,
                'merge_performed' => true,
                'merge_target' => 'integration_lane',
                'packet_completed' => true,
                'final_clean' => true,
                'outcome' => 'merged',
                'success' => true,
            ],
            // Self-Construction packet 1/2/3 progression — each a real lane merge.
            $this->packetProgressionScenario(1),
            $this->packetProgressionScenario(2),
            $this->packetProgressionScenario(3),
            // Blocked packet retry policy — preflight blocks, no provider, no merge.
            [
                'scenario' => 'blocked_packet_retry_policy',
                'finding_id' => 'AAEOS-101',
                'packet_id' => 'slice-1',
                'scope_profile' => 'factory_max',
                'preflight_allow' => false,
                'provider_invoked' => false,
                'judge_verdict' => 'none',
                'loop_mode' => 'lane',
                'main_unchanged' => true,
                'merge_performed' => false,
                'merge_target' => 'none',
                'blocked' => true,
                'blocker' => 'admission_blocked',
                'outcome' => 'blocked',
                'success' => false,
            ],
            // Backlog exhausted — honest stop, never synthetic recovery.
            [
                'scenario' => 'backlog_exhausted',
                'scope_profile' => 'factory_max',
                'preflight_allow' => false,
                'provider_invoked' => false,
                'judge_verdict' => 'none',
                'loop_mode' => 'lane',
                'main_unchanged' => true,
                'merge_performed' => false,
                'merge_target' => 'none',
                'blocked' => true,
                'blocker' => 'backlog_exhausted',
                'outcome' => 'backlog_exhausted',
                'success' => false,
            ],
            // Recovery trying to enter factory_max — MUST be refused (no provider, no merge).
            [
                'scenario' => 'recovery_into_factory_max_refused',
                'scope_profile' => 'factory_max',
                'is_recovery' => true,
                'preflight_allow' => false,
                'provider_invoked' => false,
                'judge_verdict' => 'none',
                'loop_mode' => 'lane',
                'main_unchanged' => true,
                'merge_performed' => false,
                'merge_target' => 'none',
                'refused' => true,
                'blocked' => true,
                'blocker' => 'starvation_recovery_in_autonomous_factory_max',
                'outcome' => 'refused',
                'success' => false,
            ],
            // Cross-system without envelope — refused.
            [
                'scenario' => 'cross_system_without_envelope_refused',
                'finding_id' => 'AAEOS-102',
                'scope_profile' => 'factory_max',
                'cross_system' => true,
                'envelope_armed' => false,
                'preflight_allow' => false,
                'provider_invoked' => false,
                'judge_verdict' => 'none',
                'loop_mode' => 'lane',
                'main_unchanged' => true,
                'merge_performed' => false,
                'merge_target' => 'none',
                'refused' => true,
                'blocked' => true,
                'blocker' => 'cross_system_without_armed_envelope',
                'outcome' => 'refused',
                'success' => false,
            ],
            // Cross-system WITH lane envelope — routed to lane (never main).
            [
                'scenario' => 'cross_system_with_lane_envelope_routed_to_lane',
                'finding_id' => 'AAEOS-103',
                'scope_profile' => 'factory_max',
                'cross_system' => true,
                'envelope_armed' => true,
                'preflight_allow' => true,
                'provider_invoked' => true,
                'judge_verdict' => 'accept',
                'loop_mode' => 'lane',
                'main_unchanged' => true,
                'merge_performed' => true,
                'merge_target' => 'integration_lane',
                'packet_completed' => true,
                'final_clean' => true,
                'outcome' => 'merged',
                'success' => true,
            ],
            // Judge accepted — provider ran, judge accepted, lane merge.
            [
                'scenario' => 'judge_accepted',
                'finding_id' => 'AAEOS-104',
                'scope_profile' => 'factory_max',
                'preflight_allow' => true,
                'provider_invoked' => true,
                'judge_verdict' => 'accept',
                'loop_mode' => 'lane',
                'main_unchanged' => true,
                'merge_performed' => true,
                'merge_target' => 'integration_lane',
                'packet_completed' => true,
                'final_clean' => true,
                'outcome' => 'merged',
                'success' => true,
            ],
            // Judge repair_required — NO merge (work continues, not success).
            [
                'scenario' => 'judge_repair_required_no_merge',
                'finding_id' => 'AAEOS-105',
                'scope_profile' => 'factory_max',
                'preflight_allow' => true,
                'provider_invoked' => true,
                'judge_verdict' => 'repair_required',
                'loop_mode' => 'lane',
                'main_unchanged' => true,
                'merge_performed' => false,
                'merge_target' => 'none',
                'packet_completed' => false,
                'outcome' => 'repair_required',
                'success' => false,
            ],
            // Lane merge success — explicit lane merge path.
            [
                'scenario' => 'lane_merge_success',
                'finding_id' => 'AAEOS-106',
                'scope_profile' => 'factory_max',
                'preflight_allow' => true,
                'provider_invoked' => true,
                'judge_verdict' => 'accept',
                'loop_mode' => 'lane',
                'main_unchanged' => true,
                'merge_performed' => true,
                'merge_target' => 'integration_lane',
                'packet_completed' => true,
                'final_clean' => true,
                'outcome' => 'merged',
                'success' => true,
            ],
            // Main merge forbidden — autonomous work must NOT merge to main; routed/blocked.
            [
                'scenario' => 'main_merge_forbidden',
                'finding_id' => 'AAEOS-107',
                'scope_profile' => 'factory_max',
                'preflight_allow' => false,
                'provider_invoked' => false,
                'judge_verdict' => 'none',
                'loop_mode' => 'lane',
                'main_unchanged' => true,
                'merge_performed' => false,
                'merge_target' => 'none',
                'refused' => true,
                'blocked' => true,
                'blocker' => 'cross_system_autonomous_to_main',
                'outcome' => 'refused',
                'success' => false,
            ],
            // Cleanup success — sandbox commit only, NOT a merge, leaves a clean tree.
            [
                'scenario' => 'cleanup_success',
                'finding_id' => 'AAEOS-108',
                'scope_profile' => 'factory_max',
                'preflight_allow' => true,
                'provider_invoked' => true,
                'judge_verdict' => 'none',
                'loop_mode' => 'lane',
                'main_unchanged' => true,
                'sandbox_commit_only' => true,
                'merge_performed' => false,
                'merge_target' => 'none',
                'final_clean' => true,
                'locks_remaining' => 0,
                'orphans_remaining' => 0,
                'outcome' => 'cleanup',
                'success' => false,
            ],
            // Cleanup failure — leftovers remain, NOT clean, not success.
            [
                'scenario' => 'cleanup_failure',
                'finding_id' => 'AAEOS-109',
                'scope_profile' => 'factory_max',
                'preflight_allow' => false,
                'provider_invoked' => false,
                'judge_verdict' => 'none',
                'loop_mode' => 'lane',
                'main_unchanged' => true,
                'merge_performed' => false,
                'merge_target' => 'none',
                'final_clean' => false,
                'locks_remaining' => 1,
                'orphans_remaining' => 1,
                'blocked' => true,
                'blocker' => 'cleanup_failed',
                'outcome' => 'cleanup_failed',
                'success' => false,
            ],
        ];
    }

    /**
     * A single Self-Construction packet step in a bounded progression. Each step is
     * a real lane merge after judge accept (invariant-clean).
     *
     * @return array<string,mixed>
     */
    private function packetProgressionScenario(int $sequence): array
    {
        return [
            'scenario' => 'self_construction_packet_'.$sequence,
            'finding_id' => 'AAEOS-110',
            'packet_id' => 'slice-'.$sequence,
            'scope_profile' => 'factory_max',
            'preflight_allow' => true,
            'provider_invoked' => true,
            'judge_verdict' => 'accept',
            'loop_mode' => 'lane',
            'main_unchanged' => true,
            'merge_performed' => true,
            'merge_target' => 'integration_lane',
            'packet_completed' => true,
            'final_clean' => true,
            'outcome' => 'merged',
            'success' => true,
        ];
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param  mixed  $value
     */
    private function normalizeCycles($value): int
    {
        $cycles = (int) $value;
        if ($cycles <= 0) {
            $cycles = self::DEFAULT_CYCLES;
        }

        return min($cycles, self::MAX_CYCLES);
    }

    private function normalizeMergeTarget(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['integration_lane', 'main', 'none'], true) ? $value : 'none';
    }

    /**
     * Keep only the FIRST violation per (scenario,invariant) pair so a long run with
     * a real violation produces a bounded, deterministic list (and a stable hash).
     *
     * @param  list<array<string,mixed>>  $violations
     * @return list<array<string,mixed>>
     */
    private function dedupeViolations(array $violations): array
    {
        $seen = [];
        $out = [];
        foreach ($violations as $violation) {
            $key = ($violation['scenario'] ?? '').'|'.($violation['invariant'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $violation;
        }

        return $out;
    }

    /**
     * A wiring-phase `fixture` may carry `cycles`/`scenarios`; fold it under the
     * explicit input so direct keys still win (input-seam composition).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function mergeFixture(array $input): array
    {
        $fixture = $input['fixture'] ?? null;
        if (! is_array($fixture) || $fixture === []) {
            return $input;
        }
        unset($input['fixture']);

        return array_merge($fixture, $input);
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
}
