# CODEMAP — app/Services/Ai/Learning

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AtlasHarnessAutopilot | `App\Services\Ai\Learning\Harness\AtlasHarnessAutopilot::setStatePathForTesting` |
| AtlasHarnessFrozenSuite | `App\Services\Ai\Learning\Harness\AtlasHarnessFrozenSuite::setBaselinePathForTesting` |
| AtlasHarnessInstructionSurface | `App\Services\Ai\Learning\Harness\AtlasHarnessInstructionSurface::setOverridesPathForTesting` |
| AtlasHarnessProposalBridge | `App\Services\Ai\Learning\Harness\AtlasHarnessProposalBridge::propose` |
| AtlasHarnessSurface | `App\Services\Ai\Learning\Harness\AtlasHarnessSurface::setOverridesPathForTesting` |
| AtlasLoopPredictiveOutcomeBridge | `App\Services\Ai\Learning\PredictiveFailure\AtlasLoopPredictiveOutcomeBridge::recordGrind` |
| BayesianFailureTracker | `App\Services\Ai\Learning\Failure\BayesianFailureTracker::compute` |
| CalibrationBandClassifier | `App\Services\Ai\Learning\PredictiveFailure\CalibrationBandClassifier::classify` |
| ClaimQualifierStrengthClassifier | `App\Services\Ai\Learning\ClaimCoherence\ClaimQualifierStrengthClassifier::classify` |
| ClaimSelfCoherenceScorer | `App\Services\Ai\Learning\ClaimCoherence\ClaimSelfCoherenceScorer::score` |
| ContextPackStalenessClassifier | `App\Services\Ai\Learning\Staleness\ContextPackStalenessClassifier::classify` |
| DreyfusOverlayRepository | `App\Services\Ai\Learning\Dreyfus\DreyfusOverlayRepository::find` |
| DreyfusPedagogyPromptBuilder | `App\Services\Ai\Learning\Dreyfus\DreyfusPedagogyPromptBuilder::build` |
| DreyfusPedagogyResolver | `App\Services\Ai\Learning\Dreyfus\DreyfusPedagogyResolver::resolve` |
| FailureAutoFeedHarvester | `App\Services\Ai\Learning\Failure\FailureAutoFeedHarvester::enabled` |
| FailureRecurrenceMetricService | `App\Services\Ai\Learning\Failure\FailureRecurrenceMetricService::compute` |
| FailureRepetitionAlerter | `App\Services\Ai\Learning\Failure\FailureRepetitionAlerter::evaluate` |
| FailureSignatureClassifier | `App\Services\Ai\Learning\Failure\FailureSignatureClassifier::classify` |
| FailureSignatureRepository | `App\Services\Ai\Learning\Failure\FailureSignatureRepository::record` |
| HedgeCertaintyConflictDetector | `App\Services\Ai\Learning\ClaimCoherence\HedgeCertaintyConflictDetector::detect` |
| PersonalWorkedExampleExtractionRepository | `App\Services\Ai\Learning\PersonalWorkedExample\PersonalWorkedExampleExtractionRepository::alreadyProcessed` |
| PersonalWorkedExampleExtractor | `App\Services\Ai\Learning\PersonalWorkedExample\PersonalWorkedExampleExtractor::run` |
| PersonalWorkedExamplePrivacyRedactor | `App\Services\Ai\Learning\PersonalWorkedExample\PersonalWorkedExamplePrivacyRedactor::redact` |
| PredictiveCodeIntelligenceCorrelationGateService | `App\Services\Ai\Learning\PredictiveFailure\PredictiveCodeIntelligenceCorrelationGateService::evaluate` |
| PredictiveFailureFlow | `App\Services\Ai\Learning\PredictiveFailure\PredictiveFailureFlow::insert` |
| ProcessFadingScheduler | `App\Services\Ai\Learning\WorkedExample\ProcessFadingScheduler::schedule` |
| ProcessPatternEvidenceTracker | `App\Services\Ai\Learning\Pattern\ProcessPatternEvidenceTracker::apply` |
| ProcessPatternMatcher | `App\Services\Ai\Learning\Pattern\ProcessPatternMatcher::match` |
| ProcessPatternRepository | `App\Services\Ai\Learning\Pattern\ProcessPatternRepository::findByName` |
| ProcessPatternStructureValidator | `App\Services\Ai\Learning\Pattern\ProcessPatternStructureValidator::validate` |
| ProductiveFailureComparisonEngine | `App\Services\Ai\Learning\ProductiveFailure\ProductiveFailureComparisonEngine::compare` |
| ProductiveFailureFlow | `App\Services\Ai\Learning\ProductiveFailure\ProductiveFailureFlow::start` |
| ProductiveFailureSessionRepository | `App\Services\Ai\Learning\ProductiveFailure\ProductiveFailureSessionRepository::create` |
| SRLEpisodeRepository | `App\Services\Ai\Learning\SRL\SRLEpisodeRepository::start` |
| SRLOrchestrator | `App\Services\Ai\Learning\SRL\SRLOrchestrator::beginIfEnabled` |
| SRLPhaseController | `App\Services\Ai\Learning\SRL\SRLPhaseController::currentPhase` |
| SRLPreferenceService | `App\Services\Ai\Learning\SRL\SRLPreferenceService::set` |
| StalenessActionLadder | `App\Services\Ai\Learning\Staleness\StalenessActionLadder::action` |
| SuiteRedTriage | `App\Services\Ai\Learning\Failure\SuiteRedTriage::triage` |
| WeeklyRedCountSnapshotStore | `App\Services\Ai\Learning\Failure\WeeklyRedCountSnapshotStore::tableReady` |
| WorkedExampleRenderer | `App\Services\Ai\Learning\WorkedExample\WorkedExampleRenderer::render` |
| WorkedExampleRepository | `App\Services\Ai\Learning\WorkedExample\WorkedExampleRepository::find` |
| WorkedExampleSelector | `App\Services\Ai\Learning\WorkedExample\WorkedExampleSelector::select` |

Façades: 43.
