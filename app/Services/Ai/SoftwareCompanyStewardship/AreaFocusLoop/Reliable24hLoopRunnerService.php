<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * AP-790 · reliable 24h autonomous loop runner.
 *
 * A durable supervisor that keeps the AP-786 autonomous evolution session running
 * for a long horizon (e.g. 24h) on a single area/focus, surviving blockers,
 * crashes, pauses and kill switches. It wraps AP-786 one cycle at a time and owns
 * everything around the cycle: an exclusive lock, an append-only run ledger,
 * cycle/runtime/merge/blocked budgets, a rate limit, pause + kill-switch files,
 * crash recovery from the last receipt, duplicate-finding protection and safe
 * worktree cleanup hooks.
 *
 * It deliberately does NOT reimplement finding selection, provider execution or
 * merge — those stay in AP-786 (AutonomousEvolutionSessionService) and the
 * owner-flow chain. The runner never invokes a provider, never merges and never
 * mutates the repo itself; it only orchestrates, records and governs the loop.
 *
 * Real-authority rule (AP-78x family): at runtime the loop ALWAYS drives the real
 * injected AP-786 session. The `*ForTesting` seams (session runner, clock, sleeper)
 * are test doubles confined to unit tests — they must never be wired at runtime,
 * never appear in docs as authority and never influence claim_policy. When real
 * authority is absent the wrapped AP-786 cycle is `blocked`/`partial`, and the
 * runner records exactly that; a synthetic or "valid shape" session can never make
 * the loop report genuine progress or a merge.
 */
