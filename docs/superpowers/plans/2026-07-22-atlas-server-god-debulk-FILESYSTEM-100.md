# Atlas Server — PLANO 100% FILESYSTEM (cada path em um bucket)

> Gerado: 2026-07-22T15:29:58.829611+00:00
> **PLANEJAMENTO OPERACIONAL COMPLETO** — cobertura Δ files=0 Δ loc=0 contra walk do corpus.
> Alvo **10/10** capacidades A–G (ver hub COMPLETE).
> Hub: `docs/superpowers/plans/2026-07-22-atlas-server-god-debulk-COMPLETE.md`

## Como ler
Cada bucket lista: métricas, ações obrigatórias, **todo arquivo ≥800 LOC** com ação, contagem de peels/fixtures.
Done do bucket = critérios 10/10 locais + CODEMAP/OWNERSHIP.

## Scoreboard cobertura

| Métrica | Valor |
|---|---:|
| Buckets | 478 |
| Files | 15,260 |
| LOC | 3,520,534 |
| Walk files | 15,260 |
| Δ files | 0 |
| Δ loc | 0 (same walk) |

**PROOF: Δ files = 0 (must be 0).**

## Índice por WAVE

| WAVE | Buckets | LOC |
|---|---:|---:|
| A1 | 8 | 1,045,894 |
| A2 | 13 | 216,526 |
| A3 | 33 | 170,515 |
| A4 | 66 | 56,417 |
| B1 | 1 | 138,624 |
| B3 | 1 | 46,749 |
| B4 | 1 | 21,138 |
| B-SVC | 9 | 106,238 |
| B-OTHER | 6 | 4,998 |
| T | 187 | 1,215,297 |
| D | 145 | 432,856 |
| I | 8 | 65,282 |

## WAVE A1

### `app/Services/Ai/SelfConstruction` · LOC **398,540** · files **1210** · php **1210**

| Métrica | Valor |
|---|---:|
| LOC | 398,540 |
| Files | 1210 |
| ≥2000 | 10 |
| 800–1999 | 30 |
| PHP peels <80 | 123 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 29,744 | php | `app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |
| 14,169 | php | `app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentReviewMergeSection.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |
| 13,784 | php | `app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentCodexSection.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |
| 12,751 | php | `app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionCodexReviewMergeSection.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |
| 6,198 | php | `app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentAutomaticDispatchBatch1Section.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |
| 5,360 | php | `app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentAutomaticDispatchBatch2Section.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |
| 4,302 | php | `app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentDispatchProviderSection.php` | MANDATORY_SPLIT → <2000 |
| 4,284 | php | `app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentControlPlaneSection.php` | MANDATORY_SPLIT → <2000 |
| 2,355 | php | `app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionDispatchGateSection.php` | MANDATORY_SPLIT → <2000 |
| 2,102 | php | `app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService.php` | MANDATORY_SPLIT → <2000 |
| 1,956 | php | `app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionDurableReservationSection.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,937 | php | `app/Services/Ai/SelfConstruction/AgentControlPlaneTaskQueueOrchestrator.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,854 | php | `app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,759 | php | `app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionOsEvidenceSection.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,650 | php | `app/Services/Ai/SelfConstruction/AtlasTaskServingService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,598 | php | `app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneTerminalLoopHealthDigestService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,434 | php | `app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionPostStartGateStatusSection.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,359 | php | `app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionReleaseWriterSection.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,267 | php | `app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneMultiAgentLoopProbeRunner.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,209 | php | `app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneClaimLeaseRepository.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,202 | php | `app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneTaskAutoReplenishmentService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,188 | php | `app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneTerminalLoopOperationalProofService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,176 | php | `app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneMultiAgentLoopCertificationService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,175 | php | `app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneTaskPacketQueueRepository.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,124 | php | `app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneChainIntegrityAuditService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,114 | php | `app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionMutatingWriterSection.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,110 | php | `app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentWakeupSection.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,102 | php | `app/Services/Ai/SelfConstruction/AtlasTaskPacketQualityInspector.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,038 | php | `app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,032 | php | `app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneTaskLeaseRecoveryService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 972 | php | `app/Services/Ai/SelfConstruction/Governance/AtlasTaskMergeActuator.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 946 | php | `app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionPostStartGateBSection.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 941 | php | `app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionRuntimePromotionEndgameService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 916 | php | `app/Services/Ai/SelfConstruction/ControlPlane/AgentRuntimeRegistryRepository.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 903 | php | `app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 878 | php | `app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneOneShotWorkerPacketService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 877 | php | `app/Services/Ai/SelfConstruction/TaskServing/AtlasTaskBrainReplenisher.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 875 | php | `app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneTerminalWorkerBootstrapService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 837 | php | `app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainRunRetrospectiveCompiler.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 815 | php | `app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneTaskPacketBuilder.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Aaeos` · LOC **142,212** · files **346** · php **346**

| Métrica | Valor |
|---|---:|
| LOC | 142,212 |
| Files | 346 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 7 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=7; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Programming` · LOC **131,893** · files **543** · php **543**

| Métrica | Valor |
|---|---:|
| LOC | 131,893 |
| Files | 543 |
| ≥2000 | 2 |
| 800–1999 | 28 |
| PHP peels <80 | 147 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 4,683 | php | `app/Services/Ai/Programming/ProgrammingProfessionalCompletionAuditService.php` | MANDATORY_SPLIT → <2000 |
| 2,537 | php | `app/Services/Ai/Programming/Frontend/AtlasFrontendRivalReplayHarnessService.php` | MANDATORY_SPLIT → <2000 |
| 1,491 | php | `app/Services/Ai/Programming/AtlasDev/Pipeline/SpecComposer.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,442 | php | `app/Services/Ai/Programming/Console/ProgrammingConsoleService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,434 | php | `app/Services/Ai/Programming/Frontend/AtlasFrontendDesignRuntimeService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,272 | php | `app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,260 | php | `app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,251 | php | `app/Services/Ai/Programming/AtlasDev/MinimaxFirst/AtlasMinimaxFirstWorkerService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,213 | php | `app/Services/Ai/Programming/AtlasCodeForgeFastPathService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,149 | php | `app/Services/Ai/Programming/AtlasFableFinalCaptureService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,145 | php | `app/Services/Ai/Programming/Forge/ForgeIntakeService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,118 | php | `app/Services/Ai/Programming/AtlasDev/Runtime/AtlasDevDesktopEfficiencyEvidenceService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,086 | php | `app/Services/Ai/Programming/AtlasCodeForgeUxOrchestratorService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,079 | php | `app/Services/Ai/Programming/AtlasCodeObraCommandCenterService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,048 | php | `app/Services/Ai/Programming/Governance/ProgrammingAdaptiveHierarchicalControlPlaneService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,027 | php | `app/Services/Ai/Programming/AtlasDevBeatTestReportService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,021 | php | `app/Services/Ai/Programming/ProgrammingRivalsReadinessService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,005 | php | `app/Services/Ai/Programming/AtlasRivalsBatteryStateMachine.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 974 | php | `app/Services/Ai/Programming/AtlasDev/Pipeline/AtlasDevFastPathOrchestrator.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 973 | php | `app/Services/Ai/Programming/AtlasDev/Intelligence/ReviewIntelligenceService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 936 | php | `app/Services/Ai/Programming/AtlasCodeAttentionControlPlaneService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 924 | php | `app/Services/Ai/Programming/AtlasRivalsEvidencePackService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 919 | php | `app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 905 | php | `app/Services/Ai/Programming/AtlasForgeGovernedExecutionService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 899 | php | `app/Services/Ai/Programming/Frontend/AtlasFrontendSelectedWorkspaceService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 899 | php | `app/Services/Ai/Programming/AtlasForgeLiveExecutionService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 876 | php | `app/Services/Ai/Programming/AtlasDevRuntimeService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 830 | php | `app/Services/Ai/Programming/Forge/ForgeLongHorizonStateService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 817 | php | `app/Services/Ai/Programming/AtlasForgeProviderCapacityService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 808 | php | `app/Services/Ai/Programming/AtlasDev/Mutation/MutationTestingAdapter.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/SoftwareCompanyStewardship` · LOC **116,401** · files **280** · php **280**

| Métrica | Valor |
|---|---:|
| LOC | 116,401 |
| Files | 280 |
| ≥2000 | 4 |
| 800–1999 | 31 |
| PHP peels <80 | 43 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 6,826 | php | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |
| 3,974 | php | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php` | MANDATORY_SPLIT → <2000 |
| 3,708 | php | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php` | MANDATORY_SPLIT → <2000 |
| 2,756 | php | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php` | MANDATORY_SPLIT → <2000 |
| 1,585 | php | `app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,581 | php | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/FirstFullCycleOrchestratorService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,435 | php | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,418 | php | `app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerSandboxRuntimeRunnerService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,350 | php | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Loop24hCertificationHarnessService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,218 | php | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,178 | php | `app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/DevForgeRuntimeExecutionBridgeService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,093 | php | `app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalInboxReadModelService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,083 | php | `app/Services/Ai/SoftwareCompanyStewardship/ContinuousStewardship/ContinuousStewardshipRunnerService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,053 | php | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopPreflightCycleFirewallService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,016 | php | `app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipRuntimeResultBridgeService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,003 | php | `app/Services/Ai/SoftwareCompanyStewardship/AgentExecution/LaneExecutionContractService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 991 | php | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/PlanExecution/BuildPlanDecomposerService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 984 | php | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/PlanExecution/PlanCompletionTrackerService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 981 | php | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/FindingSlicePlannerService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 960 | php | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AgenticEngineeringOsFindingEngineService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 957 | php | `app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipLiveCycleCertificationService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 935 | php | `app/Services/Ai/SoftwareCompanyStewardship/AgentExecution/MultiAgentIntegrationJudgeService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 934 | php | `app/Services/Ai/SoftwareCompanyStewardship/AgentExecution/MultiAgentRepairPlannerService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 931 | php | `app/Services/Ai/SoftwareCompanyStewardship/AgentExecution/MultiAgentLiveCycleExecutorService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 927 | php | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchMergeGovernorService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 885 | php | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopQualityDriftDetectorService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 874 | php | `app/Services/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipHealthModelService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 874 | php | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/TenCycleReadinessGovernorService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 868 | php | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopPostCycleAuditorService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 850 | php | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopInvariantHarnessService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 828 | php | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipLiveCycleAuditService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 827 | php | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusCandidateQuarantineService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 820 | php | `app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOutcomeEvidenceBridgeService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 819 | php | `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LongRunCertificationLadderService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 808 | php | `app/Services/Ai/SoftwareCompanyStewardship/AgentExecution/MultiAgentCycleCertificationService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/AutonomousEvolution` · LOC **109,613** · files **640** · php **639**

