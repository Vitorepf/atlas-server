<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\SeniorLoop\SeniorEngineerLoopExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\DevForgeRuntimeExecutionBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultBridgeService;
use App\Services\Ai\Support\JsonFileStore;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * AP-768 · Atlas Software Company Stewardship Stack · Area Focus Loop ·
 * First Full Cycle Orchestrator.
 *
 * Atlas Software Company Stewardship Stack is a stack/capability family inside
 * the Atlas Autonomous Software Company Runtime, NOT a new OS.
 *
 * This is the operator-facing seam that stitches the already-shipped components
 * into the FIRST complete, conservative, auditable 24h stewardship cycle for one
 * area/focus:
 *
 *   Runner (AP-766) → Deep Scan (AP-748) → selected Finding → Spec/Proposal seed
 *   (AP-718 → Self-Directed Evolution) → Branch Sandbox (AP-756) → Dev/Forge
 *   Runtime Execution Bridge (AP-767) → Evidence/Product Mode/Inbox Result Bridge
 *   (AP-765) → final cycle receipt.
 *
 * It COMPOSES those owners; it never re-implements a scanner, a sandbox
 * materializer, an executor, an evidence ledger or an inbox. Every stage either
 * RUNS (delegated to its owner) or is DEFERRED with a precise, machine-readable
 * contract describing exactly what the operator must supply to advance it. The
 * orchestrator itself performs no provider call, opens no branch, runs no owner
 * command, merges/deploys nothing and touches no secret. The single mutating
 * stage (AP-756 worktree creation) only runs when the operator explicitly passes
 * a real preflight + receipt; by default the sandbox is SIMULATED (a valid
 * isolated descriptor with no disk effect) so the chain can be proven safely.
 */
final class FirstFullCycleOrchestratorService
{
    public const RECEIPT_SCHEMA = 'atlas.software_company_stewardship.first_full_cycle.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.first_full_cycle_record.v1';

    public const MODE_DRY_RUN = 'dry-run';

    public const MODE_EXECUTE = 'execute';

    // Final cycle statuses.
    public const STATUS_DRY_RUN_COMPLETE = 'dry_run_complete';

    public const STATUS_EXECUTED_TO_GATE = 'executed_to_gate';

    public const STATUS_CYCLE_CLOSED = 'cycle_closed';

    public const STATUS_BLOCKED = 'blocked';

    // Per-stage statuses.
    public const STAGE_RAN = 'ran';

    public const STAGE_PROJECTED = 'projected';

    public const STAGE_DEFERRED = 'deferred';

    public const STAGE_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    public const DEFAULT_FOCUS = 'dev_forge';

    public const DEFAULT_PORTFOLIO_ID = 'atlas_software_company';

    /** Finding kinds considered small + safe enough for a first automated cycle. */
    private const SAFE_KINDS = ['test', 'doc', 'gap'];

