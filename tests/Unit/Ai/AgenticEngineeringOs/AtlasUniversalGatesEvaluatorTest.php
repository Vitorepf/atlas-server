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
}