| Métrica | Valor |
|---|---:|
| LOC | 109,613 |
| Files | 640 |
| ≥2000 | 0 |
| 800–1999 | 12 |
| PHP peels <80 | 174 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,883 | php | `app/Services/Ai/AutonomousEvolution/Campaign/AtlasLoopCampaignSupervisor.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,831 | php | `app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopQueueRefiller.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,675 | php | `app/Services/Ai/AutonomousEvolution/AtlasLoopAutoMergeService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,265 | php | `app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,231 | php | `app/Services/Ai/AutonomousEvolution/AtlasLoopObraExecutionAdapter.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,115 | php | `app/Services/Ai/AutonomousEvolution/AtlasLoopSemanticImplementationCertifier.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 929 | php | `app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 914 | php | `app/Services/Ai/AutonomousEvolution/AtlasAutonomousEvolutionLoopService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 896 | php | `app/Services/Ai/AutonomousEvolution/AtlasLoopMutationAdequacyGateService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 862 | php | `app/Services/Ai/AutonomousEvolution/AtlasLoopOriginationPipeline.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 841 | php | `app/Services/Ai/AutonomousEvolution/AtlasLoopIntentVerifierFactory.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 835 | php | `app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopTargetDiscoveryService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/(root-files)` · LOC **61,372** · files **95** · php **95**

| Métrica | Valor |
|---|---:|
| LOC | 61,372 |
| Files | 95 |
| ≥2000 | 8 |
| 800–1999 | 12 |
| PHP peels <80 | 14 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 5,884 | php | `app/Services/Ai/AtlasOpenBrainMcpService.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |
| 4,189 | php | `app/Services/Ai/AtlasOpenBrainContextPackService.php` | MANDATORY_SPLIT → <2000 |
| 3,826 | php | `app/Services/Ai/AiWorker.php` | MANDATORY_SPLIT → <2000 |
| 2,778 | php | `app/Services/Ai/AiGatewayService.php` | MANDATORY_SPLIT → <2000 |
| 2,585 | php | `app/Services/Ai/AtlasOpenBrainContextInjectionService.php` | MANDATORY_SPLIT → <2000 |
| 2,572 | php | `app/Services/Ai/AtlasDomainProfileRegistry.php` | MANDATORY_SPLIT → <2000 |
| 2,569 | php | `app/Services/Ai/AtlasAobgWorkspaceOnboardingService.php` | MANDATORY_SPLIT → <2000 |
| 2,160 | php | `app/Services/Ai/AtlasDecideService.php` | MANDATORY_SPLIT → <2000 |
| 1,780 | php | `app/Services/Ai/YouTubeKnowledgeIngestionService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,672 | php | `app/Services/Ai/AiPromptBuilder.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,390 | php | `app/Services/Ai/HermesCliProvider.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,351 | php | `app/Services/Ai/AtlasMemoryQualityService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,345 | php | `app/Services/Ai/AiCompactionService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,290 | php | `app/Services/Ai/AtlasMemoryRegistryService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,139 | php | `app/Services/Ai/AtlasOpenBrainSessionCaptureService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,082 | php | `app/Services/Ai/AtlasProviderProjectionService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,067 | php | `app/Services/Ai/AtlasOpenBrainGuardService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,050 | php | `app/Services/Ai/AiSkillStore.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 881 | php | `app/Services/Ai/AtlasHybridMemoryRetrievalService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 856 | php | `app/Services/Ai/AtlasOpenBrainFileContextService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Holding` · LOC **44,389** · files **6** · php **6**

| Métrica | Valor |
|---|---:|
| LOC | 44,389 |
| Files | 6 |
| ≥2000 | 3 |
| 800–1999 | 3 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 22,832 | php | `app/Services/Ai/Holding/ExternalActionMandateRegistryService.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |
| 10,060 | php | `app/Services/Ai/Holding/AutonomousHoldingEnterpriseBuildoutService.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |
| 7,057 | php | `app/Services/Ai/Holding/EnterpriseFlowFixtureActionRuntimeService.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |
| 1,739 | php | `app/Services/Ai/Holding/AutonomousHoldingOperatingCycleService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,566 | php | `app/Services/Ai/Holding/AutonomousHoldingReadinessService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,135 | php | `app/Services/Ai/Holding/EnterpriseFlowFixtureSuiteService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Kernel` · LOC **41,474** · files **159** · php **159**

| Métrica | Valor |
|---|---:|
| LOC | 41,474 |
| Files | 159 |
| ≥2000 | 2 |
| 800–1999 | 6 |
| PHP peels <80 | 71 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 15,566 | php | `app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |
| 2,240 | php | `app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php` | MANDATORY_SPLIT → <2000 |
| 1,098 | php | `app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,096 | php | `app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,086 | php | `app/Services/Ai/Kernel/Architecture/AtlasFeaturePlacementService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 992 | php | `app/Services/Ai/Kernel/Architecture/AtlasStructureMotherAuditReadModel.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 864 | php | `app/Services/Ai/Kernel/Architecture/AtlasProviderReleaseIntelligenceService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 802 | php | `app/Services/Ai/Kernel/Architecture/AtlasExternalGraphHarnessService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

## WAVE A2

### `app/Services/Ai/MarketingDomain` · LOC **26,853** · files **180** · php **180**

| Métrica | Valor |
|---|---:|
| LOC | 26,853 |
| Files | 180 |
| ≥2000 | 0 |
| 800–1999 | 2 |
| PHP peels <80 | 50 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,106 | php | `app/Services/Ai/MarketingDomain/Content/BridgePageComposerService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 819 | php | `app/Services/Ai/MarketingDomain/VslIntelligenceExtractorService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/AgenticEngineeringOs` · LOC **26,649** · files **17** · php **17**

| Métrica | Valor |
|---|---:|
| LOC | 26,649 |
| Files | 17 |
| ≥2000 | 1 |
| 800–1999 | 1 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 21,662 | php | `app/Services/Ai/AgenticEngineeringOs/AtlasUniversalGatesEvaluator.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |
| 1,010 | php | `app/Services/Ai/AgenticEngineeringOs/DepartmentContractRuntime.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Rivals` · LOC **20,724** · files **70** · php **70**

| Métrica | Valor |
|---|---:|
| LOC | 20,724 |
| Files | 70 |
| ≥2000 | 1 |
| 800–1999 | 3 |
| PHP peels <80 | 13 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 3,016 | php | `app/Services/Ai/Rivals/Core/EnterpriseReportBuilder.php` | MANDATORY_SPLIT → <2000 |
| 1,844 | php | `app/Services/Ai/Rivals/Core/EnterpriseReportDashboardHtml.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,036 | php | `app/Services/Ai/Rivals/Core/FaseABatteryOrchestrator.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,034 | php | `app/Services/Ai/Rivals/Core/NativeResultNormalizer.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Context` · LOC **20,248** · files **65** · php **65**

| Métrica | Valor |
|---|---:|
| LOC | 20,248 |
| Files | 65 |
| ≥2000 | 0 |
| 800–1999 | 4 |
| PHP peels <80 | 9 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,572 | php | `app/Services/Ai/Context/LocalRagBenchmarkService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,372 | php | `app/Services/Ai/Context/AtlasRetrievalFeedbackLoopService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,108 | php | `app/Services/Ai/Context/AtlasContextRankingSystemService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 903 | php | `app/Services/Ai/Context/AtlasAucriOptimizationAuditService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Finance` · LOC **17,631** · files **100** · php **100**

| Métrica | Valor |
|---|---:|
| LOC | 17,631 |
| Files | 100 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 25 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,025 | php | `app/Services/Ai/Finance/StrategyLoop/Campaign/StrategyScenarioRegistry.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Vox` · LOC **16,103** · files **39** · php **39**

| Métrica | Valor |
|---|---:|
| LOC | 16,103 |
| Files | 39 |
| ≥2000 | 1 |
| 800–1999 | 3 |
| PHP peels <80 | 5 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 2,116 | php | `app/Services/Ai/Vox/Gate/VoxV6CertificationService.php` | MANDATORY_SPLIT → <2000 |
| 1,020 | php | `app/Services/Ai/Vox/Gate/VoxV5CertificationService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 921 | php | `app/Services/Ai/Vox/Audit/VoxV3HardeningAuditService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 865 | php | `app/Services/Ai/Vox/Routing/VoxFlowOrchestrator.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/EngineeringKernel` · LOC **14,293** · files **110** · php **110**

| Métrica | Valor |
|---|---:|
| LOC | 14,293 |
| Files | 110 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 55 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,343 | php | `app/Services/Ai/EngineeringKernel/EliteExecutorKernel.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/AcosMax` · LOC **14,223** · files **51** · php **51**

| Métrica | Valor |
|---|---:|
| LOC | 14,223 |
| Files | 51 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 4 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,266 | php | `app/Services/Ai/AcosMax/AcosMaxLote2MeasureService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Hermes` · LOC **13,507** · files **53** · php **53**

| Métrica | Valor |
|---|---:|
| LOC | 13,507 |
| Files | 53 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 9 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,152 | php | `app/Services/Ai/Hermes/HermesCapabilityProbe.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Cognition` · LOC **13,420** · files **52** · php **52**

| Métrica | Valor |
|---|---:|
| LOC | 13,420 |
| Files | 52 |
| ≥2000 | 0 |
| 800–1999 | 2 |
| PHP peels <80 | 11 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,229 | php | `app/Services/Ai/Cognition/Watchdog/AtlasAcosWatchdogHealthService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,059 | php | `app/Services/Ai/Cognition/AtlasAcosLongHorizonGateService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/SelfImprovement` · LOC **11,333** · files **22** · php **22**

| Métrica | Valor |
|---|---:|
| LOC | 11,333 |
| Files | 22 |
| ≥2000 | 1 |
| 800–1999 | 3 |
| PHP peels <80 | 2 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 3,166 | php | `app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php` | MANDATORY_SPLIT → <2000 |
| 1,044 | php | `app/Services/Ai/SelfImprovement/AtlasSelfImprovementForgeActivationService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 830 | php | `app/Services/Ai/SelfImprovement/AtlasSelfImprovementActivationCockpitService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 815 | php | `app/Services/Ai/SelfImprovement/AtlasSelfImprovementProposalBacklogService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Foundry` · LOC **11,331** · files **47** · php **47**

| Métrica | Valor |
|---|---:|
| LOC | 11,331 |
| Files | 47 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 12 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 927 | php | `app/Services/Ai/Foundry/FoundryEvidenceHarvesterService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Product` · LOC **10,211** · files **34** · php **34**

| Métrica | Valor |
|---|---:|
| LOC | 10,211 |
| Files | 34 |
| ≥2000 | 1 |
| 800–1999 | 0 |
| PHP peels <80 | 4 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 2,231 | php | `app/Services/Ai/Product/AtlasAiProductCertificationService.php` | MANDATORY_SPLIT → <2000 |

---

## WAVE A3

### `app/Services/Ai/Cli` · LOC **9,618** · files **30** · php **30**

| Métrica | Valor |
|---|---:|
| LOC | 9,618 |
| Files | 30 |
| ≥2000 | 0 |
| 800–1999 | 2 |
| PHP peels <80 | 4 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,598 | php | `app/Services/Ai/Cli/AtlasFileAttachmentService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 880 | php | `app/Services/Ai/Cli/AtlasCliDevWorkflowService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/AtlasDecide` · LOC **9,583** · files **33** · php **33**

