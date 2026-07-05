<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Adapters;

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
        \App\Services\Ai\Obra\AtlasObraCertificationService::class,
        \App\Services\Ai\Programming\Forge\Qa\ForgeObraCertificationService::class,
    ];

    public const B_STATE = [
        // Reclassificado 05/07: certify() sem argumentos rodando smokes do runtime AVER
        // = auditor de ESTADO, nao juiz de entrega (a 1a classificacao errou; leitura direta corrigiu).
        \App\Services\Ai\VerifiedExecution\AtlasVerifiedExecutionCertificationService::class,
        \App\Services\Ai\Aemor\AtlasAemorCertificationService::class,
        \App\Services\Ai\AgenticWorkcell\AtlasAgenticWorkcellCertificationService::class,
        \App\Services\Ai\AutonomousEvolution\AtlasAutonomousEvolutionCertificationService::class,
        \App\Services\Ai\AutonomousWorkExecution\AtlasAutonomousWorkExecutionCertificationService::class,
        \App\Services\Ai\Compounding\AtlasTemporalCertificationService::class,
        \App\Services\Ai\Context\AtlasContextQualityCertificationService::class,
        \App\Services\Ai\ContextIntelligence\AtlasContextIntelligenceCertificationService::class,
        \App\Services\Ai\ConversationOps\AtlasConversationOperationsCertificationService::class,
        \App\Services\Ai\IntelligenceFactory\AtlasIntelligenceFactoryCertificationService::class,
        \App\Services\Ai\LongHorizon\AtlasTeosFinalCertificationService::class,
        \App\Services\Ai\LongHorizon\AtlasTeosIncrement2CertificationService::class,
        \App\Services\Ai\LongHorizon\AtlasTeosReadinessCertificationService::class,
        \App\Services\Ai\LongHorizon\LongHorizonContinuityCertificationService::class,
        \App\Services\Ai\Mission\MissionCertificationService::class,
        \App\Services\Ai\PersistentContext\AtlasPersistentContextCertificationService::class,
        \App\Services\Ai\Product\AtlasAiProductCertificationService::class,
        \App\Services\Ai\Product\AtlasAiRuntimeUxCertificationService::class,
        \App\Services\Ai\Product\AtlasProductDeliveryCertificationService::class,
        \App\Services\Ai\Programming\AtlasCodeEnterpriseCertificationService::class,
        \App\Services\Ai\Programming\AtlasDev\Runtime\AtlasDevDesktopCertificationService::class,
        \App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevRunCertificationService::class,
        \App\Services\Ai\Programming\AtlasForgeContinuumCertificationService::class,
        \App\Services\Ai\Programming\AtlasForgeRuntimeCertificationService::class,
        \App\Services\Ai\Programming\DevForgeRobustFlowCertificationService::class,
        \App\Services\Ai\Programming\Frontend\AtlasFrontendRunCertificationService::class,
        \App\Services\Ai\ProgrammingRuntime\AtlasProgrammingFinalCertificationService::class,
        \App\Services\Ai\RealitySandbox\AtlasAutonomousRealitySandboxCertificationService::class,
        \App\Services\Ai\Router\AtlasAiHyperflowCertificationService::class,
        \App\Services\Ai\RouterRuntime\AtlasDesktopHyperflowIntegrationCertificationService::class,
        \App\Services\Ai\RuntimeEfficiency\AtlasRuntimeEfficiencyGovernorCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentCycleCertificationService::class,
        \App\Services\Ai\StrategicReality\AtlasStrategicRealityCertificationService::class,
        \App\Services\Ai\Voice\AtlasVoiceRuntimeCertificationService::class,
        \App\Services\Engineering\AtlasSoftwareTwinVerifiedEvolutionCertificationService::class,
    ];

    public const C_PARKED = [
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AaeosL10GenerativeEngineeringCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AaeosL7CompletionCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AaeosL8TranscendenceCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AaeosL9SovereignEngineeringCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Ap786RealCycleCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopOperationalCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L10R1GenerativeEngineeringCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L10R2LongHorizonStrategyCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L10R3BoundedRecursionCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8P1FrameEvolutionCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8P2MetaCompoundingCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8P3PredictiveTwinCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8P4LocalEngineDistillationCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8P5SelfDeceptionImmunityCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L9Q1OperatorJudgmentAmplificationCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L9Q2ProvenInvariantCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L9Q3EngineeringDisciplineEvolutionCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopAutonomyCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopChaosCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanDeliveryCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchStressCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchSystemCertificationService::class,
        \App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipLiveCycleCertificationService::class,
    ];

    public const D_ISOLATED = [
        \App\Services\Ai\SelfConstruction\AgentControlPlaneAdapterExecutionRuntimeBoundaryCertificationService::class,
        \App\Services\Ai\SelfConstruction\AgentControlPlaneAutomaticCostImportRuntimeCertificationService::class,
        \App\Services\Ai\SelfConstruction\AgentControlPlaneAutomaticWorkProductCollectionCertificationService::class,
        \App\Services\Ai\SelfConstruction\AgentControlPlaneExecutionWorkspaceCertificationService::class,
        \App\Services\Ai\SelfConstruction\AgentControlPlaneGovernanceApprovalCertificationService::class,
        \App\Services\Ai\SelfConstruction\AgentControlPlaneMultiAgentLoopCertificationService::class,
        \App\Services\Ai\SelfConstruction\AgentControlPlaneRuntimePilotCertificationService::class,
        \App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueLeaseCertificationService::class,
        \App\Services\Ai\SelfConstruction\AgentControlPlaneWorkerTaskEligibilityCertificationService::class,
        \App\Services\Ai\SelfConstruction\AgentDispatchPlannerCertificationService::class,
        \App\Services\Ai\SelfConstruction\AgentMergeReviewCertificationService::class,
        \App\Services\Ai\SelfConstruction\AgentRuntimeEvidenceCertificationService::class,
        \App\Services\Ai\SelfConstruction\AgentRuntimeRegistryCertificationService::class,
        \App\Services\Ai\SelfConstruction\AgentValidationGateCertificationService::class,
        \App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeCertificationService::class,
        \App\Services\Ai\SelfConstruction\AtlasSelfProgrammingSafetyContractCertificationService::class,
        \App\Services\Ai\Vox\Gate\VoxV5CertificationService::class,
        \App\Services\Ai\Vox\Gate\VoxV68CertificationService::class,
        \App\Services\Ai\Vox\Gate\VoxV6CertificationService::class,
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
