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
        $enqueue = (bool) ($input['enqueue'] ?? false);

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
     * Return the universal gate catalogue (provider-safe — descriptions only).
     *
     * @return array<string,array{description:string, canonical_source:string}>
     */
    public function catalogue(): array
    {
        return self::UNIVERSAL_GATES;
    }
}
