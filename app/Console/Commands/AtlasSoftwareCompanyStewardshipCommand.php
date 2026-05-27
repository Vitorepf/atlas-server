<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtlasAreaFocusLoopReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeReleaseService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOwnerQueueConsumptionGateService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FirstFullCycleOrchestratorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchLifecycleRegistryService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipPriorityEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveOperatingService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipPromotionReadinessService;
use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\AutonomousExecutiveAllocationHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\AutonomousExecutiveRecommendationService;
use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\ExecutiveDecisionInboxSurfaceService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\AtlasContinuousStewardshipLoopService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\AtlasContinuousStewardshipRecurringSchedulerService;
use App\Console\Commands\Concerns\RendersContinuousStewardshipRunner;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship\PortfolioStewardshipHealthModelService;
use App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship\PortfolioStewardshipInboxService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeCockpitSurfaceService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeOperationalControlReceiptService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeOperationalControlsReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\NewAreaProposalGateService;
use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\SelfExpandingDomainRuntimeCreationHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\SelfExpandingSoftwareCompanyService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\DevForgeRuntimeExecutionBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionDecisionLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeExecutionAdapterService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeResultBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerSandboxRuntimeRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipNativeObraRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOutcomeEvidenceBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipCompletionAuditService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipLiveCycleCertificationService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Atlas Software Company Stewardship Stack · read-only CLI.
 *
 * Atlas Software Company Stewardship Stack is a stack/capability family inside
 * the Atlas Autonomous Software Company Runtime, not a new OS.
 *
 * Exposes the AP-716 Area Focus Loop core read-only read-model. No writes, no
 * provider, no branch, no merge/deploy/secrets.
 */
class AtlasSoftwareCompanyStewardshipCommand extends Command
{
    use RendersContinuousStewardshipRunner;

    protected $signature = 'atlas:software-company-stewardship
        {action=area-focus : area-focus|first-full-cycle|first-full-cycles|first-full-cycle-replay|priority-rank|branch-lifecycle-reserve|branch-lifecycle-records|branch-merge-governor|branch-merge-governance-records|area-focus-deep-scan|area-focus-deep-scans|area-focus-deep-scan-replay|completion-audit|live-cycle-certification|native-obra-runner|area-focus-dev-forge-release|area-focus-branch-sandbox-materialize|area-focus-branch-sandboxes|area-focus-branch-sandbox-replay|area-focus-branch-sandbox-cleanup|owner-queue-consumption-gate|owner-runtime-execute|owner-sandbox-runtime-run|owner-runtime-result-bridge|runtime-result-bridge|dev-forge-execute|product-mode-cockpit|product-mode-controls|product-mode-control-receipt|product-mode-control-receipts|product-mode-control-replay|outcome-evidence|domain-runtime-creation-handoff|evolution|area-stewardship|area-stewardship-readiness|area-stewardship-active-handoff|area-stewardship-active-operate|continuous-stewardship-loop|continuous-stewardship-scheduler|continuous-runner|continuous-runner-status|portfolio|portfolio-health|portfolio-health-record|portfolio-health-snapshots|portfolio-health-replay|portfolio-inbox|portfolio-inbox-record|portfolio-inbox-list|portfolio-inbox-replay|portfolio-inbox-decision|executive|executive-recommendations|executive-recommendation-record|executive-recommendation-list|executive-recommendation-replay|executive-recommendation-decision|executive-decision-inbox|executive-allocation-handoff|executive-allocation-handoff-list|executive-allocation-handoff-replay|self-expanding|self-expanding-v0|new-area-proposal-gate|new-area-proposal-decision|evolution-decision|evolution-decisions|evolution-replay}
        {--area=agentic_engineering_os : Canonical area_id to focus}
        {--focus=dev_forge : AP-748 deep-scan focus slice (e.g. dev_forge)}
        {--max-findings= : AP-748 cap on emitted deep-scan findings}
        {--record : AP-748 append-only persist the deep-scan read-model as JSONL}
        {--scan-id= : AP-748 deep-scan id for replay}
        {--priority-file= : AP-771 JSON file containing candidates, findings, branches or a deep_scan_report}
        {--cycle-id= : AP-768 first-full-cycle id for replay}
        {--portfolio=atlas_software_company : Canonical portfolio_id}
        {--repo=atlas-server : Product Mode repository slug for AP-754 controls}
        {--repo-authorization-status=authorized_for_atlas_internal : AP-754 repo authorization status}
        {--autonomy-tier=2 : AP-754 requested autonomy tier}
        {--max-allowed-autonomy-tier=2 : AP-754 max allowed autonomy tier}
        {--cycle-budget=3 : AP-754 max admitted cycles}
        {--used-cycles=0 : AP-754 used cycles}
        {--branch-wip-limit=2 : AP-754 branch WIP limit}
        {--active-branch-count=0 : AP-754 active branch count}
        {--provider-call-limit=0 : AP-754 provider call limit before owner runtime gate}
        {--provider-calls-used=0 : AP-754 provider calls used}
        {--control-type=repo_authorization : AP-755 Product Mode control type}
        {--use-recorded-controls : AP-755 apply accepted Product Mode control receipts to AP-754/AP-739 projections}
        {--pause-product-mode : AP-754 project Product Mode as paused}
        {--lock-active : AP-754 project Product Mode lock as active}
        {--rate-limited : AP-754 project Product Mode as rate limited}
        {--inbox-id= : AP-734 portfolio inbox id for replay}
        {--item-id= : AP-734 portfolio inbox item id for decision}
        {--pack-id= : AP-735 executive recommendation pack id for replay}
        {--recommendation-id= : AP-735 executive recommendation id for decision}
        {--proposal-id= : AP-737 new area proposal id for filtering or decision}
        {--actor= : Operator actor for AP-731 decisions}
        {--decision= : AP-731 decision: accept|reject|defer|request_changes}
        {--target-type= : AP-731 target type}
        {--target-id= : AP-731 target id}
        {--target-hash= : AP-731 target hash}
        {--risk=medium : AP-731 risk: low|medium|high|critical}
        {--rationale= : AP-731 operator rationale}
        {--decision-id= : AP-731 decision id for replay}
        {--snapshot-id= : AP-733 portfolio health snapshot id for replay}
        {--record-evidence : AP-740 append idempotent Evidence Ledger events}
        {--emit-inbox : AP-740 emit deduped Morning Inbox proposal items}
        {--record-handoff : AP-741 append idempotent Domain Runtime Creation Gate handoff packets}
        {--record-active-handoff : AP-743 append idempotent Area Stewardship active handoff packets}
        {--record-active-operation : AP-744 append idempotent Area Stewardship active operation records}
        {--enable-continuous-loop : AP-745 allow exactly one scheduler-safe Continuous Stewardship Loop tick}
        {--record-continuous-cycle : AP-745 append idempotent Continuous Stewardship Loop cycle records}
        {--force-continuous-tick : AP-745 bypass min interval for explicit manual verification}
        {--enable-continuous-scheduler : AP-746 allow one recurring scheduler-safe invocation of AP-745}
        {--record-scheduler-run : AP-746 append idempotent recurring scheduler run records}
        {--record-release : AP-747 append idempotent Area Focus Dev/Forge release queue record}
        {--force-scheduler-run : AP-746 bypass due/rate limit only for explicit manual verification}
        {--pause-until= : AP-746 pause recurring scheduler until ISO timestamp}
        {--scheduler-id=atlas_continuous_stewardship_loop : AP-746 scheduler id}
        {--kill-switch : AP-745 force Product Mode kill switch active for the tick}
        {--min-interval-seconds=900 : AP-745 min seconds between recorded continuous ticks}
        {--allow-projected-evidence : AP-741 dry-run with projected AP-740 evidence instead of recorded ledger evidence}
        {--preflight-file= : AP-747 JSON file containing AP-726 preflight/handoff report}
        {--operator-receipts-file= : AP-724 JSON or JSONL file containing explicit Area Focus operator decision receipts}
        {--release-receipt-file= : AP-747 JSON file containing explicit operator release receipt}
        {--sandbox-receipt-file= : AP-756 JSON file containing explicit operator sandbox materialization receipt}
        {--materialize-sandbox : AP-756 actually create the isolated git branch/worktree}
        {--record-sandbox : AP-756 append idempotent sandbox JSONL record}
        {--repo-root= : AP-756 git repository root for sandbox materialization}
        {--base-ref=HEAD : AP-756 base ref for git worktree materialization}
        {--branch-ref= : AP-769 cycle branch ref for merge governance}
        {--record-branch-registry : AP-770 append branch lifecycle registry reservation}
        {--lifecycle-status=reserved : AP-770 lifecycle status reserved|materialized|merged|released}
        {--auto-merge : AP-769 request policy-gated automatic ff-only merge}
        {--execute-merge : AP-769 actually perform the ff-only merge when policy allows}
        {--auto-merge-class= : AP-769 operator-declared class: documentation|test|bugfix|cleanup}
        {--allow-code-auto-merge : AP-769 permit code auto-merge only with validation passing and safe declared class}
        {--max-auto-merge-files=5 : AP-769 maximum changed files for auto-merge eligibility}
        {--run-validation : AP-769 run --test-command validations before auto-merge decision}
        {--record-governance : AP-769 append merge governance record}
        {--sandbox-id= : AP-756 sandbox id for replay or cleanup}
        {--remove-sandbox : AP-756 actually remove the isolated git worktree during cleanup}
        {--allow-dirty-removal : AP-756 allow cleanup to remove a worktree that has uncommitted changes}
        {--delete-branch : AP-756 also delete the sandbox branch during cleanup}
        {--allow-unmerged-branch-delete : AP-756 allow cleanup to delete a branch with unmerged commits}
        {--release-file= : AP-748 JSON or JSONL file containing AP-747 release reports/records for outcome evidence}
        {--release-id= : AP-749 release id to select from --release-file}
        {--outcome-file= : AP-749 JSON file containing AP-748/AP-740 outcome bridge report}
        {--sandbox-record-file= : AP-757 JSON or JSONL file containing AP-756 materialized sandbox record for AP-749}
        {--execution-receipt-file= : AP-749 JSON file containing explicit owner execution receipt}
        {--record-consumption : AP-749 append idempotent owner queue consumption record}
        {--consumption-file= : AP-750 JSON or JSONL file containing AP-749 consumption reports/records}
        {--consumption-id= : AP-750 consumption id to select from --consumption-file}
        {--runtime-start-receipt-file= : AP-758 JSON file containing explicit operator runtime start receipt}
        {--record-execution : AP-758 append idempotent owner runtime execution adapter record}
        {--execution-file= : AP-759 JSON or JSONL file containing AP-758 owner runtime execution reports/records}
        {--owner-execution-id= : AP-759 owner execution id to select from --execution-file}
        {--runtime-command-receipt-file= : AP-759 JSON file containing explicit operator owner command receipt}
        {--execute-owner-command : AP-759 actually run the allowlisted owner command inside AP-756 worktree}
        {--record-owner-run : AP-759 append idempotent owner sandbox runtime run record}
        {--result-file= : AP-750 JSON file containing Atlas Dev/Forge owner runtime result receipt}
        {--approval-file= : AP-750 JSON file containing explicit irreversible action approval receipt}
        {--record-result : AP-750 append idempotent owner runtime result bridge record}
        {--execution= : AP-765 execution id reference folded into the runtime result bridge receipt}
        {--fixture : AP-765 use the built-in canonical execution_result fixture (for smoke/demo)}
        {--owner= : AP-765 owner that produced the runtime result (e.g. atlas_dev|atlas_forge)}
        {--finding-id= : AP-765 Area Focus finding id for the closed cycle}
        {--spec-id= : AP-765 Self-Directed Evolution spec id for the closed cycle}
        {--record-event : AP-765 append idempotent Product Mode runtime result visibility event}
        {--record-cycle : AP-765 append idempotent runtime result bridge cycle receipt}
        {--record-allocation-handoff : AP-752 append idempotent Autonomous Executive allocation handoff packet}
        {--handoff-id= : AP-752 allocation handoff packet id for replay}
        {--workspace=atlas-server : AP-747 Atlas Dev workspace slug for dev queue items}
        {--certification-worktree= : AP-762 certification-only sandbox worktree path}
        {--include-execution-certification : AP-763 also runs AP-762 owner-command execution certification inside the certification sandbox}
        {--mode=dry-run : AP-766 continuous runner mode: dry-run|execute}
        {--enable-continuous-runner : AP-766 enable the Continuous Stewardship Runner control plane for this invocation}
        {--area-kill-switch : AP-766 force the per-area continuous runner kill switch active}
        {--max-runs-per-day= : AP-766 daily run budget per area (admitted execute ticks); defaults to config}
        {--runner-lock-ttl-seconds= : AP-766 runner lock lease TTL in seconds; defaults to config}
        {--record-runner-run : AP-766 append idempotent continuous runner run receipts (always on in execute mode)}
        {--enable-native-obra-runner : AP-764 allow the Atlas-native Obra runner to invoke the AP-746 scheduler boundary}
        {--record-native-obra-run : AP-764 append idempotent native Obra runner records}
        {--provider-execution-authorized : AP-764 declares provider execution authorization was supplied; still requires AP-759 receipts before provider calls}
        {--handoff= : AP-767 approved handoff id for the dev-forge-execute first-cycle bridge}
        {--sandbox= : AP-767 branch sandbox id for the dev-forge-execute first-cycle bridge}
        {--sandbox-descriptor-file= : AP-767 JSON file with a sandbox descriptor (AP-756 record or flat test descriptor) for dev-forge-execute}
        {--allowed-file=* : AP-767 allowed_files scope for dev-forge-execute (repeatable)}
        {--test-command=* : AP-767 allowlisted test command for dev-forge-execute, space-separated (repeatable)}
        {--run-local-task : AP-767 run the allowlisted read-only + test local deterministic owner task inside the sandbox}
        {--run-real-atlas-dev : AP-768 execute the Atlas Dev Senior Engineer Loop in a materialized sandbox for first-full-cycle}
        {--real-atlas-dev-intent= : AP-768 operator-approved implementation intent for --run-real-atlas-dev}
        {--record-bridge-result : AP-767 append idempotent dev-forge-execute runtime execution receipt}
        {--json : Emit JSON}';

