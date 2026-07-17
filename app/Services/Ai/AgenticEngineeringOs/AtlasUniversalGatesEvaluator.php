<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\Aaeos\Cores\SpecCompletenessScorer;
use App\Services\Ai\Aaeos\Cores\SummaryFidelityCoverageScorer;
use App\Services\Ai\Aaeos\Cores\MemoryInjectionBudgetAllocator;
use App\Services\Ai\Aaeos\Cores\MemoryFeedbackDecayScorer;
use App\Services\Ai\Aaeos\Cores\SegmentImportanceRanker;
use App\Services\Ai\Aaeos\Cores\ContextParetoDominanceFilter;
use App\Services\Ai\Aaeos\Cores\AtlasMemoryRecallRelevanceScorer;
use App\Services\Ai\Aaeos\Cores\OutcomeCausalityRanker;
use App\Services\Ai\AcosMax\PredictedImpactBand;
use App\Services\Ai\AcosMax\PreReviewAdvisoryBand;
use App\Services\Ai\AcosMax\Esp09IndependentChallengerService;
use App\Services\Ai\AcosMax\DogfoodingFrictionLeadMiner;
use App\Services\Ai\AcosMax\ReactiveSaturationSignal;
use App\Services\Ai\AcosMax\PortfolioBudgetAllocator;
use App\Services\Ai\AcosMax\AmbitionRungPolicy;
use App\Services\Ai\AcosMax\AsefChunkIndexService;
use App\Services\Ai\AcosMax\DomainLexicalNormalizer;
use App\Services\Ai\AcosMax\GatedCorpusCandidateMiner;
use App\Services\Ai\AcosMax\StructuredFactSchemaMap;
use App\Services\Ai\AcosMax\CitationGroundingMeter;
use App\Services\Ai\AcosMax\ProvenanceWeightCalculator;
use App\Services\Ai\AcosMax\RecallGapAggregator;
use App\Services\Ai\AcosMax\BeliefCascadeReverificationPlanner;
use App\Services\Ai\AcosMax\AcosMaxLedgerRotationRegistry;
use App\Services\Ai\AcosMax\EvidenceVisionThesisComposer;
use App\Services\Ai\AcosMax\ExecutionContextCooccurrenceService;
use App\Services\Ai\Aaeos\AtlasAaeosGateSignalEvaluator;
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
use App\Services\Ai\AcosMax\AcosMaxObraRetroService;
use App\Services\Ai\AcosMax\AcosMaxParallelExecutionProtocol;
use App\Services\Ai\AcosMax\AcosMaxWindowOrchestratorService;
use App\Services\Ai\AcosMax\AcosProgramCockpitService;
use App\Services\Ai\AcosMax\OutcomeEnvelopeBridge;
use App\Services\Ai\Cognition\AtlasCognitiveFunctionDecomposerService;
use App\Services\Ai\Cognition\AtlasAcosRollbackTriggerCheckService;
use App\Services\Ai\Cognition\AtlasAcosLongHorizonGateService;
use App\Services\Ai\Cognition\AtlasAcosWindowGatesService;
use App\Services\Ai\Cognition\AtlasAcosEvolutionScoreService;
use App\Services\Ai\Cognition\AtlasImmuneHybridInputClassifier;
use App\Services\Ai\Cognition\ImmuneSignatureStore;
use App\Services\Ai\Cognition\ImmuneSignatureIngestor;
use App\Services\Ai\Cognition\ImmuneVerdictLedger;
use App\Services\Ai\AcosMax\PromotionProtocol;
use App\Services\Ai\AcosMax\AtlasFlywheelFunnelService;
use App\Services\Ai\AcosMax\EvidenceVisionThesisLifecycle;
use App\Services\Ai\Aaeos\AtlasAaeosThresholdLadderNormalizer;
use App\Services\Ai\AcosMax\AtlasKnowledgeItemEmbeddingCoverageService;
use App\Services\Ai\AcosMax\AtlasCodeSymbolEmbeddingCoverageService;
use App\Services\Ai\AcosMax\Teto10PredictedRevertReviewDigest;
use App\Services\Ai\AcosMax\Maxa04JinaV3DualReadLedger;
use App\Services\Ai\AcosMax\Maxa04JinaV3DualReadService;
use App\Services\Ai\AcosMax\AtlasResourceBudgetService;
use App\Services\Ai\AcosMax\AtlasModelCapabilitySpecService;
use App\Services\Ai\AcosMax\AcosMeasureSeriesFreshnessReader;
use App\Services\Ai\AcosMax\AcosMaxVerifiedShareService;
use App\Services\Ai\AcosMax\RagxChainMechanismService;
use App\Services\Ai\AcosMax\AcosMaxProceduralSkillPromoterService;
use App\Services\Ai\AcosMax\GoldenCounterfactualReplayService;
use App\Services\Ai\AcosMax\ComposedObraArcComposer;
use App\Services\Ai\AcosMax\ComposedObraArcLifecycle;
use App\Services\Ai\AcosMax\ExploratoryBetsPortfolio;
use App\Services\Ai\AcosMax\OutcomeEnvelope;
use App\Services\Ai\AcosMax\AttemptLifecycleLedger;
use App\Services\Ai\AcosMax\AtlasNCaptureDrillService;
use App\Services\Ai\AcosMax\AcosMaxLote2MeasureService;
use App\Services\Ai\AcosMax\AtlasLocalModelIntegrityService;
use App\Services\Ai\AcosMax\AemorOutcomeEnvelopeAdapter;
use App\Services\Ai\AcosMax\CompoundingOutcomeEnvelopeAdapter;
use App\Services\Ai\AcosMax\DevProceduralOutcomeEnvelopeAdapter;
use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;
use App\Services\Ai\Aaeos\AtlasAaeosThresholdComparator;
use App\Services\Ai\Aaeos\AtlasAaeosEvidenceRefNormalizer;
use App\Services\Ai\Aaeos\AtlasAaeosDocMaturityClassifier;
use App\Services\Ai\Aaeos\AtlasAaeosClaimDefinitionOfDoneValidator;
use App\Services\Ai\Aaeos\Support\AtlasAaeosArrayFieldReader;
use App\Services\Ai\Aaeos\AtlasAaeosVetoPropagationResolver;
use App\Services\Ai\Aaeos\AtlasAaeosDepartmentRegistryService;
use App\Services\Ai\Aaeos\AtlasAaeosCognitiveImmuneInputClassifier;
use App\Services\Ai\Telemetry\AiOutcomeAttributionService;
use App\Services\Ai\Aaeos\AtlasAaeosPhaseRouterService;
use App\Services\Ai\SelfConstruction\ControlPlane\AtlasSelfConstructionScopeRiskBudgetGate;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOrganMeshOrchestrator;
use App\Services\Ai\Telemetry\AiTelemetryCollector;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCausalEffectGate;
use App\Services\Ai\Aaeos\AtlasAaeosQualityBarService;
use App\Services\Ai\Aaeos\AtlasAaeosDepartmentMaturityService;
use App\Services\Ai\Aaeos\AtlasVetoPropagationWatchdog;
use App\Services\Ai\Aaeos\AtlasCrossDepartmentChoreographyService;
use App\Services\Ai\Aaeos\AtlasRepairLoopGuard;
use App\Services\Ai\Aaeos\AaeosGeneratedContractGate;
use App\Services\Ai\Aaeos\AtlasAaeosDepartmentMaturityBandClassifier;
use App\Services\Ai\Aaeos\AtlasAaeosDepartmentPromotionEligibilityEvaluator;
use App\Services\Ai\Aaeos\AtlasDebugRootCauseService;
use App\Services\Ai\Aaeos\AaeosDepartmentLevelClassifier;
use App\Services\Ai\Aaeos\AtlasAaeosDepartmentQualityBarLevelClassifier;
use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use App\Services\Ai\Aaeos\AtlasDocsAuthorityGraphService;
use App\Services\Ai\Aaeos\AtlasAaeosImplementationEvidenceResolver;
use App\Services\Ai\Aaeos\AtlasAaeosTestExecutionService;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use App\Services\Ai\Support\AiValueNormalizer;
use RuntimeException;

/**
 * Atlas Universal Gates Evaluator — produces `atlas.aaeos.gate_report.v1`
 * covering the 15 universal gates of Phase 11 (`gate_evaluation`) in the
 * AAEOS runbook.
 *
 * Each universal gate corresponds to a real signal already produced by a
 * dedicated runtime service. The evaluator does NOT re-implement the
 * checks; it consumes a `signals` map (gate_id => bool|null|"exception")
 * and synthesizes a deterministic report with required/passed/blocked
 * lists, an overall `outcome` (green|red|exception), and the provider-safe
 * receipt hash.
 *
 * Exception semantics: a gate may report `exception` when a department
 * documents a justified bypass (e.g. doc-only change skipping `tests_green`).
 * Exceptions require `exception_receipt_id` in the signal payload.
 */
final class AtlasUniversalGatesEvaluator
{
    public const SCHEMA_VERSION = 'atlas.aaeos.gate_report.v1';

    public const OBSERVE_LEDGER_ROTATION_SCHEMA = 'atlas.aaeos.ledger_rotation_observe.v1';

    public const OBSERVE_EVIDENCE_VISION_SCHEMA = 'atlas.aaeos.evidence_vision_observe.v1';

    public const OBSERVE_THRESHOLD_LADDER_SCHEMA = 'atlas.aaeos.threshold_ladder_observe.v1';

    public const OBSERVE_STRING_LIST_NORMALIZE_SCHEMA = 'atlas.aaeos.string_list_normalize.v1';

    public const OBSERVE_THRESHOLD_COMPARATOR_SCHEMA = 'atlas.aaeos.threshold_comparator.v1';

    public const OBSERVE_EVIDENCE_REF_NORMALIZE_SCHEMA = 'atlas.aaeos.evidence_ref_normalize.v1';

    public const OBSERVE_ARRAY_FIELD_READER_SCHEMA = 'atlas.aaeos.array_field_reader.v1';

    public const OBSERVE_UNIVERSAL_GATES_CATALOGUE_SCHEMA = 'atlas.aaeos.universal_gates_catalogue.v1';

    public const OBSERVE_OUTCOME_ATTRIBUTION_TYPES_SCHEMA = 'atlas.aaeos.outcome_attribution_types.v1';

    public const OBSERVE_TELEMETRY_COLLECTOR_SURFACES_SCHEMA = 'atlas.telemetry.collector.surfaces.v1';

    public const OBSERVE_BLOCKER_SEVERITY_SCHEMA = 'atlas.aaeos.blocker_severity.v1';

    public const OBSERVE_SURPRISE_GATE_BANDS_SCHEMA = 'atlas.cognition.surprise_gate.bands.v1';

