<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Gates\GateObserve01;

use App\Services\Ai\AgenticEngineeringOs\Scoring\SummaryFidelityCoverageScorer;
use App\Services\Ai\AgenticEngineeringOs\Scoring\MemoryInjectionBudgetAllocator;
use App\Services\Ai\AgenticEngineeringOs\Scoring\SegmentImportanceRanker;
use App\Services\Ai\AgenticEngineeringOs\Scoring\ContextParetoDominanceFilter;
use App\Services\Ai\AgenticEngineeringOs\Scoring\AtlasMemoryRecallRelevanceScorer;
use App\Services\Ai\AgenticEngineeringOs\Scoring\OutcomeCausalityRanker;
use App\Services\Ai\Cognition\AcosProgram\DogfoodingFrictionLeadMiner;
use App\Services\Ai\Context\Retrieval\AsefChunkIndexService;
use App\Services\Ai\Context\Retrieval\DomainLexicalNormalizer;
use App\Services\Ai\Cognition\AcosProgram\StructuredFactSchemaMap;
use App\Services\Ai\Context\Retrieval\CitationGroundingMeter;
use App\Services\Ai\Context\Retrieval\ProvenanceWeightCalculator;
use App\Services\Ai\Context\Retrieval\RecallGapAggregator;
use App\Services\Ai\Cognition\AcosProgram\EvidenceVisionThesisComposer;
use App\Services\Ai\Cognition\AcosProgram\ExecutionContextCooccurrenceService;
use App\Services\Ai\AgenticEngineeringOs\AaeosDeferredPhaseDispatcherService;
use App\Services\Ai\Cognition\AtlasSurpriseGateService;
use App\Services\Ai\Cognition\AtlasFrontierWaveLadder;
use App\Services\Ai\Cognition\AtlasImmuneClassifierHybridFreeze;
use App\Services\Ai\Cognition\AtlasCognitiveFunctionAtlasService;
use App\Services\Ai\Cognition\AtlasCognitiveMemoryFabricSchemaEvolutionService;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogRunner;
use App\Services\Ai\Cognition\Watchdog\Checks\AutonomyLadderAdversarialWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\DailyCanaryReplayByRefsWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\EvidenceLedgerIntegrityWatchdogCheck;
use App\Services\Ai\Cognition\CognitiveImmuneCheckContract;
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
use App\Services\Ai\Cognition\AtlasAcosWindowGatesService;
use App\Services\Ai\Cognition\AtlasAcosEvolutionScoreService;
use App\Services\Ai\Cognition\AtlasImmuneHybridInputClassifier;
use App\Services\Ai\Cognition\AcosProgram\PromotionProtocol;
use App\Services\Ai\Cognition\AcosProgram\AtlasFlywheelFunnelService;
use App\Services\Ai\Cognition\AcosProgram\EvidenceVisionThesisLifecycle;
use App\Services\Ai\Context\Retrieval\AtlasKnowledgeItemEmbeddingCoverageService;
use App\Services\Ai\Context\Retrieval\AtlasCodeSymbolEmbeddingCoverageService;
use App\Services\Ai\Cognition\AcosProgram\Teto10PredictedRevertReviewDigest;
use App\Services\Ai\Context\Retrieval\Maxa04JinaV3DualReadLedger;
use App\Services\Ai\Context\Retrieval\Maxa04JinaV3DualReadService;
use App\Services\Ai\Cognition\AcosProgram\AtlasResourceBudgetService;
use App\Services\Ai\Cognition\AcosProgram\AcosMeasureSeriesFreshnessReader;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxVerifiedShareService;
use App\Services\Ai\Context\Retrieval\RagxChainMechanismService;
use App\Services\Ai\Context\Retrieval\GoldenCounterfactualReplayService;
use App\Services\Ai\Cognition\AcosProgram\ComposedObraArcComposer;
use App\Services\Ai\Cognition\AcosProgram\ComposedObraArcLifecycle;
use App\Services\Ai\Cognition\AcosProgram\ExploratoryBetsPortfolio;
use App\Services\Ai\Aemor\Envelope\OutcomeEnvelope;
use App\Services\Ai\Cognition\AcosProgram\AtlasNCaptureDrillService;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxLote2MeasureService;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasThresholdComparator;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDocMaturityClassifier;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasClaimDefinitionOfDoneValidator;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasVetoPropagationResolver;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentRegistryService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasCognitiveImmuneInputClassifier;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCausalEffectGate;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentQualityBarService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentMaturityService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasCrossDepartmentChoreographyService;
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
use App\Services\Ai\AgenticEngineeringOs\AaeosBlockerSeverity;
use App\Services\Ai\AgenticEngineeringOs\AaeosBlockerSeverityGate;
use App\Services\Ai\AgenticEngineeringOs\AaeosHttpPathEnvelopeFactory;
use App\Services\Ai\AgenticEngineeringOs\AaeosPhaseHandoffService;
use App\Services\Ai\AgenticEngineeringOs\DeliveryPackCompletenessScorer;
use App\Services\Ai\AgenticEngineeringOs\DepartmentContractRuntime;
use App\Services\Ai\AgenticEngineeringOs\PhaseAdvanceVerdictClassifier;
use App\Services\Ai\AgenticEngineeringOs\RunbookOrchestrator;

/**
 * GOD-DEBULK FASE C sub-split of GateObserveSection01 (part 02).
 * Bodies byte-identical to the pre-split gate section; the
 * GateObserveSection01 facade delegates each observe call here.
 */
final class GateObserveSection01Part02
{

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
}
