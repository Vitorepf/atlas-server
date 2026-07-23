<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Gates;

use App\Services\Ai\AgenticEngineeringOs\Scoring\SpecCompletenessScorer;
use App\Services\Ai\AgenticEngineeringOs\Scoring\SummaryFidelityCoverageScorer;
use App\Services\Ai\AgenticEngineeringOs\Scoring\MemoryInjectionBudgetAllocator;
use App\Services\Ai\AgenticEngineeringOs\Scoring\MemoryFeedbackDecayScorer;
use App\Services\Ai\AgenticEngineeringOs\Scoring\SegmentImportanceRanker;
use App\Services\Ai\AgenticEngineeringOs\Scoring\ContextParetoDominanceFilter;
use App\Services\Ai\AgenticEngineeringOs\Scoring\AtlasMemoryRecallRelevanceScorer;
use App\Services\Ai\AgenticEngineeringOs\Scoring\OutcomeCausalityRanker;
use App\Services\Ai\Cognition\AcosProgram\PredictedImpactBand;
use App\Services\Ai\Cognition\AcosProgram\PreReviewAdvisoryBand;
use App\Services\Ai\Cognition\AcosProgram\Esp09IndependentChallengerService;
use App\Services\Ai\Cognition\AcosProgram\DogfoodingFrictionLeadMiner;
use App\Services\Ai\Cognition\AcosProgram\ReactiveSaturationSignal;
use App\Services\Ai\Cognition\AcosProgram\PortfolioBudgetAllocator;
use App\Services\Ai\Cognition\AcosProgram\AmbitionRungPolicy;
use App\Services\Ai\Context\Retrieval\AsefChunkIndexService;
use App\Services\Ai\Context\Retrieval\DomainLexicalNormalizer;
use App\Services\Ai\Context\Retrieval\GatedCorpusCandidateMiner;
use App\Services\Ai\Cognition\AcosProgram\StructuredFactSchemaMap;
use App\Services\Ai\Context\Retrieval\CitationGroundingMeter;
use App\Services\Ai\Context\Retrieval\ProvenanceWeightCalculator;
use App\Services\Ai\Context\Retrieval\RecallGapAggregator;
use App\Services\Ai\Cognition\AcosProgram\BeliefCascadeReverificationPlanner;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxLedgerRotationRegistry;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxMeasureSeriesRegistry;
use App\Services\Ai\Cognition\AcosProgram\EvidenceVisionThesisComposer;
use App\Services\Ai\Cognition\AcosProgram\ExecutionContextCooccurrenceService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasGateSignalEvaluator;
use App\Services\Ai\AgenticEngineeringOs\AaeosDeferredPhaseDispatcherService;
use App\Services\Ai\Cognition\AtlasSurpriseGateService;
use App\Services\Ai\Cognition\NumericRangeOverlapContradictionDetector;
use App\Services\Ai\Cognition\TemporalSupersessionClassifier;
use App\Services\Ai\Cognition\AtlasFrontierWaveLadder;
use App\Services\Ai\Cognition\AtlasImmuneClassifierHybridFreeze;
use App\Services\Ai\Cognition\AtlasImmuneSignatureFreeze;
use App\Services\Ai\Cognition\AtlasCognitiveFunctionAtlasService;
use App\Services\Ai\Cognition\AtlasCognitiveMemoryFabricSchemaEvolutionService;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogRunner;
use App\Services\Ai\Cognition\Watchdog\Checks\AutonomyLadderAdversarialWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\AcosDeadSeriesWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\AobgLatencyWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\CompactionRecoverySampleWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\DailyCanaryReplayByRefsWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\DiskFreeWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\EvidenceLedgerIntegrityWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\HealthReportWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\JointResourceBudgetWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\LocalModelIntegrityWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\OperatorLearningCaptureSchemaWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\OperatorReviewDebtWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\ProviderBoundRedactionDriftWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\SubstrateRestoreDrillWatchdogCheck;
use App\Services\Ai\Cognition\ImmuneCalibrationService;
use App\Services\Ai\Cognition\CognitiveImmuneCheckContract;
use App\Services\Ai\Cognition\CognitiveImmunePromotionGateEvaluator;
use App\Services\Ai\Cognition\CognitiveContextNudgeApplier;
use App\Services\Ai\Cognition\AtlasConsolidationRerankGuard;
use App\Services\Ai\Cognition\ImmuneSignatureDeriver;
use App\Services\Ai\Cognition\AtlasCognitionEvidenceResolver;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardV4Grouper;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\Cognition\AtlasOperationalVolumeCheckService;
use App\Services\Ai\Cognition\AtlasCognitionRemintTouchedQueue;
use App\Services\Ai\Cognition\CaptureHmacLineageService;
use App\Services\Ai\Cognition\Watchdog\AtlasAcosWatchdogHealthService;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxObraRetroService;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxParallelExecutionProtocol;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxWindowOrchestratorService;
use App\Services\Ai\Cognition\AcosProgram\AcosProgramCockpitService;
use App\Services\Ai\Aemor\Envelope\OutcomeEnvelopeBridge;
use App\Services\Ai\Cognition\AtlasCognitiveFunctionDecomposerService;
use App\Services\Ai\Cognition\AtlasAcosRollbackTriggerCheckService;
use App\Services\Ai\Cognition\AtlasAcosLongHorizonGateService;
use App\Services\Ai\Cognition\AtlasAcosWindowGatesService;
use App\Services\Ai\Cognition\AtlasAcosEvolutionScoreService;
use App\Services\Ai\Cognition\AtlasImmuneHybridInputClassifier;
use App\Services\Ai\Cognition\ImmuneSignatureStore;
use App\Services\Ai\Cognition\ImmuneSignatureIngestor;
use App\Services\Ai\Cognition\ImmuneVerdictLedger;
use App\Services\Ai\Cognition\AcosProgram\PromotionProtocol;
use App\Services\Ai\Cognition\AcosProgram\AtlasFlywheelFunnelService;
use App\Services\Ai\Cognition\AcosProgram\EvidenceVisionThesisLifecycle;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasThresholdLadderNormalizer;
use App\Services\Ai\Context\Retrieval\AtlasKnowledgeItemEmbeddingCoverageService;
use App\Services\Ai\Context\Retrieval\AtlasCodeSymbolEmbeddingCoverageService;
use App\Services\Ai\Cognition\AcosProgram\Teto10PredictedRevertReviewDigest;
use App\Services\Ai\Context\Retrieval\Maxa04JinaV3DualReadLedger;
use App\Services\Ai\Context\Retrieval\Maxa04JinaV3DualReadService;
use App\Services\Ai\Cognition\AcosProgram\AtlasResourceBudgetService;
use App\Services\Ai\Cognition\AcosProgram\AtlasModelCapabilitySpecService;
use App\Services\Ai\Cognition\AcosProgram\AcosMeasureSeriesFreshnessReader;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxVerifiedShareService;
use App\Services\Ai\Context\Retrieval\RagxChainMechanismService;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxProceduralSkillPromoterService;
use App\Services\Ai\Context\Retrieval\GoldenCounterfactualReplayService;
use App\Services\Ai\Cognition\AcosProgram\ComposedObraArcComposer;
use App\Services\Ai\Cognition\AcosProgram\ComposedObraArcLifecycle;
use App\Services\Ai\Cognition\AcosProgram\ExploratoryBetsPortfolio;
use App\Services\Ai\Aemor\Envelope\OutcomeEnvelope;
use App\Services\Ai\Cognition\AcosProgram\AttemptLifecycleLedger;
use App\Services\Ai\Cognition\AcosProgram\AtlasNCaptureDrillService;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxLote2MeasureService;
use App\Services\Ai\Cognition\AcosProgram\AtlasLocalModelIntegrityService;
use App\Services\Ai\Aemor\Envelope\AemorOutcomeEnvelopeAdapter;
use App\Services\Ai\Aemor\Envelope\CompoundingOutcomeEnvelopeAdapter;
use App\Services\Ai\Aemor\Envelope\DevProceduralOutcomeEnvelopeAdapter;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasStringListNormalizer;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasThresholdComparator;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasEvidenceRefNormalizer;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDocMaturityClassifier;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasClaimDefinitionOfDoneValidator;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasArrayFieldReader;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasVetoPropagationResolver;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentRegistryService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasCognitiveImmuneInputClassifier;
use App\Services\Ai\Telemetry\AiOutcomeAttributionService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasPhaseRouterService;
use App\Services\Ai\SelfConstruction\ControlPlane\AtlasSelfConstructionScopeRiskBudgetGate;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOrganMeshOrchestrator;
use App\Services\Ai\Telemetry\AiTelemetryCollector;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCausalEffectGate;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentQualityBarService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentMaturityService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasVetoPropagationWatchdog;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasCrossDepartmentChoreographyService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasRepairLoopGuard;
use App\Services\Ai\AgenticEngineeringOs\Support\AeosGeneratedContractGate;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentMaturityBandClassifier;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentPromotionEligibilityEvaluator;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDebugRootCauseService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentLevelClassifier;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentQualityBarLevelClassifier;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationTruthService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDocsAuthorityGraphService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationEvidenceResolver;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasCapabilityTestExecutionService;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use App\Services\Ai\Support\AiValueNormalizer;
use RuntimeException;
use App\Services\Ai\Cognition\FactPairPolarityContradictionDetector;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasAeosValueNormalizer;
use App\Services\Ai\Cognition\BigramJaccardImmuneSemanticSimilarityPort;
use App\Services\Ai\Memory\AtlasMemoryCognitiveImmuneLearningKernelService;
use App\Services\Ai\Compounding\AtlasLearningProposalDecisionService;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckRegistry;
use App\Services\Ai\AgenticEngineeringOs\AaeosBlockerSeverity;
use App\Services\Ai\AgenticEngineeringOs\AaeosBlockerSeverityGate;
use App\Services\Ai\AgenticEngineeringOs\AaeosHttpPathEnvelopeFactory;
use App\Services\Ai\AgenticEngineeringOs\AaeosPhaseHandoffService;
use App\Services\Ai\AgenticEngineeringOs\AaeosRequiredGateCoverageChecker;
use App\Services\Ai\AgenticEngineeringOs\ArchitectAgentSpecPackGateContract;
use App\Services\Ai\AgenticEngineeringOs\AtlasAaeosHttpPathFacadeService;
use App\Services\Ai\AgenticEngineeringOs\DeliveryPackCompletenessScorer;
use App\Services\Ai\AgenticEngineeringOs\DepartmentContractRuntime;
use App\Services\Ai\AgenticEngineeringOs\PhaseAdvanceVerdictClassifier;
use App\Services\Ai\AgenticEngineeringOs\QualityBarTelemetryContract;
use App\Services\Ai\AgenticEngineeringOs\RealityCompilerSlice;
use App\Services\Ai\AgenticEngineeringOs\RunbookOrchestrator;

/**
 * GOD-DEBULK FASE C — extracted observe/floors-contract gate family from
 * {@see \App\Services\Ai\AgenticEngineeringOs\AtlasUniversalGatesEvaluator}.
 * Bodies are byte-identical to the pre-split façade; the façade delegates.
 */
final class GateObserveSection01
{
    /**
     * Observe-only projection of a quality-bar telemetry payload into the
     * M5 contract shape. Does not add a universal-gate id (catalogue stays 15).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function qualityBarTelemetryObserve(array $input): array
    {
        return QualityBarTelemetryContract::fromArray($input)->toArray();
    }

    /**
     * Observe-only projection of an architect-agent spec-pack gate payload
     * into the M1 contract shape. Does not add a universal-gate id.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function architectSpecPackObserve(array $input): array
    {
        return ArchitectAgentSpecPackGateContract::fromArray($input)->toArray();
    }

    /**
     * Observe-only projection of a predicted-impact candidate into the
     * MULTN predicted-impact band shape. Does not add a universal-gate id.
     *
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function predictedImpactBandObserve(array $candidate): array
    {
        return PredictedImpactBand::classify($candidate);
    }

    /**
     * Observe-only MULTN predicted-impact band calibration projection.
     * Accepts a list of rows or `{rows:[...]}`. Catalogue stays 15.
     *
     * @param  array<mixed>  $input
     * @return array<string,mixed>
     */
    public function predictedImpactCalibrationObserve(array $input): array
    {
        $rows = array_is_list($input)
            ? $input
            : AiValueNormalizer::arrayOrEmpty($input['rows'] ?? null);

        /** @var list<array<string,mixed>> $rows */
        return PredictedImpactBand::calibration($rows);
    }

    /**
     * Observe-only projection of pre-review advisory features into the
     * MULTN15-08 band shape. Does not add a universal-gate id.
     *
     * @param  array<string,mixed>  $features
     * @return array<string,mixed>
     */
    public function preReviewAdvisoryObserve(array $features): array
    {
        return PreReviewAdvisoryBand::judge($features);
    }

    /**
     * Observe-only projection of a Reality Compiler slice map into the
     * contract shape. Does not add a universal-gate id.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function realityCompilerSliceObserve(array $input): array
    {
        return RealityCompilerSlice::fromArray($input)->toArray();
    }

    /**
     * Observe-only ESP-09 independent challenger advisory projection.
     * Does not add a universal-gate id (catalogue stays 15).
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function esp09ChallengerObserve(array $context): array
    {
        return Esp09IndependentChallengerService::evaluate($context);
    }

    /**
     * Observe-only ESP-09 promotion-gate delay signal.
     * Does not add a universal-gate id (catalogue stays 15).
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function esp09PromotionGateObserve(array $context): array
    {
        return Esp09IndependentChallengerService::promotionGate($context);
    }

    /**
     * Observe-only ESP-09 challenger refutation series death-review projection.
     * Accepts a list of events or `{events:[...], min_windows?:int, min_per_window?:int}`.
     *
     * @param  array<mixed>  $input
     * @return array<string,mixed>
     */
    public function esp09RefutationSeriesObserve(array $input): array
    {
        if (array_is_list($input)) {
            /** @var list<array<string,mixed>> $input */
            return Esp09IndependentChallengerService::refutationSeries($input);
        }

        $events = AiValueNormalizer::arrayOrEmpty($input['events'] ?? null);
        $minWindows = max(1, (int) (AiValueNormalizer::finiteFloatOrNull($input['min_windows'] ?? null) ?? Esp09IndependentChallengerService::DEFAULT_MIN_WINDOWS));
        $minPerWindow = max(1, (int) (AiValueNormalizer::finiteFloatOrNull($input['min_per_window'] ?? null) ?? Esp09IndependentChallengerService::DEFAULT_MIN_PER_WINDOW));

        /** @var list<array<string,mixed>> $events */
        return Esp09IndependentChallengerService::refutationSeries($events, $minWindows, $minPerWindow);
    }

    /**
     * Observe-only MULTN17-08 dogfooding friction lead mine.
     * Accepts a list of events or `{events:[...]}`. Catalogue stays 15.
     *
     * @param  array<mixed>  $input
     * @return array<string,mixed>
     */
    public function dogfoodingFrictionLeadsObserve(array $input): array
    {
        $events = array_is_list($input)
            ? $input
            : AiValueNormalizer::arrayOrEmpty($input['events'] ?? null);

        /** @var list<array<string,mixed>> $events */
        return DogfoodingFrictionLeadMiner::mine($events);
    }

    /**
     * Observe-only MULTN reactive saturation classification.
     * Accepts `{windows:[...], context?:{...}}` or a bare windows list.
     * Does not add a universal-gate id.
     *
     * @param  array<mixed>  $input
     * @return array<string,mixed>
     */
    public function reactiveSaturationObserve(array $input): array
    {
        if (array_is_list($input)) {
            /** @var list<array<string,mixed>> $input */
            return ReactiveSaturationSignal::classify($input);
        }

        $windows = AiValueNormalizer::arrayOrEmpty($input['windows'] ?? null);
        $context = AiValueNormalizer::arrayOrEmpty($input['context'] ?? null);

        /** @var list<array<string,mixed>> $windows */
        return ReactiveSaturationSignal::classify($windows, $context);
    }

    /**
     * Observe-only MULTK-06 portfolio budget allocation.
     * Accepts the PortfolioBudgetAllocator::derive input map.
     * Does not add a universal-gate id (catalogue stays 15).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function portfolioBudgetObserve(array $input): array
    {
        return PortfolioBudgetAllocator::derive($input);
    }

    /**
     * Observe-only MULTN17-01 ambition rung selection.
     * Accepts `{candidates:[...], context?:{...}}` or a bare candidates list.
     * Does not add a universal-gate id (catalogue stays 15).
     *
     * @param  array<mixed>  $input
     * @return array<string,mixed>
     */
    public function ambitionRungObserve(array $input): array
    {
        if (array_is_list($input)) {
            /** @var list<array<string,mixed>> $input */
            return AmbitionRungPolicy::select($input, []);
        }

        $candidates = AiValueNormalizer::arrayOrEmpty($input['candidates'] ?? null);
        $context = AiValueNormalizer::arrayOrEmpty($input['context'] ?? null);

        /** @var list<array<string,mixed>> $candidates */
        return AmbitionRungPolicy::select($candidates, $context);
    }

    /**
     * Observe-only gated corpus candidate mine (ASI-02 admission).
     * Accepts a sources list or `{sources:[...]}`. Catalogue stays 15.
     *
     * @param  array<mixed>  $input
     * @return array<string,mixed>
     */
    public function gatedCorpusCandidatesObserve(array $input): array
    {
        $sources = array_is_list($input)
            ? $input
            : AiValueNormalizer::arrayOrEmpty($input['sources'] ?? null);

        /** @var list<array<string,mixed>> $sources */
        return GatedCorpusCandidateMiner::mine($sources);
    }

    /**
     * Observe-only structured fact schema validation.
     * Accepts `{memory_type|type:string, facts:{...}}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function structuredFactSchemaObserve(array $input): array
    {
        $memoryType = AiValueNormalizer::trimmedStringOrNull(
            $input['memory_type'] ?? $input['type'] ?? null,
        ) ?? '';
        $facts = AiValueNormalizer::arrayOrEmpty($input['facts'] ?? null);

        return StructuredFactSchemaMap::validate($memoryType, $facts);
    }

    /**
     * Observe-only citation grounding meter over response rows.
     * Accepts a responses list or `{responses:[...]}`. Catalogue stays 15.
     *
     * @param  array<mixed>  $input
     * @return array<string,mixed>
     */
    public function citationGroundingObserve(array $input): array
    {
        $responses = array_is_list($input)
            ? $input
            : AiValueNormalizer::arrayOrEmpty($input['responses'] ?? null);

        /** @var list<array<string,mixed>> $responses */
        return CitationGroundingMeter::measure($responses);
    }