    public const OBSERVE_EVIDENCE_STATUSES_SCHEMA = 'atlas.cognition.evidence_statuses.v1';


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
        private readonly AtlasMemoryRecallRelevanceScorer $memoryRecallRelevance = new AtlasMemoryRecallRelevanceScorer,
    ) {}

    /**
     * The 15 canonical universal gates of AAEOS Phase 11.
     *
     * Each gate maps to:
     *   - description (provider-safe summary)
     *   - canonical_source (service or evidence schema that produces the signal)
     *
     * @var array<string,array{description:string, canonical_source:string}>
     */
    public const UNIVERSAL_GATES = [
        'lint_green' => [
            'description' => 'Linter passes on all changed files.',
            'canonical_source' => 'tools.lint_runner',
        ],
        'typecheck_green' => [
            'description' => 'Static type check passes (PHPStan/tsc/mypy).',
            'canonical_source' => 'tools.typecheck_runner',
        ],
        'tests_green' => [
            'description' => 'Selected + regression test suite is green.',
            'canonical_source' => 'tools.test_runner',
        ],
        'coverage_min_threshold' => [
            'description' => 'Coverage meets the project floor for the touched scope.',
            'canonical_source' => 'tools.coverage_reporter',
        ],
        'scope_guard_ok' => [
            'description' => 'No file outside declared scope was modified.',
            'canonical_source' => 'governance.scope_guard',
        ],
        'security_scan_clean' => [
            'description' => 'Security scanner (SAST / OWASP) reports no findings above threshold.',
            'canonical_source' => 'security.scan_runner',
        ],
        'dependency_audit_clean' => [
            'description' => 'Dependency CVE audit reports no unacknowledged vulns.',
            'canonical_source' => 'security.dependency_audit',
        ],
        'secret_scan_clean' => [
            'description' => 'No new secrets committed; existing secrets remain quarantined.',
            'canonical_source' => 'security.secret_scan',
        ],
        'sovereignty_boundary_respected' => [
            'description' => 'No sensitive/secret/cyber payload crossed local-first boundary.',
            'canonical_source' => 'security.sovereignty_gate',
        ],
        'decision_receipt_v2_signed' => [
            'description' => 'Decision Receipt v2 is signed and persisted before execution.',
            'canonical_source' => 'governance.decision_receipt_v2',
        ],
        'evidence_traceable' => [
            'description' => 'Every claim links to a hash-addressable evidence artifact.',
            'canonical_source' => 'evidence.ledger',
        ],
        'rollback_plan_present' => [
            'description' => 'Rollback plan exists for every breaking change.',
            'canonical_source' => 'delivery.rollback_planner',
        ],
        'review_packet_signed' => [
            'description' => 'Review department signed the review packet for this delivery.',
            'canonical_source' => 'review.packet_signer',
        ],
        'delivery_pack_completeness_min_0_95' => [
            'description' => 'Delivery pack completeness score >= 0.95.',
            'canonical_source' => DeliveryPackCompletenessScorer::class,
        ],
        'learning_capsule_registered' => [
            'description' => 'A learning_capsule was registered with ACOS for compounding.',
            'canonical_source' => 'memory.learning_capsule_registry',
        ],
    ];

    /**
     * Evaluate the 15 universal gates against a signals map.
     *
     * @param  array<string,bool|string|null>  $signals  gate_id => true|false|null|'exception'
     * @param  array<string,string>            $exceptionReceipts  gate_id => receipt_id for `exception`
     * @return array<string,mixed>
     */
    public function evaluate(string $intentId, array $signals, array $exceptionReceipts = []): array
    {
        AaeosPhaseHandoffService::requireIntentId($intentId);

        $required = array_keys(self::UNIVERSAL_GATES);
        $passed = [];
        $blocked = [];
        $exception = [];
        $missing = [];

        foreach ($required as $gateId) {
            $signal = $signals[$gateId] ?? null;
            if ($signal === true) {
                $passed[] = $gateId;
                continue;
            }
            if ($signal === false) {
                $blocked[] = $gateId;
                continue;
            }
            if ($signal === 'exception') {
                if (! isset($exceptionReceipts[$gateId]) || $exceptionReceipts[$gateId] === '') {
                    $blocked[] = $gateId;

                    continue;
                }
                $exception[] = ['gate' => $gateId, 'receipt_id' => $exceptionReceipts[$gateId]];

                continue;
            }
            $missing[] = $gateId;
        }

        $outcome = $this->computeOutcome($passed, $blocked, $exception, $missing);

        $report = [
            'schema' => self::SCHEMA_VERSION,
            'intent_id' => $intentId,
            'gate_count' => count($required),
            'required' => $required,
            'passed' => $passed,
            'blocked' => $blocked,
            'exception' => $exception,
            'missing' => $missing,
            'outcome' => $outcome,
            'pass_rate' => $required === [] ? 0.0 : round((count($passed) + count($exception)) / count($required), 4),
            'provider_safe' => true,
            'evaluated_at' => gmdate('c'),
        ];
        $report['report_hash'] = 'sha256:'.hash('sha256', json_encode([
            $report['intent_id'],
            $report['passed'],
            $report['blocked'],
            $report['exception'],
            $report['missing'],
        ]) ?: '');

        return $report;
    }

    /**
     * @param  list<string>  $passed
     * @param  list<string>  $blocked
     * @param  list<array<string,string>>  $exception
     * @param  list<string>  $missing
     */
    private function computeOutcome(array $passed, array $blocked, array $exception, array $missing): string
    {
        if ($blocked !== []) {
            return 'red';
        }
        if ($missing !== []) {
            return 'pending';
        }
        if ($exception !== []) {
            return 'exception';
        }

        return 'green';
    }

    /**
     * Derive the boolean signal for `delivery_pack_completeness_min_0_95`
     * from a delivery-pack composition via {@see DeliveryPackCompletenessScorer}.
     *
     * Passed only when status is `passed` and ratio >= 0.95 (gate floor).
     *
     * @param  array<string,mixed>  $composition
     */
    public function deliveryPackCompletenessSignal(
        array $composition,
        ?DeliveryPackCompletenessScorer $scorer = null,
        float $minRatio = 0.95,
    ): bool {
        return ($scorer ?? $this->deliveryPackCompleteness)->passesMin($composition, $minRatio);
    }

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
     * Derive a boolean signal from a compiled-spec shape via
     * {@see SpecCompletenessScorer}. Observe helper for callers that already
     * hold a spec map — does not add a new universal gate id.
     *
     * @param  array<string,mixed>  $spec
     */
    public function specCompletenessSignal(
        array $spec,
        ?SpecCompletenessScorer $scorer = null,
        int $minScore = SpecCompletenessScorer::COMPLETE_THRESHOLD,
    ): bool {
        return ($scorer ?? $this->specCompleteness)->passesMin($spec, $minScore);
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

    /**
     * Observe-only memory recall relevance ranking.
     * Accepts a list of candidate rows or `{rows|candidates:[...]}`.
     * Does not add a universal-gate id (catalogue stays 15).
     *
     * @param  array<mixed>  $input
     * @return array<string,mixed>
     */
    public function memoryRecallRankObserve(array $input): array
    {
        $rows = array_is_list($input)
            ? $input
            : AiValueNormalizer::arrayOrEmpty($input['rows'] ?? $input['candidates'] ?? null);

        /** @var array<int,array<string,mixed>> $rows */
        $ranked = $this->memoryRecallRelevance->rank($rows);

        return [
            'schema_version' => AtlasMemoryRecallRelevanceScorer::SCHEMA_VERSION,
            'ranked' => $ranked,
            'count' => count($ranked),
        ];
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
     * Observe-only MAXB10 domain lexical normalizer score/tokens.
     * Accepts `{query:string, fields?:[...]}` (or `contract_only`/`mode=contract`).
     * Does not add a universal-gate id (catalogue stays 15).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function domainLexicalObserve(array $input): array
    {
        if (($input['contract_only'] ?? false) === true
            || AiValueNormalizer::lowerTrimmedString($input['mode'] ?? '') === 'contract') {
            return DomainLexicalNormalizer::contract();
        }

        $query = AiValueNormalizer::trimmedStringOrNull($input['query'] ?? null) ?? '';
        $fields = AiValueNormalizer::arrayOrEmpty($input['fields'] ?? null);

        return [
            'schema_version' => DomainLexicalNormalizer::SCHEMA_VERSION,
            'formula_version' => DomainLexicalNormalizer::FORMULA_VERSION,
            'query' => $query,
            'score' => DomainLexicalNormalizer::score($query, $fields),
            'tokens' => DomainLexicalNormalizer::tokens($query),
            'contract' => DomainLexicalNormalizer::contract(),
        ];
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
     * Observe-only ledger rotation policy lookup (ELEV-24).
     * Accepts `{series:string}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function ledgerRotationObserve(array $input): array
    {
        $registry = new AcosMaxLedgerRotationRegistry;
        $series = AiValueNormalizer::trimmedStringOrNull($input['series'] ?? null) ?? '';
        $policy = $series === '' ? null : $registry->policyFor($series);

        return [
            'schema_version' => self::OBSERVE_LEDGER_ROTATION_SCHEMA,
            'series' => $series,
            'found' => $policy !== null,
            'policy' => $policy,
            'declared_series_count' => count($registry->all()),
        ];
    }

    /**
     * Observe-only evidence-vision thesis fence / field-source check.
     * Accepts `{thesis:{...}, forbidden?:[...]}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evidenceVisionObserve(array $input): array
    {
        $thesis = AiValueNormalizer::arrayOrEmpty($input['thesis'] ?? null);
        $forbidden = AiValueNormalizer::arrayOrEmpty($input['forbidden'] ?? null);
        /** @var list<string> $forbidden */

        return [
            'schema_version' => self::OBSERVE_EVIDENCE_VISION_SCHEMA,
            'field_sources_valid' => EvidenceVisionThesisComposer::thesisFieldSourcesValid($thesis),
            'operator_fence_pass' => EvidenceVisionThesisComposer::thesisPassesOperatorFence($thesis, $forbidden),
        ];
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
        return (new AtlasAaeosGateSignalEvaluator)->evaluateSpecPackAcceptanceCriteria($input);
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
        return (new AtlasAaeosGateSignalEvaluator)->evaluateIntentClarity($input);
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
        return (new AtlasAaeosGateSignalEvaluator)->evaluateTaskPackAtomicity($input);
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
        return (new AtlasAaeosGateSignalEvaluator)->evaluatePhaseGates($input);
    }

    /**
     * Observe-only AAEOS threshold ladder normalization.
     * Accepts a level-ladder list or `{band_ladder|ladder:[...]}`. Catalogue stays 15.
     *
     * @param  array<mixed>  $input
     * @return array<string,mixed>
     */
    public function thresholdLadderObserve(array $input): array
    {
        $ladder = array_is_list($input)
            ? $input
            : AiValueNormalizer::arrayOrEmpty($input['band_ladder'] ?? $input['ladder'] ?? null);
        /** @var list<array{level:string,thresholds:list<array{metric:string,comparator:string,value:float}>}> $ladder */
        $normalized = AtlasAaeosThresholdLadderNormalizer::levelLadder($ladder);

        return [
            'schema_version' => self::OBSERVE_THRESHOLD_LADDER_SCHEMA,
            'valid' => $normalized !== [] || $ladder === [],
            'band_count' => count($normalized),
            'ladder' => $normalized,
        ];
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
     * Observe-only MAXA-04 Jina v3 dual-read ledger path resolution.
     * Accepts optional `{path?:string}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function jinaDualReadLedgerObserve(array $input = []): array
    {
        $path = AiValueNormalizer::trimmedStringOrNull($input['path'] ?? null);
        $ledger = new Maxa04JinaV3DualReadLedger($path);

        return [
            'schema_version' => Maxa04JinaV3DualReadLedger::SCHEMA,
            'path' => $ledger->path(),
            'relative_path' => Maxa04JinaV3DualReadLedger::RELATIVE_PATH,
            'custom_path' => $path !== null,
        ];
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
     * Observe-only ELEV-31 measure-series freshness probe.
     * Accepts `{entry?:object}` or a bare registry entry. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function measureSeriesFreshnessObserve(array $input = []): array
    {
        $entry = AiValueNormalizer::arrayOrEmpty($input['entry'] ?? null);
        if ($entry === []) {
            $entry = $input;
        }

        $latest = (new AcosMeasureSeriesFreshnessReader)->lastAppendAt($entry);

        return [
            'schema_version' => AcosMeasureSeriesFreshnessReader::SCHEMA,
            'series' => AiValueNormalizer::trimmedStringOrNull($entry['series'] ?? null) ?? '',
            'source_type' => AiValueNormalizer::trimmedStringOrNull($entry['source_type'] ?? null) ?? '',
            'path' => AiValueNormalizer::trimmedStringOrNull($entry['path'] ?? null) ?? '',
            'table' => AiValueNormalizer::trimmedStringOrNull($entry['table'] ?? null) ?? '',
            'last_append_at' => $latest?->toIso8601String(),
            'fresh' => $latest !== null,
        ];
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

        return (new AtlasAaeosPhaseRouterService($phase))->statusSnapshot();
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
        return (new AtlasAaeosQualityBarService)->qualityBar();
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
        return (new AtlasAaeosDepartmentMaturityService)->maturity();
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
        return (new AaeosGeneratedContractGate)->status();
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
            return (new AtlasAaeosDepartmentMaturityBandClassifier)->classifyDepartments($ladders, $snapshots);
        }

        return (new AtlasAaeosDepartmentMaturityBandClassifier)->classify(
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
        return (new AtlasAaeosDepartmentPromotionEligibilityEvaluator)->evaluate(
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
        return (new AaeosDepartmentLevelClassifier)->classify(
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
        return (new AtlasAaeosDepartmentQualityBarLevelClassifier)->classify(
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

        return app(AtlasAaeosImplementationTruthService::class)->evaluate(
            AiValueNormalizer::trimmedStringOrNull($input['claimed_state'] ?? null) ?? 'spec',
            AiValueNormalizer::arrayOrEmpty($input['resolutions'] ?? null),
            $greenTestRun,
        );
    }

    /**
     * Observe-only AAEOS phase-handoff catalogue (17 canonical phases).
     * Accepts any JSON object (ignored). Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function phaseHandoffCatalogueObserve(array $input = []): array
    {
        return [
            'schema_version' => AaeosPhaseHandoffService::SCHEMA_VERSION,
            'phase_count' => count(AaeosPhaseHandoffService::PHASES),
            'phases' => AaeosPhaseHandoffService::PHASES,
            'autonomy_level_int' => AaeosPhaseHandoffService::autonomyLevelInt(
                AiValueNormalizer::trimmedStringOrNull($input['autonomy_level'] ?? null) ?? 'L0',
            ),
        ];
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
     * Observe-only AAEOS string-list normalization helpers.
     * Accepts `{values}` (list). Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function stringListNormalizeObserve(array $input = []): array
    {
        $values = AiValueNormalizer::arrayOrEmpty($input['values'] ?? null);

        return [
            'schema_version' => self::OBSERVE_STRING_LIST_NORMALIZE_SCHEMA,
            'trimmed_strings' => AtlasAaeosStringListNormalizer::trimmedStrings($values),
            'unique_trimmed_strings' => AtlasAaeosStringListNormalizer::uniqueTrimmedStrings($values),
            'non_empty_strings' => AtlasAaeosStringListNormalizer::nonEmptyStrings($values),
            'trimmed_string_or_int_values' => AtlasAaeosStringListNormalizer::trimmedStringOrIntValues($values),
        ];
    }

    /**
     * Observe-only AAEOS threshold comparator (binary + full).
     * Accepts `{comparator, observed, threshold}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function thresholdComparatorObserve(array $input = []): array
    {
        $comparator = AiValueNormalizer::trimmedStringOrNull($input['comparator'] ?? null) ?? '>=';
        $observed = AiValueNormalizer::finiteFloatOrNull($input['observed'] ?? null) ?? 0.0;
        $threshold = AiValueNormalizer::finiteFloatOrNull($input['threshold'] ?? null) ?? 0.0;

        return [
            'schema_version' => self::OBSERVE_THRESHOLD_COMPARATOR_SCHEMA,
            'comparator' => $comparator,
            'observed' => $observed,
            'threshold' => $threshold,
            'binary_satisfied' => AtlasAaeosThresholdComparator::binarySatisfied($comparator, $observed, $threshold),
            'satisfied' => AtlasAaeosThresholdComparator::satisfied($comparator, $observed, $threshold),
        ];
    }

    /**
     * Observe-only AAEOS evidence_ref normalization.
     * Accepts `{evidence_refs}` list. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evidenceRefNormalizeObserve(array $input = []): array
    {
        $normalizer = new AtlasAaeosEvidenceRefNormalizer;
        $refs = $normalizer->listFromRaw($input['evidence_refs'] ?? []);

        return [
            'schema_version' => self::OBSERVE_EVIDENCE_REF_NORMALIZE_SCHEMA,
            'count' => count($refs),
            'evidence_refs' => $refs,
        ];
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

        return (new AtlasAaeosDocMaturityClassifier)->classify($sections);
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

        return (new AtlasAaeosClaimDefinitionOfDoneValidator)->validate($claim);
    }

    /**
     * Observe-only AAEOS array field reader (stringField).
     * Accepts `{row, key}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function arrayFieldReaderObserve(array $input = []): array
    {
        $row = AiValueNormalizer::arrayOrEmpty($input['row'] ?? []);
        $key = AiValueNormalizer::trimmedStringOrNull($input['key'] ?? null) ?? 'id';

        return [
            'schema_version' => self::OBSERVE_ARRAY_FIELD_READER_SCHEMA,
            'key' => $key,
            'string_field' => AtlasAaeosArrayFieldReader::stringField($row, $key),
        ];
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

        return (new AtlasAaeosVetoPropagationResolver)->resolve($origin, $kind, $iteration);
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
        $registry = new AtlasAaeosDepartmentRegistryService;

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

        return (new AtlasAaeosCognitiveImmuneInputClassifier)->classify($text, $metadata);
    }

    /**
     * Observe-only AAEOS canonical department id list.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function departmentCanonicalListObserve(array $input = []): array
    {
        return [
            'schema_version' => AtlasAaeosDepartmentRegistryService::SCHEMA,
            'canonical_departments' => AtlasAaeosDepartmentRegistryService::CANONICAL_DEPARTMENTS,
            'count' => count(AtlasAaeosDepartmentRegistryService::CANONICAL_DEPARTMENTS),
            'required_fields' => AtlasAaeosDepartmentRegistryService::REQUIRED_FIELDS,
            'required_field_count' => count(AtlasAaeosDepartmentRegistryService::REQUIRED_FIELDS),
            'valid_maturity' => AtlasAaeosDepartmentRegistryService::VALID_MATURITY,
            'valid_maturity_count' => count(AtlasAaeosDepartmentRegistryService::VALID_MATURITY),
        ];
    }

    /**
     * Observe-only universal gate catalogue (descriptions only).
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function universalGatesCatalogueObserve(array $input = []): array
    {
        $gates = $this->catalogue();

        return [
            'schema_version' => self::OBSERVE_UNIVERSAL_GATES_CATALOGUE_SCHEMA,
            'count' => count($gates),
            'gates' => $gates,
        ];
    }

    /**
     * Observe-only outcome-attribution type catalogue.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function outcomeAttributionTypesObserve(array $input = []): array
    {
        return [
            'schema_version' => self::OBSERVE_OUTCOME_ATTRIBUTION_TYPES_SCHEMA,
            'outcome_types' => AiOutcomeAttributionService::OUTCOME_TYPES,
            'count' => count(AiOutcomeAttributionService::OUTCOME_TYPES),
        ];
    }

    /**
     * Observe-only AAEOS phase-router valid phase catalogue.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function phaseRouterValidPhasesObserve(array $input = []): array
    {
        return [
            'schema_version' => AtlasAaeosPhaseRouterService::SCHEMA_VERSION,
            'valid_phases' => AtlasAaeosPhaseRouterService::VALID_PHASES,
            'count' => count(AtlasAaeosPhaseRouterService::VALID_PHASES),
            'active_phase_ranks' => AtlasAaeosPhaseRouterService::ACTIVE_PHASE_RANKS,
            'active_phase_count' => count(AtlasAaeosPhaseRouterService::ACTIVE_PHASE_RANKS),
            'phase_descriptions' => AtlasAaeosPhaseRouterService::PHASE_DESCRIPTIONS,
        ];
    }

    /**
     * Observe-only cross-department choreography handoff kind catalogue.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function choreographyHandoffKindsObserve(array $input = []): array
    {
        return [
            'schema_version' => AtlasCrossDepartmentChoreographyService::HANDOFF_SCHEMA,
            'handoff_kinds' => AtlasCrossDepartmentChoreographyService::HANDOFF_KINDS,
            'count' => count(AtlasCrossDepartmentChoreographyService::HANDOFF_KINDS),
            'veto_sla_seconds' => AtlasCrossDepartmentChoreographyService::VETO_SLA_SECONDS,
            'repair_max_iterations' => AtlasCrossDepartmentChoreographyService::REPAIR_MAX_ITERATIONS,
        ];
    }

    /**
     * Observe-only Reality Compiler execution phase catalogue.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function realityCompilerPhasesObserve(array $input = []): array
    {
        return [
            'schema_version' => RealityCompilerSlice::SCHEMA_VERSION,
            'execution_phases' => RealityCompilerSlice::EXECUTION_PHASES,
            'count' => count(RealityCompilerSlice::EXECUTION_PHASES),
        ];
    }

    /**
     * Observe-only SelfConstruction scope risk-class catalogue.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function scopeRiskClassesObserve(array $input = []): array
    {
        return [
            'schema_version' => AtlasSelfConstructionScopeRiskBudgetGate::SCHEMA,
            'risk_classes' => AtlasSelfConstructionScopeRiskBudgetGate::RISKS,
            'count' => count(AtlasSelfConstructionScopeRiskBudgetGate::RISKS),
            'risk_floor_default' => AtlasSelfConstructionScopeRiskBudgetGate::RISK_FLOOR_DEFAULT,
        ];
    }

    /**
     * Observe-only ExternalBrain organ-mesh phase catalogue.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function organMeshPhasesObserve(array $input = []): array
    {
        return [
            'schema_version' => AtlasExternalBrainOrganMeshOrchestrator::SCHEMA,
            'phases' => AtlasExternalBrainOrganMeshOrchestrator::PHASES,
            'count' => count(AtlasExternalBrainOrganMeshOrchestrator::PHASES),
        ];
    }

    /**
     * Observe-only telemetry surface/runtime catalogues.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function telemetrySurfacesObserve(array $input = []): array
    {
        return [
            'schema_version' => self::OBSERVE_TELEMETRY_COLLECTOR_SURFACES_SCHEMA,
            'surfaces' => AiTelemetryCollector::SURFACES,
            'runtimes' => AiTelemetryCollector::RUNTIMES,
            'surface_count' => count(AiTelemetryCollector::SURFACES),
            'runtime_count' => count(AiTelemetryCollector::RUNTIMES),
        ];
    }

    /**
     * Observe-only AAEOS phases that require operator signature at L4+.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function phaseSignatureL4Observe(array $input = []): array
    {
        return [
            'schema_version' => AaeosPhaseHandoffService::SCHEMA_VERSION,
            'phases_requiring_signature_at_l4' => AaeosPhaseHandoffService::PHASES_REQUIRING_SIGNATURE_AT_L4,
            'count' => count(AaeosPhaseHandoffService::PHASES_REQUIRING_SIGNATURE_AT_L4),
        ];
    }

    /**
     * Observe-only AAEOS blocker severity level catalogue.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function blockerSeverityLevelsObserve(array $input = []): array
    {
        $levels = [
            AaeosBlockerSeverity::CRITICAL,
            AaeosBlockerSeverity::HIGH,
            AaeosBlockerSeverity::MEDIUM,
            AaeosBlockerSeverity::LOW,
        ];

        return [
            'schema_version' => self::OBSERVE_BLOCKER_SEVERITY_SCHEMA,
            'levels' => $levels,
            'count' => count($levels),
            'decisive_levels' => [AaeosBlockerSeverity::CRITICAL, AaeosBlockerSeverity::HIGH],
        ];
    }

    /**
     * Observe-only SelfConstruction high-risk class + burn ceilings.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function scopeHighRisksObserve(array $input = []): array
    {
        return [
            'schema_version' => AtlasSelfConstructionScopeRiskBudgetGate::SCHEMA,
            'high_risks' => AtlasSelfConstructionScopeRiskBudgetGate::HIGH_RISKS,
            'count' => count(AtlasSelfConstructionScopeRiskBudgetGate::HIGH_RISKS),
            'max_failure_rate' => AtlasSelfConstructionScopeRiskBudgetGate::MAX_FAILURE_RATE,
            'max_give_back_rate' => AtlasSelfConstructionScopeRiskBudgetGate::MAX_GIVE_BACK_RATE,
        ];
    }

    /**
     * Observe-only architect-agent spec-pack artefact/gate catalogue.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function architectSpecCatalogueObserve(array $input = []): array
    {
        return [
            'schema_version' => ArchitectAgentSpecPackGateContract::SCHEMA,
            'required_spec_pack_artifacts' => ArchitectAgentSpecPackGateContract::REQUIRED_SPEC_PACK_ARTIFACTS,
            'gates' => ArchitectAgentSpecPackGateContract::GATES,
            'artifact_count' => count(ArchitectAgentSpecPackGateContract::REQUIRED_SPEC_PACK_ARTIFACTS),
            'gate_count' => count(ArchitectAgentSpecPackGateContract::GATES),
            'min_autonomous_risk_scope' => ArchitectAgentSpecPackGateContract::MIN_AUTONOMOUS_RISK_SCOPE,
            'operator_signature_required_from' => ArchitectAgentSpecPackGateContract::OPERATOR_SIGNATURE_REQUIRED_FROM,
        ];
    }

    /**
     * Observe-only surprise-gate band defaults (Cognition T4-S2).
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function surpriseGateBandsObserve(array $input = []): array
    {
        return [
            'schema_version' => self::OBSERVE_SURPRISE_GATE_BANDS_SCHEMA,
            'default_threshold' => AtlasSurpriseGateService::DEFAULT_THRESHOLD,
            'default_high_band' => AtlasSurpriseGateService::DEFAULT_HIGH_BAND,
            'default_min_prediction_tokens' => AtlasSurpriseGateService::DEFAULT_MIN_PREDICTION_TOKENS,
            'unit_interval' => [0.0, 1.0],
            'fail_open_when_prediction_thin' => true,
        ];
    }

    /**
     * Observe-only immune-calibration measure contract (Cognition).
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function immuneCalibrationContractObserve(array $input = []): array
    {
        return [
            'schema_version' => ImmuneCalibrationService::SCHEMA_VERSION,
            'measure_id' => ImmuneCalibrationService::MEASURE_ID,
            'formula_version' => ImmuneCalibrationService::FORMULA_VERSION,
            'denominator_min' => ImmuneCalibrationService::DENOMINATOR_MIN,
            'ttl_days' => ImmuneCalibrationService::TTL_DAYS,
            'gate_ids' => ImmuneCalibrationService::GATE_IDS,
            'gate_count' => count(ImmuneCalibrationService::GATE_IDS),
        ];
    }

    /**
     * Observe-only cognitive-immune check contract + signature hostile classes.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function cognitiveImmuneCheckContractObserve(array $input = []): array
    {
        return [
            'schema_version' => CognitiveImmuneCheckContract::SCHEMA,
            'gate_ids' => CognitiveImmuneCheckContract::GATE_IDS,
            'gate_count' => count(CognitiveImmuneCheckContract::GATE_IDS),
            'check_categories' => CognitiveImmuneCheckContract::CHECK_CATEGORIES,
            'allowed_gate_statuses' => CognitiveImmuneCheckContract::ALLOWED_GATE_STATUSES,
            'default_gate_status' => CognitiveImmuneCheckContract::DEFAULT_GATE_STATUS,
            'hostile_classes' => ImmuneSignatureDeriver::HOSTILE_CLASSES,
            'signature_family_schema' => ImmuneSignatureDeriver::SCHEMA_VERSION,
        ];
    }

    /**
     * Observe-only ACOS evidence-resolver status + scorecard-v4 consumer groups.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function cognitionEvidenceStatusesObserve(array $input = []): array
    {
        return [
            'schema_version' => self::OBSERVE_EVIDENCE_STATUSES_SCHEMA,
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

    /**
     * Observe-only capture HMAC lineage stages + health-report watchdog catalog.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function captureHmacLineageObserve(array $input = []): array
    {
        $healthCatalog = HealthReportWatchdogCheck::catalog();

        return [
            'schema_version' => CaptureHmacLineageService::SCHEMA_VERSION,
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

    /**
     * Observe-only cognitive-function decomposition axes.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function cognitiveFunctionAxesObserve(array $input = []): array
    {
        return [
            'schema_version' => AtlasCognitiveFunctionDecomposerService::SCHEMA,
            'functions' => AtlasCognitiveFunctionDecomposerService::FUNCTIONS,
            'function_count' => count(AtlasCognitiveFunctionDecomposerService::FUNCTIONS),
            'rule_axes' => array_keys(AtlasCognitiveFunctionDecomposerService::RULES),
            'rule_axis_count' => count(AtlasCognitiveFunctionDecomposerService::RULES),
        ];
    }

    /**
     * Observe-only AAEOS gate-signal weights/thresholds + TETO-10 band rank.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function gateSignalContractObserve(array $input = []): array
    {
        return [
            'schema_version' => AtlasAaeosGateSignalEvaluator::SCHEMA_VERSION,
            'gates' => [
                AtlasAaeosGateSignalEvaluator::GATE_INTENT_CLARITY,
                AtlasAaeosGateSignalEvaluator::GATE_SPEC_PACK,
                AtlasAaeosGateSignalEvaluator::GATE_TASK_PACK,
            ],
            'intent_clarity_threshold' => AtlasAaeosGateSignalEvaluator::INTENT_CLARITY_THRESHOLD,
            'spec_pack_min_criteria' => AtlasAaeosGateSignalEvaluator::SPEC_PACK_MIN_CRITERIA,
            'weights' => [
                'resolved' => AtlasAaeosGateSignalEvaluator::WEIGHT_RESOLVED,
                'bounded' => AtlasAaeosGateSignalEvaluator::WEIGHT_BOUNDED,
                'no_ambiguity' => AtlasAaeosGateSignalEvaluator::WEIGHT_NO_AMBIGUITY,
                'no_missing' => AtlasAaeosGateSignalEvaluator::WEIGHT_NO_MISSING,
            ],
            'compound_connectors' => AtlasAaeosGateSignalEvaluator::COMPOUND_CONNECTORS,
            'teto10_schema' => Teto10PredictedRevertReviewDigest::SCHEMA_VERSION,
            'teto10_band_rank' => Teto10PredictedRevertReviewDigest::BAND_RANK,
        ];
    }

    /**
     * Observe-only ACOS rollback-trigger contract (ROL-01).
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function rollbackTriggerContractObserve(array $input = []): array
    {
        return [
            'schema_version' => AtlasAcosRollbackTriggerCheckService::SCHEMA_VERSION,
            'default_executor' => 'watchdog_alert_operator_reverts',
            'auto_revert' => false,
            'alert_code' => 'rollback_trigger_fired',
        ];
    }

    /**
     * Observe-only ACOS long-horizon gate contract (L6-9).
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function longHorizonGateContractObserve(array $input = []): array
    {
        return [
            'schema_version' => AtlasAcosLongHorizonGateService::SCHEMA_VERSION,
            'fixtures' => ['live', 'mature', 'short-window'],
            'ready_status' => 'acos_long_horizon_ready',
            'blocked_status' => 'insufficient_long_horizon_evidence',
        ];
    }

    /**
     * Observe-only immune signature store contract (MAXI-05).
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function immuneSignatureStoreContractObserve(array $input = []): array
    {
        return [
            'schema_version' => ImmuneSignatureStore::SCHEMA_VERSION,
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

    /**
     * Observe-only ACOS promotion-protocol states.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function promotionProtocolStatesObserve(array $input = []): array
    {
        return [
            'schema_version' => PromotionProtocol::SCHEMA,
            'report_schema' => PromotionProtocol::REPORT_SCHEMA,
            'states' => PromotionProtocol::STATES,
            'state_count' => count(PromotionProtocol::STATES),
        ];
    }

    /**
     * Observe-only autonomous work execution cycle stages.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function autonomousWorkCycleStagesObserve(array $input = []): array
    {
        return [
            'schema_version' => AutonomousWorkExecutionOs::SCHEMA_VERSION,
            'autonomy_levels' => AutonomousWorkExecutionOs::AUTONOMY_LEVELS,
            'cycle_stages' => AutonomousWorkExecutionOs::CYCLE_STAGES,
            'stage_count' => count(AutonomousWorkExecutionOs::CYCLE_STAGES),
            'stage_statuses' => AutonomousWorkExecutionOs::STAGE_STATUSES,
            'stage_status_count' => count(AutonomousWorkExecutionOs::STAGE_STATUSES),
        ];
    }

    /**
     * Observe-only immune verdict ledger labels (MAXI).
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function immuneVerdictLedgerLabelsObserve(array $input = []): array
    {
        return [
            'schema_version' => ImmuneVerdictLedger::SCHEMA_VERSION,
            'labels' => ImmuneVerdictLedger::LABELS,
            'label_count' => count(ImmuneVerdictLedger::LABELS),
        ];
    }

    /**
     * Observe-only Atlas M flywheel funnel stages.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function flywheelFunnelStagesObserve(array $input = []): array
    {
        return [
            'schema_version' => AtlasFlywheelFunnelService::SCHEMA_VERSION,
            'measure_id' => AtlasFlywheelFunnelService::MEASURE_ID,
            'formula_version' => AtlasFlywheelFunnelService::FORMULA_VERSION,
            'stages' => AtlasFlywheelFunnelService::STAGES,
            'stage_count' => count(AtlasFlywheelFunnelService::STAGES),
        ];
    }

    /**
     * Observe-only mission-control cockpit schema.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function missionControlCockpitSchemaObserve(array $input = []): array
    {
        return [
            'schema_version' => AtlasMissionControlCockpitService::SCHEMA_VERSION,
            'phase_count' => count(AaeosPhaseHandoffService::PHASES),
            'phases' => AaeosPhaseHandoffService::PHASES,
        ];
    }

    /**
     * Observe-only evidence-vision thesis lifecycle schema.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evidenceVisionThesisLifecycleObserve(array $input = []): array
    {
        return [
            'schema_version' => EvidenceVisionThesisLifecycle::SCHEMA_VERSION,
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

    /**
     * Observe-only exploratory bets portfolio contract.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function exploratoryBetsPortfolioContractObserve(array $input = []): array
    {
        return [
            'schema_version' => ExploratoryBetsPortfolio::SCHEMA_VERSION,
            'default_k' => ExploratoryBetsPortfolio::DEFAULT_K,
            'default_window_days' => ExploratoryBetsPortfolio::DEFAULT_WINDOW_DAYS,
            'min_n' => ExploratoryBetsPortfolio::MIN_N,
            'double_down_multiplier' => ExploratoryBetsPortfolio::DOUBLE_DOWN_MULTIPLIER,
        ];
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
     * Observe-only memory feedback decay contract thresholds.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function memoryFeedbackDecayContractObserve(array $input = []): array
    {
        return [
            'schema_version' => MemoryFeedbackDecayScorer::SCHEMA_VERSION,
            'hard_stale_age_days' => MemoryFeedbackDecayScorer::HARD_STALE_AGE_DAYS,
            'soft_stale_age_days' => MemoryFeedbackDecayScorer::SOFT_STALE_AGE_DAYS,
            'default_base_priority' => MemoryFeedbackDecayScorer::DEFAULT_BASE_PRIORITY,
            'archive_stale_feedback_threshold' => MemoryFeedbackDecayScorer::ARCHIVE_STALE_FEEDBACK_THRESHOLD,
            'inactivate_negative_threshold' => MemoryFeedbackDecayScorer::INACTIVATE_NEGATIVE_THRESHOLD,
            'inactivate_health_ceiling' => MemoryFeedbackDecayScorer::INACTIVATE_HEALTH_CEILING,
            'degrade_health_ceiling' => MemoryFeedbackDecayScorer::DEGRADE_HEALTH_CEILING,
        ];
    }

    /**
     * Observe-only SpecCompleteness contract thresholds.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function specCompletenessContractObserve(array $input = []): array
    {
        return [
            'schema_version' => SpecCompletenessScorer::SCHEMA_VERSION,
            'text_min_length' => SpecCompletenessScorer::TEXT_MIN_LENGTH,
            'total_fields' => SpecCompletenessScorer::TOTAL_FIELDS,
            'complete_threshold' => SpecCompletenessScorer::COMPLETE_THRESHOLD,
            'partial_threshold' => SpecCompletenessScorer::PARTIAL_THRESHOLD,
            'list_fields' => SpecCompletenessScorer::LIST_FIELDS,
            'list_field_count' => count(SpecCompletenessScorer::LIST_FIELDS),
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
     * Observe-only ESP-06 OutcomeEnvelope contract (schema/statuses/origins).
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function outcomeEnvelopeContractObserve(array $input = []): array
    {
        return [
            'schema_version' => OutcomeEnvelope::SCHEMA_VERSION,
            'formula_version' => OutcomeEnvelope::FORMULA_VERSION,
            'adapter_origins' => OutcomeEnvelope::ADAPTER_ORIGINS,
            'statuses' => OutcomeEnvelope::STATUSES,
            'origin_count' => count(OutcomeEnvelope::ADAPTER_ORIGINS),
            'status_count' => count(OutcomeEnvelope::STATUSES),
        ];
    }

    /**
     * Observe-only MULTN15-08 pre-review advisory band contract floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function preReviewAdvisoryContractObserve(array $input = []): array
    {
        return [
            'schema_version' => PreReviewAdvisoryBand::SCHEMA_VERSION,
            'formula_version' => PreReviewAdvisoryBand::FORMULA_VERSION,
            'min_n_for_band' => PreReviewAdvisoryBand::MIN_N_FOR_BAND,
            'death_min_n' => PreReviewAdvisoryBand::DEATH_MIN_N,
            'death_min_lift' => PreReviewAdvisoryBand::DEATH_MIN_LIFT,
            'blocks_auto_apply' => false,
            'delays_auto_apply' => false,
        ];
    }

    /**
     * Observe-only ambition rung policy ladder (MULTN17-01).
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function ambitionRungPolicyContractObserve(array $input = []): array
    {
        return [
            'schema_version' => AmbitionRungPolicy::SCHEMA_VERSION,
            'rungs' => AmbitionRungPolicy::RUNGS,
            'rung_count' => count(AmbitionRungPolicy::RUNGS),
            'scope_has_ceiling' => false,
            'provider_calls_made' => false,
        ];
    }

    /**
     * Observe-only reactive saturation floors (originator lane).
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function reactiveSaturationContractObserve(array $input = []): array
    {
        return [
            'schema_version' => ReactiveSaturationSignal::SCHEMA_VERSION,
            'min_n_per_window' => ReactiveSaturationSignal::MIN_N_PER_WINDOW,
            'min_windows' => ReactiveSaturationSignal::MIN_WINDOWS,
            'report_only' => true,
            'disables_reactive_lane' => false,
            'provider_calls_made' => false,
        ];
    }

    /**
     * Observe-only MULTK-06 portfolio budget allocator floors/ceilings.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function portfolioBudgetContractObserve(array $input = []): array
    {
        return [
            'schema_version' => PortfolioBudgetAllocator::SCHEMA_VERSION,
            'formula_version' => PortfolioBudgetAllocator::FORMULA_VERSION,
            'classes' => PortfolioBudgetAllocator::CLASSES,
            'class_count' => count(PortfolioBudgetAllocator::CLASSES),
            'hard_floor_share' => PortfolioBudgetAllocator::HARD_FLOOR_SHARE,
            'hard_ceiling_share' => PortfolioBudgetAllocator::HARD_CEILING_SHARE,
            'min_n_per_class' => PortfolioBudgetAllocator::MIN_N_PER_CLASS,
            'allocator_writes_own_weights' => false,
        ];
    }

    /**
     * Observe-only predicted-impact band contract (bands + rung weights).
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function predictedImpactBandContractObserve(array $input = []): array
    {
        return [
            'schema_version' => PredictedImpactBand::SCHEMA_VERSION,
            'bands' => PredictedImpactBand::BANDS,
            'band_count' => count(PredictedImpactBand::BANDS),
            'rung_weights' => PredictedImpactBand::RUNG_WEIGHT,
            'influences_pick' => false,
            'single_scalar_score_emitted' => false,
        ];
    }

    /**
     * Observe-only gated corpus miner contract (protected privacy classes).
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function gatedCorpusContractObserve(array $input = []): array
    {
        return [
            'schema_version' => GatedCorpusCandidateMiner::SCHEMA_VERSION,
            'protected_classes' => GatedCorpusCandidateMiner::PROTECTED,
            'protected_class_count' => count(GatedCorpusCandidateMiner::PROTECTED),
            'candidate_only' => true,
            'writes_memory_directly' => false,
            'count_is_acceptance' => false,
        ];
    }

    /**
     * Observe-only Claim Definition-of-Done contract fields.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function claimDefinitionOfDoneContractObserve(array $input = []): array
    {
        return [
            'schema_version' => AtlasAaeosClaimDefinitionOfDoneValidator::SCHEMA_VERSION,
            'evaluated_against' => AtlasAaeosClaimDefinitionOfDoneValidator::EVALUATED_AGAINST,
            'canonical_fields' => AtlasAaeosClaimDefinitionOfDoneValidator::CANONICAL_FIELDS,
            'unconditional_fields' => AtlasAaeosClaimDefinitionOfDoneValidator::UNCONDITIONAL_FIELDS,
            'canonical_field_count' => count(AtlasAaeosClaimDefinitionOfDoneValidator::CANONICAL_FIELDS),
            'unconditional_field_count' => count(AtlasAaeosClaimDefinitionOfDoneValidator::UNCONDITIONAL_FIELDS),
            'field_statuses' => [
                AtlasAaeosClaimDefinitionOfDoneValidator::STATUS_PRESENT,
                AtlasAaeosClaimDefinitionOfDoneValidator::STATUS_MISSING,
                AtlasAaeosClaimDefinitionOfDoneValidator::STATUS_NOT_APPLICABLE,
            ],
            'verdicts' => [
                AtlasAaeosClaimDefinitionOfDoneValidator::VERDICT_EVIDENCE,
                AtlasAaeosClaimDefinitionOfDoneValidator::VERDICT_NARRATIVE,
            ],
        ];
    }

    /**
     * Observe-only M5 quality-bar telemetry contract floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function qualityBarTelemetryContractObserve(array $input = []): array
    {
        return [
            'schema_version' => QualityBarTelemetryContract::SCHEMA,
            'quality_bar_schema' => QualityBarTelemetryContract::QUALITY_BAR_SCHEMA,
            'immune_gate_id' => QualityBarTelemetryContract::IMMUNE_GATE_ID,
            'breach_signal' => QualityBarTelemetryContract::BREACH_SIGNAL,
            'evaluated_window_days' => QualityBarTelemetryContract::EVALUATED_WINDOW_DAYS,
            'auto_block_on_breach' => QualityBarTelemetryContract::AUTO_BLOCK_ON_BREACH,
            'evidence_required' => QualityBarTelemetryContract::EVIDENCE_REQUIRED,
            'telemetry_fields' => QualityBarTelemetryContract::TELEMETRY_FIELDS,
        ];
    }

    /**
     * Observe-only DOC L0..L4 maturity contract (never proves runtime).
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function docMaturityContractObserve(array $input = []): array
    {
        return [
            'schema_version' => AtlasAaeosDocMaturityClassifier::SCHEMA_VERSION,
            'levels' => AtlasAaeosDocMaturityClassifier::LEVELS,
            'level_count' => count(AtlasAaeosDocMaturityClassifier::LEVELS),
            'boolean_requirements' => AtlasAaeosDocMaturityClassifier::BOOLEAN_REQUIREMENTS,
            'strength_requirements' => AtlasAaeosDocMaturityClassifier::STRENGTH_REQUIREMENTS,
            'l4_signals' => AtlasAaeosDocMaturityClassifier::L4_SIGNALS,
            'runtime_ready_always' => false,
        ];
    }

    /**
     * Observe-only ESP-01 attempt lifecycle terminal states.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function attemptLifecycleContractObserve(array $input = []): array
    {
        return [
            'schema_version' => AttemptLifecycleLedger::SCHEMA_VERSION,
            'terminal_states' => AttemptLifecycleLedger::TERMINAL_STATES,
            'terminal_state_count' => count(AttemptLifecycleLedger::TERMINAL_STATES),
            'outcome_without_attempt_allowed' => false,
            'attempt_id_deduped' => true,
        ];
    }

    /**
     * Observe-only ESP-09 independent challenger advisory floors.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function esp09ChallengerContractObserve(array $input = []): array
    {
        return [
            'schema_version' => Esp09IndependentChallengerService::SCHEMA_VERSION,
            'measure_id' => Esp09IndependentChallengerService::MEASURE_ID,
            'mode' => Esp09IndependentChallengerService::MODE,
            'high_alignment_band' => Esp09IndependentChallengerService::HIGH_ALIGNMENT_BAND,
            'trigger_kinds' => Esp09IndependentChallengerService::TRIGGER_KINDS,
            'trigger_kind_count' => count(Esp09IndependentChallengerService::TRIGGER_KINDS),
            'advisory_only' => true,
            'gates_override' => false,
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
     * Observe-only delivery-pack completeness contract keys/statuses.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function deliveryPackContractObserve(array $input = []): array
    {
        return [
            'schema_version' => DeliveryPackCompletenessScorer::SCHEMA,
            'required_keys' => DeliveryPackCompletenessScorer::REQUIRED_KEYS,
            'required_key_count' => count(DeliveryPackCompletenessScorer::REQUIRED_KEYS),
            'statuses' => DeliveryPackCompletenessScorer::STATUSES,
            'blocker_missing_hash' => DeliveryPackCompletenessScorer::BLOCKER_MISSING_HASH,
            'blocker_evidence_required' => DeliveryPackCompletenessScorer::BLOCKER_EVIDENCE_REQUIRED,
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
            'threshold_epsilon' => AtlasAaeosThresholdComparator::EPSILON,
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
     * Observe-only segment-importance kind weights + bonuses.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function segmentImportanceContractObserve(array $input = []): array
    {
        return [
            'schema_version' => SegmentImportanceRanker::SCHEMA_VERSION,
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

    /**
     * Observe-only cognitive-immune promotion gates G0..G8 contract.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function cognitiveImmunePromotionGateContractObserve(array $input = []): array
    {
        return [
            'schema_version' => CognitiveImmunePromotionGateEvaluator::SCHEMA_VERSION,
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

    /**
     * Observe-only AAEOS cognitive-immune input classifier contract.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function cognitiveImmuneInputClassifierContractObserve(array $input = []): array
    {
        return [
            'schema_version' => AtlasAaeosCognitiveImmuneInputClassifier::SCHEMA_VERSION,
            'recurrence_memory_threshold' => AtlasAaeosCognitiveImmuneInputClassifier::RECURRENCE_MEMORY_THRESHOLD,
            'destination_classes' => array_keys(AtlasAaeosCognitiveImmuneInputClassifier::DESTINATIONS),
            'destination_class_count' => count(AtlasAaeosCognitiveImmuneInputClassifier::DESTINATIONS),
            'embedding_forbidden_classes' => AtlasAaeosCognitiveImmuneInputClassifier::EMBEDDING_FORBIDDEN_CLASSES,
            'injection_marker_count' => count(AtlasAaeosCognitiveImmuneInputClassifier::INJECTION_MARKERS),
            'strategic_marker_count' => count(AtlasAaeosCognitiveImmuneInputClassifier::STRATEGIC_MARKERS),
            'technical_marker_count' => count(AtlasAaeosCognitiveImmuneInputClassifier::TECHNICAL_MARKERS),
            'personal_marker_count' => count(AtlasAaeosCognitiveImmuneInputClassifier::PERSONAL_MARKERS),
            'project_evidence_marker_count' => count(AtlasAaeosCognitiveImmuneInputClassifier::PROJECT_EVIDENCE_MARKERS),
            'conversation_marker_count' => count(AtlasAaeosCognitiveImmuneInputClassifier::CONVERSATION_MARKERS),
            'veto_propagation_schema' => AtlasAaeosVetoPropagationResolver::SCHEMA_VERSION,
            'repair_loop_auto_escalation_threshold' => AtlasAaeosVetoPropagationResolver::REPAIR_LOOP_AUTO_ESCALATION_THRESHOLD,
            'promotion_eligibility_schema' => AtlasAaeosDepartmentPromotionEligibilityEvaluator::SCHEMA_VERSION,
            'promotion_max_evidence_age_days' => AtlasAaeosDepartmentPromotionEligibilityEvaluator::DEFAULT_MAX_EVIDENCE_AGE_DAYS,
            'promotion_max_tier' => AtlasAaeosDepartmentPromotionEligibilityEvaluator::DEFAULT_MAX_TIER,
            'consolidation_rerank_schema' => AtlasConsolidationRerankGuard::SCHEMA_VERSION,
            'consolidation_rerank_epsilon' => AtlasConsolidationRerankGuard::EPSILON,
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
            'implementation_truth_schema' => AtlasAaeosImplementationTruthService::SCHEMA,
            'implementation_truth_ranks' => AtlasAaeosImplementationTruthService::RANK,
            'implementation_truth_rank_count' => count(AtlasAaeosImplementationTruthService::RANK),
            'docs_authority_schema' => AtlasDocsAuthorityGraphService::SCHEMA_VERSION,
            'docs_authority_confidence' => AtlasDocsAuthorityGraphService::CONFIDENCE,
            'docs_authority_basis_count' => count(AtlasDocsAuthorityGraphService::CONFIDENCE),
            'verified_share_schema' => AcosMaxVerifiedShareService::SCHEMA_VERSION,
            'verified_share_executors' => AcosMaxVerifiedShareService::EXECUTORS,
            'verified_share_executor_count' => count(AcosMaxVerifiedShareService::EXECUTORS),
            'quality_bar_schema' => AtlasAaeosQualityBarService::SCHEMA_VERSION,
            'department_level_schema' => AaeosDepartmentLevelClassifier::SCHEMA_VERSION,
            'department_maturity_band_schema' => AtlasAaeosDepartmentMaturityBandClassifier::SCHEMA_VERSION,
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
            'evidence_symbol_types' => AtlasAaeosImplementationEvidenceResolver::SYMBOL_TYPES,
            'evidence_symbol_type_count' => count(AtlasAaeosImplementationEvidenceResolver::SYMBOL_TYPES),
            'evidence_signature_match_types' => AtlasAaeosImplementationEvidenceResolver::SIGNATURE_MATCH_TYPES,
            'test_execution_schema' => AtlasAaeosTestExecutionService::SCHEMA,
            'test_output_tail_chars' => AtlasAaeosTestExecutionService::OUTPUT_TAIL_CHARS,
            'operational_volume_schema' => AtlasOperationalVolumeCheckService::SCHEMA_VERSION,
            'dev_flow_ids' => AtlasOperationalVolumeCheckService::DEV_FLOW_IDS,
            'forge_flow_ids' => AtlasOperationalVolumeCheckService::FORGE_FLOW_IDS,
            'dev_runs_per_business_day_min' => AtlasOperationalVolumeCheckService::DEV_RUNS_PER_BUSINESS_DAY_MIN,
            'forge_cycles_per_week_min' => AtlasOperationalVolumeCheckService::FORGE_CYCLES_PER_WEEK_MIN,
            'deferred_phase_keys' => array_keys(AaeosHttpPathEnvelopeFactory::DEFERRED_PHASE_SPECS),
            'deferred_phase_count' => count(AaeosHttpPathEnvelopeFactory::DEFERRED_PHASE_SPECS),
            'scorecard_schema' => AtlasCognitionScoreCardService::SCHEMA_VERSION,
            'scorecard_status_points' => AtlasCognitionScoreCardService::STATUS_POINTS,
            'department_maturity_schema' => AtlasAaeosDepartmentMaturityService::SCHEMA_VERSION,
            'department_maturity_owner' => AtlasAaeosDepartmentMaturityService::OWNER,
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
            'quality_bar_level_schema' => AtlasAaeosDepartmentQualityBarLevelClassifier::SCHEMA_VERSION,
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
            'test_execution_schema' => AtlasAaeosTestExecutionService::SCHEMA,
            'test_execution_output_tail_chars' => AtlasAaeosTestExecutionService::OUTPUT_TAIL_CHARS,
            'evidence_symbol_types' => AtlasAaeosImplementationEvidenceResolver::SYMBOL_TYPES,
            'evidence_shared_index_key' => AtlasAaeosImplementationEvidenceResolver::SHARED_INDEX_KEY,
            'evidence_signature_match_types' => AtlasAaeosImplementationEvidenceResolver::SIGNATURE_MATCH_TYPES,
            'department_maturity_schema' => AtlasAaeosDepartmentMaturityService::SCHEMA_VERSION,
            'department_maturity_owner' => AtlasAaeosDepartmentMaturityService::OWNER,
            'department_maturity_department_count' => count(AtlasAaeosDepartmentMaturityService::DEPARTMENTS),
            'department_maturity_last_evaluation' => AtlasAaeosDepartmentMaturityService::LAST_EVALUATION,
            'department_maturity_next_due' => AtlasAaeosDepartmentMaturityService::NEXT_EVALUATION_DUE,
            'deferred_phase_schema' => AaeosDeferredPhaseDispatcherService::SCHEMA_VERSION,
            'immune_injection_marker_count' => count(AtlasAaeosCognitiveImmuneInputClassifier::INJECTION_MARKERS),
            'immune_strategic_marker_count' => count(AtlasAaeosCognitiveImmuneInputClassifier::STRATEGIC_MARKERS),
            'immune_technical_marker_count' => count(AtlasAaeosCognitiveImmuneInputClassifier::TECHNICAL_MARKERS),
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
            'quality_bar_schema' => AtlasAaeosQualityBarService::SCHEMA_VERSION,
            'quality_bar_department_count' => count(AtlasAaeosQualityBarService::DEPARTMENT_DATA),
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
            'implementation_truth_schema' => AtlasAaeosImplementationTruthService::SCHEMA,
            'implementation_truth_ledger_schema' => AtlasAaeosImplementationTruthService::LEDGER_SCHEMA,
            'implementation_truth_hash_format' => AtlasAaeosImplementationTruthService::IMPL_FILES_HASH_FORMAT,
            'implementation_truth_rank' => AtlasAaeosImplementationTruthService::RANK,
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
            'quality_bar_level_schema' => AtlasAaeosDepartmentQualityBarLevelClassifier::SCHEMA_VERSION,
            'maturity_band_schema' => AtlasAaeosDepartmentMaturityBandClassifier::SCHEMA_VERSION,
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
     * Observe-only outcome-causality weight floors (deepen of comparator observe).
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function outcomeCausalityWeightsContractObserve(array $input = []): array
    {
        return [
            'schema_version' => OutcomeCausalityRanker::SCHEMA_VERSION,
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
            'phase_router_schema' => AtlasAaeosPhaseRouterService::SCHEMA_VERSION,
            'phase_legacy' => AtlasAaeosPhaseRouterService::PHASE_LEGACY,
            'phase_1' => AtlasAaeosPhaseRouterService::PHASE_1,
            'phase_2' => AtlasAaeosPhaseRouterService::PHASE_2,
            'phase_3' => AtlasAaeosPhaseRouterService::PHASE_3,
            'phase_4' => AtlasAaeosPhaseRouterService::PHASE_4,
            'valid_phase_count' => count(AtlasAaeosPhaseRouterService::VALID_PHASES),
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
            'doc_maturity_schema' => AtlasAaeosDocMaturityClassifier::SCHEMA_VERSION,
            'doc_level_l0' => AtlasAaeosDocMaturityClassifier::LEVEL_L0,
            'doc_level_l1' => AtlasAaeosDocMaturityClassifier::LEVEL_L1,
            'doc_level_l2' => AtlasAaeosDocMaturityClassifier::LEVEL_L2,
            'doc_level_l3' => AtlasAaeosDocMaturityClassifier::LEVEL_L3,
            'doc_level_l4' => AtlasAaeosDocMaturityClassifier::LEVEL_L4,
            'doc_strength_none' => AtlasAaeosDocMaturityClassifier::STRENGTH_NONE,
            'doc_strength_partial' => AtlasAaeosDocMaturityClassifier::STRENGTH_PARTIAL,
            'doc_strength_strong' => AtlasAaeosDocMaturityClassifier::STRENGTH_STRONG,
            'promotion_schema' => PromotionProtocol::SCHEMA,
            'promotion_state_off' => PromotionProtocol::STATE_OFF,
            'promotion_state_shadow' => PromotionProtocol::STATE_SHADOW,
            'promotion_state_live' => PromotionProtocol::STATE_LIVE,
            'promotion_state_rolled_back' => PromotionProtocol::STATE_ROLLED_BACK,
            'promotion_state_suspended_pending_evidence' => PromotionProtocol::STATE_SUSPENDED_PENDING_EVIDENCE,
            'promotion_default_ledger_relative_path' => PromotionProtocol::DEFAULT_LEDGER_RELATIVE_PATH,
            'promotion_required_fields' => PromotionProtocol::REQUIRED_FIELDS,
            'promotion_required_field_count' => count(PromotionProtocol::REQUIRED_FIELDS),
            'claim_dod_schema' => AtlasAaeosClaimDefinitionOfDoneValidator::SCHEMA_VERSION,
            'claim_field_owner_doc' => AtlasAaeosClaimDefinitionOfDoneValidator::FIELD_OWNER_DOC,
            'claim_field_documental_state' => AtlasAaeosClaimDefinitionOfDoneValidator::FIELD_DOCUMENTAL_STATE,
            'claim_field_runtime_state' => AtlasAaeosClaimDefinitionOfDoneValidator::FIELD_RUNTIME_STATE,
            'claim_field_code_command_path' => AtlasAaeosClaimDefinitionOfDoneValidator::FIELD_CODE_COMMAND_PATH,
            'claim_field_proof' => AtlasAaeosClaimDefinitionOfDoneValidator::FIELD_PROOF,
            'claim_field_caveat' => AtlasAaeosClaimDefinitionOfDoneValidator::FIELD_CAVEAT,
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
            'gate_signal_schema' => AtlasAaeosGateSignalEvaluator::SCHEMA_VERSION,
            'ambiguity_saturation' => AtlasAaeosGateSignalEvaluator::AMBIGUITY_SATURATION,
            'missing_saturation' => AtlasAaeosGateSignalEvaluator::MISSING_SATURATION,
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
            'implementation_truth_schema' => AtlasAaeosImplementationTruthService::SCHEMA,
            'implementation_truth_ledger_schema' => AtlasAaeosImplementationTruthService::LEDGER_SCHEMA,
            'implementation_truth_hash_format' => AtlasAaeosImplementationTruthService::IMPL_FILES_HASH_FORMAT,
            'rank_spec' => AtlasAaeosImplementationTruthService::RANK['spec'],
            'rank_partial' => AtlasAaeosImplementationTruthService::RANK['partial'],
            'rank_verified' => AtlasAaeosImplementationTruthService::RANK['verified'],
            'rank_count' => count(AtlasAaeosImplementationTruthService::RANK),
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
            'generated_contract_gate_schema' => AaeosGeneratedContractGate::SCHEMA_VERSION,
            'docs_locate_schema' => AtlasDocsAuthorityGraphService::LOCATE_SCHEMA,
            'docs_authority_schema' => AtlasDocsAuthorityGraphService::SCHEMA_VERSION,
            'outcome_envelope_bridge_schema' => OutcomeEnvelopeBridge::BRIDGE_SCHEMA,
            'outcome_envelope_bridge_measure_id' => OutcomeEnvelopeBridge::MEASURE_ID,
            'lote2_measure_report_schema' => AcosMaxLote2MeasureService::REPORT_SCHEMA,
            'pre_review_calibration_schema' => PreReviewAdvisoryBand::CALIBRATION_SCHEMA,
            'pre_review_advisory_schema' => PreReviewAdvisoryBand::SCHEMA_VERSION,
            'doc_runtime_coverage_schema' => AtlasAaeosImplementationTruthService::DOC_RUNTIME_COVERAGE_SCHEMA,
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
     * Observe-only HTTP-path + watchdog health secondary report schemas + evaluator observe schemas.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
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

    /**
     * Observe-only remaining evaluator observe-helper schemas + long-horizon area_v2.
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
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

    /**
     * Observe-only gate-report schema floor (evaluator catalogue envelope).
     * Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function gateReportSchemaContractObserve(array $input = []): array
    {
        return [
            'gate_report_schema' => self::SCHEMA_VERSION,
            'universal_gates_catalogue_observe_schema' => self::OBSERVE_UNIVERSAL_GATES_CATALOGUE_SCHEMA,
            'catalogue_gate_count' => count($this->catalogue()),
            'observe_helper_schema_count' => 13,
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
            'promotion_eligibility_schema' => AtlasAaeosDepartmentPromotionEligibilityEvaluator::SCHEMA_VERSION,
            'promotion_max_evidence_age_days' => AtlasAaeosDepartmentPromotionEligibilityEvaluator::DEFAULT_MAX_EVIDENCE_AGE_DAYS,
            'promotion_max_tier' => AtlasAaeosDepartmentPromotionEligibilityEvaluator::DEFAULT_MAX_TIER,
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
            'generated_hot_path_enabled_config_key' => AaeosGeneratedContractGate::HOT_PATH_ENABLED_CONFIG_KEY,
            'generated_hot_path_enabled_default' => AaeosGeneratedContractGate::DEFAULT_HOT_PATH_ENABLED,
            'generated_quarantine_namespace_config_key' => AaeosGeneratedContractGate::QUARANTINE_NAMESPACE_CONFIG_KEY,
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
            'http_path_phase_config_key' => AtlasAaeosPhaseRouterService::HTTP_PATH_PHASE_CONFIG_KEY,
            'http_path_phase_legacy' => AtlasAaeosPhaseRouterService::PHASE_LEGACY,
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
            'veto_reason_operator_final_override' => AtlasAaeosVetoPropagationResolver::REASON_OPERATOR_VETO_FINAL_OVERRIDE,
            'veto_reason_security_pause_downstream' => AtlasAaeosVetoPropagationResolver::REASON_SECURITY_VETO_PAUSE_DOWNSTREAM,
            'veto_reason_architect_upstream' => AtlasAaeosVetoPropagationResolver::REASON_ARCHITECT_SPEC_VETO_UPSTREAM,
            'veto_reason_no_canonical_rule' => AtlasAaeosVetoPropagationResolver::REASON_NO_CANONICAL_VETO_RULE,
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
            'truth_level_spec' => AtlasAaeosImplementationTruthService::LEVEL_SPEC,
            'truth_level_partial' => AtlasAaeosImplementationTruthService::LEVEL_PARTIAL,
            'truth_level_verified' => AtlasAaeosImplementationTruthService::LEVEL_VERIFIED,
            'truth_level_existence_only' => AtlasAaeosImplementationTruthService::LEVEL_EXISTENCE_ONLY,
            'truth_rank_spec' => AtlasAaeosImplementationTruthService::RANK[AtlasAaeosImplementationTruthService::LEVEL_SPEC],
            'truth_rank_partial' => AtlasAaeosImplementationTruthService::RANK[AtlasAaeosImplementationTruthService::LEVEL_PARTIAL],
            'truth_rank_verified' => AtlasAaeosImplementationTruthService::RANK[AtlasAaeosImplementationTruthService::LEVEL_VERIFIED],
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

    /**
     * Observe-only: golden-counterfactual + cooccurrence + pareto + fidelity/spec scorers + maxa04 floors —
     * no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function goldenParetoScorerMaxa04FloorsContractObserve(array $input = []): array
    {
        return [
            'golden_status_skipped' => GoldenCounterfactualReplayService::STATUS_SKIPPED,
            'golden_reason_paired_arms_missing' => GoldenCounterfactualReplayService::REASON_PAIRED_ARMS_MISSING,
            'golden_reason_paired_golden_runs_unavailable' => GoldenCounterfactualReplayService::REASON_PAIRED_GOLDEN_RUNS_UNAVAILABLE,
            'cooccurrence_status_unmeasurable' => ExecutionContextCooccurrenceService::STATUS_UNMEASURABLE,
            'cooccurrence_reason_measured_share_zero' => ExecutionContextCooccurrenceService::REASON_MEASURED_SHARE_ZERO,
            'cooccurrence_reason_run_artifact_unavailable' => ExecutionContextCooccurrenceService::REASON_RUN_ARTIFACT_UNAVAILABLE,
            'pareto_status_blocked' => ContextParetoDominanceFilter::STATUS_BLOCKED,
            'pareto_status_dominated' => ContextParetoDominanceFilter::STATUS_DOMINATED,
            'pareto_status_frontier' => ContextParetoDominanceFilter::STATUS_FRONTIER,
            'fidelity_verdict_passed' => SummaryFidelityCoverageScorer::VERDICT_PASSED,
            'fidelity_verdict_degraded' => SummaryFidelityCoverageScorer::VERDICT_DEGRADED,
            'fidelity_verdict_failed' => SummaryFidelityCoverageScorer::VERDICT_FAILED,
            'spec_verdict_complete' => SpecCompletenessScorer::VERDICT_COMPLETE,
            'spec_verdict_partial' => SpecCompletenessScorer::VERDICT_PARTIAL,
            'spec_verdict_insufficient' => SpecCompletenessScorer::VERDICT_INSUFFICIENT,
            'maxa04_mode_shadow_only' => Maxa04JinaV3DualReadService::MODE_SHADOW_ONLY,
            'maxa04_status_mechanism_ready' => Maxa04JinaV3DualReadService::STATUS_MECHANISM_READY,
            'maxa04_status_no_dual_read_cases' => Maxa04JinaV3DualReadService::STATUS_NO_DUAL_READ_CASES,
            'composed_arc_reason_not_active' => ComposedObraArcLifecycle::REASON_ARC_NOT_ACTIVE,
            'composed_arc_reason_kill_gate' => ComposedObraArcLifecycle::REASON_KILL_GATE_CONSECUTIVE_FAILURES,
            'golden_pareto_scorer_maxa04_floor_count' => 20,
        ];
    }

    /**
     * Observe-only: parallel-execution + procedural-promoter + cockpit/debug/rerank/rollback + latency/substrate residual floors —
     * no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function parallelProceduralWatchdogResidualFloorsContractObserve(array $input = []): array
    {
        return [
            'parallel_action_proceed' => AcosMaxParallelExecutionProtocol::ACTION_PROCEED,
            'parallel_action_skip' => AcosMaxParallelExecutionProtocol::ACTION_SKIP,
            'procedural_kind_playbook' => AcosMaxProceduralSkillPromoterService::KIND_PROCEDURAL_PLAYBOOK,
            'procedural_status_held_for_evidence' => AcosMaxProceduralSkillPromoterService::STATUS_HELD_FOR_EVIDENCE,
            'cockpit_status_unavailable' => AcosProgramCockpitService::STATUS_UNAVAILABLE,
            'cockpit_reason_source_not_landed_yet' => AcosProgramCockpitService::REASON_SOURCE_NOT_LANDED_YET,
            'debug_status_analyzed' => AtlasDebugRootCauseService::STATUS_ANALYZED,
            'debug_status_no_data' => AtlasDebugRootCauseService::STATUS_NO_DATA,
            'rerank_status_no_baseline' => AtlasConsolidationRerankGuard::STATUS_NO_BASELINE,
            'rerank_status_unmeasured' => AtlasConsolidationRerankGuard::STATUS_UNMEASURED,
            'rollback_status_simulated_fire' => AtlasAcosRollbackTriggerCheckService::STATUS_SIMULATED_FIRE,
            'rollback_reason_simulated_condition' => AtlasAcosRollbackTriggerCheckService::REASON_SIMULATED_CONDITION,
            'aobg_latency_reason_insufficient_signal' => AobgLatencyWatchdogCheck::REASON_INSUFFICIENT_SIGNAL,
            'aobg_latency_reason_floor_exceeded' => AobgLatencyWatchdogCheck::REASON_LATENCY_FLOOR_EXCEEDED,
            'aobg_latency_reason_within_floors' => AobgLatencyWatchdogCheck::REASON_SUFFICIENT_SIGNAL_WITHIN_FLOORS,
            'substrate_reason_no_successful_drill' => SubstrateRestoreDrillWatchdogCheck::REASON_NO_SUCCESSFUL_DRILL,
            'substrate_reason_drill_fresh' => SubstrateRestoreDrillWatchdogCheck::REASON_SUCCESSFUL_DRILL_FRESH,
            'substrate_reason_drill_stale' => SubstrateRestoreDrillWatchdogCheck::REASON_SUCCESSFUL_DRILL_STALE,
            'esp09_outcome_accepted' => Esp09IndependentChallengerService::OUTCOME_ACCEPTED,
            'esp09_outcome_ignored' => Esp09IndependentChallengerService::OUTCOME_IGNORED,
            'parallel_procedural_watchdog_residual_floor_count' => 20,
        ];
    }

    /**
     * Observe-only: lote2 kind/mode + decomposer/redaction reasons + previously published but unobserved floors —
     * no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function lote2DecomposerRedactionUnobservedFloorsContractObserve(array $input = []): array
    {
        return [
            'lote2_kind_measure_freeze' => AcosMaxLote2MeasureService::KIND_MEASURE_FREEZE,
            'lote2_mode_observe' => AcosMaxLote2MeasureService::MODE_OBSERVE,
            'decomposer_reason_empty_input' => AtlasCognitiveFunctionDecomposerService::REASON_EMPTY_INPUT,
            'decomposer_reason_no_keyword_signal' => AtlasCognitiveFunctionDecomposerService::REASON_NO_KEYWORD_SIGNAL,
            'redaction_reason_memory_entries_missing' => ProviderBoundRedactionDriftWatchdogCheck::REASON_ATLAS_MEMORY_ENTRIES_MISSING,
            'redaction_reason_no_provider_bound_drift' => ProviderBoundRedactionDriftWatchdogCheck::REASON_NO_PROVIDER_BOUND_REDACTION_DRIFT,
            'esp09_decision_kind_ordinary_route' => Esp09IndependentChallengerService::DECISION_KIND_ORDINARY_ROUTE,
            'esp09_skip_reason_low_affinity' => Esp09IndependentChallengerService::SKIP_REASON_LOW_AFFINITY,
            'esp09_reason_challenger_block_present' => Esp09IndependentChallengerService::REASON_CHALLENGER_BLOCK_PRESENT,
            'esp09_death_review_near_zero_accepted' => Esp09IndependentChallengerService::DEATH_REVIEW_REASON_NEAR_ZERO_ACCEPTED,
            'bets_state_active' => ExploratoryBetsPortfolio::STATE_ACTIVE,
            'bets_state_exploring' => ExploratoryBetsPortfolio::STATE_EXPLORING,
            'obra_outcome_succeeded' => AcosMaxObraRetroService::OUTCOME_STATUS_SUCCEEDED,
            'obra_outcome_failed' => AcosMaxObraRetroService::OUTCOME_STATUS_FAILED,
            'obra_slice_state_landed' => AcosMaxObraRetroService::SLICE_STATE_LANDED,
            'asef_status_failed' => AsefChunkIndexService::STATUS_FAILED,
            'asef_status_empty' => AsefChunkIndexService::STATUS_EMPTY,
            'promotion_status_ok' => PromotionProtocol::STATUS_OK,
            'ragx_reason_no_verified_maxf09_l2_summaries' => RagxChainMechanismService::REASON_NO_VERIFIED_MAXF09_L2_SUMMARIES,
            'composed_arc_status_refused' => ComposedObraArcLifecycle::STATUS_REFUSED,
            'lote2_decomposer_redaction_unobserved_floor_count' => 20,
        ];
    }

    /**
     * Observe-only: remaining published but previously unwired status/basis/handoff floors —
     * no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function unobservedStatusBasisHandoffFloorsContractObserve(array $input = []): array
    {
        return [
            'attempt_reason_task_or_attempt_unresolvable' => AttemptLifecycleLedger::REASON_TASK_OR_ATTEMPT_UNRESOLVABLE,
            'attempt_reason_attempt_missing' => AttemptLifecycleLedger::REASON_ATTEMPT_MISSING,
            'attempt_reason_invalid_terminal_state' => AttemptLifecycleLedger::REASON_INVALID_TERMINAL_STATE,
            'composed_task_status_failed' => ComposedObraArcLifecycle::TASK_STATUS_FAILED,
            'composed_task_status_landed' => ComposedObraArcLifecycle::TASK_STATUS_LANDED,
            'composed_reason_arc_not_found' => ComposedObraArcLifecycle::REASON_ARC_NOT_FOUND,
            'esp09_trigger_decision_kind' => Esp09IndependentChallengerService::TRIGGER_DECISION_KIND,
            'esp09_trigger_high_operator_alignment' => Esp09IndependentChallengerService::TRIGGER_HIGH_OPERATOR_ALIGNMENT,
            'esp09_reason_challenger_not_required' => Esp09IndependentChallengerService::REASON_CHALLENGER_NOT_REQUIRED,
            'bets_basis_evidence_turned_positive' => ExploratoryBetsPortfolio::BASIS_EVIDENCE_TURNED_POSITIVE,
            'bets_basis_proven_negative_effect' => ExploratoryBetsPortfolio::BASIS_PROVEN_NEGATIVE_EFFECT,
            'bets_decision_kind_continuation_gate' => ExploratoryBetsPortfolio::DECISION_KIND_CONTINUATION_GATE,
            'obra_lesson_status_pending_review' => AcosMaxObraRetroService::LESSON_STATUS_PENDING_REVIEW,
            'obra_slice_state_refutado' => AcosMaxObraRetroService::SLICE_STATE_REFUTADO,
            'evidence_thesis_reason_not_active' => EvidenceVisionThesisLifecycle::REASON_THESIS_NOT_ACTIVE,
            'evidence_thesis_death_ttl_expired' => EvidenceVisionThesisLifecycle::DEATH_REASON_TTL_EXPIRED,
            'choreography_handoff_kind_delegation' => AtlasCrossDepartmentChoreographyService::HANDOFF_KIND_DELEGATION,
            'choreography_handoff_kind_escalation' => AtlasCrossDepartmentChoreographyService::HANDOFF_KIND_ESCALATION,
            'immune_trust_band_unclassified' => CognitiveImmunePromotionGateEvaluator::TRUST_BAND_UNCLASSIFIED,
            'numeric_relation_touching' => NumericRangeOverlapContradictionDetector::RELATION_TOUCHING,
            'unobserved_status_basis_handoff_floor_count' => 20,
        ];
    }

    /**
     * Observe-only: remaining choreography repair/review handoff floors plus
     * measure-freeze / autonomy-ladder export floors — no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function choreographyRepairReviewMeasureFreezeFloorsContractObserve(array $input = []): array
    {
        return [
            'choreography_handoff_kind_repair' => AtlasCrossDepartmentChoreographyService::HANDOFF_KIND_REPAIR,
            'choreography_handoff_kind_review_request' => AtlasCrossDepartmentChoreographyService::HANDOFF_KIND_REVIEW_REQUEST,
            'kb_embedding_kind_measure_freeze' => AtlasKnowledgeItemEmbeddingCoverageService::KIND_MEASURE_FREEZE,
            'code_symbol_embedding_kind_measure_freeze' => AtlasCodeSymbolEmbeddingCoverageService::KIND_MEASURE_FREEZE,
            'outcome_envelope_kind_measure_freeze' => OutcomeEnvelopeBridge::KIND_MEASURE_FREEZE,
            'autonomy_ladder_export_bool_true' => AutonomyLadderAdversarialWatchdogCheck::EXPORT_BOOL_TRUE,
            'autonomy_ladder_export_bool_false' => AutonomyLadderAdversarialWatchdogCheck::EXPORT_BOOL_FALSE,
            'autonomy_ladder_export_bool_unset' => AutonomyLadderAdversarialWatchdogCheck::EXPORT_BOOL_UNSET,
            'choreography_repair_review_measure_freeze_floor_count' => 8,
        ];
    }

    /**
     * Observe-only: residual published error/basis/status floors plus newly
     * published ready/calibrated/rotate-age floors — no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function residualErrorBasisStatusFloorsContractObserve(array $input = []): array
    {
        return [
            'veto_reason_review_delivery_repair' => AtlasAaeosVetoPropagationResolver::REASON_REVIEW_DELIVERY_VETO_REPAIR,
            'decay_decision_stale_review_recommended' => MemoryFeedbackDecayScorer::DECISION_STALE_REVIEW_RECOMMENDED,
            'composed_task_status_never_served_archived' => ComposedObraArcLifecycle::TASK_STATUS_NEVER_SERVED_ARCHIVED,
            'esp09_error_engine_ids_required' => Esp09IndependentChallengerService::ERROR_ENGINE_IDS_REQUIRED,
            'esp09_error_challenger_engine_must_differ' => Esp09IndependentChallengerService::ERROR_CHALLENGER_ENGINE_MUST_DIFFER,
            'bets_basis_positive_causal_effect' => ExploratoryBetsPortfolio::BASIS_POSITIVE_CAUSAL_EFFECT,
            'bets_basis_unproven_effect' => ExploratoryBetsPortfolio::BASIS_UNPROVEN_EFFECT,
            'obra_lesson_path_normal_capture' => AcosMaxObraRetroService::LESSON_PATH_NORMAL_CAPTURE,
            'obra_source_acos_max_obra_retro' => AcosMaxObraRetroService::SOURCE_ACOS_MAX_OBRA_RETRO,
            'ragx_status_empty' => RagxChainMechanismService::STATUS_EMPTY,
            'ragx_status_ok' => RagxChainMechanismService::STATUS_OK,
            'ragx_status_unknown' => RagxChainMechanismService::STATUS_UNKNOWN,
            'numeric_relation_b_contains_a' => NumericRangeOverlapContradictionDetector::RELATION_B_CONTAINS_A,
            'remint_reason_empty_paths' => AtlasCognitionRemintTouchedQueue::REASON_EMPTY_PATHS,
            'remint_reason_queue_path_empty' => AtlasCognitionRemintTouchedQueue::REASON_QUEUE_PATH_EMPTY,
            'ledger_mode_rotate_age' => AcosMaxLedgerRotationRegistry::MODE_ROTATE_AGE,
            'immune_status_calibrated' => ImmuneCalibrationService::STATUS_CALIBRATED,
            'immune_status_ok' => ImmuneCalibrationService::STATUS_OK,
            'hmac_status_ready' => CaptureHmacLineageService::STATUS_READY,
            'residual_error_basis_status_floor_count' => 19,
        ];
    }

    /**
     * Observe-only: newly published signature modes, obra suspended,
     * http-path unknown, and department operator floors — no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function signatureModeSuspendedUnknownFloorsContractObserve(array $input = []): array
    {
        return [
            'immune_signature_mode_off' => ImmuneSignatureStore::MODE_OFF,
            'immune_signature_mode_observe' => ImmuneSignatureStore::MODE_OBSERVE,
            'immune_signature_mode_enforce' => ImmuneSignatureStore::MODE_ENFORCE,
            'obra_slice_state_suspended' => AcosMaxObraRetroService::SLICE_STATE_SUSPENDED,
            'http_path_result_unknown' => AtlasAaeosHttpPathFacadeService::RESULT_UNKNOWN,
            'department_operator' => DepartmentContractRuntime::DEPARTMENT_OPERATOR,
            'department_qa' => DepartmentContractRuntime::DEPARTMENT_QA,
            'signature_mode_suspended_unknown_floor_count' => 7,
        ];
    }

    /**
     * Observe-only: architect targets, promotion verdicts, freeze kinds,
     * cognitive ready, and rollback status floors — no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function architectVerdictFreezeReadyFloorsContractObserve(array $input = []): array
    {
        return [
            'department_architect' => DepartmentContractRuntime::DEPARTMENT_ARCHITECT,
            'choreography_target_architect' => AtlasCrossDepartmentChoreographyService::TARGET_ARCHITECT,
            'choreography_target_operator' => AtlasCrossDepartmentChoreographyService::TARGET_OPERATOR,
            'choreography_target_product' => AtlasCrossDepartmentChoreographyService::TARGET_PRODUCT,
            'promotion_verdict_eligible' => AtlasAaeosDepartmentPromotionEligibilityEvaluator::VERDICT_ELIGIBLE,
            'promotion_verdict_blocked' => AtlasAaeosDepartmentPromotionEligibilityEvaluator::VERDICT_BLOCKED,
            'immune_signature_freeze_kind_measure_freeze' => AtlasImmuneSignatureFreeze::KIND_MEASURE_FREEZE,
            'immune_hybrid_freeze_kind_measure_freeze' => AtlasImmuneClassifierHybridFreeze::KIND_MEASURE_FREEZE,
            'cognitive_atlas_status_ready' => AtlasCognitiveFunctionAtlasService::STATUS_READY,
            'cognitive_atlas_status_partial' => AtlasCognitiveFunctionAtlasService::STATUS_PARTIAL,
            'cognitive_atlas_status_unknown' => AtlasCognitiveFunctionAtlasService::STATUS_UNKNOWN,
            'rollback_status_healthy' => AtlasAcosRollbackTriggerCheckService::STATUS_HEALTHY,
            'rollback_status_disabled' => AtlasAcosRollbackTriggerCheckService::STATUS_DISABLED,
            'rollback_status_alert' => AtlasAcosRollbackTriggerCheckService::STATUS_ALERT,
            'architect_verdict_freeze_ready_floor_count' => 14,
        ];
    }

    /**
     * Observe-only: mission-control phase statuses, reality-compiler pending,
     * and claim DoD partial-state floors — no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function missionControlPendingPartialFloorsContractObserve(array $input = []): array
    {
        return [
            'mission_control_status_pending' => AtlasMissionControlCockpitService::STATUS_PENDING,
            'mission_control_status_skipped' => AtlasMissionControlCockpitService::STATUS_SKIPPED,
            'mission_control_status_blocked' => AtlasMissionControlCockpitService::STATUS_BLOCKED,
            'mission_control_status_complete' => AtlasMissionControlCockpitService::STATUS_COMPLETE,
            'mission_control_status_in_progress' => AtlasMissionControlCockpitService::STATUS_IN_PROGRESS,
            'reality_compiler_status_pending' => RealityCompilerSlice::STATUS_PENDING,
            'claim_dod_state_partial' => AtlasAaeosClaimDefinitionOfDoneValidator::STATE_PARTIAL,
            'mission_control_pending_partial_floor_count' => 7,
        ];
    }

    /**
     * Observe-only: operational-volume / autonomy stage / gate-coverage /
     * envelope-unknown floors — no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function volumeAutonomyCoverageUnknownFloorsContractObserve(array $input = []): array
    {
        return [
            'operational_volume_status_healthy' => AtlasOperationalVolumeCheckService::STATUS_HEALTHY,
            'operational_volume_status_skipped' => AtlasOperationalVolumeCheckService::STATUS_SKIPPED,
            'operational_volume_status_alert' => AtlasOperationalVolumeCheckService::STATUS_ALERT,
            'autonomy_status_pending' => AutonomousWorkExecutionOs::STATUS_PENDING,
            'autonomy_status_in_progress' => AutonomousWorkExecutionOs::STATUS_IN_PROGRESS,
            'autonomy_status_succeeded' => AutonomousWorkExecutionOs::STATUS_SUCCEEDED,
            'autonomy_status_failed' => AutonomousWorkExecutionOs::STATUS_FAILED,
            'autonomy_status_skipped' => AutonomousWorkExecutionOs::STATUS_SKIPPED,
            'gate_coverage_no_gate' => AaeosRequiredGateCoverageChecker::COVERAGE_NO_GATE,
            'gate_coverage_incomplete' => AaeosRequiredGateCoverageChecker::COVERAGE_INCOMPLETE,
            'gate_coverage_complete' => AaeosRequiredGateCoverageChecker::COVERAGE_COMPLETE,
            'http_envelope_status_unknown' => AaeosHttpPathEnvelopeFactory::STATUS_UNKNOWN,
            'debug_status_unknown' => AtlasDebugRootCauseService::STATUS_UNKNOWN,
            'volume_autonomy_coverage_unknown_floor_count' => 13,
        ];
    }

    /**
     * Observe-only: watchdog health / long-horizon / immune-gate / truth / deferred unknown floors.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function watchdogHealthActiveDisabledFloorsContractObserve(array $input = []): array
    {
        return [
            'watchdog_health_status_ok' => AtlasAcosWatchdogHealthService::STATUS_OK,
            'watchdog_health_status_alert' => AtlasAcosWatchdogHealthService::STATUS_ALERT,
            'watchdog_health_status_healthy' => AtlasAcosWatchdogHealthService::STATUS_HEALTHY,
            'watchdog_health_status_ready' => AtlasAcosWatchdogHealthService::STATUS_READY,
            'watchdog_health_status_not_ready' => AtlasAcosWatchdogHealthService::STATUS_NOT_READY,
            'watchdog_health_status_unavailable' => AtlasAcosWatchdogHealthService::STATUS_UNAVAILABLE,
            'long_horizon_status_blocked' => AtlasAcosLongHorizonGateService::STATUS_BLOCKED,
            'long_horizon_status_disabled' => AtlasAcosLongHorizonGateService::STATUS_DISABLED,
            'long_horizon_status_ready' => AtlasAcosLongHorizonGateService::STATUS_READY,
            'long_horizon_status_insufficient' => AtlasAcosLongHorizonGateService::STATUS_INSUFFICIENT,
            'implementation_truth_status_active' => AtlasAaeosImplementationTruthService::STATUS_ACTIVE,
            'implementation_truth_status_building' => AtlasAaeosImplementationTruthService::STATUS_BUILDING,
            'immune_verdict_gate_status_pass' => ImmuneVerdictLedger::GATE_STATUS_PASS,
            'immune_verdict_gate_status_block' => ImmuneVerdictLedger::GATE_STATUS_BLOCK,
            'immune_verdict_gate_status_pending' => ImmuneVerdictLedger::GATE_STATUS_PENDING,
            'immune_verdict_writer_unknown' => ImmuneVerdictLedger::WRITER_UNKNOWN,
            'deferred_phase_unknown' => AaeosDeferredPhaseDispatcherService::PHASE_UNKNOWN,
            'dev_procedural_fallback_run_id' => DevProceduralOutcomeEnvelopeAdapter::FALLBACK_RUN_ID,
            'watchdog_health_active_disabled_floor_count' => 18,
        ];
    }

    /**
     * Observe-only: embedding active/pending + mission outcome + arc/spec/drill unknown floors.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function embeddingPendingMissionOutcomeFloorsContractObserve(array $input = []): array
    {
        return [
            'evidence_resolver_status_active' => AtlasAaeosImplementationEvidenceResolver::STATUS_ACTIVE,
            'kb_embedding_status_active' => AtlasKnowledgeItemEmbeddingCoverageService::STATUS_ACTIVE,
            'code_symbol_embedding_status_active' => AtlasCodeSymbolEmbeddingCoverageService::STATUS_ACTIVE,
            'asef_embedding_status_pending' => AsefChunkIndexService::EMBEDDING_STATUS_PENDING,
            'asef_embedding_status_persisted' => AsefChunkIndexService::EMBEDDING_STATUS_PERSISTED,
            'composed_arc_status_unknown' => ComposedObraArcLifecycle::STATUS_UNKNOWN,
            'spec_completeness_reason_ok' => SpecCompletenessScorer::REASON_OK,
            'mission_control_status_succeeded' => AtlasMissionControlCockpitService::STATUS_SUCCEEDED,
            'mission_control_status_failed' => AtlasMissionControlCockpitService::STATUS_FAILED,
            'mission_control_outcome_green' => AtlasMissionControlCockpitService::OUTCOME_GREEN,
            'mission_control_outcome_red' => AtlasMissionControlCockpitService::OUTCOME_RED,
            'mission_control_outcome_exception' => AtlasMissionControlCockpitService::OUTCOME_EXCEPTION,
            'n_capture_trigger_unknown' => AtlasNCaptureDrillService::TRIGGER_UNKNOWN,
            'watchdog_runner_check_id_unknown' => AtlasWatchdogRunner::CHECK_ID_UNKNOWN,
            'embedding_pending_mission_outcome_floor_count' => 14,
        ];
    }

    /**
     * Observe-only: local-model integrity + embedding coverage + immune/lote2 unavailable floors.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function localModelEmbeddingImmuneUnavailableFloorsContractObserve(array $input = []): array
    {
        return [
            'local_model_fallback_model_id' => AtlasLocalModelIntegrityService::FALLBACK_MODEL_ID,
            'local_model_status_unknown' => AtlasLocalModelIntegrityService::STATUS_UNKNOWN,
            'local_model_status_invalid' => AtlasLocalModelIntegrityService::STATUS_INVALID,
            'local_model_status_unpinned' => AtlasLocalModelIntegrityService::STATUS_UNPINNED,
            'local_model_status_missing' => AtlasLocalModelIntegrityService::STATUS_MISSING,
            'local_model_status_verified' => AtlasLocalModelIntegrityService::STATUS_VERIFIED,
            'local_model_status_mismatched' => AtlasLocalModelIntegrityService::STATUS_MISMATCHED,
            'code_symbol_embedding_status_ok' => AtlasCodeSymbolEmbeddingCoverageService::STATUS_OK,
            'code_symbol_embedding_status_insufficient_signal' => AtlasCodeSymbolEmbeddingCoverageService::STATUS_INSUFFICIENT_SIGNAL,
            'code_symbol_embedding_status_partial_coverage' => AtlasCodeSymbolEmbeddingCoverageService::STATUS_PARTIAL_COVERAGE,
            'kb_embedding_status_ok' => AtlasKnowledgeItemEmbeddingCoverageService::STATUS_OK,
            'kb_embedding_status_insufficient_signal' => AtlasKnowledgeItemEmbeddingCoverageService::STATUS_INSUFFICIENT_SIGNAL,
            'kb_embedding_status_partial_coverage' => AtlasKnowledgeItemEmbeddingCoverageService::STATUS_PARTIAL_COVERAGE,
            'window_orchestrator_status_ok' => AcosMaxWindowOrchestratorService::STATUS_OK,
            'recall_gap_status_ok' => RecallGapAggregator::STATUS_OK,
            'recall_gap_status_insufficient_signal' => RecallGapAggregator::STATUS_INSUFFICIENT_SIGNAL,
            'immune_signature_status_unavailable' => ImmuneSignatureStore::STATUS_UNAVAILABLE,
            'lote2_basis_unavailable' => AcosMaxLote2MeasureService::BASIS_UNAVAILABLE,
            'lote2_memory_type_unknown' => AcosMaxLote2MeasureService::MEMORY_TYPE_UNKNOWN,
            'cognitive_immune_gate_status_pending' => CognitiveImmuneCheckContract::GATE_STATUS_PENDING,
            'cognitive_immune_gate_status_pass' => CognitiveImmuneCheckContract::GATE_STATUS_PASS,
            'cognitive_immune_gate_status_block' => CognitiveImmuneCheckContract::GATE_STATUS_BLOCK,
            'cognitive_immune_gate_status_unknown' => CognitiveImmuneCheckContract::GATE_STATUS_UNKNOWN,
            'local_model_embedding_immune_unavailable_floor_count' => 23,
        ];
    }

    /**
     * Observe-only: pre-review/parallel/golden/flywheel/hybrid/frontier residual floors.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prereviewParallelFlywheelFrontierFloorsContractObserve(array $input = []): array
    {
        return [
            'prereview_target_class_unknown' => PreReviewAdvisoryBand::TARGET_CLASS_UNKNOWN,
            'parallel_engine_unknown' => AcosMaxParallelExecutionProtocol::ENGINE_UNKNOWN,
            'golden_counterfactual_status_ok' => GoldenCounterfactualReplayService::STATUS_OK,
            'n_capture_status_ok' => AtlasNCaptureDrillService::STATUS_OK,
            'n_capture_status_insufficient_signal' => AtlasNCaptureDrillService::STATUS_INSUFFICIENT_SIGNAL,
            'flywheel_status_ok' => AtlasFlywheelFunnelService::STATUS_OK,
            'flywheel_status_no_signal' => AtlasFlywheelFunnelService::STATUS_NO_SIGNAL,
            'flywheel_status_insufficient' => AtlasFlywheelFunnelService::STATUS_INSUFFICIENT,
            'immune_hybrid_source_unavailable' => AtlasImmuneHybridInputClassifier::SOURCE_UNAVAILABLE,
            'immune_hybrid_source_jaccard_baseline' => AtlasImmuneHybridInputClassifier::SOURCE_JACCARD_BASELINE,
            'frontier_activation_active' => AtlasFrontierWaveLadder::ACTIVATION_ACTIVE,
            'frontier_activation_aguardando_eventos' => AtlasFrontierWaveLadder::ACTIVATION_AGUARDANDO_EVENTOS,
            'autonomy_field_complete' => AutonomousWorkExecutionOs::FIELD_COMPLETE,
            'watchdog_health_field_pass' => AtlasAcosWatchdogHealthService::FIELD_PASS,
            'prereview_parallel_flywheel_frontier_floor_count' => 14,
        ];
    }

    /**
     * Observe-only: obra-retro/portfolio/cooccurrence/pareto/http blocked residual floors.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function obraPortfolioParetoBlockedFloorsContractObserve(array $input = []): array
    {
        return [
            'obra_retro_status_unknown' => AcosMaxObraRetroService::STATUS_UNKNOWN,
            'portfolio_status_ok' => PortfolioBudgetAllocator::STATUS_OK,
            'portfolio_status_weights_reverted' => PortfolioBudgetAllocator::STATUS_WEIGHTS_REVERTED,
            'portfolio_basis_measured' => PortfolioBudgetAllocator::BASIS_MEASURED,
            'portfolio_basis_insufficient_n' => PortfolioBudgetAllocator::BASIS_INSUFFICIENT_N,
            'cooccurrence_status_ok' => ExecutionContextCooccurrenceService::STATUS_OK,
            'lote2_field_complete' => AcosMaxLote2MeasureService::FIELD_COMPLETE,
            'pareto_field_blocked' => ContextParetoDominanceFilter::FIELD_BLOCKED,
            'http_envelope_field_blocked' => AaeosHttpPathEnvelopeFactory::FIELD_BLOCKED,
            'watchdog_check_field_alert' => AtlasWatchdogCheckResult::FIELD_ALERT,
            'obra_portfolio_pareto_blocked_floor_count' => 10,
        ];
    }

    /**
     * Observe-only: corpus/parallel/truth-resolution/http/phase blocked residual floors.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function corpusParallelTruthBlockedFloorsContractObserve(array $input = []): array
    {
        return [
            'gated_corpus_source_unknown' => GatedCorpusCandidateMiner::SOURCE_UNKNOWN,
            'parallel_field_ok' => AcosMaxParallelExecutionProtocol::FIELD_OK,
            'implementation_truth_test_resolution_green' => AtlasAaeosImplementationTruthService::TEST_RESOLUTION_GREEN,
            'implementation_truth_test_resolution_mixed' => AtlasAaeosImplementationTruthService::TEST_RESOLUTION_MIXED,
            'implementation_truth_test_resolution_existence_only_unrun' => AtlasAaeosImplementationTruthService::TEST_RESOLUTION_EXISTENCE_ONLY_UNRUN,
            'http_path_field_blocked' => AtlasAaeosHttpPathFacadeService::FIELD_BLOCKED,
            'phase_advance_field_blocked' => PhaseAdvanceVerdictClassifier::FIELD_BLOCKED,
            'lote2_field_partial' => AcosMaxLote2MeasureService::FIELD_PARTIAL,
            'corpus_parallel_truth_blocked_floor_count' => 8,
        ];
    }

    /**
     * Observe-only: teto10 bands + cockpit/ladder/promotion/hmac residual floors.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function teto10CockpitLadderPromotionFloorsContractObserve(array $input = []): array
    {
        return [
            'teto10_band_high' => Teto10PredictedRevertReviewDigest::BAND_HIGH,
            'teto10_band_sweet' => Teto10PredictedRevertReviewDigest::BAND_SWEET,
            'teto10_band_low' => Teto10PredictedRevertReviewDigest::BAND_LOW,
            'teto10_band_unknown' => Teto10PredictedRevertReviewDigest::BAND_UNKNOWN,
            'program_cockpit_status_ok' => AcosProgramCockpitService::STATUS_OK,
            'autonomy_ladder_probe_id_unknown' => AutonomyLadderAdversarialWatchdogCheck::PROBE_ID_UNKNOWN,
            'autonomy_ladder_field_ok' => AutonomyLadderAdversarialWatchdogCheck::FIELD_OK,
            'watchdog_health_status_unknown' => AtlasAcosWatchdogHealthService::STATUS_UNKNOWN,
            'consolidation_field_ok' => AtlasConsolidationRerankGuard::FIELD_OK,
            'consolidation_status_ok' => AtlasConsolidationRerankGuard::STATUS_OK,
            'consolidation_status_healthy' => AtlasConsolidationRerankGuard::STATUS_HEALTHY,
            'promotion_field_ok' => PromotionProtocol::FIELD_OK,
            'model_capability_status_ok' => AtlasModelCapabilitySpecService::STATUS_OK,
            'model_capability_status_violates_spec' => AtlasModelCapabilitySpecService::STATUS_VIOLATES_SPEC,
            'model_capability_fallback_model_id' => AtlasModelCapabilitySpecService::FALLBACK_MODEL_ID,
            'hmac_stage_unknown' => CaptureHmacLineageService::STAGE_UNKNOWN,
            'hmac_field_ok' => CaptureHmacLineageService::FIELD_OK,
            'autonomy_field_blocked' => AutonomousWorkExecutionOs::FIELD_BLOCKED,
            'phase_handoff_field_blocked' => AaeosPhaseHandoffService::FIELD_BLOCKED,
            'teto10_cockpit_ladder_promotion_floor_count' => 19,
        ];
    }

    /**
     * Observe-only: dead-series/miner/signature/adapter residual floors — no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function deadSeriesMinerSignatureAdapterFloorsContractObserve(array $input = []): array
    {
        return [
            'dead_series_status_ok' => AcosDeadSeriesWatchdogCheck::STATUS_OK,
            'dead_series_status_stale' => AcosDeadSeriesWatchdogCheck::STATUS_STALE,
            'dead_series_status_missing' => AcosDeadSeriesWatchdogCheck::STATUS_MISSING,
            'local_model_status_mismatched' => AtlasLocalModelIntegrityService::STATUS_MISMATCHED,
            'local_model_status_missing' => AtlasLocalModelIntegrityService::STATUS_MISSING,
            'compaction_status_ok' => CompactionRecoverySampleWatchdogCheck::STATUS_OK,
            'compaction_status_unknown' => CompactionRecoverySampleWatchdogCheck::STATUS_UNKNOWN,
            'compaction_status_insufficient_sample' => CompactionRecoverySampleWatchdogCheck::STATUS_INSUFFICIENT_SAMPLE,
            'scorecard_grouper_status_unknown' => AtlasCognitionScoreCardV4Grouper::STATUS_UNKNOWN,
            'scorecard_grouper_status_blocked' => AtlasCognitionScoreCardV4Grouper::STATUS_BLOCKED,
            'dogfooding_status_ok' => DogfoodingFrictionLeadMiner::STATUS_OK,
            'dogfooding_status_insufficient_signal' => DogfoodingFrictionLeadMiner::STATUS_INSUFFICIENT_SIGNAL,
            'corpus_miner_status_ok' => GatedCorpusCandidateMiner::STATUS_OK,
            'citation_status_ok' => CitationGroundingMeter::STATUS_OK,
            'teto10_status_ok' => Teto10PredictedRevertReviewDigest::STATUS_OK,
            'teto10_status_empty' => Teto10PredictedRevertReviewDigest::STATUS_EMPTY,
            'immune_signature_status_ok' => ImmuneSignatureStore::STATUS_OK,
            'immune_signature_status_pending_window' => ImmuneSignatureStore::STATUS_PENDING_WINDOW,
            'deferred_status_blocked' => AaeosDeferredPhaseDispatcherService::STATUS_BLOCKED,
            'evolution_status_unknown' => AtlasAcosEvolutionScoreService::STATUS_UNKNOWN,
            'window_gates_status_unknown' => AtlasAcosWindowGatesService::STATUS_UNKNOWN,
            'immune_writer_unknown' => ImmuneVerdictLedger::WRITER_UNKNOWN,
            'immune_ingestor_status_blocked' => ImmuneSignatureIngestor::STATUS_BLOCKED,
            'immune_ingestor_writer_unknown' => ImmuneSignatureIngestor::WRITER_UNKNOWN,
            'outcome_status_blocked' => OutcomeEnvelope::STATUS_BLOCKED,
            'canary_status_unknown' => DailyCanaryReplayByRefsWatchdogCheck::STATUS_UNKNOWN,
            'evidence_ledger_status_ok' => EvidenceLedgerIntegrityWatchdogCheck::STATUS_OK,
            'dead_series_miner_signature_adapter_floor_count' => 27,
        ];
    }

    /**
     * Observe-only: ragx/prereview/lote2/schema residual floors — no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function ragxPrereviewLote2SchemaFloorsContractObserve(array $input = []): array
    {
        return [
            'ragx_status_verified' => RagxChainMechanismService::STATUS_VERIFIED,
            'ragx_status_ok' => RagxChainMechanismService::STATUS_OK,
            'ragx_field_enabled' => RagxChainMechanismService::FIELD_ENABLED,
            'ragx_field_pending_window' => RagxChainMechanismService::FIELD_PENDING_WINDOW,
            'prereview_basis_insufficient_sample' => PreReviewAdvisoryBand::BASIS_INSUFFICIENT_SAMPLE,
            'prereview_basis_measured' => PreReviewAdvisoryBand::BASIS_MEASURED,
            'promotion_field_missing' => PromotionProtocol::FIELD_MISSING,
            'structured_fact_status_unschematized' => StructuredFactSchemaMap::STATUS_UNSCHEMATIZED,
            'structured_fact_field_missing' => StructuredFactSchemaMap::FIELD_MISSING,
            'lote2_field_incomplete' => AcosMaxLote2MeasureService::FIELD_INCOMPLETE,
            'lote2_mission_status_completed' => AcosMaxLote2MeasureService::MISSION_STATUS_COMPLETED,
            'lote2_mission_status_success' => AcosMaxLote2MeasureService::MISSION_STATUS_SUCCESS,
            'dev_procedural_native_needs_review' => DevProceduralOutcomeEnvelopeAdapter::NATIVE_NEEDS_REVIEW,
            'outcome_status_blocked' => OutcomeEnvelope::STATUS_BLOCKED,
            'program_cockpit_reason_source_unavailable' => AcosProgramCockpitService::REASON_SOURCE_UNAVAILABLE,
            'program_cockpit_field_error' => AcosProgramCockpitService::FIELD_ERROR,
            'ambition_field_enabled' => AmbitionRungPolicy::FIELD_ENABLED,
            'composed_obra_field_enabled' => ComposedObraArcComposer::FIELD_ENABLED,
            'exploratory_bets_field_enabled' => ExploratoryBetsPortfolio::FIELD_ENABLED,
            'required_gate_field_missing' => AaeosRequiredGateCoverageChecker::FIELD_MISSING,
            'remint_field_error' => AtlasCognitionRemintTouchedQueue::FIELD_ERROR,
            'mission_control_field_blocked' => AtlasMissionControlCockpitService::FIELD_BLOCKED,
            'deferred_field_blocked' => AaeosDeferredPhaseDispatcherService::FIELD_BLOCKED,
            'ragx_prereview_lote2_schema_floor_count' => 23,
        ];
    }

    /**
     * Observe-only: window-gates/integrity/flag-disabled residual floors — no gate verdict.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function windowGatesIntegrityFlagDisabledFloorsContractObserve(array $input = []): array
    {
        return [
            'window_gates_status_sem_dados' => AtlasAcosWindowGatesService::STATUS_SEM_DADOS,
            'window_gates_status_aguardando_janela' => AtlasAcosWindowGatesService::STATUS_AGUARDANDO_JANELA,
            'window_gates_status_certified' => AtlasAcosWindowGatesService::STATUS_CERTIFIED,
            'window_gates_status_met' => AtlasAcosWindowGatesService::STATUS_MET,
            'window_gates_field_certified' => AtlasAcosWindowGatesService::FIELD_CERTIFIED,
            'structured_fact_status_valid' => StructuredFactSchemaMap::STATUS_VALID,
            'structured_fact_status_missing_fields' => StructuredFactSchemaMap::STATUS_MISSING_FIELDS,
            'structured_fact_field_valid' => StructuredFactSchemaMap::FIELD_VALID,
            'ambition_basis_flag_disabled' => AmbitionRungPolicy::BASIS_FLAG_DISABLED,
            'ambition_basis_not_saturated' => AmbitionRungPolicy::BASIS_NOT_SATURATED,
            'ambition_basis_rung_up_after_saturation' => AmbitionRungPolicy::BASIS_RUNG_UP_AFTER_SATURATION,
            'local_model_field_verified' => AtlasLocalModelIntegrityService::FIELD_VERIFIED,
            'local_model_field_mismatched' => AtlasLocalModelIntegrityService::FIELD_MISMATCHED,
            'local_model_field_missing' => AtlasLocalModelIntegrityService::FIELD_MISSING,
            'local_model_field_unpinned' => AtlasLocalModelIntegrityService::FIELD_UNPINNED,
            'esp09_field_error' => Esp09IndependentChallengerService::FIELD_ERROR,
            'outcome_bridge_field_enabled' => OutcomeEnvelopeBridge::FIELD_ENABLED,
            'composed_obra_status_flag_disabled' => ComposedObraArcComposer::STATUS_FLAG_DISABLED,
            'evidence_vision_field_enabled' => EvidenceVisionThesisComposer::FIELD_ENABLED,
            'evidence_vision_status_flag_disabled' => EvidenceVisionThesisComposer::STATUS_FLAG_DISABLED,
            'mission_control_field_missing' => AtlasMissionControlCockpitService::FIELD_MISSING,
            'procedural_field_pending_window' => AcosMaxProceduralSkillPromoterService::FIELD_PENDING_WINDOW,
            'ragx_field_pending_window' => RagxChainMechanismService::FIELD_PENDING_WINDOW,
            'window_gates_integrity_flag_disabled_floor_count' => 23,
        ];
    }

    /**
     * Return the universal gate catalogue (provider-safe — descriptions only).
     *
     * @return array<string,array{description:string, canonical_source:string}>
     */
    public function catalogue(): array
    {
        return self::UNIVERSAL_GATES;
    }
}
