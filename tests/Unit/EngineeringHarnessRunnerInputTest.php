<?php

namespace Tests\Unit;

use App\Services\Engineering\EngineeringHarnessRunnerInput;
use Tests\TestCase;

class EngineeringHarnessRunnerInputTest extends TestCase
{
    public function test_normalizes_engineering_harness_runner_attempt_limits(): void
    {
        $input = new EngineeringHarnessRunnerInput;

        $this->assertSame(EngineeringHarnessRunnerInput::DEFAULT_MAX_ATTEMPTS, $input->maxAttempts(null));
        $this->assertSame(1, $input->maxAttempts(-10));
        $this->assertSame(3, $input->maxAttempts('3'));
        $this->assertSame(EngineeringHarnessRunnerInput::MAX_MAX_ATTEMPTS, $input->maxAttempts(999));
        $this->assertSame(EngineeringHarnessRunnerInput::DEFAULT_MAX_ATTEMPTS, $input->maxAttempts('bad'));
    }
}
