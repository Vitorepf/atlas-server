<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowRunner;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultProjector;
use App\Support\AtlasSecurity;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * AP-786 · Atlas-owned autonomous evolution session.
 *
 * This is the "real loop" operator asked for. It must operate through Atlas'
 * own software factory: Obra/Forge owner flow, SDD/TDD/BDD packets, quality
 * gates, repair loop, Evidence and governed merge. A provider driver with an
 * Atlas-shaped prompt is only a legacy diagnostic path and cannot be claimed as
 * Atlas Forge, Atlas Dev or autonomous factory execution.
 */
final class AutonomousEvolutionSessionService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.autonomous_evolution_session.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.autonomous_evolution_session_record.v1';

    public const STATUS_DRY_RUN = 'dry_run_planned';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    public const DEFAULT_FOCUS = 'dev_forge';

    public const SCOPE_BALANCED = 'balanced';

    public const SCOPE_FACTORY_MAX = 'factory_max';

    public const FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID = 'factory_max_ap790_candidate_starvation_recovery';

    /** @var list<string> */
    private const STARVATION_META_REJECTION_REASONS = [
        'terminal_locked_existing_failure',
        'terminal_unlock_candidate_locked',
    ];

    private const FORBIDDEN_PATHS = ['.env', 'storage/secrets', 'config/secrets', 'vendor/', 'node_modules/'];

    /** @var list<string> */
    private const FACTORY_MAX_RUNTIME_PREFIXES = [
        'app/Services/Ai/AgenticEngineeringOs/',
        'app/Services/Ai/AtlasDecide/',
        'app/Services/Ai/AgenticWorkcell/',
        'app/Services/Ai/AtlasForge/',
        'app/Services/Ai/Cartography/',
        'app/Services/Ai/Cognition/',
        'app/Services/Ai/Compounding/',
        'app/Services/Ai/Context/',
        'app/Services/Ai/LongHorizon/',
        'app/Services/Ai/Programming/',
        'app/Services/Ai/ProgrammingRuntime/',
        'app/Services/Ai/Product/',
        'app/Services/Ai/Provider/',
        'app/Services/Ai/Reality/',
        'app/Services/Ai/RealitySandbox/',
        'app/Services/Ai/StrategicReality/',
        'app/Services/Ai/VerifiedExecution/',
        'app/Services/Ai/VerifiedContextExecution/',
        'app/Services/Ai/Kernel/',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/',
        'app/Services/Ai/SoftwareCompanyStewardship/ProductMode/',
        'app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/',
    ];

    /** @var list<string> */
    private const FACTORY_MAX_SAFE_STRUCTURAL_ORIGIN_TYPES = [
        'missing_test',
    ];

    /** @var list<string> */
    private const FACTORY_MAX_STEWARDSHIP_FILES = [
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerService.php',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeRouterService.php',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchMergeGovernorService.php',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeAutonomyPolicyService.php',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php',
    ];

    /** @var list<string> */
    private const FACTORY_MAX_REJECTED_ORIGIN_TYPES = [
        'docs_stale',
        'focus_owner_doc_missing',
        'missing_evidence',
    ];

    private const FACTORY_MAX_MAINTENANCE_STREAK_LIMIT = 4;

    /**
     * Blockers that mean the cycle spent provider or merge budget without a
     * shippable result. Used to skip repeat selection and downstream work.
     *
     * @var list<string>
     */
    private const WASTED_CYCLE_BLOCKERS = [
        'provider_produced_no_changes',
        'provider_not_called',
        'provider_scope_violation',
        'validation_failed',
        'commit_failed',
        'owner_runtime_no_patch_needed',
        'owner_runtime_no_patch_needed_without_proof',
        'owner_runtime_senior_loop_execution_not_passed',
        'owner_runtime_routing_not_executable',
        'owner_runtime_scope_violation',
        'branch_already_merged_or_ancestor_of_base',
    ];

    /** @var list<string> */
    private const REQUIRED_FULL_OWNER_FLOW_APS = [
        'AP-747',
        'AP-756',
        'AP-757',
        'AP-749',
        'AP-758',
        'AP-759',
        'AP-750',
    ];

    /** @var list<string> */
    private const REQUIRED_ROBUST_FLOW_CAPABILITIES = [
        'native_obra_or_work_packet',
        'self_directed_spec_or_sdd_packet',
        'tdd_test_contract',
        'bdd_acceptance_contract',
        'atlas_decide_provider_topology',
        'aawr_or_multi_agent_workcell',
        'universal_gates_and_programming_governance',
        'deterministic_validation_suite',
        'repair_loop_with_failed_gate_capsule',
        'evidence_ledger_and_decision_receipts',
        'replay_or_reproduction_packet',
        'ap769_ap774_merge_governance',
    ];

    private ?string $storageDirOverride = null;

    public function __construct(
        private readonly AreaFocusDeepFindingEngineService $deepScan,
        private readonly StewardshipPriorityRanker $priorityEngine,
        private readonly AreaFocusBranchSandboxMaterializer $materializer,
        private readonly AtlasForgeProviderInvocationDriverRouter $providerRouter,
        private readonly StewardshipRuntimeResultProjector $resultBridge,
        private readonly StewardshipBranchMergeGovernor $mergeGovernor,
        private readonly Ap786RobustForgeQualityContractService $robustContract,
        private readonly Ap786OwnerFlowRunner $ownerFlow,
    ) {}

    private ?AutonomousLoopReceiptIntegrityService $loopReceiptIntegrity = null;

    private ?AreaFocusCandidateQuarantineService $candidateQuarantine = null;

    /** AP-791 loop inbox/merge/receipt integrity (pure; lazily constructed). */
    private function loopReceiptIntegrity(): AutonomousLoopReceiptIntegrityService
    {
        return $this->loopReceiptIntegrity ??= new AutonomousLoopReceiptIntegrityService();
    }

    public function setCandidateQuarantineForTesting(?AreaFocusCandidateQuarantineService $service): void
    {
        $this->candidateQuarantine = $service;
    }

    private function quarantine(): AreaFocusCandidateQuarantineService
    {
        return $this->candidateQuarantine ??= app(AreaFocusCandidateQuarantineService::class);
    }

    public function setStorageDirForTesting(?string $path): void
    {
        $this->storageDirOverride = $path;
    }

    public function storageDir(): string
    {
        if ($this->storageDirOverride !== null) {
            return $this->storageDirOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/autonomous_evolution_sessions')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/autonomous_evolution_sessions';
    }

    public function recordPath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input = []): array
    {
        $areaId = $this->slug((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID));
        $focus = trim((string) ($input['focus'] ?? self::DEFAULT_FOCUS)) ?: self::DEFAULT_FOCUS;
        $execute = (bool) ($input['execute'] ?? false);
        $record = (bool) ($input['record'] ?? false);
        $cyclesRequested = max(1, min(12, (int) ($input['cycles'] ?? 1)));
        $provider = trim((string) ($input['provider'] ?? 'cursor_cli')) ?: 'cursor_cli';
        $model = trim((string) ($input['model'] ?? (config('atlas.ai.providers.cursor_cli.model') ?: 'composer-2.5-fast'))) ?: 'composer-2.5-fast';
        $scopeProfile = $this->scopeProfile((string) ($input['scope_profile'] ?? self::SCOPE_BALANCED));
        $repoRoot = $this->repoRoot((string) ($input['repo_root'] ?? ''));
        $actor = trim((string) ($input['actor'] ?? 'operator')) ?: 'operator';
        $continueOnBlocked = (bool) ($input['continue_on_blocked'] ?? false);

        $sessionId = 'aess_'.substr(MissionCanonicalHash::sha256([
            'AP-786',
            $areaId,
            $focus,
            $cyclesRequested,
            $provider,
            $model,
            $this->now(),
        ]), 0, 18);

        $cycles = [];
        $blockers = [];
        $sessionReviewLocked = $this->normalizeReviewLocked($input['session_review_locked'] ?? []);
        $sessionTerminalLocked = $this->normalizeReviewLocked($input['session_terminal_locked'] ?? []);
        $seenLoopTitles = [];

        for ($index = 0; $index < $cyclesRequested; $index++) {
            $cycle = $this->runCycle($sessionId, $index + 1, [
                'area_id' => $areaId,
                'focus' => $focus,
                'execute' => $execute,
                'provider' => $provider,
                'model' => $model,
                'scope_profile' => $scopeProfile,
                'repo_root' => $repoRoot,
                'actor' => $actor,
                'auto_merge' => (bool) ($input['auto_merge'] ?? false),
                'allow_code_auto_merge' => (bool) ($input['allow_code_auto_merge'] ?? false),
                'pull_main' => (bool) ($input['pull_main'] ?? false),
                'max_findings' => (int) ($input['max_findings'] ?? 40),
                'max_auto_merge_files' => (int) ($input['max_auto_merge_files'] ?? 5),
                'validation_commands' => $this->validationCommands($input),
                'continue_on_blocked' => $continueOnBlocked,
                'session_review_locked' => $sessionReviewLocked,
                'session_terminal_locked' => $sessionTerminalLocked,
                'allow_direct_provider_driver' => (bool) ($input['allow_direct_provider_driver'] ?? false),
                'forge_inputs' => $this->forgeInputs($input),
            ]);

            // AP-791: every cycle — completed/planned/blocked/failed/skipped — carries
            // an auditable loop receipt with pre/post inbox, merge decision, replay
            // command and next_action; duplicate titles across cycles are warned.
            $cycle = $this->loopReceiptIntegrity()->attach($cycle, [
                'session_id' => $sessionId,
                'area_id' => $areaId,
                'focus' => $focus,
                'seen_titles' => $seenLoopTitles,
            ]);
            foreach ($this->loopReceiptIntegrity()->titlesOf($cycle) as $title) {
                $seenLoopTitles[$title] = ($seenLoopTitles[$title] ?? 0) + 1;
            }

            $cycles[] = $cycle;
            if ($execute) {
                foreach ($this->findingKeys((array) ($cycle['selected_finding'] ?? [])) as $key) {
                    $sessionReviewLocked[$key] = true;
                }
            }
            if (($cycle['continue_loop'] ?? false) !== true) {
                $cycleBlockers = array_values((array) ($cycle['blockers'] ?? []));
                $blockers = array_merge($blockers, $cycleBlockers);
                if (($cycle['stop_session_after_blocker'] ?? false) === true
                    || ! $continueOnBlocked
                    || $this->shouldStopSessionAfterBlockedCycle($cycleBlockers)) {
                    break;
                }
            }
        }

        $status = $execute ? self::STATUS_COMPLETED : self::STATUS_DRY_RUN;
        if ($blockers !== []) {
            $status = count($cycles) > 0 ? self::STATUS_PARTIAL : self::STATUS_BLOCKED;
        }

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-786',
            'status' => $status,
            'session_id' => $sessionId,
            'area_id' => $areaId,
            'focus' => $focus,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-747', 'AP-748', 'AP-749', 'AP-750', 'AP-756', 'AP-757', 'AP-758', 'AP-759', 'AP-765', 'AP-769', 'AP-774', 'AP-785', 'AP-786'],
            'provider' => $provider,
            'model' => $model,
            'scope_profile' => $scopeProfile,
            'execute_requested' => $execute,
            'record_requested' => $record,
            'cycles_requested' => $cyclesRequested,
            'cycles_completed' => count(array_filter($cycles, static fn (array $c): bool => (string) ($c['final_status'] ?? '') === 'cycle_completed')),
            'cycles_waiting_review' => count(array_filter($cycles, static fn (array $c): bool => (string) ($c['final_status'] ?? '') === 'cycle_completed_waiting_review_or_merge')),
            'cycles_attempted' => count($cycles),
            'cycles' => $cycles,
            'blockers' => array_values(array_unique($blockers)),
            'next_actions' => $this->nextActions($status, $blockers),
            'claim_policy' => [
                'atlas_owned_flow' => true,
                'uses_cursor_cli_account_driver' => $provider === 'cursor_cli',
                'requires_full_atlas_forge_owner_flow' => true,
                'requires_robust_obra_forge_quality_flow' => true,
                'direct_provider_driver_allowed' => (bool) ($input['allow_direct_provider_driver'] ?? false),
                'required_robust_flow_capabilities' => self::REQUIRED_ROBUST_FLOW_CAPABILITIES,
                'provider_called' => $this->anyCycleFlag($cycles, 'provider_called'),
                'branch_created' => $this->anyCycleFlag($cycles, 'branch_created'),
                'worktree_created' => $this->anyCycleFlag($cycles, 'worktree_created'),
                'inbox_emitted_before_merge_attempt' => true,
                'merge_performed' => $this->anyCycleFlag($cycles, 'merge_performed'),
                'merge_policy' => 'AP-769/AP-774 ff-only only',
                'blocked_cycle_policy' => $continueOnBlocked ? 'record_inbox_keep_branch_isolated_and_continue' : 'stop_session_on_first_blocker',
                'selection_scope' => $this->selectionScopeClaim($scopeProfile),
                'deploy_performed' => false,
                'external_push_performed' => false,
                'secret_access' => false,
            ],
        ];
        $payload['session_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = $this->now();

        return $record ? $this->record($areaId, $payload) : $payload + ['session_storage_status' => 'projected'];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function runCycle(string $sessionId, int $cycleIndex, array $input): array
    {
        $areaId = (string) $input['area_id'];
        $focus = (string) $input['focus'];
        $execute = (bool) $input['execute'];
        $repoRoot = (string) $input['repo_root'];
        $scopeProfile = (string) ($input['scope_profile'] ?? self::SCOPE_BALANCED);
        $cycleId = 'aesc_'.substr(MissionCanonicalHash::sha256([$sessionId, $cycleIndex, $this->now()]), 0, 18);

        $scan = $this->deepScan->scan([
            'area_id' => $areaId,
            'focus' => $focus,
            'max_findings' => (int) $input['max_findings'],
        ]);
        $selection = $this->selectCandidate(
            $areaId,
            $focus,
            $scan,
            $repoRoot,
            $scopeProfile,
            (array) ($input['session_review_locked'] ?? []),
            $this->forgeInputs($input),
            (array) ($input['session_terminal_locked'] ?? []),
        );
        $finding = $selection['finding'];
        if ($finding === null && is_array($selection['selection_refill'] ?? null)
            && (string) ($selection['selection_refill']['strategy'] ?? '') === 'ap790_candidate_starvation_recovery'
            && ! in_array('terminal_locked_existing_failure', array_column((array) ($selection['selection_rejections'] ?? []), 'reason'), true)) {
            $finding = $this->factoryMaxStarvationRecoveryCandidate(
                $this->starvationExhaustionRejections($selection['selection_rejections'] ?? []),
            );
        }
        if ($finding === null && is_array($selection['selection_refill'] ?? null)
            && (string) ($selection['selection_refill']['strategy'] ?? '') === 'ap790_candidate_starvation_recovery'
            && in_array('terminal_locked_existing_failure', array_column((array) ($selection['selection_rejections'] ?? []), 'reason'), true)) {
            $terminalUnlockCandidates = $this->factoryMaxTerminalBacklogUnlockCandidates(
                $this->starvationExhaustionRejections($selection['selection_rejections'] ?? []),
            );
            $finding = $terminalUnlockCandidates[0] ?? null;
            if ($finding !== null) {
                $selection['selection_refill'] = (array) $selection['selection_refill'] + [
                    'terminal_unlock_strategy' => 'ap790_terminal_backlog_unlock',
                    'terminal_ladder_fully_exhausted_replenishment' => true,
                ];
            }
        }
        if ($finding === null) {
            return $this->blockedCycle($cycleId, $cycleIndex, ['no_candidate_with_allowed_files'], [
                'scan' => $scan,
                'priority_report' => $selection['priority_report'],
                'scope_profile' => $scopeProfile,
                'selection_rejections' => $selection['selection_rejections'] ?? [],
                'selection_refill' => $selection['selection_refill'] ?? null,
            ]);
        }

        $allowedFiles = $this->allowedFiles($finding);
        $owner = $this->owner($finding);
        $class = $this->autoMergeClass($finding, $allowedFiles);

        if (! $execute) {
            return [
                'cycle_id' => $cycleId,
                'cycle_index' => $cycleIndex,
                'final_status' => 'dry_run_planned',
                'selected_finding' => $this->findingSummary($finding),
                'allowed_files' => $allowedFiles,
                'owner' => $owner,
                'auto_merge_class' => $class,
                'scope_profile' => $scopeProfile,
                'priority_report' => $selection['priority_report'],
                'selection_rejections' => $selection['selection_rejections'] ?? [],
                'selection_refill' => $selection['selection_refill'] ?? null,
                'continue_loop' => false,
                'blockers' => [],
            ];
        }

        if (! $this->findingAllowsAutonomousExecution($finding)) {
            return $this->blockedCycle($cycleId, $cycleIndex, ['auto_execution_not_allowed'], [
                'selected_finding' => $this->findingSummary($finding),
                'priority_report' => $selection['priority_report'],
                'scope_profile' => $scopeProfile,
                'selection_rejections' => $selection['selection_rejections'] ?? [],
                'provider_skipped' => true,
                'sandbox_skipped' => true,
            ]);
        }

        $reviewLocked = $this->reviewLockedFindingKeys($areaId, $repoRoot) + $this->normalizeReviewLocked($input['session_review_locked'] ?? []);
        if (! $this->isFactoryMaxStarvationRecoveryFinding($finding)
            && ! $this->isFactoryMaxTerminalBacklogUnlockFinding($finding)
            && $this->findingIsReviewLocked($finding, $reviewLocked)) {
            return $this->blockedCycle($cycleId, $cycleIndex, ['review_locked_existing_branch'], [
                'selected_finding' => $this->findingSummary($finding),
                'priority_report' => $selection['priority_report'],
                'scope_profile' => $scopeProfile,
                'selection_rejections' => $selection['selection_rejections'] ?? [],
                'provider_skipped' => true,
                'sandbox_skipped' => true,
            ]);
        }

        $allowDirect = (bool) ($input['allow_direct_provider_driver'] ?? false);
        $flowIntegrityGate = $this->flowIntegrityGate($owner, $allowDirect);
        $robustFlowContract = $allowDirect
            ? $this->diagnosticRobustFlowContractSkipped($finding, $allowedFiles, $owner)
            : $this->robustFlowContract($areaId, $focus, $finding, $allowedFiles, $owner, $this->ownerValidationCommands((array) $input['validation_commands'], $finding, $allowedFiles));

        if (! $allowDirect && (string) ($robustFlowContract['status'] ?? '') !== Ap786RobustForgeQualityContractService::STATUS_READY) {
            return $this->blockedCycle($cycleId, $cycleIndex, array_values((array) ($robustFlowContract['blockers'] ?? ['robust_flow_contract_blocked'])), [
                'selected_finding' => $this->findingSummary($finding),
                'priority_report' => $selection['priority_report'],
                'scope_profile' => $scopeProfile,
                'selection_rejections' => $selection['selection_rejections'] ?? [],
                'flow_integrity_gate' => $flowIntegrityGate,
                'robust_flow_contract' => $robustFlowContract,
                'provider_skipped' => true,
                'sandbox_skipped' => true,
                'merge_skipped' => true,
                'result_bridge_skipped' => true,
            ]);
        }

        $preflight = $this->buildPreflight($areaId, $finding, $allowedFiles, $owner, $cycleId);
        $sandbox = $this->materializeSandbox($preflight, $areaId, $repoRoot);
        if (($sandbox['status'] ?? '') !== AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED) {
            return $this->blockedCycle($cycleId, $cycleIndex, ['sandbox_materialization_failed'], [
                'selected_finding' => $this->findingSummary($finding),
                'priority_report' => $selection['priority_report'],
                'scope_profile' => $scopeProfile,
                'selection_rejections' => $selection['selection_rejections'] ?? [],
                'flow_integrity_gate' => $flowIntegrityGate,
                'robust_flow_contract' => $robustFlowContract,
                'sandbox' => $sandbox,
            ]);
        }

        $worktree = (string) data_get($sandbox, 'materialization.worktree_path', '');
        $branch = (string) data_get($sandbox, 'materialization.branch_name', '');

        // Default path: the REAL Atlas owner-runtime chain (AP-747 -> AP-756 ->
        // AP-757 -> AP-749 -> AP-758 -> AP-759 -> AP-750). The direct provider
        // driver is a legacy diagnostic path only and requires an explicit
        // opt-in; it must never be claimed as Atlas Forge/Dev execution.
        if (! $allowDirect) {
            return $this->runOwnerFlowCycle($cycleId, $cycleIndex, $input, $finding, $selection, $scopeProfile, $owner, $allowedFiles, $class, $preflight, $sandbox, $worktree, $branch, $flowIntegrityGate, $robustFlowContract);
        }

        $decision = $this->decisionReceipt($cycleId, $finding, $allowedFiles, $owner);
        $providerResult = $this->invokeProvider($input, $decision, $finding, $allowedFiles, $worktree);
        $postProviderSkip = $this->postProviderSkipReason($providerResult, $worktree, $allowedFiles);
        if ($postProviderSkip !== null) {
            return $this->blockedCycle($cycleId, $cycleIndex, $postProviderSkip['blockers'], [
                'selected_finding' => $this->findingSummary($finding),
                'priority_report' => $selection['priority_report'],
                'scope_profile' => $scopeProfile,
                'selection_rejections' => $selection['selection_rejections'] ?? [],
                'sandbox' => $sandbox,
                'robust_flow_contract' => $robustFlowContract,
                'provider_result' => $this->providerSummary($providerResult),
                'post_provider_skip' => $postProviderSkip['reason'],
                'unsafe_files' => $postProviderSkip['unsafe_files'] ?? [],
                'validation_skipped' => true,
                'merge_skipped' => true,
            ]);
        }

        $validation = $this->runValidationWithRepair(
            (array) $input['validation_commands'],
            $worktree,
            $allowedFiles,
            $finding,
        );
        if (($validation['passed'] ?? null) === false) {
            return $this->governCycleOutcome($this->blockedCycle($cycleId, $cycleIndex, ['validation_failed'], [
                'selected_finding' => $this->findingSummary($finding),
                'priority_report' => $selection['priority_report'],
                'scope_profile' => $scopeProfile,
                'selection_rejections' => $selection['selection_rejections'] ?? [],
                'sandbox' => $sandbox,
                'robust_flow_contract' => $robustFlowContract,
                'provider_called' => (bool) ($providerResult['provider_called'] ?? false),
                'provider_result' => $this->providerSummary($providerResult),
                'validation' => $validation,
                'changed_files' => $this->changedFiles($worktree),
                'post_execution_skip' => 'validation_failed',
                'commit_skipped' => true,
                'branch_created' => true,
                'worktree_created' => true,
                'merge_skipped' => true,
                'result_bridge_skipped' => true,
            ]), $areaId, $focus, $finding, $allowedFiles, $owner, $branch, $worktree, true);
        }

        $commit = $this->commitSandbox($worktree, $allowedFiles, $finding);
        $changedFiles = $this->changedFiles($worktree);
        $postExecutionSkip = $this->postExecutionSkipReason($commit);
        if ($postExecutionSkip !== null) {
            return $this->blockedCycle($cycleId, $cycleIndex, $postExecutionSkip['blockers'], [
                'selected_finding' => $this->findingSummary($finding),
                'priority_report' => $selection['priority_report'],
                'scope_profile' => $scopeProfile,
                'selection_rejections' => $selection['selection_rejections'] ?? [],
                'sandbox' => $sandbox,
                'robust_flow_contract' => $robustFlowContract,
                'provider_called' => (bool) ($providerResult['provider_called'] ?? false),
                'provider_result' => $this->providerSummary($providerResult),
                'validation' => $validation,
                'commit' => $commit,
                'changed_files' => $changedFiles,
                'post_execution_skip' => $postExecutionSkip['reason'],
                'unsafe_files' => $postExecutionSkip['unsafe_files'] ?? [],
                'branch_created' => true,
                'worktree_created' => true,
                'merge_skipped' => true,
                'result_bridge_skipped' => true,
            ]);
        }

        $executionResult = $this->executionResult($cycleId, $areaId, $owner, $finding, $sandbox, $providerResult, $validation, $commit, $changedFiles);
        $resultBridge = $this->resultBridge->project([
            'area_id' => $areaId,
            'portfolio_id' => 'atlas_software_company',
            'owner' => $owner,
            'actor' => (string) $input['actor'],
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'spec_id' => (string) data_get($finding, 'spec_seed.candidate_id', ''),
            'execution_result' => $executionResult,
            'emit_inbox' => true,
            'record_evidence' => true,
            'record_event' => true,
            'record_cycle' => true,
        ]);

        $merge = $this->mergeGovernor->evaluate([
            'area_id' => $areaId,
            'repo_root' => $repoRoot,
            'base_ref' => 'main',
            'branch_ref' => $branch,
            'worktree_path' => $worktree,
            'auto_merge' => (bool) $input['auto_merge'],
            'execute_merge' => (bool) $input['auto_merge'],
            'auto_merge_class' => $class,
            'allow_code_auto_merge' => (bool) $input['allow_code_auto_merge'],
            'max_auto_merge_files' => (int) $input['max_auto_merge_files'],
            'run_validation' => true,
            'test_commands' => (array) $input['validation_commands'],
            'record_governance' => true,
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'spec_id' => (string) data_get($finding, 'spec_seed.candidate_id', ''),
            'sandbox_id' => (string) ($sandbox['sandbox_id'] ?? ''),
        ]);
        $pull = ((bool) $input['pull_main'] && ($merge['status'] ?? '') === StewardshipBranchMergeGovernorService::STATUS_MERGED)
            ? $this->pullMain($repoRoot)
            : ['status' => 'not_requested_or_not_merged'];

        $merged = ($merge['status'] ?? '') === StewardshipBranchMergeGovernorService::STATUS_MERGED;

        $cycle = [
            'cycle_id' => $cycleId,
            'cycle_index' => $cycleIndex,
            'final_status' => $merged ? 'cycle_completed' : 'cycle_completed_waiting_review_or_merge',
            'selected_finding' => $this->findingSummary($finding),
            'priority_report' => $selection['priority_report'],
            'scope_profile' => $scopeProfile,
            'selection_rejections' => $selection['selection_rejections'] ?? [],
            'owner' => $owner,
            'allowed_files' => $allowedFiles,
            'sandbox_id' => (string) ($sandbox['sandbox_id'] ?? ''),
            'branch_ref' => $branch,
            'worktree_path' => $worktree,
            'provider_called' => (bool) ($providerResult['provider_called'] ?? false),
            'provider_result' => $this->providerSummary($providerResult),
            'validation' => $validation,
            'commit' => $commit,
            'changed_files' => $changedFiles,
            'result_bridge_id' => (string) ($resultBridge['result_bridge_id'] ?? ''),
            'inbox_item_id' => $resultBridge['inbox_item_id'] ?? null,
            'inbox_emitted_before_merge_attempt' => true,
            'merge_governance' => $merge,
            'pull_main' => $pull,
            'branch_created' => true,
            'worktree_created' => true,
            'merge_performed' => $merged,
            'continue_loop' => $merged,
            'blockers' => $merged ? [] : array_values((array) ($merge['blockers'] ?? ['merge_not_performed'])),
        ];
        if (($validation['repair']['retried'] ?? false) === true) {
            $cycle['retried'] = true;
        }

        return $cycle;
    }

    /**
     * @param  array<string,mixed>  $scan
     * @return array{finding:array<string,mixed>|null,priority_report:array<string,mixed>,selection_rejections:list<array<string,string>>,selection_refill:array<string,mixed>|null}
     */
    private function selectCandidate(string $areaId, string $focus, array $scan, string $repoRoot, string $scopeProfile, array $sessionReviewLocked = [], array $forgeInputs = [], array $sessionTerminalLocked = []): array
    {
        $findings = array_values(array_filter((array) ($scan['findings'] ?? []), 'is_array'));
        $maintenanceBudgetExhausted = $scopeProfile === self::SCOPE_FACTORY_MAX
            && $this->recentFactoryMaintenanceCycleCount($areaId) >= self::FACTORY_MAX_MAINTENANCE_STREAK_LIMIT;
        $reviewLocked = $this->reviewLockedFindingKeys($areaId, $repoRoot)
            + $this->quarantine()->quarantinedFindingKeys($areaId, $focus)
            + $this->normalizeReviewLocked($sessionReviewLocked);
        $terminalLocked = $this->normalizeReviewLocked($sessionTerminalLocked);
        $candidates = [];
        $candidateKeys = [];
        $rejections = [];
        foreach ($findings as $finding) {
            $finding = $this->promoteSafeFactoryFinding($finding, $scopeProfile);
            $allowedFiles = $this->allowedFiles($finding);
            $rejection = $this->candidateRejectionReason($finding, $allowedFiles, $reviewLocked, $scopeProfile, $areaId, $focus, $forgeInputs, $maintenanceBudgetExhausted, $terminalLocked);
            if ($rejection !== '') {
                $rejections[] = [
                    'finding_id' => (string) ($finding['finding_id'] ?? ''),
                    'title' => (string) ($finding['title'] ?? ''),
                    'reason' => $rejection,
                ];
                continue;
            }
            foreach ($this->findingKeys($finding) as $key) {
                $candidateKeys[$key] = true;
            }
            $candidates[] = $finding;
        }
        if ($scopeProfile === self::SCOPE_FACTORY_MAX) {
            foreach ($this->factoryMaxSeedCandidates() as $finding) {
                $finding = $this->promoteSafeFactoryFinding($finding, $scopeProfile);
                if ($this->findingIsReviewLocked($finding, $candidateKeys)) {
                    $rejections[] = [
                        'finding_id' => (string) ($finding['finding_id'] ?? ''),
                        'title' => (string) ($finding['title'] ?? ''),
                        'reason' => 'duplicate_candidate_key_in_pass',
                    ];
                    continue;
                }
                $allowedFiles = $this->allowedFiles($finding);
                $rejection = $this->candidateRejectionReason($finding, $allowedFiles, $reviewLocked, $scopeProfile, $areaId, $focus, $forgeInputs, $maintenanceBudgetExhausted, $terminalLocked);
                if ($rejection !== '') {
                    $rejections[] = [
                        'finding_id' => (string) ($finding['finding_id'] ?? ''),
                        'title' => (string) ($finding['title'] ?? ''),
                        'reason' => $rejection,
                    ];
                    continue;
                }
                foreach ($this->findingKeys($finding) as $key) {
                    $candidateKeys[$key] = true;
                }
                $candidates[] = $finding;
            }
        }
        $priority = $this->priorityEngine->rank([
            'area_id' => $areaId,
            'focus' => self::DEFAULT_FOCUS,
            'candidates' => $candidates,
            'scope_profile' => $scopeProfile,
            'has_live_forge_authority' => $this->hasLiveForgeAuthority($forgeInputs),
        ]);
        if ($candidates === [] && $scopeProfile === self::SCOPE_FACTORY_MAX) {
            foreach ($this->factoryMaxPriorityBacklogCandidates($priority) as $finding) {
                $finding = $this->promoteSafeFactoryFinding($finding, $scopeProfile);
                if ($this->findingIsReviewLocked($finding, $candidateKeys)) {
                    $rejections[] = [
                        'finding_id' => (string) ($finding['finding_id'] ?? ''),
                        'title' => (string) ($finding['title'] ?? ''),
                        'reason' => 'duplicate_candidate_key_in_pass',
                    ];
                    continue;
                }
                $allowedFiles = $this->allowedFiles($finding);
                $rejection = $this->candidateRejectionReason($finding, $allowedFiles, $reviewLocked, $scopeProfile, $areaId, $focus, $forgeInputs, $maintenanceBudgetExhausted, $terminalLocked);
                if ($rejection !== '') {
                    $rejections[] = [
                        'finding_id' => (string) ($finding['finding_id'] ?? ''),
                        'title' => (string) ($finding['title'] ?? ''),
                        'reason' => $rejection,
                    ];
                    continue;
                }
                foreach ($this->findingKeys($finding) as $key) {
                    $candidateKeys[$key] = true;
                }
                $candidates[] = $finding;
                break;
            }

            if ($candidates !== []) {
                $priority = $this->priorityEngine->rank([
                    'area_id' => $areaId,
                    'focus' => self::DEFAULT_FOCUS,
                    'candidates' => $candidates,
                    'scope_profile' => $scopeProfile,
                    'has_live_forge_authority' => $this->hasLiveForgeAuthority($forgeInputs),
                ]);
            }
        }
        $selectionRefill = null;
        if ($candidates === [] && $scopeProfile === self::SCOPE_FACTORY_MAX) {
            if ($rejections === []) {
                $rejections[] = [
                    'finding_id' => '',
                    'title' => 'factory_max_no_executable_candidates',
                    'reason' => 'no_executable_candidates_after_selection_pass',
                ];
            }
            $exhaustionRejections = $this->starvationExhaustionRejections($rejections);
            $candidate = $this->factoryMaxStarvationRecoveryCandidate($exhaustionRejections);
            $selectionRefill = $this->factoryMaxSelectionRefillReceipt($exhaustionRejections);
            if ($this->findingIsReviewLocked($candidate, $terminalLocked)) {
                $rejections[] = [
                    'finding_id' => (string) ($candidate['finding_id'] ?? ''),
                    'title' => (string) ($candidate['title'] ?? ''),
                    'reason' => 'terminal_locked_existing_failure',
                ];

                $terminalUnlockCandidates = $this->factoryMaxTerminalBacklogUnlockCandidates($exhaustionRejections);
                foreach ($terminalUnlockCandidates as $unlockCandidate) {
                    if ($this->findingIsReviewLocked($unlockCandidate, $reviewLocked + $terminalLocked + $candidateKeys)) {
                        $rejections[] = [
                            'finding_id' => (string) ($unlockCandidate['finding_id'] ?? ''),
                            'title' => (string) ($unlockCandidate['title'] ?? ''),
                            'reason' => 'terminal_unlock_candidate_locked',
                        ];
                        continue;
                    }

                    $priority = $this->priorityEngine->rank([
                        'area_id' => $areaId,
                        'focus' => self::DEFAULT_FOCUS,
                        'candidates' => [$unlockCandidate],
                        'scope_profile' => $scopeProfile,
                        'has_live_forge_authority' => $this->hasLiveForgeAuthority($forgeInputs),
                    ]);

                    return [
                        'finding' => $unlockCandidate,
                        'priority_report' => $priority,
                        'selection_rejections' => $rejections,
                        'selection_refill' => $selectionRefill + [
                            'terminal_unlock_strategy' => 'ap790_terminal_backlog_unlock',
                        ],
                    ];
                }

                foreach ($terminalUnlockCandidates as $unlockCandidate) {
                    if ($this->findingIsReviewLocked($unlockCandidate, $terminalLocked + $candidateKeys)) {
                        continue;
                    }

                    $priority = $this->priorityEngine->rank([
                        'area_id' => $areaId,
                        'focus' => self::DEFAULT_FOCUS,
                        'candidates' => [$unlockCandidate],
                        'scope_profile' => $scopeProfile,
                        'has_live_forge_authority' => $this->hasLiveForgeAuthority($forgeInputs),
                    ]);

                    return [
                        'finding' => $unlockCandidate,
                        'priority_report' => $priority,
                        'selection_rejections' => $rejections,
                        'selection_refill' => $selectionRefill + [
                            'terminal_unlock_strategy' => 'ap790_terminal_backlog_unlock',
                            'terminal_ladder_exhausted_replenishment' => true,
                        ],
                    ];
                }

                $unlockCandidate = $terminalUnlockCandidates[0] ?? null;
                if (is_array($unlockCandidate)) {
                    $priority = $this->priorityEngine->rank([
                        'area_id' => $areaId,
                        'focus' => self::DEFAULT_FOCUS,
                        'candidates' => [$unlockCandidate],
                        'scope_profile' => $scopeProfile,
                        'has_live_forge_authority' => $this->hasLiveForgeAuthority($forgeInputs),
                    ]);

                    return [
                        'finding' => $unlockCandidate,
                        'priority_report' => $priority,
                        'selection_rejections' => $rejections,
                        'selection_refill' => $selectionRefill + [
                            'terminal_unlock_strategy' => 'ap790_terminal_backlog_unlock',
                            'terminal_ladder_fully_exhausted_replenishment' => true,
                        ],
                    ];
                }

                return [
                    'finding' => null,
                    'priority_report' => $priority,
                    'selection_rejections' => $rejections,
                    'selection_refill' => $selectionRefill,
                ];
            }
            $priority = $this->priorityEngine->rank([
                'area_id' => $areaId,
                'focus' => self::DEFAULT_FOCUS,
                'candidates' => [$candidate],
                'scope_profile' => $scopeProfile,
                'has_live_forge_authority' => $this->hasLiveForgeAuthority($forgeInputs),
            ]);

            return [
                'finding' => $candidate,
                'priority_report' => $priority,
                'selection_rejections' => $rejections,
                'selection_refill' => $selectionRefill,
            ];
        }
        $topId = (string) data_get($priority, 'top_candidate.candidate_id', '');
        foreach ($candidates as $candidate) {
            if (in_array($topId, [
                (string) ($candidate['finding_id'] ?? ''),
                (string) ($candidate['finding_hash'] ?? ''),
                (string) ($candidate['id'] ?? ''),
            ], true)) {
                return [
                    'finding' => $candidate,
                    'priority_report' => $priority,
                    'selection_rejections' => $rejections,
                    'selection_refill' => null,
                ];
            }
        }

        return [
            'finding' => $candidates[0] ?? null,
            'priority_report' => $priority,
            'selection_rejections' => $rejections,
            'selection_refill' => $selectionRefill,
        ];
    }

    /**
     * When the high-value backlog is fully rejected by current governance, the
     * long-running loop should work on that exact bottleneck instead of spinning
     * on empty selection. This fallback is narrow, factory-scoped and mergeable:
     * it asks the owner runtime to improve candidate refill/authority handling in
     * AP-786 itself.
     *
     * @param  list<array<string,string>>  $rejections
     * @return array<string,mixed>
     */
    private function factoryMaxStarvationRecoveryCandidate(array $rejections): array
    {
        $context = $this->starvationExhaustionStateContext($rejections);
        $reasons = $context['reasons'];
        $rejectedIds = $context['rejected_ids'];
        $stateHash = $context['state_hash'];
        $findingId = self::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID.'_'.$stateHash;

        $detail = 'The AP-790 long loop exhausted executable factory candidates while high-value backlog remained blocked by governance or authority. Improve AP-786 selection refill so the loop converts that state into a bounded next action instead of repeating empty selection.';

        $finding = $this->factorySeed(
            'ap790_candidate_starvation_recovery_'.$stateHash,
            'Recover AP-790 from empty executable candidate selection · '.$stateHash,
            $detail.' Rejection reason count: '.count($reasons).'. Rejection state hash: '.$stateHash.'.',
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
            'AutonomousEvolutionSessionServiceTest.php',
            'atlas_dev',
            'bug',
        );
        $finding['autonomous_selection_refill'] = true;
        $finding['finding_id'] = $findingId;
        $finding['origin_type'] = 'ap790_candidate_starvation_recovery';
        $finding['starvation_state_hash'] = $stateHash;
        $finding['starvation_rejection_reasons'] = $reasons;
        $finding['starvation_rejected_ids'] = array_slice($rejectedIds, 0, 24);
        $finding['spec_seed']['state_hash'] = $stateHash;

        return $finding;
    }

    /**
     * @param  list<array<string,string>>  $rejections
     * @return array<string,mixed>
     */
    private function factoryMaxSelectionRefillReceipt(array $rejections): array
    {
        $context = $this->starvationExhaustionStateContext($rejections);
        $reasons = $context['reasons'];
        $rejectedIds = $context['rejected_ids'];
        $stateHash = $context['state_hash'];

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap786_selection_refill.v1',
            'strategy' => 'ap790_candidate_starvation_recovery',
            'finding_id' => self::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID,
            'starvation_state_hash' => $stateHash,
            'rejection_reason_count' => count($reasons),
            'rejection_reasons' => $reasons,
            'rejected_finding_count' => count($rejectedIds),
            'bounded_next_action' => 'Improve AP-786 selection refill so exhausted factory backlog becomes one bounded owner-runtime cycle instead of repeating empty selection.',
        ];
    }

    /**
     * @param  list<array<string,string>>  $rejections
     * @return list<array<string,string>>
     */
    private function starvationExhaustionRejections(array $rejections): array
    {
        return array_values(array_filter(
            $rejections,
            function (array $rejection): bool {
                $findingId = (string) ($rejection['finding_id'] ?? '');
                $reason = (string) ($rejection['reason'] ?? '');

                if (str_starts_with($findingId, self::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID)) {
                    return false;
                }
                if ($this->isFactoryMaxTerminalBacklogUnlockFindingId($findingId)) {
                    return false;
                }
                if (in_array($reason, self::STARVATION_META_REJECTION_REASONS, true)) {
                    return false;
                }

                return true;
            },
        ));
    }

    private function isFactoryMaxTerminalBacklogUnlockFindingId(string $findingId): bool
    {
        return str_starts_with($findingId, 'factory_max_ap790_terminal_backlog_unlock_')
            || str_starts_with($findingId, 'factory_max_ap748_terminal_backlog_discovery_')
            || str_starts_with($findingId, 'factory_max_ap785_terminal_backlog_rebalance_');
    }

    /**
     * @param  list<array<string,string>>  $rejections
     * @return array{reasons:list<string>,rejected_ids:list<string>,state_hash:string}
     */
    private function starvationExhaustionStateContext(array $rejections): array
    {
        $exhaustionRejections = $this->starvationExhaustionRejections($rejections);
        $reasons = array_values(array_unique(array_filter(array_map(
            static fn (array $rejection): string => (string) ($rejection['reason'] ?? ''),
            $exhaustionRejections,
        ))));
        sort($reasons);
        $rejectedIds = array_values(array_unique(array_filter(array_map(
            static fn (array $rejection): string => (string) ($rejection['finding_id'] ?? ''),
            $exhaustionRejections,
        ))));
        sort($rejectedIds);
        $rejectedIds = array_slice($rejectedIds, 0, 24);
        $stateHash = substr(MissionCanonicalHash::sha256([
            'reasons' => $reasons,
            'rejected_ids' => $rejectedIds,
        ]), 0, 12);

        return [
            'reasons' => $reasons,
            'rejected_ids' => $rejectedIds,
            'state_hash' => $stateHash,
        ];
    }

    /**
     * AP-785 can still rank canonical high-impact backlog when AP-748 finds no
     * executable item. The long loop must turn that ranked backlog into bounded
     * owner-runtime work instead of stopping at no_candidate_with_allowed_files.
     *
     * @param  array<string,mixed>  $priority
     * @return list<array<string,mixed>>
     */
    private function factoryMaxPriorityBacklogCandidates(array $priority): array
    {
        $ranked = array_values(array_filter((array) ($priority['ranked_items'] ?? []), 'is_array'));
        if ($ranked === []) {
            $ranked = array_values(array_filter((array) ($priority['ranked_candidates'] ?? []), 'is_array'));
        }

        $candidates = [];
        foreach ($ranked as $item) {
            $lane = strtolower((string) ($item['lane'] ?? ''));
            $status = strtolower((string) ($item['completion_status'] ?? 'pending'));
            if ($lane !== 'now' || $status === 'completed') {
                continue;
            }

            $candidate = $this->factoryMaxPriorityBacklogCandidate($item);
            if ($candidate === null) {
                continue;
            }
            $candidates[] = $candidate;
        }

        return $candidates;
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>|null
     */
    private function factoryMaxPriorityBacklogCandidate(array $item): ?array
    {
        $id = strtolower((string) ($item['item_id'] ?? $item['candidate_id'] ?? $item['id'] ?? ''));
        $type = strtolower((string) ($item['item_type'] ?? $item['type'] ?? ''));
        $key = $id !== '' ? $id : $type;
        if ($key === '') {
            return null;
        }

        $seed = match (true) {
            str_contains($key, 'owner_runtime') || str_contains($key, 'runtime_execution') => $this->factorySeed(
                'ap790_priority_owner_runtime_real_execution_bridge',
                'Materialize owner runtime real execution bridge backlog into AP-790 work',
                'The priority engine ranks owner-runtime real execution as the highest pending factory unlock, but it has no executable files attached. Materialize it through AP-786 owner-flow diagnostics and tests so the loop can keep improving real owner execution instead of stopping at empty candidate selection.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php',
                'OwnerFlow/Ap786OwnerFlowExecutorTest.php',
                'atlas_dev',
                'bug',
            ),
            str_contains($key, 'continuous_24h') || str_contains($key, '24h_scheduler') || str_contains($key, 'scheduler') => $this->factorySeed(
                'ap790_priority_continuous_24h_scheduler',
                'Materialize continuous 24h scheduler backlog into AP-790 work',
                'The priority engine ranks continuous 24h scheduler reliability as a pending factory unlock, but the backlog item has no executable files attached. Materialize it through Reliable24hLoopRunnerService so blocked, merged and recovered cycles remain observable and bounded.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php',
                'Reliable24hLoopRunnerServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            str_contains($key, 'product_mode') || str_contains($key, 'controls') || str_contains($key, 'receipt') => $this->factorySeed(
                'ap790_priority_product_mode_controls_receipts',
                'Materialize Product Mode controls and receipts backlog into AP-790 work',
                'The priority engine ranks Product Mode controls and receipts as the next operator-safety unlock, but the backlog item has no executable files attached. Materialize it through ProductModeOperationalControlReceiptService so pause, kill-switch and autonomy decisions remain receipt-backed before longer unattended runs.',
                'app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlReceiptService.php',
                'ProductModeOperationalControlReceiptServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            str_contains($key, 'provider_routing') || str_contains($key, 'provider_optimization') || str_contains($key, 'atlas_decide') => $this->factorySeed(
                'ap789_provider_routing_authority_bridge',
                'Materialize provider routing authority bridge into AP-790 work',
                'The priority engine ranks provider routing only after owner runtime, scheduler and Product Mode controls are real. Materialize the next safe step through ForgeLiveAuthorityBootstrapService so AP-790 can move toward AtlasDecide/Forge authority without direct provider routing, fake topology or unsandboxed mutation.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapService.php',
                'ForgeLiveAuthorityBootstrapServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            str_contains($key, 'senior_loop') || str_contains($key, 'failed_gate') || str_contains($key, 'repair_after_authority') => $this->factorySeed(
                'ap786_owner_senior_loop_repair_after_authority_blocker',
                'Materialize owner senior loop repair after authority blocker',
                'The AP-790 loop reached a real owner runtime blocker: owner_runtime_senior_loop_execution_not_passed. Materialize a repair in Ap786OwnerFlowExecutor so senior-loop failures become more actionable and the loop can keep advancing without hiding failed provider/verification attempts.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php',
                'OwnerFlow/Ap786OwnerFlowExecutorTest.php',
                'atlas_dev',
                'bug',
            ),
            default => null,
        };

        if ($seed === null) {
            return null;
        }

        $seed['origin_type'] = 'priority_backlog_materialized';
        $seed['priority_source'] = [
            'schema_version' => 'atlas.software_company_stewardship.priority_backlog_source.v1',
            'item_id' => $id,
            'item_type' => $type,
            'lane' => (string) ($item['lane'] ?? ''),
            'final_priority_score' => $item['final_priority_score'] ?? null,
        ];
        $seed['evidence_refs'][] = 'ap785_priority_backlog:'.$key;
        $seed['spec_seed']['evidence_refs'][] = 'ap785_priority_backlog:'.$key;
        $seed['spec_seed']['acceptance'][] = 'The loop can select this priority-backed candidate when scanned findings and static seeds are exhausted.';

        return $seed;
    }

    /**
     * Terminal-locked starvation recovery means the loop has already tried to
     * fix empty selection and the owner runtime could not finish it. The next
     * professional move is to replenish the candidate factory itself through a
     * small ordered ladder, not to keep selecting the same exhausted recovery.
     *
     * @param  list<array<string,string>>  $rejections
     * @return list<array<string,mixed>>
     */
    private function factoryMaxTerminalBacklogUnlockCandidates(array $rejections): array
    {
        $context = $this->starvationExhaustionStateContext($rejections);
        $stateHash = $context['state_hash'];
        $reasonCount = count($context['reasons']);
        $detailSuffix = ' Terminal backlog state hash: '.$stateHash.'. Rejection reason count: '.$reasonCount.'.';

        $candidates = [
            $this->factorySeed(
                'ap790_terminal_backlog_unlock_'.$stateHash,
                'Unlock AP-790 terminal candidate starvation · '.$stateHash,
                'The 24h loop reached terminal-locked starvation recovery. Add bounded selection/backlog replenishment behavior so AP-786 can continue to a fresh, high-impact executable candidate instead of stopping at no_candidate_with_allowed_files.'.$detailSuffix,
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
                'AutonomousEvolutionSessionServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap748_terminal_backlog_discovery_'.$stateHash,
                'Replenish AP-748 runtime candidate discovery after terminal starvation · '.$stateHash,
                'The 24h loop exhausted AP-786 static and priority-backed candidates. Improve AP-748 deep finding discovery so factory_max scans surface fresh runtime bottlenecks in Atlas Dev and Forge instead of leaving AP-790 without executable work.'.$detailSuffix,
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php',
                'AreaFocusDeepFindingEngineServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap785_terminal_backlog_rebalance_'.$stateHash,
                'Rebalance AP-785 priority backlog after terminal starvation · '.$stateHash,
                'The 24h loop has no executable high-impact candidate after locks and terminal blockers. Improve AP-785 priority backlog materialization so owner runtime, Forge authority, scheduler, merge and provider-routing unlocks stay available as concrete executable candidates.'.$detailSuffix,
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php',
                'StewardshipPriorityEngineServiceTest.php',
                'atlas_dev',
                'bug',
            ),
        ];

        foreach ($candidates as $index => $candidate) {
            $candidates[$index]['origin_type'] = 'ap790_terminal_backlog_unlock';
            $candidates[$index]['terminal_backlog_state_hash'] = $stateHash;
            $candidates[$index]['terminal_backlog_rejection_reasons'] = $context['reasons'];
            $candidates[$index]['terminal_backlog_rejected_ids'] = $context['rejected_ids'];
            $candidates[$index]['spec_seed']['state_hash'] = $stateHash;
            $candidates[$index]['spec_seed']['acceptance'][] = 'AP-790 no longer stops at no_candidate_with_allowed_files for this terminal backlog state.';
        }

        return $candidates;
    }

    /** @param array<string,mixed> $finding */
    private function isFactoryMaxStarvationRecoveryFinding(array $finding): bool
    {
        return str_starts_with((string) ($finding['finding_id'] ?? ''), self::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID)
            || (string) ($finding['origin_type'] ?? '') === 'ap790_candidate_starvation_recovery';
    }

    /** @param array<string,mixed> $finding */
    private function isFactoryMaxTerminalBacklogUnlockFinding(array $finding): bool
    {
        if ((string) ($finding['origin_type'] ?? '') === 'ap790_terminal_backlog_unlock') {
            return true;
        }

        $findingId = (string) ($finding['finding_id'] ?? '');

        return str_starts_with($findingId, 'factory_max_ap790_terminal_backlog_unlock_')
            || str_starts_with($findingId, 'factory_max_ap748_terminal_backlog_discovery_')
            || str_starts_with($findingId, 'factory_max_ap785_terminal_backlog_rebalance_');
    }

    /** @param array<string,mixed> $finding */
    private function isForgeAuthorityReadinessCandidate(array $finding): bool
    {
        $originType = (string) ($finding['origin_type'] ?? '');
        $findingId = (string) ($finding['finding_id'] ?? '');

        return str_starts_with($originType, 'ap789_')
            || str_starts_with($findingId, 'factory_max_ap789_');
    }

    /**
     * AP-748 is read-only by design, so structural findings arrive as
     * proposal-only. The 24h factory loop may still execute the narrow subset that
     * is already safe: in-focus Atlas Dev missing-test findings over factory
     * runtime files with an explicit expected test path.
     *
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function promoteSafeFactoryFinding(array $finding, string $scopeProfile): array
    {
        if ($scopeProfile !== self::SCOPE_FACTORY_MAX || $this->findingAllowsAutonomousExecution($finding)) {
            return $finding;
        }
        if (! $this->isSafeFactoryStructuralFinding($finding)) {
            return $finding;
        }

        $allowedFiles = $this->allowedFiles($finding);
        $testsRequired = $this->testsRequiredForFinding($finding, $allowedFiles);
        $title = trim((string) ($finding['title'] ?? ''));

        $finding['auto_execution_allowed'] = true;
        $finding['operator_review_required'] = false;
        $finding['autonomous_execution_reason'] = 'factory_max_safe_structural_missing_test';
        $finding['proposed_next_action'] = $this->safeFactoryNextAction($title, $allowedFiles, $testsRequired);

        $specSeed = is_array($finding['spec_seed'] ?? null) ? $finding['spec_seed'] : [];
        $specSeed['proposal_only'] = false;
        $specSeed['operator_review_required'] = false;
        $specSeed['tests_required'] = $testsRequired;
        $specSeed['acceptance'] = array_values(array_filter([
            $title !== '' ? 'The owner runtime implements the selected missing-test finding: '.$title.'.' : '',
            $testsRequired !== [] ? 'The focused test command passes: php artisan test '.$testsRequired[0].'.' : '',
            'The implementation changes only the selected runtime/test allowed_files.',
        ], static fn (string $line): bool => $line !== ''));
        $finding['spec_seed'] = $specSeed;

        return $finding;
    }

    /** @param array<string,mixed> $finding */
    private function isSafeFactoryStructuralFinding(array $finding): bool
    {
        if ((string) ($finding['origin'] ?? '') !== 'structural_ap717') {
            return false;
        }
        if (! in_array(strtolower((string) ($finding['origin_type'] ?? '')), self::FACTORY_MAX_SAFE_STRUCTURAL_ORIGIN_TYPES, true)) {
            return false;
        }
        if ((bool) ($finding['in_focus'] ?? false) !== true || $this->owner($finding) !== 'atlas_dev') {
            return false;
        }
        if (! in_array(strtolower((string) ($finding['severity'] ?? '')), ['low', 'medium'], true)) {
            return false;
        }
        if ($this->stringList($finding['affected_docs'] ?? []) !== []) {
            return false;
        }

        $allowedFiles = $this->allowedFiles($finding);
        $testsRequired = $this->testsRequiredForFinding($finding, $allowedFiles);

        return $allowedFiles !== []
            && $testsRequired !== []
            && $this->touchesFactoryRuntime($allowedFiles);
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $testsRequired
     */
    private function safeFactoryNextAction(string $title, array $allowedFiles, array $testsRequired): string
    {
        $target = $allowedFiles[0] ?? 'selected runtime';
        $test = $testsRequired[0] ?? 'focused test';

        return sprintf(
            'Implement the safe AP-717 missing-test finding "%s": add or harden %s for %s, keep the diff inside allowed_files, and prove it with php artisan test %s.',
            $title !== '' ? $title : 'missing test',
            $test,
            $target,
            $test,
        );
    }

    /**
     * AP-786 must not silently degrade into "provider + Atlas prompt". Until
     * the native owner chain is wired for this session, direct driver execution
     * is a legacy diagnostic path that requires an explicit caller opt-in.
     *
     * @return array<string,mixed>
     */
    private function flowIntegrityGate(string $owner, bool $allowDirectProviderDriver): array
    {
        // The default AP-786 execute path now routes through the real owner
        // runtime chain (AP-747 -> AP-756 -> AP-757 -> AP-749 -> AP-758 ->
        // AP-759 -> AP-750) via the Ap786OwnerFlowRunner. The direct provider
        // driver only runs when the caller explicitly opts into the legacy
        // diagnostic path.
        $usesFullOwnerRuntimeChain = ! $allowDirectProviderDriver;
        $directProviderDriverPath = $allowDirectProviderDriver;
        $ok = $usesFullOwnerRuntimeChain || $allowDirectProviderDriver;

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap786_flow_integrity_gate.v1',
            'ok' => $ok,
            'owner' => $owner,
            'uses_full_owner_runtime_chain' => $usesFullOwnerRuntimeChain,
            'direct_provider_driver_path' => $directProviderDriverPath,
            'direct_provider_driver_allowed' => $allowDirectProviderDriver,
            'blocked_reason' => $ok ? null : 'full_atlas_forge_flow_required',
            'required_chain' => self::REQUIRED_FULL_OWNER_FLOW_APS,
            'required_robust_flow_capabilities' => self::REQUIRED_ROBUST_FLOW_CAPABILITIES,
            'robust_flow_contract' => [
                'schema' => Ap786RobustForgeQualityContractService::CONTRACT_SCHEMA,
                'service' => Ap786RobustForgeQualityContractService::class,
                'evaluates' => 'per-finding capability ok/missing/evidence_refs (ready|blocked) before provider execution',
            ],
            'forbidden_claim' => 'Do not claim full Atlas Forge or Atlas Dev execution when AP-786 is only invoking a provider driver with an Atlas-shaped prompt.',
            'next_action' => $directProviderDriverPath
                ? 'legacy_direct_provider_driver_path_explicitly_allowed'
                : 'execute through the native Atlas owner runtime chain (AP-747 -> AP-756 -> AP-757 -> AP-749 -> AP-758 -> AP-759 -> AP-750) via Ap786OwnerFlowRunner before any merge.',
        ];
    }

    /**
     * Enforce the robust Forge quality contract on the default owner-flow path.
     * This is intentionally evaluated before sandbox/provider/owner execution so
     * AP-786 cannot spend a cycle without SDD/TDD/BDD, workcell, repair,
     * evidence/replay and merge-governance proof.
     *
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $validationCommands
     * @return array<string,mixed>
     */
    private function robustFlowContract(string $areaId, string $focus, array $finding, array $allowedFiles, string $owner, array $validationCommands): array
    {
        $testsRequired = $this->testsRequiredForFinding($finding, $allowedFiles);
        $acceptance = $this->acceptanceForFinding($finding);
        $specId = (string) (data_get($finding, 'spec_seed.candidate_id') ?: ($finding['finding_id'] ?? ''));
        $decisionReceiptId = 'AP-786:'.(string) ($finding['finding_id'] ?? substr(MissionCanonicalHash::sha256($finding), 0, 12));

        return $this->robustContract->build([
            'area_id' => $areaId,
            'focus' => $focus,
            'owner' => $owner,
            'selected_finding' => $finding,
            'allowed_files' => $allowedFiles,
            'validation_commands' => $validationCommands !== [] ? $validationCommands : ['git diff --check'],
            'sdd_packet' => [
                'spec_id' => $specId,
                'objective' => (string) ($finding['why_it_matters'] ?? $finding['detail'] ?? $finding['title'] ?? ''),
                'scope' => (string) ($finding['title'] ?? 'AP-786 autonomous evolution work'),
                'acceptance' => $acceptance,
                'owner_docs' => $this->stringList(data_get($finding, 'spec_seed.owner_doc_refs', [])),
            ],
            'tdd_contract' => [
                'tests_required' => $testsRequired,
                'focused_test' => $testsRequired[0] ?? '',
                'test_first' => $testsRequired !== [],
            ],
            'bdd_contract' => [
                'behavior_acceptance' => $acceptance,
                'operator_visible_outcome' => (string) ($finding['why_it_matters'] ?? $finding['proposed_next_action'] ?? ''),
            ],
            'provider_topology' => [
                'source' => 'atlas_decide',
                'chosen_by_atlas_decide' => true,
                'owner_runtime_authority' => 'AP-759',
                'target_owner' => $owner,
            ],
            'workcell' => [
                'context_scout' => 'AP-748 deep finding scan',
                'architect' => 'Self-Directed Evolution spec seed / SDD packet',
                'implementer' => 'AP-759 owner runtime command',
                'reviewer' => 'AP-750 owner runtime result bridge',
                'repair_agent' => 'Atlas Dev Senior Loop failure capsule',
                'certifier' => 'AP-786/AP-769/AP-774 certification gates',
            ],
            'repair_policy' => [
                'max_attempts' => 2,
                'failed_gate_capsule_schema' => 'atlas.software_company_stewardship.ap786_failed_gate_capsule.v1',
                'stop_conditions' => ['validation_still_failing', 'diff_outside_allowed_files', 'no_progress_between_attempts'],
            ],
            'evidence' => [
                'decision_receipt_id' => $decisionReceiptId,
                'evidence_ledger_ref' => 'AP-750:owner_runtime_result_bridge',
                'ap750_result_bridge' => 'required_before_merge',
                'replay_ref' => 'AP-786:autonomous_evolution_session_jsonl',
                'programming_governance' => true,
            ],
            'merge_requirements' => [
                'governed_by' => ['AP-769', 'AP-774'],
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    private function diagnosticRobustFlowContractSkipped(array $finding, array $allowedFiles, string $owner): array
    {
        return [
            'schema_version' => Ap786RobustForgeQualityContractService::CONTRACT_SCHEMA,
            'ap_contract' => 'AP-786',
            'status' => 'diagnostic_skipped',
            'owner' => $owner,
            'selected_finding' => $this->findingSummary($finding),
            'allowed_files' => $allowedFiles,
            'blockers' => ['legacy_direct_provider_driver_diagnostic_path'],
            'claim_policy' => [
                'counts_as_full_atlas_forge_execution' => false,
                'counts_as_robust_obra_forge_quality_flow' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    private function testsRequiredForFinding(array $finding, array $allowedFiles): array
    {
        $tests = $this->stringList(data_get($finding, 'spec_seed.tests_required', []));
        foreach ($allowedFiles as $file) {
            if (str_starts_with($file, 'tests/') || str_ends_with($file, 'Test.php')) {
                $tests[] = $file;
            }
        }

        return array_values(array_unique($tests));
    }

    /**
     * AP-786 is only useful when the owner runtime receives an executable proof
     * contract. A generic `git diff --check` lets providers truthfully return
     * no_patch_needed; thread the selected finding's focused tests into Atlas
     * Dev so the provider sees a concrete patch target and verification gate.
     *
     * @param  list<string>  $inputCommands
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    private function ownerValidationCommands(array $inputCommands, array $finding, array $allowedFiles): array
    {
        $commands = array_values(array_filter(array_map(
            static fn (mixed $command): string => is_string($command) ? trim($command) : '',
            $inputCommands,
        ), static fn (string $command): bool => $command !== ''));

        foreach ($this->testsRequiredForFinding($finding, $allowedFiles) as $test) {
            $test = trim($test);
            if ($test === '' || str_contains($test, "\n") || strlen($test) > 180) {
                continue;
            }
            if (str_starts_with($test, 'php artisan test ')) {
                $commands[] = $test;
            } elseif (str_starts_with($test, 'tests/') && str_ends_with($test, '.php')) {
                $commands[] = 'php artisan test '.$test;
            }
        }

        if (! in_array('git diff --check', $commands, true)) {
            $commands[] = 'git diff --check';
        }

        return array_values(array_slice(array_unique($commands), 0, 4));
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return list<string>
     */
    private function acceptanceForFinding(array $finding): array
    {
        $acceptance = $this->stringList(data_get($finding, 'spec_seed.acceptance', []));
        if ($acceptance !== []) {
            return $acceptance;
        }

        $title = trim((string) ($finding['title'] ?? ''));
        $nextAction = trim((string) ($finding['proposed_next_action'] ?? ''));

        return array_values(array_filter([
            $title !== '' ? 'Given the selected AP-786 finding, the owner runtime implements: '.$title : '',
            $nextAction !== '' ? 'Operator can verify the result by the proposed next action: '.$nextAction : '',
        ], static fn (string $line): bool => $line !== ''));
    }

    /**
     * High-impact fallback work for the operator's core thesis: improve the
     * software factory itself before spending cycles on downstream domains or
     * low-leverage documentation/evidence cleanup.
     *
     * @return list<array<string,mixed>>
     */
    private function factoryMaxSeedCandidates(): array
    {
        return [
            $this->factorySeed(
                'ap789_forge_topology_dispatch_readiness',
                'Repair AP-789 Forge live topology dispatch readiness',
                'The 24h loop cannot execute high-impact Forge work while AP-789 reports forge_live_topology_unavailable. Improve the real readiness diagnostics or wiring around ForgeLiveAuthorityBootstrapService so the loop gets an actionable, bounded next step instead of starving candidate selection.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapService.php',
                'ForgeLiveAuthorityBootstrapServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap789_awis_workspace_handoff_readiness',
                'Repair AP-789 AWIS workspace handoff readiness',
                'The 24h loop cannot graduate into real Forge owner runtime while AP-789 reports workspace_handoff_pack_blocked or awis_handoff blockers. Improve the AWIS handoff readiness surface and tests so AP-790 can progress without fabricating authority.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapService.php',
                'ForgeLiveAuthorityBootstrapServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap790_runtime_gap_matrix_ingestion',
                'Make AP-790 consume structural AAEOS runtime gap backlog before maintenance',
                'Wire the autonomous loop selection policy to prefer high-impact partial_runtime/spec_runtime_gap items from the AAEOS runtime gap matrix before spending more cycles on routine missing-test maintenance.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
                'AutonomousEvolutionSessionServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap789_forge_authority_readiness',
                'Improve AP-789 live authority readiness diagnostics',
                'Make AP-789 live authority blockers more actionable so the 24h loop can graduate from Atlas Dev maintenance into real owner-runtime dispatch without fabricating authority.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapService.php',
                'ForgeLiveAuthorityBootstrapServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap792_loop_certification_runtime_realness',
                'Harden 24h certification harness against partial-runtime false confidence',
                'Strengthen the loop certification harness so it distinguishes small successful maintenance cycles from large Dev/Forge runtime cycles before any months-ready claim.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Loop24hCertificationHarnessService.php',
                'Loop24hCertificationHarnessServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap786_loop_hardening',
                'Harden AP-786 autonomous evolution loop against wasted cycles',
                'Make the autonomous loop better at choosing, executing, validating, merging and continuing without wasting provider calls.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
                'AutonomousEvolutionSessionServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap785_priority_power',
                'Improve factory-max priority scoring for highest-return engineering work',
                'Tune the priority engine so work that improves Atlas Dev, Forge, provider routing, sandboxing, validation and merge throughput dominates cosmetic or documentary work.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php',
                'StewardshipPriorityEngineServiceTest.php',
                'forge',
                'bug',
            ),
            $this->factorySeed(
                'ap748_deep_scan_power',
                'Expand deep finding engine to discover runtime bottlenecks in Atlas Dev and Forge',
                'Increase the scanner ability to find real runtime gaps, missing tests, provider-routing risks and execution bottlenecks instead of low-leverage doc findings.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php',
                'AreaFocusDeepFindingEngineServiceTest.php',
                'forge',
                'bug',
            ),
            $this->factorySeed(
                'ap717_missing_test_precision',
                'Suppress AP-717 interface-only missing-test false positives',
                'The 24h loop must not waste provider cycles on impossible or low-value missing-test findings for interfaces when the concrete implementation/service test already covers the runtime contract.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php',
                'AreaFocusDeepFindingEngineServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap756_sandbox_throughput',
                'Harden branch sandbox materializer for faster safe autonomous cycles',
                'Improve the isolated branch/worktree layer because every autonomous implementation cycle depends on reliable sandbox creation, cleanup and receipts.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerService.php',
                'AreaFocusBranchSandboxMaterializerServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap769_merge_throughput',
                'Improve merge governor throughput without lowering safety',
                'Reduce false blocks and strengthen evidence in the merge governor so safe changes land faster while risky changes remain isolated.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchMergeGovernorService.php',
                'StewardshipBranchMergeGovernorServiceTest.php',
                'forge',
                'bug',
            ),
            $this->factorySeed(
                'cursor_driver_reliability',
                'Harden Cursor CLI driver for long autonomous factory runs',
                'Provider invocation reliability directly controls factory throughput; improve prompt passing, scope checks, timeout evidence and account-driver safety.',
                'app/Services/Ai/Programming/AtlasForgeCursorCliInvocationDriver.php',
                'AtlasForgeCursorCliDriverTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap786_owner_failure_specificity',
                'Expose owner-runtime failure states as actionable AP-786 blockers',
                'When the owner runtime returns no_patch_needed, senior_loop_execution_not_passed or routing_not_executable, AP-786 should surface the precise machine blocker instead of collapsing everything into owner_runtime_result_not_completed.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
                'AutonomousEvolutionSessionServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap790_blocked_cycle_summary_test',
                'Add focused unit coverage for AP-790 blocked-cycle summaries',
                'Prove that the reliable 24h runner reports blocked cycles with exact blockers, cycle indexes and no merge claim so the operator can trust loop progress telemetry.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php',
                'Reliable24hLoopRunnerServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap785_priority_state_test',
                'Add focused unit coverage for AP-785 priority state awareness',
                'Prove that factory priority ranking prefers high-return Atlas Dev and Forge execution work while preserving deterministic state-aware ordering.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php',
                'StewardshipPriorityEngineServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap748_deep_scan_path_test',
                'Add focused unit coverage for AP-748 deep-scan path precision',
                'Prove that the deep finding engine emits actionable source and test paths for factory runtime work instead of routing low-leverage documentation-only findings.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php',
                'AreaFocusDeepFindingEngineServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap786_read_model_test',
                'Add focused unit coverage for AP-786 session read model',
                'Prove that the autonomous session read model projects recorded cycle receipts without executing providers, branches or merge operations.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionReadModelService.php',
                'AutonomousEvolutionSessionReadModelServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap791_receipt_integrity_test',
                'Add focused unit coverage for AP-791 loop receipt integrity',
                'Prove that loop receipt integrity keeps pre-merge inbox evidence mandatory and emits reviewable lifecycle receipts for completed, blocked and planned cycles.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousLoopReceiptIntegrityService.php',
                'AutonomousLoopReceiptIntegrityServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap716_area_focus_read_model_test',
                'Add focused unit coverage for AP-716 area focus read model',
                'Prove that the area focus read model exposes actionable agentic engineering status without mutating repositories or bypassing owner routing.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtlasAreaFocusLoopReadModelService.php',
                'AtlasAreaFocusLoopReadModelServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap790_blocked_cycle_mergeable_test',
                'Add mergeable AP-790 blocked-cycle regression coverage',
                'Add a focused regression test proving AP-790 records blocked-cycle blockers and remains safe to auto-merge when the diff is test-only.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php',
                'Reliable24hLoopRunnerServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap748_interface_false_positive_test',
                'Add AP-748 interface false-positive regression coverage',
                'Add a focused regression test proving AP-748 does not promote interface-only missing-test findings when the concrete runtime already has coverage.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php',
                'AreaFocusDeepFindingEngineServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap790_seen_finding_resume_test',
                'Add AP-790 seen-finding resume regression coverage',
                'Add a focused regression test proving AP-790 crash recovery forwards seen findings so the loop keeps moving instead of repeating completed work.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php',
                'Reliable24hLoopRunnerServiceTest.php',
                'atlas_dev',
                'test',
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function factorySeed(string $id, string $title, string $detail, string $sourceFile, string $testBasename, string $owner, string $kind, string $severity = 'medium'): array
    {
        $hash = 'sha256:'.MissionCanonicalHash::sha256(['AP-786', self::SCOPE_FACTORY_MAX, $id, $sourceFile, $testBasename]);

        return [
            'schema_version' => 'atlas.software_company_stewardship.area_focus_deep_finding.v1',
            'finding_id' => 'factory_max_'.$id,
            'finding_hash' => $hash,
            'area_id' => self::DEFAULT_AREA_ID,
            'focus' => self::DEFAULT_FOCUS,
            'title' => $title,
            'detail' => $detail,
            'kind' => $kind,
            'severity' => $severity,
            'confidence' => 'high',
            'confidence_score' => 0.9,
            'owner_candidate' => $owner,
            'evidence_refs' => [
                'factory_max_seed:'.$id,
                'impl:'.$sourceFile,
                'expected_test:'.$testBasename,
            ],
            'affected_files' => [$sourceFile],
            'affected_docs' => [],
            'why_it_matters' => $detail,
            'proposed_spec_title' => 'Factory Max: '.$title,
            'proposed_next_action' => sprintf(
                'Implement "%s" by changing the targeted runtime and/or focused test. Target runtime: %s. Required focused test: %s. This cycle is invalid if it only changes docs or returns no_patch_needed without concrete proof.',
                $title,
                $sourceFile,
                $this->expectedTestPath($testBasename, [$sourceFile]),
            ),
            'in_focus' => true,
            'priority_score' => 950,
            'origin' => 'factory_max_seed',
            'origin_type' => $id,
            'auto_execution_allowed' => true,
            'operator_review_required' => false,
            'spec_seed' => [
                'schema_version' => 'atlas.software_company_stewardship.factory_max_spec_seed.v1',
                'candidate_id' => 'factory_max_'.$id,
                'candidate_hash' => $hash,
                'source_owner' => $owner,
                'gap_kind' => 'software_factory_runtime_improvement',
                'title' => 'Factory Max: '.$title,
                'rationale' => $detail,
                'capability' => self::DEFAULT_FOCUS,
                'risk_level' => $severity,
                'evidence_refs' => ['factory_max_seed:'.$id, 'impl:'.$sourceFile, 'expected_test:'.$testBasename],
                'owner_doc_refs' => [],
                'route_hint_owner' => $owner,
                'acceptance' => [
                    'The implementation changes the targeted runtime or its focused tests, not only documentation.',
                    'The focused test path proves the behavior or guard that makes autonomous cycles more robust.',
                    'The AP-786 robust flow contract remains ready before owner execution.',
                ],
                'tests_required' => [$this->expectedTestPath($testBasename, [$sourceFile])],
                'proposal_only' => false,
                'operator_review_required' => false,
            ],
        ];
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  array<string,true>  $reviewLocked
     */
    private function candidateRejectionReason(array $finding, array $allowedFiles, array $reviewLocked, string $scopeProfile, string $areaId, string $focus, array $forgeInputs = [], bool $maintenanceBudgetExhausted = false, array $terminalLocked = []): string
    {
        if ($this->findingIsReviewLocked($finding, $terminalLocked)) {
            return 'terminal_locked_existing_failure';
        }
        if ($this->isFactoryMaxStarvationRecoveryFinding($finding)) {
            return $this->factoryMaxStarvationRecoveryRejectionReason(
                $finding,
                $allowedFiles,
                $scopeProfile,
            );
        }
        if ($this->isFactoryMaxTerminalBacklogUnlockFinding($finding)) {
            return $this->factoryMaxTerminalBacklogUnlockRejectionReason(
                $finding,
                $allowedFiles,
                $scopeProfile,
            );
        }
        if ($allowedFiles === []) {
            return 'no_allowed_files';
        }
        if (! $this->findingAllowsAutonomousExecution($finding)) {
            return 'auto_execution_not_allowed';
        }
        if ($this->findingIsReviewLocked($finding, $this->quarantine()->quarantinedFindingKeys($areaId, $focus))) {
            return 'candidate_quarantined';
        }
        if ($this->findingIsReviewLocked($finding, $reviewLocked)) {
            return 'review_locked_existing_branch';
        }
        if ($scopeProfile !== self::SCOPE_FACTORY_MAX) {
            return '';
        }

        if ($maintenanceBudgetExhausted && $this->isFactoryMaintenanceFinding($finding) && $this->touchesFactoryRuntime($allowedFiles)) {
            return 'factory_max_rejects_maintenance_after_budget';
        }

        $originType = strtolower((string) ($finding['origin_type'] ?? ''));
        if (in_array($originType, self::FACTORY_MAX_REJECTED_ORIGIN_TYPES, true)) {
            return 'factory_max_rejects_low_leverage_doc_or_evidence_work';
        }
        if ($this->allDocs($allowedFiles)) {
            return 'factory_max_rejects_docs_only_work';
        }
        if ($this->benchmarkOrRivalsCandidate($finding, $allowedFiles)) {
            return 'factory_max_rejects_benchmark_or_rivals_work';
        }
        if ($originType === 'missing_test') {
            return 'factory_max_rejects_routine_missing_test_work';
        }
        if (! $this->touchesFactoryRuntime($allowedFiles)) {
            return 'factory_max_requires_direct_factory_runtime_or_test_impact';
        }
        if (! $this->hasExistingImplementationSource($finding)) {
            return 'factory_max_rejects_missing_runtime_source';
        }
        if ($this->owner($finding) === 'forge' && ! $this->hasLiveForgeAuthority($forgeInputs)) {
            return 'factory_max_rejects_forge_without_live_authority';
        }
        if ($this->owner($finding) === 'atlas_dev'
            && ! $this->hasLiveForgeAuthority($forgeInputs)
            && ! $this->isForgeAuthorityReadinessCandidate($finding)
            && $this->atlasDevForbiddenTopologyLeakCandidate($finding, $allowedFiles)) {
            return 'factory_max_rejects_atlas_dev_topology_leak_without_authority';
        }
        if ($this->owner($finding) === 'atlas_dev'
            && ! $this->hasLiveForgeAuthority($forgeInputs)
            && $this->highRiskDeepFinding($finding)) {
            return 'factory_max_rejects_high_risk_deep_finding_without_forge_authority';
        }
        if ($this->owner($finding) === 'atlas_dev'
            && ! $this->hasLiveForgeAuthority($forgeInputs)
            && ! $this->factoryScopedAutonomousPatchCandidate($allowedFiles)) {
            return 'factory_max_rejects_non_factory_scope_without_automerge_authority';
        }

        return '';
    }

    /**
     * AP-790 starvation recovery must stay executable even when the same state
     * hash was review-locked, quarantined or previously attempted. Only hard
     * factory-max safety checks apply so empty selection becomes one bounded
     * owner-runtime cycle instead of repeating no_candidate_with_allowed_files.
     *
     * @param  list<string>  $allowedFiles
     */
    private function factoryMaxStarvationRecoveryRejectionReason(
        array $finding,
        array $allowedFiles,
        string $scopeProfile,
    ): string {
        if ($scopeProfile !== self::SCOPE_FACTORY_MAX) {
            return '';
        }
        if (! $this->touchesFactoryRuntime($allowedFiles)) {
            return 'factory_max_requires_direct_factory_runtime_or_test_impact';
        }

        return '';
    }

    /**
     * AP-790 terminal backlog unlock must stay executable through review locks so
     * terminal-locked starvation can convert into one bounded owner-runtime cycle.
     *
     * @param  list<string>  $allowedFiles
     */
    private function factoryMaxTerminalBacklogUnlockRejectionReason(
        array $finding,
        array $allowedFiles,
        string $scopeProfile,
    ): string {
        if ($scopeProfile !== self::SCOPE_FACTORY_MAX) {
            return '';
        }
        if (! $this->touchesFactoryRuntime($allowedFiles)) {
            return 'factory_max_requires_direct_factory_runtime_or_test_impact';
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     */
    private function benchmarkOrRivalsCandidate(array $finding, array $allowedFiles): bool
    {
        $haystack = strtolower(implode(' ', array_merge($allowedFiles, [
            (string) ($finding['finding_id'] ?? ''),
            (string) ($finding['title'] ?? ''),
            (string) ($finding['detail'] ?? ''),
            (string) ($finding['why_it_matters'] ?? ''),
        ])));

        return str_contains($haystack, 'rivals') || str_contains($haystack, 'benchmark');
    }

    /**
     * Atlas Dev's provider prompt quality gate intentionally blocks Forge/Council
     * instructions. Rejecting these candidates before owner execution keeps the
     * 24h loop from spending a full branch/sandbox cycle on a prompt projection
     * that cannot be sent.
     *
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     */
    private function atlasDevForbiddenTopologyLeakCandidate(array $finding, array $allowedFiles): bool
    {
        $haystack = strtolower(implode(' ', array_merge($allowedFiles, [
            (string) ($finding['finding_id'] ?? ''),
            (string) ($finding['title'] ?? ''),
            (string) ($finding['detail'] ?? ''),
            (string) ($finding['why_it_matters'] ?? ''),
            (string) ($finding['proposed_next_action'] ?? ''),
            (string) data_get($finding, 'spec_seed.title', ''),
            (string) data_get($finding, 'spec_seed.rationale', ''),
        ])));

        return str_contains($haystack, 'forge') || str_contains($haystack, 'council');
    }

    /** @param array<string,mixed> $finding */
    private function highRiskDeepFinding(array $finding): bool
    {
        $origin = (string) ($finding['origin'] ?? '');
        $originType = (string) ($finding['origin_type'] ?? '');
        if ($origin === 'factory_max_seed' || str_starts_with($originType, 'ap')) {
            return false;
        }

        return strtolower((string) ($finding['severity'] ?? '')) === 'high';
    }

    /**
     * Mirrors AP-774's narrow factory-scoped auto-merge boundary so AP-790 does
     * not select work it cannot merge without human review while Forge authority
     * is unavailable.
     *
     * @param  list<string>  $allowedFiles
     */
    private function factoryScopedAutonomousPatchCandidate(array $allowedFiles): bool
    {
        if ($allowedFiles === []) {
            return false;
        }

        foreach ($allowedFiles as $file) {
            if (str_starts_with($file, 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/')) {
                continue;
            }
            if (str_starts_with($file, 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/')) {
                continue;
            }
            if (str_starts_with($file, 'app/Services/Ai/SoftwareCompanyStewardship/ProductMode/')) {
                continue;
            }
            if (str_starts_with($file, 'tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/')) {
                continue;
            }

            return false;
        }

        return true;
    }

    /** @param array<string,mixed> $finding */
    private function hasExistingImplementationSource(array $finding): bool
    {
        foreach ($this->stringList($finding['affected_files'] ?? []) as $file) {
            if (! str_starts_with($file, 'app/')) {
                continue;
            }
            $absolute = function_exists('base_path') ? base_path($file) : $file;
            if (is_file($absolute)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $forgeInputs */
    private function hasLiveForgeAuthority(array $forgeInputs): bool
    {
        $obra = trim((string) ($forgeInputs['forge_obra'] ?? $forgeInputs['obra_id'] ?? ''));
        $topology = is_array($forgeInputs['forge_live_topology'] ?? null) ? $forgeInputs['forge_live_topology'] : [];
        $decision = is_array($forgeInputs['forge_live_decision'] ?? null) ? $forgeInputs['forge_live_decision'] : [];

        return $obra !== ''
            && strtolower((string) ($topology['status'] ?? '')) === 'live'
            && $decision !== [];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return list<string>
     */
    private function allowedFiles(array $finding): array
    {
        $files = array_merge(
            $this->stringList($finding['affected_files'] ?? []),
            $this->stringList($finding['affected_docs'] ?? []),
        );
        foreach ((array) ($finding['evidence_refs'] ?? []) as $ref) {
            if (! is_string($ref)) {
                continue;
            }
            if (str_starts_with($ref, 'expected_test:')) {
                $basename = trim(substr($ref, strlen('expected_test:')));
                $testPath = $this->expectedTestPath($basename, $this->stringList($finding['affected_files'] ?? []));
                if ($testPath !== '') {
                    $files[] = $testPath;
                }
            }
            if (preg_match_all('/(?:tests|app|docs|config|routes|database)\/[A-Za-z0-9_.,:\/\\\\ -]+?\.(?:php|md|ts|tsx|json|yml|yaml)/', $ref, $matches)) {
                foreach ($matches[0] as $match) {
                    $files[] = trim($match, " \t\n\r\0\x0B,.:");
                }
            }
        }

        return array_values(array_unique(array_filter(array_map(
            fn (string $file): string => $this->normalizePath($file),
            $files,
        ), fn (string $file): bool => $file !== '' && ! $this->forbidden($file))));
    }

    /**
     * @param  list<string>  $affectedFiles
     */
    private function expectedTestPath(string $basename, array $affectedFiles): string
    {
        if ($basename === '') {
            return '';
        }
        $source = $affectedFiles[0] ?? '';
        if (str_starts_with($source, 'app/Services/Ai/NightShift/')) {
            return 'tests/Unit/Ai/NightShift/'.$basename;
        }
        if (str_starts_with($source, 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/')) {
            return 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/'.$basename;
        }
        if (str_starts_with($source, 'app/Services/Ai/')) {
            $tail = substr($source, strlen('app/Services/Ai/'));
            $dir = trim(dirname($tail), '.');

            return 'tests/Unit/Ai/'.($dir !== '' ? $dir.'/' : '').$basename;
        }

        return 'tests/Unit/'.$basename;
    }

    /**
     * Build the AP-726 preflight/handoff ONCE so the same handoff_hash threads
     * through AP-756 (sandbox materialization), AP-747 (release) and AP-757
     * (sandbox binding inside AP-749). Both the sandbox materializer and the
     * owner-flow executor must see the same handoff.
     *
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    private function buildPreflight(string $areaId, array $finding, array $allowedFiles, string $owner, string $cycleId): array
    {
        $route = $owner === 'forge' ? AreaFocusDevForgeRouterService::ROUTE_FORGE : AreaFocusDevForgeRouterService::ROUTE_ATLAS_DEV;
        $hash = substr(MissionCanonicalHash::sha256([$cycleId, $finding['finding_hash'] ?? '', $allowedFiles]), 0, 12);
        $branchName = 'atlas/area-focus/'.$areaId.'/'.$route.'/'.$hash;
        $workOrderId = 'ap786_wo_'.$hash;
        $workOrderHash = 'sha256:'.MissionCanonicalHash::sha256([$workOrderId, $finding]);
        $decisionId = 'ap786_decision_'.$hash;
        $decisionHash = 'sha256:'.MissionCanonicalHash::sha256([$decisionId, 'session_operator_authorized']);
        $handoffHash = 'sha256:'.MissionCanonicalHash::sha256([$cycleId, $branchName, $workOrderHash, $decisionHash]);

        return [
            'schema_version' => AreaFocusBranchSandboxPreflightService::REPORT_SCHEMA,
            'ap_contract' => 'AP-726',
            'status' => AreaFocusBranchSandboxPreflightService::STATUS_READY,
            'area_id' => $areaId,
            'branch_plan' => [
                'branch_name' => $branchName,
                'base_ref_plan' => 'main',
                'allowed_files' => $allowedFiles,
            ],
            'handoff_packet' => [
                'schema_version' => AreaFocusBranchSandboxPreflightService::HANDOFF_SCHEMA,
                'area_id' => $areaId,
                'route' => $route,
                'target_owner' => $owner,
                'work_order_id' => $workOrderId,
                'work_order_hash' => $workOrderHash,
                'finding_hash' => (string) ($finding['finding_hash'] ?? ''),
                'decision_id' => $decisionId,
                'decision_hash' => $decisionHash,
                'title' => (string) ($finding['title'] ?? 'Autonomous evolution work'),
                'risk_level' => (string) ($finding['severity'] ?? 'medium'),
                'allowed_files' => $allowedFiles,
                'allowed_paths' => $allowedFiles,
                'handoff_hash' => $handoffHash,
            ],
            'preflight_hash' => 'sha256:'.MissionCanonicalHash::sha256([$cycleId, $handoffHash]),
        ];
    }

    /**
     * @param  array<string,mixed>  $preflight
     * @return array<string,mixed>
     */
    private function materializeSandbox(array $preflight, string $areaId, string $repoRoot): array
    {
        $handoffHash = (string) data_get($preflight, 'handoff_packet.handoff_hash', '');

        return $this->materializer->materialize([
            'area_id' => $areaId,
            'repo_root' => $repoRoot,
            'base_ref' => 'main',
            'preflight_report' => $preflight,
            'sandbox_receipt' => [
                'decision' => 'materialize_sandbox',
                'operator_actor' => 'ap786_autonomous_session',
                'target_handoff_hash' => $handoffHash,
                'rationale' => 'Operator authorized AP-786 autonomous evolution session for this area/focus.',
            ],
            'materialize_sandbox' => true,
            'record_sandbox' => true,
        ]);
    }

    /**
     * Default execute path: run the REAL Atlas owner-runtime chain via the
     * Ap786OwnerFlowRunner (AP-747 -> AP-748 -> AP-749 -> AP-758 -> AP-759 ->
     * AP-750), emit Evidence/Inbox before any merge, then evaluate merge through
     * AP-769/AP-774. It never calls the provider driver router.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $selection
     * @param  list<string>  $allowedFiles
     * @param  array<string,mixed>  $preflight
     * @param  array<string,mixed>  $sandbox
     * @param  array<string,mixed>  $flowIntegrityGate
     * @return array<string,mixed>
     */
    private function runOwnerFlowCycle(string $cycleId, int $cycleIndex, array $input, array $finding, array $selection, string $scopeProfile, string $owner, array $allowedFiles, string $class, array $preflight, array $sandbox, string $worktree, string $branch, array $flowIntegrityGate, array $robustFlowContract): array
    {
        $areaId = (string) $input['area_id'];
        $focus = (string) ($input['focus'] ?? self::DEFAULT_FOCUS);
        $repoRoot = (string) $input['repo_root'];

        $ownerFlow = $this->ownerFlow->execute(array_replace([
            'area_id' => $areaId,
            'portfolio_id' => 'atlas_software_company',
            'owner' => $owner,
            'actor' => (string) $input['actor'],
            'finding' => $finding,
            'allowed_files' => $allowedFiles,
            'preflight_report' => $preflight,
            'sandbox_record' => $sandbox,
            'worktree_path' => $worktree,
            'execute' => true,
            'provider' => (string) ($input['provider'] ?? 'cursor_cli'),
            'model' => (string) ($input['model'] ?? 'composer-2.5-fast'),
            'validation_commands' => $this->ownerValidationCommands((array) $input['validation_commands'], $finding, $allowedFiles),
        ], (array) ($input['forge_inputs'] ?? [])));
        $ownerFlowSummary = $this->ownerFlowSummary($ownerFlow);

        if ((string) ($ownerFlow['status'] ?? '') === Ap786OwnerFlowExecutor::STATUS_BLOCKED) {
            return $this->governCycleOutcome($this->blockedCycle($cycleId, $cycleIndex, array_values((array) ($ownerFlow['blockers'] ?? ['owner_flow_blocked'])), [
                'selected_finding' => $this->findingSummary($finding),
                'priority_report' => $selection['priority_report'],
                'scope_profile' => $scopeProfile,
                'selection_rejections' => $selection['selection_rejections'] ?? [],
                'flow_integrity_gate' => $flowIntegrityGate,
                'robust_flow_contract' => $robustFlowContract,
                'owner' => $owner,
                'sandbox_id' => (string) ($sandbox['sandbox_id'] ?? ''),
                'branch_ref' => $branch,
                'worktree_path' => $worktree,
                'owner_flow' => $ownerFlowSummary,
                'provider_called' => false,
                'branch_created' => true,
                'worktree_created' => true,
                'merge_skipped' => true,
                'result_bridge_skipped' => true,
            ]), $areaId, $focus, $finding, $allowedFiles, $owner, $branch, $worktree, true);
        }

        $executionResult = is_array($ownerFlow['execution_result'] ?? null) ? $ownerFlow['execution_result'] : [];

        // AP-765 Product Mode / Inbox evidence BEFORE any merge attempt.
        $resultBridge = $this->resultBridge->project([
            'area_id' => $areaId,
            'portfolio_id' => 'atlas_software_company',
            'owner' => $owner,
            'actor' => (string) $input['actor'],
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'spec_id' => (string) data_get($finding, 'spec_seed.candidate_id', ''),
            'execution_result' => $executionResult,
            'emit_inbox' => true,
            'record_evidence' => true,
            'record_event' => true,
            'record_cycle' => true,
        ]);

        $base = [
            'cycle_id' => $cycleId,
            'cycle_index' => $cycleIndex,
            'selected_finding' => $this->findingSummary($finding),
            'priority_report' => $selection['priority_report'],
            'scope_profile' => $scopeProfile,
            'selection_rejections' => $selection['selection_rejections'] ?? [],
            'flow_integrity_gate' => $flowIntegrityGate,
            'robust_flow_contract' => $robustFlowContract,
            'owner' => $owner,
            'allowed_files' => $allowedFiles,
            'sandbox_id' => (string) ($sandbox['sandbox_id'] ?? ''),
            'branch_ref' => $branch,
            'worktree_path' => $worktree,
            'provider_called' => false,
            'owner_flow' => $ownerFlowSummary,
            'result_bridge_id' => (string) ($resultBridge['result_bridge_id'] ?? ''),
            'inbox_item_id' => $resultBridge['inbox_item_id'] ?? null,
            'inbox_emitted_before_merge_attempt' => true,
            'branch_created' => true,
            'worktree_created' => true,
        ];

        // AP-791: a cycle must NOT merge without an operator-visible pre-merge
        // inbox / AP-750 result-bridge evidence. If none was emitted, block the
        // cycle before any merge so nothing lands unaudited.
        $preMerge = $this->loopReceiptIntegrity()->preMergeGate($base);
        if (($preMerge['merge_allowed'] ?? false) !== true) {
            return $base + [
                'final_status' => 'blocked',
                'merge_performed' => false,
                'merge_skipped' => true,
                'continue_loop' => false,
                'pre_merge_gate' => $preMerge,
                'blockers' => [(string) ($preMerge['reason'] ?? AutonomousLoopReceiptIntegrityService::PRE_MERGE_INBOX_REQUIRED)],
            ];
        }

        // Owner runtime ran and AP-750 bridged, but the result is not a clean
        // completion: Evidence/Inbox are emitted, merge is withheld for review.
        if (($ownerFlow['merge_allowed'] ?? false) !== true) {
            return $this->governCycleOutcome($base + [
                'final_status' => 'cycle_completed_waiting_review_or_merge',
                'merge_performed' => false,
                'merge_skipped' => true,
                'continue_loop' => (bool) ($input['continue_on_blocked'] ?? false),
                'blockers' => array_values((array) ($ownerFlow['blockers'] ?? ['owner_runtime_result_not_completed'])),
            ], $areaId, $focus, $finding, $allowedFiles, $owner, $branch, $worktree, true);
        }

        $commit = $this->commitSandbox($worktree, $allowedFiles, $finding);
        $changedFiles = array_values((array) ($commit['changed_files'] ?? []));
        $postExecutionSkip = $this->postExecutionSkipReason($commit);
        if ($postExecutionSkip !== null) {
            return $this->governCycleOutcome($base + [
                'final_status' => 'cycle_completed_waiting_review_or_merge',
                'commit' => $commit,
                'changed_files' => $changedFiles,
                'merge_performed' => false,
                'merge_skipped' => true,
                'continue_loop' => false,
                'post_execution_skip' => $postExecutionSkip['reason'],
                'unsafe_files' => $postExecutionSkip['unsafe_files'] ?? [],
                'blockers' => $postExecutionSkip['blockers'],
            ], $areaId, $focus, $finding, $allowedFiles, $owner, $branch, $worktree, true);
        }

        $merge = $this->mergeGovernor->evaluate([
            'area_id' => $areaId,
            'repo_root' => $repoRoot,
            'base_ref' => 'main',
            'branch_ref' => $branch,
            'worktree_path' => $worktree,
            'auto_merge' => (bool) $input['auto_merge'],
            'execute_merge' => (bool) $input['auto_merge'],
            'auto_merge_class' => $class,
            'allow_code_auto_merge' => (bool) $input['allow_code_auto_merge'],
            'max_auto_merge_files' => (int) $input['max_auto_merge_files'],
            'run_validation' => true,
            'test_commands' => (array) $input['validation_commands'],
            'record_governance' => true,
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'spec_id' => (string) data_get($finding, 'spec_seed.candidate_id', ''),
            'sandbox_id' => (string) ($sandbox['sandbox_id'] ?? ''),
        ]);
        $pull = ((bool) $input['pull_main'] && ($merge['status'] ?? '') === StewardshipBranchMergeGovernorService::STATUS_MERGED)
            ? $this->pullMain($repoRoot)
            : ['status' => 'not_requested_or_not_merged'];
        $merged = ($merge['status'] ?? '') === StewardshipBranchMergeGovernorService::STATUS_MERGED;

        return $this->governCycleOutcome($base + [
            'final_status' => $merged ? 'cycle_completed' : 'cycle_completed_waiting_review_or_merge',
            'commit' => $commit,
            'changed_files' => $changedFiles,
            'merge_governance' => $merge,
            'pull_main' => $pull,
            'merge_performed' => $merged,
            'continue_loop' => $merged,
            'blockers' => $merged ? [] : array_values((array) ($merge['blockers'] ?? ['merge_not_performed'])),
        ], $areaId, $focus, $finding, $allowedFiles, $owner, $branch, $worktree, true);
    }

    /**
     * @param  list<string>  $commands
     * @param  list<string>  $allowedFiles
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function runValidationWithRepair(array $commands, string $worktree, array $allowedFiles, array $finding): array
    {
        $validation = $this->runValidation($commands, $worktree);
        if (($validation['passed'] ?? null) !== false) {
            return $validation + ['repair' => ['attempted' => false, 'retried' => false]];
        }

        $changed = $this->changedFiles($worktree);
        $unsafe = array_values(array_filter(
            $changed,
            static fn (string $file): bool => ! in_array($file, $allowedFiles, true),
        ));
        if ($unsafe !== [] || $changed === []) {
            return $validation + [
                'repair' => [
                    'attempted' => false,
                    'retried' => false,
                    'skipped_reason' => $unsafe !== [] ? 'diff_outside_allowed_files' : 'no_changes_to_retry',
                ],
            ];
        }

        $retry = $this->runValidation($commands, $worktree);

        return $retry + [
            'repair' => [
                'attempted' => true,
                'retried' => true,
                'first_passed' => false,
                'second_passed' => ($retry['passed'] ?? null) === true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    private function governCycleOutcome(
        array $cycle,
        string $areaId,
        string $focus,
        array $finding,
        array $allowedFiles,
        string $owner,
        string $branch,
        string $worktree,
        bool $execute,
    ): array {
        if (! $execute) {
            return $cycle;
        }

        $blockers = array_values(array_filter((array) ($cycle['blockers'] ?? []), 'is_string'));
        if ($blockers === []) {
            return $cycle;
        }

        $policy = $this->quarantine()->repairPolicyForBlockers($blockers);
        $changedFiles = array_values((array) ($cycle['changed_files'] ?? $this->changedFiles($worktree)));
        $cycle['repair_policy'] = $policy;

        if ($policy['emit_failure_capsule'] === true) {
            $cycle['failure_capsule'] = $this->quarantine()->buildFailureCapsule($blockers, $finding, $allowedFiles, $changedFiles);
        }

        if (($cycle['validation']['repair']['retried'] ?? false) === true) {
            $cycle['retried'] = true;
            if (($cycle['validation']['passed'] ?? null) === true) {
                $cycle['repaired'] = true;

                return $cycle;
            }
            $cycle['quarantine_after_repair_exhausted'] = true;
        }

        if (($cycle['validation']['repair']['attempted'] ?? false) === true
            && ($cycle['validation']['passed'] ?? null) === true) {
            $cycle['repaired'] = true;

            return $cycle;
        }

        if (! $this->isFactoryMaxStarvationRecoveryFinding($finding)
            && $this->quarantine()->shouldQuarantine($blockers, $cycle)) {
            $cycle['quarantine'] = $this->quarantine()->appendFromCycle($areaId, $focus, $finding, $blockers, [
                'owner' => $owner,
                'branch_ref' => $branch,
                'worktree_path' => $worktree,
                'cycle_id' => (string) ($cycle['cycle_id'] ?? ''),
                'sandbox_id' => (string) ($cycle['sandbox_id'] ?? ''),
                'post_execution_skip' => (string) ($cycle['post_execution_skip'] ?? ''),
                'reason' => (string) $policy['reason'],
            ]);
            $cycle['quarantined'] = true;
            $cycle['continue_loop'] = $policy['stop_session'] ? false : (bool) ($cycle['continue_loop'] ?? false);
        }

        if ($policy['stop_session'] === true) {
            $cycle['stop_session_after_blocker'] = true;
        }

        return $cycle;
    }

    /**
     * @param  array<string,mixed>  $ownerFlow
     * @return array<string,mixed>
     */
    private function ownerFlowSummary(array $ownerFlow): array
    {
        return [
            'status' => (string) ($ownerFlow['status'] ?? ''),
            'uses_full_owner_runtime_chain' => (bool) ($ownerFlow['uses_full_owner_runtime_chain'] ?? false),
            'provider_router_used' => (bool) ($ownerFlow['provider_router_used'] ?? false),
            'merge_allowed' => (bool) ($ownerFlow['merge_allowed'] ?? false),
            'consumption_id' => (string) ($ownerFlow['consumption_id'] ?? ''),
            'release_id' => (string) ($ownerFlow['release_id'] ?? ''),
            'queue_item_id' => (string) ($ownerFlow['queue_item_id'] ?? ''),
            'owner_execution_id' => (string) ($ownerFlow['owner_execution_id'] ?? ''),
            'owner_sandbox_run_id' => (string) ($ownerFlow['owner_sandbox_run_id'] ?? ''),
            'owner_result_status' => (string) data_get($ownerFlow, 'owner_result.result_status', ''),
            'ap750_result_bridge_status' => (string) data_get($ownerFlow, 'result_bridge.status', ''),
            'steps' => array_values((array) ($ownerFlow['steps'] ?? [])),
            'blockers' => array_values((array) ($ownerFlow['blockers'] ?? [])),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $decision
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    private function invokeProvider(array $input, array $decision, array $finding, array $allowedFiles, string $worktree): array
    {
        $prompt = [
            'schema_version' => 'atlas.software_company_stewardship.ap786_cursor_task.v1',
            'decision_receipt_id' => (string) $decision['decision_receipt_id'],
            'decision_receipt_hash' => (string) $decision['decision_receipt_hash'],
            'task' => [
                'title' => (string) ($finding['title'] ?? ''),
                'detail' => (string) ($finding['detail'] ?? ''),
                'why_it_matters' => (string) ($finding['why_it_matters'] ?? ''),
                'requested_outcome' => 'Implement the smallest correct fix inside allowed_files only. Prefer tests/docs when sufficient. Do not touch forbidden files. Do not merge, push, deploy or change secrets.',
            ],
            'scope_contract' => [
                'allowed_files' => $allowedFiles,
                'forbidden_files' => self::FORBIDDEN_PATHS,
            ],
            'validation_commands' => (array) $input['validation_commands'],
        ];

        return $this->providerRouter->driverInvoke((string) $input['provider'], [
            'provider' => (string) $input['provider'],
            'model' => (string) $input['model'],
            'prompt' => $prompt,
            'cwd' => $worktree,
            'workspace' => ['path' => $worktree],
            'timeout_seconds' => 900,
            'max_output_chars' => 24000,
            'decision_receipt_id' => (string) $decision['decision_receipt_id'],
            'decision_receipt_hash' => (string) $decision['decision_receipt_hash'],
        ]);
    }

    /**
     * @param  list<string>  $commands
     * @return array<string,mixed>
     */
    private function runValidation(array $commands, string $worktree): array
    {
        $results = [];
        $passed = true;
        foreach ($commands as $command) {
            $process = Process::fromShellCommandline($command, $worktree, AtlasSecurity::processEnv(profile: 'tool'));
            $process->setTimeout(180);
            $process->run();
            $ok = $process->isSuccessful();
            $passed = $passed && $ok;
            $results[] = [
                'command' => $command,
                'ok' => $ok,
                'exit_code' => $process->getExitCode(),
                'output_excerpt' => substr(AtlasSecurity::redactString(trim($process->getOutput()."\n".$process->getErrorOutput())), 0, 2000),
            ];
        }

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap786_validation.v1',
            'passed' => $commands === [] ? null : $passed,
            'commands' => $commands,
            'results' => $results,
        ];
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function commitSandbox(string $worktree, array $allowedFiles, array $finding): array
    {
        $changed = $this->changedFiles($worktree);
        if ($changed === []) {
            return ['status' => 'no_changes', 'changed_files' => []];
        }

        $unsafe = array_values(array_filter($changed, fn (string $file): bool => ! in_array($file, $allowedFiles, true)));
        if ($unsafe !== []) {
            return ['status' => 'blocked_scope_violation', 'changed_files' => $changed, 'unsafe_files' => $unsafe];
        }

        $add = $this->git($worktree, array_merge(['add', '--'], $changed));
        if (! $add['ok']) {
            return ['status' => 'git_add_failed', 'git' => $add, 'changed_files' => $changed];
        }

        $title = trim((string) ($finding['title'] ?? 'Autonomous stewardship cycle'));
        $message = 'Atlas autonomous evolution: '.$title;
        $commit = $this->git($worktree, ['commit', '-m', substr($message, 0, 180)]);

        return [
            'status' => $commit['ok'] ? 'committed' : 'git_commit_failed',
            'changed_files' => $changed,
            'git' => $commit,
            'commit_hash' => $commit['ok'] ? trim((string) $this->git($worktree, ['rev-parse', 'HEAD'])['out']) : '',
        ];
    }

    /**
     * @return list<string>
     */
    private function changedFiles(string $worktree): array
    {
        $status = $this->git($worktree, ['status', '--porcelain', '--untracked-files=all']);
        if (! $status['ok']) {
            return [];
        }
        $files = [];
        foreach (preg_split('/\R/', rtrim((string) $status['out'], "\r\n")) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $path = strlen($line) >= 4 && ctype_space($line[2])
                ? substr($line, 3)
                : preg_replace('/\A[ MADRCU?!]{1,2}\s+/', '', $line);
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }
            if (str_contains($path, ' -> ')) {
                $parts = explode(' -> ', $path);
                $path = trim((string) end($parts));
            }
            $files[] = $path;
        }

        return $this->productChangedFiles(array_values(array_filter($files)));
    }

    /**
     * Atlas control-plane files may be generated inside a sandbox to pass
     * provider contracts and receipts. They are not product changes and must
     * not be staged, committed, merged or counted as loop progress.
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    private function productChangedFiles(array $files): array
    {
        return array_values(array_unique(array_filter(
            $files,
            static fn (string $file): bool => ! str_starts_with($file, '.atlas/')
                && $file !== '.atlas'
        )));
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<string>
     */
    private function validationCommands(array $input): array
    {
        $commands = array_values(array_filter((array) ($input['validation_commands'] ?? []), 'is_string'));
        if ($commands === []) {
            $commands[] = 'git diff --check';
        }

        return $commands;
    }

    /**
     * Forge owner-runtime inputs forwarded to the AP-787 dispatch bridge. In
     * autonomous mode these are usually absent, so owner=forge blocks honestly
     * with a precise reason (forge_obra_required etc.) instead of being faked.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function forgeInputs(array $input): array
    {
        $forge = is_array($input['forge_inputs'] ?? null) ? $input['forge_inputs'] : [];
        foreach ([
            'forge_obra', 'obra_id', 'forge_live_topology', 'forge_live_decision',
            'forge_dispatch_mode', 'forge_role', 'forge_provider_authorization',
            'forge_budget_approved', 'forge_tickets', 'forge_agents',
        ] as $key) {
            if (array_key_exists($key, $input)) {
                $forge[$key] = $input[$key];
            }
        }

        return $forge;
    }

    private function scopeProfile(string $value): string
    {
        $profile = strtolower(trim($value));

        return $profile === self::SCOPE_FACTORY_MAX ? self::SCOPE_FACTORY_MAX : self::SCOPE_BALANCED;
    }

    /** @return array<string,mixed> */
    private function selectionScopeClaim(string $scopeProfile): array
    {
        if ($scopeProfile !== self::SCOPE_FACTORY_MAX) {
            return [
                'profile' => self::SCOPE_BALANCED,
                'objective' => 'balanced autonomous area improvement',
            ];
        }

        return [
            'profile' => self::SCOPE_FACTORY_MAX,
            'objective' => 'maximize Atlas software factory power per cycle',
            'rejects' => [
                'docs_only',
                'missing_evidence_only',
                'cosmetic_or_surface_only',
                'work_without_direct_dev_forge_or_factory_runtime_impact',
            ],
            'requires' => [
                'direct runtime/test impact on AAEOS, Atlas Dev, Forge, provider routing, sandbox, merge, evidence, replay, priority, or scheduler',
                'isolated branch/worktree and merge governance',
            ],
        ];
    }

    /** @param list<string> $files */
    private function allDocs(array $files): bool
    {
        return $files !== [] && count(array_filter(
            $files,
            static fn (string $file): bool => str_starts_with($file, 'docs/') || str_ends_with($file, '.md'),
        )) === count($files);
    }

    /** @param list<string> $files */
    private function touchesFactoryRuntime(array $files): bool
    {
        foreach ($files as $file) {
            if ($this->factoryRuntimeFile($file)) {
                return true;
            }
            if (str_starts_with($file, 'tests/')) {
                $source = $this->sourcePathFromTestPath($file);
                if ($source !== '' && $this->factoryRuntimeFile($source)) {
                    return true;
                }
                if (str_contains($file, '/AgenticEngineeringOs/')
                    || str_contains($file, '/AtlasForge/')
                    || str_contains($file, '/Programming/')
                    || str_contains($file, '/ProgrammingRuntime/')
                    || str_contains($file, '/SoftwareCompanyStewardship/AreaFocusLoop/')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function factoryRuntimeFile(string $file): bool
    {
        foreach (self::FACTORY_MAX_RUNTIME_PREFIXES as $prefix) {
            if (str_starts_with($file, $prefix)) {
                return true;
            }
        }

        return in_array($file, self::FACTORY_MAX_STEWARDSHIP_FILES, true);
    }

    private function sourcePathFromTestPath(string $file): string
    {
        if (! str_starts_with($file, 'tests/Unit/Ai/')) {
            return '';
        }
        $tail = substr($file, strlen('tests/Unit/Ai/'));

        return 'app/Services/Ai/'.$tail;
    }

    /**
     * Review-locked findings already produced a branch/InBox item and failed
     * merge governance. The autonomous loop must keep moving instead of
     * repeatedly generating branches for the same unresolved review packet.
     *
     * @return array<string,true>
     */
    private function reviewLockedFindingKeys(string $areaId, string $repoRoot): array
    {
        $path = $this->recordPath($areaId);
        if (! is_file($path)) {
            return [];
        }

        $locked = [];
        $handle = fopen($path, 'rb');
        if (! is_resource($handle)) {
            return [];
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $record = json_decode($line, true);
                if (! is_array($record)) {
                    continue;
                }
                foreach ((array) ($record['cycles'] ?? []) as $cycle) {
                    if (! is_array($cycle)) {
                        continue;
                    }
                    $status = (string) ($cycle['final_status'] ?? '');
                    $blockers = array_values(array_filter((array) ($cycle['blockers'] ?? []), 'is_string'));
                    if ($status === 'cycle_completed') {
                        // Completed findings already landed on main. Locking them
                        // across daemon invocations prevents a factory seed from
                        // burning cycles on the same completed improvement.
                    } elseif ($this->isWastedCycleBlockerSet($blockers)) {
                        if ($this->isRetryableRoutingBlockerSet($blockers)) {
                            // Routing failures are governed by AP-790 quarantine
                            // retry windows. Once that append-only quarantine
                            // expires, do not let the historical session record
                            // turn the finding into a permanent review lock.
                            continue;
                        }
                        // Some owner-flow failures still surface as
                        // cycle_completed_waiting_review_or_merge because they emit
                        // evidence/inbox receipts. The blocker is the source of
                        // truth for wasted-cycle quarantine.
                    } elseif ($status === 'cycle_completed_waiting_review_or_merge') {
                        $branch = (string) ($cycle['branch_ref'] ?? '');
                        if ($branch === '' || $this->branchMergedIntoMain($repoRoot, $branch)) {
                            continue;
                        }
                    } elseif ($status === 'blocked' && $this->isWastedCycleBlockerSet($blockers)) {
                        if ($this->isRetryableRoutingBlockerSet($blockers)) {
                            continue;
                        }
                        // A blocked cycle with a wasted-cycle signature already
                        // spent provider/runtime budget and should stay locked
                        // until a different repair path exists.
                    } elseif ($status !== 'blocked') {
                        continue;
                    } else {
                        // Plain governance/authority blockers are often
                        // transient. Do not permanently starve them from the
                        // long-running AP-790 loop; the runner's own
                        // blocked-in-row/quarantine policy decides whether to
                        // retry, repair or stop.
                        continue;
                    }
                    if ($status !== 'cycle_completed'
                        && $this->isFactoryMaxStarvationRecoveryFinding((array) ($cycle['selected_finding'] ?? []))) {
                        continue;
                    }
                    foreach ($this->findingKeys((array) ($cycle['selected_finding'] ?? [])) as $key) {
                        $locked[$key] = true;
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        return $locked;
    }

    private function recentFactoryMaintenanceCycleCount(string $areaId): int
    {
        $path = $this->recordPath($areaId);
        if (! is_file($path)) {
            return 0;
        }

        $cycles = [];
        $handle = fopen($path, 'rb');
        if (! is_resource($handle)) {
            return 0;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $record = json_decode($line, true);
                if (! is_array($record)) {
                    continue;
                }
                foreach ((array) ($record['cycles'] ?? []) as $cycle) {
                    if (is_array($cycle)) {
                        $status = (string) ($cycle['final_status'] ?? '');
                        if ($status === self::STATUS_DRY_RUN || str_starts_with($status, 'dry_run')) {
                            continue;
                        }
                        $cycles[] = $cycle;
                        if (count($cycles) > self::FACTORY_MAX_MAINTENANCE_STREAK_LIMIT + 3) {
                            array_shift($cycles);
                        }
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        $count = 0;
        foreach (array_reverse($cycles) as $cycle) {
            $status = (string) ($cycle['final_status'] ?? '');
            if (! in_array($status, ['cycle_completed', 'cycle_completed_waiting_review_or_merge'], true)) {
                break;
            }
            $finding = is_array($cycle['selected_finding'] ?? null) ? $cycle['selected_finding'] : [];
            if (! $this->isFactoryMaintenanceFinding($finding)) {
                break;
            }
            $count++;
        }

        return $count;
    }

    /** @param array<string,mixed> $finding */
    private function isFactoryMaintenanceFinding(array $finding): bool
    {
        $title = strtolower((string) ($finding['title'] ?? ''));
        $originType = strtolower((string) ($finding['origin_type'] ?? ''));
        $reason = strtolower((string) ($finding['autonomous_execution_reason'] ?? ''));

        return $originType === 'missing_test'
            || str_contains($reason, 'missing_test')
            || str_starts_with($title, 'missing test for ');
    }

    /** @param array<string,true> $locked */
    private function findingIsReviewLocked(array $finding, array $locked): bool
    {
        foreach ($this->findingKeys($finding) as $key) {
            if (isset($locked[$key])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,true> */
    private function normalizeReviewLocked(mixed $locked): array
    {
        $normalized = [];
        foreach ((array) $locked as $key => $value) {
            if ($value === true && is_string($key) && $key !== '') {
                $normalized[$key] = true;
                continue;
            }
            if (is_string($value) && trim($value) !== '') {
                $normalized[trim($value)] = true;
            }
        }

        return $normalized;
    }

    /** @return list<string> */
    private function findingKeys(array $finding): array
    {
        return array_values(array_unique(array_filter([
            (string) ($finding['finding_id'] ?? ''),
            (string) ($finding['finding_hash'] ?? ''),
            (string) ($finding['title'] ?? ''),
        ], static fn (string $value): bool => $value !== '')));
    }

    /**
     * @param  array<string,mixed>  $providerResult
     * @param  list<string>  $allowedFiles
     * @return array{reason:string,blockers:list<string>,unsafe_files?:list<string>}|null
     */
    private function postProviderSkipReason(array $providerResult, string $worktree, array $allowedFiles): ?array
    {
        $blockers = array_values(array_filter((array) ($providerResult['blockers'] ?? []), 'is_string'));
        if ($blockers !== []) {
            return [
                'reason' => 'provider_reported_blockers',
                'blockers' => $blockers,
            ];
        }
        if ((bool) ($providerResult['provider_called'] ?? false) !== true) {
            return [
                'reason' => 'provider_not_called',
                'blockers' => ['provider_not_called'],
            ];
        }

        $changed = $this->changedFiles($worktree);
        if ($changed === []) {
            return [
                'reason' => 'provider_produced_no_changes',
                'blockers' => ['provider_produced_no_changes'],
            ];
        }

        $unsafe = array_values(array_filter(
            $changed,
            static fn (string $file): bool => ! in_array($file, $allowedFiles, true),
        ));
        if ($unsafe !== []) {
            return [
                'reason' => 'provider_scope_violation',
                'blockers' => ['provider_scope_violation'],
                'unsafe_files' => $unsafe,
            ];
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $commit
     * @return array{reason:string,blockers:list<string>,unsafe_files?:list<string>}|null
     */
    private function postExecutionSkipReason(array $commit): ?array
    {
        $commitStatus = (string) ($commit['status'] ?? '');
        if ($commitStatus === 'committed') {
            return null;
        }

        if ($commitStatus === 'no_changes') {
            return [
                'reason' => 'commit_no_changes',
                'blockers' => ['provider_produced_no_changes'],
            ];
        }

        if ($commitStatus === 'blocked_scope_violation') {
            return [
                'reason' => 'commit_scope_violation',
                'blockers' => ['provider_scope_violation'],
                'unsafe_files' => array_values((array) ($commit['unsafe_files'] ?? [])),
            ];
        }

        return [
            'reason' => 'commit_failed',
            'blockers' => ['commit_failed'],
        ];
    }

    /**
     * AP-786 only spends sandbox/provider budget on findings explicitly cleared
     * for autonomous execution (factory-max seeds, operator-authorized packets).
     */
    private function findingAllowsAutonomousExecution(array $finding): bool
    {
        if (($finding['auto_execution_allowed'] ?? false) !== true) {
            return false;
        }

        return ($finding['operator_review_required'] ?? true) !== true;
    }

    /** @param list<string> $blockers */
    private function shouldStopSessionAfterBlockedCycle(array $blockers): bool
    {
        return in_array('no_candidate_with_allowed_files', $blockers, true)
            || in_array('auto_execution_not_allowed', $blockers, true)
            || in_array('provider_scope_violation', $blockers, true);
    }

    /** @param list<string> $blockers */
    private function isWastedCycleBlockerSet(array $blockers): bool
    {
        foreach ($blockers as $blocker) {
            if (in_array($blocker, self::WASTED_CYCLE_BLOCKERS, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $blockers */
    private function isRetryableRoutingBlockerSet(array $blockers): bool
    {
        return in_array('owner_runtime_routing_not_executable', $blockers, true);
    }

    private function branchMergedIntoMain(string $repoRoot, string $branch): bool
    {
        $branchExists = $this->git($repoRoot, ['rev-parse', '--verify', '--quiet', $branch], 30);
        if (! $branchExists['ok']) {
            return true;
        }
        $merged = $this->git($repoRoot, ['merge-base', '--is-ancestor', $branch, 'main'], 30);

        return $merged['ok'];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return array<string,string>
     */
    private function decisionReceipt(string $cycleId, array $finding, array $allowedFiles, string $owner): array
    {
        $receipt = [
            'schema_version' => 'atlas.software_company_stewardship.ap786_decision_receipt.v1',
            'decision_receipt_id' => 'ap786_decision_'.$cycleId,
            'decision' => 'execute_autonomous_evolution_cycle',
            'owner' => $owner,
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'finding_hash' => (string) ($finding['finding_hash'] ?? ''),
            'allowed_files' => $allowedFiles,
            'operator_actor' => 'operator_session_authorization',
        ];
        $receipt['decision_receipt_hash'] = 'sha256:'.MissionCanonicalHash::sha256($receipt);

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $finding
     */
    private function owner(array $finding): string
    {
        $owner = strtolower((string) ($finding['owner_candidate'] ?? data_get($finding, 'spec_seed.route_hint_owner', 'atlas_dev')));

        return $owner === 'forge' ? 'forge' : 'atlas_dev';
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     */
    private function autoMergeClass(array $finding, array $allowedFiles): string
    {
        $kind = strtolower((string) ($finding['kind'] ?? ''));
        if ($kind === 'test') {
            return 'test';
        }
        if ($allowedFiles !== [] && count(array_filter($allowedFiles, fn (string $f): bool => str_starts_with($f, 'docs/') || str_ends_with($f, '.md'))) === count($allowedFiles)) {
            return 'documentation';
        }
        if ($allowedFiles !== [] && count(array_filter($allowedFiles, fn (string $f): bool => str_starts_with($f, 'tests/'))) === count($allowedFiles)) {
            return 'test';
        }
        if ($kind === 'bug') {
            return 'bugfix';
        }
        if ($kind === 'cleanup') {
            return 'cleanup';
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $providerResult
     * @param  array<string,mixed>  $validation
     * @param  array<string,mixed>  $commit
     * @param  list<string>  $changedFiles
     * @return array<string,mixed>
     */
    private function executionResult(string $cycleId, string $areaId, string $owner, array $finding, array $sandbox, array $providerResult, array $validation, array $commit, array $changedFiles): array
    {
        return [
            'schema_version' => 'atlas.software_company_stewardship.ap786_execution_result.v1',
            'execution_id' => $cycleId,
            'area_id' => $areaId,
            'owner' => $owner,
            'result_status' => (($providerResult['blockers'] ?? []) === [] && ($commit['status'] ?? '') === 'committed') ? 'completed' : 'partial',
            'summary' => 'Atlas found "'.(string) ($finding['title'] ?? 'finding').'", invoked Cursor CLI in an isolated sandbox, committed the scoped result and produced merge governance.',
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'spec_id' => (string) data_get($finding, 'spec_seed.candidate_id', ''),
            'handoff_id' => 'AP-786:'.$cycleId,
            'sandbox_id' => (string) ($sandbox['sandbox_id'] ?? ''),
            'branch_ref' => (string) data_get($sandbox, 'materialization.branch_name', ''),
            'worktree_path' => (string) data_get($sandbox, 'materialization.worktree_path', ''),
            'changed_files' => $changedFiles,
            'tests' => (array) ($validation['commands'] ?? []),
            'validation_commands' => (array) ($validation['commands'] ?? []),
            'test_results' => (array) ($validation['results'] ?? []),
            'evidence_pack' => [
                'summary' => 'Cursor CLI provider result plus AP-786 validation and git commit receipt.',
                'changed_files' => $changedFiles,
                'tests' => (array) ($validation['commands'] ?? []),
                'test_results' => (array) ($validation['results'] ?? []),
                'provider' => $providerResult['provider'] ?? 'cursor_cli',
                'model' => $providerResult['model'] ?? null,
                'commit_hash' => (string) ($commit['commit_hash'] ?? ''),
            ],
            'risks' => array_values((array) ($providerResult['blockers'] ?? [])),
            'rollback' => 'Revert the ff-only merge if merged, or delete the isolated branch/worktree if not merged.',
            'provider_invoked' => (bool) ($providerResult['provider_called'] ?? false),
            'provider' => (string) ($providerResult['provider'] ?? 'cursor_cli'),
            'model' => (string) ($providerResult['model'] ?? ''),
            'merge_performed' => false,
            'deploy_performed' => false,
            'external_push_performed' => false,
            'secret_access' => false,
            'destructive_change' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $providerResult
     * @return array<string,mixed>
     */
    private function providerSummary(array $providerResult): array
    {
        return [
            'provider' => (string) ($providerResult['provider'] ?? ''),
            'model' => (string) ($providerResult['model'] ?? ''),
            'provider_called' => (bool) ($providerResult['provider_called'] ?? false),
            'external_provider_call' => (bool) ($providerResult['external_provider_call'] ?? false),
            'exit_code' => $providerResult['exit_code'] ?? null,
            'duration_ms' => $providerResult['duration_ms'] ?? null,
            'changed_files' => array_values((array) ($providerResult['changed_files'] ?? [])),
            'blockers' => array_values((array) ($providerResult['blockers'] ?? [])),
            'note' => (string) ($providerResult['note'] ?? ''),
        ];
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
            'why_it_matters' => (string) ($finding['why_it_matters'] ?? ''),
            'proposed_next_action' => (string) ($finding['proposed_next_action'] ?? ''),
            'starvation_state_hash' => (string) ($finding['starvation_state_hash'] ?? ''),
            'terminal_backlog_state_hash' => (string) ($finding['terminal_backlog_state_hash'] ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function pullMain(string $repoRoot): array
    {
        $checkout = $this->git($repoRoot, ['checkout', 'main'], 120);
        if (! $checkout['ok']) {
            return ['status' => 'checkout_main_failed', 'git' => $checkout];
        }
        $pull = $this->git($repoRoot, ['pull', '--ff-only'], 180);

        return [
            'status' => $pull['ok'] ? 'main_updated' : 'pull_failed_or_no_upstream',
            'git' => $pull,
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function blockedCycle(string $cycleId, int $cycleIndex, array $blockers, array $extra = []): array
    {
        return [
            'cycle_id' => $cycleId,
            'cycle_index' => $cycleIndex,
            'final_status' => 'blocked',
            'continue_loop' => false,
            'blockers' => $blockers,
        ] + $extra;
    }

    /**
     * @param  list<array<string,mixed>>  $cycles
     */
    private function anyCycleFlag(array $cycles, string $key): bool
    {
        foreach ($cycles as $cycle) {
            if ((bool) ($cycle[$key] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $blockers
     * @return list<string>
     */
    private function nextActions(string $status, array $blockers): array
    {
        if ($status === self::STATUS_COMPLETED) {
            return ['Session completed. Review the emitted Inbox items and git history; the next scheduler tick can run another AP-786 session.'];
        }
        if ($status === self::STATUS_DRY_RUN) {
            return ['Dry-run only. Re-run with --execute to invoke Cursor CLI in a real AP-756 sandbox.'];
        }

        return ['Resolve blockers before continuing: '.implode(', ', $blockers)];
    }

    private function repoRoot(string $value): string
    {
        $candidate = trim($value);
        if ($candidate === '' && function_exists('base_path')) {
            $candidate = base_path();
        }
        if ($candidate === '') {
            $candidate = getcwd() ?: '';
        }

        return realpath($candidate) ?: $candidate;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): string => is_string($item) ? $item : '',
            $value,
        ), fn (string $item): bool => trim($item) !== ''));
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('/\s+/', '', $path) ?? $path;

        return ltrim($path, '/');
    }

    private function forbidden(string $path): bool
    {
        foreach (self::FORBIDDEN_PATHS as $forbidden) {
            if (str_starts_with($path, rtrim($forbidden, '/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $args
     * @return array{ok:bool,exit_code:int|null,out:string,err:string}
     */
    private function git(string $cwd, array $args, int $timeout = 60): array
    {
        if (! is_dir($cwd)) {
            return [
                'ok' => false,
                'exit_code' => null,
                'out' => '',
                'err' => 'cwd_missing:'.$cwd,
            ];
        }

        $process = new Process(array_merge(['git'], $args), $cwd, AtlasSecurity::processEnv(profile: 'tool'), null, $timeout);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'exit_code' => $process->getExitCode(),
            'out' => AtlasSecurity::redactString($process->getOutput()),
            'err' => AtlasSecurity::redactString($process->getErrorOutput()),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function record(string $areaId, array $payload): array
    {
        File::ensureDirectoryExists(dirname($this->recordPath($areaId)));
        $record = ['schema_version' => self::RECORD_SCHEMA, 'recorded_at' => $this->now()] + $payload;
        File::append($this->recordPath($areaId), json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $record + ['session_storage_status' => 'recorded'];
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($value)) ?: '');

        return trim($slug, '_') ?: self::DEFAULT_AREA_ID;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