| Métrica | Valor |
|---|---:|
| LOC | 9,583 |
| Files | 33 |
| ≥2000 | 0 |
| 800–1999 | 2 |
| PHP peels <80 | 3 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,173 | php | `app/Services/Ai/AtlasDecide/AtlasDecideMetaLearningService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,153 | php | `app/Services/Ai/AtlasDecide/AtlasEngineeringRunConductorService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Mobile` · LOC **8,925** · files **22** · php **22**

| Métrica | Valor |
|---|---:|
| LOC | 8,925 |
| Files | 22 |
| ≥2000 | 0 |
| 800–1999 | 2 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,707 | php | `app/Services/Ai/Mobile/InboxActionRegistry.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 891 | php | `app/Services/Ai/Mobile/MobilePushService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Cognitive` · LOC **8,848** · files **65** · php **65**

| Métrica | Valor |
|---|---:|
| LOC | 8,848 |
| Files | 65 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 26 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=26; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/LongHorizon` · LOC **8,678** · files **23** · php **23**

| Métrica | Valor |
|---|---:|
| LOC | 8,678 |
| Files | 23 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 872 | php | `app/Services/Ai/LongHorizon/AtlasTeosReadinessCertificationService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Telemetry` · LOC **8,623** · files **35** · php **35**

| Métrica | Valor |
|---|---:|
| LOC | 8,623 |
| Files | 35 |
| ≥2000 | 0 |
| 800–1999 | 3 |
| PHP peels <80 | 15 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,136 | php | `app/Services/Ai/Telemetry/AiTraceMetricAggregator.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,012 | php | `app/Services/Ai/Telemetry/AiTelemetryPerformanceReportService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 948 | php | `app/Services/Ai/Telemetry/AiTelemetryHealthService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/VentureFoundry` · LOC **8,117** · files **39** · php **39**

| Métrica | Valor |
|---|---:|
| LOC | 8,117 |
| Files | 39 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 10 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=10; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/WorkspaceIntelligence` · LOC **8,059** · files **17** · php **17**

| Métrica | Valor |
|---|---:|
| LOC | 8,059 |
| Files | 17 |
| ≥2000 | 1 |
| 800–1999 | 0 |
| PHP peels <80 | 8 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 4,875 | php | `app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceRuntimeService.php` | MANDATORY_SPLIT → <2000 |

---

### `app/Services/Ai/Compounding` · LOC **7,321** · files **40** · php **40**

| Métrica | Valor |
|---|---:|
| LOC | 7,321 |
| Files | 40 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 11 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=11; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Memory` · LOC **7,238** · files **34** · php **34**

| Métrica | Valor |
|---|---:|
| LOC | 7,238 |
| Files | 34 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 7 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,278 | php | `app/Services/Ai/Memory/MemoryConsolidationScanner.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/RealExecution` · LOC **6,431** · files **8** · php **8**

| Métrica | Valor |
|---|---:|
| LOC | 6,431 |
| Files | 8 |
| ≥2000 | 1 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 3,989 | php | `app/Services/Ai/RealExecution/AtlasRealEngineeringExecutionKernelService.php` | MANDATORY_SPLIT → <2000 |

---

### `app/Services/Ai/Publishing` · LOC **6,254** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 6,254 |
| Files | 2 |
| ≥2000 | 1 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 5,497 | php | `app/Services/Ai/Publishing/BlogEditorialContextService.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |

---

### `app/Services/Ai/Reality` · LOC **5,620** · files **7** · php **7**

| Métrica | Valor |
|---|---:|
| LOC | 5,620 |
| Files | 7 |
| ≥2000 | 1 |
| 800–1999 | 1 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 2,807 | php | `app/Services/Ai/Reality/AtlasRealityGraphIngestionService.php` | MANDATORY_SPLIT → <2000 |
| 1,542 | php | `app/Services/Ai/Reality/AtlasRealityGraphQueryService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Voice` · LOC **5,363** · files **12** · php **12**

| Métrica | Valor |
|---|---:|
| LOC | 5,363 |
| Files | 12 |
| ≥2000 | 1 |
| 800–1999 | 1 |
| PHP peels <80 | 2 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 2,483 | php | `app/Services/Ai/Voice/AtlasVoiceRealtimeService.php` | MANDATORY_SPLIT → <2000 |
| 984 | php | `app/Services/Ai/Voice/AtlasVoiceRuntimeCertificationService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Mission` · LOC **5,115** · files **25** · php **25**

| Métrica | Valor |
|---|---:|
| LOC | 5,115 |
| Files | 25 |
| ≥2000 | 0 |
| 800–1999 | 2 |
| PHP peels <80 | 7 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 995 | php | `app/Services/Ai/Mission/MissionFollowThroughService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 860 | php | `app/Services/Ai/Mission/MissionCertificationService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/ControlPlane` · LOC **4,904** · files **13** · php **13**

| Métrica | Valor |
|---|---:|
| LOC | 4,904 |
| Files | 13 |
| ≥2000 | 1 |
| 800–1999 | 0 |
| PHP peels <80 | 3 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 3,023 | php | `app/Services/Ai/ControlPlane/AtlasAiControlPlaneService.php` | MANDATORY_SPLIT → <2000 |

---

### `app/Services/Ai/Obra` · LOC **4,787** · files **19** · php **19**

| Métrica | Valor |
|---|---:|
| LOC | 4,787 |
| Files | 19 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 4 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,277 | php | `app/Services/Ai/Obra/AtlasObraExecutor.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Domain` · LOC **4,695** · files **22** · php **22**

| Métrica | Valor |
|---|---:|
| LOC | 4,695 |
| Files | 22 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Governance` · LOC **4,687** · files **16** · php **16**

| Métrica | Valor |
|---|---:|
| LOC | 4,687 |
| Files | 16 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/RouterRuntime` · LOC **3,778** · files **14** · php **14**

| Métrica | Valor |
|---|---:|
| LOC | 3,778 |
| Files | 14 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 4 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,211 | php | `app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/OperatorIntelligence` · LOC **3,665** · files **19** · php **19**

| Métrica | Valor |
|---|---:|
| LOC | 3,665 |
| Files | 19 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/ProgrammingRuntime` · LOC **3,657** · files **12** · php **12**

| Métrica | Valor |
|---|---:|
| LOC | 3,657 |
| Files | 12 |
| ≥2000 | 0 |
| 800–1999 | 2 |
| PHP peels <80 | 4 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 935 | php | `app/Services/Ai/ProgrammingRuntime/AtlasProgrammingFinalCertificationService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 832 | php | `app/Services/Ai/ProgrammingRuntime/ProgrammingRuntimeReadinessService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Runtime` · LOC **2,879** · files **14** · php **14**

| Métrica | Valor |
|---|---:|
| LOC | 2,879 |
| Files | 14 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 6 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,055 | php | `app/Services/Ai/Runtime/AiToolRuntime.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Autonomy` · LOC **2,775** · files **10** · php **10**

| Métrica | Valor |
|---|---:|
| LOC | 2,775 |
| Files | 10 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 3 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=3; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Router` · LOC **2,764** · files **10** · php **10**

| Métrica | Valor |
|---|---:|
| LOC | 2,764 |
| Files | 10 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Organism` · LOC **2,647** · files **24** · php **24**

| Métrica | Valor |
|---|---:|
| LOC | 2,647 |
| Files | 24 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 11 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=11; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Aemor` · LOC **2,444** · files **5** · php **5**

| Métrica | Valor |
|---|---:|
| LOC | 2,444 |
| Files | 5 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 814 | php | `app/Services/Ai/Aemor/AtlasAemorRuntimeService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Compression` · LOC **2,327** · files **14** · php **14**

| Métrica | Valor |
|---|---:|
| LOC | 2,327 |
| Files | 14 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 5 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=5; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/RuntimeEfficiency` · LOC **2,269** · files **4** · php **4**

| Métrica | Valor |
|---|---:|
| LOC | 2,269 |
| Files | 4 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,093 | php | `app/Services/Ai/RuntimeEfficiency/AtlasRuntimeEfficiencyGovernorService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Arena` · LOC **2,231** · files **7** · php **7**

| Métrica | Valor |
|---|---:|
| LOC | 2,231 |
| Files | 7 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/ToolRuntime` · LOC **2,080** · files **15** · php **15**

| Métrica | Valor |
|---|---:|
| LOC | 2,080 |
| Files | 15 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 5 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=5; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Surface` · LOC **2,079** · files **15** · php **15**

| Métrica | Valor |
|---|---:|
| LOC | 2,079 |
| Files | 15 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 11 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=11; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Evidence` · LOC **2,054** · files **17** · php **17**

| Métrica | Valor |
|---|---:|
| LOC | 2,054 |
| Files | 17 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 2 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=2; garantir CODEMAP se houver API pública._

---

## WAVE A4

### `app/Services/Ai/AutonomousEngineering` · LOC **1,897** · files **4** · php **4**

| Métrica | Valor |
|---|---:|
| LOC | 1,897 |
| Files | 4 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,002 | php | `app/Services/Ai/AutonomousEngineering/AtlasAutonomousEngineeringService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/AutomationDomain` · LOC **1,869** · files **16** · php **16**

| Métrica | Valor |
|---|---:|
| LOC | 1,869 |
| Files | 16 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 7 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=7; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Cyber` · LOC **1,742** · files **14** · php **14**

| Métrica | Valor |
|---|---:|
| LOC | 1,742 |
| Files | 14 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 3 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=3; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/RuntimeBoundary` · LOC **1,739** · files **23** · php **23**

| Métrica | Valor |
|---|---:|
| LOC | 1,739 |
| Files | 23 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 17 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=17; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/AgenticWorkcell` · LOC **1,699** · files **3** · php **3**

| Métrica | Valor |
|---|---:|
| LOC | 1,699 |
| Files | 3 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,247 | php | `app/Services/Ai/AgenticWorkcell/AtlasAgenticWorkcellRuntimeService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/DomainRuntime` · LOC **1,574** · files **10** · php **10**

| Métrica | Valor |
|---|---:|
| LOC | 1,574 |
| Files | 10 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Strategy` · LOC **1,527** · files **13** · php **13**

| Métrica | Valor |
|---|---:|
| LOC | 1,527 |
| Files | 13 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 2 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=2; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Policy` · LOC **1,474** · files **10** · php **10**

| Métrica | Valor |
|---|---:|
| LOC | 1,474 |
| Files | 10 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Skills` · LOC **1,450** · files **8** · php **8**

| Métrica | Valor |
|---|---:|
| LOC | 1,450 |
| Files | 8 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Patamar4` · LOC **1,428** · files **6** · php **6**

| Métrica | Valor |
|---|---:|
| LOC | 1,428 |
| Files | 6 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Support` · LOC **1,413** · files **12** · php **12**

| Métrica | Valor |
|---|---:|
| LOC | 1,413 |
| Files | 12 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 4 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=4; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/ValueObjects` · LOC **1,370** · files **7** · php **7**

| Métrica | Valor |
|---|---:|
| LOC | 1,370 |
| Files | 7 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 3 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=3; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/NightShift` · LOC **1,348** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 1,348 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,006 | php | `app/Services/Ai/NightShift/AreaFocusLoopReadModelService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/ResearchDomain` · LOC **1,291** · files **10** · php **10**

| Métrica | Valor |
|---|---:|
| LOC | 1,291 |
| Files | 10 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/ContextIntelligence` · LOC **1,205** · files **5** · php **5**

| Métrica | Valor |
|---|---:|
| LOC | 1,205 |
| Files | 5 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Scheduling` · LOC **1,190** · files **6** · php **6**

