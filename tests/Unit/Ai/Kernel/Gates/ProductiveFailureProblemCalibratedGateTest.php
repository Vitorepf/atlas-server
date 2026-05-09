<?php

namespace Tests\Unit\Ai\Kernel\Gates;

use App\Services\Ai\Kernel\Gates\ProductiveFailureProblemCalibratedGate;
use Tests\TestCase;

class ProductiveFailureProblemCalibratedGateTest extends TestCase
{
    public function test_gate_passes_problem_in_band_and_blocks_problem_outside_band(): void
    {
        $gate = app(ProductiveFailureProblemCalibratedGate::class);

        $this->assertSame('passed', $gate->evaluate([
            'domain' => 'programming',
            'prediction_prompt' => 'Diagnose queue latency.',
            'expected_difficulty' => 4,
        ], 3)['status']);

        $blocked = $gate->evaluate([
            'domain' => 'programming',
            'prediction_prompt' => 'Diagnose queue latency.',
            'expected_difficulty' => 1,
        ], 3);

        $this->assertSame('blocked', $blocked['status']);
        $this->assertSame('productive_failure_problem_outside_calibration_band', $blocked['reason']);
    }
}