    /** Severities a first conservative cycle is willing to auto-select. */
    private const SAFE_SEVERITIES = ['low', 'medium'];

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly ContinuousStewardshipRunnerService $runner,
        private readonly AreaFocusDeepFindingEngineService $deepScan,
        private readonly AreaFocusSpecDraftBridge $specBridge,
        private readonly AreaFocusBranchSandboxMaterializerService $materializer,
        private readonly DevForgeRuntimeExecutionBridgeService $devForgeBridge,
        private readonly StewardshipRuntimeResultBridgeService $resultBridge,
        private readonly SeniorEngineerLoopExecutor $seniorEngineerLoop,
        private readonly StewardshipBranchMergeGovernorService $branchMergeGovernor,
        private readonly StewardshipBranchReviewPacketService $branchReviewPacket,
        private readonly StewardshipPriorityEngineService $priorityEngine,
    ) {}

    /**
     * Propagate a hermetic storage root to every composed owner that supports it
     * (used by integration tests so nothing touches real storage/).
     */
    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
        $this->runner->setStorageRootForTesting($dir !== null ? $dir.'/runner' : null);
        $this->deepScan->setStorageRootForTesting($dir !== null ? $dir.'/deep_scan' : null);
        $this->materializer->setStorageRootForTesting($dir !== null ? $dir.'/sandbox' : null);
        $this->devForgeBridge->setStorageRootForTesting($dir !== null ? $dir.'/dev_forge' : null);
        $this->resultBridge->setStorageRootForTesting($dir !== null ? $dir.'/result_bridge' : null);
        $this->branchMergeGovernor->setStorageRootForTesting($dir !== null ? $dir.'/merge_governor' : null);
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/first_full_cycle')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/first_full_cycle';
    }

    public function cycleFilePath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.AreaFocusSlugNormalizer::lowerFileToken($areaId, self::DEFAULT_AREA_ID).'.jsonl';
    }

    /**
     * Run one first full cycle.
     *
     * Recognised `$input`:
     *   - area_id (default agentic_engineering_os), focus (default dev_forge),
     *     portfolio_id, mode (dry-run|execute), owner (atlas_dev|forge override),
     *     max_findings, actor.
     *   - record:               persist the cycle receipt (JSONL, idempotent).
     *   - materialize_sandbox:   only honoured with a real preflight_report+sandbox_receipt.
     *   - run_local_task:        let AP-767 run the allowlisted read-only+test task.
     *   - emit_inbox:            let AP-765 emit a real inbox item (execute only).
     *
     * Deterministic test seams:
     *   - deep_scan_report:   bypass the live scan with a full AP-748 report.
     *   - selected_finding:   force the selected finding.
     *   - execution_result:   inject an AP-767 execution_result (drives AP-765).
     *   - runner_report:      bypass the live runner projection.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input = []): array
    {
        $areaId = $this->areaId($input);
        $focus = $this->focus($input);
        $portfolioId = $this->portfolioId($input);
        $mode = $this->mode($input);
        $actor = trim((string) ($input['actor'] ?? '')) ?: 'operator';

        $stages = [];
        $blockers = [];

        // 1. Runner control-plane snapshot (always dry-run inside the cycle; the
        //    runner's own execute path delegates AP-746 ticks separately).
        $runnerStage = $this->runnerStage($areaId, $input);
        $stages['runner'] = $runnerStage;

        // 2. Deep scan (AP-748), read-only.
        [$scan, $scanStage] = $this->scanStage($areaId, $focus, $input);
        $stages['deep_scan'] = $scanStage;
        if (($scan['status'] ?? '') === AreaFocusDeepFindingEngineService::STATUS_BLOCKED) {
            $blockers[] = 'deep_scan_blocked';
        }

        // 3. Select a small, safe finding.
        $selection = $this->selectFinding($scan, $input);
        $stages['selected_finding'] = $selection['stage'];
        $finding = $selection['finding'];
        if ($finding === null) {
            return $this->finalize($this->assemble($areaId, $focus, $portfolioId, $mode, $stages, self::STATUS_BLOCKED, [
                'no_safe_finding_available',
            ], $this->blockedNextActions(), $input), $input);
        }

        // 4. Spec / proposal seed (AP-718 -> Self-Directed Evolution), proposal-only.
        $specStage = $this->specStage($finding);
        $stages['spec_proposal_seed'] = $specStage;

        // 5. Branch sandbox (AP-756) — simulated by default, materialized only with
        //    an explicit operator preflight + receipt.
        $sandboxStage = $this->sandboxStage($areaId, $finding, $mode, $input);
        $stages['sandbox'] = $sandboxStage;
        $sandboxDescriptor = $sandboxStage['descriptor'];

        // 6. Dev/Forge runtime execution bridge (AP-767).
        $devForgeStage = $this->devForgeStage($areaId, $portfolioId, $finding, $sandboxDescriptor, $specStage, $mode, $input);
        $stages['dev_forge_execution'] = $devForgeStage;
        $executionResult = $devForgeStage['execution_result'];

        // 7. Evidence / Product Mode / Inbox result bridge (AP-765).
        $resultStage = $this->resultStage($areaId, $portfolioId, $finding, $executionResult, $mode, $actor, $input);
        $stages['runtime_result'] = $resultStage;

        // 8. Branch merge governance (AP-769): visual/reviewable branch state,
        //    conflict preflight and optional policy-gated ff-only merge.
        $mergeGovernanceStage = $this->branchMergeGovernanceStage($areaId, $finding, $sandboxDescriptor, $executionResult, $mode, $input);
        $stages['branch_merge_governance'] = $mergeGovernanceStage;

        // 9. Branch review packet (AP-780): one operator-facing GitKraken/Product
        //    Mode packet derived from AP-769 governance.
        $branchReviewStage = $this->branchReviewPacketStage($areaId, $mergeGovernanceStage, $input);
        $stages['branch_review_packet'] = $branchReviewStage;

        $finalStatus = $this->finalStatus($mode, $stages, $blockers);
        $nextAction = $this->nextOperatorAction($finalStatus, $stages, $finding);

        return $this->finalize(
            $this->assemble($areaId, $focus, $portfolioId, $mode, $stages, $finalStatus, $blockers, $nextAction, $input),
            $input,
        );
    }

    // ------------------------------------------------------------------
    // Stage 1 — runner
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function runnerStage(string $areaId, array $input): array
    {
        if (is_array($input['runner_report'] ?? null)) {
            $report = $input['runner_report'];

            return $this->stage('runner', 'AP-766', self::STAGE_PROJECTED, 'Runner projection supplied by override.', [
                'runner_id' => (string) ($report['runner_id'] ?? ''),
                'runner_status' => (string) ($report['status'] ?? 'unknown'),
                'run_hash' => (string) ($report['run_hash'] ?? ''),
                'report' => $report,
            ]);
        }

        try {
            // The cycle only needs the control-plane gate snapshot; force dry-run so
            // the orchestrator never delegates an AP-746 tick or records a run.
            $report = $this->runner->run([
                'area_id' => $areaId,
                'mode' => ContinuousStewardshipRunnerService::MODE_DRY_RUN,
                'record_runner_run' => false,
            ]);
        } catch (Throwable $e) {
            return $this->stage('runner', 'AP-766', self::STAGE_DEFERRED, 'Runner projection unavailable: '.$e->getMessage(), [
                'runner_status' => 'unavailable',
            ]);
        }

        return $this->stage('runner', 'AP-766', self::STAGE_PROJECTED, 'Continuous runner control-plane gate snapshot (dry-run, no tick).', [
            'runner_id' => (string) ($report['runner_id'] ?? ''),
            'runner_status' => (string) ($report['status'] ?? 'unknown'),
            'would_admit_tick' => (bool) ($report['would_admit_tick'] ?? false),
            'kill_switch_status' => (string) ($report['kill_switch_status'] ?? 'unknown'),
            'run_hash' => (string) ($report['run_hash'] ?? ''),
            'report' => $report,
        ]);
    }

    // ------------------------------------------------------------------
    // Stage 2 — deep scan
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $input
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function scanStage(string $areaId, string $focus, array $input): array
    {
        if (is_array($input['deep_scan_report'] ?? null)) {
            $scan = $input['deep_scan_report'];
        } else {
            $scanInput = ['area_id' => $areaId, 'focus' => $focus];
            if (isset($input['max_findings']) && is_numeric($input['max_findings'])) {
                $scanInput['max_findings'] = (int) $input['max_findings'];
            }
            $scan = $this->deepScan->scan($scanInput);
        }

        $status = (string) ($scan['status'] ?? 'unknown');
        $stage = $this->stage('deep_scan', 'AP-748',
            $status === AreaFocusDeepFindingEngineService::STATUS_BLOCKED ? self::STAGE_BLOCKED : self::STAGE_RAN,
            'Read-only deep finding scan over '.$areaId.'/'.$focus.'.',
            [
                'scan_id' => (string) ($scan['scan_id'] ?? ''),
                'scan_status' => $status,
                'finding_count' => (int) ($scan['finding_count'] ?? 0),
                'in_focus' => (int) data_get($scan, 'focus_summary.in_focus', 0),
            ],
        );

        return [$scan, $stage];
    }

    // ------------------------------------------------------------------
    // Stage 3 — finding selection
    // ------------------------------------------------------------------

    /**
     * Deterministically select the highest-priority safe in-focus finding. A
     * `selected_finding` override wins. Returns null finding when none qualifies.
     *
     * @param  array<string,mixed>  $scan
     * @param  array<string,mixed>  $input
     * @return array{finding:array<string,mixed>|null,stage:array<string,mixed>}
     */
    private function selectFinding(array $scan, array $input): array
    {
        if (is_array($input['selected_finding'] ?? null) && $input['selected_finding'] !== []) {
            $finding = $input['selected_finding'];

            return [
                'finding' => $finding,
                'stage' => $this->stage('selected_finding', 'AP-748', self::STAGE_RAN, 'Finding supplied by operator override.',
                    $this->findingSummary($finding) + ['selection_strategy' => 'operator_override']),
            ];
        }

        $findings = AreaFocusLoopPayloadNormalizer::listOfArrays($scan['findings'] ?? []);
        $candidates = array_values(array_filter($findings, fn (array $f): bool => $this->isSafeSmallFinding($f)));

        if ($candidates === []) {
            return [
                'finding' => null,
                'stage' => $this->stage('selected_finding', 'AP-748', self::STAGE_BLOCKED,
                    'No small, safe, in-focus finding available to start a conservative cycle.', [
                        'total_findings' => count($findings),
                        'safe_kinds' => self::SAFE_KINDS,
                        'safe_severities' => self::SAFE_SEVERITIES,
                    ]),
            ];
        }

        $priority = $this->priorityEngine->rank([
            'area_id' => (string) ($scan['area_id'] ?? self::DEFAULT_AREA_ID),
            'candidates' => $candidates,
        ]);
        $topCandidateId = (string) data_get($priority, 'top_candidate.candidate_id', '');
        $finding = $this->findingByPriorityId($candidates, $topCandidateId) ?? $this->highestSafetyFallback($candidates);

        return [
            'finding' => $finding,
            'stage' => $this->stage('selected_finding', 'AP-748', self::STAGE_RAN,
                'Selected the highest AP-785 priority finding inside the conservative safe candidate set.',
                $this->findingSummary($finding) + [
                    'selection_strategy' => 'ap785_priority_with_safe_candidate_gate',
                    'safe_candidate_count' => count($candidates),
                    'priority_report' => $priority,
                ]),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array<string,mixed>|null
     */
    private function findingByPriorityId(array $candidates, string $candidateId): ?array
    {
        if ($candidateId === '') {
            return null;
        }

        foreach ($candidates as $candidate) {
            $ids = [
                (string) ($candidate['id'] ?? ''),
                (string) ($candidate['finding_id'] ?? ''),
                (string) ($candidate['finding_hash'] ?? ''),
            ];
            if (in_array($candidateId, $ids, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array<string,mixed>
     */
    private function highestSafetyFallback(array $candidates): array
    {
        usort($candidates, fn (array $a, array $b): int => $this->safetyScore($b) <=> $this->safetyScore($a)
            ?: ((string) ($a['finding_hash'] ?? '') <=> (string) ($b['finding_hash'] ?? '')));

        return $candidates[0];
    }

    /**
     * @param  array<string,mixed>  $finding
     */
    private function isSafeSmallFinding(array $finding): bool
    {
        if (trim((string) ($finding['finding_hash'] ?? '')) === '') {
            return false;
        }
        $kind = (string) ($finding['kind'] ?? '');
        $severity = strtolower((string) ($finding['severity'] ?? ''));

        return in_array($kind, self::SAFE_KINDS, true) && in_array($severity, self::SAFE_SEVERITIES, true);
    }

    /**
     * Higher = safer/smaller/more confident. Prefers in-focus, low severity, high
     * confidence, doc/test over gap.
     *
     * @param  array<string,mixed>  $finding
     */
    private function safetyScore(array $finding): int
    {
        $kind = (string) ($finding['kind'] ?? '');
        $severity = strtolower((string) ($finding['severity'] ?? ''));
        $confidence = strtolower((string) ($finding['confidence'] ?? ''));

        $kindScore = match ($kind) {
            'test' => 40,
            'doc' => 30,
            'gap' => 10,
            default => 0,
        };
        $severityScore = $severity === 'low' ? 20 : ($severity === 'medium' ? 10 : 0);
        $confidenceScore = $confidence === 'high' ? 15 : ($confidence === 'medium' ? 8 : 3);
        $focusScore = ($finding['in_focus'] ?? false) === true ? 25 : 0;

        return $kindScore + $severityScore + $confidenceScore + $focusScore;
    }

    // ------------------------------------------------------------------
    // Stage 4 — spec / proposal seed
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function specStage(array $finding): array
    {
        try {
            $draft = $this->specBridge->draftFromFinding($this->findingForBridge($finding));
        } catch (Throwable $e) {
            return $this->stage('spec_proposal_seed', 'AP-718', self::STAGE_DEFERRED,
                'Spec proposal seed could not be drafted: '.$e->getMessage(), []);
        }

        return $this->stage('spec_proposal_seed', 'AP-718', self::STAGE_RAN,
            'Proposal-only spec draft from the finding (operator-curated; nothing written).', [
                'spec_draftable' => (bool) ($draft['spec_draftable'] ?? false),
                'bridge_hash' => (string) ($draft['bridge_hash'] ?? ''),
                'proposed_doc_path' => (string) data_get($draft, 'spec_proposal_draft.proposed_doc_path', ''),
                'spec_seed' => is_array($finding['spec_seed'] ?? null) ? $finding['spec_seed'] : null,
                'spec_proposal_draft' => $draft,
            ]);
    }

    // ------------------------------------------------------------------
    // Stage 5 — branch sandbox
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function sandboxStage(string $areaId, array $finding, string $mode, array $input): array
    {
        $allowedFiles = $this->allowedFiles($finding);
        $simulated = $this->simulatedSandbox($areaId, $finding, $allowedFiles);

        if (is_array($input['sandbox_descriptor'] ?? null) && $input['sandbox_descriptor'] !== []) {
            $descriptor = $this->normalizeExternalSandboxDescriptor($input['sandbox_descriptor'], $allowedFiles);

            return $this->stage('sandbox', 'AP-756', self::STAGE_RAN,
                'Using an externally supplied isolated sandbox descriptor for this first full cycle.',
                [
                    'descriptor' => $descriptor,
                    'sandbox_mode' => ((bool) ($descriptor['simulated'] ?? true)) ? 'simulated' : 'materialized',
                    'sandbox_id' => (string) ($descriptor['sandbox_id'] ?? ''),
                    'materializer_status' => 'external_descriptor',
                    'materializer_record' => null,
                ]);
        }

        $wantsMaterialize = $mode === self::MODE_EXECUTE && (bool) ($input['materialize_sandbox'] ?? false);
        $preflight = is_array($input['preflight_report'] ?? null) ? $input['preflight_report'] : [];
        $receipt = is_array($input['sandbox_receipt'] ?? null) ? $input['sandbox_receipt'] : [];

        // Real materialization only with an explicit operator-gated preflight + receipt.
        if ($wantsMaterialize && $preflight !== [] && $receipt !== []) {
            try {
                $record = $this->materializer->materialize([
                    'area_id' => $areaId,
                    'preflight_report' => $preflight,
                    'sandbox_receipt' => $receipt,
                    'materialize_sandbox' => true,
                    'record_sandbox' => true,
                    'base_ref' => (string) ($input['base_ref'] ?? 'HEAD'),
                ]);
            } catch (Throwable $e) {
                return $this->stage('sandbox', 'AP-756', self::STAGE_DEFERRED,
                    'Sandbox materialization raised an exception: '.$e->getMessage(),
                    ['descriptor' => $simulated, 'sandbox_mode' => 'simulated_after_error']);
            }

            $descriptor = $this->descriptorFromMaterializer($record, $allowedFiles);

            return $this->stage('sandbox', 'AP-756',
                ($record['status'] ?? '') === AreaFocusBranchSandboxMaterializerService::STATUS_BLOCKED ? self::STAGE_BLOCKED : self::STAGE_RAN,
                'Materialized an isolated git worktree via the operator-gated AP-756 path.',
                [
                    'descriptor' => $descriptor,
                    'sandbox_mode' => 'materialized',
                    'sandbox_id' => (string) ($record['sandbox_id'] ?? ''),
                    'materializer_status' => (string) ($record['status'] ?? ''),
                    'materializer_record' => $record,
                ]);
        }

        $reason = $wantsMaterialize
            ? 'Materialization requested but no AP-726 preflight_report + sandbox_receipt supplied; using a simulated descriptor.'
            : 'Conservative default: simulated isolated sandbox descriptor (no disk effect).';

        return $this->stage('sandbox', 'AP-756', self::STAGE_PROJECTED, $reason, [
            'descriptor' => $simulated,
            'sandbox_mode' => 'simulated',
            'sandbox_id' => (string) $simulated['sandbox_id'],
            'materialization_contract' => [
                'required_inputs' => [
                    'AP-724 operator accept decision (accept, not executed)',
                    'AP-726 preflight_report (ready Dev/Forge handoff)',
                    'sandbox_receipt matching the ready handoff target_hash',
                ],
                'command' => 'php artisan atlas:software-company-stewardship area-focus-branch-sandbox-materialize --preflight-file=<preflight.json> --sandbox-receipt-file=<receipt.json> --materialize-sandbox --record-sandbox --json',
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    private function simulatedSandbox(string $areaId, array $finding, array $allowedFiles): array
    {
        $hash = substr((string) ($finding['finding_hash'] ?? hash('sha256', $areaId)), -12);
        $hash = preg_replace('/[^a-z0-9]/', '', strtolower($hash)) ?: 'sim';
        $sandboxId = 'afbs_sim_'.substr(MissionCanonicalHash::sha256([$areaId, $finding['finding_hash'] ?? '']), 0, 16);
        $branch = 'atlas/area-focus/'.AreaFocusSlugNormalizer::lowerFileToken($areaId, self::DEFAULT_AREA_ID).'/'.$hash;
        $allowedPaths = $this->allowedPaths($allowedFiles);

        return [
            'present' => true,
            'simulated' => true,
            'isolated' => true,
            'sandbox_id' => $sandboxId,
            'branch_name' => $branch,
            // Deterministic non-existent path: AP-767 dry-run only plans; a real
            // local task would honestly block on worktree_not_materialized.
            'worktree_path' => rtrim($this->storageDir(), '/').'/simulated_worktrees/'.$sandboxId,
            'base_ref' => 'HEAD',
            'allowed_paths' => $allowedPaths,
            'forbidden_paths' => ['.env', 'storage/secrets', 'config/secrets', 'vendor/', 'node_modules/'],
        ];
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    private function descriptorFromMaterializer(array $record, array $allowedFiles): array
    {
        $mat = is_array($record['materialization'] ?? null) ? $record['materialization'] : [];

        return [
            'present' => true,
            'simulated' => false,
            'isolated' => true,
            'sandbox_id' => (string) ($record['sandbox_id'] ?? ''),
            'branch_name' => (string) ($mat['branch_name'] ?? ''),
            'worktree_path' => (string) ($mat['worktree_path'] ?? ''),
            'base_ref' => (string) ($mat['base_ref'] ?? 'HEAD'),
            'allowed_paths' => $this->allowedPaths($allowedFiles),
            'forbidden_paths' => ['.env', 'storage/secrets', 'config/secrets', 'vendor/', 'node_modules/'],
            'materialization' => $mat,
        ];
    }

    /**
     * @param  array<string,mixed>  $descriptor
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    private function normalizeExternalSandboxDescriptor(array $descriptor, array $allowedFiles): array
    {
        $worktree = trim((string) ($descriptor['worktree_path'] ?? data_get($descriptor, 'materialization.worktree_path', '')));
        $branch = trim((string) ($descriptor['branch_name'] ?? data_get($descriptor, 'materialization.branch_name', '')));
        $sandboxId = trim((string) ($descriptor['sandbox_id'] ?? ''));
        $simulated = (bool) ($descriptor['simulated'] ?? false);

        return [
            'present' => true,
            'simulated' => $simulated,
            'isolated' => (bool) ($descriptor['isolated'] ?? true),
            'sandbox_id' => $sandboxId !== '' ? $sandboxId : 'external_'.substr(MissionCanonicalHash::sha256($descriptor), 0, 16),
            'branch_name' => $branch,
            'worktree_path' => $worktree,
            'base_ref' => (string) ($descriptor['base_ref'] ?? 'HEAD'),
            'allowed_paths' => AreaFocusStringListNormalizer::coercedStringValues($descriptor['allowed_paths'] ?? $this->allowedPaths($allowedFiles)),
            'forbidden_paths' => AreaFocusStringListNormalizer::coercedStringValues($descriptor['forbidden_paths'] ?? ['.env', 'storage/secrets', 'config/secrets', 'vendor/', 'node_modules/']),
            'materialization' => is_array($descriptor['materialization'] ?? null) ? $descriptor['materialization'] : [],
        ];
    }

    // ------------------------------------------------------------------
    // Stage 6 — Dev/Forge runtime execution bridge
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $sandbox
     * @param  array<string,mixed>  $specStage
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function devForgeStage(string $areaId, string $portfolioId, array $finding, array $sandbox, array $specStage, string $mode, array $input): array
    {
        $owner = $this->owner($finding, $input);
        $allowedFiles = $this->allowedFiles($finding);

        if (is_array($input['allowed_files'] ?? null) && $input['allowed_files'] !== []) {
            $allowedFiles = AreaFocusStringListNormalizer::uniqueStringValues(array_filter($input['allowed_files'], 'is_string'));
        }

        if ($mode === self::MODE_EXECUTE && (bool) ($input['run_real_atlas_dev'] ?? false)) {
            return $this->realAtlasDevStage($areaId, $portfolioId, $finding, $sandbox, $allowedFiles, $input);
        }

        $source = [
            'kind' => 'finding',
            'id' => (string) ($finding['finding_id'] ?? ''),
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'title' => (string) ($finding['title'] ?? ''),
            'allowed_files' => $allowedFiles,
            'spec' => [
                'title' => (string) ($finding['proposed_spec_title'] ?? $finding['title'] ?? ''),
                'allowed_files' => $allowedFiles,
                'rationale' => (string) ($finding['why_it_matters'] ?? ''),
            ],
        ];

        $bridgeInput = [
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'owner' => $owner,
            'mode' => $mode,
            'source' => $source,
            'sandbox' => $sandbox,
            'run_local_deterministic_task' => $mode === self::MODE_EXECUTE && (bool) ($input['run_local_task'] ?? false),
        ];

        try {
            $report = $this->devForgeBridge->execute($bridgeInput);
        } catch (Throwable $e) {
            return $this->stage('dev_forge_execution', 'AP-767', self::STAGE_DEFERRED,
                'Dev/Forge bridge raised an exception: '.$e->getMessage(), ['execution_result' => null, 'owner' => $owner]);
        }

        $status = (string) ($report['status'] ?? '');
        $executionResult = is_array($report['execution_result'] ?? null) ? $report['execution_result'] : null;
        $stageStatus = match (true) {
            $status === DevForgeRuntimeExecutionBridgeService::STATUS_BLOCKED => self::STAGE_BLOCKED,
            $status === DevForgeRuntimeExecutionBridgeService::STATUS_EXECUTED => self::STAGE_RAN,
            default => self::STAGE_PROJECTED,
        };

        return $this->stage('dev_forge_execution', 'AP-767', $stageStatus,
            $this->devForgeNarrative($status, $owner), [
                'owner' => $owner,
                'bridge_status' => $status,
                'next_state' => (string) ($report['next_state'] ?? ''),
                'execution_id' => (string) ($report['execution_id'] ?? ''),
                'provider_bridge_missing' => (bool) data_get($report, 'provider_bridge.provider_bridge_missing', false),
                'changed_files' => array_values((array) ($report['changed_files'] ?? [])),
                'execution_result' => $executionResult,
                'report' => $report,
            ]);
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $sandbox
     * @param  list<string>  $allowedFiles
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function realAtlasDevStage(string $areaId, string $portfolioId, array $finding, array $sandbox, array $allowedFiles, array $input): array
    {
        $owner = $this->owner($finding, $input);
        if ($owner !== 'atlas_dev') {
            return $this->stage('dev_forge_execution', 'AP-767+AtlasDev', self::STAGE_DEFERRED,
                'Real provider execution is only wired for atlas_dev in this slice; Forge remains routed through AP-767.', [
                    'owner' => $owner,
                    'bridge_status' => 'real_atlas_dev_owner_mismatch',
                    'execution_result' => null,
                ]);
        }

        $workspace = trim((string) ($sandbox['worktree_path'] ?? ''));
        if ($workspace === '' || ! is_dir($workspace) || (bool) ($sandbox['simulated'] ?? true)) {
            return $this->stage('dev_forge_execution', 'AP-767+AtlasDev', self::STAGE_DEFERRED,
                'Real Atlas Dev execution requires a materialized isolated worktree descriptor.', [
                    'owner' => $owner,
                    'bridge_status' => 'worktree_not_materialized',
                    'execution_result' => null,
                ]);
        }

        $intent = trim((string) ($input['real_atlas_dev_intent'] ?? ''));
        if ($intent === '') {
            return $this->stage('dev_forge_execution', 'AP-767+AtlasDev', self::STAGE_DEFERRED,
                'Real Atlas Dev execution requires an explicit operator-approved implementation intent for the first live cycle.', [
                    'owner' => $owner,
                    'bridge_status' => 'real_atlas_dev_intent_required',
                    'execution_result' => null,
                ]);
        }

        $validationCommands = AreaFocusStringListNormalizer::coercedStringValues($input['test_commands'] ?? []);
        $constraints = [];
        foreach ($allowedFiles as $file) {
            $constraints[] = 'allowed_files='.$file;
        }
        foreach ($validationCommands as $command) {
            $constraints[] = 'validation_command='.$command;
        }

        try {
            $execution = $this->seniorEngineerLoop->run(
                surfaceId: 'software_company_stewardship.first_full_cycle',
                workspace: $workspace,
                rawIntent: $intent,
                userConstraints: $constraints,
                surfaceHints: [
                    'area_id' => $areaId,
                    'portfolio_id' => $portfolioId,
                    'finding_id' => (string) ($finding['finding_id'] ?? ''),
                    'sandbox_id' => (string) ($sandbox['sandbox_id'] ?? ''),
                    'source' => 'AP-768 first-full-cycle',
                ],
            );
        } catch (Throwable $e) {
            return $this->stage('dev_forge_execution', 'AP-767+AtlasDev', self::STAGE_DEFERRED,
                'Atlas Dev real execution raised an exception: '.$e->getMessage(), [
                    'owner' => $owner,
                    'bridge_status' => 'atlas_dev_exception',
                    'execution_result' => null,
                ]);
        }

        $executionResult = $this->executionResultFromSeniorLoop($execution->toCanonicalArray(), $areaId, $portfolioId, $finding, $sandbox, $allowedFiles, $validationCommands);
        $stageStatus = $execution->status === 'passed' ? self::STAGE_RAN : self::STAGE_BLOCKED;

        return $this->stage('dev_forge_execution', 'AP-767+AtlasDev', $stageStatus,
            'Atlas Dev Senior Engineer Loop executed in the isolated sandbox and returned receipts for AP-765 closure.', [
                'owner' => $owner,
                'bridge_status' => 'atlas_dev_real_executed',
                'next_state' => $execution->status === 'passed' ? 'runtime_result_bridge' : 'operator_review',
                'execution_id' => $execution->runId,
                'provider_bridge_missing' => false,
                'changed_files' => array_values((array) ($executionResult['changed_files'] ?? [])),
                'execution_result' => $executionResult,
                'report' => [
                    'schema_version' => 'atlas.software_company_stewardship.real_atlas_dev_execution.v1',
                    'status' => $execution->status,
                    'senior_loop_execution' => $execution->toCanonicalArray(),
                    'receipt_dir' => $this->atlasDevReceiptDir($execution->runId),
                ],
            ]);
    }

    /**
     * @param  array<string,mixed>  $execution
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $sandbox
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $validationCommands
     * @return array<string,mixed>
     */
    private function executionResultFromSeniorLoop(array $execution, string $areaId, string $portfolioId, array $finding, array $sandbox, array $allowedFiles, array $validationCommands): array
    {
        $runId = (string) ($execution['run_id'] ?? '');
        $provider = $this->readAtlasDevReceipt($runId, ArtifactNames::PROVIDER_CALL_RESULT);
        $diff = $this->readAtlasDevReceipt($runId, ArtifactNames::DIFF_PARSE_RESULT);
        $patch = $this->readAtlasDevReceipt($runId, ArtifactNames::PATCH_APPLY_RESULT);
        $scope = $this->readAtlasDevReceipt($runId, ArtifactNames::SCOPE_GUARD_RECEIPT);
        $verification = $this->readAtlasDevReceipt($runId, ArtifactNames::VERIFICATION_RECEIPT);

        $changedFiles = AreaFocusStringListNormalizer::uniqueStringValues(array_filter((array) ($diff['changed_files'] ?? $scope['changed_files'] ?? $allowedFiles), 'is_string'));
        $providerSummary = is_array($execution['run_summary']['provider_call'] ?? null) ? $execution['run_summary']['provider_call'] : [];
        $providerInvoked = ((int) ($providerSummary['provider_calls'] ?? 0)) > 0 || $provider !== [];
        $status = (string) ($execution['status'] ?? 'needs_review');
        $testResults = [
            'status' => (string) ($verification['status'] ?? data_get($execution, 'run_summary.verification_status', 'unknown')),
            'commands' => $validationCommands,
            'receipt' => $verification,
        ];

        return [
            'schema_version' => 'atlas.software_company_stewardship.real_atlas_dev_result.v1',
            'execution_id' => $runId,
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'owner' => 'atlas_dev',
            'result_status' => $status === 'passed' ? 'completed' : 'needs_review',
            'summary' => 'Atlas Dev real provider execution completed with status '.$status.'.',
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'spec_id' => (string) data_get($finding, 'spec_seed.spec_id', ''),
            'handoff_id' => 'AP-768:first_full_cycle:'.$runId,
            'sandbox_id' => (string) ($sandbox['sandbox_id'] ?? ''),
            'branch_ref' => (string) ($sandbox['branch_name'] ?? ''),
            'worktree_path' => (string) ($sandbox['worktree_path'] ?? ''),
            'changed_files' => $changedFiles,
            'tests' => $validationCommands,
            'test_results' => $testResults,
            'validation_commands' => $validationCommands,
            'evidence_pack' => [
                'evidence_hash' => 'sha256:'.MissionCanonicalHash::sha256([$execution, $provider, $diff, $patch, $scope, $verification]),
                'summary' => 'Provider patch, scope guard and verification receipts from Atlas Dev Senior Engineer Loop.',
                'changed_files' => $changedFiles,
                'tests' => $validationCommands,
                'sandbox_id' => (string) ($sandbox['sandbox_id'] ?? ''),
                'finding_id' => (string) ($finding['finding_id'] ?? ''),
                'spec_id' => (string) data_get($finding, 'spec_seed.spec_id', ''),
                'receipts' => [
                    'senior_loop_execution' => $execution,
                    'provider_call_result' => $provider,
                    'diff_parse_result' => $diff,
                    'patch_apply_result' => $patch,
                    'scope_guard_receipt' => $scope,
                    'verification_receipt' => $verification,
                ],
            ],
            'risks' => ['Operator must review the isolated branch diff before accepting or merging.'],
            'rollback' => 'Discard the isolated worktree/branch; no merge, deploy or external push was performed.',
            'runtime_execution_started' => true,
            'provider_invoked' => $providerInvoked,
            'provider' => (string) ($providerSummary['provider'] ?? data_get($provider, 'provider', 'claude_cli')),
            'model' => (string) ($providerSummary['model'] ?? data_get($provider, 'model', '')),
            'merge_performed' => false,
            'deploy_performed' => false,
            'external_push_performed' => false,
            'secret_access' => false,
            'destructive_change' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readAtlasDevReceipt(string $runId, string $name): array
    {
        if ($runId === '') {
            return [];
        }

        $path = $this->atlasDevReceiptDir($runId).DIRECTORY_SEPARATOR.$name;
        if (! is_file($path)) {
            return [];
        }

        return JsonFileStore::readArray($path) ?? [];
    }

    private function atlasDevReceiptDir(string $runId): string
    {
        return function_exists('storage_path')
            ? storage_path('atlas-dev/receipts/'.$runId)
            : sys_get_temp_dir().'/atlas-dev/receipts/'.$runId;
    }

    private function devForgeNarrative(string $status, string $owner): string
    {
        return match ($status) {
            DevForgeRuntimeExecutionBridgeService::STATUS_PLANNED => 'AP-767 planned the '.$owner.' work (dry-run, no mutation).',
            DevForgeRuntimeExecutionBridgeService::STATUS_EXECUTED => 'AP-767 ran the allowlisted read-only + test task inside the isolated sandbox.',
            DevForgeRuntimeExecutionBridgeService::STATUS_PROVIDER_BRIDGE_MISSING => 'AP-767 honestly reports provider_bridge_missing; real code generation needs a bound provider runtime.',
            DevForgeRuntimeExecutionBridgeService::STATUS_NEEDS_OPERATOR_OR_SPEC => 'AP-767 needs allowed_files/spec scope before it can execute.',
            DevForgeRuntimeExecutionBridgeService::STATUS_BLOCKED => 'AP-767 consumption gate blocked the request (safe stop).',
            default => 'AP-767 produced a '.$status.' result.',
        };
    }

    // ------------------------------------------------------------------
    // Stage 7 — Evidence / Product Mode / Inbox result bridge
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>|null  $executionResult
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function resultStage(string $areaId, string $portfolioId, array $finding, ?array $executionResult, string $mode, string $actor, array $input): array
    {
        $injected = is_array($input['execution_result'] ?? null) ? $input['execution_result'] : null;
        $executionResult = $injected ?? $executionResult;

        if ($executionResult === null || $executionResult === []) {
            return $this->stage('runtime_result', 'AP-765', self::STAGE_DEFERRED,
                'No execution_result yet, so Evidence/Product Mode/Inbox closure is deferred with a precise contract.', [
                    'evidence_pack' => null,
                    'inbox_item' => null,
                    'product_mode_event' => null,
                    'deferred_contract' => [
                        'reason' => 'execution_result_required',
                        'how_to_produce' => [
                            'Materialize the sandbox (AP-756) so a real isolated worktree exists on disk.',
                            'Run AP-767 with --mode=execute --run-local-task (read-only + test proof) or bind a provider runtime via AP-758/AP-759.',
                            'Feed the resulting execution_result into AP-765 runtime-result-bridge.',
                        ],
                        'command' => 'php artisan atlas:software-company-stewardship runtime-result-bridge --result-file=<execution_result.json> --owner='.$this->owner($finding, $input).' --emit-inbox --record-event --record-cycle --json',
                    ],
                ]);
        }

        $recordArtifacts = $mode === self::MODE_EXECUTE && (bool) ($input['record'] ?? false);
        try {
            $report = $this->resultBridge->project([
                'area_id' => $areaId,
                'portfolio_id' => $portfolioId,
                'owner' => $this->owner($finding, $input),
                'execution_result' => $executionResult,
                'finding_id' => (string) ($finding['finding_id'] ?? ''),
                'actor' => $actor,
                'emit_inbox' => $recordArtifacts && (bool) ($input['emit_inbox'] ?? false),
                'record_evidence' => $recordArtifacts,
                'record_event' => $recordArtifacts,
                'record_cycle' => $recordArtifacts,
            ]);
        } catch (Throwable $e) {
            return $this->stage('runtime_result', 'AP-765', self::STAGE_DEFERRED,
                'AP-765 result bridge raised an exception: '.$e->getMessage(), [
                    'evidence_pack' => null, 'inbox_item' => null, 'product_mode_event' => null,
                ]);
        }

        $status = (string) ($report['status'] ?? '');
        $stageStatus = $status === StewardshipRuntimeResultBridgeService::STATUS_BLOCKED ? self::STAGE_BLOCKED : self::STAGE_RAN;

        return $this->stage('runtime_result', 'AP-765', $stageStatus,
            'AP-765 turned the execution_result into evidence pack + inbox item + Product Mode event + portfolio signal.', [
                'result_bridge_id' => (string) ($report['result_bridge_id'] ?? ''),
                'result_status' => (string) ($report['result_status'] ?? ''),
                'evidence_pack' => is_array($report['evidence_pack'] ?? null) ? $report['evidence_pack'] : null,
                'evidence_pack_id' => (string) ($report['evidence_pack_id'] ?? ''),
                'inbox_item' => is_array($report['inbox_item'] ?? null) ? $report['inbox_item'] : null,
                'inbox_item_id' => $report['inbox_item_id'] ?? null,
                'product_mode_event' => is_array($report['product_mode_event'] ?? null) ? $report['product_mode_event'] : null,
                'product_mode_event_id' => (string) ($report['product_mode_event_id'] ?? ''),
                'portfolio_signal' => is_array($report['portfolio_signal'] ?? null) ? $report['portfolio_signal'] : null,
                'acceptance_options' => is_array($report['acceptance_options'] ?? null) ? $report['acceptance_options'] : null,
                'report' => $report,
            ]);
    }

    // ------------------------------------------------------------------
    // Stage 8 — Branch merge governance
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $sandbox
     * @param  array<string,mixed>|null  $executionResult
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function branchMergeGovernanceStage(string $areaId, array $finding, array $sandbox, ?array $executionResult, string $mode, array $input): array
    {
        if ($mode !== self::MODE_EXECUTE) {
            return $this->stage('branch_merge_governance', 'AP-769', self::STAGE_PROJECTED,
                'Branch merge governance runs after an execute cycle has a real branch/result.', [
                    'governance_report' => null,
                    'merge_status' => 'not_run_in_dry_run',
                ]);
        }

        $executionResult = is_array($input['execution_result'] ?? null) ? $input['execution_result'] : $executionResult;
        $branchRef = trim((string) ($input['branch_ref'] ?? data_get($executionResult ?? [], 'branch_ref', data_get($sandbox, 'branch_name', ''))));
        $repoRoot = trim((string) ($input['repo_root'] ?? data_get($sandbox, 'materialization.repo_root', '')));
        $worktreePath = trim((string) data_get($executionResult ?? [], 'worktree_path', data_get($sandbox, 'worktree_path', '')));

        if ($branchRef === '' || (bool) ($sandbox['simulated'] ?? true)) {
            return $this->stage('branch_merge_governance', 'AP-769', self::STAGE_DEFERRED,
                'No materialized branch is available yet, so merge governance is deferred honestly.', [
                    'governance_report' => null,
                    'merge_status' => 'branch_required',
                    'deferred_contract' => [
                        'reason' => 'materialized_branch_required',
                        'command' => 'php artisan atlas:software-company-stewardship branch-merge-governor --branch-ref=<cycle-branch> --base-ref=main --json',
                    ],
                ]);
        }

        $testCommands = AreaFocusStringListNormalizer::coercedStringValues($input['test_commands'] ?? data_get($executionResult ?? [], 'validation_commands', []));

        try {
            $report = $this->branchMergeGovernor->evaluate([
                'area_id' => $areaId,
                'repo_root' => $repoRoot,
                'base_ref' => (string) ($input['base_ref'] ?? 'main'),
                'branch_ref' => $branchRef,
                'worktree_path' => $worktreePath,
                'auto_merge' => (bool) ($input['auto_merge'] ?? false),
                'execute_merge' => (bool) ($input['execute_merge'] ?? false),
                'auto_merge_class' => (string) ($input['auto_merge_class'] ?? ''),
                'allow_code_auto_merge' => (bool) ($input['allow_code_auto_merge'] ?? false),
                'max_auto_merge_files' => (int) ($input['max_auto_merge_files'] ?? 5),
                'run_validation' => (bool) ($input['run_validation'] ?? false),
                'test_commands' => $testCommands,
                'record_governance' => (bool) ($input['record_merge_governance'] ?? ($input['record'] ?? false)),
            ]);
        } catch (Throwable $e) {
            return $this->stage('branch_merge_governance', 'AP-769', self::STAGE_DEFERRED,
                'AP-769 merge governor raised an exception: '.$e->getMessage(), [
                    'governance_report' => null,
                    'merge_status' => 'governor_exception',
                ]);
        }

        $status = (string) ($report['status'] ?? 'unknown');
        $stageStatus = $status === StewardshipBranchMergeGovernorService::STATUS_BLOCKED ? self::STAGE_BLOCKED : self::STAGE_RAN;

        return $this->stage('branch_merge_governance', 'AP-769', $stageStatus,
            'AP-769 produced branch visibility, conflict preflight and auto-merge policy for the cycle branch.', [
                'merge_status' => $status,
                'auto_merge_eligible' => (bool) data_get($report, 'auto_merge_policy.eligible', false),
                'branch_ref' => (string) data_get($report, 'repo.branch_ref', $branchRef),
                'base_ref' => (string) data_get($report, 'repo.base_ref', ''),
                'changed_files' => array_values((array) data_get($report, 'gitkraken_review_surface.changed_files', [])),
                'reviewable_commits' => array_values((array) data_get($report, 'gitkraken_review_surface.reviewable_commits', [])),
                'blockers' => array_values((array) ($report['blockers'] ?? [])),
                'governance_report' => $report,
            ]);
    }

    /**
     * @param  array<string,mixed>  $mergeGovernanceStage
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function branchReviewPacketStage(string $areaId, array $mergeGovernanceStage, array $input): array
    {
        $governance = is_array($mergeGovernanceStage['governance_report'] ?? null)
            ? $mergeGovernanceStage['governance_report']
            : [];

        if ($governance === []) {
            return $this->stage('branch_review_packet', 'AP-780', self::STAGE_DEFERRED,
                'No AP-769 governance report is available yet, so the AP-780 operator packet is deferred honestly.', [
                    'review_packet' => null,
                    'packet_status' => 'governance_required',
                    'deferred_contract' => [
                        'reason' => 'branch_governance_report_required',
                        'command' => 'php artisan atlas:software-company-stewardship branch-merge-governor --branch-ref=<cycle-branch> --base-ref=main --json',
                    ],
                ]);
        }

        $packet = $this->branchReviewPacket->build([
            'area_id' => $areaId,
            'governance_report' => $governance,
            'evidence_refs' => AreaFocusStringListNormalizer::coercedStringValues($input['evidence_refs'] ?? []),
            'queue_context' => [
                'source_stage' => 'branch_merge_governance',
                'source_ap_contract' => 'AP-769',
            ],
        ]);

        $packetStatus = (string) ($packet['status'] ?? 'unknown');
        $stageStatus = $packetStatus === StewardshipBranchReviewPacketService::STATUS_BLOCKED
            ? self::STAGE_BLOCKED
            : self::STAGE_RAN;

        return $this->stage('branch_review_packet', 'AP-780', $stageStatus,
            'AP-780 produced the single GitKraken/Product Mode operator review packet for the cycle branch.', [
                'packet_status' => $packetStatus,
                'branch_ref' => (string) data_get($packet, 'branch_identity.branch_ref', ''),
                'base_ref' => (string) data_get($packet, 'branch_identity.base_ref', ''),
                'auto_merge_candidate' => $packetStatus === StewardshipBranchReviewPacketService::STATUS_AUTO_MERGE_CANDIDATE,
                'decision_options' => array_values((array) ($packet['decision_options'] ?? [])),
                'blockers' => array_values((array) ($packet['blockers'] ?? [])),
                'review_packet' => $packet,
            ]);
    }

    // ------------------------------------------------------------------
    // Final receipt assembly
    // ------------------------------------------------------------------

    /**
     * @param  array<string,array<string,mixed>>  $stages
     * @param  list<string>  $blockers
     * @param  list<string>  $nextActions
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function assemble(string $areaId, string $focus, string $portfolioId, string $mode, array $stages, string $finalStatus, array $blockers, array $nextActions, array $input): array
    {
        $selected = is_array($stages['selected_finding'] ?? null) ? $stages['selected_finding'] : [];
        $devForge = is_array($stages['dev_forge_execution'] ?? null) ? $stages['dev_forge_execution'] : [];
        $result = is_array($stages['runtime_result'] ?? null) ? $stages['runtime_result'] : [];
        $mergeGovernance = is_array($stages['branch_merge_governance'] ?? null) ? $stages['branch_merge_governance'] : [];

        $cycleId = 'affc_'.substr(MissionCanonicalHash::sha256([
            'AP-768', $areaId, $focus, $mode,
            (string) data_get($stages, 'deep_scan.scan_id', ''),
            (string) ($selected['finding_hash'] ?? ''),
            (string) data_get($stages, 'sandbox.sandbox_id', ''),
            (string) data_get($stages, 'dev_forge_execution.bridge_status', ''),
            (bool) ($input['run_real_atlas_dev'] ?? false),
        ]), 0, 18);

        return [
            'schema_version' => self::RECEIPT_SCHEMA,
            'ap_contract' => 'AP-768',
            'cycle_id' => $cycleId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'stewardship_stack_note' => 'Atlas Software Company Stewardship Stack is a stack/capability family inside the Atlas Autonomous Software Company Runtime, not a new OS.',
            'source_ap_contracts' => ['AP-748', 'AP-718', 'AP-756', 'AP-765', 'AP-766', 'AP-767', 'AP-769', 'AP-780'],
            'area_id' => $areaId,
            'focus' => $focus,
            'portfolio_id' => $portfolioId,
            'mode' => $mode,
            'final_status' => $finalStatus,
            // The full required-by-contract surface, lifted to the top level.
            'runner_receipt' => $stages['runner'] ?? null,
            'scan_id' => (string) data_get($stages, 'deep_scan.scan_id', ''),
            'selected_finding' => $selected,
            'spec_proposal_seed' => $stages['spec_proposal_seed'] ?? null,
            'sandbox_receipt' => $stages['sandbox'] ?? null,
            'dev_forge_execution_result' => $devForge['execution_result'] ?? null,
            'dev_forge_execution' => $devForge,
            'evidence_pack' => $result['evidence_pack'] ?? null,
            'inbox_item' => $result['inbox_item'] ?? null,
            'product_mode_event' => $result['product_mode_event'] ?? null,
            'branch_merge_governance' => $mergeGovernance['governance_report'] ?? null,
            'branch_review_packet' => data_get($stages, 'branch_review_packet.review_packet'),
            'tests' => $this->testsSummary($devForge),
            'stages' => $stages,
            'stage_order' => ['runner', 'deep_scan', 'selected_finding', 'spec_proposal_seed', 'sandbox', 'dev_forge_execution', 'runtime_result', 'branch_merge_governance', 'branch_review_packet'],
            'blockers' => $blockers,
            'next_operator_action' => $nextActions,
            'claim_policy' => $this->claimPolicy($mode, $stages, $input),
        ];
    }

    /**
     * @param  array<string,mixed>  $devForge
     * @return array<string,mixed>
     */
    private function testsSummary(array $devForge): array
    {
        $result = is_array($devForge['execution_result'] ?? null) ? $devForge['execution_result'] : [];
        $tests = AreaFocusStringListNormalizer::coercedStringValues($result['tests'] ?? []);
        $validation = AreaFocusStringListNormalizer::coercedStringValues($result['validation_commands'] ?? []);

        return [
            'declared_test_commands' => $tests,
            'validation_commands' => $validation,
            'test_count' => count($tests),
            'executed' => is_array($result['test_results'] ?? null) ? $result['test_results'] : [],
            'note' => $tests === [] && $validation === []
                ? 'No test command executed yet; tests run once the sandbox is materialized and AP-767 executes.'
                : 'Validation/test commands captured from the AP-767 execution_result.',
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $stages
     * @param  list<string>  $blockers
     */
    private function finalStatus(string $mode, array $stages, array $blockers): string
    {
        if ($blockers !== [] || ($stages['selected_finding']['status'] ?? '') === self::STAGE_BLOCKED) {
            return self::STATUS_BLOCKED;
        }

        $resultRan = ($stages['runtime_result']['status'] ?? '') === self::STAGE_RAN;
        if ($resultRan) {
            return self::STATUS_CYCLE_CLOSED;
        }

        return $mode === self::MODE_EXECUTE ? self::STATUS_EXECUTED_TO_GATE : self::STATUS_DRY_RUN_COMPLETE;
    }

    /**
     * @param  array<string,array<string,mixed>>  $stages
     * @param  array<string,mixed>  $finding
     * @return list<string>
     */
    private function nextOperatorAction(string $finalStatus, array $stages, array $finding): array
    {
        return match ($finalStatus) {
            self::STATUS_CYCLE_CLOSED => [
                'Review the evidence pack and inbox item, then accept/reject/defer via AP-731 (accept does NOT merge or deploy).',
                (string) data_get($stages, 'branch_review_packet.review_packet.operator_next_action', 'Review the AP-780 branch review packet before merge.'),
                (string) data_get($stages, 'branch_merge_governance.governance_report.next_actions.0', 'Review the AP-769 branch merge governance report before merge.'),
                (string) data_get($stages, 'runtime_result.acceptance_options.decision_command', 'Use the AP-731 evolution-decision command to record your decision.'),
            ],
            self::STATUS_EXECUTED_TO_GATE => [
                (string) data_get($stages, 'runtime_result.deferred_contract.command', 'Materialize the sandbox and run AP-767 execute to produce an execution_result.'),
                'Nothing was merged, deployed or pushed; review the AP-767 plan/result before advancing.',
            ],
            self::STATUS_DRY_RUN_COMPLETE => [
                'Dry-run only: review the selected finding, spec seed and simulated sandbox plan.',
                'Re-run with --mode=execute (optionally --materialize-sandbox + --run-local-task) to advance to the AP-767 owner runtime under governance.',
            ],
            default => $this->blockedNextActions(),
        };
    }

    /**
     * @return list<string>
     */
    private function blockedNextActions(): array
    {
        return [
            'Resolve the cycle blocker (most often: no small/safe finding in this scan).',
            'Re-run the deep scan or widen the focus, then start the cycle again.',
        ];
    }

    // ------------------------------------------------------------------
    // Persistence
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function finalize(array $payload, array $input): array
    {
        $payload['cycle_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));
        $payload['generated_at'] = AreaFocusUtcClock::atomNow();

        $record = (bool) ($input['record'] ?? false);
        if (! $record || ($payload['final_status'] ?? '') === self::STATUS_BLOCKED) {
            return $payload + ['cycle_storage_status' => $record ? 'not_recorded_when_blocked' : 'projected'];
        }

        $areaId = (string) $payload['area_id'];
        $path = $this->cycleFilePath($areaId);
        $existing = $this->findRecord($path, (string) $payload['cycle_id']);
        if ($existing !== null) {
            return $existing + ['cycle_storage_status' => 'existing'];
        }

        $recordPayload = ['schema_version' => self::RECORD_SCHEMA, 'recorded_at' => AreaFocusUtcClock::atomNow()] + $payload;
        AreaFocusAppendOnlyJsonlRecorder::append($path, $recordPayload);

        return $recordPayload + ['cycle_storage_status' => 'recorded'];
    }

    /**
     * List recorded cycles for an area (newest last).
     *
     * @return array<string,mixed>
     */
    public function listCycles(string $areaId): array
    {
        $path = $this->cycleFilePath($areaId);
        $summaries = [];
        foreach ($this->records($path) as $record) {
            $summaries[] = [
                'cycle_id' => (string) ($record['cycle_id'] ?? ''),
                'focus' => (string) ($record['focus'] ?? ''),
                'mode' => (string) ($record['mode'] ?? ''),
                'final_status' => (string) ($record['final_status'] ?? ''),
                'scan_id' => (string) ($record['scan_id'] ?? ''),
                'recorded_at' => (string) ($record['recorded_at'] ?? ''),
                'cycle_hash' => (string) ($record['cycle_hash'] ?? ''),
            ];
        }

        return [
            'schema_version' => self::RECEIPT_SCHEMA,
            'area_id' => $areaId,
            'cycle_count' => count($summaries),
            'cycles' => $summaries,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function replay(string $cycleId, ?string $areaId = null): ?array
    {
        $files = $areaId !== null && trim($areaId) !== ''
            ? [$this->cycleFilePath($areaId)]
            : AreaFocusStringListNormalizer::coercedStringValues(glob($this->storageDir().DIRECTORY_SEPARATOR.'*.jsonl'));
        foreach ($files as $file) {
            $found = $this->findRecord($file, $cycleId);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function records(string $path): array
    {
        return AreaFocusJsonlReader::rowsWithPresentKey($path, 'cycle_id');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findRecord(string $path, string $cycleId): ?array
    {
        if ($cycleId === '') {
            return null;
        }
        foreach ($this->records($path) as $record) {
            if ((string) ($record['cycle_id'] ?? '') === $cycleId) {
                return $record;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Mapping helpers
    // ------------------------------------------------------------------

    /**
     * Map a deep finding (AP-748 schema) into the shape AP-718 spec bridge expects.
     *
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function findingForBridge(array $finding): array
    {
        return [
            'schema_version' => (string) ($finding['schema_version'] ?? AreaFocusDeepFindingEngineService::FINDING_SCHEMA),
            'finding_hash' => (string) ($finding['finding_hash'] ?? ''),
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'area_id' => (string) ($finding['area_id'] ?? self::DEFAULT_AREA_ID),
            'title' => (string) ($finding['title'] ?? ''),
            'detail' => (string) ($finding['detail'] ?? ($finding['why_it_matters'] ?? '')),
            'severity' => (string) ($finding['severity'] ?? 'medium'),
            'risk_level' => (string) ($finding['severity'] ?? 'medium'),
            'finding_type' => (string) ($finding['origin_type'] ?? ($finding['kind'] ?? 'area_focus_finding')),
            'route_hint' => $this->ownerToRoute($this->ownerCandidate($finding)),
            'recommended_action' => (string) ($finding['proposed_next_action'] ?? 'Operator review required.'),
            'evidence_refs' => AreaFocusStringListNormalizer::coercedStringValues($finding['evidence_refs'] ?? []),
            'priority_score' => (int) ($finding['priority_score'] ?? 0),
        ];
    }

    /**
     * @param  array<string,mixed>  $finding
     */
    private function ownerCandidate(array $finding): string
    {
        return (string) ($finding['owner_candidate'] ?? '');
    }

    private function ownerToRoute(string $owner): string
    {
        return $owner === AreaFocusDeepFindingEngineService::OWNER_FORGE ? 'forge' : 'atlas_dev';
    }

    /**
     * AP-767 supports only atlas_dev | forge. Everything small/local routes to
     * Atlas Dev; only an explicit forge owner_candidate routes to Forge. An
     * explicit `owner` input override wins.
     *
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $input
     */
    private function owner(array $finding, array $input): string
    {
        $override = strtolower(trim((string) ($input['owner'] ?? '')));
        if (in_array($override, ['forge', 'atlas_forge'], true)) {
            return 'forge';
        }
        if (in_array($override, ['atlas_dev', 'dev'], true)) {
            return 'atlas_dev';
        }

        return $this->ownerCandidate($finding) === AreaFocusDeepFindingEngineService::OWNER_FORGE ? 'forge' : 'atlas_dev';
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return list<string>
     */
    private function allowedFiles(array $finding): array
    {
        $files = AreaFocusStringListNormalizer::coercedStringValues($finding['affected_files'] ?? []);
        if ($files === []) {
            // Docs are safe to scope when no code file is implicated.
            $files = AreaFocusStringListNormalizer::coercedStringValues($finding['affected_docs'] ?? []);
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($files);
    }

    /**
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    private function allowedPaths(array $allowedFiles): array
    {
        $paths = [];
        foreach ($allowedFiles as $file) {
            $dir = trim(dirname($file), '.');
            if ($dir !== '' && $dir !== '/') {
                $paths[] = rtrim($dir, '/').'/';
            }
        }
        if ($paths === []) {
            $paths = ['app/', 'docs/', 'tests/'];
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($paths);
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function findingSummary(array $finding): array
    {
        return [
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'finding_hash' => (string) ($finding['finding_hash'] ?? ''),
            'title' => (string) ($finding['title'] ?? ''),
            'kind' => (string) ($finding['kind'] ?? ''),
            'severity' => (string) ($finding['severity'] ?? ''),
            'owner_candidate' => (string) ($finding['owner_candidate'] ?? ''),
            'in_focus' => (bool) ($finding['in_focus'] ?? false),
            'affected_files' => AreaFocusStringListNormalizer::coercedStringValues($finding['affected_files'] ?? []),
            'affected_docs' => AreaFocusStringListNormalizer::coercedStringValues($finding['affected_docs'] ?? []),
        ];
    }

    // ------------------------------------------------------------------
    // Small primitives
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $detail
     * @return array<string,mixed>
     */
    private function stage(string $key, string $ap, string $status, string $note, array $detail): array
    {
        return ['stage' => $key, 'ap_contract' => $ap, 'status' => $status, 'note' => $note] + $detail;
    }

    /**
     * @param  array<string,array<string,mixed>>  $stages
     * @param  array<string,mixed>  $input
     * @return array<string,bool|string>
     */
    private function claimPolicy(string $mode, array $stages, array $input): array
    {
        $materialized = (string) data_get($stages, 'sandbox.sandbox_mode', 'simulated') === 'materialized';
        $devForgeRan = (string) data_get($stages, 'dev_forge_execution.bridge_status', '') === DevForgeRuntimeExecutionBridgeService::STATUS_EXECUTED;
        $realAtlasDevRan = (string) data_get($stages, 'dev_forge_execution.bridge_status', '') === 'atlas_dev_real_executed';
        $mergePerformed = (string) data_get($stages, 'branch_merge_governance.merge_status', '') === StewardshipBranchMergeGovernorService::STATUS_MERGED;

        return [
            'mode' => $mode,
            'orchestrator_only' => true,
            'composes_owners' => true,
            'reimplements_owner' => false,
            'creates_new_os' => false,
            'parallel_runtime_created' => false,
            'provider_invoked' => (bool) data_get($stages, 'dev_forge_execution.execution_result.provider_invoked', false),
            'branch_created' => $materialized,
            'worktree_created' => $materialized,
            'owner_command_executed_by_bridge' => $devForgeRan || $realAtlasDevRan,
            'mutates_main' => false,
            'merges' => $mergePerformed,
            'merge_governance_checked' => isset($stages['branch_merge_governance']),
            'auto_merge_default' => false,
            'auto_merge_strategy' => 'ff_only_when_ap769_policy_allows',
            'deploys' => false,
            'pushes_external' => false,
            'touches_secrets' => false,
            'destructive_change' => false,
            'auto_approval' => false,
            'auto_implementation' => $realAtlasDevRan,
            'records_cycle_when_requested' => (bool) ($input['record'] ?? false),
            'operator_review_required' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['generated_at'], $copy['cycle_hash'], $copy['recorded_at'], $copy['cycle_storage_status']);

        return $this->stripTimestamps($copy);
    }

    /**
     * @param  array<string|int,mixed>  $value
     * @return array<string|int,mixed>
     */
    private function stripTimestamps(array $value): array
    {
        foreach (['generated_at', 'recorded_at', 'created_at', 'acquired_at', 'expires_at', 'decided_at', 'next_allowed_at'] as $key) {
            unset($value[$key]);
        }
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->stripTimestamps($v);
            }
        }

        return $value;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function areaId(array $input): string
    {
        $value = trim((string) ($input['area_id'] ?? $input['area'] ?? ''));

        return $value !== '' ? AreaFocusSlugNormalizer::lowerFileToken($value, self::DEFAULT_AREA_ID) : self::DEFAULT_AREA_ID;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function focus(array $input): string
    {
        $value = trim((string) ($input['focus'] ?? ''));

        return $value !== '' ? $value : self::DEFAULT_FOCUS;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function portfolioId(array $input): string
    {
        $value = trim((string) ($input['portfolio_id'] ?? $input['portfolio'] ?? ''));

        return $value !== '' ? AreaFocusSlugNormalizer::lowerFileToken($value, self::DEFAULT_AREA_ID) : self::DEFAULT_PORTFOLIO_ID;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function mode(array $input): string
    {
        $value = strtolower(trim((string) ($input['mode'] ?? self::MODE_DRY_RUN)));

        return in_array($value, [self::MODE_EXECUTE, 'execute', 'run'], true) ? self::MODE_EXECUTE : self::MODE_DRY_RUN;
    }
}
