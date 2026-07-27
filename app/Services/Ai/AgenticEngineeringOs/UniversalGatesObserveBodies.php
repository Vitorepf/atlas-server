<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\Aemor\Envelope\OutcomeEnvelope;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasCapabilityTestExecutionService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasClaimDefinitionOfDoneValidator;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasCognitiveImmuneInputClassifier;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasCrossDepartmentChoreographyService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentLevelClassifier;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentMaturityBandClassifier;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentPromotionEligibilityEvaluator;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentQualityBarLevelClassifier;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentRegistryService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDocMaturityClassifier;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasGateSignalEvaluator;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasPhaseRouterService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasRepairLoopGuard;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasVetoPropagationResolver;
use App\Services\Ai\AgenticEngineeringOs\Scoring\AtlasMemoryRecallRelevanceScorer;
use App\Services\Ai\AgenticEngineeringOs\Scoring\MemoryFeedbackDecayScorer;
use App\Services\Ai\AgenticEngineeringOs\Scoring\OutcomeCausalityRanker;
use App\Services\Ai\AgenticEngineeringOs\Scoring\SegmentImportanceRanker;
use App\Services\Ai\AgenticEngineeringOs\Scoring\SpecCompletenessScorer;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasArrayFieldReader;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasEvidenceRefNormalizer;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasStringListNormalizer;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasThresholdComparator;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasThresholdLadderNormalizer;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxLedgerRotationRegistry;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxObraRetroService;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxParallelExecutionProtocol;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxProceduralSkillPromoterService;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxVerifiedShareService;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxWindowOrchestratorService;
use App\Services\Ai\Cognition\AcosProgram\AcosMeasureSeriesFreshnessReader;
use App\Services\Ai\Cognition\AcosProgram\AcosProgramCockpitService;
use App\Services\Ai\Cognition\AcosProgram\AmbitionRungPolicy;
use App\Services\Ai\Cognition\AcosProgram\AtlasFlywheelFunnelService;
use App\Services\Ai\Cognition\AcosProgram\AttemptLifecycleLedger;
use App\Services\Ai\Cognition\AcosProgram\Esp09IndependentChallengerService;
use App\Services\Ai\Cognition\AcosProgram\EvidenceVisionThesisComposer;
use App\Services\Ai\Cognition\AcosProgram\EvidenceVisionThesisLifecycle;
use App\Services\Ai\Cognition\AcosProgram\ExploratoryBetsPortfolio;
use App\Services\Ai\Cognition\AcosProgram\PortfolioBudgetAllocator;
use App\Services\Ai\Cognition\AcosProgram\PredictedImpactBand;
use App\Services\Ai\Cognition\AcosProgram\PreReviewAdvisoryBand;
use App\Services\Ai\Cognition\AcosProgram\PromotionProtocol;
use App\Services\Ai\Cognition\AcosProgram\ReactiveSaturationSignal;
use App\Services\Ai\Cognition\AcosProgram\Teto10PredictedRevertReviewDigest;
use App\Services\Ai\Cognition\AtlasAcosEvolutionScoreService;
use App\Services\Ai\Cognition\AtlasAcosLongHorizonGateService;
use App\Services\Ai\Cognition\AtlasAcosRollbackTriggerCheckService;
use App\Services\Ai\Cognition\AtlasAcosWindowGatesService;
use App\Services\Ai\Cognition\AtlasCognitionEvidenceResolver;
use App\Services\Ai\Cognition\AtlasCognitionRemintTouchedQueue;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardV4Grouper;
use App\Services\Ai\Cognition\AtlasCognitiveFunctionDecomposerService;
use App\Services\Ai\Cognition\AtlasConsolidationRerankGuard;
use App\Services\Ai\Cognition\AtlasFrontierWaveLadder;
use App\Services\Ai\Cognition\AtlasSurpriseGateService;
use App\Services\Ai\Cognition\CaptureHmacLineageService;
use App\Services\Ai\Cognition\CognitiveImmuneCheckContract;
use App\Services\Ai\Cognition\CognitiveImmunePromotionGateEvaluator;
use App\Services\Ai\Cognition\ImmuneCalibrationService;
use App\Services\Ai\Cognition\ImmuneSignatureDeriver;
use App\Services\Ai\Cognition\ImmuneSignatureStore;
use App\Services\Ai\Cognition\ImmuneVerdictLedger;
use App\Services\Ai\Cognition\Watchdog\AtlasAcosWatchdogHealthService;
use App\Services\Ai\Cognition\Watchdog\Checks\HealthReportWatchdogCheck;
use App\Services\Ai\Context\Retrieval\AtlasCodeSymbolEmbeddingCoverageService;
use App\Services\Ai\Context\Retrieval\AtlasKnowledgeItemEmbeddingCoverageService;
use App\Services\Ai\Context\Retrieval\DomainLexicalNormalizer;
use App\Services\Ai\Context\Retrieval\GatedCorpusCandidateMiner;
use App\Services\Ai\Context\Retrieval\Maxa04JinaV3DualReadLedger;
use App\Services\Ai\SelfConstruction\ControlPlane\AtlasSelfConstructionScopeRiskBudgetGate;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOrganMeshOrchestrator;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Telemetry\AiOutcomeAttributionService;
use App\Services\Ai\Telemetry\AiTelemetryCollector;

/**
 * TRI-HYGIENE W5b — multi-line observe bodies moved from façade.
 */
trait UniversalGatesObserveBodies
{
    public function deliveryPackCompletenessSignal(
        array $composition,
        ?DeliveryPackCompletenessScorer $scorer = null,
        float $minRatio = 0.95,
    ): bool {
        return ($scorer ?? $this->deliveryPackCompleteness)->passesMin($composition, $minRatio);
    }

    public function specCompletenessSignal(
        array $spec,
        ?SpecCompletenessScorer $scorer = null,
        int $minScore = SpecCompletenessScorer::COMPLETE_THRESHOLD,
    ): bool {
        return ($scorer ?? $this->specCompleteness)->passesMin($spec, $minScore);
    }

    public function memoryRecallRankObserve(array $input): array
    {
        $rows = array_is_list($input)
            ? $input
            : AiValueNormalizer::arrayOrEmpty($input['rows'] ?? $input['candidates'] ?? null);

        /** @var array<int,array<string,mixed>> $rows */
        $ranked = $this->memoryRecallRelevance->rank($rows);

        return [
            self::FIELD_SCHEMA_VERSION => AtlasMemoryRecallRelevanceScorer::SCHEMA_VERSION,
            'ranked' => $ranked,
            self::FIELD_COUNT => count($ranked),
        ];
    }

    public function domainLexicalObserve(array $input): array
    {
        if (($input['contract_only'] ?? false) === true
            || AiValueNormalizer::lowerTrimmedString($input['mode'] ?? '') === 'contract') {
            return DomainLexicalNormalizer::contract();
        }

        $query = AiValueNormalizer::trimmedStringOrNull($input['query'] ?? null) ?? '';
        $fields = AiValueNormalizer::arrayOrEmpty($input['fields'] ?? null);

        return [
            self::FIELD_SCHEMA_VERSION => DomainLexicalNormalizer::SCHEMA_VERSION,
            'formula_version' => DomainLexicalNormalizer::FORMULA_VERSION,
            'query' => $query,
            'score' => DomainLexicalNormalizer::score($query, $fields),
            'tokens' => DomainLexicalNormalizer::tokens($query),
            'contract' => DomainLexicalNormalizer::contract(),
        ];
    }

