<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\ZeroProviderPreflightGate;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\BuildPlanDecomposerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\OwnerFlowPlanSliceCycleExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanCompletionTrackerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanSliceDecompositionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanSliceSelectionService;
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

    public const STATUS_BACKLOG_EXHAUSTED = 'stopped_backlog_exhausted';

    /** Stopped because a recurring failure cascade (same tier) cannot be safely retried. */
    public const STATUS_CASCADE_HALT = 'stopped_failure_cascade';

    /** Stopped because a provider-spent diff was rejected as unsafe/non-useful. */
    public const STATUS_PROVIDER_WASTE = 'stopped_provider_waste';

    /**
     * Stopped because the self-maintenance merge cap was hit. A 24h loop that keeps
     * merging its OWN recovery/maintenance work (instead of product findings) is
     * spinning on filler; the cap forces an honest stop so self-maintenance can
     * never crowd out real product throughput over a long run.
     */
    public const STATUS_SELF_MAINTENANCE_CAP = 'stopped_self_maintenance_cap';

    /** Single-writer guard: a mutating run was refused on the canonical/human checkout. */
    public const STATUS_CANONICAL_WORKTREE_REFUSED = 'stopped_canonical_worktree_write_refused';

    /** Cycle receipt `work_class` for real product findings. */
    public const WORK_CLASS_PRODUCT = 'product';

    /** Cycle receipt `work_class` for the loop's own recovery/maintenance work. */
    public const WORK_CLASS_SELF_MAINTENANCE = 'self_maintenance';

    public const SCHEDULER_BACKLOG_BRIDGE_SCHEMA = 'atlas.software_company_stewardship.ap790_continuous_24h_scheduler_backlog.v1';

    public const AP790_BACKLOG_CONTINUOUS_24H_SCHEDULER = 'continuous_24h_scheduler';

    public const PLAN_BACKLOG_BRIDGE_SCHEMA = 'atlas.software_company_stewardship.ap790_plan_backlog_bridge.v1';

    public const STEWARDSHIP_RECOVERY_CONTRACT_SCHEMA = Reliable24hStewardshipRecoveryContract::SCHEMA;

    /** Upper bound for operator-facing cycle slices (continuous 24h scheduler observability). */
    public const DEFAULT_BOUNDED_CYCLE_WINDOW = 20;

    private const AAEOS_LOOP_SELF_PROTECTION_BACKLOG = 'docs/engineering-knowledge-base/atlas-aaeos-loop-self-protection-leap-backlog.md';

    private const AAEOS_FACTORY_RUNTIME_BRIDGE_BACKLOG = 'docs/engineering-knowledge-base/atlas-aaeos-factory-runtime-bridge-backlog.md';

    /** @var list<string> */
    private const DEFAULT_AAEOS_PLAN_BACKLOG_DOCS = [
        self::AAEOS_LOOP_SELF_PROTECTION_BACKLOG,
        self::AAEOS_FACTORY_RUNTIME_BRIDGE_BACKLOG,
        'docs/engineering-knowledge-base/atlas-aaeos-reliability-testos-leap-backlog.md',
        'docs/engineering-knowledge-base/atlas-aaeos-forge-dev-leap-backlog.md',
        'docs/engineering-knowledge-base/atlas-aaeos-high-value-evolution-backlog.md',
        'docs/engineering-knowledge-base/atlas-aaeos-cognitive-plane-leap-backlog.md',
        'docs/engineering-knowledge-base/atlas-aaeos-aemor-deepvein-leap-backlog.md',
        'docs/engineering-knowledge-base/atlas-aaeos-deep-cores-leap-backlog.md',
        'docs/engineering-knowledge-base/atlas-aaeos-final-convergence-leap-backlog.md',
        'docs/engineering-knowledge-base/atlas-aaeos-l7-l10-governed-ladder-backlog.md',
    ];

    private const AAEOS_PLAN_BACKLOG_INDEX = 'docs/engineering-knowledge-base/atlas-aaeos-evolution-backlog-index.md';

    private const OUTCOME_MERGED = 'merged';

    private const OUTCOME_BLOCKED = 'blocked';

    private const OUTCOME_PROGRESS = 'progress';

    private const OUTCOME_REPEATED = 'repeated_finding';

    private const OUTCOME_TERMINAL_BLOCKED = 'terminal_blocked';

    private const TERMINAL_BLOCKERS = [
        'owner_runtime_senior_loop_repair_exhausted',
        'owner_runtime_repeated_repair_no_progress',
        'owner_runtime_review_locked',
        'owner_runtime_php_syntax_error_after_max_repairs',
        'quarantine_after_repair_exhausted',
        'repair_exhausted',
        FinalDeliveryQualityGateService::BLOCKER,
        'owner_runtime_'.FinalDeliveryQualityGateService::BLOCKER,
        'minimax_no_code_extracted',
        'owner_runtime_minimax_no_code_extracted',
        ZeroProviderPreflightGate::REASON_PRIOR_NON_RETRYABLE_FAILURE_PATTERN,
        ZeroProviderPreflightGate::REASON_TEST_SUBJECT_NOT_AUTONOMOUSLY_TESTABLE,
    ];

    /** @var list<string> */
    private const PROVIDER_WASTE_BLOCKERS = [
        'minimax_no_code_extracted',
        'owner_runtime_minimax_no_code_extracted',
        'senior_loop_repair_exhausted',
        'owner_runtime_senior_loop_repair_exhausted',
        'repeated_repair_no_progress',
        'owner_runtime_repeated_repair_no_progress',
        'review_locked',
        'owner_runtime_review_locked',
        'php_syntax_error_after_max_repairs',
        'owner_runtime_php_syntax_error_after_max_repairs',
        'provider_diff_quality_gate_failed',
        'owner_runtime_provider_diff_quality_gate_failed',
        'large_product_diff_without_test_update',
        'owner_runtime_large_product_diff_without_test_update',
        'large_product_deletion_without_test_update',
        'owner_runtime_large_product_deletion_without_test_update',
        'large_single_file_deletion_without_test_update',
        'owner_runtime_large_single_file_deletion_without_test_update',
        'deletion_heavy_product_diff_without_test_update',
        'owner_runtime_deletion_heavy_product_diff_without_test_update',
    ];

    /** Absolute safety cap so the loop can never spin forever within one process. */
    private const HARD_ITERATION_CAP = 1000;

    /**
     * A blocked finding may re-enter for a bounded retry (transient/repair), but
     * after this many blocked attempts in a run it is review-locked so the loop
     * moves to a different finding instead of re-implementing the same one over
     * and over (which produced duplicate sandbox branches/commits).
     */
    private const MAX_BLOCKED_ATTEMPTS_PER_FINDING = 2;

    /**
     * Failure-cascade detection threshold. When the SAME failure tier (e.g. a
     * tier-4 policy `merge_not_performed`, or a tier-2 execution failure) recurs
     * this many times consecutively, the runner stops blind-counting blocked-in-row
     * and reacts to the actual class: a recurring policy failure that has also hit a
     * merge budget is a HONEST budget stop, an unbudgeted policy cascade tries a
     * different finding, and a recurring execution cascade halts with a diagnostic
     * instead of looping forever on a broken provider/senior-loop.
     */
    private const CASCADE_CONSECUTIVE_THRESHOLD = 3;

    /** Re-check the kill/pause files at least this often (seconds) during an inter-cycle sleep. */
    private const SLEEP_INTERRUPT_GRANULARITY_SECONDS = 5;

    private ?string $storageRootOverride = null;

    /**
     * AP-807 (LHL-01) wire flag. Default OFF: when false, the cycle ledger record
     * is byte-identical to its prior shape and the preflight firewall is never
     * invoked. Set true per-run via input `attach_cycle_firewall_ref` to attach a
     * diagnostic read-only `preflight_ref` to each cycle receipt.
     */
    private bool $attachFirewallRef = false;

    /**
     * AP-807 (LHL-02) wire flag. Default OFF: when false, the cycle ledger record
     * is byte-identical to its prior shape and the post-cycle auditor is never
     * invoked. Set true per-run via input `attach_cycle_audit_ref` to attach a
     * diagnostic read-only `post_cycle_audit_ref` to each cycle receipt.
     */
    private bool $attachAuditorRef = false;

    /** @var null|callable(array<string,mixed>):array<string,mixed> */
    private $sessionRunner = null;

    /** @var null|callable():float */
    private $clock = null;

    /** @var null|callable(int):void */
    private $sleeper = null;

    /** @var null|callable():list<array<string,mixed>> */
    private $processTableProvider = null;

    /** @var null|callable(int):?string */
    private $processCwdProvider = null;

    /** @var null|callable(int,string):bool */
    private $processKiller = null;

    public function __construct(
        private readonly AutonomousEvolutionSessionService $session,
        private readonly AreaFocusBranchSandboxMaterializer $materializer,
        private readonly AreaFocusCandidateQuarantineService $quarantine,
        // AP-807 wire point (LHL-01): an OPTIONAL, read-only preflight firewall.
        // It is null in the bare 3-arg constructor and only attaches a diagnostic
        // `preflight_ref` to the cycle ledger when `attach_cycle_firewall_ref` is
        // explicitly enabled — it NEVER gates merge/judge/cleanup here.
        private readonly ?LoopPreflightCycleFirewallService $preflightFirewall = null,
        // AP-807 wire point (LHL-02): an OPTIONAL, read-only post-cycle auditor.
        // It is null in the few-arg constructor and only attaches a diagnostic
        // `post_cycle_audit_ref` to the cycle ledger when `attach_cycle_audit_ref`
        // is explicitly enabled. Symmetric to the preflight firewall: the auditor
        // verdict is recorded as a REFERENCE only and NEVER gates merge/judge/
        // cleanup nor stops the loop.
        private readonly ?LoopPostCycleAuditorService $postCycleAuditor = null,
        // Failure-cascade wire point: an OPTIONAL, read-only failure taxonomy. It
        // classifies a non-merged cycle into a 5-tier taxonomy + recovery action so
        // the loop can react to the actual failure CLASS (setup/execution/quality/
        // policy/budget) instead of blind-counting blocked-in-row. Nullable +
        // lazily resolved so the loop never hard-depends on it; it never invokes a
        // provider, never merges and never deletes a branch.
        private readonly ?LoopCycleFailureTaxonomyService $failureTaxonomy = null,
        // AP-810 health pulse (optional): every 10 cycles, records a resource health
        // snapshot (memory/git-gc/JSONL-rotation/zombie-kill/disk) into the JSONL
        // ledger. Null-safe — absent pulse = no side-effect on the loop.
        private readonly ?LoopHealthPulseService $healthPulse = null,
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

    /** @param callable():list<array<string,mixed>> $provider */
    public function setProcessTableForTesting(callable $provider): void
    {
        $this->processTableProvider = $provider;
    }

    /** @param callable(int):?string $provider */
    public function setProcessCwdForTesting(callable $provider): void
    {
        $this->processCwdProvider = $provider;
    }

    /** @param callable(int,string):bool $killer */
    public function setProcessKillerForTesting(callable $killer): void
    {
        $this->processKiller = $killer;
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
        foreach ($this->jsonlLines($path) as $line) {
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
        // A lock whose holder process is dead (same host) is reclaimable right
        // now — acquireLock() already treats it as orphaned. lockStatus() must
        // report the same truth, otherwise a crashed run keeps falsely blocking
        // readiness/certification for the rest of its lease (up to an hour).
        $orphaned = $this->lockProcessIsDead($holder);
        $reclaimable = $expired || $orphaned;

        return [
            'available' => $reclaimable,
            'held' => ! $reclaimable,
            'holder' => $reclaimable ? null : $holder,
            'expired' => $expired,
            'orphaned' => $orphaned,
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
        $stewardshipRecovery = Reliable24hStewardshipRecoveryContract::fromLedgerRecords($areaId, $focus, $records);
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
                'recovered' => $stewardshipRecovery->lastCycleIndex > 0,
                'last_cycle_index' => $stewardshipRecovery->lastCycleIndex,
                'merges_total' => $stewardshipRecovery->mergesTotal,
                'blocked_in_row' => $stewardshipRecovery->blockedInRow,
                'consecutive_merged_cycles' => $stewardshipRecovery->consecutiveMergedCycles,
                'recovery_normal' => $stewardshipRecovery->recoveryNormal(),
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
     * AP-790 · entry for 24h stewardship recovery until consecutive merged cycles are normal.
     *
     * Step-2 seam: validates area/focus scope and returns the step-1 default contract
     * when the ledger is empty. Step-3+ ledger-derived inputs stay in private helpers.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function stewardshipRecoveryUntilConsecutiveMergedCyclesNormal(array $input = []): array
    {
        [$areaId, $focus] = $this->validatedStewardshipRecoveryScope($input);

        $records = $this->readLedgerRecords($areaId, $focus);
        if ($records === []) {
            return Reliable24hStewardshipRecoveryContract::defaults($areaId, $focus)->toArray();
        }

        return $this->stewardshipRecoveryContractFromLedger($areaId, $focus, $records);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{0:string,1:string}
     */
    private function validatedStewardshipRecoveryScope(array $input): array
    {
        $areaId = $this->slug((string) ($input['area_id'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = $this->slug((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';

        return [$areaId, $focus];
    }

    /**
     * Step-3 seam: applies merge-eligibility (first rule) — only ledger rows with
     * outcome=merged and merge_performed=true count toward recovery inputs.
     *
     * @param  list<array<string,mixed>>  $records
     * @return array<string,mixed>
     */
    private function stewardshipRecoveryContractFromLedger(string $areaId, string $focus, array $records): array
    {
        return Reliable24hStewardshipRecoveryContract::fromLedgerRecords($areaId, $focus, $records)->toArray();
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input = []): array
    {
        $areaId = $this->slug((string) ($input['area_id'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = $this->slug((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';
        // AP-807 (LHL-01): opt-in, read-only. The diagnostic preflight_ref is only
        // attached when the operator explicitly asks for it. It defaults OFF so the
        // existing cycle ledger and run hash are unchanged. The firewall instance is
        // resolved lazily (constructor-injected for the test seam, container
        // fallback at runtime) inside the receipt helper, fully fail-safe.
        $this->attachFirewallRef = (bool) ($input['attach_cycle_firewall_ref'] ?? false);
        // AP-807 (LHL-02): symmetric opt-in for the post-cycle auditor. Default OFF
        // so the cycle ledger and run hash are unchanged; the auditor is resolved
        // lazily inside the receipt helper (fully fail-safe) and recorded only as a
        // diagnostic reference — it never gates merge/judge/cleanup or stops the loop.
        $this->attachAuditorRef = (bool) ($input['attach_cycle_audit_ref'] ?? false);
        $dryRun = (bool) ($input['dry_run'] ?? false);
        $execute = (bool) ($input['execute'] ?? false) && ! $dryRun;
        $stewardshipRecovery = Reliable24hStewardshipRecoveryContract::fromLedgerRecords(
            $areaId,
            $focus,
            $this->readLedgerRecords($areaId, $focus),
        );
        if (! array_key_exists('continue_on_blocked', $input)) {
            $input['continue_on_blocked'] = $stewardshipRecovery->continueOnBlockedDefault($execute);
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

        // 1b. Single-writer guard (operator mandate 2026-05-31): the loop must run
        // in a DEDICATED worktree and must NOT mutate the canonical/human checkout
        // (two writers on one working tree = interleaved staging / swept WIP / lane
        // divergence). A mutating run ($execute) whose repo_root is the canonical
        // checkout is refused cleanly here — before the lock, like kill/pause —
        // UNLESS the operator explicitly opts in. Read-only / dry-run runs
        // (execute=false) are never affected, and a degraded/non-git topology fails
        // safe to allowed (the verifier returns is_canonical_checkout=false).
        if ($execute && $this->singleWriterGuardActive($input)) {
            $writeGuard = (new CanonicalWorktreeWriteGuard())->decide([
                'repo_root' => $this->repoRootFromInput($input),
                'on_canonical' => (new LoopWorktreeTopologyVerifierService())->isCanonicalCheckout($this->repoRootFromInput($input)),
                'is_mutating' => true,
                'allow_canonical_worktree_write' => (bool) ($input['allow_canonical_worktree_write'] ?? false),
            ]);
            if (($writeGuard['decision'] ?? '') === CanonicalWorktreeWriteGuard::DECISION_REFUSED) {
                return $this->report($areaId, $focus, $runId, self::STATUS_CANONICAL_WORKTREE_REFUSED, CanonicalWorktreeWriteGuard::BLOCKER, [], $budgets, $execute, $dryRun, null, 0, 0, 0);
            }
        }

        // 2. Exclusive lock per area/focus.
        $lock = $this->acquireLock($areaId, $focus, $runId, $leaseTtl);
        if ($lock['acquired'] !== true) {
            return $this->report($areaId, $focus, $runId, self::STATUS_LOCK_HELD, 'lock_held_by_'.(string) ($lock['holder']['run_id'] ?? 'unknown'), [], $budgets, $execute, $dryRun, $lock['holder'] ?? null, 0, 0, 0);
        }

        try {
            $this->reapLoopSandboxProcesses($input, $execute, $areaId);

            // 3. Crash recovery: resume cumulative counters and seen findings from the ledger.
            $resume = $this->resumeState($areaId, $focus);
            $cycleIndex = (int) $resume['last_cycle_index'];
            $mergesTotal = (int) $resume['merges_total'];
            $seenFindingKeys = $resume['seen_finding_keys'];
            $seenFindingOutcomes = $resume['seen_finding_outcomes'];
            $blockedInRow = (int) $resume['blocked_in_row'];
            $lastBlockedFindingKey = '';
            // Failure-cascade tracking (in-process, this run). Tracks how many times
            // the SAME failure tier has recurred consecutively so the loop can react
            // to the actual class instead of blind-counting blocked-in-row:
            //   - tier-4 (policy) cascade => check the merge budget, then move on;
            //   - tier-2 (execution) cascade => halt with a diagnostic.
            $lastFailureTier = 0;
            $tierConsecutiveCount = 0;
            // Per-finding blocked-attempt counter — caps retries so a repeatedly-blocked
            // finding stops being re-offered (no duplicates, no re-implementing a failing
            // slice forever). Seeded from the durable ledger so the cap SURVIVES across
            // run() invocations: a non-terminal "blocked" outcome deliberately does NOT
            // lock the finding across runs (repair must finish its bounded loop), so a
            // per-run-only counter let a slice that blocks once-per-short-run be
            // re-selected forever. Resuming the count makes the cap honest across runs.
            $blockedAttemptsByFinding = $resume['blocked_attempts_by_finding'];

            $cyclesThisRun = 0;
            // Per-run merge counter. The max_merges budget must limit merges in
            // THIS run, not the cumulative ledger total (which resumes at e.g.
            // 47); otherwise --max-merges stops the loop before it runs once.
            $mergesThisRun = 0;
            // Per-run self-maintenance merge counter for the FASE 5 cap. Counts only
            // merges whose work_class is self_maintenance, so a real product merge
            // never trips the cap.
            $selfMaintenanceMergesThisRun = 0;
            $cycleReports = [];
            $startedAt = $this->time();
            $status = $execute ? self::STATUS_COMPLETED : self::STATUS_DRY_RUN;
            $stopReason = 'budget_or_no_more_work';

            $this->sweepMergedCleanSandboxes($input, $execute, $areaId);

            // Merge-truth backstop (operator mandate 2026-05-31): resolve repo_root
            // once so each cycle can capture main before/after and prove a merge
            // really advanced main. Enforcement of the COUNT is opt-in
            // (enforce_merge_truth_counting); evidence is always recorded.
            $mergeTruthRepoRoot = $this->repoRootFromInput($input);
            $enforceMergeTruthCounting = (bool) ($input['enforce_merge_truth_counting'] ?? false);

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
                $budgetStop = $this->budgetStop(
                    $budgets,
                    $cyclesThisRun,
                    $mergesThisRun,
                    $blockedInRow,
                    $startedAt,
                    $this->allowBlockedRecoveryProbe($input, $stewardshipRecovery),
                );
                if ($budgetStop !== null) {
                    $status = self::STATUS_BUDGET;
                    $stopReason = $budgetStop;
                    break;
                }

                $cycleIndex++;
                $cyclesThisRun++;

                // Capture main BEFORE the session may merge, so merge-truth can prove
                // main actually advanced (vs a false / lane-only / no-op merge).
                $mainBefore = $execute ? $this->headSha($mergeTruthRepoRoot, 'main') : '';

                try {
                    $sessionReport = $this->invokeSession($input, $areaId, $focus, $execute, $seenFindingKeys, $seenFindingOutcomes, $blockedAttemptsByFinding);
                } finally {
                    // AP-810 hardening: the owner runtime can leave detached
                    // cursor/worker processes alive even after AP-786 returns a
                    // blocked cycle. Reap only processes attributable to the
                    // AP-756 controlled sandbox root before they can keep
                    // writing branches/ledger after the cycle was recorded.
                    $this->reapLoopSandboxProcesses($input, $execute, $areaId);
                }
                $cycle = $this->firstCycle($sessionReport);
                $findingKey = $this->findingKey($cycle);

                // Duplicate-finding protection: never spend owner runtime again
                // on a finding that already made progress, merged, repeated or
                // terminal-blocked. Only plain blocked findings may re-enter so
                // repair/quarantine policies can finish their bounded loop.
                $priorOutcome = $findingKey !== '' ? ($seenFindingOutcomes[$findingKey] ?? null) : null;
                if ($findingKey !== '' && isset($seenFindingKeys[$findingKey]) && $priorOutcome !== self::OUTCOME_BLOCKED) {
                    $receipt = $this->cycleReceipt($runId, $cycleIndex, $findingKey, self::OUTCOME_REPEATED, $sessionReport, $cycle, $cyclesThisRun, $mergesTotal, $blockedInRow);
                    $this->appendLedger($areaId, $focus, $receipt);
                    $cycleReports[] = $this->cycleSummary($receipt);
                    $status = self::STATUS_REPEATED;
                    $stopReason = 'repeated_finding:'.$findingKey;
                    break;
                }

                $outcome = $this->classifyOutcome($cycle);
                // Failure tier for cascade detection — only meaningful for a
                // non-merged cycle. A merge resets the cascade; a blocked/progress
                // cycle is classified into the 5-tier taxonomy so the loop can react
                // to the actual failure class below.
                $failureTier = 0;
                $failureClassified = false;
                $workClass = $this->workClass($cycle);
                if ($outcome === self::OUTCOME_MERGED) {
                    // Merge-truth: prove main actually advanced. Evidence is always
                    // recorded; the COUNT is gated only when enforcement is opted in
                    // (default off so existing behavior / faked-session tests are
                    // unchanged). A real false merge is already blocked upstream by
                    // the governor's nothing_to_merge guard — this is the count-site
                    // backstop for the operator mandate "merge só conta se main avançou".
                    $mergeTruth = (new MergeTruthValidator())->validate([
                        'main_before' => $mainBefore,
                        'main_after' => $execute ? $this->headSha($mergeTruthRepoRoot, 'main') : $mainBefore,
                        'target_ref_before' => $mainBefore,
                        'target_ref_after' => $execute ? $this->headSha($mergeTruthRepoRoot, 'main') : $mainBefore,
                        'merge_target' => MergeTruthValidator::TARGET_MAIN,
                        'merge_performed_to_base' => true,
                    ]);
                    $cycle['merge_truth'] = $mergeTruth;

                    if (! $enforceMergeTruthCounting || ! $execute || $mergeTruth['merge_real']) {
                        $mergesTotal++;
                        $mergesThisRun++;
                        if ($workClass === self::WORK_CLASS_SELF_MAINTENANCE) {
                            $selfMaintenanceMergesThisRun++;
                        }
                        $blockedInRow = 0;
                        $lastFailureTier = 0;
                        $tierConsecutiveCount = 0;
                        $this->safeCleanup($input, $execute, $cycle, $areaId);
                    } else {
                        // Claimed merge but main did not advance: honest non-merge —
                        // never counted as autonomy under enforcement.
                        $outcome = self::OUTCOME_BLOCKED;
                        $blockedInRow++;
                    }
                } elseif ($outcome === self::OUTCOME_BLOCKED) {
                    if ($findingKey !== '') {
                        $blockedAttemptsByFinding[$findingKey] = ($blockedAttemptsByFinding[$findingKey] ?? 0) + 1;
                    }
                    if ($findingKey !== '' && $findingKey === $lastBlockedFindingKey) {
                        $blockedInRow++;
                    } elseif ($findingKey !== '') {
                        $blockedInRow = 1;
                        $lastBlockedFindingKey = $findingKey;
                    } else {
                        $blockedInRow++;
                    }

                    // Classify the failure and track same-tier recurrence so the
                    // cascade reaction below can branch on the real class.
                    $classification = $this->classifyFailureTier($cycle);
                    $failureTier = $classification['tier'];
                    $failureClassified = $classification['classified'];
                    if ($failureTier > 0 && $failureTier === $lastFailureTier) {
                        $tierConsecutiveCount++;
                    } else {
                        $tierConsecutiveCount = $failureTier > 0 ? 1 : 0;
                        $lastFailureTier = $failureTier;
                    }
                } else {
                    $blockedInRow = 0;
                    $lastBlockedFindingKey = '';
                    $lastFailureTier = 0;
                    $tierConsecutiveCount = 0;
                }

                if ($findingKey !== '') {
                    $seenOutcome = ((bool) ($cycle['quarantined'] ?? false) || $this->terminalBlocked($cycle))
                        ? self::OUTCOME_TERMINAL_BLOCKED
                        : $outcome;
                    foreach ($this->findingKeys($cycle, $findingKey) as $seenKey) {
                        $seenFindingKeys[$seenKey] = true;
                        $seenFindingOutcomes[$seenKey] = $seenOutcome;
                    }
                }

                $receipt = $this->cycleReceipt($runId, $cycleIndex, $findingKey, $outcome, $sessionReport, $cycle, $cyclesThisRun, $mergesTotal, $blockedInRow);
                $this->appendLedger($areaId, $focus, $receipt);
                $cycleReports[] = $this->cycleSummary($receipt);
                if ($outcome === self::OUTCOME_MERGED
                    || $this->containsSpecificBlocker($cycle, AutonomousEvolutionSessionService::PROVIDER_DIFF_QUALITY_BLOCKER)
                    || $this->containsAnySpecificBlocker($cycle, self::PROVIDER_WASTE_BLOCKERS)
                    || $this->containsAnySpecificBlocker($cycle, [
                        FinalDeliveryQualityGateService::BLOCKER,
                        'minimax_no_code_extracted',
                        ZeroProviderPreflightGate::REASON_PRIOR_NON_RETRYABLE_FAILURE_PATTERN,
                    ])) {
                    $this->safeCleanup($input, $execute, $cycle, $areaId);
                }

                if ($outcome === self::OUTCOME_BLOCKED && $this->containsAnySpecificBlocker($cycle, self::PROVIDER_WASTE_BLOCKERS)) {
                    $status = self::STATUS_PROVIDER_WASTE;
                    $stopReason = 'provider_waste_blocker:'.implode(',', array_values(array_intersect(
                        array_values(array_filter((array) ($cycle['blockers'] ?? []), 'is_string')),
                        self::PROVIDER_WASTE_BLOCKERS,
                    )));
                    break;
                }

                // FASE 5 self-maintenance cap: a 24h loop that keeps merging its own
                // recovery/maintenance work is spinning on filler. Stop HONESTLY once
                // self-maintenance merges in THIS run reach the cap — the merge that
                // tripped the cap is already on the ledger above, so the stop is
                // honest (we never hide a merge). Null cap = uncapped (back-compat).
                $selfMaintenanceCap = $budgets['max_self_maintenance_merges'] ?? null;
                if ($selfMaintenanceCap !== null && $selfMaintenanceMergesThisRun >= (int) $selfMaintenanceCap) {
                    $status = self::STATUS_SELF_MAINTENANCE_CAP;
                    $stopReason = 'max_self_maintenance_merges_reached:'.(int) $selfMaintenanceCap;
                    break;
                }

                // AP-810 health pulse: every 10 cycles, snapshot resource health and
                // append a `health_snapshot` record to the JSONL ledger. Fail-safe:
                // a missing pulse service or any throw is silently swallowed so a
                // long-running loop is never broken by instrumentation.
                if ($cycleIndex % LoopHealthPulseService::PULSE_EVERY_N_CYCLES === 0 && $this->healthPulse !== null) {
                    try {
                        $pulse = $this->healthPulse->pulse(
                            $cycleIndex,
                            (string) ($input['repo_root'] ?? ''),
                            $this->ledgerPath($areaId, $focus),
                        );
                        $this->appendLedger($areaId, $focus, array_merge(
                            ['record_type' => 'health_snapshot'],
                            $pulse,
                        ));
                    } catch (Throwable) {
                        // Non-fatal — never stop the loop on a health pulse failure.
                    }
                }

                // AP-806: backlog_exhausted means factory_max found no eligible work
                // AND the Self-Construction admission bridge produced no safe packet
                // AND synthetic starvation-recovery was refused. Stop HONESTLY instead
                // of looping on filler; the admission report rides on the cycle/ledger.
                if (in_array('backlog_exhausted', array_values(array_filter((array) ($cycle['blockers'] ?? []), 'is_string')), true)) {
                    $status = self::STATUS_BACKLOG_EXHAUSTED;
                    $stopReason = 'backlog_exhausted';
                    break;
                }

                // Failure-cascade reaction: when the SAME failure tier recurs past
                // the cascade threshold, react to the actual class instead of
                // blind-counting blocked-in-row.
                //   - tier-5 (budget_exhausted): immediately stop with STATUS_BUDGET;
                //     a real ceiling means there is no more work, never retry.
                //   - tier-4 (policy_failure) cascade: if the merge budget is
                //     exhausted this run, stop HONESTLY with STATUS_BUDGET instead of
                //     retrying a merge that can never happen; otherwise keep going so
                //     the loop tries a DIFFERENT finding (the cascade warning is on
                //     the ledger/report).
                //   - tier-2 (execution_failure) cascade: halt with a diagnostic —
                //     a provider/senior-loop that keeps failing will not self-heal by
                //     re-running the same way.
                $cascade = $this->cascadeReaction(
                    $failureTier,
                    $failureClassified,
                    $tierConsecutiveCount,
                    $budgets,
                    $mergesThisRun,
                );
                if ($cascade !== null) {
                    $status = $cascade['status'];
                    $stopReason = $cascade['stop_reason'];
                    break;
                }

                // A blocked cycle stops the loop only when stewardship recovery says not to continue.
                if ($outcome === self::OUTCOME_BLOCKED && ! $this->shouldContinueOnBlockedCycle($input, $stewardshipRecovery)) {
                    $status = self::STATUS_BLOCKED_STOP;
                    $stopReason = 'blocked_cycle_without_continue_on_blocked';
                    break;
                }

                // Rate limit between cycles — but stay RESPONSIVE to the kill/pause
                // files. A single blocking sleep(N) made a mid-sleep kill honored
                // only after the FULL sleep elapsed (up to N seconds of latency).
                // We chunk the sleep and re-check the signal files every few
                // seconds, so a kill/pause issued mid-sleep stops the loop within
                // SLEEP_INTERRUPT_GRANULARITY_SECONDS instead of after the whole N.
                if ($sleepSeconds > 0) {
                    $interrupt = $this->responsiveSleep($sleepSeconds, $areaId, $focus);
                    if ($interrupt !== null) {
                        $status = $interrupt === self::STATUS_KILLED ? self::STATUS_KILLED : self::STATUS_PAUSED;
                        $stopReason = $interrupt === self::STATUS_KILLED ? 'kill_switch_active' : 'pause_file_present';
                        break;
                    }
                }
            }

            // End-of-run sweep: leave a clean slate. The start-of-run sweep only
            // catches sandboxes from a PREVIOUS run; without this, merged
            // sandboxes from THIS run (and from any cycle whose per-cycle cleanup
            // did not fire) accumulate as branch/worktree pollution until the next
            // run starts. Best-effort, merged+clean only, never destructive.
            $this->sweepMergedCleanSandboxes($input, $execute, $areaId);

            return $this->report(
                $areaId, $focus, $runId, $status, $stopReason, $cycleReports, $budgets, $execute, $dryRun,
                null, $cyclesThisRun, $mergesTotal, $blockedInRow,
                resumedFrom: (int) $resume['last_cycle_index'],
                cyclesTotal: $cycleIndex,
                seenFindingCount: count($seenFindingKeys),
                stewardshipRecovery: $stewardshipRecovery->toArray(),
            );
        } finally {
            $this->reapLoopSandboxProcesses($input, $execute, $areaId);
            $this->releaseLock($areaId, $focus, $runId);
            // Never let an opt-in wire flag leak across runs on a shared singleton.
            $this->attachFirewallRef = false;
            $this->attachAuditorRef = false;
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
    private function invokeSession(array $input, string $areaId, string $focus, bool $execute, array $seenFindingKeys, array $seenFindingOutcomes, array $blockedAttemptsByFinding = []): array
    {
        $reviewLocked = $this->sessionReviewLockedKeys($seenFindingKeys, $seenFindingOutcomes);
        // Cap blocked-finding retries: once a finding has blocked too many times
        // this run, review-lock it so the loop picks a different finding instead
        // of re-implementing the same one (the source of duplicate branches).
        $reviewLocked += $this->blockedAttemptReviewLocks($blockedAttemptsByFinding);
        $terminalLocked = $this->sessionTerminalLockedKeys($seenFindingKeys, $seenFindingOutcomes);

        $planBacklogReport = $this->invokePlanBacklogSession(
            $input,
            $areaId,
            $focus,
            $execute,
            $reviewLocked + $terminalLocked,
        );
        if ($planBacklogReport !== null) {
            return $planBacklogReport;
        }

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
            'multi_agent_workcell' => (bool) ($input['multi_agent_workcell'] ?? false),
            'pull_main' => (bool) ($input['pull_main'] ?? false),
            'record' => (bool) ($input['record'] ?? false),
            'max_findings' => (int) ($input['max_findings'] ?? 200),
            'max_auto_merge_files' => (int) ($input['max_auto_merge_files'] ?? 5),
            'ap790_kill_switch_path' => $this->killSwitchPath($areaId, $focus),
            'validation_commands' => array_values(array_filter((array) ($input['validation_commands'] ?? []), 'is_string')),
            'session_review_locked' => $reviewLocked,
            'session_terminal_locked' => $terminalLocked,
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
     * AP-790 plan-backlog bridge: when a factory_max AAEOS run is active, consume the
     * curated plan-execution backlog docs one atomic slice at a time before falling
     * back to broad native selection. This keeps the 24h runner on high-probability,
     * operator-authored AAEOS slices while preserving the same AP-786 owner flow for
     * provider/sandbox/merge.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,bool>  $skipFindingKeys
     * @return array<string,mixed>|null
     */
    private function invokePlanBacklogSession(array $input, string $areaId, string $focus, bool $execute, array $skipFindingKeys = []): ?array
    {
        $docs = $this->planBacklogDocs($input, $areaId, $focus);
        if ($docs === []) {
            return null;
        }

        $decomposer = new BuildPlanDecomposerService;
        $tracker = new PlanCompletionTrackerService;
        $selector = new PlanSliceSelectionService;
        $decomposition = new PlanSliceDecompositionService;
        $executor = new OwnerFlowPlanSliceCycleExecutor($this->session, $execute);
        $repoRoot = $this->repoRootFromInput($input);

        $blockedDocs = [];
        $completeDocs = [];
        $orderedDocGate = $this->shouldEnforceOrderedPlanBacklogDocs($input, $areaId, $focus, $docs);
        if ($orderedDocGate) {
            $orderBlockers = $this->planBacklogOrderPreflightBlockers($docs);
            if ($orderBlockers !== []) {
                return $this->planBacklogNoReadySession($docs, $orderBlockers, $completeDocs);
            }
        }

        foreach ($docs as $index => $doc) {
            $docPath = $this->absoluteRepoPath($repoRoot, $doc);
            if (! is_file($docPath)) {
                $blockedDocs[] = 'plan_doc_missing:'.$doc;
                if ($orderedDocGate && $this->isOrderedPlanBacklogDoc($doc)) {
                    $blockedDocs[] = 'plan_ordered_doc_not_complete:'.$doc;

                    return $this->planBacklogNoReadySession($docs, $blockedDocs, $completeDocs);
                }

                continue;
            }

            try {
                $plan = $decomposer->decompose([
                    'doc_path' => $docPath,
                    'scope_profile' => (string) ($input['scope_profile'] ?? 'factory_max'),
                ]);
            } catch (Throwable $e) {
                $blockedDocs[] = 'plan_doc_decomposition_exception:'.$doc.':'.substr($e->getMessage(), 0, 120);
                if ($orderedDocGate && $this->isOrderedPlanBacklogDoc($doc)) {
                    $blockedDocs[] = 'plan_ordered_doc_not_complete:'.$doc;

                    return $this->planBacklogNoReadySession($docs, $blockedDocs, $completeDocs);
                }

                continue;
            }

            $planId = (string) ($plan['plan_id'] ?? '');
            if ($planId === '' || (string) ($plan['decomposition_status'] ?? '') === 'blocked') {
                $blockedDocs[] = 'plan_doc_decomposition_blocked:'.$doc.':'.implode(',', array_values(array_filter((array) ($plan['blockers'] ?? []), 'is_string')));
                if ($orderedDocGate && $this->isOrderedPlanBacklogDoc($doc)) {
                    $blockedDocs[] = 'plan_ordered_doc_not_complete:'.$doc;

                    return $this->planBacklogNoReadySession($docs, $blockedDocs, $completeDocs);
                }

                continue;
            }

            $rollupBefore = $tracker->rollup($planId, $areaId, $plan);
            $selection = $selector->selectNext($plan, $rollupBefore, $skipFindingKeys);
            $kind = (string) ($selection['kind'] ?? '');
            if ($kind === PlanSliceSelectionService::KIND_PLAN_COMPLETE) {
                $completeDocs[] = $doc;

                continue;
            }
            if ($kind !== PlanSliceSelectionService::KIND_SLICE_READY) {
                $blockedDocs[] = 'plan_doc_no_ready_slice:'.$doc.':'.(string) ($selection['reason'] ?? 'unknown');
                if ($orderedDocGate && $this->isOrderedPlanBacklogDoc($doc)) {
                    $blockedDocs[] = 'plan_ordered_doc_not_complete:'.$doc;

                    return $this->planBacklogNoReadySession($docs, $blockedDocs, $completeDocs);
                }

                continue;
            }

            $selectedSlice = is_array($selection['slice'] ?? null) ? $selection['slice'] : [];
            $slice = $decomposition->resolveExecutableSlice($selectedSlice);
            $cycle = $executor->executeSlice($slice, $this->planBacklogContext($input, $areaId, $focus));
            if (! is_array($cycle)) {
                $cycle = [];
            }
            if (! isset($cycle['selected_finding']) || ! is_array($cycle['selected_finding'])) {
                $sliceId = (string) ($selection['slice_id'] ?? $slice['slice_id'] ?? '');
                $cycle['selected_finding'] = [
                    'finding_id' => $sliceId,
                    'title' => (string) ($slice['title'] ?? $slice['delivery'] ?? $sliceId),
                ];
            }

            try {
                $rollupAfter = $tracker->recordCycle([
                    'decomposed_plan' => $plan,
                    'area_id' => $areaId,
                    'cycle' => $cycle,
                ]);
            } catch (Throwable $e) {
                $rollupAfter = $rollupBefore;
                $cycle['final_status'] = 'blocked';
                $cycle['blockers'] = array_values(array_unique(array_merge(
                    array_values(array_filter((array) ($cycle['blockers'] ?? []), 'is_string')),
                    ['plan_backlog_tracker_record_failed'],
                )));
                $cycle['tracker_error_excerpt'] = substr($e->getMessage(), 0, 160);
            }

            $planBacklog = [
                'schema_version' => self::PLAN_BACKLOG_BRIDGE_SCHEMA,
                'mode' => 'ap790_auto_plan_backlog',
                'doc_path' => $doc,
                'doc_index' => $index + 1,
                'doc_count' => count($docs),
                'plan_id' => $planId,
                'plan_hash' => (string) ($plan['plan_hash'] ?? ''),
                'decomposition_status' => (string) ($plan['decomposition_status'] ?? ''),
                'selection_kind' => $kind,
                'selection_reason' => (string) ($selection['reason'] ?? ''),
                'slice_id' => (string) ($selection['slice_id'] ?? ''),
                'finding_id' => (string) ($selection['finding_id'] ?? ''),
                'allowed_files' => array_values(array_filter((array) ($slice['allowed_files'] ?? []), 'is_string')),
                'total_slices' => (int) ($rollupBefore['total_slices'] ?? count((array) ($plan['slices'] ?? []))),
                'delivered_before' => (int) ($rollupBefore['delivered_count'] ?? 0),
                'delivered_after' => (int) ($rollupAfter['delivered_count'] ?? 0),
                'completion_pct_after' => (float) ($rollupAfter['completion_pct'] ?? 0.0),
                'tracker_blockers_after' => array_values(array_filter((array) ($rollupAfter['blockers'] ?? []), 'is_string')),
                'selection_skip_count' => count($skipFindingKeys),
                'ordered_doc_gate' => $orderedDocGate && $this->isOrderedPlanBacklogDoc($doc),
            ];
            $cycle['plan_backlog'] = $planBacklog;

            return [
                'schema_version' => AutonomousEvolutionSessionService::REPORT_SCHEMA,
                'status' => (string) ($cycle['final_status'] ?? '') === 'blocked' ? 'blocked' : 'completed',
                'cycles' => [$cycle],
                'plan_backlog' => $planBacklog,
            ];
        }

        return $this->planBacklogNoReadySession($docs, $blockedDocs, $completeDocs);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<string>
     */
    private function planBacklogDocs(array $input, string $areaId, string $focus): array
    {
        $explicit = $this->stringList($input['plan_backlog_docs'] ?? []);
        if ($explicit !== []) {
            return $explicit;
        }

        if ((bool) ($input['auto_plan_backlog'] ?? false) !== true) {
            return [];
        }
        if ($areaId !== 'agentic_engineering_os' || $focus !== 'dev_forge') {
            return [];
        }
        if ((string) ($input['scope_profile'] ?? 'factory_max') !== 'factory_max') {
            return [];
        }

        $repoRoot = $this->repoRootFromInput($input);
        $fromIndex = $this->planBacklogDocsFromIndex($repoRoot);

        return $fromIndex !== [] ? $fromIndex : self::DEFAULT_AAEOS_PLAN_BACKLOG_DOCS;
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  list<string>  $docs
     */
    private function shouldEnforceOrderedPlanBacklogDocs(array $input, string $areaId, string $focus, array $docs): bool
    {
        if ($areaId !== 'agentic_engineering_os' || $focus !== 'dev_forge') {
            return false;
        }
        if ((string) ($input['scope_profile'] ?? 'factory_max') !== 'factory_max') {
            return false;
        }

        foreach ($docs as $doc) {
            if ($this->isOrderedPlanBacklogDoc($doc)) {
                return true;
            }
        }

        return false;
    }

    private function isOrderedPlanBacklogDoc(string $doc): bool
    {
        return in_array($doc, self::DEFAULT_AAEOS_PLAN_BACKLOG_DOCS, true);
    }

    /**
     * @param  list<string>  $docs
     * @return list<string>
     */
    private function planBacklogOrderPreflightBlockers(array $docs): array
    {
        $canonical = self::DEFAULT_AAEOS_PLAN_BACKLOG_DOCS;
        $positions = array_flip($canonical);
        $seen = [];
        $maxIndex = -1;
        $lastIndex = -1;

        foreach ($docs as $doc) {
            if (! isset($positions[$doc])) {
                continue;
            }

            if (isset($seen[$doc])) {
                return ['plan_order_duplicate_doc:'.$doc];
            }

            $index = (int) $positions[$doc];
            if ($index <= $lastIndex) {
                return ['plan_order_docs_out_of_order:'.$doc];
            }

            $seen[$doc] = true;
            $maxIndex = max($maxIndex, $index);
            $lastIndex = $index;
        }

        if ($maxIndex < 0) {
            return [];
        }

        for ($index = 0; $index <= $maxIndex; $index++) {
            $doc = $canonical[$index];
            if (! isset($seen[$doc])) {
                return ['plan_order_predecessor_doc_not_in_selection:'.$doc.':before:'.$canonical[$maxIndex]];
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function planBacklogDocsFromIndex(string $repoRoot): array
    {
        $path = $this->absoluteRepoPath($repoRoot, self::AAEOS_PLAN_BACKLOG_INDEX);
        if (! is_file($path)) {
            return [];
        }
        $markdown = (string) file_get_contents($path);
        if ($markdown === '') {
            return [];
        }
        $count = preg_match_all('/`(atlas-aaeos-[^`]+\.md)`/', $markdown, $matches);
        if ($count === false || $count < 1) {
            return [];
        }

        $docs = [];
        foreach (array_values($matches[1]) as $file) {
            if (str_contains($file, 'index') || ! str_contains($file, 'backlog')) {
                continue;
            }
            $docs[] = 'docs/engineering-knowledge-base/'.$file;
        }

        return array_values(array_unique($docs));
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function planBacklogContext(array $input, string $areaId, string $focus): array
    {
        $context = [
            'area_id' => $areaId,
            'focus' => $focus,
            'scope_profile' => (string) ($input['scope_profile'] ?? 'factory_max'),
            'repo_root' => (string) ($input['repo_root'] ?? ''),
            'provider' => (string) ($input['provider'] ?? ''),
            'model' => (string) ($input['model'] ?? ''),
            'provider_explicit' => (bool) ($input['provider_explicit'] ?? false),
            'model_explicit' => (bool) ($input['model_explicit'] ?? false),
            'auto_merge' => (bool) ($input['auto_merge'] ?? false),
            'allow_code_auto_merge' => (bool) ($input['allow_code_auto_merge'] ?? false),
            'record' => (bool) ($input['record'] ?? false),
            'multi_agent_workcell' => (bool) ($input['multi_agent_workcell'] ?? false),
            'pull_main' => (bool) ($input['pull_main'] ?? false),
            'allow_direct_provider_driver' => (bool) ($input['allow_direct_provider_driver'] ?? false),
            'validation_commands' => array_values(array_filter((array) ($input['validation_commands'] ?? []), 'is_string')),
            'cycle_index' => 0,
        ];

        return array_filter($context, static fn (mixed $value): bool => $value !== '');
    }

    /**
     * @param  list<string>  $docs
     * @param  list<string>  $blockedDocs
     * @param  list<string>  $completeDocs
     * @return array<string,mixed>
     */
    private function planBacklogNoReadySession(array $docs, array $blockedDocs, array $completeDocs): array
    {
        $allComplete = count($docs) > 0 && count($completeDocs) === count($docs);
        $blockers = $allComplete
            ? ['backlog_exhausted', 'plan_backlog_all_docs_complete']
            : array_values(array_unique(array_merge(['plan_backlog_no_ready_slice'], $blockedDocs)));

        $cycle = [
            'cycle_id' => 'plan_backlog_no_ready_'.substr(MissionCanonicalHash::sha256([$docs, $blockedDocs, $completeDocs]), 0, 16),
            'final_status' => 'blocked',
            'selected_finding' => [
                'finding_id' => $allComplete ? 'plan_backlog_exhausted' : 'plan_backlog_blocked',
                'title' => $allComplete ? 'AAEOS plan backlog exhausted' : 'AAEOS plan backlog has no ready slice',
            ],
            'blockers' => $blockers,
            'merge_performed' => false,
            'provider_invoked' => false,
            'plan_backlog' => [
                'schema_version' => self::PLAN_BACKLOG_BRIDGE_SCHEMA,
                'mode' => 'ap790_auto_plan_backlog',
                'selection_kind' => $allComplete ? 'plan_complete' : 'blocked',
                'doc_count' => count($docs),
                'complete_docs' => $completeDocs,
                'blocked_docs' => $blockedDocs,
            ],
        ];

        return [
            'schema_version' => AutonomousEvolutionSessionService::REPORT_SCHEMA,
            'status' => 'blocked',
            'cycles' => [$cycle],
            'plan_backlog' => $cycle['plan_backlog'],
        ];
    }

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
            $process = new \Symfony\Component\Process\Process(['git', 'rev-parse', '--verify', '--quiet', $ref], $repoRoot);
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
        $blockers = array_values(array_filter((array) ($cycle['blockers'] ?? [])));
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
                'blockers' => array_values(array_filter((array) ($cycle['blockers'] ?? []), 'is_string')),
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

        foreach ($this->jsonlLines($path) as $line) {
            $record = json_decode($line, true);
            if (! is_array($record) || (string) ($record['schema_version'] ?? '') !== self::LEDGER_SCHEMA) {
                continue;
            }
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
                $keys = array_merge([$this->str($record['finding_key'] ?? '')], $this->stringList($record['finding_keys'] ?? []));
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
        if ($this->str($record['cycle_final_status'] ?? '') === 'dry_run_planned'
            || $this->str($record['session_status'] ?? '') === self::STATUS_DRY_RUN) {
            return false;
        }
        $blockers = (array) ($record['blockers'] ?? []);
        if ($this->quarantine->hasTransientBlocker($blockers)) {
            return false;
        }

        return ! $this->containsTerminalBlocker($blockers);
    }

    /**
     * Iterate JSONL files line-by-line so long-running loop ledgers do not blow
     * Product Mode or resume-state memory by calling file() on a growing ledger.
     *
     * @return \Generator<int,string>
     */
    private function jsonlLines(string $path): \Generator
    {
        if (! is_file($path)) {
            return;
        }

        $file = new \SplFileObject($path, 'rb');
        while (! $file->eof()) {
            $line = trim((string) $file->fgets());
            if ($line !== '') {
                yield $line;
            }
        }
    }

    /** @param array<string,mixed> $record */
    private function ledgerRecordLocksFindingAcrossRuns(array $record): bool
    {
        if ($this->ledgerRecordLooksLegacyPlanSliceScopeLayerFalsePositive($record)) {
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
    private function ledgerRecordLooksLegacyPlanSliceScopeLayerFalsePositive(array $record): bool
    {
        $blockers = array_values(array_filter(array_map(
            fn (mixed $blocker): string => $this->str($blocker),
            (array) ($record['blockers'] ?? []),
        )));
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
        $receipt = [
            'schema_version' => self::LEDGER_SCHEMA,
            'run_id' => $runId,
            'cycle_index' => $cycleIndex,
            'cycle_id' => $this->str($cycle['cycle_id'] ?? ''),
            'finding_key' => $findingKey,
            'finding_keys' => $this->findingKeys($cycle, $findingKey),
            'outcome' => $outcome,
            'work_class' => $this->workClass($cycle),
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
            'multi_agent_workcell' => $this->workcellLedgerSummary($cycle),
            'recorded_at' => $this->now(),
        ];
        if (isset($cycle['plan_backlog']) && is_array($cycle['plan_backlog'])) {
            $receipt['plan_backlog'] = $cycle['plan_backlog'];
        }

        // AP-807 (LHL-01) wire point: attach a diagnostic, read-only preflight_ref
        // ONLY when explicitly enabled. This never gates merge/judge/cleanup — it
        // records what the per-cycle firewall WOULD have decided so the ledger row
        // can reference a preflight receipt (AP-807 Definition of Done).
        if ($this->attachFirewallRef) {
            $receipt['preflight_ref'] = $this->diagnosticPreflightRef($runId, $cycleIndex, $cycle);
        }

        // AP-807 (LHL-02) wire point: attach a diagnostic, read-only
        // post_cycle_audit_ref ONLY when explicitly enabled. Symmetric to
        // preflight_ref: it records what the post-cycle auditor WOULD have verdicted
        // for this cycle (a compact reference: status + report_hash + violation
        // count). It NEVER gates merge/judge/cleanup behaviour and NEVER stops the
        // loop. Flag OFF => this key is absent and the ledger row is byte-identical.
        if ($this->attachAuditorRef) {
            $receipt['post_cycle_audit_ref'] = $this->diagnosticPostCycleAuditRef($runId, $cycleIndex, $outcome, $sessionReport, $cycle);
        }

        return $receipt;
    }

    /**
     * AP-807 (LHL-01): build a compact, read-only reference to what the preflight
     * firewall WOULD have decided for this cycle, using only data the runner
     * already holds. It NEVER invokes a provider, NEVER changes loop behaviour and
     * is fully fail-safe: a missing firewall or any throw yields an inert marker so
     * a long-running loop can never be broken by diagnostic instrumentation.
     *
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    private function diagnosticPreflightRef(string $runId, int $cycleIndex, array $cycle): array
    {
        $firewall = $this->resolveFirewall();
        if ($firewall === null) {
            return ['mode' => 'diagnostic', 'available' => false, 'reason' => 'firewall_not_wired'];
        }

        try {
            $finding = is_array($cycle['selected_finding'] ?? null) ? $cycle['selected_finding'] : [];
            $report = $firewall->evaluate([
                'run_id' => $runId,
                'cycle_index' => $cycleIndex,
                'candidate' => $finding,
            ]);

            return [
                'mode' => 'diagnostic',
                'available' => true,
                'schema_version' => $this->str($report['schema_version'] ?? ''),
                'firewall_id' => $this->str($report['firewall_id'] ?? ''),
                'status' => $this->str($report['status'] ?? ''),
                'block_status' => $this->str($report['block_status'] ?? ''),
                'report_hash' => $this->str($report['report_hash'] ?? ''),
            ];
        } catch (Throwable) {
            return ['mode' => 'diagnostic', 'available' => false, 'reason' => 'firewall_evaluate_failed'];
        }
    }

    /**
     * AP-807 (LHL-01): prefer the constructor-injected firewall (unit-test seam),
     * else lazily resolve it from the container at runtime. Nullable + fail-safe so
     * the loop never depends on it; only the opt-in diagnostic ref consults it.
     */
    private function resolveFirewall(): ?LoopPreflightCycleFirewallService
    {
        if ($this->preflightFirewall !== null) {
            return $this->preflightFirewall;
        }
        if (! function_exists('app')) {
            return null;
        }
        try {
            $resolved = app(LoopPreflightCycleFirewallService::class);

            return $resolved instanceof LoopPreflightCycleFirewallService ? $resolved : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * AP-807 (LHL-02): build a compact, read-only reference to what the post-cycle
     * auditor WOULD have verdicted for this cycle, using only the post-cycle data
     * the runner already holds (merge result / judge status / merge target / main +
     * lane before/after / cleanup). It NEVER invokes a provider, NEVER changes loop
     * behaviour and is fully fail-safe: a missing auditor or any throw yields an
     * inert marker so a long-running loop can never be broken by instrumentation.
     *
     * @param  array<string,mixed>  $sessionReport
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    private function diagnosticPostCycleAuditRef(string $runId, int $cycleIndex, string $outcome, array $sessionReport, array $cycle): array
    {
        $auditor = $this->resolveAuditor();
        if ($auditor === null) {
            return ['mode' => 'diagnostic', 'available' => false, 'reason' => 'auditor_not_wired'];
        }

        try {
            $finding = is_array($cycle['selected_finding'] ?? null) ? $cycle['selected_finding'] : [];
            $merge = is_array($cycle['merge_governance'] ?? null) ? $cycle['merge_governance'] : [];
            $mergePerformed = (bool) ($cycle['merge_performed'] ?? false);

            $report = $auditor->audit([
                'run_id' => $runId,
                'cycle_index' => $cycleIndex,
                'candidate' => $finding,
                'provider_invoked' => (bool) data_get($cycle, 'multi_agent_workcell.provider_invoked', false),
                'merge_performed' => $mergePerformed,
                'merge_target' => $this->str($merge['merge_target'] ?? data_get($cycle, 'loop_receipt.merge_target', '')),
                'merge_commit' => $this->str($cycle['merge_hash'] ?? data_get($cycle, 'loop_receipt.merge_hash', '')),
                'judge_status' => $this->str(data_get($cycle, 'multi_agent_workcell.judge_decision.status', '')),
                'main_before' => $this->str(data_get($cycle, 'merge_governance.main_before', '')),
                'main_after' => $this->str(data_get($cycle, 'merge_governance.main_after', '')),
                'lane_before' => $this->str(data_get($cycle, 'merge_governance.integration_lane.lane_commit_before', '')),
                'lane_after' => $this->str(data_get($cycle, 'merge_governance.integration_lane.lane_commit_after', '')),
                'cycle_outcome' => $outcome,
            ]);

            $violations = is_array($report['violations'] ?? null) ? $report['violations'] : [];

            return [
                'mode' => 'diagnostic',
                'available' => true,
                'schema_version' => $this->str($report['schema_version'] ?? ''),
                'status' => $this->str($report['status'] ?? ''),
                'report_hash' => $this->str($report['report_hash'] ?? ''),
                'violations_count' => count($violations),
            ];
        } catch (Throwable) {
            return ['mode' => 'diagnostic', 'available' => false, 'reason' => 'auditor_audit_failed'];
        }
    }

    /**
     * AP-807 (LHL-02): prefer the constructor-injected auditor (unit-test seam),
     * else lazily resolve it from the container at runtime. Nullable + fail-safe so
     * the loop never depends on it; only the opt-in diagnostic ref consults it.
     */
    private function resolveAuditor(): ?LoopPostCycleAuditorService
    {
        if ($this->postCycleAuditor !== null) {
            return $this->postCycleAuditor;
        }
        if (! function_exists('app')) {
            return null;
        }
        try {
            $resolved = app(LoopPostCycleAuditorService::class);

            return $resolved instanceof LoopPostCycleAuditorService ? $resolved : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Persist an auditable summary of the AP-801 multi-agent workcell projection
     * so a recorded cycle can be checked for real lane/session receipts, the judge
     * verdict and repair state — not just merge truth. Without this the workcell
     * ran in-process but left no auditable multi-agent evidence in the ledger.
     *
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    private function workcellLedgerSummary(array $cycle): array
    {
        $maw = is_array($cycle['multi_agent_workcell'] ?? null) ? $cycle['multi_agent_workcell'] : [];
        if ($maw === []) {
            return ['present' => false];
        }

        $lanes = is_array($maw['lane_sessions'] ?? null) ? $maw['lane_sessions'] : [];
        $role = fn (mixed $l): string => is_array($l) ? $this->str($l['role'] ?? $l['lane_id'] ?? '') : '';
        $sid = fn (mixed $l): string => is_array($l) ? $this->str($l['agent_session_id'] ?? $l['session_hash'] ?? '') : '';

        $ownerFlow = is_array($cycle['owner_flow'] ?? null) ? $cycle['owner_flow'] : [];
        $seniorLoopExitCode = $ownerFlow['senior_loop_exit_code'] ?? null;
        $seniorLoopStderr = $this->str($ownerFlow['senior_loop_stderr_excerpt'] ?? '');

        $summary = [
            'present' => true,
            'status' => $this->str($maw['status'] ?? ''),
            'lane_count' => (int) ($maw['lane_count'] ?? count($lanes)),
            'lanes' => array_values(array_filter(array_map($role, $lanes), static fn (string $r): bool => $r !== '')),
            'lane_session_ids' => array_values(array_filter(array_map($sid, $lanes), static fn (string $s): bool => $s !== '')),
            'provider_invoked' => (bool) ($maw['provider_invoked'] ?? false),
            'judge_status' => $this->str(data_get($maw, 'judge_decision.status', '')),
            'repair_status' => $this->str(data_get($maw, 'repair_decision.decision', data_get($maw, 'repair_decision.classification', ''))),
            'merge_eligible' => (bool) ($maw['merge_eligible'] ?? false),
            'production_certified' => (bool) ($maw['production_certified'] ?? false),
        ];

        // Surface senior-loop failure details (exit_code + stderr) so the JSONL
        // ledger has enough signal to diagnose a not_executed/not_passed cycle
        // without correlating AP-759 records separately.
        if ($seniorLoopExitCode !== null || $seniorLoopStderr !== '') {
            $summary['senior_loop_failure_detail'] = array_filter([
                'exit_code' => $seniorLoopExitCode,
                'stderr_excerpt' => $seniorLoopStderr !== '' ? $seniorLoopStderr : null,
            ], static fn (mixed $v): bool => $v !== null);
        }

        return $summary;
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
            'multi_agent_workcell' => is_array($receipt['multi_agent_workcell'] ?? null) ? $receipt['multi_agent_workcell'] : ['present' => false],
            'plan_backlog' => is_array($receipt['plan_backlog'] ?? null) ? $receipt['plan_backlog'] : null,
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
        $sandboxId = $this->str($cycle['sandbox_id'] ?? data_get($cycle, 'sandbox.sandbox_id', data_get($cycle, 'loop_receipt.sandbox_id', '')));
        if ($sandboxId === '') {
            return;
        }
        try {
            $cleanupRejectedProviderDiff = $this->containsSpecificBlocker($cycle, AutonomousEvolutionSessionService::PROVIDER_DIFF_QUALITY_BLOCKER)
                && (string) data_get($cycle, 'commit.status', '') === '';
            $cleanupRejectedTerminalDiff = $this->containsAnySpecificBlocker($cycle, [
                FinalDeliveryQualityGateService::BLOCKER,
                'minimax_no_code_extracted',
                'senior_loop_repair_exhausted',
                'repeated_repair_no_progress',
                'review_locked',
                'php_syntax_error_after_max_repairs',
                ZeroProviderPreflightGate::REASON_PRIOR_NON_RETRYABLE_FAILURE_PATTERN,
            ]);
            // AP-756 cleanupSandbox refuses to remove dirty worktrees or anything
            // outside the controlled worktrees root unless the loop itself has
            // just rejected an uncommitted provider diff-quality failure. In that
            // narrow case, dirty removal is the safety action: the bad provider
            // WIP must not become a branch pile-up.
            $cleanup = [
                'sandbox_id' => $sandboxId,
                'area_id' => $areaId,
                'remove_sandbox' => true,
                'delete_branch' => true,
                'only_if_merged' => true,
                'only_if_clean' => true,
            ];
            if ($cleanupRejectedProviderDiff || $cleanupRejectedTerminalDiff) {
                $cleanup['allow_dirty_removal'] = true;
                $cleanup['allow_unmerged_branch_delete'] = true;
                $cleanup['only_if_merged'] = false;
                $cleanup['only_if_clean'] = false;
            }
            $this->materializer->cleanupSandbox($cleanup);
        } catch (Throwable) {
            // Cleanup is best-effort and must never break the loop.
        }
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function containsSpecificBlocker(array $cycle, string $expected): bool
    {
        $ownerRuntimeExpected = 'owner_runtime_'.$expected;
        foreach ((array) ($cycle['blockers'] ?? []) as $blocker) {
            $blocker = $this->str($blocker);
            if ($blocker === $expected || $blocker === $ownerRuntimeExpected) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @param  list<string>  $expected
     */
    private function containsAnySpecificBlocker(array $cycle, array $expected): bool
    {
        foreach ($expected as $blocker) {
            if ($this->containsSpecificBlocker($cycle, $blocker)) {
                return true;
            }
        }

        return false;
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

    /**
     * Kill only provider/owner-runtime processes that are attributable to the
     * AP-756 controlled worktree root. This is intentionally narrower than a
     * global `cursor-agent` kill: unrelated operator sessions are left alone,
     * while stale sandbox children cannot keep mutating branches/ledger after a
     * cycle was already blocked or a run was stopped.
     *
     * @param  array<string,mixed>  $input
     * @return list<int>
     */
    private function reapLoopSandboxProcesses(array $input, bool $execute, string $areaId): array
    {
        if (! $execute || (bool) ($input['cleanup_worktrees'] ?? false) !== true) {
            return [];
        }

        $root = $this->controlledWorktreeRoot();
        if ($root === '') {
            return [];
        }

        $processes = $this->processTable();
        $targets = $this->sandboxProcessTargets($processes, $root);
        if ($targets === []) {
            return [];
        }

        $depths = [];
        foreach ($targets as $pid => $_process) {
            $depths[$pid] = $this->processTreeDepth($pid, $processes);
        }

        $pids = array_keys($targets);
        usort($pids, static fn (int $a, int $b): int => ($depths[$b] ?? 0) <=> ($depths[$a] ?? 0));

        $killed = [];
        $self = function_exists('getmypid') ? (getmypid() ?: 0) : 0;
        foreach ($pids as $pid) {
            if ($pid < 1 || $pid === $self) {
                continue;
            }
            if ($this->sendProcessSignal($pid, 'TERM')) {
                $killed[] = $pid;
                if ($this->processKiller === null && $this->processAlive($pid)) {
                    usleep(100000);
                    if ($this->processAlive($pid)) {
                        $this->sendProcessSignal($pid, 'KILL');
                    }
                }
            }
        }

        return $killed;
    }

    private function controlledWorktreeRoot(): string
    {
        try {
            $storageDir = method_exists($this->materializer, 'storageDir')
                ? (string) $this->materializer->storageDir()
                : (function_exists('storage_path')
                    ? storage_path('atlas/software_company_stewardship/area_focus_branch_sandboxes')
                    : sys_get_temp_dir().'/atlas/software_company_stewardship/area_focus_branch_sandboxes');
        } catch (Throwable) {
            return '';
        }

        return rtrim($this->normalizePath($storageDir.DIRECTORY_SEPARATOR.'worktrees'), DIRECTORY_SEPARATOR);
    }

    /**
     * @return list<array{pid:int,ppid:int,command:string,cwd:string}>
     */
    private function processTable(): array
    {
        if ($this->processTableProvider !== null) {
            return array_values(array_map(fn (array $process): array => [
                'pid' => max(0, (int) ($process['pid'] ?? 0)),
                'ppid' => max(0, (int) ($process['ppid'] ?? 0)),
                'command' => $this->str($process['command'] ?? ''),
                'cwd' => $this->str($process['cwd'] ?? ''),
            ], ($this->processTableProvider)()));
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return [];
        }

        $output = (string) shell_exec('ps -axo pid=,ppid=,command= 2>/dev/null');
        $rows = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if (! preg_match('/^\s*(\d+)\s+(\d+)\s+(.*)$/', $line, $matches)) {
                continue;
            }
            $pid = (int) $matches[1];
            $rows[] = [
                'pid' => $pid,
                'ppid' => (int) $matches[2],
                'command' => trim($matches[3]),
                'cwd' => '',
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{pid:int,ppid:int,command:string,cwd:string}>  $processes
     * @return array<int,array{pid:int,ppid:int,command:string,cwd:string}>
     */
    private function sandboxProcessTargets(array $processes, string $root): array
    {
        $targets = [];
        $children = [];
        foreach ($processes as $process) {
            $children[(int) $process['ppid']][] = (int) $process['pid'];
            if ($this->processBelongsToLoopSandbox($process, $root)) {
                $targets[(int) $process['pid']] = $process;
            }
        }

        $queue = array_keys($targets);
        while ($queue !== []) {
            $pid = array_shift($queue);
            foreach ((array) ($children[$pid] ?? []) as $childPid) {
                if (isset($targets[$childPid])) {
                    continue;
                }
                $child = $this->findProcess($processes, $childPid);
                if ($child === null) {
                    continue;
                }
                $targets[$childPid] = $child;
                $queue[] = $childPid;
            }
        }

        return $targets;
    }

    /** @param array{pid:int,ppid:int,command:string,cwd:string} $process */
    private function processBelongsToLoopSandbox(array $process, string $root): bool
    {
        $command = (string) $process['command'];
        if (! $this->isLoopProviderCommand($command)) {
            return false;
        }

        if ($this->stringContainsPath($command, $root)) {
            return true;
        }

        $cwd = $process['cwd'] !== '' ? $process['cwd'] : $this->processCwd((int) $process['pid']);

        return $cwd !== null && $this->pathWithin($cwd, $root);
    }

    private function isLoopProviderCommand(string $command): bool
    {
        foreach ([
            'atlas:dev:senior-loop:run',
            'cursor-agent',
            'worker-server',
            'composer-2.5',
            'claude',
            'codex',
        ] as $needle) {
            if (str_contains($command, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function processCwd(int $pid): ?string
    {
        if ($this->processCwdProvider !== null) {
            $cwd = ($this->processCwdProvider)($pid);

            return is_string($cwd) && $cwd !== '' ? $cwd : null;
        }
        if ($pid < 1 || PHP_OS_FAMILY === 'Windows') {
            return null;
        }

        $output = (string) shell_exec('lsof -a -p '.escapeshellarg((string) $pid).' -d cwd -Fn 2>/dev/null');
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if (str_starts_with($line, 'n')) {
                return substr($line, 1) ?: null;
            }
        }

        return null;
    }

    private function sendProcessSignal(int $pid, string $signal): bool
    {
        if ($this->processKiller !== null) {
            return (bool) ($this->processKiller)($pid, $signal);
        }
        if ($pid < 1) {
            return false;
        }

        if (function_exists('posix_kill')) {
            $signum = $signal === 'KILL' && defined('SIGKILL')
                ? SIGKILL
                : (defined('SIGTERM') ? SIGTERM : 15);

            return @posix_kill($pid, $signum);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        $cmd = 'kill -'.escapeshellarg($signal).' '.escapeshellarg((string) $pid).' >/dev/null 2>&1; echo $?';

        return trim((string) shell_exec($cmd)) === '0';
    }

    private function processAlive(int $pid): bool
    {
        if ($pid < 1) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        return trim((string) shell_exec('ps -p '.escapeshellarg((string) $pid).' -o pid= 2>/dev/null')) !== '';
    }

    /**
     * @param  list<array{pid:int,ppid:int,command:string,cwd:string}>  $processes
     * @return array{pid:int,ppid:int,command:string,cwd:string}|null
     */
    private function findProcess(array $processes, int $pid): ?array
    {
        foreach ($processes as $process) {
            if ((int) $process['pid'] === $pid) {
                return $process;
            }
        }

        return null;
    }

    /**
     * @param  list<array{pid:int,ppid:int,command:string,cwd:string}>  $processes
     */
    private function processTreeDepth(int $pid, array $processes): int
    {
        $depth = 0;
        $current = $this->findProcess($processes, $pid);
        while ($current !== null && (int) $current['ppid'] > 0 && $depth < 50) {
            $depth++;
            $current = $this->findProcess($processes, (int) $current['ppid']);
        }

        return $depth;
    }

    private function stringContainsPath(string $haystack, string $path): bool
    {
        $path = rtrim($this->normalizePath($path), DIRECTORY_SEPARATOR);

        return $path !== '' && str_contains($haystack, $path);
    }

    private function pathWithin(string $path, string $root): bool
    {
        $path = rtrim($this->normalizePath($path), DIRECTORY_SEPARATOR);
        $root = rtrim($this->normalizePath($root), DIRECTORY_SEPARATOR);

        return $path !== '' && $root !== ''
            && ($path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR));
    }

    private function normalizePath(string $path): string
    {
        $path = preg_replace('/\s+\(deleted\)$/', '', trim($path)) ?: trim($path);
        if ($path === '') {
            return '';
        }

        return realpath($path) ?: $path;
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
    private function report(string $areaId, string $focus, string $runId, string $status, string $stopReason, array $cycleReports, array $budgets, bool $execute, bool $dryRun, ?array $lockHolder, int $cyclesThisRun, int $mergesTotal, int $blockedInRow, int $resumedFrom = 0, int $cyclesTotal = 0, int $seenFindingCount = 0, array $stewardshipRecovery = []): array
    {
        if ($stewardshipRecovery === []) {
            $stewardshipRecovery = $this->stewardshipRecoveryUntilConsecutiveMergedCyclesNormal([
                'area_id' => $areaId,
                'focus' => $focus,
            ]);
        }

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
            'stewardship_recovery' => $stewardshipRecovery,
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
            self::STATUS_CASCADE_HALT => ['A recurring execution-failure cascade halted the loop; inspect the senior-loop/provider diagnostics in the ledger before re-running.'],
            self::STATUS_PROVIDER_WASTE => ['Provider spend produced an unsafe/non-useful diff; inspect the cycle blockers and improve slicing/preflight before re-running.'],
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

    /**
     * Sleep for $sleepSeconds while staying interruptible by the kill/pause files.
     *
     * Instead of one blocking sleep(N) — which honors a mid-sleep kill only AFTER
     * the full N elapses — this sleeps in SLEEP_INTERRUPT_GRANULARITY_SECONDS chunks
     * and re-checks the signal files between chunks. It checks the kill/pause files
     * BEFORE the first chunk too, so a signal that arrives the instant the cycle
     * ends is honored immediately. Returns the interrupt status (STATUS_KILLED or
     * STATUS_PAUSED) the moment a signal is seen, or null when the full sleep
     * elapsed undisturbed. Kill takes precedence over pause.
     */
    private function responsiveSleep(int $sleepSeconds, string $areaId, string $focus): ?string
    {
        if ($sleepSeconds <= 0) {
            return null;
        }

        $granularity = max(1, self::SLEEP_INTERRUPT_GRANULARITY_SECONDS);
        for ($slept = 0; $slept < $sleepSeconds; $slept += $granularity) {
            // Check before sleeping the chunk so a signal at the cycle boundary is
            // honored without waiting one granularity window.
            if ($this->killSwitchActive($areaId, $focus, [])) {
                return self::STATUS_KILLED;
            }
            if ($this->pauseActive($areaId, $focus, [])) {
                return self::STATUS_PAUSED;
            }

            $this->sleep(min($granularity, $sleepSeconds - $slept));
        }

        // Final check after the last chunk so a signal arriving during the very
        // last window still stops the loop before the next cycle begins.
        if ($this->killSwitchActive($areaId, $focus, [])) {
            return self::STATUS_KILLED;
        }
        if ($this->pauseActive($areaId, $focus, [])) {
            return self::STATUS_PAUSED;
        }

        return null;
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
