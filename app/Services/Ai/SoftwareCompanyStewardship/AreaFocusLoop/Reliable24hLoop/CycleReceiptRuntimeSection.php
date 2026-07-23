<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusAppendOnlyJsonlRecorder;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusJsonlReader;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusPathNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusSlugNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusUtcClock;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FinalDeliveryQualityGateService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopCycleFailureTaxonomyService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopPostCycleAuditorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopPreflightCycleFirewallService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\BuildPlanDecomposerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\OwnerFlowPlanSliceCycleExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanCompletionTrackerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanSliceDecompositionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanSliceSelectionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\ZeroProviderPreflightGate;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hStewardshipRecoveryContract;
use App\Services\Ai\Support\JsonFileStore;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Cycle receipt/diagnostics/reporting/cleanup + sandbox-process-reap + low-level utility family. Extracted VERBATIM from Reliable24hLoopRunnerService by the GOD-DEBULK split; composed back into the facade as a trait. Constants and properties stay on the facade.
 */
trait CycleReceiptRuntimeSection
{
//__GODDEBULK_SPAN_START__
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
            'blockers' => AreaFocusStringListNormalizer::coercedStringValues($cycle['blockers'] ?? []),
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
            'recorded_at' => AreaFocusUtcClock::atomNow(),
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
            'lanes' => AreaFocusStringListNormalizer::preserveStrings(array_map($role, $lanes)),
            'lane_session_ids' => AreaFocusStringListNormalizer::preserveStrings(array_map($sid, $lanes)),
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
            'blockers' => AreaFocusStringListNormalizer::coercedStringValues($receipt['blockers'] ?? []),
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
                $blockers = AreaFocusStringListNormalizer::truthyValues($plan['blockers'] ?? []);
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

        return rtrim(AreaFocusPathNormalizer::existingOrRawPathWithoutDeletedSuffix($storageDir.DIRECTORY_SEPARATOR.'worktrees'), DIRECTORY_SEPARATOR);
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
        $path = rtrim(AreaFocusPathNormalizer::existingOrRawPathWithoutDeletedSuffix($path), DIRECTORY_SEPARATOR);

        return $path !== '' && str_contains($haystack, $path);
    }

    private function pathWithin(string $path, string $root): bool
    {
        $path = rtrim(AreaFocusPathNormalizer::existingOrRawPathWithoutDeletedSuffix($path), DIRECTORY_SEPARATOR);
        $root = rtrim(AreaFocusPathNormalizer::existingOrRawPathWithoutDeletedSuffix($root), DIRECTORY_SEPARATOR);

        return $path !== '' && $root !== ''
            && ($path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR));
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
        $payload['generated_at'] = AreaFocusUtcClock::atomNow();

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

        return AreaFocusStringListNormalizer::uniqueStringValues(array_filter(array_merge(
            [$fallback],
            AreaFocusStringListNormalizer::coercedTrimmedUniqueStringOrNumberValues([
                $finding['finding_id'] ?? '',
                $finding['finding_hash'] ?? '',
                $finding['title'] ?? '',
            ]),
        ), static fn (string $value): bool => $value !== ''));
    }

    private function key(string $areaId, string $focus): string
    {
        return AreaFocusSlugNormalizer::lowerFileToken($areaId, '').'__'.AreaFocusSlugNormalizer::lowerFileToken($focus, '');
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
//__GODDEBULK_SPAN_END__
}
