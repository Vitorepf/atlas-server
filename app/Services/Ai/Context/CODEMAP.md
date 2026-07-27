# CODEMAP — app/Services/Ai/Context

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AiContextPackBuilder | `App\Services\Ai\Context\AiContextPackBuilder::build` |
| AiContextSnapshotRecorder | `App\Services\Ai\Context\AiContextSnapshotRecorder::record` |
| AiConversationContextBuilder | `App\Services\Ai\Context\AiConversationContextBuilder::build` |
| AiConversationRecorder | `App\Services\Ai\Context\AiConversationRecorder::recordUserMessage` |
| AobgSemanticRetrievalLiftService | `App\Services\Ai\Context\AobgSemanticRetrievalLiftService::report` |
| AsefChunkIndexService | `App\Services\Ai\Context\Retrieval\AsefChunkIndexService::contextualizedText` |
| AtlasAgenticRagFrameworkService | `App\Services\Ai\Context\AtlasAgenticRagFrameworkService::plan` |
| AtlasAucriOptimizationAuditService | `App\Services\Ai\Context\AtlasAucriOptimizationAuditService::audit` |
| AtlasAucriRuntimeEnforcementService | `App\Services\Ai\Context\AtlasAucriRuntimeEnforcementService::enforce` |
| AtlasAucriTokenQualityCanarySetService | `App\Services\Ai\Context\AtlasAucriTokenQualityCanarySetService::report` |
| AtlasCanonicalContextRef | `App\Services\Ai\Context\AtlasCanonicalContextRef::fromCodeItem` |
| AtlasCodeSymbolEmbeddingCoverageService | `App\Services\Ai\Context\Retrieval\AtlasCodeSymbolEmbeddingCoverageService::freezePayload` |
| AtlasCognitiveMemoryFabricService | `App\Services\Ai\Context\AtlasCognitiveMemoryFabricService::plan` |
| AtlasContextCacheCompilerRuntimeService | `App\Services\Ai\Context\AtlasContextCacheCompilerRuntimeService::warm` |
| AtlasContextCompilerRuntimeService | `App\Services\Ai\Context\AtlasContextCompilerRuntimeService::compile` |
| AtlasContextFeedbackSignalPolicy | `App\Services\Ai\Context\AtlasContextFeedbackSignalPolicy::isMeasuredAggregateEligible` |
| AtlasContextFreshnessQualityGateService | `App\Services\Ai\Context\AtlasContextFreshnessQualityGateService::evaluate` |
| AtlasContextIdRemapService | `App\Services\Ai\Context\AtlasContextIdRemapService::isEnabled` |
| AtlasContextObservabilityPlaneService | `App\Services\Ai\Context\AtlasContextObservabilityPlaneService::snapshot` |
| AtlasContextParetoFrontierRuntimeService | `App\Services\Ai\Context\AtlasContextParetoFrontierRuntimeService::report` |
| AtlasContextQualityCertificationService | `App\Services\Ai\Context\AtlasContextQualityCertificationService::certify` |
| AtlasContextRankingSystemService | `App\Services\Ai\Context\AtlasContextRankingSystemService::rank` |
| AtlasContextRuntime | `App\Services\Ai\Context\AtlasContextRuntime::compose` |
| AtlasDeliveredPackLedger | `App\Services\Ai\Context\AtlasDeliveredPackLedger::fromConfig` |
| AtlasDialecticTensionService | `App\Services\Ai\Context\AtlasDialecticTensionService::openTensions` |
| AtlasFusionInjectionApplier | `App\Services\Ai\Context\AtlasFusionInjectionApplier::apply` |
| AtlasGraphRetrievalNetworkService | `App\Services\Ai\Context\AtlasGraphRetrievalNetworkService::retrieve` |
| AtlasHybridRetrievalInfrastructureService | `App\Services\Ai\Context\AtlasHybridRetrievalInfrastructureService::report` |
| AtlasIntelligenceRolloutMode | `App\Services\Ai\Context\AtlasIntelligenceRolloutMode::resolve` |
| AtlasKnowledgeIngestionFabricService | `App\Services\Ai\Context\AtlasKnowledgeIngestionFabricService::normalize` |
| AtlasKnowledgeItemEmbeddingCoverageService | `App\Services\Ai\Context\Retrieval\AtlasKnowledgeItemEmbeddingCoverageService::freezePayload` |
| AtlasPythonDataRetrievalRuntimeService | `App\Services\Ai\Context\AtlasPythonDataRetrievalRuntimeService::run` |
| AtlasRetrievalCostLatencyGovernorService | `App\Services\Ai\Context\AtlasRetrievalCostLatencyGovernorService::govern` |
| AtlasRetrievalEvaluationBenchmarkArenaService | `App\Services\Ai\Context\AtlasRetrievalEvaluationBenchmarkArenaService::evaluate` |
| AtlasRetrievalFeedbackLoopService | `App\Services\Ai\Context\AtlasRetrievalFeedbackLoopService::capture` |
| AtlasRetrievalFusionService | `App\Services\Ai\Context\AtlasRetrievalFusionService::fuse` |
| AtlasRetrievalPrivacyTrustLayerService | `App\Services\Ai\Context\AtlasRetrievalPrivacyTrustLayerService::evaluate` |
| AtlasSemanticEmbeddingFoundationService | `App\Services\Ai\Context\AtlasSemanticEmbeddingFoundationService::candidateSet` |
| AtlasSpuriousMemoryNoisePurgeService | `App\Services\Ai\Context\AtlasSpuriousMemoryNoisePurgeService::purge` |
| AtlasTokenEconomyRuntimeService | `App\Services\Ai\Context\AtlasTokenEconomyRuntimeService::optimize` |
| AtlasUnifiedRealityGraphService | `App\Services\Ai\Context\AtlasUnifiedRealityGraphService::snapshot` |
| CitationGroundingMeter | `App\Services\Ai\Context\Retrieval\CitationGroundingMeter::measure` |
| ContextPackMemoryInput | `App\Services\Ai\Context\ContextPackMemoryInput::memoryRegistryLimit` |
| ContextPackSelfReflectionGate | `App\Services\Ai\Context\ContextPackSelfReflectionGate::assess` |
| ContextRetrievalRouter | `App\Services\Ai\Context\ContextRetrievalRouter::plan` |
| ConversationContextInput | `App\Services\Ai\Context\ConversationContextInput::recentTurnLimit` |
| DomainLexicalNormalizer | `App\Services\Ai\Context\Retrieval\DomainLexicalNormalizer::tokens` |
| EpistemicEvidenceBundleComposer | `App\Services\Ai\Context\EpistemicEvidenceBundleComposer::compose` |
| GatedCorpusCandidateMiner | `App\Services\Ai\Context\Retrieval\GatedCorpusCandidateMiner::mine` |
| GoldenCounterfactualReplayService | `App\Services\Ai\Context\Retrieval\GoldenCounterfactualReplayService::report` |
| LocalRagBenchmarkService | `App\Services\Ai\Context\LocalRagBenchmarkService::report` |
| LocalRagPrecisionCorpusService | `App\Services\Ai\Context\LocalRagPrecisionCorpusService::report` |
| LocalRagReadinessService | `App\Services\Ai\Context\LocalRagReadinessService::report` |
| Maxa04JinaV3DualReadLedger | `App\Services\Ai\Context\Retrieval\Maxa04JinaV3DualReadLedger::append` |
| Maxa04JinaV3DualReadService | `App\Services\Ai\Context\Retrieval\Maxa04JinaV3DualReadService::plan` |
| PackSufficiencyBlockBuilder | `App\Services\Ai\Context\PackSufficiencyBlockBuilder::build` |
| ProvenanceWeightCalculator | `App\Services\Ai\Context\Retrieval\ProvenanceWeightCalculator::calculate` |
| RagxChainMechanismService | `App\Services\Ai\Context\Retrieval\RagxChainMechanismService::stageReport` |
| RecallGapAggregator | `App\Services\Ai\Context\Retrieval\RecallGapAggregator::aggregate` |
| RetrievalAgendaComposer | `App\Services\Ai\Context\RetrievalAgendaComposer::compose` |
| RetrievalRankInput | `App\Services\Ai\Context\RetrievalRankInput::sessionTopN` |
| SemanticContextInput | `App\Services\Ai\Context\SemanticContextInput::contextNoteLimit` |
| SemanticContextRetrievalService | `App\Services\Ai\Context\SemanticContextRetrievalService::rank` |
| SpanLevelRetrievalResolver | `App\Services\Ai\Context\SpanLevelRetrievalResolver::resolve` |
| SufficiencyCalibrationService | `App\Services\Ai\Context\SufficiencyCalibrationService::report` |
| TaskFacetExtractor | `App\Services\Ai\Context\TaskFacetExtractor::extract` |

Façades: 66.
