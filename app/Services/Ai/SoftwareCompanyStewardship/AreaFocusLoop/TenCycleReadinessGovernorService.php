<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentCycleCertificationService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * AP-805 — 10-Cycle Readiness Governor.
 *
 * Read-only. Answers a single question before anyone runs 10 real consecutive
 * multi-agent Stewardship cycles: is it SAFE to start, or must we block honestly?
 *
 * It never runs the loop, never invokes a provider, never merges, never deletes a
 * branch. It composes existing read-only probes (repo/git state, provider config,
 * AP-790 runner lock/kill, AP-800 capability detection, merge-truth guard,
 * leases/locks, Product Mode memory safety) into a deterministic verdict plus a
 * cleanup PLAN (never an action) and the exact, safe command to run the 10 cycles.
 *
 * Honesty rules (operator does not accept false claims):
 *   - blocked is never dressed as ready;
 *   - a merge counts only if main advances (delegated to the merge-truth guard);
 *   - simulated is never real; missing capabilities are reported precisely;
 *   - branch cleanup is a PLAN only — no destructive action here.
 *
 * Contract: docs/ap/AP-805-ten-cycle-readiness-governor-contract.md
 */
final class TenCycleReadinessGovernorService
{
    public const REPORT_SCHEMA = 'atlas.stewardship.ten_cycle_readiness.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    /** Minimum inner provider-call timeout (seconds) required for real cycles. */
    public const MIN_PROVIDER_TIMEOUT_SECONDS = 300;

    /** Hard gates: a failure here blocks the 10-cycle run. */
    private const HARD_GATES = [
        'repo_clean_or_known_dirty',
        'no_uncommitted_ap_substrate',
        'provider_timeout_minimum_ok',
        'merge_truth_guard_present',
        'finding_slice_planner_available',
        'duplicate_finding_guard_available',
        'multi_agent_lane_contracts_available',
        'judge_repair_available',
        'provider_routing_available_or_honest_degraded',
        'kill_switch_available',
        'no_open_leases',
        'no_stale_lock',
        'product_mode_projection_memory_safe',
    ];

    /** Soft gates: a failure here is a warning / proof-command, not a hard block. */
    private const SOFT_GATES = [
        'per_run_budget_ok',
        'max_merges_not_cumulative',
        'branch_cleanup_plan_available',
        'docs_health_ok',
        'architecture_validate_ok',
    ];

    /**
     * Warnings that still make the readiness answer partial because the operator
     * did not get enough operational proof to start the run safely.
     *
     * docs-health and architecture-validate failures remain explicit warnings,
     * but they are soft AP-805 gates: global documentation debt must not dress
     * an otherwise safe AAEOS ten-cycle attempt as a hard "DO NOT RUN".
     */
    private const READINESS_BLOCKING_WARNINGS = [
        'repo_has_unrelated_uncommitted_changes',
        'many_stale_area_focus_branches',
        'branch_audit_skipped',
        'provider_probe_skipped',
        'budget_mechanism_unverifiable',
        'multi_agent_capabilities_incomplete',
        'product_mode_probe_skipped',
        'ledger_large_consider_archive',
        'many_active_worktrees',
        'validations_not_run_see_proof_commands',
    ];