final class Reliable24hLoopRunnerService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.ap790_reliable_24h_loop.v1';

    public const LEDGER_SCHEMA = 'atlas.software_company_stewardship.ap790_reliable_24h_loop_cycle.v1';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DRY_RUN = 'dry_run_planned';

    public const STATUS_LOCK_HELD = 'lock_held';

    public const STATUS_KILLED = 'stopped_kill_switch';

    public const STATUS_PAUSED = 'stopped_pause';

    public const STATUS_BUDGET = 'stopped_budget';

    public const STATUS_BLOCKED_STOP = 'stopped_on_blocked';

    public const STATUS_REPEATED = 'stopped_repeated_finding';

    public const SCHEDULER_BACKLOG_BRIDGE_SCHEMA = 'atlas.software_company_stewardship.ap790_continuous_24h_scheduler_backlog.v1';

    public const AP790_BACKLOG_CONTINUOUS_24H_SCHEDULER = 'continuous_24h_scheduler';

    /** Upper bound for operator-facing cycle slices (continuous 24h scheduler observability). */
    public const DEFAULT_BOUNDED_CYCLE_WINDOW = 20;

    private const OUTCOME_MERGED = 'merged';

    private const OUTCOME_BLOCKED = 'blocked';

    private const OUTCOME_PROGRESS = 'progress';

    private const OUTCOME_REPEATED = 'repeated_finding';

    private const OUTCOME_TERMINAL_BLOCKED = 'terminal_blocked';

    private const TERMINAL_BLOCKERS = [
        'owner_runtime_senior_loop_repair_exhausted',
        'quarantine_after_repair_exhausted',
        'repair_exhausted',
    ];

    /** Absolute safety cap so the loop can never spin forever within one process. */
    private const HARD_ITERATION_CAP = 1000;

    private ?string $storageRootOverride = null;

    /** @var null|callable(array<string,mixed>):array<string,mixed> */
    private $sessionRunner = null;

    /** @var null|callable():float */
    private $clock = null;

    /** @var null|callable(int):void */
    private $sleeper = null;

    public function __construct(
        private readonly AutonomousEvolutionSessionService $session,
        private readonly AreaFocusBranchSandboxMaterializer $materializer,
        private readonly AreaFocusCandidateQuarantineService $quarantine,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    /**
     * @param  callable(array<string,mixed>):array<string,mixed>  $runner
     */
    public function setSessionRunnerForTesting(callable $runner): void
    {
        $this->sessionRunner = $runner;
    }

    /**
     * @param  callable():float  $clock
     */
    public function setClockForTesting(callable $clock): void
    {
        $this->clock = $clock;
    }

    /**
     * @param  callable(int):void  $sleeper
     */
    public function setSleeperForTesting(callable $sleeper): void
    {
        $this->sleeper = $sleeper;
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/reliable_24h_loop')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/reliable_24h_loop';
    }

    public function ledgerPath(string $areaId, string $focus): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->key($areaId, $focus).'.jsonl';
    }

    public function lockPath(string $areaId, string $focus): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.'locks'.DIRECTORY_SEPARATOR.$this->key($areaId, $focus).'.lock';
    }

    public function killSwitchPath(string $areaId, string $focus): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->key($areaId, $focus).'.kill';
    }

    public function pausePath(string $areaId, string $focus): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->key($areaId, $focus).'.pause';
    }

    /**
     * Read-only: append-only AP-790 cycle ledger for observability surfaces.
     *
     * @return list<array<string,mixed>>
     */
    public function readLedgerRecords(string $areaId, string $focus = 'dev_forge'): array
    {
        $path = $this->ledgerPath($areaId, $focus);
        if (! is_file($path)) {
            return [];
        }

        $records = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && (string) ($decoded['schema_version'] ?? '') === self::LEDGER_SCHEMA) {
                $records[] = $decoded;
            }
        }

        return $records;
    }

    /**
     * Read-only lock snapshot for 24h readiness/observability.
     *
     * @return array{available:bool,held:bool,holder:array<string,mixed>|null,path:string}
     */
    public function lockStatus(string $areaId, string $focus = 'dev_forge'): array
    {
        $path = $this->lockPath($areaId, $focus);
        $holder = $this->readJson($path);
        if ($holder === null) {
            return ['available' => true, 'held' => false, 'holder' => null, 'path' => $path];
        }

        $acquiredAt = (float) ($holder['acquired_at_epoch'] ?? 0);
        $ttl = (int) ($holder['lease_ttl_seconds'] ?? 0);
        $expired = ($acquiredAt + $ttl) <= $this->time();

        return [
            'available' => $expired,
            'held' => ! $expired,
            'holder' => $expired ? null : $holder,
            'path' => $path,
        ];
    }

    /**
     * @return array{active:bool,path:string}
     */
    public function killSwitchStatus(string $areaId, string $focus = 'dev_forge'): array
    {
        $path = $this->killSwitchPath($areaId, $focus);

        return ['active' => is_file($path), 'path' => $path];
    }

    /**
     * @return array{active:bool,path:string}
     */
    public function pauseStatus(string $areaId, string $focus = 'dev_forge'): array
    {
        $path = $this->pausePath($areaId, $focus);

        return ['active' => is_file($path), 'path' => $path];
    }

    /**
     * AP-790 · materialize continuous 24h scheduler backlog into bounded observability.
     *
     * Read-only aggregate for priority-engine backlog `continuous_24h_scheduler`: blocked,
     * merged and crash-recovered cycles stay visible without unbounded ledger replay.
     *
     * @return array<string,mixed>
     */
    public function continuous24hSchedulerBacklogObservability(string $areaId, string $focus = 'dev_forge', int $recentLimit = self::DEFAULT_BOUNDED_CYCLE_WINDOW): array
    {
        $recentLimit = max(1, min($recentLimit, self::DEFAULT_BOUNDED_CYCLE_WINDOW));
        $records = $this->readLedgerRecords($areaId, $focus);
        $resume = $this->resumeState($areaId, $focus);
        $outcomeCounts = [
            self::OUTCOME_BLOCKED => 0,
            self::OUTCOME_MERGED => 0,
            self::OUTCOME_PROGRESS => 0,
            self::OUTCOME_REPEATED => 0,
        ];
        foreach ($records as $record) {
            $outcome = $this->str($record['outcome'] ?? '');
            if (array_key_exists($outcome, $outcomeCounts)) {
                $outcomeCounts[$outcome]++;
            }
        }

        $recentCycles = [];
        foreach (array_slice($records, -$recentLimit) as $record) {
            $recentCycles[] = $this->cycleSummary($record);
        }

        return [
            'schema_version' => self::SCHEDULER_BACKLOG_BRIDGE_SCHEMA,
            'ap790_backlog_item' => self::AP790_BACKLOG_CONTINUOUS_24H_SCHEDULER,
            'bounded_by' => ['recent_cycles_limit' => $recentLimit],
            'outcome_counts' => $outcomeCounts,
            'recovery' => [
                'recovered' => (int) $resume['last_cycle_index'] > 0,
                'last_cycle_index' => (int) $resume['last_cycle_index'],
                'merges_total' => (int) $resume['merges_total'],
                'blocked_in_row' => (int) $resume['blocked_in_row'],
                'seen_finding_count' => count($resume['seen_finding_keys']),
            ],
            'recent_cycles' => $recentCycles,
            'lock' => $this->lockStatus($areaId, $focus),
            'kill_switch' => $this->killSwitchStatus($areaId, $focus),
            'pause' => $this->pauseStatus($areaId, $focus),
            'ledger_record_count' => count($records),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input = []): array
    {
        $areaId = $this->slug((string) ($input['area_id'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = $this->slug((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';
        $dryRun = (bool) ($input['dry_run'] ?? false);
        $execute = (bool) ($input['execute'] ?? false) && ! $dryRun;
        if (! array_key_exists('continue_on_blocked', $input)) {
            $input['continue_on_blocked'] = $execute;
        }
        $budgets = $this->budgets($input);
        $sleepSeconds = max(0, (int) ($input['sleep_seconds'] ?? 0));
        $leaseTtl = max(60, (int) ($input['lock_lease_seconds'] ?? 3600));
        $runId = 'ap790run_'.substr(MissionCanonicalHash::sha256([$areaId, $focus, $this->now(), random_int(0, PHP_INT_MAX)]), 0, 18);

        // 1. Kill switch and pause are checked before touching the lock so they stop
        // cleanly without acquiring or disturbing another instance's lock.
        if ($this->killSwitchActive($areaId, $focus, $input)) {
            return $this->report($areaId, $focus, $runId, self::STATUS_KILLED, 'kill_switch_active', [], $budgets, $execute, $dryRun, null, 0, 0, 0);
        }
        if ($this->pauseActive($areaId, $focus, $input)) {
            return $this->report($areaId, $focus, $runId, self::STATUS_PAUSED, 'pause_file_present', [], $budgets, $execute, $dryRun, null, 0, 0, 0);
        }

        // 2. Exclusive lock per area/focus.
        $lock = $this->acquireLock($areaId, $focus, $runId, $leaseTtl);
        if ($lock['acquired'] !== true) {
            return $this->report($areaId, $focus, $runId, self::STATUS_LOCK_HELD, 'lock_held_by_'.(string) ($lock['holder']['run_id'] ?? 'unknown'), [], $budgets, $execute, $dryRun, $lock['holder'] ?? null, 0, 0, 0);
        }

        try {
            // 3. Crash recovery: resume cumulative counters and seen findings from the ledger.
            $resume = $this->resumeState($areaId, $focus);
            $cycleIndex = (int) $resume['last_cycle_index'];
            $mergesTotal = (int) $resume['merges_total'];
            $seenFindingKeys = $resume['seen_finding_keys'];
            $seenFindingOutcomes = $resume['seen_finding_outcomes'];
            $blockedInRow = (int) $resume['blocked_in_row'];
            $lastBlockedFindingKey = '';

            $cyclesThisRun = 0;
            $cycleReports = [];
            $startedAt = $this->time();
            $status = $execute ? self::STATUS_COMPLETED : self::STATUS_DRY_RUN;
            $stopReason = 'budget_or_no_more_work';

            $this->sweepMergedCleanSandboxes($input, $execute, $areaId);

            for ($iteration = 0; $iteration < self::HARD_ITERATION_CAP; $iteration++) {
                // Re-check kill/pause every iteration so mid-loop signals stop cleanly.
                if ($this->killSwitchActive($areaId, $focus, [])) {
                    $status = self::STATUS_KILLED;
                    $stopReason = 'kill_switch_active';
                    break;
                }
                if ($this->pauseActive($areaId, $focus, [])) {
                    $status = self::STATUS_PAUSED;
                    $stopReason = 'pause_file_present';
                    break;
                }

                // Budget checks before spending a cycle.
                $budgetStop = $this->budgetStop($budgets, $cyclesThisRun, $mergesTotal, $blockedInRow, $startedAt);
                if ($budgetStop !== null) {
                    $status = self::STATUS_BUDGET;
                    $stopReason = $budgetStop;
                    break;
                }

                $cycleIndex++;
                $cyclesThisRun++;

                $sessionReport = $this->invokeSession($input, $areaId, $focus, $execute, $seenFindingKeys, $seenFindingOutcomes);
                $cycle = $this->firstCycle($sessionReport);
                $findingKey = $this->findingKey($cycle);

                // Duplicate-finding protection: never grind the same finding after it already
                // made forward progress; consecutive blocked outcomes on the same finding are
                // allowed so blocked_in_row budgets and quarantine can apply.
                $priorOutcome = $findingKey !== '' ? ($seenFindingOutcomes[$findingKey] ?? null) : null;
                $currentBlockers = array_values(array_filter((array) ($cycle['blockers'] ?? [])));
                $currentMerged = (bool) ($cycle['merge_performed'] ?? false);
                if ($findingKey !== '' && isset($seenFindingKeys[$findingKey]) && $priorOutcome !== self::OUTCOME_BLOCKED && $currentBlockers === [] && ! $currentMerged) {
                    $receipt = $this->cycleReceipt($runId, $cycleIndex, $findingKey, self::OUTCOME_REPEATED, $sessionReport, $cycle, $cyclesThisRun, $mergesTotal, $blockedInRow);
                    $this->appendLedger($areaId, $focus, $receipt);
                    $cycleReports[] = $this->cycleSummary($receipt);
                    $status = self::STATUS_REPEATED;
                    $stopReason = 'repeated_finding:'.$findingKey;
                    break;
                }

                $outcome = $this->classifyOutcome($cycle);
                if ($outcome === self::OUTCOME_MERGED) {
                    $mergesTotal++;
                    $blockedInRow = 0;
                    $this->safeCleanup($input, $execute, $cycle, $areaId);
                } elseif ($outcome === self::OUTCOME_BLOCKED) {
                    if ($findingKey !== '' && $findingKey === $lastBlockedFindingKey) {
                        $blockedInRow++;
                    } elseif ($findingKey !== '') {
                        $blockedInRow = 1;
                        $lastBlockedFindingKey = $findingKey;
                    } else {
                        $blockedInRow++;
                    }
                } else {
                    $blockedInRow = 0;
                    $lastBlockedFindingKey = '';
                }

                if ($findingKey !== '') {
                    $seenFindingKeys[$findingKey] = true;
                    $seenFindingOutcomes[$findingKey] = $this->terminalBlocked($cycle)
                        ? self::OUTCOME_TERMINAL_BLOCKED
                        : $outcome;
                }

                $receipt = $this->cycleReceipt($runId, $cycleIndex, $findingKey, $outcome, $sessionReport, $cycle, $cyclesThisRun, $mergesTotal, $blockedInRow);
                $this->appendLedger($areaId, $focus, $receipt);
                $cycleReports[] = $this->cycleSummary($receipt);
                if ($outcome === self::OUTCOME_MERGED) {
                    $this->safeCleanup($input, $execute, $cycle, $areaId);
                }

                // A blocked cycle stops the loop only when continuation is not allowed.
                if ($outcome === self::OUTCOME_BLOCKED && ! (bool) ($input['continue_on_blocked'] ?? false)) {
                    $status = self::STATUS_BLOCKED_STOP;
                    $stopReason = 'blocked_cycle_without_continue_on_blocked';
                    break;
                }

                // Rate limit between cycles.
                if ($sleepSeconds > 0) {
                    $this->sleep($sleepSeconds);
                }
            }

            return $this->report(
                $areaId, $focus, $runId, $status, $stopReason, $cycleReports, $budgets, $execute, $dryRun,
                null, $cyclesThisRun, $mergesTotal, $blockedInRow,
                resumedFrom: (int) $resume['last_cycle_index'],
                cyclesTotal: $cycleIndex,
                seenFindingCount: count($seenFindingKeys),
            );
        } finally {
            $this->releaseLock($areaId, $focus, $runId);
        }
    }

    // ------------------------------------------------------------------
    // Session invocation (wraps AP-786, never reimplements it)
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,bool>  $seenFindingKeys
     * @param  array<string,string>  $seenFindingOutcomes
     * @return array<string,mixed>
     */
    private function invokeSession(array $input, string $areaId, string $focus, bool $execute, array $seenFindingKeys, array $seenFindingOutcomes): array
    {
        $sessionInput = [
            'area_id' => $areaId,
            'focus' => $focus,
            'cycles' => 1,
            'provider' => (string) ($input['provider'] ?? 'cursor_cli'),
            'model' => (string) ($input['model'] ?? ''),
            'scope_profile' => (string) ($input['scope_profile'] ?? 'factory_max'),
            'repo_root' => (string) ($input['repo_root'] ?? ''),
            'actor' => (string) ($input['actor'] ?? 'operator'),
            'execute' => $execute,
            'auto_merge' => (bool) ($input['auto_merge'] ?? false),
            'allow_code_auto_merge' => (bool) ($input['allow_code_auto_merge'] ?? false),
            'allow_direct_provider_driver' => (bool) ($input['allow_direct_provider_driver'] ?? false),
            'continue_on_blocked' => (bool) ($input['continue_on_blocked'] ?? false),
            'pull_main' => (bool) ($input['pull_main'] ?? false),
            'record' => (bool) ($input['record'] ?? false),
            'max_findings' => (int) ($input['max_findings'] ?? 200),
            'max_auto_merge_files' => (int) ($input['max_auto_merge_files'] ?? 5),
            'validation_commands' => array_values(array_filter((array) ($input['validation_commands'] ?? []), 'is_string')),
            'session_review_locked' => $this->sessionReviewLockedKeys($seenFindingKeys, $seenFindingOutcomes),
            'session_terminal_locked' => $this->sessionTerminalLockedKeys($seenFindingKeys, $seenFindingOutcomes),
        ];
        foreach ([
            'forge_obra', 'obra_id', 'forge_live_topology', 'forge_live_decision',
            'forge_dispatch_mode', 'forge_role', 'forge_provider_authorization',
            'forge_budget_approved', 'forge_tickets', 'forge_agents',
        ] as $key) {
            if (array_key_exists($key, $input)) {
                $sessionInput[$key] = $input[$key];
            }
        }
        if (isset($input['forge_inputs']) && is_array($input['forge_inputs'])) {
            $sessionInput['forge_inputs'] = $input['forge_inputs'];
        }

        $runner = $this->sessionRunner ?? fn (array $in): array => $this->session->run($in);

        try {
            $report = $runner($sessionInput);

            return is_array($report) ? $report : [];
        } catch (Throwable $e) {
            return [
                'status' => 'blocked',
                'cycles' => [[
                    'final_status' => 'blocked',
                    'blockers' => ['session_invocation_failed'],
                    'selected_finding' => [],
                    'error' => $e->getMessage(),
                ]],
            ];
        }
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
        $id = $this->str($finding['finding_id'] ?? '');
        if ($id !== '') {
            return $id;
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
        $blockers = array_values(array_filter((array) ($cycle['blockers'] ?? [])));
        if ($finalStatus === 'blocked' || $blockers !== []) {
            return self::OUTCOME_BLOCKED;
        }

        return self::OUTCOME_PROGRESS;
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
        ];
    }

    /**
     * @param  array<string,int|null>  $budgets
     */
    private function budgetStop(array $budgets, int $cyclesThisRun, int $mergesTotal, int $blockedInRow, float $startedAt): ?string
    {
        if ($budgets['max_cycles'] !== null && $cyclesThisRun >= $budgets['max_cycles']) {
            return 'max_cycles_reached:'.$budgets['max_cycles'];
        }
        if ($budgets['max_merges'] !== null && $mergesTotal >= $budgets['max_merges']) {
            return 'max_merges_reached:'.$budgets['max_merges'];
        }
        if ($blockedInRow >= (int) $budgets['max_blocked_in_row']) {
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
    private function acquireLock(string $areaId, string $focus, string $runId, int $leaseTtl): array
    {
        $path = $this->lockPath($areaId, $focus);
        File::ensureDirectoryExists(dirname($path));

        $existing = $this->readJson($path);
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
            'acquired_at' => $this->now(),
            'acquired_at_epoch' => $this->time(),
            'lease_ttl_seconds' => $leaseTtl,
        ];
        File::put($path, json_encode($lock, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return ['acquired' => true, 'holder' => $lock];
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
        $existing = $this->readJson($path);
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
     * @return array{last_cycle_index:int,merges_total:int,blocked_in_row:int,seen_finding_keys:array<string,bool>,seen_finding_outcomes:array<string,string>}
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
        ];
        if (! is_file($path)) {
            return $state;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $record = json_decode($line, true);
            if (! is_array($record) || (string) ($record['schema_version'] ?? '') !== self::LEDGER_SCHEMA) {
                continue;
            }
            $state['last_cycle_index'] = max($state['last_cycle_index'], (int) ($record['cycle_index'] ?? 0));
            if ($this->ledgerRecordCountsAsMerge($record)) {
                $state['merges_total']++;
            }
            $state['blocked_in_row'] = (int) data_get($record, 'cumulative.blocked_in_row', $state['blocked_in_row']);
            $keys = [];
            if ($this->ledgerRecordLocksFindingAcrossRuns($record) || (bool) ($record['quarantined'] ?? false)) {
                $keys = array_merge([$this->str($record['finding_key'] ?? '')], $this->stringList($record['finding_keys'] ?? []));
            }
            foreach ($keys as $key) {
                if ($key === '') {
                    continue;
                }
                $state['seen_finding_keys'][$key] = true;
                $state['seen_finding_outcomes'][$key] = $this->ledgerRecordIsTerminalBlocked($record)
                    ? self::OUTCOME_TERMINAL_BLOCKED
                    : $this->str($record['outcome'] ?? '');
            }
        }

        $state['seen_finding_keys'] += $this->quarantine->quarantinedFindingKeys($areaId, $focus);

        return $state;
    }

    /** @param array<string,mixed> $record */
    private function ledgerRecordLocksFindingAcrossRuns(array $record): bool
    {
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

        return $this->containsTerminalBlocker((array) ($record['blockers'] ?? []));
    }

    /** @param array<string,mixed> $record */
    private function ledgerRecordCountsAsMerge(array $record): bool
    {
        return $this->str($record['outcome'] ?? '') === self::OUTCOME_MERGED
            && (bool) ($record['merge_performed'] ?? false);
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function appendLedger(string $areaId, string $focus, array $receipt): void
    {
        $path = $this->ledgerPath($areaId, $focus);
        File::ensureDirectoryExists(dirname($path));
        File::append($path, json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
    }

    /**
     * @param  array<string,mixed>  $sessionReport
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    private function cycleReceipt(string $runId, int $cycleIndex, string $findingKey, string $outcome, array $sessionReport, array $cycle, int $cyclesThisRun, int $mergesTotal, int $blockedInRow): array
    {
        return [
            'schema_version' => self::LEDGER_SCHEMA,
            'run_id' => $runId,
            'cycle_index' => $cycleIndex,
            'cycle_id' => $this->str($cycle['cycle_id'] ?? ''),
            'finding_key' => $findingKey,
            'finding_keys' => $this->findingKeys($cycle, $findingKey),
            'outcome' => $outcome,
            'session_status' => $this->str($sessionReport['status'] ?? ''),
            'cycle_final_status' => $this->str($cycle['final_status'] ?? ''),
            'blockers' => array_values(array_filter((array) ($cycle['blockers'] ?? []), 'is_string')),
            'merge_performed' => (bool) ($cycle['merge_performed'] ?? false),
            'merge_hash' => $this->str($cycle['merge_hash'] ?? data_get($cycle, 'loop_receipt.merge_hash', '')),
            'loop_receipt_integrity' => $this->str(data_get($cycle, 'loop_receipt.integrity', '')),
            'loop_receipt_hash' => $this->str(data_get($cycle, 'loop_receipt.receipt_hash', '')),
            'inbox_item_id' => $this->str($cycle['inbox_item_id'] ?? ''),
            'result_bridge_id' => $this->str($cycle['result_bridge_id'] ?? ''),
            'cumulative' => [
                'cycles_this_run' => $cyclesThisRun,
                'merges_total' => $mergesTotal,
                'blocked_in_row' => $blockedInRow,
            ],
            'repaired' => (bool) ($cycle['repaired'] ?? false),
            'retried' => (bool) ($cycle['retried'] ?? false),
            'quarantined' => (bool) ($cycle['quarantined'] ?? false),
            'quarantine_reason' => $this->str(data_get($cycle, 'quarantine.reason', '')),
            'recorded_at' => $this->now(),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $cycleReports
     * @return array{blocked:int,merged:int,progress:int,repeated_finding:int}
     */
    private function cycleOutcomesThisRun(array $cycleReports): array
    {
        $counts = [
            self::OUTCOME_BLOCKED => 0,
            self::OUTCOME_MERGED => 0,
            self::OUTCOME_PROGRESS => 0,
            self::OUTCOME_REPEATED => 0,
        ];
        foreach ($cycleReports as $cycle) {
            $outcome = $this->str($cycle['outcome'] ?? '');
            if (array_key_exists($outcome, $counts)) {
                $counts[$outcome]++;
            }
        }

        return $counts;
    }

    private function cycleSummary(array $receipt): array
    {
        return [
            'cycle_index' => (int) ($receipt['cycle_index'] ?? 0),
            'outcome' => $this->str($receipt['outcome'] ?? ''),
            'finding_key' => $this->str($receipt['finding_key'] ?? ''),
            'cycle_final_status' => $this->str($receipt['cycle_final_status'] ?? ''),
            'merge_performed' => (bool) ($receipt['merge_performed'] ?? false),
            'merge_hash' => $this->str($receipt['merge_hash'] ?? ''),
            'loop_receipt_integrity' => $this->str($receipt['loop_receipt_integrity'] ?? ''),
            'blockers' => array_values(array_filter((array) ($receipt['blockers'] ?? []), 'is_string')),
            'repaired' => (bool) ($receipt['repaired'] ?? false),
            'retried' => (bool) ($receipt['retried'] ?? false),
            'quarantined' => (bool) ($receipt['quarantined'] ?? false),
            'quarantine_reason' => $this->str($receipt['quarantine_reason'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $cycle */
    private function terminalBlocked(array $cycle): bool
    {
        return $this->containsTerminalBlocker((array) ($cycle['blockers'] ?? []));
    }

    /** @param array<int|string,mixed> $blockers */
    private function containsTerminalBlocker(array $blockers): bool
    {
        foreach ($blockers as $blocker) {
            if (in_array($this->str($blocker), self::TERMINAL_BLOCKERS, true)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Safe worktree cleanup (delegates to AP-756, only removes clean sandboxes)
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $cycle
     */
    private function safeCleanup(array $input, bool $execute, array $cycle, string $areaId): void
    {
        if (! $execute || (bool) ($input['cleanup_worktrees'] ?? false) !== true) {
            return;
        }
        $sandboxId = $this->str($cycle['sandbox_id'] ?? data_get($cycle, 'sandbox.sandbox_id', ''));
        if ($sandboxId === '') {
            return;
        }
        try {
            // AP-756 cleanupSandbox refuses to remove dirty worktrees or anything
            // outside the controlled worktrees root — that is the safety guarantee.
            $this->materializer->cleanupSandbox([
                'sandbox_id' => $sandboxId,
                'area_id' => $areaId,
                'remove_sandbox' => true,
                'delete_branch' => true,
                'only_if_merged' => true,
                'only_if_clean' => true,
            ]);
        } catch (Throwable) {
            // Cleanup is best-effort and must never break the loop.
        }
    }

    /**
     * AP-790 long-running loops must not accumulate stale AP-756 branches/worktrees.
     *
     * This sweep is deliberately narrower than operator cleanup: it only removes
     * sandboxes whose AP-756 dry-run cleanup proves the branch is already merged
     * into HEAD and the worktree has no product changes. Dirty or unmerged
     * sandboxes stay visible for operator review instead of being force-cleaned.
     *
     * @param  array<string,mixed>  $input
     */
    private function sweepMergedCleanSandboxes(array $input, bool $execute, string $areaId): void
    {
        if (! $execute || (bool) ($input['cleanup_worktrees'] ?? false) !== true) {
            return;
        }
        if (! method_exists($this->materializer, 'listSandboxes')) {
            return;
        }

        try {
            $listed = $this->materializer->listSandboxes($areaId);
        } catch (Throwable) {
            return;
        }

        foreach ((array) ($listed['sandboxes'] ?? []) as $sandbox) {
            if (! is_array($sandbox)) {
                continue;
            }
            if ((string) ($sandbox['lifecycle_state'] ?? $sandbox['status'] ?? '') === AreaFocusBranchSandboxMaterializerService::STATUS_CLEANED) {
                continue;
            }

            $sandboxId = $this->str($sandbox['sandbox_id'] ?? '');
            if ($sandboxId === '') {
                continue;
            }

            try {
                $plan = $this->materializer->cleanupSandbox([
                    'sandbox_id' => $sandboxId,
                    'area_id' => $areaId,
                    'remove_sandbox' => false,
                    'delete_branch' => true,
                ]);
                $safety = is_array($plan['safety'] ?? null) ? $plan['safety'] : [];
                $blockers = array_values(array_filter((array) ($plan['blockers'] ?? [])));
                $merged = (bool) ($safety['branch_merged_into_head'] ?? false);
                $dirty = (bool) ($safety['worktree_dirty'] ?? true);
                if ($blockers !== [] || ! $merged || $dirty) {
                    continue;
                }

                $this->materializer->cleanupSandbox([
                    'sandbox_id' => $sandboxId,
                    'area_id' => $areaId,
                    'remove_sandbox' => true,
                    'delete_branch' => true,
                    'only_if_merged' => true,
                    'only_if_clean' => true,
                ]);
            } catch (Throwable) {
                // Cleanup is best-effort and must never break a long-running loop.
                continue;
            }
        }
    }

    // ------------------------------------------------------------------
    // Report
    // ------------------------------------------------------------------

    /**
     * @param  list<array<string,mixed>>  $cycleReports
     * @param  array<string,int|null>  $budgets
     * @param  array<string,mixed>|null  $lockHolder
     * @return array<string,mixed>
     */
    private function report(string $areaId, string $focus, string $runId, string $status, string $stopReason, array $cycleReports, array $budgets, bool $execute, bool $dryRun, ?array $lockHolder, int $cyclesThisRun, int $mergesTotal, int $blockedInRow, int $resumedFrom = 0, int $cyclesTotal = 0, int $seenFindingCount = 0): array
    {
        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-790',
            'status' => $status,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'area_id' => $areaId,
            'focus' => $focus,
            'run_id' => $runId,
            'mode' => $dryRun ? 'dry_run' : ($execute ? 'execute' : 'plan'),
            'stop_reason' => $stopReason,
            'wraps' => 'AP-786 AutonomousEvolutionSessionService (one cycle per iteration)',
            'resumed_from_cycle_index' => $resumedFrom,
            'cycles_this_run' => $cyclesThisRun,
            'cycles_total' => max($cyclesTotal, $resumedFrom),
            'merges_total' => $mergesTotal,
            'blocked_in_row' => $blockedInRow,
            'seen_finding_count' => $seenFindingCount,
            'budgets' => $budgets,
            'lock_holder' => $lockHolder,
            'cycles' => $cycleReports,
            'cycle_outcomes_this_run' => $this->cycleOutcomesThisRun($cycleReports),
            'scheduler_backlog' => $this->continuous24hSchedulerBacklogObservability($areaId, $focus),
            'ledger_path' => $this->relativeLedgerPath($areaId, $focus),
            'next_actions' => $this->nextActions($status),
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['run_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    private function relativeLedgerPath(string $areaId, string $focus): string
    {
        $full = $this->ledgerPath($areaId, $focus);
        $base = function_exists('storage_path') ? storage_path() : '';

        return $base !== '' && str_starts_with($full, $base) ? 'storage'.substr($full, strlen($base)) : $full;
    }

    private function nextActions(string $status): array
    {
        return match ($status) {
            self::STATUS_KILLED => ['Remove the kill-switch file to resume the 24h loop.'],
            self::STATUS_PAUSED => ['Remove the pause file to resume the 24h loop from the last receipt.'],
            self::STATUS_LOCK_HELD => ['Another runner holds the lock; wait for its lease to expire or stop it.'],
            self::STATUS_REPEATED => ['The same finding recurred; review/close it before re-running so the loop advances.'],
            self::STATUS_BLOCKED_STOP => ['Review the blocked cycle in Inbox; re-run with --continue-on-blocked to keep advancing.'],
            self::STATUS_BUDGET => ['Budget reached; re-run to continue from the ledger, or raise the budget.'],
            default => ['Loop finished its budget cleanly; re-run to continue from the ledger.'],
        };
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'wraps_ap786_does_not_reimplement_selection_or_execution' => true,
            'runner_invokes_provider_directly' => false,
            'runner_merges_directly' => false,
            'runner_mutates_repo_directly' => false,
            'append_only_ledger' => true,
            'exclusive_lock_per_area_focus' => true,
            'crash_recoverable_from_ledger' => true,
            'duplicate_finding_protected' => true,
            'continuous_24h_scheduler_backlog_observable' => true,
            'bounded_cycle_window' => true,
            'quarantine_ledger_append_only' => true,
            'repair_and_quarantine_governed_by_ap786' => true,
            'safe_cleanup_only_clean_worktrees' => true,
            'deploy_performed' => false,
            'secret_access' => false,
            // Real-authority rule: runtime drives the real AP-786 session only; the
            // *ForTesting seams are test doubles confined to unit tests and never
            // cross runtime. Progress/merge can only come from real AP-786 cycles.
            'no_test_doubles_at_runtime' => true,
            'synthetic_or_valid_shape_input_cannot_produce_progress' => true,
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || (is_numeric($value) && (int) $value <= 0)) {
            return null;
        }

        return (int) $value;
    }

    private function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function time(): float
    {
        return $this->clock !== null ? (float) ($this->clock)() : microtime(true);
    }

    private function sleep(int $seconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);

            return;
        }
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    private function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return list<string>
     */
    private function findingKeys(array $cycle, string $fallback): array
    {
        $finding = is_array($cycle['selected_finding'] ?? null) ? $cycle['selected_finding'] : [];

        return array_values(array_unique(array_filter(array_merge(
            [$fallback],
            $this->stringList([
                $finding['finding_id'] ?? '',
                $finding['finding_hash'] ?? '',
                $finding['title'] ?? '',
            ]),
        ), static fn (string $value): bool => $value !== '')));
    }

    /** @return list<string> */
    private function stringList(mixed $values): array
    {
        $out = [];
        foreach ((array) $values as $value) {
            $text = $this->str($value);
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return array_values(array_unique($out));
    }

    private function key(string $areaId, string $focus): string
    {
        return $this->slug($areaId).'__'.$this->slug($focus);
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($value)) ?: '');

        return trim($slug, '_');
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['generated_at'], $copy['run_hash'], $copy['lock_holder']);

        return $copy;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
