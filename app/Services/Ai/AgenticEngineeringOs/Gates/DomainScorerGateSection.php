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
use App\Services\Ai\AgenticEngineeringOs\Gates\GateObserveSection01;
use App\Services\Ai\AgenticEngineeringOs\Gates\GateObserveSection02;
use App\Services\Ai\AgenticEngineeringOs\Gates\GateObserveSection03;
use App\Services\Ai\AgenticEngineeringOs\Gates\GateObserveSection04;
use App\Services\Ai\AgenticEngineeringOs\Gates\GateObserveSection05;
use App\Services\Ai\AgenticEngineeringOs\Gates\GateObserveSection06;
use App\Services\Ai\AgenticEngineeringOs\Gates\GateObserveSection07;
use App\Services\Ai\AgenticEngineeringOs\Gates\GateObserveSection08;
use App\Services\Ai\AgenticEngineeringOs\Gates\GateObserveSection09;
use App\Services\Ai\AgenticEngineeringOs\AaeosBlockerSeverityGate;
use App\Services\Ai\AgenticEngineeringOs\AaeosRequiredGateCoverageChecker;
use App\Services\Ai\AgenticEngineeringOs\DeliveryPackCompletenessScorer;
use App\Services\Ai\AgenticEngineeringOs\PhaseAdvanceVerdictClassifier;

/**
 * GOD-DEBULK FASE C — extracted observe/floors-contract gate family from
 * {@see \App\Services\Ai\AgenticEngineeringOs\AtlasUniversalGatesEvaluator}.
 * Bodies are byte-identical to the pre-split façade; the façade delegates.
 */
final class DomainScorerGateSection
{
    public function __construct(
        private readonly DeliveryPackCompletenessScorer $deliveryPackCompleteness = new DeliveryPackCompletenessScorer,
        private readonly SpecCompletenessScorer $specCompleteness = new SpecCompletenessScorer,
        private readonly AaeosBlockerSeverityGate $blockerSeverity = new AaeosBlockerSeverityGate,
        private readonly PhaseAdvanceVerdictClassifier $phaseAdvance = new PhaseAdvanceVerdictClassifier,
        private readonly AaeosRequiredGateCoverageChecker $requiredGateCoverage = new AaeosRequiredGateCoverageChecker,
        private readonly OutcomeCausalityRanker $outcomeCausality = new OutcomeCausalityRanker,
        private readonly SummaryFidelityCoverageScorer $summaryFidelity = new SummaryFidelityCoverageScorer,
        private readonly MemoryInjectionBudgetAllocator $memoryInjectionBudget = new MemoryInjectionBudgetAllocator,
        private readonly MemoryFeedbackDecayScorer $memoryFeedbackDecay = new MemoryFeedbackDecayScorer,
        private readonly SegmentImportanceRanker $segmentImportance = new SegmentImportanceRanker,
        private readonly ContextParetoDominanceFilter $contextPareto = new ContextParetoDominanceFilter,
    ) {}

    /**
     * Observe-only full delivery-pack completeness score projection.
     * Does not add a universal-gate id (catalogue stays 15).
     *
     * @param  array<string,mixed>  $composition
     * @return array<string,mixed>
     */
    public function deliveryPackCompletenessScoreObserve(array $composition): array
    {
        return $this->deliveryPackCompleteness->score($composition);
    }

    /**
     * Observe-only full SpecCompletenessScorer projection.
     * Does not add a universal-gate id.
     *
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    public function specCompletenessScoreObserve(array $spec): array
    {
        return $this->specCompleteness->score($spec);
    }

    /**
     * Observe-only AAEOS blocker-severity gate assessment.
     * Accepts a list of blockers or `{blockers:[...]}`. Catalogue stays 15.
     *
     * @param  array<mixed>  $input
     * @return array<string,mixed>
     */
    public function blockerSeverityObserve(array $input): array
    {
        $blockers = array_is_list($input)
            ? $input
            : AiValueNormalizer::arrayOrEmpty($input['blockers'] ?? null);

        return $this->blockerSeverity->assess($blockers);
    }