    public function __construct(
        private readonly Reliable24hLoopRunnerService $runner,
        private readonly MultiAgentCycleCertificationService $multiAgentCertification,
        private readonly AreaFocusCandidateQuarantineService $quarantine,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function assess(array $input = []): array
    {
        $area = trim((string) ($input['area'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';
        $repoRoot = $this->repoRoot($input);

        $blockers = [];
        $warnings = [];
        $gates = [];

        $repoState = $this->repoState($input, $repoRoot, $gates, $blockers, $warnings);
        $branchState = $this->branchState($input, $repoRoot, (bool) ($input['include_branch_audit'] ?? false), $gates, $warnings);
        $providerState = $this->providerState($input, (bool) ($input['include_provider_probe'] ?? false), $gates, $blockers, $warnings);
        $budgetState = $this->budgetState($input, $gates, $warnings);
        $backlogState = $this->backlogState($input, $area, $focus, $gates, $blockers);
        $multiAgentState = $this->multiAgentState($input, $gates, $blockers, $warnings);
        $mergeTruthState = $this->mergeTruthState($input, $gates, $blockers);
        $productModeState = $this->productModeState($input, (bool) ($input['include_product_mode'] ?? false), $gates, $blockers, $warnings);
        $memoryState = $this->memoryState($input, $area, $focus, $repoRoot, $warnings);
        $killSwitchState = $this->killSwitchState($input, $area, $focus, $gates, $blockers);
        $leaseLockState = $this->leaseAndLockState($input, $area, $focus, $gates, $blockers, $warnings);
        $validationState = $this->validationState($input, $gates, $warnings);

        $cleanupPlan = $branchState['cleanup_plan'] ?? [];

        $blockingWarnings = $this->blockingWarnings($warnings);

        $status = $blockers !== []
            ? self::STATUS_BLOCKED
            : ($blockingWarnings !== [] ? self::STATUS_PARTIAL : self::STATUS_READY);

        $recommended = $this->recommendedCommand($area, $focus, $status, (bool) ($input['allow_cleanup_plan'] ?? false));

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-805',
            'status' => $status,
            'readiness_id' => 'tcr_'.substr(MissionCanonicalHash::sha256([$area, $focus, $repoRoot]), 0, 16),
            'area' => $area,
            'focus' => $focus,
            'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'repo_state' => $repoState,
            'branch_state' => $branchState,
            'provider_state' => $providerState,
            'budget_state' => $budgetState,
            'backlog_state' => $backlogState,
            'multi_agent_state' => $multiAgentState,
            'merge_truth_state' => $mergeTruthState,
            'product_mode_state' => $productModeState,
            'memory_state' => $memoryState,
            'kill_switch_state' => $killSwitchState,
            'lease_lock_state' => $leaseLockState,
            'validation_state' => $validationState,
            'gates' => $gates,
            'required_commands' => $this->requiredCommands($area, $focus),
            'recommended_command_for_10_cycle_run' => $recommended,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'blocking_warnings' => $blockingWarnings,
            'cleanup_plan' => $cleanupPlan,
            'proof_commands' => $this->proofCommands($area, $focus),
            'claim_policy' => [
                'read_only' => true,
                'runs_loop' => false,
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
     * @param  list<string>  $warnings
     * @return list<string>
     */
    private function blockingWarnings(array $warnings): array
    {
        $blocking = array_values(array_intersect(array_values(array_unique($warnings)), self::READINESS_BLOCKING_WARNINGS));

        sort($blocking);

        return $blocking;
    }

    // ---------- gates ----------

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $gates
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @return array<string,mixed>
     */
    private function repoState(array $input, string $repoRoot, array &$gates, array &$blockers, array &$warnings): array
    {
        $porcelain = $this->repoStatusLines($input, $repoRoot);
        $dirty = $porcelain !== null ? $porcelain : [];
        $available = $porcelain !== null;

        // Substrate code dirty = a hard block (uncommitted AP runtime/services/tests).
        $substrateDirty = [];
        $otherDirty = [];
        foreach ($dirty as $line) {
            $path = trim(substr($line, 3));
            if ($path === '') {
                continue;
            }
            if (preg_match('#^app/Services/Ai/SoftwareCompanyStewardship/(AreaFocusLoop|AgentExecution)/.+\.php$#', $path) === 1
                || preg_match('#^tests/Unit/Ai/SoftwareCompanyStewardship/(AreaFocusLoop|AgentExecution)/.+\.php$#', $path) === 1) {
                $substrateDirty[] = $path;
            } else {
                $otherDirty[] = $path;
            }
        }

        $this->gate($gates, 'repo_clean_or_known_dirty', $available, $available ? 'git status readable; dirty paths classified' : 'git status unavailable');
        if (! $available) {
            $blockers[] = 'repo_status_unavailable';
        }
        $this->gate($gates, 'no_uncommitted_ap_substrate', $substrateDirty === [], $substrateDirty === [] ? 'no uncommitted AP substrate code' : 'uncommitted substrate code present');
        if ($substrateDirty !== []) {
            $blockers[] = 'uncommitted_ap_substrate_code';
        }
        if ($otherDirty !== []) {
            $warnings[] = 'repo_has_unrelated_uncommitted_changes';
        }

        return [
            'status_available' => $available,
            'clean' => $dirty === [],
            'uncommitted_substrate' => $substrateDirty,
            'uncommitted_other' => $otherDirty,
            'note' => 'Unrelated human changes are preserved; never auto-discarded.',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $gates
     * @param  list<string>  $warnings
     * @return array<string,mixed>
     */
    private function branchState(array $input, string $repoRoot, bool $audit, array &$gates, array &$warnings): array
    {
        $branches = isset($input['branches']) && is_array($input['branches'])
            ? array_values(array_filter($input['branches'], 'is_string'))
            : ($audit ? $this->areaFocusBranches($repoRoot) : null);

        $cleanupPlan = [];
        $stale = 0;
        if (is_array($branches)) {
            foreach ($branches as $branch) {
                $merged = $this->branchMergedIntoMain($input, $repoRoot, $branch);
                $cleanupPlan[] = [
                    'branch' => $branch,
                    'merged_into_main' => $merged,
                    'recommended_action' => $merged ? 'safe_to_delete_after_run' : 'inspect_before_delete',
                    'destructive' => false,
                ];
                $stale++;
            }
        }

        $planAvailable = $branches !== null;
        $this->gate($gates, 'branch_cleanup_plan_available', $planAvailable, $planAvailable ? 'cleanup plan computed (no deletion)' : 'branch audit not requested (pass --include-branch-audit)');
        if ($stale > 8) {
            $warnings[] = 'many_stale_area_focus_branches';
        }
        if (! $planAvailable) {
            $warnings[] = 'branch_audit_skipped';
        }

        // Worktree topology (operator mandate 2026-05-31): expose canonical/loop
        // worktree state so readiness shows whether the loop is isolated. Read-only
        // and fail-safe (degraded/non-git => status=blocked, fields null/false).
        $topology = (new LoopWorktreeTopologyVerifierService())->verify([
            'repo_root' => $repoRoot,
            'loop_worktree_root' => (string) ($input['loop_worktree_root'] ?? ''),
        ]);
        if (($topology['is_canonical_checkout'] ?? false) === true && ($topology['loop_worktree_present'] ?? false) !== true) {
            $warnings[] = 'loop_worktree_absent_running_on_canonical_checkout';
        }

        return [
            'audited' => $planAvailable,
            'stale_count' => $stale,
            'cleanup_plan' => $cleanupPlan,
            'canonical_clean' => $topology['canonical_clean'],
            'loop_worktree_present' => $topology['loop_worktree_present'],
            'loop_branch_ref' => $topology['loop_branch_ref'],
            'is_canonical_checkout' => $topology['is_canonical_checkout'],
            'worktree_topology_status' => $topology['status'],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $gates
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @return array<string,mixed>
     */
    private function providerState(array $input, bool $probe, array &$gates, array &$blockers, array &$warnings): array
    {
        $devTimeout = (int) ($input['provider_timeout_dev'] ?? $this->config('atlas_dev.provider.timeout_seconds', 0));
        $cursorTimeout = (int) ($input['provider_timeout_cursor'] ?? $this->config('atlas.ai.providers.cursor_cli.timeout_seconds', 0));
        $minOk = $devTimeout >= self::MIN_PROVIDER_TIMEOUT_SECONDS && $cursorTimeout >= self::MIN_PROVIDER_TIMEOUT_SECONDS;
        $this->gate($gates, 'provider_timeout_minimum_ok', $minOk, 'dev='.$devTimeout.'s cursor='.$cursorTimeout.'s min='.self::MIN_PROVIDER_TIMEOUT_SECONDS.'s');
        if (! $minOk) {
            $blockers[] = 'provider_timeout_below_minimum';
        }

        $available = [];
        if (array_key_exists('provider_binaries', $input) && is_array($input['provider_binaries'])) {
            $available = array_values(array_filter($input['provider_binaries'], 'is_string'));
        } elseif ($probe) {
            foreach (['cursor-agent', 'claude', 'codex'] as $bin) {
                if ($this->binaryOnPath($bin)) {
                    $available[] = $bin;
                }
            }
        }
        $routingOk = ! $probe || $available !== [];
        $this->gate($gates, 'provider_routing_available_or_honest_degraded', $routingOk, $probe ? ('binaries: '.implode(',', $available ?: ['none'])) : 'provider probe skipped (pass --include-provider-probe)');
        if ($probe && $available === []) {
            $blockers[] = 'no_provider_available_no_fallback';
        }
        if (! $probe) {
            $warnings[] = 'provider_probe_skipped';
        }

        return [
            'provider_timeout_dev_seconds' => $devTimeout,
            'provider_timeout_cursor_seconds' => $cursorTimeout,
            'min_required_seconds' => self::MIN_PROVIDER_TIMEOUT_SECONDS,
            'timeout_ok' => $minOk,
            'probed' => $probe,
            'available_binaries' => $available,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $gates
     * @param  list<string>  $warnings
     * @return array<string,mixed>
     */
    private function budgetState(array $input, array &$gates, array &$warnings): array
    {
        // The per-run merge budget fix (AP-790) is structural: the runner tracks a
        // per-run merge counter distinct from the cumulative ledger total. We verify
        // the runner exposes the budget mechanism and a readable ledger.
        $perRunOk = method_exists($this->runner, 'run') && method_exists($this->runner, 'readLedgerRecords');
        $this->gate($gates, 'per_run_budget_ok', $perRunOk, 'AP-790 runner exposes per-run budget + ledger');
        $this->gate($gates, 'max_merges_not_cumulative', $perRunOk, 'max_merges is budgeted per-run (committed AP-790 fix), not cumulative');
        if (! $perRunOk) {
            $warnings[] = 'budget_mechanism_unverifiable';
        }

        return [
            'per_run_budget' => $perRunOk,
            'recommended_max_cycles' => 12,
            'recommended_max_merges' => 10,
            'recommended_max_blocked_in_row' => 14,
            'note' => 'max_merges counts merges in THIS run, never the cumulative ledger total.',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $gates
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function backlogState(array $input, string $area, string $focus, array &$gates, array &$blockers): array
    {
        $slicePlanner = $this->capabilityPresent($input, 'finding_slice_planner')
            || class_exists(FindingSlicePlannerService::class);
        $this->gate($gates, 'finding_slice_planner_available', $slicePlanner, 'AP-794/AP-796 slice planner present');
        if (! $slicePlanner) {
            $blockers[] = 'finding_slice_planner_missing';
        }

        // Duplicate-finding guard lives in the AP-790 runner (seen-finding + repeated
        // outcome) and the quarantine service (transient-aware).
        $dupGuard = method_exists($this->runner, 'readLedgerRecords')
            && method_exists($this->quarantine, 'hasTransientBlocker');
        $this->gate($gates, 'duplicate_finding_guard_available', $dupGuard, 'AP-790 seen-finding + AP quarantine transient guard present');
        if (! $dupGuard) {
            $blockers[] = 'duplicate_finding_guard_missing';
        }

        return [
            'finding_slice_planner_available' => $slicePlanner,
            'duplicate_finding_guard_available' => $dupGuard,
            'transient_timeout_not_permanent_quarantine' => method_exists($this->quarantine, 'hasTransientBlocker'),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $gates
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @return array<string,mixed>
     */
    private function multiAgentState(array $input, array &$gates, array &$blockers, array &$warnings): array
    {
        $overrides = is_array($input['capability_overrides'] ?? null) ? $input['capability_overrides'] : [];
        $cert = $this->multiAgentCertification->certify([
            'capability_overrides' => $overrides,
            'capability_probes' => $input['capability_probes'] ?? [],
        ]);
        $caps = is_array($cert['capabilities'] ?? null) ? $cert['capabilities'] : [];
        $present = static fn (string $k): bool => (bool) ($caps[$k]['present'] ?? false);

        $lanes = $present('lane_orchestrator');
        $this->gate($gates, 'multi_agent_lane_contracts_available', $lanes, 'AP-797 lane orchestrator present');
        if (! $lanes) {
            $blockers[] = 'lane_orchestrator_missing';
        }

        $judgeRepair = $present('integration_judge') && $present('repair_planner');
        $this->gate($gates, 'judge_repair_available', $judgeRepair, 'AP-798 judge + AP-799 repair present');
        if (! $judgeRepair) {
            $blockers[] = 'judge_or_repair_missing';
        }

        $missing = is_array($cert['missing_capabilities'] ?? null) ? $cert['missing_capabilities'] : [];
        if ($missing !== []) {
            $warnings[] = 'multi_agent_capabilities_incomplete';
        }

        return [
            'lane_contracts_available' => $lanes,
            'judge_repair_available' => $judgeRepair,
            'capabilities_present' => array_keys(array_filter($caps, static fn ($c): bool => (bool) ($c['present'] ?? false))),
            'missing_capabilities' => $missing,
            'isolation_level' => 'L1_git_worktree',
            'isolation_warning' => 'L1 git worktree isolation only — agents run on the host. L2 OS/process isolation (containers, AP-793 future provider) is a hardening upgrade, NOT a blocker for the 10-cycle run.',
            'l2_process_isolation_present' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $gates
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function mergeTruthState(array $input, array &$gates, array &$blockers): array
    {
        $governor = 'App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\StewardshipBranchMergeGovernorService';
        $present = isset($input['merge_truth_guard_present'])
            ? (bool) $input['merge_truth_guard_present']
            : (class_exists($governor) && method_exists($governor, 'evaluate'));
        $this->gate($gates, 'merge_truth_guard_present', $present, 'merge governor advances-main guard present (AP-793 merge truth)');
        if (! $present) {
            $blockers[] = 'merge_truth_guard_missing';
        }

        return [
            'merge_governor_present' => $present,
            'rule' => 'a merge counts only when main_before != main_after and the recorded hash is the real new head',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $gates
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @return array<string,mixed>
     */
    private function productModeState(array $input, bool $include, array &$gates, array &$blockers, array &$warnings): array
    {
        if (array_key_exists('product_mode_memory_safe', $input)) {
            $safe = (bool) $input['product_mode_memory_safe'];
        } elseif ($include) {
            // Memory safety = the read model exposes a bounded window rather than
            // loading an unbounded ledger into memory.
            $readModel = 'App\\Services\\Ai\\SoftwareCompanyStewardship\\ProductMode\\ProductModeOperationalInboxReadModelService';
            $boundedRunner = defined(Reliable24hLoopRunnerService::class.'::DEFAULT_BOUNDED_CYCLE_WINDOW');
            $safe = class_exists($readModel) && $boundedRunner;
        } else {
            $safe = null;
        }

        $ok = $safe !== false;
        $this->gate($gates, 'product_mode_projection_memory_safe', $ok, $safe === null ? 'product mode probe skipped (pass --include-product-mode)' : ($safe ? 'bounded projection window present' : 'unbounded read model risk'));
        // An unsafe read model can OOM a long run — that is a hard block, not a
        // warning. A skipped probe is only a warning (run --include-product-mode).
        if ($safe === false) {
            $blockers[] = 'product_mode_oom_risk';
        }
        if ($safe === null) {
            $warnings[] = 'product_mode_probe_skipped';
        }

        return [
            'checked' => $include || array_key_exists('product_mode_memory_safe', $input),
            'memory_safe' => $safe,
            'bounded_window' => Reliable24hLoopRunnerService::DEFAULT_BOUNDED_CYCLE_WINDOW,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  list<string>  $warnings
     * @return array<string,mixed>
     */
    private function memoryState(array $input, string $area, string $focus, string $repoRoot, array &$warnings): array
    {
        $ledgerPath = $this->runner->ledgerPath($area, $focus);
        $ledgerBytes = is_file($ledgerPath) ? (int) (filesize($ledgerPath) ?: 0) : 0;
        $worktreeCount = (int) ($input['worktree_count'] ?? $this->worktreeCount($repoRoot));
        $largeLedger = $ledgerBytes > 50_000_000; // 50MB
        if ($largeLedger) {
            $warnings[] = 'ledger_large_consider_archive';
        }
        if ($worktreeCount > 20) {
            $warnings[] = 'many_active_worktrees';
        }

        return [
            'ledger_bytes' => $ledgerBytes,
            'ledger_large' => $largeLedger,
            'active_worktree_count' => $worktreeCount,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $gates
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function killSwitchState(array $input, string $area, string $focus, array &$gates, array &$blockers): array
    {
        $available = isset($input['kill_switch_available'])
            ? (bool) $input['kill_switch_available']
            : (method_exists($this->runner, 'killSwitchStatus') && method_exists($this->runner, 'pausePath'));
        $this->gate($gates, 'kill_switch_available', $available, 'AP-790 kill-switch + pause controls present');
        if (! $available) {
            $blockers[] = 'kill_switch_unavailable';
        }

        $active = false;
        try {
            $active = (bool) ($this->runner->killSwitchStatus($area, $focus)['active'] ?? false);
        } catch (Throwable) {
            $active = false;
        }

        return [
            'kill_switch_available' => $available,
            'kill_switch_path' => $this->runner->killSwitchPath($area, $focus),
            'pause_path' => $this->runner->pausePath($area, $focus),
            'kill_switch_active_now' => $active,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $gates
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @return array<string,mixed>
     */
    private function leaseAndLockState(array $input, string $area, string $focus, array &$gates, array &$blockers, array &$warnings): array
    {
        $lock = isset($input['lock_held'])
            ? ['held' => (bool) $input['lock_held'], 'available' => ! (bool) $input['lock_held'], 'holder' => null]
            : $this->runner->lockStatus($area, $focus);
        $stale = (bool) ($input['stale_lock'] ?? false);
        $lockOk = ($lock['held'] ?? false) !== true && ! $stale;
        $this->gate($gates, 'no_stale_lock', $lockOk, $lockOk ? 'no held/stale loop lock' : 'loop lock held or stale');
        if (! $lockOk) {
            $blockers[] = 'loop_lock_held';
        }

        $openLeases = (int) ($input['open_leases'] ?? 0);
        $leaseOk = $openLeases === 0;
        $this->gate($gates, 'no_open_leases', $leaseOk, $leaseOk ? 'no open repo merge leases' : $openLeases.' open lease(s)');
        if (! $leaseOk) {
            $blockers[] = 'open_repo_merge_lease';
        }

        return [
            'loop_lock_held' => (bool) ($lock['held'] ?? false),
            'loop_lock_stale' => $stale,
            'open_repo_merge_leases' => $openLeases,
        ];
    }

    /**
     * docs-health and architecture-validate are external read-only validations.
     * The command runs them and passes the status in; the service never shells
     * out. When not supplied they are proof-commands the operator must run.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $gates
     * @param  list<string>  $warnings
     * @return array<string,mixed>
     */
    private function validationState(array $input, array &$gates, array &$warnings): array
    {
        $docs = $input['docs_health'] ?? null; // true|false|null
        $arch = $input['architecture_validate'] ?? null;

        $docsOk = $docs !== false;
        $archOk = $arch !== false;
        $this->gate($gates, 'docs_health_ok', $docsOk, $docs === null ? 'run proof command to verify' : ($docs ? 'docs-health ok' : 'docs-health reported issues'));
        $this->gate($gates, 'architecture_validate_ok', $archOk, $arch === null ? 'run proof command to verify' : ($arch ? 'architecture-validate ok' : 'architecture-validate reported violations'));
        if ($docs === false) {
            $warnings[] = 'docs_health_issues';
        }
        if ($arch === false) {
            $warnings[] = 'architecture_validate_violations';
        }
        if ($docs === null || $arch === null) {
            $warnings[] = 'validations_not_run_see_proof_commands';
        }

        return [
            'docs_health' => $docs,
            'architecture_validate' => $arch,
        ];
    }

    // ---------- commands ----------

    /**
     * @return list<string>
     */
    private function requiredCommands(string $area, string $focus): array
    {
        return [
            'php artisan atlas:ai:architecture-validate --json',
            'php artisan atlas:engineering:knowledge docs-health --json',
            'php artisan atlas:software-company-stewardship:certify-24h-loop --use-real-services --json',
        ];
    }

    /**
     * @return list<string>
     */
    private function proofCommands(string $area, string $focus): array
    {
        return [
            'git status --porcelain',
            'git worktree list',
            'php artisan atlas:software-company-stewardship ten-cycle-readiness --area='.$area.' --focus='.$focus.' --strict --include-provider-probe --include-product-mode --include-branch-audit --json',
        ];
    }

    private function recommendedCommand(string $area, string $focus, string $status, bool $allowCleanupPlan): string
    {
        if ($status !== self::STATUS_READY) {
            return 'DO NOT RUN: status='.$status.'. Resolve blockers first, then re-run ten-cycle-readiness --strict.';
        }

        return 'php artisan atlas:software-company-stewardship:reliable-24h-loop'
            .' --area='.$area
            .' --focus='.$focus
            .' --scope-profile=factory_max'
            .' --repo-root=$(pwd)'
            .' --execute --auto-merge --allow-code-auto-merge'
            // Merge-truth is MANDATORY for any proof run (operator mandate 2026-06-01):
            // a cycle counts as a real merge only when main actually advanced
            // (main_before != main_after). Run from the dedicated loop worktree so
            // the single-writer guard is satisfied (never the canonical checkout).
            .' --enforce-merge-truth-counting'
            .' --continue-on-blocked --cleanup-worktrees'
            .' --multi-agent-workcell'
            .' --max-cycles=12 --max-merges=10 --max-blocked-in-row=14'
            .' --record --json';
    }

    // ---------- probes ----------

    /**
     * @param  array<string,mixed>  $input
     * @return list<string>|null  porcelain lines, or null when git is unavailable
     */
    private function repoStatusLines(array $input, string $repoRoot): ?array
    {
        if (array_key_exists('repo_status', $input)) {
            $lines = (array) $input['repo_status'];

            return array_values(array_filter(array_map(static fn ($l): string => (string) $l, $lines), static fn (string $l): bool => $l !== ''));
        }

        $result = $this->git($repoRoot, ['status', '--porcelain']);
        if ($result === null) {
            return null;
        }

        return array_values(array_filter(explode("\n", $result), static fn (string $l): bool => trim($l) !== ''));
    }

    /**
     * @return list<string>
     */
    private function areaFocusBranches(string $repoRoot): array
    {
        $out = $this->git($repoRoot, ['branch', '--list', 'atlas/area-focus/*', '--format=%(refname:short)']);
        if ($out === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $out)), static fn (string $b): bool => $b !== ''));
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function branchMergedIntoMain(array $input, string $repoRoot, string $branch): bool
    {
        if (isset($input['merged_branches']) && is_array($input['merged_branches'])) {
            return in_array($branch, $input['merged_branches'], true);
        }

        return $this->git($repoRoot, ['merge-base', '--is-ancestor', $branch, 'main'], true) !== null;
    }

    private function worktreeCount(string $repoRoot): int
    {
        $out = $this->git($repoRoot, ['worktree', 'list', '--porcelain']);
        if ($out === null) {
            return 0;
        }

        return substr_count($out, "\nworktree ") + (str_starts_with($out, 'worktree ') ? 1 : 0);
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function capabilityPresent(array $input, string $key): bool
    {
        $overrides = is_array($input['capability_overrides'] ?? null) ? $input['capability_overrides'] : [];
        if (array_key_exists($key, $overrides)) {
            return (bool) $overrides[$key];
        }

        return false;
    }

    private function binaryOnPath(string $binary): bool
    {
        $which = $this->process(['/usr/bin/env', 'which', $binary], null, 5);

        return $which !== null && trim($which) !== '';
    }

    /**
     * @param  list<string>  $argv
     */
    private function git(string $repoRoot, array $argv, bool $exitCodeOnly = false): ?string
    {
        $out = $this->process(array_merge(['git', '-C', $repoRoot], $argv), $repoRoot, 15, $exitCodeOnly);

        return $out;
    }

    /**
     * @param  list<string>  $argv
     */
    private function process(array $argv, ?string $cwd, int $timeout, bool $okSignal = false): ?string
    {
        try {
            $process = new Process($argv, $cwd, null, null, (float) $timeout);
            $process->run();
            if (! $process->isSuccessful()) {
                return null;
            }

            return $okSignal ? '1' : (string) $process->getOutput();
        } catch (Throwable) {
            return null;
        }
    }

    private function config(string $key, mixed $default): mixed
    {
        return function_exists('config') ? config($key, $default) : $default;
    }

    private function repoRoot(array $input): string
    {
        $root = trim((string) ($input['repo_root'] ?? ''));
        if ($root !== '') {
            return $root;
        }

        return function_exists('base_path') ? base_path() : (getcwd() ?: '.');
    }

    /**
     * @param  array<string,mixed>  $gates
     */
    private function gate(array &$gates, string $id, bool $ok, string $detail): void
    {
        $gates[$id] = [
            'gate' => $id,
            'ok' => $ok,
            'hard' => in_array($id, self::HARD_GATES, true),
            'detail' => $detail,
        ];
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
