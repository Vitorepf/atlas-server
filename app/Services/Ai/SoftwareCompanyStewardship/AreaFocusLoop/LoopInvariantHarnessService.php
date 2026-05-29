<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-810 / LHL-05 — Loop Invariant Harness.
 *
 * Owner AP: AP-808 (Part 2 — Invariant Test Harness).
 *
 * Encodes the non-negotiable rules of the Stewardship / Area Focus long-horizon
 * loop as EXECUTABLE assertions and checks a list of cycle records against them.
 * It is read-only / deterministic / input-seam driven: it NEVER runs the loop,
 * never invokes a provider, never merges and never deletes a branch. It only
 * DIAGNOSES whether recorded (or simulated) cycles obeyed the invariants.
 *
 * Honesty rules (the operator does not accept false claims):
 *   - any invariant violation FAILS the harness (status=fail) and BLOCKS
 *     long-run readiness — a violation is never dressed up as a pass;
 *   - blocked is never success; a sandbox-only commit is never a merge;
 *   - plan-only Forge is never an implementation;
 *   - recovery selected in autonomous factory_max is a CRITICAL violation;
 *   - a packet is "completed" only when a real merge was performed.
 *
 * Two methods:
 *   - evaluate(): check `cycles` against the canonical invariant registry.
 *   - report():   AGGREGATOR for `loop-assurance-report` — composes the latest
 *                 simulation, chaos, resource and invariant results (each passed
 *                 in via input seams) into one assurance verdict. The aggregate
 *                 is `ready` only when every composed part passed.
 *
 * Contract: docs/ap/AP-808 Part 2; AP-810 LHL-05 (FROZEN build contract).
 */