| Métrica | Valor |
|---|---:|
| LOC | 1,190 |
| Files | 6 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/AgentGovernance` · LOC **1,159** · files **12** · php **12**

| Métrica | Valor |
|---|---:|
| LOC | 1,159 |
| Files | 12 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 6 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=6; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/AutonomousWorkExecution` · LOC **1,152** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 1,152 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 946 | php | `app/Services/Ai/AutonomousWorkExecution/AtlasAutonomousWorkExecutionService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/SelfDirectedEvolution` · LOC **1,114** · files **3** · php **3**

| Métrica | Valor |
|---|---:|
| LOC | 1,114 |
| Files | 3 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/StrategicReality` · LOC **1,080** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 1,080 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Learning` · LOC **1,067** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 1,067 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,067 | php | `app/Services/Ai/Learning/AtlasAiLearningLoopService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/SpecialistFlows` · LOC **1,052** · files **13** · php **13**

| Métrica | Valor |
|---|---:|
| LOC | 1,052 |
| Files | 13 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 9 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=9; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/VerifiedExecution` · LOC **1,044** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 1,044 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 815 | php | `app/Services/Ai/VerifiedExecution/AtlasVerifiedExecutionRuntimeService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Concerns` · LOC **1,027** · files **3** · php **3**

| Métrica | Valor |
|---|---:|
| LOC | 1,027 |
| Files | 3 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 2 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 908 | php | `app/Services/Ai/Concerns/RunsCliProcesses.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/OperatorApproval` · LOC **1,017** · files **4** · php **4**

| Métrica | Valor |
|---|---:|
| LOC | 1,017 |
| Files | 4 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/RealitySandbox` · LOC **1,003** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 1,003 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 806 | php | `app/Services/Ai/RealitySandbox/AtlasAutonomousRealitySandboxService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/IntelligenceFactory` · LOC **982** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 982 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/PersistentContext` · LOC **977** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 977 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/EngineeringCompany` · LOC **909** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 909 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 895 | php | `app/Services/Ai/EngineeringCompany/AtlasRealEngineeringCompanyRuntimeService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/StrategicOperatingSystem` · LOC **884** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 884 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 884 | php | `app/Services/Ai/StrategicOperatingSystem/AtlasStrategicOperatingSystemRuntimeService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/RuntimeReadiness` · LOC **857** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 857 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 857 | php | `app/Services/Ai/RuntimeReadiness/AtlasAiRuntimeReadinessService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Ai/Caching` · LOC **851** · files **6** · php **6**

| Métrica | Valor |
|---|---:|
| LOC | 851 |
| Files | 6 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 3 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=3; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Analysis` · LOC **835** · files **6** · php **6**

| Métrica | Valor |
|---|---:|
| LOC | 835 |
| Files | 6 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 2 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=2; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/PersonalDevelopment` · LOC **787** · files **6** · php **6**

| Métrica | Valor |
|---|---:|
| LOC | 787 |
| Files | 6 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/ConversationOps` · LOC **736** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 736 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Teos` · LOC **723** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 723 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Capture` · LOC **719** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 719 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/CrossDomain` · LOC **706** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 706 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Rsi` · LOC **701** · files **6** · php **6**

| Métrica | Valor |
|---|---:|
| LOC | 701 |
| Files | 6 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 2 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=2; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Provider` · LOC **675** · files **10** · php **10**

| Métrica | Valor |
|---|---:|
| LOC | 675 |
| Files | 10 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 8 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=8; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/AtlasForge` · LOC **665** · files **4** · php **4**

| Métrica | Valor |
|---|---:|
| LOC | 665 |
| Files | 4 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Attachments` · LOC **649** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 649 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/OpenBrain` · LOC **622** · files **3** · php **3**

| Métrica | Valor |
|---|---:|
| LOC | 622 |
| Files | 3 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/DualCore` · LOC **620** · files **5** · php **5**

| Métrica | Valor |
|---|---:|
| LOC | 620 |
| Files | 5 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 2 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=2; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Reconciliation` · LOC **613** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 613 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Brain` · LOC **591** · files **3** · php **3**

| Métrica | Valor |
|---|---:|
| LOC | 591 |
| Files | 3 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Knowledge` · LOC **585** · files **3** · php **3**

| Métrica | Valor |
|---|---:|
| LOC | 585 |
| Files | 3 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Compaction` · LOC **492** · files **4** · php **4**

| Métrica | Valor |
|---|---:|
| LOC | 492 |
| Files | 4 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 2 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=2; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/CognitiveMemory` · LOC **466** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 466 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Search` · LOC **456** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 456 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/VerifiedContextExecution` · LOC **442** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 442 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Cartography` · LOC **388** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 388 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/SoftwareCompany` · LOC **364** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 364 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Gateway` · LOC **357** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 357 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Operator` · LOC **323** · files **4** · php **4**

| Métrica | Valor |
|---|---:|
| LOC | 323 |
| Files | 4 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 3 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=3; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Transcription` · LOC **304** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 304 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/HumanSurface` · LOC **290** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 290 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Forge` · LOC **287** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 287 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Tasks` · LOC **284** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 284 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Mcp` · LOC **283** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 283 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Tokens` · LOC **275** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 275 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/RuntimeReleaseGate` · LOC **263** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 263 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Streaming` · LOC **167** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 167 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Instrumentation` · LOC **155** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 155 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/MemoryGovernance` · LOC **148** · files **3** · php **3**

| Métrica | Valor |
|---|---:|
| LOC | 148 |
| Files | 3 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 3 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=3; garantir CODEMAP se houver API pública._

---

### `app/Services/Ai/Security` · LOC **55** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 55 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

## WAVE B1

### `app/Console` · LOC **138,624** · files **939** · php **939**

| Métrica | Valor |
|---|---:|
| LOC | 138,624 |
| Files | 939 |
| ≥2000 | 3 |
| 800–1999 | 11 |
| PHP peels <80 | 413 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 4,737 | php | `app/Console/Commands/AiChatCommand.php` | MANDATORY_SPLIT → <2000 |
| 3,909 | php | `app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php` | MANDATORY_SPLIT → <2000 |
| 2,148 | php | `app/Console/Commands/AtlasAiAutonomousHoldingCommand.php` | MANDATORY_SPLIT → <2000 |
| 1,836 | php | `app/Console/Commands/AtlasAaeosCommand.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,760 | php | `app/Console/Commands/AtlasFinanceStrategySearchCommand.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,648 | php | `app/Console/Commands/AtlasFinancePolyExecCommand.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,643 | php | `app/Console/Commands/AtlasAiSelfConstructionMotherCommand.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,377 | php | `app/Console/Commands/AtlasCliDevCommand.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,279 | php | `app/Console/Commands/AtlasAiLocalRagBenchmarkCommand.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,239 | php | `app/Console/Commands/AtlasRivalsCommand.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,213 | php | `app/Console/Commands/AtlasAiVoiceRealtimeCommand.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,117 | php | `app/Console/Commands/AtlasEngineeringBenchmarkFairCommand.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,045 | php | `app/Console/Commands/AtlasEngineeringVisualSmokeCommand.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 916 | php | `app/Console/Commands/AtlasBrainStateCommand.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

## WAVE B3

### `app/Http` · LOC **46,749** · files **331** · php **331**

| Métrica | Valor |
|---|---:|
| LOC | 46,749 |
| Files | 331 |
| ≥2000 | 1 |
| 800–1999 | 7 |
| PHP peels <80 | 211 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 5,139 | php | `app/Http/Controllers/AtlasDev/Support/PipelineRunExecutor.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |
| 1,813 | php | `app/Http/Controllers/AtlasCodeWorkController.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,692 | php | `app/Http/Controllers/AtlasCodeForgeExecutionController.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,600 | php | `app/Http/Controllers/AtlasFrontendWorkspaceController.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,408 | php | `app/Http/Controllers/AiInteractionController.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,253 | php | `app/Http/Controllers/Ai/SoftwareCompanyStewardship/AreaFocusLoopCommandController.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,134 | php | `app/Http/Controllers/AtlasAiVoxController.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 902 | php | `app/Http/Controllers/EngineeringBenchmarkController.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

## WAVE B4

### `app/Models` · LOC **21,138** · files **407** · php **407**

| Métrica | Valor |
|---|---:|
| LOC | 21,138 |
| Files | 407 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 370 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=370; garantir CODEMAP se houver API pública._

---

## WAVE B-SVC

### `app/Services/Engineering` · LOC **72,135** · files **144** · php **144**

| Métrica | Valor |
|---|---:|
| LOC | 72,135 |
| Files | 144 |
| ≥2000 | 6 |
| 800–1999 | 6 |
| PHP peels <80 | 8 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 5,900 | php | `app/Services/Engineering/EngineeringBenchmarkService.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |
| 5,792 | php | `app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |
| 4,322 | php | `app/Services/Engineering/EngineeringCodeIntelligenceService.php` | MANDATORY_SPLIT → <2000 |
| 2,917 | php | `app/Services/Engineering/EngineeringHarnessRunnerService.php` | MANDATORY_SPLIT → <2000 |
| 2,894 | php | `app/Services/Engineering/AtlasDocumentationRealitySystemService.php` | MANDATORY_SPLIT → <2000 |
| 2,153 | php | `app/Services/Engineering/AtlasUniversalRealityCartographyService.php` | MANDATORY_SPLIT → <2000 |
| 1,090 | php | `app/Services/Engineering/EngineeringTestMatrixService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,017 | php | `app/Services/Engineering/EngineeringDocumentationHealthService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 961 | php | `app/Services/Engineering/CodeGraph/CodeGraphContextRetriever.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 933 | php | `app/Services/Engineering/AtlasSystemStructureService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 933 | php | `app/Services/Engineering/AtlasDocumentationRealityCausalSelfModelService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 815 | php | `app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/AtlasCode` · LOC **11,288** · files **33** · php **33**

| Métrica | Valor |
|---|---:|
| LOC | 11,288 |
| Files | 33 |
| ≥2000 | 0 |
| 800–1999 | 3 |
| PHP peels <80 | 3 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,420 | php | `app/Services/AtlasCode/AtlasCodeAskService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,006 | php | `app/Services/AtlasCode/DevToForgePromotionService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 845 | php | `app/Services/AtlasCode/AtlasCodeObservedSessionService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/(root-php-files)` · LOC **8,213** · files **15** · php **15**

| Métrica | Valor |
|---|---:|
| LOC | 8,213 |
| Files | 15 |
| ≥2000 | 1 |
| 800–1999 | 2 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 2,346 | php | `app/Services/ProjectExecutionService.php` | MANDATORY_SPLIT → <2000 |
| 1,272 | php | `app/Services/CaptureService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,171 | php | `app/Services/TaskPlanningService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Semantic` · LOC **5,385** · files **19** · php **19**

| Métrica | Valor |
|---|---:|
| LOC | 5,385 |
| Files | 19 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 4 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 875 | php | `app/Services/Semantic/CurationProposalService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Tools` · LOC **4,309** · files **14** · php **14**

| Métrica | Valor |
|---|---:|
| LOC | 4,309 |
| Files | 14 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `app/Services/MacAgent` · LOC **1,686** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 1,686 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,686 | php | `app/Services/MacAgent/MacAgentService.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Services/Vault` · LOC **1,446** · files **7** · php **7**

