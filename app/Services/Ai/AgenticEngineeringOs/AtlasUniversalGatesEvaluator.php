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
use App\Services\Ai\AcosMax\DomainLexicalNormalizer;
use App\Services\Ai\AcosMax\GatedCorpusCandidateMiner;
use App\Services\Ai\AcosMax\StructuredFactSchemaMap;
use App\Services\Ai\AcosMax\CitationGroundingMeter;
use App\Services\Ai\AcosMax\ProvenanceWeightCalculator;
use App\Services\Ai\AcosMax\RecallGapAggregator;
use App\Services\Ai\AcosMax\BeliefCascadeReverificationPlanner;
use App\Services\Ai\AcosMax\AcosMaxLedgerRotationRegistry;
use App\Services\Ai\AcosMax\EvidenceVisionThesisComposer;
use App\Services\Ai\Aaeos\AtlasAaeosGateSignalEvaluator;
use App\Services\Ai\Cognition\AtlasSurpriseGateService;
use App\Services\Ai\Cognition\ImmuneCalibrationService;
use App\Services\Ai\Cognition\CognitiveImmuneCheckContract;
use App\Services\Ai\Cognition\ImmuneSignatureDeriver;
use App\Services\Ai\Cognition\AtlasCognitionEvidenceResolver;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardV4Grouper;
use App\Services\Ai\Cognition\CaptureHmacLineageService;
use App\Services\Ai\Cognition\Watchdog\Checks\HealthReportWatchdogCheck;
use App\Services\Ai\Cognition\AtlasCognitiveFunctionDecomposerService;
use App\Services\Ai\Cognition\AtlasAcosRollbackTriggerCheckService;
use App\Services\Ai\Cognition\AtlasAcosLongHorizonGateService;
use App\Services\Ai\Cognition\ImmuneSignatureStore;
use App\Services\Ai\Cognition\ImmuneVerdictLedger;
use App\Services\Ai\AcosMax\PromotionProtocol;
use App\Services\Ai\Aaeos\AtlasAaeosThresholdLadderNormalizer;
use App\Services\Ai\AcosMax\AtlasKnowledgeItemEmbeddingCoverageService;
use App\Services\Ai\AcosMax\AtlasCodeSymbolEmbeddingCoverageService;
use App\Services\Ai\AcosMax\Teto10PredictedRevertReviewDigest;
use App\Services\Ai\AcosMax\Maxa04JinaV3DualReadLedger;
use App\Services\Ai\AcosMax\AtlasResourceBudgetService;
use App\Services\Ai\AcosMax\AtlasModelCapabilitySpecService;
use App\Services\Ai\AcosMax\AcosMeasureSeriesFreshnessReader;
use App\Services\Ai\AcosMax\AcosMaxVerifiedShareService;
use App\Services\Ai\AcosMax\RagxChainMechanismService;
use App\Services\Ai\AcosMax\AcosMaxProceduralSkillPromoterService;
use App\Services\Ai\AcosMax\GoldenCounterfactualReplayService;
use App\Services\Ai\AcosMax\ComposedObraArcComposer;
use App\Services\Ai\AcosMax\ExploratoryBetsPortfolio;
use App\Services\Ai\AcosMax\AtlasNCaptureDrillService;
use App\Services\Ai\AcosMax\AcosMaxLote2MeasureService;
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
        $minWindows = max(1, (int) ($input['min_windows'] ?? 2));
        $minPerWindow = max(1, (int) ($input['min_per_window'] ?? 2));

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
        $summary = AiValueNormalizer::trimmedString(
            $input['summary_text'] ?? $input['summary'] ?? '',
        );

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
                ? (int) $input['min_excerpt_chars']
                : (array_key_exists('min_excerpt', $input) ? (int) $input['min_excerpt'] : null),
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

        $query = AiValueNormalizer::trimmedString($input['query'] ?? '');
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
        $memoryType = AiValueNormalizer::trimmedString(
            $input['memory_type'] ?? $input['type'] ?? '',
        );
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
        $minOccurrences = max(1, (int) ($input['min_occurrences'] ?? 3));

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
        $origin = AiValueNormalizer::trimmedString($input['origin'] ?? '');
        $graph = AiValueNormalizer::arrayOrEmpty($input['graph'] ?? null);
        $depthCap = max(0, (int) ($input['depth_cap'] ?? 3));

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
        $series = AiValueNormalizer::trimmedString($input['series'] ?? '');
        $policy = $series === '' ? null : $registry->policyFor($series);

        return [
            'schema_version' => 'atlas.aaeos.ledger_rotation_observe.v1',
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
            'schema_version' => 'atlas.aaeos.evidence_vision_observe.v1',
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
            'schema_version' => 'atlas.aaeos.threshold_ladder_observe.v1',
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
        $limit = max(1, (int) ($input['limit'] ?? 50));

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
        $function = AiValueNormalizer::trimmedString($input['function'] ?? 'dense_embed');
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
                'model_id' => AiValueNormalizer::trimmedString($model['model_id'] ?? '') ?: 'unknown',
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
            'series' => AiValueNormalizer::trimmedString($entry['series'] ?? ''),
            'source_type' => AiValueNormalizer::trimmedString($entry['source_type'] ?? ''),
            'path' => AiValueNormalizer::trimmedString($entry['path'] ?? ''),
            'table' => AiValueNormalizer::trimmedString($entry['table'] ?? ''),
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
        $days = array_key_exists('days', $input) ? max(1, (int) $input['days']) : null;

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
            $deps[AiValueNormalizer::trimmedString($key)] = (bool) $value;
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
        $floor = array_key_exists('floor', $input) ? max(1, (int) $input['floor']) : null;
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
        $currentIteration = max(0, (int) ($input['current_iteration'] ?? 0));

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
                max(0, (int) ($input['iteration'] ?? 0)),
                max(0, (int) ($input['max_iterations'] ?? AtlasCrossDepartmentChoreographyService::REPAIR_MAX_ITERATIONS)),
            ),
            'handoff' => $svc->handoffEnvelope(
                AiValueNormalizer::trimmedString($input['from'] ?? ''),
                AiValueNormalizer::trimmedString($input['to'] ?? ''),
                AiValueNormalizer::trimmedString($input['kind'] ?? 'delegation'),
                AiValueNormalizer::arrayOrEmpty($input['payload'] ?? null),
            ),
            default => $svc->evaluateVeto(
                AiValueNormalizer::trimmedString($input['department'] ?? $input['vetoing_department'] ?? ''),
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
        $needle = AiValueNormalizer::trimmedString($input['needle'] ?? '');
        $limit = max(1, (int) ($input['limit'] ?? 5));

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
            AiValueNormalizer::trimmedString($input['department_id'] ?? ''),
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
            AiValueNormalizer::trimmedString($input['department_id'] ?? ''),
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
            AiValueNormalizer::trimmedString($input['claimed_state'] ?? 'spec'),
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
                AiValueNormalizer::trimmedString($input['autonomy_level'] ?? 'L0'),
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

        return (new AtlasNCaptureDrillService)->report($days === null ? null : max(1, (int) $days));
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
            'schema_version' => 'atlas.aaeos.string_list_normalize.v1',
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
        $comparator = AiValueNormalizer::trimmedString($input['comparator'] ?? '>=');
        $observed = AiValueNormalizer::finiteFloatOrNull($input['observed'] ?? null) ?? 0.0;
        $threshold = AiValueNormalizer::finiteFloatOrNull($input['threshold'] ?? null) ?? 0.0;

        return [
            'schema_version' => 'atlas.aaeos.threshold_comparator.v1',
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
            'schema_version' => 'atlas.aaeos.evidence_ref_normalize.v1',
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
        $key = AiValueNormalizer::trimmedString($input['key'] ?? 'id');

        return [
            'schema_version' => 'atlas.aaeos.array_field_reader.v1',
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
        $origin = AiValueNormalizer::trimmedString($input['origin_department'] ?? $input['origin'] ?? '');
        $kind = AiValueNormalizer::trimmedString($input['veto_kind'] ?? $input['kind'] ?? '');
        $iteration = max(0, (int) ($input['repair_iteration'] ?? 0));

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
        $text = AiValueNormalizer::trimmedString($input['text'] ?? '');
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
            'schema_version' => 'atlas.aaeos.universal_gates_catalogue.v1',
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
            'schema_version' => 'atlas.aaeos.outcome_attribution_types.v1',
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
            'schema_version' => 'atlas.telemetry.collector.surfaces.v1',
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
            'schema_version' => 'atlas.aaeos.blocker_severity.v1',
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
            'schema_version' => 'atlas.cognition.surprise_gate.bands.v1',
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
            'schema_version' => 'atlas.cognition.evidence_statuses.v1',
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
                static fn (array $row): string => (string) ($row['id'] ?? ''),
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
            'labels' => [
                ImmuneVerdictLedger::LABEL_TRUE_BLOCK,
                ImmuneVerdictLedger::LABEL_FALSE_BLOCK,
                ImmuneVerdictLedger::LABEL_MISSED_POISON,
            ],
            'label_count' => 3,
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
