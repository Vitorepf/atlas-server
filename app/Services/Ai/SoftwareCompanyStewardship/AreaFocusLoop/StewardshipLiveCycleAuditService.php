<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipDayStartService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipFirstLiveBranchProofService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultBridgeService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Symfony\Component\Process\Process;

/**
 * AP-784 · Stewardship Live Cycle Auditor.
 *
 * Read-only operational proof inspector for the real git + receipt surface of the
 * stewardship loop (AP-781 proof branches, AP-782 integration lanes, AP-769/AP-780
 * artifacts). It never mutates refs, worktrees, schedulers or provider runtimes.
 * Deferred stages stay explicitly not-real; nothing is upgraded to "done".
 */
final class StewardshipLiveCycleAuditService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.live_cycle_audit.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly StewardshipBranchMergeGovernorService $mergeGovernor,
        private readonly StewardshipFirstLiveBranchProofService $firstLiveProof,
        private readonly StewardshipIntegrationLaneService $integrationLane,
        private readonly StewardshipRuntimeResultBridgeService $runtimeBridge,
        private readonly FirstFullCycleOrchestratorService $firstFullCycle,
        private readonly ContinuousStewardshipRunnerService $continuousRunner,
        private readonly ContinuousStewardshipDayStartService $dayStart,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $root = $dir !== null ? rtrim($dir, DIRECTORY_SEPARATOR) : null;
        $this->storageRootOverride = $root;
        $this->mergeGovernor->setStorageRootForTesting($root !== null ? $root.'/merge_governor' : null);
        $this->firstLiveProof->setStorageRootForTesting($root !== null ? $root.'/live_proofs' : null);
        $this->integrationLane->setStorageRootForTesting($root !== null ? $root.'/integration_lanes' : null);
        $this->runtimeBridge->setStorageRootForTesting($root);
        $this->firstFullCycle->setStorageRootForTesting($root !== null ? $root.'/first_cycle' : null);
        $this->continuousRunner->setStorageRootForTesting($root !== null ? $root.'/runner' : null);
        $this->dayStart->setStorageRootForTesting($root);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function audit(array $input = []): array
    {
        $areaId = $this->slug((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID));
        $focus = $this->slug((string) ($input['focus'] ?? 'dev_forge'));
        $baseRef = trim((string) ($input['base_ref'] ?? 'main')) ?: 'main';
        $repoRoot = $this->repoRoot($input);

        if ($repoRoot === '' || ! $this->isGitRepo($repoRoot)) {
            return $this->finalize($this->failedPayload($areaId, $baseRef, $repoRoot, 'repo_root_not_git_repository', 'Live cycle audit requires a git repository root.'));
        }
        if ($this->revParse($repoRoot, $baseRef) === '') {
            return $this->finalize($this->failedPayload($areaId, $baseRef, $repoRoot, 'base_ref_not_found', 'Base ref was not found in the repository.'));
        }

        $baseCommit = $this->revParse($repoRoot, $baseRef);
        $baseClean = $this->worktreeClean($repoRoot);
        $proofBranches = $this->discoverProofBranches($repoRoot, $areaId, $baseRef, $baseCommit);
        $integrationLanes = $this->discoverIntegrationLanes($repoRoot, $areaId, $baseRef, $baseCommit);
        $latestLane = $integrationLanes[0] ?? null;
        $latestLaneCommit = is_array($latestLane) ? (string) ($latestLane['lane_commit'] ?? '') : '';

        $receipts = $this->receiptSignals($areaId, $repoRoot, $focus);
        $realSteps = $this->realSteps($repoRoot, $proofBranches, $integrationLanes, $baseCommit, $latestLaneCommit, $receipts);
        $notYetReal = $this->notYetReal($realSteps, $receipts);

        $blockers = $this->blockers($baseClean, $integrationLanes, $proofBranches, $realSteps);
        $promotionReady = $baseClean
            && $latestLaneCommit !== ''
            && $latestLaneCommit !== $baseCommit
            && ! $realSteps['main_promoted']
            && ($latestLane['ahead_of_base'] ?? 0) > 0;

        $status = $this->status($promotionReady, $blockers, $proofBranches, $integrationLanes, $realSteps);

        return $this->finalize([
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-784',
            'status' => $status,
            'area_id' => $areaId,
            'base_ref' => $baseRef,
            'base_clean' => $baseClean,
            'repo_root' => $repoRoot,
            'repo_root_hash' => hash('sha256', $repoRoot),
            'proof_branches' => $proofBranches,
            'integration_lanes' => $integrationLanes,
            'latest_lane_commit' => $latestLaneCommit,
            'main_commit' => $baseCommit,
            'real_steps' => $realSteps,
            'not_yet_real' => $notYetReal,
            'receipt_signals' => $receipts,
            'promotion_ready' => $promotionReady,
            'blockers' => $blockers,
            'next_real_action' => $this->nextRealAction($status, $blockers, $proofBranches, $integrationLanes, $promotionReady, $areaId, $baseRef),
            'operator_truth' => $this->operatorTruth($status, $realSteps, $notYetReal, $baseClean, $promotionReady, $proofBranches, $integrationLanes),
            'source_ap_contracts' => ['AP-768', 'AP-769', 'AP-780', 'AP-781', 'AP-782', 'AP-784'],
            'claim_policy' => [
                'read_only' => true,
                'mutates_refs' => false,
                'mutates_worktrees' => false,
                'invokes_provider' => false,
                'starts_scheduler' => false,
                'fabricates_receipts' => false,
                'deferred_counted_as_done' => false,
            ],
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function discoverProofBranches(string $repoRoot, string $areaId, string $baseRef, string $baseCommit): array
    {
        $prefix = 'atlas/area-focus/'.$areaId.'/';
        $branches = [];
        foreach ($this->localBranches($repoRoot, $prefix) as $branchRef) {
            $commit = $this->revParse($repoRoot, $branchRef);
            if ($commit === '') {
                continue;
            }
            $branches[] = [
                'branch_ref' => $branchRef,
                'branch_commit' => $commit,
                'ahead_of_base' => $this->aheadCount($repoRoot, $baseCommit, $commit),
                'is_live_proof' => str_contains($branchRef, '/live-proof-'),
                'has_worktree' => $this->branchHasWorktree($repoRoot, $branchRef),
                'ap781_recorded' => $this->proofRecordedForBranch($repoRoot, $areaId, $branchRef),
                'base_ref' => $baseRef,
            ];
        }

        usort($branches, static fn (array $a, array $b): int => strcmp((string) $b['branch_commit'], (string) $a['branch_commit']));

        return $branches;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function discoverIntegrationLanes(string $repoRoot, string $areaId, string $baseRef, string $baseCommit): array
    {
        $prefix = 'atlas/integration/'.$areaId.'/';
        $lanes = [];
        foreach ($this->localBranches($repoRoot, $prefix) as $laneRef) {
            $laneCommit = $this->revParse($repoRoot, $laneRef);
            if ($laneCommit === '') {
                continue;
            }
            $lanes[] = [
                'lane_ref' => $laneRef,
                'lane_commit' => $laneCommit,
                'ahead_of_base' => $this->aheadCount($repoRoot, $baseCommit, $laneCommit),
                'ap782_recorded' => $this->integrationRecordedForLane($areaId, $laneRef, $laneCommit),
                'candidate_branch_refs' => $this->candidateBranchesForLane($repoRoot, $laneCommit, $areaId),
                'base_ref' => $baseRef,
            ];
        }

        usort($lanes, static fn (array $a, array $b): int => strcmp((string) $b['lane_commit'], (string) $a['lane_commit']));

        return $lanes;
    }

    /**
     * @param  list<array<string,mixed>>  $proofBranches
     * @param  list<array<string,mixed>>  $integrationLanes
     * @param  array<string,mixed>  $receipts
     * @return array<string,bool>
     */
    private function realSteps(
        string $repoRoot,
        array $proofBranches,
        array $integrationLanes,
        string $baseCommit,
        string $latestLaneCommit,
        array $receipts,
    ): array {
        $lane = $integrationLanes[0] ?? null;

        $branchCreated = $this->anyAreaFocusBranch($proofBranches);
        $worktreeCreated = $this->anyWorktree($proofBranches) || (bool) ($receipts['ap781_worktree_paths'] ?? false);
        $reviewPacketCreated = (bool) ($receipts['ap780_packet_present'] ?? false)
            || (bool) ($receipts['ap781_review_packet_present'] ?? false);
        $integrationLaneAdvanced = $lane !== null
            && (string) ($lane['lane_commit'] ?? '') !== ''
            && (((int) ($lane['ahead_of_base'] ?? 0) > 0) || (bool) ($lane['ap782_recorded'] ?? false));
        $mainPromoted = $lane !== null
            && $latestLaneCommit !== ''
            && $this->isAncestor($repoRoot, $latestLaneCommit, $baseCommit)
            && ((bool) ($lane['ap782_recorded'] ?? false) || (int) ($lane['ahead_of_base'] ?? 0) === 0);
        $commitCreated = $this->anyProofCommit($proofBranches) || $mainPromoted;

        return [
            'branch_created' => $branchCreated,
            'worktree_created' => $worktreeCreated,
            'commit_created' => $commitCreated,
            'review_packet_created' => $reviewPacketCreated,
            'integration_lane_advanced' => $integrationLaneAdvanced,
            'main_promoted' => $mainPromoted,
            'owner_runtime_executed' => (bool) ($receipts['owner_runtime_executed'] ?? false),
            'provider_invoked' => (bool) ($receipts['provider_invoked'] ?? false),
        ];
    }

    /**
     * @param  array<string,bool>  $realSteps
     * @param  array<string,mixed>  $receipts
     * @return list<string>
     */
    private function notYetReal(array $realSteps, array $receipts): array
    {
        $items = [];
        if (! $realSteps['owner_runtime_executed']) {
            $items[] = 'owner_runtime_execution (AP-758/AP-759/AP-767) — not proven in git or runtime receipts';
        }
        if (! $realSteps['provider_invoked']) {
            $items[] = 'provider_execution — not proven; deferred does not count as real';
        } elseif ($realSteps['provider_invoked']) {
            $items[] = 'provider_execution — receipt claims invocation; verify operator intent';
        }
        if (! $realSteps['main_promoted']) {
            $items[] = 'main_promotion (AP-769/AP-772 ff-only) — not performed on '.$receipts['base_ref_hint'];
        }
        if (! ($receipts['continuous_runner_tick'] ?? false)) {
            $items[] = 'continuous_runner_tick (AP-766) — no recorded execute tick in runner JSONL';
        }
        if (! ($receipts['day_start_first_tick'] ?? false)) {
            $items[] = '24h_day_start_first_tick (AP-778) — no recorded first-tick receipt';
        }
        if (! ($receipts['scheduler_installed'] ?? false)) {
            $items[] = 'external_24h_scheduler — not installed by Atlas; operator must schedule externally';
        }
        if (($receipts['first_full_cycle_deferred_stages'] ?? []) !== []) {
            $items[] = 'first_full_cycle_deferred: '.implode(', ', (array) $receipts['first_full_cycle_deferred_stages']);
        }

        return $items;
    }

    /**
     * @return array<string,mixed>
     */
    private function receiptSignals(string $areaId, string $repoRoot, string $focus): array
    {
        $ap781 = $this->readJsonl($this->firstLiveProof->recordPath($areaId, $repoRoot));
        $ap782 = $this->readJsonl($this->integrationLane->recordPath($areaId));
        $ap769 = (array) ($this->mergeGovernor->listRecords($areaId)['records'] ?? []);
        $bridge = $this->readJsonl($this->runtimeBridge->bridgeFilePath($areaId));
        $runner = $this->readJsonl($this->continuousRunner->runFilePath($areaId));
        $starts = $this->readJsonl($this->dayStart->receiptFilePath($areaId));
        $reliableLoop = $this->readJsonl($this->reliableLoopLedgerPath($areaId, $focus));

        $ap781Review = false;
        $ap781Worktree = false;
        foreach ($ap781 as $row) {
            if (is_array($row['branch_review_packet'] ?? null)) {
                $ap781Review = true;
            }
            if (trim((string) data_get($row, 'branch.worktree_path', '')) !== '') {
                $ap781Worktree = true;
            }
        }

        $providerInvoked = false;
        foreach ([...$bridge, ...$runner, ...$starts, ...$reliableLoop] as $row) {
            if ((bool) data_get($row, 'claim_policy.provider_invoked', false)
                || (bool) data_get($row, 'multi_agent_workcell.provider_invoked', false)
                || (bool) data_get($row, 'provider_invoked', false)) {
                $providerInvoked = true;
            }
        }

        $deferredStages = [];
        $cycles = $this->firstFullCycle->listCycles($areaId);
        $latestCycleId = (string) data_get($cycles, 'cycles.'.(count((array) ($cycles['cycles'] ?? [])) - 1).'.cycle_id', '');
        if ($latestCycleId !== '') {
            $replay = $this->firstFullCycle->replay($latestCycleId, $areaId);
            foreach ((array) ($replay['stages'] ?? []) as $name => $stage) {
                if (is_array($stage) && ($stage['status'] ?? '') === FirstFullCycleOrchestratorService::STAGE_DEFERRED) {
                    $deferredStages[] = (string) $name;
                }
            }
        }

        $ownerRuntime = false;
        foreach ($bridge as $row) {
            if (in_array((string) ($row['status'] ?? ''), ['ready_for_operator_review', 'runtime_result_bridge_recorded'], true)) {
                $ownerRuntime = true;
            }
        }
        foreach ($reliableLoop as $row) {
            if ((string) ($row['cycle_final_status'] ?? '') !== '' || (string) ($row['session_status'] ?? '') !== '') {
                $ownerRuntime = true;
            }
        }

        $runnerTick = false;
        foreach ($runner as $row) {
            if ((bool) ($row['tick_admitted'] ?? false) && ($row['mode'] ?? '') === ContinuousStewardshipRunnerService::MODE_EXECUTE) {
                $runnerTick = true;
            }
        }

        $firstTick = false;
        foreach ($starts as $row) {
            if (($row['final_status'] ?? '') === ContinuousStewardshipDayStartService::STATUS_FIRST_TICK_EXECUTED) {
                $firstTick = true;
            }
        }

        return [
            'ap781_proof_records' => count($ap781),
            'ap782_integration_records' => count($ap782),
            'ap769_governance_records' => count($ap769),
            'ap765_runtime_bridge_records' => count($bridge),
            'ap790_reliable_loop_records' => count($reliableLoop),
            'ap781_review_packet_present' => $ap781Review || $this->packetInGovernanceRecords($ap769),
            'ap780_packet_present' => $ap781Review || $this->packetInGovernanceRecords($ap769),
            'ap781_worktree_paths' => $ap781Worktree,
            'owner_runtime_executed' => $ownerRuntime,
            'provider_invoked' => $providerInvoked,
            'continuous_runner_tick' => $runnerTick,
            'day_start_first_tick' => $firstTick,
            'scheduler_installed' => false,
            'first_full_cycle_deferred_stages' => $deferredStages,
            'base_ref_hint' => 'base_ref',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $records
     */
    private function packetInGovernanceRecords(array $records): bool
    {
        foreach ($records as $record) {
            if (is_array($record['branch_review_packet'] ?? null)) {
                return true;
            }
            if (is_array($record['governance_report']['branch_review_packet'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $proofBranches
     * @param  list<array<string,mixed>>  $integrationLanes
     * @param  array<string,bool>  $realSteps
     * @return list<string>
     */
    private function blockers(bool $baseClean, array $integrationLanes, array $proofBranches, array $realSteps): array
    {
        $blockers = [];
        if (! $baseClean && $integrationLanes !== [] && (int) ($integrationLanes[0]['ahead_of_base'] ?? 0) > 0) {
            $blockers[] = 'base_worktree_dirty';
        }
        if ($proofBranches === [] && $integrationLanes === []) {
            $blockers[] = 'no_proof_branch_or_integration_lane_detected';
        }
        if ($realSteps['integration_lane_advanced'] && ! $baseClean && ! $realSteps['main_promoted']) {
            $blockers[] = 'promotion_blocked_until_base_clean';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  list<string>  $blockers
     * @param  list<array<string,mixed>>  $proofBranches
     * @param  list<array<string,mixed>>  $integrationLanes
     * @param  array<string,bool>  $realSteps
     */
    private function status(bool $promotionReady, array $blockers, array $proofBranches, array $integrationLanes, array $realSteps): string
    {
        if ($promotionReady) {
            return self::STATUS_READY;
        }
        if (in_array('base_worktree_dirty', $blockers, true)) {
            return self::STATUS_BLOCKED;
        }
        if ($realSteps['main_promoted']) {
            return self::STATUS_READY;
        }
        if ($proofBranches === [] && $integrationLanes === []) {
            return self::STATUS_PARTIAL;
        }
        if ($integrationLanes === []) {
            return self::STATUS_PARTIAL;
        }
        if ($blockers !== []) {
            return self::STATUS_BLOCKED;
        }

        return self::STATUS_PARTIAL;
    }

    /**
     * @param  list<string>  $blockers
     * @param  list<array<string,mixed>>  $proofBranches
     * @param  list<array<string,mixed>>  $integrationLanes
     */
    private function nextRealAction(
        string $status,
        array $blockers,
        array $proofBranches,
        array $integrationLanes,
        bool $promotionReady,
        string $areaId,
        string $baseRef,
    ): string {
        if ($promotionReady) {
            return 'Acquire AP-775 repo/base lease, then run AP-769/AP-772 fast-forward-only promotion from '
                .(string) ($integrationLanes[0]['lane_ref'] ?? 'integration lane').' to '.$baseRef.'.';
        }
        if (in_array('base_worktree_dirty', $blockers, true)) {
            return 'Clean or stash the primary '.$baseRef.' worktree, then re-run live-cycle-audit before any ff-only promotion.';
        }
        if ($proofBranches !== [] && $integrationLanes === []) {
            $candidate = (string) ($proofBranches[0]['branch_ref'] ?? '');

            return 'Run AP-782 integration lane: php artisan atlas:software-company-stewardship:integration-lane --area='
                .$areaId.' --base-ref='.$baseRef.' --branch-ref='.$candidate.' --record --json';
        }
        if ($proofBranches === [] && $integrationLanes === []) {
            return 'Run AP-781 first live branch proof: php artisan atlas:software-company-stewardship:first-live-branch-proof --area='
                .$areaId.' --base-ref='.$baseRef.' --record --json';
        }
        if ($status === self::STATUS_PARTIAL) {
            return 'Review GitKraken branches and receipts; advance only the next deferred real step — do not treat dry-run/deferred as done.';
        }

        return 'Resolve blockers: '.implode(', ', $blockers);
    }

    /**
     * @param  array<string,bool>  $realSteps
     * @param  list<string>  $notYetReal
     * @param  list<array<string,mixed>>  $proofBranches
     * @param  list<array<string,mixed>>  $integrationLanes
     */
    private function operatorTruth(
        string $status,
        array $realSteps,
        array $notYetReal,
        bool $baseClean,
        bool $promotionReady,
        array $proofBranches,
        array $integrationLanes,
    ): string {
        $real = array_keys(array_filter($realSteps));
        $summary = 'Audit status='.$status.'. Real in git/receipts: '.($real === [] ? 'none yet' : implode(', ', $real)).'.';
        if ($promotionReady) {
            $summary .= ' Promotion to main can proceed now (base clean, integration lane ahead).';
        } elseif (! $baseClean && $integrationLanes !== []) {
            $summary .= ' Base worktree is dirty; integration lane progress is visible but main promotion must wait.';
        } elseif ($proofBranches !== [] && $integrationLanes === []) {
            $summary .= ' Proof branch exists without an integration lane; AP-782 has not advanced a safe lane yet.';
        }
        if ($notYetReal !== []) {
            $summary .= ' Not real yet: '.implode('; ', array_slice($notYetReal, 0, 4));
            if (count($notYetReal) > 4) {
                $summary .= ' (+'.(count($notYetReal) - 4).' more)';
            }
        }

        return $summary;
    }

    /**
     * @param  list<array<string,mixed>>  $proofBranches
     */
    private function anyAreaFocusBranch(array $proofBranches): bool
    {
        return $proofBranches !== [];
    }

    /**
     * @param  list<array<string,mixed>>  $proofBranches
     */
    private function anyWorktree(array $proofBranches): bool
    {
        foreach ($proofBranches as $branch) {
            if ((bool) ($branch['has_worktree'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $proofBranches
     */
    private function anyProofCommit(array $proofBranches): bool
    {
        foreach ($proofBranches as $branch) {
            if ((string) ($branch['branch_commit'] ?? '') !== ''
                && (((int) ($branch['ahead_of_base'] ?? 0) > 0) || (bool) ($branch['ap781_recorded'] ?? false))) {
                return true;
            }
        }

        return false;
    }

    private function proofRecordedForBranch(string $repoRoot, string $areaId, string $branchRef): bool
    {
        foreach ($this->readJsonl($this->firstLiveProof->recordPath($areaId, $repoRoot)) as $row) {
            if ((string) data_get($row, 'branch.branch_ref', '') === $branchRef
                && ($row['status'] ?? '') === StewardshipFirstLiveBranchProofService::STATUS_PROVEN) {
                return true;
            }
        }

        return false;
    }

    private function integrationRecordedForLane(string $areaId, string $laneRef, string $laneCommit): bool
    {
        foreach ($this->readJsonl($this->integrationLane->recordPath($areaId)) as $row) {
            if ((string) data_get($row, 'integration_lane.lane_ref', '') === $laneRef
                && (string) data_get($row, 'integration_lane.lane_commit_after', '') === $laneCommit) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function candidateBranchesForLane(string $repoRoot, string $laneCommit, string $areaId): array
    {
        $candidates = [];
        $prefix = 'atlas/area-focus/'.$areaId.'/';
        foreach ($this->localBranches($repoRoot, $prefix) as $branchRef) {
            $commit = $this->revParse($repoRoot, $branchRef);
            if ($commit !== '' && $this->isAncestor($repoRoot, $laneCommit, $commit) && $commit === $laneCommit) {
                $candidates[] = $branchRef;
            }
        }

        return $candidates;
    }

    /**
     * @return list<string>
     */
    private function localBranches(string $repoRoot, string $prefix): array
    {
        $out = [];
        foreach ($this->gitLines($repoRoot, ['for-each-ref', '--format=%(refname:short)', 'refs/heads/'.$prefix]) as $line) {
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    private function branchHasWorktree(string $repoRoot, string $branchRef): bool
    {
        foreach ($this->gitLines($repoRoot, ['worktree', 'list', '--porcelain']) as $line) {
            if (str_starts_with($line, 'branch ') && str_ends_with(trim(substr($line, 7)), $branchRef)) {
                return true;
            }
        }

        return false;
    }

    private function worktreeClean(string $repoRoot): bool
    {
        return trim($this->git($repoRoot, ['status', '--porcelain'])['out']) === '';
    }

    private function aheadCount(string $repoRoot, string $baseCommit, string $headCommit): int
    {
        if ($baseCommit === '' || $headCommit === '' || $baseCommit === $headCommit) {
            return 0;
        }
        if (! $this->isAncestor($repoRoot, $baseCommit, $headCommit)) {
            return 0;
        }
        $count = trim($this->git($repoRoot, ['rev-list', '--count', $baseCommit.'..'.$headCommit])['out']);

        return max(0, (int) $count);
    }

    private function isAncestor(string $repoRoot, string $ancestor, string $descendant): bool
    {
        if ($ancestor === '' || $descendant === '') {
            return false;
        }

        return $this->git($repoRoot, ['merge-base', '--is-ancestor', $ancestor, $descendant])['ok'] === true;
    }

    private function revParse(string $repoRoot, string $ref): string
    {
        return trim($this->git($repoRoot, ['rev-parse', $ref])['out']);
    }

    private function isGitRepo(string $repoRoot): bool
    {
        return $this->git($repoRoot, ['rev-parse', '--is-inside-work-tree'])['ok'] === true;
    }

    /**
     * @param  list<string>  $args
     * @return list<string>
     */
    private function gitLines(string $repoRoot, array $args): array
    {
        $out = trim($this->git($repoRoot, $args)['out']);

        if ($out === '') {
            return [];
        }

        return preg_split('/\r\n|\r|\n/', $out) ?: [];
    }

    /**
     * @param  list<string>  $args
     * @return array{ok:bool,out:string,err:string}
     */
    private function git(string $repoRoot, array $args): array
    {
        $process = new Process(array_merge(['git'], $args), $repoRoot, null, null, 30);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'out' => $process->getOutput(),
            'err' => $process->getErrorOutput(),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonl(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    private function reliableLoopLedgerPath(string $areaId, string $focus): string
    {
        $base = $this->storageRootOverride !== null
            ? $this->storageRootOverride.'/reliable_24h_loop'
            : (function_exists('storage_path')
                ? storage_path('atlas/software_company_stewardship/reliable_24h_loop')
                : sys_get_temp_dir().'/atlas/software_company_stewardship/reliable_24h_loop');

        return $base.DIRECTORY_SEPARATOR.$this->slug($areaId).'__'.$this->slug($focus).'.jsonl';
    }

    /**
     * @return array<string,mixed>
     */
    private function failedPayload(string $areaId, string $baseRef, string $repoRoot, string $reason, string $detail): array
    {
        return [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-784',
            'status' => self::STATUS_FAILED,
            'area_id' => $areaId,
            'base_ref' => $baseRef,
            'base_clean' => false,
            'repo_root' => $repoRoot,
            'proof_branches' => [],
            'integration_lanes' => [],
            'latest_lane_commit' => '',
            'main_commit' => '',
            'real_steps' => $this->emptyRealSteps(),
            'not_yet_real' => ['audit_failed: '.$reason],
            'blockers' => [$reason],
            'next_real_action' => $detail,
            'operator_truth' => $detail,
            'reason' => $reason,
            'claim_policy' => ['read_only' => true, 'mutates_refs' => false],
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function emptyRealSteps(): array
    {
        return [
            'branch_created' => false,
            'worktree_created' => false,
            'commit_created' => false,
            'review_packet_created' => false,
            'integration_lane_advanced' => false,
            'main_promoted' => false,
            'owner_runtime_executed' => false,
            'provider_invoked' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $hashPayload = $payload;
        unset($hashPayload['generated_at'], $hashPayload['audit_hash']);
        $payload['audit_hash'] = 'sha256:'.MissionCanonicalHash::sha256($hashPayload);
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function repoRoot(array $input): string
    {
        $candidate = trim((string) ($input['repo_root'] ?? ''));
        if ($candidate === '' && function_exists('base_path')) {
            $candidate = base_path();
        }

        return $candidate !== '' ? (realpath($candidate) ?: $candidate) : '';
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_\-]+/', '_', $slug) ?? '';
        $slug = $slug !== '' ? $slug : self::DEFAULT_AREA_ID;

        return trim($slug, '_-') ?: self::DEFAULT_AREA_ID;
    }
}
