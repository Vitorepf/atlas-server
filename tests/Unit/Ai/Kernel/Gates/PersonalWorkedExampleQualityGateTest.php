<?php

namespace Tests\Unit\Ai\Kernel\Gates;

use App\Services\Ai\Kernel\Gates\PersonalWorkedExampleQualityGate;
use Tests\TestCase;

class PersonalWorkedExampleQualityGateTest extends TestCase
{
    public function test_accepts_only_known_high_quality_sources(): void
    {
        $gate = new PersonalWorkedExampleQualityGate;

        $this->assertSame('blocked', $gate->evaluate(['source_type' => 'unknown'])['status']);
        $this->assertSame('passed', $gate->evaluate([
            'source_type' => 'feynman_session',
            'quality_signals' => ['feynman_score' => 8.2],
        ])['status']);
        $this->assertSame('passed', $gate->evaluate([
            'source_type' => 'strategic_decision',
            'quality_signals' => ['decision_outcome' => 'partial_success'],
        ])['status']);
    }
}
