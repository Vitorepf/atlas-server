<?php

namespace Tests\Unit;

use App\Services\Engineering\EngineeringHarnessabilityInput;
use Tests\TestCase;

class EngineeringHarnessabilityInputTest extends TestCase
{
    public function test_normalizes_engineering_harnessability_calibration_limit(): void
    {
        $input = new EngineeringHarnessabilityInput;

        $this->assertSame(EngineeringHarnessabilityInput::DEFAULT_CALIBRATION_LIMIT, $input->calibrationLimit(null));
        $this->assertSame(1, $input->calibrationLimit(-10));
        $this->assertSame(42, $input->calibrationLimit('42'));
        $this->assertSame(EngineeringHarnessabilityInput::MAX_CALIBRATION_LIMIT, $input->calibrationLimit(99999));
        $this->assertSame(EngineeringHarnessabilityInput::DEFAULT_CALIBRATION_LIMIT, $input->calibrationLimit('bad'));
    }
}
