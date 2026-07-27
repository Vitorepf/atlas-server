# CODEMAP — app/Services/Ai/Cognition

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AcosDeadSeriesWatchdogCheck | `App\Services\Ai\Cognition\Watchdog\Checks\AcosDeadSeriesWatchdogCheck::id` |
| AcosDeltaSeriesJsonl | `App\Services\Ai\Cognition\Acos\AcosDeltaSeriesJsonl::readSeries` |
| AcosMaxLedgerRotationRegistry | `App\Services\Ai\Cognition\AcosProgram\AcosMaxLedgerRotationRegistry::all` |
| AcosMaxLote2MeasureService | `App\Services\Ai\Cognition\AcosProgram\AcosMaxLote2MeasureService::freezePayload` |
| AcosMaxMeasureSeriesRegistry | `App\Services\Ai\Cognition\AcosProgram\AcosMaxMeasureSeriesRegistry::entries` |
| AcosMaxObraRetroService | `App\Services\Ai\Cognition\AcosProgram\AcosMaxObraRetroService::closeLote` |
| AcosMaxParallelExecutionProtocol | `App\Services\Ai\Cognition\AcosProgram\AcosMaxParallelExecutionProtocol::familyClaimTarget` |
| AcosMaxProceduralSkillPromoterService | `App\Services\Ai\Cognition\AcosProgram\AcosMaxProceduralSkillPromoterService::report` |
| AcosMaxVerifiedShareService | `App\Services\Ai\Cognition\AcosProgram\AcosMaxVerifiedShareService::freezePayload` |
| AcosMaxWindowOrchestratorService | `App\Services\Ai\Cognition\AcosProgram\AcosMaxWindowOrchestratorService::report` |
| AcosMeasureSeriesFreshnessReader | `App\Services\Ai\Cognition\AcosProgram\AcosMeasureSeriesFreshnessReader::lastAppendAt` |
| AcosProgramCockpitService | `App\Services\Ai\Cognition\AcosProgram\AcosProgramCockpitService::report` |
| AmbitionRungPolicy | `App\Services\Ai\Cognition\AcosProgram\AmbitionRungPolicy::select` |
| AobgLatencyWatchdogCheck | `App\Services\Ai\Cognition\Watchdog\Checks\AobgLatencyWatchdogCheck::id` |
| AtlasAcosEvolutionScoreService | `App\Services\Ai\Cognition\AtlasAcosEvolutionScoreService::build` |
| AtlasAcosLongHorizonGateService | `App\Services\Ai\Cognition\AtlasAcosLongHorizonGateService::evaluate` |
| AtlasAcosRollbackTriggerCheckService | `App\Services\Ai\Cognition\AtlasAcosRollbackTriggerCheckService::check` |
| AtlasAcosWatchdogHealthService | `App\Services\Ai\Cognition\Watchdog\AtlasAcosWatchdogHealthService::memoryQualityCheck` |
| AtlasAcosWindowGatesService | `App\Services\Ai\Cognition\AtlasAcosWindowGatesService::status` |
| AtlasCognitionEvidenceResolver | `App\Services\Ai\Cognition\AtlasCognitionEvidenceResolver::resolveDocStatus` |
| AtlasCognitionRemintTouchedQueue | `App\Services\Ai\Cognition\AtlasCognitionRemintTouchedQueue::enqueue` |
| AtlasCognitionScoreCardService | `App\Services\Ai\Cognition\AtlasCognitionScoreCardService::build` |
| AtlasCognitionScoreCardV4Grouper | `App\Services\Ai\Cognition\AtlasCognitionScoreCardV4Grouper::group` |
| AtlasCognitiveFunctionAtlasService | `App\Services\Ai\Cognition\AtlasCognitiveFunctionAtlasService::selfModel` |
| AtlasCognitiveFunctionDecomposerService | `App\Services\Ai\Cognition\AtlasCognitiveFunctionDecomposerService::setLogPathForTesting` |
| AtlasCognitiveMemoryFabricSchemaEvolutionService | `App\Services\Ai\Cognition\AtlasCognitiveMemoryFabricSchemaEvolutionService::setProposalsLogPathForTesting` |
| AtlasConsolidationRerankGuard | `App\Services\Ai\Cognition\AtlasConsolidationRerankGuard::freeze` |
| AtlasFlywheelFunnelService | `App\Services\Ai\Cognition\AcosProgram\AtlasFlywheelFunnelService::report` |
| AtlasFrontierWaveLadder | `App\Services\Ai\Cognition\AtlasFrontierWaveLadder::path` |
| AtlasImmuneClassifierHybridFreeze | `App\Services\Ai\Cognition\AtlasImmuneClassifierHybridFreeze::freezePayload` |
| AtlasImmuneHybridInputClassifier | `App\Services\Ai\Cognition\AtlasImmuneHybridInputClassifier::classifyHybrid` |
| AtlasImmuneSignatureFreeze | `App\Services\Ai\Cognition\AtlasImmuneSignatureFreeze::freezePayload` |
| AtlasLocalModelIntegrityService | `App\Services\Ai\Cognition\AcosProgram\AtlasLocalModelIntegrityService::verifyAll` |
| AtlasModelCapabilitySpecService | `App\Services\Ai\Cognition\AcosProgram\AtlasModelCapabilitySpecService::functions` |
| AtlasNCaptureDrillService | `App\Services\Ai\Cognition\AcosProgram\AtlasNCaptureDrillService::freezePayload` |
| AtlasOperationalVolumeCheckService | `App\Services\Ai\Cognition\AtlasOperationalVolumeCheckService::check` |
| AtlasResourceBudgetService | `App\Services\Ai\Cognition\AcosProgram\AtlasResourceBudgetService::report` |
| AtlasSurpriseGateService | `App\Services\Ai\Cognition\AtlasSurpriseGateService::evaluate` |
| AtlasWatchdogCheckRegistry | `App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckRegistry::register` |
| AtlasWatchdogCheckResult | `App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult::ok` |
| AtlasWatchdogRunner | `App\Services\Ai\Cognition\Watchdog\AtlasWatchdogRunner::run` |
| AttemptLifecycleLedger | `App\Services\Ai\Cognition\AcosProgram\AttemptLifecycleLedger::start` |
| AutonomyLadderAdversarialWatchdogCheck | `App\Services\Ai\Cognition\Watchdog\Checks\AutonomyLadderAdversarialWatchdogCheck::id` |
| BeliefCascadeReverificationPlanner | `App\Services\Ai\Cognition\AcosProgram\BeliefCascadeReverificationPlanner::plan` |
| BigramJaccardImmuneSemanticSimilarityPort | `App\Services\Ai\Cognition\BigramJaccardImmuneSemanticSimilarityPort::similarity` |
| CaptureHmacLineageService | `App\Services\Ai\Cognition\CaptureHmacLineageService::stampStage` |
| CognitiveContextNudgeApplier | `App\Services\Ai\Cognition\CognitiveContextNudgeApplier::applyNudges` |
| CognitiveImmuneCheckContract | `App\Services\Ai\Cognition\CognitiveImmuneCheckContract::defaults` |
| CognitiveImmunePromotionGateEvaluator | `App\Services\Ai\Cognition\CognitiveImmunePromotionGateEvaluator::evaluate` |
| CompactionRecoverySampleWatchdogCheck | `App\Services\Ai\Cognition\Watchdog\Checks\CompactionRecoverySampleWatchdogCheck::id` |
| ComposedObraArcComposer | `App\Services\Ai\Cognition\AcosProgram\ComposedObraArcComposer::compose` |
| ComposedObraArcLifecycle | `App\Services\Ai\Cognition\AcosProgram\ComposedObraArcLifecycle::reset` |
| DailyCanaryReplayByRefsWatchdogCheck | `App\Services\Ai\Cognition\Watchdog\Checks\DailyCanaryReplayByRefsWatchdogCheck::id` |
| DiskFreeWatchdogCheck | `App\Services\Ai\Cognition\Watchdog\Checks\DiskFreeWatchdogCheck::id` |
| DogfoodingFrictionLeadMiner | `App\Services\Ai\Cognition\AcosProgram\DogfoodingFrictionLeadMiner::mine` |
| Esp09IndependentChallengerService | `App\Services\Ai\Cognition\AcosProgram\Esp09IndependentChallengerService::evaluate` |
| EvidenceLedgerIntegrityWatchdogCheck | `App\Services\Ai\Cognition\Watchdog\Checks\EvidenceLedgerIntegrityWatchdogCheck::id` |
| EvidenceVisionThesisComposer | `App\Services\Ai\Cognition\AcosProgram\EvidenceVisionThesisComposer::compose` |
| EvidenceVisionThesisLifecycle | `App\Services\Ai\Cognition\AcosProgram\EvidenceVisionThesisLifecycle::reset` |
| ExecutionContextCooccurrenceService | `App\Services\Ai\Cognition\AcosProgram\ExecutionContextCooccurrenceService::report` |
| ExploratoryBetsPortfolio | `App\Services\Ai\Cognition\AcosProgram\ExploratoryBetsPortfolio::evaluate` |
| FactPairPolarityContradictionDetector | `App\Services\Ai\Cognition\FactPairPolarityContradictionDetector::detect` |
| HealthReportWatchdogCheck | `App\Services\Ai\Cognition\Watchdog\Checks\HealthReportWatchdogCheck::id` |
| ImmuneCalibrationService | `App\Services\Ai\Cognition\ImmuneCalibrationService::report` |
| ImmuneSignatureDeriver | `App\Services\Ai\Cognition\ImmuneSignatureDeriver::derive` |
| ImmuneSignatureIngestor | `App\Services\Ai\Cognition\ImmuneSignatureIngestor::maybeIngestFromVerdict` |
| ImmuneSignatureStore | `App\Services\Ai\Cognition\ImmuneSignatureStore::recordFromIncident` |
| ImmuneVerdictLedger | `App\Services\Ai\Cognition\ImmuneVerdictLedger::recordVerdict` |
| JointResourceBudgetWatchdogCheck | `App\Services\Ai\Cognition\Watchdog\Checks\JointResourceBudgetWatchdogCheck::id` |
| LocalModelIntegrityWatchdogCheck | `App\Services\Ai\Cognition\Watchdog\Checks\LocalModelIntegrityWatchdogCheck::id` |
| NumericRangeOverlapContradictionDetector | `App\Services\Ai\Cognition\NumericRangeOverlapContradictionDetector::detect` |
| OperatorLearningCaptureSchemaWatchdogCheck | `App\Services\Ai\Cognition\Watchdog\Checks\OperatorLearningCaptureSchemaWatchdogCheck::id` |
| OperatorReviewDebtWatchdogCheck | `App\Services\Ai\Cognition\Watchdog\Checks\OperatorReviewDebtWatchdogCheck::id` |
| PortfolioBudgetAllocator | `App\Services\Ai\Cognition\AcosProgram\PortfolioBudgetAllocator::derive` |
| PreReviewAdvisoryBand | `App\Services\Ai\Cognition\AcosProgram\PreReviewAdvisoryBand::judge` |
| PredictedImpactBand | `App\Services\Ai\Cognition\AcosProgram\PredictedImpactBand::classify` |
| PromotionProtocol | `App\Services\Ai\Cognition\AcosProgram\PromotionProtocol::entries` |
| ProviderBoundRedactionDriftWatchdogCheck | `App\Services\Ai\Cognition\Watchdog\Checks\ProviderBoundRedactionDriftWatchdogCheck::id` |
| ReactiveSaturationSignal | `App\Services\Ai\Cognition\AcosProgram\ReactiveSaturationSignal::classify` |
| StructuredFactSchemaMap | `App\Services\Ai\Cognition\AcosProgram\StructuredFactSchemaMap::validate` |
| SubstrateRestoreDrillWatchdogCheck | `App\Services\Ai\Cognition\Watchdog\Checks\SubstrateRestoreDrillWatchdogCheck::id` |
| TemporalSupersessionClassifier | `App\Services\Ai\Cognition\TemporalSupersessionClassifier::classify` |
| Teto10PredictedRevertReviewDigest | `App\Services\Ai\Cognition\AcosProgram\Teto10PredictedRevertReviewDigest::compose` |

Façades: 83.
