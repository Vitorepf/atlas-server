# CODEMAP — app/Services/Ai/Memory

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AiMemoryDeltaProposer | `App\Services\Ai\Memory\AiMemoryDeltaProposer::proposeForWorkspace` |
| AtlasHybridMemoryRetrievalService | `App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService::recall` |
| AtlasMemoryCandidateGateService | `App\Services\Ai\Memory\AtlasMemoryCandidateGateService::capture` |
| AtlasMemoryCognitiveImmuneLearningKernelService | `App\Services\Ai\Memory\AtlasMemoryCognitiveImmuneLearningKernelService::defaultState` |
| AtlasMemoryConflictResolutionService | `App\Services\Ai\Memory\AtlasMemoryConflictResolutionService::judge` |
| AtlasMemoryContextComposer | `App\Services\Ai\Memory\AtlasMemoryContextComposer::compose` |
| AtlasMemoryDeltaPromotionService | `App\Services\Ai\Memory\AtlasMemoryDeltaPromotionService::promote` |
| AtlasMemoryLearningPromotionService | `App\Services\Ai\Memory\AtlasMemoryLearningPromotionService::run` |
| AtlasMemoryMaintenanceService | `App\Services\Ai\Memory\AtlasMemoryMaintenanceService::run` |
| AtlasMemoryQualityService | `App\Services\Ai\Memory\AtlasMemoryQualityService::scorecard` |
| AtlasMemoryRecallCache | `App\Services\Ai\Memory\AtlasMemoryRecallCache::isEnabled` |
| AtlasMemoryRecallConcentrationDemotion | `App\Services\Ai\Memory\AtlasMemoryRecallConcentrationDemotion::dominantEntryIds` |
| AtlasMemoryRecallConcentrationV2Reader | `App\Services\Ai\Memory\AtlasMemoryRecallConcentrationV2Reader::read` |
| AtlasMemoryRegistryService | `App\Services\Ai\Memory\AtlasMemoryRegistryService::record` |
| AtlasMemoryReviewQueueService | `App\Services\Ai\Memory\AtlasMemoryReviewQueueService::queue` |
| AtlasMemorySemanticIndexer | `App\Services\Ai\Memory\AtlasMemorySemanticIndexer::isEnabled` |
| AtlasMemorySubstrateDumpRunner | `App\Services\Ai\Memory\Substrate\AtlasMemorySubstrateDumpRunner::dump` |
| AtlasMemorySubstrateRestoreDrillRunner | `App\Services\Ai\Memory\Substrate\AtlasMemorySubstrateRestoreDrillRunner::restore` |
| AtlasMemorySubstrateRestoreDrillService | `App\Services\Ai\Memory\Substrate\AtlasMemorySubstrateRestoreDrillService::run` |
| AtlasMemorySubstrateRestoreProofRunner | `App\Services\Ai\Memory\Substrate\AtlasMemorySubstrateRestoreProofRunner::prove` |
| AtlasMemorySubstrateSnapshotService | `App\Services\Ai\Memory\Substrate\AtlasMemorySubstrateSnapshotService::run` |
| AtlasMemoryTemporalDefaultDeriver | `App\Services\Ai\Memory\AtlasMemoryTemporalDefaultDeriver::derive` |
| AtlasMemoryTemporalQualityService | `App\Services\Ai\Memory\AtlasMemoryTemporalQualityService::freezePayload` |
| AtlasMemoryUsageService | `App\Services\Ai\Memory\AtlasMemoryUsageService::recordSnapshotUsages` |
| AtlasMemoryVectorSearchService | `App\Services\Ai\Memory\AtlasMemoryVectorSearchService::available` |
| AtlasRecallUncertaintyMap | `App\Services\Ai\Memory\AtlasRecallUncertaintyMap::forRecall` |
| AtlasVerbatimMemoryService | `App\Services\Ai\Memory\AtlasVerbatimMemoryService::record` |
| LocalAgentMemoryIngestionCanon | `App\Services\Ai\Memory\LocalAgentIngestion\LocalAgentMemoryIngestionCanon::promotionBlockedClasses` |
| LocalAgentMemoryIngestionService | `App\Services\Ai\Memory\LocalAgentIngestion\LocalAgentMemoryIngestionService::run` |
| LocalAgentSecretScanner | `App\Services\Ai\Memory\LocalAgentIngestion\LocalAgentSecretScanner::scanAndRedact` |
| LocalAgentSourceClassifier | `App\Services\Ai\Memory\LocalAgentIngestion\LocalAgentSourceClassifier::classify` |
| LocalAgentSourceDiscoveryService | `App\Services\Ai\Memory\LocalAgentIngestion\LocalAgentSourceDiscoveryService::walk` |
| MemoryConsolidationScanner | `App\Services\Ai\Memory\MemoryConsolidationScanner::scan` |
| MemoryEntrySafetySummary | `App\Services\Ai\Memory\MemoryEntrySafetySummary::forEntry` |
| MemoryPairwiseCosineScorer | `App\Services\Ai\Memory\MemoryPairwiseCosineScorer::scoreEntries` |
| MemoryQueryInput | `App\Services\Ai\Memory\MemoryQueryInput::registryLimit` |
| MemoryRecallInput | `App\Services\Ai\Memory\MemoryRecallInput::recallLimit` |
| PgDumpAtlasMemorySubstrateDumpRunner | `App\Services\Ai\Memory\Substrate\PgDumpAtlasMemorySubstrateDumpRunner::dump` |
| PgsqlAtlasMemorySubstrateRestoreDrillRunner | `App\Services\Ai\Memory\Substrate\PgsqlAtlasMemorySubstrateRestoreDrillRunner::restore` |
| PgsqlAtlasMemorySubstrateRestoreProofRunner | `App\Services\Ai\Memory\Substrate\PgsqlAtlasMemorySubstrateRestoreProofRunner::prove` |
| VectorMemoryPairwiseCosineScorer | `App\Services\Ai\Memory\VectorMemoryPairwiseCosineScorer::scoreEntries` |

Façades: 41.