final class LoopInvariantHarnessService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.loop_invariant_report.v1';

    public const STATUS_PASS = 'pass';

    public const STATUS_FAIL = 'fail';

    public function __construct(
        private readonly LoopCycleInvariantRegistry $registry = new LoopCycleInvariantRegistry,
    ) {}

    /**
     * Check a list of cycle records against every canonical invariant.
     *
     * Input seams:
     *   - `cycles`: list<array> of cycle records (the only required fact).
     *   - `area`, `focus`: labels only.
     *   - `fixture`: when present and `cycles` absent, `fixture['cycles']` is used
     *     (so the CLI `--fixture-file` can feed cycles directly).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input = []): array
    {
        $area = $this->str($input, 'area', 'agentic_engineering_os');
        $focus = $this->str($input, 'focus', 'dev_forge');
        $cycles = $this->cyclesFromInput($input);

        $definitions = $this->registry->definitions();

        // Per-invariant accumulators.
        $invariantSummaries = [];
        $violations = [];
        $blockers = [];
        $checkedCount = 0;
        $criticalCount = 0;

        foreach ($definitions as $id => $definition) {
            $violatingCycles = [];

            foreach ($cycles as $index => $cycle) {
                $cycle = is_array($cycle) ? $cycle : [];
                $result = $this->registry->check($id, $cycle);
                $checkedCount++;

                // null = invariant not applicable to this cycle (skipped, no opinion).
                if ($result === null) {
                    continue;
                }

                if ($result === false) {
                    $violation = [
                        'invariant' => $id,
                        'description' => $definition['description'],
                        'severity' => $definition['severity'],
                        'cycle_index' => is_int($index) ? $index : (int) $index,
                        'cycle_ref' => $this->cycleRef($cycle, is_int($index) ? $index : (int) $index),
                        'detail' => $definition['violation_detail'],
                    ];
                    $violations[] = $violation;
                    $violatingCycles[] = $violation['cycle_index'];
                    if ($definition['severity'] === LoopCycleInvariantRegistry::SEVERITY_CRITICAL) {
                        $criticalCount++;
                    }
                }
            }

            $passed = $violatingCycles === [];
            $invariantSummaries[$id] = [
                'invariant' => $id,
                'description' => $definition['description'],
                'severity' => $definition['severity'],
                'passed' => $passed,
                'violating_cycles' => $violatingCycles,
            ];
            if (! $passed) {
                $blockers[] = 'invariant_violation:'.$id;
            }
        }

        $status = $violations === [] ? self::STATUS_PASS : self::STATUS_FAIL;
        // Any violation blocks long-run promotion; a critical one is the strongest signal.
        $blocksLongRunReadiness = $violations !== [];

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-808',
            'slice_id' => 'LHL-05',
            'status' => $status,
            'invariant_report_id' => 'lih_'.substr(
                MissionCanonicalHash::sha256([$area, $focus, $this->stableCycles($cycles)]),
                0,
                16,
            ),
            'area' => $area,
            'focus' => $focus,
            'checked_at' => $this->now(),
            'cycles_checked' => count($cycles),
            'invariant_checks_run' => $checkedCount,
            'invariants' => array_values($invariantSummaries),
            'violations' => array_values($violations),
            'critical_violations' => $criticalCount,
            'blocks_long_run_readiness' => $blocksLongRunReadiness,
            'next_action' => $status === self::STATUS_PASS ? 'continue' : 'stop_invariant_violation',
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => $cycles === [] ? ['no_cycles_supplied_to_invariant_harness'] : [],
            'claim_policy' => [
                'read_only' => true,
                'runs_provider' => false,
                'runs_merge' => false,
                'deletes_branches' => false,
                'blocked_never_dressed_as_ready' => true,
            ],
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->withoutVolatile($payload));

        return $payload;
    }

    /**
     * Aggregate the latest assurance signals into one long-run readiness verdict.
     *
     * Composition is via INPUT SEAMS — each lower report is passed in, never built
     * here (no `new` of a sibling service). When the invariant report is absent it
     * is computed from `cycles` so the command can run invariants inline.
     *
     * Recognized input seams (each an already-produced report array OR a status):
     *   - `simulation` / `simulation_report` (LHL-04)
     *   - `chaos` / `chaos_report`           (LHL-06)
     *   - `resource` / `resource_report`     (LHL-08)
     *   - `invariant` / `invariant_report`   (this service; else computed from `cycles`)
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function report(array $input = []): array
    {
        $area = $this->str($input, 'area', 'agentic_engineering_os');
        $focus = $this->str($input, 'focus', 'dev_forge');

        // Invariant part: prefer a supplied report; otherwise compute it now.
        $invariantReport = $this->reportSeam($input, ['invariant', 'invariant_report']);
        if ($invariantReport === null) {
            $invariantReport = $this->evaluate([
                'area' => $area,
                'focus' => $focus,
                'cycles' => $this->cyclesFromInput($input),
            ]);
        }

        $parts = [
            'simulation' => $this->partStatus(
                $this->reportSeam($input, ['simulation', 'simulation_report']),
                [LoopDeterministicSimulatorServiceStatus::PASS],
            ),
            'chaos' => $this->partStatus(
                $this->reportSeam($input, ['chaos', 'chaos_report']),
                [LoopDeterministicSimulatorServiceStatus::PASS],
            ),
            'resource' => $this->partStatus(
                $this->reportSeam($input, ['resource', 'resource_report']),
                [LoopDeterministicSimulatorServiceStatus::OK],
            ),
            'invariant' => $this->partStatus($invariantReport, [self::STATUS_PASS]),
        ];

        $blockers = [];
        foreach ($parts as $name => $part) {
            if ($part['present'] && ! $part['ok']) {
                $blockers[] = $name.'_'.($part['status'] ?: 'unknown');
            }
            if (! $part['present']) {
                $blockers[] = $name.'_report_missing';
            }
        }

        // Aggregate is ready ONLY when every composed part is present AND ok.
        $allOk = array_reduce(
            $parts,
            static fn (bool $carry, array $p): bool => $carry && $p['present'] && $p['ok'],
            true,
        );
        $status = $allOk ? self::STATUS_PASS : self::STATUS_FAIL;

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-808',
            'slice_id' => 'LHL-05',
            'report_kind' => 'assurance_report',
            'status' => $status,
            'assurance_report_id' => 'lar_'.substr(
                MissionCanonicalHash::sha256([
                    $area,
                    $focus,
                    $parts['simulation']['status'],
                    $parts['chaos']['status'],
                    $parts['resource']['status'],
                    $parts['invariant']['status'],
                    $invariantReport['report_hash'] ?? '',
                ]),
                0,
                16,
            ),
            'area' => $area,
            'focus' => $focus,
            'checked_at' => $this->now(),
            'composed' => [
                'simulation' => $parts['simulation'],
                'chaos' => $parts['chaos'],
                'resource' => $parts['resource'],
                'invariant' => $parts['invariant'],
            ],
            'invariant_report' => $invariantReport,
            'blocks_long_run_readiness' => $status !== self::STATUS_PASS,
            'next_action' => $status === self::STATUS_PASS ? 'continue' : 'stop_assurance_incomplete',
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => [],
            'claim_policy' => [
                'read_only' => true,
                'runs_provider' => false,
                'runs_merge' => false,
                'deletes_branches' => false,
                'blocked_never_dressed_as_ready' => true,
                'aggregate_ready_requires_all_parts_pass' => true,
            ],
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->withoutVolatile($payload));

        return $payload;
    }

    /**
     * E2E contract test count gate entry seam (step 2/3).
     *
     * Validates caller input tolerantly and returns the default gate contract
     * from {@see E2eContractTestCountGateContract}. Count transformation is
     * deferred to step 3.
     *
     * Input seams:
     *   - `area_id` / `area`, `focus`: labels only (defaults apply).
     *   - `department_id`, `contract_test_count`: accepted when present but not
     *     applied until step 3.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function e2eContractTestCountGate(array $input = []): array
    {
        $areaId = $this->str($input, 'area_id', $this->str($input, 'area', 'agentic_engineering_os'));
        $focus = $this->str($input, 'focus', 'dev_forge');

        $this->validateE2eContractTestCountGateInput($input);

        return E2eContractTestCountGateContract::defaults($areaId, $focus)->toArray();
    }

    // ---------- composition helpers ----------

    /**
     * Pull a previously-produced report array out of the input under the first
     * matching key. A bare string is tolerated as a status shortcut.
     *
     * @param  array<string,mixed>  $input
     * @param  list<string>  $keys
     * @return array<string,mixed>|null
     */
    private function reportSeam(array $input, array $keys): ?array
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            if (is_array($value)) {
                return $value;
            }
            if (is_string($value) && $value !== '') {
                return ['status' => $value];
            }
        }

        return null;
    }

    /**
     * Reduce a composed report to a present/ok/status triple.
     *
     * @param  array<string,mixed>|null  $report
     * @param  list<string>  $okStatuses
     * @return array{present:bool,ok:bool,status:string}
     */
    private function partStatus(?array $report, array $okStatuses): array
    {
        if ($report === null) {
            return ['present' => false, 'ok' => false, 'status' => ''];
        }

        $status = (string) ($report['status'] ?? '');

        return [
            'present' => true,
            'ok' => $status !== '' && in_array($status, $okStatuses, true),
            'status' => $status,
        ];
    }

    // ---------- cycle helpers ----------

    /**
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function cyclesFromInput(array $input): array
    {
        $cycles = $input['cycles'] ?? null;
        if (! is_array($cycles) && isset($input['fixture']) && is_array($input['fixture'])) {
            $cycles = $input['fixture']['cycles'] ?? $input['fixture'];
        }
        if (! is_array($cycles)) {
            return [];
        }

        $out = [];
        foreach ($cycles as $cycle) {
            if (is_array($cycle)) {
                $out[] = $cycle;
            }
        }

        return $out;
    }

    /**
     * A stable, order-preserving projection of cycles for the id hash (no volatile
     * fields). Sorting is left to MissionCanonicalHash (it sorts map keys).
     *
     * @param  list<array<string,mixed>>  $cycles
     * @return list<array<string,mixed>>
     */
    private function stableCycles(array $cycles): array
    {
        return array_map(function (array $cycle): array {
            unset($cycle['checked_at'], $cycle['recorded_at'], $cycle['timestamp']);

            return $cycle;
        }, $cycles);
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function cycleRef(array $cycle, int $index): string
    {
        foreach (['cycle_ref', 'cycle_id', 'run_id', 'finding_id', 'packet_id'] as $key) {
            $value = $cycle[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return 'cycle#'.$index;
    }

    // ---------- E2E contract test count gate helpers ----------

    /**
     * @param  array<string,mixed>  $input
     */
    private function validateE2eContractTestCountGateInput(array $input): void
    {
        if (! array_key_exists('contract_test_count', $input)) {
            return;
        }

        $count = $input['contract_test_count'];
        if (is_int($count)) {
            return;
        }

        if (is_string($count) && is_numeric($count)) {
            return;
        }

        throw new \InvalidArgumentException(
            'e2e_contract_test_count_gate: contract_test_count must be int or numeric string',
        );
    }

    // ---------- generic helpers ----------

    /**
     * @param  array<string,mixed>  $input
     */
    private function str(array $input, string $key, string $default): string
    {
        $value = trim((string) ($input[$key] ?? ''));

        return $value !== '' ? $value : $default;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
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

/**
 * Canonical statuses of sibling assurance services that `report()` composes.
 * Kept local (a string-const holder) so this slice does not hard-couple to the
 * LHL-04/06/08 service classes — composition is via input seams, this only names
 * the statuses we treat as "ok" for each part.
 */
final class LoopDeterministicSimulatorServiceStatus
{
    public const PASS = 'pass';

    public const OK = 'ok';
}

/**
 * AP-810 / LHL-05 — Canonical Loop Cycle Invariant Registry.
 *
 * The single source of truth for the loop's non-negotiable invariants. Each entry
 * is `id => {description, severity}` plus a PURE checker (no I/O, no provider, no
 * git) that inspects ONE cycle record and returns:
 *   - true  => invariant held for this cycle,
 *   - false => invariant VIOLATED,
 *   - null  => invariant not applicable to this cycle (no opinion).
 *
 * Invariant ids are FROZEN — LHL-04 (simulator) and LHL-19 (ladder) reference
 * these exact strings. Do not rename.
 */
final class LoopCycleInvariantRegistry
{
    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITY_FATAL = 'fatal';

    /** The ten canonical invariant ids, in registry order. */
    public const INVARIANT_PROVIDER_PREFLIGHT = 'provider_invoked_implies_preflight_allow';

    public const INVARIANT_MERGE_JUDGE = 'merge_performed_implies_judge_accept';

    public const INVARIANT_LANE_MAIN_UNCHANGED = 'lane_mode_implies_main_unchanged';

    public const INVARIANT_BLOCKED_NOT_SUCCESS = 'blocked_implies_not_success';

    public const INVARIANT_SANDBOX_NOT_MERGE = 'sandbox_commit_only_implies_not_merge';

    public const INVARIANT_FORGE_PLAN_NOT_IMPL = 'forge_plan_only_implies_not_implementation';

    public const INVARIANT_RECOVERY_FACTORY_MAX = 'recovery_in_factory_max_is_critical_violation';

    public const INVARIANT_FINAL_CLEAN = 'final_clean_implies_zero_locks_and_zero_orphans';

    public const INVARIANT_DUPLICATE_SPIN = 'same_finding_same_packet_same_blocker_repeated_is_duplicate_spin';

    public const INVARIANT_PACKET_COMPLETE_MERGE = 'packet_completed_implies_merge_performed';

    /**
     * @return array<string,array{description:string,severity:string,violation_detail:string}>
     */
    public function definitions(): array
    {
        return [
            self::INVARIANT_PROVIDER_PREFLIGHT => [
                'description' => 'A provider call may only happen when AP-807 preflight returned allow.',
                'severity' => self::SEVERITY_CRITICAL,
                'violation_detail' => 'provider_invoked=true while preflight_status!=allow',
            ],
            self::INVARIANT_MERGE_JUDGE => [
                'description' => 'A merge may only be performed when the judge accepted it for the merge governor.',
                'severity' => self::SEVERITY_CRITICAL,
                'violation_detail' => 'merge_performed=true while judge_status not accepted',
            ],
            self::INVARIANT_LANE_MAIN_UNCHANGED => [
                'description' => 'When merge_target is the integration lane, main must be unchanged.',
                'severity' => self::SEVERITY_CRITICAL,
                'violation_detail' => 'merge_target=integration_lane but main_before!=main_after',
            ],
            self::INVARIANT_BLOCKED_NOT_SUCCESS => [
                'description' => 'A blocked cycle can never count as success.',
                'severity' => self::SEVERITY_FATAL,
                'violation_detail' => 'cycle is blocked but counts_as_success=true',
            ],
            self::INVARIANT_SANDBOX_NOT_MERGE => [
                'description' => 'A sandbox-only commit can never count as a merge.',
                'severity' => self::SEVERITY_FATAL,
                'violation_detail' => 'sandbox_commit_only=true but counts_as_merge=true',
            ],
            self::INVARIANT_FORGE_PLAN_NOT_IMPL => [
                'description' => 'A plan-only Forge cycle can never count as an implementation.',
                'severity' => self::SEVERITY_FATAL,
                'violation_detail' => 'forge_plan_only=true but counts_as_implementation=true',
            ],
            self::INVARIANT_RECOVERY_FACTORY_MAX => [
                'description' => 'Selecting a recovery/filler candidate while in autonomous factory_max is a critical violation.',
                'severity' => self::SEVERITY_CRITICAL,
                'violation_detail' => 'recovery/filler candidate selected in autonomous factory_max scope',
            ],
            self::INVARIANT_FINAL_CLEAN => [
                'description' => 'A cycle reported as final_clean must have zero locks and zero orphans (worktrees/processes).',
                'severity' => self::SEVERITY_CRITICAL,
                'violation_detail' => 'final_clean=true but locks/orphan_worktrees/orphan_processes != 0',
            ],
            self::INVARIANT_DUPLICATE_SPIN => [
                'description' => 'Repeating the same finding_id+packet_id+blocker is duplicate spin, not progress.',
                'severity' => self::SEVERITY_CRITICAL,
                'violation_detail' => 'same finding_id+packet_id+blocker repeated across cycles',
            ],
            self::INVARIANT_PACKET_COMPLETE_MERGE => [
                'description' => 'A packet may be marked completed only when a real merge was performed.',
                'severity' => self::SEVERITY_FATAL,
                'violation_detail' => 'packet_completed=true but merge_performed=false',
            ],
        ];
    }

    /**
     * Check a single cycle against a single invariant.
     *
     * @param  array<string,mixed>  $cycle
     * @return bool|null  true=held, false=violated, null=not applicable
     */
    public function check(string $invariantId, array $cycle): ?bool
    {
        return match ($invariantId) {
            self::INVARIANT_PROVIDER_PREFLIGHT => $this->checkProviderPreflight($cycle),
            self::INVARIANT_MERGE_JUDGE => $this->checkMergeJudge($cycle),
            self::INVARIANT_LANE_MAIN_UNCHANGED => $this->checkLaneMainUnchanged($cycle),
            self::INVARIANT_BLOCKED_NOT_SUCCESS => $this->checkBlockedNotSuccess($cycle),
            self::INVARIANT_SANDBOX_NOT_MERGE => $this->checkSandboxNotMerge($cycle),
            self::INVARIANT_FORGE_PLAN_NOT_IMPL => $this->checkForgePlanNotImpl($cycle),
            self::INVARIANT_RECOVERY_FACTORY_MAX => $this->checkRecoveryFactoryMax($cycle),
            self::INVARIANT_FINAL_CLEAN => $this->checkFinalClean($cycle),
            self::INVARIANT_DUPLICATE_SPIN => $this->checkDuplicateSpin($cycle),
            self::INVARIANT_PACKET_COMPLETE_MERGE => $this->checkPacketCompleteMerge($cycle),
            default => null,
        };
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function checkProviderPreflight(array $cycle): ?bool
    {
        if (! $this->truthy($cycle, 'provider_invoked')) {
            return null; // no provider call => invariant not engaged.
        }
        $status = $this->preflightStatus($cycle);

        return $status === 'allow';
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function checkMergeJudge(array $cycle): ?bool
    {
        if (! $this->truthy($cycle, 'merge_performed')) {
            return null;
        }

        return $this->judgeAccepted($cycle);
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function checkLaneMainUnchanged(array $cycle): ?bool
    {
        if ($this->mergeTarget($cycle) !== 'integration_lane') {
            return null;
        }
        $before = $cycle['main_before'] ?? null;
        $after = $cycle['main_after'] ?? null;
        if ($before === null && $after === null) {
            return null; // no main hashes recorded => cannot assert (no opinion).
        }

        return (string) $before === (string) $after;
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function checkBlockedNotSuccess(array $cycle): ?bool
    {
        if (! $this->isBlocked($cycle)) {
            return null;
        }

        return $this->countsAsSuccess($cycle) === false;
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function checkSandboxNotMerge(array $cycle): ?bool
    {
        if (! $this->truthy($cycle, 'sandbox_commit_only')) {
            return null;
        }

        return $this->truthy($cycle, 'counts_as_merge') === false;
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function checkForgePlanNotImpl(array $cycle): ?bool
    {
        if (! $this->truthy($cycle, 'forge_plan_only')) {
            return null;
        }

        return $this->truthy($cycle, 'counts_as_implementation') === false;
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function checkRecoveryFactoryMax(array $cycle): ?bool
    {
        $scope = strtolower(trim((string) ($cycle['scope_profile'] ?? $cycle['scope'] ?? '')));
        $autonomous = $this->truthy($cycle, 'autonomous', true);
        if ($scope !== 'factory_max' || ! $autonomous) {
            return null;
        }
        $kind = strtolower(trim((string) ($cycle['candidate_kind'] ?? $cycle['candidate_source'] ?? '')));
        $isRecovery = $this->truthy($cycle, 'recovery_candidate')
            || $this->truthy($cycle, 'is_recovery')
            || $this->truthy($cycle, 'is_filler')
            || in_array($kind, ['recovery', 'filler', 'starvation_recovery', 'recovery_filler'], true);

        // In autonomous factory_max, a recovery/filler candidate is forbidden:
        // its presence violates the invariant.
        return ! $isRecovery;
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function checkFinalClean(array $cycle): ?bool
    {
        if (! $this->truthy($cycle, 'final_clean')) {
            return null;
        }
        $locks = (int) ($cycle['locks'] ?? $cycle['lock_count'] ?? 0);
        $orphanWorktrees = (int) ($cycle['orphan_worktrees'] ?? $cycle['orphan_worktree_count'] ?? 0);
        $orphanProcesses = (int) ($cycle['orphan_processes'] ?? $cycle['orphan_process_count'] ?? 0);

        return $locks === 0 && $orphanWorktrees === 0 && $orphanProcesses === 0;
    }

    /**
     * Duplicate-spin is a property of the cycle relative to its own history. A cycle
     * record may self-report it (`duplicate_spin`), or carry a `repeated_signature`
     * flag, or expose its prior-attempt fingerprint that equals the current one.
     *
     * @param  array<string,mixed>  $cycle
     */
    private function checkDuplicateSpin(array $cycle): ?bool
    {
        // Explicit self-report of a repeated signature => violation.
        if ($this->truthy($cycle, 'duplicate_spin') || $this->truthy($cycle, 'repeated_signature')) {
            return false;
        }

        $finding = trim((string) ($cycle['finding_id'] ?? ''));
        $packet = trim((string) ($cycle['packet_id'] ?? ''));
        $blocker = trim((string) ($cycle['blocker'] ?? $cycle['blocker_reason'] ?? ''));
        if ($finding === '' && $packet === '' && $blocker === '') {
            return null; // not enough signature to judge.
        }
        $signature = $finding.'|'.$packet.'|'.$blocker;
        $prior = $cycle['prior_signature'] ?? $cycle['previous_signature'] ?? null;
        if (is_string($prior) && $prior !== '') {
            return $prior !== $signature;
        }

        return null; // single record with no prior to compare => no opinion.
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function checkPacketCompleteMerge(array $cycle): ?bool
    {
        if (! $this->truthy($cycle, 'packet_completed')) {
            return null;
        }

        return $this->truthy($cycle, 'merge_performed');
    }

    // ---------- field readers (tolerant of multiple shapes) ----------

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function preflightStatus(array $cycle): string
    {
        $value = $cycle['preflight_status'] ?? null;
        if (is_array($cycle['preflight'] ?? null)) {
            $value = $cycle['preflight']['status'] ?? $value;
        }

        return strtolower(trim((string) $value));
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function judgeAccepted(array $cycle): bool
    {
        $status = $cycle['judge_status'] ?? null;
        if (is_array($cycle['judge'] ?? null)) {
            $status = $cycle['judge']['status'] ?? $status;
        }
        $status = strtolower(trim((string) $status));

        return in_array($status, ['accepted', 'accepted_for_merge_governor', 'accept', 'approved'], true);
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function mergeTarget(array $cycle): string
    {
        return strtolower(trim((string) ($cycle['merge_target'] ?? '')));
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function isBlocked(array $cycle): bool
    {
        if ($this->truthy($cycle, 'blocked')) {
            return true;
        }
        $status = strtolower(trim((string) ($cycle['status'] ?? $cycle['cycle_status'] ?? '')));

        return in_array($status, [
            'blocked',
            'valid_block',
            'admission_blocked',
            'backlog_exhausted',
            'review_locked',
            'quarantined',
            'duplicate_blocked',
        ], true);
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function countsAsSuccess(array $cycle): ?bool
    {
        foreach (['counts_as_success', 'counts_as_real_success', 'success'] as $key) {
            if (array_key_exists($key, $cycle)) {
                return (bool) $cycle[$key];
            }
        }

        return null;
    }

    /**
     * Read a boolean-ish flag. When $default is provided it is returned for absent
     * keys (used where "unspecified means engaged", e.g. autonomous defaults true
     * only inside the factory_max guard).
     *
     * @param  array<string,mixed>  $cycle
     */
    private function truthy(array $cycle, string $key, bool $default = false): bool
    {
        if (! array_key_exists($key, $cycle)) {
            return $default;
        }
        $value = $cycle[$key];
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }
}
