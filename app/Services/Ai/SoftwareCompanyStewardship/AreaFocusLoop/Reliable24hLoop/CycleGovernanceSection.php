<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusAppendOnlyJsonlRecorder;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusJsonlReader;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusUtcClock;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopCycleFailureTaxonomyService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\ZeroProviderPreflightGate;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hStewardshipRecoveryContract;
use App\Services\Ai\Support\JsonFileStore;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Repo/lock/budget/cascade/outcome-classification + ledger-record predicate family. Extracted VERBATIM from Reliable24hLoopRunnerService by the GOD-DEBULK split; composed back into the facade as a trait. Constants and properties stay on the facade.
 */
trait CycleGovernanceSection
{
    /**
     * @param  array<string,mixed>  $input
     */
    private function repoRootFromInput(array $input): string
    {
        $repoRoot = trim((string) ($input['repo_root'] ?? ''));
        if ($repoRoot !== '') {
            return rtrim($repoRoot, '/');
        }
        if (function_exists('base_path')) {
            try {
                return rtrim((string) base_path(), '/');
            } catch (Throwable) {
                // fall through
            }
        }

        return rtrim((string) (getcwd() ?: ''), '/');
    }

    /**
     * The single-writer guard protects REAL autonomous runs from mutating the
     * canonical checkout. Under the testing environment the loop runs against a
     * FAKED session (setSessionRunnerForTesting) that never mutates the canonical
     * tree, so the guard would only false-positive on fixtures — it is inactive
     * there unless a test explicitly forces it (force_single_writer_guard, used by
     * the guard's own refusal test). In every non-testing environment it is active.
     */
    private function singleWriterGuardActive(array $input): bool
    {
        if ((bool) ($input['force_single_writer_guard'] ?? false)) {
            return true;
        }
        if (function_exists('app')) {
            try {
                if (app()->environment('testing')) {
                    return false;
                }
            } catch (Throwable) {
                // fall through: guard active by default
            }
        }

        return true;
    }

    /**
     * Resolve a ref's commit SHA in repo_root, or '' if it cannot be resolved
     * (degraded/non-git). Used by the merge-truth backstop to prove main advanced.
     */
    private function headSha(string $repoRoot, string $ref): string
    {
        if (trim($repoRoot) === '') {
            return '';
        }
        try {
            $process = new Process(['git', 'rev-parse', '--verify', '--quiet', $ref], $repoRoot);
            $process->setTimeout(15);
            $process->run();

            return $process->isSuccessful() ? trim($process->getOutput()) : '';
        } catch (Throwable) {
            return '';
        }
    }

    private function absoluteRepoPath(string $repoRoot, string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($repoRoot, '/').'/'.ltrim($path, '/');
    }

    /**
     * AP-790 duplicate protection must not starve AP-786 after a blocked cycle.
     * Merged/progress/quarantined findings stay review-locked, but blocked
     * findings are allowed back into selection so repair/quarantine policies and
     * blocked-in-row budgets can act on the same root cause.
     *
     * @param  array<string,bool>  $seenFindingKeys
     * @param  array<string,string>  $seenFindingOutcomes
     * @return array<string,bool>
     */
    private function sessionReviewLockedKeys(array $seenFindingKeys, array $seenFindingOutcomes): array
    {
        $locked = [];
        foreach ($seenFindingKeys as $key => $seen) {
            if ($seen !== true || $key === '') {
                continue;
            }
            if (($seenFindingOutcomes[$key] ?? '') === self::OUTCOME_BLOCKED) {
                continue;
            }
            $locked[$key] = true;
        }

        return $locked;
    }

    /**
     * A repeatedly blocked finding is still retryable at first, but once the
     * durable per-finding cap is reached, every selection path, including the
     * plan-backlog bridge, must skip it before owner runtime can spend tokens.
     *
     * @param  array<string,int>  $blockedAttemptsByFinding
     * @return array<string,bool>
     */
    private function blockedAttemptReviewLocks(array $blockedAttemptsByFinding): array
    {
        $locked = [];
        foreach ($blockedAttemptsByFinding as $key => $attempts) {
            if ($key !== '' && (int) $attempts >= self::MAX_BLOCKED_ATTEMPTS_PER_FINDING) {
                $locked[$key] = true;
            }
        }

        return $locked;
    }

