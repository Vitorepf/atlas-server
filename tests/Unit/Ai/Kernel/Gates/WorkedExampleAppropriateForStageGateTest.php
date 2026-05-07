<?php

namespace Tests\Unit\Ai\Kernel\Gates;

use App\Services\Ai\Kernel\Gates\WorkedExampleAppropriateForStageGate;
use Tests\TestCase;

class WorkedExampleAppropriateForStageGateTest extends TestCase
{
    public function test_gate_passes_valid_fading_level_and_blocks_invalid(): void
    {
        $gate = app(WorkedExampleAppropriateForStageGate::class);

        $this->assertSame('passed', $gate->evaluate([
            'dreyfus_stage' => 2,
            'fading_level_resolved' => 2,
        ])['status']);

        $this->assertSame('worked_example_invalid_fading_level', $gate->evaluate([
            'dreyfus_stage' => 2,
            'fading_level_resolved' => 9,
        ])['reason']);
    }

    public function test_gate_blocks_over_scaffolding_master_stage(): void
    {
        $result = app(WorkedExampleAppropriateForStageGate::class)->evaluate([
            'dreyfus_stage' => 5,
            'fading_level_resolved' => 2,
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('worked_example_unnecessary_for_master_stage', $result['reason']);
    }
}