| Métrica | Valor |
|---|---:|
| LOC | 1,446 |
| Files | 7 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 2 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=2; garantir CODEMAP se houver API pública._

---

### `app/Services/Digital` · LOC **1,235** · files **7** · php **7**

| Métrica | Valor |
|---|---:|
| LOC | 1,235 |
| Files | 7 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `app/Services/Bitacula` · LOC **541** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 541 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

## WAVE B-OTHER

### `app/Providers` · LOC **1,760** · files **3** · php **3**

| Métrica | Valor |
|---|---:|
| LOC | 1,760 |
| Files | 3 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,590 | php | `app/Providers/AppServiceProvider.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `app/Support` · LOC **1,610** · files **15** · php **15**

| Métrica | Valor |
|---|---:|
| LOC | 1,610 |
| Files | 15 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 8 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=8; garantir CODEMAP se houver API pública._

---

### `app/Jobs` · LOC **1,478** · files **14** · php **14**

| Métrica | Valor |
|---|---:|
| LOC | 1,478 |
| Files | 14 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 8 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=8; garantir CODEMAP se houver API pública._

---

### `app/Enums` · LOC **88** · files **3** · php **3**

| Métrica | Valor |
|---|---:|
| LOC | 88 |
| Files | 3 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 3 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=3; garantir CODEMAP se houver API pública._

---

### `app/Observers` · LOC **36** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 36 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `app/Logging` · LOC **26** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 26 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

## WAVE T

### `tests/Unit/Ai` · LOC **608,475** · files **3061** · php **3061**

| Métrica | Valor |
|---|---:|
| LOC | 608,475 |
| Files | 3061 |
| ≥2000 | 5 |
| 800–1999 | 24 |
| PHP peels <80 | 739 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 17,206 | php | `tests/Unit/Ai/AgenticEngineeringOs/AtlasUniversalGatesEvaluatorTest.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |
| 5,043 | php | `tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionServiceTest.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |
| 2,976 | php | `tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerServiceTest.php` | MANDATORY_SPLIT → <2000 |
| 2,416 | php | `tests/Unit/Ai/ProposalInboxEmitterTest.php` | MANDATORY_SPLIT → <2000 |
| 2,108 | php | `tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php` | MANDATORY_SPLIT → <2000 |
| 1,863 | php | `tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutorTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,737 | php | `tests/Unit/Ai/Holding/AutonomousHoldingEnterpriseBuildoutServiceTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,619 | php | `tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,614 | php | `tests/Unit/Ai/Programming/AtlasDev/Probe/E6ConstitutionGateTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,421 | php | `tests/Unit/Ai/Programming/AtlasDev/Http/PipelineRunExecutorTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,399 | php | `tests/Unit/Ai/Programming/Frontend/AtlasFrontendRivalReplayHarnessServiceTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,324 | php | `tests/Unit/Ai/Rivals/EnterpriseReportBuilderTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,175 | php | `tests/Unit/Ai/Programming/AtlasDev/MinimaxFirst/AtlasMinimaxFirstWorkerServiceTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,017 | php | `tests/Unit/Ai/Vox/VoxFlowOrchestratorTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,001 | php | `tests/Unit/Ai/SelfConstruction/Completion/AtlasSelfConstructionFinalEvidenceSourceRegistryTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 976 | php | `tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceServiceTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 955 | php | `tests/Unit/Ai/Vox/VoxInterlocutorPolicyTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 948 | php | `tests/Unit/Ai/SelfConstruction/AgentControlPlaneTaskQueueOrchestratorTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 943 | php | `tests/Unit/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainComplexityDebtBurnDownPlannerTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 915 | php | `tests/Unit/Ai/AtlasDecideReceiptIntegrationTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 914 | php | `tests/Unit/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainOutcomeLearnerTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 914 | php | `tests/Unit/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainFinal95GapBurnDownSchedulerTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 896 | php | `tests/Unit/Ai/AtlasDecide/AtlasEngineeringRunConductorServiceTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 844 | php | `tests/Unit/Ai/Vox/VoxAutoModeRouterTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 832 | php | `tests/Unit/Ai/SelfConstruction/TaskFabric/AtlasTaskFabricRoadmapGapMinerTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 821 | php | `tests/Unit/Ai/Programming/ProgrammingEnterpriseRuntimeTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 819 | php | `tests/Unit/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainMuscleReadinessContractTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 818 | php | `tests/Unit/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainTaskGraphRoiSchedulerTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 806 | php | `tests/Unit/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainBacklogCostModelTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `tests/Feature/Ai` · LOC **395,942** · files **1599** · php **1598**

| Métrica | Valor |
|---|---:|
| LOC | 395,942 |
| Files | 1599 |
| ≥2000 | 10 |
| 800–1999 | 30 |
| PHP peels <80 | 281 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 31,813 | php | `tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |
| 9,419 | php | `tests/Feature/Ai/Holding/AutonomousHoldingEnterpriseCommandTest.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |
| 4,005 | php | `tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php` | MANDATORY_SPLIT → <2000 |
| 3,377 | php | `tests/Feature/Ai/AtlasOpenBrainContextPackServiceTest.php` | MANDATORY_SPLIT → <2000 |
| 3,080 | php | `tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php` | MANDATORY_SPLIT → <2000 |
| 2,694 | php | `tests/Feature/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceRuntimeServiceTest.php` | MANDATORY_SPLIT → <2000 |
| 2,460 | php | `tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneChainIntegrityAuditTest.php` | MANDATORY_SPLIT → <2000 |
| 2,365 | php | `tests/Feature/Ai/AtlasAiVoiceRealtimeApiTest.php` | MANDATORY_SPLIT → <2000 |
| 2,292 | php | `tests/Feature/Ai/AtlasAiVoiceRealtimeCommandTest.php` | MANDATORY_SPLIT → <2000 |
| 2,073 | php | `tests/Feature/Ai/EngineeringKernel/EliteExecutorKernelReadOnlyVerticalTest.php` | MANDATORY_SPLIT → <2000 |
| 1,922 | php | `tests/Feature/Ai/Programming/AtlasDev/RepairToGreenTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,893 | php | `tests/Feature/Ai/AiWorkerProviderChoiceTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,741 | php | `tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,632 | php | `tests/Feature/Ai/Publishing/AtlasBlogEditorialPlanCommandTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,575 | php | `tests/Feature/Ai/Programming/Frontend/AtlasFrontendWorkspaceApiTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,508 | php | `tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOperatorEvidenceSubmissionReadinessTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,343 | php | `tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,320 | php | `tests/Feature/Ai/Vox/AtlasAiVoxControllerTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,299 | php | `tests/Feature/Ai/ControlPlane/AtlasAiControlPlaneServiceTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,275 | php | `tests/Feature/Ai/Programming/AtlasDev/BestOfNHermesTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,246 | php | `tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionCompletionEvidenceCertificationTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,234 | php | `tests/Feature/Ai/AtlasAiLocalRagBenchmarkCommandTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,131 | php | `tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOsCompletionAuditTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,080 | php | `tests/Feature/Ai/Programming/AtlasDev/Cli/AtlasCliDevEfficientCommandTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,080 | php | `tests/Feature/Ai/Finance/PolymarketExec/PolyExecShadowSimTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,041 | php | `tests/Feature/Ai/AtlasAobgWorkspaceOnboardingServiceTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,029 | php | `tests/Feature/Ai/Programming/AtlasDev/SeniorCriticTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,010 | php | `tests/Feature/Ai/Programming/AtlasDev/AtlasDevDesktopEfficiencyEvidenceCommandTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 976 | php | `tests/Feature/Ai/Finance/StrategyLoop/AtlasFinanceStrategySearchCampaignCommandTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 935 | php | `tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTaskAutoReplenishmentTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 916 | php | `tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionFinalCompletionReadinessGateTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 902 | php | `tests/Feature/Ai/InboxLedgerProjectionActionTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 869 | php | `tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTaskQueueOrchestratorTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 845 | php | `tests/Feature/Ai/AiObservabilityKernelSloTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 838 | php | `tests/Feature/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleServiceTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 815 | php | `tests/Feature/Ai/Rivals/EngineeringNativeUnitScriptTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 813 | php | `tests/Feature/Ai/EngineeringKernel/CanonicalCommitActuationTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 812 | php | `tests/Feature/Ai/AutonomousEvolution/Brain/AtlasBrainStateCommandTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 802 | php | `tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTerminalWorkerBootstrapTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 801 | php | `tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionRuntimePromotionEndgameTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `tests/Feature/Loop` · LOC **41,041** · files **270** · php **270**

| Métrica | Valor |
|---|---:|
| LOC | 41,041 |
| Files | 270 |
| ≥2000 | 0 |
| 800–1999 | 2 |
| PHP peels <80 | 38 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 983 | php | `tests/Feature/Loop/AtlasLoopCampaignSupervisorTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 878 | php | `tests/Feature/Loop/AtlasLoopAutoMergeServiceTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `tests/Feature/Console` · LOC **22,969** · files **67** · php **67**

| Métrica | Valor |
|---|---:|
| LOC | 22,969 |
| Files | 67 |
| ≥2000 | 1 |
| 800–1999 | 0 |
| PHP peels <80 | 13 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 12,678 | php | `tests/Feature/Console/AtlasAaeosCommandTest.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |

---

### `tests/Unit/Services` · LOC **21,757** · files **217** · php **217**

| Métrica | Valor |
|---|---:|
| LOC | 21,757 |
| Files | 217 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 86 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=86; garantir CODEMAP se houver API pública._

---

### `tests/Feature/Engineering` · LOC **14,827** · files **45** · php **45**

| Métrica | Valor |
|---|---:|
| LOC | 14,827 |
| Files | 45 |
| ≥2000 | 0 |
| 800–1999 | 2 |
| PHP peels <80 | 2 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,172 | php | `tests/Feature/Engineering/AtlasCodeRealityUsageIntelligenceServiceTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |
| 1,039 | php | `tests/Feature/Engineering/AtlasDocumentationRealityCommitAutoHealTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `tests/Fixtures` · LOC **6,408** · files **524** · php **22**

| Métrica | Valor |
|---|---:|
| LOC | 6,408 |
| Files | 524 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 22 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=22; garantir CODEMAP se houver API pública._

---

### `tests/Unit/CodeGraph` · LOC **6,329** · files **28** · php **27**

| Métrica | Valor |
|---|---:|
| LOC | 6,329 |
| Files | 28 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Concerns` · LOC **5,750** · files **61** · php **61**

| Métrica | Valor |
|---|---:|
| LOC | 5,750 |
| Files | 61 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 27 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=27; garantir CODEMAP se houver API pública._

---

### `tests/Unit/Engineering` · LOC **5,727** · files **33** · php **33**

| Métrica | Valor |
|---|---:|
| LOC | 5,727 |
| Files | 33 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 2 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=2; garantir CODEMAP se houver API pública._

---

### `tests/Feature/EngineeringHarnessRunnerTest.php` · LOC **5,641** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 5,641 |
| Files | 1 |
| ≥2000 | 1 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 5,641 | php | `tests/Feature/EngineeringHarnessRunnerTest.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |

---

### `tests/Feature/CodeGraph` · LOC **5,046** · files **23** · php **22**