    protected $description = 'Atlas Software Company Stewardship Stack · read-only/proposal read-models plus append-only review ledgers. No provider, no branch, no merge/deploy/secrets.';

    public function handle(
        AtlasAreaFocusLoopReadModelService $readModel,
        AreaFocusDeepFindingEngineService $deepFindingEngine,
        FirstFullCycleOrchestratorService $firstFullCycle,
        AreaFocusDevForgeReleaseService $areaFocusDevForgeRelease,
        AreaFocusBranchSandboxMaterializerService $branchSandboxMaterializer,
        StewardshipBranchLifecycleRegistryService $branchLifecycleRegistry,
        StewardshipBranchMergeGovernorService $branchMergeGovernor,
        StewardshipPriorityEngineService $priorityEngine,
        AreaFocusOwnerQueueConsumptionGateService $ownerQueueConsumptionGate,
        StewardshipEvolutionReadModelService $evolution,
        StewardshipEvolutionDecisionLedgerService $ledger,
        AreaStewardshipPromotionReadinessService $areaStewardshipReadiness,
        AreaStewardshipActiveHandoffService $areaStewardshipActiveHandoff,
        AreaStewardshipActiveOperatingService $areaStewardshipActiveOperating,
        AtlasContinuousStewardshipLoopService $continuousStewardshipLoop,
        AtlasContinuousStewardshipRecurringSchedulerService $continuousStewardshipScheduler,
        ContinuousStewardshipRunnerService $continuousStewardshipRunner,
        PortfolioStewardshipHealthModelService $portfolioHealth,
        PortfolioStewardshipInboxService $portfolioInbox,
        AutonomousExecutiveRecommendationService $executiveRecommendations,
        ExecutiveDecisionInboxSurfaceService $executiveDecisionInbox,
        AutonomousExecutiveAllocationHandoffService $executiveAllocationHandoff,
        NewAreaProposalGateService $newAreaProposalGate,
        SelfExpandingSoftwareCompanyService $selfExpanding,
        ProductModeCockpitSurfaceService $productModeCockpit,
        ProductModeOperationalControlReceiptService $productModeControlReceipts,
        ProductModeOperationalControlsReadModelService $productModeControls,
        StewardshipOutcomeEvidenceBridgeService $outcomeEvidence,
        SelfExpandingDomainRuntimeCreationHandoffService $domainRuntimeCreationHandoff,
        StewardshipOwnerRuntimeExecutionAdapterService $ownerRuntimeExecutionAdapter,
        StewardshipOwnerSandboxRuntimeRunnerService $ownerSandboxRuntimeRunner,
        StewardshipOwnerRuntimeResultBridgeService $ownerRuntimeResultBridge,
        StewardshipRuntimeResultBridgeService $runtimeResultBridge,
        StewardshipLiveCycleCertificationService $liveCycleCertification,
        StewardshipCompletionAuditService $completionAudit,
        StewardshipNativeObraRunnerService $nativeObraRunner,
        DevForgeRuntimeExecutionBridgeService $devForgeRuntimeExecutionBridge,
    ): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'area-focus' => $this->runAreaFocus($readModel),
            'first-full-cycle' => $this->runFirstFullCycle($firstFullCycle),
            'first-full-cycles' => $this->runFirstFullCycleList($firstFullCycle),
            'first-full-cycle-replay' => $this->runFirstFullCycleReplay($firstFullCycle),
            'priority-rank' => $this->runPriorityRank($priorityEngine),
            'branch-lifecycle-reserve' => $this->runBranchLifecycleReserve($branchLifecycleRegistry),
            'branch-lifecycle-records' => $this->runBranchLifecycleRecords($branchLifecycleRegistry),
            'branch-merge-governor' => $this->runBranchMergeGovernor($branchMergeGovernor),
            'branch-merge-governance-records' => $this->runBranchMergeGovernanceRecords($branchMergeGovernor),
            'area-focus-deep-scan' => $this->runAreaFocusDeepScan($deepFindingEngine),
            'area-focus-deep-scans' => $this->runAreaFocusDeepScanList($deepFindingEngine),
            'area-focus-deep-scan-replay' => $this->runAreaFocusDeepScanReplay($deepFindingEngine),
            'completion-audit' => $this->runCompletionAudit($completionAudit),
            'live-cycle-certification' => $this->runLiveCycleCertification($liveCycleCertification),
            'native-obra-runner' => $this->runNativeObraRunner($nativeObraRunner),
            'area-focus-dev-forge-release' => $this->runAreaFocusDevForgeRelease($areaFocusDevForgeRelease),
            'area-focus-branch-sandbox-materialize' => $this->runAreaFocusBranchSandboxMaterialize($branchSandboxMaterializer),
            'area-focus-branch-sandboxes' => $this->runAreaFocusBranchSandboxList($branchSandboxMaterializer),
            'area-focus-branch-sandbox-replay' => $this->runAreaFocusBranchSandboxReplay($branchSandboxMaterializer),
            'area-focus-branch-sandbox-cleanup' => $this->runAreaFocusBranchSandboxCleanup($branchSandboxMaterializer),
            'owner-queue-consumption-gate' => $this->runOwnerQueueConsumptionGate($ownerQueueConsumptionGate),
            'owner-runtime-execute' => $this->runOwnerRuntimeExecute($ownerRuntimeExecutionAdapter),
            'owner-sandbox-runtime-run' => $this->runOwnerSandboxRuntimeRun($ownerSandboxRuntimeRunner),
            'owner-runtime-result-bridge' => $this->runOwnerRuntimeResultBridge($ownerRuntimeResultBridge),
            'runtime-result-bridge' => $this->runRuntimeResultBridge($runtimeResultBridge),
            'dev-forge-execute' => $this->runDevForgeExecute($devForgeRuntimeExecutionBridge),
            'product-mode-cockpit' => $this->runProductModeCockpit($productModeCockpit, $productModeControlReceipts),
            'product-mode-controls' => $this->runProductModeControls($productModeControls, $productModeControlReceipts),
            'product-mode-control-receipt' => $this->runProductModeControlReceipt($productModeControlReceipts),
            'product-mode-control-receipts' => $this->runProductModeControlReceiptList($productModeControlReceipts),
            'product-mode-control-replay' => $this->runProductModeControlReceiptReplay($productModeControlReceipts),
            'outcome-evidence' => $this->runOutcomeEvidence($outcomeEvidence),
            'domain-runtime-creation-handoff' => $this->runDomainRuntimeCreationHandoff($domainRuntimeCreationHandoff),
            'evolution' => $this->runEvolution($evolution),
            'area-stewardship' => $this->runEvolution($evolution, 'area_stewardship'),
            'area-stewardship-readiness' => $this->runAreaStewardshipReadiness($areaStewardshipReadiness),
            'area-stewardship-active-handoff' => $this->runAreaStewardshipActiveHandoff($areaStewardshipActiveHandoff),
            'area-stewardship-active-operate' => $this->runAreaStewardshipActiveOperate($areaStewardshipActiveOperating),
            'continuous-stewardship-loop' => $this->runContinuousStewardshipLoop($continuousStewardshipLoop),
            'continuous-stewardship-scheduler' => $this->runContinuousStewardshipScheduler($continuousStewardshipScheduler),
            'continuous-runner' => $this->runContinuousRunner($continuousStewardshipRunner),
            'continuous-runner-status' => $this->runContinuousRunnerStatus($continuousStewardshipRunner),
            'portfolio' => $this->runEvolution($evolution, 'portfolio_stewardship'),
            'portfolio-health' => $this->runPortfolioHealth($portfolioHealth),
            'portfolio-health-record' => $this->runPortfolioHealthRecord($portfolioHealth),
            'portfolio-health-snapshots' => $this->runPortfolioHealthSnapshots($portfolioHealth),
            'portfolio-health-replay' => $this->runPortfolioHealthReplay($portfolioHealth),
            'portfolio-inbox' => $this->runPortfolioInbox($portfolioInbox),
            'portfolio-inbox-record' => $this->runPortfolioInboxRecord($portfolioInbox),
            'portfolio-inbox-list' => $this->runPortfolioInboxList($portfolioInbox),
            'portfolio-inbox-replay' => $this->runPortfolioInboxReplay($portfolioInbox),
            'portfolio-inbox-decision' => $this->runPortfolioInboxDecision($portfolioInbox),
            'executive' => $this->runEvolution($evolution, 'autonomous_executive'),
            'executive-recommendations' => $this->runExecutiveRecommendations($executiveRecommendations),
            'executive-recommendation-record' => $this->runExecutiveRecommendationRecord($executiveRecommendations),
            'executive-recommendation-list' => $this->runExecutiveRecommendationList($executiveRecommendations),
            'executive-recommendation-replay' => $this->runExecutiveRecommendationReplay($executiveRecommendations),
            'executive-recommendation-decision' => $this->runExecutiveRecommendationDecision($executiveRecommendations),
            'executive-decision-inbox' => $this->runExecutiveDecisionInbox($executiveDecisionInbox),
            'executive-allocation-handoff' => $this->runExecutiveAllocationHandoff($executiveAllocationHandoff),
            'executive-allocation-handoff-list' => $this->runExecutiveAllocationHandoffList($executiveAllocationHandoff),
            'executive-allocation-handoff-replay' => $this->runExecutiveAllocationHandoffReplay($executiveAllocationHandoff),
            'self-expanding' => $this->runEvolution($evolution, 'self_expanding_software_company'),
            'self-expanding-v0' => $this->runSelfExpandingV0($selfExpanding),
            'new-area-proposal-gate' => $this->runNewAreaProposalGate($newAreaProposalGate),
            'new-area-proposal-decision' => $this->runNewAreaProposalDecision($newAreaProposalGate),
            'evolution-decision' => $this->runEvolutionDecision($ledger),
            'evolution-decisions' => $this->runEvolutionDecisionList($ledger),
            'evolution-replay' => $this->runEvolutionDecisionReplay($ledger),
            default => $this->blockedResult('unknown_action', $action),
        };
    }

    private function runAreaStewardshipReadiness(AreaStewardshipPromotionReadinessService $service): int
    {
        $payload = $service->assess(['area_id' => (string) $this->option('area')]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('Area Stewardship readiness', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Target', (string) ($p['target_id'] ?? ''));
            $this->components->twoColumnDetail('Operator acceptance', (string) data_get($p, 'operator_acceptance.status', 'unknown'));
            foreach ($p['blockers'] ?? [] as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
        });

        return ($payload['status'] ?? '') === AreaStewardshipPromotionReadinessService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runAreaStewardshipActiveHandoff(AreaStewardshipActiveHandoffService $service): int
    {
        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'record_active_handoff' => (bool) $this->option('record-active-handoff'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-743 Area Stewardship active handoff', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Readiness', (string) ($p['readiness_status'] ?? ''));
            $this->components->twoColumnDetail('Packets', (string) ($p['active_handoff_count'] ?? 0));
            $this->components->twoColumnDetail('Recorded', ((bool) ($p['record_active_handoff_requested'] ?? false)) ? 'requested' : 'projection-only');
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === AreaStewardshipActiveHandoffService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runAreaStewardshipActiveOperate(AreaStewardshipActiveOperatingService $service): int
    {
        $operatorReceipts = $this->operatorReceiptsFromOption();
        if ($operatorReceipts === null) {
            return $this->blockedResult('operator_receipts_file_invalid', '--operator-receipts-file must be a readable JSON object, JSON array, or JSONL file.');
        }

        $payload = $service->operate([
            'area_id' => (string) $this->option('area'),
            'record_active_operation' => (bool) $this->option('record-active-operation'),
            'operator_receipts' => $operatorReceipts,
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-744 Area Stewardship active operation', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Operation', (string) ($p['operation_id'] ?? ''));
            $this->components->twoColumnDetail('Handoff', (string) ($p['active_handoff_status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Cycle', (string) ($p['operational_cycle_id'] ?? ''));
            $this->components->twoColumnDetail('Findings', (string) data_get($p, 'counts.findings', 0));
            $this->components->twoColumnDetail('Work orders', (string) data_get($p, 'counts.work_orders', 0));
            $this->components->twoColumnDetail('Spec drafts', (string) data_get($p, 'counts.spec_drafts', 0));
            $this->components->twoColumnDetail('Ready handoffs', (string) data_get($p, 'counts.ready_branch_handoffs', 0));
            $this->components->twoColumnDetail('Recorded', ((bool) ($p['record_active_operation_requested'] ?? false)) ? 'requested' : 'projection-only');
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === AreaStewardshipActiveOperatingService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runContinuousStewardshipLoop(AtlasContinuousStewardshipLoopService $service): int
    {
        $operatorReceipts = $this->operatorReceiptsFromOption();
        if ($operatorReceipts === null) {
            return $this->blockedResult('operator_receipts_file_invalid', '--operator-receipts-file must be a readable JSON object, JSON array, or JSONL file.');
        }

        $payload = $service->tick([
            'area_id' => (string) $this->option('area'),
            'enabled' => (bool) $this->option('enable-continuous-loop'),
            'record_continuous_cycle' => (bool) $this->option('record-continuous-cycle'),
            'force_continuous_tick' => (bool) $this->option('force-continuous-tick'),
            'kill_switch' => (bool) $this->option('kill-switch'),
            'min_interval_seconds' => (int) $this->option('min-interval-seconds'),
            'operator_receipts' => $operatorReceipts,
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-745 Continuous Stewardship Loop', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Tick', (string) ($p['tick_id'] ?? ''));
            $this->components->twoColumnDetail('AP-744 operation', (string) ($p['active_operation_status'] ?? 'not_run'));
            $this->components->twoColumnDetail('Recorded', ((bool) ($p['record_continuous_cycle_requested'] ?? false)) ? 'requested' : 'projection-only');
            $this->components->twoColumnDetail('Enabled', ((bool) data_get($p, 'policy.enabled', false)) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Kill switch', ((bool) data_get($p, 'policy.kill_switch_active', false)) ? 'active' : 'clear');
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return in_array((string) ($payload['status'] ?? ''), [
            AtlasContinuousStewardshipLoopService::STATUS_BLOCKED,
            AtlasContinuousStewardshipLoopService::STATUS_LOCKED,
        ], true)
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runContinuousStewardshipScheduler(AtlasContinuousStewardshipRecurringSchedulerService $service): int
    {
        $operatorReceipts = $this->operatorReceiptsFromOption();
        if ($operatorReceipts === null) {
            return $this->blockedResult('operator_receipts_file_invalid', '--operator-receipts-file must be a readable JSON object, JSON array, or JSONL file.');
        }

        $payload = $service->run([
            'area_id' => (string) $this->option('area'),
            'scheduler_id' => (string) $this->option('scheduler-id'),
            'enabled' => (bool) $this->option('enable-continuous-scheduler'),
            'continuous_loop_enabled' => (bool) $this->option('enable-continuous-scheduler'),
            'record_scheduler_run' => (bool) $this->option('record-scheduler-run'),
            'record_continuous_cycle' => (bool) $this->option('record-continuous-cycle'),
            'force_scheduler_run' => (bool) $this->option('force-scheduler-run'),
            'kill_switch' => (bool) $this->option('kill-switch'),
            'pause_until' => (string) ($this->option('pause-until') ?? ''),
            'min_interval_seconds' => (int) $this->option('min-interval-seconds'),
            'operator_receipts' => $operatorReceipts,
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-746 Continuous Stewardship Scheduler', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Scheduler', (string) ($p['scheduler_id'] ?? ''));
            $this->components->twoColumnDetail('Run', (string) ($p['scheduler_run_id'] ?? ''));
            $this->components->twoColumnDetail('AP-745 tick', (string) ($p['tick_status'] ?? data_get($p, 'continuous_loop.status', 'not_run')));
            $this->components->twoColumnDetail('Scheduler record', ((bool) ($p['record_scheduler_run_requested'] ?? false)) ? 'requested' : 'projection-only');
            $this->components->twoColumnDetail('Continuous record', ((bool) ($p['record_continuous_cycle_requested'] ?? false)) ? 'requested' : 'projection-only');
            $this->components->twoColumnDetail('Scheduler enabled', ((bool) data_get($p, 'policy.scheduler_enabled', false)) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Kill switch', ((bool) data_get($p, 'policy.kill_switch_active', false)) ? 'active' : 'clear');
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return in_array((string) ($payload['status'] ?? ''), [
            AtlasContinuousStewardshipRecurringSchedulerService::STATUS_BLOCKED,
            AtlasContinuousStewardshipRecurringSchedulerService::STATUS_LOCKED,
        ], true)
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runNativeObraRunner(StewardshipNativeObraRunnerService $service): int
    {
        $operatorReceipts = $this->operatorReceiptsFromOption();
        if ($operatorReceipts === null) {
            return $this->blockedResult('operator_receipts_file_invalid', '--operator-receipts-file must be a readable JSON object, JSON array, or JSONL file.');
        }

        $payload = $service->run([
            'area_id' => (string) $this->option('area'),
            'enable_native_obra_runner' => (bool) $this->option('enable-native-obra-runner'),
            'record_native_obra_run' => (bool) $this->option('record-native-obra-run'),
            'provider_execution_authorized' => (bool) $this->option('provider-execution-authorized'),
            'record_scheduler_run' => (bool) $this->option('record-scheduler-run'),
            'record_continuous_cycle' => (bool) $this->option('record-continuous-cycle'),
            'force_scheduler_run' => (bool) $this->option('force-scheduler-run'),
            'kill_switch' => (bool) $this->option('kill-switch'),
            'min_interval_seconds' => (int) $this->option('min-interval-seconds'),
            'operator_receipts' => $operatorReceipts,
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-764 native Obra runner', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Run', (string) ($p['native_obra_run_id'] ?? ''));
            $this->components->twoColumnDetail('Scheduler', (string) ($p['scheduler_run_status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Active operation', (string) ($p['active_operation_status'] ?? 'not_available'));
            $this->components->twoColumnDetail('Native Obra handoffs', (string) ($p['native_obra_handoff_count'] ?? 0));
            $this->components->twoColumnDetail('Recorded', ((bool) ($p['record_native_obra_run_requested'] ?? false)) ? 'requested' : 'projection-only');
            $this->components->twoColumnDetail('Codex app automation', ((bool) data_get($p, 'claim_policy.codex_app_automation_used', false)) ? 'used' : 'not used');
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === StewardshipNativeObraRunnerService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runAreaFocus(AtlasAreaFocusLoopReadModelService $readModel): int
    {
        $payload = $readModel->project(['area_id' => (string) $this->option('area')]);

        $this->emit($payload, function (array $p): void {
            $stack = is_array($p['stewardship_stack'] ?? null) ? $p['stewardship_stack'] : [];
            $this->components->twoColumnDetail('Stewardship Stack', (string) ($stack['level'] ?? 'Area Focus Loop').' (read-only)');
            $this->components->twoColumnDetail('Schema', (string) ($p['schema_version'] ?? ''));
            $this->components->twoColumnDetail('Status', (string) ($p['status'] ?? 'unknown'));

            $contract = is_array($p['area_contract'] ?? null) ? $p['area_contract'] : null;
            if ($contract !== null) {
                $this->components->twoColumnDetail('Area', (string) ($contract['area_name'] ?? '').' ('.(string) ($contract['area_id'] ?? '').')');
            }

            $readiness = is_array($p['readiness'] ?? null) ? $p['readiness'] : [];
            $checks = is_array($readiness['checks'] ?? null) ? $readiness['checks'] : [];
            $this->components->twoColumnDetail(
                'Owner docs present',
                (string) ($checks['owner_docs_present'] ?? 0).'/'.(string) ($checks['owner_docs_total'] ?? 0),
            );
            $this->components->twoColumnDetail('Finding seeds', (string) ($p['finding_seed_count'] ?? 0));

            foreach ($p['finding_seeds'] ?? [] as $seed) {
                $this->line(sprintf(
                    '  [%s] %s · %s -> %s',
                    (string) ($seed['severity'] ?? '?'),
                    (string) ($seed['kind'] ?? ''),
                    (string) ($seed['summary'] ?? ''),
                    (string) ($seed['recommended_owner'] ?? ''),
                ));
            }
            foreach ($p['blockers'] ?? [] as $blocker) {
                $this->warn(sprintf(
                    '  blocker: %s · %s',
                    (string) ($blocker['reason'] ?? '?'),
                    (string) ($blocker['detail'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runAreaFocusDeepScan(AreaFocusDeepFindingEngineService $service): int
    {
        $maxFindings = $this->option('max-findings');
        $input = [
            'area_id' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'record' => (bool) $this->option('record'),
        ];
        if ($maxFindings !== null && $maxFindings !== '' && is_numeric($maxFindings)) {
            $input['max_findings'] = (int) $maxFindings;
        }

        $payload = $service->scan($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-748 Area Focus deep scan', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Focus', (string) ($p['focus'] ?? '').' ('.(string) ($p['focus_label'] ?? '').')');
            $this->components->twoColumnDetail('Scan', (string) ($p['scan_id'] ?? ''));
            $this->components->twoColumnDetail('Mode', (string) ($p['mode'] ?? ''));
            $this->components->twoColumnDetail('Findings', (string) ($p['finding_count'] ?? 0).(($p['capped'] ?? false) ? ' (capped)' : ''));
            $this->components->twoColumnDetail('In focus', (string) data_get($p, 'focus_summary.in_focus', 0));
            $this->components->twoColumnDetail('Recorded', (string) (data_get($p, 'record.recorded', false) ? 'yes' : (($p['mode'] ?? '') === 'record' ? 'idempotent/skipped' : 'projection-only')));
            foreach (array_slice((array) ($p['findings'] ?? []), 0, 10) as $finding) {
                $this->line(sprintf(
                    '  [%s] %s · %s -> %s · %s',
                    (string) ($finding['severity'] ?? '?'),
                    (string) ($finding['kind'] ?? ''),
                    (string) ($finding['title'] ?? ''),
                    (string) ($finding['owner_candidate'] ?? ''),
                    ((bool) ($finding['in_focus'] ?? false)) ? 'in-focus' : 'out',
                ));
            }
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) ($blocker['reason'] ?? '?').' · '.(string) ($blocker['detail'] ?? ''));
            }
        });

        return ($payload['status'] ?? '') === AreaFocusDeepFindingEngineService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runAreaFocusDeepScanList(AreaFocusDeepFindingEngineService $service): int
    {
        $payload = $service->listScans((string) $this->option('area'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-748 deep scans', (string) ($p['scan_count'] ?? 0));
            foreach ((array) ($p['scans'] ?? []) as $scan) {
                $this->line(sprintf(
                    '  %s · %s · %s · %d findings · %s',
                    (string) ($scan['scan_id'] ?? ''),
                    (string) ($scan['focus'] ?? ''),
                    (string) ($scan['status'] ?? ''),
                    (int) ($scan['finding_count'] ?? 0),
                    (string) ($scan['recorded_at'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runAreaFocusDeepScanReplay(AreaFocusDeepFindingEngineService $service): int
    {
        $scanId = trim((string) $this->option('scan-id'));
        if ($scanId === '') {
            return $this->blockedResult('scan_id_required', '--scan-id is required for area-focus-deep-scan-replay');
        }

        $payload = $service->replay($scanId);
        if ($payload === null) {
            return $this->blockedResult('scan_not_found', $scanId);
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-748 deep scan replay', (string) ($p['scan_id'] ?? ''));
            $this->components->twoColumnDetail('Focus', (string) ($p['focus'] ?? ''));
            $this->components->twoColumnDetail('Status', (string) ($p['status'] ?? ''));
            $this->components->twoColumnDetail('Findings', (string) ($p['finding_count'] ?? 0));
        });

        return self::SUCCESS;
    }

    private function runFirstFullCycle(FirstFullCycleOrchestratorService $service): int
    {
        $input = [
            'area_id' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'mode' => (string) $this->option('mode'),
            'owner' => (string) ($this->option('owner') ?? ''),
            'actor' => (string) ($this->option('actor') ?? ''),
            'record' => (bool) $this->option('record'),
            'materialize_sandbox' => (bool) $this->option('materialize-sandbox'),
            'run_local_task' => (bool) $this->option('run-local-task'),
            'run_real_atlas_dev' => (bool) $this->option('run-real-atlas-dev'),
            'real_atlas_dev_intent' => (string) ($this->option('real-atlas-dev-intent') ?? ''),
            'allowed_files' => array_values(array_filter((array) $this->option('allowed-file'), 'is_string')),
            'test_commands' => array_values(array_filter((array) $this->option('test-command'), 'is_string')),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'base_ref' => (string) ($this->option('base-ref') ?: 'main'),
            'branch_ref' => (string) ($this->option('branch-ref') ?: ''),
            'auto_merge' => (bool) $this->option('auto-merge'),
            'execute_merge' => (bool) $this->option('execute-merge'),
            'auto_merge_class' => (string) ($this->option('auto-merge-class') ?? ''),
            'allow_code_auto_merge' => (bool) $this->option('allow-code-auto-merge'),
            'max_auto_merge_files' => (int) ($this->option('max-auto-merge-files') ?: 5),
            'run_validation' => (bool) $this->option('run-validation'),
            'record_merge_governance' => (bool) $this->option('record-governance'),
            'emit_inbox' => (bool) $this->option('emit-inbox'),
        ];
        $maxFindings = $this->option('max-findings');
        if ($maxFindings !== null && $maxFindings !== '' && is_numeric($maxFindings)) {
            $input['max_findings'] = (int) $maxFindings;
        }
        $preflight = $this->readJsonFile((string) ($this->option('preflight-file') ?? ''));
        if (is_array($preflight)) {
            $input['preflight_report'] = $preflight;
        }
        $sandboxReceipt = $this->readJsonFile((string) ($this->option('sandbox-receipt-file') ?? ''));
        if (is_array($sandboxReceipt)) {
            $input['sandbox_receipt'] = $sandboxReceipt;
        }
        $sandboxDescriptor = $this->readJsonFile((string) ($this->option('sandbox-descriptor-file') ?? ''));
        if (is_array($sandboxDescriptor)) {
            $input['sandbox_descriptor'] = $sandboxDescriptor;
        }

        $payload = $service->run($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-768 first full cycle', (string) ($p['final_status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area / Focus', (string) ($p['area_id'] ?? '').' / '.(string) ($p['focus'] ?? ''));
            $this->components->twoColumnDetail('Mode', (string) ($p['mode'] ?? ''));
            $this->components->twoColumnDetail('Cycle', (string) ($p['cycle_id'] ?? ''));
            $this->components->twoColumnDetail('Scan', (string) ($p['scan_id'] ?? ''));
            $this->components->twoColumnDetail('Recorded', (string) ($p['cycle_storage_status'] ?? 'projected'));
            $finding = is_array($p['selected_finding'] ?? null) ? $p['selected_finding'] : [];
            $this->components->twoColumnDetail('Selected finding', (string) ($finding['title'] ?? '(none)'));
            foreach ((array) ($p['stage_order'] ?? []) as $key) {
                $stage = data_get($p, 'stages.'.$key, []);
                $this->line(sprintf('  [%s] %s · %s — %s',
                    (string) data_get($stage, 'status', '?'),
                    (string) data_get($stage, 'ap_contract', ''),
                    (string) $key,
                    (string) data_get($stage, 'note', ''),
                ));
            }
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_operator_action'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['final_status'] ?? '') === FirstFullCycleOrchestratorService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runFirstFullCycleList(FirstFullCycleOrchestratorService $service): int
    {
        $payload = $service->listCycles((string) $this->option('area'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-768 cycles', (string) ($p['cycle_count'] ?? 0));
            foreach ((array) ($p['cycles'] ?? []) as $cycle) {
                $this->line(sprintf('  %s · %s · %s · %s · %s',
                    (string) ($cycle['cycle_id'] ?? ''),
                    (string) ($cycle['focus'] ?? ''),
                    (string) ($cycle['mode'] ?? ''),
                    (string) ($cycle['final_status'] ?? ''),
                    (string) ($cycle['recorded_at'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runFirstFullCycleReplay(FirstFullCycleOrchestratorService $service): int
    {
        $cycleId = trim((string) $this->option('cycle-id'));
        if ($cycleId === '') {
            return $this->blockedResult('cycle_id_required', '--cycle-id is required for first-full-cycle-replay');
        }

        $payload = $service->replay($cycleId, (string) $this->option('area'));
        if ($payload === null) {
            return $this->blockedResult('cycle_not_found', $cycleId);
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-768 cycle replay', (string) ($p['cycle_id'] ?? ''));
            $this->components->twoColumnDetail('Final status', (string) ($p['final_status'] ?? ''));
            $this->components->twoColumnDetail('Focus', (string) ($p['focus'] ?? ''));
        });

        return self::SUCCESS;
    }

    private function runPriorityRank(StewardshipPriorityEngineService $service): int
    {
        $input = [
            'area_id' => (string) $this->option('area'),
        ];
        $filePayload = $this->readJsonFile((string) ($this->option('priority-file') ?? ''));
        if (is_array($filePayload)) {
            $input += $filePayload;
            if (array_is_list($filePayload)) {
                $input['candidates'] = $filePayload;
            }
        } else {
            $scan = $this->readJsonFile((string) ($this->option('scan-id') ?? ''));
            if (is_array($scan)) {
                $input['deep_scan_report'] = $scan;
            }
        }

        $payload = $service->rank($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-771 priority engine', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Candidates', (string) ($p['candidate_count'] ?? 0));
            $top = is_array($p['top_candidate'] ?? null) ? $p['top_candidate'] : [];
            $this->components->twoColumnDetail('Top candidate', (string) ($top['title'] ?? '(none)'));
            $this->components->twoColumnDetail('Top score', (string) ($top['priority_score'] ?? ''));
            foreach (array_slice((array) ($p['ranked_candidates'] ?? []), 0, 10) as $item) {
                $this->line(sprintf(
                    '  #%d %s · %s · %s',
                    (int) ($item['rank'] ?? 0),
                    (string) ($item['priority_score'] ?? ''),
                    (string) ($item['priority_band'] ?? ''),
                    (string) ($item['title'] ?? ''),
                ));
            }
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
        });

        return ($payload['status'] ?? '') === StewardshipPriorityEngineService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runBranchLifecycleReserve(StewardshipBranchLifecycleRegistryService $service): int
    {
        $branchRef = trim((string) ($this->option('branch-ref') ?? ''));
        if ($branchRef === '') {
            return $this->blockedResult('branch_ref_required', '--branch-ref is required for branch-lifecycle-reserve');
        }

        $payload = $service->reserve([
            'area_id' => (string) $this->option('area'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'base_ref' => (string) ($this->option('base-ref') ?: 'HEAD'),
            'branch_name' => $branchRef,
            'sandbox_id' => (string) ($this->option('sandbox-id') ?? ''),
            'owner' => (string) ($this->option('owner') ?? ''),
            'lifecycle_status' => (string) ($this->option('lifecycle-status') ?? 'reserved'),
            'record_branch_registry' => (bool) $this->option('record-branch-registry'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-770 branch lifecycle registry', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Branch', (string) data_get($p, 'branch_identity.branch_name', ''));
            $this->components->twoColumnDetail('Registry', (string) ($p['registry_id'] ?? ''));
            $this->components->twoColumnDetail('Storage', (string) ($p['registry_storage_status'] ?? 'projected'));
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === StewardshipBranchLifecycleRegistryService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runBranchLifecycleRecords(StewardshipBranchLifecycleRegistryService $service): int
    {
        $payload = $service->listRecords((string) $this->option('area'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-770 branch lifecycle records', (string) ($p['record_count'] ?? 0));
            $this->components->twoColumnDetail('Active', (string) ($p['active_record_count'] ?? 0));
            foreach ((array) ($p['records'] ?? []) as $record) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($record['status'] ?? ''),
                    (string) data_get($record, 'branch_identity.branch_name', ''),
                    (string) ($record['recorded_at'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runBranchMergeGovernor(StewardshipBranchMergeGovernorService $service): int
    {
        $branchRef = trim((string) ($this->option('branch-ref') ?? ''));
        if ($branchRef === '') {
            return $this->blockedResult('branch_ref_required', '--branch-ref is required for branch-merge-governor');
        }

        $payload = $service->evaluate([
            'area_id' => (string) $this->option('area'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'base_ref' => (string) ($this->option('base-ref') ?: 'main'),
            'branch_ref' => $branchRef,
            'auto_merge' => (bool) $this->option('auto-merge'),
            'execute_merge' => (bool) $this->option('execute-merge'),
            'auto_merge_class' => (string) ($this->option('auto-merge-class') ?? ''),
            'allow_code_auto_merge' => (bool) $this->option('allow-code-auto-merge'),
            'max_auto_merge_files' => (int) ($this->option('max-auto-merge-files') ?: 5),
            'run_validation' => (bool) $this->option('run-validation'),
            'test_commands' => array_values(array_filter((array) $this->option('test-command'), 'is_string')),
            'record_governance' => (bool) $this->option('record-governance'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-769 branch merge governor', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Branch', (string) data_get($p, 'repo.branch_ref', ''));
            $this->components->twoColumnDetail('Base', (string) data_get($p, 'repo.base_ref', ''));
            $this->components->twoColumnDetail('Changed files', (string) data_get($p, 'classification.changed_file_count', 0));
            $this->components->twoColumnDetail('Auto-merge eligible', data_get($p, 'auto_merge_policy.eligible') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Graph shape', (string) data_get($p, 'gitkraken_review_surface.graph_shape', ''));
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === StewardshipBranchMergeGovernorService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runBranchMergeGovernanceRecords(StewardshipBranchMergeGovernorService $service): int
    {
        $payload = $service->listRecords((string) $this->option('area'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-769 governance records', (string) ($p['record_count'] ?? 0));
            foreach ((array) ($p['records'] ?? []) as $record) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($record['status'] ?? ''),
                    (string) data_get($record, 'repo.branch_ref', ''),
                    (string) ($record['recorded_at'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runLiveCycleCertification(StewardshipLiveCycleCertificationService $service): int
    {
        $payload = $service->certify([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'workspace' => (string) $this->option('workspace'),
            'execute_owner_command' => (bool) $this->option('execute-owner-command'),
            'worktree_path' => (string) ($this->option('certification-worktree') ?? ''),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-762 live cycle certification', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Mode', (string) ($p['mode'] ?? ''));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Certification', (string) ($p['certification_id'] ?? ''));
            $this->components->twoColumnDetail('Review items', (string) data_get($p, 'counts.product_mode_review_items', 0));
            $this->components->twoColumnDetail('Owner results', (string) data_get($p, 'counts.owner_results', 0));
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === StewardshipLiveCycleCertificationService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runCompletionAudit(StewardshipCompletionAuditService $service): int
    {
        $payload = $service->audit([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'workspace' => (string) $this->option('workspace'),
            'worktree_path' => (string) ($this->option('certification-worktree') ?? ''),
            'include_execution_certification' => (bool) $this->option('include-execution-certification'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-763 completion audit', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Mode', (string) ($p['mode'] ?? ''));
            $this->components->twoColumnDetail('Current number', (string) ($p['current_practical_number'] ?? 0).'/'.(string) ($p['target_practical_number'] ?? 0));
            $this->components->twoColumnDetail('Proven', (string) ($p['proven_count'] ?? 0));
            $this->components->twoColumnDetail('Weak', (string) ($p['weak_count'] ?? 0));
            $this->components->twoColumnDetail('Missing', (string) ($p['missing_count'] ?? 0));
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
        });

        return ($payload['status'] ?? '') === StewardshipCompletionAuditService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runAreaFocusDevForgeRelease(AreaFocusDevForgeReleaseService $service): int
    {
        $preflightFile = (string) ($this->option('preflight-file') ?? '');
        $receiptFile = (string) ($this->option('release-receipt-file') ?? '');
        if ($preflightFile === '') {
            return $this->blockedResult('preflight_file_required', '--preflight-file is required for area-focus-dev-forge-release');
        }
        if ($receiptFile === '') {
            return $this->blockedResult('release_receipt_file_required', '--release-receipt-file is required for area-focus-dev-forge-release');
        }

        $preflight = $this->readJsonFile($preflightFile);
        if (! is_array($preflight)) {
            return $this->blockedResult('preflight_file_invalid', $preflightFile);
        }
        $receipt = $this->readJsonFile($receiptFile);
        if (! is_array($receipt)) {
            return $this->blockedResult('release_receipt_file_invalid', $receiptFile);
        }

        $payload = $service->release([
            'area_id' => (string) $this->option('area'),
            'preflight_report' => $preflight,
            'release_receipt' => $receipt,
            'record_release' => (bool) $this->option('record-release'),
            'workspace' => (string) $this->option('workspace'),
            'kill_switch' => (bool) $this->option('kill-switch'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-747 Dev/Forge release', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Target owner', (string) ($p['target_owner'] ?? ''));
            $this->components->twoColumnDetail('Release', (string) ($p['release_id'] ?? ''));
            $this->components->twoColumnDetail('Queue item', (string) data_get($p, 'queue_item.queue_item_id', ''));
            $this->components->twoColumnDetail('Recorded', ((bool) ($p['record_release_requested'] ?? false)) ? 'requested' : 'projection-only');
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === AreaFocusDevForgeReleaseService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runAreaFocusBranchSandboxMaterialize(AreaFocusBranchSandboxMaterializerService $service): int
    {
        $preflightFile = (string) ($this->option('preflight-file') ?? '');
        $receiptFile = (string) ($this->option('sandbox-receipt-file') ?? '');
        if ($preflightFile === '') {
            return $this->blockedResult('preflight_file_required', '--preflight-file is required for area-focus-branch-sandbox-materialize');
        }
        if ($receiptFile === '') {
            return $this->blockedResult('sandbox_receipt_file_required', '--sandbox-receipt-file is required for area-focus-branch-sandbox-materialize');
        }

        $preflight = $this->readJsonFile($preflightFile);
        if (! is_array($preflight)) {
            return $this->blockedResult('preflight_file_invalid', $preflightFile);
        }
        $receipt = $this->readJsonFile($receiptFile);
        if (! is_array($receipt)) {
            return $this->blockedResult('sandbox_receipt_file_invalid', $receiptFile);
        }

        $payload = $service->materialize([
            'area_id' => (string) $this->option('area'),
            'preflight_report' => $preflight,
            'sandbox_receipt' => $receipt,
            'materialize_sandbox' => (bool) $this->option('materialize-sandbox'),
            'record_sandbox' => (bool) $this->option('record-sandbox'),
            'repo_root' => (string) ($this->option('repo-root') ?: base_path()),
            'base_ref' => (string) $this->option('base-ref'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-756 branch sandbox', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Sandbox', (string) ($p['sandbox_id'] ?? ''));
            $this->components->twoColumnDetail('Branch', (string) data_get($p, 'materialization.branch_name', ''));
            $this->components->twoColumnDetail('Worktree', (string) data_get($p, 'materialization.worktree_path', ''));
            $this->components->twoColumnDetail('Recorded', (string) ($p['sandbox_storage_status'] ?? 'projected'));
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === AreaFocusBranchSandboxMaterializerService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runAreaFocusBranchSandboxList(AreaFocusBranchSandboxMaterializerService $service): int
    {
        $payload = $service->listSandboxes((string) $this->option('area'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-756 sandboxes', (string) ($p['sandbox_count'] ?? 0));
            foreach ((array) ($p['sandboxes'] ?? []) as $sandbox) {
                $this->line(sprintf(
                    '  %s · %s · %s · %s',
                    (string) ($sandbox['sandbox_id'] ?? ''),
                    (string) ($sandbox['status'] ?? ''),
                    (string) data_get($sandbox, 'materialization.branch_name', ''),
                    (string) ($sandbox['recorded_at'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runAreaFocusBranchSandboxReplay(AreaFocusBranchSandboxMaterializerService $service): int
    {
        $sandboxId = trim((string) $this->option('sandbox-id'));
        if ($sandboxId === '') {
            return $this->blockedResult('sandbox_id_required', '--sandbox-id is required for area-focus-branch-sandbox-replay');
        }

        $payload = $service->replay($sandboxId, (string) $this->option('area'));
        if ($payload === null) {
            return $this->blockedResult('sandbox_not_found', $sandboxId);
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-756 replay', (string) ($p['sandbox_id'] ?? ''));
            $this->components->twoColumnDetail('Status', (string) ($p['status'] ?? ''));
            $this->components->twoColumnDetail('Branch', (string) data_get($p, 'materialization.branch_name', ''));
            $this->components->twoColumnDetail('Worktree', (string) data_get($p, 'materialization.worktree_path', ''));
        });

        return self::SUCCESS;
    }

    private function runAreaFocusBranchSandboxCleanup(AreaFocusBranchSandboxMaterializerService $service): int
    {
        $sandboxId = trim((string) $this->option('sandbox-id'));
        if ($sandboxId === '') {
            return $this->blockedResult('sandbox_id_required', '--sandbox-id is required for area-focus-branch-sandbox-cleanup');
        }

        $payload = $service->cleanupSandbox([
            'sandbox_id' => $sandboxId,
            'area_id' => (string) $this->option('area'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'remove_sandbox' => (bool) $this->option('remove-sandbox'),
            'allow_dirty_removal' => (bool) $this->option('allow-dirty-removal'),
            'delete_branch' => (bool) $this->option('delete-branch'),
            'allow_unmerged_branch_delete' => (bool) $this->option('allow-unmerged-branch-delete'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-756 cleanup', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Sandbox', (string) ($p['sandbox_id'] ?? ''));
            $this->components->twoColumnDetail('Mode', (string) ($p['mode'] ?? ''));
            $this->components->twoColumnDetail('Worktree removed', data_get($p, 'actions.worktree_removed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Branch deleted', data_get($p, 'actions.branch_deleted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Recorded', (string) ($p['cleanup_storage_status'] ?? 'projected'));
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === AreaFocusBranchSandboxMaterializerService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runOwnerQueueConsumptionGate(AreaFocusOwnerQueueConsumptionGateService $service): int
    {
        $releaseFile = (string) ($this->option('release-file') ?? '');
        $outcomeFile = (string) ($this->option('outcome-file') ?? '');
        if ($releaseFile === '') {
            return $this->blockedResult('release_file_required', '--release-file is required for owner-queue-consumption-gate');
        }
        if ($outcomeFile === '') {
            return $this->blockedResult('outcome_file_required', '--outcome-file is required for owner-queue-consumption-gate');
        }

        $releaseRecords = $this->readJsonOrJsonlRecords($releaseFile);
        if ($releaseRecords === null || $releaseRecords === []) {
            return $this->blockedResult('release_file_invalid', $releaseFile);
        }
        $release = $this->selectRecordById($releaseRecords, (string) ($this->option('release-id') ?? ''), 'release_id');
        if ($release === null) {
            return $this->blockedResult('release_id_not_found', (string) $this->option('release-id'));
        }

        $outcome = $this->readJsonFile($outcomeFile);
        if (! is_array($outcome)) {
            return $this->blockedResult('outcome_file_invalid', $outcomeFile);
        }

        $receiptFile = (string) ($this->option('execution-receipt-file') ?? '');
        $receipt = [];
        if ($receiptFile !== '') {
            $receipt = $this->readJsonFile($receiptFile);
            if (! is_array($receipt)) {
                return $this->blockedResult('execution_receipt_file_invalid', $receiptFile);
            }
        }

        $sandboxFile = (string) ($this->option('sandbox-record-file') ?? '');
        $sandboxRecord = [];
        if ($sandboxFile !== '') {
            $sandboxRecords = $this->readJsonOrJsonlRecords($sandboxFile);
            if ($sandboxRecords === null || $sandboxRecords === []) {
                return $this->blockedResult('sandbox_record_file_invalid', $sandboxFile);
            }
            $sandboxRecord = $this->selectRecordById($sandboxRecords, (string) ($this->option('sandbox-id') ?? ''), 'sandbox_id');
            if ($sandboxRecord === null) {
                return $this->blockedResult('sandbox_id_not_found', (string) $this->option('sandbox-id'));
            }
        }

        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'release_report' => $release,
            'outcome_bridge' => $outcome,
            'sandbox_record' => $sandboxRecord,
            'execution_receipt' => $receipt,
            'record_consumption' => (bool) $this->option('record-consumption'),
            'kill_switch' => (bool) $this->option('kill-switch'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-749 owner queue gate', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Target owner', (string) ($p['target_owner'] ?? ''));
            $this->components->twoColumnDetail('Release', (string) ($p['release_id'] ?? ''));
            $this->components->twoColumnDetail('Queue item', (string) ($p['queue_item_id'] ?? ''));
            $this->components->twoColumnDetail('Sandbox', (string) data_get($p, 'sandbox_binding.sandbox_id', ''));
            $this->components->twoColumnDetail('Execution receipt', (string) ($p['execution_receipt_status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Recorded', ((bool) ($p['record_consumption_requested'] ?? false)) ? 'requested' : 'projection-only');
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === AreaFocusOwnerQueueConsumptionGateService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runOwnerRuntimeExecute(StewardshipOwnerRuntimeExecutionAdapterService $service): int
    {
        $consumptionFile = (string) ($this->option('consumption-file') ?? '');
        $receiptFile = (string) ($this->option('runtime-start-receipt-file') ?? '');
        if ($consumptionFile === '') {
            return $this->blockedResult('consumption_file_required', '--consumption-file is required for owner-runtime-execute');
        }
        if ($receiptFile === '') {
            return $this->blockedResult('runtime_start_receipt_file_required', '--runtime-start-receipt-file is required for owner-runtime-execute');
        }

        $consumptionRecords = $this->readJsonOrJsonlRecords($consumptionFile);
        if ($consumptionRecords === null || $consumptionRecords === []) {
            return $this->blockedResult('consumption_file_invalid', $consumptionFile);
        }
        $consumption = $this->selectRecordById($consumptionRecords, (string) ($this->option('consumption-id') ?? ''), 'consumption_id');
        if ($consumption === null) {
            return $this->blockedResult('consumption_id_not_found', (string) $this->option('consumption-id'));
        }

        $receipt = $this->readJsonFile($receiptFile);
        if (! is_array($receipt)) {
            return $this->blockedResult('runtime_start_receipt_file_invalid', $receiptFile);
        }

        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'consumption_report' => $consumption,
            'runtime_start_receipt' => $receipt,
            'record_execution' => (bool) $this->option('record-execution'),
            'kill_switch' => (bool) $this->option('kill-switch'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-758 owner runtime execute', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Target owner', (string) ($p['target_owner'] ?? ''));
            $this->components->twoColumnDetail('Consumption', (string) ($p['consumption_id'] ?? ''));
            $this->components->twoColumnDetail('Execution', (string) ($p['owner_execution_id'] ?? ''));
            $this->components->twoColumnDetail('Driver', (string) data_get($p, 'runtime_invocation.driver_mode', ''));
            $this->components->twoColumnDetail('Owner result', (string) data_get($p, 'owner_result.result_id', ''));
            $this->components->twoColumnDetail('Recorded', ((bool) ($p['record_execution_requested'] ?? false)) ? 'requested' : 'projection-only');
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === StewardshipOwnerRuntimeExecutionAdapterService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runOwnerSandboxRuntimeRun(StewardshipOwnerSandboxRuntimeRunnerService $service): int
    {
        $executionFile = (string) ($this->option('execution-file') ?? '');
        $receiptFile = (string) ($this->option('runtime-command-receipt-file') ?? '');
        if ($executionFile === '') {
            return $this->blockedResult('execution_file_required', '--execution-file is required for owner-sandbox-runtime-run');
        }
        if ($receiptFile === '') {
            return $this->blockedResult('runtime_command_receipt_file_required', '--runtime-command-receipt-file is required for owner-sandbox-runtime-run');
        }

        $executionRecords = $this->readJsonOrJsonlRecords($executionFile);
        if ($executionRecords === null || $executionRecords === []) {
            return $this->blockedResult('execution_file_invalid', $executionFile);
        }
        $execution = $this->selectRecordById($executionRecords, (string) ($this->option('owner-execution-id') ?? ''), 'owner_execution_id');
        if ($execution === null) {
            return $this->blockedResult('owner_execution_id_not_found', (string) $this->option('owner-execution-id'));
        }

        $receipt = $this->readJsonFile($receiptFile);
        if (! is_array($receipt)) {
            return $this->blockedResult('runtime_command_receipt_file_invalid', $receiptFile);
        }

        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'execution_adapter_report' => $execution,
            'runtime_command_receipt' => $receipt,
            'execute' => (bool) $this->option('execute-owner-command'),
            'record_run' => (bool) $this->option('record-owner-run'),
            'kill_switch' => (bool) $this->option('kill-switch'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-759 owner sandbox runtime', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Target owner', (string) ($p['target_owner'] ?? ''));
            $this->components->twoColumnDetail('Owner execution', (string) ($p['owner_execution_id'] ?? ''));
            $this->components->twoColumnDetail('Sandbox run', (string) ($p['owner_sandbox_run_id'] ?? ''));
            $this->components->twoColumnDetail('Command', (string) data_get($p, 'command_plan.command_display', ''));
            $this->components->twoColumnDetail('Owner result', (string) data_get($p, 'owner_result.result_id', ''));
            $this->components->twoColumnDetail('Recorded', ((bool) ($p['record_run_requested'] ?? false)) ? 'requested' : 'projection-only');
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === StewardshipOwnerSandboxRuntimeRunnerService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runOwnerRuntimeResultBridge(StewardshipOwnerRuntimeResultBridgeService $service): int
    {
        $consumptionFile = (string) ($this->option('consumption-file') ?? '');
        $resultFile = (string) ($this->option('result-file') ?? '');
        if ($consumptionFile === '') {
            return $this->blockedResult('consumption_file_required', '--consumption-file is required for owner-runtime-result-bridge');
        }
        if ($resultFile === '') {
            return $this->blockedResult('result_file_required', '--result-file is required for owner-runtime-result-bridge');
        }

        $consumptionRecords = $this->readJsonOrJsonlRecords($consumptionFile);
        if ($consumptionRecords === null || $consumptionRecords === []) {
            return $this->blockedResult('consumption_file_invalid', $consumptionFile);
        }
        $consumption = $this->selectRecordById($consumptionRecords, (string) ($this->option('consumption-id') ?? ''), 'consumption_id');
        if ($consumption === null) {
            return $this->blockedResult('consumption_id_not_found', (string) $this->option('consumption-id'));
        }

        $result = $this->readJsonFile($resultFile);
        if (! is_array($result)) {
            return $this->blockedResult('result_file_invalid', $resultFile);
        }

        $approvalFile = (string) ($this->option('approval-file') ?? '');
        $approval = [];
        if ($approvalFile !== '') {
            $approval = $this->readJsonFile($approvalFile);
            if (! is_array($approval)) {
                return $this->blockedResult('approval_file_invalid', $approvalFile);
            }
        }

        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'consumption_report' => $consumption,
            'owner_result' => $result,
            'irreversible_approval_receipt' => $approval,
            'record_result' => (bool) $this->option('record-result'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-750 owner runtime result bridge', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Target owner', (string) ($p['target_owner'] ?? ''));
            $this->components->twoColumnDetail('Consumption', (string) ($p['consumption_id'] ?? ''));
            $this->components->twoColumnDetail('Result', (string) ($p['owner_result_id'] ?? ''));
            $this->components->twoColumnDetail('Result status', (string) ($p['owner_result_status'] ?? ''));
            $this->components->twoColumnDetail('Evidence items', (string) count((array) ($p['evidence_items'] ?? [])));
            $this->components->twoColumnDetail('Inbox items', (string) count((array) ($p['morning_inbox_items'] ?? [])));
            $this->components->twoColumnDetail('Recorded', ((bool) ($p['record_result_requested'] ?? false)) ? 'requested' : 'projection-only');
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === StewardshipOwnerRuntimeResultBridgeService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runRuntimeResultBridge(StewardshipRuntimeResultBridgeService $service): int
    {
        $resultFile = (string) ($this->option('result-file') ?? '');
        $useFixture = (bool) $this->option('fixture');

        if ($resultFile === '' && ! $useFixture) {
            return $this->blockedResult('execution_result_required', '--result-file=<owner-result.json> or --fixture is required for runtime-result-bridge');
        }

        if ($useFixture) {
            $result = $this->runtimeResultFixture();
        } else {
            $result = $this->readJsonFile($resultFile);
            if (! is_array($result)) {
                return $this->blockedResult('result_file_invalid', $resultFile);
            }
        }

        $approvalFile = (string) ($this->option('approval-file') ?? '');
        $approval = [];
        if ($approvalFile !== '') {
            $approval = $this->readJsonFile($approvalFile);
            if (! is_array($approval)) {
                return $this->blockedResult('approval_file_invalid', $approvalFile);
            }
        }

        $execution = (string) ($this->option('execution') ?? '');
        if ($execution !== '' && ! isset($result['execution_id'])) {
            $result['execution_id'] = $execution;
        }

        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'owner' => (string) ($this->option('owner') ?? ''),
            'sandbox_id' => (string) ($this->option('sandbox-id') ?? ''),
            'finding_id' => (string) ($this->option('finding-id') ?? ''),
            'spec_id' => (string) ($this->option('spec-id') ?? ''),
            'handoff_id' => (string) ($this->option('handoff-id') ?? ''),
            'actor' => (string) ($this->option('actor') ?? ''),
            'execution_result' => $result,
            'irreversible_approval_receipt' => $approval,
            'emit_inbox' => (bool) $this->option('emit-inbox'),
            'record_evidence' => (bool) $this->option('record-evidence'),
            'record_event' => (bool) $this->option('record-event'),
            'record_cycle' => (bool) $this->option('record-cycle'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-765 runtime result bridge', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Owner', (string) ($p['owner'] ?? ''));
            $this->components->twoColumnDetail('Result', (string) ($p['result_status'] ?? ''));
            $this->components->twoColumnDetail('Bridge', (string) ($p['result_bridge_id'] ?? ''));
            $this->components->twoColumnDetail('Evidence pack', (string) ($p['evidence_pack_id'] ?? '').' ('.(string) ($p['evidence_ledger_status'] ?? '').')');
            $this->components->twoColumnDetail('Inbox item', (string) ($p['inbox_item_id'] ?? data_get($p, 'inbox_item.inbox_status', 'projected')));
            $this->components->twoColumnDetail('Product Mode event', (string) ($p['product_mode_event_id'] ?? '').' ('.(string) ($p['product_mode_event_status'] ?? '').')');
            $this->components->twoColumnDetail('Portfolio signal', (string) ($p['portfolio_signal_id'] ?? ''));
            $this->components->twoColumnDetail('Cycle recorded', (string) ($p['cycle_storage_status'] ?? 'projected'));
            if (($p['status'] ?? '') !== StewardshipRuntimeResultBridgeService::STATUS_BLOCKED) {
                $this->line('  next: '.(string) ($p['operator_next_step'] ?? ''));
            }
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
        });

        return ($payload['status'] ?? '') === StewardshipRuntimeResultBridgeService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * Built-in canonical execution_result fixture for smoke/demo and the
     * `--fixture` flag. Represents a completed, branch-isolated Atlas Dev run.
     *
     * @return array<string,mixed>
     */
    private function runtimeResultFixture(): array
    {
        return [
            'execution_id' => 'afexec_fixture_001',
            'owner' => 'atlas_dev',
            'result_status' => 'completed',
            'summary' => 'Atlas Dev implemented the Area Focus finding under branch isolation; tests green, no merge performed.',
            'finding_id' => 'aff_fixture_dev_forge',
            'spec_id' => 'spec_fixture_dev_forge',
            'handoff_id' => 'afho_fixture',
            'sandbox_id' => 'afsb_fixture',
            'branch_ref' => 'atlas/area-focus/agentic_engineering_os/fixture',
            'worktree_path' => 'storage/atlas/software_company_stewardship/area_focus_branch_sandboxes/worktrees/afsb_fixture',
            'changed_files' => [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ExampleFix.php',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ExampleFixTest.php',
            ],
            'tests' => ['php artisan test --filter=ExampleFix'],
            'test_results' => [
                ['command' => 'php artisan test --filter=ExampleFix', 'status' => 'passed', 'passed' => 3, 'failed' => 0],
            ],
            'validation_commands' => ['php artisan atlas:ai:architecture-validate --json'],
            'risks' => ['Change is isolated to the Area Focus example fix; no schema or route changes.'],
            'rollback' => 'Discard the isolated git worktree/branch; no merge performed, so nothing reaches main.',
            'runtime_execution_started' => true,
            'provider_invoked' => true,
            'merge_performed' => false,
            'deploy_performed' => false,
            'external_push_performed' => false,
            'secret_access' => false,
            'destructive_change' => false,
        ];
    }

    private function runDevForgeExecute(DevForgeRuntimeExecutionBridgeService $service): int
    {
        $owner = (string) ($this->option('owner') ?? '');
        if ($owner === '') {
            return $this->blockedResult('owner_required', '--owner=atlas_dev|forge is required for dev-forge-execute');
        }

        $sandboxId = (string) ($this->option('sandbox') ?? $this->option('sandbox-id') ?? '');
        $sandbox = null;
        $descriptorFile = (string) ($this->option('sandbox-descriptor-file') ?? '');
        if ($descriptorFile !== '') {
            $sandbox = $this->readJsonFile($descriptorFile);
            if (! is_array($sandbox)) {
                return $this->blockedResult('sandbox_descriptor_file_invalid', $descriptorFile);
            }
        } else {
            $recordFile = (string) ($this->option('sandbox-record-file') ?? '');
            if ($recordFile !== '') {
                $records = $this->readJsonOrJsonlRecords($recordFile);
                if ($records === null || $records === []) {
                    return $this->blockedResult('sandbox_record_file_invalid', $recordFile);
                }
                $sandbox = $this->selectRecordById($records, $sandboxId, 'sandbox_id');
                if ($sandbox === null) {
                    return $this->blockedResult('sandbox_id_not_found', $sandboxId);
                }
            } elseif ($sandboxId !== '') {
                // Minimal id-only descriptor; the gate blocks honestly on missing worktree/isolation.
                $sandbox = ['sandbox_id' => $sandboxId];
            }
        }

        $payload = $service->execute([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'owner' => $owner,
            'mode' => (string) ($this->option('mode') ?? 'dry-run'),
            'handoff_id' => (string) ($this->option('handoff') ?? $this->option('handoff-id') ?? ''),
            'finding_id' => (string) ($this->option('finding-id') ?? ''),
            'spec_id' => (string) ($this->option('spec-id') ?? ''),
            'allowed_files' => array_values(array_filter((array) $this->option('allowed-file'), 'is_string')),
            'test_commands' => array_values(array_filter((array) $this->option('test-command'), 'is_string')),
            'sandbox' => $sandbox,
            'run_local_deterministic_task' => (bool) $this->option('run-local-task'),
            'record_result' => (bool) $this->option('record-bridge-result'),
            'kill_switch' => (bool) $this->option('kill-switch'),
            'area_kill_switch' => (bool) $this->option('area-kill-switch'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-767 dev-forge-execute', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Owner', (string) ($p['owner'] ?? ''));
            $this->components->twoColumnDetail('Mode', (string) ($p['mode'] ?? ''));
            $this->components->twoColumnDetail('Execution', (string) ($p['execution_id'] ?? ''));
            $this->components->twoColumnDetail('Sandbox', (string) data_get($p, 'sandbox.sandbox_id', ''));
            $this->components->twoColumnDetail('Provider bridge', ((bool) data_get($p, 'provider_bridge.provider_bridge_missing', false)) ? 'missing' : 'bound');
            $this->components->twoColumnDetail('Next state', (string) ($p['next_state'] ?? ''));
            $this->components->twoColumnDetail('Recorded', (string) ($p['execution_storage_status'] ?? 'projected'));
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === DevForgeRuntimeExecutionBridgeService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runProductModeCockpit(ProductModeCockpitSurfaceService $service, ProductModeOperationalControlReceiptService $controlReceipts): int
    {
        $input = [
            'area_id' => (string) $this->option('area'),
            'pack_id' => (string) $this->option('pack-id'),
            'proposal_id' => (string) $this->option('proposal-id'),
        ] + $this->productModeControlInput();
        if ((bool) $this->option('use-recorded-controls')) {
            $input = $controlReceipts->effectiveControls((string) $this->option('area'), (string) $this->option('portfolio'), $input);
        }

        $payload = $service->project((string) $this->option('portfolio'), $input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-739 Product Mode Cockpit', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Review queue', (string) data_get($p, 'counters.review_queue_items', 0));
            $this->components->twoColumnDetail('Executive pending', (string) data_get($p, 'counters.executive_pending_review', 0));
            $this->components->twoColumnDetail('New area blocked', (string) data_get($p, 'counters.new_area_blocked_review', 0));
            $this->components->twoColumnDetail('Outcome evidence', (string) data_get($p, 'counters.outcome_evidence_items', 0));
            $this->components->twoColumnDetail('Domain handoffs', (string) data_get($p, 'counters.ready_domain_handoffs', 0).'/'.(string) data_get($p, 'counters.domain_handoff_packets', 0).' ready');
            $this->components->twoColumnDetail('Product controls', (string) data_get($p, 'product_mode_operational_controls.status', 'unknown'));
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === ProductModeCockpitSurfaceService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runProductModeControls(ProductModeOperationalControlsReadModelService $service, ProductModeOperationalControlReceiptService $controlReceipts): int
    {
        $input = $this->productModeControlInput();
        if ((bool) $this->option('use-recorded-controls')) {
            $input = $controlReceipts->effectiveControls((string) $this->option('area'), (string) $this->option('portfolio'), $input);
        }

        $payload = $service->project((string) $this->option('area'), (string) $this->option('portfolio'), $input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-754 Product Mode controls', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Repo', (string) data_get($p, 'repo_onboarding.repository', ''));
            $this->components->twoColumnDetail('Repo authorized', ((bool) data_get($p, 'repo_onboarding.is_authorized', false)) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Autonomy tier', (string) data_get($p, 'autonomy_tiers.current_tier', 0).' / max '.(string) data_get($p, 'autonomy_tiers.max_allowed_tier', 0));
            $this->components->twoColumnDetail('Kill switch', ((bool) data_get($p, 'safety_controls.kill_switch_active', false)) ? 'active' : 'clear');
            $this->components->twoColumnDetail('Evidence', (string) data_get($p, 'evidence_inspector.inspector_status', 'unknown'));
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === ProductModeOperationalControlsReadModelService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runProductModeControlReceipt(ProductModeOperationalControlReceiptService $service): int
    {
        try {
            $payload = $service->record($this->productModeControlInput() + [
                'area_id' => (string) $this->option('area'),
                'portfolio_id' => (string) $this->option('portfolio'),
                'control_type' => (string) $this->option('control-type'),
                'operator_actor' => (string) $this->option('actor'),
                'decision' => (string) ($this->option('decision') ?: 'accept'),
                'risk' => (string) $this->option('risk'),
                'rationale' => (string) $this->option('rationale'),
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->blockedResult('product_mode_control_receipt_blocked', $e->getMessage());
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-755 Product Mode control receipt', (string) ($p['decision_id'] ?? ''));
            $this->components->twoColumnDetail('Control', (string) data_get($p, 'target_payload.control_type', ''));
            $this->components->twoColumnDetail('Repo', (string) data_get($p, 'target_payload.repo', ''));
            $this->components->twoColumnDetail('Decision', (string) ($p['decision'] ?? ''));
            $this->components->twoColumnDetail('Executed', ((bool) ($p['executed'] ?? false)) ? 'yes' : 'no');
        });

        return self::SUCCESS;
    }

    private function runProductModeControlReceiptList(ProductModeOperationalControlReceiptService $service): int
    {
        $payload = $service->listReceipts((string) $this->option('area'), (string) $this->option('portfolio'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-755 Product Mode control receipts', (string) ($p['receipt_count'] ?? 0));
            foreach ((array) ($p['receipts'] ?? []) as $receipt) {
                $this->line(sprintf(
                    '  %s · %s · %s · %s',
                    (string) ($receipt['decision_id'] ?? ''),
                    (string) ($receipt['control_type'] ?? ''),
                    (string) ($receipt['decision'] ?? ''),
                    (string) ($receipt['repo'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runProductModeControlReceiptReplay(ProductModeOperationalControlReceiptService $service): int
    {
        $decisionId = trim((string) $this->option('decision-id'));
        if ($decisionId === '') {
            return $this->blockedResult('decision_id_required', '--decision-id is required for product-mode-control-replay');
        }

        $payload = $service->replay($decisionId);
        if ($payload === null) {
            return $this->blockedResult('product_mode_control_receipt_not_found', $decisionId);
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-755 replay', (string) ($p['decision_id'] ?? ''));
            $this->components->twoColumnDetail('Control', (string) data_get($p, 'target_payload.control_type', ''));
            $this->components->twoColumnDetail('Decision', (string) ($p['decision'] ?? ''));
        });

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function productModeControlInput(): array
    {
        return [
            'repo' => (string) $this->option('repo'),
            'repo_authorization_status' => (string) $this->option('repo-authorization-status'),
            'authorized_repositories' => [(string) $this->option('repo')],
            'autonomy_tier' => (int) $this->option('autonomy-tier'),
            'max_allowed_autonomy_tier' => (int) $this->option('max-allowed-autonomy-tier'),
            'cycle_budget' => (int) $this->option('cycle-budget'),
            'used_cycles' => (int) $this->option('used-cycles'),
            'branch_wip_limit' => (int) $this->option('branch-wip-limit'),
            'active_branch_count' => (int) $this->option('active-branch-count'),
            'provider_call_limit' => (int) $this->option('provider-call-limit'),
            'provider_calls_used' => (int) $this->option('provider-calls-used'),
            'kill_switch' => (bool) $this->option('kill-switch'),
            'paused' => (bool) $this->option('pause-product-mode'),
            'lock_active' => (bool) $this->option('lock-active'),
            'rate_limited' => (bool) $this->option('rate-limited'),
        ];
    }

    private function runOutcomeEvidence(StewardshipOutcomeEvidenceBridgeService $service): int
    {
        $releaseFile = (string) ($this->option('release-file') ?? '');
        $releaseReports = $releaseFile !== '' ? $this->readJsonOrJsonlRecords($releaseFile) : null;
        if ($releaseFile !== '' && $releaseReports === null) {
            return $this->blockedResult('release_file_invalid', $releaseFile);
        }

        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'proposal_id' => (string) $this->option('proposal-id'),
            'actor' => (string) $this->option('actor'),
            'record_evidence' => (bool) $this->option('record-evidence'),
            'emit_inbox' => (bool) $this->option('emit-inbox'),
            'release_reports' => $releaseReports ?? [],
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-740 outcome bridge', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Decisions', (string) ($p['decision_count'] ?? 0));
            $this->components->twoColumnDetail('AP-747 releases', (string) data_get($p, 'release_outcome_summary.release_count', 0));
            $this->components->twoColumnDetail('Evidence items', (string) ($p['evidence_item_count'] ?? 0));
            $this->components->twoColumnDetail('Morning Inbox items', (string) ($p['morning_inbox_item_count'] ?? 0));
            $this->components->twoColumnDetail('Portfolio feed areas', (string) count((array) data_get($p, 'portfolio_feed.areas', [])));
            $this->components->twoColumnDetail('Ledger writes', ((bool) ($p['record_evidence_requested'] ?? false)) ? 'requested' : 'projection-only');
            $this->components->twoColumnDetail('Inbox emits', ((bool) ($p['emit_inbox_requested'] ?? false)) ? 'requested' : 'projection-only');
            foreach ($p['morning_inbox_items'] ?? [] as $item) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($item['kind'] ?? ''),
                    (string) ($item['target_id'] ?? $item['proposal_id'] ?? ''),
                    (string) ($item['recommended_action'] ?? ''),
                ));
            }
        });

        return ($payload['status'] ?? '') === StewardshipOutcomeEvidenceBridgeService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runDomainRuntimeCreationHandoff(SelfExpandingDomainRuntimeCreationHandoffService $service): int
    {
        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'proposal_id' => (string) $this->option('proposal-id'),
            'record_handoff' => (bool) $this->option('record-handoff'),
            'require_recorded_evidence' => ! (bool) $this->option('allow-projected-evidence'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-741 creation handoff', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Ready packets', (string) ($p['ready_handoff_count'] ?? 0));
            $this->components->twoColumnDetail('Blocked packets', (string) ($p['blocked_handoff_count'] ?? 0));
            $this->components->twoColumnDetail('Recorded', ((bool) ($p['record_handoff_requested'] ?? false)) ? 'requested' : 'projection-only');
            foreach ($p['handoff_packets'] ?? [] as $packet) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($packet['handoff_packet_id'] ?? ''),
                    (string) ($packet['candidate_area'] ?? ''),
                    (string) ($packet['handoff_status'] ?? ''),
                ));
                foreach ((array) ($packet['blockers'] ?? []) as $blocker) {
                    $this->warn('    blocker: '.(string) $blocker);
                }
            }
        });

        return ($payload['status'] ?? '') === SelfExpandingDomainRuntimeCreationHandoffService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runEvolution(StewardshipEvolutionReadModelService $evolution, ?string $section = null): int
    {
        $payload = $evolution->project(['area_id' => (string) $this->option('area')]);
        $output = $section !== null && is_array($payload[$section] ?? null)
            ? $payload[$section] + [
                'parent_schema_version' => $payload['schema_version'] ?? StewardshipEvolutionReadModelService::REPORT_SCHEMA,
                'parent_report_hash' => $payload['report_hash'] ?? null,
                'claim_policy' => $payload['claim_policy'] ?? [],
            ]
            : $payload;

        $this->emit($output, function (array $p) use ($section): void {
            $this->components->twoColumnDetail('Stewardship Stack', $section ?? 'evolution ladder');
            $this->components->twoColumnDetail('Schema', (string) ($p['schema_version'] ?? ''));
            $this->components->twoColumnDetail('Status', (string) ($p['status'] ?? 'unknown'));

            if ($section === null) {
                $stack = is_array($p['stewardship_stack'] ?? null) ? $p['stewardship_stack'] : [];
                $this->components->twoColumnDetail('Continuous Loop', (string) ($stack['continuous_loop_role'] ?? '24h governed motor'));
                $this->components->twoColumnDetail('Stack ceiling', (string) ($stack['stack_ceiling'] ?? 'Self-Expanding Software Company'));
                $executive = is_array($p['autonomous_executive'] ?? null) ? $p['autonomous_executive'] : [];
                $primary = is_array($executive['primary_recommendation'] ?? null) ? $executive['primary_recommendation'] : [];
                $this->components->twoColumnDetail('Executive target', (string) ($primary['target_area'] ?? '?'));
                $self = is_array($p['self_expanding_software_company'] ?? null) ? $p['self_expanding_software_company'] : [];
                $this->components->twoColumnDetail('Expansion proposals', (string) ($self['proposal_count'] ?? 0));
            }
        });

        return ($payload['status'] ?? '') === StewardshipEvolutionReadModelService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runPortfolioHealth(PortfolioStewardshipHealthModelService $service): int
    {
        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-733 portfolio health', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Score', (string) data_get($p, 'portfolio_health.score', 0).' · '.(string) data_get($p, 'portfolio_health.band', 'unknown'));
            $this->components->twoColumnDetail('Lowest area', (string) data_get($p, 'portfolio_health.lowest_health_area', ''));
            foreach ($p['rebalance_candidates'] ?? [] as $candidate) {
                $this->line(sprintf(
                    '  %s · %s · priority=%s',
                    (string) ($candidate['candidate_id'] ?? ''),
                    (string) ($candidate['target_area'] ?? ''),
                    (string) ($candidate['priority_score'] ?? ''),
                ));
            }
            foreach ($p['blockers'] ?? [] as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
        });

        return ($payload['status'] ?? '') === PortfolioStewardshipHealthModelService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runPortfolioHealthRecord(PortfolioStewardshipHealthModelService $service): int
    {
        $payload = $service->record([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-733 snapshot', (string) ($p['snapshot_id'] ?? ''));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Score', (string) data_get($p, 'portfolio_health.score', 0).' · '.(string) data_get($p, 'portfolio_health.band', 'unknown'));
            $this->components->twoColumnDetail('Recorded', (string) ($p['recorded_at'] ?? ''));
        });

        return ($payload['status'] ?? '') === PortfolioStewardshipHealthModelService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runPortfolioHealthSnapshots(PortfolioStewardshipHealthModelService $service): int
    {
        $payload = $service->listSnapshots((string) $this->option('portfolio'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-733 snapshots', (string) ($p['snapshot_count'] ?? 0));
            foreach ($p['snapshots'] ?? [] as $snapshot) {
                $this->line(sprintf(
                    '  %s · %s · %s · %s',
                    (string) ($snapshot['snapshot_id'] ?? ''),
                    (string) ($snapshot['portfolio_id'] ?? ''),
                    (string) ($snapshot['score'] ?? ''),
                    (string) ($snapshot['recorded_at'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runPortfolioHealthReplay(PortfolioStewardshipHealthModelService $service): int
    {
        $snapshotId = trim((string) $this->option('snapshot-id'));
        if ($snapshotId === '') {
            return $this->blockedResult('snapshot_id_required', '--snapshot-id is required for portfolio-health-replay');
        }

        $payload = $service->replay($snapshotId);
        if ($payload === null) {
            return $this->blockedResult('snapshot_not_found', $snapshotId);
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-733 replay', (string) ($p['snapshot_id'] ?? ''));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Score', (string) data_get($p, 'portfolio_health.score', 0).' · '.(string) data_get($p, 'portfolio_health.band', 'unknown'));
        });

        return self::SUCCESS;
    }

    private function runPortfolioInbox(PortfolioStewardshipInboxService $service): int
    {
        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'snapshot_id' => (string) $this->option('snapshot-id'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-734 portfolio inbox', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Items', (string) ($p['item_count'] ?? 0));
            foreach ($p['items'] ?? [] as $item) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($item['item_id'] ?? ''),
                    (string) ($item['target_area'] ?? ''),
                    (string) ($item['risk_level'] ?? ''),
                ));
            }
        });

        return ($payload['status'] ?? '') === PortfolioStewardshipInboxService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runPortfolioInboxRecord(PortfolioStewardshipInboxService $service): int
    {
        $payload = $service->record([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'snapshot_id' => (string) $this->option('snapshot-id'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-734 inbox', (string) ($p['inbox_id'] ?? ''));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Items', (string) ($p['item_count'] ?? 0));
            $this->components->twoColumnDetail('Recorded', (string) ($p['recorded_at'] ?? ''));
        });

        return ($payload['status'] ?? '') === PortfolioStewardshipInboxService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runPortfolioInboxList(PortfolioStewardshipInboxService $service): int
    {
        $payload = $service->listInboxes((string) $this->option('portfolio'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-734 inboxes', (string) ($p['inbox_count'] ?? 0));
            foreach ($p['inboxes'] ?? [] as $inbox) {
                $this->line(sprintf(
                    '  %s · %s · items=%s · %s',
                    (string) ($inbox['inbox_id'] ?? ''),
                    (string) ($inbox['portfolio_id'] ?? ''),
                    (string) ($inbox['item_count'] ?? 0),
                    (string) ($inbox['recorded_at'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runPortfolioInboxReplay(PortfolioStewardshipInboxService $service): int
    {
        $inboxId = trim((string) $this->option('inbox-id'));
        if ($inboxId === '') {
            return $this->blockedResult('inbox_id_required', '--inbox-id is required for portfolio-inbox-replay');
        }

        $payload = $service->replay($inboxId);
        if ($payload === null) {
            return $this->blockedResult('inbox_not_found', $inboxId);
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-734 replay', (string) ($p['inbox_id'] ?? ''));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Items', (string) ($p['item_count'] ?? 0));
        });

        return self::SUCCESS;
    }

    private function runPortfolioInboxDecision(PortfolioStewardshipInboxService $service): int
    {
        try {
            $payload = $service->decide([
                'area_id' => (string) $this->option('area'),
                'portfolio_id' => (string) $this->option('portfolio'),
                'snapshot_id' => (string) $this->option('snapshot-id'),
                'inbox_id' => (string) $this->option('inbox-id'),
                'item_id' => (string) $this->option('item-id'),
                'operator_actor' => (string) $this->option('actor'),
                'decision' => (string) $this->option('decision'),
                'target_id' => (string) $this->option('target-id'),
                'target_hash' => (string) $this->option('target-hash'),
                'risk' => (string) $this->option('risk'),
                'rationale' => (string) $this->option('rationale'),
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->blockedResult('portfolio_inbox_decision_blocked', $e->getMessage());
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-734 decision', (string) ($p['decision_id'] ?? ''));
            $this->components->twoColumnDetail('Target', (string) ($p['target_type'] ?? '').' · '.(string) ($p['target_id'] ?? ''));
            $this->components->twoColumnDetail('Decision', (string) ($p['decision'] ?? ''));
            $this->components->twoColumnDetail('Executed', ((bool) ($p['executed'] ?? false)) ? 'yes' : 'no');
        });

        return self::SUCCESS;
    }

    private function runExecutiveRecommendations(AutonomousExecutiveRecommendationService $service): int
    {
        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'inbox_id' => (string) $this->option('inbox-id'),
            'snapshot_id' => (string) $this->option('snapshot-id'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-735 executive recommendations', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Recommendations', (string) ($p['recommendation_count'] ?? 0));
            foreach ($p['recommendations'] ?? [] as $recommendation) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($recommendation['recommendation_id'] ?? ''),
                    (string) ($recommendation['target_area'] ?? ''),
                    (string) data_get($recommendation, 'risk_analysis.risk_level', ''),
                ));
            }
        });

        return ($payload['status'] ?? '') === AutonomousExecutiveRecommendationService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runExecutiveRecommendationRecord(AutonomousExecutiveRecommendationService $service): int
    {
        $payload = $service->record([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'inbox_id' => (string) $this->option('inbox-id'),
            'snapshot_id' => (string) $this->option('snapshot-id'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-735 pack', (string) ($p['pack_id'] ?? ''));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Recommendations', (string) ($p['recommendation_count'] ?? 0));
            $this->components->twoColumnDetail('Recorded', (string) ($p['recorded_at'] ?? ''));
        });

        return ($payload['status'] ?? '') === AutonomousExecutiveRecommendationService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runExecutiveRecommendationList(AutonomousExecutiveRecommendationService $service): int
    {
        $payload = $service->listPacks((string) $this->option('portfolio'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-735 packs', (string) ($p['pack_count'] ?? 0));
            foreach ($p['packs'] ?? [] as $pack) {
                $this->line(sprintf(
                    '  %s · %s · recommendations=%s · %s',
                    (string) ($pack['pack_id'] ?? ''),
                    (string) ($pack['portfolio_id'] ?? ''),
                    (string) ($pack['recommendation_count'] ?? 0),
                    (string) ($pack['recorded_at'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runExecutiveRecommendationReplay(AutonomousExecutiveRecommendationService $service): int
    {
        $packId = trim((string) $this->option('pack-id'));
        if ($packId === '') {
            return $this->blockedResult('pack_id_required', '--pack-id is required for executive-recommendation-replay');
        }

        $payload = $service->replay($packId);
        if ($payload === null) {
            return $this->blockedResult('executive_recommendation_pack_not_found', $packId);
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-735 replay', (string) ($p['pack_id'] ?? ''));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Recommendations', (string) ($p['recommendation_count'] ?? 0));
        });

        return self::SUCCESS;
    }

    private function runExecutiveRecommendationDecision(AutonomousExecutiveRecommendationService $service): int
    {
        try {
            $payload = $service->decide([
                'area_id' => (string) $this->option('area'),
                'portfolio_id' => (string) $this->option('portfolio'),
                'inbox_id' => (string) $this->option('inbox-id'),
                'pack_id' => (string) $this->option('pack-id'),
                'recommendation_id' => (string) $this->option('recommendation-id'),
                'operator_actor' => (string) $this->option('actor'),
                'decision' => (string) $this->option('decision'),
                'target_id' => (string) $this->option('target-id'),
                'target_hash' => (string) $this->option('target-hash'),
                'risk' => (string) $this->option('risk'),
                'rationale' => (string) $this->option('rationale'),
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->blockedResult('executive_recommendation_decision_blocked', $e->getMessage());
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-735 decision', (string) ($p['decision_id'] ?? ''));
            $this->components->twoColumnDetail('Target', (string) ($p['target_type'] ?? '').' · '.(string) ($p['target_id'] ?? ''));
            $this->components->twoColumnDetail('Decision', (string) ($p['decision'] ?? ''));
            $this->components->twoColumnDetail('Executed', ((bool) ($p['executed'] ?? false)) ? 'yes' : 'no');
        });

        return self::SUCCESS;
    }

    private function runExecutiveDecisionInbox(ExecutiveDecisionInboxSurfaceService $service): int
    {
        $payload = $service->project((string) $this->option('portfolio'), [
            'area_id' => (string) $this->option('area'),
            'pack_id' => (string) $this->option('pack-id'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-736 executive decision inbox', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Items', (string) ($p['item_count'] ?? 0));
            foreach ($p['items'] ?? [] as $item) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($item['source_recommendation_id'] ?? ''),
                    (string) ($item['target_area'] ?? ''),
                    (string) ($item['status'] ?? ''),
                ));
            }
        });

        return ($payload['status'] ?? '') === ExecutiveDecisionInboxSurfaceService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runExecutiveAllocationHandoff(AutonomousExecutiveAllocationHandoffService $service): int
    {
        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'pack_id' => (string) $this->option('pack-id'),
            'recommendation_id' => (string) $this->option('recommendation-id'),
            'record_allocation_handoff' => (bool) $this->option('record-allocation-handoff'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-752 executive allocation handoff', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Recommendation', (string) ($p['source_recommendation_id'] ?? ''));
            $this->components->twoColumnDetail('Decision', (string) ($p['source_decision_id'] ?? ''));
            $this->components->twoColumnDetail('Packets', (string) ($p['allocation_handoff_count'] ?? 0));
            $this->components->twoColumnDetail('Recorded', ((bool) ($p['record_allocation_handoff_requested'] ?? false)) ? 'requested' : 'projection-only');
            foreach ((array) ($p['allocation_handoff_packets'] ?? []) as $packet) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($packet['handoff_packet_id'] ?? ''),
                    (string) ($packet['target_owner'] ?? ''),
                    (string) ($packet['recommended_action'] ?? ''),
                ));
            }
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === AutonomousExecutiveAllocationHandoffService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runExecutiveAllocationHandoffList(AutonomousExecutiveAllocationHandoffService $service): int
    {
        $payload = $service->listHandoffs((string) $this->option('portfolio'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-752 handoffs', (string) ($p['handoff_count'] ?? 0));
            foreach ((array) ($p['handoffs'] ?? []) as $handoff) {
                $this->line(sprintf(
                    '  %s · %s · %s · %s',
                    (string) ($handoff['handoff_packet_id'] ?? ''),
                    (string) ($handoff['target_owner'] ?? ''),
                    (string) ($handoff['recommended_action'] ?? ''),
                    (string) ($handoff['recorded_at'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runExecutiveAllocationHandoffReplay(AutonomousExecutiveAllocationHandoffService $service): int
    {
        $handoffId = trim((string) $this->option('handoff-id'));
        if ($handoffId === '') {
            return $this->blockedResult('handoff_id_required', '--handoff-id is required for executive-allocation-handoff-replay');
        }

        $payload = $service->replay($handoffId);
        if ($payload === null) {
            return $this->blockedResult('executive_allocation_handoff_not_found', $handoffId);
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-752 replay', (string) ($p['handoff_packet_id'] ?? ''));
            $this->components->twoColumnDetail('Target owner', (string) ($p['target_owner'] ?? ''));
            $this->components->twoColumnDetail('Action', (string) ($p['recommended_action'] ?? ''));
            $this->components->twoColumnDetail('Status', (string) ($p['handoff_status'] ?? ''));
        });

        return self::SUCCESS;
    }

    private function runNewAreaProposalGate(NewAreaProposalGateService $service): int
    {
        $payload = $service->evaluate([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'proposal_id' => (string) $this->option('proposal-id'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-737 new area proposal gate', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Items', (string) ($p['gate_item_count'] ?? 0));
            foreach ($p['gate_items'] ?? [] as $item) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($item['proposal_id'] ?? ''),
                    (string) ($item['candidate_area'] ?? ''),
                    (string) ($item['gate_status'] ?? ''),
                ));
            }
        });

        return ($payload['status'] ?? '') === NewAreaProposalGateService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runNewAreaProposalDecision(NewAreaProposalGateService $service): int
    {
        try {
            $payload = $service->decide([
                'area_id' => (string) $this->option('area'),
                'portfolio_id' => (string) $this->option('portfolio'),
                'proposal_id' => (string) $this->option('proposal-id'),
                'operator_actor' => (string) $this->option('actor'),
                'decision' => (string) $this->option('decision'),
                'risk' => (string) $this->option('risk'),
                'rationale' => (string) $this->option('rationale'),
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->blockedResult('new_area_proposal_decision_blocked', $e->getMessage());
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-737 decision', (string) ($p['decision_id'] ?? ''));
            $this->components->twoColumnDetail('Target', (string) ($p['target_type'] ?? '').' · '.(string) ($p['target_id'] ?? ''));
            $this->components->twoColumnDetail('Decision', (string) ($p['decision'] ?? ''));
            $this->components->twoColumnDetail('Next', (string) ($p['next_allowed_action'] ?? ''));
            $this->components->twoColumnDetail('Executed', ((bool) ($p['executed'] ?? false)) ? 'yes' : 'no');
        });

        return self::SUCCESS;
    }

    private function runSelfExpandingV0(SelfExpandingSoftwareCompanyService $service): int
    {
        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'proposal_id' => (string) $this->option('proposal-id'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-738 Self-Expanding v0', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Layer', (string) ($p['layer'] ?? ''));
            $summary = is_array($p['expansion_summary'] ?? null) ? $p['expansion_summary'] : [];
            $this->components->twoColumnDetail('Candidates', (string) ($summary['total_candidates'] ?? 0));
            $this->components->twoColumnDetail('New domains', (string) ($summary['new_domain_candidates'] ?? 0));
            $this->components->twoColumnDetail('Existing handoffs', (string) ($summary['existing_capability_handoffs'] ?? 0));
            foreach (data_get($p, 'operator_inbox.items', []) as $item) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($item['proposal_id'] ?? ''),
                    (string) ($item['candidate_area'] ?? ''),
                    (string) ($item['recommended_operator_action'] ?? ''),
                ));
            }
        });

        return ($payload['status'] ?? '') === SelfExpandingSoftwareCompanyService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runEvolutionDecision(StewardshipEvolutionDecisionLedgerService $ledger): int
    {
        try {
            $payload = $ledger->record([
                'area_id' => (string) $this->option('area'),
                'operator_actor' => (string) $this->option('actor'),
                'decision' => (string) $this->option('decision'),
                'target_type' => (string) $this->option('target-type'),
                'target_id' => (string) $this->option('target-id'),
                'target_hash' => (string) $this->option('target-hash'),
                'risk' => (string) $this->option('risk'),
                'rationale' => (string) $this->option('rationale'),
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->blockedResult('evolution_decision_blocked', $e->getMessage());
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-731 decision', (string) ($p['decision_id'] ?? ''));
            $this->components->twoColumnDetail('Target', (string) ($p['target_type'] ?? '').' · '.(string) ($p['target_id'] ?? ''));
            $this->components->twoColumnDetail('Decision', (string) ($p['decision'] ?? ''));
            $this->components->twoColumnDetail('Executed', ((bool) ($p['executed'] ?? false)) ? 'yes' : 'no');
        });

        return self::SUCCESS;
    }

    private function runEvolutionDecisionList(StewardshipEvolutionDecisionLedgerService $ledger): int
    {
        $payload = $ledger->listDecisions((string) $this->option('area'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-731 decisions', (string) ($p['decision_count'] ?? 0));
            foreach ($p['decisions'] ?? [] as $decision) {
                $this->line(sprintf(
                    '  %s · %s · %s · %s',
                    (string) ($decision['decision_id'] ?? ''),
                    (string) ($decision['target_type'] ?? ''),
                    (string) ($decision['decision'] ?? ''),
                    (string) ($decision['operator_actor'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runEvolutionDecisionReplay(StewardshipEvolutionDecisionLedgerService $ledger): int
    {
        $decisionId = trim((string) $this->option('decision-id'));
        if ($decisionId === '') {
            return $this->blockedResult('decision_id_required', '--decision-id is required for evolution-replay');
        }

        $payload = $ledger->replay($decisionId);
        if ($payload === null) {
            return $this->blockedResult('decision_not_found', $decisionId);
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-731 replay', (string) ($p['decision_id'] ?? ''));
            $this->components->twoColumnDetail('Target', (string) ($p['target_type'] ?? '').' · '.(string) ($p['target_id'] ?? ''));
            $this->components->twoColumnDetail('Decision', (string) ($p['decision'] ?? ''));
        });

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, ?callable $human = null): void
    {
        if ((bool) $this->option('json') || $human === null) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }
        $human($payload);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJsonFile(string $path): ?array
    {
        if ($path === '' || ! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<array<string,mixed>>|null
     */
    private function readJsonOrJsonlRecords(string $path): ?array
    {
        if ($path === '' || ! is_file($path)) {
            return null;
        }

        $contents = (string) file_get_contents($path);
        $decoded = json_decode($contents, true);
        if (is_array($decoded)) {
            if (array_is_list($decoded)) {
                return array_values(array_filter($decoded, 'is_array'));
            }

            return [$decoded];
        }

        $records = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $item = json_decode($line, true);
            if (! is_array($item)) {
                return null;
            }
            $records[] = $item;
        }

        return $records;
    }

    /**
     * @return list<array<string,mixed>>|null
     */
    private function operatorReceiptsFromOption(): ?array
    {
        $file = trim((string) ($this->option('operator-receipts-file') ?? ''));
        if ($file === '') {
            return [];
        }

        return $this->readJsonOrJsonlRecords($file);
    }

    /**
     * @param  list<array<string,mixed>>  $records
     * @return array<string,mixed>|null
     */
    private function selectRecordById(array $records, string $id, string $field): ?array
    {
        if ($id === '') {
            return $records[0] ?? null;
        }

        foreach ($records as $record) {
            if ((string) ($record[$field] ?? '') === $id) {
                return $record;
            }
        }

        return null;
    }

    private function blockedResult(string $reason, string $detail): int
    {
        $payload = [
            'schema_version' => 'atlas.software_company_stewardship.command_error.v1',
            'status' => 'blocked',
            'reason' => $reason,
            'detail' => $detail,
        ];
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::FAILURE;
    }
}
