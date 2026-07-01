<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimulationTwinPlanProbe;
use Tests\TestCase;

final class AtlasExternalBrainSimulationTwinPlanProbeTest extends TestCase
{
    public function test_acceptable_batch_has_no_risk_flags(): void
    {
        $result = (new AtlasExternalBrainSimulationTwinPlanProbe)->probe([
            'batch' => [
                ['packet_id' => 'p1', 'estimated_compounding_value' => 0.8],
            ],
            'worker_capacity' => 5,
            'current_queue_depth' => 0,
        ]);

        $this->assertSame(AtlasExternalBrainSimulationTwinPlanProbe::VERDICT_ACCEPTABLE, $result['verdict']);
        $this->assertSame([], $result['risk_flags']);
    }

    public function test_risky_batch_reports_batch_level_risk_flags(): void
    {
        $result = (new AtlasExternalBrainSimulationTwinPlanProbe)->probe([
            'batch' => [
                ['packet_id' => 'p1', 'is_forbidden_target' => true, 'write_set' => ['a.php']],
                ['packet_id' => 'p2', 'write_set' => ['a.php']],
            ],
            'worker_capacity' => 0,
            'current_queue_depth' => 99,
            'saturation_limit' => 100,
            'give_back_rate' => 0.9,
            'verification_evidence_coverage' => 0.1,
        ]);

        $this->assertSame(AtlasExternalBrainSimulationTwinPlanProbe::VERDICT_RISKY, $result['verdict']);
        $this->assertContains(AtlasExternalBrainSimulationTwinPlanProbe::FLAG_GIVE_BACK_PRESSURE, $result['risk_flags']);
        $this->assertContains(AtlasExternalBrainSimulationTwinPlanProbe::FLAG_FORBIDDEN_TARGET_PRESSURE, $result['risk_flags']);
        $this->assertContains(AtlasExternalBrainSimulationTwinPlanProbe::FLAG_QUEUE_OVERSATURATION, $result['risk_flags']);
        $this->assertContains(AtlasExternalBrainSimulationTwinPlanProbe::FLAG_INSUFFICIENT_WORKER_CAPACITY, $result['risk_flags']);
        $this->assertContains(AtlasExternalBrainSimulationTwinPlanProbe::FLAG_WRITE_SET_COLLISION, $result['risk_flags']);
        $this->assertContains(AtlasExternalBrainSimulationTwinPlanProbe::FLAG_INSUFFICIENT_VERIFICATION_EVIDENCE, $result['risk_flags']);
    }

    public function test_per_candidate_predictions_cover_duplicate_contradiction_missing_files_and_low_value(): void
    {
        $result = (new AtlasExternalBrainSimulationTwinPlanProbe)->probe([
            'batch' => [
                ['packet_id' => 'dup', 'target_path' => 'app/Foo.php'],
                ['packet_id' => 'dup2', 'target_path' => 'app/Foo.php'],
                ['packet_id' => 'contradict', 'contradiction_risk_score' => 0.9],
                ['packet_id' => 'missing', 'has_implementation_file' => false, 'has_test_file' => true],
                ['packet_id' => 'lowvalue', 'value_density' => 0.05],
            ],
        ]);

        $predictions = collect($result['per_candidate_predictions'])->keyBy('packet_id');

        $this->assertSame(AtlasExternalBrainSimulationTwinPlanProbe::OUTCOME_REJECT, $predictions['dup2']['predicted_outcome']);
        $this->assertSame(AtlasExternalBrainSimulationTwinPlanProbe::OUTCOME_DEFER, $predictions['contradict']['predicted_outcome']);
        $this->assertSame(AtlasExternalBrainSimulationTwinPlanProbe::OUTCOME_REPAIR, $predictions['missing']['predicted_outcome']);
        $this->assertSame(AtlasExternalBrainSimulationTwinPlanProbe::OUTCOME_SPLIT, $predictions['lowvalue']['predicted_outcome']);
    }

    public function test_simulation_summary_is_deterministic_and_never_mutates_input(): void
    {
        $probe = new AtlasExternalBrainSimulationTwinPlanProbe;
        $input = [
            'batch' => [['packet_id' => 'p1', 'estimated_compounding_value' => 0.5]],
            'worker_capacity' => 3,
        ];

        $first = $probe->probe($input);
        $second = $probe->probe($input);

        $this->assertSame($first, $second);
        $this->assertSame(1, $first['simulation_summary']['batch_size']);
    }
}
