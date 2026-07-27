# CODEMAP — app/Services/Ai/Kernel

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AgentBehaviorContract | `App\Services\Ai\Kernel\Provider\AgentBehaviorContract::principles` |
| AgentBehaviorQualityGate | `App\Services\Ai\Kernel\Behavior\AgentBehaviorQualityGate::evaluate` |
| AtlasAiArchitectureValidationService | `App\Services\Ai\Kernel\Architecture\AtlasAiArchitectureValidationService::payload` |
| AtlasAiDomainCatalogService | `App\Services\Ai\Kernel\Domain\AtlasAiDomainCatalogService::inspect` |
| AtlasArchitectureOperationsCatalog | `App\Services\Ai\Kernel\Architecture\AtlasArchitectureOperationsCatalog::sectionKey` |
| AtlasArchitectureReadinessService | `App\Services\Ai\Kernel\Architecture\AtlasArchitectureReadinessService::snapshot` |
| AtlasDocumentationSplitPlanService | `App\Services\Ai\Kernel\Architecture\AtlasDocumentationSplitPlanService::plan` |
| AtlasDomainOrchestrator | `App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator::orchestratorId` |
| AtlasEvidenceLedger | `App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger::record` |
| AtlasExternalGraphHarnessService | `App\Services\Ai\Kernel\Architecture\AtlasExternalGraphHarnessService::contract` |
| AtlasFeaturePlacementService | `App\Services\Ai\Kernel\Architecture\AtlasFeaturePlacementService::place` |
| AtlasGovernanceGateService | `App\Services\Ai\Kernel\Architecture\AtlasGovernanceGateService::strictBlocked` |
| AtlasLedgerReplayService | `App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService::eventsForEnvelope` |
| AtlasProceduralPlaybookApplier | `App\Services\Ai\Kernel\Procedural\AtlasProceduralPlaybookApplier::apply` |
| AtlasProceduralPlaybookDevBridge | `App\Services\Ai\Kernel\Procedural\AtlasProceduralPlaybookDevBridge::enabled` |
| AtlasProceduralPlaybookLedger | `App\Services\Ai\Kernel\Procedural\AtlasProceduralPlaybookLedger::path` |
| AtlasProviderIdentityProjector | `App\Services\Ai\Kernel\Provider\AtlasProviderIdentityProjector::forProvider` |
| AtlasProviderReleaseIntelligenceService | `App\Services\Ai\Kernel\Architecture\AtlasProviderReleaseIntelligenceService::review` |
| AtlasProviderReleaseSourceRegistry | `App\Services\Ai\Kernel\Architecture\AtlasProviderReleaseSourceRegistry::summary` |
| AtlasQualitativeLevelsReadModel | `App\Services\Ai\Kernel\Architecture\AtlasQualitativeLevelsReadModel::report` |
| AtlasRepairOrchestrator | `App\Services\Ai\Kernel\Repair\AtlasRepairOrchestrator::plan` |
| AtlasRepairPlaybookLedger | `App\Services\Ai\Kernel\Repair\AtlasRepairPlaybookLedger::path` |
| AtlasRuntimeLanguageBoundaryReportService | `App\Services\Ai\Kernel\Architecture\AtlasRuntimeLanguageBoundaryReportService::report` |
| AtlasSessionBootstrapService | `App\Services\Ai\Kernel\Architecture\AtlasSessionBootstrapService::bootstrap` |
| AtlasStructureMotherAuditReadModel | `App\Services\Ai\Kernel\Architecture\AtlasStructureMotherAuditReadModel::report` |
| ComputeEffortPolicy | `App\Services\Ai\Kernel\Decision\ComputeEffortPolicy::contract` |
| DecisionReceipt | `App\Services\Ai\Kernel\Decision\DecisionReceipt::isExpired` |
| DecisionReceiptHash | `App\Services\Ai\Kernel\Decision\DecisionReceiptHash::hash` |
| DecisionReceiptIssuer | `App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer::issue` |
| DecisionReceiptRuntimeGuard | `App\Services\Ai\Kernel\Decision\DecisionReceiptRuntimeGuard::receiptForJob` |
| DreyfusReceiptExtensionContract | `App\Services\Ai\Kernel\Decision\DreyfusReceiptExtensionContract::cognitiveDecision` |
| DynamicComputeMarketAdvisor | `App\Services\Ai\Kernel\Decision\DynamicComputeMarketAdvisor::advise` |
| DynamicComputeMarketReportService | `App\Services\Ai\Kernel\Decision\DynamicComputeMarketReportService::report` |
| EffectiveProfile | `App\Services\Ai\Kernel\Envelope\EffectiveProfile::fromArray` |
| FailureClassification | `App\Services\Ai\Kernel\Failure\FailureClassification::toArray` |
| FailureClassifier | `App\Services\Ai\Kernel\Failure\FailureClassifier::classify` |
| IdentityFragment | `App\Services\Ai\Kernel\Provider\IdentityFragment::fromText` |
| IntentClassification | `App\Services\Ai\Kernel\Envelope\IntentClassification::fromArray` |
| KernelArchitectureStaticScanner | `App\Services\Ai\Kernel\Architecture\KernelArchitectureStaticScanner::complianceReport` |
| KernelInput | `App\Services\Ai\Kernel\Envelope\KernelInput::fromArray` |
| KernelLedgerEnvelopeInput | `App\Services\Ai\Kernel\Evidence\KernelLedgerEnvelopeInput::eventLimit` |
| KernelLedgerEnvelopeReportService | `App\Services\Ai\Kernel\Evidence\KernelLedgerEnvelopeReportService::report` |
| KernelPipelineAuditService | `App\Services\Ai\Kernel\Pipeline\KernelPipelineAuditService::recordScaffoldExecution` |
| KernelPipelineDevPlanBuilder | `App\Services\Ai\Kernel\Pipeline\KernelPipelineDevPlanBuilder::attachProgrammingPlan` |
| KernelPipelinePlanGuard | `App\Services\Ai\Kernel\Pipeline\KernelPipelinePlanGuard::validate` |
| KernelPipelineRuntimeGuard | `App\Services\Ai\Kernel\Pipeline\KernelPipelineRuntimeGuard::violationForJob` |
| KernelReplayReportInput | `App\Services\Ai\Kernel\Evidence\KernelReplayReportInput::hours` |
| KernelRoutingCoverageReport | `App\Services\Ai\Kernel\Architecture\KernelRoutingCoverageReport::engineeringExecutionCoverageSchema` |
| KernelSloProbe | `App\Services\Ai\Kernel\Slo\KernelSloProbe::observe` |
| KernelSloTargets | `App\Services\Ai\Kernel\Slo\KernelSloTargets::all` |
| LedgerProjectionRegistry | `App\Services\Ai\Kernel\Evidence\LedgerProjectionRegistry::projections` |
| LedgerProjectionWorker | `App\Services\Ai\Kernel\Evidence\LedgerProjectionWorker::project` |
| ModelSelectionContractFactory | `App\Services\Ai\Kernel\Decision\ModelSelectionContractFactory::forCliDev` |
| OpenBrainAudit | `App\Services\Ai\Kernel\Architecture\Scanner\OpenBrainAudit::checks` |
| OpenBrainMcpInput | `App\Services\Ai\Kernel\Mcp\OpenBrainMcpInput::limit` |
| OperationEnvelope | `App\Services\Ai\Kernel\Envelope\OperationEnvelope::hasDecisionReceipt` |
| OperationEnvelopeFactory | `App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory::create` |
| PedagogyMatchesStageGate | `App\Services\Ai\Kernel\Gates\PedagogyMatchesStageGate::evaluate` |
| PersonalWorkedExamplePrivacySafeGate | `App\Services\Ai\Kernel\Gates\PersonalWorkedExamplePrivacySafeGate::evaluate` |
| PersonalWorkedExampleQualityGate | `App\Services\Ai\Kernel\Gates\PersonalWorkedExampleQualityGate::evaluate` |
| PipelineInput | `App\Services\Ai\Kernel\Pipeline\PipelineInput::fromArray` |
| PredictiveFailureCalibrationBandGate | `App\Services\Ai\Kernel\Gates\PredictiveFailureCalibrationBandGate::evaluate` |
| PredictiveFailureSafetyGate | `App\Services\Ai\Kernel\Gates\PredictiveFailureSafetyGate::evaluate` |
| ProceduralPlaybook | `App\Services\Ai\Kernel\Procedural\ProceduralPlaybook::normalizeCategory` |
| ProductiveFailurePhaseCompleteGate | `App\Services\Ai\Kernel\Gates\ProductiveFailurePhaseCompleteGate::evaluate` |
| ProductiveFailureProblemCalibratedGate | `App\Services\Ai\Kernel\Gates\ProductiveFailureProblemCalibratedGate::evaluate` |
| Provenance | `App\Services\Ai\Kernel\Envelope\Provenance::fromArray` |
| ProviderDriver | `App\Services\Ai\Kernel\Provider\ProviderDriver::providerId` |
| ProviderDriverExecutionPlan | `App\Services\Ai\Kernel\Provider\ProviderDriverExecutionPlan::fromPreparedRequest` |
| ProviderDriverExecutionResult | `App\Services\Ai\Kernel\Provider\ProviderDriverExecutionResult::toArray` |
| ProviderPerformanceProjection | `App\Services\Ai\Kernel\Evidence\ProviderPerformanceProjection::reportForWindow` |
| ProviderPreparedRequestValidator | `App\Services\Ai\Kernel\Provider\ProviderPreparedRequestValidator::validate` |
| ProviderRequestHasher | `App\Services\Ai\Kernel\Provider\ProviderRequestHasher::hash` |
| ProviderUsagePayload | `App\Services\Ai\Kernel\Evidence\ProviderUsagePayload::called` |
| RepairDecision | `App\Services\Ai\Kernel\Repair\RepairDecision::allowsRepair` |
| RepairPolicy | `App\Services\Ai\Kernel\Repair\RepairPolicy::fromArray` |
| RepairRequest | `App\Services\Ai\Kernel\Repair\RepairRequest::fromArray` |
| RepairRequestFactory | `App\Services\Ai\Kernel\Repair\RepairRequestFactory::fromKernelContext` |
| RepairStrategy | `App\Services\Ai\Kernel\Repair\RepairStrategy::values` |
| RetrievalAudit | `App\Services\Ai\Kernel\Architecture\Scanner\RetrievalAudit::checks` |
| ScaffoldAtlasKernelPipeline | `App\Services\Ai\Kernel\Pipeline\ScaffoldAtlasKernelPipeline::stages` |
| SloAudit | `App\Services\Ai\Kernel\Architecture\Scanner\SloAudit::checks` |
| SurfaceAdapter | `App\Services\Ai\Kernel\Surface\SurfaceAdapter::surfaceId` |
| SurfaceAttachmentKind | `App\Services\Ai\Kernel\Surface\SurfaceAttachmentKind::all` |
| SurfaceCapability | `App\Services\Ai\Kernel\Surface\SurfaceCapability::all` |
| SurfaceDomainFlowHintKey | `App\Services\Ai\Kernel\Surface\SurfaceDomainFlowHintKey::all` |
| SurfaceHintKey | `App\Services\Ai\Kernel\Surface\SurfaceHintKey::all` |
| WorkedExampleAppropriateForStageGate | `App\Services\Ai\Kernel\Gates\WorkedExampleAppropriateForStageGate::evaluate` |

Façades: 88.
