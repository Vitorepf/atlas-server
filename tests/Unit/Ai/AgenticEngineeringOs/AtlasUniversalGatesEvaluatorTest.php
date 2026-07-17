<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\ArchitectAgentSpecPackGateContract;
use App\Services\Ai\AgenticEngineeringOs\AtlasUniversalGatesEvaluator;
use App\Services\Ai\AgenticEngineeringOs\DeliveryPackCompletenessScorer;
use App\Services\Ai\AgenticEngineeringOs\QualityBarTelemetryContract;
use App\Services\Ai\AgenticEngineeringOs\RealityCompilerSlice;
use App\Services\Ai\AcosMax\PredictedImpactBand;
use App\Services\Ai\AcosMax\PreReviewAdvisoryBand;
use App\Services\Ai\AcosMax\Esp09IndependentChallengerService;
use App\Services\Ai\AcosMax\DogfoodingFrictionLeadMiner;
use App\Services\Ai\AcosMax\ReactiveSaturationSignal;
use App\Services\Ai\Aaeos\Cores\SpecCompletenessScorer;
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
}
