<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-810 / LHL-17 — Loop Disaster Recovery (owner AP-809).
 *
 * The crash-and-corruption preflight for the long-horizon loop. When something
 * has gone wrong between cycles — a torn ledger tail, a missing lane branch, a
 * dirty sandbox, a dead lock owner, a missing evidence receipt, an unexpected
 * main/lane divergence, or a disk threshold breach — this service decides, in a
 * read-only / deterministic / input-seam way, whether recovery is even safe to
 * attempt and WHAT it would do, WITHOUT ever doing it.
 *
 * It NEVER invokes a provider, NEVER runs the loop, NEVER merges, NEVER deletes
 * a branch/worktree, NEVER touches the working tree, and NEVER runs destructive
 * git. It produces a PLAN; an owner executes it elsewhere.
 *
 * Doctrine (the operator does not accept guesses):
 *   - NEVER guess. An ambiguous / undiagnosable state => `fail_closed`, never a
 *     hopeful "recoverable".
 *   - Irreversible repair (anything that throws away evidence or rewrites
 *     history: deleting a branch, resetting a ledger, force-cleaning a sandbox,
 *     hard-resetting a divergence) requires an explicit operator receipt
 *     (`$input['operator_receipt']`). Without it, any scenario that needs an
 *     irreversible action returns `needs_operator` — the plan is computed but
 *     gated, never auto-executed.
 *   - Preserve raw evidence BEFORE any cleanup. Every irreversible action is
 *     preceded by a reversible "preserve raw evidence" safe action, and the
 *     report asserts `raw_evidence_preserved` only when that preservation step
 *     is part of the plan (and is reported false when it cannot be guaranteed).
 *
 * Status:
 *   - recoverable    — a fully reversible safe plan exists (or the irreversible
 *                      part is authorized by an operator receipt) and the state
 *                      is unambiguously diagnosed.
 *   - needs_operator — diagnosed, but the only path forward is irreversible and
 *                      no operator receipt authorizes it.
 *   - fail_closed    — ambiguous / undiagnosable / multiple conflicting signals;
 *                      recovery must NOT be attempted.
 *
 * Contract: AP-809; AP-810 build contract slice LHL-17.
 * Reference shape: LoopPreflightCycleFirewallService (gate/report shape).
 */
