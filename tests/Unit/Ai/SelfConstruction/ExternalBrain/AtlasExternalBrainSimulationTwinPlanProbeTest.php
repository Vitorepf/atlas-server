<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimulationTwinPlanProbe;
use Tests\TestCase;

final class AtlasExternalBrainSimulationTwinPlanProbeTest extends TestCase
{
    private function probe(): AtlasExternalBrainSimulationTwinPlanProbe
    {
        return new AtlasExternalBrainSimulationTwinPlanProbe();
    }

    private function okBatch(int $n = 2): array
    {
        $packets = [];
        for ($i = 0; $i < $n; $i++) {
            $packets[] = [
                'packet_id'                  => 'p'.$i,
                'estimated_compounding_value' => 0.8,
                'write_set'                  => ['file'.$i.'.php'],
            ];
        }

        return $packets;
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->probe()->probe(['batch' => []]);

        $this->assertSame(AtlasExternalBrainSimulationTwinPlanProbe::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->probe()->probe(['batch' => []]);

        foreach (['schema', 'verdict', 'risk_flags', 'risk_reasons', 'simulation_summary'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    public function test_simulation_summary_has_required_fields(): void
    {
        $result = $this->probe()->probe(['batch' => []]);

        foreach (['batch_size', 'forbidden_target_count', 'write_set_collision_count', 'projected_give_back_count', 'can_worker_handle', 'avg_compounding_value'] as $f) {
            $this->assertArrayHasKey($f, $result['simulation_summary']);
        }
    }

    // ── acceptable verdict ────────────────────────────────────────────────────

    public function test_acceptable_when_no_risk_factors(): void
    {
        $result = $this->probe()->probe([
            'batch'                          => $this->okBatch(2),
            'worker_capacity'                => 5,
            'current_queue_depth'            => 10,
            'saturation_limit'               => 100,
            'give_back_rate'                 => 0.10,
            'verification_evidence_coverage' => 0.95,
        ]);

        $this->assertSame(AtlasExternalBrainSimulationTwinPlanProbe::VERDICT_ACCEPTABLE, $result['verdict']);
        $this->assertSame([], $result['risk_flags']);
    }

    public function test_empty_batch_is_acceptable(): void
    {
        $result = $this->probe()->probe(['batch' => [], 'worker_capacity' => 0]);

        $this->assertSame(AtlasExternalBrainSimulationTwinPlanProbe::VERDICT_ACCEPTABLE, $result['verdict']);
    }

    // ── give_back_pressure ────────────────────────────────────────────────────

    public function test_risky_when_give_back_rate_at_threshold(): void
    {
        $result = $this->probe()->probe([
            'batch'            => $this->okBatch(2),
            'worker_capacity'  => 5,
            'give_back_rate'   => 0.30,
        ]);

        $this->assertSame(AtlasExternalBrainSimulationTwinPlanProbe::VERDICT_RISKY, $result['verdict']);
        $this->assertContains(AtlasExternalBrainSimulationTwinPlanProbe::FLAG_GIVE_BACK_PRESSURE, $result['risk_flags']);
    }

    public function test_acceptable_when_give_back_rate_below_threshold(): void
    {
        $result = $this->probe()->probe([
            'batch'           => $this->okBatch(2),
            'worker_capacity' => 5,
            'give_back_rate'  => 0.29,
        ]);

        $this->assertNotContains(AtlasExternalBrainSimulationTwinPlanProbe::FLAG_GIVE_BACK_PRESSURE, $result['risk_flags']);
    }

    // ── forbidden_target_pressure ─────────────────────────────────────────────

    public function test_risky_when_forbidden_target_in_batch(): void
    {
        $result = $this->probe()->probe([
            'batch'           => [['packet_id' => 'p0', 'is_forbidden_target' => true, 'estimated_compounding_value' => 0.9]],
            'worker_capacity' => 5,
        ]);

        $this->assertSame(AtlasExternalBrainSimulationTwinPlanProbe::VERDICT_RISKY, $result['verdict']);
        $this->assertContains(AtlasExternalBrainSimulationTwinPlanProbe::FLAG_FORBIDDEN_TARGET_PRESSURE, $result['risk_flags']);
    }

    public function test_forbidden_target_count_in_summary(): void
    {
        $result = $this->probe()->probe([
            'batch' => [
                ['packet_id' => 'a', 'is_forbidden_target' => true, 'estimated_compounding_value' => 0.9],
                ['packet_id' => 'b', 'is_forbidden_target' => true, 'estimated_compounding_value' => 0.9],
            ],
            'worker_capacity' => 5,
        ]);

        $this->assertSame(2, $result['simulation_summary']['forbidden_target_count']);
    }

    // ── queue_oversaturation ──────────────────────────────────────────────────

    public function test_risky_when_batch_plus_queue_exceeds_saturation_limit(): void
    {
        $result = $this->probe()->probe([
            'batch'               => $this->okBatch(5),
            'worker_capacity'     => 10,
            'current_queue_depth' => 97,
            'saturation_limit'    => 100,
        ]);

        $this->assertContains(AtlasExternalBrainSimulationTwinPlanProbe::FLAG_QUEUE_OVERSATURATION, $result['risk_flags']);
    }

    public function test_no_oversaturation_when_exactly_at_limit(): void
    {
        $result = $this->probe()->probe([
            'batch'               => $this->okBatch(5),
            'worker_capacity'     => 10,
            'current_queue_depth' => 95,
            'saturation_limit'    => 100,
        ]);

        $this->assertNotContains(AtlasExternalBrainSimulationTwinPlanProbe::FLAG_QUEUE_OVERSATURATION, $result['risk_flags']);
    }

    // ── low_compounding_value ─────────────────────────────────────────────────

    public function test_risky_when_avg_compounding_below_floor(): void
    {
        $result = $this->probe()->probe([
            'batch' => [
                ['packet_id' => 'x', 'estimated_compounding_value' => 0.05],
            ],
            'worker_capacity'       => 5,
            'compounding_value_floor' => 0.20,
        ]);

        $this->assertContains(AtlasExternalBrainSimulationTwinPlanProbe::FLAG_LOW_COMPOUNDING_VALUE, $result['risk_flags']);
    }

    public function test_no_low_compounding_when_avg_at_floor(): void
    {
        $result = $this->probe()->probe([
            'batch'                  => [['packet_id' => 'x', 'estimated_compounding_value' => 0.20]],
            'worker_capacity'        => 5,
            'compounding_value_floor' => 0.20,
        ]);

        $this->assertNotContains(AtlasExternalBrainSimulationTwinPlanProbe::FLAG_LOW_COMPOUNDING_VALUE, $result['risk_flags']);
    }

    // ── insufficient_worker_capacity ──────────────────────────────────────────

    public function test_risky_when_worker_capacity_less_than_batch_size(): void
    {
        $result = $this->probe()->probe([
            'batch'           => $this->okBatch(5),
            'worker_capacity' => 2,
        ]);

        $this->assertContains(AtlasExternalBrainSimulationTwinPlanProbe::FLAG_INSUFFICIENT_WORKER_CAPACITY, $result['risk_flags']);
        $this->assertFalse($result['simulation_summary']['can_worker_handle']);
    }

    public function test_acceptable_worker_capacity_when_equal_to_batch_size(): void
    {
        $result = $this->probe()->probe([
            'batch'           => $this->okBatch(3),
            'worker_capacity' => 3,
        ]);

        $this->assertNotContains(AtlasExternalBrainSimulationTwinPlanProbe::FLAG_INSUFFICIENT_WORKER_CAPACITY, $result['risk_flags']);
        $this->assertTrue($result['simulation_summary']['can_worker_handle']);
    }

    // ── write_set_collision ───────────────────────────────────────────────────

    public function test_risky_when_two_packets_share_a_file(): void
    {
        $result = $this->probe()->probe([
            'batch' => [
                ['packet_id' => 'a', 'write_set' => ['Foo.php', 'Bar.php'], 'estimated_compounding_value' => 0.8],
                ['packet_id' => 'b', 'write_set' => ['Foo.php'], 'estimated_compounding_value' => 0.8],
            ],
            'worker_capacity' => 5,
        ]);

        $this->assertContains(AtlasExternalBrainSimulationTwinPlanProbe::FLAG_WRITE_SET_COLLISION, $result['risk_flags']);
    }

    public function test_no_collision_when_write_sets_are_disjoint(): void
    {
        $result = $this->probe()->probe([
            'batch' => [
                ['packet_id' => 'a', 'write_set' => ['A.php'], 'estimated_compounding_value' => 0.8],
                ['packet_id' => 'b', 'write_set' => ['B.php'], 'estimated_compounding_value' => 0.8],
            ],
            'worker_capacity' => 5,
        ]);

        $this->assertNotContains(AtlasExternalBrainSimulationTwinPlanProbe::FLAG_WRITE_SET_COLLISION, $result['risk_flags']);
    }

    // ── insufficient_verification_evidence ───────────────────────────────────

    public function test_risky_when_evidence_coverage_below_threshold(): void
    {
        $result = $this->probe()->probe([
            'batch'                          => $this->okBatch(2),
            'worker_capacity'                => 5,
            'verification_evidence_coverage' => 0.50,
        ]);

        $this->assertContains(AtlasExternalBrainSimulationTwinPlanProbe::FLAG_INSUFFICIENT_VERIFICATION_EVIDENCE, $result['risk_flags']);
    }

    public function test_no_evidence_flag_when_coverage_at_threshold(): void
    {
        $result = $this->probe()->probe([
            'batch'                            => $this->okBatch(2),
            'worker_capacity'                  => 5,
            'verification_evidence_coverage'   => 0.70,
            'verification_evidence_threshold'  => 0.70,
        ]);

        $this->assertNotContains(AtlasExternalBrainSimulationTwinPlanProbe::FLAG_INSUFFICIENT_VERIFICATION_EVIDENCE, $result['risk_flags']);
    }

    // ── projected_give_back_count ─────────────────────────────────────────────

    public function test_projected_give_back_count_in_summary(): void
    {
        $result = $this->probe()->probe([
            'batch'           => $this->okBatch(10),
            'worker_capacity' => 15,
            'give_back_rate'  => 0.20,
        ]);

        $this->assertSame(2, $result['simulation_summary']['projected_give_back_count']);
    }

    // ── multiple flags ────────────────────────────────────────────────────────

    public function test_multiple_flags_all_reported(): void
    {
        $result = $this->probe()->probe([
            'batch' => [
                ['packet_id' => 'p0', 'is_forbidden_target' => true, 'estimated_compounding_value' => 0.01, 'write_set' => ['X.php']],
                ['packet_id' => 'p1', 'is_forbidden_target' => false, 'estimated_compounding_value' => 0.01, 'write_set' => ['X.php']],
            ],
            'worker_capacity'  => 1,   // insufficient (batch=2)
            'give_back_rate'   => 0.50, // above threshold
        ]);

        $this->assertSame(AtlasExternalBrainSimulationTwinPlanProbe::VERDICT_RISKY, $result['verdict']);
        $this->assertGreaterThan(1, count($result['risk_flags']));
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = [
            'batch'                          => $this->okBatch(3),
            'worker_capacity'                => 5,
            'current_queue_depth'            => 20,
            'give_back_rate'                 => 0.10,
            'verification_evidence_coverage' => 0.90,
        ];

        $this->assertSame($this->probe()->probe($input), $this->probe()->probe($input));
    }
}
