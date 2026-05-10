<?php

namespace Tests\Unit\Ai\Kernel\Gates;

use App\Services\Ai\Kernel\Gates\PredictiveFailureCalibrationBandGate;
use App\Services\Ai\Kernel\Gates\PredictiveFailureSafetyGate;
use Tests\TestCase;

class PredictiveFailureGateTest extends TestCase
{
    public function test_calibration_gate_blocks_outside_sweet_band_and_passes_sweet_band(): void
    {
        $gate = app(PredictiveFailureCalibrationBandGate::class);

        $this->assertSame('blocked', $gate->evaluate(['domain' => 'programming', 'predicted_failure_probability' => 0.60])['status']);
        $this->assertSame('passed', $gate->evaluate(['domain' => 'programming', 'predicted_failure_probability' => 0.78])['status']);
        $this->assertSame('blocked', $gate->evaluate(['domain' => 'programming', 'predicted_failure_probability' => 0.91])['status']);
    }

    public function test_safety_gate_blocks_high_load_and_sensitive_privacy(): void
    {
        $gate = app(PredictiveFailureSafetyGate::class);

        $this->assertSame('blocked', $gate->evaluate(['domain' => 'programming'], ['level' => 'high'])['status']);
        $this->assertSame('blocked', $gate->evaluate(['domain' => 'programming', 'problem_payload' => ['privacy_class' => 'p3']])['status']);
        $this->assertSame('blocked', $gate->evaluate(['domain' => 'programming', 'problem_payload' => ['privacy_class' => 3]])['status']);
        $this->assertSame('blocked', $gate->evaluate(['domain' => 'programming', 'problem_payload' => ['privacy_class' => 'class_4']])['status']);
        $this->assertSame('passed', $gate->evaluate(['domain' => 'programming', 'problem_payload' => ['privacy_class' => 'p1']], ['level' => 'normal'])['status']);
    }
}