    /**
     * Observe-only phase-advance verdict over an atlas.aaeos.phase.v1 envelope.
     * Does not add a universal-gate id.
     *
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    public function phaseAdvanceVerdictObserve(array $envelope): array
    {
        return $this->phaseAdvance->classify($envelope);
    }

    /**
     * Observe-only required-vs-passed gate coverage projection.
     * Accepts `{required:[...], passed:[...]}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function requiredGateCoverageObserve(array $input): array
    {
        return $this->requiredGateCoverage->check(
            AiValueNormalizer::arrayOrEmpty($input['required'] ?? null),
            AiValueNormalizer::arrayOrEmpty($input['passed'] ?? null),
        );
    }

    /**
     * Observe-only outcome causality ranking over an OutcomeEnvelope map.
     * Does not add a universal-gate id.
     *
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    public function outcomeCausalityObserve(array $envelope): array
    {
        return $this->outcomeCausality->rankOutcomeEnvelope($envelope);
    }

    /**
     * Observe-only summary fidelity / context-retention coverage.
     * Accepts `{required_items|required:[...], summary_text|summary:string}`.
     * Does not add a universal-gate id (catalogue stays 15).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function summaryFidelityCoverageObserve(array $input): array
    {
        $required = AiValueNormalizer::arrayOrEmpty(
            $input['required_items'] ?? $input['required'] ?? null,
        );
        $summary = AiValueNormalizer::trimmedStringOrNull(
            $input['summary_text'] ?? $input['summary'] ?? null,
        ) ?? '';

        /** @var list<array{id?: mixed, kind?: mixed, digest?: mixed}> $required */
        return $this->summaryFidelity->score($required, $summary);
    }

    /**
     * Observe-only memory injection budget allocation.
     * Accepts `{ranked_items|items:[...], total_budget_chars, per_item_cap_chars, min_excerpt_chars?}`.
     * Does not add a universal-gate id (catalogue stays 15).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function memoryInjectionBudgetObserve(array $input): array
    {
        $items = AiValueNormalizer::arrayOrEmpty(
            $input['ranked_items'] ?? $input['items'] ?? null,
        );

        /** @var list<array{ref:string,priority:int|float,estimated_chars:int}> $items */
        return $this->memoryInjectionBudget->allocate(
            $items,
            (int) ($input['total_budget_chars'] ?? $input['total_budget'] ?? 0),
            (int) ($input['per_item_cap_chars'] ?? $input['per_item_cap'] ?? 0),
            array_key_exists('min_excerpt_chars', $input)
                ? (int) (AiValueNormalizer::finiteFloatOrNull($input['min_excerpt_chars'] ?? null) ?? 0)
                : (array_key_exists('min_excerpt', $input) ? (int) (AiValueNormalizer::finiteFloatOrNull($input['min_excerpt'] ?? null) ?? 0) : null),
        );
    }

    /**
     * Observe-only memory feedback decay / lifecycle health score.
     * Accepts a signals map (`positive_count`, `negative_count`, ages, …).
     * Does not add a universal-gate id (catalogue stays 15).
     *
     * @param  array<string,mixed>  $signals
     * @return array<string,mixed>
     */
    public function memoryFeedbackDecayObserve(array $signals): array
    {
        return $this->memoryFeedbackDecay->score($signals);
    }

    /**
     * Observe-only segment importance ranking / token-budget selection.
     * Accepts `{segments|maybe_discard:[...], token_budget|budget:int}`.
     * Does not add a universal-gate id (catalogue stays 15).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function segmentImportanceObserve(array $input): array
    {
        $segments = AiValueNormalizer::arrayOrEmpty(
            $input['segments'] ?? $input['maybe_discard'] ?? null,
        );
        $budget = (int) ($input['token_budget'] ?? $input['budget'] ?? 0);

        /** @var list<array<string,mixed>> $segments */
        return $this->segmentImportance->select($segments, $budget);
    }

    /**
     * Observe-only multi-objective Pareto dominance frontier.
     * Accepts `{variants:[...], objective_direction|objectives:{...}, hard_constraints?:{...}}`.
     * Does not add a universal-gate id (catalogue stays 15).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function contextParetoDominanceObserve(array $input): array
    {
        $variants = AiValueNormalizer::arrayOrEmpty($input['variants'] ?? null);
        $direction = AiValueNormalizer::arrayOrEmpty(
            $input['objective_direction'] ?? $input['objectives'] ?? null,
        );
        $constraints = AiValueNormalizer::arrayOrEmpty($input['hard_constraints'] ?? null);

        /** @var list<array<string,mixed>> $variants */
        /** @var array<string,string> $direction */
        /** @var array<string,array<string,mixed>> $constraints */
        return $this->contextPareto->filter($variants, $direction, $constraints);
    }
}
