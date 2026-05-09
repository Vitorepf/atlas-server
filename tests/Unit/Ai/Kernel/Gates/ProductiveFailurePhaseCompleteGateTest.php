<?php

namespace Tests\Unit\Ai\Kernel\Gates;

use App\Services\Ai\Kernel\Gates\ProductiveFailurePhaseCompleteGate;
use Tests\TestCase;

class ProductiveFailurePhaseCompleteGateTest extends TestCase
{
    public function test_gate_blocks_missing_comparison_and_delta_before_completion(): void
    {
        $gate = app(ProductiveFailurePhaseCompleteGate::class);

        $this->assertSame('productive_failure_phase_2_comparison_missing', $gate->evaluate([], 'complete')['reason']);

        $this->assertSame('productive_failure_prediction_error_delta_missing', $gate->evaluate([
            'phase_2_comparison' => ['validated_reality' => 'canonical'],
        ], 'complete')['reason']);
    }

    public function test_gate_allows_articulation_then_completion_when_delta_exists(): void
    {
        $gate = app(ProductiveFailurePhaseCompleteGate::class);

        $readyForArticulation = $gate->evaluate([
            'phase_2_comparison' => ['prediction_error_delta' => 'missed lock contention'],
        ], 'complete');

        $this->assertSame('passed', $readyForArticulation['status']);
        $this->assertSame('productive_failure_phase_3_ready_for_articulation', $readyForArticulation['reason']);

        $complete = $gate->evaluate([
            'phase_2_comparison' => ['prediction_error_delta' => 'missed lock contention'],
            'phase_3_articulation' => [
                'model_update' => 'check contention before memory',
                'principle_extracted' => 'latency can be lock bound',
            ],
        ], 'complete');

        $this->assertSame('passed', $complete['status']);
    }
}