    /**
     * AP-786 is allowed to pierce ordinary review locks for starvation recovery,
     * but terminal blockers are final for the current loop horizon.
     *
     * @param  array<string,bool>  $seenFindingKeys
     * @param  array<string,string>  $seenFindingOutcomes
     * @return array<string,bool>
     */
    private function sessionTerminalLockedKeys(array $seenFindingKeys, array $seenFindingOutcomes): array
    {
        $locked = [];
        foreach ($seenFindingKeys as $key => $seen) {
            if ($seen === true && $key !== '' && ($seenFindingOutcomes[$key] ?? '') === self::OUTCOME_TERMINAL_BLOCKED) {
                $locked[$key] = true;
            }
        }

        return $locked;
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function firstCycle(array $report): array
    {
        $cycles = is_array($report['cycles'] ?? null) ? $report['cycles'] : [];
        foreach ($cycles as $cycle) {
            if (is_array($cycle)) {
                return $cycle;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function findingKey(array $cycle): string
    {
        $finding = is_array($cycle['selected_finding'] ?? null) ? $cycle['selected_finding'] : [];
        // AP-806 slice-progression: each bounded semantic slice is a distinct unit of
        // work, so it carries its own key — otherwise advancing to the next slice of
        // the same parent finding would trip the duplicate-finding stop.
        $sliceId = $this->str($finding['active_slice_id'] ?? '');
        if ($sliceId !== '') {
            return $sliceId;
        }
        $id = $this->str($finding['finding_id'] ?? '');
        if ($id !== '') {
            // AP-806: synthetic starvation/terminal recovery findings carry a
            // per-attempt `_rv_<hash>` suffix that made every re-attempt look like a
            // DISTINCT finding, so the duplicate-finding stop never caught the loop
            // re-merging the SAME recovery state over and over as near-duplicate
            // filler. Collapse the attempt suffix so a repeated recovery of the same
            // state IS detected and the loop stops honestly (stopped_repeated_finding)
            // instead of faking productivity on self-maintenance.
            return (string) preg_replace('/_rv_[0-9a-f]+$/', '', $id);
        }

        return $this->str($finding['finding_hash'] ?? '');
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function classifyOutcome(array $cycle): string
    {
        if ((bool) ($cycle['merge_performed'] ?? false)) {
            return self::OUTCOME_MERGED;
        }
        $finalStatus = $this->str($cycle['final_status'] ?? '');
        $blockers = AreaFocusStringListNormalizer::truthyValues($cycle['blockers'] ?? []);
        if ($finalStatus === 'blocked' || $blockers !== []) {
            return self::OUTCOME_BLOCKED;
        }

        return self::OUTCOME_PROGRESS;
    }

    /**
     * Classify a non-merged cycle into the 5-tier failure taxonomy for cascade
     * detection. Returns ['tier'=>0,'classified'=>false] when no taxonomy is wired
     * (fail-safe: the loop then falls back to plain blocked-in-row counting).
     * Read-only; never gates merge/judge/cleanup — only informs the cascade
     * reaction. `classified` is true only when a known tier needle matched, so the
     * loop never escalates an UNRECOGNISED blocker as a known execution failure.
     *
     * @param  array<string,mixed>  $cycle
     * @return array{tier:int,classified:bool}
     */
    private function classifyFailureTier(array $cycle): array
    {
        $taxonomy = $this->resolveFailureTaxonomy();
        if ($taxonomy === null) {
            return ['tier' => 0, 'classified' => false];
        }

        try {
            $verdict = $taxonomy->classify([
                'final_status' => $this->str($cycle['final_status'] ?? ''),
                'blockers' => AreaFocusStringListNormalizer::coercedStringValues($cycle['blockers'] ?? []),
                'merge_performed' => (bool) ($cycle['merge_performed'] ?? false),
            ]);

            return [
                'tier' => (int) ($verdict['tier'] ?? 0),
                'classified' => (bool) ($verdict['classified'] ?? false),
            ];
        } catch (Throwable) {
            return ['tier' => 0, 'classified' => false];
        }
    }

    /**
     * Decide whether a failure cascade must stop the loop, and how.
     *
     * tier-5 (budget_exhausted) stops IMMEDIATELY on first occurrence — a real
     * budget/backlog ceiling means there is no more work, so STATUS_BUDGET without
     * waiting for the blocked-in-row ceiling. tier-4 (policy_failure) and tier-2
     * (execution_failure) only react once the SAME tier has recurred past the
     * cascade threshold: a tier-4 cascade with the merge budget already spent is an
     * honest STATUS_BUDGET (no merge can happen this run); a CLASSIFIED tier-2
     * cascade halts with a diagnostic. Returns null when the loop should keep going.
     *
     * $failureClassified guards the tier-2 halt: an UNRECOGNISED blocker falls into
     * the generic execution bucket (classified=false) and must NOT halt the loop —
     * different unmapped findings are legitimately worked one after another. Only a
     * known execution failure (senior-loop not passed / provider timeout) that keeps
     * recurring is a genuine cascade that re-running the same way cannot fix.
     *
     * @param  array<string,int|null>  $budgets
     * @return array{status:string,stop_reason:string}|null
     */
    private function cascadeReaction(int $failureTier, bool $failureClassified, int $tierConsecutiveCount, array $budgets, int $mergesThisRun): ?array
    {
        if ($failureTier === LoopCycleFailureTaxonomyService::TIER_BUDGET) {
            // tier-5: terminal budget/backlog ceiling — stop honestly at once.
            return ['status' => self::STATUS_BUDGET, 'stop_reason' => 'budget_exhausted_failure_tier'];
        }

        if ($tierConsecutiveCount < self::CASCADE_CONSECUTIVE_THRESHOLD) {
            return null;
        }

        if ($failureTier === LoopCycleFailureTaxonomyService::TIER_POLICY) {
            // tier-4 policy cascade: only an HONEST stop if the merge budget is
            // exhausted — retrying a merge that can never happen is dishonest.
            // Otherwise keep going so the loop can try a different finding.
            $maxMerges = $budgets['max_merges'] ?? null;
            if ($maxMerges !== null && $mergesThisRun >= (int) $maxMerges) {
                return ['status' => self::STATUS_BUDGET, 'stop_reason' => 'policy_failure_cascade_with_merge_budget_exhausted'];
            }

            return null;
        }

        if ($failureTier === LoopCycleFailureTaxonomyService::TIER_EXECUTION && $failureClassified) {
            // tier-2 execution cascade (KNOWN failure only): a provider/senior-loop
            // that keeps failing will not self-heal by re-running the same way —
            // halt + emit diagnostic. An unclassified blocker never lands here.
            return ['status' => self::STATUS_CASCADE_HALT, 'stop_reason' => 'execution_failure_cascade:tier_2_x'.$tierConsecutiveCount];
        }

        return null;
    }

    /**
     * Prefer the constructor-injected failure taxonomy (unit-test seam), else lazily
     * resolve it from the container at runtime. Nullable + fail-safe so the loop
     * never hard-depends on it; only cascade detection consults it.
     */
    private function resolveFailureTaxonomy(): ?LoopCycleFailureTaxonomyService
    {
        if ($this->failureTaxonomy !== null) {
            return $this->failureTaxonomy;
        }
        if (! function_exists('app')) {
            return null;
        }
        try {
            $resolved = app(LoopCycleFailureTaxonomyService::class);

            return $resolved instanceof LoopCycleFailureTaxonomyService ? $resolved : null;
        } catch (Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Budgets, kill switch, pause
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,int|null>
     */
    private function budgets(array $input): array
    {
        return [
            'max_cycles' => $this->intOrNull($input['max_cycles'] ?? null),
            'max_runtime_minutes' => max(1, (int) ($input['max_runtime_minutes'] ?? 1440)),
            'max_merges' => $this->intOrNull($input['max_merges'] ?? null),
            'max_blocked_in_row' => max(1, (int) ($input['max_blocked_in_row'] ?? 3)),
            // Self-maintenance merge cap (FASE 5). Null = uncapped (back-compat
            // default). When set, the loop stops once self-maintenance merges in
            // THIS run reach the cap, so the loop cannot quietly fill a 24h window
            // with its own recovery work instead of shipping product findings.
            'max_self_maintenance_merges' => $this->intOrNull($input['max_self_maintenance_merges'] ?? null),
        ];
    }

    /**
     * Classify a cycle as product work vs the loop's own self-maintenance/recovery
     * work. Self-maintenance is signalled either explicitly (finding `work_class`
     * or `kind`) or by the synthetic starvation/terminal-recovery finding id, which
     * carries an `_rv_<hash>` attempt suffix. This drives the self-maintenance cap
     * and the metrics read-model; it never invokes a provider or gates a merge.
     *
     * @param  array<string,mixed>  $cycle
     */
    private function workClass(array $cycle): string
    {
        $finding = is_array($cycle['selected_finding'] ?? null) ? $cycle['selected_finding'] : [];

        $explicit = strtolower($this->str($finding['work_class'] ?? ''));
        if ($explicit === self::WORK_CLASS_SELF_MAINTENANCE || $explicit === self::WORK_CLASS_PRODUCT) {
            return $explicit;
        }

        $kind = strtolower($this->str($finding['kind'] ?? ''));
        if ($kind !== '' && (str_contains($kind, 'recovery') || str_contains($kind, 'maintenance') || str_contains($kind, 'self_'))) {
            return self::WORK_CLASS_SELF_MAINTENANCE;
        }

        $findingId = $this->str($finding['finding_id'] ?? '');
        if ($findingId !== '' && preg_match('/_rv_[0-9a-f]+$/', $findingId) === 1) {
            return self::WORK_CLASS_SELF_MAINTENANCE;
        }

        return self::WORK_CLASS_PRODUCT;
    }

    /**
     * @param  array<string,int|null>  $budgets
     */
    private function budgetStop(array $budgets, int $cyclesThisRun, int $mergesThisRun, int $blockedInRow, float $startedAt, bool $allowBlockedRecoveryProbe = false): ?string
    {
        if ($budgets['max_cycles'] !== null && $cyclesThisRun >= $budgets['max_cycles']) {
            return 'max_cycles_reached:'.$budgets['max_cycles'];
        }
        if ($budgets['max_merges'] !== null && $mergesThisRun >= $budgets['max_merges']) {
            return 'max_merges_reached:'.$budgets['max_merges'];
        }
        if ($blockedInRow >= (int) $budgets['max_blocked_in_row']
            && ! ($allowBlockedRecoveryProbe && $cyclesThisRun === 0)) {
            return 'max_blocked_in_row_reached:'.$budgets['max_blocked_in_row'];
        }
        $elapsedMinutes = ($this->time() - $startedAt) / 60.0;
        if ($elapsedMinutes >= (int) $budgets['max_runtime_minutes']) {
            return 'max_runtime_minutes_reached:'.$budgets['max_runtime_minutes'];
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function killSwitchActive(string $areaId, string $focus, array $input): bool
    {
        return (bool) ($input['kill_switch'] ?? false) || is_file($this->killSwitchPath($areaId, $focus));
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function pauseActive(string $areaId, string $focus, array $input): bool
    {
        return (bool) ($input['pause'] ?? false) || is_file($this->pausePath($areaId, $focus));
    }

    // ------------------------------------------------------------------
    // Lock
    // ------------------------------------------------------------------

    /**
     * @return array{acquired:bool,holder?:array<string,mixed>}
     */
    private function acquireLock(string $areaId, string $focus, string $runId, int $leaseTtl, array $input = []): array
    {
        $path = $this->lockPath($areaId, $focus);
        File::ensureDirectoryExists(dirname($path));

        $existing = JsonFileStore::readArray($path);
        if ($existing !== null) {
            $acquiredAt = (float) ($existing['acquired_at_epoch'] ?? 0);
            $ttl = (int) ($existing['lease_ttl_seconds'] ?? 0);
            $expired = ($acquiredAt + $ttl) <= $this->time();
            $orphaned = $this->lockProcessIsDead($existing);
            if (! $expired && ! $orphaned) {
                return ['acquired' => false, 'holder' => $existing];
            }
        }

        $lock = [
            'schema_version' => 'atlas.software_company_stewardship.ap790_loop_lock.v1',
            'run_id' => $runId,
            'area_id' => $areaId,
            'focus' => $focus,
            'pid' => function_exists('getmypid') ? (getmypid() ?: 0) : 0,
            'host' => gethostname() ?: 'unknown',
            'acquired_at' => AreaFocusUtcClock::atomNow(),
            'acquired_at_epoch' => $this->time(),
            'lease_ttl_seconds' => $leaseTtl,
            // Public placement is intentionally label-only. The runner's absolute
            // repo_root can contain a user path and is never serialized into a
            // lock/HTTP surface; branch stays null when Git cannot prove it.
            'runtime' => $this->runtimePlacement($this->repoRootFromInput($input)),
        ];
        File::put($path, json_encode($lock, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return ['acquired' => true, 'holder' => $lock];
    }

    /**
     * @return array{environment:?string,workspace:?string,repository:?string,branch:?string}
     */
    private function runtimePlacement(string $repoRoot): array
    {
        $repoRoot = rtrim(trim($repoRoot), DIRECTORY_SEPARATOR);
        $repository = $repoRoot !== '' ? basename($repoRoot) : null;
        $parent = $repoRoot !== '' ? dirname($repoRoot) : '';
        $workspace = $parent !== '' && $parent !== '.' && $parent !== DIRECTORY_SEPARATOR
            ? basename($parent)
            : null;
        $environment = null;
        if (function_exists('app')) {
            try {
                $environment = app()->environment();
            } catch (Throwable) {
                // Degrade honestly to the process environment below.
            }
        }
        if (! is_string($environment) || trim($environment) === '') {
            $environment = trim((string) (getenv('APP_ENV') ?: '')) ?: null;
        }

        $branch = null;
        if ($repoRoot !== '' && is_dir($repoRoot)) {
            try {
                $process = new Process(['git', 'branch', '--show-current'], $repoRoot);
                $process->setTimeout(10);
                $process->run();
                $candidate = trim($process->getOutput());
                $branch = $process->isSuccessful() && $candidate !== '' ? $candidate : null;
            } catch (Throwable) {
                // A non-git or degraded runtime has no provable branch.
            }
        }

        return [
            'environment' => $environment,
            'workspace' => $workspace,
            'repository' => $repository,
            'branch' => $branch,
        ];
    }

    /** @param array<string,mixed> $lock */
    private function lockProcessIsDead(array $lock): bool
    {
        $pid = (int) ($lock['pid'] ?? 0);
        if ($pid < 1) {
            return false;
        }

        $host = (string) ($lock['host'] ?? '');
        $currentHost = gethostname() ?: 'unknown';
        if ($host !== '' && $host !== $currentHost) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return ! @posix_kill($pid, 0);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        $result = trim((string) shell_exec('ps -p '.escapeshellarg((string) $pid).' -o pid= 2>/dev/null'));

        return $result === '';
    }

    private function releaseLock(string $areaId, string $focus, string $runId): void
    {
        $path = $this->lockPath($areaId, $focus);
        $existing = JsonFileStore::readArray($path);
        // Only release a lock this run actually holds — never delete another
        // instance's lock.
        if ($existing !== null && (string) ($existing['run_id'] ?? '') === $runId && is_file($path)) {
            @unlink($path);
        }
    }

    // ------------------------------------------------------------------
    // Ledger + crash recovery
    // ------------------------------------------------------------------

    /**
     * @return array{last_cycle_index:int,merges_total:int,blocked_in_row:int,seen_finding_keys:array<string,bool>,seen_finding_outcomes:array<string,string>,blocked_attempts_by_finding:array<string,int>}
     */
    private function resumeState(string $areaId, string $focus): array
    {
        $path = $this->ledgerPath($areaId, $focus);
        $state = [
            'last_cycle_index' => 0,
            'merges_total' => 0,
            'blocked_in_row' => 0,
            'seen_finding_keys' => [],
            'seen_finding_outcomes' => [],
            'blocked_attempts_by_finding' => [],
        ];
        if (! is_file($path)) {
            return $state;
        }

        foreach (AreaFocusJsonlReader::streamRowsWithSchemaVersion($path, self::LEDGER_SCHEMA) as $record) {
            $state['last_cycle_index'] = max($state['last_cycle_index'], (int) ($record['cycle_index'] ?? 0));
            if ($this->ledgerRecordCountsAsMerge($record)) {
                $state['merges_total']++;
            }
            $state['blocked_in_row'] = (int) data_get($record, 'cumulative.blocked_in_row', $state['blocked_in_row']);
            // Resume the per-finding blocked-attempt count so the retry cap survives
            // across run() invocations (not just within one process). A NON-terminal,
            // non-transient blocked cycle is exactly the case that re-enters selection
            // forever when the loop runs as short invocations; count it under the same
            // finding_key the live loop increments so MAX_BLOCKED_ATTEMPTS_PER_FINDING is
            // honest end-to-end. Terminal/transient/merged/progress outcomes are excluded
            // (terminal already locks; transient must be retried fresh).
            if ($this->ledgerRecordCountsAsBlockedAttempt($record)) {
                $attemptKey = $this->str($record['finding_key'] ?? '');
                if ($attemptKey !== '') {
                    $state['blocked_attempts_by_finding'][$attemptKey] = ($state['blocked_attempts_by_finding'][$attemptKey] ?? 0) + 1;
                }
            }
            $keys = [];
            $recordQuarantined = (bool) ($record['quarantined'] ?? false);
            if ($this->ledgerRecordLocksFindingAcrossRuns($record) || $recordQuarantined) {
                $keys = array_merge([$this->str($record['finding_key'] ?? '')], AreaFocusStringListNormalizer::coercedTrimmedUniqueStringOrNumberValues($record['finding_keys'] ?? []));
            }
            foreach ($keys as $key) {
                if ($key === '') {
                    continue;
                }
                $state['seen_finding_keys'][$key] = true;
                $state['seen_finding_outcomes'][$key] = ($recordQuarantined || $this->ledgerRecordIsTerminalBlocked($record))
                    ? self::OUTCOME_TERMINAL_BLOCKED
                    : $this->str($record['outcome'] ?? '');
            }
        }

        foreach ($this->quarantine->quarantinedFindingKeys($areaId, $focus) as $key => $seen) {
            if ($seen === true && $key !== '') {
                $state['seen_finding_keys'][$key] = true;
                $state['seen_finding_outcomes'][$key] = self::OUTCOME_TERMINAL_BLOCKED;
            }
        }

        return $state;
    }

    /**
     * A ledger record counts toward the per-finding blocked-attempt cap when it is a
     * real, non-terminal, non-transient blocked verdict. These are precisely the
     * cycles that re-enter selection (repair may finish), so they must accumulate
     * across runs — otherwise a packet that blocks once per short run is re-offered
     * forever and the loop never advances to a different admissible packet.
     *
     * Dry-run rows, transient-infra blocks and terminal blocks are excluded: dry runs
     * spend no real attempt, transient blocks produced no verdict and must retry
     * fresh, and terminal blocks already lock the finding for the loop horizon.
     *
     * @param  array<string,mixed>  $record
     */
    private function ledgerRecordCountsAsBlockedAttempt(array $record): bool
    {
        if ($this->str($record['outcome'] ?? '') !== self::OUTCOME_BLOCKED) {
            return false;
        }
        if ($this->ledgerRecordLooksLegacyPlanSliceScopeLayerFalsePositive($record)) {
            return false;
        }
        if ($this->ledgerRecordLooksExecutableContractGateFalsePositive($record)) {
            return false;
        }
        if ($this->str($record['cycle_final_status'] ?? '') === 'dry_run_planned'
            || $this->str($record['session_status'] ?? '') === self::STATUS_DRY_RUN) {
            return false;
        }
        $blockers = (array) ($record['blockers'] ?? []);
        if (in_array('review_locked_existing_branch', array_map(fn (mixed $blocker): string => $this->str($blocker), $blockers), true)) {
            return false;
        }
        if ($this->quarantine->hasTransientBlocker($blockers)) {
            return false;
        }

        return ! $this->containsTerminalBlocker($blockers);
    }

    /** @param array<string,mixed> $record */
    private function ledgerRecordLocksFindingAcrossRuns(array $record): bool
    {
        if ($this->ledgerRecordLooksLegacyPlanSliceScopeLayerFalsePositive($record)) {
            return false;
        }
        if ($this->ledgerRecordLooksExecutableContractGateFalsePositive($record)) {
            return false;
        }
        if ($this->str($record['cycle_final_status'] ?? '') === 'dry_run_planned'
            || $this->str($record['session_status'] ?? '') === self::STATUS_DRY_RUN) {
            return false;
        }
        if ($this->ledgerRecordIsTerminalBlocked($record)) {
            return true;
        }

        return in_array($this->str($record['outcome'] ?? ''), [
            self::OUTCOME_MERGED,
            self::OUTCOME_REPEATED,
        ], true);
    }

    /** @param array<string,mixed> $record */
    private function ledgerRecordIsTerminalBlocked(array $record): bool
    {
        if ($this->str($record['outcome'] ?? '') !== self::OUTCOME_BLOCKED) {
            return false;
        }
        if ($this->ledgerRecordLooksLegacyPlanSliceScopeLayerFalsePositive($record)) {
            return false;
        }
        if ($this->ledgerRecordLooksExecutableContractGateFalsePositive($record)) {
            return false;
        }

        $blockers = (array) ($record['blockers'] ?? []);
        // A transient infra failure (provider timeout / rate limit / outage)
        // never permanently locks a finding across runs — the attempt produced
        // no real verdict and must be retried once infra recovers.
        if ($this->quarantine->hasTransientBlocker($blockers)) {
            return false;
        }

        return $this->containsTerminalBlocker($blockers);
    }

    /** @param array<string,mixed> $record */
    private function ledgerRecordLooksExecutableContractGateFalsePositive(array $record): bool
    {
        $blockers = AreaFocusStringListNormalizer::trimmedScalarValues((array) ($record['blockers'] ?? []));
        if (! in_array(AutonomousEvolutionSessionService::PROVIDER_DIFF_QUALITY_BLOCKER, $blockers, true)
            || ! in_array('contract_only_diff_without_runtime_wiring', $blockers, true)) {
            return false;
        }

        $planBacklog = is_array($record['plan_backlog'] ?? null) ? $record['plan_backlog'] : [];
        $sliceId = $this->str($planBacklog['slice_id'] ?? $record['finding_key'] ?? '');
        $allowedFiles = (array) ($planBacklog['allowed_files'] ?? []);
        if (preg_match('/^S\d+$/', $sliceId) !== 1 || $allowedFiles === []) {
            return false;
        }

        $hasExecutableContract = false;
        foreach ($allowedFiles as $file) {
            $path = str_replace('\\', '/', $this->str($file));
            if ($path === '' || str_starts_with($path, 'tests/') || str_starts_with($path, 'test/') || str_ends_with($path, 'Test.php')) {
                continue;
            }
            if (! str_ends_with(basename($path), 'Contract.php')) {
                return false;
            }
            $hasExecutableContract = true;
        }

        return $hasExecutableContract;
    }

    /** @param array<string,mixed> $record */
    private function ledgerRecordLooksLegacyPlanSliceScopeLayerFalsePositive(array $record): bool
    {
        $blockers = AreaFocusStringListNormalizer::trimmedScalarValues((array) ($record['blockers'] ?? []));
        if (! in_array(ZeroProviderPreflightGate::REASON_PRIOR_NON_RETRYABLE_FAILURE_PATTERN, $blockers, true)
            || ! in_array(ZeroProviderPreflightGate::REASON_SCOPE_MULTIPLE_LAYERS, $blockers, true)) {
            return false;
        }

        $planBacklog = is_array($record['plan_backlog'] ?? null) ? $record['plan_backlog'] : [];
        $sliceId = $this->str($planBacklog['slice_id'] ?? $record['finding_key'] ?? '');
        $allowedFiles = (array) ($planBacklog['allowed_files'] ?? []);
        if ($allowedFiles === []) {
            return false;
        }

        return preg_match('/^S\d+$/', $sliceId) === 1 && $this->productionLayerCountIgnoringTests($allowedFiles) <= 1;
    }

    /** @param array<int|string,mixed> $files */
    private function productionLayerCountIgnoringTests(array $files): int
    {
        $layers = [];
        foreach ($files as $file) {
            $path = str_replace('\\', '/', $this->str($file));
            $path = ltrim($path, '/');
            if ($path === '' || str_starts_with($path, 'tests/') || str_starts_with($path, 'test/') || str_ends_with($path, 'Test.php')) {
                continue;
            }
            $parts = array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''));
            $layer = count($parts) >= 2 ? $parts[0].'/'.$parts[1] : ($parts[0] ?? '');
            if ($layer !== '') {
                $layers[$layer] = true;
            }
        }

        return count($layers);
    }

    /** @param array<string,mixed> $record */
    private function ledgerRecordCountsAsMerge(array $record): bool
    {
        return Reliable24hStewardshipRecoveryContract::ledgerRecordCountsAsMerge($record);
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function shouldContinueOnBlockedCycle(array $input, Reliable24hStewardshipRecoveryContract $stewardshipRecovery): bool
    {
        if (array_key_exists('continue_on_blocked', $input)) {
            return (bool) $input['continue_on_blocked'];
        }

        return $stewardshipRecovery->continueOnBlockedDefault((bool) ($input['execute'] ?? false));
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function allowBlockedRecoveryProbe(array $input, Reliable24hStewardshipRecoveryContract $stewardshipRecovery): bool
    {
        return $this->shouldContinueOnBlockedCycle($input, $stewardshipRecovery);
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function appendLedger(string $areaId, string $focus, array $receipt): void
    {
        $path = $this->ledgerPath($areaId, $focus);
        AreaFocusAppendOnlyJsonlRecorder::append($path, $receipt);
    }

}