    public function ledgerRotationObserve(array $input): array
    {
        $registry = new AcosMaxLedgerRotationRegistry;
        $series = AiValueNormalizer::trimmedStringOrNull($input['series'] ?? null) ?? '';
        $policy = $series === '' ? null : $registry->policyFor($series);

        return [
            self::FIELD_SCHEMA_VERSION => self::OBSERVE_LEDGER_ROTATION_SCHEMA,
            'series' => $series,
            'found' => $policy !== null,
            'policy' => $policy,
            'declared_series_count' => count($registry->all()),
        ];
    }

    public function evidenceVisionObserve(array $input): array
    {
        $thesis = AiValueNormalizer::arrayOrEmpty($input['thesis'] ?? null);
        $forbidden = AiValueNormalizer::arrayOrEmpty($input['forbidden'] ?? null);
        /** @var list<string> $forbidden */

        return [
            self::FIELD_SCHEMA_VERSION => self::OBSERVE_EVIDENCE_VISION_SCHEMA,
            'field_sources_valid' => EvidenceVisionThesisComposer::thesisFieldSourcesValid($thesis),
            'operator_fence_pass' => EvidenceVisionThesisComposer::thesisPassesOperatorFence($thesis, $forbidden),
        ];
    }

    public function thresholdLadderObserve(array $input): array
    {
        $ladder = array_is_list($input)
            ? $input
            : AiValueNormalizer::arrayOrEmpty($input['band_ladder'] ?? $input['ladder'] ?? null);
        /** @var list<array{level:string,thresholds:list<array{metric:string,comparator:string,value:float}>}> $ladder */
        $normalized = AtlasThresholdLadderNormalizer::levelLadder($ladder);

        return [
            self::FIELD_SCHEMA_VERSION => self::OBSERVE_THRESHOLD_LADDER_SCHEMA,
            'valid' => $normalized !== [] || $ladder === [],
            'band_count' => count($normalized),
            'ladder' => $normalized,
        ];
    }

    public function jinaDualReadLedgerObserve(array $input = []): array
    {
        $path = AiValueNormalizer::trimmedStringOrNull($input['path'] ?? null);
        $ledger = new Maxa04JinaV3DualReadLedger($path);

        return [
            self::FIELD_SCHEMA_VERSION => Maxa04JinaV3DualReadLedger::SCHEMA,
            'path' => $ledger->path(),
            'relative_path' => Maxa04JinaV3DualReadLedger::RELATIVE_PATH,
            'custom_path' => $path !== null,
        ];
    }

    public function measureSeriesFreshnessObserve(array $input = []): array
    {
        $entry = AiValueNormalizer::arrayOrEmpty($input['entry'] ?? null);
        if ($entry === []) {
            $entry = $input;
        }

        $latest = (new AcosMeasureSeriesFreshnessReader)->lastAppendAt($entry);

        return [
            self::FIELD_SCHEMA_VERSION => AcosMeasureSeriesFreshnessReader::SCHEMA,
            'series' => AiValueNormalizer::trimmedStringOrNull($entry['series'] ?? null) ?? '',
            'source_type' => AiValueNormalizer::trimmedStringOrNull($entry['source_type'] ?? null) ?? '',
            'path' => AiValueNormalizer::trimmedStringOrNull($entry['path'] ?? null) ?? '',
            'table' => AiValueNormalizer::trimmedStringOrNull($entry['table'] ?? null) ?? '',
            'last_append_at' => $latest?->toIso8601String(),
            'fresh' => $latest !== null,
        ];
    }

    public function phaseHandoffCatalogueObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => AaeosPhaseHandoffService::SCHEMA_VERSION,
            'phase_count' => count(AaeosPhaseHandoffService::PHASES),
            'phases' => AaeosPhaseHandoffService::PHASES,
            'autonomy_level_int' => AaeosPhaseHandoffService::autonomyLevelInt(
                AiValueNormalizer::trimmedStringOrNull($input['autonomy_level'] ?? null) ?? 'L0',
            ),
        ];
    }

    public function stringListNormalizeObserve(array $input = []): array
    {
        $values = AiValueNormalizer::arrayOrEmpty($input['values'] ?? null);

        return [
            self::FIELD_SCHEMA_VERSION => self::OBSERVE_STRING_LIST_NORMALIZE_SCHEMA,
            'trimmed_strings' => AtlasStringListNormalizer::trimmedStrings($values),
            'unique_trimmed_strings' => AtlasStringListNormalizer::uniqueTrimmedStrings($values),
            'non_empty_strings' => AtlasStringListNormalizer::nonEmptyStrings($values),
            'trimmed_string_or_int_values' => AtlasStringListNormalizer::trimmedStringOrIntValues($values),
        ];
    }

    public function thresholdComparatorObserve(array $input = []): array
    {
        $comparator = AiValueNormalizer::trimmedStringOrNull($input['comparator'] ?? null) ?? '>=';
        $observed = AiValueNormalizer::finiteFloatOrNull($input['observed'] ?? null) ?? 0.0;
        $threshold = AiValueNormalizer::finiteFloatOrNull($input['threshold'] ?? null) ?? 0.0;

        return [
            self::FIELD_SCHEMA_VERSION => self::OBSERVE_THRESHOLD_COMPARATOR_SCHEMA,
            'comparator' => $comparator,
            'observed' => $observed,
            'threshold' => $threshold,
            'binary_satisfied' => AtlasThresholdComparator::binarySatisfied($comparator, $observed, $threshold),
            'satisfied' => AtlasThresholdComparator::satisfied($comparator, $observed, $threshold),
        ];
    }

    public function evidenceRefNormalizeObserve(array $input = []): array
    {
        $normalizer = new AtlasEvidenceRefNormalizer;
        $refs = $normalizer->listFromRaw($input['evidence_refs'] ?? []);

        return [
            self::FIELD_SCHEMA_VERSION => self::OBSERVE_EVIDENCE_REF_NORMALIZE_SCHEMA,
            self::FIELD_COUNT => count($refs),
            'evidence_refs' => $refs,
        ];
    }

    public function arrayFieldReaderObserve(array $input = []): array
    {
        $row = AiValueNormalizer::arrayOrEmpty($input['row'] ?? []);
        $key = AiValueNormalizer::trimmedStringOrNull($input['key'] ?? null) ?? 'id';

        return [
            self::FIELD_SCHEMA_VERSION => self::OBSERVE_ARRAY_FIELD_READER_SCHEMA,
            'key' => $key,
            'string_field' => AtlasArrayFieldReader::stringField($row, $key),
        ];
    }

    public function departmentCanonicalListObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => AtlasDepartmentRegistryService::SCHEMA,
            'canonical_departments' => AtlasDepartmentRegistryService::CANONICAL_DEPARTMENTS,
            self::FIELD_COUNT => count(AtlasDepartmentRegistryService::CANONICAL_DEPARTMENTS),
            'required_fields' => AtlasDepartmentRegistryService::REQUIRED_FIELDS,
            'required_field_count' => count(AtlasDepartmentRegistryService::REQUIRED_FIELDS),
            'valid_maturity' => AtlasDepartmentRegistryService::VALID_MATURITY,
            'valid_maturity_count' => count(AtlasDepartmentRegistryService::VALID_MATURITY),
        ];
    }

    public function universalGatesCatalogueObserve(array $input = []): array
    {
        $gates = $this->catalogue();

        return [
            self::FIELD_SCHEMA_VERSION => self::OBSERVE_UNIVERSAL_GATES_CATALOGUE_SCHEMA,
            self::FIELD_COUNT => count($gates),
            'gates' => $gates,
        ];
    }

    public function outcomeAttributionTypesObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => self::OBSERVE_OUTCOME_ATTRIBUTION_TYPES_SCHEMA,
            'outcome_types' => AiOutcomeAttributionService::OUTCOME_TYPES,
            self::FIELD_COUNT => count(AiOutcomeAttributionService::OUTCOME_TYPES),
        ];
    }

    public function phaseRouterValidPhasesObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => AtlasPhaseRouterService::SCHEMA_VERSION,
            'valid_phases' => AtlasPhaseRouterService::VALID_PHASES,
            self::FIELD_COUNT => count(AtlasPhaseRouterService::VALID_PHASES),
            'active_phase_ranks' => AtlasPhaseRouterService::ACTIVE_PHASE_RANKS,
            'active_phase_count' => count(AtlasPhaseRouterService::ACTIVE_PHASE_RANKS),
            'phase_descriptions' => AtlasPhaseRouterService::PHASE_DESCRIPTIONS,
        ];
    }

    public function choreographyHandoffKindsObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => AtlasCrossDepartmentChoreographyService::HANDOFF_SCHEMA,
            'handoff_kinds' => AtlasCrossDepartmentChoreographyService::HANDOFF_KINDS,
            self::FIELD_COUNT => count(AtlasCrossDepartmentChoreographyService::HANDOFF_KINDS),
            'veto_sla_seconds' => AtlasCrossDepartmentChoreographyService::VETO_SLA_SECONDS,
            'repair_max_iterations' => AtlasCrossDepartmentChoreographyService::REPAIR_MAX_ITERATIONS,
        ];
    }

    public function realityCompilerPhasesObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => RealityCompilerSlice::SCHEMA_VERSION,
            'execution_phases' => RealityCompilerSlice::EXECUTION_PHASES,
            self::FIELD_COUNT => count(RealityCompilerSlice::EXECUTION_PHASES),
        ];
    }

    public function scopeRiskClassesObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => AtlasSelfConstructionScopeRiskBudgetGate::SCHEMA,
            'risk_classes' => AtlasSelfConstructionScopeRiskBudgetGate::RISKS,
            self::FIELD_COUNT => count(AtlasSelfConstructionScopeRiskBudgetGate::RISKS),
            'risk_floor_default' => AtlasSelfConstructionScopeRiskBudgetGate::RISK_FLOOR_DEFAULT,
        ];
    }

    public function organMeshPhasesObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => AtlasExternalBrainOrganMeshOrchestrator::SCHEMA,
            'phases' => AtlasExternalBrainOrganMeshOrchestrator::PHASES,
            self::FIELD_COUNT => count(AtlasExternalBrainOrganMeshOrchestrator::PHASES),
        ];
    }

    public function telemetrySurfacesObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => self::OBSERVE_TELEMETRY_COLLECTOR_SURFACES_SCHEMA,
            'surfaces' => AiTelemetryCollector::SURFACES,
            'runtimes' => AiTelemetryCollector::RUNTIMES,
            'surface_count' => count(AiTelemetryCollector::SURFACES),
            'runtime_count' => count(AiTelemetryCollector::RUNTIMES),
        ];
    }

    public function phaseSignatureL4Observe(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => AaeosPhaseHandoffService::SCHEMA_VERSION,
            'phases_requiring_signature_at_l4' => AaeosPhaseHandoffService::PHASES_REQUIRING_SIGNATURE_AT_L4,
            self::FIELD_COUNT => count(AaeosPhaseHandoffService::PHASES_REQUIRING_SIGNATURE_AT_L4),
        ];
    }

    public function blockerSeverityLevelsObserve(array $input = []): array
    {
        $levels = [
            AaeosBlockerSeverity::CRITICAL,
            AaeosBlockerSeverity::HIGH,
            AaeosBlockerSeverity::MEDIUM,
            AaeosBlockerSeverity::LOW,
        ];

        return [
            self::FIELD_SCHEMA_VERSION => self::OBSERVE_BLOCKER_SEVERITY_SCHEMA,
            'levels' => $levels,
            self::FIELD_COUNT => count($levels),
            'decisive_levels' => [AaeosBlockerSeverity::CRITICAL, AaeosBlockerSeverity::HIGH],
        ];
    }

    public function scopeHighRisksObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => AtlasSelfConstructionScopeRiskBudgetGate::SCHEMA,
            'high_risks' => AtlasSelfConstructionScopeRiskBudgetGate::HIGH_RISKS,
            self::FIELD_COUNT => count(AtlasSelfConstructionScopeRiskBudgetGate::HIGH_RISKS),
            'max_failure_rate' => AtlasSelfConstructionScopeRiskBudgetGate::MAX_FAILURE_RATE,
            'max_give_back_rate' => AtlasSelfConstructionScopeRiskBudgetGate::MAX_GIVE_BACK_RATE,
        ];
    }

    public function architectSpecCatalogueObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => ArchitectAgentSpecPackGateContract::SCHEMA,
            'required_spec_pack_artifacts' => ArchitectAgentSpecPackGateContract::REQUIRED_SPEC_PACK_ARTIFACTS,
            'gates' => ArchitectAgentSpecPackGateContract::GATES,
            'artifact_count' => count(ArchitectAgentSpecPackGateContract::REQUIRED_SPEC_PACK_ARTIFACTS),
            'gate_count' => count(ArchitectAgentSpecPackGateContract::GATES),
            'min_autonomous_risk_scope' => ArchitectAgentSpecPackGateContract::MIN_AUTONOMOUS_RISK_SCOPE,
            'operator_signature_required_from' => ArchitectAgentSpecPackGateContract::OPERATOR_SIGNATURE_REQUIRED_FROM,
        ];
    }

    public function surpriseGateBandsObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => self::OBSERVE_SURPRISE_GATE_BANDS_SCHEMA,
            'default_threshold' => AtlasSurpriseGateService::DEFAULT_THRESHOLD,
            'default_high_band' => AtlasSurpriseGateService::DEFAULT_HIGH_BAND,
            'default_min_prediction_tokens' => AtlasSurpriseGateService::DEFAULT_MIN_PREDICTION_TOKENS,
            'unit_interval' => [0.0, 1.0],
            'fail_open_when_prediction_thin' => true,
        ];
    }

    public function immuneCalibrationContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => ImmuneCalibrationService::SCHEMA_VERSION,
            'measure_id' => ImmuneCalibrationService::MEASURE_ID,
            'formula_version' => ImmuneCalibrationService::FORMULA_VERSION,
            'denominator_min' => ImmuneCalibrationService::DENOMINATOR_MIN,
            'ttl_days' => ImmuneCalibrationService::TTL_DAYS,
            'gate_ids' => ImmuneCalibrationService::GATE_IDS,
            'gate_count' => count(ImmuneCalibrationService::GATE_IDS),
        ];
    }

    public function cognitiveImmuneCheckContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => CognitiveImmuneCheckContract::SCHEMA,
            'gate_ids' => CognitiveImmuneCheckContract::GATE_IDS,
            'gate_count' => count(CognitiveImmuneCheckContract::GATE_IDS),
            'check_categories' => CognitiveImmuneCheckContract::CHECK_CATEGORIES,
            'allowed_gate_statuses' => CognitiveImmuneCheckContract::ALLOWED_GATE_STATUSES,
            'default_gate_status' => CognitiveImmuneCheckContract::DEFAULT_GATE_STATUS,
            'hostile_classes' => ImmuneSignatureDeriver::HOSTILE_CLASSES,
            'signature_family_schema' => ImmuneSignatureDeriver::SCHEMA_VERSION,
        ];
    }

    public function cognitionEvidenceStatusesObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => self::OBSERVE_EVIDENCE_STATUSES_SCHEMA,
            'evidence_statuses' => [
                AtlasCognitionEvidenceResolver::STATUS_READY,
                AtlasCognitionEvidenceResolver::STATUS_PARTIAL,
                AtlasCognitionEvidenceResolver::STATUS_BUILDING,
                AtlasCognitionEvidenceResolver::STATUS_BLOCKED,
            ],
            'scorecard_v4_schema' => AtlasCognitionScoreCardV4Grouper::SCHEMA_VERSION,
            'consumer_groups' => AtlasCognitionScoreCardV4Grouper::CONSUMER_GROUPS,
            'consumer_group_count' => count(AtlasCognitionScoreCardV4Grouper::CONSUMER_GROUPS),
        ];
    }

    public function captureHmacLineageObserve(array $input = []): array
    {
        $healthCatalog = HealthReportWatchdogCheck::catalog();

        return [
            self::FIELD_SCHEMA_VERSION => CaptureHmacLineageService::SCHEMA_VERSION,
            'genesis_receipt' => CaptureHmacLineageService::GENESIS_RECEIPT,
            'stages' => [
                CaptureHmacLineageService::STAGE_SOURCE,
                CaptureHmacLineageService::STAGE_CAPTURE,
                CaptureHmacLineageService::STAGE_MEMORY,
            ],
            'threat_model' => CaptureHmacLineageService::THREAT_MODEL,
            'health_report_check_ids' => array_values(array_map(
                static fn (array $row): string => (AiValueNormalizer::trimmedStringOrNull($row['id'] ?? null) ?? ''),
                $healthCatalog,
            )),
            'health_report_check_count' => count($healthCatalog),
        ];
    }

    public function cognitiveFunctionAxesObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => AtlasCognitiveFunctionDecomposerService::SCHEMA,
            'functions' => AtlasCognitiveFunctionDecomposerService::FUNCTIONS,
            'function_count' => count(AtlasCognitiveFunctionDecomposerService::FUNCTIONS),
            'rule_axes' => array_keys(AtlasCognitiveFunctionDecomposerService::RULES),
            'rule_axis_count' => count(AtlasCognitiveFunctionDecomposerService::RULES),
        ];
    }

    public function gateSignalContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => AtlasGateSignalEvaluator::SCHEMA_VERSION,
            'gates' => [
                AtlasGateSignalEvaluator::GATE_INTENT_CLARITY,
                AtlasGateSignalEvaluator::GATE_SPEC_PACK,
                AtlasGateSignalEvaluator::GATE_TASK_PACK,
            ],
            'intent_clarity_threshold' => AtlasGateSignalEvaluator::INTENT_CLARITY_THRESHOLD,
            'spec_pack_min_criteria' => AtlasGateSignalEvaluator::SPEC_PACK_MIN_CRITERIA,
            'weights' => [
                'resolved' => AtlasGateSignalEvaluator::WEIGHT_RESOLVED,
                'bounded' => AtlasGateSignalEvaluator::WEIGHT_BOUNDED,
                'no_ambiguity' => AtlasGateSignalEvaluator::WEIGHT_NO_AMBIGUITY,
                'no_missing' => AtlasGateSignalEvaluator::WEIGHT_NO_MISSING,
            ],
            'compound_connectors' => AtlasGateSignalEvaluator::COMPOUND_CONNECTORS,
            'teto10_schema' => Teto10PredictedRevertReviewDigest::SCHEMA_VERSION,
            'teto10_band_rank' => Teto10PredictedRevertReviewDigest::BAND_RANK,
        ];
    }

    public function rollbackTriggerContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => AtlasAcosRollbackTriggerCheckService::SCHEMA_VERSION,
            'default_executor' => 'watchdog_alert_operator_reverts',
            'auto_revert' => false,
            'alert_code' => 'rollback_trigger_fired',
        ];
    }

    public function longHorizonGateContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => AtlasAcosLongHorizonGateService::SCHEMA_VERSION,
            'fixtures' => ['live', 'mature', 'short-window'],
            'ready_status' => 'acos_long_horizon_ready',
            'blocked_status' => 'insufficient_long_horizon_evidence',
        ];
    }

    public function immuneSignatureStoreContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => ImmuneSignatureStore::SCHEMA_VERSION,
            'measure_id' => ImmuneSignatureStore::MEASURE_ID,
            'statuses' => [
                ImmuneSignatureStore::STATUS_ACTIVE,
                ImmuneSignatureStore::STATUS_REVOKED,
                ImmuneSignatureStore::STATUS_DECAYED,
            ],
            'origins' => [
                ImmuneSignatureStore::ORIGIN_VERDICT,
                ImmuneSignatureStore::ORIGIN_MEMORY_REVERT,
            ],
        ];
    }

    public function promotionProtocolStatesObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => PromotionProtocol::SCHEMA,
            'report_schema' => PromotionProtocol::REPORT_SCHEMA,
            'states' => PromotionProtocol::STATES,
            'state_count' => count(PromotionProtocol::STATES),
        ];
    }

    public function autonomousWorkCycleStagesObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => AutonomousWorkExecutionOs::SCHEMA_VERSION,
            'autonomy_levels' => AutonomousWorkExecutionOs::AUTONOMY_LEVELS,
            'cycle_stages' => AutonomousWorkExecutionOs::CYCLE_STAGES,
            'stage_count' => count(AutonomousWorkExecutionOs::CYCLE_STAGES),
            'stage_statuses' => AutonomousWorkExecutionOs::STAGE_STATUSES,
            'stage_status_count' => count(AutonomousWorkExecutionOs::STAGE_STATUSES),
        ];
    }

    public function immuneVerdictLedgerLabelsObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => ImmuneVerdictLedger::SCHEMA_VERSION,
            'labels' => ImmuneVerdictLedger::LABELS,
            'label_count' => count(ImmuneVerdictLedger::LABELS),
        ];
    }

    public function flywheelFunnelStagesObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => AtlasFlywheelFunnelService::SCHEMA_VERSION,
            'measure_id' => AtlasFlywheelFunnelService::MEASURE_ID,
            'formula_version' => AtlasFlywheelFunnelService::FORMULA_VERSION,
            'stages' => AtlasFlywheelFunnelService::STAGES,
            'stage_count' => count(AtlasFlywheelFunnelService::STAGES),
        ];
    }

    public function missionControlCockpitSchemaObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => AtlasMissionControlCockpitService::SCHEMA_VERSION,
            'phase_count' => count(AaeosPhaseHandoffService::PHASES),
            'phases' => AaeosPhaseHandoffService::PHASES,
        ];
    }

    public function evidenceVisionThesisLifecycleObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => EvidenceVisionThesisLifecycle::SCHEMA_VERSION,
            'composer_schema' => EvidenceVisionThesisComposer::SCHEMA_VERSION,
            'max_theses' => EvidenceVisionThesisComposer::MAX_THESES,
            'min_regression_windows' => EvidenceVisionThesisComposer::MIN_REGRESSION_WINDOWS,
            'default_ttl_days' => EvidenceVisionThesisComposer::DEFAULT_TTL_DAYS,
            'allowed_evidence_sources' => EvidenceVisionThesisComposer::ALLOWED_EVIDENCE_SOURCES,
            'allowed_evidence_source_count' => count(EvidenceVisionThesisComposer::ALLOWED_EVIDENCE_SOURCES),
            'active_accessor' => 'activeTheses',
            'supports_reset' => true,
            'remint_touched_schema' => AtlasCognitionRemintTouchedQueue::SCHEMA_VERSION,
        ];
    }

    public function exploratoryBetsPortfolioContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => ExploratoryBetsPortfolio::SCHEMA_VERSION,
            'default_k' => ExploratoryBetsPortfolio::DEFAULT_K,
            'default_window_days' => ExploratoryBetsPortfolio::DEFAULT_WINDOW_DAYS,
            'min_n' => ExploratoryBetsPortfolio::MIN_N,
            'double_down_multiplier' => ExploratoryBetsPortfolio::DOUBLE_DOWN_MULTIPLIER,
        ];
    }

    public function memoryFeedbackDecayContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => MemoryFeedbackDecayScorer::SCHEMA_VERSION,
            'hard_stale_age_days' => MemoryFeedbackDecayScorer::HARD_STALE_AGE_DAYS,
            'soft_stale_age_days' => MemoryFeedbackDecayScorer::SOFT_STALE_AGE_DAYS,
            'default_base_priority' => MemoryFeedbackDecayScorer::DEFAULT_BASE_PRIORITY,
            'archive_stale_feedback_threshold' => MemoryFeedbackDecayScorer::ARCHIVE_STALE_FEEDBACK_THRESHOLD,
            'inactivate_negative_threshold' => MemoryFeedbackDecayScorer::INACTIVATE_NEGATIVE_THRESHOLD,
            'inactivate_health_ceiling' => MemoryFeedbackDecayScorer::INACTIVATE_HEALTH_CEILING,
            'degrade_health_ceiling' => MemoryFeedbackDecayScorer::DEGRADE_HEALTH_CEILING,
        ];
    }

    public function specCompletenessContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => SpecCompletenessScorer::SCHEMA_VERSION,
            'text_min_length' => SpecCompletenessScorer::TEXT_MIN_LENGTH,
            'total_fields' => SpecCompletenessScorer::TOTAL_FIELDS,
            'complete_threshold' => SpecCompletenessScorer::COMPLETE_THRESHOLD,
            'partial_threshold' => SpecCompletenessScorer::PARTIAL_THRESHOLD,
            'list_fields' => SpecCompletenessScorer::LIST_FIELDS,
            'list_field_count' => count(SpecCompletenessScorer::LIST_FIELDS),
        ];
    }

    public function outcomeEnvelopeContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => OutcomeEnvelope::SCHEMA_VERSION,
            'formula_version' => OutcomeEnvelope::FORMULA_VERSION,
            'adapter_origins' => OutcomeEnvelope::ADAPTER_ORIGINS,
            'statuses' => OutcomeEnvelope::STATUSES,
            'origin_count' => count(OutcomeEnvelope::ADAPTER_ORIGINS),
            'status_count' => count(OutcomeEnvelope::STATUSES),
        ];
    }

    public function preReviewAdvisoryContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => PreReviewAdvisoryBand::SCHEMA_VERSION,
            'formula_version' => PreReviewAdvisoryBand::FORMULA_VERSION,
            'min_n_for_band' => PreReviewAdvisoryBand::MIN_N_FOR_BAND,
            'death_min_n' => PreReviewAdvisoryBand::DEATH_MIN_N,
            'death_min_lift' => PreReviewAdvisoryBand::DEATH_MIN_LIFT,
            'blocks_auto_apply' => false,
            'delays_auto_apply' => false,
        ];
    }

    public function ambitionRungPolicyContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => AmbitionRungPolicy::SCHEMA_VERSION,
            'rungs' => AmbitionRungPolicy::RUNGS,
            'rung_count' => count(AmbitionRungPolicy::RUNGS),
            'scope_has_ceiling' => false,
            'provider_calls_made' => false,
        ];
    }

    public function reactiveSaturationContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => ReactiveSaturationSignal::SCHEMA_VERSION,
            'min_n_per_window' => ReactiveSaturationSignal::MIN_N_PER_WINDOW,
            'min_windows' => ReactiveSaturationSignal::MIN_WINDOWS,
            'report_only' => true,
            'disables_reactive_lane' => false,
            'provider_calls_made' => false,
        ];
    }

    public function portfolioBudgetContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => PortfolioBudgetAllocator::SCHEMA_VERSION,
            'formula_version' => PortfolioBudgetAllocator::FORMULA_VERSION,
            'classes' => PortfolioBudgetAllocator::CLASSES,
            'class_count' => count(PortfolioBudgetAllocator::CLASSES),
            'hard_floor_share' => PortfolioBudgetAllocator::HARD_FLOOR_SHARE,
            'hard_ceiling_share' => PortfolioBudgetAllocator::HARD_CEILING_SHARE,
            'min_n_per_class' => PortfolioBudgetAllocator::MIN_N_PER_CLASS,
            'allocator_writes_own_weights' => false,
        ];
    }

    public function predictedImpactBandContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => PredictedImpactBand::SCHEMA_VERSION,
            'bands' => PredictedImpactBand::BANDS,
            'band_count' => count(PredictedImpactBand::BANDS),
            'rung_weights' => PredictedImpactBand::RUNG_WEIGHT,
            'influences_pick' => false,
            'single_scalar_score_emitted' => false,
        ];
    }

    public function gatedCorpusContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => GatedCorpusCandidateMiner::SCHEMA_VERSION,
            'protected_classes' => GatedCorpusCandidateMiner::PROTECTED,
            'protected_class_count' => count(GatedCorpusCandidateMiner::PROTECTED),
            'candidate_only' => true,
            'writes_memory_directly' => false,
            'count_is_acceptance' => false,
        ];
    }

    public function claimDefinitionOfDoneContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => AtlasClaimDefinitionOfDoneValidator::SCHEMA_VERSION,
            'evaluated_against' => AtlasClaimDefinitionOfDoneValidator::EVALUATED_AGAINST,
            'canonical_fields' => AtlasClaimDefinitionOfDoneValidator::CANONICAL_FIELDS,
            'unconditional_fields' => AtlasClaimDefinitionOfDoneValidator::UNCONDITIONAL_FIELDS,
            'canonical_field_count' => count(AtlasClaimDefinitionOfDoneValidator::CANONICAL_FIELDS),
            'unconditional_field_count' => count(AtlasClaimDefinitionOfDoneValidator::UNCONDITIONAL_FIELDS),
            'field_statuses' => [
                AtlasClaimDefinitionOfDoneValidator::STATUS_PRESENT,
                AtlasClaimDefinitionOfDoneValidator::STATUS_MISSING,
                AtlasClaimDefinitionOfDoneValidator::STATUS_NOT_APPLICABLE,
            ],
            'verdicts' => [
                AtlasClaimDefinitionOfDoneValidator::VERDICT_EVIDENCE,
                AtlasClaimDefinitionOfDoneValidator::VERDICT_NARRATIVE,
            ],
        ];
    }

    public function qualityBarTelemetryContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => QualityBarTelemetryContract::SCHEMA,
            'quality_bar_schema' => QualityBarTelemetryContract::QUALITY_BAR_SCHEMA,
            'immune_gate_id' => QualityBarTelemetryContract::IMMUNE_GATE_ID,
            'breach_signal' => QualityBarTelemetryContract::BREACH_SIGNAL,
            'evaluated_window_days' => QualityBarTelemetryContract::EVALUATED_WINDOW_DAYS,
            'auto_block_on_breach' => QualityBarTelemetryContract::AUTO_BLOCK_ON_BREACH,
            'evidence_required' => QualityBarTelemetryContract::EVIDENCE_REQUIRED,
            'telemetry_fields' => QualityBarTelemetryContract::TELEMETRY_FIELDS,
        ];
    }

    public function docMaturityContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => AtlasDocMaturityClassifier::SCHEMA_VERSION,
            'levels' => AtlasDocMaturityClassifier::LEVELS,
            'level_count' => count(AtlasDocMaturityClassifier::LEVELS),
            'boolean_requirements' => AtlasDocMaturityClassifier::BOOLEAN_REQUIREMENTS,
            'strength_requirements' => AtlasDocMaturityClassifier::STRENGTH_REQUIREMENTS,
            'l4_signals' => AtlasDocMaturityClassifier::L4_SIGNALS,
            'runtime_ready_always' => false,
        ];
    }

    public function attemptLifecycleContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => AttemptLifecycleLedger::SCHEMA_VERSION,
            'terminal_states' => AttemptLifecycleLedger::TERMINAL_STATES,
            'terminal_state_count' => count(AttemptLifecycleLedger::TERMINAL_STATES),
            'outcome_without_attempt_allowed' => false,
            'attempt_id_deduped' => true,
        ];
    }

    public function esp09ChallengerContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => Esp09IndependentChallengerService::SCHEMA_VERSION,
            'measure_id' => Esp09IndependentChallengerService::MEASURE_ID,
            'mode' => Esp09IndependentChallengerService::MODE,
            'high_alignment_band' => Esp09IndependentChallengerService::HIGH_ALIGNMENT_BAND,
            'trigger_kinds' => Esp09IndependentChallengerService::TRIGGER_KINDS,
            'trigger_kind_count' => count(Esp09IndependentChallengerService::TRIGGER_KINDS),
            'advisory_only' => true,
            'gates_override' => false,
        ];
    }

    public function deliveryPackContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => DeliveryPackCompletenessScorer::SCHEMA,
            'required_keys' => DeliveryPackCompletenessScorer::REQUIRED_KEYS,
            'required_key_count' => count(DeliveryPackCompletenessScorer::REQUIRED_KEYS),
            'statuses' => DeliveryPackCompletenessScorer::STATUSES,
            'blocker_missing_hash' => DeliveryPackCompletenessScorer::BLOCKER_MISSING_HASH,
            'blocker_evidence_required' => DeliveryPackCompletenessScorer::BLOCKER_EVIDENCE_REQUIRED,
        ];
    }

    public function segmentImportanceContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => SegmentImportanceRanker::SCHEMA_VERSION,
            'kind_weights' => SegmentImportanceRanker::KIND_WEIGHT,
            'kind_weight_count' => count(SegmentImportanceRanker::KIND_WEIGHT),
            'kind_weight_unknown' => SegmentImportanceRanker::KIND_WEIGHT_UNKNOWN,
            'evidence_ref_bonus' => SegmentImportanceRanker::EVIDENCE_REF_BONUS,
            'decision_or_blocker_link_bonus' => SegmentImportanceRanker::DECISION_OR_BLOCKER_LINK_BONUS,
            'dedup_step_penalty' => SegmentImportanceRanker::DEDUP_STEP_PENALTY,
            'dedup_penalty_cap' => SegmentImportanceRanker::DEDUP_PENALTY_CAP,
            'drop_reasons' => [
                SegmentImportanceRanker::DROP_REASON_BUDGET_EXCEEDED,
                SegmentImportanceRanker::DROP_REASON_OVERSIZED_SEGMENT,
            ],
        ];
    }

    public function cognitiveImmunePromotionGateContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => CognitiveImmunePromotionGateEvaluator::SCHEMA_VERSION,
            'gate_ids' => CognitiveImmunePromotionGateEvaluator::GATE_IDS,
            'gate_count' => count(CognitiveImmunePromotionGateEvaluator::GATE_IDS),
            'statuses' => [
                CognitiveImmunePromotionGateEvaluator::STATUS_PASS,
                CognitiveImmunePromotionGateEvaluator::STATUS_BLOCK,
                CognitiveImmunePromotionGateEvaluator::STATUS_PENDING,
            ],
            'known_scopes' => CognitiveImmunePromotionGateEvaluator::KNOWN_SCOPES,
            'allowed_promotion_modes' => CognitiveImmunePromotionGateEvaluator::ALLOWED_PROMOTION_MODES,
            'blocked_promotion_modes' => CognitiveImmunePromotionGateEvaluator::BLOCKED_PROMOTION_MODES,
            'probation_min_recall_actors' => CognitiveImmunePromotionGateEvaluator::PROBATION_MIN_RECALL_ACTORS,
        ];
    }

    public function cognitiveImmuneInputClassifierContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => AtlasCognitiveImmuneInputClassifier::SCHEMA_VERSION,
            'recurrence_memory_threshold' => AtlasCognitiveImmuneInputClassifier::RECURRENCE_MEMORY_THRESHOLD,
            'destination_classes' => array_keys(AtlasCognitiveImmuneInputClassifier::DESTINATIONS),
            'destination_class_count' => count(AtlasCognitiveImmuneInputClassifier::DESTINATIONS),
            'embedding_forbidden_classes' => AtlasCognitiveImmuneInputClassifier::EMBEDDING_FORBIDDEN_CLASSES,
            'injection_marker_count' => count(AtlasCognitiveImmuneInputClassifier::INJECTION_MARKERS),
            'strategic_marker_count' => count(AtlasCognitiveImmuneInputClassifier::STRATEGIC_MARKERS),
            'technical_marker_count' => count(AtlasCognitiveImmuneInputClassifier::TECHNICAL_MARKERS),
            'personal_marker_count' => count(AtlasCognitiveImmuneInputClassifier::PERSONAL_MARKERS),
            'project_evidence_marker_count' => count(AtlasCognitiveImmuneInputClassifier::PROJECT_EVIDENCE_MARKERS),
            'conversation_marker_count' => count(AtlasCognitiveImmuneInputClassifier::CONVERSATION_MARKERS),
            'veto_propagation_schema' => AtlasVetoPropagationResolver::SCHEMA_VERSION,
            'repair_loop_auto_escalation_threshold' => AtlasVetoPropagationResolver::REPAIR_LOOP_AUTO_ESCALATION_THRESHOLD,
            'promotion_eligibility_schema' => AtlasDepartmentPromotionEligibilityEvaluator::SCHEMA_VERSION,
            'promotion_max_evidence_age_days' => AtlasDepartmentPromotionEligibilityEvaluator::DEFAULT_MAX_EVIDENCE_AGE_DAYS,
            'promotion_max_tier' => AtlasDepartmentPromotionEligibilityEvaluator::DEFAULT_MAX_TIER,
            'consolidation_rerank_schema' => AtlasConsolidationRerankGuard::SCHEMA_VERSION,
            'consolidation_rerank_epsilon' => AtlasConsolidationRerankGuard::EPSILON,
        ];
    }

    public function outcomeCausalityWeightsContractObserve(array $input = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => OutcomeCausalityRanker::SCHEMA_VERSION,
            'primary_causes' => OutcomeCausalityRanker::PRIMARY_CAUSES,
            'primary_cause_count' => count(OutcomeCausalityRanker::PRIMARY_CAUSES),
            'outcomes' => OutcomeCausalityRanker::OUTCOMES,
            'outcome_count' => count(OutcomeCausalityRanker::OUTCOMES),
            'status_succeeded' => OutcomeCausalityRanker::STATUS_SUCCEEDED,
            'weights' => [
                OutcomeCausalityRanker::CAUSE_MISSING_EVIDENCE => OutcomeCausalityRanker::WEIGHT_MISSING_EVIDENCE,
                OutcomeCausalityRanker::CAUSE_TESTS_FAILED => OutcomeCausalityRanker::WEIGHT_TESTS_FAILED,
                OutcomeCausalityRanker::CAUSE_EXECUTION_FAILED_OR_BLOCKED => OutcomeCausalityRanker::WEIGHT_EXECUTION_FAILED_OR_BLOCKED,
                OutcomeCausalityRanker::CAUSE_CONTEXT_MISSING_REQUIRED_SOURCES => OutcomeCausalityRanker::WEIGHT_CONTEXT_MISSING_REQUIRED_SOURCES,
                OutcomeCausalityRanker::CAUSE_EXECUTION_STRATEGY_LIKELY_SUCCEEDED => OutcomeCausalityRanker::WEIGHT_EXECUTION_STRATEGY_LIKELY_SUCCEEDED,
                OutcomeCausalityRanker::CAUSE_SCOPE_OR_CONTRACT_MISMATCH => OutcomeCausalityRanker::WEIGHT_SCOPE_OR_CONTRACT_MISMATCH,
                OutcomeCausalityRanker::CAUSE_PACKET_QUALITY_FAILURE => OutcomeCausalityRanker::WEIGHT_PACKET_QUALITY_FAILURE,
            ],
            'weight_count' => 7,
        ];
    }

    public function httpPathWatchdogObserveSchemasContractObserve(array $input = []): array
    {
        return [
            'http_path_status_schema' => AtlasAaeosHttpPathFacadeService::STATUS_SCHEMA,
            'http_path_request_schema' => AtlasAaeosHttpPathFacadeService::REQUEST_SCHEMA,
            'aurg_coverage_schema' => AtlasAcosWatchdogHealthService::AURG_COVERAGE_SCHEMA,
            'rag_dimension_schema' => AtlasAcosWatchdogHealthService::RAG_DIMENSION_SCHEMA,
            'pipeline_scorecard_stability_schema' => AtlasAcosWatchdogHealthService::PIPELINE_SCORECARD_STABILITY_SCHEMA,
            'ope_lift_cycle_closure_schema' => AtlasAcosWatchdogHealthService::OPE_LIFT_CYCLE_CLOSURE_SCHEMA,
            'ope_scorecard_receipts_diagnosis_schema' => AtlasAcosWatchdogHealthService::OPE_SCORECARD_RECEIPTS_DIAGNOSIS_SCHEMA,
            'onda4_emitter_version' => AtlasAcosWatchdogHealthService::ONDA4_EMITTER_VERSION,
            'observe_ledger_rotation_schema' => self::OBSERVE_LEDGER_ROTATION_SCHEMA,
            'observe_universal_gates_catalogue_schema' => self::OBSERVE_UNIVERSAL_GATES_CATALOGUE_SCHEMA,
            'observe_evidence_statuses_schema' => self::OBSERVE_EVIDENCE_STATUSES_SCHEMA,
            'observe_surprise_gate_bands_schema' => self::OBSERVE_SURPRISE_GATE_BANDS_SCHEMA,
            'observe_schema_count' => 13,
        ];
    }

    public function evaluatorObserveHelpersContractObserve(array $input = []): array
    {
        return [
            'observe_evidence_vision_schema' => self::OBSERVE_EVIDENCE_VISION_SCHEMA,
            'observe_threshold_ladder_schema' => self::OBSERVE_THRESHOLD_LADDER_SCHEMA,
            'observe_string_list_normalize_schema' => self::OBSERVE_STRING_LIST_NORMALIZE_SCHEMA,
            'observe_threshold_comparator_schema' => self::OBSERVE_THRESHOLD_COMPARATOR_SCHEMA,
            'observe_evidence_ref_normalize_schema' => self::OBSERVE_EVIDENCE_REF_NORMALIZE_SCHEMA,
            'observe_array_field_reader_schema' => self::OBSERVE_ARRAY_FIELD_READER_SCHEMA,
            'observe_outcome_attribution_types_schema' => self::OBSERVE_OUTCOME_ATTRIBUTION_TYPES_SCHEMA,
            'observe_telemetry_collector_surfaces_schema' => self::OBSERVE_TELEMETRY_COLLECTOR_SURFACES_SCHEMA,
            'observe_blocker_severity_schema' => self::OBSERVE_BLOCKER_SEVERITY_SCHEMA,
            'long_horizon_gate_schema' => AtlasAcosLongHorizonGateService::SCHEMA_VERSION,
            'long_horizon_area_v2_schema' => AtlasAcosLongHorizonGateService::AREA_V2_SCHEMA,
            'evaluator_observe_helper_count' => 11,
        ];
    }

    public function gateReportSchemaContractObserve(array $input = []): array
    {
        return [
            'gate_report_schema' => self::SCHEMA_VERSION,
            'universal_gates_catalogue_observe_schema' => self::OBSERVE_UNIVERSAL_GATES_CATALOGUE_SCHEMA,
            'catalogue_gate_count' => count($this->catalogue()),
            'observe_helper_schema_count' => 13,
        ];
    }

    public function repairParallelPromoterVerifiedCockpitDeferredWindowRemintScorecardFloorsContractObserve(array $input = []): array
    {
        return [
            'escalate' => AtlasRepairLoopGuard::FIELD_ESCALATE,
            self::FIELD_SCHEMA_VERSION => AtlasRepairLoopGuard::FIELD_SCHEMA_VERSION,
            'meta' => AcosMaxParallelExecutionProtocol::FIELD_META,
            'protocol' => AcosMaxParallelExecutionProtocol::FIELD_PROTOCOL,
            'frontier_promotes' => AcosMaxProceduralSkillPromoterService::FIELD_FRONTIER_PROMOTES,
            'kind' => AcosMaxProceduralSkillPromoterService::FIELD_KIND,
            'freeze' => AcosMaxVerifiedShareService::FIELD_FREEZE,
            'freeze_required' => AcosMaxVerifiedShareService::FIELD_FREEZE_REQUIRED,
            'brakes' => AcosProgramCockpitService::FIELD_BRAKES,
            'current_lote' => AcosProgramCockpitService::FIELD_CURRENT_LOTE,
            'enqueued' => AaeosDeferredPhaseDispatcherService::FIELD_ENQUEUED,
            'enqueued_at' => AaeosDeferredPhaseDispatcherService::FIELD_ENQUEUED_AT,
            'components' => AtlasAcosWindowGatesService::FIELD_COMPONENTS,
            'fresh' => AtlasAcosWindowGatesService::FIELD_FRESH,
            'command' => AtlasCognitionRemintTouchedQueue::FIELD_COMMAND,
            'command_args' => AtlasCognitionRemintTouchedQueue::FIELD_COMMAND_ARGS,
            'governance' => AtlasCognitionScoreCardV4Grouper::FIELD_GOVERNANCE,
            'group' => AtlasCognitionScoreCardV4Grouper::FIELD_GROUP,
            'repair_parallel_promoter_verified_cockpit_deferred_window_remint_scorecard_floor_count' => 18,
        ];
    }

    public function obraRetroAcosRollbackWindowOrchestratorLongAaeosFloorsContractObserve(array $input = []): array
    {
        return [
            'id' => AcosMaxObraRetroService::FIELD_ID,
            'objective' => AcosMaxObraRetroService::FIELD_OBJECTIVE,
            'id' => AtlasAcosRollbackTriggerCheckService::FIELD_ID,
            'env' => AtlasAcosRollbackTriggerCheckService::FIELD_ENV,
            'id' => AcosMaxWindowOrchestratorService::FIELD_ID,
            'dead_after_days' => AcosMaxWindowOrchestratorService::FIELD_DEAD_AFTER_DAYS,
            'status' => AtlasAcosLongHorizonGateService::FIELD_STATUS,
            'config' => AtlasAcosLongHorizonGateService::FIELD_CONFIG,
            'next_band' => AtlasDepartmentMaturityBandClassifier::FIELD_NEXT_BAND,
            'next_band_breaches' => AtlasDepartmentMaturityBandClassifier::FIELD_NEXT_BAND_BREACHES,
            'class' => AtlasCapabilityTestExecutionService::FIELD_CLASS,
            'explain' => AtlasCapabilityTestExecutionService::FIELD_EXPLAIN,
            'denominator_min_active_symbols' => AtlasCodeSymbolEmbeddingCoverageService::FIELD_DENOMINATOR_MIN_ACTIVE_SYMBOLS,
            'dual_read_required' => AtlasCodeSymbolEmbeddingCoverageService::FIELD_DUAL_READ_REQUIRED,
            'dual_read_required' => AtlasKnowledgeItemEmbeddingCoverageService::FIELD_DUAL_READ_REQUIRED,
            'judge_engine_id' => AtlasKnowledgeItemEmbeddingCoverageService::FIELD_JUDGE_ENGINE_ID,
            'autonomy_governance' => AtlasAcosEvolutionScoreService::FIELD_AUTONOMY_GOVERNANCE,
            self::FIELD_COUNT => AtlasAcosEvolutionScoreService::FIELD_COUNT,
            'obra_retro_acos_rollback_window_orchestrator_long_aaeos_floor_count' => 18,
        ];
    }

    public function httpPathCognitionScoreDepartmentLevelAaeosDocFloorsContractObserve(array $input = []): array
    {
        return [
            'id' => AaeosHttpPathEnvelopeFactory::FIELD_ID,
            'policy_status' => AaeosHttpPathEnvelopeFactory::FIELD_POLICY_STATUS,
            'v4' => AtlasCognitionScoreCardService::FIELD_V4,
            'code' => AtlasCognitionScoreCardService::FIELD_CODE,
            'value' => AtlasDepartmentLevelClassifier::FIELD_VALUE,
            'thresholds' => AtlasDepartmentLevelClassifier::FIELD_THRESHOLDS,
            'satisfied' => AtlasDepartmentQualityBarLevelClassifier::FIELD_SATISFIED,
            'threshold' => AtlasDepartmentQualityBarLevelClassifier::FIELD_THRESHOLD,
            'level_ordinal' => AtlasDocMaturityClassifier::FIELD_LEVEL_ORDINAL,
            'missing_for_next' => AtlasDocMaturityClassifier::FIELD_MISSING_FOR_NEXT,
            'fabricates_rate_on_zero_n' => PreReviewAdvisoryBand::FIELD_FABRICATES_RATE_ON_ZERO_N,
            'lift_basis' => PreReviewAdvisoryBand::FIELD_LIFT_BASIS,
            'id' => PhaseAdvanceVerdictClassifier::FIELD_ID,
            'operator_signature' => PhaseAdvanceVerdictClassifier::FIELD_OPERATOR_SIGNATURE,
            'at' => AtlasFrontierWaveLadder::FIELD_AT,
            'event_threshold' => AtlasFrontierWaveLadder::FIELD_EVENT_THRESHOLD,
            self::FIELD_COUNT => CognitiveImmunePromotionGateEvaluator::FIELD_COUNT,
            'recall_concentration_v2' => CognitiveImmunePromotionGateEvaluator::FIELD_RECALL_CONCENTRATION_V2,
            'http_path_cognition_score_department_level_aaeos_doc_floor_count' => 18,
        ];
    }
}
