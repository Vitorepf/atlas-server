<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\ArchitectAgentSpecPackGateContract;
use App\Services\Ai\AgenticEngineeringOs\AtlasUniversalGatesEvaluator;
use App\Services\Ai\AgenticEngineeringOs\DeliveryPackCompletenessScorer;
use App\Services\Ai\AgenticEngineeringOs\QualityBarTelemetryContract;
use App\Services\Ai\AcosMax\PredictedImpactBand;
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
}