    /**
     * Observe-only provenance weight over evidence/verified refs.
     * Accepts `{evidence_refs:[...], verified_refs:[...]}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function provenanceWeightObserve(array $input): array
    {
        $evidenceRefs = AiValueNormalizer::arrayOrEmpty($input['evidence_refs'] ?? null);
        $verifiedRefs = AiValueNormalizer::arrayOrEmpty($input['verified_refs'] ?? null);

        /** @var list<string> $evidenceRefs */
        /** @var list<string> $verifiedRefs */
        return ProvenanceWeightCalculator::calculate($evidenceRefs, $verifiedRefs);
    }

    /**
     * Observe-only recall-gap aggregation over weak-score query events.
     * Accepts a list of events or `{events:[...], min_occurrences?:int}`.
     * Catalogue stays 15.
     *
     * @param  array<mixed>  $input
     * @return array<string,mixed>
     */
    public function recallGapObserve(array $input): array
    {
        if (array_is_list($input)) {
            /** @var list<array<string,mixed>> $input */
            return RecallGapAggregator::aggregate($input);
        }

        $events = AiValueNormalizer::arrayOrEmpty($input['events'] ?? null);
        $minOccurrences = max(1, (int) (AiValueNormalizer::finiteFloatOrNull($input['min_occurrences'] ?? null) ?? RecallGapAggregator::DEFAULT_MIN_OCCURRENCES));

        /** @var list<array<string,mixed>> $events */
        return RecallGapAggregator::aggregate($events, $minOccurrences);
    }

    /**
     * Observe-only belief cascade reverification plan.
     * Accepts `{origin:string, graph:{node:[children...]}, depth_cap?:int}`.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function beliefCascadeObserve(array $input): array
    {
        $origin = AiValueNormalizer::trimmedStringOrNull($input['origin'] ?? null) ?? '';
        $graph = AiValueNormalizer::arrayOrEmpty($input['graph'] ?? null);
        $depthCap = max(0, (int) (AiValueNormalizer::finiteFloatOrNull($input['depth_cap'] ?? null) ?? BeliefCascadeReverificationPlanner::DEFAULT_DEPTH_CAP));

        /** @var array<string,list<string>> $graph */
        return BeliefCascadeReverificationPlanner::plan($origin, $graph, $depthCap);
    }

    /**
     * Observe-only AAEOS gate-signal spec-pack acceptance criteria.
     * Accepts a spec-pack object with `acceptance_criteria`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function gateSignalSpecPackObserve(array $input): array
    {
        return (new AtlasGateSignalEvaluator)->evaluateSpecPackAcceptanceCriteria($input);
    }

    /**
     * Observe-only AAEOS gate-signal intent clarity.
     * Accepts disambiguation feature fields. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function gateSignalIntentObserve(array $input): array
    {
        return (new AtlasGateSignalEvaluator)->evaluateIntentClarity($input);
    }

    /**
     * Observe-only AAEOS gate-signal task-pack atomicity.
     * Accepts a task-pack object with `tasks`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function gateSignalTaskPackObserve(array $input): array
    {
        return (new AtlasGateSignalEvaluator)->evaluateTaskPackAtomicity($input);
    }

    /**
     * Observe-only AAEOS gate-signal phase-gates rollup.
     * Accepts phaseOutputs with intent/spec/tasks slices. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function gateSignalPhaseObserve(array $input): array
    {
        return (new AtlasGateSignalEvaluator)->evaluatePhaseGates($input);
    }

    /**
     * Observe-only KB embedding coverage ruler (MAXA-06 fase 1).
     * Accepts optional empty object; runs the read-only report. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function kbEmbeddingCoverageObserve(array $input = []): array
    {
        return (new AtlasKnowledgeItemEmbeddingCoverageService)->report();
    }

    /**
     * Observe-only code-symbol embedding coverage ruler (MAXA-06 fase 2).
     * Accepts optional empty object; runs the read-only report. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function codeSymbolEmbeddingCoverageObserve(array $input = []): array
    {
        return (new AtlasCodeSymbolEmbeddingCoverageService)->report();
    }

    /**
     * Observe-only TETO-10 predicted-revert review digest.
     * Accepts an items list or `{items:[...], limit?:int}`. Catalogue stays 15.
     *
     * @param  array<mixed>  $input
     * @return array<string,mixed>
     */
    public function predictedRevertDigestObserve(array $input): array
    {
        if (array_is_list($input)) {
            /** @var list<array<string,mixed>> $input */
            return Teto10PredictedRevertReviewDigest::compose($input);
        }

        $items = AiValueNormalizer::arrayOrEmpty($input['items'] ?? null);
        $limit = max(1, (int) (AiValueNormalizer::finiteFloatOrNull($input['limit'] ?? null) ?? Teto10PredictedRevertReviewDigest::DEFAULT_LIMIT));

        /** @var list<array<string,mixed>> $items */
        return Teto10PredictedRevertReviewDigest::compose($items, $limit);
    }

    /**
     * Observe-only ELEV-27 joint resource budget report.
     * Accepts optional budget override object. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function resourceBudgetObserve(array $input = []): array
    {
        $budget = AiValueNormalizer::arrayOrEmpty($input['budget'] ?? null);
        $service = $budget === []
            ? new AtlasResourceBudgetService
            : new AtlasResourceBudgetService($budget);

        return $service->report();
    }

    /**
     * Observe-only ELEV-29s model capability spec verify.
     * Accepts `{function?:string, model?:object, spec?:object}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function modelCapabilitySpecObserve(array $input = []): array
    {
        $function = AiValueNormalizer::trimmedStringOrNull($input['function'] ?? null) ?? 'dense_embed';
        $model = AiValueNormalizer::arrayOrEmpty($input['model'] ?? null);
        $spec = AiValueNormalizer::arrayOrEmpty($input['spec'] ?? null);
        $service = $spec === []
            ? new AtlasModelCapabilitySpecService
            : new AtlasModelCapabilitySpecService($spec);

        try {
            return $service->verify($function, $model);
        } catch (RuntimeException) {
            return [
                'status' => 'unknown_function',
                'function' => AiValueNormalizer::lowerTrimmedString($function),
                'model_id' => AiValueNormalizer::trimmedStringOrNull($model['model_id'] ?? null) ?? 'unknown',
                'violations' => [[
                    'field' => 'function',
                    'reason' => 'unknown_model_function',
                    'expected' => $service->functions(),
                    'actual' => $function,
                ]],
            ];
        }
    }

    /**
     * Observe-only ELEV-12 verified-share measure report.
     * Accepts optional `{days?:int}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function verifiedShareObserve(array $input = []): array
    {
        $days = array_key_exists('days', $input) ? max(1, (int) (AiValueNormalizer::finiteFloatOrNull($input['days'] ?? null) ?? 0)) : null;

        return (new AcosMaxVerifiedShareService)->report($days);
    }

    /**
     * Observe-only RAGX chain mechanisms stage report.
     * Accepts optional `{deps?:object}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function ragxChainObserve(array $input = []): array
    {
        $deps = [];
        foreach (AiValueNormalizer::arrayOrEmpty($input['deps'] ?? null) as $key => $value) {
            $deps[AiValueNormalizer::trimmedStringOrNull($key) ?? ''] = (bool) $value;
        }

        return (new RagxChainMechanismService)->stageReport($deps);
    }

    /**
     * Observe-only MULTJ-04 procedural skill promoter report.
     * Accepts optional `{floor?:int, enqueue?:bool}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function proceduralSkillPromoterObserve(array $input = []): array
    {
        $floor = array_key_exists('floor', $input) ? max(1, (int) (AiValueNormalizer::finiteFloatOrNull($input['floor'] ?? null) ?? 0)) : null;
        $enqueue = (AiValueNormalizer::boolOrNull($input['enqueue'] ?? null) ?? false);

        return (new AcosMaxProceduralSkillPromoterService)->report($floor, $enqueue);
    }

    /**
     * Observe-only AAEOS HTTP path phase-router snapshot.
     * Accepts optional `{phase?:string}` override. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function aaeosPhaseRouterObserve(array $input = []): array
    {
        $phase = AiValueNormalizer::trimmedStringOrNull($input['phase'] ?? null);

        return (new AtlasPhaseRouterService($phase))->statusSnapshot();
    }

    /**
     * Observe-only AAEOS department quality-bar snapshot.
     * Accepts any JSON object (ignored). Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function aaeosQualityBarObserve(array $input = []): array
    {
        return (new AtlasDepartmentQualityBarService)->qualityBar();
    }

    /**
     * Observe-only AAEOS department maturity matrix snapshot.
     * Accepts any JSON object (ignored). Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function aaeosDepartmentMaturityObserve(array $input = []): array
    {
        return (new AtlasDepartmentMaturityService)->maturity();
    }

    /**
     * Observe-only cross-department veto propagation watchdog replay.
     * Accepts `{events:[...]}` or a bare events list. Catalogue stays 15.
     *
     * @param  array<mixed>  $input
     * @return array<string,mixed>
     */
    public function vetoPropagationWatchdogObserve(array $input = []): array
    {
        $events = array_is_list($input)
            ? $input
            : AiValueNormalizer::arrayOrEmpty($input['events'] ?? null);

        /** @var list<array{department:string, lift?:bool}> $events */
        return (new AtlasVetoPropagationWatchdog(new AtlasCrossDepartmentChoreographyService))->watch($events);
    }

    /**
     * Observe-only AAEOS repair-loop guard decision.
     * Accepts `{current_iteration?:int}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function repairLoopGuardObserve(array $input = []): array
    {
        $currentIteration = max(0, (int) (AiValueNormalizer::finiteFloatOrNull($input['current_iteration'] ?? null) ?? 0));

        return (new AtlasRepairLoopGuard(new AtlasCrossDepartmentChoreographyService))->guard($currentIteration);
    }

    /**
     * Observe-only Aaeos/Generated quarantine gate status.
     * Accepts any JSON object (ignored). Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function generatedContractGateObserve(array $input = []): array
    {
        return (new AeosGeneratedContractGate)->status();
    }

    /**
     * Observe-only AAEOS maturity-band classification.
     * Accepts `{department_band_ladders, department_snapshots}` or
     * `{band_ladder, metrics_snapshot}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function maturityBandClassifierObserve(array $input = []): array
    {
        $ladders = AiValueNormalizer::arrayOrEmpty($input['department_band_ladders'] ?? null);
        $snapshots = AiValueNormalizer::arrayOrEmpty($input['department_snapshots'] ?? null);
        if ($ladders !== []) {
            return (new AtlasDepartmentMaturityBandClassifier)->classifyDepartments($ladders, $snapshots);
        }

        return (new AtlasDepartmentMaturityBandClassifier)->classify(
            AiValueNormalizer::arrayOrEmpty($input['band_ladder'] ?? null),
            AiValueNormalizer::arrayOrEmpty($input['metrics_snapshot'] ?? null),
        );
    }

    /**
     * Observe-only AAEOS department promotion-eligibility verdict.
     * Accepts `{department, metrics, options?}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function promotionEligibilityObserve(array $input = []): array
    {
        return (new AtlasDepartmentPromotionEligibilityEvaluator)->evaluate(
            AiValueNormalizer::arrayOrEmpty($input['department'] ?? null),
            AiValueNormalizer::arrayOrEmpty($input['metrics'] ?? null),
            AiValueNormalizer::arrayOrEmpty($input['options'] ?? null),
        );
    }

    /**
     * Observe-only AAEOS debug root-cause analysis.
     * Accepts any context object (e.g. `{suspected_cause}`). Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function debugRootCauseObserve(array $input = []): array
    {
        return (new AtlasDebugRootCauseService)->analyzeRootCause($input);
    }

    /**
     * Observe-only cross-department choreography decision.
     * Accepts `{mode:veto|repair|handoff,...}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function crossDepartmentChoreographyObserve(array $input = []): array
    {
        $svc = new AtlasCrossDepartmentChoreographyService;
        $mode = AiValueNormalizer::lowerTrimmedString($input['mode'] ?? 'veto');

        return match ($mode) {
            'repair' => $svc->evaluateRepairLoop(
                max(0, (int) (AiValueNormalizer::finiteFloatOrNull($input['iteration'] ?? null) ?? 0)),
                max(0, (int) (AiValueNormalizer::finiteFloatOrNull($input['max_iterations'] ?? null) ?? AtlasCrossDepartmentChoreographyService::REPAIR_MAX_ITERATIONS)),
            ),
            'handoff' => $svc->handoffEnvelope(
                AiValueNormalizer::trimmedStringOrNull($input['from'] ?? null) ?? '',
                AiValueNormalizer::trimmedStringOrNull($input['to'] ?? null) ?? '',
                AiValueNormalizer::trimmedStringOrNull($input['kind'] ?? null) ?? 'delegation',
                AiValueNormalizer::arrayOrEmpty($input['payload'] ?? null),
            ),
            default => $svc->evaluateVeto(
                AiValueNormalizer::trimmedStringOrNull($input['department'] ?? $input['vetoing_department'] ?? null) ?? '',
            ),
        };
    }

    /**
     * Observe-only docs authority locate (fail-open when graph table missing).
     * Accepts `{needle, limit?}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function docsAuthorityLocateObserve(array $input = []): array
    {
        $needle = AiValueNormalizer::trimmedStringOrNull($input['needle'] ?? null) ?? '';
        $limit = max(1, (int) (AiValueNormalizer::finiteFloatOrNull($input['limit'] ?? null) ?? AtlasDocsAuthorityGraphService::DEFAULT_LOCATE_LIMIT));

        return (new AtlasDocsAuthorityGraphService(new CanonicalDocsFrontmatterParser))->locate($needle, $limit);
    }

    /**
     * Observe-only AAEOS department level classification.
     * Accepts `{department_id, metrics_snapshot, band_ladder}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function departmentLevelClassifierObserve(array $input = []): array
    {
        return (new AtlasDepartmentLevelClassifier)->classify(
            AiValueNormalizer::trimmedStringOrNull($input['department_id'] ?? null) ?? '',
            AiValueNormalizer::arrayOrEmpty($input['metrics_snapshot'] ?? null),
            AiValueNormalizer::arrayOrEmpty($input['band_ladder'] ?? null),
        );
    }

    /**
     * Observe-only AAEOS quality-bar level classification.
     * Accepts `{department_id, measured_metrics, band_ladder}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function qualityBarLevelClassifierObserve(array $input = []): array
    {
        return (new AtlasDepartmentQualityBarLevelClassifier)->classify(
            AiValueNormalizer::trimmedStringOrNull($input['department_id'] ?? null) ?? '',
            AiValueNormalizer::arrayOrEmpty($input['measured_metrics'] ?? $input['metrics_snapshot'] ?? null),
            AiValueNormalizer::arrayOrEmpty($input['band_ladder'] ?? null),
        );
    }

    /**
     * Observe-only Implementation Truth evaluate (pure, no I/O).
     * Accepts `{claimed_state, resolutions, green_test_run?}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function implementationTruthEvaluateObserve(array $input = []): array
    {
        $green = $input['green_test_run'] ?? null;
        $greenTestRun = is_bool($green) ? $green : null;

        return app(AtlasImplementationTruthService::class)->evaluate(
            AiValueNormalizer::trimmedStringOrNull($input['claimed_state'] ?? null) ?? 'spec',
            AiValueNormalizer::arrayOrEmpty($input['resolutions'] ?? null),
            $greenTestRun,
        );
    }

    /**
     * Observe-only golden counterfactual replay report (fail-open without runs file).
     * Accepts `{runs_path?, decision_id?}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function goldenCounterfactualReplayObserve(array $input = []): array
    {
        return (new GoldenCounterfactualReplayService)->report(
            AiValueNormalizer::trimmedStringOrNull($input['runs_path'] ?? null),
            AiValueNormalizer::trimmedStringOrNull($input['decision_id'] ?? null),
        );
    }

    /**
     * Observe-only composed obra-arc origination (flag-gated).
     * Accepts `{candidates, cluster_leads?, context?}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function composedObraArcObserve(array $input = []): array
    {
        return ComposedObraArcComposer::compose(
            AiValueNormalizer::arrayOrEmpty($input['candidates'] ?? null),
            AiValueNormalizer::arrayOrEmpty($input['cluster_leads'] ?? null),
            AiValueNormalizer::arrayOrEmpty($input['context'] ?? null),
        );
    }

    /**
     * Observe-only exploratory bets portfolio (flag-gated).
     * Accepts `{candidates|originated_candidates, context?}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function exploratoryBetsPortfolioObserve(array $input = []): array
    {
        return ExploratoryBetsPortfolio::evaluate(
            AiValueNormalizer::arrayOrEmpty($input['candidates'] ?? $input['originated_candidates'] ?? null),
            app(AtlasBrainCausalEffectGate::class),
            AiValueNormalizer::arrayOrEmpty($input['context'] ?? null),
        );
    }

    /**
     * Observe-only N-capture drill report (read-only ledger slice).
     * Accepts `{days?}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function nCaptureDrillObserve(array $input = []): array
    {
        $days = AiValueNormalizer::finiteFloatOrNull($input['days'] ?? null);

        return (new AtlasNCaptureDrillService)->report($days === null ? null : max(1, (int) (AiValueNormalizer::finiteFloatOrNull($days) ?? 0)));
    }

    /**
     * Observe-only MULTJ-03 counterfactual lift measure (fail-open without table).
     * Accepts any JSON object (ignored). Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function lote2CounterfactualLiftObserve(array $input = []): array
    {
        return app(AcosMaxLote2MeasureService::class)->multj03CounterfactualLift();
    }

    /**
     * Observe-only AAEOS DOC L0..L4 maturity classifier.
     * Accepts `{sections}` map (or the sections object itself). Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function docMaturityClassifyObserve(array $input = []): array
    {
        $sections = AiValueNormalizer::arrayOrEmpty($input['sections'] ?? $input);

        return (new AtlasDocMaturityClassifier)->classify($sections);
    }

    /**
     * Observe-only AAEOS claim Definition-of-Done validator.
     * Accepts `{claim}` map (or the claim object itself). Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function claimDefinitionOfDoneObserve(array $input = []): array
    {
        $claim = AiValueNormalizer::arrayOrEmpty($input['claim'] ?? $input);

        return (new AtlasClaimDefinitionOfDoneValidator)->validate($claim);
    }

    /**
     * Observe-only AAEOS veto propagation resolver.
     * Accepts `{origin_department|origin, veto_kind|kind, repair_iteration}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function vetoPropagationResolveObserve(array $input = []): array
    {
        $origin = AiValueNormalizer::trimmedStringOrNull($input['origin_department'] ?? $input['origin'] ?? null) ?? '';
        $kind = AiValueNormalizer::trimmedStringOrNull($input['veto_kind'] ?? $input['kind'] ?? null) ?? '';
        $iteration = max(0, (int) (AiValueNormalizer::finiteFloatOrNull($input['repair_iteration'] ?? null) ?? 0));

        return (new AtlasVetoPropagationResolver)->resolve($origin, $kind, $iteration);
    }

    /**
     * Observe-only AAEOS department registry validation.
     * Accepts `{department}` or `{departments}` list. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function departmentRegistryValidateObserve(array $input = []): array
    {
        $registry = new AtlasDepartmentRegistryService;

        if (isset($input['departments']) && is_array($input['departments'])) {
            return $registry->validateRegistry($input['departments']);
        }

        $department = AiValueNormalizer::arrayOrEmpty($input['department'] ?? $input);

        return $registry->validateDepartment($department);
    }

    /**
     * Observe-only AAEOS cognitive immune input classifier.
     * Accepts `{text, metadata?}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function cognitiveImmuneClassifyObserve(array $input = []): array
    {
        $text = AiValueNormalizer::trimmedStringOrNull($input['text'] ?? null) ?? '';
        $metadata = AiValueNormalizer::arrayOrEmpty($input['metadata'] ?? []);

        return (new AtlasCognitiveImmuneInputClassifier)->classify($text, $metadata);
    }

    /**
     * Observe-only composed obra-arc contract (composer + lifecycle).
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function composedObraArcContractObserve(array $input = []): array
    {
        return [
            'composer_schema' => ComposedObraArcComposer::SCHEMA_VERSION,
            'lifecycle_schema' => ComposedObraArcLifecycle::SCHEMA_VERSION,
            'min_neighbor_candidates' => ComposedObraArcComposer::MIN_NEIGHBOR_CANDIDATES,
            'kill_gate_consecutive_failures' => ComposedObraArcComposer::KILL_GATE_CONSECUTIVE_FAILURES,
            'default_author_engine_id' => ComposedObraArcComposer::DEFAULT_AUTHOR_ENGINE_ID,
            'default_judge_engine_id' => ComposedObraArcComposer::DEFAULT_JUDGE_ENGINE_ID,
            'supports_lifecycle_reset' => true,
        ];
    }

    /**
     * Observe-only summary-fidelity + segment-importance schemas.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function contextRetentionSchemasObserve(array $input = []): array
    {
        return [
            'summary_fidelity_schema' => SummaryFidelityCoverageScorer::SCHEMA_VERSION,
            'segment_importance_schema' => SegmentImportanceRanker::SCHEMA_VERSION,
            'summary_retention_fail_floor' => SummaryFidelityCoverageScorer::RETENTION_FAIL_FLOOR,
            'summary_score_precision' => SummaryFidelityCoverageScorer::SCORE_PRECISION,
            'summary_decision_kind' => SummaryFidelityCoverageScorer::DECISION_KIND,
        ];
    }

    /**
     * Observe-only memory-injection / Pareto / delivery-pack schemas.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function contextBudgetSchemasObserve(array $input = []): array
    {
        return [
            'memory_injection_schema' => MemoryInjectionBudgetAllocator::SCHEMA_VERSION,
            'context_pareto_schema' => ContextParetoDominanceFilter::SCHEMA_VERSION,
            'delivery_pack_schema' => DeliveryPackCompletenessScorer::SCHEMA,
            'memory_injection_default_floor_chars' => MemoryInjectionBudgetAllocator::DEFAULT_INTERNAL_FLOOR_CHARS,
            'memory_injection_drop_reasons' => [
                MemoryInjectionBudgetAllocator::REASON_BUDGET_EXHAUSTED,
                MemoryInjectionBudgetAllocator::REASON_BELOW_MIN_EXCERPT,
                MemoryInjectionBudgetAllocator::REASON_ZERO_ESTIMATED_CHARS,
            ],
            'pareto_directions' => [
                ContextParetoDominanceFilter::DIRECTION_MAXIMIZE,
                ContextParetoDominanceFilter::DIRECTION_MINIMIZE,
            ],
        ];
    }

    /**
     * Observe-only provenance weight floor + recall-gap weak score floor.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function memoryWeightFloorsContractObserve(array $input = []): array
    {
        return [
            'provenance_weight_schema' => ProvenanceWeightCalculator::SCHEMA_VERSION,
            'provenance_weight_floor' => ProvenanceWeightCalculator::FLOOR,
            'recall_gap_schema' => RecallGapAggregator::SCHEMA_VERSION,
            'recall_gap_weak_score_floor' => RecallGapAggregator::WEAK_SCORE_FLOOR,
            'citation_grounding_schema' => CitationGroundingMeter::SCHEMA_VERSION,
            'dogfooding_min_occurrences' => DogfoodingFrictionLeadMiner::MIN_OCCURRENCES,
            'provider_calls_made' => false,
        ];
    }

    /**
     * Observe-only domain lexical + structured-fact schema floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function domainLexicalFactSchemaContractObserve(array $input = []): array
    {
        return [
            'domain_lexical_schema' => DomainLexicalNormalizer::SCHEMA_VERSION,
            'domain_lexical_formula' => DomainLexicalNormalizer::FORMULA_VERSION,
            'max_expanded_tokens' => DomainLexicalNormalizer::MAX_EXPANDED_TOKENS,
            'equivalence_entry_count' => count(DomainLexicalNormalizer::EQUIVALENCES),
            'structured_fact_schema' => StructuredFactSchemaMap::SCHEMA_VERSION,
            'structured_fact_memory_types' => array_keys(StructuredFactSchemaMap::REQUIRED),
            'structured_fact_type_count' => count(StructuredFactSchemaMap::REQUIRED),
            'provider_calls_made' => false,
            'deterministic' => true,
        ];
    }

    /**
     * Observe-only phase-advance verdict + blocker severity signals.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function phaseAdvanceBlockerContractObserve(array $input = []): array
    {
        return [
            'phase_advance_schema' => PhaseAdvanceVerdictClassifier::SCHEMA_VERSION,
            'verdicts' => PhaseAdvanceVerdictClassifier::VERDICTS,
            'verdict_count' => count(PhaseAdvanceVerdictClassifier::VERDICTS),
            'rules' => PhaseAdvanceVerdictClassifier::RULES,
            'rule_count' => count(PhaseAdvanceVerdictClassifier::RULES),
            'blocker_signals' => AaeosBlockerSeverityGate::SIGNALS,
            'blocker_levels' => [
                AaeosBlockerSeverity::CRITICAL,
                AaeosBlockerSeverity::HIGH,
                AaeosBlockerSeverity::MEDIUM,
                AaeosBlockerSeverity::LOW,
            ],
        ];
    }

    /**
     * Observe-only outcome-causality + threshold-comparator floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function outcomeCausalityComparatorContractObserve(array $input = []): array
    {
        return [
            'outcome_causality_schema' => OutcomeCausalityRanker::SCHEMA_VERSION,
            'primary_causes' => OutcomeCausalityRanker::PRIMARY_CAUSES,
            'primary_cause_count' => count(OutcomeCausalityRanker::PRIMARY_CAUSES),
            'outcomes' => OutcomeCausalityRanker::OUTCOMES,
            'threshold_epsilon' => AtlasThresholdComparator::EPSILON,
            'memory_recall_schema' => AtlasMemoryRecallRelevanceScorer::SCHEMA_VERSION,
            'status_succeeded' => OutcomeCausalityRanker::STATUS_SUCCEEDED,
            'weight_missing_evidence' => OutcomeCausalityRanker::WEIGHT_MISSING_EVIDENCE,
            'weight_tests_failed' => OutcomeCausalityRanker::WEIGHT_TESTS_FAILED,
            'weight_execution_failed_or_blocked' => OutcomeCausalityRanker::WEIGHT_EXECUTION_FAILED_OR_BLOCKED,
            'weight_context_missing_required_sources' => OutcomeCausalityRanker::WEIGHT_CONTEXT_MISSING_REQUIRED_SOURCES,
            'weight_execution_strategy_likely_succeeded' => OutcomeCausalityRanker::WEIGHT_EXECUTION_STRATEGY_LIKELY_SUCCEEDED,
            'weight_scope_or_contract_mismatch' => OutcomeCausalityRanker::WEIGHT_SCOPE_OR_CONTRACT_MISMATCH,
            'weight_packet_quality_failure' => OutcomeCausalityRanker::WEIGHT_PACKET_QUALITY_FAILURE,
        ];
    }

    /**
     * Observe-only window-gates / evolution-score / hybrid-classifier floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function windowEvolutionHybridContractObserve(array $input = []): array
    {
        return [
            'window_gates_schema' => AtlasAcosWindowGatesService::SCHEMA_VERSION,
            'receipt_fresh_seconds' => AtlasAcosWindowGatesService::RECEIPT_FRESH_SECONDS,
            'evolution_score_schema' => AtlasAcosEvolutionScoreService::SCHEMA_VERSION,
            'heartbeat_fresh_seconds' => AtlasAcosEvolutionScoreService::HEARTBEAT_FRESH_SECONDS,
            'gate_fresh_seconds' => AtlasAcosEvolutionScoreService::GATE_FRESH_SECONDS,
            'scheduled_organs' => AtlasAcosEvolutionScoreService::SCHEDULED_ORGANS,
            'scheduled_organ_count' => count(AtlasAcosEvolutionScoreService::SCHEDULED_ORGANS),
            'lift_cases_per_arm_required' => AtlasAcosEvolutionScoreService::LIFT_CASES_PER_ARM_REQUIRED,
            'hostile_severity' => AtlasImmuneHybridInputClassifier::HOSTILE_SEVERITY,
            'hostile_severity_count' => count(AtlasImmuneHybridInputClassifier::HOSTILE_SEVERITY),
        ];
    }

    /**
     * Observe-only implementation-truth / docs-authority / verified-share floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function implementationAuthorityContractObserve(array $input = []): array
    {
        return [
            'implementation_truth_schema' => AtlasImplementationTruthService::SCHEMA,
            'implementation_truth_ranks' => AtlasImplementationTruthService::RANK,
            'implementation_truth_rank_count' => count(AtlasImplementationTruthService::RANK),
            'docs_authority_schema' => AtlasDocsAuthorityGraphService::SCHEMA_VERSION,
            'docs_authority_confidence' => AtlasDocsAuthorityGraphService::CONFIDENCE,
            'docs_authority_basis_count' => count(AtlasDocsAuthorityGraphService::CONFIDENCE),
            'verified_share_schema' => AcosMaxVerifiedShareService::SCHEMA_VERSION,
            'verified_share_executors' => AcosMaxVerifiedShareService::EXECUTORS,
            'verified_share_executor_count' => count(AcosMaxVerifiedShareService::EXECUTORS),
            'quality_bar_schema' => AtlasDepartmentQualityBarService::SCHEMA_VERSION,
            'department_level_schema' => AtlasDepartmentLevelClassifier::SCHEMA_VERSION,
            'department_maturity_band_schema' => AtlasDepartmentMaturityBandClassifier::SCHEMA_VERSION,
        ];
    }

    /**
     * Observe-only evidence-resolver / volume / deferred-phase / scorecard floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evidenceVolumeDeferredContractObserve(array $input = []): array
    {
        return [
            'evidence_symbol_types' => AtlasImplementationEvidenceResolver::SYMBOL_TYPES,
            'evidence_symbol_type_count' => count(AtlasImplementationEvidenceResolver::SYMBOL_TYPES),
            'evidence_signature_match_types' => AtlasImplementationEvidenceResolver::SIGNATURE_MATCH_TYPES,
            'test_execution_schema' => AtlasCapabilityTestExecutionService::SCHEMA,
            'test_output_tail_chars' => AtlasCapabilityTestExecutionService::OUTPUT_TAIL_CHARS,
            'operational_volume_schema' => AtlasOperationalVolumeCheckService::SCHEMA_VERSION,
            'dev_flow_ids' => AtlasOperationalVolumeCheckService::DEV_FLOW_IDS,
            'forge_flow_ids' => AtlasOperationalVolumeCheckService::FORGE_FLOW_IDS,
            'dev_runs_per_business_day_min' => AtlasOperationalVolumeCheckService::DEV_RUNS_PER_BUSINESS_DAY_MIN,
            'forge_cycles_per_week_min' => AtlasOperationalVolumeCheckService::FORGE_CYCLES_PER_WEEK_MIN,
            'deferred_phase_keys' => array_keys(AaeosHttpPathEnvelopeFactory::DEFERRED_PHASE_SPECS),
            'deferred_phase_count' => count(AaeosHttpPathEnvelopeFactory::DEFERRED_PHASE_SPECS),
            'scorecard_schema' => AtlasCognitionScoreCardService::SCHEMA_VERSION,
            'scorecard_status_points' => AtlasCognitionScoreCardService::STATUS_POINTS,
            'department_maturity_schema' => AtlasDepartmentMaturityService::SCHEMA_VERSION,
            'department_maturity_owner' => AtlasDepartmentMaturityService::OWNER,
        ];
    }

    /**
     * Observe-only ACOS watchdog health floors + check statuses.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function watchdogHealthFloorsContractObserve(array $input = []): array
    {
        return [
            'memory_quality_schema' => AtlasAcosWatchdogHealthService::MEMORY_QUALITY_SCHEMA,
            'context_feedback_schema' => AtlasAcosWatchdogHealthService::CONTEXT_FEEDBACK_SCHEMA,
            'compaction_soak_schema' => AtlasAcosWatchdogHealthService::COMPACTION_SOAK_SCHEMA,
            'engineering_readiness_schema' => AtlasAcosWatchdogHealthService::ENGINEERING_READINESS_SCHEMA,
            'memory_score_regression_tolerance' => AtlasAcosWatchdogHealthService::MEMORY_SCORE_REGRESSION_TOLERANCE,
            'memory_snapshot_max_age_hours' => AtlasAcosWatchdogHealthService::MEMORY_SNAPSHOT_MAX_AGE_HOURS,
            'memory_concentration_floor' => AtlasAcosWatchdogHealthService::MEMORY_CONCENTRATION_FLOOR,
            'rag_coverage_floor' => AtlasAcosWatchdogHealthService::RAG_COVERAGE_FLOOR,
            'rag_recall_at_5_floor' => AtlasAcosWatchdogHealthService::RAG_RECALL_AT_5_FLOOR,
            'feedback_window_hours' => AtlasAcosWatchdogHealthService::FEEDBACK_WINDOW_HOURS,
            'feedback_total_event_floor' => AtlasAcosWatchdogHealthService::FEEDBACK_TOTAL_EVENT_FLOOR,
            'compaction_min_receipts' => AtlasAcosWatchdogHealthService::COMPACTION_MIN_RECEIPTS,
            'compaction_min_retention_score' => AtlasAcosWatchdogHealthService::COMPACTION_MIN_RETENTION_SCORE,
            'eng_window_days' => AtlasAcosWatchdogHealthService::ENG_WINDOW_DAYS,
            'eng_min_forge_promoted_cycles' => AtlasAcosWatchdogHealthService::ENG_MIN_FORGE_PROMOTED_CYCLES,
            'watchdog_statuses' => AtlasWatchdogCheckResult::STATUSES,
            'watchdog_status_count' => count(AtlasWatchdogCheckResult::STATUSES),
            'debug_root_cause_version' => AtlasDebugRootCauseService::SERVICE_VERSION,
            'quality_bar_level_schema' => AtlasDepartmentQualityBarLevelClassifier::SCHEMA_VERSION,
            'obra_retro_schema' => AcosMaxObraRetroService::SCHEMA_VERSION,
            'obra_retro_scoreboard_path' => AcosMaxObraRetroService::SCOREBOARD_RELATIVE_PATH,
        ];
    }

    /**
     * Observe-only evidence-vision composer floors (deepen of lifecycle observe).
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evidenceVisionComposerContractObserve(array $input = []): array
    {
        return [
            'composer_schema' => EvidenceVisionThesisComposer::SCHEMA_VERSION,
            'lifecycle_schema' => EvidenceVisionThesisLifecycle::SCHEMA_VERSION,
            'max_theses' => EvidenceVisionThesisComposer::MAX_THESES,
            'min_regression_windows' => EvidenceVisionThesisComposer::MIN_REGRESSION_WINDOWS,
            'default_ttl_days' => EvidenceVisionThesisComposer::DEFAULT_TTL_DAYS,
            'default_author_engine_id' => EvidenceVisionThesisComposer::DEFAULT_AUTHOR_ENGINE_ID,
            'allowed_evidence_sources' => EvidenceVisionThesisComposer::ALLOWED_EVIDENCE_SOURCES,
            'allowed_evidence_source_count' => count(EvidenceVisionThesisComposer::ALLOWED_EVIDENCE_SOURCES),
            'remint_touched_schema' => AtlasCognitionRemintTouchedQueue::SCHEMA_VERSION,
        ];
    }

    /**
     * Observe-only measure-series freshness + MAXA-04 dual-read floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function measureSeriesMaxa04ContractObserve(array $input = []): array
    {
        return [
            'freshness_schema' => AcosMeasureSeriesFreshnessReader::SCHEMA,
            'capture_hmac_schema' => CaptureHmacLineageService::SCHEMA_VERSION,
            'capture_hmac_stages' => [
                CaptureHmacLineageService::STAGE_SOURCE,
                CaptureHmacLineageService::STAGE_CAPTURE,
                CaptureHmacLineageService::STAGE_MEMORY,
            ],
            'maxa04_candidate_model' => Maxa04JinaV3DualReadService::CANDIDATE_MODEL,
            'maxa04_candidate_dimensions' => Maxa04JinaV3DualReadService::CANDIDATE_DIMENSIONS,
            'maxa04_pending_window' => Maxa04JinaV3DualReadService::PENDING_WINDOW,
            'maxa04_ledger_schema' => Maxa04JinaV3DualReadLedger::SCHEMA,
            'maxa04_ledger_relative_path' => Maxa04JinaV3DualReadLedger::RELATIVE_PATH,
            'teto10_schema' => Teto10PredictedRevertReviewDigest::SCHEMA_VERSION,
            'teto10_band_rank' => Teto10PredictedRevertReviewDigest::BAND_RANK,
        ];
    }

    /**
     * Observe-only RAGX + cross-dept choreography + resource-budget floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function ragxChoreographyBudgetContractObserve(array $input = []): array
    {
        return [
            'ragx_schema' => RagxChainMechanismService::SCHEMA,
            'ragx_ab_schema' => RagxChainMechanismService::AB_SCHEMA,
            'ragx_raptor_schema' => RagxChainMechanismService::RAPTOR_SCHEMA,
            'ragx_louvain_schema' => RagxChainMechanismService::LOUVAIN_SCHEMA,
            'choreography_handoff_schema' => AtlasCrossDepartmentChoreographyService::HANDOFF_SCHEMA,
            'choreography_veto_sla_seconds' => AtlasCrossDepartmentChoreographyService::VETO_SLA_SECONDS,
            'choreography_repair_max_iterations' => AtlasCrossDepartmentChoreographyService::REPAIR_MAX_ITERATIONS,
            'choreography_handoff_kinds' => AtlasCrossDepartmentChoreographyService::HANDOFF_KINDS,
            'choreography_veto_rules' => AtlasCrossDepartmentChoreographyService::VETO_RULES,
            'choreography_veto_rule_count' => count(AtlasCrossDepartmentChoreographyService::VETO_RULES),
            'resource_budget_schema' => AtlasResourceBudgetService::SCHEMA,
            'exploratory_bets_schema' => ExploratoryBetsPortfolio::SCHEMA_VERSION,
            'exploratory_bets_default_k' => ExploratoryBetsPortfolio::DEFAULT_K,
            'exploratory_bets_min_n' => ExploratoryBetsPortfolio::MIN_N,
            'promotion_protocol_schema' => PromotionProtocol::SCHEMA,
            'promotion_protocol_states' => PromotionProtocol::STATES,
            'cognitive_immune_check_schema' => CognitiveImmuneCheckContract::SCHEMA,
        ];
    }

    /**
     * Observe-only verified-share + scorecard subsystems + golden/asef floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function verifiedShareScorecardContractObserve(array $input = []): array
    {
        return [
            'verified_share_schema' => AcosMaxVerifiedShareService::SCHEMA_VERSION,
            'verified_share_measure_id' => AcosMaxVerifiedShareService::MEASURE_ID,
            'verified_share_formula' => AcosMaxVerifiedShareService::FORMULA_VERSION,
            'verified_share_executors' => AcosMaxVerifiedShareService::EXECUTORS,
            'verified_share_executor_count' => count(AcosMaxVerifiedShareService::EXECUTORS),
            'scorecard_status_points' => AtlasCognitionScoreCardService::STATUS_POINTS,
            'scorecard_subsystem_count' => count(AtlasCognitionScoreCardService::SUBSYSTEMS),
            'scorecard_v4_supplemental_count' => count(AtlasCognitionScoreCardService::V4_SUPPLEMENTAL_SUBSYSTEMS),
            'golden_counterfactual_schema' => GoldenCounterfactualReplayService::SCHEMA_VERSION,
            'golden_counterfactual_measure_id' => GoldenCounterfactualReplayService::MEASURE_ID,
            'golden_counterfactual_formula' => GoldenCounterfactualReplayService::FORMULA_VERSION,
            'asef_chunk_index_schema' => AsefChunkIndexService::SCHEMA_VERSION,
            'outcome_envelope_schema' => OutcomeEnvelope::SCHEMA_VERSION,
            'composed_obra_author_engine' => ComposedObraArcComposer::DEFAULT_AUTHOR_ENGINE_ID,
            'composed_obra_judge_engine' => ComposedObraArcComposer::DEFAULT_JUDGE_ENGINE_ID,
        ];
    }

    /**
     * Observe-only AAEOS evidence/maturity/test-execution/deferred floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function aaeosEvidenceMaturityContractObserve(array $input = []): array
    {
        return [
            'test_execution_schema' => AtlasCapabilityTestExecutionService::SCHEMA,
            'test_execution_output_tail_chars' => AtlasCapabilityTestExecutionService::OUTPUT_TAIL_CHARS,
            'evidence_symbol_types' => AtlasImplementationEvidenceResolver::SYMBOL_TYPES,
            'evidence_shared_index_key' => AtlasImplementationEvidenceResolver::SHARED_INDEX_KEY,
            'evidence_signature_match_types' => AtlasImplementationEvidenceResolver::SIGNATURE_MATCH_TYPES,
            'department_maturity_schema' => AtlasDepartmentMaturityService::SCHEMA_VERSION,
            'department_maturity_owner' => AtlasDepartmentMaturityService::OWNER,
            'department_maturity_department_count' => count(AtlasDepartmentMaturityService::DEPARTMENTS),
            'department_maturity_last_evaluation' => AtlasDepartmentMaturityService::LAST_EVALUATION,
            'department_maturity_next_due' => AtlasDepartmentMaturityService::NEXT_EVALUATION_DUE,
            'deferred_phase_schema' => AaeosDeferredPhaseDispatcherService::SCHEMA_VERSION,
            'immune_injection_marker_count' => count(AtlasCognitiveImmuneInputClassifier::INJECTION_MARKERS),
            'immune_strategic_marker_count' => count(AtlasCognitiveImmuneInputClassifier::STRATEGIC_MARKERS),
            'immune_technical_marker_count' => count(AtlasCognitiveImmuneInputClassifier::TECHNICAL_MARKERS),
        ];
    }

    /**
     * Observe-only lote-2 measure ids + quality-bar department floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function lote2QualityBarContractObserve(array $input = []): array
    {
        return [
            'maxl06_measure_id' => AcosMaxLote2MeasureService::MAXL06_MEASURE_ID,
            'multn1704_measure_id' => AcosMaxLote2MeasureService::MULTN1704_MEASURE_ID,
            'multx01_measure_id' => AcosMaxLote2MeasureService::MULTX01_MEASURE_ID,
            'multx06_measure_id' => AcosMaxLote2MeasureService::MULTX06_MEASURE_ID,
            'multx09_measure_id' => AcosMaxLote2MeasureService::MULTX09_MEASURE_ID,
            'multj01_measure_id' => AcosMaxLote2MeasureService::MULTJ01_MEASURE_ID,
            'multj02_measure_id' => AcosMaxLote2MeasureService::MULTJ02_MEASURE_ID,
            'multj03_measure_id' => AcosMaxLote2MeasureService::MULTJ03_MEASURE_ID,
            'multj04_measure_id' => AcosMaxLote2MeasureService::MULTJ04_MEASURE_ID,
            'multj06_measure_id' => AcosMaxLote2MeasureService::MULTJ06_MEASURE_ID,
            'teto02_measure_id' => AcosMaxLote2MeasureService::TETO02_MEASURE_ID,
            'quality_bar_schema' => AtlasDepartmentQualityBarService::SCHEMA_VERSION,
            'quality_bar_department_count' => count(AtlasDepartmentQualityBarService::DEPARTMENT_DATA),
            'obra_retro_schema' => AcosMaxObraRetroService::SCHEMA_VERSION,
            'obra_retro_scoreboard_path' => AcosMaxObraRetroService::SCOREBOARD_RELATIVE_PATH,
        ];
    }

    /**
     * Observe-only embedding coverage + N-capture + implementation-truth floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function embeddingCoverageTruthContractObserve(array $input = []): array
    {
        return [
            'kb_embedding_schema' => AtlasKnowledgeItemEmbeddingCoverageService::SCHEMA_VERSION,
            'kb_embedding_measure_id' => AtlasKnowledgeItemEmbeddingCoverageService::MEASURE_ID,
            'kb_embedding_formula' => AtlasKnowledgeItemEmbeddingCoverageService::FORMULA_VERSION,
            'code_symbol_embedding_schema' => AtlasCodeSymbolEmbeddingCoverageService::SCHEMA_VERSION,
            'code_symbol_embedding_measure_id' => AtlasCodeSymbolEmbeddingCoverageService::MEASURE_ID,
            'code_symbol_embedding_formula' => AtlasCodeSymbolEmbeddingCoverageService::FORMULA_VERSION,
            'n_capture_schema' => AtlasNCaptureDrillService::SCHEMA_VERSION,
            'n_capture_measure_id' => AtlasNCaptureDrillService::MEASURE_ID,
            'n_capture_formula' => AtlasNCaptureDrillService::FORMULA_VERSION,
            'n_capture_ledger_path' => AtlasNCaptureDrillService::RELATIVE_LEDGER_PATH,
            'implementation_truth_schema' => AtlasImplementationTruthService::SCHEMA,
            'implementation_truth_ledger_schema' => AtlasImplementationTruthService::LEDGER_SCHEMA,
            'implementation_truth_hash_format' => AtlasImplementationTruthService::IMPL_FILES_HASH_FORMAT,
            'implementation_truth_rank' => AtlasImplementationTruthService::RANK,
            'execution_cooccurrence_schema' => ExecutionContextCooccurrenceService::SCHEMA_VERSION,
            'execution_cooccurrence_measure_id' => ExecutionContextCooccurrenceService::MEASURE_ID,
            'execution_cooccurrence_formula' => ExecutionContextCooccurrenceService::FORMULA_VERSION,
        ];
    }

    /**
     * Observe-only phase-gates map + flywheel + parallel-execution + surprise floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function phaseGatesFlywheelContractObserve(array $input = []): array
    {
        return [
            'phase_handoff_schema' => AaeosPhaseHandoffService::SCHEMA_VERSION,
            'phase_count' => count(AaeosPhaseHandoffService::PHASES),
            'phase_gates_map' => AaeosPhaseHandoffService::PHASE_GATES_MAP,
            'phase_gates_map_count' => count(AaeosPhaseHandoffService::PHASE_GATES_MAP),
            'phases_requiring_signature_at_l4' => AaeosPhaseHandoffService::PHASES_REQUIRING_SIGNATURE_AT_L4,
            'flywheel_schema' => AtlasFlywheelFunnelService::SCHEMA_VERSION,
            'flywheel_measure_id' => AtlasFlywheelFunnelService::MEASURE_ID,
            'flywheel_stages' => AtlasFlywheelFunnelService::STAGES,
            'flywheel_stage_count' => count(AtlasFlywheelFunnelService::STAGES),
            'parallel_execution_schema' => AcosMaxParallelExecutionProtocol::SCHEMA,
            'parallel_execution_claim_kind' => AcosMaxParallelExecutionProtocol::CLAIM_KIND,
            'parallel_execution_default_ttl_seconds' => AcosMaxParallelExecutionProtocol::DEFAULT_TTL_SECONDS,
            'surprise_gate_default_threshold' => AtlasSurpriseGateService::DEFAULT_THRESHOLD,
            'surprise_gate_default_high_band' => AtlasSurpriseGateService::DEFAULT_HIGH_BAND,
            'surprise_gate_min_prediction_tokens' => AtlasSurpriseGateService::DEFAULT_MIN_PREDICTION_TOKENS,
            'quality_bar_level_schema' => AtlasDepartmentQualityBarLevelClassifier::SCHEMA_VERSION,
            'maturity_band_schema' => AtlasDepartmentMaturityBandClassifier::SCHEMA_VERSION,
        ];
    }

    /**
     * Observe-only frontier/watchdog/cockpit/window/immune-freeze floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function frontierWatchdogCockpitContractObserve(array $input = []): array
    {
        return [
            'frontier_ladder_schema' => AtlasFrontierWaveLadder::SCHEMA_VERSION,
            'frontier_event_threshold' => AtlasFrontierWaveLadder::EVENT_THRESHOLD,
            'frontier_event_kinds' => AtlasFrontierWaveLadder::EVENT_KINDS,
            'frontier_event_kind_count' => count(AtlasFrontierWaveLadder::EVENT_KINDS),
            'frontier_wave_count' => count(AtlasFrontierWaveLadder::WAVES),
            'watchdog_runner_schema' => AtlasWatchdogRunner::SCHEMA_VERSION,
            'daily_canary_schema' => DailyCanaryReplayByRefsWatchdogCheck::SCHEMA_VERSION,
            'autonomy_ladder_adversarial_schema' => AutonomyLadderAdversarialWatchdogCheck::SCHEMA,
            'evidence_ledger_integrity_schema' => EvidenceLedgerIntegrityWatchdogCheck::SCHEMA_VERSION,
            'cockpit_schema' => AcosProgramCockpitService::SCHEMA_VERSION,
            'window_orchestrator_schema' => AcosMaxWindowOrchestratorService::SCHEMA_VERSION,
            'immune_hybrid_freeze_measure_id' => AtlasImmuneClassifierHybridFreeze::MEASURE_ID,
            'immune_hybrid_freeze_formula' => AtlasImmuneClassifierHybridFreeze::FORMULA_VERSION,
            'immune_hybrid_freeze_ttl_days' => AtlasImmuneClassifierHybridFreeze::TTL_DAYS,
            'immune_hybrid_freeze_anchor_fixture' => AtlasImmuneClassifierHybridFreeze::ANCHOR_FIXTURE_RELATIVE,
            'immune_hybrid_freeze_corpus_fixture' => AtlasImmuneClassifierHybridFreeze::CORPUS_FIXTURE_RELATIVE,
            'outcome_envelope_bridge_measure_id' => OutcomeEnvelopeBridge::MEASURE_ID,
        ];
    }

    /**
     * Observe-only runbook/department/atlas/memory-fabric floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function runbookDepartmentAtlasContractObserve(array $input = []): array
    {
        return [
            'runbook_schema' => RunbookOrchestrator::SCHEMA_VERSION,
            'architecture_redesign_proposal_schema' => RunbookOrchestrator::ARCHITECTURE_REDESIGN_PROPOSAL_SCHEMA,
            'runbook_replay_obras_count_min' => RunbookOrchestrator::REPLAY_OBRAS_COUNT_MIN,
            'runbook_default_flow_count' => count(RunbookOrchestrator::DEFAULT_FLOW),
            'department_runtime_schema' => DepartmentContractRuntime::SCHEMA_VERSION,
            'department_catalogue_count' => count(DepartmentContractRuntime::CATALOGUE),
            'department_canonical_field_count' => count(DepartmentContractRuntime::CANONICAL_FIELDS),
            'cognitive_function_atlas_self_model_schema' => AtlasCognitiveFunctionAtlasService::SELF_MODEL_SCHEMA,
            'cognitive_function_atlas_group_summary_schema' => AtlasCognitiveFunctionAtlasService::GROUP_SUMMARY_SCHEMA,
            'cognitive_function_atlas_overload_threshold' => AtlasCognitiveFunctionAtlasService::OVERLOAD_DEFAULT_THRESHOLD,
            'memory_fabric_proposal_schema' => AtlasCognitiveMemoryFabricSchemaEvolutionService::PROPOSAL_SCHEMA,
            'memory_fabric_ticket_schema' => AtlasCognitiveMemoryFabricSchemaEvolutionService::TICKET_SCHEMA,
            'memory_fabric_valid_triggers' => AtlasCognitiveMemoryFabricSchemaEvolutionService::VALID_TRIGGERS,
            'memory_fabric_extension_pressure_threshold' => AtlasCognitiveMemoryFabricSchemaEvolutionService::EXTENSION_PRESSURE_THRESHOLD,
        ];
    }

    /**
     * Observe-only remaining ACOS watchdog health + daily-canary floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function watchdogCanaryFloorsContractObserve(array $input = []): array
    {
        return [
            'learning_negative_max_age_hours' => AtlasAcosWatchdogHealthService::LEARNING_NEGATIVE_MAX_AGE_HOURS,
            'learning_aemor_source_max_age_hours' => AtlasAcosWatchdogHealthService::LEARNING_AEMOR_SOURCE_MAX_AGE_HOURS,
            'learning_ai_run_outcome_max_age_hours' => AtlasAcosWatchdogHealthService::LEARNING_AI_RUN_OUTCOME_MAX_AGE_HOURS,
            'rag_retrieval_eval_floor' => AtlasAcosWatchdogHealthService::RAG_RETRIEVAL_EVAL_FLOOR,
            'rag_pre_filter_concentration_mask_floor' => AtlasAcosWatchdogHealthService::RAG_PRE_FILTER_CONCENTRATION_MASK_FLOOR,
            'feedback_measured_count_floor' => AtlasAcosWatchdogHealthService::FEEDBACK_MEASURED_COUNT_FLOOR,
            'feedback_synthetic_share_max' => AtlasAcosWatchdogHealthService::FEEDBACK_SYNTHETIC_SHARE_MAX,
            'compaction_window_days' => AtlasAcosWatchdogHealthService::COMPACTION_WINDOW_DAYS,
            'lift_stalled_days' => AtlasAcosWatchdogHealthService::LIFT_STALLED_DAYS,
            'pipeline_partial_stale_days' => AtlasAcosWatchdogHealthService::PIPELINE_PARTIAL_STALE_DAYS,
            'eng_min_real_executions_per_executor' => AtlasAcosWatchdogHealthService::ENG_MIN_REAL_EXECUTIONS_PER_EXECUTOR,
            'eng_min_adml_proven_routes' => AtlasAcosWatchdogHealthService::ENG_MIN_ADML_PROVEN_ROUTES,
            'daily_canary_schema' => DailyCanaryReplayByRefsWatchdogCheck::SCHEMA_VERSION,
            'daily_canary_default_window_hours' => DailyCanaryReplayByRefsWatchdogCheck::DEFAULT_WINDOW_HOURS,
            'daily_canary_default_top_n_flows' => DailyCanaryReplayByRefsWatchdogCheck::DEFAULT_TOP_N_FLOWS,
            'daily_canary_ref_stability_alert_floor' => DailyCanaryReplayByRefsWatchdogCheck::REF_STABILITY_ALERT_FLOOR,
            'daily_canary_golden_recall_at_5_alert_floor' => DailyCanaryReplayByRefsWatchdogCheck::GOLDEN_RECALL_AT_5_ALERT_FLOOR,
            'daily_canary_improper_floor_discard_alert_ceiling' => DailyCanaryReplayByRefsWatchdogCheck::IMPROPER_FLOOR_DISCARD_ALERT_CEILING,
            'daily_canary_forbidden_evidence_key_pattern' => DailyCanaryReplayByRefsWatchdogCheck::FORBIDDEN_EVIDENCE_KEY_PATTERN,
            'watchdog_status_ok' => AtlasWatchdogCheckResult::STATUS_OK,
            'watchdog_status_warning' => AtlasWatchdogCheckResult::STATUS_WARNING,
            'watchdog_status_alert' => AtlasWatchdogCheckResult::STATUS_ALERT,
            'watchdog_status_skipped' => AtlasWatchdogCheckResult::STATUS_SKIPPED,
            'watchdog_status_error' => AtlasWatchdogCheckResult::STATUS_ERROR,
            'watchdog_status_count' => count(AtlasWatchdogCheckResult::STATUSES),
        ];
    }

    /**
     * Observe-only HTTP path facade + phase-router + department-id floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function httpPathFacadeContractObserve(array $input = []): array
    {
        return [
            'result_ok' => AtlasAaeosHttpPathFacadeService::RESULT_OK,
            'result_blocked' => AtlasAaeosHttpPathFacadeService::RESULT_BLOCKED,
            'block_placement_gate_blocked' => AtlasAaeosHttpPathFacadeService::BLOCK_PLACEMENT_GATE_BLOCKED,
            'block_policy_gate_blocked' => AtlasAaeosHttpPathFacadeService::BLOCK_POLICY_GATE_BLOCKED,
            'telemetry_key_requests' => AtlasAaeosHttpPathFacadeService::TELEMETRY_KEY_REQUESTS,
            'telemetry_key_canonical' => AtlasAaeosHttpPathFacadeService::TELEMETRY_KEY_CANONICAL,
            'telemetry_key_legacy_fallback' => AtlasAaeosHttpPathFacadeService::TELEMETRY_KEY_LEGACY_FALLBACK,
            'telemetry_key_blocked' => AtlasAaeosHttpPathFacadeService::TELEMETRY_KEY_BLOCKED,
            'telemetry_key_latency' => AtlasAaeosHttpPathFacadeService::TELEMETRY_KEY_LATENCY,
            'risk_band_fast_path' => AaeosHttpPathEnvelopeFactory::RISK_BAND_FAST_PATH,
            'risk_band_r3_plus' => AaeosHttpPathEnvelopeFactory::RISK_BAND_R3_PLUS,
            'phase_router_schema' => AtlasPhaseRouterService::SCHEMA_VERSION,
            'phase_legacy' => AtlasPhaseRouterService::PHASE_LEGACY,
            'phase_1' => AtlasPhaseRouterService::PHASE_1,
            'phase_2' => AtlasPhaseRouterService::PHASE_2,
            'phase_3' => AtlasPhaseRouterService::PHASE_3,
            'phase_4' => AtlasPhaseRouterService::PHASE_4,
            'valid_phase_count' => count(AtlasPhaseRouterService::VALID_PHASES),
            'department_runtime_schema' => DepartmentContractRuntime::SCHEMA_VERSION,
            'departments' => [
                DepartmentContractRuntime::DEPARTMENT_EXECUTIVE_INTAKE,
                DepartmentContractRuntime::DEPARTMENT_PRODUCT,
                DepartmentContractRuntime::DEPARTMENT_ARCHITECTURE,
                DepartmentContractRuntime::DEPARTMENT_RESEARCH,
                DepartmentContractRuntime::DEPARTMENT_DEV,
                DepartmentContractRuntime::DEPARTMENT_DEBUG,
                DepartmentContractRuntime::DEPARTMENT_REVIEW,
                DepartmentContractRuntime::DEPARTMENT_QA,
                DepartmentContractRuntime::DEPARTMENT_SECURITY,
                DepartmentContractRuntime::DEPARTMENT_FORGE,
                DepartmentContractRuntime::DEPARTMENT_DELIVERY,
                DepartmentContractRuntime::DEPARTMENT_MEMORY,
            ],
            'department_count' => 12,
            'architect_department_id' => ArchitectAgentSpecPackGateContract::DEPARTMENT_ID,
            'architect_spec_pack_schema' => ArchitectAgentSpecPackGateContract::SPEC_PACK_SCHEMA,
            'architect_evidence_required' => ArchitectAgentSpecPackGateContract::EVIDENCE_REQUIRED,
            'architect_evidence_required_count' => count(ArchitectAgentSpecPackGateContract::EVIDENCE_REQUIRED),
        ];
    }

    /**
     * Observe-only individual phase / doc-maturity / promotion id floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function phaseDocPromotionIdsContractObserve(array $input = []): array
    {
        return [
            'phase_handoff_schema' => AaeosPhaseHandoffService::SCHEMA_VERSION,
            'phase_ids' => [
                AaeosPhaseHandoffService::PHASE_INTENT_CAPTURE,
                AaeosPhaseHandoffService::PHASE_DISAMBIGUATION,
                AaeosPhaseHandoffService::PHASE_PLACEMENT,
                AaeosPhaseHandoffService::PHASE_CLASSIFICATION,
                AaeosPhaseHandoffService::PHASE_POLICY_GATE,
                AaeosPhaseHandoffService::PHASE_TOPOLOGY,
                AaeosPhaseHandoffService::PHASE_ROUTING,
                AaeosPhaseHandoffService::PHASE_SPEC,
                AaeosPhaseHandoffService::PHASE_TASKS,
                AaeosPhaseHandoffService::PHASE_RECEIPT,
                AaeosPhaseHandoffService::PHASE_EXECUTION,
                AaeosPhaseHandoffService::PHASE_GATES,
                AaeosPhaseHandoffService::PHASE_EVIDENCE,
                AaeosPhaseHandoffService::PHASE_DELIVERY,
                AaeosPhaseHandoffService::PHASE_HUMAN_REVIEW,
                AaeosPhaseHandoffService::PHASE_CERTIFICATION,
                AaeosPhaseHandoffService::PHASE_LEARNING,
            ],
            'phase_id_count' => 17,
            'phase_advance_schema' => PhaseAdvanceVerdictClassifier::SCHEMA_VERSION,
            'phase_advance_policy_gate' => PhaseAdvanceVerdictClassifier::PHASE_POLICY_GATE,
            'phase_advance_receipt' => PhaseAdvanceVerdictClassifier::PHASE_RECEIPT,
            'policy_gate_token' => PhaseAdvanceVerdictClassifier::POLICY_GATE_TOKEN,
            'verdict_advance' => PhaseAdvanceVerdictClassifier::VERDICT_ADVANCE,
            'verdict_repair' => PhaseAdvanceVerdictClassifier::VERDICT_REPAIR,
            'verdict_block' => PhaseAdvanceVerdictClassifier::VERDICT_BLOCK,
            'verdict_halt' => PhaseAdvanceVerdictClassifier::VERDICT_HALT,
            'doc_maturity_schema' => AtlasDocMaturityClassifier::SCHEMA_VERSION,
            'doc_level_l0' => AtlasDocMaturityClassifier::LEVEL_L0,
            'doc_level_l1' => AtlasDocMaturityClassifier::LEVEL_L1,
            'doc_level_l2' => AtlasDocMaturityClassifier::LEVEL_L2,
            'doc_level_l3' => AtlasDocMaturityClassifier::LEVEL_L3,
            'doc_level_l4' => AtlasDocMaturityClassifier::LEVEL_L4,
            'doc_strength_none' => AtlasDocMaturityClassifier::STRENGTH_NONE,
            'doc_strength_partial' => AtlasDocMaturityClassifier::STRENGTH_PARTIAL,
            'doc_strength_strong' => AtlasDocMaturityClassifier::STRENGTH_STRONG,
            'promotion_schema' => PromotionProtocol::SCHEMA,
            'promotion_state_off' => PromotionProtocol::STATE_OFF,
            'promotion_state_shadow' => PromotionProtocol::STATE_SHADOW,
            'promotion_state_live' => PromotionProtocol::STATE_LIVE,
            'promotion_state_rolled_back' => PromotionProtocol::STATE_ROLLED_BACK,
            'promotion_state_suspended_pending_evidence' => PromotionProtocol::STATE_SUSPENDED_PENDING_EVIDENCE,
            'promotion_default_ledger_relative_path' => PromotionProtocol::DEFAULT_LEDGER_RELATIVE_PATH,
            'promotion_required_fields' => PromotionProtocol::REQUIRED_FIELDS,
            'promotion_required_field_count' => count(PromotionProtocol::REQUIRED_FIELDS),
            'claim_dod_schema' => AtlasClaimDefinitionOfDoneValidator::SCHEMA_VERSION,
            'claim_field_owner_doc' => AtlasClaimDefinitionOfDoneValidator::FIELD_OWNER_DOC,
            'claim_field_documental_state' => AtlasClaimDefinitionOfDoneValidator::FIELD_DOCUMENTAL_STATE,
            'claim_field_runtime_state' => AtlasClaimDefinitionOfDoneValidator::FIELD_RUNTIME_STATE,
            'claim_field_code_command_path' => AtlasClaimDefinitionOfDoneValidator::FIELD_CODE_COMMAND_PATH,
            'claim_field_proof' => AtlasClaimDefinitionOfDoneValidator::FIELD_PROOF,
            'claim_field_caveat' => AtlasClaimDefinitionOfDoneValidator::FIELD_CAVEAT,
        ];
    }

    /**
     * Observe-only outcome/immune/scorecard/delivery/blocker/fabric id floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function outcomeImmuneScorecardIdsContractObserve(array $input = []): array
    {
        return [
            'outcome_success' => OutcomeCausalityRanker::OUTCOME_SUCCESS,
            'outcome_give_back' => OutcomeCausalityRanker::OUTCOME_GIVE_BACK,
            'outcome_poison' => OutcomeCausalityRanker::OUTCOME_POISON,
            'outcome_quarantine' => OutcomeCausalityRanker::OUTCOME_QUARANTINE,
            'outcome_count' => count(OutcomeCausalityRanker::OUTCOMES),
            'immune_verdict_table' => ImmuneVerdictLedger::TABLE,
            'immune_label_true_block' => ImmuneVerdictLedger::LABEL_TRUE_BLOCK,
            'immune_label_false_block' => ImmuneVerdictLedger::LABEL_FALSE_BLOCK,
            'immune_label_missed_poison' => ImmuneVerdictLedger::LABEL_MISSED_POISON,
            'immune_label_count' => count(ImmuneVerdictLedger::LABELS),
            'scorecard_status_ready' => AtlasCognitionScoreCardService::STATUS_READY,
            'scorecard_status_partial' => AtlasCognitionScoreCardService::STATUS_PARTIAL,
            'scorecard_status_building' => AtlasCognitionScoreCardService::STATUS_BUILDING,
            'scorecard_status_blocked' => AtlasCognitionScoreCardService::STATUS_BLOCKED,
            'scorecard_status_point_ready' => AtlasCognitionScoreCardService::STATUS_POINTS[AtlasCognitionScoreCardService::STATUS_READY],
            'delivery_status_passed' => DeliveryPackCompletenessScorer::STATUS_PASSED,
            'delivery_status_needs_review' => DeliveryPackCompletenessScorer::STATUS_NEEDS_REVIEW,
            'delivery_status_failed' => DeliveryPackCompletenessScorer::STATUS_FAILED,
            'delivery_status_count' => count(DeliveryPackCompletenessScorer::STATUSES),
            'blocker_signal_blocked' => AaeosBlockerSeverityGate::SIGNAL_BLOCKED,
            'blocker_signal_warning' => AaeosBlockerSeverityGate::SIGNAL_WARNING,
            'blocker_signal_clear' => AaeosBlockerSeverityGate::SIGNAL_CLEAR,
            'blocker_signal_count' => count(AaeosBlockerSeverityGate::SIGNALS),
            'memory_fabric_trigger_operator' => AtlasCognitiveMemoryFabricSchemaEvolutionService::TRIGGER_OPERATOR,
            'memory_fabric_trigger_frontmatter_drift' => AtlasCognitiveMemoryFabricSchemaEvolutionService::TRIGGER_FRONTMATTER_DRIFT,
            'memory_fabric_trigger_extension_pressure' => AtlasCognitiveMemoryFabricSchemaEvolutionService::TRIGGER_EXTENSION_PRESSURE,
            'memory_fabric_trigger_count' => count(AtlasCognitiveMemoryFabricSchemaEvolutionService::VALID_TRIGGERS),
        ];
    }

    /**
     * Observe-only gate-signal saturation + evolution/skill/immune-freeze floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function gateEvolutionSkillFreezeContractObserve(array $input = []): array
    {
        return [
            'gate_signal_schema' => AtlasGateSignalEvaluator::SCHEMA_VERSION,
            'ambiguity_saturation' => AtlasGateSignalEvaluator::AMBIGUITY_SATURATION,
            'missing_saturation' => AtlasGateSignalEvaluator::MISSING_SATURATION,
            'evolution_score_schema' => AtlasAcosEvolutionScoreService::SCHEMA_VERSION,
            'tier_chain_class' => AtlasAcosEvolutionScoreService::TIER_CHAIN_CLASS,
            'reversal_command_class' => AtlasAcosEvolutionScoreService::REVERSAL_COMMAND_CLASS,
            'procedural_skill_schema' => AcosMaxProceduralSkillPromoterService::SCHEMA_VERSION,
            'procedural_skill_skill_schema' => AcosMaxProceduralSkillPromoterService::SKILL_SCHEMA_VERSION,
            'immune_signature_freeze_measure_id' => AtlasImmuneSignatureFreeze::MEASURE_ID,
            'immune_signature_freeze_ttl_days' => AtlasImmuneSignatureFreeze::TTL_DAYS,
            'immune_signature_store_schema' => ImmuneSignatureStore::SCHEMA_VERSION,
            'immune_signature_deriver_schema' => ImmuneSignatureDeriver::SCHEMA_VERSION,
        ];
    }

    /**
     * Observe-only residual schema/ledger/weights floors across allowlisted cores.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function residualSchemaLedgerContractObserve(array $input = []): array
    {
        return [
            'spec_completeness_weights' => SpecCompletenessScorer::WEIGHTS,
            'spec_completeness_weight_count' => count(SpecCompletenessScorer::WEIGHTS),
            'spec_completeness_weight_sum' => array_sum(SpecCompletenessScorer::WEIGHTS),
            'quality_bar_canonical_source' => QualityBarTelemetryContract::CANONICAL_SOURCE,
            'immune_signature_table' => ImmuneSignatureStore::TABLE,
            'evidence_ledger_integrity_schema' => EvidenceLedgerIntegrityWatchdogCheck::SCHEMA_VERSION,
            'evidence_ledger_integrity_default_path' => EvidenceLedgerIntegrityWatchdogCheck::DEFAULT_LEDGER_RELATIVE_PATH,
            'dogfooding_friction_schema' => DogfoodingFrictionLeadMiner::SCHEMA_VERSION,
            'belief_cascade_schema' => BeliefCascadeReverificationPlanner::SCHEMA_VERSION,
            'operational_volume_prerequisite_gap' => AtlasOperationalVolumeCheckService::PREREQUISITE_GAP_HERMES_01,
            'obra_retro_series_tag' => AcosMaxObraRetroService::SERIES_TAG,
            'required_gate_coverage_schema' => AaeosRequiredGateCoverageChecker::SCHEMA_VERSION,
        ];
    }

    /**
     * Observe-only newly published floors for previously unwired watchdog checks.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function unwiredWatchdogChecksContractObserve(array $input = []): array
    {
        return [
            'dead_series_schema' => AcosDeadSeriesWatchdogCheck::SCHEMA_VERSION,
            'dead_series_check_id' => AcosDeadSeriesWatchdogCheck::CHECK_ID,
            'aobg_latency_schema' => AobgLatencyWatchdogCheck::SCHEMA_VERSION,
            'aobg_latency_check_id' => AobgLatencyWatchdogCheck::CHECK_ID,
            'aobg_latency_default_measure_id' => AobgLatencyWatchdogCheck::DEFAULT_MEASURE_ID,
            'aobg_latency_default_denominator_min' => AobgLatencyWatchdogCheck::DEFAULT_DENOMINATOR_MIN,
            'aobg_latency_pack_p95_ms_alert' => AobgLatencyWatchdogCheck::DEFAULT_PACK_P95_MS_ALERT,
            'aobg_latency_recall_p95_ms_alert' => AobgLatencyWatchdogCheck::DEFAULT_RECALL_P95_MS_ALERT,
            'aobg_latency_hook_p95_ms_alert' => AobgLatencyWatchdogCheck::DEFAULT_HOOK_P95_MS_ALERT,
            'disk_free_schema' => DiskFreeWatchdogCheck::SCHEMA_VERSION,
            'disk_free_check_id' => DiskFreeWatchdogCheck::CHECK_ID,
            'disk_free_default_floor_gb' => DiskFreeWatchdogCheck::DEFAULT_FLOOR_GB,
            'joint_resource_budget_schema' => JointResourceBudgetWatchdogCheck::SCHEMA_VERSION,
            'joint_resource_budget_check_id' => JointResourceBudgetWatchdogCheck::CHECK_ID,
            'local_model_integrity_schema' => LocalModelIntegrityWatchdogCheck::SCHEMA_VERSION,
            'local_model_integrity_check_id' => LocalModelIntegrityWatchdogCheck::CHECK_ID,
            'operator_review_debt_schema' => OperatorReviewDebtWatchdogCheck::SCHEMA_VERSION,
            'operator_review_debt_check_id' => OperatorReviewDebtWatchdogCheck::CHECK_ID,
            'provider_bound_redaction_schema' => ProviderBoundRedactionDriftWatchdogCheck::SCHEMA_VERSION,
            'provider_bound_redaction_check_id' => ProviderBoundRedactionDriftWatchdogCheck::CHECK_ID,
            'provider_bound_redaction_sample_limit' => ProviderBoundRedactionDriftWatchdogCheck::SAMPLE_LIMIT,
            'substrate_restore_schema' => SubstrateRestoreDrillWatchdogCheck::SCHEMA_VERSION,
            'substrate_restore_check_id' => SubstrateRestoreDrillWatchdogCheck::CHECK_ID,
            'substrate_restore_default_max_success_age_days' => SubstrateRestoreDrillWatchdogCheck::DEFAULT_MAX_SUCCESS_AGE_DAYS,
            'compaction_recovery_check_id' => CompactionRecoverySampleWatchdogCheck::CHECK_ID,
            'compaction_recovery_default_limit' => CompactionRecoverySampleWatchdogCheck::DEFAULT_LIMIT,
            'compaction_recovery_default_days' => CompactionRecoverySampleWatchdogCheck::DEFAULT_DAYS,
            'compaction_recovery_default_min_receipts' => CompactionRecoverySampleWatchdogCheck::DEFAULT_MIN_RECEIPTS,
            'operator_learning_capture_check_id' => OperatorLearningCaptureSchemaWatchdogCheck::CHECK_ID,
            'health_report_catalog' => HealthReportWatchdogCheck::CATALOG,
            'health_report_catalog_count' => count(HealthReportWatchdogCheck::CATALOG),
        ];
    }

    /**
     * Observe-only watchdog-runner aggregate statuses + autonomy-ladder check floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function watchdogRunnerAutonomyLadderContractObserve(array $input = []): array
    {
        return [
            'watchdog_runner_schema' => AtlasWatchdogRunner::SCHEMA_VERSION,
            'aggregate_status_alert' => AtlasWatchdogRunner::AGGREGATE_STATUS_ALERT,
            'aggregate_status_warning' => AtlasWatchdogRunner::AGGREGATE_STATUS_WARNING,
            'aggregate_status_healthy' => AtlasWatchdogRunner::AGGREGATE_STATUS_HEALTHY,
            'autonomy_ladder_schema' => AutonomyLadderAdversarialWatchdogCheck::SCHEMA,
            'autonomy_ladder_check_id' => AutonomyLadderAdversarialWatchdogCheck::CHECK_ID,
            'promotion_protocol_schema' => PromotionProtocol::SCHEMA,
            'promotion_protocol_report_schema' => PromotionProtocol::REPORT_SCHEMA,
            'promotion_protocol_state_count' => count(PromotionProtocol::STATES),
            'promotion_protocol_required_field_count' => count(PromotionProtocol::REQUIRED_FIELDS),
        ];
    }

    /**
     * Observe-only ESP-06 outcome envelope adapter kinds.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function outcomeEnvelopeAdaptersContractObserve(array $input = []): array
    {
        return [
            'aemor_adapter_kind' => AemorOutcomeEnvelopeAdapter::ADAPTER_KIND,
            'dev_procedural_adapter_kind' => DevProceduralOutcomeEnvelopeAdapter::ADAPTER_KIND,
            'dev_procedural_native_schema' => DevProceduralOutcomeEnvelopeAdapter::NATIVE_SCHEMA_VERSION,
            'compounding_adapter_kind' => CompoundingOutcomeEnvelopeAdapter::ADAPTER_KIND,
            'window_orchestrator_schema' => AcosMaxWindowOrchestratorService::SCHEMA_VERSION,
            'long_horizon_gate_schema' => AtlasAcosLongHorizonGateService::SCHEMA_VERSION,
            'adapter_kind_count' => 3,
        ];
    }

    /**
     * Observe-only implementation-truth ranks + capture-hmac stage floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function implementationTruthRankContractObserve(array $input = []): array
    {
        return [
            'implementation_truth_schema' => AtlasImplementationTruthService::SCHEMA,
            'implementation_truth_ledger_schema' => AtlasImplementationTruthService::LEDGER_SCHEMA,
            'implementation_truth_hash_format' => AtlasImplementationTruthService::IMPL_FILES_HASH_FORMAT,
            'rank_spec' => AtlasImplementationTruthService::RANK['spec'],
            'rank_partial' => AtlasImplementationTruthService::RANK['partial'],
            'rank_verified' => AtlasImplementationTruthService::RANK['verified'],
            'rank_count' => count(AtlasImplementationTruthService::RANK),
            'capture_hmac_schema' => CaptureHmacLineageService::SCHEMA_VERSION,
            'capture_hmac_stage_source' => CaptureHmacLineageService::STAGE_SOURCE,
            'capture_hmac_stage_capture' => CaptureHmacLineageService::STAGE_CAPTURE,
            'capture_hmac_stage_memory' => CaptureHmacLineageService::STAGE_MEMORY,
            'rollback_trigger_schema' => AtlasAcosRollbackTriggerCheckService::SCHEMA_VERSION,
            'golden_counterfactual_schema' => GoldenCounterfactualReplayService::SCHEMA_VERSION,
            'asef_chunk_index_schema' => AsefChunkIndexService::SCHEMA_VERSION,
        ];
    }

    /**
     * Observe-only secondary report schema floors (locate/bridge/lote2/calibration/etc).
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function secondaryReportSchemasContractObserve(array $input = []): array
    {
        return [
            'generated_contract_gate_schema' => AeosGeneratedContractGate::SCHEMA_VERSION,
            'docs_locate_schema' => AtlasDocsAuthorityGraphService::LOCATE_SCHEMA,
            'docs_authority_schema' => AtlasDocsAuthorityGraphService::SCHEMA_VERSION,
            'outcome_envelope_bridge_schema' => OutcomeEnvelopeBridge::BRIDGE_SCHEMA,
            'outcome_envelope_bridge_measure_id' => OutcomeEnvelopeBridge::MEASURE_ID,
            'lote2_measure_report_schema' => AcosMaxLote2MeasureService::REPORT_SCHEMA,
            'pre_review_calibration_schema' => PreReviewAdvisoryBand::CALIBRATION_SCHEMA,
            'pre_review_advisory_schema' => PreReviewAdvisoryBand::SCHEMA_VERSION,
            'doc_runtime_coverage_schema' => AtlasImplementationTruthService::DOC_RUNTIME_COVERAGE_SCHEMA,
            'model_integrity_manifest_schema' => AtlasLocalModelIntegrityService::MANIFEST_SCHEMA,
            'secondary_report_schema_count' => 7,
        ];
    }

    /**
     * Observe-only department IO/evidence schema floors published on DepartmentContractRuntime.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function departmentIoSchemasContractObserve(array $input = []): array
    {
        $ioSchemas = [];
        foreach ((new \ReflectionClass(DepartmentContractRuntime::class))->getConstants() as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                continue;
            }
            if (! str_starts_with($name, 'SCHEMA_') || $name === 'SCHEMA_VERSION') {
                continue;
            }
            $ioSchemas[$name] = $value;
        }
        ksort($ioSchemas);

        return [
            'department_runtime_schema' => DepartmentContractRuntime::SCHEMA_VERSION,
            'department_io_schema_count' => count($ioSchemas),
            'schema_intent_raw' => DepartmentContractRuntime::SCHEMA_INTENT_RAW,
            'schema_ai_mission' => DepartmentContractRuntime::SCHEMA_AI_MISSION,
            'schema_engineering_goal' => DepartmentContractRuntime::SCHEMA_ENGINEERING_GOAL,
            'schema_spec_pack' => DepartmentContractRuntime::SCHEMA_SPEC_PACK,
            'schema_task_pack' => DepartmentContractRuntime::SCHEMA_TASK_PACK,
            'schema_patch_pack' => DepartmentContractRuntime::SCHEMA_PATCH_PACK,
            'schema_test_pack' => DepartmentContractRuntime::SCHEMA_TEST_PACK,
            'schema_review_report' => DepartmentContractRuntime::SCHEMA_REVIEW_REPORT,
            'schema_security_finding' => DepartmentContractRuntime::SCHEMA_SECURITY_FINDING,
            'schema_delivery_pack' => DepartmentContractRuntime::SCHEMA_DELIVERY_PACK,
            'schema_memory_record' => DepartmentContractRuntime::SCHEMA_MEMORY_RECORD,
            'schema_learning_capsule' => DepartmentContractRuntime::SCHEMA_LEARNING_CAPSULE,
            'evidence_schema_executive_intake' => DepartmentContractRuntime::CATALOGUE[DepartmentContractRuntime::DEPARTMENT_EXECUTIVE_INTAKE]['evidence_schema'],
            'evidence_schema_dev' => DepartmentContractRuntime::CATALOGUE[DepartmentContractRuntime::DEPARTMENT_DEV]['evidence_schema'],
            'evidence_schema_memory' => DepartmentContractRuntime::CATALOGUE[DepartmentContractRuntime::DEPARTMENT_MEMORY]['evidence_schema'],
        ];
    }

    /**
     * Observe-only docs-authority confidence key floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function docsAuthorityConfidenceKeysContractObserve(array $input = []): array
    {
        $confidence = AtlasDocsAuthorityGraphService::CONFIDENCE;

        return [
            'docs_authority_schema' => AtlasDocsAuthorityGraphService::SCHEMA_VERSION,
            'docs_locate_schema' => AtlasDocsAuthorityGraphService::LOCATE_SCHEMA,
            'confidence_governs_frontmatter' => $confidence['governs_frontmatter'],
            'confidence_doc_id' => $confidence['doc_id'],
            'confidence_capability_frontmatter' => $confidence['capability_frontmatter'],
            'confidence_keyword_fallback' => $confidence['keyword_fallback'],
            'confidence_basis_count' => count($confidence),
            'confidence_max' => max($confidence),
            'confidence_min' => min($confidence),
        ];
    }

    /**
     * Observe-only MAXA-04 embedding dual-read floors + promotion eligibility defaults.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function maxa04PromotionFloorsContractObserve(array $input = []): array
    {
        return [
            'maxa04_candidate_model' => Maxa04JinaV3DualReadService::CANDIDATE_MODEL,
            'maxa04_candidate_dimensions' => Maxa04JinaV3DualReadService::CANDIDATE_DIMENSIONS,
            'maxa04_pending_window' => Maxa04JinaV3DualReadService::PENDING_WINDOW,
            'maxa04_current_model_fallback' => Maxa04JinaV3DualReadService::CURRENT_MODEL_FALLBACK,
            'maxa04_ledger_schema' => Maxa04JinaV3DualReadLedger::SCHEMA,
            'maxa04_ledger_relative_path' => Maxa04JinaV3DualReadLedger::RELATIVE_PATH,
            'promotion_eligibility_schema' => AtlasDepartmentPromotionEligibilityEvaluator::SCHEMA_VERSION,
            'promotion_max_evidence_age_days' => AtlasDepartmentPromotionEligibilityEvaluator::DEFAULT_MAX_EVIDENCE_AGE_DAYS,
            'promotion_max_tier' => AtlasDepartmentPromotionEligibilityEvaluator::DEFAULT_MAX_TIER,
            'memory_fabric_proposal_schema' => AtlasCognitiveMemoryFabricSchemaEvolutionService::PROPOSAL_SCHEMA,
            'memory_fabric_ticket_schema' => AtlasCognitiveMemoryFabricSchemaEvolutionService::TICKET_SCHEMA,
            'memory_fabric_extension_pressure_threshold' => AtlasCognitiveMemoryFabricSchemaEvolutionService::EXTENSION_PRESSURE_THRESHOLD,
            'maxa04_promotion_floor_count' => 12,
        ];
    }

    /**
     * Observe-only composed-obra + evidence-vision lifecycle floors (int-normalization peel surface).
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function composedObraLifecycleFloorsContractObserve(array $input = []): array
    {
        return [
            'composer_schema' => ComposedObraArcComposer::SCHEMA_VERSION,
            'lifecycle_schema' => ComposedObraArcLifecycle::SCHEMA_VERSION,
            'min_neighbor_candidates' => ComposedObraArcComposer::MIN_NEIGHBOR_CANDIDATES,
            'kill_gate_consecutive_failures' => ComposedObraArcComposer::KILL_GATE_CONSECUTIVE_FAILURES,
            'default_author_engine_id' => ComposedObraArcComposer::DEFAULT_AUTHOR_ENGINE_ID,
            'default_judge_engine_id' => ComposedObraArcComposer::DEFAULT_JUDGE_ENGINE_ID,
            'evidence_vision_composer_schema' => EvidenceVisionThesisComposer::SCHEMA_VERSION,
            'evidence_vision_lifecycle_schema' => EvidenceVisionThesisLifecycle::SCHEMA_VERSION,
            'max_theses' => EvidenceVisionThesisComposer::MAX_THESES,
            'min_regression_windows' => EvidenceVisionThesisComposer::MIN_REGRESSION_WINDOWS,
            'default_ttl_days' => EvidenceVisionThesisComposer::DEFAULT_TTL_DAYS,
            'allowed_evidence_sources' => EvidenceVisionThesisComposer::ALLOWED_EVIDENCE_SOURCES,
            'window_orchestrator_schema' => AcosMaxWindowOrchestratorService::SCHEMA_VERSION,
            'composed_obra_lifecycle_floor_count' => 13,
        ];
    }

    /**
     * Observe-only resource-budget host/engine floor defaults (ELEV-27 paper budget).
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function resourceBudgetHostFloorsContractObserve(array $input = []): array
    {
        return [
            'resource_budget_schema' => AtlasResourceBudgetService::SCHEMA,
            'default_host_ram_gib' => AtlasResourceBudgetService::DEFAULT_HOST_RAM_GIB,
            'default_engine_floor_gib' => AtlasResourceBudgetService::DEFAULT_ENGINE_FLOOR_GIB,
            'aobg_latency_default_denominator_min' => AobgLatencyWatchdogCheck::DEFAULT_DENOMINATOR_MIN,
            'aobg_latency_pack_p95_ms_alert' => AobgLatencyWatchdogCheck::DEFAULT_PACK_P95_MS_ALERT,
            'aobg_latency_recall_p95_ms_alert' => AobgLatencyWatchdogCheck::DEFAULT_RECALL_P95_MS_ALERT,
            'aobg_latency_hook_p95_ms_alert' => AobgLatencyWatchdogCheck::DEFAULT_HOOK_P95_MS_ALERT,
            'verified_share_schema' => AcosMaxVerifiedShareService::SCHEMA_VERSION,
            'verified_share_measure_id' => AcosMaxVerifiedShareService::MEASURE_ID,
            'verified_share_formula' => AcosMaxVerifiedShareService::FORMULA_VERSION,
            'resource_budget_host_floor_count' => 10,
        ];
    }

    /**
     * Observe-only verified-share + procedural-skill + n-capture published floor defaults.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function verifiedShareProceduralFloorsContractObserve(array $input = []): array
    {
        return [
            'verified_share_min' => AcosMaxVerifiedShareService::DEFAULT_VERIFIED_SHARE_MIN,
            'verified_share_window_days_min' => AcosMaxVerifiedShareService::DEFAULT_WINDOW_DAYS_MIN,
            'verified_share_denominator_min_executions' => AcosMaxVerifiedShareService::DEFAULT_DENOMINATOR_MIN_EXECUTIONS,
            'verified_share_ttl_days' => AcosMaxVerifiedShareService::DEFAULT_TTL_DAYS,
            'procedural_skill_schema' => AcosMaxProceduralSkillPromoterService::SCHEMA_VERSION,
            'procedural_skill_schema_version' => AcosMaxProceduralSkillPromoterService::SKILL_SCHEMA_VERSION,
            'procedural_case_count_floor' => AcosMaxProceduralSkillPromoterService::DEFAULT_CASE_COUNT_FLOOR,
            'n_capture_schema' => AtlasNCaptureDrillService::SCHEMA_VERSION,
            'n_capture_measure_id' => AtlasNCaptureDrillService::MEASURE_ID,
            'n_capture_days_between_drills_max' => AtlasNCaptureDrillService::DEFAULT_DAYS_BETWEEN_DRILLS_MAX,
            'verified_share_procedural_floor_count' => 10,
        ];
    }

    /**
     * Observe-only long-horizon gate + ESP-09 refutation published floor defaults.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function longHorizonGateFloorsContractObserve(array $input = []): array
    {
        return [
            'long_horizon_gate_schema' => AtlasAcosLongHorizonGateService::SCHEMA_VERSION,
            'long_horizon_area_v2_schema' => AtlasAcosLongHorizonGateService::AREA_V2_SCHEMA,
            'long_horizon_min_days' => AtlasAcosLongHorizonGateService::DEFAULT_MIN_DAYS,
            'long_horizon_min_overall' => AtlasAcosLongHorizonGateService::DEFAULT_MIN_OVERALL,
            'long_horizon_min_pipeline' => AtlasAcosLongHorizonGateService::DEFAULT_MIN_PIPELINE,
            'long_horizon_warning_margin' => AtlasAcosLongHorizonGateService::DEFAULT_WARNING_MARGIN,
            'long_horizon_max_latest_stale_days' => AtlasAcosLongHorizonGateService::DEFAULT_MAX_LATEST_STALE_DAYS,
            'long_horizon_max_gap_days' => AtlasAcosLongHorizonGateService::DEFAULT_MAX_GAP_DAYS,
            'esp09_high_alignment_band' => Esp09IndependentChallengerService::HIGH_ALIGNMENT_BAND,
            'esp09_default_min_windows' => Esp09IndependentChallengerService::DEFAULT_MIN_WINDOWS,
            'esp09_default_min_per_window' => Esp09IndependentChallengerService::DEFAULT_MIN_PER_WINDOW,
            'long_horizon_gate_floor_count' => 11,
        ];
    }

    /**
     * Observe-only ledger-rotation + predicted-impact + vision consecutive floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function ledgerRotationImpactFloorsContractObserve(array $input = []): array
    {
        return [
            'ledger_rotation_default_max_size_mb' => AcosMaxLedgerRotationRegistry::DEFAULT_MAX_SIZE_MB,
            'ledger_rotation_default_max_age_days' => AcosMaxLedgerRotationRegistry::DEFAULT_MAX_AGE_DAYS,
            'predicted_impact_schema' => PredictedImpactBand::SCHEMA_VERSION,
            'predicted_impact_default_rank_fallback' => PredictedImpactBand::DEFAULT_RANK_FALLBACK,
            'predicted_impact_rank_top_cutoff' => PredictedImpactBand::RANK_TOP_CUTOFF,
            'predicted_impact_yield_sweet_floor' => PredictedImpactBand::YIELD_SWEET_FLOOR,
            'predicted_impact_high_score_floor' => PredictedImpactBand::HIGH_SCORE_FLOOR,
            'predicted_impact_sweet_score_floor' => PredictedImpactBand::SWEET_SCORE_FLOOR,
            'evidence_vision_lifecycle_schema' => EvidenceVisionThesisLifecycle::SCHEMA_VERSION,
            'evidence_vision_consecutive_windows' => EvidenceVisionThesisLifecycle::DEFAULT_CONSECUTIVE_WINDOWS,
            'ledger_rotation_impact_floor_count' => 10,
        ];
    }

    /**
     * Observe-only helper limit floors (recall-gap / belief-cascade / teto10 / docs-locate).
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function observeHelperLimitFloorsContractObserve(array $input = []): array
    {
        return [
            'recall_gap_schema' => RecallGapAggregator::SCHEMA_VERSION,
            'recall_gap_weak_score_floor' => RecallGapAggregator::WEAK_SCORE_FLOOR,
            'recall_gap_default_min_occurrences' => RecallGapAggregator::DEFAULT_MIN_OCCURRENCES,
            'belief_cascade_schema' => BeliefCascadeReverificationPlanner::SCHEMA_VERSION,
            'belief_cascade_default_depth_cap' => BeliefCascadeReverificationPlanner::DEFAULT_DEPTH_CAP,
            'teto10_schema' => Teto10PredictedRevertReviewDigest::SCHEMA_VERSION,
            'teto10_default_limit' => Teto10PredictedRevertReviewDigest::DEFAULT_LIMIT,
            'teto10_hard_limit_cap' => Teto10PredictedRevertReviewDigest::HARD_LIMIT_CAP,
            'docs_locate_schema' => AtlasDocsAuthorityGraphService::LOCATE_SCHEMA,
            'docs_locate_default_limit' => AtlasDocsAuthorityGraphService::DEFAULT_LOCATE_LIMIT,
            'observe_helper_limit_floor_count' => 10,
        ];
    }

    /**
     * Observe-only outcome-envelope adapter bool field floors + boolOrNull helper.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function outcomeEnvelopeBoolFieldsContractObserve(array $input = []): array
    {
        return [
            'bool_or_null_helper' => 'AiValueNormalizer::boolOrNull',
            'aemor_adapter_kind' => AemorOutcomeEnvelopeAdapter::ADAPTER_KIND,
            'aemor_bool_fields' => AemorOutcomeEnvelopeAdapter::BOOL_FIELDS,
            'compounding_adapter_kind' => CompoundingOutcomeEnvelopeAdapter::ADAPTER_KIND,
            'compounding_bool_fields' => CompoundingOutcomeEnvelopeAdapter::BOOL_FIELDS,
            'dev_procedural_adapter_kind' => DevProceduralOutcomeEnvelopeAdapter::ADAPTER_KIND,
            'dev_procedural_native_schema' => DevProceduralOutcomeEnvelopeAdapter::NATIVE_SCHEMA_VERSION,
            'dev_procedural_bool_fields' => DevProceduralOutcomeEnvelopeAdapter::BOOL_FIELDS,
            'outcome_envelope_bool_field_count' => count(AemorOutcomeEnvelopeAdapter::BOOL_FIELDS)
                + count(CompoundingOutcomeEnvelopeAdapter::BOOL_FIELDS)
                + count(DevProceduralOutcomeEnvelopeAdapter::BOOL_FIELDS),
            'outcome_envelope_bool_fields_floor_count' => 9,
        ];
    }

    /**
     * Observe-only quality-bar + cognitive-atlas + long-horizon enabled floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function qualityBarCognitiveFloorsContractObserve(array $input = []): array
    {
        return [
            'quality_bar_schema' => QualityBarTelemetryContract::QUALITY_BAR_SCHEMA,
            'quality_bar_telemetry_schema' => QualityBarTelemetryContract::SCHEMA,
            'quality_bar_immune_gate_id' => QualityBarTelemetryContract::IMMUNE_GATE_ID,
            'quality_bar_evaluated_window_days' => QualityBarTelemetryContract::EVALUATED_WINDOW_DAYS,
            'quality_bar_auto_block_on_breach' => QualityBarTelemetryContract::AUTO_BLOCK_ON_BREACH,
            'cognitive_function_atlas_overload_threshold' => AtlasCognitiveFunctionAtlasService::OVERLOAD_DEFAULT_THRESHOLD,
            'cognitive_immune_default_gate_status' => CognitiveImmuneCheckContract::DEFAULT_GATE_STATUS,
            'long_horizon_gate_schema' => AtlasAcosLongHorizonGateService::SCHEMA_VERSION,
            'long_horizon_default_enabled' => AtlasAcosLongHorizonGateService::DEFAULT_ENABLED,
            'quality_bar_cognitive_floor_count' => 9,
        ];
    }

    /**
     * Observe-only parallel/substrate/envelope-bridge + procedural enqueue floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function parallelSubstrateBridgeFloorsContractObserve(array $input = []): array
    {
        return [
            'parallel_execution_schema' => AcosMaxParallelExecutionProtocol::SCHEMA,
            'parallel_execution_claim_kind' => AcosMaxParallelExecutionProtocol::CLAIM_KIND,
            'parallel_execution_default_ttl_seconds' => AcosMaxParallelExecutionProtocol::DEFAULT_TTL_SECONDS,
            'substrate_restore_schema' => SubstrateRestoreDrillWatchdogCheck::SCHEMA_VERSION,
            'substrate_restore_default_max_success_age_days' => SubstrateRestoreDrillWatchdogCheck::DEFAULT_MAX_SUCCESS_AGE_DAYS,
            'outcome_envelope_bridge_schema' => OutcomeEnvelopeBridge::BRIDGE_SCHEMA,
            'outcome_envelope_bridge_measure_id' => OutcomeEnvelopeBridge::MEASURE_ID,
            'outcome_envelope_adapters_enabled_config_key' => OutcomeEnvelopeBridge::ADAPTERS_ENABLED_CONFIG_KEY,
            'outcome_envelope_adapters_default_enabled' => OutcomeEnvelopeBridge::DEFAULT_ADAPTERS_ENABLED,
            'procedural_enqueue_enabled_config_key' => AcosMaxProceduralSkillPromoterService::ENQUEUE_ENABLED_CONFIG_KEY,
            'procedural_enqueue_default_enabled' => AcosMaxProceduralSkillPromoterService::DEFAULT_ENQUEUE_ENABLED,
            'parallel_substrate_bridge_floor_count' => 11,
        ];
    }

    /**
     * Observe-only: ops config-toggle floors (http-path / remint / watchdog /
     * rollback / scorecard / generated / evolution / immune) — no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function opsConfigToggleFloorsContractObserve(array $input = []): array
    {
        return [
            'http_path_mission_foundation_optional_config_key' => AtlasAaeosHttpPathFacadeService::MISSION_FOUNDATION_OPTIONAL_CONFIG_KEY,
            'http_path_mission_foundation_optional_default' => AtlasAaeosHttpPathFacadeService::DEFAULT_MISSION_FOUNDATION_OPTIONAL,
            'http_path_placement_cache_ttl_config_key' => AtlasAaeosHttpPathFacadeService::PLACEMENT_CACHE_TTL_CONFIG_KEY,
            'http_path_placement_cache_ttl_default_seconds' => AtlasAaeosHttpPathFacadeService::DEFAULT_PLACEMENT_CACHE_TTL_SECONDS,
            'http_path_telemetry_enabled_config_key' => AtlasAaeosHttpPathFacadeService::TELEMETRY_ENABLED_CONFIG_KEY,
            'http_path_telemetry_enabled_default' => AtlasAaeosHttpPathFacadeService::DEFAULT_TELEMETRY_ENABLED,
            'remint_enabled_config_key' => AtlasCognitionRemintTouchedQueue::ENABLED_CONFIG_KEY,
            'remint_enabled_default' => AtlasCognitionRemintTouchedQueue::DEFAULT_ENABLED,
            'remint_queue_disk_config_key' => AtlasCognitionRemintTouchedQueue::QUEUE_DISK_CONFIG_KEY,
            'remint_queue_disk_default' => AtlasCognitionRemintTouchedQueue::DEFAULT_QUEUE_DISK,
            'remint_queue_path_config_key' => AtlasCognitionRemintTouchedQueue::QUEUE_PATH_CONFIG_KEY,
            'remint_queue_path_default' => AtlasCognitionRemintTouchedQueue::DEFAULT_QUEUE_PATH,
            'watchdog_recall_concentration_demotion_enabled_config_key' => AtlasAcosWatchdogHealthService::RECALL_CONCENTRATION_DEMOTION_ENABLED_CONFIG_KEY,
            'watchdog_recall_concentration_demotion_enabled_default' => AtlasAcosWatchdogHealthService::DEFAULT_RECALL_CONCENTRATION_DEMOTION_ENABLED,
            'watchdog_adml_cost_outcome_enabled_config_key' => AtlasAcosWatchdogHealthService::ADML_COST_OUTCOME_ENABLED_CONFIG_KEY,
            'watchdog_adml_cost_outcome_enabled_default' => AtlasAcosWatchdogHealthService::DEFAULT_ADML_COST_OUTCOME_ENABLED,
            'rollback_triggers_enabled_config_key' => AtlasAcosRollbackTriggerCheckService::ENABLED_CONFIG_KEY,
            'rollback_triggers_enabled_default' => AtlasAcosRollbackTriggerCheckService::DEFAULT_ENABLED,
            'rollback_triggers_flips_config_key' => AtlasAcosRollbackTriggerCheckService::FLIPS_CONFIG_KEY,
            'scorecard_dual_emit_v3_config_key' => AtlasCognitionScoreCardService::DUAL_EMIT_V3_CONFIG_KEY,
            'scorecard_dual_emit_v3_default' => AtlasCognitionScoreCardService::DEFAULT_DUAL_EMIT_V3,
            'generated_hot_path_enabled_config_key' => AeosGeneratedContractGate::HOT_PATH_ENABLED_CONFIG_KEY,
            'generated_hot_path_enabled_default' => AeosGeneratedContractGate::DEFAULT_HOT_PATH_ENABLED,
            'generated_quarantine_namespace_config_key' => AeosGeneratedContractGate::QUARANTINE_NAMESPACE_CONFIG_KEY,
            'evolution_global_hints_enabled_config_key' => AtlasAcosEvolutionScoreService::GLOBAL_HINTS_ENABLED_CONFIG_KEY,
            'evolution_global_hints_enabled_default' => AtlasAcosEvolutionScoreService::DEFAULT_GLOBAL_HINTS_ENABLED,
            'immune_semantic_arm_enabled_config_key' => AtlasImmuneHybridInputClassifier::SEMANTIC_ARM_ENABLED_CONFIG_KEY,
            'immune_semantic_arm_enabled_default' => AtlasImmuneHybridInputClassifier::DEFAULT_SEMANTIC_ARM_ENABLED,
            'ops_config_toggle_floor_count' => 28,
        ];
    }

    /**
     * Observe-only: RAGX mechanism flags + substrate/immune/surprise/compaction
     * config floors — no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function ragxImmuneSubstrateConfigFloorsContractObserve(array $input = []): array
    {
        return [
            'ragx_flag_late_chunk_index' => RagxChainMechanismService::FLAG_LATE_CHUNK_INDEX,
            'ragx_flag_late_chunk_maxa04_promoted' => RagxChainMechanismService::FLAG_LATE_CHUNK_MAXA04_PROMOTED,
            'ragx_flag_adaptive_k' => RagxChainMechanismService::FLAG_ADAPTIVE_K,
            'ragx_flag_sparse_fallback' => RagxChainMechanismService::FLAG_SPARSE_FALLBACK,
            'ragx_flag_ab_registrar' => RagxChainMechanismService::FLAG_AB_REGISTRAR,
            'ragx_flag_louvain_chunks' => RagxChainMechanismService::FLAG_LOUVAIN_CHUNKS,
            'ragx_flag_maxa06_fase2_backfilled' => RagxChainMechanismService::FLAG_MAXA06_FASE2_BACKFILLED,
            'ragx_flag_raptor_lite' => RagxChainMechanismService::FLAG_RAPTOR_LITE,
            'substrate_receipt_path_config_key' => SubstrateRestoreDrillWatchdogCheck::RECEIPT_PATH_CONFIG_KEY,
            'substrate_default_receipt_relative_path' => SubstrateRestoreDrillWatchdogCheck::DEFAULT_RECEIPT_RELATIVE_PATH,
            'substrate_max_success_age_days_config_key' => SubstrateRestoreDrillWatchdogCheck::MAX_SUCCESS_AGE_DAYS_CONFIG_KEY,
            'immune_signature_decay_days_config_key' => ImmuneSignatureStore::DECAY_DAYS_CONFIG_KEY,
            'immune_signature_default_decay_days' => ImmuneSignatureStore::DEFAULT_DECAY_DAYS,
            'immune_signature_mode_config_key' => ImmuneSignatureStore::MODE_CONFIG_KEY,
            'immune_signature_default_mode' => ImmuneSignatureStore::DEFAULT_MODE,
            'surprise_threshold_config_key' => AtlasSurpriseGateService::THRESHOLD_CONFIG_KEY,
            'surprise_high_band_config_key' => AtlasSurpriseGateService::HIGH_BAND_CONFIG_KEY,
            'surprise_min_prediction_tokens_config_key' => AtlasSurpriseGateService::MIN_PREDICTION_TOKENS_CONFIG_KEY,
            'surprise_default_threshold' => AtlasSurpriseGateService::DEFAULT_THRESHOLD,
            'surprise_default_high_band' => AtlasSurpriseGateService::DEFAULT_HIGH_BAND,
            'surprise_default_min_prediction_tokens' => AtlasSurpriseGateService::DEFAULT_MIN_PREDICTION_TOKENS,
            'compaction_recovery_limit_config_key' => CompactionRecoverySampleWatchdogCheck::LIMIT_CONFIG_KEY,
            'compaction_recovery_days_config_key' => CompactionRecoverySampleWatchdogCheck::DAYS_CONFIG_KEY,
            'compaction_recovery_min_receipts_config_key' => CompactionRecoverySampleWatchdogCheck::MIN_RECEIPTS_CONFIG_KEY,
            'compaction_recovery_default_limit' => CompactionRecoverySampleWatchdogCheck::DEFAULT_LIMIT,
            'compaction_recovery_default_days' => CompactionRecoverySampleWatchdogCheck::DEFAULT_DAYS,
            'compaction_recovery_default_min_receipts' => CompactionRecoverySampleWatchdogCheck::DEFAULT_MIN_RECEIPTS,
            'ragx_immune_substrate_config_floor_count' => 27,
        ];
    }

    /**
     * Observe-only: residual RAGX seam flags + phase/disk/hmac/long-horizon/
     * model-budget config floors — no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function residualOpsConfigFloorsContractObserve(array $input = []): array
    {
        return [
            'ragx_flag_facet_retrieval' => RagxChainMechanismService::FLAG_FACET_RETRIEVAL,
            'ragx_flag_fusion_enabled' => RagxChainMechanismService::FLAG_FUSION_ENABLED,
            'ragx_flag_cross_encoder_rerank' => RagxChainMechanismService::FLAG_CROSS_ENCODER_RERANK,
            'http_path_phase_config_key' => AtlasPhaseRouterService::HTTP_PATH_PHASE_CONFIG_KEY,
            'http_path_phase_legacy' => AtlasPhaseRouterService::PHASE_LEGACY,
            'disk_free_floor_gb_config_key' => DiskFreeWatchdogCheck::FLOOR_GB_CONFIG_KEY,
            'disk_free_default_floor_gb' => DiskFreeWatchdogCheck::DEFAULT_FLOOR_GB,
            'long_horizon_gate_config_key' => AtlasAcosLongHorizonGateService::CONFIG_KEY,
            'long_horizon_gate_default_enabled' => AtlasAcosLongHorizonGateService::DEFAULT_ENABLED,
            'capture_hmac_secret_config_key' => CaptureHmacLineageService::SECRET_CONFIG_KEY,
            'capture_hmac_app_key_config_key' => CaptureHmacLineageService::APP_KEY_CONFIG_KEY,
            'capture_hmac_key_material_label' => CaptureHmacLineageService::KEY_MATERIAL_LABEL,
            'capture_hmac_key_material_fallback' => CaptureHmacLineageService::KEY_MATERIAL_FALLBACK,
            'maxa04_ledger_path_config_key' => Maxa04JinaV3DualReadLedger::LEDGER_PATH_CONFIG_KEY,
            'maxa04_ledger_relative_path' => Maxa04JinaV3DualReadLedger::RELATIVE_PATH,
            'model_capability_spec_config_key' => AtlasModelCapabilitySpecService::SPEC_CONFIG_KEY,
            'resource_budget_config_key' => AtlasResourceBudgetService::BUDGET_CONFIG_KEY,
            'local_model_manifest_config_key' => AtlasLocalModelIntegrityService::MANIFEST_CONFIG_KEY,
            'residual_ops_config_floor_count' => 18,
        ];
    }

    /**
     * Observe-only: RAGX stage/mechanism/pending-window floors + evolution
     * evidence path floors — no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function ragxStageMechanismFloorsContractObserve(array $input = []): array
    {
        return [
            'ragx_stage_ragx_01' => RagxChainMechanismService::STAGE_RAGX_01,
            'ragx_stage_ragx_02' => RagxChainMechanismService::STAGE_RAGX_02,
            'ragx_stage_ragx_03' => RagxChainMechanismService::STAGE_RAGX_03,
            'ragx_stage_ragx_05' => RagxChainMechanismService::STAGE_RAGX_05,
            'ragx_stage_ragx_06' => RagxChainMechanismService::STAGE_RAGX_06,
            'ragx_stage_ragx_07' => RagxChainMechanismService::STAGE_RAGX_07,
            'ragx_stage_ragx_10' => RagxChainMechanismService::STAGE_RAGX_10,
            'ragx_stage_ragx_11' => RagxChainMechanismService::STAGE_RAGX_11,
            'ragx_stage_maxd_05' => RagxChainMechanismService::STAGE_MAXD_05,
            'ragx_mechanism_late_chunk' => RagxChainMechanismService::MECHANISM_LATE_CHUNK,
            'ragx_mechanism_facet_retrieval' => RagxChainMechanismService::MECHANISM_FACET_RETRIEVAL,
            'ragx_mechanism_fusion' => RagxChainMechanismService::MECHANISM_FUSION,
            'ragx_mechanism_cross_encoder' => RagxChainMechanismService::MECHANISM_CROSS_ENCODER,
            'ragx_mechanism_adaptive_k' => RagxChainMechanismService::MECHANISM_ADAPTIVE_K,
            'ragx_mechanism_sparse_fallback' => RagxChainMechanismService::MECHANISM_SPARSE_FALLBACK,
            'ragx_mechanism_ab_registrar' => RagxChainMechanismService::MECHANISM_AB_REGISTRAR,
            'ragx_mechanism_louvain' => RagxChainMechanismService::MECHANISM_LOUVAIN,
            'ragx_mechanism_raptor_lite' => RagxChainMechanismService::MECHANISM_RAPTOR_LITE,
            'ragx_pending_jina_v3_dual_read' => RagxChainMechanismService::PENDING_JINA_V3_DUAL_READ,
            'ragx_pending_facet_retrieval_ab' => RagxChainMechanismService::PENDING_FACET_RETRIEVAL_AB,
            'ragx_pending_fusion_shadow_ab' => RagxChainMechanismService::PENDING_FUSION_SHADOW_AB,
            'ragx_pending_rerank_precision3' => RagxChainMechanismService::PENDING_RERANK_PRECISION3,
            'ragx_pending_late_chunk_score_dist' => RagxChainMechanismService::PENDING_LATE_CHUNK_SCORE_DIST,
            'ragx_pending_dense_vs_sparse' => RagxChainMechanismService::PENDING_DENSE_VS_SPARSE,
            'ragx_pending_golden_v2_or_live' => RagxChainMechanismService::PENDING_GOLDEN_V2_OR_LIVE,
            'ragx_pending_maxa06_fase2_backfill' => RagxChainMechanismService::PENDING_MAXA06_FASE2_BACKFILL,
            'ragx_pending_raptor_lite_summary' => RagxChainMechanismService::PENDING_RAPTOR_LITE_SUMMARY,
            'ragx_blocker_maxa04' => RagxChainMechanismService::BLOCKER_MAXA04,
            'ragx_blocker_maxa06_fase2' => RagxChainMechanismService::BLOCKER_MAXA06_FASE2,
            'ragx_blocker_maxf09' => RagxChainMechanismService::BLOCKER_MAXF09,
            'ragx_adaptive_k_score_gap_floor' => RagxChainMechanismService::ADAPTIVE_K_SCORE_GAP_FLOOR,
            'evolution_long_horizon_gate_evidence_relative' => AtlasAcosEvolutionScoreService::LONG_HORIZON_GATE_EVIDENCE_RELATIVE,
            'evolution_delta_series_evidence_relative' => AtlasAcosEvolutionScoreService::DELTA_SERIES_EVIDENCE_RELATIVE,
            'evolution_scheduler_heartbeat_relative' => AtlasAcosEvolutionScoreService::SCHEDULER_HEARTBEAT_RELATIVE,
            'ragx_stage_mechanism_floor_count' => 34,
        ];
    }

    /**
     * Observe-only: department extended IO schemas + procedural promoter
     * status/reason/admission floors — no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function departmentExtendedIoProceduralFloorsContractObserve(array $input = []): array
    {
        return [
            'schema_acceptance_criteria' => DepartmentContractRuntime::SCHEMA_ACCEPTANCE_CRITERIA,
            'schema_context_pack' => DepartmentContractRuntime::SCHEMA_CONTEXT_PACK,
            'schema_dev_debug_receipt' => DepartmentContractRuntime::SCHEMA_DEV_DEBUG_RECEIPT,
            'schema_dev_mini_programming_spec' => DepartmentContractRuntime::SCHEMA_DEV_MINI_PROGRAMMING_SPEC,
            'schema_dev_plan_visible' => DepartmentContractRuntime::SCHEMA_DEV_PLAN_VISIBLE,
            'schema_dev_review_receipt' => DepartmentContractRuntime::SCHEMA_DEV_REVIEW_RECEIPT,
            'schema_dev_test_selection_receipt' => DepartmentContractRuntime::SCHEMA_DEV_TEST_SELECTION_RECEIPT,
            'schema_engineering_architecture_decision' => DepartmentContractRuntime::SCHEMA_ENGINEERING_ARCHITECTURE_DECISION,
            'schema_engineering_release_decision' => DepartmentContractRuntime::SCHEMA_ENGINEERING_RELEASE_DECISION,
            'schema_engineering_goal_disambiguated' => DepartmentContractRuntime::SCHEMA_ENGINEERING_GOAL_DISAMBIGUATED,
            'schema_evidence_pack' => DepartmentContractRuntime::SCHEMA_EVIDENCE_PACK,
            'schema_execution_log' => DepartmentContractRuntime::SCHEMA_EXECUTION_LOG,
            'schema_failure_report' => DepartmentContractRuntime::SCHEMA_FAILURE_REPORT,
            'schema_learning_compounding_signal' => DepartmentContractRuntime::SCHEMA_LEARNING_COMPOUNDING_SIGNAL,
            'schema_migration_plan' => DepartmentContractRuntime::SCHEMA_MIGRATION_PLAN,
            'schema_obra_pack' => DepartmentContractRuntime::SCHEMA_OBRA_PACK,
            'schema_policy_decision' => DepartmentContractRuntime::SCHEMA_POLICY_DECISION,
            'schema_policy_request' => DepartmentContractRuntime::SCHEMA_POLICY_REQUEST,
            'schema_programming_durable_execution_handoff' => DepartmentContractRuntime::SCHEMA_PROGRAMMING_DURABLE_EXECUTION_HANDOFF,
            'schema_research_findings' => DepartmentContractRuntime::SCHEMA_RESEARCH_FINDINGS,
            'schema_research_pack' => DepartmentContractRuntime::SCHEMA_RESEARCH_PACK,
            'schema_research_question' => DepartmentContractRuntime::SCHEMA_RESEARCH_QUESTION,
            'schema_root_cause_pack' => DepartmentContractRuntime::SCHEMA_ROOT_CAUSE_PACK,
            'schema_topology_plan' => DepartmentContractRuntime::SCHEMA_TOPOLOGY_PLAN,
            'procedural_slice_multj04' => AcosMaxProceduralSkillPromoterService::SLICE_MULTJ04,
            'procedural_status_ok' => AcosMaxProceduralSkillPromoterService::STATUS_OK,
            'procedural_status_pending_window' => AcosMaxProceduralSkillPromoterService::STATUS_PENDING_WINDOW,
            'procedural_status_hold' => AcosMaxProceduralSkillPromoterService::STATUS_HOLD,
            'procedural_status_hold_for_asi02' => AcosMaxProceduralSkillPromoterService::STATUS_HOLD_FOR_ASI02,
            'procedural_reason_case_count_soak' => AcosMaxProceduralSkillPromoterService::REASON_PROCEDURAL_CASE_COUNT_SOAK,
            'procedural_reason_awaiting_asi02_admission' => AcosMaxProceduralSkillPromoterService::REASON_AWAITING_ASI02_ADMISSION,
            'procedural_admission_door_asi02' => AcosMaxProceduralSkillPromoterService::ADMISSION_DOOR_ASI02,
            'procedural_queue_ai_learning_candidates' => AcosMaxProceduralSkillPromoterService::QUEUE_AI_LEARNING_CANDIDATES,
            'procedural_scoreboard_landed_mechanism' => AcosMaxProceduralSkillPromoterService::SCOREBOARD_LANDED_MECHANISM,
            'procedural_default_case_count_floor' => AcosMaxProceduralSkillPromoterService::DEFAULT_CASE_COUNT_FLOOR,
            'department_extended_io_procedural_floor_count' => 35,
        ];
    }

    /**
     * Observe-only: RAGX mode/status + window/parallel execution status floors —
     * no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function runtimeStatusModeFloorsContractObserve(array $input = []): array
    {
        return [
            'ragx_mode_shadow' => RagxChainMechanismService::MODE_SHADOW,
            'ragx_mode_default_off' => RagxChainMechanismService::MODE_DEFAULT_OFF,
            'ragx_status_shadow' => RagxChainMechanismService::STATUS_SHADOW,
            'ragx_status_blocked' => RagxChainMechanismService::STATUS_BLOCKED,
            'ragx_status_disabled' => RagxChainMechanismService::STATUS_DISABLED,
            'window_state_not_started' => AcosMaxWindowOrchestratorService::STATE_NOT_STARTED,
            'window_state_unknown' => AcosMaxWindowOrchestratorService::STATE_UNKNOWN,
            'window_blocking_not_started' => AcosMaxWindowOrchestratorService::BLOCKING_WINDOW_NOT_STARTED,
            'parallel_status_active' => AcosMaxParallelExecutionProtocol::STATUS_ACTIVE,
            'parallel_status_renewed' => AcosMaxParallelExecutionProtocol::STATUS_RENEWED,
            'parallel_status_conflict' => AcosMaxParallelExecutionProtocol::STATUS_CONFLICT,
            'parallel_status_error' => AcosMaxParallelExecutionProtocol::STATUS_ERROR,
            'runtime_status_mode_floor_count' => 12,
        ];
    }

    /**
     * Observe-only: OutcomeEnvelope/Maxa04/Lote2 status floors — no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function outcomeMaxa04Lote2StatusFloorsContractObserve(array $input = []): array
    {
        return [
            'outcome_status_succeeded' => OutcomeEnvelope::STATUS_SUCCEEDED,
            'outcome_status_failed' => OutcomeEnvelope::STATUS_FAILED,
            'outcome_status_blocked' => OutcomeEnvelope::STATUS_BLOCKED,
            'outcome_native_success' => OutcomeEnvelope::NATIVE_SUCCESS,
            'outcome_native_passed' => OutcomeEnvelope::NATIVE_PASSED,
            'outcome_native_failure' => OutcomeEnvelope::NATIVE_FAILURE,
            'outcome_origin_dev_procedural' => OutcomeEnvelope::ORIGIN_DEV_PROCEDURAL,
            'outcome_origin_aemor' => OutcomeEnvelope::ORIGIN_AEMOR,
            'outcome_origin_compounding' => OutcomeEnvelope::ORIGIN_COMPOUNDING,
            'outcome_adapter_origin_count' => count(OutcomeEnvelope::ADAPTER_ORIGINS),
            'maxa04_candidate_model' => Maxa04JinaV3DualReadService::CANDIDATE_MODEL,
            'maxa04_candidate_dimensions' => Maxa04JinaV3DualReadService::CANDIDATE_DIMENSIONS,
            'maxa04_pending_window' => Maxa04JinaV3DualReadService::PENDING_WINDOW,
            'maxa04_status_pending_window' => Maxa04JinaV3DualReadService::STATUS_PENDING_WINDOW,
            'maxa04_status_insufficient_signal' => Maxa04JinaV3DualReadService::STATUS_INSUFFICIENT_SIGNAL,
            'maxa04_current_model_fallback' => Maxa04JinaV3DualReadService::CURRENT_MODEL_FALLBACK,
            'lote2_status_ok' => AcosMaxLote2MeasureService::STATUS_OK,
            'lote2_status_pending_window' => AcosMaxLote2MeasureService::STATUS_PENDING_WINDOW,
            'lote2_status_insufficient_signal' => AcosMaxLote2MeasureService::STATUS_INSUFFICIENT_SIGNAL,
            'lote2_status_measured' => AcosMaxLote2MeasureService::STATUS_MEASURED,
            'outcome_maxa04_lote2_status_floor_count' => 20,
        ];
    }

    /**
     * Observe-only: Lote2 empty-report reasons + ambition/portfolio class floors —
     * no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function lote2ReasonAmbitionPortfolioFloorsContractObserve(array $input = []): array
    {
        return [
            'lote2_reason_missing_lineage_ledger' => AcosMaxLote2MeasureService::REASON_MISSING_LINEAGE_LEDGER,
            'lote2_reason_pending_real_originator_outcome' => AcosMaxLote2MeasureService::REASON_PENDING_REAL_ORIGINATOR_OUTCOME,
            'lote2_reason_loop_source_tables_missing' => AcosMaxLote2MeasureService::REASON_LOOP_SOURCE_TABLES_MISSING,
            'lote2_reason_learning_latency_source_tables_missing' => AcosMaxLote2MeasureService::REASON_LEARNING_LATENCY_SOURCE_TABLES_MISSING,
            'lote2_reason_no_measured_lesson_usage_buckets' => AcosMaxLote2MeasureService::REASON_NO_MEASURED_LESSON_USAGE_BUCKETS,
            'lote2_reason_calibration_freeze_only' => AcosMaxLote2MeasureService::REASON_CALIBRATION_FREEZE_ONLY,
            'lote2_reason_paired_feedback_table_missing' => AcosMaxLote2MeasureService::REASON_PAIRED_FEEDBACK_TABLE_MISSING,
            'lote2_reason_mission_delivery_table_missing' => AcosMaxLote2MeasureService::REASON_MISSION_DELIVERY_TABLE_MISSING,
            'ambition_rung_task' => AmbitionRungPolicy::RUNG_TASK,
            'ambition_rung_slice' => AmbitionRungPolicy::RUNG_SLICE,
            'ambition_rung_obra' => AmbitionRungPolicy::RUNG_OBRA,
            'ambition_rung_salto' => AmbitionRungPolicy::RUNG_SALTO,
            'ambition_rung_count' => count(AmbitionRungPolicy::RUNGS),
            'portfolio_class_reactive' => PortfolioBudgetAllocator::CLASS_REACTIVE,
            'portfolio_class_originated' => PortfolioBudgetAllocator::CLASS_ORIGINATED,
            'portfolio_class_maintenance' => PortfolioBudgetAllocator::CLASS_MAINTENANCE,
            'portfolio_class_count' => count(PortfolioBudgetAllocator::CLASSES),
            'portfolio_hard_floor_share' => PortfolioBudgetAllocator::HARD_FLOOR_SHARE,
            'portfolio_hard_ceiling_share' => PortfolioBudgetAllocator::HARD_CEILING_SHARE,
            'portfolio_min_n_per_class' => PortfolioBudgetAllocator::MIN_N_PER_CLASS,
            'lote2_reason_ambition_portfolio_floor_count' => 20,
        ];
    }

    /**
     * Observe-only: Esp09 challenger + exploratory bets + obra-retro status/reason floors —
     * no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function esp09BetsObraStatusFloorsContractObserve(array $input = []): array
    {
        return [
            'esp09_trigger_kind_recursive_improvement' => Esp09IndependentChallengerService::TRIGGER_KIND_RECURSIVE_IMPROVEMENT,
            'esp09_trigger_kind_composed_obra' => Esp09IndependentChallengerService::TRIGGER_KIND_COMPOSED_OBRA,
            'esp09_status_invalid' => Esp09IndependentChallengerService::STATUS_INVALID,
            'esp09_status_skipped' => Esp09IndependentChallengerService::STATUS_SKIPPED,
            'esp09_status_advisory' => Esp09IndependentChallengerService::STATUS_ADVISORY,
            'esp09_status_delayed' => Esp09IndependentChallengerService::STATUS_DELAYED,
            'esp09_status_clear' => Esp09IndependentChallengerService::STATUS_CLEAR,
            'esp09_reason_awaiting_challenger_block' => Esp09IndependentChallengerService::REASON_AWAITING_CHALLENGER_BLOCK,
            'esp09_promotion_without_block' => Esp09IndependentChallengerService::PROMOTION_WITHOUT_BLOCK,
            'bets_status_flag_disabled' => ExploratoryBetsPortfolio::STATUS_FLAG_DISABLED,
            'bets_status_no_eligible' => ExploratoryBetsPortfolio::STATUS_NO_ELIGIBLE_BETS,
            'bets_status_ok' => ExploratoryBetsPortfolio::STATUS_OK,
            'bets_action_resume_and_double_down' => ExploratoryBetsPortfolio::ACTION_RESUME_AND_DOUBLE_DOWN,
            'bets_action_double_down' => ExploratoryBetsPortfolio::ACTION_DOUBLE_DOWN,
            'bets_action_suspend' => ExploratoryBetsPortfolio::ACTION_SUSPEND,
            'bets_action_continue_exploring' => ExploratoryBetsPortfolio::ACTION_CONTINUE_EXPLORING,
            'obra_retro_status_blocked' => AcosMaxObraRetroService::STATUS_BLOCKED,
            'obra_retro_status_recorded' => AcosMaxObraRetroService::STATUS_RECORDED,
            'obra_retro_reason_no_terminal_slices' => AcosMaxObraRetroService::REASON_NO_TERMINAL_SLICES_FOR_LOTE,
            'obra_retro_kind_failure_pattern' => AcosMaxObraRetroService::KIND_FAILURE_PATTERN,
            'esp09_bets_obra_status_floor_count' => 20,
        ];
    }

    /**
     * Observe-only: NCapture drill reasons + promotion/lifecycle/attempt status floors —
     * no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function ncapturePromotionLifecycleStatusFloorsContractObserve(array $input = []): array
    {
        return [
            'ncapture_kind_measure_freeze' => AtlasNCaptureDrillService::KIND_MEASURE_FREEZE,
            'ncapture_cold_start_channel_maxk02' => AtlasNCaptureDrillService::COLD_START_CHANNEL_MAXK02,
            'ncapture_reason_drill_receipt_incomplete' => AtlasNCaptureDrillService::REASON_DRILL_RECEIPT_INCOMPLETE,
            'ncapture_reason_admission_via_bypass_forbidden' => AtlasNCaptureDrillService::REASON_ADMISSION_VIA_BYPASS_FORBIDDEN,
            'ncapture_reason_cold_start_channel_invalid' => AtlasNCaptureDrillService::REASON_COLD_START_CHANNEL_INVALID,
            'ncapture_reason_capability_spec_violation' => AtlasNCaptureDrillService::REASON_CAPABILITY_SPEC_VIOLATION,
            'ncapture_reason_yardstick_failed_but_admitted' => AtlasNCaptureDrillService::REASON_YARDSTICK_FAILED_BUT_ADMITTED,
            'promotion_status_recorded' => PromotionProtocol::STATUS_RECORDED,
            'promotion_status_managed' => PromotionProtocol::STATUS_MANAGED,
            'promotion_status_legacy_unmanaged' => PromotionProtocol::STATUS_LEGACY_UNMANAGED,
            'promotion_status_blocked' => PromotionProtocol::STATUS_BLOCKED,
            'promotion_state_legacy_unmanaged' => PromotionProtocol::STATE_LEGACY_UNMANAGED,
            'evidence_thesis_status_active' => EvidenceVisionThesisLifecycle::STATUS_ACTIVE,
            'evidence_thesis_status_archived' => EvidenceVisionThesisLifecycle::STATUS_ARCHIVED,
            'evidence_thesis_status_refused' => EvidenceVisionThesisLifecycle::STATUS_REFUSED,
            'composed_arc_status_pending' => ComposedObraArcLifecycle::STATUS_PENDING,
            'composed_arc_status_active' => ComposedObraArcLifecycle::STATUS_ACTIVE,
            'composed_arc_status_archived' => ComposedObraArcLifecycle::STATUS_ARCHIVED,
            'attempt_state_started' => AttemptLifecycleLedger::STATE_STARTED,
            'attempt_reason_duplicate' => AttemptLifecycleLedger::REASON_DUPLICATE_ATTEMPT,
            'ncapture_promotion_lifecycle_status_floor_count' => 20,
        ];
    }

    /**
     * Observe-only: ASEF chunk index + remint queue + immune trust bands + residual RAGX status floors —
     * no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function asefRemintImmuneRagxStatusFloorsContractObserve(array $input = []): array
    {
        return [
            'asef_status_unavailable' => AsefChunkIndexService::STATUS_UNAVAILABLE,
            'asef_status_blocked' => AsefChunkIndexService::STATUS_BLOCKED,
            'asef_status_degraded' => AsefChunkIndexService::STATUS_DEGRADED,
            'asef_status_ok' => AsefChunkIndexService::STATUS_OK,
            'asef_reason_table_missing' => AsefChunkIndexService::REASON_ASEF_CHUNKS_TABLE_MISSING,
            'asef_reason_empty_source' => AsefChunkIndexService::REASON_EMPTY_SOURCE_REF_OR_TEXT,
            'asef_reason_embedding_column_absent' => AsefChunkIndexService::REASON_EMBEDDING_COLUMN_ABSENT,
            'remint_mode_off' => AtlasCognitionRemintTouchedQueue::MODE_OFF,
            'remint_mode_deferred_disk_queue' => AtlasCognitionRemintTouchedQueue::MODE_DEFERRED_DISK_QUEUE,
            'remint_reason_disabled' => AtlasCognitionRemintTouchedQueue::REASON_DISABLED,
            'remint_reason_queued' => AtlasCognitionRemintTouchedQueue::REASON_QUEUED,
            'remint_reason_queue_write_failed' => AtlasCognitionRemintTouchedQueue::REASON_QUEUE_WRITE_FAILED,
            'immune_trust_band_blocked' => CognitiveImmunePromotionGateEvaluator::TRUST_BAND_BLOCKED,
            'immune_trust_band_trusted' => CognitiveImmunePromotionGateEvaluator::TRUST_BAND_TRUSTED,
            'immune_trust_band_watch' => CognitiveImmunePromotionGateEvaluator::TRUST_BAND_WATCH,
            'immune_trust_band_candidate' => CognitiveImmunePromotionGateEvaluator::TRUST_BAND_CANDIDATE,
            'ragx_status_degraded' => RagxChainMechanismService::STATUS_DEGRADED,
            'ragx_status_registered' => RagxChainMechanismService::STATUS_REGISTERED,
            'ragx_status_insufficient_signal' => RagxChainMechanismService::STATUS_INSUFFICIENT_SIGNAL,
            'ragx_reason_late_chunk_index_error' => RagxChainMechanismService::REASON_LATE_CHUNK_INDEX_ERROR,
            'asef_remint_immune_ragx_status_floor_count' => 20,
        ];
    }

    /**
     * Observe-only: memory-feedback decay decisions + veto reasons + numeric-range relations + choreography actions —
     * no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function decayVetoNumericChoreographyFloorsContractObserve(array $input = []): array
    {
        return [
            'decay_decision_archive' => MemoryFeedbackDecayScorer::DECISION_ARCHIVE,
            'decay_decision_inactivate' => MemoryFeedbackDecayScorer::DECISION_INACTIVATE,
            'decay_decision_degrade' => MemoryFeedbackDecayScorer::DECISION_DEGRADE,
            'decay_decision_keep' => MemoryFeedbackDecayScorer::DECISION_KEEP,
            'decay_decision_fresh' => MemoryFeedbackDecayScorer::DECISION_FRESH,
            'decay_decision_stale_inactive_candidate' => MemoryFeedbackDecayScorer::DECISION_STALE_INACTIVE_CANDIDATE,
            'veto_reason_operator_final_override' => AtlasVetoPropagationResolver::REASON_OPERATOR_VETO_FINAL_OVERRIDE,
            'veto_reason_security_pause_downstream' => AtlasVetoPropagationResolver::REASON_SECURITY_VETO_PAUSE_DOWNSTREAM,
            'veto_reason_architect_upstream' => AtlasVetoPropagationResolver::REASON_ARCHITECT_SPEC_VETO_UPSTREAM,
            'veto_reason_no_canonical_rule' => AtlasVetoPropagationResolver::REASON_NO_CANONICAL_VETO_RULE,
            'numeric_relation_invalid' => NumericRangeOverlapContradictionDetector::RELATION_INVALID,
            'numeric_relation_equal' => NumericRangeOverlapContradictionDetector::RELATION_EQUAL,
            'numeric_relation_disjoint' => NumericRangeOverlapContradictionDetector::RELATION_DISJOINT,
            'numeric_relation_overlap' => NumericRangeOverlapContradictionDetector::RELATION_OVERLAP,
            'numeric_relation_a_contains_b' => NumericRangeOverlapContradictionDetector::RELATION_A_CONTAINS_B,
            'choreography_action_noop' => AtlasCrossDepartmentChoreographyService::ACTION_NOOP,
            'choreography_action_pause_downstream' => AtlasCrossDepartmentChoreographyService::ACTION_PAUSE_DOWNSTREAM,
            'choreography_action_return_upstream' => AtlasCrossDepartmentChoreographyService::ACTION_RETURN_UPSTREAM,
            'choreography_action_override' => AtlasCrossDepartmentChoreographyService::ACTION_OVERRIDE,
            'choreography_handoff_kind_veto' => AtlasCrossDepartmentChoreographyService::HANDOFF_KIND_VETO,
            'decay_veto_numeric_choreography_floor_count' => 20,
        ];
    }

    /**
     * Observe-only: evidence-vision thesis kinds + temporal supersession + HMAC lineage + immune calibration floors —
     * no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evidenceTemporalHmacCalibrationFloorsContractObserve(array $input = []): array
    {
        return [
            'evidence_thesis_status_ok' => EvidenceVisionThesisComposer::STATUS_OK,
            'evidence_thesis_status_active' => EvidenceVisionThesisComposer::STATUS_ACTIVE,
            'evidence_thesis_kind_series_recovery' => EvidenceVisionThesisComposer::KIND_SERIES_RECOVERY,
            'evidence_thesis_kind_calibration_resolved' => EvidenceVisionThesisComposer::KIND_CALIBRATION_RESOLVED,
            'evidence_thesis_kind_lead_cluster_cleared' => EvidenceVisionThesisComposer::KIND_LEAD_CLUSTER_CLEARED,
            'evidence_thesis_kind_outcome_proven' => EvidenceVisionThesisComposer::KIND_OUTCOME_PROVEN,
            'temporal_relation_coexist' => TemporalSupersessionClassifier::RELATION_COEXIST,
            'temporal_relation_a_supersedes_b' => TemporalSupersessionClassifier::RELATION_A_SUPERSEDES_B,
            'temporal_relation_b_supersedes_a' => TemporalSupersessionClassifier::RELATION_B_SUPERSEDES_A,
            'temporal_relation_tie_same_timestamp' => TemporalSupersessionClassifier::RELATION_TIE_SAME_TIMESTAMP,
            'hmac_status_unverifiable_legacy' => CaptureHmacLineageService::STATUS_UNVERIFIABLE_LEGACY,
            'hmac_status_verified' => CaptureHmacLineageService::STATUS_VERIFIED,
            'hmac_status_pending_window' => CaptureHmacLineageService::STATUS_PENDING_WINDOW,
            'hmac_status_not_found' => CaptureHmacLineageService::STATUS_NOT_FOUND,
            'hmac_kind_capture' => CaptureHmacLineageService::KIND_CAPTURE,
            'immune_calibration_mode_read_only' => ImmuneCalibrationService::MODE_READ_ONLY,
            'immune_calibration_kind_measure_freeze' => ImmuneCalibrationService::KIND_MEASURE_FREEZE,
            'immune_calibration_band_insufficient_sample' => ImmuneCalibrationService::BAND_INSUFFICIENT_SAMPLE,
            'immune_calibration_status_insufficient_sample' => ImmuneCalibrationService::STATUS_INSUFFICIENT_SAMPLE,
            'immune_calibration_reason_known_miss_denominator_zero' => ImmuneCalibrationService::REASON_KNOWN_MISS_DENOMINATOR_ZERO,
            'evidence_temporal_hmac_calibration_floor_count' => 20,
        ];
    }

    /**
     * Observe-only: verified-share + model-capability + implementation-truth + runbook ambition floors —
     * no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function verifiedShareCapabilityTruthAmbitionFloorsContractObserve(array $input = []): array
    {
        return [
            'verified_share_kind_measure_freeze' => AcosMaxVerifiedShareService::KIND_MEASURE_FREEZE,
            'verified_share_status_missing_freeze' => AcosMaxVerifiedShareService::STATUS_MISSING_FREEZE,
            'verified_share_status_insufficient_signal' => AcosMaxVerifiedShareService::STATUS_INSUFFICIENT_SIGNAL,
            'verified_share_reason_measure_freeze_not_recorded' => AcosMaxVerifiedShareService::REASON_MEASURE_FREEZE_NOT_RECORDED,
            'capability_reason_missing_model_id' => AtlasModelCapabilitySpecService::REASON_MISSING_MODEL_ID,
            'capability_reason_latency_above_spec_ceiling' => AtlasModelCapabilitySpecService::REASON_LATENCY_ABOVE_SPEC_CEILING,
            'capability_reason_license_missing' => AtlasModelCapabilitySpecService::REASON_LICENSE_MISSING,
            'capability_reason_license_not_allowed' => AtlasModelCapabilitySpecService::REASON_LICENSE_NOT_ALLOWED,
            'truth_level_spec' => AtlasImplementationTruthService::LEVEL_SPEC,
            'truth_level_partial' => AtlasImplementationTruthService::LEVEL_PARTIAL,
            'truth_level_verified' => AtlasImplementationTruthService::LEVEL_VERIFIED,
            'truth_level_existence_only' => AtlasImplementationTruthService::LEVEL_EXISTENCE_ONLY,
            'truth_rank_spec' => AtlasImplementationTruthService::RANK[AtlasImplementationTruthService::LEVEL_SPEC],
            'truth_rank_partial' => AtlasImplementationTruthService::RANK[AtlasImplementationTruthService::LEVEL_PARTIAL],
            'truth_rank_verified' => AtlasImplementationTruthService::RANK[AtlasImplementationTruthService::LEVEL_VERIFIED],
            'runbook_ambition_trivial' => RunbookOrchestrator::AMBITION_TRIVIAL,
            'runbook_ambition_task' => RunbookOrchestrator::AMBITION_TASK,
            'runbook_ambition_mission' => RunbookOrchestrator::AMBITION_MISSION,
            'runbook_ambition_obra' => RunbookOrchestrator::AMBITION_OBRA,
            'runbook_actor_kind_agent' => RunbookOrchestrator::ACTOR_KIND_AGENT,
            'verified_share_capability_truth_ambition_floor_count' => 20,
        ];
    }

    /**
     * Observe-only: daily-canary + ledger-integrity + window-orchestrator + rotation-mode floors —
     * no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function canaryIntegrityWindowRotationFloorsContractObserve(array $input = []): array
    {
        return [
            'canary_status_unavailable' => DailyCanaryReplayByRefsWatchdogCheck::STATUS_UNAVAILABLE,
            'canary_reason_drift' => DailyCanaryReplayByRefsWatchdogCheck::REASON_CANARY_DRIFT,
            'canary_reason_within_floors' => DailyCanaryReplayByRefsWatchdogCheck::REASON_CANARY_WITHIN_FLOORS,
            'canary_reason_insufficient_signal' => DailyCanaryReplayByRefsWatchdogCheck::REASON_INSUFFICIENT_SIGNAL,
            'integrity_reason_chains_intact' => EvidenceLedgerIntegrityWatchdogCheck::REASON_CHAINS_INTACT,
            'integrity_reason_gap' => EvidenceLedgerIntegrityWatchdogCheck::REASON_GAP,
            'integrity_reason_tampered' => EvidenceLedgerIntegrityWatchdogCheck::REASON_TAMPERED,
            'integrity_reason_verifier_threw' => EvidenceLedgerIntegrityWatchdogCheck::REASON_VERIFIER_THREW,
            'window_status_dead_window' => AcosMaxWindowOrchestratorService::STATUS_DEAD_WINDOW,
            'window_status_unavailable' => AcosMaxWindowOrchestratorService::STATUS_UNAVAILABLE,
            'window_reason_no_started_window' => AcosMaxWindowOrchestratorService::REASON_NO_STARTED_WINDOW_WITH_NUMERIC_DURATION,
            'rotation_mode_append_forever' => AcosMaxLedgerRotationRegistry::MODE_APPEND_FOREVER,
            'rotation_mode_rotate_hybrid' => AcosMaxLedgerRotationRegistry::MODE_ROTATE_HYBRID,
            'rotation_mode_rotate_size' => AcosMaxLedgerRotationRegistry::MODE_ROTATE_SIZE,
            'verified_share_status_ok' => AcosMaxVerifiedShareService::STATUS_OK,
            'verified_share_status_below_threshold' => AcosMaxVerifiedShareService::STATUS_BELOW_THRESHOLD,
            'attempt_state_completed' => AttemptLifecycleLedger::STATE_COMPLETED,
            'attempt_state_crashed' => AttemptLifecycleLedger::STATE_CRASHED,
            'attempt_state_timed_out' => AttemptLifecycleLedger::STATE_TIMED_OUT,
            'attempt_state_abandoned' => AttemptLifecycleLedger::STATE_ABANDONED,
            'canary_integrity_window_rotation_floor_count' => 20,
        ];
    }
}
