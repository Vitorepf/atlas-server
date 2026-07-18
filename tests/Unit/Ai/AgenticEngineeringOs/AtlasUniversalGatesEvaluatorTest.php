<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\ArchitectAgentSpecPackGateContract;
use App\Services\Ai\AgenticEngineeringOs\AtlasUniversalGatesEvaluator;
use App\Services\Ai\AgenticEngineeringOs\AaeosPhaseHandoffService;
use App\Services\Ai\AgenticEngineeringOs\DeliveryPackCompletenessScorer;
use App\Services\Ai\AgenticEngineeringOs\QualityBarTelemetryContract;
use App\Services\Ai\AgenticEngineeringOs\RealityCompilerSlice;
use App\Services\Ai\AcosMax\PredictedImpactBand;
use App\Services\Ai\AcosMax\PreReviewAdvisoryBand;
use App\Services\Ai\AcosMax\Esp09IndependentChallengerService;
use App\Services\Ai\AcosMax\DogfoodingFrictionLeadMiner;
use App\Services\Ai\AcosMax\ReactiveSaturationSignal;
use App\Services\Ai\Aaeos\Cores\SpecCompletenessScorer;
use App\Services\Ai\Aaeos\Cores\SummaryFidelityCoverageScorer;
use App\Services\Ai\Aaeos\Cores\MemoryInjectionBudgetAllocator;
use App\Services\Ai\Aaeos\Cores\MemoryFeedbackDecayScorer;
use App\Services\Ai\Aaeos\Cores\SegmentImportanceRanker;
use App\Services\Ai\Aaeos\Cores\ContextParetoDominanceFilter;
use App\Services\Ai\Aaeos\Cores\AtlasMemoryRecallRelevanceScorer;
use App\Services\Ai\AcosMax\PortfolioBudgetAllocator;
use App\Services\Ai\AcosMax\AmbitionRungPolicy;
use App\Services\Ai\AcosMax\DomainLexicalNormalizer;
use App\Services\Ai\AcosMax\GatedCorpusCandidateMiner;
use App\Services\Ai\AcosMax\StructuredFactSchemaMap;
use App\Services\Ai\AcosMax\CitationGroundingMeter;
use App\Services\Ai\AcosMax\ProvenanceWeightCalculator;
use App\Services\Ai\AcosMax\RecallGapAggregator;
use App\Services\Ai\AcosMax\BeliefCascadeReverificationPlanner;
use App\Services\Ai\AcosMax\AtlasKnowledgeItemEmbeddingCoverageService;
use App\Services\Ai\AcosMax\AtlasCodeSymbolEmbeddingCoverageService;
use App\Services\Ai\AcosMax\Teto10PredictedRevertReviewDigest;
use App\Services\Ai\AcosMax\Maxa04JinaV3DualReadLedger;
use App\Services\Ai\AcosMax\AcosMeasureSeriesFreshnessReader;
use App\Services\Ai\AcosMax\AcosMaxVerifiedShareService;
use App\Services\Ai\AcosMax\RagxChainMechanismService;
use App\Services\Ai\AcosMax\AcosMaxProceduralSkillPromoterService;
use App\Services\Ai\AcosMax\GoldenCounterfactualReplayService;
use App\Services\Ai\AcosMax\ComposedObraArcComposer;
use App\Services\Ai\AcosMax\ExploratoryBetsPortfolio;
use App\Services\Ai\AcosMax\AtlasNCaptureDrillService;
use App\Services\Ai\AcosMax\AcosMaxLote2MeasureService;
use App\Services\Ai\Aaeos\AtlasAaeosPhaseRouterService;
use App\Services\Ai\Aaeos\AaeosGeneratedContractGate;
use InvalidArgumentException;
use Tests\TestCase;
use App\Services\Ai\Aaeos\AtlasRepairLoopGuard;
use App\Services\Ai\AcosMax\AcosMaxParallelExecutionProtocol;
use App\Services\Ai\AcosMax\AcosProgramCockpitService;
use App\Services\Ai\AgenticEngineeringOs\AaeosDeferredPhaseDispatcherService;
use App\Services\Ai\Cognition\AtlasAcosWindowGatesService;
use App\Services\Ai\Cognition\AtlasCognitionRemintTouchedQueue;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardV4Grouper;
use App\Services\Ai\AgenticEngineeringOs\DepartmentContractRuntime;
use App\Services\Ai\Cognition\CognitiveContextNudgeApplier;
use App\Services\Ai\AcosMax\AemorOutcomeEnvelopeAdapter;
use App\Services\Ai\AcosMax\AtlasFlywheelFunnelService;
use App\Services\Ai\AgenticEngineeringOs\RunbookOrchestrator;
use App\Services\Ai\Cognition\AtlasCognitiveFunctionAtlasService;
use App\Services\Ai\AcosMax\AtlasResourceBudgetService;
use App\Services\Ai\AcosMax\DevProceduralOutcomeEnvelopeAdapter;
use App\Services\Ai\AgenticEngineeringOs\AutonomousWorkExecutionOs;
use App\Services\Ai\Cognition\AtlasCognitiveFunctionDecomposerService;
use App\Services\Ai\Cognition\AtlasImmuneSignatureFreeze;
use App\Services\Ai\Cognition\AtlasOperationalVolumeCheckService;
use App\Services\Ai\AcosMax\EvidenceVisionThesisComposer;
use App\Services\Ai\AcosMax\ExecutionContextCooccurrenceService;
use App\Services\Ai\AgenticEngineeringOs\AtlasAaeosHttpPathFacadeService;
use App\Services\Ai\AgenticEngineeringOs\AtlasMissionControlCockpitService;
use App\Services\Ai\Cognition\AtlasCognitiveMemoryFabricSchemaEvolutionService;
use App\Services\Ai\Cognition\AtlasConsolidationRerankGuard;
use App\Services\Ai\AcosMax\AcosMaxObraRetroService;
use App\Services\Ai\Cognition\AtlasAcosRollbackTriggerCheckService;
use App\Services\Ai\AcosMax\AcosMaxWindowOrchestratorService;
use App\Services\Ai\Cognition\AtlasAcosLongHorizonGateService;
use App\Services\Ai\Aaeos\AtlasAaeosDepartmentMaturityBandClassifier;
use App\Services\Ai\Aaeos\AtlasAaeosTestExecutionService;
use App\Services\Ai\Cognition\AtlasAcosEvolutionScoreService;
use App\Services\Ai\AgenticEngineeringOs\AaeosHttpPathEnvelopeFactory;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\Aaeos\AaeosDepartmentLevelClassifier;
use App\Services\Ai\Aaeos\AtlasAaeosDepartmentQualityBarLevelClassifier;
use App\Services\Ai\Aaeos\AtlasAaeosDocMaturityClassifier;
use App\Services\Ai\AgenticEngineeringOs\PhaseAdvanceVerdictClassifier;
use App\Services\Ai\Cognition\AtlasFrontierWaveLadder;
use App\Services\Ai\Cognition\CognitiveImmunePromotionGateEvaluator;
use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use App\Services\Ai\Aaeos\AtlasAaeosGateSignalEvaluator;
use App\Services\Ai\Cognition\ImmuneCalibrationService;
use App\Services\Ai\Cognition\ImmuneSignatureIngestor;
use App\Services\Ai\Cognition\Watchdog\AtlasAcosWatchdogHealthService;
use App\Services\Ai\Cognition\AtlasCognitionEvidenceResolver;
use App\Services\Ai\Aaeos\AtlasAaeosCognitiveImmuneInputClassifier;
use App\Services\Ai\Aaeos\AtlasAaeosImplementationEvidenceResolver;
use App\Services\Ai\Aaeos\AtlasAaeosVetoPropagationResolver;
use App\Services\Ai\Aaeos\AtlasCrossDepartmentChoreographyService;
use App\Services\Ai\AcosMax\AtlasLocalModelIntegrityService;
use App\Services\Ai\Aaeos\AtlasAaeosDepartmentRegistryService;
use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;
use App\Services\Ai\Aaeos\AtlasDebugRootCauseService;
use App\Services\Ai\Aaeos\AtlasDocsAuthorityGraphService;
use App\Services\Ai\Cognition\Watchdog\Checks\DailyCanaryReplayByRefsWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\OperatorReviewDebtWatchdogCheck;
use App\Services\Ai\Aaeos\AtlasAaeosClaimDefinitionOfDoneValidator;
use App\Services\Ai\Aaeos\AtlasAaeosDepartmentMaturityService;
use App\Services\Ai\Cognition\Watchdog\Checks\CompactionRecoverySampleWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\OperatorLearningCaptureSchemaWatchdogCheck;
use App\Services\Ai\Aaeos\AtlasAaeosDepartmentPromotionEligibilityEvaluator;
use App\Services\Ai\AcosMax\EvidenceVisionThesisLifecycle;
use App\Services\Ai\AcosMax\PromotionProtocol;
use App\Services\Ai\AgenticEngineeringOs\AaeosBlockerSeverityGate;
use App\Services\Ai\Cognition\ImmuneSignatureDeriver;
use App\Services\Ai\Cognition\Watchdog\Checks\JointResourceBudgetWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\LocalModelIntegrityWatchdogCheck;
use App\Services\Ai\AcosMax\OutcomeEnvelopeBridge;
use App\Services\Ai\Cognition\AtlasImmuneHybridInputClassifier;
use App\Services\Ai\Cognition\Watchdog\Checks\AobgLatencyWatchdogCheck;
use App\Services\Ai\AcosMax\Maxa04JinaV3DualReadService;
use App\Services\Ai\Cognition\AtlasImmuneClassifierHybridFreeze;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogRunner;
use App\Services\Ai\Cognition\Watchdog\Checks\AutonomyLadderAdversarialWatchdogCheck;

final class AtlasUniversalGatesEvaluatorTest extends TestCase
{
    private AtlasUniversalGatesEvaluator $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new AtlasUniversalGatesEvaluator;
    }

    public function test_catalogue_has_15_gates(): void
    {
        $this->assertCount(15, AtlasUniversalGatesEvaluator::UNIVERSAL_GATES);
    }

    public function test_all_green_signals_produce_green_outcome(): void
    {
        $signals = array_fill_keys(array_keys(AtlasUniversalGatesEvaluator::UNIVERSAL_GATES), true);
        $r = $this->svc->evaluate('i-1', $signals);
        $this->assertSame('green', $r['outcome']);
        $this->assertSame(15, count($r['passed']));
        $this->assertSame([], $r['blocked']);
        $this->assertSame(1.0, $r['pass_rate']);
    }

    public function test_any_blocked_signal_marks_red(): void
    {
        $signals = array_fill_keys(array_keys(AtlasUniversalGatesEvaluator::UNIVERSAL_GATES), true);
        $signals['tests_green'] = false;
        $r = $this->svc->evaluate('i-1', $signals);
        $this->assertSame('red', $r['outcome']);
        $this->assertContains('tests_green', $r['blocked']);
    }

    public function test_missing_signal_marks_pending(): void
    {
        $signals = array_fill_keys(array_keys(AtlasUniversalGatesEvaluator::UNIVERSAL_GATES), true);
        unset($signals['coverage_min_threshold']);
        $r = $this->svc->evaluate('i-1', $signals);
        $this->assertSame('pending', $r['outcome']);
        $this->assertContains('coverage_min_threshold', $r['missing']);
    }

    public function test_exception_without_receipt_blocks(): void
    {
        $signals = array_fill_keys(array_keys(AtlasUniversalGatesEvaluator::UNIVERSAL_GATES), true);
        $signals['tests_green'] = 'exception';
        $r = $this->svc->evaluate('i-1', $signals);
        $this->assertSame('red', $r['outcome']);
        $this->assertContains('tests_green', $r['blocked']);
    }

    public function test_exception_with_receipt_is_accepted(): void
    {
        $signals = array_fill_keys(array_keys(AtlasUniversalGatesEvaluator::UNIVERSAL_GATES), true);
        $signals['tests_green'] = 'exception';
        $r = $this->svc->evaluate('i-1', $signals, ['tests_green' => 'rcpt:42']);
        $this->assertSame('exception', $r['outcome']);
        $this->assertCount(1, $r['exception']);
        $this->assertSame('rcpt:42', $r['exception'][0]['receipt_id']);
    }

    public function test_report_hash_is_deterministic(): void
    {
        $signals = array_fill_keys(array_keys(AtlasUniversalGatesEvaluator::UNIVERSAL_GATES), true);
        $a = $this->svc->evaluate('i-1', $signals);
        $b = $this->svc->evaluate('i-1', $signals);
        $this->assertSame($a['report_hash'], $b['report_hash']);
    }

    public function test_empty_intent_id_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->evaluate('', []);
    }

    public function test_provider_safe_flag_set(): void
    {
        $r = $this->svc->evaluate('i-1', []);
        $this->assertTrue($r['provider_safe']);
        $this->assertSame('atlas.aaeos.gate_report.v1', $r['schema']);
    }

    public function test_quality_bar_telemetry_contract_is_not_defined_in_evaluator(): void
    {
        $evaluatorPath = (new \ReflectionClass(AtlasUniversalGatesEvaluator::class))->getFileName();
        $source = (string) file_get_contents($evaluatorPath);

        $this->assertStringNotContainsString('class QualityBarTelemetryContract', $source);
        $this->assertTrue(class_exists(QualityBarTelemetryContract::class));
        $this->assertSame(
            'atlas.aaeos.quality_bar_telemetry.v1',
            QualityBarTelemetryContract::defaults()->toArray()['schema_version'],
        );
    }

    public function test_delivery_pack_completeness_signal_uses_live_scorer(): void
    {
        $this->assertSame(
            DeliveryPackCompletenessScorer::class,
            AtlasUniversalGatesEvaluator::UNIVERSAL_GATES['delivery_pack_completeness_min_0_95']['canonical_source'],
        );

        $complete = [
            'changed_files' => 2,
            'test_evidence' => ['tests/ExampleTest.php'],
            'no_test_reason' => '',
            'evidence_hashes' => ['sha256:aa'],
            'risk_register_present' => true,
            'receipt_present' => true,
            'delivery_hash' => 'sha256:signed',
        ];
        $this->assertTrue($this->svc->deliveryPackCompletenessSignal($complete));

        $unsigned = $complete;
        $unsigned['delivery_hash'] = '';
        $this->assertFalse($this->svc->deliveryPackCompletenessSignal($unsigned));
    }

    public function test_delivery_pack_completeness_score_observe_projects_factors(): void
    {
        $score = $this->svc->deliveryPackCompletenessScoreObserve([
            'changed_files' => 1,
            'test_evidence' => ['t'],
            'no_test_reason' => '',
            'evidence_hashes' => ['h'],
            'risk_register_present' => true,
            'receipt_present' => true,
            'delivery_hash' => 'sha256:signed',
        ]);

        $this->assertSame('atlas.aaeos.delivery_pack_completeness.v1', $score['schema']);
        $this->assertTrue($score['factors']['tests_present']);
        $this->assertSame([], $score['blockers']);
    }

    public function test_spec_completeness_signal_uses_live_scorer(): void
    {
        $full = [];
        foreach (array_keys(SpecCompletenessScorer::WEIGHTS) as $field) {
            $full[$field] = in_array($field, ['non_goals', 'requirements', 'acceptance_criteria', 'assumptions', 'blocking_questions'], true)
                ? ['enough detail here']
                : 'enough detail here';
        }
        $full['blocking_questions'] = [];

        $this->assertTrue($this->svc->specCompletenessSignal($full));
        $this->assertFalse($this->svc->specCompletenessSignal([]));

        $score = $this->svc->specCompletenessScoreObserve([]);
        $this->assertSame(SpecCompletenessScorer::SCHEMA_VERSION, $score['schema_version']);
    }

    public function test_quality_bar_telemetry_observe_projects_m5_contract(): void
    {
        $payload = $this->svc->qualityBarTelemetryObserve([
            'department_id' => 'dev',
            'breach_count' => 2,
            'evidence_hash' => 'sha256:qb',
            'threshold_breaches' => [['metric' => 'coverage', 'comparator' => 'lt', 'value' => 0.8, 'observed' => 0.7, 'unit' => 'ratio']],
        ]);

        $this->assertSame(QualityBarTelemetryContract::SCHEMA, $payload['schema_version']);
        $this->assertSame('dev', $payload['inputs']['department_id']);
        $this->assertSame(2, $payload['inputs']['breach_count']);
        $this->assertSame(QualityBarTelemetryContract::IMMUNE_GATE_ID, $payload['immune_gate_id']);
    }

    public function test_architect_spec_pack_observe_projects_m1_contract(): void
    {
        $payload = $this->svc->architectSpecPackObserve([
            'risk_scope' => 'R4',
            'spec_pack_hash' => 'sha256:sp',
            'acceptance_criteria_present' => true,
        ]);

        $this->assertSame(ArchitectAgentSpecPackGateContract::SCHEMA, $payload['schema_version']);
        $this->assertSame('R4', $payload['inputs']['risk_scope']);
        $this->assertSame('sha256:sp', $payload['inputs']['spec_pack_hash']);
        $this->assertTrue($payload['inputs']['acceptance_criteria_present']);
    }

    public function test_predicted_impact_band_observe_classifies_candidate(): void
    {
        $payload = $this->svc->predictedImpactBandObserve([
            'rung' => 'obra',
            'rank' => 1,
            'path_yield' => 0.8,
        ]);

        $this->assertSame(PredictedImpactBand::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('high', $payload['band']);
        $this->assertSame('obra', $payload['components']['rung']);
        $this->assertFalse($payload['source']['influences_pick']);
    }

    public function test_predicted_impact_calibration_observe_projects_rows(): void
    {
        $payload = $this->svc->predictedImpactCalibrationObserve([
            'rows' => [
                ['band' => 'high', 'status' => 'resolved', 'realized' => true],
                ['band' => 'low', 'status' => 'unresolved'],
            ],
        ]);

        $this->assertSame(PredictedImpactBand::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(1, $payload['bands']['high']['n_realized']);
        $this->assertSame(1, $payload['bands']['high']['realized_true']);
        $this->assertSame(1, $payload['bands']['low']['unresolved']);
        $this->assertTrue($payload['source']['report_only']);
    }

    public function test_pre_review_advisory_observe_judges_features(): void
    {
        $payload = $this->svc->preReviewAdvisoryObserve([
            'target_class' => 'ops',
            'risk_band' => 'high',
            'confidence_band' => 'sweet',
            'similar_revert_rate' => 0.4,
            'n_similar' => 3,
        ]);

        $this->assertSame(PreReviewAdvisoryBand::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('insufficient_sample', $payload['basis']);
        $this->assertFalse($payload['source']['blocks_auto_apply']);
    }

    public function test_reality_compiler_slice_observe_projects_contract(): void
    {
        $payload = $this->svc->realityCompilerSliceObserve([
            'intent' => ' compile-slice ',
            'autonomy_level' => ' L2 ',
        ]);

        $this->assertSame(RealityCompilerSlice::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('compile-slice', $payload['intent']);
        $this->assertSame('L2', $payload['autonomy_level']);
        $this->assertCount(5, $payload['output_phases']);
        $this->assertSame('pending', $payload['output_phases'][0]['status']);
    }

    public function test_esp09_challenger_observe_projects_advisory(): void
    {
        $payload = $this->svc->esp09ChallengerObserve([
            'author_engine_id' => 'author-a',
            'challenger_engine_id' => 'challenger-b',
            'decision_kind' => 'composed_obra',
            'operator_alignment' => 0.9,
        ]);

        $this->assertSame(Esp09IndependentChallengerService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertTrue($payload['advisory_only']);
        $this->assertTrue($payload['triggered']);
    }

    public function test_esp09_promotion_gate_observe_delays_when_missing(): void
    {
        $payload = $this->svc->esp09PromotionGateObserve([
            'requires_challenger' => true,
            'challenger_block_present' => false,
        ]);

        $this->assertSame(Esp09IndependentChallengerService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('delayed', $payload['status']);
        $this->assertTrue($payload['promotion_delayed']);
        $this->assertFalse($payload['vetoed']);
    }

    public function test_esp09_refutation_series_observe_projects_events(): void
    {
        $payload = $this->svc->esp09RefutationSeriesObserve([
            'events' => [
                ['outcome' => 'ignored', 'window' => 'w1'],
                ['outcome' => 'ignored', 'window' => 'w1'],
            ],
            'min_windows' => 2,
            'min_per_window' => 2,
        ]);

        $this->assertSame(Esp09IndependentChallengerService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(0.0, $payload['accepted_rate']);
    }

    public function test_dogfooding_friction_leads_observe_mines_events(): void
    {
        $payload = $this->svc->dogfoodingFrictionLeadsObserve([
            'events' => [
                ['signature' => 'slow-boot', 'target' => 'cli'],
                ['signature' => 'slow-boot', 'target' => 'cli'],
            ],
        ]);

        $this->assertSame(DogfoodingFrictionLeadMiner::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('insufficient_signal', $payload['status']);
    }

    public function test_reactive_saturation_observe_classifies_windows(): void
    {
        $payload = $this->svc->reactiveSaturationObserve([
            'windows' => [
                ['n' => 10, 'yield' => 0.9],
                ['n' => 10, 'yield' => 0.7],
            ],
            'context' => ['queue_depth' => 2],
        ]);

        $this->assertSame(ReactiveSaturationSignal::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertFalse($payload['reactive_saturated']);
        $this->assertSame('insufficient_windows', $payload['basis']);
        $this->assertSame(2, $payload['queue_depth']);
    }

    public function test_blocker_severity_observe_assesses_blockers(): void
    {
        $payload = $this->svc->blockerSeverityObserve([
            'blockers' => [
                ['id' => 'b1', 'severity' => 'high', 'owner' => 'atlas-ai'],
                ['id' => 'b2', 'severity' => 'medium', 'owner' => 'atlas-ai'],
            ],
        ]);

        $this->assertSame('blocked', $payload['signal']);
        $this->assertSame(1, $payload['high_count']);
        $this->assertSame(1, $payload['medium_count']);
    }

    public function test_phase_advance_verdict_observe_classifies_envelope(): void
    {
        $payload = $this->svc->phaseAdvanceVerdictObserve([
            'phase_out' => 'spec',
            'gates' => [
                'required' => ['tests_green'],
                'passed' => ['tests_green'],
                'blocked' => [],
            ],
            'blockers' => [],
        ]);

        $this->assertSame('advance', $payload['verdict']);
        $this->assertSame([], $payload['missing_gates']);
    }

    public function test_required_gate_coverage_observe_reports_missing(): void
    {
        $payload = $this->svc->requiredGateCoverageObserve([
            'required' => ['lint_green', 'tests_green'],
            'passed' => ['lint_green'],
        ]);

        $this->assertFalse($payload['satisfied']);
        $this->assertSame(['tests_green'], $payload['missing']);
    }

    public function test_outcome_causality_observe_ranks_envelope(): void
    {
        $payload = $this->svc->outcomeCausalityObserve([
            'outcome' => 'failed',
            'has_evidence_refs' => false,
            'tests_passed' => false,
        ]);

        $this->assertSame('atlas.aaeos.outcome_causality_ranking.v1', $payload['schema_version']);
        $this->assertNotSame('', $payload['primary_cause']);
        $this->assertIsArray($payload['candidates']);
    }

    public function test_summary_fidelity_coverage_observe_scores_items(): void
    {
        $payload = $this->svc->summaryFidelityCoverageObserve([
            'required_items' => [
                ['id' => 'dec-1', 'kind' => 'decision', 'digest' => 'keep the gate catalogue at 15'],
            ],
            'summary_text' => 'We keep the gate catalogue at 15 universal gates.',
        ]);

        $this->assertSame(SummaryFidelityCoverageScorer::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertGreaterThan(0.0, $payload['context_retention_score']);
        $this->assertSame(1, $payload['present_total']);
    }

    public function test_memory_injection_budget_observe_allocates_items(): void
    {
        $payload = $this->svc->memoryInjectionBudgetObserve([
            'ranked_items' => [
                ['ref' => 'a', 'priority' => 90, 'estimated_chars' => 300],
                ['ref' => 'b', 'priority' => 10, 'estimated_chars' => 300],
            ],
            'total_budget_chars' => 400,
            'per_item_cap_chars' => 300,
            'min_excerpt_chars' => 40,
        ]);

        $this->assertSame(MemoryInjectionBudgetAllocator::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(2, $payload['admitted_count']);
        $this->assertSame('a', $payload['admitted'][0]['ref']);
        $this->assertSame(100, $payload['admitted'][1]['allocated_chars']);
        $this->assertTrue($payload['admitted'][1]['capped']);
    }

    public function test_memory_feedback_decay_observe_scores_signals(): void
    {
        $payload = $this->svc->memoryFeedbackDecayObserve([
            'positive_count' => 2,
            'negative_count' => 0,
            'wrong_context_count' => 0,
            'stale_count' => 0,
            'base_priority' => 50,
            'recall_eval_hit_rate' => 0.8,
        ]);

        $this->assertSame(MemoryFeedbackDecayScorer::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertGreaterThan(50, $payload['health_score']);
        $this->assertIsString($payload['lifecycle_action']);
    }

    public function test_segment_importance_observe_ranks_segments(): void
    {
        $payload = $this->svc->segmentImportanceObserve([
            'segments' => [
                [
                    'id' => 'keep-me',
                    'kind' => 'decision',
                    'recency_rank' => 0,
                    'token_estimate' => 10,
                    'has_evidence_ref' => true,
                    'links_decision_or_blocker' => false,
                    'dup_group' => null,
                ],
            ],
            'token_budget' => 50,
        ]);

        $this->assertSame(SegmentImportanceRanker::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(['keep-me'], $payload['kept_ids']);
        $this->assertSame(1, $payload['kept_count']);
    }

    public function test_context_pareto_dominance_observe_filters_variants(): void
    {
        $payload = $this->svc->contextParetoDominanceObserve([
            'variants' => [
                ['id' => 'a', 'quality' => 0.9, 'cost' => 0.2],
                ['id' => 'b', 'quality' => 0.5, 'cost' => 0.8],
            ],
            'objective_direction' => [
                'quality' => 'maximize',
                'cost' => 'minimize',
            ],
        ]);

        $this->assertSame(ContextParetoDominanceFilter::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertContains('a', $payload['frontier']);
    }

    public function test_memory_recall_rank_observe_orders_candidates(): void
    {
        $payload = $this->svc->memoryRecallRankObserve([
            'rows' => [
                [
                    'title' => 'WeakPreference',
                    'type' => 'preference',
                    'scope_type' => 'user',
                    'priority' => 40,
                    'importance' => 2,
                    'confidence' => 0.5,
                    'hybrid_score' => 0,
                ],
                [
                    'title' => 'StrongDecision',
                    'type' => 'decision',
                    'scope_type' => 'task',
                    'priority' => 80,
                    'importance' => 5,
                    'confidence' => 0.9,
                    'hybrid_score' => 0.5,
                ],
            ],
        ]);

        $this->assertSame(AtlasMemoryRecallRelevanceScorer::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(2, $payload['count']);
        $this->assertSame('StrongDecision', $payload['ranked'][0]['title']);
        $this->assertSame(1, $payload['ranked'][0]['rank']);
    }

    public function test_portfolio_budget_observe_derives_allocation(): void
    {
        $payload = $this->svc->portfolioBudgetObserve([
            'default_mix' => ['reactive' => 0.5, 'originated' => 0.3, 'maintenance' => 0.2],
            'operator_weights' => ['reactive' => 0.5, 'originated' => 0.3, 'maintenance' => 0.2],
            'yield_by_class' => [
                'reactive' => ['n' => 10, 'mean_proven_yield' => 0.4],
                'originated' => ['n' => 10, 'mean_proven_yield' => 0.5],
                'maintenance' => ['n' => 10, 'mean_proven_yield' => 0.3],
            ],
        ]);

        $this->assertSame(PortfolioBudgetAllocator::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertArrayHasKey('allocation', $payload);
        $this->assertSame('ok', $payload['status']);
    }

    public function test_ambition_rung_observe_selects_candidate(): void
    {
        $payload = $this->svc->ambitionRungObserve([
            'candidates' => [
                ['id' => 't1', 'rung' => 'task', 'leverage' => 1.0],
                ['id' => 's1', 'rung' => 'slice', 'leverage' => 2.0],
            ],
            'context' => [
                'enabled' => true,
                'reactive_saturated' => true,
                'current_rung' => 'task',
            ],
        ]);

        $this->assertSame(AmbitionRungPolicy::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('s1', $payload['selected_id']);
        $this->assertSame('rung_up_after_saturation', $payload['basis']);
    }

    public function test_domain_lexical_observe_scores_query(): void
    {
        $payload = $this->svc->domainLexicalObserve([
            'query' => 'memoria do cerebro',
            'fields' => ['memory brain pipeline'],
        ]);

        $this->assertSame(DomainLexicalNormalizer::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertGreaterThan(0.0, $payload['score']);
        $this->assertContains('memory', $payload['tokens']);
    }

    public function test_gated_corpus_candidates_observe_mines_sources(): void
    {
        $payload = $this->svc->gatedCorpusCandidatesObserve([
            'sources' => [
                ['ref' => 'doc:1', 'text' => 'normal corpus text', 'privacy_class' => 'normal', 'source' => 'vault'],
                ['ref' => 'sec:1', 'text' => 'secret', 'privacy_class' => 'secret', 'source' => 'vault'],
            ],
        ]);

        $this->assertSame(GatedCorpusCandidateMiner::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertCount(1, $payload['candidates']);
        $this->assertContains('protected_class_omitted', $payload['omitted']);
    }

    public function test_structured_fact_schema_observe_reports_missing(): void
    {
        $payload = $this->svc->structuredFactSchemaObserve([
            'memory_type' => 'decision',
            'facts' => [
                'contexto' => 'x',
                'alternativas' => 'y',
            ],
        ]);

        $this->assertSame(StructuredFactSchemaMap::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertFalse($payload['valid']);
        $this->assertContains('porque', $payload['missing']);
        $this->assertContains('expiry', $payload['missing']);
    }

    public function test_citation_grounding_observe_measures_responses(): void
    {
        $payload = $this->svc->citationGroundingObserve([
            'responses' => [
                [
                    'response' => 'See ref=memory:abc and code:Foo',
                    'delivered_refs' => ['memory:abc'],
                ],
            ],
        ]);

        $this->assertSame(CitationGroundingMeter::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(1, $payload['unsupported_citation_count']);
        $this->assertSame(0.5, $payload['grounding_rate']);
    }

    public function test_provenance_weight_observe_resolves_verified_refs(): void
    {
        $payload = $this->svc->provenanceWeightObserve([
            'evidence_refs' => ['ev:1', 'missing'],
            'verified_refs' => ['ev:1'],
        ]);

        $this->assertSame(ProvenanceWeightCalculator::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(1, $payload['resolved_count']);
        $this->assertSame(['missing'], $payload['dead_refs']);
        $this->assertSame(0.6, $payload['multiplier']);
    }

    public function test_recall_gap_observe_aggregates_weak_queries(): void
    {
        $payload = $this->svc->recallGapObserve([
            'events' => [
                ['query' => 'missing concept', 'top_score' => 0.0],
                ['query' => 'missing concept', 'top_score' => 0.1],
                ['query' => 'missing concept', 'top_score' => 0.2],
            ],
            'min_occurrences' => 3,
        ]);

        $this->assertSame(RecallGapAggregator::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(3, $payload['candidates'][0]['occurrences']);
    }

    public function test_belief_cascade_observe_marks_descendants(): void
    {
        $payload = $this->svc->beliefCascadeObserve([
            'origin' => 'A',
            'graph' => ['A' => ['B'], 'B' => ['C']],
            'depth_cap' => 3,
        ]);

        $this->assertSame(BeliefCascadeReverificationPlanner::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(['B', 'C'], array_column($payload['marked'], 'id'));
    }

    public function test_ledger_rotation_observe_finds_declared_policy(): void
    {
        $payload = $this->svc->ledgerRotationObserve([
            'series' => 'atlas.evidence_ledger.hash_chain.v1',
        ]);

        $this->assertSame('atlas.aaeos.ledger_rotation_observe.v1', $payload['schema_version']);
        $this->assertTrue($payload['found']);
        $this->assertSame('append_forever', $payload['policy']['mode']);
        $this->assertGreaterThan(0, $payload['declared_series_count']);
    }

    public function test_evidence_vision_observe_reports_fence_failure(): void
    {
        $payload = $this->svc->evidenceVisionObserve([
            'thesis' => [
                'claim' => 'ok claim with secret-token',
                'death_criterion' => ['described_at_birth' => 'when recovered'],
                'evidence' => [
                    ['source' => 'series', 'ref' => 'series:atlas.m.funnel.v1'],
                ],
            ],
            'forbidden' => ['secret-token'],
        ]);

        $this->assertSame('atlas.aaeos.evidence_vision_observe.v1', $payload['schema_version']);
        $this->assertTrue($payload['field_sources_valid']);
        $this->assertFalse($payload['operator_fence_pass']);
    }

    public function test_gate_signal_spec_pack_observe_counts_criteria(): void
    {
        $payload = $this->svc->gateSignalSpecPackObserve([
            'acceptance_criteria' => ['a', 'b', 'c'],
        ]);

        $this->assertSame('atlas.aaeos.gate_signal.v1', $payload['schema_version']);
        $this->assertSame('spec_pack_acceptance_criteria_min_3', $payload['gate']);
        $this->assertTrue($payload['passed']);
        $this->assertSame(3, $payload['computed_value']);
    }

    public function test_gate_signal_intent_observe_scores_clarity(): void
    {
        $payload = $this->svc->gateSignalIntentObserve([
            'resolved_target' => 'app/Services/Ai/Aaeos/Foo.php',
            'scope_bounded' => true,
            'ambiguity_tokens' => [],
            'missing_answers' => [],
        ]);

        $this->assertSame('atlas.aaeos.gate_signal.v1', $payload['schema_version']);
        $this->assertSame('intent_clarity_score_min_0_8', $payload['gate']);
        $this->assertTrue($payload['passed']);
        $this->assertGreaterThanOrEqual(0.8, $payload['computed_value']);
    }

    public function test_gate_signal_task_pack_observe_checks_atomicity(): void
    {
        $payload = $this->svc->gateSignalTaskPackObserve([
            'tasks' => [['scope' => 'build login', 'acceptance' => 'renders']],
        ]);

        $this->assertSame('atlas.aaeos.gate_signal.v1', $payload['schema_version']);
        $this->assertSame('task_pack_atomic_true_for_each', $payload['gate']);
        $this->assertTrue($payload['passed']);
    }

    public function test_gate_signal_phase_observe_rolls_up_intent(): void
    {
        $payload = $this->svc->gateSignalPhaseObserve([
            'intent' => [
                'resolved_target' => 'app/Services/Ai/Aaeos/Foo.php',
                'scope_bounded' => true,
                'ambiguity_tokens' => [],
                'missing_answers' => [],
            ],
        ]);

        $this->assertSame('atlas.aaeos.gate_signal.v1', $payload['schema_version']);
        $this->assertArrayHasKey('gates', $payload);
    }

    public function test_threshold_ladder_observe_normalizes_levels(): void
    {
        $payload = $this->svc->thresholdLadderObserve([
            'band_ladder' => [
                [
                    'level' => '  green  ',
                    'thresholds' => [
                        ['metric' => '  coverage  ', 'comparator' => '>=', 'value' => 0.9],
                    ],
                ],
            ],
        ]);

        $this->assertSame('atlas.aaeos.threshold_ladder_observe.v1', $payload['schema_version']);
        $this->assertTrue($payload['valid']);
        $this->assertSame(1, $payload['band_count']);
        $this->assertSame('green', $payload['ladder'][0]['level']);
        $this->assertSame('coverage', $payload['ladder'][0]['thresholds'][0]['metric']);
    }

    public function test_kb_embedding_coverage_observe_returns_ruler_schema(): void
    {
        $payload = $this->svc->kbEmbeddingCoverageObserve([]);

        $this->assertSame(AtlasKnowledgeItemEmbeddingCoverageService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AtlasKnowledgeItemEmbeddingCoverageService::MEASURE_ID, $payload['measure_id']);
        $this->assertArrayHasKey('status', $payload);
        $this->assertArrayHasKey('aggregate', $payload);
    }

    public function test_code_symbol_embedding_coverage_observe_returns_ruler_schema(): void
    {
        $payload = $this->svc->codeSymbolEmbeddingCoverageObserve([]);

        $this->assertSame(AtlasCodeSymbolEmbeddingCoverageService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AtlasCodeSymbolEmbeddingCoverageService::MEASURE_ID, $payload['measure_id']);
        $this->assertArrayHasKey('status', $payload);
        $this->assertArrayHasKey('aggregate', $payload);
    }

    public function test_predicted_revert_digest_observe_composes_items(): void
    {
        $payload = $this->svc->predictedRevertDigestObserve([
            'items' => [
                [
                    'id' => 'r1',
                    'title' => 'Review me',
                    'predicted_revert_band' => 'high',
                    'decision_id' => 'd1',
                    'family' => 'acos',
                ],
            ],
            'limit' => 10,
        ]);

        $this->assertSame(Teto10PredictedRevertReviewDigest::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(1, $payload['item_count']);
        $this->assertSame(1, $payload['band_counts']['high']);
    }

    public function test_jina_dual_read_ledger_observe_resolves_path(): void
    {
        $custom = sys_get_temp_dir().'/atlas-jina-dual-'.uniqid('', true).'.jsonl';
        $payload = $this->svc->jinaDualReadLedgerObserve(['path' => '  '.$custom.'  ']);

        $this->assertSame(Maxa04JinaV3DualReadLedger::SCHEMA, $payload['schema_version']);
        $this->assertSame($custom, $payload['path']);
        $this->assertTrue($payload['custom_path']);
        $this->assertSame(Maxa04JinaV3DualReadLedger::RELATIVE_PATH, $payload['relative_path']);
    }

    public function test_resource_budget_observe_trims_component_fields(): void
    {
        $payload = $this->svc->resourceBudgetObserve([
            'budget' => [
                'schema_version' => 'atlas.resource_budget.v1',
                'host_ram_gib' => 4,
                'engine_floor_gib' => 1,
                'components' => [
                    [
                        'name' => '  peel_worker  ',
                        'purpose' => '  peel purpose  ',
                        'ram_cap_mb' => 256,
                        'disk_cap_mb' => 64,
                        'cpu_share' => '  shared  ',
                        'probe_hint' => '  rss  ',
                    ],
                ],
            ],
        ]);

        $this->assertSame('atlas.resource_budget.v1', $payload['schema_version']);
        $this->assertSame('paper_fits', $payload['declared_paper_status']);
        $this->assertCount(1, $payload['components']);
        $this->assertSame('peel_worker', $payload['components'][0]['name']);
        $this->assertSame('peel purpose', $payload['components'][0]['purpose']);
        $this->assertSame('shared', $payload['components'][0]['cpu_share']);
        $this->assertSame('rss', $payload['components'][0]['probe_hint']);
    }

    public function test_model_capability_spec_observe_verifies_model(): void
    {
        $payload = $this->svc->modelCapabilitySpecObserve([
            'function' => ' Dense_Embed ',
            'model' => [
                'model_id' => 'sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2',
                'dim' => 384,
                'pooling' => 'mean',
                'ctx_tokens' => 512,
                'multilingual_pt' => true,
                'deterministic' => true,
                'license' => 'apache-2.0',
            ],
        ]);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame('dense_embed', $payload['function']);
        $this->assertSame([], $payload['violations']);
    }

    public function test_model_capability_spec_observe_unknown_function(): void
    {
        $payload = $this->svc->modelCapabilitySpecObserve([
            'function' => 'not_a_real_fn',
            'model' => ['model_id' => 'x'],
        ]);

        $this->assertSame('unknown_function', $payload['status']);
        $this->assertSame('not_a_real_fn', $payload['function']);
        $this->assertSame('unknown_model_function', $payload['violations'][0]['reason']);
    }

    public function test_measure_series_freshness_observe_reads_jsonl(): void
    {
        $path = sys_get_temp_dir().'/atlas-msf-'.uniqid('', true).'.jsonl';
        file_put_contents($path, json_encode(['recorded_at' => '2026-01-02T03:04:05Z'])."\n");

        try {
            $payload = $this->svc->measureSeriesFreshnessObserve([
                'series' => 'acos.test.freshness',
                'source_type' => 'jsonl',
                'path' => $path,
                'timestamp_field' => 'recorded_at',
            ]);

            $this->assertSame(AcosMeasureSeriesFreshnessReader::SCHEMA, $payload['schema_version']);
            $this->assertTrue($payload['fresh']);
            $this->assertSame('acos.test.freshness', $payload['series']);
            $this->assertNotNull($payload['last_append_at']);
        } finally {
            @unlink($path);
        }
    }

    public function test_verified_share_observe_reports_measure_shape(): void
    {
        $payload = $this->svc->verifiedShareObserve(['days' => 7]);

        $this->assertSame(AcosMaxVerifiedShareService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AcosMaxVerifiedShareService::MEASURE_ID, $payload['measure_id']);
        $this->assertArrayHasKey('status', $payload);
    }

    public function test_ragx_chain_observe_reports_stage_map(): void
    {
        $payload = $this->svc->ragxChainObserve(['deps' => ['louvain_ready' => true]]);

        $this->assertSame(RagxChainMechanismService::SCHEMA, $payload['schema_version']);
        $this->assertArrayHasKey('stages', $payload);
        $this->assertFalse($payload['ab_green_claimed']);
    }

    public function test_procedural_skill_promoter_observe_reports_shape(): void
    {
        $payload = $this->svc->proceduralSkillPromoterObserve(['floor' => 8]);

        $this->assertSame(AcosMaxProceduralSkillPromoterService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('MULTJ-04', $payload['slice']);
        $this->assertArrayHasKey('status', $payload);
        $this->assertFalse($payload['promotion_allowed']);
    }

    public function test_aaeos_phase_router_observe_reports_snapshot(): void
    {
        $payload = $this->svc->aaeosPhaseRouterObserve(['phase' => ' 2 ']);

        $this->assertSame(AtlasAaeosPhaseRouterService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('2', $payload['configured_phase']);
        $this->assertTrue($payload['is_valid']);
        $this->assertTrue($payload['is_active']);
        $this->assertTrue($payload['phase_capabilities']['classification']);
    }

    public function test_aaeos_quality_bar_observe_reports_departments(): void
    {
        $payload = $this->svc->aaeosQualityBarObserve([]);

        $this->assertSame('atlas.aaeos.quality_bar.v1', $payload['schema_version']);
        $this->assertNotEmpty($payload['departments']);
        $this->assertArrayHasKey('breach_count', $payload['signal']);
        $this->assertGreaterThan(0, $payload['signal']['breach_count']);
    }

    public function test_aaeos_department_maturity_observe_reports_matrix(): void
    {
        $payload = $this->svc->aaeosDepartmentMaturityObserve([]);

        $this->assertSame('atlas.aaeos.department_maturity.v1', $payload['schema_version']);
        $this->assertNotEmpty($payload['departments']);
        $this->assertSame('product', $payload['departments'][0]['department']);
    }

    public function test_veto_propagation_watchdog_observe_replays_events(): void
    {
        $payload = $this->svc->vetoPropagationWatchdogObserve([
            'events' => [
                ['department' => 'security'],
            ],
        ]);

        $this->assertArrayHasKey('paused_departments', $payload);
        $this->assertArrayHasKey('veto_receipts', $payload);
        $this->assertArrayHasKey('pause_sla_seconds', $payload);
    }

    public function test_repair_loop_guard_observe_admits_then_escalates(): void
    {
        $admitted = $this->svc->repairLoopGuardObserve(['current_iteration' => 0]);
        $this->assertTrue($admitted['admitted']);
        $this->assertFalse($admitted['escalated']);

        $escalated = $this->svc->repairLoopGuardObserve(['current_iteration' => 3]);
        $this->assertFalse($escalated['admitted']);
        $this->assertTrue($escalated['escalated']);
    }

    public function test_generated_contract_gate_observe_reports_quarantine(): void
    {
        $payload = $this->svc->generatedContractGateObserve([]);

        $this->assertSame(AaeosGeneratedContractGate::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertArrayHasKey('hot_path_enabled', $payload);
        $this->assertArrayHasKey('generated_file_count', $payload);
    }

    public function test_maturity_band_classifier_observe_classifies_ladder(): void
    {
        $payload = $this->svc->maturityBandClassifierObserve([
            'band_ladder' => [
                [
                    'band' => 'L1',
                    'rank' => 1,
                    'thresholds' => [
                        ['metric' => 'obra_completion_rate', 'comparator' => '>=', 'value' => 0.5],
                    ],
                ],
            ],
            'metrics_snapshot' => [
                'obra_completion_rate' => 0.9,
            ],
        ]);

        $this->assertSame('atlas.aaeos.department_maturity_band.v1', $payload['schema_version']);
        $this->assertSame('L1', $payload['qualified_band']);
        $this->assertFalse($payload['promotion_blocked']);
    }

    public function test_promotion_eligibility_observe_reports_verdict(): void
    {
        $payload = $this->svc->promotionEligibilityObserve([
            'department' => [
                'current_tier' => 2,
                'blockers_to_next' => [
                    ['id' => 'sec-audit', 'resolved' => true],
                ],
                'last_evaluation' => '2026-05-25T00:00:00+00:00',
            ],
            'metrics' => [
                'current_score' => 85.0,
                'tier_thresholds' => [1 => 50.0, 2 => 65.0, 3 => 80.0],
            ],
            'options' => [
                'as_of' => '2026-05-30T00:00:00+00:00',
                'max_evidence_age_days' => 30,
                'max_tier' => 5,
            ],
        ]);

        $this->assertSame('atlas.aaeos.department_promotion_eligibility.v1', $payload['schema_version']);
        $this->assertSame('eligible', $payload['verdict']);
        $this->assertFalse($payload['promotion_allowed']);
    }

    public function test_debug_root_cause_observe_reports_analysis(): void
    {
        $payload = $this->svc->debugRootCauseObserve(['suspected_cause' => '  flaky_gate  ']);

        $this->assertSame('atlas.aaeos.debug.root_cause.v1', $payload['version']);
        $this->assertSame('analyzed', $payload['status']);
        $this->assertSame('flaky_gate', $payload['root_cause']);
    }

    public function test_cross_department_choreography_observe_evaluates_veto(): void
    {
        $payload = $this->svc->crossDepartmentChoreographyObserve([
            'mode' => 'veto',
            'department' => '  Security  ',
        ]);

        $this->assertSame('atlas.aaeos.cross_dept.handoff.v1', $payload['schema_version']);
        $this->assertTrue($payload['recognized']);
        $this->assertSame('security', $payload['vetoing_department']);
        $this->assertSame('pause_downstream', $payload['action']);
    }

    public function test_docs_authority_locate_observe_fail_open(): void
    {
        $payload = $this->svc->docsAuthorityLocateObserve(['needle' => 'aaeos']);

        $this->assertSame('atlas.docs.locate.v1', $payload['schema_version']);
        $this->assertArrayHasKey('resolved', $payload);
        $this->assertArrayHasKey('candidates', $payload);
    }

    public function test_department_level_classifier_observe_classifies_ladder(): void
    {
        $payload = $this->svc->departmentLevelClassifierObserve([
            'department_id' => ' forge ',
            'metrics_snapshot' => [
                'obra_completion_rate' => '0.95',
            ],
            'band_ladder' => [
                [
                    'level' => 'L1',
                    'thresholds' => [
                        ['metric' => 'obra_completion_rate', 'comparator' => '>=', 'value' => 0.5],
                    ],
                ],
            ],
        ]);

        $this->assertSame('atlas.aaeos.department_level_classification.v1', $payload['schema_version']);
        $this->assertSame('forge', $payload['department_id']);
        $this->assertSame('L1', $payload['earned_level']);
    }

    public function test_quality_bar_level_classifier_observe_classifies_ladder(): void
    {
        $payload = $this->svc->qualityBarLevelClassifierObserve([
            'department_id' => ' forge ',
            'measured_metrics' => [
                'obra_completion_rate' => '0.95',
            ],
            'band_ladder' => [
                [
                    'level' => 'L1',
                    'thresholds' => [
                        ['metric' => 'obra_completion_rate', 'comparator' => '>=', 'value' => 0.5],
                    ],
                ],
            ],
        ]);

        $this->assertSame('atlas.aaeos.quality_bar_level.v1', $payload['schema_version']);
        $this->assertSame('forge', $payload['department_id']);
        $this->assertSame('L1', $payload['achieved_level']);
    }

    public function test_implementation_truth_evaluate_observe_reports_partial(): void
    {
        $payload = $this->svc->implementationTruthEvaluateObserve([
            'claimed_state' => 'verified',
            'resolutions' => [
                ['kind' => 'symbol', 'ref' => 'Foo', 'resolved' => true, 'matched' => 'Foo'],
                ['kind' => 'route', 'ref' => '/x', 'resolved' => true, 'matched' => '/x'],
            ],
            'green_test_run' => false,
        ]);

        $this->assertSame('partial', $payload['computed_state']);
        $this->assertArrayHasKey('test_resolution', $payload);
    }

    public function test_phase_handoff_catalogue_observe_lists_seventeen_phases(): void
    {
        $payload = $this->svc->phaseHandoffCatalogueObserve(['autonomy_level' => ' L4 ']);

        $this->assertSame(AaeosPhaseHandoffService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(17, $payload['phase_count']);
        $this->assertCount(17, $payload['phases']);
        $this->assertSame(4, $payload['autonomy_level_int']);
    }

    public function test_golden_counterfactual_replay_observe_fail_open(): void
    {
        $payload = $this->svc->goldenCounterfactualReplayObserve([]);

        $this->assertSame(GoldenCounterfactualReplayService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('skipped', $payload['status']);
        $this->assertSame('paired_golden_runs_unavailable', $payload['reason']);
    }

    public function test_composed_obra_arc_observe_reports_flag_disabled(): void
    {
        $payload = $this->svc->composedObraArcObserve([
            'candidates' => [],
            'context' => ['enabled' => false],
        ]);

        $this->assertSame(ComposedObraArcComposer::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('flag_disabled', $payload['basis'] ?? $payload['reason'] ?? null);
    }

    public function test_exploratory_bets_portfolio_observe_reports_flag_disabled(): void
    {
        $payload = $this->svc->exploratoryBetsPortfolioObserve([
            'candidates' => [],
            'context' => ['enabled' => false],
        ]);

        $this->assertSame(ExploratoryBetsPortfolio::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('flag_disabled', $payload['status']);
    }

    public function test_n_capture_drill_observe_reports_schema(): void
    {
        $payload = $this->svc->nCaptureDrillObserve(['days' => 7]);

        $this->assertSame(AtlasNCaptureDrillService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertArrayHasKey('drills', $payload);
    }

    public function test_lote2_counterfactual_lift_observe_fail_open(): void
    {
        $payload = $this->svc->lote2CounterfactualLiftObserve([]);

        $this->assertArrayHasKey('status', $payload);
        $this->assertArrayHasKey('n_pairs', $payload);
        $this->assertSame(AcosMaxLote2MeasureService::MULTJ03_MEASURE_ID, $payload['measure_id'] ?? null);
    }

    public function test_string_list_normalize_observe_trims_values(): void
    {
        $payload = $this->svc->stringListNormalizeObserve([
            'values' => ['  alpha  ', 'beta', 'alpha', '', 7],
        ]);

        $this->assertSame('atlas.aaeos.string_list_normalize.v1', $payload['schema_version']);
        $this->assertContains('alpha', $payload['trimmed_strings']);
        $this->assertContains('7', $payload['trimmed_string_or_int_values']);
    }

    public function test_threshold_comparator_observe_reports_satisfaction(): void
    {
        $payload = $this->svc->thresholdComparatorObserve([
            'comparator' => '>=',
            'observed' => 0.95,
            'threshold' => 0.9,
        ]);

        $this->assertSame('atlas.aaeos.threshold_comparator.v1', $payload['schema_version']);
        $this->assertTrue($payload['binary_satisfied']);
        $this->assertTrue($payload['satisfied']);
    }

    public function test_evidence_ref_normalize_observe_parses_refs(): void
    {
        $payload = $this->svc->evidenceRefNormalizeObserve([
            'evidence_refs' => ['ledger:abc', ['kind' => ' file ', 'ref' => ' xyz ']],
        ]);

        $this->assertSame('atlas.aaeos.evidence_ref_normalize.v1', $payload['schema_version']);
        $this->assertSame(2, $payload['count']);
        $this->assertSame('ledger', $payload['evidence_refs'][0]['kind']);
        $this->assertSame('file', $payload['evidence_refs'][1]['kind']);
    }

    public function test_doc_maturity_classify_observe_reports_level(): void
    {
        $payload = $this->svc->docMaturityClassifyObserve([
            'sections' => [
                'mother_doc' => true,
                'contracts' => true,
                'runbook' => 'strong',
                'matrix' => 'strong',
                'quality_bar' => 'strong',
                'evidence' => 'strong',
                'gates' => 'strong',
            ],
        ]);

        $this->assertSame('atlas.aaeos.doc_maturity.v1', $payload['schema_version']);
        $this->assertSame('DOC L4', $payload['level']);
        $this->assertFalse($payload['runtime_ready']);
    }

    public function test_claim_definition_of_done_observe_reports_verdict(): void
    {
        $payload = $this->svc->claimDefinitionOfDoneObserve([
            'claim' => [
                'owner_doc' => 'docs/x.md',
                'documental_state' => 'complete',
                'runtime_state' => 'complete',
                'proof' => 'tests green',
                'code_command_applicable' => false,
            ],
        ]);

        $this->assertSame('atlas.aaeos.claim_definition_of_done.v1', $payload['schema_version']);
        $this->assertSame('evidence', $payload['verdict']);
    }

    public function test_array_field_reader_observe_reads_string_field(): void
    {
        $payload = $this->svc->arrayFieldReaderObserve([
            'row' => ['id' => 42],
            'key' => 'id',
        ]);

        $this->assertSame('atlas.aaeos.array_field_reader.v1', $payload['schema_version']);
        $this->assertSame('42', $payload['string_field']);
    }

    public function test_veto_propagation_resolve_observe_reports_schema(): void
    {
        $payload = $this->svc->vetoPropagationResolveObserve([
            'origin_department' => 'review',
            'veto_kind' => 'delivery',
            'repair_iteration' => 0,
        ]);

        $this->assertSame('atlas.aaeos.veto_propagation.v1', $payload['schema_version']);
        $this->assertArrayHasKey('resolution', $payload);
        $this->assertArrayHasKey('pause_set', $payload);
    }

    public function test_department_registry_validate_observe_reports_blockers(): void
    {
        $payload = $this->svc->departmentRegistryValidateObserve([
            'department' => [
                'id' => 'dev',
                'human_name' => 'Dev',
            ],
        ]);

        $this->assertFalse($payload['valid']);
        $this->assertNotSame([], $payload['blockers']);
    }

    public function test_cognitive_immune_classify_observe_reports_class(): void
    {
        $payload = $this->svc->cognitiveImmuneClassifyObserve([
            'text' => 'what time is it',
        ]);

        $this->assertSame('atlas.aaeos.cognitive_immune_input_classifier.v1', $payload['schema_version']);
        $this->assertArrayHasKey('input_class', $payload);
        $this->assertArrayHasKey('default_destination', $payload);
    }

    public function test_department_canonical_list_observe_reports_ids(): void
    {
        $payload = $this->svc->departmentCanonicalListObserve([]);

        $this->assertSame('atlas.aaeos.department.v1', $payload['schema_version']);
        $this->assertContains('dev', $payload['canonical_departments']);
        $this->assertSame(count($payload['canonical_departments']), $payload['count']);
        $this->assertContains('maturity_level', $payload['required_fields']);
        $this->assertSame(12, $payload['required_field_count']);
        $this->assertContains('L0', $payload['valid_maturity']);
        $this->assertSame(8, $payload['valid_maturity_count']);
    }

    public function test_universal_gates_catalogue_observe_reports_fifteen(): void
    {
        $payload = $this->svc->universalGatesCatalogueObserve([]);

        $this->assertSame('atlas.aaeos.universal_gates_catalogue.v1', $payload['schema_version']);
        $this->assertSame(15, $payload['count']);
        $this->assertArrayHasKey('tests_green', $payload['gates']);
    }

    public function test_outcome_attribution_types_observe_reports_catalogue(): void
    {
        $payload = $this->svc->outcomeAttributionTypesObserve([]);

        $this->assertSame('atlas.aaeos.outcome_attribution_types.v1', $payload['schema_version']);
        $this->assertContains('task_completed', $payload['outcome_types']);
        $this->assertSame(count($payload['outcome_types']), $payload['count']);
    }

    public function test_phase_router_valid_phases_observe_reports_catalogue(): void
    {
        $payload = $this->svc->phaseRouterValidPhasesObserve([]);

        $this->assertSame('atlas.aaeos.phase_router.v1', $payload['schema_version']);
        $this->assertContains('1', $payload['valid_phases']);
        $this->assertSame(count($payload['valid_phases']), $payload['count']);
        $this->assertSame(1, $payload['active_phase_ranks']['1']);
        $this->assertSame(4, $payload['active_phase_count']);
        $this->assertArrayHasKey('legacy', $payload['phase_descriptions']);
    }

    public function test_choreography_handoff_kinds_observe_reports_catalogue(): void
    {
        $payload = $this->svc->choreographyHandoffKindsObserve([]);

        $this->assertSame('atlas.aaeos.cross_dept.handoff.v1', $payload['schema_version']);
        $this->assertContains('delegation', $payload['handoff_kinds']);
        $this->assertSame(count($payload['handoff_kinds']), $payload['count']);
        $this->assertSame(10, $payload['veto_sla_seconds']);
        $this->assertSame(3, $payload['repair_max_iterations']);
    }

    public function test_reality_compiler_phases_observe_reports_catalogue(): void
    {
        $payload = $this->svc->realityCompilerPhasesObserve([]);

        $this->assertSame('atlas.reality_compiler.slice.v1', $payload['schema_version']);
        $this->assertContains('spec', $payload['execution_phases']);
        $this->assertSame(count($payload['execution_phases']), $payload['count']);
    }

    public function test_scope_risk_classes_observe_reports_catalogue(): void
    {
        $payload = $this->svc->scopeRiskClassesObserve([]);

        $this->assertSame('atlas.controlplane.scope_risk_budget_gate.v1', $payload['schema_version']);
        $this->assertContains('hardest', $payload['risk_classes']);
        $this->assertSame(count($payload['risk_classes']), $payload['count']);
        $this->assertSame('low', $payload['risk_floor_default']);
    }

    public function test_organ_mesh_phases_observe_reports_catalogue(): void
    {
        $payload = $this->svc->organMeshPhasesObserve([]);

        $this->assertSame('atlas.external_brain.organ_mesh_orchestrator.v1', $payload['schema_version']);
        $this->assertContains('queue_decision', $payload['phases']);
        $this->assertSame(count($payload['phases']), $payload['count']);
    }

    public function test_telemetry_surfaces_observe_reports_catalogue(): void
    {
        $payload = $this->svc->telemetrySurfacesObserve([]);

        $this->assertSame('atlas.telemetry.collector.surfaces.v1', $payload['schema_version']);
        $this->assertContains('cli', $payload['surfaces']);
        $this->assertContains('laravel', $payload['runtimes']);
        $this->assertSame(count($payload['surfaces']), $payload['surface_count']);
        $this->assertSame(count($payload['runtimes']), $payload['runtime_count']);
    }

    public function test_phase_signature_l4_observe_reports_catalogue(): void
    {
        $payload = $this->svc->phaseSignatureL4Observe([]);

        $this->assertSame('atlas.aaeos.phase.v1', $payload['schema_version']);
        $this->assertContains('human_review', $payload['phases_requiring_signature_at_l4']);
        $this->assertSame(count($payload['phases_requiring_signature_at_l4']), $payload['count']);
    }

    public function test_blocker_severity_levels_observe_reports_catalogue(): void
    {
        $payload = $this->svc->blockerSeverityLevelsObserve([]);

        $this->assertSame('atlas.aaeos.blocker_severity.v1', $payload['schema_version']);
        $this->assertContains('critical', $payload['levels']);
        $this->assertContains('high', $payload['decisive_levels']);
        $this->assertSame(count($payload['levels']), $payload['count']);
    }

    public function test_scope_high_risks_observe_reports_catalogue(): void
    {
        $payload = $this->svc->scopeHighRisksObserve([]);

        $this->assertSame('atlas.controlplane.scope_risk_budget_gate.v1', $payload['schema_version']);
        $this->assertContains('hardest', $payload['high_risks']);
        $this->assertSame(count($payload['high_risks']), $payload['count']);
        $this->assertSame(0.3, $payload['max_failure_rate']);
    }

    public function test_architect_spec_catalogue_observe_reports_catalogue(): void
    {
        $payload = $this->svc->architectSpecCatalogueObserve([]);

        $this->assertSame('atlas.aaeos.architect_agent_spec_pack_gate.v1', $payload['schema_version']);
        $this->assertContains('acceptance_criteria', $payload['required_spec_pack_artifacts']);
        $this->assertContains('adr_published', $payload['gates']);
        $this->assertSame(count($payload['required_spec_pack_artifacts']), $payload['artifact_count']);
        $this->assertSame('R4', $payload['min_autonomous_risk_scope']);
    }

    public function test_surprise_gate_bands_observe_reports_defaults(): void
    {
        $payload = $this->svc->surpriseGateBandsObserve([]);

        $this->assertSame('atlas.cognition.surprise_gate.bands.v1', $payload['schema_version']);
        $this->assertSame(0.5, $payload['default_threshold']);
        $this->assertSame(0.75, $payload['default_high_band']);
        $this->assertSame(8, $payload['default_min_prediction_tokens']);
        $this->assertTrue($payload['fail_open_when_prediction_thin']);
    }

    public function test_immune_calibration_contract_observe_reports_measure(): void
    {
        $payload = $this->svc->immuneCalibrationContractObserve([]);

        $this->assertSame('atlas.cognition.immune_calibration.v1', $payload['schema_version']);
        $this->assertSame('atlas.immune.calibration.v1', $payload['measure_id']);
        $this->assertContains('G0', $payload['gate_ids']);
        $this->assertContains('G8', $payload['gate_ids']);
        $this->assertSame(9, $payload['gate_count']);
        $this->assertSame(10, $payload['denominator_min']);
    }

    public function test_cognitive_immune_check_contract_observe_reports_catalogue(): void
    {
        $payload = $this->svc->cognitiveImmuneCheckContractObserve([]);

        $this->assertSame('atlas.cognition.cognitive_immune_check.v1', $payload['schema_version']);
        $this->assertContains('G0', $payload['gate_ids']);
        $this->assertContains('drift', $payload['check_categories']);
        $this->assertContains('prompt_injection', $payload['hostile_classes']);
        $this->assertSame(9, $payload['gate_count']);
        $this->assertSame('pending', $payload['default_gate_status']);
    }

    public function test_cognition_evidence_statuses_observe_reports_catalogue(): void
    {
        $payload = $this->svc->cognitionEvidenceStatusesObserve([]);

        $this->assertSame('atlas.cognition.evidence_statuses.v1', $payload['schema_version']);
        $this->assertContains('ready', $payload['evidence_statuses']);
        $this->assertContains('blocked', $payload['evidence_statuses']);
        $this->assertContains('self_construction', $payload['consumer_groups']);
        $this->assertSame(5, $payload['consumer_group_count']);
    }

    public function test_capture_hmac_lineage_observe_reports_stages(): void
    {
        $payload = $this->svc->captureHmacLineageObserve([]);

        $this->assertSame('atlas.capture.hmac_lineage.v1', $payload['schema_version']);
        $this->assertContains('source', $payload['stages']);
        $this->assertContains('memory', $payload['stages']);
        $this->assertContains('mem-09.memory_quality', $payload['health_report_check_ids']);
        $this->assertSame(10, $payload['health_report_check_count']);
    }

    public function test_cognitive_function_axes_observe_reports_catalogue(): void
    {
        $payload = $this->svc->cognitiveFunctionAxesObserve([]);

        $this->assertSame('atlas.cognitive_function.decomposition.v1', $payload['schema_version']);
        $this->assertContains('reasoning', $payload['functions']);
        $this->assertContains('audit', $payload['functions']);
        $this->assertSame(6, $payload['function_count']);
        $this->assertContains('code', $payload['rule_axes']);
        $this->assertSame(6, $payload['rule_axis_count']);
    }

    public function test_gate_signal_contract_observe_reports_weights(): void
    {
        $payload = $this->svc->gateSignalContractObserve([]);

        $this->assertSame('atlas.aaeos.gate_signal.v1', $payload['schema_version']);
        $this->assertContains('intent_clarity_score_min_0_8', $payload['gates']);
        $this->assertSame(0.8, $payload['intent_clarity_threshold']);
        $this->assertSame(0.4, $payload['weights']['resolved']);
        $this->assertArrayHasKey('high', $payload['teto10_band_rank']);
    }

    public function test_rollback_trigger_contract_observe_reports_rol01(): void
    {
        $payload = $this->svc->rollbackTriggerContractObserve([]);

        $this->assertSame('atlas.acos.rollback_triggers.v1', $payload['schema_version']);
        $this->assertSame('watchdog_alert_operator_reverts', $payload['default_executor']);
        $this->assertFalse($payload['auto_revert']);
        $this->assertSame('rollback_trigger_fired', $payload['alert_code']);
    }

    public function test_long_horizon_gate_contract_observe_reports_fixtures(): void
    {
        $payload = $this->svc->longHorizonGateContractObserve([]);

        $this->assertSame('atlas.cognition.acos_long_horizon_gate.v1', $payload['schema_version']);
        $this->assertContains('live', $payload['fixtures']);
        $this->assertContains('mature', $payload['fixtures']);
        $this->assertSame('acos_long_horizon_ready', $payload['ready_status']);
    }

    public function test_immune_signature_store_contract_observe_reports_statuses(): void
    {
        $payload = $this->svc->immuneSignatureStoreContractObserve([]);

        $this->assertSame('atlas.cognition.immune_signature_store.v1', $payload['schema_version']);
        $this->assertSame('atlas.immune.signature_store.v1', $payload['measure_id']);
        $this->assertContains('active', $payload['statuses']);
        $this->assertContains('immune_verdict', $payload['origins']);
    }

    public function test_promotion_protocol_states_observe_reports_catalogue(): void
    {
        $payload = $this->svc->promotionProtocolStatesObserve([]);

        $this->assertSame('atlas.acos.promotion_protocol.v1', $payload['schema_version']);
        $this->assertContains('shadow', $payload['states']);
        $this->assertContains('live', $payload['states']);
        $this->assertSame(5, $payload['state_count']);
    }

    public function test_autonomous_work_cycle_stages_observe_reports_ladder(): void
    {
        $payload = $this->svc->autonomousWorkCycleStagesObserve([]);

        $this->assertSame('atlas.autonomous_work_execution_os.cycle.v1', $payload['schema_version']);
        $this->assertContains('L0', $payload['autonomy_levels']);
        $this->assertContains('goal_recorded', $payload['cycle_stages']);
        $this->assertSame(6, $payload['stage_count']);
        $this->assertContains('pending', $payload['stage_statuses']);
        $this->assertSame(5, $payload['stage_status_count']);
    }

    public function test_immune_verdict_ledger_labels_observe_reports_labels(): void
    {
        $payload = $this->svc->immuneVerdictLedgerLabelsObserve([]);

        $this->assertSame('atlas.cognition.immune_verdict_ledger.v1', $payload['schema_version']);
        $this->assertContains('true_block', $payload['labels']);
        $this->assertContains('missed_poison', $payload['labels']);
        $this->assertSame(3, $payload['label_count']);
    }

    public function test_flywheel_funnel_stages_observe_reports_stages(): void
    {
        $payload = $this->svc->flywheelFunnelStagesObserve([]);

        $this->assertSame('atlas.m.funnel.v1', $payload['schema_version']);
        $this->assertSame('atlas.m.funnel.v1', $payload['measure_id']);
        $this->assertIsArray($payload['stages']);
        $this->assertGreaterThan(0, $payload['stage_count']);
    }

    public function test_mission_control_cockpit_schema_observe_reports_phases(): void
    {
        $payload = $this->svc->missionControlCockpitSchemaObserve([]);

        $this->assertSame('atlas.aaeos.mission_control_cockpit.v1', $payload['schema_version']);
        $this->assertSame(17, $payload['phase_count']);
        $this->assertContains('intent_capture', $payload['phases']);
    }

    public function test_evidence_vision_thesis_lifecycle_observe_reports_schema(): void
    {
        $payload = $this->svc->evidenceVisionThesisLifecycleObserve([]);

        $this->assertSame('atlas.originator.evidence_vision_thesis_lifecycle.v1', $payload['schema_version']);
        $this->assertSame('activeTheses', $payload['active_accessor']);
        $this->assertTrue($payload['supports_reset']);
        $this->assertSame(3, $payload['max_theses']);
        $this->assertContains('ledger', $payload['allowed_evidence_sources']);
        $this->assertSame('atlas.cognition.remint_touched.queue_item.v1', $payload['remint_touched_schema']);
    }

    public function test_exploratory_bets_portfolio_contract_observe_reports_defaults(): void
    {
        $payload = $this->svc->exploratoryBetsPortfolioContractObserve([]);

        $this->assertSame('atlas.originator.exploratory_bets_portfolio.v1', $payload['schema_version']);
        $this->assertSame(3, $payload['default_k']);
        $this->assertSame(7, $payload['default_window_days']);
        $this->assertSame(5, $payload['min_n']);
        $this->assertSame(2.0, $payload['double_down_multiplier']);
    }

    public function test_composed_obra_arc_contract_observe_reports_kill_gate(): void
    {
        $payload = $this->svc->composedObraArcContractObserve([]);

        $this->assertSame('atlas.originator.composed_obra_arc.v1', $payload['composer_schema']);
        $this->assertSame('atlas.originator.composed_obra_arc_lifecycle.v1', $payload['lifecycle_schema']);
        $this->assertSame(3, $payload['kill_gate_consecutive_failures']);
        $this->assertTrue($payload['supports_lifecycle_reset']);
    }

    public function test_memory_feedback_decay_contract_observe_reports_thresholds(): void
    {
        $payload = $this->svc->memoryFeedbackDecayContractObserve([]);

        $this->assertSame('atlas.aaeos.memory_feedback_decay.v1', $payload['schema_version']);
        $this->assertSame(180, $payload['hard_stale_age_days']);
        $this->assertSame(45, $payload['soft_stale_age_days']);
        $this->assertSame(60, $payload['degrade_health_ceiling']);
    }

    public function test_spec_completeness_contract_observe_reports_thresholds(): void
    {
        $payload = $this->svc->specCompletenessContractObserve([]);

        $this->assertSame('atlas.aaeos.spec_completeness_score.v1', $payload['schema_version']);
        $this->assertSame(8, $payload['text_min_length']);
        $this->assertSame(12, $payload['total_fields']);
        $this->assertSame(80, $payload['complete_threshold']);
        $this->assertSame(50, $payload['partial_threshold']);
        $this->assertContains('requirements', $payload['list_fields']);
        $this->assertSame(5, $payload['list_field_count']);
    }

    public function test_context_retention_schemas_observe_reports_schemas(): void
    {
        $payload = $this->svc->contextRetentionSchemasObserve([]);

        $this->assertSame('atlas.aaeos.summary_fidelity_coverage.v1', $payload['summary_fidelity_schema']);
        $this->assertSame('atlas.aaeos.segment_importance_ranking.v1', $payload['segment_importance_schema']);
        $this->assertSame(0.6, $payload['summary_retention_fail_floor']);
        $this->assertSame(4, $payload['summary_score_precision']);
        $this->assertSame('decision', $payload['summary_decision_kind']);
    }

    public function test_context_budget_schemas_observe_reports_schemas(): void
    {
        $payload = $this->svc->contextBudgetSchemasObserve([]);

        $this->assertSame('atlas.aaeos.memory_injection_budget_allocation.v1', $payload['memory_injection_schema']);
        $this->assertSame('atlas.aaeos.context_pareto_dominance.v1', $payload['context_pareto_schema']);
        $this->assertSame('atlas.aaeos.delivery_pack_completeness.v1', $payload['delivery_pack_schema']);
        $this->assertSame(80, $payload['memory_injection_default_floor_chars']);
        $this->assertContains('budget_exhausted', $payload['memory_injection_drop_reasons']);
        $this->assertContains('maximize', $payload['pareto_directions']);
        $this->assertContains('minimize', $payload['pareto_directions']);
    }

    public function test_outcome_envelope_contract_observe_reports_statuses(): void
    {
        $payload = $this->svc->outcomeEnvelopeContractObserve([]);

        $this->assertSame('atlas.engineering_outcome.v2', $payload['schema_version']);
        $this->assertSame('esp06.outcome_envelope.v1', $payload['formula_version']);
        $this->assertContains('dev_procedural', $payload['adapter_origins']);
        $this->assertContains('succeeded', $payload['statuses']);
        $this->assertSame(3, $payload['origin_count']);
        $this->assertSame(3, $payload['status_count']);
    }

    public function test_pre_review_advisory_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->preReviewAdvisoryContractObserve([]);

        $this->assertSame('atlas.operator.pre_review_advisory_band.v1', $payload['schema_version']);
        $this->assertSame('atlas.multn15_08.pre_review_band.v1', $payload['formula_version']);
        $this->assertSame(10, $payload['min_n_for_band']);
        $this->assertSame(30, $payload['death_min_n']);
        $this->assertSame(0.15, $payload['death_min_lift']);
        $this->assertFalse($payload['blocks_auto_apply']);
        $this->assertFalse($payload['delays_auto_apply']);
    }

    public function test_ambition_rung_policy_contract_observe_reports_ladder(): void
    {
        $payload = $this->svc->ambitionRungPolicyContractObserve([]);

        $this->assertSame('atlas.originator.ambition_rung_policy.v1', $payload['schema_version']);
        $this->assertContains('task', $payload['rungs']);
        $this->assertContains('salto', $payload['rungs']);
        $this->assertSame(4, $payload['rung_count']);
        $this->assertFalse($payload['scope_has_ceiling']);
        $this->assertFalse($payload['provider_calls_made']);
    }

    public function test_reactive_saturation_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->reactiveSaturationContractObserve([]);

        $this->assertSame('atlas.originator.reactive_saturation.v1', $payload['schema_version']);
        $this->assertSame(8, $payload['min_n_per_window']);
        $this->assertSame(3, $payload['min_windows']);
        $this->assertTrue($payload['report_only']);
        $this->assertFalse($payload['disables_reactive_lane']);
        $this->assertFalse($payload['provider_calls_made']);
    }

    public function test_portfolio_budget_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->portfolioBudgetContractObserve([]);

        $this->assertSame('atlas.decide.portfolio_allocation.v1', $payload['schema_version']);
        $this->assertSame('atlas.multk_06.portfolio_allocation.v1', $payload['formula_version']);
        $this->assertContains('reactive', $payload['classes']);
        $this->assertContains('maintenance', $payload['classes']);
        $this->assertSame(3, $payload['class_count']);
        $this->assertSame(0.05, $payload['hard_floor_share']);
        $this->assertSame(0.80, $payload['hard_ceiling_share']);
        $this->assertSame(8, $payload['min_n_per_class']);
        $this->assertFalse($payload['allocator_writes_own_weights']);
    }

    public function test_predicted_impact_band_contract_observe_reports_bands(): void
    {
        $payload = $this->svc->predictedImpactBandContractObserve([]);

        $this->assertSame('atlas.originator.predicted_impact_band.v1', $payload['schema_version']);
        $this->assertContains('low', $payload['bands']);
        $this->assertContains('high', $payload['bands']);
        $this->assertSame(3, $payload['band_count']);
        $this->assertSame(0, $payload['rung_weights']['task']);
        $this->assertSame(3, $payload['rung_weights']['salto']);
        $this->assertFalse($payload['influences_pick']);
        $this->assertFalse($payload['single_scalar_score_emitted']);
    }

    public function test_gated_corpus_contract_observe_reports_protected_classes(): void
    {
        $payload = $this->svc->gatedCorpusContractObserve([]);

        $this->assertSame('atlas.corpus.gated_candidate_miner.v1', $payload['schema_version']);
        $this->assertContains('sensitive', $payload['protected_classes']);
        $this->assertContains('cyber', $payload['protected_classes']);
        $this->assertSame(3, $payload['protected_class_count']);
        $this->assertTrue($payload['candidate_only']);
        $this->assertFalse($payload['writes_memory_directly']);
        $this->assertFalse($payload['count_is_acceptance']);
    }

    public function test_claim_definition_of_done_contract_observe_reports_fields(): void
    {
        $payload = $this->svc->claimDefinitionOfDoneContractObserve([]);

        $this->assertSame('atlas.aaeos.claim_definition_of_done.v1', $payload['schema_version']);
        $this->assertContains('owner_doc', $payload['canonical_fields']);
        $this->assertContains('proof', $payload['unconditional_fields']);
        $this->assertSame(6, $payload['canonical_field_count']);
        $this->assertSame(4, $payload['unconditional_field_count']);
        $this->assertStringContainsString('implementation-reality.md', $payload['evaluated_against']);
        $this->assertContains('present', $payload['field_statuses']);
        $this->assertContains('evidence', $payload['verdicts']);
        $this->assertContains('narrative', $payload['verdicts']);
    }

    public function test_quality_bar_telemetry_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->qualityBarTelemetryContractObserve([]);

        $this->assertSame('atlas.aaeos.quality_bar_telemetry.v1', $payload['schema_version']);
        $this->assertSame('atlas.aaeos.quality_bar.v1', $payload['quality_bar_schema']);
        $this->assertSame('quality_bar_auto_block', $payload['immune_gate_id']);
        $this->assertSame('dept_quality_bar_breach_count', $payload['breach_signal']);
        $this->assertSame(30, $payload['evaluated_window_days']);
        $this->assertTrue($payload['auto_block_on_breach']);
        $this->assertContains('breach_metrics', $payload['evidence_required']);
        $this->assertContains('department_id', $payload['telemetry_fields']);
    }

    public function test_doc_maturity_contract_observe_reports_levels(): void
    {
        $payload = $this->svc->docMaturityContractObserve([]);

        $this->assertSame('atlas.aaeos.doc_maturity.v1', $payload['schema_version']);
        $this->assertContains('DOC L0', $payload['levels']);
        $this->assertContains('DOC L4', $payload['levels']);
        $this->assertSame(5, $payload['level_count']);
        $this->assertContains('mother_doc', $payload['boolean_requirements']);
        $this->assertContains('gates', $payload['l4_signals']);
        $this->assertFalse($payload['runtime_ready_always']);
    }

    public function test_attempt_lifecycle_contract_observe_reports_terminal_states(): void
    {
        $payload = $this->svc->attemptLifecycleContractObserve([]);

        $this->assertSame('atlas.execution.attempt_lifecycle.v1', $payload['schema_version']);
        $this->assertContains('completed', $payload['terminal_states']);
        $this->assertContains('abandoned', $payload['terminal_states']);
        $this->assertSame(4, $payload['terminal_state_count']);
        $this->assertFalse($payload['outcome_without_attempt_allowed']);
        $this->assertTrue($payload['attempt_id_deduped']);
    }

    public function test_esp09_challenger_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->esp09ChallengerContractObserve([]);

        $this->assertSame('atlas.esp_09.challenger_advisory.v1', $payload['schema_version']);
        $this->assertSame('advisory', $payload['mode']);
        $this->assertSame(0.80, $payload['high_alignment_band']);
        $this->assertContains('composed_obra', $payload['trigger_kinds']);
        $this->assertSame(2, $payload['trigger_kind_count']);
        $this->assertTrue($payload['advisory_only']);
        $this->assertFalse($payload['gates_override']);
    }

    public function test_memory_weight_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->memoryWeightFloorsContractObserve([]);

        $this->assertSame('atlas.memory.provenance_weight.v1', $payload['provenance_weight_schema']);
        $this->assertSame(0.5, $payload['provenance_weight_floor']);
        $this->assertSame('atlas.memory.recall_gap_aggregator.v1', $payload['recall_gap_schema']);
        $this->assertSame(0.35, $payload['recall_gap_weak_score_floor']);
        $this->assertSame('atlas.context.citation_grounding.v1', $payload['citation_grounding_schema']);
        $this->assertSame(3, $payload['dogfooding_min_occurrences']);
        $this->assertFalse($payload['provider_calls_made']);
    }

    public function test_delivery_pack_contract_observe_reports_keys(): void
    {
        $payload = $this->svc->deliveryPackContractObserve([]);

        $this->assertSame('atlas.aaeos.delivery_pack_completeness.v1', $payload['schema_version']);
        $this->assertContains('delivery_hash', $payload['required_keys']);
        $this->assertSame(7, $payload['required_key_count']);
        $this->assertContains('passed', $payload['statuses']);
        $this->assertSame('missing_signed_delivery_hash', $payload['blocker_missing_hash']);
    }

    public function test_domain_lexical_fact_schema_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->domainLexicalFactSchemaContractObserve([]);

        $this->assertSame('atlas.memory.domain_lexical_normalizer.v1', $payload['domain_lexical_schema']);
        $this->assertSame(32, $payload['max_expanded_tokens']);
        $this->assertSame(15, $payload['equivalence_entry_count']);
        $this->assertSame('atlas.memory.structured_facts.v1', $payload['structured_fact_schema']);
        $this->assertContains('decision', $payload['structured_fact_memory_types']);
        $this->assertSame(3, $payload['structured_fact_type_count']);
        $this->assertTrue($payload['deterministic']);
    }

    public function test_phase_advance_blocker_contract_observe_reports_verdicts(): void
    {
        $payload = $this->svc->phaseAdvanceBlockerContractObserve([]);

        $this->assertSame('atlas.aaeos.phase_advance_verdict.v1', $payload['phase_advance_schema']);
        $this->assertContains('advance', $payload['verdicts']);
        $this->assertContains('halt', $payload['verdicts']);
        $this->assertSame(4, $payload['verdict_count']);
        $this->assertSame(7, $payload['rule_count']);
        $this->assertContains('blocked', $payload['blocker_signals']);
        $this->assertContains('critical', $payload['blocker_levels']);
    }

    public function test_outcome_causality_comparator_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->outcomeCausalityComparatorContractObserve([]);

        $this->assertSame('atlas.aaeos.outcome_causality_ranking.v1', $payload['outcome_causality_schema']);
        $this->assertContains('missing_evidence', $payload['primary_causes']);
        $this->assertSame(7, $payload['primary_cause_count']);
        $this->assertContains('success', $payload['outcomes']);
        $this->assertSame(1e-9, $payload['threshold_epsilon']);
        $this->assertSame('atlas.aaeos.memory_recall_ranking.v1', $payload['memory_recall_schema']);
    }

    public function test_segment_importance_contract_observe_reports_weights(): void
    {
        $payload = $this->svc->segmentImportanceContractObserve([]);

        $this->assertSame('atlas.aaeos.segment_importance_ranking.v1', $payload['schema_version']);
        $this->assertSame(1.0, $payload['kind_weights']['decision']);
        $this->assertSame(10, $payload['kind_weight_count']);
        $this->assertSame(0.3, $payload['kind_weight_unknown']);
        $this->assertSame(0.20, $payload['evidence_ref_bonus']);
        $this->assertSame(0.30, $payload['decision_or_blocker_link_bonus']);
        $this->assertContains('budget_exceeded', $payload['drop_reasons']);
        $this->assertContains('oversized_segment', $payload['drop_reasons']);
    }

    public function test_cognitive_immune_promotion_gate_contract_observe_reports_gates(): void
    {
        $payload = $this->svc->cognitiveImmunePromotionGateContractObserve([]);

        $this->assertSame('atlas.cognition.cognitive_immune_promotion_gate.v1', $payload['schema_version']);
        $this->assertContains('G0', $payload['gate_ids']);
        $this->assertContains('G8', $payload['gate_ids']);
        $this->assertSame(9, $payload['gate_count']);
        $this->assertContains('pass', $payload['statuses']);
        $this->assertContains('workspace', $payload['known_scopes']);
        $this->assertContains('auto', $payload['allowed_promotion_modes']);
        $this->assertContains('blocked', $payload['blocked_promotion_modes']);
        $this->assertSame(2, $payload['probation_min_recall_actors']);
    }

    public function test_cognitive_immune_input_classifier_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->cognitiveImmuneInputClassifierContractObserve([]);

        $this->assertSame('atlas.aaeos.cognitive_immune_input_classifier.v1', $payload['schema_version']);
        $this->assertSame(3, $payload['recurrence_memory_threshold']);
        $this->assertContains('prompt_injection', $payload['destination_classes']);
        $this->assertSame(11, $payload['destination_class_count']);
        $this->assertContains('private_sensitive', $payload['embedding_forbidden_classes']);
        $this->assertSame('atlas.aaeos.veto_propagation.v1', $payload['veto_propagation_schema']);
        $this->assertSame(3, $payload['repair_loop_auto_escalation_threshold']);
        $this->assertSame(30, $payload['promotion_max_evidence_age_days']);
        $this->assertSame(5, $payload['promotion_max_tier']);
        $this->assertSame(0.0005, $payload['consolidation_rerank_epsilon']);
    }

    public function test_window_evolution_hybrid_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->windowEvolutionHybridContractObserve([]);

        $this->assertSame('atlas.cognition.window_gates.v1', $payload['window_gates_schema']);
        $this->assertSame(604800, $payload['receipt_fresh_seconds']);
        $this->assertSame('atlas.cognition.evolution_score.v1', $payload['evolution_score_schema']);
        $this->assertSame(7200, $payload['heartbeat_fresh_seconds']);
        $this->assertSame(172800, $payload['gate_fresh_seconds']);
        $this->assertContains('atlas:acos:delta-series', $payload['scheduled_organs']);
        $this->assertSame(4, $payload['scheduled_organ_count']);
        $this->assertSame(10, $payload['lift_cases_per_arm_required']);
        $this->assertSame('prompt_injection', $payload['hostile_severity'][0]);
        $this->assertSame(3, $payload['hostile_severity_count']);
    }

    public function test_implementation_authority_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->implementationAuthorityContractObserve([]);

        $this->assertSame('atlas.aaeos.implementation_state.v1', $payload['implementation_truth_schema']);
        $this->assertSame(2, $payload['implementation_truth_ranks']['verified']);
        $this->assertSame(3, $payload['implementation_truth_rank_count']);
        $this->assertSame('atlas.docs.authority_graph.v1', $payload['docs_authority_schema']);
        $this->assertSame(100, $payload['docs_authority_confidence']['governs_frontmatter']);
        $this->assertSame(4, $payload['docs_authority_basis_count']);
        $this->assertContains('dev', $payload['verified_share_executors']);
        $this->assertSame(3, $payload['verified_share_executor_count']);
        $this->assertSame('atlas.aaeos.quality_bar.v1', $payload['quality_bar_schema']);
        $this->assertSame('atlas.aaeos.department_level_classification.v1', $payload['department_level_schema']);
        $this->assertSame('atlas.aaeos.department_maturity_band.v1', $payload['department_maturity_band_schema']);
    }

    public function test_evidence_volume_deferred_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->evidenceVolumeDeferredContractObserve([]);

        $this->assertContains('class', $payload['evidence_symbol_types']);
        $this->assertSame(5, $payload['evidence_symbol_type_count']);
        $this->assertContains('route', $payload['evidence_signature_match_types']);
        $this->assertSame('atlas.aaeos.test_run_receipt.v1', $payload['test_execution_schema']);
        $this->assertSame(1600, $payload['test_output_tail_chars']);
        $this->assertSame('atlas.acos.operational_volume.v1', $payload['operational_volume_schema']);
        $this->assertContains('atlas_dev', $payload['dev_flow_ids']);
        $this->assertContains('atlas_forge', $payload['forge_flow_ids']);
        $this->assertSame(3, $payload['dev_runs_per_business_day_min']);
        $this->assertSame(5, $payload['forge_cycles_per_week_min']);
        $this->assertContains('topology', $payload['deferred_phase_keys']);
        $this->assertSame(5, $payload['deferred_phase_count']);
        $this->assertSame('atlas.cognition.scorecard.v3', $payload['scorecard_schema']);
        $this->assertSame(10, $payload['scorecard_status_points']['ready']);
        $this->assertSame('atlas.aaeos.department_maturity.v1', $payload['department_maturity_schema']);
        $this->assertSame('atlas-ai', $payload['department_maturity_owner']);
    }

    public function test_watchdog_health_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->watchdogHealthFloorsContractObserve([]);

        $this->assertSame('atlas.memory.quality_check.v1', $payload['memory_quality_schema']);
        $this->assertSame(5, $payload['memory_score_regression_tolerance']);
        $this->assertSame(48, $payload['memory_snapshot_max_age_hours']);
        $this->assertSame(0.35, $payload['memory_concentration_floor']);
        $this->assertSame(0.5, $payload['rag_coverage_floor']);
        $this->assertSame(0.85, $payload['rag_recall_at_5_floor']);
        $this->assertSame(168, $payload['feedback_window_hours']);
        $this->assertSame(10, $payload['feedback_total_event_floor']);
        $this->assertSame(50, $payload['compaction_min_receipts']);
        $this->assertSame(0.95, $payload['compaction_min_retention_score']);
        $this->assertSame(7, $payload['eng_window_days']);
        $this->assertSame(20, $payload['eng_min_forge_promoted_cycles']);
        $this->assertContains('ok', $payload['watchdog_statuses']);
        $this->assertSame(5, $payload['watchdog_status_count']);
        $this->assertSame('atlas.aaeos.debug.root_cause.v1', $payload['debug_root_cause_version']);
        $this->assertSame('atlas.aaeos.quality_bar_level.v1', $payload['quality_bar_level_schema']);
        $this->assertSame('atlas.acos_max.obra_retro.v1', $payload['obra_retro_schema']);
        $this->assertStringContainsString('scoreboard', $payload['obra_retro_scoreboard_path']);
    }

    public function test_evidence_vision_composer_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->evidenceVisionComposerContractObserve([]);

        $this->assertSame('atlas.originator.evidence_vision_thesis.v1', $payload['composer_schema']);
        $this->assertSame('atlas.originator.evidence_vision_thesis_lifecycle.v1', $payload['lifecycle_schema']);
        $this->assertSame(3, $payload['max_theses']);
        $this->assertSame(4, $payload['min_regression_windows']);
        $this->assertSame(30, $payload['default_ttl_days']);
        $this->assertContains('series', $payload['allowed_evidence_sources']);
        $this->assertSame(3, $payload['allowed_evidence_source_count']);
        $this->assertSame('atlas.cognition.remint_touched.queue_item.v1', $payload['remint_touched_schema']);
    }

    public function test_measure_series_maxa04_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->measureSeriesMaxa04ContractObserve([]);

        $this->assertSame('atlas.acos.measure_series_freshness_reader.v1', $payload['freshness_schema']);
        $this->assertSame('atlas.capture.hmac_lineage.v1', $payload['capture_hmac_schema']);
        $this->assertSame(['source', 'capture', 'memory'], $payload['capture_hmac_stages']);
        $this->assertSame('jinaai/jina-embeddings-v3', $payload['maxa04_candidate_model']);
        $this->assertSame(1024, $payload['maxa04_candidate_dimensions']);
        $this->assertSame('jina_v3_dual_read_benchmark_window', $payload['maxa04_pending_window']);
        $this->assertSame('atlas.semantic.jina_v3_dual_read.v1', $payload['maxa04_ledger_schema']);
        $this->assertStringContainsString('maxa04-jina-v3-dual-read', $payload['maxa04_ledger_relative_path']);
        $this->assertSame('atlas.acos.teto10.predicted_revert_review_digest.v1', $payload['teto10_schema']);
        $this->assertIsArray($payload['teto10_band_rank']);
        $this->assertNotEmpty($payload['teto10_band_rank']);
    }

    public function test_ragx_choreography_budget_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->ragxChoreographyBudgetContractObserve([]);

        $this->assertSame('atlas.acos_max.ragx_chain_mechanisms.v1', $payload['ragx_schema']);
        $this->assertSame('atlas.acos_max.ragx_ab_registration.v1', $payload['ragx_ab_schema']);
        $this->assertSame('atlas.acos_max.ragx10_raptor_lite.v1', $payload['ragx_raptor_schema']);
        $this->assertSame('atlas.acos_max.maxd05_louvain_chunks.v1', $payload['ragx_louvain_schema']);
        $this->assertSame('atlas.aaeos.cross_dept.handoff.v1', $payload['choreography_handoff_schema']);
        $this->assertSame(10, $payload['choreography_veto_sla_seconds']);
        $this->assertSame(3, $payload['choreography_repair_max_iterations']);
        $this->assertContains('veto', $payload['choreography_handoff_kinds']);
        $this->assertArrayHasKey('security', $payload['choreography_veto_rules']);
        $this->assertSame(4, $payload['choreography_veto_rule_count']);
        $this->assertSame('atlas.resource_budget.v1', $payload['resource_budget_schema']);
        $this->assertSame('atlas.originator.exploratory_bets_portfolio.v1', $payload['exploratory_bets_schema']);
        $this->assertSame(3, $payload['exploratory_bets_default_k']);
        $this->assertSame(5, $payload['exploratory_bets_min_n']);
        $this->assertSame('atlas.acos.promotion_protocol.v1', $payload['promotion_protocol_schema']);
        $this->assertContains('shadow', $payload['promotion_protocol_states']);
        $this->assertSame('atlas.cognition.cognitive_immune_check.v1', $payload['cognitive_immune_check_schema']);
    }

    public function test_verified_share_scorecard_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->verifiedShareScorecardContractObserve([]);

        $this->assertSame('atlas.acos_max.verified_share.v1', $payload['verified_share_schema']);
        $this->assertSame('acos.verified_share.v1', $payload['verified_share_measure_id']);
        $this->assertSame('verified_share.v1', $payload['verified_share_formula']);
        $this->assertSame(['dev', 'forge', 'autonomos'], $payload['verified_share_executors']);
        $this->assertSame(3, $payload['verified_share_executor_count']);
        $this->assertIsArray($payload['scorecard_status_points']);
        $this->assertGreaterThan(0, $payload['scorecard_subsystem_count']);
        $this->assertGreaterThan(0, $payload['scorecard_v4_supplemental_count']);
        $this->assertSame('atlas.context.golden_counterfactual.v1', $payload['golden_counterfactual_schema']);
        $this->assertSame('atlas.context.golden_counterfactual.v1', $payload['golden_counterfactual_measure_id']);
        $this->assertSame('atlas_context_golden_counterfactual_v1', $payload['golden_counterfactual_formula']);
        $this->assertSame('atlas.asef_chunks.index.v1', $payload['asef_chunk_index_schema']);
        $this->assertSame('atlas.engineering_outcome.v2', $payload['outcome_envelope_schema']);
        $this->assertSame('cursor-acos-max-multn1702', $payload['composed_obra_author_engine']);
        $this->assertSame('codex-independent-multn1702-judge', $payload['composed_obra_judge_engine']);
    }

    public function test_aaeos_evidence_maturity_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->aaeosEvidenceMaturityContractObserve([]);

        $this->assertSame('atlas.aaeos.test_run_receipt.v1', $payload['test_execution_schema']);
        $this->assertSame(1600, $payload['test_execution_output_tail_chars']);
        $this->assertContains('class', $payload['evidence_symbol_types']);
        $this->assertSame('atlas.aaeos.evidence_resolver.symbol_index', $payload['evidence_shared_index_key']);
        $this->assertContains('route', $payload['evidence_signature_match_types']);
        $this->assertSame('atlas.aaeos.department_maturity.v1', $payload['department_maturity_schema']);
        $this->assertSame('atlas-ai', $payload['department_maturity_owner']);
        $this->assertGreaterThan(0, $payload['department_maturity_department_count']);
        $this->assertSame('atlas.aaeos.deferred_phase_dispatch.v1', $payload['deferred_phase_schema']);
        $this->assertGreaterThan(0, $payload['immune_injection_marker_count']);
        $this->assertGreaterThan(0, $payload['immune_strategic_marker_count']);
        $this->assertGreaterThan(0, $payload['immune_technical_marker_count']);
    }

    public function test_lote2_quality_bar_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->lote2QualityBarContractObserve([]);

        $this->assertSame('atlas.evidence.delta_attribution.v1', $payload['maxl06_measure_id']);
        $this->assertSame('atlas.originator.predicted_impact_calibration.v1', $payload['multn1704_measure_id']);
        $this->assertSame('acos.flywheel.loops.v1', $payload['multx01_measure_id']);
        $this->assertSame('atlas.ai.procedural_skill_promoter.v1', $payload['multj04_measure_id']);
        $this->assertSame('mission_e2e.v1', $payload['teto02_measure_id']);
        $this->assertSame('atlas.aaeos.quality_bar.v1', $payload['quality_bar_schema']);
        $this->assertGreaterThan(0, $payload['quality_bar_department_count']);
        $this->assertSame('atlas.acos_max.obra_retro.v1', $payload['obra_retro_schema']);
        $this->assertStringContainsString('scoreboard', $payload['obra_retro_scoreboard_path']);
    }

    public function test_embedding_coverage_truth_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->embeddingCoverageTruthContractObserve([]);

        $this->assertSame('atlas.acos_max.kb_embedding_coverage.v1', $payload['kb_embedding_schema']);
        $this->assertSame('atlas.kb_embedding_coverage.v1', $payload['kb_embedding_measure_id']);
        $this->assertSame('atlas.acos_max.code_symbol_embedding_coverage.v1', $payload['code_symbol_embedding_schema']);
        $this->assertSame('atlas.code_symbol_embedding_coverage.v1', $payload['code_symbol_embedding_measure_id']);
        $this->assertSame('atlas.acos_max.n_capture_drill.v1', $payload['n_capture_schema']);
        $this->assertSame('atlas.n_capture_drill.v1', $payload['n_capture_measure_id']);
        $this->assertStringContainsString('n-capture', $payload['n_capture_ledger_path']);
        $this->assertSame('atlas.aaeos.implementation_state.v1', $payload['implementation_truth_schema']);
        $this->assertSame('atlas.aaeos.capability_truth_ledger.v1', $payload['implementation_truth_ledger_schema']);
        $this->assertArrayHasKey('verified', $payload['implementation_truth_rank']);
        $this->assertSame('atlas.context.execution_cooccurrence.v1', $payload['execution_cooccurrence_schema']);
        $this->assertSame('atlas.context.execution_cooccurrence.v1', $payload['execution_cooccurrence_measure_id']);
    }

    public function test_phase_gates_flywheel_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->phaseGatesFlywheelContractObserve([]);

        $this->assertSame('atlas.aaeos.phase.v1', $payload['phase_handoff_schema']);
        $this->assertSame(17, $payload['phase_count']);
        $this->assertArrayHasKey('intent_capture', $payload['phase_gates_map']);
        $this->assertSame(17, $payload['phase_gates_map_count']);
        $this->assertContains('receipt', $payload['phases_requiring_signature_at_l4']);
        $this->assertSame('atlas.m.funnel.v1', $payload['flywheel_schema']);
        $this->assertGreaterThan(0, $payload['flywheel_stage_count']);
        $this->assertSame('acos_max.parallel_execution.v1', $payload['parallel_execution_schema']);
        $this->assertSame('task', $payload['parallel_execution_claim_kind']);
        $this->assertSame(3600, $payload['parallel_execution_default_ttl_seconds']);
        $this->assertSame(0.5, $payload['surprise_gate_default_threshold']);
        $this->assertSame(0.75, $payload['surprise_gate_default_high_band']);
        $this->assertSame(8, $payload['surprise_gate_min_prediction_tokens']);
        $this->assertSame('atlas.aaeos.quality_bar_level.v1', $payload['quality_bar_level_schema']);
        $this->assertSame('atlas.aaeos.department_maturity_band.v1', $payload['maturity_band_schema']);
    }

    public function test_frontier_watchdog_cockpit_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->frontierWatchdogCockpitContractObserve([]);

        $this->assertSame('atlas.cognition.frontier_ladder.v1', $payload['frontier_ladder_schema']);
        $this->assertSame(5, $payload['frontier_event_threshold']);
        $this->assertContains('pack_diff_merged', $payload['frontier_event_kinds']);
        $this->assertSame(3, $payload['frontier_event_kind_count']);
        $this->assertSame(5, $payload['frontier_wave_count']);
        $this->assertSame('atlas.acos.watchdog_run.v1', $payload['watchdog_runner_schema']);
        $this->assertSame('atlas.acos.watchdog.daily_canary_replay_by_refs.v1', $payload['daily_canary_schema']);
        $this->assertSame('atlas.acos.watchdog.autonomy_ladder_adversarial.v1', $payload['autonomy_ladder_adversarial_schema']);
        $this->assertSame('atlas.acos.watchdog.evidence_ledger_integrity.v1', $payload['evidence_ledger_integrity_schema']);
        $this->assertSame('atlas.acos.cockpit.v1', $payload['cockpit_schema']);
        $this->assertSame('atlas.acos.windows.v1', $payload['window_orchestrator_schema']);
        $this->assertSame('atlas.immune.classifier_hybrid.v1', $payload['immune_hybrid_freeze_measure_id']);
        $this->assertSame(60, $payload['immune_hybrid_freeze_ttl_days']);
        $this->assertStringContainsString('anchors.v1.json', $payload['immune_hybrid_freeze_anchor_fixture']);
        $this->assertSame('atlas.esp_06.outcome_envelope.v1', $payload['outcome_envelope_bridge_measure_id']);
    }

    public function test_runbook_department_atlas_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->runbookDepartmentAtlasContractObserve([]);

        $this->assertSame('atlas.agentic_engineering_os.runbook.v1', $payload['runbook_schema']);
        $this->assertSame('atlas.architecture.redesign_proposal.v1', $payload['architecture_redesign_proposal_schema']);
        $this->assertSame(100, $payload['runbook_replay_obras_count_min']);
        $this->assertGreaterThan(0, $payload['runbook_default_flow_count']);
        $this->assertSame('atlas.aaeos.department.v1', $payload['department_runtime_schema']);
        $this->assertGreaterThan(0, $payload['department_catalogue_count']);
        $this->assertGreaterThan(0, $payload['department_canonical_field_count']);
        $this->assertSame('atlas.cognitive_function_atlas.self_model.v1', $payload['cognitive_function_atlas_self_model_schema']);
        $this->assertSame('atlas.cognitive_function_atlas.group_summary.v1', $payload['cognitive_function_atlas_group_summary_schema']);
        $this->assertSame(8, $payload['cognitive_function_atlas_overload_threshold']);
        $this->assertSame('atlas.acmf.schema_proposal.v1', $payload['memory_fabric_proposal_schema']);
        $this->assertSame('atlas.acmf.schema_evolution_ticket.v1', $payload['memory_fabric_ticket_schema']);
        $this->assertContains('operator_request', $payload['memory_fabric_valid_triggers']);
        $this->assertSame(4, $payload['memory_fabric_extension_pressure_threshold']);
    }

    public function test_outcome_causality_weights_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->outcomeCausalityWeightsContractObserve([]);

        $this->assertSame('atlas.aaeos.outcome_causality_ranking.v1', $payload['schema_version']);
        $this->assertSame(7, $payload['primary_cause_count']);
        $this->assertSame(4, $payload['outcome_count']);
        $this->assertSame('succeeded', $payload['status_succeeded']);
        $this->assertSame(0.95, $payload['weights']['missing_evidence']);
        $this->assertSame(0.85, $payload['weights']['tests_failed']);
        $this->assertSame(0.72, $payload['weights']['packet_quality_failure']);
        $this->assertSame(7, $payload['weight_count']);
    }

    public function test_watchdog_canary_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->watchdogCanaryFloorsContractObserve([]);

        $this->assertSame(168, $payload['learning_negative_max_age_hours']);
        $this->assertSame(48, $payload['learning_aemor_source_max_age_hours']);
        $this->assertSame(72, $payload['learning_ai_run_outcome_max_age_hours']);
        $this->assertSame(95, $payload['rag_retrieval_eval_floor']);
        $this->assertSame(0.5, $payload['rag_pre_filter_concentration_mask_floor']);
        $this->assertSame(3, $payload['feedback_measured_count_floor']);
        $this->assertSame(0.10, $payload['feedback_synthetic_share_max']);
        $this->assertSame(14, $payload['compaction_window_days']);
        $this->assertSame(7, $payload['lift_stalled_days']);
        $this->assertSame(7, $payload['pipeline_partial_stale_days']);
        $this->assertSame(1, $payload['eng_min_real_executions_per_executor']);
        $this->assertSame(3, $payload['eng_min_adml_proven_routes']);
        $this->assertSame('atlas.acos.watchdog.daily_canary_replay_by_refs.v1', $payload['daily_canary_schema']);
        $this->assertSame(24, $payload['daily_canary_default_window_hours']);
        $this->assertSame(25, $payload['daily_canary_default_top_n_flows']);
        $this->assertSame(0.95, $payload['daily_canary_ref_stability_alert_floor']);
        $this->assertSame(0.40, $payload['daily_canary_golden_recall_at_5_alert_floor']);
        $this->assertSame(0, $payload['daily_canary_improper_floor_discard_alert_ceiling']);
        $this->assertStringContainsString('query|prompt|context', $payload['daily_canary_forbidden_evidence_key_pattern']);
        $this->assertSame('ok', $payload['watchdog_status_ok']);
        $this->assertSame('warning', $payload['watchdog_status_warning']);
        $this->assertSame('alert', $payload['watchdog_status_alert']);
        $this->assertSame('skipped', $payload['watchdog_status_skipped']);
        $this->assertSame('error', $payload['watchdog_status_error']);
        $this->assertSame(5, $payload['watchdog_status_count']);
    }

    public function test_http_path_facade_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->httpPathFacadeContractObserve([]);

        $this->assertSame('ok', $payload['result_ok']);
        $this->assertSame('blocked', $payload['result_blocked']);
        $this->assertSame('placement_gate_blocked', $payload['block_placement_gate_blocked']);
        $this->assertSame('policy_gate_blocked', $payload['block_policy_gate_blocked']);
        $this->assertSame('atlas.aaeos.http_path.requests', $payload['telemetry_key_requests']);
        $this->assertSame('atlas.aaeos.http_path.canonical_calls', $payload['telemetry_key_canonical']);
        $this->assertSame('atlas.aaeos.http_path.legacy_fallback', $payload['telemetry_key_legacy_fallback']);
        $this->assertSame('atlas.aaeos.http_path.blocked', $payload['telemetry_key_blocked']);
        $this->assertSame('atlas.aaeos.http_path.latency_ms', $payload['telemetry_key_latency']);
        $this->assertSame('r1_r2_fast_path', $payload['risk_band_fast_path']);
        $this->assertSame('r3_plus', $payload['risk_band_r3_plus']);
        $this->assertSame('atlas.aaeos.phase_router.v1', $payload['phase_router_schema']);
        $this->assertSame('legacy', $payload['phase_legacy']);
        $this->assertSame('1', $payload['phase_1']);
        $this->assertSame('4', $payload['phase_4']);
        $this->assertSame(5, $payload['valid_phase_count']);
        $this->assertSame('atlas.aaeos.department.v1', $payload['department_runtime_schema']);
        $this->assertContains('architecture', $payload['departments']);
        $this->assertContains('memory', $payload['departments']);
        $this->assertSame(12, $payload['department_count']);
        $this->assertSame('architecture', $payload['architect_department_id']);
        $this->assertSame('atlas.spec_pack.v1', $payload['architect_spec_pack_schema']);
        $this->assertContains('spec_pack_hash', $payload['architect_evidence_required']);
        $this->assertSame(2, $payload['architect_evidence_required_count']);
    }

    public function test_phase_doc_promotion_ids_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->phaseDocPromotionIdsContractObserve([]);

        $this->assertContains('intent_capture', $payload['phase_ids']);
        $this->assertContains('gates', $payload['phase_ids']);
        $this->assertContains('learning', $payload['phase_ids']);
        $this->assertSame(17, $payload['phase_id_count']);
        $this->assertSame('policy_gate', $payload['phase_advance_policy_gate']);
        $this->assertSame('receipt', $payload['phase_advance_receipt']);
        $this->assertSame('policy_decision_allowed_true', $payload['policy_gate_token']);
        $this->assertSame('advance', $payload['verdict_advance']);
        $this->assertSame('repair', $payload['verdict_repair']);
        $this->assertSame('block', $payload['verdict_block']);
        $this->assertSame('halt', $payload['verdict_halt']);
        $this->assertSame('DOC L0', $payload['doc_level_l0']);
        $this->assertSame('DOC L4', $payload['doc_level_l4']);
        $this->assertSame('none', $payload['doc_strength_none']);
        $this->assertSame('partial', $payload['doc_strength_partial']);
        $this->assertSame('strong', $payload['doc_strength_strong']);
        $this->assertSame('off', $payload['promotion_state_off']);
        $this->assertSame('shadow', $payload['promotion_state_shadow']);
        $this->assertSame('live', $payload['promotion_state_live']);
        $this->assertSame('rolled_back', $payload['promotion_state_rolled_back']);
        $this->assertSame('suspended_pending_evidence', $payload['promotion_state_suspended_pending_evidence']);
        $this->assertStringContainsString('acos-max-promotion-flips.jsonl', $payload['promotion_default_ledger_relative_path']);
        $this->assertContains('flip_criterion', $payload['promotion_required_fields']);
        $this->assertSame(5, $payload['promotion_required_field_count']);
        $this->assertSame('owner_doc', $payload['claim_field_owner_doc']);
        $this->assertSame('proof', $payload['claim_field_proof']);
        $this->assertSame('caveat', $payload['claim_field_caveat']);
    }

    public function test_outcome_immune_scorecard_ids_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->outcomeImmuneScorecardIdsContractObserve([]);

        $this->assertSame('success', $payload['outcome_success']);
        $this->assertSame('give_back', $payload['outcome_give_back']);
        $this->assertSame('poison', $payload['outcome_poison']);
        $this->assertSame('quarantine', $payload['outcome_quarantine']);
        $this->assertSame(4, $payload['outcome_count']);
        $this->assertSame('immune_verdict_ledger', $payload['immune_verdict_table']);
        $this->assertSame('true_block', $payload['immune_label_true_block']);
        $this->assertSame('false_block', $payload['immune_label_false_block']);
        $this->assertSame('missed_poison', $payload['immune_label_missed_poison']);
        $this->assertSame(3, $payload['immune_label_count']);
        $this->assertSame('ready', $payload['scorecard_status_ready']);
        $this->assertSame('partial', $payload['scorecard_status_partial']);
        $this->assertSame('building', $payload['scorecard_status_building']);
        $this->assertSame('blocked', $payload['scorecard_status_blocked']);
        $this->assertSame(10, $payload['scorecard_status_point_ready']);
        $this->assertSame('passed', $payload['delivery_status_passed']);
        $this->assertSame('needs_review', $payload['delivery_status_needs_review']);
        $this->assertSame('failed', $payload['delivery_status_failed']);
        $this->assertSame(3, $payload['delivery_status_count']);
        $this->assertSame('blocked', $payload['blocker_signal_blocked']);
        $this->assertSame('warning', $payload['blocker_signal_warning']);
        $this->assertSame('clear', $payload['blocker_signal_clear']);
        $this->assertSame(3, $payload['blocker_signal_count']);
        $this->assertSame('operator_request', $payload['memory_fabric_trigger_operator']);
        $this->assertSame('frontmatter_drift', $payload['memory_fabric_trigger_frontmatter_drift']);
        $this->assertSame('extension_pressure', $payload['memory_fabric_trigger_extension_pressure']);
        $this->assertSame(3, $payload['memory_fabric_trigger_count']);
    }

    public function test_gate_evolution_skill_freeze_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->gateEvolutionSkillFreezeContractObserve([]);

        $this->assertSame('atlas.aaeos.gate_signal.v1', $payload['gate_signal_schema']);
        $this->assertSame(2, $payload['ambiguity_saturation']);
        $this->assertSame(1, $payload['missing_saturation']);
        $this->assertSame('atlas.cognition.evolution_score.v1', $payload['evolution_score_schema']);
        $this->assertStringContainsString('AtlasLoopTierPromotionChainService', $payload['tier_chain_class']);
        $this->assertStringContainsString('AtlasBrainReplayCommand', $payload['reversal_command_class']);
        $this->assertSame('atlas.ai.procedural_skill_promoter.v1', $payload['procedural_skill_schema']);
        $this->assertSame('skill.v1', $payload['procedural_skill_skill_schema']);
        $this->assertSame('atlas.immune.signature_store.v1', $payload['immune_signature_freeze_measure_id']);
        $this->assertSame(90, $payload['immune_signature_freeze_ttl_days']);
        $this->assertSame('atlas.cognition.immune_signature_store.v1', $payload['immune_signature_store_schema']);
        $this->assertSame('atlas.cognition.immune_signature_family.v1', $payload['immune_signature_deriver_schema']);
    }

    public function test_residual_schema_ledger_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->residualSchemaLedgerContractObserve([]);

        $this->assertSame(12, $payload['spec_completeness_weight_count']);
        $this->assertSame(100, $payload['spec_completeness_weight_sum']);
        $this->assertSame(14, $payload['spec_completeness_weights']['acceptance_criteria']);
        $this->assertSame('atlas.aaeos.quality_bar', $payload['quality_bar_canonical_source']);
        $this->assertSame('immune_signature_store', $payload['immune_signature_table']);
        $this->assertSame('atlas.acos.watchdog.evidence_ledger_integrity.v1', $payload['evidence_ledger_integrity_schema']);
        $this->assertStringContainsString('integrity.jsonl', $payload['evidence_ledger_integrity_default_path']);
        $this->assertSame('atlas.originator.dogfooding_friction_leads.v1', $payload['dogfooding_friction_schema']);
        $this->assertSame('atlas.memory.belief_cascade_reverification.v1', $payload['belief_cascade_schema']);
        $this->assertSame('GAP-HERMES-01', $payload['operational_volume_prerequisite_gap']);
        $this->assertSame('obra:acos-max', $payload['obra_retro_series_tag']);
        $this->assertSame('atlas.aaeos.phase.v1', $payload['required_gate_coverage_schema']);
    }

    public function test_unwired_watchdog_checks_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->unwiredWatchdogChecksContractObserve([]);

        $this->assertSame('atlas.acos.dead_series_watchdog.v1', $payload['dead_series_schema']);
        $this->assertSame('elev-20s.dead_series_registry', $payload['dead_series_check_id']);
        $this->assertSame('atlas.acos.watchdog.aobg_latency.v1', $payload['aobg_latency_schema']);
        $this->assertSame('aobg.latency_ledger.v1', $payload['aobg_latency_default_measure_id']);
        $this->assertSame(5, $payload['aobg_latency_default_denominator_min']);
        $this->assertSame(18000.0, $payload['aobg_latency_pack_p95_ms_alert']);
        $this->assertSame(15000.0, $payload['aobg_latency_recall_p95_ms_alert']);
        $this->assertSame(20000.0, $payload['aobg_latency_hook_p95_ms_alert']);
        $this->assertSame('atlas.acos.disk_free_watchdog.v1', $payload['disk_free_schema']);
        $this->assertSame(5, $payload['disk_free_default_floor_gb']);
        $this->assertSame('atlas.acos.joint_resource_budget_watchdog.v1', $payload['joint_resource_budget_schema']);
        $this->assertSame('atlas.acos.local_model_integrity_watchdog.v1', $payload['local_model_integrity_schema']);
        $this->assertSame('atlas.acos.operator_review_debt_watchdog.v1', $payload['operator_review_debt_schema']);
        $this->assertSame('atlas.provider_bound_redaction_drift.v1', $payload['provider_bound_redaction_schema']);
        $this->assertSame(200, $payload['provider_bound_redaction_sample_limit']);
        $this->assertSame('atlas.memory.substrate_restore_drill.watchdog.v1', $payload['substrate_restore_schema']);
        $this->assertSame(45, $payload['substrate_restore_default_max_success_age_days']);
        $this->assertSame('maxf-02.compaction_recovery_sample', $payload['compaction_recovery_check_id']);
        $this->assertSame(50, $payload['compaction_recovery_default_limit']);
        $this->assertSame(14, $payload['compaction_recovery_default_days']);
        $this->assertSame(20, $payload['compaction_recovery_default_min_receipts']);
        $this->assertSame('maxn-01.operator_learning_capture_schema', $payload['operator_learning_capture_check_id']);
        $this->assertSame(10, $payload['health_report_catalog_count']);
        $this->assertSame('mem-09.memory_quality', $payload['health_report_catalog'][0]['id']);
    }

    public function test_watchdog_runner_autonomy_ladder_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->watchdogRunnerAutonomyLadderContractObserve([]);

        $this->assertSame('atlas.acos.watchdog_run.v1', $payload['watchdog_runner_schema']);
        $this->assertSame('alert', $payload['aggregate_status_alert']);
        $this->assertSame('warning', $payload['aggregate_status_warning']);
        $this->assertSame('healthy', $payload['aggregate_status_healthy']);
        $this->assertSame('atlas.acos.watchdog.autonomy_ladder_adversarial.v1', $payload['autonomy_ladder_schema']);
        $this->assertSame('maxk-09.autonomy_ladder_adversarial', $payload['autonomy_ladder_check_id']);
        $this->assertSame('atlas.acos.promotion_protocol.v1', $payload['promotion_protocol_schema']);
        $this->assertSame('atlas.acos.promotion_protocol.report.v1', $payload['promotion_protocol_report_schema']);
        $this->assertSame(5, $payload['promotion_protocol_state_count']);
        $this->assertSame(5, $payload['promotion_protocol_required_field_count']);
    }

    public function test_outcome_envelope_adapters_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->outcomeEnvelopeAdaptersContractObserve([]);

        $this->assertSame('aemor', $payload['aemor_adapter_kind']);
        $this->assertSame('dev_procedural', $payload['dev_procedural_adapter_kind']);
        $this->assertSame('atlas.dev.outcome_memory.v1', $payload['dev_procedural_native_schema']);
        $this->assertSame('compounding', $payload['compounding_adapter_kind']);
        $this->assertSame('atlas.acos.windows.v1', $payload['window_orchestrator_schema']);
        $this->assertSame('atlas.cognition.acos_long_horizon_gate.v1', $payload['long_horizon_gate_schema']);
        $this->assertSame(3, $payload['adapter_kind_count']);
    }

    public function test_implementation_truth_rank_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->implementationTruthRankContractObserve([]);

        $this->assertSame('atlas.aaeos.implementation_state.v1', $payload['implementation_truth_schema']);
        $this->assertSame('atlas.aaeos.capability_truth_ledger.v1', $payload['implementation_truth_ledger_schema']);
        $this->assertSame('atlas.aaeos.impl_files_hash.v2', $payload['implementation_truth_hash_format']);
        $this->assertSame(0, $payload['rank_spec']);
        $this->assertSame(1, $payload['rank_partial']);
        $this->assertSame(2, $payload['rank_verified']);
        $this->assertSame(3, $payload['rank_count']);
        $this->assertSame('atlas.capture.hmac_lineage.v1', $payload['capture_hmac_schema']);
        $this->assertSame('source', $payload['capture_hmac_stage_source']);
        $this->assertSame('capture', $payload['capture_hmac_stage_capture']);
        $this->assertSame('memory', $payload['capture_hmac_stage_memory']);
        $this->assertSame('atlas.acos.rollback_triggers.v1', $payload['rollback_trigger_schema']);
        $this->assertSame('atlas.context.golden_counterfactual.v1', $payload['golden_counterfactual_schema']);
        $this->assertSame('atlas.asef_chunks.index.v1', $payload['asef_chunk_index_schema']);
    }

    public function test_secondary_report_schemas_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->secondaryReportSchemasContractObserve([]);

        $this->assertSame('atlas.aaeos.generated_contract_gate.v1', $payload['generated_contract_gate_schema']);
        $this->assertSame('atlas.docs.locate.v1', $payload['docs_locate_schema']);
        $this->assertSame('atlas.docs.authority_graph.v1', $payload['docs_authority_schema']);
        $this->assertSame('atlas.esp_06.outcome_envelope_bridge.v1', $payload['outcome_envelope_bridge_schema']);
        $this->assertSame('atlas.esp_06.outcome_envelope.v1', $payload['outcome_envelope_bridge_measure_id']);
        $this->assertSame('atlas.acos.lote2.measure_report.v1', $payload['lote2_measure_report_schema']);
        $this->assertSame('atlas.operator.pre_review_advisory_band.calibration.v1', $payload['pre_review_calibration_schema']);
        $this->assertSame('atlas.operator.pre_review_advisory_band.v1', $payload['pre_review_advisory_schema']);
        $this->assertSame('atlas.aaeos.doc_runtime_coverage.v1', $payload['doc_runtime_coverage_schema']);
        $this->assertSame('atlas.model_integrity_manifest.v1', $payload['model_integrity_manifest_schema']);
        $this->assertSame(7, $payload['secondary_report_schema_count']);
    }

    public function test_department_io_schemas_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->departmentIoSchemasContractObserve([]);

        $this->assertSame('atlas.aaeos.department.v1', $payload['department_runtime_schema']);
        $this->assertSame(36, $payload['department_io_schema_count']);
        $this->assertSame('atlas.intent.raw.v1', $payload['schema_intent_raw']);
        $this->assertSame('atlas.ai.mission.v1', $payload['schema_ai_mission']);
        $this->assertSame('atlas.engineering_goal.v1', $payload['schema_engineering_goal']);
        $this->assertSame('atlas.spec_pack.v1', $payload['schema_spec_pack']);
        $this->assertSame('atlas.task_pack.v1', $payload['schema_task_pack']);
        $this->assertSame('atlas.patch_pack.v1', $payload['schema_patch_pack']);
        $this->assertSame('atlas.test_pack.v1', $payload['schema_test_pack']);
        $this->assertSame('atlas.review_report.v1', $payload['schema_review_report']);
        $this->assertSame('atlas.security.finding.v1', $payload['schema_security_finding']);
        $this->assertSame('atlas.delivery_pack.v1', $payload['schema_delivery_pack']);
        $this->assertSame('atlas.memory_record.v1', $payload['schema_memory_record']);
        $this->assertSame('atlas.learning_capsule.v1', $payload['schema_learning_capsule']);
        $this->assertSame('atlas.ai.mission.v1', $payload['evidence_schema_executive_intake']);
        $this->assertSame('atlas.dev.plan_visible.v1', $payload['evidence_schema_dev']);
        $this->assertSame('atlas.learning.compounding_signal.v1', $payload['evidence_schema_memory']);
    }

    public function test_http_path_watchdog_observe_schemas_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->httpPathWatchdogObserveSchemasContractObserve([]);

        $this->assertSame('atlas.aaeos.http_path_status.v1', $payload['http_path_status_schema']);
        $this->assertSame('atlas.aaeos.http_path_request.v1', $payload['http_path_request_schema']);
        $this->assertSame('atlas.aurg.coverage_gate.v1', $payload['aurg_coverage_schema']);
        $this->assertSame('atlas.rag.dimension_watchdog.v1', $payload['rag_dimension_schema']);
        $this->assertSame('atlas.pipeline.scorecard_stability_watch.v1', $payload['pipeline_scorecard_stability_schema']);
        $this->assertSame('atlas.ope.lift_cycle_closure_watch.v1', $payload['ope_lift_cycle_closure_schema']);
        $this->assertSame('atlas.ope.scorecard_receipts_diagnosis_watch.v1', $payload['ope_scorecard_receipts_diagnosis_schema']);
        $this->assertSame('atlas.acos.watchdog.onda4.v1', $payload['onda4_emitter_version']);
        $this->assertSame('atlas.aaeos.ledger_rotation_observe.v1', $payload['observe_ledger_rotation_schema']);
        $this->assertSame('atlas.aaeos.universal_gates_catalogue.v1', $payload['observe_universal_gates_catalogue_schema']);
        $this->assertSame('atlas.cognition.evidence_statuses.v1', $payload['observe_evidence_statuses_schema']);
        $this->assertSame('atlas.cognition.surprise_gate.bands.v1', $payload['observe_surprise_gate_bands_schema']);
        $this->assertSame(13, $payload['observe_schema_count']);
    }

    public function test_evaluator_observe_helpers_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->evaluatorObserveHelpersContractObserve([]);

        $this->assertSame('atlas.aaeos.evidence_vision_observe.v1', $payload['observe_evidence_vision_schema']);
        $this->assertSame('atlas.aaeos.threshold_ladder_observe.v1', $payload['observe_threshold_ladder_schema']);
        $this->assertSame('atlas.aaeos.string_list_normalize.v1', $payload['observe_string_list_normalize_schema']);
        $this->assertSame('atlas.aaeos.threshold_comparator.v1', $payload['observe_threshold_comparator_schema']);
        $this->assertSame('atlas.aaeos.evidence_ref_normalize.v1', $payload['observe_evidence_ref_normalize_schema']);
        $this->assertSame('atlas.aaeos.array_field_reader.v1', $payload['observe_array_field_reader_schema']);
        $this->assertSame('atlas.aaeos.outcome_attribution_types.v1', $payload['observe_outcome_attribution_types_schema']);
        $this->assertSame('atlas.telemetry.collector.surfaces.v1', $payload['observe_telemetry_collector_surfaces_schema']);
        $this->assertSame('atlas.aaeos.blocker_severity.v1', $payload['observe_blocker_severity_schema']);
        $this->assertSame('atlas.cognition.acos_long_horizon_gate.v1', $payload['long_horizon_gate_schema']);
        $this->assertSame('atlas.cognition.acos_long_horizon_gate.area_v2', $payload['long_horizon_area_v2_schema']);
        $this->assertSame(11, $payload['evaluator_observe_helper_count']);
    }

    public function test_gate_report_schema_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->gateReportSchemaContractObserve([]);

        $this->assertSame('atlas.aaeos.gate_report.v1', $payload['gate_report_schema']);
        $this->assertSame('atlas.aaeos.universal_gates_catalogue.v1', $payload['universal_gates_catalogue_observe_schema']);
        $this->assertSame(15, $payload['catalogue_gate_count']);
        $this->assertSame(13, $payload['observe_helper_schema_count']);
    }

    public function test_docs_authority_confidence_keys_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->docsAuthorityConfidenceKeysContractObserve([]);

        $this->assertSame('atlas.docs.authority_graph.v1', $payload['docs_authority_schema']);
        $this->assertSame('atlas.docs.locate.v1', $payload['docs_locate_schema']);
        $this->assertSame(100, $payload['confidence_governs_frontmatter']);
        $this->assertSame(95, $payload['confidence_doc_id']);
        $this->assertSame(80, $payload['confidence_capability_frontmatter']);
        $this->assertSame(40, $payload['confidence_keyword_fallback']);
        $this->assertSame(4, $payload['confidence_basis_count']);
        $this->assertSame(100, $payload['confidence_max']);
        $this->assertSame(40, $payload['confidence_min']);
    }

    public function test_maxa04_promotion_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->maxa04PromotionFloorsContractObserve([]);

        $this->assertSame('jinaai/jina-embeddings-v3', $payload['maxa04_candidate_model']);
        $this->assertSame(1024, $payload['maxa04_candidate_dimensions']);
        $this->assertSame('jina_v3_dual_read_benchmark_window', $payload['maxa04_pending_window']);
        $this->assertSame('sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2', $payload['maxa04_current_model_fallback']);
        $this->assertSame('atlas.semantic.jina_v3_dual_read.v1', $payload['maxa04_ledger_schema']);
        $this->assertSame('app/atlas/evidence/maxa04-jina-v3-dual-read.jsonl', $payload['maxa04_ledger_relative_path']);
        $this->assertSame('atlas.aaeos.department_promotion_eligibility.v1', $payload['promotion_eligibility_schema']);
        $this->assertSame(30, $payload['promotion_max_evidence_age_days']);
        $this->assertSame(5, $payload['promotion_max_tier']);
        $this->assertSame('atlas.acmf.schema_proposal.v1', $payload['memory_fabric_proposal_schema']);
        $this->assertSame('atlas.acmf.schema_evolution_ticket.v1', $payload['memory_fabric_ticket_schema']);
        $this->assertSame(4, $payload['memory_fabric_extension_pressure_threshold']);
        $this->assertSame(12, $payload['maxa04_promotion_floor_count']);
    }

    public function test_composed_obra_lifecycle_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->composedObraLifecycleFloorsContractObserve([]);

        $this->assertSame('atlas.originator.composed_obra_arc.v1', $payload['composer_schema']);
        $this->assertSame('atlas.originator.composed_obra_arc_lifecycle.v1', $payload['lifecycle_schema']);
        $this->assertSame(3, $payload['min_neighbor_candidates']);
        $this->assertSame(3, $payload['kill_gate_consecutive_failures']);
        $this->assertSame('cursor-acos-max-multn1702', $payload['default_author_engine_id']);
        $this->assertSame('codex-independent-multn1702-judge', $payload['default_judge_engine_id']);
        $this->assertSame('atlas.originator.evidence_vision_thesis.v1', $payload['evidence_vision_composer_schema']);
        $this->assertSame('atlas.originator.evidence_vision_thesis_lifecycle.v1', $payload['evidence_vision_lifecycle_schema']);
        $this->assertSame(3, $payload['max_theses']);
        $this->assertSame(4, $payload['min_regression_windows']);
        $this->assertSame(30, $payload['default_ttl_days']);
        $this->assertSame(['series', 'ledger', 'outcome'], $payload['allowed_evidence_sources']);
        $this->assertSame('atlas.acos.windows.v1', $payload['window_orchestrator_schema']);
        $this->assertSame(13, $payload['composed_obra_lifecycle_floor_count']);
    }

    public function test_resource_budget_host_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->resourceBudgetHostFloorsContractObserve([]);

        $this->assertSame('atlas.resource_budget.v1', $payload['resource_budget_schema']);
        $this->assertSame(48, $payload['default_host_ram_gib']);
        $this->assertSame(12, $payload['default_engine_floor_gib']);
        $this->assertSame(5, $payload['aobg_latency_default_denominator_min']);
        $this->assertSame(18000.0, $payload['aobg_latency_pack_p95_ms_alert']);
        $this->assertSame(15000.0, $payload['aobg_latency_recall_p95_ms_alert']);
        $this->assertSame(20000.0, $payload['aobg_latency_hook_p95_ms_alert']);
        $this->assertSame('atlas.acos_max.verified_share.v1', $payload['verified_share_schema']);
        $this->assertSame('acos.verified_share.v1', $payload['verified_share_measure_id']);
        $this->assertSame('verified_share.v1', $payload['verified_share_formula']);
        $this->assertSame(10, $payload['resource_budget_host_floor_count']);
    }

    public function test_verified_share_procedural_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->verifiedShareProceduralFloorsContractObserve([]);

        $this->assertSame(0.80, $payload['verified_share_min']);
        $this->assertSame(14, $payload['verified_share_window_days_min']);
        $this->assertSame(50, $payload['verified_share_denominator_min_executions']);
        $this->assertSame(30, $payload['verified_share_ttl_days']);
        $this->assertSame('atlas.ai.procedural_skill_promoter.v1', $payload['procedural_skill_schema']);
        $this->assertSame('skill.v1', $payload['procedural_skill_schema_version']);
        $this->assertSame(8, $payload['procedural_case_count_floor']);
        $this->assertSame('atlas.acos_max.n_capture_drill.v1', $payload['n_capture_schema']);
        $this->assertSame('atlas.n_capture_drill.v1', $payload['n_capture_measure_id']);
        $this->assertSame(180, $payload['n_capture_days_between_drills_max']);
        $this->assertSame(10, $payload['verified_share_procedural_floor_count']);
    }

    public function test_long_horizon_gate_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->longHorizonGateFloorsContractObserve([]);

        $this->assertSame('atlas.cognition.acos_long_horizon_gate.v1', $payload['long_horizon_gate_schema']);
        $this->assertSame('atlas.cognition.acos_long_horizon_gate.area_v2', $payload['long_horizon_area_v2_schema']);
        $this->assertSame(30, $payload['long_horizon_min_days']);
        $this->assertSame(9.5, $payload['long_horizon_min_overall']);
        $this->assertSame(9.5, $payload['long_horizon_min_pipeline']);
        $this->assertSame(0.15, $payload['long_horizon_warning_margin']);
        $this->assertSame(2, $payload['long_horizon_max_latest_stale_days']);
        $this->assertSame(1, $payload['long_horizon_max_gap_days']);
        $this->assertSame(0.80, $payload['esp09_high_alignment_band']);
        $this->assertSame(2, $payload['esp09_default_min_windows']);
        $this->assertSame(2, $payload['esp09_default_min_per_window']);
        $this->assertSame(11, $payload['long_horizon_gate_floor_count']);
    }

    public function test_ledger_rotation_impact_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->ledgerRotationImpactFloorsContractObserve([]);

        $this->assertSame(32, $payload['ledger_rotation_default_max_size_mb']);
        $this->assertSame(30, $payload['ledger_rotation_default_max_age_days']);
        $this->assertSame('atlas.originator.predicted_impact_band.v1', $payload['predicted_impact_schema']);
        $this->assertSame(99, $payload['predicted_impact_default_rank_fallback']);
        $this->assertSame(3, $payload['predicted_impact_rank_top_cutoff']);
        $this->assertSame(0.5, $payload['predicted_impact_yield_sweet_floor']);
        $this->assertSame(4, $payload['predicted_impact_high_score_floor']);
        $this->assertSame(2, $payload['predicted_impact_sweet_score_floor']);
        $this->assertSame('atlas.originator.evidence_vision_thesis_lifecycle.v1', $payload['evidence_vision_lifecycle_schema']);
        $this->assertSame(2, $payload['evidence_vision_consecutive_windows']);
        $this->assertSame(10, $payload['ledger_rotation_impact_floor_count']);
    }

    public function test_observe_helper_limit_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->observeHelperLimitFloorsContractObserve([]);

        $this->assertSame('atlas.memory.recall_gap_aggregator.v1', $payload['recall_gap_schema']);
        $this->assertSame(0.35, $payload['recall_gap_weak_score_floor']);
        $this->assertSame(3, $payload['recall_gap_default_min_occurrences']);
        $this->assertSame('atlas.memory.belief_cascade_reverification.v1', $payload['belief_cascade_schema']);
        $this->assertSame(3, $payload['belief_cascade_default_depth_cap']);
        $this->assertSame('atlas.acos.teto10.predicted_revert_review_digest.v1', $payload['teto10_schema']);
        $this->assertSame(50, $payload['teto10_default_limit']);
        $this->assertSame(200, $payload['teto10_hard_limit_cap']);
        $this->assertSame('atlas.docs.locate.v1', $payload['docs_locate_schema']);
        $this->assertSame(5, $payload['docs_locate_default_limit']);
        $this->assertSame(10, $payload['observe_helper_limit_floor_count']);
    }

    public function test_outcome_envelope_bool_fields_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->outcomeEnvelopeBoolFieldsContractObserve([]);

        $this->assertSame('AiValueNormalizer::boolOrNull', $payload['bool_or_null_helper']);
        $this->assertSame('aemor', $payload['aemor_adapter_kind']);
        $this->assertSame(['verified'], $payload['aemor_bool_fields']);
        $this->assertSame('compounding', $payload['compounding_adapter_kind']);
        $this->assertSame(['verified', 'learning_required', 'human_override'], $payload['compounding_bool_fields']);
        $this->assertSame('dev_procedural', $payload['dev_procedural_adapter_kind']);
        $this->assertSame('atlas.dev.outcome_memory.v1', $payload['dev_procedural_native_schema']);
        $this->assertSame(['proven_real', 'fake_green', 'should_promote_to_aemor'], $payload['dev_procedural_bool_fields']);
        $this->assertSame(7, $payload['outcome_envelope_bool_field_count']);
        $this->assertSame(9, $payload['outcome_envelope_bool_fields_floor_count']);
    }

    public function test_quality_bar_cognitive_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->qualityBarCognitiveFloorsContractObserve([]);

        $this->assertSame('atlas.aaeos.quality_bar.v1', $payload['quality_bar_schema']);
        $this->assertSame('atlas.aaeos.quality_bar_telemetry.v1', $payload['quality_bar_telemetry_schema']);
        $this->assertSame('quality_bar_auto_block', $payload['quality_bar_immune_gate_id']);
        $this->assertSame(30, $payload['quality_bar_evaluated_window_days']);
        $this->assertTrue($payload['quality_bar_auto_block_on_breach']);
        $this->assertSame(8, $payload['cognitive_function_atlas_overload_threshold']);
        $this->assertSame('pending', $payload['cognitive_immune_default_gate_status']);
        $this->assertSame('atlas.cognition.acos_long_horizon_gate.v1', $payload['long_horizon_gate_schema']);
        $this->assertTrue($payload['long_horizon_default_enabled']);
        $this->assertSame(9, $payload['quality_bar_cognitive_floor_count']);
    }

    public function test_parallel_substrate_bridge_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->parallelSubstrateBridgeFloorsContractObserve([]);

        $this->assertSame('acos_max.parallel_execution.v1', $payload['parallel_execution_schema']);
        $this->assertSame('task', $payload['parallel_execution_claim_kind']);
        $this->assertSame(3600, $payload['parallel_execution_default_ttl_seconds']);
        $this->assertSame('atlas.memory.substrate_restore_drill.watchdog.v1', $payload['substrate_restore_schema']);
        $this->assertSame(45, $payload['substrate_restore_default_max_success_age_days']);
        $this->assertSame('atlas.esp_06.outcome_envelope_bridge.v1', $payload['outcome_envelope_bridge_schema']);
        $this->assertSame('atlas.esp_06.outcome_envelope.v1', $payload['outcome_envelope_bridge_measure_id']);
        $this->assertSame('atlas.esp_06.outcome_envelope_adapters_enabled', $payload['outcome_envelope_adapters_enabled_config_key']);
        $this->assertFalse($payload['outcome_envelope_adapters_default_enabled']);
        $this->assertSame('atlas.ai.procedural_skill_promoter.enqueue_enabled', $payload['procedural_enqueue_enabled_config_key']);
        $this->assertFalse($payload['procedural_enqueue_default_enabled']);
        $this->assertSame(11, $payload['parallel_substrate_bridge_floor_count']);
    }

    public function test_ops_config_toggle_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->opsConfigToggleFloorsContractObserve([]);

        $this->assertSame('atlas.aaeos.mission_foundation_optional_at_phase_1', $payload['http_path_mission_foundation_optional_config_key']);
        $this->assertTrue($payload['http_path_mission_foundation_optional_default']);
        $this->assertSame('atlas.aaeos.placement_cache_ttl_seconds', $payload['http_path_placement_cache_ttl_config_key']);
        $this->assertSame(300, $payload['http_path_placement_cache_ttl_default_seconds']);
        $this->assertSame('atlas.aaeos.telemetry_enabled', $payload['http_path_telemetry_enabled_config_key']);
        $this->assertTrue($payload['http_path_telemetry_enabled_default']);
        $this->assertSame('atlas.cognition.remint_touched_enabled', $payload['remint_enabled_config_key']);
        $this->assertFalse($payload['remint_enabled_default']);
        $this->assertSame('atlas.cognition.remint_touched_queue_disk', $payload['remint_queue_disk_config_key']);
        $this->assertSame('local', $payload['remint_queue_disk_default']);
        $this->assertSame('atlas.cognition.remint_touched_queue_path', $payload['remint_queue_path_config_key']);
        $this->assertSame('atlas/cognition/remint-touched-queue.jsonl', $payload['remint_queue_path_default']);
        $this->assertSame('atlas.semantic_memory.recall_concentration_demotion_enabled', $payload['watchdog_recall_concentration_demotion_enabled_config_key']);
        $this->assertTrue($payload['watchdog_recall_concentration_demotion_enabled_default']);
        $this->assertSame('atlas.patamar4.adml_cost_outcome.enabled', $payload['watchdog_adml_cost_outcome_enabled_config_key']);
        $this->assertFalse($payload['watchdog_adml_cost_outcome_enabled_default']);
        $this->assertSame('atlas.acos.rollback_triggers.enabled', $payload['rollback_triggers_enabled_config_key']);
        $this->assertTrue($payload['rollback_triggers_enabled_default']);
        $this->assertSame('atlas.acos.rollback_triggers.flips', $payload['rollback_triggers_flips_config_key']);
        $this->assertSame('atlas_elite_compaction.scorecard.dual_emit_v3', $payload['scorecard_dual_emit_v3_config_key']);
        $this->assertTrue($payload['scorecard_dual_emit_v3_default']);
        $this->assertSame('atlas_elite_compaction.generated.hot_path_enabled', $payload['generated_hot_path_enabled_config_key']);
        $this->assertFalse($payload['generated_hot_path_enabled_default']);
        $this->assertSame('atlas_elite_compaction.generated.quarantine_namespace', $payload['generated_quarantine_namespace_config_key']);
        $this->assertSame('atlas.ai.context_feedback.global_hints_enabled', $payload['evolution_global_hints_enabled_config_key']);
        $this->assertTrue($payload['evolution_global_hints_enabled_default']);
        $this->assertSame('atlas.aaeos.immune_classifier.semantic_arm_enabled', $payload['immune_semantic_arm_enabled_config_key']);
        $this->assertFalse($payload['immune_semantic_arm_enabled_default']);
        $this->assertSame(28, $payload['ops_config_toggle_floor_count']);
    }


    public function test_ragx_immune_substrate_config_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->ragxImmuneSubstrateConfigFloorsContractObserve([]);

        $this->assertSame('atlas.aobg.ragx_late_chunk_index', $payload['ragx_flag_late_chunk_index']);
        $this->assertSame('atlas.aobg.ragx_late_chunk_maxa04_promoted', $payload['ragx_flag_late_chunk_maxa04_promoted']);
        $this->assertSame('atlas.aobg.ragx_adaptive_k', $payload['ragx_flag_adaptive_k']);
        $this->assertSame('atlas.aobg.ragx_sparse_fallback', $payload['ragx_flag_sparse_fallback']);
        $this->assertSame('atlas.aobg.ragx_ab_registrar', $payload['ragx_flag_ab_registrar']);
        $this->assertSame('atlas.aobg.ragx_louvain_chunks', $payload['ragx_flag_louvain_chunks']);
        $this->assertSame('atlas.aobg.ragx_maxa06_fase2_backfilled', $payload['ragx_flag_maxa06_fase2_backfilled']);
        $this->assertSame('atlas.aobg.ragx_raptor_lite', $payload['ragx_flag_raptor_lite']);
        $this->assertSame('atlas.cognition.substrate_restore_drill.receipt_path', $payload['substrate_receipt_path_config_key']);
        $this->assertSame('app/atlas/evidence/substrate-restore-drills.jsonl', $payload['substrate_default_receipt_relative_path']);
        $this->assertSame('atlas.cognition.substrate_restore_drill.max_success_age_days', $payload['substrate_max_success_age_days_config_key']);
        $this->assertSame('atlas.aaeos.immune_signature.decay_days', $payload['immune_signature_decay_days_config_key']);
        $this->assertSame(90, $payload['immune_signature_default_decay_days']);
        $this->assertSame('atlas.aaeos.immune_signature.mode', $payload['immune_signature_mode_config_key']);
        $this->assertSame('observe', $payload['immune_signature_default_mode']);
        $this->assertSame('atlas.aobg.surprise_gate.threshold', $payload['surprise_threshold_config_key']);
        $this->assertSame('atlas.aobg.surprise_gate.high_band', $payload['surprise_high_band_config_key']);
        $this->assertSame('atlas.aobg.surprise_gate.min_prediction_tokens', $payload['surprise_min_prediction_tokens_config_key']);
        $this->assertSame(0.5, $payload['surprise_default_threshold']);
        $this->assertSame(0.75, $payload['surprise_default_high_band']);
        $this->assertSame(8, $payload['surprise_default_min_prediction_tokens']);
        $this->assertSame('atlas.compaction.recovery_sample_watchdog_limit', $payload['compaction_recovery_limit_config_key']);
        $this->assertSame('atlas.compaction.recovery_sample_watchdog_days', $payload['compaction_recovery_days_config_key']);
        $this->assertSame('atlas.compaction.recovery_sample_min_receipts', $payload['compaction_recovery_min_receipts_config_key']);
        $this->assertSame(50, $payload['compaction_recovery_default_limit']);
        $this->assertSame(14, $payload['compaction_recovery_default_days']);
        $this->assertSame(20, $payload['compaction_recovery_default_min_receipts']);
        $this->assertSame(27, $payload['ragx_immune_substrate_config_floor_count']);
    }


    public function test_residual_ops_config_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->residualOpsConfigFloorsContractObserve([]);

        $this->assertSame('atlas.aobg.facet_retrieval', $payload['ragx_flag_facet_retrieval']);
        $this->assertSame('atlas.aobg.fusion_enabled', $payload['ragx_flag_fusion_enabled']);
        $this->assertSame('atlas.aobg.cross_encoder_rerank', $payload['ragx_flag_cross_encoder_rerank']);
        $this->assertSame('atlas.aaeos.http_path_phase', $payload['http_path_phase_config_key']);
        $this->assertSame('legacy', $payload['http_path_phase_legacy']);
        $this->assertSame('atlas_resource_budget.disk_free_floor_gb', $payload['disk_free_floor_gb_config_key']);
        $this->assertSame(5, $payload['disk_free_default_floor_gb']);
        $this->assertSame('atlas.cognition.acos_long_horizon_gate', $payload['long_horizon_gate_config_key']);
        $this->assertTrue($payload['long_horizon_gate_default_enabled']);
        $this->assertSame('atlas.capture.hmac_lineage_secret', $payload['capture_hmac_secret_config_key']);
        $this->assertSame('app.key', $payload['capture_hmac_app_key_config_key']);
        $this->assertSame('atlas.capture.hmac_lineage.v1', $payload['capture_hmac_key_material_label']);
        $this->assertSame('atlas.capture.hmac_lineage.fallback.v1', $payload['capture_hmac_key_material_fallback']);
        $this->assertSame('atlas.semantic_memory.jina_v3_dual_read_ledger_path', $payload['maxa04_ledger_path_config_key']);
        $this->assertSame('app/atlas/evidence/maxa04-jina-v3-dual-read.jsonl', $payload['maxa04_ledger_relative_path']);
        $this->assertSame('atlas_model_capability_spec', $payload['model_capability_spec_config_key']);
        $this->assertSame('atlas_resource_budget', $payload['resource_budget_config_key']);
        $this->assertSame('atlas_model_manifest', $payload['local_model_manifest_config_key']);
        $this->assertSame(18, $payload['residual_ops_config_floor_count']);
    }


    public function test_ragx_stage_mechanism_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->ragxStageMechanismFloorsContractObserve([]);

        $this->assertSame('RAGX-01', $payload['ragx_stage_ragx_01']);
        $this->assertSame('RAGX-11', $payload['ragx_stage_ragx_11']);
        $this->assertSame('MAXD-05', $payload['ragx_stage_maxd_05']);
        $this->assertSame('late_chunk_asef_chunks_shadow', $payload['ragx_mechanism_late_chunk']);
        $this->assertSame('raptor_lite_from_louvain_and_verified_l2_summaries', $payload['ragx_mechanism_raptor_lite']);
        $this->assertSame('jina_v3_dual_read_benchmark_window', $payload['ragx_pending_jina_v3_dual_read']);
        $this->assertSame('raptor_lite_verified_summary_window', $payload['ragx_pending_raptor_lite_summary']);
        $this->assertSame('MAXA-04', $payload['ragx_blocker_maxa04']);
        $this->assertSame('MAXA-06(fase 2)', $payload['ragx_blocker_maxa06_fase2']);
        $this->assertSame('MAXF-09', $payload['ragx_blocker_maxf09']);
        $this->assertSame(0.25, $payload['ragx_adaptive_k_score_gap_floor']);
        $this->assertSame('app/atlas/evidence/acos-long-horizon-gate.json', $payload['evolution_long_horizon_gate_evidence_relative']);
        $this->assertSame('app/atlas/evidence/acos-delta-series.jsonl', $payload['evolution_delta_series_evidence_relative']);
        $this->assertSame('atlas/scheduler/heartbeat.jsonl', $payload['evolution_scheduler_heartbeat_relative']);
        $this->assertSame(34, $payload['ragx_stage_mechanism_floor_count']);
    }


    public function test_department_extended_io_procedural_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->departmentExtendedIoProceduralFloorsContractObserve([]);

        $this->assertSame('atlas.acceptance_criteria.v1', $payload['schema_acceptance_criteria']);
        $this->assertSame('atlas.context_pack.v1', $payload['schema_context_pack']);
        $this->assertSame('atlas.topology_plan.v1', $payload['schema_topology_plan']);
        $this->assertSame('atlas.obra_pack.v1', $payload['schema_obra_pack']);
        $this->assertSame('MULTJ-04', $payload['procedural_slice_multj04']);
        $this->assertSame('ok', $payload['procedural_status_ok']);
        $this->assertSame('pending_window', $payload['procedural_status_pending_window']);
        $this->assertSame('hold', $payload['procedural_status_hold']);
        $this->assertSame('hold_for_asi02', $payload['procedural_status_hold_for_asi02']);
        $this->assertSame('procedural_case_count_soak', $payload['procedural_reason_case_count_soak']);
        $this->assertSame('awaiting_asi02_admission', $payload['procedural_reason_awaiting_asi02_admission']);
        $this->assertSame('ASI-02', $payload['procedural_admission_door_asi02']);
        $this->assertSame('ai_learning_candidates', $payload['procedural_queue_ai_learning_candidates']);
        $this->assertSame('mechanism', $payload['procedural_scoreboard_landed_mechanism']);
        $this->assertSame(8, $payload['procedural_default_case_count_floor']);
        $this->assertSame(35, $payload['department_extended_io_procedural_floor_count']);
    }


    public function test_runtime_status_mode_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->runtimeStatusModeFloorsContractObserve([]);

        $this->assertSame('shadow', $payload['ragx_mode_shadow']);
        $this->assertSame('default_off', $payload['ragx_mode_default_off']);
        $this->assertSame('shadow', $payload['ragx_status_shadow']);
        $this->assertSame('blocked', $payload['ragx_status_blocked']);
        $this->assertSame('disabled', $payload['ragx_status_disabled']);
        $this->assertSame('not_started', $payload['window_state_not_started']);
        $this->assertSame('unknown', $payload['window_state_unknown']);
        $this->assertSame('window_not_started', $payload['window_blocking_not_started']);
        $this->assertSame('active', $payload['parallel_status_active']);
        $this->assertSame('renewed', $payload['parallel_status_renewed']);
        $this->assertSame('conflict', $payload['parallel_status_conflict']);
        $this->assertSame('error', $payload['parallel_status_error']);
        $this->assertSame(12, $payload['runtime_status_mode_floor_count']);
    }


    public function test_outcome_maxa04_lote2_status_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->outcomeMaxa04Lote2StatusFloorsContractObserve([]);

        $this->assertSame('succeeded', $payload['outcome_status_succeeded']);
        $this->assertSame('failed', $payload['outcome_status_failed']);
        $this->assertSame('blocked', $payload['outcome_status_blocked']);
        $this->assertSame('success', $payload['outcome_native_success']);
        $this->assertSame('passed', $payload['outcome_native_passed']);
        $this->assertSame('failure', $payload['outcome_native_failure']);
        $this->assertSame('dev_procedural', $payload['outcome_origin_dev_procedural']);
        $this->assertSame(3, $payload['outcome_adapter_origin_count']);
        $this->assertSame('jinaai/jina-embeddings-v3', $payload['maxa04_candidate_model']);
        $this->assertSame(1024, $payload['maxa04_candidate_dimensions']);
        $this->assertSame('pending_window', $payload['maxa04_status_pending_window']);
        $this->assertSame('insufficient_signal', $payload['maxa04_status_insufficient_signal']);
        $this->assertSame('ok', $payload['lote2_status_ok']);
        $this->assertSame('measured', $payload['lote2_status_measured']);
        $this->assertSame(20, $payload['outcome_maxa04_lote2_status_floor_count']);
    }


    public function test_lote2_reason_ambition_portfolio_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->lote2ReasonAmbitionPortfolioFloorsContractObserve([]);

        $this->assertSame('missing_lineage_ledger_dependencies', $payload['lote2_reason_missing_lineage_ledger']);
        $this->assertSame('mission_delivery_table_missing', $payload['lote2_reason_mission_delivery_table_missing']);
        $this->assertSame('task', $payload['ambition_rung_task']);
        $this->assertSame('salto', $payload['ambition_rung_salto']);
        $this->assertSame(4, $payload['ambition_rung_count']);
        $this->assertSame('reactive', $payload['portfolio_class_reactive']);
        $this->assertSame('maintenance', $payload['portfolio_class_maintenance']);
        $this->assertSame(3, $payload['portfolio_class_count']);
        $this->assertSame(0.05, $payload['portfolio_hard_floor_share']);
        $this->assertSame(0.80, $payload['portfolio_hard_ceiling_share']);
        $this->assertSame(8, $payload['portfolio_min_n_per_class']);
        $this->assertSame(20, $payload['lote2_reason_ambition_portfolio_floor_count']);
    }

    public function test_esp09_bets_obra_status_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->esp09BetsObraStatusFloorsContractObserve([]);

        $this->assertSame('recursive_improvement', $payload['esp09_trigger_kind_recursive_improvement']);
        $this->assertSame('composed_obra', $payload['esp09_trigger_kind_composed_obra']);
        $this->assertSame('advisory', $payload['esp09_status_advisory']);
        $this->assertSame('awaiting_challenger_block', $payload['esp09_reason_awaiting_challenger_block']);
        $this->assertSame('delayed_not_vetoed', $payload['esp09_promotion_without_block']);
        $this->assertSame('flag_disabled', $payload['bets_status_flag_disabled']);
        $this->assertSame('resume_and_double_down', $payload['bets_action_resume_and_double_down']);
        $this->assertSame('continue_exploring', $payload['bets_action_continue_exploring']);
        $this->assertSame('blocked', $payload['obra_retro_status_blocked']);
        $this->assertSame('no_terminal_slices_for_lote', $payload['obra_retro_reason_no_terminal_slices']);
        $this->assertSame('failure_pattern', $payload['obra_retro_kind_failure_pattern']);
        $this->assertSame(20, $payload['esp09_bets_obra_status_floor_count']);
    }

    public function test_ncapture_promotion_lifecycle_status_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->ncapturePromotionLifecycleStatusFloorsContractObserve([]);

        $this->assertSame('measure_freeze', $payload['ncapture_kind_measure_freeze']);
        $this->assertSame('maxk02', $payload['ncapture_cold_start_channel_maxk02']);
        $this->assertSame('admission_via_bypass_forbidden', $payload['ncapture_reason_admission_via_bypass_forbidden']);
        $this->assertSame('yardstick_failed_but_admitted', $payload['ncapture_reason_yardstick_failed_but_admitted']);
        $this->assertSame('recorded', $payload['promotion_status_recorded']);
        $this->assertSame('legacy_unmanaged', $payload['promotion_status_legacy_unmanaged']);
        $this->assertSame('blocked', $payload['promotion_status_blocked']);
        $this->assertSame('active', $payload['evidence_thesis_status_active']);
        $this->assertSame('archived', $payload['evidence_thesis_status_archived']);
        $this->assertSame('pending', $payload['composed_arc_status_pending']);
        $this->assertSame('started', $payload['attempt_state_started']);
        $this->assertSame('duplicate_attempt', $payload['attempt_reason_duplicate']);
        $this->assertSame(20, $payload['ncapture_promotion_lifecycle_status_floor_count']);
    }

    public function test_asef_remint_immune_ragx_status_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->asefRemintImmuneRagxStatusFloorsContractObserve([]);

        $this->assertSame('unavailable', $payload['asef_status_unavailable']);
        $this->assertSame('asef_chunks_table_missing', $payload['asef_reason_table_missing']);
        $this->assertSame('embedding_column_absent', $payload['asef_reason_embedding_column_absent']);
        $this->assertSame('off', $payload['remint_mode_off']);
        $this->assertSame('deferred_disk_queue', $payload['remint_mode_deferred_disk_queue']);
        $this->assertSame('queued', $payload['remint_reason_queued']);
        $this->assertSame('blocked', $payload['immune_trust_band_blocked']);
        $this->assertSame('trusted', $payload['immune_trust_band_trusted']);
        $this->assertSame('candidate', $payload['immune_trust_band_candidate']);
        $this->assertSame('degraded', $payload['ragx_status_degraded']);
        $this->assertSame('registered', $payload['ragx_status_registered']);
        $this->assertSame('late_chunk_index_error', $payload['ragx_reason_late_chunk_index_error']);
        $this->assertSame(20, $payload['asef_remint_immune_ragx_status_floor_count']);
    }

    public function test_decay_veto_numeric_choreography_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->decayVetoNumericChoreographyFloorsContractObserve([]);

        $this->assertSame('archive', $payload['decay_decision_archive']);
        $this->assertSame('fresh', $payload['decay_decision_fresh']);
        $this->assertSame('stale_inactive_candidate', $payload['decay_decision_stale_inactive_candidate']);
        $this->assertSame('operator_veto_is_final_override_always_passes', $payload['veto_reason_operator_final_override']);
        $this->assertSame('no_canonical_veto_rule_matched_origin_and_kind', $payload['veto_reason_no_canonical_rule']);
        $this->assertSame('invalid', $payload['numeric_relation_invalid']);
        $this->assertSame('overlap', $payload['numeric_relation_overlap']);
        $this->assertSame('a_contains_b', $payload['numeric_relation_a_contains_b']);
        $this->assertSame('noop', $payload['choreography_action_noop']);
        $this->assertSame('pause_downstream', $payload['choreography_action_pause_downstream']);
        $this->assertSame('override', $payload['choreography_action_override']);
        $this->assertSame('veto', $payload['choreography_handoff_kind_veto']);
        $this->assertSame(20, $payload['decay_veto_numeric_choreography_floor_count']);
    }

    public function test_evidence_temporal_hmac_calibration_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->evidenceTemporalHmacCalibrationFloorsContractObserve([]);

        $this->assertSame('ok', $payload['evidence_thesis_status_ok']);
        $this->assertSame('series_recovery', $payload['evidence_thesis_kind_series_recovery']);
        $this->assertSame('outcome_proven', $payload['evidence_thesis_kind_outcome_proven']);
        $this->assertSame('coexist', $payload['temporal_relation_coexist']);
        $this->assertSame('a_supersedes_b', $payload['temporal_relation_a_supersedes_b']);
        $this->assertSame('tie_same_timestamp', $payload['temporal_relation_tie_same_timestamp']);
        $this->assertSame('verified', $payload['hmac_status_verified']);
        $this->assertSame('not_found', $payload['hmac_status_not_found']);
        $this->assertSame('capture', $payload['hmac_kind_capture']);
        $this->assertSame('read_only', $payload['immune_calibration_mode_read_only']);
        $this->assertSame('insufficient_sample', $payload['immune_calibration_band_insufficient_sample']);
        $this->assertSame('known_miss_denominator_zero', $payload['immune_calibration_reason_known_miss_denominator_zero']);
        $this->assertSame(20, $payload['evidence_temporal_hmac_calibration_floor_count']);
    }

    public function test_verified_share_capability_truth_ambition_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->verifiedShareCapabilityTruthAmbitionFloorsContractObserve([]);

        $this->assertSame('measure_freeze', $payload['verified_share_kind_measure_freeze']);
        $this->assertSame('missing_freeze', $payload['verified_share_status_missing_freeze']);
        $this->assertSame('insufficient_signal', $payload['verified_share_status_insufficient_signal']);
        $this->assertSame('missing_model_id', $payload['capability_reason_missing_model_id']);
        $this->assertSame('license_not_allowed', $payload['capability_reason_license_not_allowed']);
        $this->assertSame('spec', $payload['truth_level_spec']);
        $this->assertSame('verified', $payload['truth_level_verified']);
        $this->assertSame('existence_only', $payload['truth_level_existence_only']);
        $this->assertSame(0, $payload['truth_rank_spec']);
        $this->assertSame(1, $payload['truth_rank_partial']);
        $this->assertSame(2, $payload['truth_rank_verified']);
        $this->assertSame('trivial', $payload['runbook_ambition_trivial']);
        $this->assertSame('obra', $payload['runbook_ambition_obra']);
        $this->assertSame('agent', $payload['runbook_actor_kind_agent']);
        $this->assertSame(20, $payload['verified_share_capability_truth_ambition_floor_count']);
    }

    public function test_canary_integrity_window_rotation_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->canaryIntegrityWindowRotationFloorsContractObserve([]);

        $this->assertSame('unavailable', $payload['canary_status_unavailable']);
        $this->assertSame('canary_drift', $payload['canary_reason_drift']);
        $this->assertSame('canary_within_floors', $payload['canary_reason_within_floors']);
        $this->assertSame('chains_intact', $payload['integrity_reason_chains_intact']);
        $this->assertSame('tampered', $payload['integrity_reason_tampered']);
        $this->assertSame('dead_window', $payload['window_status_dead_window']);
        $this->assertSame('no_started_window_with_numeric_duration', $payload['window_reason_no_started_window']);
        $this->assertSame('append_forever', $payload['rotation_mode_append_forever']);
        $this->assertSame('rotate_hybrid', $payload['rotation_mode_rotate_hybrid']);
        $this->assertSame('rotate_size', $payload['rotation_mode_rotate_size']);
        $this->assertSame('ok', $payload['verified_share_status_ok']);
        $this->assertSame('below_threshold', $payload['verified_share_status_below_threshold']);
        $this->assertSame('completed', $payload['attempt_state_completed']);
        $this->assertSame('abandoned', $payload['attempt_state_abandoned']);
        $this->assertSame(20, $payload['canary_integrity_window_rotation_floor_count']);
    }

    public function test_golden_pareto_scorer_maxa04_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->goldenParetoScorerMaxa04FloorsContractObserve([]);

        $this->assertSame('skipped', $payload['golden_status_skipped']);
        $this->assertSame('paired_arms_missing', $payload['golden_reason_paired_arms_missing']);
        $this->assertSame('unmeasurable', $payload['cooccurrence_status_unmeasurable']);
        $this->assertSame('measured_share_zero', $payload['cooccurrence_reason_measured_share_zero']);
        $this->assertSame('frontier', $payload['pareto_status_frontier']);
        $this->assertSame('dominated', $payload['pareto_status_dominated']);
        $this->assertSame('passed', $payload['fidelity_verdict_passed']);
        $this->assertSame('failed', $payload['fidelity_verdict_failed']);
        $this->assertSame('complete', $payload['spec_verdict_complete']);
        $this->assertSame('insufficient', $payload['spec_verdict_insufficient']);
        $this->assertSame('shadow_only', $payload['maxa04_mode_shadow_only']);
        $this->assertSame('mechanism_ready', $payload['maxa04_status_mechanism_ready']);
        $this->assertSame('no_dual_read_cases', $payload['maxa04_status_no_dual_read_cases']);
        $this->assertSame('arc_not_active', $payload['composed_arc_reason_not_active']);
        $this->assertSame(20, $payload['golden_pareto_scorer_maxa04_floor_count']);
    }

    public function test_parallel_procedural_watchdog_residual_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->parallelProceduralWatchdogResidualFloorsContractObserve([]);

        $this->assertSame('proceed', $payload['parallel_action_proceed']);
        $this->assertSame('skip', $payload['parallel_action_skip']);
        $this->assertSame('procedural_playbook', $payload['procedural_kind_playbook']);
        $this->assertSame('held_for_evidence', $payload['procedural_status_held_for_evidence']);
        $this->assertSame('unavailable', $payload['cockpit_status_unavailable']);
        $this->assertSame('analyzed', $payload['debug_status_analyzed']);
        $this->assertSame('no_baseline', $payload['rerank_status_no_baseline']);
        $this->assertSame('simulated_fire', $payload['rollback_status_simulated_fire']);
        $this->assertSame('latency_floor_exceeded', $payload['aobg_latency_reason_floor_exceeded']);
        $this->assertSame('successful_drill_stale', $payload['substrate_reason_drill_stale']);
        $this->assertSame('accepted', $payload['esp09_outcome_accepted']);
        $this->assertSame(20, $payload['parallel_procedural_watchdog_residual_floor_count']);
    }

    public function test_lote2_decomposer_redaction_unobserved_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->lote2DecomposerRedactionUnobservedFloorsContractObserve([]);

        $this->assertSame('measure_freeze', $payload['lote2_kind_measure_freeze']);
        $this->assertSame('observe', $payload['lote2_mode_observe']);
        $this->assertSame('empty_input', $payload['decomposer_reason_empty_input']);
        $this->assertSame('no_keyword_signal', $payload['decomposer_reason_no_keyword_signal']);
        $this->assertSame('atlas_memory_entries_missing', $payload['redaction_reason_memory_entries_missing']);
        $this->assertSame('ordinary_route', $payload['esp09_decision_kind_ordinary_route']);
        $this->assertSame('active', $payload['bets_state_active']);
        $this->assertSame('succeeded', $payload['obra_outcome_succeeded']);
        $this->assertSame('failed', $payload['asef_status_failed']);
        $this->assertSame('ok', $payload['promotion_status_ok']);
        $this->assertSame('refused', $payload['composed_arc_status_refused']);
        $this->assertSame(20, $payload['lote2_decomposer_redaction_unobserved_floor_count']);
    }

    public function test_unobserved_status_basis_handoff_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->unobservedStatusBasisHandoffFloorsContractObserve([]);

        $this->assertSame('attempt_missing', $payload['attempt_reason_attempt_missing']);
        $this->assertSame('failed', $payload['composed_task_status_failed']);
        $this->assertSame('landed', $payload['composed_task_status_landed']);
        $this->assertSame('decision_kind', $payload['esp09_trigger_decision_kind']);
        $this->assertSame('evidence_turned_positive', $payload['bets_basis_evidence_turned_positive']);
        $this->assertSame('exploratory_bet_continuation_gate', $payload['bets_decision_kind_continuation_gate']);
        $this->assertSame('pending_review', $payload['obra_lesson_status_pending_review']);
        $this->assertSame('ttl_expired', $payload['evidence_thesis_death_ttl_expired']);
        $this->assertSame('delegation', $payload['choreography_handoff_kind_delegation']);
        $this->assertSame('unclassified', $payload['immune_trust_band_unclassified']);
        $this->assertSame('touching', $payload['numeric_relation_touching']);
        $this->assertSame(20, $payload['unobserved_status_basis_handoff_floor_count']);
    }

    public function test_choreography_repair_review_measure_freeze_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->choreographyRepairReviewMeasureFreezeFloorsContractObserve([]);

        $this->assertSame('repair', $payload['choreography_handoff_kind_repair']);
        $this->assertSame('review_request', $payload['choreography_handoff_kind_review_request']);
        $this->assertSame('measure_freeze', $payload['kb_embedding_kind_measure_freeze']);
        $this->assertSame('measure_freeze', $payload['code_symbol_embedding_kind_measure_freeze']);
        $this->assertSame('measure_freeze', $payload['outcome_envelope_kind_measure_freeze']);
        $this->assertSame('true', $payload['autonomy_ladder_export_bool_true']);
        $this->assertSame('false', $payload['autonomy_ladder_export_bool_false']);
        $this->assertSame('unset', $payload['autonomy_ladder_export_bool_unset']);
        $this->assertSame(8, $payload['choreography_repair_review_measure_freeze_floor_count']);
    }

    public function test_residual_error_basis_status_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->residualErrorBasisStatusFloorsContractObserve([]);

        $this->assertSame('review_delivery_veto_redirects_to_dev_forge_for_repair', $payload['veto_reason_review_delivery_repair']);
        $this->assertSame('stale_review_recommended', $payload['decay_decision_stale_review_recommended']);
        $this->assertSame('never_served_archived', $payload['composed_task_status_never_served_archived']);
        $this->assertSame('engine_ids_required', $payload['esp09_error_engine_ids_required']);
        $this->assertSame('challenger_engine_must_differ', $payload['esp09_error_challenger_engine_must_differ']);
        $this->assertSame('positive_causal_effect', $payload['bets_basis_positive_causal_effect']);
        $this->assertSame('unproven_effect', $payload['bets_basis_unproven_effect']);
        $this->assertSame('empty', $payload['ragx_status_empty']);
        $this->assertSame('ok', $payload['ragx_status_ok']);
        $this->assertSame('b_contains_a', $payload['numeric_relation_b_contains_a']);
        $this->assertSame('empty_paths', $payload['remint_reason_empty_paths']);
        $this->assertSame('rotate_age', $payload['ledger_mode_rotate_age']);
        $this->assertSame('calibrated', $payload['immune_status_calibrated']);
        $this->assertSame('ready', $payload['hmac_status_ready']);
        $this->assertSame(19, $payload['residual_error_basis_status_floor_count']);
    }

    public function test_signature_mode_suspended_unknown_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->signatureModeSuspendedUnknownFloorsContractObserve([]);

        $this->assertSame('off', $payload['immune_signature_mode_off']);
        $this->assertSame('observe', $payload['immune_signature_mode_observe']);
        $this->assertSame('enforce', $payload['immune_signature_mode_enforce']);
        $this->assertSame('suspended', $payload['obra_slice_state_suspended']);
        $this->assertSame('unknown', $payload['http_path_result_unknown']);
        $this->assertSame('operator', $payload['department_operator']);
        $this->assertSame('qa', $payload['department_qa']);
        $this->assertSame(7, $payload['signature_mode_suspended_unknown_floor_count']);
    }

    public function test_architect_verdict_freeze_ready_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->architectVerdictFreezeReadyFloorsContractObserve([]);

        $this->assertSame('architect', $payload['department_architect']);
        $this->assertSame('architect', $payload['choreography_target_architect']);
        $this->assertSame('operator', $payload['choreography_target_operator']);
        $this->assertSame('eligible', $payload['promotion_verdict_eligible']);
        $this->assertSame('blocked', $payload['promotion_verdict_blocked']);
        $this->assertSame('measure_freeze', $payload['immune_signature_freeze_kind_measure_freeze']);
        $this->assertSame('ready', $payload['cognitive_atlas_status_ready']);
        $this->assertSame('healthy', $payload['rollback_status_healthy']);
        $this->assertSame('alert', $payload['rollback_status_alert']);
        $this->assertSame(14, $payload['architect_verdict_freeze_ready_floor_count']);
    }

    public function test_mission_control_pending_partial_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->missionControlPendingPartialFloorsContractObserve([]);

        $this->assertSame('pending', $payload['mission_control_status_pending']);
        $this->assertSame('skipped', $payload['mission_control_status_skipped']);
        $this->assertSame('blocked', $payload['mission_control_status_blocked']);
        $this->assertSame('complete', $payload['mission_control_status_complete']);
        $this->assertSame('in_progress', $payload['mission_control_status_in_progress']);
        $this->assertSame('pending', $payload['reality_compiler_status_pending']);
        $this->assertSame('partial', $payload['claim_dod_state_partial']);
        $this->assertSame(7, $payload['mission_control_pending_partial_floor_count']);
    }

    public function test_volume_autonomy_coverage_unknown_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->volumeAutonomyCoverageUnknownFloorsContractObserve([]);

        $this->assertSame('healthy', $payload['operational_volume_status_healthy']);
        $this->assertSame('alert', $payload['operational_volume_status_alert']);
        $this->assertSame('pending', $payload['autonomy_status_pending']);
        $this->assertSame('failed', $payload['autonomy_status_failed']);
        $this->assertSame('complete', $payload['gate_coverage_complete']);
        $this->assertSame('incomplete', $payload['gate_coverage_incomplete']);
        $this->assertSame('unknown', $payload['http_envelope_status_unknown']);
        $this->assertSame('unknown', $payload['debug_status_unknown']);
        $this->assertSame(13, $payload['volume_autonomy_coverage_unknown_floor_count']);
    }

    public function test_watchdog_health_active_disabled_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->watchdogHealthActiveDisabledFloorsContractObserve([]);

        $this->assertSame('ok', $payload['watchdog_health_status_ok']);
        $this->assertSame('alert', $payload['watchdog_health_status_alert']);
        $this->assertSame('healthy', $payload['watchdog_health_status_healthy']);
        $this->assertSame('ready', $payload['watchdog_health_status_ready']);
        $this->assertSame('not_ready', $payload['watchdog_health_status_not_ready']);
        $this->assertSame('unavailable', $payload['watchdog_health_status_unavailable']);
        $this->assertSame('blocked', $payload['long_horizon_status_blocked']);
        $this->assertSame('disabled', $payload['long_horizon_status_disabled']);
        $this->assertSame('acos_long_horizon_ready', $payload['long_horizon_status_ready']);
        $this->assertSame('insufficient_long_horizon_evidence', $payload['long_horizon_status_insufficient']);
        $this->assertSame('active', $payload['implementation_truth_status_active']);
        $this->assertSame('building', $payload['implementation_truth_status_building']);
        $this->assertSame('pass', $payload['immune_verdict_gate_status_pass']);
        $this->assertSame('block', $payload['immune_verdict_gate_status_block']);
        $this->assertSame('pending', $payload['immune_verdict_gate_status_pending']);
        $this->assertSame('unknown', $payload['immune_verdict_writer_unknown']);
        $this->assertSame('unknown', $payload['deferred_phase_unknown']);
        $this->assertSame('unknown', $payload['dev_procedural_fallback_run_id']);
        $this->assertSame(18, $payload['watchdog_health_active_disabled_floor_count']);
    }

    public function test_embedding_pending_mission_outcome_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->embeddingPendingMissionOutcomeFloorsContractObserve([]);

        $this->assertSame('active', $payload['evidence_resolver_status_active']);
        $this->assertSame('active', $payload['kb_embedding_status_active']);
        $this->assertSame('active', $payload['code_symbol_embedding_status_active']);
        $this->assertSame('pending', $payload['asef_embedding_status_pending']);
        $this->assertSame('persisted', $payload['asef_embedding_status_persisted']);
        $this->assertSame('unknown', $payload['composed_arc_status_unknown']);
        $this->assertSame('ok', $payload['spec_completeness_reason_ok']);
        $this->assertSame('succeeded', $payload['mission_control_status_succeeded']);
        $this->assertSame('failed', $payload['mission_control_status_failed']);
        $this->assertSame('green', $payload['mission_control_outcome_green']);
        $this->assertSame('red', $payload['mission_control_outcome_red']);
        $this->assertSame('exception', $payload['mission_control_outcome_exception']);
        $this->assertSame('unknown', $payload['n_capture_trigger_unknown']);
        $this->assertSame('unknown', $payload['watchdog_runner_check_id_unknown']);
        $this->assertSame(14, $payload['embedding_pending_mission_outcome_floor_count']);
    }

    public function test_local_model_embedding_immune_unavailable_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->localModelEmbeddingImmuneUnavailableFloorsContractObserve([]);

        $this->assertSame('unknown', $payload['local_model_fallback_model_id']);
        $this->assertSame('verified', $payload['local_model_status_verified']);
        $this->assertSame('mismatched', $payload['local_model_status_mismatched']);
        $this->assertSame('ok', $payload['code_symbol_embedding_status_ok']);
        $this->assertSame('partial_coverage', $payload['code_symbol_embedding_status_partial_coverage']);
        $this->assertSame('insufficient_signal', $payload['kb_embedding_status_insufficient_signal']);
        $this->assertSame('ok', $payload['window_orchestrator_status_ok']);
        $this->assertSame('ok', $payload['recall_gap_status_ok']);
        $this->assertSame('unavailable', $payload['immune_signature_status_unavailable']);
        $this->assertSame('unavailable', $payload['lote2_basis_unavailable']);
        $this->assertSame('unknown', $payload['lote2_memory_type_unknown']);
        $this->assertSame('pending', $payload['cognitive_immune_gate_status_pending']);
        $this->assertSame('pass', $payload['cognitive_immune_gate_status_pass']);
        $this->assertSame('block', $payload['cognitive_immune_gate_status_block']);
        $this->assertSame('unknown', $payload['cognitive_immune_gate_status_unknown']);
        $this->assertSame(23, $payload['local_model_embedding_immune_unavailable_floor_count']);
    }

    public function test_prereview_parallel_flywheel_frontier_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->prereviewParallelFlywheelFrontierFloorsContractObserve([]);

        $this->assertSame('unknown', $payload['prereview_target_class_unknown']);
        $this->assertSame('unknown', $payload['parallel_engine_unknown']);
        $this->assertSame('ok', $payload['golden_counterfactual_status_ok']);
        $this->assertSame('ok', $payload['n_capture_status_ok']);
        $this->assertSame('insufficient_signal', $payload['n_capture_status_insufficient_signal']);
        $this->assertSame('ok', $payload['flywheel_status_ok']);
        $this->assertSame('no_signal', $payload['flywheel_status_no_signal']);
        $this->assertSame('insufficient', $payload['flywheel_status_insufficient']);
        $this->assertSame('unavailable', $payload['immune_hybrid_source_unavailable']);
        $this->assertSame('jaccard_baseline', $payload['immune_hybrid_source_jaccard_baseline']);
        $this->assertSame('active', $payload['frontier_activation_active']);
        $this->assertSame('aguardando_eventos', $payload['frontier_activation_aguardando_eventos']);
        $this->assertSame('complete', $payload['autonomy_field_complete']);
        $this->assertSame('pass', $payload['watchdog_health_field_pass']);
        $this->assertSame(14, $payload['prereview_parallel_flywheel_frontier_floor_count']);
    }

    public function test_obra_portfolio_pareto_blocked_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->obraPortfolioParetoBlockedFloorsContractObserve([]);

        $this->assertSame('unknown', $payload['obra_retro_status_unknown']);
        $this->assertSame('ok', $payload['portfolio_status_ok']);
        $this->assertSame('weights_reverted_to_default', $payload['portfolio_status_weights_reverted']);
        $this->assertSame('measured', $payload['portfolio_basis_measured']);
        $this->assertSame('insufficient_n', $payload['portfolio_basis_insufficient_n']);
        $this->assertSame('ok', $payload['cooccurrence_status_ok']);
        $this->assertSame('complete', $payload['lote2_field_complete']);
        $this->assertSame('blocked', $payload['pareto_field_blocked']);
        $this->assertSame('blocked', $payload['http_envelope_field_blocked']);
        $this->assertSame('alert', $payload['watchdog_check_field_alert']);
        $this->assertSame(10, $payload['obra_portfolio_pareto_blocked_floor_count']);
    }

    public function test_corpus_parallel_truth_blocked_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->corpusParallelTruthBlockedFloorsContractObserve([]);

        $this->assertSame('unknown', $payload['gated_corpus_source_unknown']);
        $this->assertSame('ok', $payload['parallel_field_ok']);
        $this->assertSame('green', $payload['implementation_truth_test_resolution_green']);
        $this->assertSame('mixed', $payload['implementation_truth_test_resolution_mixed']);
        $this->assertSame('existence_only_unrun', $payload['implementation_truth_test_resolution_existence_only_unrun']);
        $this->assertSame('blocked', $payload['http_path_field_blocked']);
        $this->assertSame('blocked', $payload['phase_advance_field_blocked']);
        $this->assertSame('partial', $payload['lote2_field_partial']);
        $this->assertSame(8, $payload['corpus_parallel_truth_blocked_floor_count']);
    }

    public function test_teto10_cockpit_ladder_promotion_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->teto10CockpitLadderPromotionFloorsContractObserve([]);

        $this->assertSame('high', $payload['teto10_band_high']);
        $this->assertSame('unknown', $payload['teto10_band_unknown']);
        $this->assertSame('ok', $payload['program_cockpit_status_ok']);
        $this->assertSame('unknown', $payload['autonomy_ladder_probe_id_unknown']);
        $this->assertSame('ok', $payload['autonomy_ladder_field_ok']);
        $this->assertSame('unknown', $payload['watchdog_health_status_unknown']);
        $this->assertSame('ok', $payload['consolidation_status_ok']);
        $this->assertSame('healthy', $payload['consolidation_status_healthy']);
        $this->assertSame('ok', $payload['promotion_field_ok']);
        $this->assertSame('ok', $payload['model_capability_status_ok']);
        $this->assertSame('violates_spec', $payload['model_capability_status_violates_spec']);
        $this->assertSame('unknown', $payload['model_capability_fallback_model_id']);
        $this->assertSame('unknown', $payload['hmac_stage_unknown']);
        $this->assertSame('blocked', $payload['autonomy_field_blocked']);
        $this->assertSame('blocked', $payload['phase_handoff_field_blocked']);
        $this->assertSame(19, $payload['teto10_cockpit_ladder_promotion_floor_count']);
    }

    public function test_dead_series_miner_signature_adapter_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->deadSeriesMinerSignatureAdapterFloorsContractObserve([]);

        $this->assertSame('ok', $payload['dead_series_status_ok']);
        $this->assertSame('stale', $payload['dead_series_status_stale']);
        $this->assertSame('missing', $payload['dead_series_status_missing']);
        $this->assertSame('mismatched', $payload['local_model_status_mismatched']);
        $this->assertSame('ok', $payload['compaction_status_ok']);
        $this->assertSame('unknown', $payload['compaction_status_unknown']);
        $this->assertSame('blocked', $payload['scorecard_grouper_status_blocked']);
        $this->assertSame('insufficient_signal', $payload['dogfooding_status_insufficient_signal']);
        $this->assertSame('empty', $payload['teto10_status_empty']);
        $this->assertSame('pending_window', $payload['immune_signature_status_pending_window']);
        $this->assertSame('blocked', $payload['deferred_status_blocked']);
        $this->assertSame('unknown', $payload['evolution_status_unknown']);
        $this->assertSame('blocked', $payload['immune_ingestor_status_blocked']);
        $this->assertSame('blocked', $payload['outcome_status_blocked']);
        $this->assertSame('ok', $payload['evidence_ledger_status_ok']);
        $this->assertSame(27, $payload['dead_series_miner_signature_adapter_floor_count']);
    }

    public function test_ragx_prereview_lote2_schema_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->ragxPrereviewLote2SchemaFloorsContractObserve([]);

        $this->assertSame('verified', $payload['ragx_status_verified']);
        $this->assertSame('ok', $payload['ragx_status_ok']);
        $this->assertSame('enabled', $payload['ragx_field_enabled']);
        $this->assertSame('pending_window', $payload['ragx_field_pending_window']);
        $this->assertSame('insufficient_sample', $payload['prereview_basis_insufficient_sample']);
        $this->assertSame('measured', $payload['prereview_basis_measured']);
        $this->assertSame('missing', $payload['promotion_field_missing']);
        $this->assertSame('unschematized', $payload['structured_fact_status_unschematized']);
        $this->assertSame('incomplete', $payload['lote2_field_incomplete']);
        $this->assertSame('success', $payload['lote2_mission_status_success']);
        $this->assertSame('needs_review', $payload['dev_procedural_native_needs_review']);
        $this->assertSame('source_unavailable', $payload['program_cockpit_reason_source_unavailable']);
        $this->assertSame('enabled', $payload['ambition_field_enabled']);
        $this->assertSame('blocked', $payload['mission_control_field_blocked']);
        $this->assertSame(23, $payload['ragx_prereview_lote2_schema_floor_count']);
    }

    public function test_window_gates_integrity_flag_disabled_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->windowGatesIntegrityFlagDisabledFloorsContractObserve([]);

        $this->assertSame('sem_dados', $payload['window_gates_status_sem_dados']);
        $this->assertSame('aguardando_janela', $payload['window_gates_status_aguardando_janela']);
        $this->assertSame('certified', $payload['window_gates_status_certified']);
        $this->assertSame('met', $payload['window_gates_status_met']);
        $this->assertSame('valid', $payload['structured_fact_status_valid']);
        $this->assertSame('missing_fields', $payload['structured_fact_status_missing_fields']);
        $this->assertSame('flag_disabled', $payload['ambition_basis_flag_disabled']);
        $this->assertSame('not_saturated', $payload['ambition_basis_not_saturated']);
        $this->assertSame('verified', $payload['local_model_field_verified']);
        $this->assertSame('mismatched', $payload['local_model_field_mismatched']);
        $this->assertSame('error', $payload['esp09_field_error']);
        $this->assertSame('enabled', $payload['outcome_bridge_field_enabled']);
        $this->assertSame('flag_disabled', $payload['composed_obra_status_flag_disabled']);
        $this->assertSame('flag_disabled', $payload['evidence_vision_status_flag_disabled']);
        $this->assertSame('pending_window', $payload['procedural_field_pending_window']);
        $this->assertSame(23, $payload['window_gates_integrity_flag_disabled_floor_count']);
    }

    public function test_obra_verified_long_horizon_enabled_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->obraVerifiedLongHorizonEnabledFloorsContractObserve([]);

        $this->assertSame('author_judge_invariant_violation', $payload['composed_obra_status_author_judge_invariant_violation']);
        $this->assertSame('invalid_dependency_graph', $payload['composed_obra_status_invalid_dependency_graph']);
        $this->assertSame('no_neighbor_cluster', $payload['composed_obra_status_no_neighbor_cluster']);
        $this->assertSame('verified', $payload['compounding_field_verified']);
        $this->assertSame('verified', $payload['aemor_field_verified']);
        $this->assertSame('valid', $payload['department_registry_field_valid']);
        $this->assertSame('enabled', $payload['long_horizon_field_enabled']);
        $this->assertSame('certified', $payload['long_horizon_field_certified']);
        $this->assertSame('enabled', $payload['rollback_field_enabled']);
        $this->assertSame('enabled', $payload['immune_hybrid_field_enabled']);
        $this->assertSame('insufficient_signal', $payload['evidence_vision_status_insufficient_signal']);
        $this->assertSame('sem_dados', $payload['window_gates_status_sem_dados']);
        $this->assertSame('measured', $payload['prereview_basis_measured']);
        $this->assertSame('blocked', $payload['mission_control_field_blocked']);
        $this->assertSame(17, $payload['obra_verified_long_horizon_enabled_floor_count']);
    }

    public function test_embedding_table_fixture_measured_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->embeddingTableFixtureMeasuredFloorsContractObserve([]);

        $this->assertSame('table_missing', $payload['code_symbol_status_table_missing']);
        $this->assertSame('table_missing', $payload['kb_embedding_status_table_missing']);
        $this->assertSame('live', $payload['long_horizon_fixture_live']);
        $this->assertSame('mature', $payload['long_horizon_fixture_mature']);
        $this->assertSame('short-window', $payload['long_horizon_fixture_short_window']);
        $this->assertSame('verified', $payload['ragx_status_verified']);
        $this->assertSame('ok', $payload['ragx_status_ok']);
        $this->assertSame('verified', $payload['compounding_field_verified']);
        $this->assertSame('verified', $payload['aemor_field_verified']);
        $this->assertSame('valid', $payload['department_registry_field_valid']);
        $this->assertSame('measured', $payload['execution_context_field_measured']);
        $this->assertSame('measured', $payload['watchdog_health_field_measured']);
        $this->assertSame('certified', $payload['watchdog_health_field_certified']);
        $this->assertSame('missing_fields', $payload['claim_dod_field_missing_fields']);
        $this->assertSame('missing', $payload['maturity_band_field_missing']);
        $this->assertSame('missing', $payload['department_runtime_field_missing']);
        $this->assertSame('enabled', $payload['immune_hybrid_field_enabled']);
        $this->assertSame(17, $payload['embedding_table_fixture_measured_floor_count']);
    }

    public function test_conflict_frontier_fixture_pending_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->conflictFrontierFixturePendingFloorsContractObserve([]);

        $this->assertSame('mature', $payload['long_horizon_fixture_mature']);
        $this->assertSame('short-window', $payload['long_horizon_fixture_short_window']);
        $this->assertSame('pending_window', $payload['maxa04_status_pending_window']);
        $this->assertSame('conflict', $payload['parallel_status_conflict']);
        $this->assertSame('ok', $payload['parallel_field_ok']);
        $this->assertSame('frontier', $payload['pareto_status_frontier']);
        $this->assertSame('dominated', $payload['pareto_status_dominated']);
        $this->assertSame('frontier', $payload['pareto_field_frontier']);
        $this->assertSame('dominated', $payload['pareto_field_dominated']);
        $this->assertSame('recorded', $payload['obra_retro_status_recorded']);
        $this->assertSame('block', $payload['immune_status_block']);
        $this->assertSame('blocked', $payload['immune_trust_band_blocked']);
        $this->assertSame('passed', $payload['compounding_status_passed']);
        $this->assertSame('absent', $payload['compounding_status_absent']);
        $this->assertSame('absent', $payload['aemor_status_absent']);
        $this->assertSame('verified', $payload['compounding_field_verified']);
        $this->assertSame('verified', $payload['aemor_field_verified']);
        $this->assertSame(17, $payload['conflict_frontier_fixture_pending_floor_count']);
    }

    public function test_queued_passed_advisory_absent_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->queuedPassedAdvisoryAbsentFloorsContractObserve([]);

        $this->assertSame('queued', $payload['remint_field_queued']);
        $this->assertSame('queued', $payload['remint_reason_queued']);
        $this->assertSame('accepted', $payload['esp09_outcome_accepted']);
        $this->assertSame('ignored', $payload['esp09_outcome_ignored']);
        $this->assertSame('advisory', $payload['esp09_mode']);
        $this->assertSame('advisory', $payload['esp09_status_advisory']);
        $this->assertSame('passed', $payload['phase_handoff_field_passed']);
        $this->assertSame('passed', $payload['gate_signal_field_passed']);
        $this->assertSame('passed', $payload['promotion_eligibility_field_passed']);
        $this->assertSame('passed', $payload['test_execution_field_passed']);
        $this->assertSame('passed', $payload['delivery_pack_status_passed']);
        $this->assertSame('absent', $payload['dev_procedural_status_absent']);
        $this->assertSame('verified', $payload['dev_procedural_field_verified']);
        $this->assertSame('proven_real', $payload['dev_procedural_field_proven_real']);
        $this->assertSame('learning_required', $payload['compounding_field_learning_required']);
        $this->assertSame('human_override', $payload['compounding_field_human_override']);
        $this->assertSame('passed', $payload['compounding_status_passed']);
        $this->assertSame(17, $payload['queued_passed_advisory_absent_floor_count']);
    }

    public function test_ran_accepted_keep_fixture_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->ranAcceptedKeepFixtureFloorsContractObserve([]);

        $this->assertSame('ran', $payload['test_execution_field_ran']);
        $this->assertSame('accepted', $payload['department_runtime_field_accepted']);
        $this->assertSame('verified', $payload['outcome_envelope_field_verified']);
        $this->assertSame('fixture', $payload['long_horizon_field_fixture']);
        $this->assertSame('queued', $payload['obra_retro_field_queued']);
        $this->assertSame('accepted', $payload['attempt_lifecycle_field_accepted']);
        $this->assertSame('keep', $payload['segment_decision_keep']);
        $this->assertSame('drop', $payload['segment_decision_drop']);
        $this->assertSame('proven_real', $payload['lote2_field_proven_real']);
        $this->assertSame('fixture', $payload['lote2_field_fixture']);
        $this->assertSame('is_fixture', $payload['lote2_field_is_fixture']);
        $this->assertSame('passed', $payload['mission_control_field_passed']);
        $this->assertSame('passed', $payload['test_execution_field_passed']);
        $this->assertSame('live', $payload['long_horizon_fixture_live']);
        $this->assertSame('started', $payload['attempt_state_started']);
        $this->assertSame('recorded', $payload['obra_retro_status_recorded']);
        $this->assertSame('missing', $payload['department_runtime_field_missing']);
        $this->assertSame(17, $payload['ran_accepted_keep_fixture_floor_count']);
    }

    public function test_immune_class_chunks_hmac_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->immuneClassChunksHmacFloorsContractObserve([]);

        $this->assertSame('trivial_query', $payload['immune_class_trivial_query']);
        $this->assertSame('prompt_injection', $payload['immune_class_prompt_injection']);
        $this->assertSame('private_sensitive', $payload['immune_class_private_sensitive']);
        $this->assertSame('project_evidence', $payload['immune_class_project_evidence']);
        $this->assertSame(11, $payload['immune_destination_count']);
        $this->assertSame('chunks_written', $payload['asef_field_chunks_written']);
        $this->assertSame('chunks_skipped', $payload['asef_field_chunks_skipped']);
        $this->assertSame('proven_real', $payload['evidence_vision_field_proven_real']);
        $this->assertSame('receipt_hash', $payload['hmac_field_receipt_hash']);
        $this->assertSame('broken_at', $payload['hmac_field_broken_at']);
        $this->assertSame('promotion_allowed', $payload['procedural_field_promotion_allowed']);
        $this->assertSame('verified_basis', $payload['compounding_field_verified_basis']);
        $this->assertSame('verified_basis', $payload['aemor_field_verified_basis']);
        $this->assertSame('ok', $payload['hmac_field_ok']);
        $this->assertSame('ok', $payload['asef_status_ok']);
        $this->assertSame('untrusted_content', $payload['immune_class_untrusted_content']);
        $this->assertSame(3, $payload['immune_embedding_forbidden_count']);
        $this->assertSame(17, $payload['immune_class_chunks_hmac_floor_count']);
    }

    public function test_rotation_measure_series_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->rotationMeasureSeriesFloorsContractObserve([]);

        $this->assertSame('max_size_mb', $payload['rotation_field_max_size_mb']);
        $this->assertSame('max_age_days', $payload['rotation_field_max_age_days']);
        $this->assertSame('mode', $payload['rotation_field_mode']);
        $this->assertSame('rationale', $payload['rotation_field_rationale']);
        $this->assertSame('append_forever', $payload['rotation_mode_append_forever']);
        $this->assertSame('rotate_hybrid', $payload['rotation_mode_rotate_hybrid']);
        $this->assertSame('series', $payload['measure_field_series']);
        $this->assertSame('ttl_days', $payload['measure_field_ttl_days']);
        $this->assertSame('source_type', $payload['measure_field_source_type']);
        $this->assertSame('timestamp_field', $payload['measure_field_timestamp_field']);
        $this->assertSame('ttl_source', $payload['measure_field_ttl_source']);
        $this->assertSame('jsonl', $payload['measure_source_type_jsonl']);
        $this->assertSame('table', $payload['measure_source_type_table']);
        $this->assertSame('command', $payload['measure_source_type_command']);
        $this->assertSame('jsonl_dir', $payload['measure_source_type_jsonl_dir']);
        $this->assertSame('computed_reader_field', $payload['measure_source_type_computed_reader_field']);
        $this->assertSame('slice', $payload['measure_field_slice']);
        $this->assertSame(17, $payload['rotation_measure_series_floor_count']);
    }

    public function test_promotion_lote2_measure_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->promotionLote2MeasureFloorsContractObserve([]);

        $this->assertSame('family', $payload['promotion_field_family']);
        $this->assertSame('state', $payload['promotion_field_state']);
        $this->assertSame('judge_engine_id', $payload['promotion_field_judge_engine_id']);
        $this->assertSame('author_engine_id', $payload['promotion_field_author_engine_id']);
        $this->assertSame('receipt', $payload['promotion_field_receipt']);
        $this->assertSame('to_state', $payload['promotion_field_to_state']);
        $this->assertSame(5, $payload['promotion_required_field_count']);
        $this->assertSame('off', $payload['promotion_state_off']);
        $this->assertSame('measure_id', $payload['lote2_field_measure_id']);
        $this->assertSame('formula_version', $payload['lote2_field_formula_version']);
        $this->assertSame('denominator_min', $payload['lote2_field_denominator_min']);
        $this->assertSame('kind', $payload['lote2_field_kind']);
        $this->assertSame('status', $payload['lote2_field_status']);
        $this->assertSame('measured', $payload['lote2_status_measured']);
        $this->assertSame('slice', $payload['lote2_field_slice']);
        $this->assertSame('n_pairs', $payload['lote2_field_n_pairs']);
        $this->assertSame('schema_version', $payload['lote2_field_schema_version']);
        $this->assertSame(17, $payload['promotion_lote2_measure_floor_count']);
    }


    public function test_department_contract_maturity_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->departmentContractMaturityFloorsContractObserve([]);

        $this->assertSame('name', $payload['department_field_name']);
        $this->assertSame('schema', $payload['department_field_schema']);
        $this->assertSame('scope', $payload['department_field_scope']);
        $this->assertSame('gates', $payload['department_field_gates']);
        $this->assertSame('inputs', $payload['department_field_inputs']);
        $this->assertSame('outputs', $payload['department_field_outputs']);
        $this->assertSame('maturity_level', $payload['department_field_maturity_level']);
        $this->assertSame('emits_handoff_to', $payload['department_field_emits_handoff_to']);
        $this->assertSame('atlas.aaeos.department.v1', $payload['department_schema_version']);
        $this->assertSame('department_id', $payload['maturity_field_department_id']);
        $this->assertSame('current_level', $payload['maturity_field_current_level']);
        $this->assertSame('evidence', $payload['maturity_field_evidence']);
        $this->assertSame('blocker_id', $payload['maturity_field_blocker_id']);
        $this->assertSame('blocker_summary', $payload['maturity_field_blocker_summary']);
        $this->assertSame('blocker_severity', $payload['maturity_field_blocker_severity']);
        $this->assertSame('atlas.aaeos.department_maturity.v1', $payload['maturity_schema_version']);
        $this->assertSame('atlas-ai', $payload['maturity_owner']);
        $this->assertSame(17, $payload['department_contract_maturity_floor_count']);
    }


    public function test_quality_veto_evolution_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->qualityVetoEvolutionFloorsContractObserve([]);

        $this->assertSame('department', $payload['quality_field_department']);
        $this->assertSame('threshold', $payload['quality_field_threshold']);
        $this->assertSame('current', $payload['quality_field_current']);
        $this->assertSame('deficit', $payload['quality_field_deficit']);
        $this->assertSame('atlas.aaeos.quality_bar.v1', $payload['quality_schema_version']);
        $this->assertSame('resolution', $payload['veto_field_resolution']);
        $this->assertSame('pause_set', $payload['veto_field_pause_set']);
        $this->assertSame('redirect_to', $payload['veto_field_redirect_to']);
        $this->assertSame('propagate_pause', $payload['veto_resolution_propagate_pause']);
        $this->assertSame('no_match', $payload['veto_resolution_no_match']);
        $this->assertSame('atlas.aaeos.veto_propagation.v1', $payload['veto_schema_version']);
        $this->assertSame('evidence', $payload['evolution_field_evidence']);
        $this->assertSame('points', $payload['evolution_field_points']);
        $this->assertSame('score', $payload['evolution_field_score']);
        $this->assertSame('signal', $payload['evolution_field_signal']);
        $this->assertSame('implemented', $payload['evolution_field_implemented']);
        $this->assertSame('atlas.cognition.evolution_score.v1', $payload['evolution_schema_version']);
        $this->assertSame(17, $payload['quality_veto_evolution_floor_count']);
    }


    public function test_watchdog_immune_ragx_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->watchdogImmuneRagxFloorsContractObserve([]);

        $this->assertSame('status', $payload['watchdog_field_status']);
        $this->assertSame('blocking', $payload['watchdog_field_blocking']);
        $this->assertSame('schema_version', $payload['watchdog_field_schema_version']);
        $this->assertSame('generated_at', $payload['watchdog_field_generated_at']);
        $this->assertSame('thresholds', $payload['watchdog_field_thresholds']);
        $this->assertSame('ok', $payload['watchdog_status_ok']);
        $this->assertSame('status', $payload['immune_field_status']);
        $this->assertSame('signature', $payload['immune_field_signature']);
        $this->assertSame('hostile_class', $payload['immune_field_hostile_class']);
        $this->assertSame('hit_count', $payload['immune_field_hit_count']);
        $this->assertSame('active', $payload['immune_status_active']);
        $this->assertSame('atlas.cognition.immune_signature_store.v1', $payload['immune_schema_version']);
        $this->assertSame('status', $payload['ragx_field_status']);
        $this->assertSame('slice', $payload['ragx_field_slice']);
        $this->assertSame('ab_green_claimed', $payload['ragx_field_ab_green_claimed']);
        $this->assertSame('schema_version', $payload['ragx_field_schema_version']);
        $this->assertSame('atlas.acos_max.ragx_chain_mechanisms.v1', $payload['ragx_schema']);
        $this->assertSame(17, $payload['watchdog_immune_ragx_floor_count']);
    }


    public function test_obra_evidence_http_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->obraEvidenceHttpFloorsContractObserve([]);

        $this->assertSame('status', $payload['obra_field_status']);
        $this->assertSame('consecutive_failures', $payload['obra_field_consecutive_failures']);
        $this->assertSame('kill_gate_k', $payload['obra_field_kill_gate_k']);
        $this->assertSame('arc_id', $payload['obra_field_arc_id']);
        $this->assertSame('active', $payload['obra_status_active']);
        $this->assertSame('source', $payload['evidence_field_source']);
        $this->assertSame('claim', $payload['evidence_field_claim']);
        $this->assertSame('death_criterion', $payload['evidence_field_death_criterion']);
        $this->assertSame('proven_real', $payload['evidence_field_proven_real']);
        $this->assertSame('atlas.originator.evidence_vision_thesis.v1', $payload['evidence_schema_version']);
        $this->assertSame('intent_hash', $payload['http_field_intent_hash']);
        $this->assertSame('severity', $payload['http_field_severity']);
        $this->assertSame('owner', $payload['http_field_owner']);
        $this->assertSame('phase_in', $payload['http_field_phase_in']);
        $this->assertSame('skip_reason', $payload['http_field_skip_reason']);
        $this->assertSame('blocked', $payload['http_field_blocked']);
        $this->assertSame('r1_r2_fast_path', $payload['http_risk_band_fast_path']);
        $this->assertSame(17, $payload['obra_evidence_http_floor_count']);
    }


    public function test_teto_cognitive_hmac_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->tetoCognitiveHmacFloorsContractObserve([]);

        $this->assertSame('group_key', $payload['teto_field_group_key']);
        $this->assertSame('decision_id', $payload['teto_field_decision_id']);
        $this->assertSame('predicted_revert_band', $payload['teto_field_predicted_revert_band']);
        $this->assertSame('high', $payload['teto_band_high']);
        $this->assertSame('ok', $payload['teto_status_ok']);
        $this->assertSame('group', $payload['cognitive_field_group']);
        $this->assertSame('subsystems', $payload['cognitive_field_subsystems']);
        $this->assertSame('declared_ready', $payload['cognitive_field_declared_ready']);
        $this->assertSame('evidence_files_seen', $payload['cognitive_field_evidence_files_seen']);
        $this->assertSame('atlas.cognitive_function_atlas.self_model.v1', $payload['cognitive_self_model_schema']);
        $this->assertSame('status', $payload['hmac_field_status']);
        $this->assertSame('head_receipt_hash', $payload['hmac_field_head_receipt_hash']);
        $this->assertSame('stages', $payload['hmac_field_stages']);
        $this->assertSame('stage_count', $payload['hmac_field_stage_count']);
        $this->assertSame('receipt_hash', $payload['hmac_field_receipt_hash']);
        $this->assertSame('ok', $payload['hmac_field_ok']);
        $this->assertSame('atlas.capture.hmac_lineage.v1', $payload['hmac_schema_version']);
        $this->assertSame(17, $payload['teto_cognitive_hmac_floor_count']);
    }


    public function test_promotion_asef_autonomy_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->promotionAsefAutonomyFloorsContractObserve([]);

        $this->assertSame('slice', $payload['promotion_field_slice']);
        $this->assertSame('config_key', $payload['promotion_field_config_key']);
        $this->assertSame('env_key', $payload['promotion_field_env_key']);
        $this->assertSame('status', $payload['promotion_field_status']);
        $this->assertSame('ok', $payload['promotion_status_ok']);
        $this->assertSame('status', $payload['asef_field_status']);
        $this->assertSame('source_ref', $payload['asef_field_source_ref']);
        $this->assertSame('chunk_hash', $payload['asef_field_chunk_hash']);
        $this->assertSame('chunk_id', $payload['asef_field_chunk_id']);
        $this->assertSame('similarity', $payload['asef_field_similarity']);
        $this->assertSame('chunks_written', $payload['asef_field_chunks_written']);
        $this->assertSame('refused', $payload['autonomy_field_refused']);
        $this->assertSame('observed', $payload['autonomy_field_observed']);
        $this->assertSame('expected', $payload['autonomy_field_expected']);
        $this->assertSame('reason', $payload['autonomy_field_reason']);
        $this->assertSame('maxk-09.autonomy_ladder_adversarial', $payload['autonomy_check_id']);
        $this->assertSame('atlas.acos.watchdog.autonomy_ladder_adversarial.v1', $payload['autonomy_schema']);
        $this->assertSame(17, $payload['promotion_asef_autonomy_floor_count']);
    }

    public function test_longhorizon_window_aemor_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->longhorizonWindowAemorFloorsContractObserve([]);

        $this->assertSame('min_overall', $payload['longhorizon_field_min_overall']);
        $this->assertSame('date', $payload['longhorizon_field_date']);
        $this->assertSame('blockers', $payload['longhorizon_field_blockers']);
        $this->assertSame('warnings', $payload['longhorizon_field_warnings']);
        $this->assertSame('series_day_count', $payload['longhorizon_field_series_day_count']);
        $this->assertSame('latest_date', $payload['longhorizon_field_latest_date']);
        $this->assertSame('acos_long_horizon_ready', $payload['longhorizon_status_ready']);
        $this->assertSame('slice', $payload['window_field_slice']);
        $this->assertSame('days_remaining', $payload['window_field_days_remaining']);
        $this->assertSame('flag_id', $payload['window_field_flag_id']);
        $this->assertSame('observation_window_id', $payload['window_field_observation_window_id']);
        $this->assertSame('status', $payload['window_field_status']);
        $this->assertSame('ok', $payload['window_status_ok']);
        $this->assertSame('executor', $payload['aemor_field_executor']);
        $this->assertSame('summary', $payload['aemor_field_summary']);
        $this->assertSame('outcome_type', $payload['aemor_field_outcome_type']);
        $this->assertSame('aemor', $payload['aemor_adapter_kind']);
        $this->assertSame(17, $payload['longhorizon_window_aemor_floor_count']);
    }

    public function test_test_immune_truth_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->testImmuneTruthFloorsContractObserve([]);

        $this->assertSame('runner', $payload['test_field_runner']);
        $this->assertSame('exit_code', $payload['test_field_exit_code']);
        $this->assertSame('tests_run', $payload['test_field_tests_run']);
        $this->assertSame('output_tail', $payload['test_field_output_tail']);
        $this->assertSame('reason', $payload['test_field_reason']);
        $this->assertSame('test_file_hash', $payload['test_field_test_file_hash']);
        $this->assertSame('atlas.aaeos.test_run_receipt.v1', $payload['test_schema']);
        $this->assertSame('sample_label', $payload['immune_field_sample_label']);
        $this->assertSame('promotion_status', $payload['immune_field_promotion_status']);
        $this->assertSame('pending_gate_ids', $payload['immune_field_pending_gate_ids']);
        $this->assertSame('gate_statuses', $payload['immune_field_gate_statuses']);
        $this->assertSame('blocking_gate_ids', $payload['immune_field_blocking_gate_ids']);
        $this->assertSame('atlas.cognition.immune_verdict_ledger.v1', $payload['immune_schema_version']);
        $this->assertSame('resolved', $payload['truth_field_resolved']);
        $this->assertSame('evidence_refs', $payload['truth_field_evidence_refs']);
        $this->assertSame('implementation_state', $payload['truth_field_implementation_state']);
        $this->assertSame('drift', $payload['truth_field_drift']);
        $this->assertSame(17, $payload['test_immune_truth_floor_count']);
    }

    public function test_model_causality_skill_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->modelCausalitySkillFloorsContractObserve([]);

        $this->assertSame('reason', $payload['model_field_reason']);
        $this->assertSame('field', $payload['model_field_field']);
        $this->assertSame('expected', $payload['model_field_expected']);
        $this->assertSame('actual', $payload['model_field_actual']);
        $this->assertSame('status', $payload['model_field_status']);
        $this->assertSame('ok', $payload['model_status_ok']);
        $this->assertSame('weight', $payload['causality_field_weight']);
        $this->assertSame('cause', $payload['causality_field_cause']);
        $this->assertSame('order', $payload['causality_field_order']);
        $this->assertSame('outcome', $payload['causality_field_outcome']);
        $this->assertSame('atlas.aaeos.outcome_causality_ranking.v1', $payload['causality_schema_version']);
        $this->assertSame('case_count', $payload['skill_field_case_count']);
        $this->assertSame('task_category', $payload['skill_field_task_category']);
        $this->assertSame('candidate_hash', $payload['skill_field_candidate_hash']);
        $this->assertSame('admission_door', $payload['skill_field_admission_door']);
        $this->assertSame('case_count_floor', $payload['skill_field_case_count_floor']);
        $this->assertSame('ok', $payload['skill_status_ok']);
        $this->assertSame(17, $payload['model_causality_skill_floor_count']);
    }

    public function test_choreography_hybrid_dev_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->choreographyHybridDevFloorsContractObserve([]);

        $this->assertSame('action', $payload['choreography_field_action']);
        $this->assertSame('return_to', $payload['choreography_field_return_to']);
        $this->assertSame('propagates_to', $payload['choreography_field_propagates_to']);
        $this->assertSame('final', $payload['choreography_field_final']);
        $this->assertSame('kind', $payload['choreography_field_kind']);
        $this->assertSame('atlas.aaeos.cross_dept.handoff.v1', $payload['choreography_handoff_schema']);
        $this->assertSame('input_class', $payload['hybrid_field_input_class']);
        $this->assertSame('winner_source', $payload['hybrid_field_winner_source']);
        $this->assertSame('matched_signals', $payload['hybrid_field_matched_signals']);
        $this->assertSame('immune_signature', $payload['hybrid_field_immune_signature']);
        $this->assertSame('hybrid_arm', $payload['hybrid_field_hybrid_arm']);
        $this->assertSame('jaccard_baseline', $payload['hybrid_source_jaccard']);
        $this->assertSame('outcome_status', $payload['dev_field_outcome_status']);
        $this->assertSame('selected_tests', $payload['dev_field_selected_tests']);
        $this->assertSame('run_id', $payload['dev_field_run_id']);
        $this->assertSame('proof_reason', $payload['dev_field_proof_reason']);
        $this->assertSame('dev_procedural', $payload['dev_adapter_kind']);
        $this->assertSame(17, $payload['choreography_hybrid_dev_floor_count']);
    }

    public function test_compounding_scorecard_canary_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->compoundingScorecardCanaryFloorsContractObserve([]);

        $this->assertSame('run_id', $payload['compounding_field_run_id']);
        $this->assertSame('retrieval_quality', $payload['compounding_field_retrieval_quality']);
        $this->assertSame('missed_signals', $payload['compounding_field_missed_signals']);
        $this->assertSame('flow_quality', $payload['compounding_field_flow_quality']);
        $this->assertSame('execution_quality', $payload['compounding_field_execution_quality']);
        $this->assertSame('compounding', $payload['compounding_adapter_kind']);
        $this->assertSame('evidence_alias_of', $payload['scorecard_field_evidence_alias_of']);
        $this->assertSame('acronym', $payload['scorecard_field_acronym']);
        $this->assertSame('score_out_of_10', $payload['scorecard_field_score_out_of_10']);
        $this->assertSame('pipeline_status', $payload['scorecard_field_pipeline_status']);
        $this->assertSame('doc_status', $payload['scorecard_field_doc_status']);
        $this->assertSame('atlas.cognition.scorecard.v3', $payload['scorecard_schema_version']);
        $this->assertSame('recall_at_5', $payload['canary_field_recall_at_5']);
        $this->assertSame('improper_floor_discards', $payload['canary_field_improper_floor_discards']);
        $this->assertSame('refs_total', $payload['canary_field_refs_total']);
        $this->assertSame('flows_checked', $payload['canary_field_flows_checked']);
        $this->assertSame('atlas.acos.watchdog.daily_canary_replay_by_refs.v1', $payload['canary_schema_version']);
        $this->assertSame(17, $payload['compounding_scorecard_canary_floor_count']);
    }

    public function test_obra_lote2_health_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->obraLote2HealthFloorsContractObserve([]);

        $this->assertSame('status', $payload['obra_field_status']);
        $this->assertSame('state', $payload['obra_field_state']);
        $this->assertSame('kind', $payload['obra_field_kind']);
        $this->assertSame('evidence_refs', $payload['obra_field_evidence_refs']);
        $this->assertSame('series_tag', $payload['obra_field_series_tag']);
        $this->assertSame('atlas.acos_max.obra_retro.v1', $payload['obra_schema_version']);
        $this->assertSame('never_delivered', $payload['lote2_field_never_delivered']);
        $this->assertSame('never_cited', $payload['lote2_field_never_cited']);
        $this->assertSame('rows', $payload['lote2_field_rows']);
        $this->assertSame('freeze', $payload['lote2_field_freeze']);
        $this->assertSame('delivered', $payload['lote2_field_delivered']);
        $this->assertSame('atlas.acos.lote2.measure_report.v1', $payload['lote2_report_schema']);
        $this->assertSame('report_method', $payload['health_field_report_method']);
        $this->assertSame('alert_code', $payload['health_field_alert_code']);
        $this->assertSame('message', $payload['health_field_message']);
        $this->assertSame('id', $payload['health_field_id']);
        $this->assertSame(10, $payload['health_catalog_count']);
        $this->assertSame(17, $payload['obra_lote2_health_floor_count']);
    }

    public function test_volume_cockpit_rollback_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->volumeCockpitRollbackFloorsContractObserve([]);

        $this->assertSame('available', $payload['volume_field_available']);
        $this->assertSame('count', $payload['volume_field_count']);
        $this->assertSame('sources', $payload['volume_field_sources']);
        $this->assertSame('status', $payload['volume_field_status']);
        $this->assertSame('healthy', $payload['volume_status_healthy']);
        $this->assertSame('atlas.acos.operational_volume.v1', $payload['volume_schema_version']);
        $this->assertSame('status', $payload['cockpit_field_status']);
        $this->assertSame('source', $payload['cockpit_field_source']);
        $this->assertSame('payload', $payload['cockpit_field_payload']);
        $this->assertSame('lines', $payload['cockpit_field_lines']);
        $this->assertSame('ok', $payload['cockpit_status_ok']);
        $this->assertSame('atlas.acos.cockpit.v1', $payload['cockpit_schema_version']);
        $this->assertSame('slices', $payload['rollback_field_slices']);
        $this->assertSame('rollback_action', $payload['rollback_field_rollback_action']);
        $this->assertSame('executor', $payload['rollback_field_executor']);
        $this->assertSame('status', $payload['rollback_field_status']);
        $this->assertSame('atlas.acos.rollback_triggers.v1', $payload['rollback_schema_version']);
        $this->assertSame(17, $payload['volume_cockpit_rollback_floor_count']);
    }

    public function test_arc_segment_window_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->arcSegmentWindowFloorsContractObserve([]);

        $this->assertSame('target_path', $payload['arc_field_target_path']);
        $this->assertSame('organ_class', $payload['arc_field_organ_class']);
        $this->assertSame('leverage', $payload['arc_field_leverage']);
        $this->assertSame('status', $payload['arc_field_status']);
        $this->assertSame('arc_id', $payload['arc_field_arc_id']);
        $this->assertSame('atlas.originator.composed_obra_arc.v1', $payload['arc_schema_version']);
        $this->assertSame('score', $payload['segment_field_score']);
        $this->assertSame('recency_rank', $payload['segment_field_recency_rank']);
        $this->assertSame('kind_weight', $payload['segment_field_kind_weight']);
        $this->assertSame('decision', $payload['segment_field_decision']);
        $this->assertSame('keep', $payload['segment_decision_keep']);
        $this->assertSame('atlas.aaeos.segment_importance_ranking.v1', $payload['segment_schema_version']);
        $this->assertSame('status', $payload['window_field_status']);
        $this->assertSame('gate', $payload['window_field_gate']);
        $this->assertSame('certified', $payload['window_field_certified']);
        $this->assertSame('met', $payload['window_status_met']);
        $this->assertSame('atlas.cognition.window_gates.v1', $payload['window_schema_version']);
        $this->assertSame(17, $payload['arc_segment_window_floor_count']);
    }

    public function test_department_integrity_capture_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->departmentIntegrityCaptureFloorsContractObserve([]);

        $this->assertSame('accepts_handoff_from', $payload['department_field_accepts_handoff_from']);
        $this->assertSame('reason', $payload['department_field_reason']);
        $this->assertSame('status', $payload['department_field_status']);
        $this->assertSame('name', $payload['department_field_name']);
        $this->assertSame('schema', $payload['department_field_schema']);
        $this->assertSame('emits_handoff_to', $payload['department_field_emits_handoff_to']);
        $this->assertSame('status', $payload['integrity_field_status']);
        $this->assertSame('reason', $payload['integrity_field_reason']);
        $this->assertSame('model_id', $payload['integrity_field_model_id']);
        $this->assertSame('verified', $payload['integrity_status_verified']);
        $this->assertSame('atlas.model_integrity_manifest.v1', $payload['integrity_manifest_schema']);
        $this->assertSame('reason', $payload['capture_field_reason']);
        $this->assertSame('field', $payload['capture_field_field']);
        $this->assertSame('status', $payload['capture_field_status']);
        $this->assertSame('expected', $payload['capture_field_expected']);
        $this->assertSame('actual', $payload['capture_field_actual']);
        $this->assertSame('atlas.acos_max.n_capture_drill.v1', $payload['capture_schema_version']);
        $this->assertSame(17, $payload['department_integrity_capture_floor_count']);
    }

    public function test_asef_calibration_jina_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->asefCalibrationJinaFloorsContractObserve([]);

        $this->assertSame('reason', $payload['asef_field_reason']);
        $this->assertSame('title', $payload['asef_field_title']);
        $this->assertSame('section', $payload['asef_field_section']);
        $this->assertSame('status', $payload['asef_field_status']);
        $this->assertSame('documents', $payload['asef_field_documents']);
        $this->assertSame('ok', $payload['asef_status_ok']);
        $this->assertSame('atlas.asef_chunks.index.v1', $payload['asef_schema_version']);
        $this->assertSame('status', $payload['calibration_field_status']);
        $this->assertSame('band', $payload['calibration_field_band']);
        $this->assertSame('missed_poison_rate', $payload['calibration_field_missed_poison_rate']);
        $this->assertSame('calibration_status', $payload['calibration_field_calibration_status']);
        $this->assertSame('calibrated', $payload['calibration_status_calibrated']);
        $this->assertSame('atlas.cognition.immune_calibration.v1', $payload['calibration_schema_version']);
        $this->assertSame('status', $payload['jina_field_status']);
        $this->assertSame('cases', $payload['jina_field_cases']);
        $this->assertSame('slice', $payload['jina_field_slice']);
        $this->assertSame('pending_window', $payload['jina_status_pending_window']);
        $this->assertSame(17, $payload['asef_calibration_jina_floor_count']);
    }

    public function test_ledger_counterfactual_advisory_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->ledgerCounterfactualAdvisoryFloorsContractObserve([]);

        $this->assertSame('status', $payload['ledger_field_status']);
        $this->assertSame('reason', $payload['ledger_field_reason']);
        $this->assertSame('gap_count', $payload['ledger_field_gap_count']);
        $this->assertSame('tampered_event_ids', $payload['ledger_field_tampered_event_ids']);
        $this->assertSame('ok', $payload['ledger_status_ok']);
        $this->assertSame('atlas.acos.watchdog.evidence_ledger_integrity.v1', $payload['ledger_schema_version']);
        $this->assertSame('status', $payload['golden_field_status']);
        $this->assertSame('reason', $payload['golden_field_reason']);
        $this->assertSame('decision_id', $payload['golden_field_decision_id']);
        $this->assertSame('counterfactual', $payload['golden_field_counterfactual']);
        $this->assertSame('recall_at_5', $payload['golden_field_recall_at_5']);
        $this->assertSame('ok', $payload['golden_status_ok']);
        $this->assertSame('atlas.context.golden_counterfactual.v1', $payload['golden_schema_version']);
        $this->assertSame('predicted_revert_band', $payload['advisory_field_predicted_revert_band']);
        $this->assertSame('basis', $payload['advisory_field_basis']);
        $this->assertSame('realized_revert_rate', $payload['advisory_field_realized_revert_rate']);
        $this->assertSame('measured', $payload['advisory_basis_measured']);
        $this->assertSame(17, $payload['ledger_counterfactual_advisory_floor_count']);
    }

    public function test_verified_frontier_cooccurrence_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->verifiedFrontierCooccurrenceFloorsContractObserve([]);

        $this->assertSame('status', $payload['verified_field_status']);
        $this->assertSame('reason', $payload['verified_field_reason']);
        $this->assertSame('measure_id', $payload['verified_field_measure_id']);
        $this->assertSame('thresholds', $payload['verified_field_thresholds']);
        $this->assertSame('ok', $payload['verified_status_ok']);
        $this->assertSame('atlas.acos_max.verified_share.v1', $payload['verified_schema_version']);
        $this->assertSame('key', $payload['frontier_field_key']);
        $this->assertSame('wave', $payload['frontier_field_wave']);
        $this->assertSame('activation', $payload['frontier_field_activation']);
        $this->assertSame('waves', $payload['frontier_field_waves']);
        $this->assertSame('active', $payload['frontier_activation_active']);
        $this->assertSame('atlas.cognition.frontier_ladder.v1', $payload['frontier_schema_version']);
        $this->assertSame('status', $payload['cooccurrence_field_status']);
        $this->assertSame('reason', $payload['cooccurrence_field_reason']);
        $this->assertSame('measured', $payload['cooccurrence_field_measured']);
        $this->assertSame('measured_share', $payload['cooccurrence_field_measured_share']);
        $this->assertSame('ok', $payload['cooccurrence_status_ok']);
        $this->assertSame(17, $payload['verified_frontier_cooccurrence_floor_count']);
    }

    public function test_docs_handoff_adversarial_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->docsHandoffAdversarialFloorsContractObserve([]);

        $this->assertSame('needle', $payload['docs_field_needle']);
        $this->assertSame('owner_doc_path', $payload['docs_field_owner_doc_path']);
        $this->assertSame('confidence', $payload['docs_field_confidence']);
        $this->assertSame('owner_basis', $payload['docs_field_owner_basis']);
        $this->assertSame('candidates', $payload['docs_field_candidates']);
        $this->assertSame('atlas.docs.authority_graph.v1', $payload['docs_schema_version']);
        $this->assertSame('actor', $payload['handoff_field_actor']);
        $this->assertSame('kind', $payload['handoff_field_kind']);
        $this->assertSame('gates', $payload['handoff_field_gates']);
        $this->assertSame('blocked', $payload['handoff_field_blocked']);
        $this->assertSame('passed', $payload['handoff_field_passed']);
        $this->assertSame('atlas.aaeos.phase.v1', $payload['handoff_schema_version']);
        $this->assertSame('status', $payload['adversarial_field_status']);
        $this->assertSame('reason', $payload['adversarial_field_reason']);
        $this->assertSame('refusal_reason', $payload['adversarial_field_refusal_reason']);
        $this->assertSame('requested_autonomy', $payload['adversarial_field_requested_autonomy']);
        $this->assertSame('passed', $payload['adversarial_field_passed']);
        $this->assertSame(17, $payload['docs_handoff_adversarial_floor_count']);
    }

    public function test_immune_ragx_scorecard_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->immuneRagxScorecardFloorsContractObserve([]);

        $this->assertSame('origin_kind', $payload['immune_field_origin_kind']);
        $this->assertSame('reverse_handle', $payload['immune_field_reverse_handle']);
        $this->assertSame('measure_id', $payload['immune_field_measure_id']);
        $this->assertSame('active_cells', $payload['immune_field_active_cells']);
        $this->assertSame('metadata', $payload['immune_field_metadata']);
        $this->assertSame('status', $payload['immune_field_status']);
        $this->assertSame('blocked_by', $payload['ragx_field_blocked_by']);
        $this->assertSame('communities', $payload['ragx_field_communities']);
        $this->assertSame('nodes', $payload['ragx_field_nodes']);
        $this->assertSame('mode', $payload['ragx_field_mode']);
        $this->assertSame('score', $payload['ragx_field_score']);
        $this->assertSame('status', $payload['ragx_field_status']);
        $this->assertSame('schema_version', $payload['scorecard_field_schema_version']);
        $this->assertSame('score', $payload['scorecard_field_score']);
        $this->assertSame('modules', $payload['scorecard_field_modules']);
        $this->assertSame('scorecard_hash', $payload['scorecard_field_scorecard_hash']);
        $this->assertSame('overall', $payload['scorecard_field_overall']);
        $this->assertSame(17, $payload['immune_ragx_scorecard_floor_count']);
    }

    public function test_http_thesis_lote2_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->httpThesisLote2FloorsContractObserve([]);

        $this->assertSame('intent_id', $payload['http_field_intent_id']);
        $this->assertSame('reason', $payload['http_field_reason']);
        $this->assertSame('envelopes', $payload['http_field_envelopes']);
        $this->assertSame('placement', $payload['http_field_placement']);
        $this->assertSame('status', $payload['http_field_status']);
        $this->assertSame('blocked', $payload['http_field_blocked']);
        $this->assertSame('ref', $payload['thesis_field_ref']);
        $this->assertSame('thesis_id', $payload['thesis_field_thesis_id']);
        $this->assertSame('kind', $payload['thesis_field_kind']);
        $this->assertSame('author_engine_id', $payload['thesis_field_author_engine_id']);
        $this->assertSame('claim', $payload['thesis_field_claim']);
        $this->assertSame('atlas.originator.evidence_vision_thesis.v1', $payload['thesis_schema_version']);
        $this->assertSame('read_only', $payload['lote2_field_read_only']);
        $this->assertSame('delivery_p50', $payload['lote2_field_delivery_p50']);
        $this->assertSame('citation_p95', $payload['lote2_field_citation_p95']);
        $this->assertSame('memory_written', $payload['lote2_field_memory_written']);
        $this->assertSame('status', $payload['lote2_field_status']);
        $this->assertSame(17, $payload['http_thesis_lote2_floor_count']);
    }

    public function test_longhorizon_watchdog_promotion_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->longhorizonWatchdogPromotionFloorsContractObserve([]);

        $this->assertSame('min_pipeline', $payload['longhorizon_field_min_pipeline']);
        $this->assertSame('max_latest_stale_days', $payload['longhorizon_field_max_latest_stale_days']);
        $this->assertSame('max_gap_days', $payload['longhorizon_field_max_gap_days']);
        $this->assertSame('series_path', $payload['longhorizon_field_series_path']);
        $this->assertSame('calendar_span_days', $payload['longhorizon_field_calendar_span_days']);
        $this->assertSame('details', $payload['longhorizon_field_details']);
        $this->assertSame('raw', $payload['watchdog_field_raw']);
        $this->assertSame('id', $payload['watchdog_field_id']);
        $this->assertSame('total_event_count', $payload['watchdog_field_total_event_count']);
        $this->assertSame('window', $payload['watchdog_field_window']);
        $this->assertSame('false_positive_total', $payload['watchdog_field_false_positive_total']);
        $this->assertSame('false_positive_rate', $payload['watchdog_field_false_positive_rate']);
        $this->assertSame('false_positive_rate_threshold', $payload['watchdog_field_false_positive_rate_threshold']);
        $this->assertSame('max_events', $payload['watchdog_field_max_events']);
        $this->assertSame('id', $payload['promotion_field_id']);
        $this->assertSame('schema_version', $payload['promotion_field_schema_version']);
        $this->assertSame('reason', $payload['promotion_field_reason']);
        $this->assertSame(17, $payload['longhorizon_watchdog_promotion_floor_count']);
    }

    public function test_esp09_lote2_hmac_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->esp09Lote2HmacFloorsContractObserve([]);

        $this->assertSame('status', $payload['esp09_field_status']);
        $this->assertSame('schema_version', $payload['esp09_field_schema_version']);
        $this->assertSame('promotion_delayed', $payload['esp09_field_promotion_delayed']);
        $this->assertSame('decision_kind', $payload['esp09_field_decision_kind']);
        $this->assertSame('challenger', $payload['esp09_field_challenger']);
        $this->assertSame('operator_alignment', $payload['esp09_field_operator_alignment']);
        $this->assertSame('vetoed', $payload['esp09_field_vetoed']);
        $this->assertSame('claim_policy', $payload['lote2_field_claim_policy']);
        $this->assertSame('latency_seconds', $payload['lote2_field_latency_seconds']);
        $this->assertSame('sample_rate', $payload['lote2_field_sample_rate']);
        $this->assertSame('paired_delta', $payload['lote2_field_paired_delta']);
        $this->assertSame('thresholds', $payload['lote2_field_thresholds']);
        $this->assertSame('stage', $payload['hmac_field_stage']);
        $this->assertSame('chained_captures', $payload['hmac_field_chained_captures']);
        $this->assertSame('coverage_rate', $payload['hmac_field_coverage_rate']);
        $this->assertSame('key_version', $payload['hmac_field_key_version']);
        $this->assertSame('threat_model', $payload['hmac_field_threat_model']);
        $this->assertSame(17, $payload['esp09_lote2_hmac_floor_count']);
    }

    public function test_phase_obra_bets_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->phaseObraBetsFloorsContractObserve([]);

        $this->assertSame('intent_id', $payload['phase_field_intent_id']);
        $this->assertSame('phase_in', $payload['phase_field_phase_in']);
        $this->assertSame('phase_out', $payload['phase_field_phase_out']);
        $this->assertSame('evidence_hashes', $payload['phase_field_evidence_hashes']);
        $this->assertSame('operator_signature', $payload['phase_field_operator_signature']);
        $this->assertSame('next_phase', $payload['phase_field_next_phase']);
        $this->assertSame('schema_version', $payload['obra_field_schema_version']);
        $this->assertSame('composed', $payload['obra_field_composed']);
        $this->assertSame('arcs', $payload['obra_field_arcs']);
        $this->assertSame('arc_count', $payload['obra_field_arc_count']);
        $this->assertSame('author_engine_id', $payload['obra_field_author_engine_id']);
        $this->assertSame('schema_version', $payload['bets_field_schema_version']);
        $this->assertSame('path_weight_multiplier', $payload['bets_field_path_weight_multiplier']);
        $this->assertSame('originated_candidates', $payload['bets_field_originated_candidates']);
        $this->assertSame('evaluated_bets', $payload['bets_field_evaluated_bets']);
        $this->assertSame('decisions', $payload['bets_field_decisions']);
        $this->assertSame('causal_effect', $payload['bets_field_causal_effect']);
        $this->assertSame(17, $payload['phase_obra_bets_floor_count']);
    }

    public function test_parallel_truth_autonomy_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->parallelTruthAutonomyFloorsContractObserve([]);

        $this->assertSame('lote', $payload['parallel_field_lote']);
        $this->assertSame('family', $payload['parallel_field_family']);
        $this->assertSame('schema', $payload['parallel_field_schema']);
        $this->assertSame('target', $payload['parallel_field_target']);
        $this->assertSame('claimed_by', $payload['parallel_field_claimed_by']);
        $this->assertSame('scoreboard_annotation', $payload['parallel_field_scoreboard_annotation']);
        $this->assertSame('capability_id', $payload['truth_field_capability_id']);
        $this->assertSame('owner_doc', $payload['truth_field_owner_doc']);
        $this->assertSame('claimed_state', $payload['truth_field_claimed_state']);
        $this->assertSame('computed_state', $payload['truth_field_computed_state']);
        $this->assertSame('under_claim', $payload['truth_field_under_claim']);
        $this->assertSame('unmet_evidence', $payload['truth_field_unmet_evidence']);
        $this->assertSame('next_stage', $payload['autonomy_field_next_stage']);
        $this->assertSame('certification_blocked', $payload['autonomy_field_certification_blocked']);
        $this->assertSame('learning_blocked', $payload['autonomy_field_learning_blocked']);
        $this->assertSame('failure_stage', $payload['autonomy_field_failure_stage']);
        $this->assertSame('stages', $payload['autonomy_field_stages']);
        $this->assertSame(17, $payload['parallel_truth_autonomy_floor_count']);
    }

    public function test_obra_thesis_skill_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->obraThesisSkillFloorsContractObserve([]);

        $this->assertSame('schema_version', $payload['obra_retro_field_schema_version']);
        $this->assertSame('outcomes', $payload['obra_retro_field_outcomes']);
        $this->assertSame('lesson_candidates', $payload['obra_retro_field_lesson_candidates']);
        $this->assertSame('workspace', $payload['obra_retro_field_workspace']);
        $this->assertSame('summary', $payload['obra_retro_field_summary']);
        $this->assertSame('slice_id', $payload['obra_retro_field_slice_id']);
        $this->assertSame('schema_version', $payload['thesis_field_schema_version']);
        $this->assertSame('composed', $payload['thesis_field_composed']);
        $this->assertSame('thesis_count', $payload['thesis_field_thesis_count']);
        $this->assertSame('theses', $payload['thesis_field_theses']);
        $this->assertSame('max_theses', $payload['thesis_field_max_theses']);
        $this->assertSame('influences_pick', $payload['thesis_field_influences_pick']);
        $this->assertSame('reason', $payload['skill_field_reason']);
        $this->assertSame('slice', $payload['skill_field_slice']);
        $this->assertSame('skill_schema_version', $payload['skill_field_skill_schema_version']);
        $this->assertSame('enqueued', $payload['skill_field_enqueued']);
        $this->assertSame('skill_name', $payload['skill_field_skill_name']);
        $this->assertSame(17, $payload['obra_thesis_skill_floor_count']);
    }

    public function test_mission_promotion_outcome_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->missionPromotionOutcomeFloorsContractObserve([]);

        $this->assertSame('phase', $payload['mission_field_phase']);
        $this->assertSame('index', $payload['mission_field_index']);
        $this->assertSame('status', $payload['mission_field_status']);
        $this->assertSame('gates_passed', $payload['mission_field_gates_passed']);
        $this->assertSame('gates_blocked', $payload['mission_field_gates_blocked']);
        $this->assertSame('gate_coverage', $payload['mission_field_gate_coverage']);
        $this->assertSame('actor_kind', $payload['mission_field_actor_kind']);
        $this->assertSame('schema_version', $payload['promo_field_schema_version']);
        $this->assertSame('verdict', $payload['promo_field_verdict']);
        $this->assertSame('current_tier', $payload['promo_field_current_tier']);
        $this->assertSame('target_tier', $payload['promo_field_target_tier']);
        $this->assertSame('preconditions', $payload['promo_field_preconditions']);
        $this->assertSame('failed_preconditions', $payload['promo_field_failed_preconditions']);
        $this->assertSame('schema_version', $payload['outcome_field_schema_version']);
        $this->assertSame('formula_version', $payload['outcome_field_formula_version']);
        $this->assertSame('adapter_origin', $payload['outcome_field_adapter_origin']);
        $this->assertSame('native_divergent', $payload['outcome_field_native_divergent']);
        $this->assertSame(17, $payload['mission_promotion_outcome_floor_count']);
    }

    public function test_immune_rollback_remint_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->immuneRollbackRemintFloorsContractObserve([]);

        $this->assertSame('schema_version', $payload['immune_field_schema_version']);
        $this->assertSame('source', $payload['immune_field_source']);
        $this->assertSame('tau', $payload['immune_field_tau']);
        $this->assertSame('max_similarity', $payload['immune_field_max_similarity']);
        $this->assertSame('lexical_hostile_class', $payload['immune_field_lexical_hostile_class']);
        $this->assertSame('override_applied', $payload['immune_field_override_applied']);
        $this->assertSame('trigger_id', $payload['rollback_field_trigger_id']);
        $this->assertSame('condition_kind', $payload['rollback_field_condition_kind']);
        $this->assertSame('checked_at', $payload['rollback_field_checked_at']);
        $this->assertSame('armed', $payload['rollback_field_armed']);
        $this->assertSame('triggers', $payload['rollback_field_triggers']);
        $this->assertSame('fired', $payload['rollback_field_fired']);
        $this->assertSame('reason', $payload['remint_field_reason']);
        $this->assertSame('mode', $payload['remint_field_mode']);
        $this->assertSame('paths', $payload['remint_field_paths']);
        $this->assertSame('queued', $payload['remint_field_queued']);
        $this->assertSame('error', $payload['remint_field_error']);
        $this->assertSame(17, $payload['immune_rollback_remint_floor_count']);
    }

    public function test_scorecard_gate_test_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->scorecardGateTestFloorsContractObserve([]);

        $this->assertSame('group', $payload['scorecard_field_group']);
        $this->assertSame('service_class', $payload['scorecard_field_service_class']);
        $this->assertSame('consumer_module_count', $payload['scorecard_field_consumer_module_count']);
        $this->assertSame('consumer_modules', $payload['scorecard_field_consumer_modules']);
        $this->assertSame('supplemental_subsystem_count', $payload['scorecard_field_supplemental_subsystem_count']);
        $this->assertSame('scorecard_hash', $payload['scorecard_field_scorecard_hash']);
        $this->assertSame('schema_version', $payload['gate_field_schema_version']);
        $this->assertSame('gates', $payload['gate_field_gates']);
        $this->assertSame('all_passed', $payload['gate_field_all_passed']);
        $this->assertSame('reasons', $payload['gate_field_reasons']);
        $this->assertSame('passed', $payload['gate_field_passed']);
        $this->assertSame('capability_id', $payload['test_field_capability_id']);
        $this->assertSame('test_ref', $payload['test_field_test_ref']);
        $this->assertSame('filter', $payload['test_field_filter']);
        $this->assertSame('commit_stamp', $payload['test_field_commit_stamp']);
        $this->assertSame('ran_at', $payload['test_field_ran_at']);
        $this->assertSame('status', $payload['test_field_status']);
        $this->assertSame(17, $payload['scorecard_gate_test_floor_count']);
    }

    public function test_ncapture_immune_coverage_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->ncaptureImmuneCoverageFloorsContractObserve([]);

        $this->assertSame('measure_id', $payload['ncapture_field_measure_id']);
        $this->assertSame('formula_version', $payload['ncapture_field_formula_version']);
        $this->assertSame('denominator_min', $payload['ncapture_field_denominator_min']);
        $this->assertSame('schema_version', $payload['ncapture_field_schema_version']);
        $this->assertSame('drill', $payload['ncapture_field_drill']);
        $this->assertSame('expected', $payload['ncapture_field_expected']);
        $this->assertSame('schema_version', $payload['immune_ledger_field_schema_version']);
        $this->assertSame('candidate_hash', $payload['immune_ledger_field_candidate_hash']);
        $this->assertSame('writer', $payload['immune_ledger_field_writer']);
        $this->assertSame('decided_at', $payload['immune_ledger_field_decided_at']);
        $this->assertSame('blocking_gate_ids', $payload['immune_ledger_field_blocking_gate_ids']);
        $this->assertSame('promotion_status', $payload['immune_ledger_field_promotion_status']);
        $this->assertSame('coverage', $payload['coverage_field_coverage']);
        $this->assertSame('satisfied', $payload['coverage_field_satisfied']);
        $this->assertSame('extra_passed_gates', $payload['coverage_field_extra_passed_gates']);
        $this->assertSame('missing', $payload['coverage_field_missing']);
        $this->assertSame('actual', $payload['ncapture_field_actual']);
        $this->assertSame(17, $payload['ncapture_immune_coverage_floor_count']);
    }

    public function test_cockpit_canary_adversarial_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->cockpitCanaryAdversarialFloorsContractObserve([]);

        $this->assertSame('loops', $payload['cockpit_field_loops']);
        $this->assertSame('funnel', $payload['cockpit_field_funnel']);
        $this->assertSame('rollback_triggers', $payload['cockpit_field_rollback_triggers']);
        $this->assertSame('operational_volume', $payload['cockpit_field_operational_volume']);
        $this->assertSame('heading', $payload['cockpit_field_heading']);
        $this->assertSame('sections', $payload['cockpit_field_sections']);
        $this->assertSame('version', $payload['canary_field_version']);
        $this->assertSame('metric', $payload['canary_field_metric']);
        $this->assertSame('value', $payload['canary_field_value']);
        $this->assertSame('floor', $payload['canary_field_floor']);
        $this->assertSame('refs_total', $payload['canary_field_refs_total']);
        $this->assertSame('flows_checked', $payload['canary_field_flows_checked']);
        $this->assertSame('probe', $payload['adversarial_field_probe']);
        $this->assertSame('violations', $payload['adversarial_field_violations']);
        $this->assertSame('operator', $payload['adversarial_field_operator']);
        $this->assertSame('reversal_rate', $payload['adversarial_field_reversal_rate']);
        $this->assertSame('metrics', $payload['adversarial_field_metrics']);
        $this->assertSame(17, $payload['cockpit_canary_adversarial_floor_count']);
    }

    public function test_maturity_envelope_lifecycle_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->maturityEnvelopeLifecycleFloorsContractObserve([]);

        $this->assertSame('band', $payload['maturity_field_band']);
        $this->assertSame('rank', $payload['maturity_field_rank']);
        $this->assertSame('schema_version', $payload['maturity_field_schema_version']);
        $this->assertSame('qualifies', $payload['maturity_field_qualifies']);
        $this->assertSame('breaches', $payload['maturity_field_breaches']);
        $this->assertSame('qualified_band', $payload['maturity_field_qualified_band']);
        $this->assertSame('promotion_blocked', $payload['maturity_field_promotion_blocked']);
        $this->assertSame('dev_procedural', $payload['envelope_field_dev_procedural']);
        $this->assertSame('aemor', $payload['envelope_field_aemor']);
        $this->assertSame('compounding', $payload['envelope_field_compounding']);
        $this->assertSame('measure_id', $payload['envelope_field_measure_id']);
        $this->assertSame('producers', $payload['envelope_field_producers']);
        $this->assertSame('consumers', $payload['envelope_field_consumers']);
        $this->assertSame('reason', $payload['lifecycle_field_reason']);
        $this->assertSame('attempt', $payload['lifecycle_field_attempt']);
        $this->assertSame('attempt_id', $payload['lifecycle_field_attempt_id']);
        $this->assertSame('state', $payload['lifecycle_field_state']);
        $this->assertSame(17, $payload['maturity_envelope_lifecycle_floor_count']);
    }

    public function test_embedding_coverage_thesis_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->embeddingCoverageThesisFloorsContractObserve([]);

        $this->assertSame('measure_id', $payload['code_embed_field_measure_id']);
        $this->assertSame('formula_version', $payload['code_embed_field_formula_version']);
        $this->assertSame('denominator_min', $payload['code_embed_field_denominator_min']);
        $this->assertSame('aggregate', $payload['code_embed_field_aggregate']);
        $this->assertSame('status', $payload['code_embed_field_status']);
        $this->assertSame('reason', $payload['code_embed_field_reason']);
        $this->assertSame('measure_id', $payload['kb_embed_field_measure_id']);
        $this->assertSame('formula_version', $payload['kb_embed_field_formula_version']);
        $this->assertSame('aggregate', $payload['kb_embed_field_aggregate']);
        $this->assertSame('status', $payload['kb_embed_field_status']);
        $this->assertSame('reason', $payload['kb_embed_field_reason']);
        $this->assertSame('status', $payload['thesis_field_status']);
        $this->assertSame('schema_version', $payload['thesis_field_schema_version']);
        $this->assertSame('thesis_id', $payload['thesis_field_thesis_id']);
        $this->assertSame('archive_receipt', $payload['thesis_field_archive_receipt']);
        $this->assertSame('reason', $payload['thesis_field_reason']);
        $this->assertSame('claim', $payload['thesis_field_claim']);
        $this->assertSame(17, $payload['embedding_coverage_thesis_floor_count']);
    }

    public function test_dept_level_evidence_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->deptLevelEvidenceFloorsContractObserve([]);

        $this->assertSame('schema_version', $payload['dept_level_field_schema_version']);
        $this->assertSame('department_id', $payload['dept_level_field_department_id']);
        $this->assertSame('earned_level', $payload['dept_level_field_earned_level']);
        $this->assertSame('earned_level_index', $payload['dept_level_field_earned_level_index']);
        $this->assertSame('highest_band_offered', $payload['dept_level_field_highest_band_offered']);
        $this->assertSame('all_bands_satisfied', $payload['dept_level_field_all_bands_satisfied']);
        $this->assertSame('achieved_level', $payload['quality_level_field_achieved_level']);
        $this->assertSame('achieved_band_index', $payload['quality_level_field_achieved_band_index']);
        $this->assertSame('highest_evaluable_level', $payload['quality_level_field_highest_evaluable_level']);
        $this->assertSame('next_level', $payload['quality_level_field_next_level']);
        $this->assertSame('promotion_blocked', $payload['quality_level_field_promotion_blocked']);
        $this->assertSame('symbol', $payload['evidence_field_symbol']);
        $this->assertSame('test', $payload['evidence_field_test']);
        $this->assertSame('class', $payload['evidence_field_class']);
        $this->assertSame('method', $payload['evidence_field_method']);
        $this->assertSame('names', $payload['evidence_field_names']);
        $this->assertSame('paths', $payload['evidence_field_paths']);
        $this->assertSame(17, $payload['dept_level_evidence_floor_count']);
    }

    public function test_schema_decomposer_surprise_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->schemaDecomposerSurpriseFloorsContractObserve([]);

        $this->assertSame('change_kind', $payload['schema_field_change_kind']);
        $this->assertSame('proposed_effect', $payload['schema_field_proposed_effect']);
        $this->assertSame('scope', $payload['schema_field_scope']);
        $this->assertSame('privacy_class', $payload['schema_field_privacy_class']);
        $this->assertSame('actor', $payload['schema_field_actor']);
        $this->assertSame('current_schema', $payload['schema_field_current_schema']);
        $this->assertSame('reasoning', $payload['decomposer_field_reasoning']);
        $this->assertSame('retrieval', $payload['decomposer_field_retrieval']);
        $this->assertSame('generation', $payload['decomposer_field_generation']);
        $this->assertSame('code', $payload['decomposer_field_code']);
        $this->assertSame('vision', $payload['decomposer_field_vision']);
        $this->assertSame('audit', $payload['decomposer_field_audit']);
        $this->assertSame('surprise', $payload['surprise_field_surprise']);
        $this->assertSame('record', $payload['surprise_field_record']);
        $this->assertSame('priority', $payload['surprise_field_priority']);
        $this->assertSame('predicted', $payload['surprise_field_predicted']);
        $this->assertSame('gated', $payload['surprise_field_gated']);
        $this->assertSame(17, $payload['schema_decomposer_surprise_floor_count']);
    }

    public function test_evidence_flywheel_budget_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->evidenceFlywheelBudgetFloorsContractObserve([]);

        $this->assertSame('status', $payload['evidence_field_status']);
        $this->assertSame('reason', $payload['evidence_field_reason']);
        $this->assertSame('owner_capability_ids', $payload['evidence_field_owner_capability_ids']);
        $this->assertSame('candidate_test_refs', $payload['evidence_field_candidate_test_refs']);
        $this->assertSame('latest_receipt_at', $payload['evidence_field_latest_receipt_at']);
        $this->assertSame('green_receipt_count', $payload['evidence_field_green_receipt_count']);
        $this->assertSame('status', $payload['flywheel_field_status']);
        $this->assertSame('stages', $payload['flywheel_field_stages']);
        $this->assertSame('by_executor', $payload['flywheel_field_by_executor']);
        $this->assertSame('outcome_count', $payload['flywheel_field_outcome_count']);
        $this->assertSame('outcomes_without_lesson', $payload['flywheel_field_outcomes_without_lesson']);
        $this->assertSame('ram_mb', $payload['budget_field_ram_mb']);
        $this->assertSame('disk_mb', $payload['budget_field_disk_mb']);
        $this->assertSame('name', $payload['budget_field_name']);
        $this->assertSame('purpose', $payload['budget_field_purpose']);
        $this->assertSame('ram_cap_mb', $payload['budget_field_ram_cap_mb']);
        $this->assertSame('status', $payload['budget_field_status']);
        $this->assertSame(17, $payload['evidence_flywheel_budget_floor_count']);
    }

    public function test_portfolio_impact_corpus_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->portfolioImpactCorpusFloorsContractObserve([]);

        $this->assertSame('mean_proven_yield', $payload['portfolio_field_mean_proven_yield']);
        $this->assertSame('min', $payload['portfolio_field_min']);
        $this->assertSame('max', $payload['portfolio_field_max']);
        $this->assertSame('basis', $payload['portfolio_field_basis']);
        $this->assertSame('allocated_share', $payload['portfolio_field_allocated_share']);
        $this->assertSame('status', $payload['portfolio_field_status']);
        $this->assertSame('schema_version', $payload['impact_field_schema_version']);
        $this->assertSame('source', $payload['impact_field_source']);
        $this->assertSame('task', $payload['impact_field_task']);
        $this->assertSame('slice', $payload['impact_field_slice']);
        $this->assertSame('obra', $payload['impact_field_obra']);
        $this->assertSame('band', $payload['impact_field_band']);
        $this->assertSame('schema_version', $payload['corpus_field_schema_version']);
        $this->assertSame('source', $payload['corpus_field_source']);
        $this->assertSame('candidate_hash', $payload['corpus_field_candidate_hash']);
        $this->assertSame('status', $payload['corpus_field_status']);
        $this->assertSame('candidates', $payload['corpus_field_candidates']);
        $this->assertSame(17, $payload['portfolio_impact_corpus_floor_count']);
    }

    public function test_disk_deadseries_latency_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->diskDeadseriesLatencyFloorsContractObserve([]);

        $this->assertSame('path', $payload['disk_field_path']);
        $this->assertSame('free_gb', $payload['disk_field_free_gb']);
        $this->assertSame('floor_gb', $payload['disk_field_floor_gb']);
        $this->assertSame('free_bytes', $payload['disk_field_free_bytes']);
        $this->assertSame('total_bytes', $payload['disk_field_total_bytes']);
        $this->assertSame('code', $payload['disk_field_code']);
        $this->assertSame('series', $payload['deadseries_field_series']);
        $this->assertSame('schema_version', $payload['deadseries_field_schema_version']);
        $this->assertSame('registry_count', $payload['deadseries_field_registry_count']);
        $this->assertSame('dead_count', $payload['deadseries_field_dead_count']);
        $this->assertSame('code', $payload['deadseries_field_code']);
        $this->assertSame('message', $payload['deadseries_field_message']);
        $this->assertSame('reason', $payload['latency_field_reason']);
        $this->assertSame('samples', $payload['latency_field_samples']);
        $this->assertSame('required', $payload['latency_field_required']);
        $this->assertSame('measure_id', $payload['latency_field_measure_id']);
        $this->assertSame('thresholds', $payload['latency_field_thresholds']);
        $this->assertSame(17, $payload['disk_deadseries_latency_floor_count']);
    }

    public function test_memory_spec_dogfood_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->memorySpecDogfoodFloorsContractObserve([]);

        $this->assertSame('ref', $payload['memory_field_ref']);
        $this->assertSame('priority', $payload['memory_field_priority']);
        $this->assertSame('requested_chars', $payload['memory_field_requested_chars']);
        $this->assertSame('allocated_chars', $payload['memory_field_allocated_chars']);
        $this->assertSame('capped', $payload['memory_field_capped']);
        $this->assertSame('rank', $payload['memory_field_rank']);
        $this->assertSame('weight', $payload['spec_field_weight']);
        $this->assertSame('reason', $payload['spec_field_reason']);
        $this->assertSame('raw_request', $payload['spec_field_raw_request']);
        $this->assertSame('interpreted_goal', $payload['spec_field_interpreted_goal']);
        $this->assertSame('non_goals', $payload['spec_field_non_goals']);
        $this->assertSame('requirements', $payload['spec_field_requirements']);
        $this->assertSame('schema_version', $payload['dogfood_field_schema_version']);
        $this->assertSame('class', $payload['dogfood_field_class']);
        $this->assertSame('signature', $payload['dogfood_field_signature']);
        $this->assertSame('occurrences', $payload['dogfood_field_occurrences']);
        $this->assertSame('target', $payload['dogfood_field_target']);
        $this->assertSame(17, $payload['memory_spec_dogfood_floor_count']);
    }

    public function test_restore_redaction_recall_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->restoreRedactionRecallFloorsContractObserve([]);

        $this->assertSame('reason', $payload['restore_field_reason']);
        $this->assertSame('schema_version', $payload['restore_field_schema_version']);
        $this->assertSame('receipt_path', $payload['restore_field_receipt_path']);
        $this->assertSame('max_success_age_days', $payload['restore_field_max_success_age_days']);
        $this->assertSame('code', $payload['restore_field_code']);
        $this->assertSame('message', $payload['restore_field_message']);
        $this->assertSame('schema', $payload['redaction_field_schema']);
        $this->assertSame('reason', $payload['redaction_field_reason']);
        $this->assertSame('memory_ref', $payload['redaction_field_memory_ref']);
        $this->assertSame('signals', $payload['redaction_field_signals']);
        $this->assertSame('drift_count', $payload['redaction_field_drift_count']);
        $this->assertSame('drift', $payload['redaction_field_drift']);
        $this->assertSame('schema_version', $payload['recall_gap_field_schema_version']);
        $this->assertSame('candidate_type', $payload['recall_gap_field_candidate_type']);
        $this->assertSame('query_hash', $payload['recall_gap_field_query_hash']);
        $this->assertSame('occurrences', $payload['recall_gap_field_occurrences']);
        $this->assertSame('status', $payload['recall_gap_field_status']);
        $this->assertSame(17, $payload['restore_redaction_recall_floor_count']);
    }

    public function test_runner_phase_saturation_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->runnerPhaseSaturationFloorsContractObserve([]);

        $this->assertSame('message', $payload['runner_field_message']);
        $this->assertSame('exception_class', $payload['runner_field_exception_class']);
        $this->assertSame('code', $payload['runner_field_code']);
        $this->assertSame('schema_version', $payload['runner_field_schema_version']);
        $this->assertSame('run_id', $payload['runner_field_run_id']);
        $this->assertSame('checked_at', $payload['runner_field_checked_at']);
        $this->assertSame('status', $payload['runner_field_status']);
        $this->assertSame('counts', $payload['runner_field_counts']);
        $this->assertSame('schema_version', $payload['phase_field_schema_version']);
        $this->assertSame('configured_phase', $payload['phase_field_configured_phase']);
        $this->assertSame('is_valid', $payload['phase_field_is_valid']);
        $this->assertSame('is_active', $payload['phase_field_is_active']);
        $this->assertSame('is_legacy', $payload['phase_field_is_legacy']);
        $this->assertSame('description', $payload['phase_field_description']);
        $this->assertSame('schema_version', $payload['saturation_field_schema_version']);
        $this->assertSame('reactive_saturated', $payload['saturation_field_reactive_saturated']);
        $this->assertSame('basis', $payload['saturation_field_basis']);
        $this->assertSame(17, $payload['runner_phase_saturation_floor_count']);
    }

    public function test_immune_scorecard_segment_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->immuneScorecardSegmentFloorsContractObserve([]);

        $this->assertSame('kind', $payload['immune_field_kind']);
        $this->assertSame('measure_id', $payload['immune_field_measure_id']);
        $this->assertSame('formula_version', $payload['immune_field_formula_version']);
        $this->assertSame('formula', $payload['immune_field_formula']);
        $this->assertSame('thresholds', $payload['immune_field_thresholds']);
        $this->assertSame('tau', $payload['immune_field_tau']);
        $this->assertSame('acronym', $payload['scorecard_field_acronym']);
        $this->assertSame('name', $payload['scorecard_field_name']);
        $this->assertSame('subsystem_count', $payload['scorecard_field_subsystem_count']);
        $this->assertSame('code_status', $payload['scorecard_field_code_status']);
        $this->assertSame('doc_status', $payload['scorecard_field_doc_status']);
        $this->assertSame('pipeline_status', $payload['scorecard_field_pipeline_status']);
        $this->assertSame('token_estimate', $payload['segment_field_token_estimate']);
        $this->assertSame('dedup_penalty', $payload['segment_field_dedup_penalty']);
        $this->assertSame('blocker', $payload['segment_field_blocker']);
        $this->assertSame('dod', $payload['segment_field_dod']);
        $this->assertSame('risk_critical', $payload['segment_field_risk_critical']);
        $this->assertSame(17, $payload['immune_scorecard_segment_floor_count']);
    }


    public function test_advisory_teto_jina_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->advisoryTetoJinaFloorsContractObserve([]);

        $this->assertSame('target_class', $payload['advisory_field_target_class']);
        $this->assertSame('risk_band', $payload['advisory_field_risk_band']);
        $this->assertSame('confidence_band', $payload['advisory_field_confidence_band']);
        $this->assertSame('similar_revert_rate', $payload['advisory_field_similar_revert_rate']);
        $this->assertSame('n_similar', $payload['advisory_field_n_similar']);
        $this->assertSame('high', $payload['advisory_field_high']);
        $this->assertSame('item_count', $payload['teto_field_item_count']);
        $this->assertSame('title', $payload['teto_field_title']);
        $this->assertSame('shown_item_count', $payload['teto_field_shown_item_count']);
        $this->assertSame('group_count', $payload['teto_field_group_count']);
        $this->assertSame('cap', $payload['teto_field_cap']);
        $this->assertSame('band_order', $payload['teto_field_band_order']);
        $this->assertSame('model_id', $payload['jina_field_model_id']);
        $this->assertSame('dimensions', $payload['jina_field_dimensions']);
        $this->assertSame('default_promoted', $payload['jina_field_default_promoted']);
        $this->assertSame('ab_green_claimed', $payload['jina_field_ab_green_claimed']);
        $this->assertSame('current_model', $payload['jina_field_current_model']);
        $this->assertSame(17, $payload['advisory_teto_jina_floor_count']);
    }



    public function test_window_canary_flywheel_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->windowCanaryFlywheelFloorsContractObserve([]);

        $this->assertSame('blocking', $payload['window_field_blocking']);
        $this->assertSame('reason', $payload['window_field_reason']);
        $this->assertSame('nodes', $payload['window_field_nodes']);
        $this->assertSame('schema_version', $payload['window_field_schema_version']);
        $this->assertSame('generated_at', $payload['window_field_generated_at']);
        $this->assertSame('source', $payload['window_field_source']);
        $this->assertSame('code', $payload['canary_field_code']);
        $this->assertSame('schema_version', $payload['canary_field_schema_version']);
        $this->assertSame('as_of', $payload['canary_field_as_of']);
        $this->assertSame('window_hours', $payload['canary_field_window_hours']);
        $this->assertSame('top_n_flows', $payload['canary_field_top_n_flows']);
        $this->assertSame('flows_available_in_window', $payload['canary_field_flows_available_in_window']);
        $this->assertSame('citations_without_better_outcome', $payload['flywheel_field_citations_without_better_outcome']);
        $this->assertSame('num', $payload['flywheel_field_num']);
        $this->assertSame('den', $payload['flywheel_field_den']);
        $this->assertSame('schema_version', $payload['flywheel_field_schema_version']);
        $this->assertSame('measure_id', $payload['flywheel_field_measure_id']);
        $this->assertSame(17, $payload['window_canary_flywheel_floor_count']);
    }



    public function test_verified_coverage_choreography_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->verifiedCoverageChoreographyFloorsContractObserve([]);

        $this->assertSame('formula', $payload['verified_field_formula']);
        $this->assertSame('verified_share_min', $payload['verified_field_verified_share_min']);
        $this->assertSame('window_days_min', $payload['verified_field_window_days_min']);
        $this->assertSame('denominator_min_executions', $payload['verified_field_denominator_min_executions']);
        $this->assertSame('ttl_days', $payload['verified_field_ttl_days']);
        $this->assertSame('series', $payload['verified_field_series']);
        $this->assertSame('active_symbols', $payload['coverage_field_active_symbols']);
        $this->assertSame('covered_count', $payload['coverage_field_covered_count']);
        $this->assertSame('stale_count', $payload['coverage_field_stale_count']);
        $this->assertSame('missing_count', $payload['coverage_field_missing_count']);
        $this->assertSame('coverage_ratio', $payload['coverage_field_coverage_ratio']);
        $this->assertSame('kind', $payload['coverage_field_kind']);
        $this->assertSame('recognized', $payload['choreography_field_recognized']);
        $this->assertSame('vetoing_department', $payload['choreography_field_vetoing_department']);
        $this->assertSame('reason', $payload['choreography_field_reason']);
        $this->assertSame('paused_departments', $payload['choreography_field_paused_departments']);
        $this->assertSame('pause_sla_seconds', $payload['choreography_field_pause_sla_seconds']);
        $this->assertSame(17, $payload['verified_coverage_choreography_floor_count']);
    }



    public function test_knowledge_decomposer_promoter_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->knowledgeDecomposerPromoterFloorsContractObserve([]);

        $this->assertSame('active_items', $payload['knowledge_field_active_items']);
        $this->assertSame('covered_count', $payload['knowledge_field_covered_count']);
        $this->assertSame('stale_count', $payload['knowledge_field_stale_count']);
        $this->assertSame('missing_count', $payload['knowledge_field_missing_count']);
        $this->assertSame('coverage_ratio', $payload['knowledge_field_coverage_ratio']);
        $this->assertSame('kind', $payload['knowledge_field_kind']);
        $this->assertSame('context', $payload['decomposer_field_context']);
        $this->assertSame('weights', $payload['decomposer_field_weights']);
        $this->assertSame('benchmark_claim_allowed', $payload['decomposer_field_benchmark_claim_allowed']);
        $this->assertSame('rivals_claim_allowed', $payload['decomposer_field_rivals_claim_allowed']);
        $this->assertSame('superiority_claim_allowed', $payload['decomposer_field_superiority_claim_allowed']);
        $this->assertSame('provider_safe_only_enforced', $payload['decomposer_field_provider_safe_only_enforced']);
        $this->assertSame('generated_at', $payload['promoter_field_generated_at']);
        $this->assertSame('freeze', $payload['promoter_field_freeze']);
        $this->assertSame('measure_id', $payload['promoter_field_measure_id']);
        $this->assertSame('scoreboard', $payload['promoter_field_scoreboard']);
        $this->assertSame('floor_met', $payload['promoter_field_floor_met']);
        $this->assertSame(17, $payload['knowledge_decomposer_promoter_floor_count']);
    }



    public function test_ncapture_obra_truth_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->ncaptureObraTruthFloorsContractObserve([]);

        $this->assertSame('kind', $payload['ncapture_field_kind']);
        $this->assertSame('formula', $payload['ncapture_field_formula']);
        $this->assertSame('thresholds', $payload['ncapture_field_thresholds']);
        $this->assertSame('days_between_drills_max', $payload['ncapture_field_days_between_drills_max']);
        $this->assertSame('bypass_forbidden', $payload['ncapture_field_bypass_forbidden']);
        $this->assertSame('peek_only', $payload['ncapture_field_peek_only']);
        $this->assertSame('items', $payload['obra_field_items']);
        $this->assertSame('scoreboard_path', $payload['obra_field_scoreboard_path']);
        $this->assertSame('slices', $payload['obra_field_slices']);
        $this->assertSame('terminal', $payload['obra_field_terminal']);
        $this->assertSame('scope', $payload['obra_field_scope']);
        $this->assertSame('flow_id', $payload['obra_field_flow_id']);
        $this->assertSame('proof_refs_resolved', $payload['truth_field_proof_refs_resolved']);
        $this->assertSame('summary', $payload['truth_field_summary']);
        $this->assertSame('evaluated', $payload['truth_field_evaluated']);
        $this->assertSame('drift_count', $payload['truth_field_drift_count']);
        $this->assertSame('capabilities', $payload['truth_field_capabilities']);
        $this->assertSame(17, $payload['ncapture_obra_truth_floor_count']);
    }



    public function test_horizon_calibration_atlas_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->horizonCalibrationAtlasFloorsContractObserve([]);

        $this->assertSame('schema_version', $payload['horizon_field_schema_version']);
        $this->assertSame('score_out_of_10', $payload['horizon_field_score_out_of_10']);
        $this->assertSame('code', $payload['horizon_field_code']);
        $this->assertSame('doc', $payload['horizon_field_doc']);
        $this->assertSame('pipeline', $payload['horizon_field_pipeline']);
        $this->assertSame('first_date', $payload['horizon_field_first_date']);
        $this->assertSame('registry_status', $payload['calibration_field_registry_status']);
        $this->assertSame('generated_at', $payload['calibration_field_generated_at']);
        $this->assertSame('freeze', $payload['calibration_field_freeze']);
        $this->assertSame('samples', $payload['calibration_field_samples']);
        $this->assertSame('ledger', $payload['calibration_field_ledger']);
        $this->assertSame('total', $payload['calibration_field_total']);
        $this->assertSame('generated_at', $payload['atlas_field_generated_at']);
        $this->assertSame('subsystem_count', $payload['atlas_field_subsystem_count']);
        $this->assertSame('group_count', $payload['atlas_field_group_count']);
        $this->assertSame('groups', $payload['atlas_field_groups']);
        $this->assertSame('overall_score', $payload['atlas_field_overall_score']);
        $this->assertSame(17, $payload['horizon_calibration_atlas_floor_count']);
    }



    public function test_decay_portfolio_spec_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->decayPortfolioSpecFloorsContractObserve([]);

        $this->assertSame('schema_version', $payload['decay_field_schema_version']);
        $this->assertSame('health_score', $payload['decay_field_health_score']);
        $this->assertSame('effective_priority', $payload['decay_field_effective_priority']);
        $this->assertSame('lifecycle_action', $payload['decay_field_lifecycle_action']);
        $this->assertSame('staleness', $payload['decay_field_staleness']);
        $this->assertSame('age_days', $payload['decay_field_age_days']);
        $this->assertSame('decision_kind', $payload['portfolio_field_decision_kind']);
        $this->assertSame('allocation', $payload['portfolio_field_allocation']);
        $this->assertSame('default_mix', $payload['portfolio_field_default_mix']);
        $this->assertSame('yield_by_class', $payload['portfolio_field_yield_by_class']);
        $this->assertSame('reasons', $payload['portfolio_field_reasons']);
        $this->assertSame('source', $payload['portfolio_field_source']);
        $this->assertSame('business_actor_object_action', $payload['spec_field_business_actor_object_action']);
        $this->assertSame('design_system_constraints', $payload['spec_field_design_system_constraints']);
        $this->assertSame('security_constraints', $payload['spec_field_security_constraints']);
        $this->assertSame('assumptions', $payload['spec_field_assumptions']);
        $this->assertSame('blocking_questions', $payload['spec_field_blocking_questions']);
        $this->assertSame(17, $payload['decay_portfolio_spec_floor_count']);
    }


    public function test_composed_promotion_ragx_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->composedPromotionRagxFloorsContractObserve([]);

        $this->assertSame('action_on_trigger', $payload['composed_field_action_on_trigger']);
        $this->assertSame('architect_phase_gate', $payload['composed_field_architect_phase_gate']);
        $this->assertSame('author_neq_judge', $payload['composed_field_author_neq_judge']);
        $this->assertSame('certifier_engine_id', $payload['composed_field_certifier_engine_id']);
        $this->assertSame('challenger_advisory', $payload['composed_field_challenger_advisory']);
        $this->assertSame('challenger_engine_id', $payload['composed_field_challenger_engine_id']);
        $this->assertSame('action', $payload['promotion_field_action']);
        $this->assertSame('actor', $payload['promotion_field_actor']);
        $this->assertSame('allowed_states', $payload['promotion_field_allowed_states']);
        $this->assertSame('challenger_advisory', $payload['promotion_field_challenger_advisory']);
        $this->assertSame('challenger_engine_id', $payload['promotion_field_challenger_engine_id']);
        $this->assertSame('decision_kind', $payload['promotion_field_decision_kind']);
        $this->assertSame('algorithm', $payload['ragx_field_algorithm']);
        $this->assertSame('baseline', $payload['ragx_field_baseline']);
        $this->assertSame('candidate', $payload['ragx_field_candidate']);
        $this->assertSame('communities_seen', $payload['ragx_field_communities_seen']);
        $this->assertSame('community', $payload['ragx_field_community']);
        $this->assertSame(17, $payload['composed_promotion_ragx_floor_count']);
    }


    public function test_http_cockpit_facade_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->httpCockpitFacadeFloorsContractObserve([]);

        $this->assertSame('aawr_invocation', $payload['envelope_field_aawr_invocation']);
        $this->assertSame('blocked_when', $payload['envelope_field_blocked_when']);
        $this->assertSame('blockers', $payload['envelope_field_blockers']);
        $this->assertSame('command_intent', $payload['envelope_field_command_intent']);
        $this->assertSame('company_runtime_invocation', $payload['envelope_field_company_runtime_invocation']);
        $this->assertSame('decision_receipt_v2_invocation', $payload['envelope_field_decision_receipt_v2_invocation']);
        $this->assertSame('active_leases', $payload['cockpit_field_active_leases']);
        $this->assertSame('actor', $payload['cockpit_field_actor']);
        $this->assertSame('autonomy_level', $payload['cockpit_field_autonomy_level']);
        $this->assertSame('blocked_or_quarantined_count', $payload['cockpit_field_blocked_or_quarantined_count']);
        $this->assertSame('blocker_signal', $payload['cockpit_field_blocker_signal']);
        $this->assertSame('blockers', $payload['cockpit_field_blockers']);
        $this->assertSame('aaeos_http_path', $payload['facade_field_aaeos_http_path']);
        $this->assertSame('blocked_when', $payload['facade_field_blocked_when']);
        $this->assertSame('blockers', $payload['facade_field_blockers']);
        $this->assertSame('canonical_calls', $payload['facade_field_canonical_calls']);
        $this->assertSame('code', $payload['facade_field_code']);
        $this->assertSame(17, $payload['http_cockpit_facade_floor_count']);
    }


    public function test_ncapture_promoter_jina_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->ncapturePromoterJinaFloorsContractObserve([]);

        $this->assertSame('admission', $payload['ncapture_field_admission']);
        $this->assertSame('admitted', $payload['ncapture_field_admitted']);
        $this->assertSame('admitted_count', $payload['ncapture_field_admitted_count']);
        $this->assertSame('aggregate', $payload['ncapture_field_aggregate']);
        $this->assertSame('author_engine_id', $payload['ncapture_field_author_engine_id']);
        $this->assertSame('bypass', $payload['ncapture_field_bypass']);
        $this->assertSame('attempts', $payload['promoter_field_attempts']);
        $this->assertSame('author_engine', $payload['promoter_field_author_engine']);
        $this->assertSame('auto_promotion_allowed', $payload['promoter_field_auto_promotion_allowed']);
        $this->assertSame('body', $payload['promoter_field_body']);
        $this->assertSame('candidate_id', $payload['promoter_field_candidate_id']);
        $this->assertSame('candidates', $payload['promoter_field_candidates']);
        $this->assertSame('ab_green_claim_allowed', $payload['jina_field_ab_green_claim_allowed']);
        $this->assertSame('allowed', $payload['jina_field_allowed']);
        $this->assertSame('applied_to_live', $payload['jina_field_applied_to_live']);
        $this->assertSame('baseline', $payload['jina_field_baseline']);
        $this->assertSame('basis', $payload['jina_field_basis']);
        $this->assertSame(17, $payload['ncapture_promoter_jina_floor_count']);
    }


    public function test_truth_obra_thesis_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->truthObraThesisFloorsContractObserve([]);

        $this->assertSame('claims_runtime', $payload['truth_field_claims_runtime']);
        $this->assertSame('command', $payload['truth_field_command']);
        $this->assertSame('coverage_pct', $payload['truth_field_coverage_pct']);
        $this->assertSame('doc_schema', $payload['truth_field_doc_schema']);
        $this->assertSame('format', $payload['truth_field_format']);
        $this->assertSame('frontmatter', $payload['truth_field_frontmatter']);
        $this->assertSame('actor_tag', $payload['obra_field_actor_tag']);
        $this->assertSame('ai_run_outcome_id', $payload['obra_field_ai_run_outcome_id']);
        $this->assertSame('auto_promoted', $payload['obra_field_auto_promoted']);
        $this->assertSame('current_state', $payload['obra_field_current_state']);
        $this->assertSame('executor', $payload['obra_field_executor']);
        $this->assertSame('future_lote_close_requires', $payload['obra_field_future_lote_close_requires']);
        $this->assertSame('alignment_keys', $payload['thesis_field_alignment_keys']);
        $this->assertSame('archived_at_basis', $payload['thesis_field_archived_at_basis']);
        $this->assertSame('bands', $payload['thesis_field_bands']);
        $this->assertSame('calibration_resolved', $payload['thesis_field_calibration_resolved']);
        $this->assertSame('consecutive_windows', $payload['thesis_field_consecutive_windows']);
        $this->assertSame(17, $payload['truth_obra_thesis_floor_count']);
    }


    public function test_atlas_bets_verified_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->atlasBetsVerifiedFloorsContractObserve([]);

        $this->assertSame('acronym', $payload['atlas_field_acronym']);
        $this->assertSame('akif', $payload['atlas_field_akif']);
        $this->assertSame('atlas_decide', $payload['atlas_field_atlas_decide']);
        $this->assertSame('aucri', $payload['atlas_field_aucri']);
        $this->assertSame('aurg', $payload['atlas_field_aurg']);
        $this->assertSame('autonomy', $payload['atlas_field_autonomy']);
        $this->assertSame('admit_compounding', $payload['bets_field_admit_compounding']);
        $this->assertSame('allocation_boundary', $payload['bets_field_allocation_boundary']);
        $this->assertSame('ci_high', $payload['bets_field_ci_high']);
        $this->assertSame('counts_landing_or_acceptance', $payload['bets_field_counts_landing_or_acceptance']);
        $this->assertSame('counts_proven_real_only', $payload['bets_field_counts_proven_real_only']);
        $this->assertSame('decision_kind', $payload['bets_field_decision_kind']);
        $this->assertSame('actor', $payload['verified_field_actor']);
        $this->assertSame('aggregate', $payload['verified_field_aggregate']);
        $this->assertSame('content_hash', $payload['verified_field_content_hash']);
        $this->assertSame('event_name', $payload['verified_field_event_name']);
        $this->assertSame('executors', $payload['verified_field_executors']);
        $this->assertSame(17, $payload['atlas_bets_verified_floor_count']);
    }


    public function test_freeze_scorecard_eligibility_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->freezeScorecardEligibilityFloorsContractObserve([]);

        $this->assertSame('anchors_local_only', $payload['freeze_field_anchors_local_only']);
        $this->assertSame('anchors_path', $payload['freeze_field_anchors_path']);
        $this->assertSame('anchors_sha256', $payload['freeze_field_anchors_sha256']);
        $this->assertSame('author_engine_id', $payload['freeze_field_author_engine_id']);
        $this->assertSame('baseline_capacity_note', $payload['freeze_field_baseline_capacity_note']);
        $this->assertSame('baseline_port', $payload['freeze_field_baseline_port']);
        $this->assertSame('aemor', $payload['scorecard_field_aemor']);
        $this->assertSame('atlas_decide', $payload['scorecard_field_atlas_decide']);
        $this->assertSame('aucri', $payload['scorecard_field_aucri']);
        $this->assertSame('autonomy', $payload['scorecard_field_autonomy']);
        $this->assertSame('boundary', $payload['scorecard_field_boundary']);
        $this->assertSame('cognition', $payload['scorecard_field_cognition']);
        $this->assertSame('age_days', $payload['eligibility_field_age_days']);
        $this->assertSame('as_of', $payload['eligibility_field_as_of']);
        $this->assertSame('auto_promote_allowed', $payload['eligibility_field_auto_promote_allowed']);
        $this->assertSame('blockers', $payload['eligibility_field_blockers']);
        $this->assertSame('blockers_to_next', $payload['eligibility_field_blockers_to_next']);
        $this->assertSame(17, $payload['freeze_scorecard_eligibility_floor_count']);
    }


    public function test_drill_skill_dualread_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->drillSkillDualreadFloorsContractObserve([]);

        $this->assertSame('capability_spec', $payload['drill_field_capability_spec']);
        $this->assertSame('cold_start_via', $payload['drill_field_cold_start_via']);
        $this->assertSame('denominators', $payload['drill_field_denominators']);
        $this->assertSame('drill_id', $payload['drill_field_drill_id']);
        $this->assertSame('drills', $payload['drill_field_drills']);
        $this->assertSame('drills_in_window', $payload['drill_field_drills_in_window']);
        $this->assertSame('case_count_floor_met', $payload['skill_field_case_count_floor_met']);
        $this->assertSame('claim', $payload['skill_field_claim']);
        $this->assertSame('claim_policy', $payload['skill_field_claim_policy']);
        $this->assertSame('confidence', $payload['skill_field_confidence']);
        $this->assertSame('corrections', $payload['skill_field_corrections']);
        $this->assertSame('created', $payload['skill_field_created']);
        $this->assertSame('candidate', $payload['dualread_field_candidate']);
        $this->assertSame('candidate_precision_at_5', $payload['dualread_field_candidate_precision_at_5']);
        $this->assertSame('candidate_precision_at_5_mean', $payload['dualread_field_candidate_precision_at_5_mean']);
        $this->assertSame('candidate_recall_at_5', $payload['dualread_field_candidate_recall_at_5']);
        $this->assertSame('candidate_recall_at_5_mean', $payload['dualread_field_candidate_recall_at_5_mean']);
        $this->assertSame(17, $payload['drill_skill_dualread_floor_count']);
    }


    public function test_envelope_cockpit_facade_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->envelopeCockpitFacadeFloorsContractObserve([]);

        $this->assertSame('department_route', $payload['envelope_field_department_route']);
        $this->assertSame('domain', $payload['envelope_field_domain']);
        $this->assertSame('flow', $payload['envelope_field_flow']);
        $this->assertSame('flow_id', $payload['envelope_field_flow_id']);
        $this->assertSame('gate_status', $payload['envelope_field_gate_status']);
        $this->assertSame('intent_id', $payload['envelope_field_intent_id']);
        $this->assertSame('current_phase', $payload['cockpit_field_current_phase']);
        $this->assertSame('department_count', $payload['cockpit_field_department_count']);
        $this->assertSame('ended_at', $payload['cockpit_field_ended_at']);
        $this->assertSame('gate_report', $payload['cockpit_field_gate_report']);
        $this->assertSame('gates', $payload['cockpit_field_gates']);
        $this->assertSame('generated_at', $payload['cockpit_field_generated_at']);
        $this->assertSame('configured_phase', $payload['facade_field_configured_phase']);
        $this->assertSame('counters', $payload['facade_field_counters']);
        $this->assertSame('domain', $payload['facade_field_domain']);
        $this->assertSame('facade_active', $payload['facade_field_facade_active']);
        $this->assertSame('flow', $payload['facade_field_flow']);
        $this->assertSame(17, $payload['envelope_cockpit_facade_floor_count']);
    }

    public function test_atlas_composed_ragx_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->atlasComposedRagxFloorsContractObserve([]);

        $this->assertSame('code_ready', $payload['atlas_field_code_ready']);
        $this->assertSame('code_status', $payload['atlas_field_code_status']);
        $this->assertSame('cognition', $payload['atlas_field_cognition']);
        $this->assertSame('cognitive_immune', $payload['atlas_field_cognitive_immune']);
        $this->assertSame('compounding', $payload['atlas_field_compounding']);
        $this->assertSame('cross_domain', $payload['atlas_field_cross_domain']);
        $this->assertSame('allowed_files', $payload['composed_field_allowed_files']);
        $this->assertSame('claim', $payload['composed_field_claim']);
        $this->assertSame('completion_criterion', $payload['composed_field_completion_criterion']);
        $this->assertSame('consecutive_failures', $payload['composed_field_consecutive_failures']);
        $this->assertSame('consecutive_failures_k', $payload['composed_field_consecutive_failures_k']);
        $this->assertSame('decision_kind', $payload['composed_field_decision_kind']);
        $this->assertSame('chunk_id', $payload['ragx_field_chunk_id']);
        $this->assertSame('edge_count', $payload['ragx_field_edge_count']);
        $this->assertSame('error_class', $payload['ragx_field_error_class']);
        $this->assertSame('experiment_id', $payload['ragx_field_experiment_id']);
        $this->assertSame('flag', $payload['ragx_field_flag']);
        $this->assertSame(17, $payload['atlas_composed_ragx_floor_count']);
    }

    public function test_schema_aemor_lifecycle_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->schemaAemorLifecycleFloorsContractObserve([]);

        $this->assertSame('added_fields', $payload['schema_field_added_fields']);
        $this->assertSame('deprecated_fields', $payload['schema_field_deprecated_fields']);
        $this->assertSame('trigger', $payload['schema_field_trigger']);
        $this->assertSame('rationale', $payload['schema_field_rationale']);
        $this->assertSame('schema', $payload['schema_field_schema']);
        $this->assertSame('decision', $payload['schema_field_decision']);
        $this->assertSame('task_category', $payload['aemor_field_task_category']);
        $this->assertSame('provider', $payload['aemor_field_provider']);
        $this->assertSame('certified_receipt_id', $payload['aemor_field_certified_receipt_id']);
        $this->assertSame('episode_id', $payload['aemor_field_episode_id']);
        $this->assertSame('outcome_contract_v2', $payload['aemor_field_outcome_contract_v2']);
        $this->assertSame('evidence_ref_count', $payload['aemor_field_evidence_ref_count']);
        $this->assertSame('order', $payload['lifecycle_field_order']);
        $this->assertSame('obra_id', $payload['lifecycle_field_obra_id']);
        $this->assertSame('remaining_servable', $payload['lifecycle_field_remaining_servable']);
        $this->assertSame('task_id', $payload['lifecycle_field_task_id']);
        $this->assertSame('target_path', $payload['lifecycle_field_target_path']);
        $this->assertSame(17, $payload['schema_aemor_lifecycle_floor_count']);
    }

    public function test_dept_quality_evidence_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->deptQualityEvidenceFloorsContractObserve([]);

        $this->assertSame('level', $payload['dept_field_level']);
        $this->assertSame('comparator', $payload['dept_field_comparator']);
        $this->assertSame('metric', $payload['dept_field_metric']);
        $this->assertSame('threshold', $payload['dept_field_threshold']);
        $this->assertSame('observed', $payload['dept_field_observed']);
        $this->assertSame('evaluated_bands', $payload['dept_field_evaluated_bands']);
        $this->assertSame('level', $payload['quality_field_level']);
        $this->assertSame('metric', $payload['quality_field_metric']);
        $this->assertSame('comparator', $payload['quality_field_comparator']);
        $this->assertSame('value', $payload['quality_field_value']);
        $this->assertSame('binding_breaches', $payload['quality_field_binding_breaches']);
        $this->assertSame('evaluated_bands', $payload['quality_field_evaluated_bands']);
        $this->assertSame('capability_id', $payload['evidence_field_capability_id']);
        $this->assertSame('evidence_refs', $payload['evidence_field_evidence_refs']);
        $this->assertSame('test_refs', $payload['evidence_field_test_refs']);
        $this->assertSame('kind', $payload['evidence_field_kind']);
        $this->assertSame('ref', $payload['evidence_field_ref']);
        $this->assertSame(17, $payload['dept_quality_evidence_floor_count']);
    }

    public function test_truth_immune_veto_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->truthImmuneVetoFloorsContractObserve([]);

        $this->assertSame('path', $payload['truth_field_path']);
        $this->assertSame('test', $payload['truth_field_test']);
        $this->assertSame('matched', $payload['truth_field_matched']);
        $this->assertSame('symbol', $payload['truth_field_symbol']);
        $this->assertSame('test_green', $payload['truth_field_test_green']);
        $this->assertSame('receipt', $payload['truth_field_receipt']);
        $this->assertSame('matched', $payload['immune_field_matched']);
        $this->assertSame('enforce_applied', $payload['immune_field_enforce_applied']);
        $this->assertSame('signature', $payload['immune_field_signature']);
        $this->assertSame('origin_ref', $payload['immune_field_origin_ref']);
        $this->assertSame('hit_count_after', $payload['immune_field_hit_count_after']);
        $this->assertSame('reason', $payload['immune_field_reason']);
        $this->assertSame('review', $payload['veto_field_review']);
        $this->assertSame('operator', $payload['veto_field_operator']);
        $this->assertSame('product', $payload['veto_field_product']);
        $this->assertSame('architect', $payload['veto_field_architect']);
        $this->assertSame('dev', $payload['veto_field_dev']);
        $this->assertSame(17, $payload['truth_immune_veto_floor_count']);
    }

    public function test_dead_series_outcome_compounding_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->deadSeriesOutcomeCompoundingFloorsContractObserve([]);

        $this->assertSame('path', $payload['dead_series_field_path']);
        $this->assertSame('table', $payload['dead_series_field_table']);
        $this->assertSame('slice', $payload['dead_series_field_slice']);
        $this->assertSame('source_type', $payload['dead_series_field_source_type']);
        $this->assertSame('timestamp_field', $payload['dead_series_field_timestamp_field']);
        $this->assertSame('ttl_days', $payload['dead_series_field_ttl_days']);
        $this->assertSame('verified_source_present', $payload['outcome_field_verified_source_present']);
        $this->assertSame('executor', $payload['outcome_field_executor']);
        $this->assertSame('task_category', $payload['outcome_field_task_category']);
        $this->assertSame('provider', $payload['outcome_field_provider']);
        $this->assertSame('status', $payload['outcome_field_status']);
        $this->assertSame('verified_basis', $payload['outcome_field_verified_basis']);
        $this->assertSame('provider', $payload['compounding_field_provider']);
        $this->assertSame('task_category', $payload['compounding_field_task_category']);
        $this->assertSame('certified_receipt_id', $payload['compounding_field_certified_receipt_id']);
        $this->assertSame('outcome_status', $payload['compounding_field_outcome_status']);
        $this->assertSame('payload', $payload['compounding_field_payload']);
        $this->assertSame(17, $payload['dead_series_outcome_compounding_floor_count']);
    }

    public function test_immune_integrity_obra_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->immuneIntegrityObraFloorsContractObserve([]);

        $this->assertSame('false_blocks', $payload['immune_cal_field_false_blocks']);
        $this->assertSame('missed_poison', $payload['immune_cal_field_missed_poison']);
        $this->assertSame('known_miss_denominator', $payload['immune_cal_field_known_miss_denominator']);
        $this->assertSame('expected_block_gate_ids', $payload['immune_cal_field_expected_block_gate_ids']);
        $this->assertSame('true_blocks', $payload['immune_cal_field_true_blocks']);
        $this->assertSame('writer', $payload['immune_cal_field_writer']);
        $this->assertSame('schema_version', $payload['integrity_field_schema_version']);
        $this->assertSame('artifacts', $payload['integrity_field_artifacts']);
        $this->assertSame('function', $payload['integrity_field_function']);
        $this->assertSame('license', $payload['integrity_field_license']);
        $this->assertSame('source_url', $payload['integrity_field_source_url']);
        $this->assertSame('path_resolved', $payload['integrity_field_path_resolved']);
        $this->assertSame('payload', $payload['obra_retro_field_payload']);
        $this->assertSame('proposal_id', $payload['obra_retro_field_proposal_id']);
        $this->assertSame('quality', $payload['obra_retro_field_quality']);
        $this->assertSame('memory_admission', $payload['obra_retro_field_memory_admission']);
        $this->assertSame('requires_human_review', $payload['obra_retro_field_requires_human_review']);
        $this->assertSame(17, $payload['immune_integrity_obra_floor_count']);
    }

    public function test_teto_atlas_longhorizon_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->tetoAtlasLonghorizonFloorsContractObserve([]);

        $this->assertSame('evidence_refs', $payload['teto_field_evidence_refs']);
        $this->assertSame('highest_predicted_revert_band', $payload['teto_field_highest_predicted_revert_band']);
        $this->assertSame('batched_asks', $payload['teto_field_batched_asks']);
        $this->assertSame('diff_ref', $payload['teto_field_diff_ref']);
        $this->assertSame('review_mode', $payload['teto_field_review_mode']);
        $this->assertSame('pending_flip', $payload['teto_field_pending_flip']);
        $this->assertSame('pipeline_status', $payload['atlas_field_pipeline_status']);
        $this->assertSame('self_improvement', $payload['atlas_field_self_improvement']);
        $this->assertSame('self_construction', $payload['atlas_field_self_construction']);
        $this->assertSame('governance', $payload['atlas_field_governance']);
        $this->assertSame('pipeline_partial', $payload['atlas_field_pipeline_partial']);
        $this->assertSame('pipeline_building', $payload['atlas_field_pipeline_building']);
        $this->assertSame('latest_staleness_days', $payload['longhorizon_field_latest_staleness_days']);
        $this->assertSame('max_consecutive_gap_days', $payload['longhorizon_field_max_consecutive_gap_days']);
        $this->assertSame('backfilled_samples', $payload['longhorizon_field_backfilled_samples']);
        $this->assertSame('areas_below_floor', $payload['longhorizon_field_areas_below_floor']);
        $this->assertSame('claim_policy', $payload['longhorizon_field_claim_policy']);
        $this->assertSame(17, $payload['teto_atlas_longhorizon_floor_count']);
    }

    public function test_watchdog_impact_budget_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->watchdogImpactBudgetFloorsContractObserve([]);

        $this->assertSame('measured_share', $payload['watchdog_field_measured_share']);
        $this->assertSame('delivered_refs', $payload['watchdog_field_delivered_refs']);
        $this->assertSame('blockers', $payload['watchdog_field_blockers']);
        $this->assertSame('window_days', $payload['watchdog_field_window_days']);
        $this->assertSame('case_counts', $payload['watchdog_field_case_counts']);
        $this->assertSame('acronym', $payload['watchdog_field_acronym']);
        $this->assertSame('rung', $payload['impact_field_rung']);
        $this->assertSame('rank', $payload['impact_field_rank']);
        $this->assertSame('path_yield', $payload['impact_field_path_yield']);
        $this->assertSame('n_realized', $payload['impact_field_n_realized']);
        $this->assertSame('realized_true', $payload['impact_field_realized_true']);
        $this->assertSame('unresolved', $payload['impact_field_unresolved']);
        $this->assertSame('cpu_share', $payload['budget_field_cpu_share']);
        $this->assertSame('probe_hint', $payload['budget_field_probe_hint']);
        $this->assertSame('schema_version', $payload['budget_field_schema_version']);
        $this->assertSame('host_ram_gib', $payload['budget_field_host_ram_gib']);
        $this->assertSame('engine_floor_gib', $payload['budget_field_engine_floor_gib']);
        $this->assertSame(17, $payload['watchdog_impact_budget_floor_count']);
    }

    public function test_longhorizon_lote2_adversarial_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->longhorizonLote2AdversarialFloorsContractObserve([]);

        $this->assertSame('series_v2_path', $payload['longhorizon_field_series_v2_path']);
        $this->assertSame('min_area_overall', $payload['longhorizon_field_min_area_overall']);
        $this->assertSame('min_area_code', $payload['longhorizon_field_min_area_code']);
        $this->assertSame('min_area_doc', $payload['longhorizon_field_min_area_doc']);
        $this->assertSame('min_area_pipeline', $payload['longhorizon_field_min_area_pipeline']);
        $this->assertSame('sampled_dates_in_window', $payload['longhorizon_field_sampled_dates_in_window']);
        $this->assertSame('policy_violation_rows', $payload['lote2_field_policy_violation_rows']);
        $this->assertSame('control_score_sum', $payload['lote2_field_control_score_sum']);
        $this->assertSame('treatment_score_sum', $payload['lote2_field_treatment_score_sum']);
        $this->assertSame('delta_sum', $payload['lote2_field_delta_sum']);
        $this->assertSame('missing_tables', $payload['lote2_field_missing_tables']);
        $this->assertSame('time_per_loop', $payload['lote2_field_time_per_loop']);
        $this->assertSame('assist_sessions', $payload['adversarial_field_assist_sessions']);
        $this->assertSame('acceptance_rate', $payload['adversarial_field_acceptance_rate']);
        $this->assertSame('severe_hallucination_count', $payload['adversarial_field_severe_hallucination_count']);
        $this->assertSame('metrics_authority', $payload['adversarial_field_metrics_authority']);
        $this->assertSame('eligible', $payload['adversarial_field_eligible']);
        $this->assertSame('code', $payload['adversarial_field_code']);
        $this->assertSame(18, $payload['longhorizon_lote2_adversarial_floor_count']);
    }

    public function test_immune_window_evidence_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->immuneWindowEvidenceFloorsContractObserve([]);

        $this->assertSame('signature_family', $payload['immune_field_signature_family']);
        $this->assertSame('first_seen', $payload['immune_field_first_seen']);
        $this->assertSame('family', $payload['immune_field_family']);
        $this->assertSame('hit_count_after', $payload['immune_field_hit_count_after']);
        $this->assertSame('pending_reason', $payload['immune_field_pending_reason']);
        $this->assertSame('acceptance_floor', $payload['immune_field_acceptance_floor']);
        $this->assertSame('depends_on', $payload['window_field_depends_on']);
        $this->assertSame('watchdog_alert', $payload['window_field_watchdog_alert']);
        $this->assertSame('started_at', $payload['window_field_started_at']);
        $this->assertSame('shadow_minimum_window', $payload['window_field_shadow_minimum_window']);
        $this->assertSame('starts_windows', $payload['window_field_starts_windows']);
        $this->assertSame('critical_path', $payload['window_field_critical_path']);
        $this->assertSame('series', $payload['evidence_field_series']);
        $this->assertSame('stage', $payload['evidence_field_stage']);
        $this->assertSame('yield', $payload['evidence_field_yield']);
        $this->assertSame('n_realized', $payload['evidence_field_n_realized']);
        $this->assertSame('realized_true', $payload['evidence_field_realized_true']);
        $this->assertSame('consecutive_windows', $payload['evidence_field_consecutive_windows']);
        $this->assertSame(18, $payload['immune_window_evidence_floor_count']);
    }

    public function test_health_canary_maxa04_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->healthCanaryMaxa04FloorsContractObserve([]);

        $this->assertSame('service_class', $payload['health_field_service_class']);
        $this->assertSame('bypass_rate', $payload['health_field_bypass_rate']);
        $this->assertSame('days_in_block', $payload['health_field_days_in_block']);
        $this->assertSame('windowed_concentration_ratio', $payload['health_field_windowed_concentration_ratio']);
        $this->assertSame('snapshot_age_hours', $payload['health_field_snapshot_age_hours']);
        $this->assertSame('max_age_hours', $payload['health_field_max_age_hours']);
        $this->assertSame('flows_available', $payload['canary_field_flows_available']);
        $this->assertSame('entries', $payload['canary_field_entries']);
        $this->assertSame('non_canonical', $payload['canary_field_non_canonical']);
        $this->assertSame('by_kind', $payload['canary_field_by_kind']);
        $this->assertSame('memory_recall_golden_versions', $payload['canary_field_memory_recall_golden_versions']);
        $this->assertSame('ref_stability_floor', $payload['canary_field_ref_stability_floor']);
        $this->assertSame('query_id', $payload['maxa04_field_query_id']);
        $this->assertSame('current_recall_at_5', $payload['maxa04_field_current_recall_at_5']);
        $this->assertSame('current_precision_at_5', $payload['maxa04_field_current_precision_at_5']);
        $this->assertSame('current_recall_at_5_mean', $payload['maxa04_field_current_recall_at_5_mean']);
        $this->assertSame('current_precision_at_5_mean', $payload['maxa04_field_current_precision_at_5_mean']);
        $this->assertSame('live_flip_performed', $payload['maxa04_field_live_flip_performed']);
        $this->assertSame(18, $payload['health_canary_maxa04_floor_count']);
    }

    public function test_joint_lote2_horizon_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->jointLote2HorizonFloorsContractObserve([]);

        $this->assertSame('measured_headroom_mb', $payload['joint_field_measured_headroom_mb']);
        $this->assertSame('over_cap_components', $payload['joint_field_over_cap_components']);
        $this->assertSame('host_ram_gib', $payload['joint_field_host_ram_gib']);
        $this->assertSame('engine_floor_gib', $payload['joint_field_engine_floor_gib']);
        $this->assertSame('total_ram_cap_mb', $payload['joint_field_total_ram_cap_mb']);
        $this->assertSame('paper_headroom_mb', $payload['joint_field_paper_headroom_mb']);
        $this->assertSame('p50_seconds', $payload['lote2_field_p50_seconds']);
        $this->assertSame('p95_seconds', $payload['lote2_field_p95_seconds']);
        $this->assertSame('marco_esp_v1', $payload['lote2_field_marco_esp_v1']);
        $this->assertSame('satisfied', $payload['lote2_field_satisfied']);
        $this->assertSame('valid_loop_definition', $payload['lote2_field_valid_loop_definition']);
        $this->assertSame('requires_zero_fixture', $payload['lote2_field_requires_zero_fixture']);
        $this->assertSame('day_count', $payload['horizon_field_day_count']);
        $this->assertSame('calendar_span', $payload['horizon_field_calendar_span']);
        $this->assertSame('resolved_evidence', $payload['horizon_field_resolved_evidence']);
        $this->assertSame('future_dated', $payload['horizon_field_future_dated']);
        $this->assertSame('window_stale', $payload['horizon_field_window_stale']);
        $this->assertSame('gap', $payload['horizon_field_gap']);
        $this->assertSame(18, $payload['joint_lote2_horizon_floor_count']);
    }

    public function test_threshold_http_immune_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->thresholdHttpImmuneFloorsContractObserve([]);

        $this->assertSame('value', $payload['threshold_field_value']);
        $this->assertSame('thresholds', $payload['threshold_field_thresholds']);
        $this->assertSame('rank', $payload['threshold_field_rank']);
        $this->assertSame('metric', $payload['threshold_field_metric']);
        $this->assertSame('comparator', $payload['threshold_field_comparator']);
        $this->assertSame('level', $payload['threshold_field_level']);
        $this->assertSame('schema', $payload['http_field_schema']);
        $this->assertSame('latency_ms', $payload['http_field_latency_ms']);
        $this->assertSame('layer', $payload['http_field_layer']);
        $this->assertSame('requires_ap', $payload['http_field_requires_ap']);
        $this->assertSame('payload', $payload['http_field_payload']);
        $this->assertSame('http_status', $payload['http_field_http_status']);
        $this->assertSame('sample_label', $payload['immune_ingest_field_sample_label']);
        $this->assertSame('promotion_status', $payload['immune_ingest_field_promotion_status']);
        $this->assertSame('writer', $payload['immune_ingest_field_writer']);
        $this->assertSame('matched_signals', $payload['immune_ingest_field_matched_signals']);
        $this->assertSame('memory_id', $payload['immune_ingest_field_memory_id']);
        $this->assertSame('decision_id', $payload['immune_ingest_field_decision_id']);
        $this->assertSame(18, $payload['threshold_http_immune_floor_count']);
    }

    public function test_ncapture_asef_spec_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->ncaptureAsefSpecFloorsContractObserve([]);

        $this->assertSame('engine_id', $payload['ncapture_field_engine_id']);
        $this->assertSame('trigger', $payload['ncapture_field_trigger']);
        $this->assertSame('recorded_at', $payload['ncapture_field_recorded_at']);
        $this->assertSame('required_fields', $payload['ncapture_field_required_fields']);
        $this->assertSame('ttl_days', $payload['ncapture_field_ttl_days']);
        $this->assertSame('judge_engine_id', $payload['ncapture_field_judge_engine_id']);
        $this->assertSame('source_hash', $payload['asef_field_source_hash']);
        $this->assertSame('chunk_index', $payload['asef_field_chunk_index']);
        $this->assertSame('updated_at', $payload['asef_field_updated_at']);
        $this->assertSame('embedded_content_hash', $payload['asef_field_embedded_content_hash']);
        $this->assertSame('created_at', $payload['asef_field_created_at']);
        $this->assertSame('manifest_status', $payload['asef_field_manifest_status']);
        $this->assertSame('field', $payload['spec_field_field']);
        $this->assertSame('weight_loss', $payload['spec_field_weight_loss']);
        $this->assertSame('total_score', $payload['spec_field_total_score']);
        $this->assertSame('earned', $payload['spec_field_earned']);
        $this->assertSame('schema_version', $payload['spec_field_schema_version']);
        $this->assertSame('verdict', $payload['spec_field_verdict']);
        $this->assertSame(18, $payload['ncapture_asef_spec_floor_count']);
    }

    public function test_execution_quality_immune_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->executionQualityImmuneFloorsContractObserve([]);

        $this->assertSame('context_causal_binding', $payload['execution_field_context_causal_binding']);
        $this->assertSame('run_id', $payload['execution_field_run_id']);
        $this->assertSame('outcome_receipt_id', $payload['execution_field_outcome_receipt_id']);
        $this->assertSame('green_run', $payload['execution_field_green_run']);
        $this->assertSame('enforcement_allowed', $payload['execution_field_enforcement_allowed']);
        $this->assertSame('claim_policy', $payload['execution_field_claim_policy']);
        $this->assertSame('evaluated_window_days', $payload['quality_field_evaluated_window_days']);
        $this->assertSame('department_id', $payload['quality_field_department_id']);
        $this->assertSame('breach_count', $payload['quality_field_breach_count']);
        $this->assertSame('evidence_hash', $payload['quality_field_evidence_hash']);
        $this->assertSame('threshold_breaches', $payload['quality_field_threshold_breaches']);
        $this->assertSame('schema_version', $payload['quality_field_schema_version']);
        $this->assertSame('finding_id', $payload['immune_check_field_finding_id']);
        $this->assertSame('decision_surface', $payload['immune_check_field_decision_surface']);
        $this->assertSame('target_paths', $payload['immune_check_field_target_paths']);
        $this->assertSame('gate_statuses', $payload['immune_check_field_gate_statuses']);
        $this->assertSame('autonomous_execution_allowed', $payload['immune_check_field_autonomous_execution_allowed']);
        $this->assertSame('blockers', $payload['immune_check_field_blockers']);
        $this->assertSame(18, $payload['execution_quality_immune_floor_count']);
    }

    public function test_health_lote2_horizon_residual_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->healthLote2HorizonResidualFloorsContractObserve([]);

        $this->assertSame('with_recalled_memory', $payload['watchdog_health_field_with_recalled_memory']);
        $this->assertSame('without_recalled_memory', $payload['watchdog_health_field_without_recalled_memory']);
        $this->assertSame('lift_status', $payload['watchdog_health_field_lift_status']);
        $this->assertSame('coverage', $payload['watchdog_health_field_coverage']);
        $this->assertSame('last_ingest_at', $payload['watchdog_health_field_last_ingest_at']);
        $this->assertSame('memory_cross_layer_coverage_ratio', $payload['watchdog_health_field_memory_cross_layer_coverage_ratio']);
        $this->assertSame('synthetic_fixture_claim_allowed', $payload['lote2_field_synthetic_fixture_claim_allowed']);
        $this->assertSame('requires_proven_real_outcome', $payload['lote2_field_requires_proven_real_outcome']);
        $this->assertSame('outcome_id', $payload['lote2_field_outcome_id']);
        $this->assertSame('loop_id', $payload['lote2_field_loop_id']);
        $this->assertSame('fixture_free', $payload['lote2_field_fixture_free']);
        $this->assertSame('by_lesson_class', $payload['lote2_field_by_lesson_class']);
        $this->assertSame('backfilled', $payload['long_horizon_field_backfilled']);
        $this->assertSame('scorecard_hash', $payload['long_horizon_field_scorecard_hash']);
        $this->assertSame('min_area_scores', $payload['long_horizon_field_min_area_scores']);
        $this->assertSame('area_days_below_floor', $payload['long_horizon_field_area_days_below_floor']);
        $this->assertSame('days_below_floor', $payload['long_horizon_field_days_below_floor']);
        $this->assertSame('benchmark_claim_allowed', $payload['long_horizon_field_benchmark_claim_allowed']);
        $this->assertSame(18, $payload['health_lote2_horizon_residual_floor_count']);
    }

    public function test_health_lote2_horizon_depth_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->healthLote2HorizonDepthFloorsContractObserve([]);

        $this->assertSame('age_hours', $payload['watchdog_health_field_age_hours']);
        $this->assertSame('available', $payload['watchdog_health_field_available']);
        $this->assertSame('blocker', $payload['watchdog_health_field_blocker']);
        $this->assertSame('autonomos', $payload['watchdog_health_field_autonomos']);
        $this->assertSame('aurg_cross_layer_coverage_ratio', $payload['watchdog_health_field_aurg_cross_layer_coverage_ratio']);
        $this->assertSame('aemor_source_max_age_hours', $payload['watchdog_health_field_aemor_source_max_age_hours']);
        $this->assertSame('admission_door', $payload['lote2_field_admission_door']);
        $this->assertSame('actual_merge_count', $payload['lote2_field_actual_merge_count']);
        $this->assertSame('attributed_delta', $payload['lote2_field_attributed_delta']);
        $this->assertSame('bands', $payload['lote2_field_bands']);
        $this->assertSame('basis', $payload['lote2_field_basis']);
        $this->assertSame('buckets', $payload['lote2_field_buckets']);
        $this->assertSame('assessment', $payload['long_horizon_field_assessment']);
        $this->assertSame('by_area', $payload['long_horizon_field_by_area']);
        $this->assertSame('completion_claim_allowed', $payload['long_horizon_field_completion_claim_allowed']);
        $this->assertSame('dates', $payload['long_horizon_field_dates']);
        $this->assertSame('floors', $payload['long_horizon_field_floors']);
        $this->assertSame('evidence', $payload['long_horizon_field_evidence']);
        $this->assertSame(18, $payload['health_lote2_horizon_depth_floor_count']);
    }

    public function test_runbook_immune_promoter_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->runbookImmunePromoterFloorsContractObserve([]);

        $this->assertSame('architect_signatures_count', $payload['runbook_field_architect_signatures_count']);
        $this->assertSame('autonomy_level', $payload['runbook_field_autonomy_level']);
        $this->assertSame('current', $payload['runbook_field_current']);
        $this->assertSame('current_state_snapshot_hash', $payload['runbook_field_current_state_snapshot_hash']);
        $this->assertSame('default_flow', $payload['runbook_field_default_flow']);
        $this->assertSame('department', $payload['runbook_field_department']);
        $this->assertSame('atomic_claim_present', $payload['immune_cal_field_atomic_claim_present']);
        $this->assertSame('author_engine_id', $payload['immune_cal_field_author_engine_id']);
        $this->assertSame('blocking_gate_ids', $payload['immune_cal_field_blocking_gate_ids']);
        $this->assertSame('blocks_denominator', $payload['immune_cal_field_blocks_denominator']);
        $this->assertSame('bound', $payload['immune_cal_field_bound']);
        $this->assertSame('claim_source_present', $payload['immune_cal_field_claim_source_present']);
        $this->assertSame('decided_at', $payload['promoter_field_decided_at']);
        $this->assertSame('decision', $payload['promoter_field_decision']);
        $this->assertSame('default_off', $payload['promoter_field_default_off']);
        $this->assertSame('description', $payload['promoter_field_description']);
        $this->assertSame('enqueue_effective', $payload['promoter_field_enqueue_effective']);
        $this->assertSame('enqueue_enabled', $payload['promoter_field_enqueue_enabled']);
        $this->assertSame(18, $payload['runbook_immune_promoter_floor_count']);
    }

    public function test_lexical_envelope_cockpit_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->lexicalEnvelopeCockpitFloorsContractObserve([]);

        $this->assertSame('aprendizado', $payload['lexical_field_aprendizado']);
        $this->assertSame('brain', $payload['lexical_field_brain']);
        $this->assertSame('cerebro', $payload['lexical_field_cerebro']);
        $this->assertSame('decisao', $payload['lexical_field_decisao']);
        $this->assertSame('decision', $payload['lexical_field_decision']);
        $this->assertSame('deterministic', $payload['lexical_field_deterministic']);
        $this->assertSame('kind', $payload['envelope_field_kind']);
        $this->assertSame('layer', $payload['envelope_field_layer']);
        $this->assertSame('mission_should_activate', $payload['envelope_field_mission_should_activate']);
        $this->assertSame('mission_signal_kind', $payload['envelope_field_mission_signal_kind']);
        $this->assertSame('placement', $payload['envelope_field_placement']);
        $this->assertSame('placement_domain', $payload['envelope_field_placement_domain']);
        $this->assertSame('implementable_supply', $payload['cockpit_field_implementable_supply']);
        $this->assertSame('intent_id', $payload['cockpit_field_intent_id']);
        $this->assertSame('malformed', $payload['cockpit_field_malformed']);
        $this->assertSame('malformed_count', $payload['cockpit_field_malformed_count']);
        $this->assertSame('next_phase', $payload['cockpit_field_next_phase']);
        $this->assertSame('operator_signature_required', $payload['cockpit_field_operator_signature_required']);
        $this->assertSame(18, $payload['lexical_envelope_cockpit_floor_count']);
    }

    public function test_ncapture_scorecard_esp09_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->ncaptureScorecardEsp09FloorsContractObserve([]);

        $this->assertSame('dual_read_required', $payload['ncapture_field_dual_read_required']);
        $this->assertSame('engines', $payload['ncapture_field_engines']);
        $this->assertSame('freeze', $payload['ncapture_field_freeze']);
        $this->assertSame('generated_at', $payload['ncapture_field_generated_at']);
        $this->assertSame('golden_v2_passed', $payload['ncapture_field_golden_v2_passed']);
        $this->assertSame('golden_v2_score', $payload['ncapture_field_golden_v2_score']);
        $this->assertSame('cognitive_immune', $payload['scorecard_field_cognitive_immune']);
        $this->assertSame('compounding', $payload['scorecard_field_compounding']);
        $this->assertSame('context_cache', $payload['scorecard_field_context_cache']);
        $this->assertSame('context_intelligence', $payload['scorecard_field_context_intelligence']);
        $this->assertSame('context_quality', $payload['scorecard_field_context_quality']);
        $this->assertSame('cross_domain', $payload['scorecard_field_cross_domain']);
        $this->assertSame('accepted_rate', $payload['esp09_field_accepted_rate']);
        $this->assertSame('alternative', $payload['esp09_field_alternative']);
        $this->assertSame('challenger_block_present', $payload['esp09_field_challenger_block_present']);
        $this->assertSame('death_review_candidate', $payload['esp09_field_death_review_candidate']);
        $this->assertSame('death_review_reason', $payload['esp09_field_death_review_reason']);
        $this->assertSame('denominator', $payload['esp09_field_denominator']);
        $this->assertSame(18, $payload['ncapture_scorecard_esp09_floor_count']);
    }

    public function test_flywheel_immune_obra_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->flywheelImmuneObraFloorsContractObserve([]);

        $this->assertSame('actor', $payload['flywheel_field_actor']);
        $this->assertSame('claim_policy', $payload['flywheel_field_claim_policy']);
        $this->assertSame('denominator_min', $payload['flywheel_field_denominator_min']);
        $this->assertSame('diagnostic_only', $payload['flywheel_field_diagnostic_only']);
        $this->assertSame('learning_status', $payload['flywheel_field_learning_status']);
        $this->assertSame('lesson_promoted', $payload['flywheel_field_lesson_promoted']);
        $this->assertSame('calibration_authority', $payload['immune_freeze_field_calibration_authority']);
        $this->assertSame('candidate_text_leaves_machine', $payload['immune_freeze_field_candidate_text_leaves_machine']);
        $this->assertSame('config_key', $payload['immune_freeze_field_config_key']);
        $this->assertSame('content_hash', $payload['immune_freeze_field_content_hash']);
        $this->assertSame('corpus_path', $payload['immune_freeze_field_corpus_path']);
        $this->assertSame('corpus_sha256', $payload['immune_freeze_field_corpus_sha256']);
        $this->assertSame('executable', $payload['obra_field_executable']);
        $this->assertSame('falsified_when', $payload['obra_field_falsified_when']);
        $this->assertSame('fqcn', $payload['obra_field_fqcn']);
        $this->assertSame('graph', $payload['obra_field_graph']);
        $this->assertSame('individual_gate_required', $payload['obra_field_individual_gate_required']);
        $this->assertSame('judge_engine_id', $payload['obra_field_judge_engine_id']);
        $this->assertSame(18, $payload['flywheel_immune_obra_floor_count']);
    }

    public function test_health_lote2_runbook_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->healthLote2RunbookFloorsContractObserve([]);

        $this->assertSame('blocker_series', $payload['health_field_blocker_series']);
        $this->assertSame('compaction_count', $payload['health_field_compaction_count']);
        $this->assertSame('days', $payload['health_field_days']);
        $this->assertSame('delivered_refs_share', $payload['health_field_delivered_refs_share']);
        $this->assertSame('edges_by_source', $payload['health_field_edges_by_source']);
        $this->assertSame('first_seen_at', $payload['health_field_first_seen_at']);
        $this->assertSame('bucket_width_weeks', $payload['lote2_field_bucket_width_weeks']);
        $this->assertSame('chain', $payload['lote2_field_chain']);
        $this->assertSame('control', $payload['lote2_field_control']);
        $this->assertSame('invalid_pairs', $payload['lote2_field_invalid_pairs']);
        $this->assertSame('loop', $payload['lote2_field_loop']);
        $this->assertSame('mode', $payload['lote2_field_mode']);
        $this->assertSame('gates', $payload['runbook_field_gates']);
        $this->assertSame('intent_class', $payload['runbook_field_intent_class']);
        $this->assertSame('proposal_hash', $payload['runbook_field_proposal_hash']);
        $this->assertSame('proposed', $payload['runbook_field_proposed']);
        $this->assertSame('proposed_by_actor', $payload['runbook_field_proposed_by_actor']);
        $this->assertSame('structural_changes', $payload['runbook_field_structural_changes']);
        $this->assertSame(18, $payload['health_lote2_runbook_floor_count']);
    }

    public function test_health_lote2_horizon_more_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->healthLote2HorizonMoreFloorsContractObserve([]);

        $this->assertSame('fp_definition', $payload['health_field_fp_definition']);
        $this->assertSame('hours', $payload['health_field_hours']);
        $this->assertSame('partial_count', $payload['health_field_partial_count']);
        $this->assertSame('pipeline_status', $payload['health_field_pipeline_status']);
        $this->assertSame('recall_at_5', $payload['health_field_recall_at_5']);
        $this->assertSame('retrieval_eval', $payload['health_field_retrieval_eval']);
        $this->assertSame('operator_requests', $payload['lote2_field_operator_requests']);
        $this->assertSame('peek_policy', $payload['lote2_field_peek_policy']);
        $this->assertSame('peek_policy_violation', $payload['lote2_field_peek_policy_violation']);
        $this->assertSame('policy_valid', $payload['lote2_field_policy_valid']);
        $this->assertSame('positive_lift_fabricated', $payload['lote2_field_positive_lift_fabricated']);
        $this->assertSame('rate', $payload['lote2_field_rate']);
        $this->assertSame('assessment_v2', $payload['horizon_field_assessment_v2']);
        $this->assertSame('recorded_at', $payload['horizon_field_recorded_at']);
        $this->assertSame('scorecard_overall', $payload['horizon_field_scorecard_overall']);
        $this->assertSame('scorecard_report', $payload['horizon_field_scorecard_report']);
        $this->assertSame('series', $payload['horizon_field_series']);
        $this->assertSame('series_v2', $payload['horizon_field_series_v2']);
        $this->assertSame(18, $payload['health_lote2_horizon_more_floor_count']);
    }

    public function test_health_deferred_runner_runbook_golden_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->healthDeferredRunnerRunbookGoldenFloorsContractObserve([]);

        $this->assertSame('scorecard_hash', $payload['health_field_scorecard_hash']);
        $this->assertSame('source', $payload['health_field_source']);
        $this->assertSame('store', $payload['health_field_store']);
        $this->assertSame('subsystems', $payload['health_field_subsystems']);
        $this->assertSame('writer_shares', $payload['health_field_writer_shares']);
        $this->assertSame('phase', $payload['deferred_field_phase']);
        $this->assertSame('blockers', $payload['deferred_field_blockers']);
        $this->assertSame('intent_id', $payload['deferred_field_intent_id']);
        $this->assertSame('schema', $payload['deferred_field_schema']);
        $this->assertSame('correlation_id', $payload['runner_field_correlation_id']);
        $this->assertSame('envelope_id', $payload['runner_field_envelope_id']);
        $this->assertSame('operator_id', $payload['runner_field_operator_id']);
        $this->assertSame('tenant_id', $payload['runner_field_tenant_id']);
        $this->assertSame('target', $payload['runbook_field_target']);
        $this->assertSame('target_doc', $payload['runbook_field_target_doc']);
        $this->assertSame('title', $payload['runbook_field_title']);
        $this->assertSame('touches_sovereignty_layer', $payload['runbook_field_touches_sovereignty_layer']);
        $this->assertSame('arm', $payload['golden_field_arm']);
        $this->assertSame(18, $payload['health_deferred_runner_runbook_golden_floor_count']);
    }

    public function test_lexical_rerank_maturity_budget_volume_immune_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->lexicalRerankMaturityBudgetVolumeImmuneFloorsContractObserve([]);

        $this->assertSame('evidence', $payload['lexical_field_evidence']);
        $this->assertSame('execution', $payload['lexical_field_execution']);
        $this->assertSame('memory', $payload['lexical_field_memory']);
        $this->assertSame('precision_at_k', $payload['rerank_field_precision_at_k']);
        $this->assertSame('reason', $payload['rerank_field_reason']);
        $this->assertSame('schema_version', $payload['rerank_field_schema_version']);
        $this->assertSame('comparator', $payload['maturity_field_comparator']);
        $this->assertSame('metric', $payload['maturity_field_metric']);
        $this->assertSame('value', $payload['maturity_field_value']);
        $this->assertSame('components', $payload['budget_field_components']);
        $this->assertSame('declared_paper_status', $payload['budget_field_declared_paper_status']);
        $this->assertSame('measured_ram_mb', $payload['budget_field_measured_ram_mb']);
        $this->assertSame('end', $payload['volume_field_end']);
        $this->assertSame('label', $payload['volume_field_label']);
        $this->assertSame('start', $payload['volume_field_start']);
        $this->assertSame('default_destination', $payload['immune_hybrid_field_default_destination']);
        $this->assertSame('embedding_allowed', $payload['immune_hybrid_field_embedding_allowed']);
        $this->assertSame('memory_eligible', $payload['immune_hybrid_field_memory_eligible']);
        $this->assertSame(18, $payload['lexical_rerank_maturity_budget_volume_immune_floor_count']);
    }

    public function test_decomposer_evidence_teto_fact_ragx_golden_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->decomposerEvidenceTetoFactRagxGoldenFloorsContractObserve([]);

        $this->assertSame('framework', $payload['decomposer_field_framework']);
        $this->assertSame('privacy_class', $payload['decomposer_field_privacy_class']);
        $this->assertSame('role', $payload['decomposer_field_role']);
        $this->assertSame('impl_files_hash', $payload['evidence_field_impl_files_hash']);
        $this->assertSame('path', $payload['evidence_field_path']);
        $this->assertSame('symbol_ref', $payload['evidence_field_symbol_ref']);
        $this->assertSame('ask_ref', $payload['teto10_field_ask_ref']);
        $this->assertSame('batched_ask', $payload['teto10_field_batched_ask']);
        $this->assertSame('flip_ref', $payload['teto10_field_flip_ref']);
        $this->assertSame('fail_open_entry_allowed', $payload['fact_field_fail_open_entry_allowed']);
        $this->assertSame('schema_version', $payload['fact_field_schema_version']);
        $this->assertSame('status', $payload['fact_field_status']);
        $this->assertSame('matches', $payload['ragx_field_matches']);
        $this->assertSame('result', $payload['ragx_field_result']);
        $this->assertSame('source', $payload['ragx_field_source']);
        $this->assertSame('commit', $payload['golden_field_commit']);
        $this->assertSame('executed_at', $payload['golden_field_executed_at']);
        $this->assertSame('run_id', $payload['golden_field_run_id']);
        $this->assertSame(18, $payload['decomposer_evidence_teto_fact_ragx_golden_floor_count']);
    }

    public function test_envelope_integrity_promoter_series_lote2_lexical_substrate_bets_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->envelopeIntegrityPromoterSeriesLote2LexicalSubstrateBetsFloorsContractObserve([]);

        $this->assertSame('certified_receipt_id', $payload['envelope_field_certified_receipt_id']);
        $this->assertSame('provider', $payload['envelope_field_provider']);
        $this->assertSame('task_category', $payload['envelope_field_task_category']);
        $this->assertSame('model_verified', $payload['integrity_field_model_verified']);
        $this->assertSame('sha256_computed', $payload['integrity_field_sha256_computed']);
        $this->assertSame('sha256_pin', $payload['integrity_field_sha256_pin']);
        $this->assertSame('fake_green_suppressed', $payload['promoter_field_fake_green_suppressed']);
        $this->assertSame('success_rate', $payload['promoter_field_success_rate']);
        $this->assertSame('successes', $payload['promoter_field_successes']);
        $this->assertSame('scope_id', $payload['series_field_scope_id']);
        $this->assertSame('scope_type', $payload['series_field_scope_type']);
        $this->assertSame('where', $payload['series_field_where']);
        $this->assertSame('treatment', $payload['lote2_field_treatment']);
        $this->assertSame('usage_rows_recorded', $payload['lote2_field_usage_rows_recorded']);
        $this->assertSame('window_days', $payload['lote2_field_window_days']);
        $this->assertSame('verification', $payload['lexical_field_verification']);
        $this->assertSame('checked_at', $payload['substrate_field_checked_at']);
        $this->assertSame('suspension_update', $payload['bets_field_suspension_update']);
        $this->assertSame(18, $payload['envelope_integrity_promoter_series_lote2_lexical_substrate_bets_floor_count']);
    }

    public function test_pareto_window_cockpit_obra_ambition_dead_scorecard_vision_esp09_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->paretoWindowCockpitObraAmbitionDeadScorecardVisionEsp09FloorsContractObserve([]);

        $this->assertSame('dominated_by', $payload['pareto_field_dominated_by']);
        $this->assertSame('status', $payload['pareto_field_status']);
        $this->assertSame('generated_at', $payload['window_field_generated_at']);
        $this->assertSame('target', $payload['window_field_target']);
        $this->assertSame('phase_out', $payload['cockpit_field_phase_out']);
        $this->assertSame('servable_now', $payload['cockpit_field_servable_now']);
        $this->assertSame('summary', $payload['obra_field_summary']);
        $this->assertSame('obra_cluster_candidate', $payload['obra_field_obra_cluster_candidate']);
        $this->assertSame('rung', $payload['ambition_field_rung']);
        $this->assertSame('leverage', $payload['ambition_field_leverage']);
        $this->assertSame('status', $payload['dead_field_status']);
        $this->assertSame('ttl_source', $payload['dead_field_ttl_source']);
        $this->assertSame('name', $payload['scorecard_field_name']);
        $this->assertSame('overall_out_of_10', $payload['scorecard_field_overall_out_of_10']);
        $this->assertSame('threshold', $payload['vision_field_threshold']);
        $this->assertSame('window', $payload['vision_field_window']);
        $this->assertSame('proposed_choice', $payload['esp09_field_proposed_choice']);
        $this->assertSame('refutation', $payload['esp09_field_refutation']);
        $this->assertSame(18, $payload['pareto_window_cockpit_obra_ambition_dead_scorecard_vision_esp09_floor_count']);
    }

    public function test_evolution_reality_freshness_nudge_immune_share_flywheel_aemor_quality_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->evolutionRealityFreshnessNudgeImmuneShareFlywheelAemorQualityFloorsContractObserve([]);

        $this->assertSame('max', $payload['evolution_field_max']);
        $this->assertSame('signals', $payload['evolution_field_signals']);
        $this->assertSame('phase', $payload['reality_field_phase']);
        $this->assertSame('status', $payload['reality_field_status']);
        $this->assertSame('timestamp_field', $payload['freshness_field_timestamp_field']);
        $this->assertSame('table', $payload['freshness_field_table']);
        $this->assertSame('audit', $payload['nudge_field_audit']);
        $this->assertSame('retrieval', $payload['nudge_field_retrieval']);
        $this->assertSame('recalls', $payload['immune_field_recalls']);
        $this->assertSame('per_actor', $payload['immune_field_per_actor']);
        $this->assertSame('total_count', $payload['share_field_total_count']);
        $this->assertSame('verified_share', $payload['share_field_verified_share']);
        $this->assertSame('promoted_lesson_cited', $payload['flywheel_field_promoted_lesson_cited']);
        $this->assertSame('promoted_lesson_recalled', $payload['flywheel_field_promoted_lesson_recalled']);
        $this->assertSame('objective', $payload['aemor_field_objective']);
        $this->assertSame('run_id', $payload['aemor_field_run_id']);
        $this->assertSame('evaluated_metrics', $payload['quality_field_evaluated_metrics']);
        $this->assertSame('thresholds', $payload['quality_field_thresholds']);
        $this->assertSame(18, $payload['evolution_reality_freshness_nudge_immune_share_flywheel_aemor_quality_floor_count']);
    }

    public function test_promotion_parallel_docs_reality_evidence_repair_architect_delivery_scorecard_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->promotionParallelDocsRealityEvidenceRepairArchitectDeliveryScorecardFloorsContractObserve([]);

        $this->assertSame('current_score', $payload['promotion_field_current_score']);
        $this->assertSame('unresolved', $payload['promotion_field_unresolved']);
        $this->assertSame('cwd', $payload['parallel_field_cwd']);
        $this->assertSame('workspace', $payload['parallel_field_workspace']);
        $this->assertSame('owner_doc_id', $payload['docs_field_owner_doc_id']);
        $this->assertSame('owner_implementation_state', $payload['docs_field_owner_implementation_state']);
        $this->assertSame('autonomy_level', $payload['reality_field_autonomy_level']);
        $this->assertSame('intent', $payload['reality_field_intent']);
        $this->assertSame('kind', $payload['evidence_field_kind']);
        $this->assertSame('ref', $payload['evidence_field_ref']);
        $this->assertSame('escalate_to', $payload['repair_field_escalate_to']);
        $this->assertSame('remaining_repairs', $payload['repair_field_remaining_repairs']);
        $this->assertSame('acceptance_criteria_present', $payload['architect_field_acceptance_criteria_present']);
        $this->assertSame('breaking_change_matrix_present', $payload['architect_field_breaking_change_matrix_present']);
        $this->assertSame('ratio', $payload['delivery_field_ratio']);
        $this->assertSame('receipt_present', $payload['delivery_field_receipt_present']);
        $this->assertSame('supplemental_count', $payload['scorecard_field_supplemental_count']);
        $this->assertSame('evidence', $payload['scorecard_field_evidence']);
        $this->assertSame(18, $payload['promotion_parallel_docs_reality_evidence_repair_architect_delivery_scorecard_floor_count']);
    }

    public function test_integrity_architect_veto_bets_promotion_freeze_compounding_resolver_budget_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->integrityArchitectVetoBetsPromotionFreezeCompoundingResolverBudgetFloorsContractObserve([]);

        $this->assertSame('artifacts', $payload['integrity_field_artifacts']);
        $this->assertSame('generated_at', $payload['integrity_field_generated_at']);
        $this->assertSame('operator_signature_present', $payload['architect_field_operator_signature_present']);
        $this->assertSame('risk_scope', $payload['architect_field_risk_scope']);
        $this->assertSame('paused_departments', $payload['veto_field_paused_departments']);
        $this->assertSame('department', $payload['veto_field_department']);
        $this->assertSame('window_id', $payload['bets_field_window_id']);
        $this->assertSame('deletes_suspended_family', $payload['bets_field_deletes_suspended_family']);
        $this->assertSame('operator_alignment', $payload['promotion_field_operator_alignment']);
        $this->assertSame('event', $payload['promotion_field_event']);
        $this->assertSame('judge_engine_id', $payload['freeze_field_judge_engine_id']);
        $this->assertSame('fixtures', $payload['freeze_field_fixtures']);
        $this->assertSame('outcome_contract_v2', $payload['compounding_field_outcome_contract_v2']);
        $this->assertSame('episode_id', $payload['compounding_field_episode_id']);
        $this->assertSame('memory', $payload['resolver_field_memory']);
        $this->assertSame('auto_escalated', $payload['resolver_field_auto_escalated']);
        $this->assertSame('components', $payload['budget_field_components']);
        $this->assertSame('declared_paper_status', $payload['budget_field_declared_paper_status']);
        $this->assertSame(18, $payload['integrity_architect_veto_bets_promotion_freeze_compounding_resolver_budget_floor_count']);
    }

    public function test_architect_rollback_ledger_work_substrate_decay_dod_capability_maturity_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->architectRollbackLedgerWorkSubstrateDecayDodCapabilityMaturityFloorsContractObserve([]);

        $this->assertSame('rollback_plan_present', $payload['architect_field_rollback_plan_present']);
        $this->assertSame('spec_pack_hash', $payload['architect_field_spec_pack_hash']);
        $this->assertSame('any_env', $payload['rollback_field_any_env']);
        $this->assertSame('alert_code', $payload['rollback_field_alert_code']);
        $this->assertSame('legacy_unchained_count', $payload['ledger_field_legacy_unchained_count']);
        $this->assertSame('artifact', $payload['ledger_field_artifact']);
        $this->assertSame('autonomy_level', $payload['work_field_autonomy_level']);
        $this->assertSame('blocking_reasons', $payload['work_field_blocking_reasons']);
        $this->assertSame('snapshot_path', $payload['substrate_field_snapshot_path']);
        $this->assertSame('restored_ok', $payload['substrate_field_restored_ok']);
        $this->assertSame('recall_eval_hit_rate', $payload['decay_field_recall_eval_hit_rate']);
        $this->assertSame('base_priority', $payload['decay_field_base_priority']);
        $this->assertSame('subject', $payload['dod_field_subject']);
        $this->assertSame('code_command_applicable', $payload['dod_field_code_command_applicable']);
        $this->assertSame('latency_per_pair_ms_p95', $payload['capability_field_latency_per_pair_ms_p95']);
        $this->assertSame('functions', $payload['capability_field_functions']);
        $this->assertSame('owner', $payload['maturity_field_owner']);
        $this->assertSame('blockers_to_next', $payload['maturity_field_blockers_to_next']);
        $this->assertSame(18, $payload['architect_rollback_ledger_work_substrate_decay_dod_capability_maturity_floor_count']);
    }

    public function test_delivery_immune_registry_operator_lote2_health_horizon_promoter_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->deliveryImmuneRegistryOperatorLote2HealthHorizonPromoterFloorsContractObserve([]);

        $this->assertSame('risk_register_present', $payload['delivery_field_risk_register_present']);
        $this->assertSame('blockers', $payload['delivery_field_blockers']);
        $this->assertSame('positive_actor_count', $payload['immune_field_positive_actor_count']);
        $this->assertSame('actor', $payload['immune_field_actor']);
        $this->assertSame('escalation_to', $payload['registry_field_escalation_to']);
        $this->assertSame('blockers', $payload['registry_field_blockers']);
        $this->assertSame('missing_tables', $payload['operator_field_missing_tables']);
        $this->assertSame('chat_capture_enabled', $payload['operator_field_chat_capture_enabled']);
        $this->assertSame('cadence', $payload['debt_field_cadence']);
        $this->assertSame('operator_review_debt', $payload['debt_field_operator_review_debt']);
        $this->assertSame('abandoned_count_as_not_completed', $payload['lote2_field_abandoned_count_as_not_completed']);
        $this->assertSame('arm', $payload['lote2_field_arm']);
        $this->assertSame('adml_cost_outcome', $payload['health_field_adml_cost_outcome']);
        $this->assertSame('adml_proven_route_volume_below_floor', $payload['health_field_adml_proven_route_volume_below_floor']);
        $this->assertSame('acos_long_horizon_gate_disabled', $payload['horizon_field_acos_long_horizon_gate_disabled']);
        $this->assertSame('certification_window_days_below_floor', $payload['horizon_field_certification_window_days_below_floor']);
        $this->assertSame('enqueue_requested', $payload['promoter_field_enqueue_requested']);
        $this->assertSame('evidence_refs', $payload['promoter_field_evidence_refs']);
        $this->assertSame(18, $payload['delivery_immune_registry_operator_lote2_health_horizon_promoter_floor_count']);
    }

    public function test_lote2_health_horizon_promoter_capture_obra_dual_truth_vision_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->lote2HealthHorizonPromoterCaptureObraDualTruthVisionFloorsContractObserve([]);

        $this->assertSame('asks_per_request', $payload['lote2_field_asks_per_request']);
        $this->assertSame('blocked_by_top', $payload['lote2_field_blocked_by_top']);
        $this->assertSame('adml_proven_routes', $payload['health_field_adml_proven_routes']);
        $this->assertSame('ai_rag_feedback_events_table_missing', $payload['health_field_ai_rag_feedback_events_table_missing']);
        $this->assertSame('certification_window_end', $payload['horizon_field_certification_window_end']);
        $this->assertSame('certification_window_sample_count', $payload['horizon_field_certification_window_sample_count']);
        $this->assertSame('floor_pending', $payload['promoter_field_floor_pending']);
        $this->assertSame('forbidden_actions', $payload['promoter_field_forbidden_actions']);
        $this->assertSame('hours_of_integration', $payload['capture_field_hours_of_integration']);
        $this->assertSame('latest', $payload['capture_field_latest']);
        $this->assertSame('kill_gate', $payload['obra_field_kill_gate']);
        $this->assertSame('member_paths', $payload['obra_field_member_paths']);
        $this->assertSame('command', $payload['dual_field_command']);
        $this->assertSame('default_model_unchanged', $payload['dual_field_default_model_unchanged']);
        $this->assertSame('graph_id', $payload['truth_field_graph_id']);
        $this->assertSame('index_resolved', $payload['truth_field_index_resolved']);
        $this->assertSame('bands', $payload['vision_field_bands']);
        $this->assertSame('calibration', $payload['vision_field_calibration']);
        $this->assertSame(18, $payload['lote2_health_horizon_promoter_capture_obra_dual_truth_vision_floor_count']);
    }

    public function test_registry_spec_summary_memory_segment_pareto_recall_outcome_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->registrySpecSummaryMemorySegmentParetoRecallOutcomeFloorsContractObserve([]);

        $this->assertSame('schema_version', $payload['registry_field_schema_version']);
        $this->assertSame('department_count', $payload['registry_field_department_count']);
        $this->assertSame('fields', $payload['spec_field_fields']);
        $this->assertSame('missing_or_weak', $payload['spec_field_missing_or_weak']);
        $this->assertSame('context_retention_score', $payload['summary_field_context_retention_score']);
        $this->assertSame('decision_total', $payload['summary_field_decision_total']);
        $this->assertSame('admitted', $payload['budget_field_admitted']);
        $this->assertSame('admitted_count', $payload['budget_field_admitted_count']);
        $this->assertSame('last_used_at_age_days', $payload['decay_field_last_used_at_age_days']);
        $this->assertSame('negative_count', $payload['decay_field_negative_count']);
        $this->assertSame('boundary_index', $payload['segment_field_boundary_index']);
        $this->assertSame('dropped_count', $payload['segment_field_dropped_count']);
        $this->assertSame('admitted', $payload['pareto_field_admitted']);
        $this->assertSame('equals', $payload['pareto_field_equals']);
        $this->assertSame('engineering_run', $payload['recall_field_engineering_run']);
        $this->assertSame('feedback', $payload['recall_field_feedback']);
        $this->assertSame('allowed_files_sufficient', $payload['outcome_field_allowed_files_sufficient']);
        $this->assertSame('alternative_explanations', $payload['outcome_field_alternative_explanations']);
        $this->assertSame(18, $payload['registry_spec_summary_memory_segment_pareto_recall_outcome_floor_count']);
    }

    public function test_impact_advisory_esp09_dogfood_saturation_budget_ambition_asef_lexical_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->impactAdvisoryEsp09DogfoodSaturationBudgetAmbitionAsefLexicalFloorsContractObserve([]);

        $this->assertSame('bands', $payload['impact_field_bands']);
        $this->assertSame('caller_declared_band_ignored', $payload['impact_field_caller_declared_band_ignored']);
        $this->assertSame('band', $payload['advisory_field_band']);
        $this->assertSame('blocks_auto_apply', $payload['advisory_field_blocks_auto_apply']);
        $this->assertSame('elev18_engine_ids_distinct', $payload['esp09_field_elev18_engine_ids_distinct']);
        $this->assertSame('high_alignment_band', $payload['esp09_field_high_alignment_band']);
        $this->assertSame('lead_only_not_seed', $payload['dogfood_field_lead_only_not_seed']);
        $this->assertSame('leads', $payload['dogfood_field_leads']);
        $this->assertSame('disables_reactive_lane', $payload['saturation_field_disables_reactive_lane']);
        $this->assertSame('provider_calls_made', $payload['saturation_field_provider_calls_made']);
        $this->assertSame('allocator_writes_own_weights', $payload['budget_field_allocator_writes_own_weights']);
        $this->assertSame('ceiling_absolute', $payload['budget_field_ceiling_absolute']);
        $this->assertSame('basis', $payload['ambition_field_basis']);
        $this->assertSame('current_rung', $payload['ambition_field_current_rung']);
        $this->assertSame('candidate_set', $payload['asef_field_candidate_set']);
        $this->assertSame('chunk_hit_count', $payload['asef_field_chunk_hit_count']);
        $this->assertSame('equivalences', $payload['lexical_field_equivalences']);
        $this->assertSame('esteira', $payload['lexical_field_esteira']);
        $this->assertSame(18, $payload['impact_advisory_esp09_dogfood_saturation_budget_ambition_asef_lexical_floor_count']);
    }

    public function test_spec_summary_budget_decay_segment_pareto_recall_outcome_corpus_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->specSummaryBudgetDecaySegmentParetoRecallOutcomeCorpusFloorsContractObserve([]);

        $this->assertSame('present_count', $payload['spec_field_present_count']);
        $this->assertSame('total_fields', $payload['spec_field_total_fields']);
        $this->assertSame('digest', $payload['summary_field_digest']);
        $this->assertSame('missed_decision_rate', $payload['summary_field_missed_decision_rate']);
        $this->assertSame('dropped', $payload['budget_field_dropped']);
        $this->assertSame('dropped_count', $payload['budget_field_dropped_count']);
        $this->assertSame('positive_count', $payload['decay_field_positive_count']);
        $this->assertSame('recorded_at_age_days', $payload['decay_field_recorded_at_age_days']);
        $this->assertSame('dropped_ids', $payload['segment_field_dropped_ids']);
        $this->assertSame('dup_group', $payload['segment_field_dup_group']);
        $this->assertSame('evaluated', $payload['pareto_field_evaluated']);
        $this->assertSame('failed_constraints', $payload['pareto_field_failed_constraints']);
        $this->assertSame('harness_learning', $payload['recall_field_harness_learning']);
        $this->assertSame('memory_type', $payload['recall_field_memory_type']);
        $this->assertSame('attribution_blocked', $payload['outcome_field_attribution_blocked']);
        $this->assertSame('attribution_confidence', $payload['outcome_field_attribution_confidence']);
        $this->assertSame('candidate_only', $payload['corpus_field_candidate_only']);
        $this->assertSame('count_is_acceptance', $payload['corpus_field_count_is_acceptance']);
        $this->assertSame(18, $payload['spec_summary_budget_decay_segment_pareto_recall_outcome_corpus_floor_count']);
    }

    public function test_fact_citation_provenance_recall_cascade_vision_cooccur_gate_dispatch_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->factCitationProvenanceRecallCascadeVisionCooccurGateDispatchFloorsContractObserve([]);

        $this->assertSame('decision', $payload['fact_field_decision']);
        $this->assertSame('gotcha', $payload['fact_field_gotcha']);
        $this->assertSame('citation_coverage', $payload['citation_field_citation_coverage']);
        $this->assertSame('delivered_refs', $payload['citation_field_delivered_refs']);
        $this->assertSame('dead_ref_counts_as_weight', $payload['provenance_field_dead_ref_counts_as_weight']);
        $this->assertSame('dead_refs', $payload['provenance_field_dead_refs']);
        $this->assertSame('candidates', $payload['gap_field_candidates']);
        $this->assertSame('query', $payload['gap_field_query']);
        $this->assertSame('caps_hit', $payload['cascade_field_caps_hit']);
        $this->assertSame('cascade_origin', $payload['cascade_field_cascade_origin']);
        $this->assertSame('forbidden_strings', $payload['vision_field_forbidden_strings']);
        $this->assertSame('high', $payload['vision_field_high']);
        $this->assertSame('cooccurrence_count', $payload['cooccur_field_cooccurrence_count']);
        $this->assertSame('delivered_ref_count', $payload['cooccur_field_delivered_ref_count']);
        $this->assertSame('acceptance', $payload['gate_field_acceptance']);
        $this->assertSame('acceptance_criteria', $payload['gate_field_acceptance_criteria']);
        $this->assertSame('blocker_signal', $payload['dispatch_field_blocker_signal']);
        $this->assertSame('dispatch_id', $payload['dispatch_field_dispatch_id']);
        $this->assertSame(18, $payload['fact_citation_provenance_recall_cascade_vision_cooccur_gate_dispatch_floor_count']);
    }

    public function test_summary_budget_decay_segment_pareto_recall_outcome_impact_advisory_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->summaryBudgetDecaySegmentParetoRecallOutcomeImpactAdvisoryFloorsContractObserve([]);

        $this->assertSame('missing_decision_ids', $payload['summary_field_missing_decision_ids']);
        $this->assertSame('missing_item_ids', $payload['summary_field_missing_item_ids']);
        $this->assertSame('estimated_chars', $payload['budget_field_estimated_chars']);
        $this->assertSame('min_excerpt_chars', $payload['budget_field_min_excerpt_chars']);
        $this->assertSame('stale_count', $payload['decay_field_stale_count']);
        $this->assertSame('wrong_context_count', $payload['decay_field_wrong_context_count']);
        $this->assertSame('duplicate', $payload['segment_field_duplicate']);
        $this->assertSame('has_evidence_ref', $payload['segment_field_has_evidence_ref']);
        $this->assertSame('max', $payload['pareto_field_max']);
        $this->assertSame('min', $payload['pareto_field_min']);
        $this->assertSame('project', $payload['recall_field_project']);
        $this->assertSame('rank', $payload['recall_field_rank']);
        $this->assertSame('candidates', $payload['outcome_field_candidates']);
        $this->assertSame('has_evidence_refs', $payload['outcome_field_has_evidence_refs']);
        $this->assertSame('influences_pick', $payload['impact_field_influences_pick']);
        $this->assertSame('realized', $payload['impact_field_realized']);
        $this->assertSame('critical', $payload['advisory_field_critical']);
        $this->assertSame('death_criterion', $payload['advisory_field_death_criterion']);
        $this->assertSame(18, $payload['summary_budget_decay_segment_pareto_recall_outcome_impact_advisory_floor_count']);
    }

    public function test_esp09_dogfood_saturation_budget_ambition_lexical_corpus_fact_citation_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->esp09DogfoodSaturationBudgetAmbitionLexicalCorpusFactCitationFloorsContractObserve([]);

        $this->assertSame('outcome', $payload['esp09_field_outcome']);
        $this->assertSame('promotion_without_block', $payload['esp09_field_promotion_without_block']);
        $this->assertSame('operator_text_in_objective', $payload['dogfood_field_operator_text_in_objective']);
        $this->assertSame('provider_calls_made', $payload['dogfood_field_provider_calls_made']);
        $this->assertSame('uses_queue_empty_as_sole_signal', $payload['saturation_field_uses_queue_empty_as_sole_signal']);
        $this->assertSame('yield', $payload['saturation_field_yield']);
        $this->assertSame('ceiling_bands', $payload['budget_field_ceiling_bands']);
        $this->assertSame('consumer_of_maxk_07', $payload['budget_field_consumer_of_maxk_07']);
        $this->assertSame('provider_calls_made', $payload['ambition_field_provider_calls_made']);
        $this->assertSame('reactive_saturated', $payload['ambition_field_reactive_saturated']);
        $this->assertSame('evidencia', $payload['lexical_field_evidencia']);
        $this->assertSame('execucao', $payload['lexical_field_execucao']);
        $this->assertSame('immune_gates_apply', $payload['corpus_field_immune_gates_apply']);
        $this->assertSame('omitted', $payload['corpus_field_omitted']);
        $this->assertSame('harness_learning', $payload['fact_field_harness_learning']);
        $this->assertSame('llm_extraction_hot_path', $payload['fact_field_llm_extraction_hot_path']);
        $this->assertSame('fuses_grounding_and_coverage', $payload['citation_field_fuses_grounding_and_coverage']);
        $this->assertSame('grounding_rate', $payload['citation_field_grounding_rate']);
        $this->assertSame(18, $payload['esp09_dogfood_saturation_budget_ambition_lexical_corpus_fact_citation_floor_count']);
    }


    public function test_teto_ragx_promotion_envelope_golden_bets_thesis_attempt_cockpit_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->tetoRagxPromotionEnvelopeGoldenBetsThesisAttemptCockpitFloorsContractObserve([]);

        $this->assertSame('slice', $payload['teto_field_slice']);
        $this->assertSame('frontier_plan_section', $payload['teto_field_frontier_plan_section']);
        $this->assertSame('stages', $payload['ragx_field_stages']);
        $this->assertSame('node_count', $payload['ragx_field_node_count']);
        $this->assertSame('event_id', $payload['promotion_field_event_id']);
        $this->assertSame('from_state', $payload['promotion_field_from_state']);
        $this->assertSame('anti_unification_fence', $payload['envelope_field_anti_unification_fence']);
        $this->assertSame('formula_version', $payload['envelope_field_formula_version']);
        $this->assertSame('claim_policy', $payload['golden_field_claim_policy']);
        $this->assertSame('read_only', $payload['golden_field_read_only']);
        $this->assertSame('objective_class', $payload['bets_field_objective_class']);
        $this->assertSame('suspended_paths', $payload['bets_field_suspended_paths']);
        $this->assertSame('theses', $payload['thesis_field_theses']);
        $this->assertSame('expires_at', $payload['thesis_field_expires_at']);
        $this->assertSame('unterminated_count', $payload['attempt_field_unterminated_count']);
        $this->assertSame('outcome_without_attempt_allowed', $payload['attempt_field_outcome_without_attempt_allowed']);
        $this->assertSame('external_provider_call', $payload['cockpit_field_external_provider_call']);
        $this->assertSame('provider_tokens_spent', $payload['cockpit_field_provider_tokens_spent']);
        $this->assertSame(18, $payload['teto_ragx_promotion_envelope_golden_bets_thesis_attempt_cockpit_floor_count']);
    }


    public function test_veto_repair_phase_truth_ledger_canary_latency_dual_budget_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->vetoRepairPhaseTruthLedgerCanaryLatencyDualBudgetFloorsContractObserve([]);

        $this->assertSame('recognized', $payload['veto_field_recognized']);
        $this->assertSame('lift', $payload['veto_field_lift']);
        $this->assertSame('attempt', $payload['repair_field_attempt']);
        $this->assertSame('admitted', $payload['repair_field_admitted']);
        $this->assertSame('intent_capture', $payload['phase_field_intent_capture']);
        $this->assertSame('disambiguation', $payload['phase_field_disambiguation']);
        $this->assertSame('total_canonical_docs', $payload['truth_field_total_canonical_docs']);
        $this->assertSame('with_evidence_refs', $payload['truth_field_with_evidence_refs']);
        $this->assertSame('tampered_count', $payload['ledger_field_tampered_count']);
        $this->assertSame('chain_details', $payload['ledger_field_chain_details']);
        $this->assertSame('golden_version', $payload['canary_field_golden_version']);
        $this->assertSame('golden_recall_at_5', $payload['canary_field_golden_recall_at_5']);
        $this->assertSame('denominator_min_samples', $payload['latency_field_denominator_min_samples']);
        $this->assertSame('freeze_source', $payload['latency_field_freeze_source']);
        $this->assertSame('multilingual_pt', $payload['dual_field_multilingual_pt']);
        $this->assertSame('dual_read', $payload['dual_field_dual_read']);
        $this->assertSame('disk_actual_mb', $payload['budget_field_disk_actual_mb']);
        $this->assertSame('total_ram_cap_mb', $payload['budget_field_total_ram_cap_mb']);
        $this->assertSame(18, $payload['veto_repair_phase_truth_ledger_canary_latency_dual_budget_floor_count']);
    }


    public function test_joint_autonomy_dead_runner_envelope_obra_docs_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->jointAutonomyDeadRunnerEnvelopeObraDocsFloorsContractObserve([]);

        $this->assertSame('paper_status', $payload['joint_field_paper_status']);
        $this->assertSame('reasons', $payload['joint_field_reasons']);
        $this->assertSame('probe_count', $payload['autonomy_field_probe_count']);
        $this->assertSame('refused_count', $payload['autonomy_field_refused_count']);
        $this->assertSame('freshness_reader', $payload['dead_field_freshness_reader']);
        $this->assertSame('last_append_at', $payload['dead_field_last_append_at']);
        $this->assertSame('ledger_event_id', $payload['runner_field_ledger_event_id']);
        $this->assertSame('check_id', $payload['runner_field_check_id']);
        $this->assertSame('executor', $payload['dev_envelope_field_executor']);
        $this->assertSame('verified_basis', $payload['dev_envelope_field_verified_basis']);
        $this->assertSame('evidence_refs', $payload['compounding_field_evidence_refs']);
        $this->assertSame('native_divergent', $payload['compounding_field_native_divergent']);
        $this->assertSame('archived_at_basis', $payload['obra_life_field_archived_at_basis']);
        $this->assertSame('receipt_hash', $payload['obra_life_field_receipt_hash']);
        $this->assertSame('task_id', $payload['obra_compose_field_task_id']);
        $this->assertSame('objective', $payload['obra_compose_field_objective']);
        $this->assertSame('governs_frontmatter', $payload['docs_field_governs_frontmatter']);
        $this->assertSame('doc_id', $payload['docs_field_doc_id']);
        $this->assertSame(18, $payload['joint_autonomy_dead_runner_envelope_obra_docs_floor_count']);
    }

    public function test_health_ingest_derive_calib_dispatch_prov_cooccur_vision_cascade_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->healthIngestDeriveCalibDispatchProvCooccurVisionCascadeFloorsContractObserve([]);

        $this->assertSame('trend_status', $payload['health_field_trend_status']);
        $this->assertSame('tolerance_points', $payload['health_field_tolerance_points']);
        $this->assertSame('candidate_hash', $payload['ingest_field_candidate_hash']);
        $this->assertSame('immune_classification', $payload['ingest_field_immune_classification']);
        $this->assertSame('content_hash', $payload['derive_field_content_hash']);
        $this->assertSame('marker_centroid', $payload['derive_field_marker_centroid']);
        $this->assertSame('denominator_min_samples', $payload['calib_field_denominator_min_samples']);
        $this->assertSame('known_miss_denominator_must_be_non_zero', $payload['calib_field_known_miss_denominator_must_be_non_zero']);
        $this->assertSame('phase_out', $payload['dispatch_field_phase_out']);
        $this->assertSame('envelope', $payload['dispatch_field_envelope']);
        $this->assertSame('resolved_count', $payload['prov_field_resolved_count']);
        $this->assertSame('multiplier', $payload['prov_field_multiplier']);
        $this->assertSame('requires_counterfactual_before_enforcement', $payload['cooccur_field_requires_counterfactual_before_enforcement']);
        $this->assertSame('read_only', $payload['cooccur_field_read_only']);
        $this->assertSame('series_windows', $payload['vision_field_series_windows']);
        $this->assertSame('leads', $payload['vision_field_leads']);
        $this->assertSame('needs_reverification', $payload['cascade_field_needs_reverification']);
        $this->assertSame('marked', $payload['cascade_field_marked']);
        $this->assertSame(18, $payload['health_ingest_derive_calib_dispatch_prov_cooccur_vision_cascade_floor_count']);
    }

    public function test_promo_immune_nudge_hmac_runbook_qbar_phase_dept_ncapture_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->promoImmuneNudgeHmacRunbookQbarPhaseDeptNcaptureFloorsContractObserve([]);

        $this->assertSame('gate_statuses', $payload['promo_field_gate_statuses']);
        $this->assertSame('promotion_status', $payload['promo_field_promotion_status']);
        $this->assertSame('check_categories', $payload['immune_contract_field_check_categories']);
        $this->assertSame('pending_gates', $payload['immune_contract_field_pending_gates']);
        $this->assertSame('vision', $payload['nudge_field_vision']);
        $this->assertSame('generation', $payload['nudge_field_generation']);
        $this->assertSame('source_packet', $payload['hmac_field_source_packet']);
        $this->assertSame('hmac_lineage', $payload['hmac_field_hmac_lineage']);
        $this->assertSame('needs_research', $payload['runbook_field_needs_research']);
        $this->assertSame('needs_debug', $payload['runbook_field_needs_debug']);
        $this->assertSame('quality_bar_schema', $payload['qbar_field_quality_bar_schema']);
        $this->assertSame('immune_gate_id', $payload['qbar_field_immune_gate_id']);
        $this->assertSame('phase_out', $payload['phase_adv_field_phase_out']);
        $this->assertSame('blockers', $payload['phase_adv_field_blockers']);
        $this->assertSame('department_count', $payload['dept_field_department_count']);
        $this->assertSame('canon_department_count', $payload['dept_field_canon_department_count']);
        $this->assertSame('series_registry', $payload['ncapture_field_series_registry']);
        $this->assertSame('source_type', $payload['ncapture_field_source_type']);
        $this->assertSame(18, $payload['promo_immune_nudge_hmac_runbook_qbar_phase_dept_ncapture_floor_count']);
    }

    public function test_volume_sig_hybrid_delivery_autowork_mission_http_impact_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->volumeSigHybridDeliveryAutoworkMissionHttpImpactFloorsContractObserve([]);

        $this->assertSame('alert_code', $payload['volume_field_alert_code']);
        $this->assertSame('dev_runs_per_business_day_min', $payload['volume_field_dev_runs_per_business_day_min']);
        $this->assertSame('measure_id', $payload['sig_freeze_field_measure_id']);
        $this->assertSame('family_schema_version', $payload['sig_freeze_field_family_schema_version']);
        $this->assertSame('hostile_class', $payload['hybrid_field_hostile_class']);
        $this->assertSame('scores_by_class', $payload['hybrid_field_scores_by_class']);
        $this->assertSame('obfuscated_denominator_min', $payload['hybrid_freeze_field_obfuscated_denominator_min']);
        $this->assertSame('legitimate_denominator_min', $payload['hybrid_freeze_field_legitimate_denominator_min']);
        $this->assertSame('changed_files', $payload['delivery_field_changed_files']);
        $this->assertSame('test_evidence', $payload['delivery_field_test_evidence']);
        $this->assertSame('operator_consent_present', $payload['autowork_field_operator_consent_present']);
        $this->assertSame('prior_failure_signatures', $payload['autowork_field_prior_failure_signatures']);
        $this->assertSame('phase_count', $payload['mission_field_phase_count']);
        $this->assertSame('phase_advance', $payload['mission_field_phase_advance']);
        $this->assertSame('phase_router', $payload['http_facade_field_phase_router']);
        $this->assertSame('legacy_fallback', $payload['http_facade_field_legacy_fallback']);
        $this->assertSame('single_scalar_score_emitted', $payload['impact_field_single_scalar_score_emitted']);
        $this->assertSame('unresolved_counts_as_success', $payload['impact_field_unresolved_counts_as_success']);
        $this->assertSame(18, $payload['volume_sig_hybrid_delivery_autowork_mission_http_impact_floor_count']);
    }

    public function test_frontier_rerank_fabric_decomp_specpack_handoff_envelope_blocker_advisory_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->frontierRerankFabricDecompSpecpackHandoffEnvelopeBlockerAdvisoryFloorsContractObserve([]);

        $this->assertSame('constituicao', $payload['frontier_field_constituicao']);
        $this->assertSame('external_events', $payload['frontier_field_external_events']);
        $this->assertSame('current_precision_at_k', $payload['rerank_field_current_precision_at_k']);
        $this->assertSame('baseline_precision_at_k', $payload['rerank_field_baseline_precision_at_k']);
        $this->assertSame('requested_autonomy', $payload['fabric_field_requested_autonomy']);
        $this->assertSame('proposal_id', $payload['fabric_field_proposal_id']);
        $this->assertSame('input_length', $payload['decomp_field_input_length']);
        $this->assertSame('input_preview', $payload['decomp_field_input_preview']);
        $this->assertSame('department_id', $payload['specpack_field_department_id']);
        $this->assertSame('min_autonomous_risk_scope', $payload['specpack_field_min_autonomous_risk_scope']);
        $this->assertSame('surface_captured_intent', $payload['handoff_field_surface_captured_intent']);
        $this->assertSame('intent_clarity_score_min_0_8', $payload['handoff_field_intent_clarity_score_min_0_8']);
        $this->assertSame('placement_layer', $payload['envelope_factory_field_placement_layer']);
        $this->assertSame('placement_flow', $payload['envelope_factory_field_placement_flow']);
        $this->assertSame('critical_count', $payload['blocker_field_critical_count']);
        $this->assertSame('high_count', $payload['blocker_field_high_count']);
        $this->assertSame('delays_auto_apply', $payload['advisory_field_delays_auto_apply']);
        $this->assertSame('mutates_pipeline', $payload['advisory_field_mutates_pipeline']);
        $this->assertSame(18, $payload['frontier_rerank_fabric_decomp_specpack_handoff_envelope_blocker_advisory_floor_count']);
    }

    public function test_integrity_promo_share_thesis_atlas_promo_flywheel_golden_ambition_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->integrityPromoShareThesisAtlasPromoFlywheelGoldenAmbitionFloorsContractObserve([]);

        $this->assertSame('model_id', $payload['integrity_field_model_id']);
        $this->assertSame('total', $payload['integrity_field_total']);
        $this->assertSame('quality_bar', $payload['promo_elig_field_quality_bar']);
        $this->assertSame('promotion_allowed', $payload['promo_elig_field_promotion_allowed']);
        $this->assertSame('window_days', $payload['share_field_window_days']);
        $this->assertSame('verified_count', $payload['share_field_verified_count']);
        $this->assertSame('series_recovery', $payload['thesis_field_series_recovery']);
        $this->assertSame('outcome_proven', $payload['thesis_field_outcome_proven']);
        $this->assertSame('service_present', $payload['fn_atlas_field_service_present']);
        $this->assertSame('pipeline_ready', $payload['fn_atlas_field_pipeline_ready']);
        $this->assertSame('recorded_at', $payload['promo_proto_field_recorded_at']);
        $this->assertSame('protocol_receipt', $payload['promo_proto_field_protocol_receipt']);
        $this->assertSame('used_as_producer_target', $payload['flywheel_field_used_as_producer_target']);
        $this->assertSame('subsequent_outcome_improved', $payload['flywheel_field_subsequent_outcome_improved']);
        $this->assertSame('recall_at_5_without', $payload['golden_field_recall_at_5_without']);
        $this->assertSame('recall_at_5_with', $payload['golden_field_recall_at_5_with']);
        $this->assertSame('selected_rung', $payload['ambition_field_selected_rung']);
        $this->assertSame('scope_has_ceiling', $payload['ambition_field_scope_has_ceiling']);
        $this->assertSame(18, $payload['integrity_promo_share_thesis_atlas_promo_flywheel_golden_ambition_floor_count']);
    }

    public function test_ledger_disk_latency_teto_ragx_envelope_fidelity_segment_causality_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->ledgerDiskLatencyTetoRagxEnvelopeFidelitySegmentCausalityFloorsContractObserve([]);

        $this->assertSame('tampered_total', $payload['ledger_integrity_field_tampered_total']);
        $this->assertSame('gap_total', $payload['ledger_integrity_field_gap_total']);
        $this->assertSame('generated_at', $payload['disk_free_field_generated_at']);
        $this->assertSame('background_should_pause', $payload['disk_free_field_background_should_pause']);
        $this->assertSame('report', $payload['aobg_latency_field_report']);
        $this->assertSame('insufficient_ops', $payload['aobg_latency_field_insufficient_ops']);
        $this->assertSame('markdown_cli_only', $payload['teto10_field_markdown_cli_only']);
        $this->assertSame('ui_created', $payload['teto10_field_ui_created']);
        $this->assertSame('recorded_at', $payload['ragx_chain_field_recorded_at']);
        $this->assertSame('summary_ref', $payload['ragx_chain_field_summary_ref']);
        $this->assertSame('formula', $payload['outcome_envelope_field_formula']);
        $this->assertSame('thresholds', $payload['outcome_envelope_field_thresholds']);
        $this->assertSame('required_total', $payload['fidelity_field_required_total']);
        $this->assertSame('present_total', $payload['fidelity_field_present_total']);
        $this->assertSame('stale_query', $payload['segment_rank_field_stale_query']);
        $this->assertSame('low_score_ref', $payload['segment_rank_field_low_score_ref']);
        $this->assertSame('primary_cause', $payload['causality_field_primary_cause']);
        $this->assertSame('tests_passed', $payload['causality_field_tests_passed']);
        $this->assertSame(18, $payload['ledger_disk_latency_teto_ragx_envelope_fidelity_segment_causality_floor_count']);
    }

    public function test_autonomy_watchdog_scorecard_maxa_corpus_esp09_budget_recall_veto_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->autonomyWatchdogScorecardMaxaCorpusEsp09BudgetRecallVetoFloorsContractObserve([]);

        $this->assertSame('probes', $payload['adversarial_field_probes']);
        $this->assertSame('errors', $payload['adversarial_field_errors']);
        $this->assertSame('checks', $payload['watchdog_runner_field_checks']);
        $this->assertSame('alerts', $payload['watchdog_runner_field_alerts']);
        $this->assertSame('memory_core', $payload['scorecard_v4_field_memory_core']);
        $this->assertSame('research_domain', $payload['scorecard_v4_field_research_domain']);
        $this->assertSame('required', $payload['maxa04_field_required']);
        $this->assertSame('ledger_path', $payload['maxa04_field_ledger_path']);
        $this->assertSame('via_asi_02', $payload['gated_corpus_field_via_asi_02']);
        $this->assertSame('writes_memory_directly', $payload['gated_corpus_field_writes_memory_directly']);
        $this->assertSame('trigger', $payload['esp09_field_trigger']);
        $this->assertSame('trigger_kinds', $payload['esp09_field_trigger_kinds']);
        $this->assertSame('per_item_cap_chars', $payload['budget_alloc_field_per_item_cap_chars']);
        $this->assertSame('used_chars', $payload['budget_alloc_field_used_chars']);
        $this->assertSame('session', $payload['recall_scorer_field_session']);
        $this->assertSame('requirement', $payload['recall_scorer_field_requirement']);
        $this->assertSame('final_override_active', $payload['veto_watchdog_field_final_override_active']);
        $this->assertSame('pause_sla_seconds', $payload['veto_watchdog_field_pause_sla_seconds']);
        $this->assertSame(18, $payload['autonomy_watchdog_scorecard_maxa_corpus_esp09_budget_recall_veto_floor_count']);
    }

    public function test_health_immune_calib_deferred_cooccur_thesis_lexical_repair_docs_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->healthImmuneCalibDeferredCooccurThesisLexicalRepairDocsFloorsContractObserve([]);

        $this->assertSame('current_delta_from_latest', $payload['watchdog_health_field_current_delta_from_latest']);
        $this->assertSame('latest_delta_from_previous', $payload['watchdog_health_field_latest_delta_from_previous']);
        $this->assertSame('memory_type', $payload['immune_ingest_field_memory_type']);
        $this->assertSame('refutation_memory', $payload['immune_ingest_field_refutation_memory']);
        $this->assertSame('control', $payload['immune_calib_field_control']);
        $this->assertSame('formula', $payload['immune_calib_field_formula']);
        $this->assertSame('phase_advance', $payload['deferred_phase_field_phase_advance']);
        $this->assertSame('outcome_causality', $payload['deferred_phase_field_outcome_causality']);
        $this->assertSame('generated_at', $payload['cooccur_field_generated_at']);
        $this->assertSame('provider_calls_made', $payload['cooccur_field_provider_calls_made']);
        $this->assertSame('remaining_rows_max', $payload['thesis_composer_field_remaining_rows_max']);
        $this->assertSame('outcomes', $payload['thesis_composer_field_outcomes']);
        $this->assertSame('memoria', $payload['lexical_field_memoria']);
        $this->assertSame('verificacao', $payload['lexical_field_verificacao']);
        $this->assertSame('escalated', $payload['repair_loop_field_escalated']);
        $this->assertSame('decision', $payload['repair_loop_field_decision']);
        $this->assertSame('capability_frontmatter', $payload['docs_auth_field_capability_frontmatter']);
        $this->assertSame('rows', $payload['docs_auth_field_rows']);
        $this->assertSame(18, $payload['health_immune_calib_deferred_cooccur_thesis_lexical_repair_docs_floor_count']);
    }

    public function test_dept_immune_nudge_runbook_quality_dev_compound_obra_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->deptImmuneNudgeRunbookQualityDevCompoundObraFloorsContractObserve([]);

        $this->assertSame('from', $payload['dept_runtime_field_from']);
        $this->assertSame('departments', $payload['dept_runtime_field_departments']);
        $this->assertSame('blocking_gate_ids', $payload['immune_promo_field_blocking_gate_ids']);
        $this->assertSame('pending_gate_ids', $payload['immune_promo_field_pending_gate_ids']);
        $this->assertSame('inputs', $payload['immune_check_field_inputs']);
        $this->assertSame('outputs', $payload['immune_check_field_outputs']);
        $this->assertSame('framework', $payload['nudge_field_framework']);
        $this->assertSame('role', $payload['nudge_field_role']);
        $this->assertSame('order', $payload['runbook_field_order']);
        $this->assertSame('evidence_schema', $payload['runbook_field_evidence_schema']);
        $this->assertSame('breach_signal', $payload['quality_bar_field_breach_signal']);
        $this->assertSame('canonical_source', $payload['quality_bar_field_canonical_source']);
        $this->assertSame('verified_source_present', $payload['dev_proc_field_verified_source_present']);
        $this->assertSame('evidence_ref_count', $payload['dev_proc_field_evidence_ref_count']);
        $this->assertSame('executor', $payload['compound_field_executor']);
        $this->assertSame('verified_source_present', $payload['compound_field_verified_source_present']);
        $this->assertSame('seed_gate', $payload['obra_arc_field_seed_gate']);
        $this->assertSame('obra_id', $payload['obra_arc_field_obra_id']);
        $this->assertSame(18, $payload['dept_immune_nudge_runbook_quality_dev_compound_obra_floor_count']);
    }

    public function test_volume_immune_scorecard_phase_delivery_autowork_citation_cascade_budget_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->volumeImmuneScorecardPhaseDeliveryAutoworkCitationCascadeBudgetFloorsContractObserve([]);

        $this->assertSame('checked_at', $payload['volume_check_field_checked_at']);
        $this->assertSame('thresholds', $payload['volume_check_field_thresholds']);
        $this->assertSame('author', $payload['immune_freeze_field_author']);
        $this->assertSame('judge', $payload['immune_freeze_field_judge']);
        $this->assertSame('subsystems', $payload['scorecard_field_subsystems']);
        $this->assertSame('notes', $payload['scorecard_field_notes']);
        $this->assertSame('missing_gates', $payload['phase_verdict_field_missing_gates']);
        $this->assertSame('blocked_gates', $payload['phase_verdict_field_blocked_gates']);
        $this->assertSame('files_have_evidence', $payload['delivery_pack_field_files_have_evidence']);
        $this->assertSame('tests_present', $payload['delivery_pack_field_tests_present']);
        $this->assertSame('cycle_id', $payload['autowork_field_cycle_id']);
        $this->assertSame('goal_hash', $payload['autowork_field_goal_hash']);
        $this->assertSame('unsupported_citation_count', $payload['citation_field_unsupported_citation_count']);
        $this->assertSame('measured_count', $payload['citation_field_measured_count']);
        $this->assertSame('depth', $payload['cascade_field_depth']);
        $this->assertSame('deletes_descendants', $payload['cascade_field_deletes_descendants']);
        $this->assertSame('engine_floor_mb', $payload['resource_budget_field_engine_floor_mb']);
        $this->assertSame('host_ram_mb', $payload['resource_budget_field_host_ram_mb']);
        $this->assertSame(18, $payload['volume_immune_scorecard_phase_delivery_autowork_citation_cascade_budget_floor_count']);
    }

    public function test_frontier_rerank_fabric_cockpit_http_specpack_advisory_ncapture_model_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->frontierRerankFabricCockpitHttpSpecpackAdvisoryNcaptureModelFloorsContractObserve([]);

        $this->assertSame('prior_events', $payload['frontier_field_prior_events']);
        $this->assertSame('threshold', $payload['frontier_field_threshold']);
        $this->assertSame('frozen_at', $payload['rerank_field_frozen_at']);
        $this->assertSame('promote_allowed', $payload['rerank_field_promote_allowed']);
        $this->assertSame('doc_skeleton', $payload['fabric_field_doc_skeleton']);
        $this->assertSame('admission_decision', $payload['fabric_field_admission_decision']);
        $this->assertSame('phases', $payload['cockpit_field_phases']);
        $this->assertSame('outcome_causality', $payload['cockpit_field_outcome_causality']);
        $this->assertSame('requests', $payload['http_facade_field_requests']);
        $this->assertSame('samples', $payload['http_facade_field_samples']);
        $this->assertSame('operator_signature_required_from', $payload['specpack_field_operator_signature_required_from']);
        $this->assertSame('spec_pack_schema', $payload['specpack_field_spec_pack_schema']);
        $this->assertSame('reorders_digest_only', $payload['advisory_field_reorders_digest_only']);
        $this->assertSame('reuses_calibration_band_classifier', $payload['advisory_field_reuses_calibration_band_classifier']);
        $this->assertSame('function', $payload['ncapture_field_function']);
        $this->assertSame('verified', $payload['ncapture_field_verified']);
        $this->assertSame('function', $payload['model_cap_field_function']);
        $this->assertSame('license_allowed', $payload['model_cap_field_license_allowed']);
        $this->assertSame(18, $payload['frontier_rerank_fabric_cockpit_http_specpack_advisory_ncapture_model_floor_count']);
    }

    public function test_texec_obra_evo_window_rollback_maturity_embed_horizon_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->texecObraEvoWindowRollbackMaturityEmbedHorizonFloorsContractObserve([]);

        $this->assertSame('method', $payload['texec_field_method']);
        $this->assertSame('born_stale', $payload['texec_field_born_stale']);
        $this->assertSame('line', $payload['obra_retro_field_line']);
        $this->assertSame('metrics', $payload['obra_retro_field_metrics']);
        $this->assertSame('acos_scorecard_overall', $payload['evo_score_field_acos_scorecard_overall']);
        $this->assertSame('autonomia', $payload['evo_score_field_autonomia']);
        $this->assertSame('alerts', $payload['window_orch_field_alerts']);
        $this->assertSame('days_elapsed', $payload['window_orch_field_days_elapsed']);
        $this->assertSame('alerts', $payload['rollback_field_alerts']);
        $this->assertSame('condition', $payload['rollback_field_condition']);
        $this->assertSame('all_bands_breached', $payload['maturity_field_all_bands_breached']);
        $this->assertSame('departments', $payload['maturity_field_departments']);
        $this->assertSame('author_engine_id', $payload['embed_item_field_author_engine_id']);
        $this->assertSame('denominator_min_active_items', $payload['embed_item_field_denominator_min_active_items']);
        $this->assertSame('author_engine_id', $payload['embed_symbol_field_author_engine_id']);
        $this->assertSame('default_switch', $payload['embed_symbol_field_default_switch']);
        $this->assertSame('certification_window_start', $payload['longhorizon_field_certification_window_start']);
        $this->assertSame('completion_requires_real_30d_window', $payload['longhorizon_field_completion_requires_real_30d_window']);
        $this->assertSame(18, $payload['texec_obra_evo_window_rollback_maturity_embed_horizon_floor_count']);
    }

    public function test_maturity_attempt_scorecard_http_qbar_compound_immune_phase_dept_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->maturityAttemptScorecardHttpQbarCompoundImmunePhaseDeptFloorsContractObserve([]);

        $this->assertSame('contracts', $payload['doc_maturity_field_contracts']);
        $this->assertSame('level', $payload['doc_maturity_field_level']);
        $this->assertSame('attempt_id_deduped', $payload['attempt_field_attempt_id_deduped']);
        $this->assertSame('source', $payload['attempt_field_source']);
        $this->assertSame('benchmark_claim_allowed', $payload['scorecard_field_benchmark_claim_allowed']);
        $this->assertSame('cognitive_immune_law_enforced', $payload['scorecard_field_cognitive_immune_law_enforced']);
        $this->assertSame('passed', $payload['http_env_field_passed']);
        $this->assertSame('policy_allowed', $payload['http_env_field_policy_allowed']);
        $this->assertSame('missing_metric', $payload['qbar_field_missing_metric']);
        $this->assertSame('observed', $payload['qbar_field_observed']);
        $this->assertSame('evidence_ref_count', $payload['compound_field_evidence_ref_count']);
        $this->assertSame('fields', $payload['compound_field_fields']);
        $this->assertSame('autonomous_promotion_allowed', $payload['immune_promo_field_autonomous_promotion_allowed']);
        $this->assertSame('reasons', $payload['immune_promo_field_reasons']);
        $this->assertSame('gates', $payload['phase_verdict_field_gates']);
        $this->assertSame('high_blocker_ids', $payload['phase_verdict_field_high_blocker_ids']);
        $this->assertSame('failed_thresholds', $payload['dept_level_field_failed_thresholds']);
        $this->assertSame('satisfied', $payload['dept_level_field_satisfied']);
        $this->assertSame(18, $payload['maturity_attempt_scorecard_http_qbar_compound_immune_phase_dept_floor_count']);
    }

    public function test_gate_signal_truth_router_veto_choreo_debug_docs_watchdog_pareto_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->gateSignalTruthRouterVetoChoreoDebugDocsWatchdogParetoFloorsContractObserve([]);

        $this->assertSame('ambiguity_tokens', $payload['gate_signal_field_ambiguity_tokens']);
        $this->assertSame('computed_value', $payload['gate_signal_field_computed_value']);
        $this->assertSame('paths', $payload['impl_truth_field_paths']);
        $this->assertSame('rank_claimed', $payload['impl_truth_field_rank_claimed']);
        $this->assertSame('classification', $payload['phase_router_field_classification']);
        $this->assertSame('placement', $payload['phase_router_field_placement']);
        $this->assertSame('debug', $payload['veto_prop_field_debug']);
        $this->assertSame('delivery', $payload['veto_prop_field_delivery']);
        $this->assertSame('decision', $payload['choreo_field_decision']);
        $this->assertSame('escalate', $payload['choreo_field_escalate']);
        $this->assertSame('context', $payload['debug_rc_field_context']);
        $this->assertSame('root_cause', $payload['debug_rc_field_root_cause']);
        $this->assertSame('basis', $payload['docs_auth_field_basis']);
        $this->assertSame('capabilities', $payload['docs_auth_field_capabilities']);
        $this->assertSame('final_override', $payload['veto_wd_field_final_override']);
        $this->assertSame('veto_receipts', $payload['veto_wd_field_veto_receipts']);
        $this->assertSame('objective_direction', $payload['pareto_field_objective_direction']);
        $this->assertSame('summary', $payload['pareto_field_summary']);
        $this->assertSame(18, $payload['gate_signal_truth_router_veto_choreo_debug_docs_watchdog_pareto_floor_count']);
    }

    public function test_immune_freeze_outcome_window_flywheel_promo_calib_handoff_runbook_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->immuneFreezeOutcomeWindowFlywheelPromoCalibHandoffRunbookFloorsContractObserve([]);

        $this->assertSame('generated_at', $payload['imm_freeze_field_generated_at']);
        $this->assertSame('ref', $payload['imm_freeze_field_ref']);
        $this->assertSame('path_declared', $payload['outcome_env_field_path_declared']);
        $this->assertSame('pin_present', $payload['outcome_env_field_pin_present']);
        $this->assertSame('owner', $payload['win_gates_field_owner']);
        $this->assertSame('severity', $payload['win_gates_field_severity']);
        $this->assertSame('command', $payload['flywheel_field_command']);
        $this->assertSame('kind', $payload['flywheel_field_kind']);
        $this->assertSame('existing_test_refs', $payload['cog_promo_field_existing_test_refs']);
        $this->assertSame('matched', $payload['cog_promo_field_matched']);
        $this->assertSame('consumer_of_maxn_04', $payload['imm_calib_field_consumer_of_maxn_04']);
        $this->assertSame('flag', $payload['imm_calib_field_flag']);
        $this->assertSame('default_destination', $payload['phase_hand_field_default_destination']);
        $this->assertSame('embedding_allowed', $payload['phase_hand_field_embedding_allowed']);
        $this->assertSame('memory', $payload['runbook_field_memory']);
        $this->assertSame('payload', $payload['runbook_field_payload']);
        $this->assertSame('candidate_id', $payload['dept_reg_field_candidate_id']);
        $this->assertSame('citation_latency_seconds', $payload['dept_reg_field_citation_latency_seconds']);
        $this->assertSame(18, $payload['immune_freeze_outcome_window_flywheel_promo_calib_handoff_runbook_floor_count']);
    }

    public function test_verdict_dept_debt_canary_asef_freshness_reality_list_schema_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->verdictDeptDebtCanaryAsefFreshnessRealityListSchemaFloorsContractObserve([]);

        $this->assertSame('id', $payload['verdict_field_id']);
        $this->assertSame('updated_at', $payload['verdict_field_updated_at']);
        $this->assertSame('id', $payload['dept_reg_field_id']);
        $this->assertSame('maturity_level', $payload['dept_reg_field_maturity_level']);
        $this->assertSame('message', $payload['review_debt_field_message']);
        $this->assertSame('code', $payload['review_debt_field_code']);
        $this->assertSame('v1', $payload['canary_field_v1']);
        $this->assertSame('violations', $payload['canary_field_violations']);
        $this->assertSame('id', $payload['asef_field_id']);
        $this->assertSame('chunk_hits', $payload['asef_field_chunk_hits']);
        $this->assertSame('path', $payload['freshness_field_path']);
        $this->assertSame('where', $payload['freshness_field_where']);
        $this->assertSame('output_phases', $payload['reality_field_output_phases']);
        $this->assertSame('schema_version', $payload['reality_field_schema_version']);
        $this->assertSame('type', $payload['string_list_field_type']);
        $this->assertSame('name', $payload['string_list_field_name']);
        $this->assertSame('source', $payload['fact_schema_field_source']);
        $this->assertSame('required_on_write', $payload['fact_schema_field_required_on_write']);
        $this->assertSame(18, $payload['verdict_dept_debt_canary_asef_freshness_reality_list_schema_floor_count']);
    }

    public function test_compaction_redaction_capture_provenance_impact_bets_maturity_claim_generated_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->compactionRedactionCaptureProvenanceImpactBetsMaturityClaimGeneratedFloorsContractObserve([]);

        $this->assertSame('recovery_rate', $payload['compaction_field_recovery_rate']);
        $this->assertSame('status', $payload['compaction_field_status']);
        $this->assertSame('message', $payload['redaction_field_message']);
        $this->assertSame('code', $payload['redaction_field_code']);
        $this->assertSame('reason', $payload['capture_field_reason']);
        $this->assertSame('operator_schema_ready', $payload['capture_field_operator_schema_ready']);
        $this->assertSame('source', $payload['provenance_field_source']);
        $this->assertSame('schema_version', $payload['provenance_field_schema_version']);
        $this->assertSame('status', $payload['impact_field_status']);
        $this->assertSame('report_only', $payload['impact_field_report_only']);
        $this->assertSame('writes_class_allocation_weights', $payload['bets_field_writes_class_allocation_weights']);
        $this->assertSame('uses_atlas_brain_causal_effect_gate', $payload['bets_field_uses_atlas_brain_causal_effect_gate']);
        $this->assertSame('summary', $payload['maturity_field_summary']);
        $this->assertSame('signals', $payload['maturity_field_signals']);
        $this->assertSame('verdict', $payload['claim_field_verdict']);
        $this->assertSame('schema_version', $payload['claim_field_schema_version']);
        $this->assertSame('hot_path_enabled', $payload['generated_field_hot_path_enabled']);
        $this->assertSame('generated_file_count', $payload['generated_field_generated_file_count']);
        $this->assertSame(18, $payload['compaction_redaction_capture_provenance_impact_bets_maturity_claim_generated_floor_count']);
    }

    public function test_promo_handoff_blocker_protocol_replay_thesis_integrity_budget_deriver_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->promoHandoffBlockerProtocolReplayThesisIntegrityBudgetDeriverFloorsContractObserve([]);

        $this->assertSame('total', $payload['promo_elig_field_total']);
        $this->assertSame('tier_thresholds', $payload['promo_elig_field_tier_thresholds']);
        $this->assertSame('universal_15_gates_green_or_exception', $payload['handoff_field_universal_15_gates_green_or_exception']);
        $this->assertSame('topology_plan_providers_min_1_available', $payload['handoff_field_topology_plan_providers_min_1_available']);
        $this->assertSame('unknown_count', $payload['blocker_field_unknown_count']);
        $this->assertSame('signal', $payload['blocker_field_signal']);
        $this->assertSame('states', $payload['protocol_field_states']);
        $this->assertSame('required_fields', $payload['protocol_field_required_fields']);
        $this->assertSame('runs', $payload['replay_field_runs']);
        $this->assertSame('provider_calls_made', $payload['replay_field_provider_calls_made']);
        $this->assertSame('yield', $payload['thesis_field_yield']);
        $this->assertSame('target_path', $payload['thesis_field_target_path']);
        $this->assertSame('status', $payload['integrity_field_status']);
        $this->assertSame('schema_version', $payload['integrity_field_schema_version']);
        $this->assertSame('schema_version', $payload['budget_field_schema_version']);
        $this->assertSame('message', $payload['budget_field_message']);
        $this->assertSame('signature', $payload['deriver_field_signature']);
        $this->assertSame('schema_version', $payload['deriver_field_schema_version']);
        $this->assertSame(18, $payload['promo_handoff_blocker_protocol_replay_thesis_integrity_budget_deriver_floor_count']);
    }

    public function test_segment_fidelity_causality_teto_ragx_envelope_latency_watchdog_hybrid_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->segmentFidelityCausalityTetoRagxEnvelopeLatencyWatchdogHybridFloorsContractObserve([]);

        $this->assertSame('id', $payload['segment_field_id']);
        $this->assertSame('tokens_kept', $payload['segment_field_tokens_kept']);
        $this->assertSame('verdict', $payload['fidelity_field_verdict']);
        $this->assertSame('unverifiable_item_ids', $payload['fidelity_field_unverifiable_item_ids']);
        $this->assertSame('packet_quality_failed', $payload['causality_field_packet_quality_failed']);
        $this->assertSame('missing_required_sources', $payload['causality_field_missing_required_sources']);
        $this->assertSame('id', $payload['teto_field_id']);
        $this->assertSame('source', $payload['teto_field_source']);
        $this->assertSame('k', $payload['ragx_field_k']);
        $this->assertSame('weight', $payload['ragx_field_weight']);
        $this->assertSame('ttl_days', $payload['envelope_field_ttl_days']);
        $this->assertSame('kind', $payload['envelope_field_kind']);
        $this->assertSame('op', $payload['latency_field_op']);
        $this->assertSame('violations', $payload['latency_field_violations']);
        $this->assertSame('status', $payload['watchdog_field_status']);
        $this->assertSame('evidence', $payload['watchdog_field_evidence']);
        $this->assertSame('untrusted_content', $payload['hybrid_field_untrusted_content']);
        $this->assertSame('thresholds', $payload['hybrid_field_thresholds']);
        $this->assertSame(18, $payload['segment_fidelity_causality_teto_ragx_envelope_latency_watchdog_hybrid_floor_count']);
    }

    public function test_memory_budget_recall_maxa_corpus_esp09_dogfood_autonomy_runner_freeze_floors_contract_observe_reports_floors(): void
    {
        $payload = $this->svc->memoryBudgetRecallMaxaCorpusEsp09DogfoodAutonomyRunnerFreezeFloorsContractObserve([]);

        $this->assertSame('truncated', $payload['budget_field_truncated']);
        $this->assertSame('remaining_chars', $payload['budget_field_remaining_chars']);
        $this->assertSame('workspace', $payload['recall_field_workspace']);
        $this->assertSame('verbatim', $payload['recall_field_verbatim']);
        $this->assertSame('writes_live_default_model', $payload['maxa_field_writes_live_default_model']);
        $this->assertSame('requires_dual_read_ledger', $payload['maxa_field_requires_dual_read_ledger']);
        $this->assertSame('text', $payload['corpus_field_text']);
        $this->assertSame('ref', $payload['corpus_field_ref']);
        $this->assertSame('windows', $payload['esp09_field_windows']);
        $this->assertSame('window', $payload['esp09_field_window']);
        $this->assertSame('status', $payload['dogfood_field_status']);
        $this->assertSame('kind', $payload['dogfood_field_kind']);
        $this->assertSame('n', $payload['autonomy_field_n']);
        $this->assertSame('schema_version', $payload['autonomy_field_schema_version']);
        $this->assertSame('id', $payload['runner_field_id']);
        $this->assertSame('total', $payload['runner_field_total']);
        $this->assertSame('ttl_days', $payload['freeze_field_ttl_days']);
        $this->assertSame('switch', $payload['freeze_field_switch']);
        $this->assertSame(18, $payload['memory_budget_recall_maxa_corpus_esp09_dogfood_autonomy_runner_freeze_floor_count']);
    }

    public function test_repair_parallel_promoter_verified_cockpit_deferred_window_remint_scorecard_floors_contract_observe_reports_floors(): void
    {
        $gates = $this->app->make(AtlasUniversalGatesEvaluator::class);
        $out = $gates->repairParallelPromoterVerifiedCockpitDeferredWindowRemintScorecardFloorsContractObserve([]);
        $this->assertSame(AtlasRepairLoopGuard::FIELD_ESCALATE, $out['escalate']);
        $this->assertSame(AtlasRepairLoopGuard::FIELD_SCHEMA_VERSION, $out['schema_version']);
        $this->assertSame(AcosMaxParallelExecutionProtocol::FIELD_META, $out['meta']);
        $this->assertSame(AcosMaxParallelExecutionProtocol::FIELD_PROTOCOL, $out['protocol']);
        $this->assertSame(AcosMaxProceduralSkillPromoterService::FIELD_FRONTIER_PROMOTES, $out['frontier_promotes']);
        $this->assertSame(AcosMaxProceduralSkillPromoterService::FIELD_KIND, $out['kind']);
        $this->assertSame(AcosMaxVerifiedShareService::FIELD_FREEZE, $out['freeze']);
        $this->assertSame(AcosMaxVerifiedShareService::FIELD_FREEZE_REQUIRED, $out['freeze_required']);
        $this->assertSame(AcosProgramCockpitService::FIELD_BRAKES, $out['brakes']);
        $this->assertSame(AcosProgramCockpitService::FIELD_CURRENT_LOTE, $out['current_lote']);
        $this->assertSame(AaeosDeferredPhaseDispatcherService::FIELD_ENQUEUED, $out['enqueued']);
        $this->assertSame(AaeosDeferredPhaseDispatcherService::FIELD_ENQUEUED_AT, $out['enqueued_at']);
        $this->assertSame(AtlasAcosWindowGatesService::FIELD_COMPONENTS, $out['components']);
        $this->assertSame(AtlasAcosWindowGatesService::FIELD_FRESH, $out['fresh']);
        $this->assertSame(AtlasCognitionRemintTouchedQueue::FIELD_COMMAND, $out['command']);
        $this->assertSame(AtlasCognitionRemintTouchedQueue::FIELD_COMMAND_ARGS, $out['command_args']);
        $this->assertSame(AtlasCognitionScoreCardV4Grouper::FIELD_GOVERNANCE, $out['governance']);
        $this->assertSame(AtlasCognitionScoreCardV4Grouper::FIELD_GROUP, $out['group']);
        $this->assertSame(18, $out['repair_parallel_promoter_verified_cockpit_deferred_window_remint_scorecard_floor_count']);
    }

    public function test_obra_dept_nudge_aemor_ambition_flywheel_quality_runbook_function_floors_contract_observe_reports_floors(): void
    {
        $gates = $this->app->make(AtlasUniversalGatesEvaluator::class);
        $out = $gates->obraDeptNudgeAemorAmbitionFlywheelQualityRunbookFunctionFloorsContractObserve([]);
        $this->assertSame(ComposedObraArcComposer::FIELD_ID, $out['id']);
        $this->assertSame(ComposedObraArcComposer::FIELD_NEIGHBOR_BASIS, $out['neighbor_basis']);
        $this->assertSame(DepartmentContractRuntime::FIELD_TO, $out['to']);
        $this->assertSame(DepartmentContractRuntime::FIELD_DEPARTMENT, $out['department']);
        $this->assertSame(CognitiveContextNudgeApplier::FIELD_CODE, $out['code']);
        $this->assertSame(CognitiveContextNudgeApplier::FIELD_REASONING, $out['reasoning']);
        $this->assertSame(AemorOutcomeEnvelopeAdapter::FIELD_EVIDENCE_REFS, $out['evidence_refs']);
        $this->assertSame(AemorOutcomeEnvelopeAdapter::FIELD_FIELDS, $out['fields']);
        $this->assertSame(AmbitionRungPolicy::FIELD_ID, $out['id']);
        $this->assertSame(AmbitionRungPolicy::FIELD_RUNG_DISTRIBUTION, $out['rung_distribution']);
        $this->assertSame(AtlasFlywheelFunnelService::FIELD_ALL, $out['all']);
        $this->assertSame(AtlasFlywheelFunnelService::FIELD_MEMORY_WRITTEN, $out['memory_written']);
        $this->assertSame(QualityBarTelemetryContract::FIELD_AUTO_BLOCK_ON_BREACH, $out['auto_block_on_breach']);
        $this->assertSame(QualityBarTelemetryContract::FIELD_EVIDENCE_REQUIRED, $out['evidence_required']);
        $this->assertSame(RunbookOrchestrator::FIELD_DEFAULT_FLOW_GATES_TOTAL, $out['default_flow_gates_total']);
        $this->assertSame(RunbookOrchestrator::FIELD_DEPARTMENT_COUNT, $out['department_count']);
        $this->assertSame(AtlasCognitiveFunctionAtlasService::FIELD_DOC_READY, $out['doc_ready']);
        $this->assertSame(AtlasCognitiveFunctionAtlasService::FIELD_DOC_STATUS, $out['doc_status']);
        $this->assertSame(18, $out['obra_dept_nudge_aemor_ambition_flywheel_quality_runbook_function_floor_count']);
    }

    public function test_delivery_pack_resource_budget_belief_cascade_citation_grounding_floors_contract_observe_reports_floors(): void
    {
        $gates = $this->app->make(AtlasUniversalGatesEvaluator::class);
        $out = $gates->deliveryPackResourceBudgetBeliefCascadeCitationGroundingFloorsContractObserve([]);
        $this->assertSame(DeliveryPackCompletenessScorer::FIELD_STATUS, $out['status']);
        $this->assertSame(DeliveryPackCompletenessScorer::FIELD_DELIVERY_HASH, $out['delivery_hash']);
        $this->assertSame(AtlasResourceBudgetService::FIELD_MEASURED_HEADROOM_MB, $out['measured_headroom_mb']);
        $this->assertSame(AtlasResourceBudgetService::FIELD_MEASURED_RAM_MB, $out['measured_ram_mb']);
        $this->assertSame(BeliefCascadeReverificationPlanner::FIELD_CYCLE_SAFE, $out['cycle_safe']);
        $this->assertSame(BeliefCascadeReverificationPlanner::FIELD_ID, $out['id']);
        $this->assertSame(CitationGroundingMeter::FIELD_PROVIDER_CALLS_MADE, $out['provider_calls_made']);
        $this->assertSame(CitationGroundingMeter::FIELD_RESPONSE, $out['response']);
        $this->assertSame(DevProceduralOutcomeEnvelopeAdapter::FIELD_EPISODE_ID, $out['episode_id']);
        $this->assertSame(DevProceduralOutcomeEnvelopeAdapter::FIELD_FIELDS, $out['fields']);
        $this->assertSame(AutonomousWorkExecutionOs::FIELD_EVALUATED_AT, $out['evaluated_at']);
        $this->assertSame(AutonomousWorkExecutionOs::FIELD_GOAL, $out['goal']);
        $this->assertSame(AtlasCognitiveFunctionDecomposerService::FIELD_CLAIM_POLICY, $out['claim_policy']);
        $this->assertSame(AtlasCognitiveFunctionDecomposerService::FIELD_DEBUG, $out['debug']);
        $this->assertSame(AtlasImmuneSignatureFreeze::FIELD_ACCEPTANCE, $out['acceptance']);
        $this->assertSame(AtlasImmuneSignatureFreeze::FIELD_CELLS_WITH_HIT_COUNT_GTE_2, $out['cells_with_hit_count_gte_2']);
        $this->assertSame(AtlasOperationalVolumeCheckService::FIELD_DEV, $out['dev']);
        $this->assertSame(AtlasOperationalVolumeCheckService::FIELD_FORGE, $out['forge']);
        $this->assertSame(18, $out['delivery_pack_resource_budget_belief_cascade_citation_grounding_floor_count']);
    }

    public function test_n_capture_domain_lexical_evidence_vision_execution_context_floors_contract_observe_reports_floors(): void
    {
        $gates = $this->app->make(AtlasUniversalGatesEvaluator::class);
        $out = $gates->nCaptureDomainLexicalEvidenceVisionExecutionContextFloorsContractObserve([]);
        $this->assertSame(AtlasNCaptureDrillService::FIELD_PATH, $out['path']);
        $this->assertSame(AtlasNCaptureDrillService::FIELD_PEEK_MODE, $out['peek_mode']);
        $this->assertSame(DomainLexicalNormalizer::FIELD_FORMULA_VERSION, $out['formula_version']);
        $this->assertSame(DomainLexicalNormalizer::FIELD_LEARNING, $out['learning']);
        $this->assertSame(EvidenceVisionThesisComposer::FIELD_OPERATOR_FORBIDDEN_STRINGS, $out['operator_forbidden_strings']);
        $this->assertSame(EvidenceVisionThesisComposer::FIELD_OUTCOME_ID, $out['outcome_id']);
        $this->assertSame(ExecutionContextCooccurrenceService::FIELD_DELIVERED_REFS, $out['delivered_refs']);
        $this->assertSame(ExecutionContextCooccurrenceService::FIELD_FEEDS_ENFORCEMENT, $out['feeds_enforcement']);
        $this->assertSame(ArchitectAgentSpecPackGateContract::FIELD_EVIDENCE_REQUIRED, $out['evidence_required']);
        $this->assertSame(ArchitectAgentSpecPackGateContract::FIELD_GATES, $out['gates']);
        $this->assertSame(AtlasAaeosHttpPathFacadeService::FIELD_ID, $out['id']);
        $this->assertSame(AtlasAaeosHttpPathFacadeService::FIELD_INPUT_TEXT, $out['input_text']);
        $this->assertSame(AtlasMissionControlCockpitService::FIELD_ID, $out['id']);
        $this->assertSame(AtlasMissionControlCockpitService::FIELD_KIND, $out['kind']);
        $this->assertSame(AtlasCognitiveMemoryFabricSchemaEvolutionService::FIELD_FILES_MATCHING, $out['files_matching']);
        $this->assertSame(AtlasCognitiveMemoryFabricSchemaEvolutionService::FIELD_FILES_SCANNED, $out['files_scanned']);
        $this->assertSame(AtlasConsolidationRerankGuard::FIELD_HASH, $out['hash']);
        $this->assertSame(AtlasConsolidationRerankGuard::FIELD_LABEL, $out['label']);
        $this->assertSame(18, $out['n_capture_domain_lexical_evidence_vision_execution_context_floor_count']);
    }

    public function test_obra_retro_acos_rollback_window_orchestrator_long_aaeos_floors_contract_observe_reports_floors(): void
    {
        $gates = $this->app->make(AtlasUniversalGatesEvaluator::class);
        $out = $gates->obraRetroAcosRollbackWindowOrchestratorLongAaeosFloorsContractObserve([]);
        $this->assertSame(AcosMaxObraRetroService::FIELD_ID, $out['id']);
        $this->assertSame(AcosMaxObraRetroService::FIELD_OBJECTIVE, $out['objective']);
        $this->assertSame(AtlasAcosRollbackTriggerCheckService::FIELD_ID, $out['id']);
        $this->assertSame(AtlasAcosRollbackTriggerCheckService::FIELD_ENV, $out['env']);
        $this->assertSame(AcosMaxWindowOrchestratorService::FIELD_ID, $out['id']);
        $this->assertSame(AcosMaxWindowOrchestratorService::FIELD_DEAD_AFTER_DAYS, $out['dead_after_days']);
        $this->assertSame(AtlasAcosLongHorizonGateService::FIELD_STATUS, $out['status']);
        $this->assertSame(AtlasAcosLongHorizonGateService::FIELD_CONFIG, $out['config']);
        $this->assertSame(AtlasAaeosDepartmentMaturityBandClassifier::FIELD_NEXT_BAND, $out['next_band']);
        $this->assertSame(AtlasAaeosDepartmentMaturityBandClassifier::FIELD_NEXT_BAND_BREACHES, $out['next_band_breaches']);
        $this->assertSame(AtlasAaeosTestExecutionService::FIELD_CLASS, $out['class']);
        $this->assertSame(AtlasAaeosTestExecutionService::FIELD_EXPLAIN, $out['explain']);
        $this->assertSame(AtlasCodeSymbolEmbeddingCoverageService::FIELD_DENOMINATOR_MIN_ACTIVE_SYMBOLS, $out['denominator_min_active_symbols']);
        $this->assertSame(AtlasCodeSymbolEmbeddingCoverageService::FIELD_DUAL_READ_REQUIRED, $out['dual_read_required']);
        $this->assertSame(AtlasKnowledgeItemEmbeddingCoverageService::FIELD_DUAL_READ_REQUIRED, $out['dual_read_required']);
        $this->assertSame(AtlasKnowledgeItemEmbeddingCoverageService::FIELD_JUDGE_ENGINE_ID, $out['judge_engine_id']);
        $this->assertSame(AtlasAcosEvolutionScoreService::FIELD_AUTONOMY_GOVERNANCE, $out['autonomy_governance']);
        $this->assertSame(AtlasAcosEvolutionScoreService::FIELD_COUNT, $out['count']);
        $this->assertSame(18, $out['obra_retro_acos_rollback_window_orchestrator_long_aaeos_floor_count']);
    }

    public function test_http_path_cognition_score_department_level_aaeos_doc_floors_contract_observe_reports_floors(): void
    {
        $gates = $this->app->make(AtlasUniversalGatesEvaluator::class);
        $out = $gates->httpPathCognitionScoreDepartmentLevelAaeosDocFloorsContractObserve([]);
        $this->assertSame(AaeosHttpPathEnvelopeFactory::FIELD_ID, $out['id']);
        $this->assertSame(AaeosHttpPathEnvelopeFactory::FIELD_POLICY_STATUS, $out['policy_status']);
        $this->assertSame(AtlasCognitionScoreCardService::FIELD_V4, $out['v4']);
        $this->assertSame(AtlasCognitionScoreCardService::FIELD_CODE, $out['code']);
        $this->assertSame(AaeosDepartmentLevelClassifier::FIELD_VALUE, $out['value']);
        $this->assertSame(AaeosDepartmentLevelClassifier::FIELD_THRESHOLDS, $out['thresholds']);
        $this->assertSame(AtlasAaeosDepartmentQualityBarLevelClassifier::FIELD_SATISFIED, $out['satisfied']);
        $this->assertSame(AtlasAaeosDepartmentQualityBarLevelClassifier::FIELD_THRESHOLD, $out['threshold']);
        $this->assertSame(AtlasAaeosDocMaturityClassifier::FIELD_LEVEL_ORDINAL, $out['level_ordinal']);
        $this->assertSame(AtlasAaeosDocMaturityClassifier::FIELD_MISSING_FOR_NEXT, $out['missing_for_next']);
        $this->assertSame(PreReviewAdvisoryBand::FIELD_FABRICATES_RATE_ON_ZERO_N, $out['fabricates_rate_on_zero_n']);
        $this->assertSame(PreReviewAdvisoryBand::FIELD_LIFT_BASIS, $out['lift_basis']);
        $this->assertSame(PhaseAdvanceVerdictClassifier::FIELD_ID, $out['id']);
        $this->assertSame(PhaseAdvanceVerdictClassifier::FIELD_OPERATOR_SIGNATURE, $out['operator_signature']);
        $this->assertSame(AtlasFrontierWaveLadder::FIELD_AT, $out['at']);
        $this->assertSame(AtlasFrontierWaveLadder::FIELD_EVENT_THRESHOLD, $out['event_threshold']);
        $this->assertSame(CognitiveImmunePromotionGateEvaluator::FIELD_COUNT, $out['count']);
        $this->assertSame(CognitiveImmunePromotionGateEvaluator::FIELD_RECALL_CONCENTRATION_V2, $out['recall_concentration_v2']);
        $this->assertSame(18, $out['http_path_cognition_score_department_level_aaeos_doc_floor_count']);
    }

    public function test_aaeos_implementation_context_pareto_gate_phase_immune_calibration_floors_contract_observe_reports_floors(): void
    {
        $gates = $this->app->make(AtlasUniversalGatesEvaluator::class);
        $out = $gates->aaeosImplementationContextParetoGatePhaseImmuneCalibrationFloorsContractObserve([]);
        $this->assertSame(AtlasAaeosImplementationTruthService::FIELD_ID, $out['id']);
        $this->assertSame(AtlasAaeosImplementationTruthService::FIELD_RANK_COMPUTED, $out['rank_computed']);
        $this->assertSame(ContextParetoDominanceFilter::FIELD_ID, $out['id']);
        $this->assertSame(ContextParetoDominanceFilter::FIELD_SCHEMA_VERSION, $out['schema_version']);
        $this->assertSame(AtlasAaeosGateSignalEvaluator::FIELD_GATE, $out['gate']);
        $this->assertSame(AtlasAaeosGateSignalEvaluator::FIELD_INTENT, $out['intent']);
        $this->assertSame(AtlasAaeosPhaseRouterService::FIELD_POLICY_GATE, $out['policy_gate']);
        $this->assertSame(AtlasAaeosPhaseRouterService::FIELD_RECEIPT, $out['receipt']);
        $this->assertSame(ImmuneCalibrationService::FIELD_CLAIM_TYPE, $out['claim_type']);
        $this->assertSame(ImmuneCalibrationService::FIELD_CLASSIFIER_BAND, $out['classifier_band']);
        $this->assertSame(ImmuneSignatureIngestor::FIELD_BLOCKING_GATE_IDS, $out['blocking_gate_ids']);
        $this->assertSame(ImmuneSignatureIngestor::FIELD_ID, $out['id']);
        $this->assertSame(AtlasAcosWatchdogHealthService::FIELD_AI_RUN_OUTCOME_MAX_AGE_HOURS, $out['ai_run_outcome_max_age_hours']);
        $this->assertSame(AtlasAcosWatchdogHealthService::FIELD_BY_EXECUTOR, $out['by_executor']);
        $this->assertSame(AtlasUniversalGatesEvaluator::FIELD_SCHEMA_VERSION, $out['schema_version']);
        $this->assertSame(AtlasUniversalGatesEvaluator::FIELD_CANONICAL_SOURCE, $out['canonical_source']);
        $this->assertSame(AtlasCognitionEvidenceResolver::FIELD_ID, $out['id']);
        $this->assertSame(AtlasCognitionEvidenceResolver::FIELD_OWNER_DOC, $out['owner_doc']);
        $this->assertSame(18, $out['aaeos_implementation_context_pareto_gate_phase_immune_calibration_floor_count']);
    }

    public function test_aaeos_cognitive_implementation_veto_cross_department_lote_measure_floors_contract_observe_reports_floors(): void
    {
        $gates = $this->app->make(AtlasUniversalGatesEvaluator::class);
        $out = $gates->aaeosCognitiveImplementationVetoCrossDepartmentLoteMeasureFloorsContractObserve([]);
        $this->assertSame(AtlasAaeosCognitiveImmuneInputClassifier::FIELD_INPUT_CLASS, $out['input_class']);
        $this->assertSame(AtlasAaeosCognitiveImmuneInputClassifier::FIELD_MATCHED_SIGNALS, $out['matched_signals']);
        $this->assertSame(AtlasAaeosImplementationEvidenceResolver::FIELD_MATCHED, $out['matched']);
        $this->assertSame(AtlasAaeosImplementationEvidenceResolver::FIELD_MIGRATION, $out['migration']);
        $this->assertSame(AtlasAaeosVetoPropagationResolver::FIELD_FORGE, $out['forge']);
        $this->assertSame(AtlasAaeosVetoPropagationResolver::FIELD_QA, $out['qa']);
        $this->assertSame(AtlasCrossDepartmentChoreographyService::FIELD_ESCALATE_TO, $out['escalate_to']);
        $this->assertSame(AtlasCrossDepartmentChoreographyService::FIELD_FROM_DEPARTMENT, $out['from_department']);
        $this->assertSame(AcosMaxLote2MeasureService::FIELD_COMPLETED_E2E, $out['completed_e2e']);
        $this->assertSame(AcosMaxLote2MeasureService::FIELD_COMPLETION_CLAIM_ALLOWED, $out['completion_claim_allowed']);
        $this->assertSame(AtlasLocalModelIntegrityService::FIELD_PATH, $out['path']);
        $this->assertSame(AtlasLocalModelIntegrityService::FIELD_TOTAL, $out['total']);
        $this->assertSame(PortfolioBudgetAllocator::FIELD_FLAG_DEFAULT, $out['flag_default']);
        $this->assertSame(PortfolioBudgetAllocator::FIELD_OPERATOR_WEIGHTS, $out['operator_weights']);
        $this->assertSame(AtlasUniversalGatesEvaluator::FIELD_COUNT, $out['count']);
        $this->assertSame(AtlasUniversalGatesEvaluator::FIELD_DESCRIPTION, $out['description']);
        $this->assertSame(AcosMaxObraRetroService::FIELD_OBRA_RETRO_LOTE, $out['obra_retro_lote']);
        $this->assertSame(AcosMaxObraRetroService::FIELD_OUTCOME_FLOW_ID, $out['outcome_flow_id']);
        $this->assertSame(18, $out['aaeos_cognitive_implementation_veto_cross_department_lote_measure_floor_count']);
    }

    public function test_aaeos_department_string_debug_root_docs_authority_daily_floors_contract_observe_reports_floors(): void
    {
        $gates = $this->app->make(AtlasUniversalGatesEvaluator::class);
        $out = $gates->aaeosDepartmentStringDebugRootDocsAuthorityDailyFloorsContractObserve([]);
        $this->assertSame(AtlasAaeosDepartmentRegistryService::FIELD_DEPARTMENTS, $out['departments']);
        $this->assertSame(AtlasAaeosDepartmentRegistryService::FIELD_DUPLICATE_IDS, $out['duplicate_ids']);
        $this->assertSame(AtlasAaeosStringListNormalizer::FIELD_ID, $out['id']);
        $this->assertSame(AtlasAaeosStringListNormalizer::FIELD_KIND, $out['kind']);
        $this->assertSame(AtlasDebugRootCauseService::FIELD_STATUS, $out['status']);
        $this->assertSame(AtlasDebugRootCauseService::FIELD_SUSPECTED_CAUSE, $out['suspected_cause']);
        $this->assertSame(AtlasDocsAuthorityGraphService::FIELD_CREATED_AT, $out['created_at']);
        $this->assertSame(AtlasDocsAuthorityGraphService::FIELD_DOCS, $out['docs']);
        $this->assertSame(DailyCanaryReplayByRefsWatchdogCheck::FIELD_CEILING, $out['ceiling']);
        $this->assertSame(DailyCanaryReplayByRefsWatchdogCheck::FIELD_DELIVERED_REFS, $out['delivered_refs']);
        $this->assertSame(OperatorReviewDebtWatchdogCheck::FIELD_SCHEMA_VERSION, $out['schema_version']);
        $this->assertSame(OperatorReviewDebtWatchdogCheck::FIELD_STATUS, $out['status']);
        $this->assertSame(AcosMaxLote2MeasureService::FIELD_CONTROL_SCORE_MEAN, $out['control_score_mean']);
        $this->assertSame(AcosMaxLote2MeasureService::FIELD_CORRELATION_LABEL_REQUIRED, $out['correlation_label_required']);
        $this->assertSame(AcosMaxObraRetroService::FIELD_OUTCOME_ID, $out['outcome_id']);
        $this->assertSame(AcosMaxObraRetroService::FIELD_PROPOSED_STATE, $out['proposed_state']);
        $this->assertSame(AcosMaxParallelExecutionProtocol::FIELD_RELEASED, $out['released']);
        $this->assertSame(AcosMaxParallelExecutionProtocol::FIELD_RELEASED_COUNT, $out['released_count']);
        $this->assertSame(18, $out['aaeos_department_string_debug_root_docs_authority_daily_floor_count']);
    }

    public function test_generated_contract_aaeos_claim_department_exploratory_bets_provenance_floors_contract_observe_reports_floors(): void
    {
        $gates = $this->app->make(AtlasUniversalGatesEvaluator::class);
        $out = $gates->generatedContractAaeosClaimDepartmentExploratoryBetsProvenanceFloorsContractObserve([]);
        $this->assertSame(AaeosGeneratedContractGate::FIELD_QUARANTINE_NAMESPACE, $out['quarantine_namespace']);
        $this->assertSame(AaeosGeneratedContractGate::FIELD_SCHEMA_VERSION, $out['schema_version']);
        $this->assertSame(AtlasAaeosClaimDefinitionOfDoneValidator::FIELD_EVALUATED_AGAINST, $out['evaluated_against']);
        $this->assertSame(AtlasAaeosClaimDefinitionOfDoneValidator::FIELD_FIELD_STATUS, $out['field_status']);
        $this->assertSame(AtlasAaeosDepartmentMaturityService::FIELD_DEPARTMENT, $out['department']);
        $this->assertSame(AtlasAaeosDepartmentMaturityService::FIELD_DEPARTMENTS, $out['departments']);
        $this->assertSame(ExploratoryBetsPortfolio::FIELD_FLAG, $out['flag']);
        $this->assertSame(ExploratoryBetsPortfolio::FIELD_FLAG_DEFAULT, $out['flag_default']);
        $this->assertSame(ProvenanceWeightCalculator::FIELD_FLOOR, $out['floor']);
        $this->assertSame(ProvenanceWeightCalculator::FIELD_HOT_PATH_LEDGER_LOOKUP, $out['hot_path_ledger_lookup']);
        $this->assertSame(CompactionRecoverySampleWatchdogCheck::FIELD_CODE, $out['code']);
        $this->assertSame(CompactionRecoverySampleWatchdogCheck::FIELD_MESSAGE, $out['message']);
        $this->assertSame(OperatorLearningCaptureSchemaWatchdogCheck::FIELD_CODE, $out['code']);
        $this->assertSame(OperatorLearningCaptureSchemaWatchdogCheck::FIELD_MESSAGE, $out['message']);
        $this->assertSame(AtlasAaeosCognitiveImmuneInputClassifier::FIELD_MEMORY_ELIGIBLE, $out['memory_eligible']);
        $this->assertSame(AtlasAaeosCognitiveImmuneInputClassifier::FIELD_REASON, $out['reason']);
        $this->assertSame(AcosMaxLote2MeasureService::FIELD_COSINE_MERGE_THRESHOLD, $out['cosine_merge_threshold']);
        $this->assertSame(AcosMaxLote2MeasureService::FIELD_COUNT, $out['count']);
        $this->assertSame(18, $out['generated_contract_aaeos_claim_department_exploratory_bets_provenance_floor_count']);
    }

    public function test_aaeos_department_evidence_vision_golden_counterfactual_promotion_protocol_floors_contract_observe_reports_floors(): void
    {
        $gates = $this->app->make(AtlasUniversalGatesEvaluator::class);
        $out = $gates->aaeosDepartmentEvidenceVisionGoldenCounterfactualPromotionProtocolFloorsContractObserve([]);
        $this->assertSame(AtlasAaeosDepartmentPromotionEligibilityEvaluator::FIELD_CANONICAL_WRITE_ALLOWED, $out['canonical_write_allowed']);
        $this->assertSame(AtlasAaeosDepartmentPromotionEligibilityEvaluator::FIELD_DEFICIT, $out['deficit']);
        $this->assertSame(EvidenceVisionThesisLifecycle::FIELD_EVIDENCE, $out['evidence']);
        $this->assertSame(EvidenceVisionThesisLifecycle::FIELD_HIGH, $out['high']);
        $this->assertSame(GoldenCounterfactualReplayService::FIELD_DELTA, $out['delta']);
        $this->assertSame(GoldenCounterfactualReplayService::FIELD_DELTA_REQUIRES_BOTH_ARMS, $out['delta_requires_both_arms']);
        $this->assertSame(PromotionProtocol::FIELD_FLAGS, $out['flags']);
        $this->assertSame(PromotionProtocol::FIELD_LAST_FLIP, $out['last_flip']);
        $this->assertSame(AaeosBlockerSeverityGate::FIELD_LOW_COUNT, $out['low_count']);
        $this->assertSame(AaeosBlockerSeverityGate::FIELD_MEDIUM_COUNT, $out['medium_count']);
        $this->assertSame(AaeosPhaseHandoffService::FIELD_CERTIFICATION_SEVERITY_ACCEPTABLE, $out['certification_severity_acceptable']);
        $this->assertSame(AaeosPhaseHandoffService::FIELD_DECISION_RECEIPT_V2_SIGNED, $out['decision_receipt_v2_signed']);
        $this->assertSame(ImmuneSignatureDeriver::FIELD_FAMILY, $out['family']);
        $this->assertSame(ImmuneSignatureDeriver::FIELD_HOSTILE_CLASS, $out['hostile_class']);
        $this->assertSame(JointResourceBudgetWatchdogCheck::FIELD_CODE, $out['code']);
        $this->assertSame(JointResourceBudgetWatchdogCheck::FIELD_GENERATED_AT, $out['generated_at']);
        $this->assertSame(LocalModelIntegrityWatchdogCheck::FIELD_CODE, $out['code']);
        $this->assertSame(LocalModelIntegrityWatchdogCheck::FIELD_MESSAGE, $out['message']);
        $this->assertSame(18, $out['aaeos_department_evidence_vision_golden_counterfactual_promotion_protocol_floor_count']);
    }

    public function test_segment_importance_summary_fidelity_outcome_envelope_ragx_chain_floors_contract_observe_reports_floors(): void
    {
        $gates = $this->app->make(AtlasUniversalGatesEvaluator::class);
        $out = $gates->segmentImportanceSummaryFidelityOutcomeEnvelopeRagxChainFloorsContractObserve([]);
        $this->assertSame(SegmentImportanceRanker::FIELD_KEPT_COUNT, $out['kept_count']);
        $this->assertSame(SegmentImportanceRanker::FIELD_KEPT_IDS, $out['kept_ids']);
        $this->assertSame(SummaryFidelityCoverageScorer::FIELD_MISSING_TOTAL, $out['missing_total']);
        $this->assertSame(SummaryFidelityCoverageScorer::FIELD_PRESENT_ITEM_IDS, $out['present_item_ids']);
        $this->assertSame(OutcomeEnvelopeBridge::FIELD_ADAPTER_ORIGINS, $out['adapter_origins']);
        $this->assertSame(OutcomeEnvelopeBridge::FIELD_AUTHOR_ENGINE_ID, $out['author_engine_id']);
        $this->assertSame(RagxChainMechanismService::FIELD_L2_SUMMARY_ID, $out['l2_summary_id']);
        $this->assertSame(RagxChainMechanismService::FIELD_MAXD05_LOUVAIN, $out['maxd05_louvain']);
        $this->assertSame(Teto10PredictedRevertReviewDigest::FIELD_COUNT, $out['count']);
        $this->assertSame(Teto10PredictedRevertReviewDigest::FIELD_EVIDENCE_REF, $out['evidence_ref']);
        $this->assertSame(AtlasImmuneHybridInputClassifier::FIELD_CLASSES, $out['classes']);
        $this->assertSame(AtlasImmuneHybridInputClassifier::FIELD_MODE, $out['mode']);
        $this->assertSame(AobgLatencyWatchdogCheck::FIELD_CODE, $out['code']);
        $this->assertSame(AobgLatencyWatchdogCheck::FIELD_FLOOR_MS, $out['floor_ms']);
        $this->assertSame(AtlasAaeosClaimDefinitionOfDoneValidator::FIELD_PARTIAL_CLAIM, $out['partial_claim']);
        $this->assertSame(AtlasAaeosClaimDefinitionOfDoneValidator::FIELD_PASSES, $out['passes']);
        $this->assertSame(AtlasAaeosDepartmentMaturityBandClassifier::FIELD_OBSERVED, $out['observed']);
        $this->assertSame(AtlasAaeosDepartmentMaturityBandClassifier::FIELD_PER_BAND, $out['per_band']);
        $this->assertSame(18, $out['segment_importance_summary_fidelity_outcome_envelope_ragx_chain_floor_count']);
    }

    public function test_memory_recall_esp_independent_maxa_jina_immune_classifier_floors_contract_observe_reports_floors(): void
    {
        $gates = $this->app->make(AtlasUniversalGatesEvaluator::class);
        $out = $gates->memoryRecallEspIndependentMaxaJinaImmuneClassifierFloorsContractObserve([]);
        $this->assertSame(AtlasMemoryRecallRelevanceScorer::FIELD_FAILURE, $out['failure']);
        $this->assertSame(AtlasMemoryRecallRelevanceScorer::FIELD_RELEVANCE_SCORE, $out['relevance_score']);
        $this->assertSame(Esp09IndependentChallengerService::FIELD_REQUIRES_CHALLENGER, $out['requires_challenger']);
        $this->assertSame(Esp09IndependentChallengerService::FIELD_SERIES, $out['series']);
        $this->assertSame(Maxa04JinaV3DualReadService::FIELD_DESCRIPTION, $out['description']);
        $this->assertSame(Maxa04JinaV3DualReadService::FIELD_HANDLE, $out['handle']);
        $this->assertSame(AtlasImmuneClassifierHybridFreeze::FIELD_DEFAULT, $out['default']);
        $this->assertSame(AtlasImmuneClassifierHybridFreeze::FIELD_FREEZE, $out['freeze']);
        $this->assertSame(AtlasWatchdogRunner::FIELD_EMITTER_STAGE, $out['emitter_stage']);
        $this->assertSame(AtlasWatchdogRunner::FIELD_EMITTER_VERSION, $out['emitter_version']);
        $this->assertSame(AutonomyLadderAdversarialWatchdogCheck::FIELD_GATES_MUTATION, $out['gates_mutation']);
        $this->assertSame(AutonomyLadderAdversarialWatchdogCheck::FIELD_READ_ONLY, $out['read_only']);
        $this->assertSame(AtlasAaeosClaimDefinitionOfDoneValidator::FIELD_PRESENT_FIELDS, $out['present_fields']);
        $this->assertSame(AtlasAaeosClaimDefinitionOfDoneValidator::FIELD_REASON, $out['reason']);
        $this->assertSame(AtlasAaeosDepartmentMaturityBandClassifier::FIELD_THRESHOLD, $out['threshold']);
        $this->assertSame(AtlasAaeosDepartmentMaturityBandClassifier::FIELD_THRESHOLDS, $out['thresholds']);
        $this->assertSame(AtlasAaeosDepartmentMaturityService::FIELD_ID, $out['id']);
        $this->assertSame(AtlasAaeosDepartmentMaturityService::FIELD_LAST_EVALUATION, $out['last_evaluation']);
        $this->assertSame(18, $out['memory_recall_esp_independent_maxa_jina_immune_classifier_floor_count']);
    }

    public function test_procedural_skill_verified_share_acos_program_deferred_phase_floors_contract_observe_reports_floors(): void
    {
        $gates = $this->app->make(AtlasUniversalGatesEvaluator::class);
        $out = $gates->proceduralSkillVerifiedShareAcosProgramDeferredPhaseFloorsContractObserve([]);
        $this->assertSame(AcosMaxProceduralSkillPromoterService::FIELD_MEMORY_TYPE, $out['memory_type']);
        $this->assertSame(AcosMaxProceduralSkillPromoterService::FIELD_NAME, $out['name']);
        $this->assertSame(AcosMaxVerifiedShareService::FIELD_JUDGE_AUTHOR_DISTINCT, $out['judge_author_distinct']);
        $this->assertSame(AcosMaxVerifiedShareService::FIELD_MODE, $out['mode']);
        $this->assertSame(AcosProgramCockpitService::FIELD_EXIT_CODE, $out['exit_code']);
        $this->assertSame(AcosProgramCockpitService::FIELD_GENERATED_AT, $out['generated_at']);
        $this->assertSame(AaeosDeferredPhaseDispatcherService::FIELD_ENQUEUED_COUNT, $out['enqueued_count']);
        $this->assertSame(AaeosDeferredPhaseDispatcherService::FIELD_GATES, $out['gates']);
        $this->assertSame(AtlasAcosWindowGatesService::FIELD_LIVE_DIMENSIONS, $out['live_dimensions']);
        $this->assertSame(AtlasAcosWindowGatesService::FIELD_NOTE, $out['note']);
        $this->assertSame(AtlasCognitionRemintTouchedQueue::FIELD_JSON, $out['json']);
        $this->assertSame(AtlasCognitionRemintTouchedQueue::FIELD_METADATA, $out['metadata']);
        $this->assertSame(AtlasCognitionScoreCardV4Grouper::FIELD_INTEGRATION, $out['integration']);
        $this->assertSame(AtlasCognitionScoreCardV4Grouper::FIELD_LONG_HORIZON, $out['long_horizon']);
        $this->assertSame(AtlasAaeosDepartmentMaturityService::FIELD_MATURITY_TIER, $out['maturity_tier']);
        $this->assertSame(AtlasAaeosDepartmentMaturityService::FIELD_NEXT_EVALUATION_DUE, $out['next_evaluation_due']);
        $this->assertSame(AtlasAaeosDepartmentPromotionEligibilityEvaluator::FIELD_ELIGIBILITY_HASH, $out['eligibility_hash']);
        $this->assertSame(AtlasAaeosDepartmentPromotionEligibilityEvaluator::FIELD_FRESHNESS, $out['freshness']);
        $this->assertSame(18, $out['procedural_skill_verified_share_acos_program_deferred_phase_floor_count']);
    }

    public function test_aemor_outcome_ambition_rung_flywheel_funnel_composed_obra_floors_contract_observe_reports_floors(): void
    {
        $gates = $this->app->make(AtlasUniversalGatesEvaluator::class);
        $out = $gates->aemorOutcomeAmbitionRungFlywheelFunnelComposedObraFloorsContractObserve([]);
        $this->assertSame(AemorOutcomeEnvelopeAdapter::FIELD_NATIVE_DIVERGENT, $out['native_divergent']);
        $this->assertSame(AemorOutcomeEnvelopeAdapter::FIELD_SCOPE_ID, $out['scope_id']);
        $this->assertSame(AmbitionRungPolicy::FIELD_RUNG_SERIES_INFORMATIONAL, $out['rung_series_informational']);
        $this->assertSame(AmbitionRungPolicy::FIELD_RUNG_SERIES_USED_AS_SCORE, $out['rung_series_used_as_score']);
        $this->assertSame(AtlasFlywheelFunnelService::FIELD_OUTCOME_ROWS, $out['outcome_rows']);
        $this->assertSame(AtlasFlywheelFunnelService::FIELD_OUTCOMES_PATH, $out['outcomes_path']);
        $this->assertSame(ComposedObraArcComposer::FIELD_TARGET_FQCN, $out['target_fqcn']);
        $this->assertSame(ComposedObraArcComposer::FIELD_TASKS, $out['tasks']);
        $this->assertSame(DepartmentContractRuntime::FIELD_EVALUATION, $out['evaluation']);
        $this->assertSame(DepartmentContractRuntime::FIELD_EVIDENCE_COUNT, $out['evidence_count']);
        $this->assertSame(QualityBarTelemetryContract::FIELD_INPUTS, $out['inputs']);
        $this->assertSame(QualityBarTelemetryContract::FIELD_TELEMETRY_FIELDS, $out['telemetry_fields']);
        $this->assertSame(RunbookOrchestrator::FIELD_DETAIL, $out['detail']);
        $this->assertSame(RunbookOrchestrator::FIELD_DUAL_SIGNATURE_REQUIRED, $out['dual_signature_required']);
        $this->assertSame(AtlasCognitiveFunctionAtlasService::FIELD_KERNEL_HASH, $out['kernel_hash']);
        $this->assertSame(AtlasCognitiveFunctionAtlasService::FIELD_MEMORY, $out['memory']);
        $this->assertSame(AtlasAaeosDepartmentMaturityService::FIELD_PRIMARY_BLOCKER, $out['primary_blocker']);
        $this->assertSame(AtlasAaeosDepartmentMaturityService::FIELD_SCHEMA, $out['schema']);
        $this->assertSame(18, $out['aemor_outcome_ambition_rung_flywheel_funnel_composed_obra_floor_count']);
    }

    public function test_resource_budget_belief_cascade_citation_grounding_dev_procedural_floors_contract_observe_reports_floors(): void
    {
        $gates = $this->app->make(AtlasUniversalGatesEvaluator::class);
        $out = $gates->resourceBudgetBeliefCascadeCitationGroundingDevProceduralFloorsContractObserve([]);
        $this->assertSame(AtlasResourceBudgetService::FIELD_OVER_CAP_COMPONENTS, $out['over_cap_components']);
        $this->assertSame(AtlasResourceBudgetService::FIELD_PAPER_HEADROOM_MB, $out['paper_headroom_mb']);
        $this->assertSame(BeliefCascadeReverificationPlanner::FIELD_SCHEMA_VERSION, $out['schema_version']);
        $this->assertSame(BeliefCascadeReverificationPlanner::FIELD_SOURCE, $out['source']);
        $this->assertSame(CitationGroundingMeter::FIELD_SCHEMA_VERSION, $out['schema_version']);
        $this->assertSame(CitationGroundingMeter::FIELD_SOURCE, $out['source']);
        $this->assertSame(DevProceduralOutcomeEnvelopeAdapter::FIELD_NATIVE_DIVERGENT, $out['native_divergent']);
        $this->assertSame(DevProceduralOutcomeEnvelopeAdapter::FIELD_SCHEMA_VERSION, $out['schema_version']);
        $this->assertSame(DeliveryPackCompletenessScorer::FIELD_EVIDENCE_HASHES, $out['evidence_hashes']);
        $this->assertSame(DeliveryPackCompletenessScorer::FIELD_EVIDENCE_PRESENT, $out['evidence_present']);
        $this->assertSame(AtlasCognitiveFunctionDecomposerService::FIELD_DECOMPOSITION_HASH, $out['decomposition_hash']);
        $this->assertSame(AtlasCognitiveFunctionDecomposerService::FIELD_DOMINANT, $out['dominant']);
        $this->assertSame(AtlasImmuneSignatureFreeze::FIELD_DECAY_DAYS, $out['decay_days']);
        $this->assertSame(AtlasImmuneSignatureFreeze::FIELD_DEFAULT_MODE, $out['default_mode']);
        $this->assertSame(AtlasOperationalVolumeCheckService::FIELD_FORGE_CYCLES_PER_WEEK_MIN, $out['forge_cycles_per_week_min']);
        $this->assertSame(AtlasOperationalVolumeCheckService::FIELD_ID, $out['id']);
        $this->assertSame(AtlasAaeosDepartmentMaturityService::FIELD_SCHEMA_VERSION, $out['schema_version']);
        $this->assertSame(AtlasAaeosDepartmentMaturityService::FIELD_SEVERITY, $out['severity']);
        $this->assertSame(18, $out['resource_budget_belief_cascade_citation_grounding_dev_procedural_floor_count']);
    }

    public function test_b375_n_capture_domain_lexical_evidence_vision_execution_context_floors_contract_observe_reports_floors(): void
    {
        $gates = $this->app->make(AtlasUniversalGatesEvaluator::class);
        $out = $gates->b375NCaptureDomainLexicalEvidenceVisionExecutionContextFloorsContractObserve([]);
        $this->assertSame(AtlasNCaptureDrillService::FIELD_PROVEN_REAL_OUTCOMES_OBSERVED, $out['proven_real_outcomes_observed']);
        $this->assertSame(AtlasNCaptureDrillService::FIELD_REFUSED_COUNT, $out['refused_count']);
        $this->assertSame(DomainLexicalNormalizer::FIELD_MAX_EXPANDED_TOKENS, $out['max_expanded_tokens']);
        $this->assertSame(DomainLexicalNormalizer::FIELD_OPERADOR, $out['operador']);
        $this->assertSame(EvidenceVisionThesisComposer::FIELD_PATH, $out['path']);
        $this->assertSame(EvidenceVisionThesisComposer::FIELD_SWEET, $out['sweet']);
        $this->assertSame(ExecutionContextCooccurrenceService::FIELD_INTERSECTION_ALONE_IS_NOT_CAUSAL, $out['intersection_alone_is_not_causal']);
        $this->assertSame(ExecutionContextCooccurrenceService::FIELD_INTERSECTION_REFS, $out['intersection_refs']);
        $this->assertSame(ArchitectAgentSpecPackGateContract::FIELD_INPUTS, $out['inputs']);
        $this->assertSame(ArchitectAgentSpecPackGateContract::FIELD_REQUIRED_SPEC_PACK_ARTIFACTS, $out['required_spec_pack_artifacts']);
        $this->assertSame(AtlasAaeosHttpPathFacadeService::FIELD_MAX, $out['max']);
        $this->assertSame(AtlasAaeosHttpPathFacadeService::FIELD_PHASE_ACTIVE, $out['phase_active']);
        $this->assertSame(AtlasMissionControlCockpitService::FIELD_OUTCOME, $out['outcome']);
        $this->assertSame(AtlasMissionControlCockpitService::FIELD_PROVIDER_SAFE, $out['provider_safe']);
        $this->assertSame(AtlasCognitiveMemoryFabricSchemaEvolutionService::FIELD_GENERATED_AT, $out['generated_at']);
        $this->assertSame(AtlasCognitiveMemoryFabricSchemaEvolutionService::FIELD_IS_PROPOSAL, $out['is_proposal']);
        $this->assertSame(AtlasConsolidationRerankGuard::FIELD_STATUS, $out['status']);
        $this->assertSame(AtlasConsolidationRerankGuard::FIELD_VERDICT, $out['verdict']);
        $this->assertSame(18, $out['b375_n_capture_domain_lexical_evidence_vision_execution_context_floor_count']);
    }

    public function test_aaeos_test_window_orchestrator_code_symbol_knowledge_item_floors_contract_observe_reports_floors(): void
    {
        $gates = $this->app->make(AtlasUniversalGatesEvaluator::class);
        $out = $gates->aaeosTestWindowOrchestratorCodeSymbolKnowledgeItemFloorsContractObserve([]);
        $this->assertSame(AtlasAaeosTestExecutionService::FIELD_FRESH_HASHES, $out['fresh_hashes']);
        $this->assertSame(AtlasAaeosTestExecutionService::FIELD_GIT_PORCELAIN, $out['git_porcelain']);
        $this->assertSame(AcosMaxWindowOrchestratorService::FIELD_DURATION_DAYS, $out['duration_days']);
        $this->assertSame(AcosMaxWindowOrchestratorService::FIELD_LAST_DATA_AT, $out['last_data_at']);
        $this->assertSame(AtlasCodeSymbolEmbeddingCoverageService::FIELD_JUDGE_ENGINE_ID, $out['judge_engine_id']);
        $this->assertSame(AtlasCodeSymbolEmbeddingCoverageService::FIELD_MISSING_DEFINITION, $out['missing_definition']);
        $this->assertSame(AtlasKnowledgeItemEmbeddingCoverageService::FIELD_MISSING_DEFINITION, $out['missing_definition']);
        $this->assertSame(AtlasKnowledgeItemEmbeddingCoverageService::FIELD_PATH, $out['path']);
        $this->assertSame(AtlasAcosEvolutionScoreService::FIELD_DIMENSIONS, $out['dimensions']);
        $this->assertSame(AtlasAcosEvolutionScoreService::FIELD_EXECUCAO_PROVADA, $out['execucao_provada']);
        $this->assertSame(AtlasAcosLongHorizonGateService::FIELD_DELTA_SERIES_APPEND_ONLY_INPUT, $out['delta_series_append_only_input']);
        $this->assertSame(AtlasAcosLongHorizonGateService::FIELD_DIMENSIONS, $out['dimensions']);
        $this->assertSame(AtlasAcosRollbackTriggerCheckService::FIELD_EVALUATIONS, $out['evaluations']);
        $this->assertSame(AtlasAcosRollbackTriggerCheckService::FIELD_FLIP_COUNT, $out['flip_count']);
        $this->assertSame(AtlasAaeosDepartmentPromotionEligibilityEvaluator::FIELD_ID, $out['id']);
        $this->assertSame(AtlasAaeosDepartmentPromotionEligibilityEvaluator::FIELD_LAST_EVALUATION, $out['last_evaluation']);
        $this->assertSame(AtlasAaeosDocMaturityClassifier::FIELD_MOTHER_DOC, $out['mother_doc']);
        $this->assertSame(AtlasAaeosDocMaturityClassifier::FIELD_RATIONALE, $out['rationale']);
        $this->assertSame(18, $out['aaeos_test_window_orchestrator_code_symbol_knowledge_item_floor_count']);
    }

    public function test_pre_review_http_path_phase_advance_cognition_score_floors_contract_observe_reports_floors(): void
    {
        $gates = $this->app->make(AtlasUniversalGatesEvaluator::class);
        $out = $gates->preReviewHttpPathPhaseAdvanceCognitionScoreFloorsContractObserve([]);
        $this->assertSame(PreReviewAdvisoryBand::FIELD_LIFT_HIGH_OVER_LOW, $out['lift_high_over_low']);
        $this->assertSame(PreReviewAdvisoryBand::FIELD_MEDIUM, $out['medium']);
        $this->assertSame(AaeosHttpPathEnvelopeFactory::FIELD_POLICY_TARGET, $out['policy_target']);
        $this->assertSame(AaeosHttpPathEnvelopeFactory::FIELD_PROVIDER, $out['provider']);
        $this->assertSame(PhaseAdvanceVerdictClassifier::FIELD_PASSED, $out['passed']);
        $this->assertSame(PhaseAdvanceVerdictClassifier::FIELD_REASON, $out['reason']);
        $this->assertSame(AtlasCognitionScoreCardService::FIELD_DIMENSIONS, $out['dimensions']);
        $this->assertSame(AtlasCognitionScoreCardService::FIELD_DOC, $out['doc']);
        $this->assertSame(AtlasFrontierWaveLadder::FIELD_GENERATED_AT, $out['generated_at']);
        $this->assertSame(AtlasFrontierWaveLadder::FIELD_NOTE, $out['note']);
        $this->assertSame(AtlasAaeosDepartmentPromotionEligibilityEvaluator::FIELD_MAX_AGE_DAYS, $out['max_age_days']);
        $this->assertSame(AtlasAaeosDepartmentPromotionEligibilityEvaluator::FIELD_MAX_EVIDENCE_AGE_DAYS, $out['max_evidence_age_days']);
        $this->assertSame(AtlasAaeosDocMaturityClassifier::FIELD_RUNBOOK, $out['runbook']);
        $this->assertSame(AtlasAaeosDocMaturityClassifier::FIELD_RUNTIME_READY, $out['runtime_ready']);
        $this->assertSame(AtlasAaeosGateSignalEvaluator::FIELD_MISSING_ANSWERS, $out['missing_answers']);
        $this->assertSame(AtlasAaeosGateSignalEvaluator::FIELD_NO_PHASE_OUTPUTS, $out['no_phase_outputs']);
        $this->assertSame(AtlasAaeosImplementationEvidenceResolver::FIELD_RECEIPT, $out['receipt']);
        $this->assertSame(AtlasAaeosImplementationEvidenceResolver::FIELD_REF, $out['ref']);
        $this->assertSame(18, $out['pre_review_http_path_phase_advance_cognition_score_floor_count']);
    }

    public function test_aaeos_implementation_phase_immune_calibration_signature_acos_watchdog_floors_contract_observe_reports_floors(): void
    {
        $gates = $this->app->make(AtlasUniversalGatesEvaluator::class);
        $out = $gates->aaeosImplementationPhaseImmuneCalibrationSignatureAcosWatchdogFloorsContractObserve([]);
        $this->assertSame(AtlasAaeosImplementationTruthService::FIELD_ROUTE, $out['route']);
        $this->assertSame(AtlasAaeosImplementationTruthService::FIELD_SCORE_OUT_OF_10, $out['score_out_of_10']);
        $this->assertSame(AtlasAaeosPhaseRouterService::FIELD_ROUTING, $out['routing']);
        $this->assertSame(AtlasAaeosPhaseRouterService::FIELD_SPEC, $out['spec']);
        $this->assertSame(ImmuneCalibrationService::FIELD_CLASSIFIER_SCHEMA_VERSION, $out['classifier_schema_version']);
        $this->assertSame(ImmuneCalibrationService::FIELD_CONSENT_GRANTED, $out['consent_granted']);
        $this->assertSame(ImmuneSignatureIngestor::FIELD_INPUT_CLASS, $out['input_class']);
        $this->assertSame(ImmuneSignatureIngestor::FIELD_MEMORY_REVERT, $out['memory_revert']);
        $this->assertSame(AtlasAcosWatchdogHealthService::FIELD_BY_WRITER, $out['by_writer']);
        $this->assertSame(AtlasAcosWatchdogHealthService::FIELD_COMMANDS, $out['commands']);
        $this->assertSame(AtlasAaeosDepartmentPromotionEligibilityEvaluator::FIELD_MAX_TIER, $out['max_tier']);
        $this->assertSame(AtlasAaeosDepartmentPromotionEligibilityEvaluator::FIELD_REQUIRED_THRESHOLD, $out['required_threshold']);
        $this->assertSame(AtlasAaeosDocMaturityClassifier::FIELD_SATISFIED, $out['satisfied']);
        $this->assertSame(AtlasAaeosDocMaturityClassifier::FIELD_SCHEMA_VERSION, $out['schema_version']);
        $this->assertSame(AtlasAaeosGateSignalEvaluator::FIELD_RESOLVED_TARGET, $out['resolved_target']);
        $this->assertSame(AtlasAaeosGateSignalEvaluator::FIELD_SCOPE, $out['scope']);
        $this->assertSame(AtlasAaeosImplementationEvidenceResolver::FIELD_RESOLVED, $out['resolved']);
        $this->assertSame(AtlasAaeosImplementationEvidenceResolver::FIELD_ROUTE, $out['route']);
        $this->assertSame(18, $out['aaeos_implementation_phase_immune_calibration_signature_acos_watchdog_floor_count']);
    }

    public function test_cross_department_portfolio_budget_aaeos_gate_implementation_phase_floors_contract_observe_reports_floors(): void
    {
        $gates = $this->app->make(AtlasUniversalGatesEvaluator::class);
        $out = $gates->crossDepartmentPortfolioBudgetAaeosGateImplementationPhaseFloorsContractObserve([]);
        $this->assertSame(AtlasCrossDepartmentChoreographyService::FIELD_ITERATION, $out['iteration']);
        $this->assertSame(AtlasCrossDepartmentChoreographyService::FIELD_MAX_ITERATIONS, $out['max_iterations']);
        $this->assertSame(PortfolioBudgetAllocator::FIELD_STARVATION_FLOOR_ABSOLUTE, $out['starvation_floor_absolute']);
        $this->assertSame(PortfolioBudgetAllocator::FIELD_YIELD_RECOMPUTED_HERE, $out['yield_recomputed_here']);
        $this->assertSame(AtlasAaeosDepartmentPromotionEligibilityEvaluator::FIELD_RESOLVED, $out['resolved']);
        $this->assertSame(AtlasAaeosDepartmentPromotionEligibilityEvaluator::FIELD_TARGET_THRESHOLD, $out['target_threshold']);
        $this->assertSame(AtlasAaeosGateSignalEvaluator::FIELD_SCOPE_BOUNDED, $out['scope_bounded']);
        $this->assertSame(AtlasAaeosGateSignalEvaluator::FIELD_SPEC_PACK, $out['spec_pack']);
        $this->assertSame(AtlasAaeosImplementationTruthService::FIELD_STATUS, $out['status']);
        $this->assertSame(AtlasAaeosImplementationTruthService::FIELD_TEST_FILE_HASH, $out['test_file_hash']);
        $this->assertSame(AtlasAaeosPhaseRouterService::FIELD_TASKS, $out['tasks']);
        $this->assertSame(AtlasAaeosPhaseRouterService::FIELD_TOPOLOGY, $out['topology']);
        $this->assertSame(AtlasAaeosTestExecutionService::FIELD_SCHEMA_VERSION, $out['schema_version']);
        $this->assertSame(AtlasAaeosTestExecutionService::FIELD_SEALED, $out['sealed']);
        $this->assertSame(AtlasDocsAuthorityGraphService::FIELD_GOVERNS, $out['governs']);
        $this->assertSame(AtlasDocsAuthorityGraphService::FIELD_GRAPH_ID, $out['graph_id']);
        $this->assertSame(AtlasMemoryRecallRelevanceScorer::FIELD_SCOPE, $out['scope']);
        $this->assertSame(AtlasMemoryRecallRelevanceScorer::FIELD_SCOPE_TYPE, $out['scope_type']);
        $this->assertSame(18, $out['cross_department_portfolio_budget_aaeos_gate_implementation_phase_floor_count']);
    }

    public function test_obra_retro_daily_canary_aaeos_gate_implementation_cross_floors_contract_observe_reports_floors(): void
    {
        $gates = $this->app->make(AtlasUniversalGatesEvaluator::class);
        $out = $gates->obraRetroDailyCanaryAaeosGateImplementationCrossFloorsContractObserve([]);
        $this->assertSame(AcosMaxObraRetroService::FIELD_PROVIDER, $out['provider']);
        $this->assertSame(AcosMaxObraRetroService::FIELD_RUN_ID, $out['run_id']);
        $this->assertSame(DailyCanaryReplayByRefsWatchdogCheck::FIELD_FD, $out['fd']);
        $this->assertSame(DailyCanaryReplayByRefsWatchdogCheck::FIELD_GOLDEN_RECALL_AT_5_FLOOR, $out['golden_recall_at_5_floor']);
        $this->assertSame(AtlasAaeosGateSignalEvaluator::FIELD_TASK_PACK, $out['task_pack']);
        $this->assertSame(AtlasAaeosGateSignalEvaluator::FIELD_TASKS, $out['tasks']);
        $this->assertSame(AtlasAaeosImplementationTruthService::FIELD_TEST_REFS, $out['test_refs']);
        $this->assertSame(AtlasAaeosImplementationTruthService::FIELD_UNVERIFIABLE_CLAIMS, $out['unverifiable_claims']);
        $this->assertSame(AtlasCrossDepartmentChoreographyService::FIELD_PAYLOAD, $out['payload']);
        $this->assertSame(AtlasCrossDepartmentChoreographyService::FIELD_REMAINING_REPAIRS, $out['remaining_repairs']);
        $this->assertSame(AtlasDocsAuthorityGraphService::FIELD_ID, $out['id']);
        $this->assertSame(AtlasDocsAuthorityGraphService::FIELD_IMPLEMENTATION_STATE, $out['implementation_state']);
        $this->assertSame(AtlasMemoryRecallRelevanceScorer::FIELD_SOURCE, $out['source']);
        $this->assertSame(AtlasMemoryRecallRelevanceScorer::FIELD_TASK, $out['task']);
        $this->assertSame(SegmentImportanceRanker::FIELD_LINKS_DECISION_OR_BLOCKER, $out['links_decision_or_blocker']);
        $this->assertSame(SegmentImportanceRanker::FIELD_RANKED, $out['ranked']);
        $this->assertSame(AcosMaxLote2MeasureService::FIELD_DEAD_WINDOW_SILENT_DAYS, $out['dead_window_silent_days']);
        $this->assertSame(AcosMaxLote2MeasureService::FIELD_DECISION_ID, $out['decision_id']);
        $this->assertSame(18, $out['obra_retro_daily_canary_aaeos_gate_implementation_cross_floor_count']);
    }

}
