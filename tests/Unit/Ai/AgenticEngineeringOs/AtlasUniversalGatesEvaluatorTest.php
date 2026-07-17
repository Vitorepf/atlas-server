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
}