| Métrica | Valor |
|---|---:|
| LOC | 5,046 |
| Files | 23 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 2 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=2; garantir CODEMAP se houver API pública._

---

### `tests/Feature/MobileGatewayTest.php` · LOC **4,774** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 4,774 |
| Files | 1 |
| ≥2000 | 1 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 4,774 | php | `tests/Feature/MobileGatewayTest.php` | MANDATORY_SPLIT → <2000 |

---

### `tests/Feature/AtlasMemoryRegistryTest.php` · LOC **3,896** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 3,896 |
| Files | 1 |
| ≥2000 | 1 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 3,896 | php | `tests/Feature/AtlasMemoryRegistryTest.php` | MANDATORY_SPLIT → <2000 |

---

### `tests/Feature/CaptureTranscriptionRetryTest.php` · LOC **3,420** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 3,420 |
| Files | 1 |
| ≥2000 | 1 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 3,420 | php | `tests/Feature/CaptureTranscriptionRetryTest.php` | MANDATORY_SPLIT → <2000 |

---

### `tests/Feature/Foundry` · LOC **3,159** · files **11** · php **11**

| Métrica | Valor |
|---|---:|
| LOC | 3,159 |
| Files | 11 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/PlanExecution` · LOC **2,864** · files **9** · php **9**

| Métrica | Valor |
|---|---:|
| LOC | 2,864 |
| Files | 9 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/Architecture` · LOC **2,644** · files **10** · php **10**

| Métrica | Valor |
|---|---:|
| LOC | 2,644 |
| Files | 10 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Feature/ProgrammingGovernance` · LOC **2,586** · files **13** · php **13**

| Métrica | Valor |
|---|---:|
| LOC | 2,586 |
| Files | 13 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasCode` · LOC **2,507** · files **11** · php **11**

| Métrica | Valor |
|---|---:|
| LOC | 2,507 |
| Files | 11 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/Reality` · LOC **2,429** · files **6** · php **6**

| Métrica | Valor |
|---|---:|
| LOC | 2,429 |
| Files | 6 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasCode` · LOC **2,309** · files **17** · php **17**

| Métrica | Valor |
|---|---:|
| LOC | 2,309 |
| Files | 17 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 7 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=7; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasToolRuntimeCoreTest.php` · LOC **2,237** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 2,237 |
| Files | 1 |
| ≥2000 | 1 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 2,237 | php | `tests/Feature/AtlasToolRuntimeCoreTest.php` | MANDATORY_SPLIT → <2000 |

---

### `tests/Feature/AtlasCodeContractTest.php` · LOC **1,906** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 1,906 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,906 | php | `tests/Feature/AtlasCodeContractTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `tests/Unit/AiCliProviderRuntimeArgsTest.php` · LOC **1,777** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 1,777 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,777 | php | `tests/Unit/AiCliProviderRuntimeArgsTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `tests/Feature/Engine` · LOC **1,657** · files **6** · php **6**

| Métrica | Valor |
|---|---:|
| LOC | 1,657 |
| Files | 6 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasVaultCommandTest.php` · LOC **1,300** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 1,300 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,300 | php | `tests/Feature/AtlasVaultCommandTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `tests/Feature/Sdd` · LOC **1,245** · files **9** · php **9**

| Métrica | Valor |
|---|---:|
| LOC | 1,245 |
| Files | 9 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AiTelemetryMetricsTest.php` · LOC **1,150** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 1,150 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,150 | php | `tests/Feature/AiTelemetryMetricsTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `tests/Feature/AtlasEngineeringKnowledgeBaseTest.php` · LOC **1,136** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 1,136 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,136 | php | `tests/Feature/AtlasEngineeringKnowledgeBaseTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `tests/Feature/AiTelemetryRouterAndDiagnosticsTest.php` · LOC **1,010** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 1,010 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,010 | php | `tests/Feature/AiTelemetryRouterAndDiagnosticsTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `tests/Unit/EngineeringBenchmarkFairClaudeScorecardTest.php` · LOC **948** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 948 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 948 | php | `tests/Unit/EngineeringBenchmarkFairClaudeScorecardTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `tests/Unit/AiJobControlTest.php` · LOC **923** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 923 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 923 | php | `tests/Unit/AiJobControlTest.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `tests/Unit/Foundry` · LOC **774** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 774 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasCliDevCommandTest.php` · LOC **752** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 752 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AiSessionManagerTest.php` · LOC **747** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 747 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/EngineeringDocumentationHealthServiceTest.php` · LOC **732** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 732 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AgentGovernance` · LOC **662** · files **5** · php **5**

| Métrica | Valor |
|---|---:|
| LOC | 662 |
| Files | 5 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 2 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=2; garantir CODEMAP se houver API pública._

---

### `tests/Unit/YouTubeKnowledgeIngestionServiceTest.php` · LOC **619** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 619 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasCartographyContractTest.php` · LOC **601** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 601 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AiAtlasDecideContractTest.php` · LOC **596** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 596 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/Sdd` · LOC **571** · files **3** · php **3**

| Métrica | Valor |
|---|---:|
| LOC | 571 |
| Files | 3 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasCodeInteractiveObservedProviderTest.php` · LOC **559** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 559 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AiChatCommandPermissionTest.php` · LOC **556** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 556 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasCodeWorkspaceProfileTest.php` · LOC **534** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 534 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/MacAgentServiceTest.php` · LOC **533** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 533 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasCliDevWorkflowServiceTest.php` · LOC **508** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 508 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasVaultApiTest.php` · LOC **475** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 475 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AiTelemetryToolDiagnosticsTest.php` · LOC **462** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 462 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/TemporalTruth` · LOC **452** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 452 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasEngineeringQualityScanCommandTest.php` · LOC **447** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 447 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/ProjectPlanningProposalTest.php` · LOC **418** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 418 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/Scheduler` · LOC **412** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 412 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/ProjectBlockerFlowTest.php` · LOC **386** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 386 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AiTelemetryPerformanceReportTest.php` · LOC **379** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 379 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AiTelemetryCostConfidenceRenameTest.php` · LOC **357** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 357 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/EngineeringRunArtifactServiceTest.php` · LOC **347** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 347 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasEngineeringVisualSmokeCommandTest.php` · LOC **342** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 342 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AiTelemetryScorecardCostSegregationTest.php` · LOC **331** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 331 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/EngineeringProjectBlueprintPipelineTest.php` · LOC **318** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 318 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AiTelemetryScorecardToolMetricsTest.php` · LOC **310** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 310 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasEngineeringApiContractTest.php` · LOC **308** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 308 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasCliBootstrapCommandTest.php` · LOC **307** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 307 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AiTelemetryPerformanceReportToolsTest.php` · LOC **301** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 301 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AiToolRuntimeTest.php` · LOC **295** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 295 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AiQualityActionServiceTest.php` · LOC **282** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 282 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/EngineeringTaskApiTest.php` · LOC **280** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 280 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/CaptureDeletionApiTest.php` · LOC **278** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 278 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AiAttachmentContentTest.php` · LOC **273** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 273 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AiTelemetryTest.php` · LOC **263** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 263 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasImageAttachmentServiceTest.php` · LOC **252** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 252 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/Marketing` · LOC **251** · files **4** · php **4**

| Métrica | Valor |
|---|---:|
| LOC | 251 |
| Files | 4 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 3 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=3; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AiConversationContextBuilderTest.php` · LOC **250** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 250 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/Models` · LOC **249** · files **5** · php **5**

| Métrica | Valor |
|---|---:|
| LOC | 249 |
| Files | 5 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 4 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=4; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AiHarnessContractsTest.php` · LOC **242** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 242 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasCliSessionServiceTest.php` · LOC **239** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 239 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/Http` · LOC **231** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 231 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/FairClaudePolicyTest.php` · LOC **227** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 227 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/EngineeringDocumentationAuthorityAuditServiceTest.php` · LOC **215** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 215 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasCliContinueCommandTest.php` · LOC **215** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 215 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasAiPolicyApiTest.php` · LOC **215** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 215 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AiMetricDailySnapshotRefreshTest.php` · LOC **212** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 212 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AiStreamRecorderTest.php` · LOC **211** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 211 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/atlas_generated_0.php` · LOC **205** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 205 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/Semantic` · LOC **202** · files **3** · php **3**

| Métrica | Valor |
|---|---:|
| LOC | 202 |
| Files | 3 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 3 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=3; garantir CODEMAP se houver API pública._

---

### `tests/Unit/YoutubeCanonicalProjectionTest.php` · LOC **197** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 197 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasFileAttachmentServiceTest.php` · LOC **197** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 197 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AiQualityEvaluatorTerminalStatusTest.php` · LOC **197** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 197 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AiQualityEvaluatorTest.php` · LOC **192** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 192 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AiQualityBackfillCommandTest.php` · LOC **190** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 190 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasVaultFrontmatterServiceTest.php` · LOC **184** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 184 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasCliProviderStrategyServiceTest.php` · LOC **183** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 183 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasFinalResponseSanitizerTest.php` · LOC **182** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 182 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/ProgrammingGovernance` · LOC **181** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 181 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AiChatCommandPasteImageTest.php` · LOC **178** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 178 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasCliScheduleCommandTest.php` · LOC **175** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 175 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AiPerformanceSmokeCommandTest.php` · LOC **175** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 175 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/EngineeringDockerHarnessServiceTest.php` · LOC **173** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 173 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/ScheduleParserTest.php` · LOC **166** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 166 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasCliSchedulerServiceTest.php` · LOC **163** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 163 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/IntentPermissionResolverTest.php` · LOC **155** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 155 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/Integration` · LOC **155** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 155 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasCodeReceiptSignTest.php` · LOC **151** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 151 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/Loop` · LOC **149** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 149 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/SelfConstruction` · LOC **142** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 142 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/TerminalMarkdownRendererTest.php` · LOC **141** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 141 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasCliQualityServiceTest.php` · LOC **140** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 140 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AiToolRuntimeEventKeyTest.php` · LOC **127** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 127 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/Patamar4` · LOC **126** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 126 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasCliStartServiceTest.php` · LOC **125** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 125 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AiToolPermissionEngineTest.php` · LOC **120** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 120 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AiIntentRouterTest.php` · LOC **120** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 120 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasDomainRegistryTest.php` · LOC **120** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 120 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/Memory` · LOC **119** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 119 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasCliDogfoodCommandTest.php` · LOC **119** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 119 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasEngineeringVisualDriverCommandTest.php` · LOC **118** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 118 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasVaultManagedNoteServiceTest.php` · LOC **112** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 112 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AiSessionStateServicePendingSteerTest.php` · LOC **110** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 110 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasMemoryGovernanceNearDuplicateScanTest.php` · LOC **110** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 110 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasVaultLinkServiceTest.php` · LOC **109** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 109 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/TaskPlanningServiceEventReceiptTest.php` · LOC **107** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 107 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/DevProgressReporterTest.php` · LOC **106** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 106 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasVaultFileStoreTest.php` · LOC **106** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 106 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AiPermissionEngineTest.php` · LOC **106** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 106 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/HealthMetricIntegrityTest.php` · LOC **105** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 105 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/Docs` · LOC **104** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 104 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 2 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=2; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AgentGovernance` · LOC **103** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 103 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 2 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=2; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasCliSetupServiceTest.php` · LOC **100** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 100 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/Jobs` · LOC **98** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 98 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/TestCase.php` · LOC **94** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 94 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasCliInstallCommandTest.php` · LOC **93** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 93 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasSecurityTest.php` · LOC **89** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 89 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AiChunkedUploadTest.php` · LOC **87** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 87 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Feature/Cognition` · LOC **85** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 85 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/hooks` · LOC **81** · files **2** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 81 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `tests/Unit/EngineeringProviderRuntimeServiceTest.php` · LOC **79** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 79 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Feature/RizeApiSyncTest.php` · LOC **79** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 79 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasCliCompletionCommandTest.php` · LOC **77** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 77 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/EngineeringTaskContractServiceTest.php` · LOC **76** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 76 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/YouTubeRichInputPayloadExtractionTest.php` · LOC **75** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 75 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasToolEvidenceExportReceiptTest.php` · LOC **75** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 75 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasCliStartCommandTest.php` · LOC **73** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 73 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Feature/Api` · LOC **73** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 73 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/DigitalActivityQualityTest.php` · LOC **72** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 72 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/WorkspaceProfilerTest.php` · LOC **69** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 69 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AiAttachmentPayloadTest.php` · LOC **69** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 69 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasCliFixCommandTest.php` · LOC **69** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 69 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/Console` · LOC **68** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 68 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 2 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=2; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasCliCheckpointServiceTest.php` · LOC **68** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 68 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasCliDashboardCommandTest.php` · LOC **68** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 68 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AiTraceMetricSummaryFkTest.php` · LOC **64** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 64 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasReplHistoryTest.php` · LOC **63** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 63 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Support` · LOC **62** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 62 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasCliHelpCommandTest.php` · LOC **62** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 62 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Feature/RizeWebhookTest.php` · LOC **61** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 61 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasCliSetupCommandTest.php` · LOC **59** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 59 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/WhisperTranscriberTest.php` · LOC **58** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 58 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasCliInterruptCommandTest.php` · LOC **57** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 57 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/Config` · LOC **54** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 54 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasCliDashboardServiceTest.php` · LOC **54** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 54 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/EngineeringBlueprintServiceTest.php` · LOC **52** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 52 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasCliDoctorCommandTest.php` · LOC **52** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 52 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/CheckinRecordedAtValidationTest.php` · LOC **49** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 49 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasCliFinalCommandTest.php` · LOC **48** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 48 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/Support` · LOC **47** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 47 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasCliReleaseCommandTest.php` · LOC **45** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 45 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/CanonicalBehaviorCatalogTest.php` · LOC **43** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 43 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasTerminalThemeTest.php` · LOC **40** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 40 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasCliLauncherTest.php` · LOC **40** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 40 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/TestDatabaseIsolationGuardTest.php` · LOC **37** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 37 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/BitaculaServiceTest.php` · LOC **35** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 35 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/EngineeringTestMatrixInputTest.php` · LOC **32** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 32 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/EngineeringDockerHarnessInputTest.php` · LOC **30** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 30 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasPhpBinaryTest.php` · LOC **29** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 29 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/FrontmatterParserTest.php` · LOC **28** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 28 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/BehaviorCategoriesTest.php` · LOC **27** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 27 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/EngineeringBenchmarkInputTest.php` · LOC **26** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 26 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Feature/AtlasEngineeringDockerCleanupCommandTest.php` · LOC **26** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 26 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasTestCommandResolverTest.php` · LOC **25** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 25 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/EngineeringClaudeCodeBaselineInputTest.php` · LOC **23** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 23 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Feature/HealthTest.php` · LOC **23** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 23 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/EngineeringContextIntelligenceInputTest.php` · LOC **21** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 21 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/EngineeringHarnessabilityInputTest.php` · LOC **20** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 20 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/EngineeringHarnessRunnerInputTest.php` · LOC **20** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 20 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/AtlasVaultCommandInputTest.php` · LOC **20** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 20 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Feature/BitaculaNormalizeTest.php` · LOC **20** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 20 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

