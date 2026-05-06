<?php

namespace Tests\Unit;

use App\Services\Engineering\EngineeringClaudeCodeBaselineInput;
use Tests\TestCase;

class EngineeringClaudeCodeBaselineInputTest extends TestCase
{
    public function test_normalizes_claude_code_baseline_timeouts(): void
    {
        $input = new EngineeringClaudeCodeBaselineInput;

        $this->assertSame(EngineeringClaudeCodeBaselineInput::DEFAULT_TIMEOUT_SECONDS, $input->runTimeoutSeconds([]));
        $this->assertSame(EngineeringClaudeCodeBaselineInput::DEFAULT_VALIDATION_TIMEOUT_SECONDS, $input->validationTimeoutSeconds([]));
        $this->assertSame(1, $input->runTimeoutSeconds(['baseline_timeout_seconds' => -10]));
        $this->assertSame(1, $input->validationTimeoutSeconds(['baseline_validation_timeout_seconds' => -10]));
        $this->assertSame(EngineeringClaudeCodeBaselineInput::MAX_TIMEOUT_SECONDS, $input->runTimeoutSeconds(['claude_code_baseline_timeout' => 99999]));
        $this->assertSame(EngineeringClaudeCodeBaselineInput::MAX_VALIDATION_TIMEOUT_SECONDS, $input->validationTimeoutSeconds(['claude_code_baseline_validation_timeout' => 99999]));
        $this->assertSame(42, $input->runTimeoutSeconds(['claude_code_baseline_timeout' => '42']));
        $this->assertSame(EngineeringClaudeCodeBaselineInput::DEFAULT_TIMEOUT_SECONDS, $input->runTimeoutSeconds(['claude_code_baseline_timeout' => 'bad']));
    }
}
