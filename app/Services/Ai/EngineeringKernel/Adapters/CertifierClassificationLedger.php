<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Adapters;

use App\Services\Ai\Aemor\AtlasAemorCertificationService;
use App\Services\Ai\AgenticWorkcell\AtlasAgenticWorkcellCertificationService;
use App\Services\Ai\AutonomousEvolution\AtlasAutonomousEvolutionCertificationService;
use App\Services\Ai\AutonomousWorkExecution\AtlasAutonomousWorkExecutionCertificationService;
use App\Services\Ai\Compounding\AtlasTemporalCertificationService;
use App\Services\Ai\Context\AtlasContextQualityCertificationService;
use App\Services\Ai\ContextIntelligence\AtlasContextIntelligenceCertificationService;
use App\Services\Ai\ConversationOps\AtlasConversationOperationsCertificationService;
use App\Services\Ai\LongHorizon\AtlasTeosFinalCertificationService;
use App\Services\Ai\LongHorizon\AtlasTeosIncrement2CertificationService;
use App\Services\Ai\LongHorizon\AtlasTeosReadinessCertificationService;
use App\Services\Ai\LongHorizon\LongHorizonContinuityCertificationService;
use App\Services\Ai\Mission\MissionCertificationService;
use App\Services\Ai\Obra\AtlasObraCertificationService;
use App\Services\Ai\PersistentContext\AtlasPersistentContextCertificationService;
use App\Services\Ai\Product\AtlasAiProductCertificationService;
use App\Services\Ai\Product\AtlasAiRuntimeUxCertificationService;
use App\Services\Ai\Product\AtlasProductDeliveryCertificationService;
use App\Services\Ai\Programming\AtlasCodeEnterpriseCertificationService;
use App\Services\Ai\Programming\AtlasDev\Runtime\AtlasDevDesktopCertificationService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevRunCertificationService;
use App\Services\Ai\Programming\AtlasForgeContinuumCertificationService;
use App\Services\Ai\Programming\AtlasForgeRuntimeCertificationService;
use App\Services\Ai\Programming\DevForgeRobustFlowCertificationService;
use App\Services\Ai\Programming\Forge\Qa\ForgeObraCertificationService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRunCertificationService;
use App\Services\Ai\ProgrammingRuntime\AtlasProgrammingFinalCertificationService;
use App\Services\Ai\RealitySandbox\AtlasAutonomousRealitySandboxCertificationService;
use App\Services\Ai\Router\AtlasAiHyperflowCertificationService;
use App\Services\Ai\RouterRuntime\AtlasDesktopHyperflowIntegrationCertificationService;
use App\Services\Ai\RuntimeEfficiency\AtlasRuntimeEfficiencyGovernorCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneAdapterExecutionRuntimeBoundaryCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneAutomaticCostImportRuntimeCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneAutomaticWorkProductCollectionCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneExecutionWorkspaceCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneGovernanceApprovalCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneMultiAgentLoopCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneRuntimePilotCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskQueueLeaseCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneWorkerTaskEligibilityCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchPlannerCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentMergeReviewCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentRuntimeEvidenceCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentRuntimeRegistryCertificationService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRealProviderSmokeCertificationService;
use App\Services\Ai\SelfConstruction\Support\AgentValidationGateCertificationService;
use App\Services\Ai\SelfConstruction\Support\AtlasSelfProgrammingSafetyContractCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentCycleCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AaeosL7CompletionCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Ap786RealCycleCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopOperationalCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopAutonomyCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopChaosCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanDeliveryCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchStressCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchSystemCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipLiveCycleCertificationService;
use App\Services\Ai\StrategicReality\AtlasStrategicRealityCertificationService;
use App\Services\Ai\VerifiedExecution\AtlasVerifiedExecutionCertificationService;
use App\Services\Ai\Voice\AtlasVoiceRuntimeCertificationService;
use App\Services\Ai\Vox\Gate\VoxV5CertificationService;
use App\Services\Ai\Vox\Gate\VoxV68CertificationService;
use App\Services\Ai\Vox\Gate\VoxV6CertificationService;
use App\Services\Engineering\AtlasSoftwareTwinVerifiedEvolutionCertificationService;

/**
 * OBRA #5 S0 — ledger deterministico de classificacao dos *CertificationService.
 *
 * A_DELIVERY: juizes de ENTREGA (veredito bloqueia merge/escalacao/execucao) — DEVEM
 *   rotear pelo AcceptanceGate soberano (S1). B_STATE: auditores de estado/readiness
 *   (certify()->checks[], observacional) — motor unico (S2), NUNCA no gate (auditor
 *   != juiz). C_PARKED: serie AreaFocusLoop/Stewardship aguardando decisao do
 *   operador. D_ISOLATED: control-plane dry-run read-only + Vox gates, isolados por
 *   construcao.
 *
 * ANTI-DRIFT: o teste do ledger quebra se um certifier novo nascer sem classificacao
 * — certifier novo entra AQUI (e, se A_DELIVERY, entra no gate) ou nao entra.
 */
