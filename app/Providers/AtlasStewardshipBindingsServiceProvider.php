<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Ai\AtlasDecideService;
use App\Services\Ai\ExecutionAuthority\AwisExecutionGatePort;
use App\Services\Ai\ExecutionAuthority\AwisHandoffPackPort;
use App\Services\Ai\ExecutionAuthority\ForgeLiveDecideReceiptPort;
use App\Services\Ai\ExecutionAuthority\ForgeProviderTopologyPort;
use App\Services\Ai\Programming\AtlasForgeProviderTopologyService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeReleaseService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOwnerQueueConsumptionGateService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\CyclePhpTierRunner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowRunner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\ForgeOwnerRuntimeDispatchBridge;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\ForgeOwnerRuntimeDispatchPlanner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\OwnerQueueConsumptionGate;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\OwnerQueueReleaseGate;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\OwnerRuntimeExecutionAdapter;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\OwnerRuntimeResultProjector;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\OwnerSandboxRuntimeRunner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\RepairValidationRunner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\ShellRepairValidationRunner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\StewardshipOutcomeProjector;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ShellCyclePhpTierRunner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipPriorityEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipPriorityRanker;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOutcomeEvidenceBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeExecutionAdapterService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeResultBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerSandboxRuntimeRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultProjector;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceHandoffPackService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use Illuminate\Support\ServiceProvider;

/**
 * Software company stewardship / forge authority binds (full-pass ASP peel).
 */
final class AtlasStewardshipBindingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AreaFocusBranchSandboxMaterializer::class, AreaFocusBranchSandboxMaterializerService::class);
        $this->app->bind(CyclePhpTierRunner::class, ShellCyclePhpTierRunner::class);
        $this->app->bind(StewardshipBranchMergeGovernor::class, StewardshipBranchMergeGovernorService::class);
        $this->app->bind(StewardshipPriorityRanker::class, StewardshipPriorityEngineService::class);
        $this->app->bind(StewardshipRuntimeResultProjector::class, StewardshipRuntimeResultBridgeService::class);

        // AP-786 full owner-runtime flow seams: bind each owner-flow port to its
        // canonical service so AP-786 composes the real AP-747 -> AP-750 chain
        // and never falls back to a direct provider driver.
        $this->app->bind(OwnerQueueReleaseGate::class, AreaFocusDevForgeReleaseService::class);
        $this->app->bind(StewardshipOutcomeProjector::class, StewardshipOutcomeEvidenceBridgeService::class);
        $this->app->bind(OwnerQueueConsumptionGate::class, AreaFocusOwnerQueueConsumptionGateService::class);
        $this->app->bind(OwnerRuntimeExecutionAdapter::class, StewardshipOwnerRuntimeExecutionAdapterService::class);
        $this->app->bind(OwnerSandboxRuntimeRunner::class, StewardshipOwnerSandboxRuntimeRunnerService::class);
        $this->app->bind(OwnerRuntimeResultProjector::class, StewardshipOwnerRuntimeResultBridgeService::class);
        $this->app->bind(Ap786OwnerFlowRunner::class, Ap786OwnerFlowExecutor::class);
        // AP-786 repair-agent pre-return validation gate: run the declared
        // validation command inside the AP-756 worktree before claiming a repair.
        $this->app->bind(RepairValidationRunner::class, ShellRepairValidationRunner::class);
        // AP-787 Forge owner runtime dispatch planner seam.
        $this->app->bind(ForgeOwnerRuntimeDispatchPlanner::class, ForgeOwnerRuntimeDispatchBridge::class);
        // AP-789 Forge live authority bootstrap ports -> REAL services only.
        $this->app->bind(ForgeProviderTopologyPort::class, AtlasForgeProviderTopologyService::class);
        $this->app->bind(ForgeLiveDecideReceiptPort::class, AtlasDecideService::class);
        $this->app->bind(AwisExecutionGatePort::class, AtlasWorkspaceIntelligenceExecutionGateService::class);
        $this->app->bind(AwisHandoffPackPort::class, AtlasWorkspaceHandoffPackService::class);
    }
}