### `tests/Unit/BehaviorLifecycleTest.php` · LOC **18** · files **1** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 18 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=1; garantir CODEMAP se houver API pública._

---

## WAVE D

### `docs/engineering-knowledge-base` · LOC **349,401** · files **1065** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 349,401 |
| Files | 1065 |
| ≥2000 | 5 |
| 800–1999 | 7 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 5,604 | md | `docs/engineering-knowledge-base/archive/source-material/evolution/atlas-ai-evolution-roadmap-full-2026-05-08.md` | DOC_SPLIT índice/corpo · archive se morto |
| 4,023 | md | `docs/engineering-knowledge-base/archive/source-material/atlas-ai-memory-context-core-open-brain-full-2026-05-08.md` | DOC_SPLIT índice/corpo · archive se morto |
| 3,743 | md | `docs/engineering-knowledge-base/archive/source-material/kernel/atlas-ai-kernel-architecture-full-2026-05-08.md` | DOC_SPLIT índice/corpo · archive se morto |
| 3,496 | patch | `docs/engineering-knowledge-base/_recovery/stashes/stash-4-WIP_on_main__3e275e58f8_atlas-task_codex-terminal-24h-cockpit-job-result-actions.patch` | DATA/LEGACY · quarantine ou dono explícito |
| 3,204 | md | `docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md` | DOC_SPLIT índice/corpo · archive se morto |
| 1,753 | md | `docs/engineering-knowledge-base/archive/source-material/master-architecture/atlas-ai-master-architecture-full-2026-05-08.md` | DOC_SPLIT índice/corpo · archive se morto |
| 1,475 | patch | `docs/engineering-knowledge-base/_recovery/stashes/stash-2-On_main__acos-max-lote3-agent-preexisting-wip.patch` | DATA/LEGACY · quarantine ou dono explícito |
| 1,073 | md | `docs/engineering-knowledge-base/memory/diagrams/atlas-self-learning-note.md` | INDEX_OR_QUARANTINE |
| 1,028 | md | `docs/engineering-knowledge-base/archive/source-material/atlas-forge-rivals-intelligence-ledger-v1-full-2026-06-09.md` | INDEX_OR_QUARANTINE |
| 892 | md | `docs/engineering-knowledge-base/START_HERE.md` | INDEX_OR_QUARANTINE |
| 869 | md | `docs/engineering-knowledge-base/atlas-acos-excellence-10-10-plan-v1.md` | INDEX_OR_QUARANTINE |
| 803 | md | `docs/engineering-knowledge-base/archive/2026-05-14-atlas-self-construction-os-handoff.md` | INDEX_OR_QUARANTINE |

---

### `docs/ap` · LOC **26,920** · files **236** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 26,920 |
| Files | 236 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/superpowers` · LOC **20,776** · files **20** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 20,776 |
| Files | 20 |
| ≥2000 | 1 |
| 800–1999 | 3 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 12,416 | md | `docs/superpowers/plans/2026-07-22-atlas-server-god-debulk-FILESYSTEM-100.md` | DOC_SPLIT índice/corpo · archive se morto |
| 1,430 | md | `docs/superpowers/plans/2026-05-03-mcp-tools-expansion.md` | DOC_SPLIT índice/corpo · archive se morto |
| 993 | md | `docs/superpowers/plans/2026-07-09-atlas-elite-engineering-factory-v2.md` | INDEX_OR_QUARANTINE |
| 901 | md | `docs/superpowers/plans/2026-05-04-atlas-dev-paste-image.md` | INDEX_OR_QUARANTINE |

---

### `docs/goals` · LOC **3,887** · files **14** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 3,887 |
| Files | 14 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 996 | css | `docs/goals/fable-campanha-obra-atual/.goalbuddy-board/styles.css` | ASSET docs · manter fora do hot path IA ou documentar |

---

### `docs/affiliate-mastery` · LOC **3,603** · files **11** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 3,603 |
| Files | 11 |
| ≥2000 | 0 |
| 800–1999 | 3 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 968 | md | `docs/affiliate-mastery/sistema-conversao-1-para-25.md` | INDEX_OR_QUARANTINE |
| 927 | md | `docs/affiliate-mastery/marketing-campaigns-max-performance-skills.md` | INDEX_OR_QUARANTINE |
| 801 | md | `docs/affiliate-mastery/affiliate-google-ads-mastery.md` | INDEX_OR_QUARANTINE |

---

### `docs/work-orders` · LOC **2,471** · files **34** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 2,471 |
| Files | 34 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/contracts` · LOC **2,130** · files **10** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 2,130 |
| Files | 10 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-cli-5x-claude-code-plan.md` · LOC **2,075** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 2,075 |
| Files | 1 |
| ≥2000 | 1 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 2,075 | md | `docs/atlas-cli-5x-claude-code-plan.md` | DOC_SPLIT índice/corpo · archive se morto |

---

### `docs/rivals-warroom.md` · LOC **1,874** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 1,874 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,874 | md | `docs/rivals-warroom.md` | DOC_SPLIT índice/corpo · archive se morto |

---

### `docs/autonomos-evolution-journal` · LOC **1,410** · files **2** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 1,410 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-cli-fair-claude-benchmark.md` · LOC **1,273** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 1,273 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,273 | md | `docs/atlas-cli-fair-claude-benchmark.md` | DOC_SPLIT índice/corpo · archive se morto |

---

### `docs/atlas-vault-cartografia.md` · LOC **1,021** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 1,021 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,021 | md | `docs/atlas-vault-cartografia.md` | INDEX_OR_QUARANTINE |

---

