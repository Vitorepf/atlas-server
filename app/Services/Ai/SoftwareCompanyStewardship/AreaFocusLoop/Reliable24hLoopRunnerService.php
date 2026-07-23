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
use App\Services\Ai\SoftwareCompanyStewardship\Concerns\HasStewardshipStorageRoot;
use App\Services\Ai\Support\JsonFileStore;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
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

    /** The lock holder released the same mission at an iteration boundary for a queued successor. */
    public const STATUS_TRANSFER_REQUESTED = 'stopped_transfer_requested';

    public const STATUS_BUDGET = 'stopped_budget';

    public const STATUS_BLOCKED_STOP = 'stopped_on_blocked';

    public const STATUS_REPEATED = 'stopped_repeated_finding';

    public const STATUS_BACKLOG_EXHAUSTED = 'stopped_backlog_exhausted';

    /** Stopped because a recurring failure cascade (same tier) cannot be safely retried. */
    public const STATUS_CASCADE_HALT = 'stopped_failure_cascade';

    /** Stopped because a provider-spent diff was rejected as unsafe/non-useful. */
    public const STATUS_PROVIDER_WASTE = 'stopped_provider_waste';

    /** Stopped because a wrapped cycle claimed merge, but the target main ref did not advance. */
    public const STATUS_MERGE_TRUTH_FAILURE = 'stopped_merge_truth_failure';

    public const MERGE_TRUTH_MAIN_NOT_ADVANCED_BLOCKER = 'merge_truth_main_not_advanced_after_claimed_merge';

    /**
     * Stopped because the self-maintenance merge cap was hit. A 24h loop that keeps
     * merging its OWN recovery/maintenance work (instead of product findings) is
     * spinning on filler; the cap forces an honest stop so self-maintenance can
     * never crowd out real product throughput over a long run.
     */
    public const STATUS_SELF_MAINTENANCE_CAP = 'stopped_self_maintenance_cap';

    /** Single-writer guard: a mutating run was refused on the canonical/human checkout. */
    public const STATUS_CANONICAL_WORKTREE_REFUSED = 'stopped_canonical_worktree_write_refused';

    /** Controller branch guard: loop-runner code must be promoted before provider spend. */
    public const STATUS_LOOP_RUNNER_BASE_REFUSED = 'stopped_loop_runner_branch_not_promoted';

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

    private const PLAN_BACKLOG_STUCK_THRESHOLD = 3;

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
        self::MERGE_TRUTH_MAIN_NOT_ADVANCED_BLOCKER,
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

    use HasStewardshipStorageRoot;
    use \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoop\SessionPlanBacklogSection;
    use \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoop\CycleGovernanceSection;
    use \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoop\CycleReceiptRuntimeSection;

    private const STORAGE_SUBPATH = 'atlas/software_company_stewardship/reliable_24h_loop';

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

    private ?Reliable24hLoopHandoffService $handoffService = null;

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

    public function handoff(): Reliable24hLoopHandoffService
    {
        return $this->handoffService ??= new Reliable24hLoopHandoffService($this);
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
        foreach (AreaFocusJsonlReader::streamRowsWithSchemaVersion($path, self::LEDGER_SCHEMA) as $record) {
            $records[] = $record;
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
        $holder = JsonFileStore::readArray($path);
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
        $areaId = AreaFocusSlugNormalizer::lowerFileToken((string) ($input['area_id'] ?? 'agentic_engineering_os'), '') ?: 'agentic_engineering_os';
        $focus = AreaFocusSlugNormalizer::lowerFileToken((string) ($input['focus'] ?? 'dev_forge'), '') ?: 'dev_forge';

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
        $areaId = AreaFocusSlugNormalizer::lowerFileToken((string) ($input['area_id'] ?? 'agentic_engineering_os'), '') ?: 'agentic_engineering_os';
        $focus = AreaFocusSlugNormalizer::lowerFileToken((string) ($input['focus'] ?? 'dev_forge'), '') ?: 'dev_forge';
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
        $runId = 'ap790run_'.substr(MissionCanonicalHash::sha256([$areaId, $focus, AreaFocusUtcClock::atomNow(), random_int(0, PHP_INT_MAX)]), 0, 18);

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
            $writeGuard = (new CanonicalWorktreeWriteGuard)->decide([
                'repo_root' => $this->repoRootFromInput($input),
                'on_canonical' => (new LoopWorktreeTopologyVerifierService)->isCanonicalCheckout($this->repoRootFromInput($input)),
                'is_mutating' => true,
                'allow_canonical_worktree_write' => (bool) ($input['allow_canonical_worktree_write'] ?? false),
            ]);
            if (($writeGuard['decision'] ?? '') === CanonicalWorktreeWriteGuard::DECISION_REFUSED) {
                return $this->report($areaId, $focus, $runId, self::STATUS_CANONICAL_WORKTREE_REFUSED, CanonicalWorktreeWriteGuard::BLOCKER, [], $budgets, $execute, $dryRun, null, 0, 0, 0);
            }
        }

        // 1c. Controller-base guard (operator mandate, 2026-06-01): when AP-790
        // runs from an atlas/loop-runner branch, AP-786 inherits that branch as
        // the sandbox base. If the controller branch is ahead/diverged from main,
        // every provider candidate carries supervisor commits in its diff and the
        // merge governor sees a polluted 8+ commit branch instead of the slice.
        // Stop before spending provider; promote the controller fixes to main (or
        // switch to a branch at main) first.
        if ($execute) {
            $controllerBase = $this->loopRunnerControllerBaseState($this->repoRootFromInput($input));
            if (($controllerBase['ok'] ?? true) !== true) {
                return $this->report($areaId, $focus, $runId, self::STATUS_LOOP_RUNNER_BASE_REFUSED, (string) ($controllerBase['reason'] ?? 'loop_runner_branch_not_promoted_to_main'), [], $budgets, $execute, $dryRun, null, 0, 0, 0);
            }
        }

        // 2. Exclusive lock per area/focus.
        $lock = $this->acquireLock($areaId, $focus, $runId, $leaseTtl, $input);
        if ($lock['acquired'] !== true) {
            return $this->report($areaId, $focus, $runId, self::STATUS_LOCK_HELD, 'lock_held_by_'.(string) ($lock['holder']['run_id'] ?? 'unknown'), [], $budgets, $execute, $dryRun, $lock['holder'] ?? null, 0, 0, 0);
        }

        $handoffId = trim((string) ($input['handoff_id'] ?? ''));

        try {
            if ($handoffId !== '') {
                $claimed = $this->handoff()->claimTarget(
                    $handoffId,
                    $areaId,
                    $focus,
                    $runId,
                    gethostname() ?: 'unknown',
                );
                if ($claimed === null) {
                    return $this->report($areaId, $focus, $runId, self::STATUS_LOCK_HELD, 'handoff_not_claimable', [], $budgets, $execute, $dryRun, null, 0, 0, 0);
                }
            }
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
            $releasedHandoffId = null;

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
                if ($handoff = $this->handoff()->requestForSource($areaId, $focus, $runId)) {
                    $released = $this->handoff()->markSourceReleased((string) $handoff['handoff_id'], $runId, [
                        'cycles_total' => $cycleIndex,
                        'cycles_this_run' => $cyclesThisRun,
                        'merges_total' => $mergesTotal,
                        'blocked_in_row' => $blockedInRow,
                        'last_cycle' => $cycleReports === [] ? null : $cycleReports[array_key_last($cycleReports)],
                        'recorded_at' => AreaFocusUtcClock::atomNow(),
                    ]);
                    if ($released !== null) {
                        $releasedHandoffId = (string) $released['handoff_id'];
                        $status = self::STATUS_TRANSFER_REQUESTED;
                        $stopReason = 'transfer_requested_by_operator';
                        break;
                    }
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
                if ($findingKey !== ''
                    && isset($seenFindingKeys[$findingKey])
                    && $priorOutcome !== self::OUTCOME_BLOCKED
                    && ! $this->cycleReplaysRehabilitatedPlanSlice($cycle, $findingKey)
                    && ! $this->cycleReconcilesProviderProofPlanSlice($cycle, $findingKey)
                    && ! $this->cycleRecordsSupervisedExistingDelivery($cycle, $findingKey)) {
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
                $mergeTruthFailure = false;
                if ($outcome === self::OUTCOME_MERGED) {
                    // Merge-truth: prove main actually advanced. Evidence is always
                    // recorded; the COUNT is gated only when enforcement is opted in
                    // (default off so existing behavior / faked-session tests are
                    // unchanged). A real false merge is already blocked upstream by
                    // the governor's nothing_to_merge guard — this is the count-site
                    // backstop for the operator mandate "merge só conta se main avançou".
                    $mergeTruth = (new MergeTruthValidator)->validate([
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
                        // never counted as autonomy under enforcement. Normalize the
                        // wrapped AP-786 cycle before writing the AP-790 receipt; a
                        // sandbox/lane commit hash is not a main merge hash.
                        $outcome = self::OUTCOME_BLOCKED;
                        $mergeTruthFailure = true;
                        $claimedMergeHash = $this->str($cycle['merge_hash'] ?? data_get($cycle, 'loop_receipt.merge_hash', ''));
                        $blockers = AreaFocusStringListNormalizer::coercedStringValues($cycle['blockers'] ?? []);
                        $blockers[] = self::MERGE_TRUTH_MAIN_NOT_ADVANCED_BLOCKER;
                        $cycle['blockers'] = AreaFocusStringListNormalizer::uniqueStringValues($blockers);
                        $cycle['merge_performed'] = false;
                        $cycle['merge_hash'] = '';
                        $cycle['claimed_merge_hash'] = $claimedMergeHash;
                        $cycle['false_merge_truth'] = [
                            'blocker' => self::MERGE_TRUTH_MAIN_NOT_ADVANCED_BLOCKER,
                            'claimed_merge_hash' => $claimedMergeHash,
                            'main_before' => $this->str($mergeTruth['main_before'] ?? ''),
                            'main_after' => $this->str($mergeTruth['main_after'] ?? ''),
                        ];
                        $blockedInRow++;
                    }
                } elseif ($outcome === self::OUTCOME_BLOCKED) {
                    $reviewLockedExistingBranch = $this->containsSpecificBlocker($cycle, 'review_locked_existing_branch');
                    if ($findingKey !== '' && ! $reviewLockedExistingBranch) {
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
                if ($mergeTruthFailure) {
                    $status = self::STATUS_MERGE_TRUTH_FAILURE;
                    $stopReason = self::MERGE_TRUTH_MAIN_NOT_ADVANCED_BLOCKER.($findingKey !== '' ? ':'.$findingKey : '');
                    break;
                }
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
                        AreaFocusStringListNormalizer::coercedStringValues($cycle['blockers'] ?? []),
                        self::PROVIDER_WASTE_BLOCKERS,
                    )));
                    break;
                }

                if ($outcome === self::OUTCOME_BLOCKED && $this->containsSpecificBlocker($cycle, 'review_locked_existing_branch')) {
                    $status = self::STATUS_BLOCKED_STOP;
                    $stopReason = 'review_locked_existing_branch'.($findingKey !== '' ? ':'.$findingKey : '');
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
                if (in_array('backlog_exhausted', AreaFocusStringListNormalizer::coercedStringValues($cycle['blockers'] ?? []), true)) {
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

            $report = $this->report(
                $areaId, $focus, $runId, $status, $stopReason, $cycleReports, $budgets, $execute, $dryRun,
                null, $cyclesThisRun, $mergesTotal, $blockedInRow,
                resumedFrom: (int) $resume['last_cycle_index'],
                cyclesTotal: $cycleIndex,
                seenFindingCount: count($seenFindingKeys),
                stewardshipRecovery: $stewardshipRecovery->toArray(),
            );
            if ($releasedHandoffId !== null) {
                $report['handoff_id'] = $releasedHandoffId;
                $report['handoff'] = $this->handoff()->publicRecord($releasedHandoffId);
            }

            return $report;
        } finally {
            $this->reapLoopSandboxProcesses($input, $execute, $areaId);
            $this->releaseLock($areaId, $focus, $runId);
            // Never let an opt-in wire flag leak across runs on a shared singleton.
            $this->attachFirewallRef = false;
            $this->attachAuditorRef = false;
        }
    }
}