final class LoopDisasterRecoveryService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.loop_disaster_recovery.v1';

    public const STATUS_RECOVERABLE = 'recoverable';

    public const STATUS_NEEDS_OPERATOR = 'needs_operator';

    public const STATUS_FAIL_CLOSED = 'fail_closed';

    /** Canonical disaster scenarios (AP-809). */
    public const SCENARIO_CORRUPT_LEDGER_TAIL = 'corrupt_ledger_tail';

    public const SCENARIO_MISSING_LANE_BRANCH = 'missing_lane_branch';

    public const SCENARIO_DIRTY_SANDBOX = 'dirty_sandbox';

    public const SCENARIO_LOCK_OWNER_DEAD = 'lock_owner_dead';

    public const SCENARIO_MISSING_EVIDENCE_RECEIPT = 'missing_evidence_receipt';

    public const SCENARIO_UNEXPECTED_DIVERGENCE = 'unexpected_main_lane_divergence';

    public const SCENARIO_DISK_THRESHOLD_EXCEEDED = 'disk_threshold_exceeded';

    public const SCENARIO_UNKNOWN = 'unknown';

    /** Canonical disaster scenarios in a stable order. */
    private const KNOWN_SCENARIOS = [
        self::SCENARIO_CORRUPT_LEDGER_TAIL,
        self::SCENARIO_MISSING_LANE_BRANCH,
        self::SCENARIO_DIRTY_SANDBOX,
        self::SCENARIO_LOCK_OWNER_DEAD,
        self::SCENARIO_MISSING_EVIDENCE_RECEIPT,
        self::SCENARIO_UNEXPECTED_DIVERGENCE,
        self::SCENARIO_DISK_THRESHOLD_EXCEEDED,
    ];

    /**
     * Single entrypoint. Every key is optional; the diagnostic default analyzes
     * an unknown/ambiguous state and fails closed without crashing.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function preflight(array $input = []): array
    {
        // A wiring-phase `fixture` (from --fixture-file) may carry the whole
        // disaster snapshot; merge it under the explicit input so direct keys win.
        $input = $this->mergeFixture($input);

        $area = trim((string) ($input['area'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';
        $runId = trim((string) ($input['run_id'] ?? ''));

        $scenario = $this->normalizeScenario((string) ($input['scenario'] ?? ''));
        $operatorReceipt = $this->resolveOperatorReceipt($input);

        $blockers = [];
        $warnings = [];

        // Diagnose the scenario into a reversible safe plan + a (possibly empty)
        // irreversible plan. NEVER guess: an unknown or ambiguous scenario fails closed.
        $diag = $this->diagnose($scenario, $input, $blockers, $warnings);

        /** @var list<array<string,mixed>> $safeActions */
        $safeActions = $diag['safe_actions'];
        /** @var list<array<string,mixed>> $irreversibleActions */
        $irreversibleActions = $diag['irreversible_actions'];
        $diagnosis = (string) $diag['diagnosis'];
        $ambiguous = (bool) $diag['ambiguous'];
        // A diagnosis may declare itself non-recoverable (e.g. the lock owner is
        // alive => there is no disaster to recover; do NOT touch the lock).
        $forceFailClosed = (bool) ($diag['fail_closed'] ?? false);
        $rawEvidencePreserved = (bool) $diag['raw_evidence_preserved'];

        $requiresIrreversible = $irreversibleActions !== [];
        $operatorAuthorized = $operatorReceipt !== null;

        // Resolve status with the honesty doctrine.
        if ($ambiguous || $forceFailClosed || $scenario === self::SCENARIO_UNKNOWN) {
            // NEVER guess. Ambiguous / undiagnosable / non-recoverable => fail_closed.
            $status = self::STATUS_FAIL_CLOSED;
        } elseif ($requiresIrreversible && ! $operatorAuthorized) {
            // Irreversible repair without an operator receipt is NEVER auto-run.
            $status = self::STATUS_NEEDS_OPERATOR;
            $blockers[] = 'irreversible_repair_requires_operator_receipt';
        } else {
            $status = self::STATUS_RECOVERABLE;
        }

        // Evidence must be preserved BEFORE any cleanup. If a cleanup/irreversible
        // action exists but no preservation step guards it, we cannot claim safety.
        if ($requiresIrreversible && ! $rawEvidencePreserved) {
            $warnings[] = 'raw_evidence_preservation_not_guaranteed';
            // A recovery that would touch irreversible state without preserving
            // evidence first must not be presented as cleanly recoverable.
            if ($status === self::STATUS_RECOVERABLE) {
                $status = self::STATUS_NEEDS_OPERATOR;
                $blockers[] = 'raw_evidence_not_preserved_before_cleanup';
            }
        }

        // Honesty guard: irreversible actions are reported as gated unless authorized.
        $irreversibleActions = array_map(
            function (array $action) use ($operatorAuthorized): array {
                $action['gated_on_operator_receipt'] = true;
                $action['authorized'] = $operatorAuthorized;

                return $action;
            },
            $irreversibleActions,
        );

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-809',
            'slice_id' => 'LHL-17',
            'status' => $status,
            'recovery_id' => 'ldr_'.substr(MissionCanonicalHash::sha256([
                $area,
                $focus,
                $runId,
                $scenario,
                $operatorAuthorized ? '1' : '0',
            ]), 0, 16),
            'run_id' => $runId,
            'area' => $area,
            'focus' => $focus,
            'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'scenario' => $scenario,
            'diagnosis' => $diagnosis,
            'safe_actions' => array_values($safeActions),
            'irreversible_actions' => array_values($irreversibleActions),
            'raw_evidence_preserved' => $rawEvidencePreserved,
            'requires_irreversible_repair' => $requiresIrreversible,
            'operator_receipt_present' => $operatorAuthorized,
            'operator_receipt' => $operatorReceipt,
            'guessed' => false,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'next_action' => $this->nextAction($status, $requiresIrreversible),
            'claim_policy' => [
                'read_only' => true,
                'runs_provider' => false,
                'runs_loop' => false,
                'runs_merge' => false,
                'deletes_branches' => false,
                'destructive_git' => false,
                'guesses_recovery' => false,
                'irreversible_without_receipt' => false,
                'blocked_never_dressed_as_ready' => true,
            ],
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->withoutVolatile($payload));

        return $payload;
    }

    /**
     * Diagnose a scenario into a reversible safe plan + an irreversible plan.
     * NEVER guesses: an unknown scenario or conflicting signals are flagged
     * ambiguous so the caller fails closed.
     *
     * @param  array<string,mixed>  $input
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @return array{diagnosis:string,safe_actions:list<array<string,mixed>>,irreversible_actions:list<array<string,mixed>>,raw_evidence_preserved:bool,ambiguous:bool,fail_closed?:bool}
     */
    private function diagnose(string $scenario, array $input, array &$blockers, array &$warnings): array
    {
        // Multiple conflicting scenario signals at once => we cannot safely pick
        // one. NEVER guess; surface ambiguity so the caller fails closed.
        $signalCount = $this->conflictingSignalCount($input);
        if ($signalCount > 1 && $scenario === self::SCENARIO_UNKNOWN) {
            $blockers[] = 'ambiguous_multiple_disaster_signals';

            return [
                'diagnosis' => 'Multiple conflicting disaster signals present and no scenario selected; recovery cannot be chosen without guessing.',
                'safe_actions' => [
                    $this->safeAction('snapshot_full_state', 'Snapshot the full loop state (ledger, locks, worktrees, evidence) read-only for the operator.'),
                ],
                'irreversible_actions' => [],
                'raw_evidence_preserved' => false,
                'ambiguous' => true,
            ];
        }

        return match ($scenario) {
            self::SCENARIO_CORRUPT_LEDGER_TAIL => $this->diagnoseCorruptLedgerTail($input, $warnings),
            self::SCENARIO_MISSING_LANE_BRANCH => $this->diagnoseMissingLaneBranch($input, $warnings),
            self::SCENARIO_DIRTY_SANDBOX => $this->diagnoseDirtySandbox($input, $warnings),
            self::SCENARIO_LOCK_OWNER_DEAD => $this->diagnoseLockOwnerDead($input, $blockers, $warnings),
            self::SCENARIO_MISSING_EVIDENCE_RECEIPT => $this->diagnoseMissingEvidenceReceipt($input, $blockers, $warnings),
            self::SCENARIO_UNEXPECTED_DIVERGENCE => $this->diagnoseUnexpectedDivergence($input, $blockers, $warnings),
            self::SCENARIO_DISK_THRESHOLD_EXCEEDED => $this->diagnoseDiskThresholdExceeded($input, $warnings),
            default => $this->diagnoseUnknown($blockers),
        };
    }

    /**
     * Corrupt ledger tail: preserve the raw ledger, then (irreversibly) truncate
     * the torn tail back to the last valid append-only record. Truncation rewrites
     * the ledger => irreversible => gated on operator receipt.
     *
     * @param  array<string,mixed>  $input
     * @param  list<string>  $warnings
     * @return array{diagnosis:string,safe_actions:list<array<string,mixed>>,irreversible_actions:list<array<string,mixed>>,raw_evidence_preserved:bool,ambiguous:bool}
     */
    private function diagnoseCorruptLedgerTail(array $input, array &$warnings): array
    {
        $ledgerPath = $this->nullableString($input['ledger_path'] ?? null);
        $lastValid = $this->nullableString($input['last_valid_offset'] ?? ($input['last_valid_record'] ?? null));

        $safe = [
            $this->safeAction(
                'preserve_raw_ledger',
                'Copy the raw ledger to a quarantine sidecar before any truncation.',
                ['ledger_path' => $ledgerPath],
            ),
            $this->safeAction(
                'parse_valid_prefix',
                'Read-only parse the ledger to find the last valid append-only record.',
                ['last_valid' => $lastValid],
            ),
        ];

        if ($lastValid === null) {
            $warnings[] = 'last_valid_ledger_record_unknown';
        }

        $irreversible = [
            $this->irreversibleAction(
                'truncate_corrupt_ledger_tail',
                'Truncate the ledger back to the last valid record (rewrites the ledger file).',
                ['ledger_path' => $ledgerPath, 'truncate_to' => $lastValid],
            ),
        ];

        return [
            'diagnosis' => 'Ledger tail is torn/corrupt; the valid prefix is recoverable after preserving the raw ledger, but truncating the tail is irreversible.',
            'safe_actions' => $safe,
            'irreversible_actions' => $irreversible,
            'raw_evidence_preserved' => true,
            'ambiguous' => false,
        ];
    }

    /**
     * Missing lane branch: re-creating the lane from a known-good ref is reversible
     * (no data lost) when the source ref is known. If unknown, surface ambiguity.
     *
     * @param  array<string,mixed>  $input
     * @param  list<string>  $warnings
     * @return array{diagnosis:string,safe_actions:list<array<string,mixed>>,irreversible_actions:list<array<string,mixed>>,raw_evidence_preserved:bool,ambiguous:bool}
     */
    private function diagnoseMissingLaneBranch(array $input, array &$warnings): array
    {
        $lane = $this->nullableString($input['lane_branch'] ?? null);
        $sourceRef = $this->nullableString($input['lane_recreate_from'] ?? ($input['known_good_ref'] ?? null));

        // No known-good source => we'd be guessing where the lane should point.
        if ($sourceRef === null) {
            return [
                'diagnosis' => 'Integration lane branch is missing and no known-good source ref is provided; the recreate point cannot be chosen without guessing.',
                'safe_actions' => [
                    $this->safeAction('locate_lane_source', 'Read-only inspect reflog/evidence for the lane\'s last known-good ref.', ['lane_branch' => $lane]),
                ],
                'irreversible_actions' => [],
                'raw_evidence_preserved' => true,
                'ambiguous' => true,
            ];
        }

        $warnings[] = 'lane_branch_will_be_recreated_from_known_good_ref';

        return [
            'diagnosis' => 'Integration lane branch is missing but a known-good source ref exists; recreating the lane pointer is reversible (no work discarded).',
            'safe_actions' => [
                $this->safeAction('verify_known_good_ref', 'Verify the known-good ref exists and is reachable (read-only).', ['ref' => $sourceRef]),
                $this->safeAction('recreate_lane_branch', 'Create the lane branch pointing at the known-good ref (additive, no history rewrite).', ['lane_branch' => $lane, 'from' => $sourceRef]),
            ],
            'irreversible_actions' => [],
            'raw_evidence_preserved' => true,
            'ambiguous' => false,
        ];
    }

    /**
     * Dirty sandbox: stash/preserve the dirty worktree, then a force-clean of the
     * sandbox is irreversible (discards uncommitted work) => gated on receipt.
     *
     * @param  array<string,mixed>  $input
     * @param  list<string>  $warnings
     * @return array{diagnosis:string,safe_actions:list<array<string,mixed>>,irreversible_actions:list<array<string,mixed>>,raw_evidence_preserved:bool,ambiguous:bool}
     */
    private function diagnoseDirtySandbox(array $input, array &$warnings): array
    {
        $sandbox = $this->nullableString($input['sandbox_path'] ?? ($input['sandbox_branch'] ?? null));
        $dirtyPaths = AreaFocusStringListNormalizer::preserveStrings($input['dirty_paths'] ?? []);

        if ($dirtyPaths === []) {
            $warnings[] = 'dirty_sandbox_paths_not_enumerated';
        }

        return [
            'diagnosis' => 'A sandbox worktree holds uncommitted changes; the changes can be preserved, but force-cleaning the sandbox discards them irreversibly.',
            'safe_actions' => [
                $this->safeAction('preserve_sandbox_diff', 'Capture the dirty sandbox diff/stash into a quarantine sidecar before cleanup.', ['sandbox' => $sandbox, 'dirty_paths' => $dirtyPaths]),
            ],
            'irreversible_actions' => [
                $this->irreversibleAction('force_clean_sandbox', 'Reset/clean the sandbox worktree to a pristine state (discards uncommitted changes).', ['sandbox' => $sandbox]),
            ],
            'raw_evidence_preserved' => true,
            'ambiguous' => false,
        ];
    }

    /**
     * Lock owner dead: if the prior holder PID is confirmed dead, breaking the
     * stale lock is reversible (just removes a lockfile). If liveness is unknown,
     * we must NOT guess the owner is dead => ambiguous => fail closed.
     *
     * @param  array<string,mixed>  $input
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @return array{diagnosis:string,safe_actions:list<array<string,mixed>>,irreversible_actions:list<array<string,mixed>>,raw_evidence_preserved:bool,ambiguous:bool,fail_closed?:bool}
     */
    private function diagnoseLockOwnerDead(array $input, array &$blockers, array &$warnings): array
    {
        $lockPath = $this->nullableString($input['lock_path'] ?? null);
        $ownerPid = $this->nullableString($input['lock_owner_pid'] ?? null);

        // Only treat the owner as dead when liveness is explicitly proven dead.
        $ownerAliveKnown = array_key_exists('lock_owner_alive', $input);
        $ownerAlive = (bool) ($input['lock_owner_alive'] ?? false);

        if (! $ownerAliveKnown) {
            $blockers[] = 'lock_owner_liveness_unknown';

            return [
                'diagnosis' => 'A loop lock is held but the holder\'s liveness is unknown; assuming the owner is dead would be a guess.',
                'safe_actions' => [
                    $this->safeAction('probe_lock_owner_liveness', 'Read-only probe whether the lock owner PID is still alive.', ['lock_path' => $lockPath, 'owner_pid' => $ownerPid]),
                ],
                'irreversible_actions' => [],
                'raw_evidence_preserved' => true,
                'ambiguous' => true,
            ];
        }

        if ($ownerAlive) {
            $blockers[] = 'lock_owner_still_alive';

            return [
                'diagnosis' => 'The lock owner is still alive; the lock must NOT be broken. There is no disaster to recover from.',
                'safe_actions' => [
                    $this->safeAction('await_lock_owner', 'Wait for the live lock owner to release the lock or honor its lease.', ['lock_path' => $lockPath, 'owner_pid' => $ownerPid]),
                ],
                'irreversible_actions' => [],
                'raw_evidence_preserved' => true,
                'ambiguous' => false,
                'fail_closed' => true,
            ];
        }

        $warnings[] = 'lock_owner_confirmed_dead_lock_break_is_reversible';

        return [
            'diagnosis' => 'The lock owner PID is confirmed dead; removing the stale lockfile is reversible and safe.',
            'safe_actions' => [
                $this->safeAction('archive_stale_lock', 'Copy the stale lockfile to evidence before removing it.', ['lock_path' => $lockPath]),
                $this->safeAction('break_stale_lock', 'Remove the stale lockfile so the next cycle can acquire the lock (reversible: just a lockfile).', ['lock_path' => $lockPath, 'owner_pid' => $ownerPid]),
            ],
            'irreversible_actions' => [],
            'raw_evidence_preserved' => true,
            'ambiguous' => false,
        ];
    }

    /**
     * Missing evidence receipt: NEVER fabricate evidence. A missing receipt for a
     * completed cycle is a hard integrity problem the operator must adjudicate.
     *
     * @param  array<string,mixed>  $input
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @return array{diagnosis:string,safe_actions:list<array<string,mixed>>,irreversible_actions:list<array<string,mixed>>,raw_evidence_preserved:bool,ambiguous:bool}
     */
    private function diagnoseMissingEvidenceReceipt(array $input, array &$blockers, array &$warnings): array
    {
        $cycleRef = $this->nullableString($input['cycle_ref'] ?? ($input['run_id'] ?? null));
        $reconstructable = (bool) ($input['evidence_reconstructable_from_artifacts'] ?? false);

        $blockers[] = 'evidence_receipt_missing_cannot_be_fabricated';

        $safe = [
            $this->safeAction('scan_for_stray_artifacts', 'Read-only scan for stray artifacts that belong to the cycle with the missing receipt.', ['cycle_ref' => $cycleRef]),
        ];

        if ($reconstructable) {
            $warnings[] = 'evidence_receipt_reconstructable_from_existing_artifacts';
            $safe[] = $this->safeAction(
                'rebuild_receipt_from_existing_artifacts',
                'Re-derive the receipt deterministically from artifacts that already exist on disk (additive; fabricates nothing).',
                ['cycle_ref' => $cycleRef],
            );
        }

        return [
            'diagnosis' => 'A completed cycle has no evidence receipt; the receipt cannot be invented. The operator must decide whether to reconstruct from existing artifacts or quarantine the cycle.',
            'safe_actions' => $safe,
            'irreversible_actions' => [],
            'raw_evidence_preserved' => true,
            'ambiguous' => false,
        ];
    }

    /**
     * Unexpected main/lane divergence: reconciling by hard-reset rewrites history
     * => irreversible => gated. We preserve the divergent tip first.
     *
     * @param  array<string,mixed>  $input
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @return array{diagnosis:string,safe_actions:list<array<string,mixed>>,irreversible_actions:list<array<string,mixed>>,raw_evidence_preserved:bool,ambiguous:bool}
     */
    private function diagnoseUnexpectedDivergence(array $input, array &$blockers, array &$warnings): array
    {
        $main = $this->nullableString($input['main_head'] ?? null);
        $lane = $this->nullableString($input['lane_head'] ?? null);
        $expectedAncestor = $this->nullableString($input['expected_merge_base'] ?? null);

        if ($expectedAncestor === null) {
            $blockers[] = 'divergence_expected_merge_base_unknown';

            return [
                'diagnosis' => 'main and the lane have diverged unexpectedly and the expected merge-base is unknown; reconciliation cannot be chosen without guessing.',
                'safe_actions' => [
                    $this->safeAction('snapshot_both_tips', 'Preserve both divergent tips (main + lane) as evidence refs before any reconciliation.', ['main_head' => $main, 'lane_head' => $lane]),
                ],
                'irreversible_actions' => [],
                'raw_evidence_preserved' => true,
                'ambiguous' => true,
            ];
        }

        $warnings[] = 'main_lane_divergence_reconcile_is_irreversible';

        return [
            'diagnosis' => 'main and the lane have diverged unexpectedly; both tips can be preserved, but reconciling by resetting a branch rewrites history irreversibly.',
            'safe_actions' => [
                $this->safeAction('snapshot_both_tips', 'Preserve both divergent tips (main + lane) as evidence refs before any reconciliation.', ['main_head' => $main, 'lane_head' => $lane]),
                $this->safeAction('compute_reconcile_plan', 'Read-only compute the reconciliation against the expected merge-base.', ['expected_merge_base' => $expectedAncestor]),
            ],
            'irreversible_actions' => [
                $this->irreversibleAction('reset_divergent_branch_to_merge_base', 'Reset the unexpectedly-diverged branch back to the expected merge-base (rewrites history).', ['expected_merge_base' => $expectedAncestor]),
            ],
            'raw_evidence_preserved' => true,
            'ambiguous' => false,
        ];
    }

    /**
     * Disk threshold exceeded: pruning OLD, already-preserved artifacts is the
     * reversible safe path; we never delete un-evidenced data. Pause is reversible.
     *
     * @param  array<string,mixed>  $input
     * @param  list<string>  $warnings
     * @return array{diagnosis:string,safe_actions:list<array<string,mixed>>,irreversible_actions:list<array<string,mixed>>,raw_evidence_preserved:bool,ambiguous:bool}
     */
    private function diagnoseDiskThresholdExceeded(array $input, array &$warnings): array
    {
        $usedPct = $input['disk_used_pct'] ?? null;
        $thresholdPct = $input['disk_threshold_pct'] ?? null;

        $warnings[] = 'disk_threshold_exceeded_pause_loop_until_reclaimed';

        return [
            'diagnosis' => 'Disk usage exceeded the configured threshold; the loop should pause (reversible) while old, already-archived artifacts are pruned. Un-evidenced data is never deleted.',
            'safe_actions' => [
                $this->safeAction('pause_loop_for_disk', 'Pause the loop until disk is reclaimed (reversible).', ['used_pct' => $usedPct, 'threshold_pct' => $thresholdPct]),
                $this->safeAction('report_reclaimable_archived_artifacts', 'Read-only report which already-archived artifacts are safe to prune for the operator to approve.', []),
            ],
            'irreversible_actions' => [],
            'raw_evidence_preserved' => true,
            'ambiguous' => false,
        ];
    }

    /**
     * Unknown scenario: NEVER guess. Fail closed with a read-only snapshot.
     *
     * @param  list<string>  $blockers
     * @return array{diagnosis:string,safe_actions:list<array<string,mixed>>,irreversible_actions:list<array<string,mixed>>,raw_evidence_preserved:bool,ambiguous:bool}
     */
    private function diagnoseUnknown(array &$blockers): array
    {
        $blockers[] = 'unknown_or_undiagnosable_disaster_scenario';

        return [
            'diagnosis' => 'No recognized disaster scenario was provided or the state is undiagnosable; recovery must not be attempted (fail closed).',
            'safe_actions' => [
                $this->safeAction('snapshot_full_state', 'Snapshot the full loop state (ledger, locks, worktrees, evidence) read-only for the operator.'),
            ],
            'irreversible_actions' => [],
            'raw_evidence_preserved' => false,
            'ambiguous' => true,
        ];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Count how many distinct disaster signals are present in the raw input. Used
     * only to detect ambiguity when no explicit scenario is selected.
     *
     * @param  array<string,mixed>  $input
     */
    private function conflictingSignalCount(array $input): int
    {
        $signals = 0;
        if ((bool) ($input['ledger_corrupt'] ?? false) || $this->nullableString($input['ledger_path'] ?? null) !== null && (bool) ($input['ledger_corrupt'] ?? false)) {
            $signals++;
        }
        if ((bool) ($input['lane_branch_missing'] ?? false)) {
            $signals++;
        }
        if (AreaFocusStringListNormalizer::preserveStrings($input['dirty_paths'] ?? []) !== [] || (bool) ($input['sandbox_dirty'] ?? false)) {
            $signals++;
        }
        if (array_key_exists('lock_owner_alive', $input) && ! (bool) $input['lock_owner_alive']) {
            $signals++;
        }
        if ((bool) ($input['evidence_receipt_missing'] ?? false)) {
            $signals++;
        }
        if ((bool) ($input['main_lane_diverged'] ?? false)) {
            $signals++;
        }
        if ((bool) ($input['disk_threshold_exceeded'] ?? false)) {
            $signals++;
        }

        return $signals;
    }

    /**
     * A reversible recovery step (safe to auto-apply; never destroys evidence).
     *
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function safeAction(string $action, string $description, array $params = []): array
    {
        return [
            'action' => $action,
            'description' => $description,
            'reversible' => true,
            'destroys_evidence' => false,
            'params' => $this->pruneNull($params),
        ];
    }

    /**
     * An irreversible recovery step (rewrites history / discards data). Always
     * gated on an operator receipt; the gate flags are stamped by the caller.
     *
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function irreversibleAction(string $action, string $description, array $params = []): array
    {
        return [
            'action' => $action,
            'description' => $description,
            'reversible' => false,
            'destroys_evidence' => true,
            'requires_operator_receipt' => true,
            'params' => $this->pruneNull($params),
        ];
    }

    private function nextAction(string $status, bool $requiresIrreversible): string
    {
        return match ($status) {
            self::STATUS_RECOVERABLE => $requiresIrreversible ? 'execute_recovery_plan_with_receipt' : 'execute_safe_recovery_plan',
            self::STATUS_NEEDS_OPERATOR => 'await_operator_receipt',
            default => 'stop_fail_closed',
        };
    }

    private function normalizeScenario(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['-', ' '], '_', $value);

        // Tolerant aliases for the canonical scenarios.
        $value = match ($value) {
            'corrupt_ledger', 'ledger_corruption', 'corrupt_ledger_tail', 'torn_ledger', 'torn_ledger_tail' => self::SCENARIO_CORRUPT_LEDGER_TAIL,
            'missing_lane', 'missing_lane_branch', 'lane_branch_missing', 'lane_missing' => self::SCENARIO_MISSING_LANE_BRANCH,
            'dirty_sandbox', 'sandbox_dirty', 'dirty_worktree_sandbox' => self::SCENARIO_DIRTY_SANDBOX,
            'lock_owner_dead', 'dead_lock_owner', 'stale_lock_owner', 'lock_holder_dead' => self::SCENARIO_LOCK_OWNER_DEAD,
            'missing_evidence_receipt', 'missing_receipt', 'evidence_receipt_missing', 'no_evidence_receipt' => self::SCENARIO_MISSING_EVIDENCE_RECEIPT,
            'unexpected_main_lane_divergence', 'main_lane_divergence', 'divergence', 'unexpected_divergence', 'main_lane_diverged' => self::SCENARIO_UNEXPECTED_DIVERGENCE,
            'disk_threshold_exceeded', 'disk_full', 'disk_threshold', 'low_disk' => self::SCENARIO_DISK_THRESHOLD_EXCEEDED,
            default => $value,
        };

        return in_array($value, self::KNOWN_SCENARIOS, true) ? $value : self::SCENARIO_UNKNOWN;
    }

    /**
     * Resolve an operator receipt from the input seam. A receipt must be a
     * non-empty, identifiable approval — an empty array / blank string is NOT a
     * receipt (no auto-authorization of irreversible repair).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>|null
     */
    private function resolveOperatorReceipt(array $input): ?array
    {
        $receipt = $input['operator_receipt'] ?? null;

        if (is_string($receipt)) {
            $receipt = trim($receipt);

            return $receipt === '' ? null : ['receipt_id' => $receipt];
        }

        if (! is_array($receipt) || $receipt === []) {
            return null;
        }

        // A receipt must carry an identifying field and an explicit approval.
        $id = trim((string) ($receipt['receipt_id'] ?? ($receipt['id'] ?? '')));
        $approved = ! array_key_exists('approved', $receipt) || (bool) $receipt['approved'];
        if ($id === '' || ! $approved) {
            return null;
        }

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function pruneNull(array $params): array
    {
        return array_filter($params, static fn ($v): bool => $v !== null);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * A wiring-phase `fixture` may be a single disaster snapshot; fold it under
     * the explicit input so direct keys still take precedence (input-seam composition).
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