### `docs/archive` · LOC **699** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 699 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-cli-5x-codex-implementation-prompt.md` · LOC **569** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 569 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-outro-patamar-roadmap.md` · LOC **549** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 549 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/rivals-handoff-fable-20260720.md` · LOC **497** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 497 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-os-architecture.md` · LOC **460** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 460 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-cli-5x-codex-safety-context-prompt.md` · LOC **417** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 417 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-handoff.md` · LOC **398** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 398 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-os-architecture.draft.md` · LOC **385** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 385 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-task-serving-runbook.md` · LOC **340** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 340 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-architecture-evolution-loop.md` · LOC **330** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 330 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-brain-architecture.md` · LOC **297** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 297 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/acde-loop-master-handoff.md` · LOC **279** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 279 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-cli-final-product.md` · LOC **274** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 274 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-canonical-definition.md` · LOC **234** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 234 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-ai-telemetry.md` · LOC **224** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 224 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/rich-input` · LOC **223** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 223 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/arbor-loop-improvement-report.md` · LOC **216** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 216 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/obra8-refatoracao-pesada-spec-2026-07-06.md` · LOC **204** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 204 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-handoff-context.md` · LOC **202** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 202 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-company-success-engine-buildout.md` · LOC **200** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 200 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-engineering-os-deep-analysis-2026-07-05.md` · LOC **199** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 199 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/obra-linha-acos-plano-de-execucao-2026-07-06.md` · LOC **193** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 193 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-exponential-evolution-ladder.md` · LOC **192** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 192 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/ventures` · LOC **189** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 189 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/fable-campanha-11-dias-nxm.md` · LOC **184** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 184 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/conversion-os-state.md` · LOC **176** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 176 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/obra4-self-hardening-harness-spec-2026-07-05.md` · LOC **172** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 172 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/fable-lista-4-14-itens.md` · LOC **160** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 160 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/fable-lista-6-14-itens.md` · LOC **154** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 154 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/brain-architecture-v2.md` · LOC **154** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 154 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/paste-image-setup.md` · LOC **148** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 148 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/acde-exponential-architecture-levers.md` · LOC **147** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 147 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/fable-lista-5-14-itens.md` · LOC **144** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 144 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-soak-run-profile.md` · LOC **141** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 141 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/search-keyword-os-state.md` · LOC **138** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 138 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/acde-teto-closure.md` · LOC **133** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 133 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/obra6-consolidacao-estrutural-2026-07-05.md` · LOC **128** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 128 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/fable-lista-3-14-itens.md` · LOC **128** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 128 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/fable-listas-4-5-6-execution-prompt.md` · LOC **123** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 123 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-autonomous-company-architecture-v2.md` · LOC **121** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 121 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-ai-performance-engine-ops.md` · LOC **120** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 120 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-os-architecture.r2-findings.md` · LOC **117** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 117 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-8-phase-cycle-canonical.md` · LOC **115** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 115 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-mac-agent.md` · LOC **114** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 114 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/fable-campanha-execution-prompt.md` · LOC **111** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 111 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/obra7-religar-reduzir-2026-07-05.md` · LOC **108** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 108 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-supera-arbor-roadmap.md` · LOC **108** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 108 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-soak-runbook.md` · LOC **107** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 107 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-cli-release-checklist.md` · LOC **107** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 107 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/obra5-certifier-sovereignty-spec-2026-07-05.md` · LOC **102** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 102 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/obra20-acos-organismo-soberano-2026-07-06.md` · LOC **102** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 102 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/rivals-goal-mission.md` · LOC **99** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 99 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/brain-self-improvement-method-catalog.md` · LOC **98** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 98 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/acde-minimax-ceiling-buildout.md` · LOC **96** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 96 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/acde-9s-batch-shipped.md` · LOC **95** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 95 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/obra17-acos-3x-plano-mestre-2026-07-06.md` · LOC **94** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 94 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/brain-circulation-coupling-spec.md` · LOC **93** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 93 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/obra12-refatoracao-pilha-cognitiva-spec-2026-07-06.md` · LOC **92** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 92 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/agent-governance-control-plane.md` · LOC **92** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 92 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-self-modification-safety-canonical.md` · LOC **91** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 91 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/fable-campanha-goalbuddy-prep-request.md` · LOC **91** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 91 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-ai-performance-reports.md` · LOC **91** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 91 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/canonical-docs-truth-campaign-2026-07-05.md` · LOC **86** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 86 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/fable-lista-2-14-itens.md` · LOC **85** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 85 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/limpeza-bruta-nucleo-campaign-2026-07-05.md` · LOC **83** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 83 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-leverage-producer-build.md` · LOC **82** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 82 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/acde-beat-ultracode-worklist.md` · LOC **82** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 82 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-brain-provider-portability.md` · LOC **81** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 81 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/obra14-acos-evolucao-estruturada-2026-07-06.md` · LOC **79** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 79 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-ai-aggregator-versions.md` · LOC **79** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 79 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-night-backlog.md` · LOC **78** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 78 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/brain-meta-improvement-engine-spec.md` · LOC **78** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 78 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-autonomous-learning-catalog.md` · LOC **77** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 77 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-part1-backlog-manifest.md` · LOC **76** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 76 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-brain-harness-build-spec.md` · LOC **76** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 76 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-proxy-pattern-catalog.md` · LOC **75** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 75 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-self-evolution-architecture.md` · LOC **73** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 73 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-product-audience-10x-design.md` · LOC **72** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 72 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/obra-linha-acos-carta-de-autonomia-2026-07-06.md` · LOC **71** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 71 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/rivals-warroom-codex-goal.md` · LOC **70** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 70 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-unified-receipts.md` · LOC **70** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 70 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-os-architecture.r3-findings.md` · LOC **70** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 70 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/brain-augmented-worker-prompt.md` · LOC **70** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 70 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-self-learning-map.md` · LOC **70** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 70 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-grade-report.md` · LOC **69** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 69 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/fable-campanha-handoff-packet.md` · LOC **67** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 67 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/obra18-acos-materia-prima-canos-kit-2026-07-06.md` · LOC **66** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 66 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/obra15-acos-consciencia-de-engenharia-2026-07-06.md` · LOC **63** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 63 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/obra19-modelo-5x-motor-de-entrega-2026-07-06.md` · LOC **62** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 62 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-recovery-replay.md` · LOC **60** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 60 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-v2-sufficiency-review.md` · LOC **59** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 59 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-soak-evaluation-report.md` · LOC **56** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 56 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-model-check-state-machine.md` · LOC **56** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 56 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-brain-state.md` · LOC **56** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 56 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/obra16-acos-cognicao-ativa-2026-07-06.md` · LOC **54** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 54 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/atlas-phase0-keystones-build-brief.md` · LOC **54** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 54 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/obra-linha-acos-contexto-mestre-2026-07-07.md` · LOC **52** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 52 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-audit-trail.md` · LOC **52** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 52 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-wire-format.md` · LOC **47** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 47 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-permission-gradient.md` · LOC **47** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 47 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/trinity-anti-decoupling-receipt-ledger.md` · LOC **45** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 45 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/cortex-universal-config.md` · LOC **44** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 44 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/acde-next-levers-deferred.md` · LOC **44** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 44 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/conversion-os-audit-cycle44.md` · LOC **42** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 42 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-task-class-discovery.md` · LOC **41** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 41 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/brain-research-source-registry.md` · LOC **41** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 41 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/cortex-universal-facts-schema.md` · LOC **40** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 40 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/conversion-os-audit-cycle45.md` · LOC **40** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 40 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/obra-linha-acos-leia-me-implementador.md` · LOC **39** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 39 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/maestro-packet-decay.md` · LOC **38** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 38 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/cortex-adoption-receipt-ledger.md` · LOC **37** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 37 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-model-check-cli.md` · LOC **36** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 36 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/obra-linha-acos-fechamento-2026-07-07.md` · LOC **35** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 35 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/affiliate-loop-state.md` · LOC **34** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 34 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-model-check-deadlock.md` · LOC **33** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 33 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-consolidation-certifier.md` · LOC **33** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 33 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-conflict-resolution.md` · LOC **33** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 33 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/trinity-anti-decoupling-contract.md` · LOC **32** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 32 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-pause-resume-observability.md` · LOC **32** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 32 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/cortex-multilang-cli.md` · LOC **31** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 31 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/cortex-universal-contract.md` · LOC **30** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 30 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/conversion-os-audit-cycle43.md` · LOC **30** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 30 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-os-slice0-provenance-receipt.md` · LOC **28** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 28 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/trinity-anti-decoupling-drift-detector.md` · LOC **27** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 27 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/obra17-baselines-ancora-2026-07-07.md` · LOC **26** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 26 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-aael-stepwise-debugger.md` · LOC **26** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 26 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/trinity-anti-decoupling-auditor.md` · LOC **25** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 25 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/cortex-spatial-locality.md` · LOC **25** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 25 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/cortex-portability-proof.md` · LOC **21** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 21 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/cortex-council-v3.md` · LOC **21** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 21 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/GOAL-acde-autonomy-backlog-4000.txt` · LOC **19** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 19 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-soak-merge-log.md` · LOC **12** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 12 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

### `docs/loop-self-architecture-baseline.json` · LOC **8** · files **1** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 8 |
| Files | 1 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

## WAVE I

### `database/` · LOC **29,789** · files **375** · php **372**

| Métrica | Valor |
|---|---:|
| LOC | 29,789 |
| Files | 375 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 250 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=250; garantir CODEMAP se houver API pública._

---

### `public/` · LOC **11,412** · files **9** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 11,412 |
| Files | 9 |
| ≥2000 | 3 |
| 800–1999 | 3 |
| PHP peels <80 | 1 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 2,460 | html | `public/atlas-code-cockpit-mockup-v4.html` | DOC_SPLIT índice/corpo · archive se morto |
| 2,363 | html | `public/atlas-truth-cartography.html` | DOC_SPLIT índice/corpo · archive se morto |
| 2,066 | html | `public/atlas-vault-cockpit-mockup.html` | DOC_SPLIT índice/corpo · archive se morto |
| 1,937 | html | `public/atlas-code-cockpit-mockup-v3.html` | DOC_SPLIT índice/corpo · archive se morto |
| 1,533 | html | `public/atlas-code-cockpit-mockup.html` | DOC_SPLIT índice/corpo · archive se morto |
| 1,006 | html | `public/atlas-code-cockpit-mockup-mvp.html` | INDEX_OR_QUARANTINE |

---

### `scripts/` · LOC **10,043** · files **78** · php **13**

| Métrica | Valor |
|---|---:|
| LOC | 10,043 |
| Files | 78 |
| ≥2000 | 0 |
| 800–1999 | 2 |
| PHP peels <80 | 2 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,655 | other | `scripts/rivals_engineering_driver.py` | CLASSIFY · assign owner |
| 848 | other | `scripts/__pycache__/rivals_engineering_driver.cpython-314.pyc` | CLASSIFY · assign owner |

---

### `config/` · LOC **9,891** · files **30** · php **30**

| Métrica | Valor |
|---|---:|
| LOC | 9,891 |
| Files | 30 |
| ≥2000 | 1 |
| 800–1999 | 0 |
| PHP peels <80 | 11 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 5,367 | php | `config/atlas.php` | EMERGENCY_SPLIT → <2000 → target ≤800 se hot |

---

### `routes/` · LOC **1,632** · files **2** · php **2**

| Métrica | Valor |
|---|---:|
| LOC | 1,632 |
| Files | 2 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 1,037 | php | `routes/api.php` | REVIEW_HOT · se façade/command/http → SPLIT ≤800 |

---

### `bin/` · LOC **1,175** · files **7** · php **0**

| Métrica | Valor |
|---|---:|
| LOC | 1,175 |
| Files | 7 |
| ≥2000 | 0 |
| 800–1999 | 1 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

| LOC | Kind | Path | Ação |
|---:|---|---|---|
| 844 | other | `bin/atlas` | CLASSIFY · assign owner |

---

### `bootstrap/` · LOC **1,070** · files **5** · php **4**

| Métrica | Valor |
|---|---:|
| LOC | 1,070 |
| Files | 5 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 2 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=2; garantir CODEMAP se houver API pública._

---

### `resources/` · LOC **270** · files **4** · php **1**

| Métrica | Valor |
|---|---:|
| LOC | 270 |
| Files | 4 |
| ≥2000 | 0 |
| 800–1999 | 0 |
| PHP peels <80 | 0 |

**Ordem:** AUDIT callers → SPLIT ≥2000 (hot≤800) → OWNERSHIP → FUSE peels → CODEMAP → PROVE tests → LEDGER

**Done:** 0 paths >2000 (php/tests) · hot≤800 · CODEMAP 100% públicos · owner único · testes pacote green

_Sem arquivos ≥800. Manter forma; peels PHP=0; garantir CODEMAP se houver API pública._

---

## COVERAGE_PROOF FINAL

- buckets=478
- files=15260
- loc=3520534
- walk_files=15260
- delta_files=0
- godfile_or_dense_rows_listed includes all ≥800; god≥2000 count listed=83

**PASS: 100% dos paths do corpus estão em exatamente um bucket.**