final class CertifierClassificationLedger
{
    public const A_DELIVERY = [
        AtlasObraCertificationService::class,
        ForgeObraCertificationService::class,
    ];

    public const B_STATE = [
        // Reclassificado 05/07: certify() sem argumentos rodando smokes do runtime AVER
        // = auditor de ESTADO, nao juiz de entrega (a 1a classificacao errou; leitura direta corrigiu).
        AtlasVerifiedExecutionCertificationService::class,
        AtlasAemorCertificationService::class,
        AtlasAgenticWorkcellCertificationService::class,
        AtlasAutonomousEvolutionCertificationService::class,
        AtlasAutonomousWorkExecutionCertificationService::class,
        AtlasTemporalCertificationService::class,
        AtlasContextQualityCertificationService::class,
        AtlasContextIntelligenceCertificationService::class,
        AtlasConversationOperationsCertificationService::class,
        // GOD-DEBULK 3b: AtlasIntelligenceFactoryCertificationService removed — quarantined to
        // archive/app/Services/Ai/IntelligenceFactory (blueprint 91c334a27 §2.2); no longer in app/.
        AtlasTeosFinalCertificationService::class,
        AtlasTeosIncrement2CertificationService::class,
        AtlasTeosReadinessCertificationService::class,
        LongHorizonContinuityCertificationService::class,
        MissionCertificationService::class,
        AtlasPersistentContextCertificationService::class,
        AtlasAiProductCertificationService::class,
        AtlasAiRuntimeUxCertificationService::class,
        AtlasProductDeliveryCertificationService::class,
        AtlasCodeEnterpriseCertificationService::class,
        AtlasDevDesktopCertificationService::class,
        DevRunCertificationService::class,
        AtlasForgeContinuumCertificationService::class,
        AtlasForgeRuntimeCertificationService::class,
        DevForgeRobustFlowCertificationService::class,
        AtlasFrontendRunCertificationService::class,
        AtlasProgrammingFinalCertificationService::class,
        AtlasAutonomousRealitySandboxCertificationService::class,
        AtlasAiHyperflowCertificationService::class,
        AtlasDesktopHyperflowIntegrationCertificationService::class,
        AtlasRuntimeEfficiencyGovernorCertificationService::class,
        MultiAgentCycleCertificationService::class,
        AtlasStrategicRealityCertificationService::class,
        AtlasVoiceRuntimeCertificationService::class,
        AtlasSoftwareTwinVerifiedEvolutionCertificationService::class,
    ];

    public const C_PARKED = [
        AaeosL7CompletionCertificationService::class,
        Ap786RealCycleCertificationService::class,
        AreaFocusLoopCertificationService::class,
        AreaFocusLoopOperationalCertificationService::class,
        LoopAutonomyCertificationService::class,
        LoopChaosCertificationService::class,
        PlanDeliveryCertificationService::class,
        StewardshipBranchStressCertificationService::class,
        StewardshipBranchSystemCertificationService::class,
        StewardshipLiveCycleCertificationService::class,
    ];

    public const D_ISOLATED = [
        AgentControlPlaneAdapterExecutionRuntimeBoundaryCertificationService::class,
        AgentControlPlaneAutomaticCostImportRuntimeCertificationService::class,
        AgentControlPlaneAutomaticWorkProductCollectionCertificationService::class,
        AgentControlPlaneExecutionWorkspaceCertificationService::class,
        AgentControlPlaneGovernanceApprovalCertificationService::class,
        AgentControlPlaneMultiAgentLoopCertificationService::class,
        AgentControlPlaneRuntimePilotCertificationService::class,
        AgentControlPlaneTaskQueueLeaseCertificationService::class,
        AgentControlPlaneWorkerTaskEligibilityCertificationService::class,
        AgentDispatchPlannerCertificationService::class,
        AgentMergeReviewCertificationService::class,
        AgentRuntimeEvidenceCertificationService::class,
        AgentRuntimeRegistryCertificationService::class,
        AgentValidationGateCertificationService::class,
        AtlasSelfConstructionRealProviderSmokeCertificationService::class,
        AtlasSelfProgrammingSafetyContractCertificationService::class,
        VoxV5CertificationService::class,
        VoxV68CertificationService::class,
        VoxV6CertificationService::class,
    ];

    /** @return array<class-string, string> */
    public static function all(): array
    {
        $map = [];
        foreach (['A_DELIVERY', 'B_STATE', 'C_PARKED', 'D_ISOLATED'] as $cat) {
            foreach (constant(self::class.'::'.$cat) as $fqcn) {
                $map[$fqcn] = $cat;
            }
        }

        return $map;
    }

    public static function classify(string $fqcn): ?string
    {
        return self::all()[$fqcn] ?? null;
    }
}
