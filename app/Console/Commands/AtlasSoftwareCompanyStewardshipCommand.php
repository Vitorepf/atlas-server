<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtlasAreaFocusLoopReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeReleaseService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusFactoryMaxCanonicalBacklogService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusSelfConstructionAdmissionBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOwnerQueueConsumptionGateService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\CycleQualityScoreService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FirstFullCycleOrchestratorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopChaosCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopInvariantHarnessService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopLedgerArchiveService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopPreflightCycleFirewallService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LongHorizonLoopDeliveryLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopPostCycleAuditorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopFlightRecorderService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopDeterministicSimulatorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\TransactionalCycleStateService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopResourceGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\BacklogDepthGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\BacklogRegenerationEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopQualityDriftDetectorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AlwaysOnLoopSupervisorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\SelfHealingMaintenanceWindowService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ProviderReliabilityLayerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopDisasterRecoveryService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopProcessIsolationStatusService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LongRunCertificationLadderService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Loop24hCertificationHarnessService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\TenCycleReadinessGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopAutonomyCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipAutonomyEnvelopeService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchLifecycleRegistryService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchSafetyAuditService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchStressCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchSystemCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipMergeQueueService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipPriorityEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipRepoMergeLeaseService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveOperatingService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipPromotionReadinessService;
use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\AutonomousExecutiveAllocationHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\AutonomousExecutiveRecommendationService;
use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\ExecutiveDecisionInboxSurfaceService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\AtlasContinuousStewardshipLoopService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\AtlasContinuousStewardshipRecurringSchedulerService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipDayReadinessService;
use App\Console\Commands\Concerns\RendersContinuousStewardshipRunner;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipDayStartService;
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
use App\Console\Commands\SoftwareCompanyStewardship\AreaFocusSection;
use App\Console\Commands\SoftwareCompanyStewardship\BranchGovernanceSection;
use App\Console\Commands\SoftwareCompanyStewardship\RuntimeExecutionSection;
use App\Console\Commands\SoftwareCompanyStewardship\PortfolioExecutiveEvolutionSection;
use App\Console\Commands\SoftwareCompanyStewardship\LoopAssuranceSection;
use App\Console\Concerns\EmitsCanonicalJson;

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
    use EmitsCanonicalJson;

    use RendersContinuousStewardshipRunner;
    use AreaFocusSection;
    use BranchGovernanceSection;
    use RuntimeExecutionSection;
    use PortfolioExecutiveEvolutionSection;
    use LoopAssuranceSection;

    protected $signature = 'atlas:software-company-stewardship
        {action=area-focus : area-focus|reliable-24h-observability|loop-24h-readiness|first-full-cycle|first-full-cycles|first-full-cycle-replay|priority-rank|branch-system-certify|branch-stress-certify|branch-safety-audit|branch-safety-audit-records|repo-merge-lease-acquire|repo-merge-lease-release|repo-merge-lease-records|merge-queue|merge-queue-records|branch-lifecycle-reserve|branch-lifecycle-records|branch-merge-governor|branch-merge-governance-records|area-focus-deep-scan|area-focus-deep-scans|area-focus-deep-scan-replay|completion-audit|live-cycle-certification|native-obra-runner|area-focus-dev-forge-release|area-focus-branch-sandbox-materialize|area-focus-branch-sandboxes|area-focus-branch-sandbox-replay|area-focus-branch-sandbox-cleanup|owner-queue-consumption-gate|owner-runtime-execute|owner-sandbox-runtime-run|owner-runtime-result-bridge|runtime-result-bridge|dev-forge-execute|product-mode-cockpit|product-mode-controls|product-mode-control-receipt|product-mode-control-receipts|product-mode-control-replay|outcome-evidence|domain-runtime-creation-handoff|evolution|area-stewardship|area-stewardship-readiness|area-stewardship-active-handoff|area-stewardship-active-operate|continuous-24h-readiness|continuous-24h-start|continuous-24h-starts|continuous-24h-start-replay|continuous-stewardship-loop|continuous-stewardship-scheduler|continuous-runner|continuous-runner-status|portfolio|portfolio-health|portfolio-health-record|portfolio-health-snapshots|portfolio-health-replay|portfolio-inbox|portfolio-inbox-record|portfolio-inbox-list|portfolio-inbox-replay|portfolio-inbox-decision|executive|executive-recommendations|executive-recommendation-record|executive-recommendation-list|executive-recommendation-replay|executive-recommendation-decision|executive-decision-inbox|executive-allocation-handoff|executive-allocation-handoff-list|executive-allocation-handoff-replay|self-expanding|self-expanding-v0|new-area-proposal-gate|new-area-proposal-decision|evolution-decision|evolution-decisions|evolution-replay|ten-cycle-readiness|loop-autonomy-certify|loop-autonomy-envelope|loop-cycle-firewall|loop-assurance-report|loop-assurance-chaos|loop-cycle-quality|loop-ledger-archive|loop-enterprise-block|loop-post-cycle-audit|loop-flight-recorder|loop-assurance-simulate|loop-cycle-state|loop-resource-governor|loop-backlog-depth|loop-backlog-regenerate|loop-quality-drift|loop-supervisor|loop-maintenance-window|loop-provider-reliability|loop-disaster-recovery-preflight|loop-isolation-status|loop-certification-ladder|loop-months-readiness}
        {--area=agentic_engineering_os : Canonical area_id to focus}
        {--focus=dev_forge : AP-748 deep-scan focus slice (e.g. dev_forge)}
        {--max-findings= : AP-748 cap on emitted deep-scan findings}
        {--record : AP-748 append-only persist the deep-scan read-model as JSONL}
        {--scan-id= : AP-748 deep-scan id for replay}
        {--scan-canonical-doc-backlog : Opt-in Canonical Doc Backlog Miner source (read-only, default OFF)}
        {--priority-file= : AP-771 JSON file containing candidates, findings, branches or a deep_scan_report}
        {--queue-file= : AP-772 JSON file containing branch_refs or branches for merge-queue}
        {--execute-queue : AP-772 execute sequential queue actions when AP-769 policy allows}
        {--record-queue : AP-772 append merge queue record}
        {--branch-prefix=atlas/area-focus/ : AP-773 branch prefix used when branch refs are not supplied}
        {--record-branch-audit : AP-773 append branch safety audit record}
        {--lease-owner= : AP-775 lease owner / runner id for merge queue execution}
        {--lease-ttl=1800 : AP-775 merge lease TTL seconds}
        {--release-reason=operator_release : AP-775 merge lease release reason}
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
        {--include-branch-stress : AP-776/AP-779 run disposable git stress scenarios during branch-system-certify}
        {--preserve-stress-tmp : AP-779 keep disposable git stress directories for debugging}
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
        {--duration-hours=24 : AP-777 target full-day readiness window}
        {--execute-first-tick : AP-778 execute exactly one AP-766 first tick after AP-777 readiness passes}
        {--start-receipt-id= : AP-778 continuous 24h start receipt id for replay}
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
        {--strict : AP-805 exit non-zero when ten-cycle readiness is not ready}
        {--allow-cleanup-plan : AP-805 include a branch cleanup PLAN (never deletes) in the readiness report}
        {--include-product-mode : AP-805 probe Product Mode read-model memory safety}
        {--include-provider-probe : AP-805 probe provider binaries on PATH}
        {--include-branch-audit : AP-805 audit stale area-focus branches for the cleanup plan}
        {--target-mode= : AP-806 autonomy target mode: factory_scoped_self_improvement|aaeos_dev_integration_lane|aaeos_forge_full}
        {--envelope-action= : AP-806 loop-autonomy-envelope sub-action: arm|show|disarm (default show)}
        {--operator-actor= : AP-806 operator actor authorizing the armed envelope (never fabricated)}
        {--merge-target= : AP-806 envelope merge target: integration_lane (default, safe) | main}
        {--admit-cross-system : AP-806 envelope pre-authorizes cross-system atlas_dev work (routed to the lane)}
        {--risk-ceiling= : AP-806 envelope risk ceiling: low|medium|high (default medium)}
        {--duration-days= : AP-806 envelope window in days (default 7)}
        {--allowed-providers= : AP-806 comma-separated allowed providers (default cursor_cli)}
        {--forbidden-actions= : AP-806 comma-separated extra forbidden actions (safe defaults always applied)}
        {--quality-criteria= : AP-806 comma-separated quality criteria (safe defaults always applied)}
        {--max-cycles= : AP-806 envelope per-run max cycles (default 12)}
        {--max-merges= : AP-806 envelope per-run max merges (default 10)}
        {--run-id= : AP-810 loop run id for cycle firewall/quality/invariant scope}
        {--cycle-index= : AP-810 loop cycle index for cycle firewall/quality scope}
        {--cycles= : AP-810 loop cycle count for assurance invariant harness}
        {--profile= : AP-810 chaos certification profile (e.g. pre_24h)}
        {--horizon= : AP-810 horizon selector for second-action variants}
        {--block-action= : AP-810 loop-ledger-archive sub-action: plan|compact|replay (default plan)}
        {--scenario= : AP-810 assurance report scenario selector}
        {--fixture-file= : AP-810 JSON object/array or JSONL fixture file for loop assurance/firewall/quality/ledger}
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
        StewardshipBranchSafetyAuditService $branchSafetyAudit,
        StewardshipBranchStressCertificationService $branchStressCertification,
        StewardshipBranchSystemCertificationService $branchSystemCertification,
        StewardshipMergeQueueService $mergeQueue,
        StewardshipPriorityEngineService $priorityEngine,
        StewardshipRepoMergeLeaseService $repoMergeLease,
        AreaFocusOwnerQueueConsumptionGateService $ownerQueueConsumptionGate,
        StewardshipEvolutionReadModelService $evolution,
        StewardshipEvolutionDecisionLedgerService $ledger,
        AreaStewardshipPromotionReadinessService $areaStewardshipReadiness,
        AreaStewardshipActiveHandoffService $areaStewardshipActiveHandoff,
        AreaStewardshipActiveOperatingService $areaStewardshipActiveOperating,
        AtlasContinuousStewardshipLoopService $continuousStewardshipLoop,
        AtlasContinuousStewardshipRecurringSchedulerService $continuousStewardshipScheduler,
        ContinuousStewardshipDayReadinessService $continuousDayReadiness,
        ContinuousStewardshipDayStartService $continuousDayStart,
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
        AutonomousEvolutionSessionReadModelService $autonomousSessionReadModel,
        Loop24hCertificationHarnessService $loop24hCertification,
        TenCycleReadinessGovernorService $tenCycleReadiness,
        LoopAutonomyCertificationService $loopAutonomyCertification,
        StewardshipAutonomyEnvelopeService $autonomyEnvelope,
        LoopPreflightCycleFirewallService $loopPreflightFirewall,
        LoopInvariantHarnessService $loopInvariantHarness,
        LoopChaosCertificationService $loopChaosCertification,
        CycleQualityScoreService $cycleQualityScore,
        LoopLedgerArchiveService $loopLedgerArchive,
        LongHorizonLoopDeliveryLedgerService $longHorizonDeliveryLedger,
        LoopPostCycleAuditorService $loopPostCycleAuditor,
        LoopFlightRecorderService $loopFlightRecorder,
        LoopDeterministicSimulatorService $loopDeterministicSimulator,
        TransactionalCycleStateService $transactionalCycleState,
        LoopResourceGovernorService $loopResourceGovernor,
        BacklogDepthGovernorService $backlogDepthGovernor,
        BacklogRegenerationEngineService $backlogRegenerationEngine,
        LoopQualityDriftDetectorService $loopQualityDriftDetector,
        AlwaysOnLoopSupervisorService $alwaysOnLoopSupervisor,
        SelfHealingMaintenanceWindowService $selfHealingMaintenanceWindow,
        ProviderReliabilityLayerService $providerReliabilityLayer,
        LoopDisasterRecoveryService $loopDisasterRecovery,
        LoopProcessIsolationStatusService $loopProcessIsolationStatus,
        LongRunCertificationLadderService $longRunCertificationLadder,
    ): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'area-focus' => $this->runAreaFocus($readModel),
            'ten-cycle-readiness' => $this->runTenCycleReadiness($tenCycleReadiness),
            'loop-autonomy-certify' => $this->runLoopAutonomyCertify($loopAutonomyCertification),
            'loop-autonomy-envelope' => $this->runLoopAutonomyEnvelope($autonomyEnvelope),
            'reliable-24h-observability' => $this->runReliable24hObservability($autonomousSessionReadModel),
            'loop-24h-readiness' => $this->runLoop24hReadiness($loop24hCertification),
            'first-full-cycle' => $this->runFirstFullCycle($firstFullCycle),
            'first-full-cycles' => $this->runFirstFullCycleList($firstFullCycle),
            'first-full-cycle-replay' => $this->runFirstFullCycleReplay($firstFullCycle),
            'priority-rank' => $this->runPriorityRank($priorityEngine),
            'branch-system-certify' => $this->runBranchSystemCertification($branchSystemCertification),
            'branch-stress-certify' => $this->runBranchStressCertification($branchStressCertification),
            'branch-safety-audit' => $this->runBranchSafetyAudit($branchSafetyAudit),
            'branch-safety-audit-records' => $this->runBranchSafetyAuditRecords($branchSafetyAudit),
            'repo-merge-lease-acquire' => $this->runRepoMergeLeaseAcquire($repoMergeLease),
            'repo-merge-lease-release' => $this->runRepoMergeLeaseRelease($repoMergeLease),
            'repo-merge-lease-records' => $this->runRepoMergeLeaseRecords($repoMergeLease),
            'merge-queue' => $this->runMergeQueue($mergeQueue),
            'merge-queue-records' => $this->runMergeQueueRecords($mergeQueue),
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
            'continuous-24h-readiness' => $this->runContinuous24hReadiness($continuousDayReadiness),
            'continuous-24h-start' => $this->runContinuous24hStart($continuousDayStart),
            'continuous-24h-starts' => $this->runContinuous24hStartList($continuousDayStart),
            'continuous-24h-start-replay' => $this->runContinuous24hStartReplay($continuousDayStart),
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
            'loop-cycle-firewall' => $this->runLoopCycleFirewall($loopPreflightFirewall),
            'loop-assurance-report' => $this->runLoopAssuranceReport($loopInvariantHarness),
            'loop-assurance-chaos' => $this->runLoopAssuranceChaos($loopChaosCertification),
            'loop-cycle-quality' => $this->runLoopCycleQuality($cycleQualityScore),
            'loop-ledger-archive' => $this->runLoopLedgerArchive($loopLedgerArchive),
            'loop-enterprise-block' => $this->runLoopEnterpriseBlock($longHorizonDeliveryLedger),
            'loop-post-cycle-audit' => $this->runLoopPostCycleAudit($loopPostCycleAuditor),
            'loop-flight-recorder' => $this->runLoopFlightRecorder($loopFlightRecorder),
            'loop-assurance-simulate' => $this->runLoopAssuranceSimulate($loopDeterministicSimulator),
            'loop-cycle-state' => $this->runLoopCycleState($transactionalCycleState),
            'loop-resource-governor' => $this->runLoopResourceGovernor($loopResourceGovernor),
            'loop-backlog-depth' => $this->runLoopBacklogDepth($backlogDepthGovernor),
            'loop-backlog-regenerate' => $this->runLoopBacklogRegenerate($backlogRegenerationEngine),
            'loop-quality-drift' => $this->runLoopQualityDrift($loopQualityDriftDetector),
            'loop-supervisor' => $this->runLoopSupervisor($alwaysOnLoopSupervisor),
            'loop-maintenance-window' => $this->runLoopMaintenanceWindow($selfHealingMaintenanceWindow),
            'loop-provider-reliability' => $this->runLoopProviderReliability($providerReliabilityLayer),
            'loop-disaster-recovery-preflight' => $this->runLoopDisasterRecoveryPreflight($loopDisasterRecovery),
            'loop-isolation-status' => $this->runLoopIsolationStatus($loopProcessIsolationStatus),
            'loop-certification-ladder' => $this->runLoopCertificationLadder($longRunCertificationLadder),
            'loop-months-readiness' => $this->runLoopCertificationLadder($longRunCertificationLadder),
            default => $this->blockedResult('unknown_action', $action),
        };
    }

    private function safeValidation(string $command, array $args): ?bool
    {
        try {
            return $this->callSilently($command, $args) === self::SUCCESS;
        } catch (\Throwable) {
            return null;
        }
    }

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

    private function composeLiveCanonicalBacklog(string $area, string $focus): array
    {
        try {
            if (! function_exists('app')) {
                return ['canonical_parents' => [], 'self_construction_packets' => []];
            }
            $backlog = app(AreaFocusFactoryMaxCanonicalBacklogService::class);
            $bridge = app(AreaFocusSelfConstructionAdmissionBridgeService::class);
            $parents = $backlog->findings($area, $focus);
            $packets = [];
            foreach ($parents as $finding) {
                if (! is_array($finding)) {
                    continue;
                }
                $admission = $bridge->admit(
                    $finding,
                    'factory_max_rejects_high_risk_deep_finding_without_forge_authority',
                    $area,
                    $focus,
                );
                if (($admission['admissible'] ?? false) !== true) {
                    continue;
                }
                foreach ((array) ($admission['packets'] ?? []) as $packet) {
                    if (is_array($packet)) {
                        $packets[] = $packet;
                    }
                }
            }

            return [
                'canonical_parents' => array_values(array_filter($parents, 'is_array')),
                'self_construction_packets' => $packets,
            ];
        } catch (\Throwable) {
            return ['canonical_parents' => [], 'self_construction_packets' => []];
        }
    }

    private function jsonFixtureFromOption(): ?array
    {
        $file = trim((string) ($this->option('fixture-file') ?? ''));
        if ($file === '') {
            return null;
        }

        try {
            if (! is_file($file) || ! is_readable($file)) {
                return null;
            }

            $contents = (string) file_get_contents($file);
            if (trim($contents) === '') {
                return null;
            }

            $decoded = json_decode($contents, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            // JSONL fallback: one JSON object/value per line.
            $records = [];
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $item = json_decode($line, true);
                if (! is_array($item)) {
                    return null;
                }
                $records[] = $item;
            }

            return $records === [] ? null : $records;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, ?callable $human = null): void
    {
        if ((bool) $this->option('json') || $human === null) {
            $this->line($this->encode($payload));

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
        $this->line($this->encode($payload));

        return self::FAILURE;
    }
}
