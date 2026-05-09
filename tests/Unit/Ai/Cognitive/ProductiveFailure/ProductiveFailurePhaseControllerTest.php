<?php

namespace Tests\Unit\Ai\Cognitive\ProductiveFailure;

use App\Services\Ai\Cognitive\ProductiveFailure\ProductiveFailurePhaseController;
use Tests\TestCase;

class ProductiveFailurePhaseControllerTest extends TestCase
{
    public function test_phase_controller_blocks_skips_and_allows_next_phase(): void
    {
        $controller = app(ProductiveFailurePhaseController::class);

        $empty = [];
        $this->assertSame('phase_1', $controller->currentPhase($empty));
        $this->assertSame('passed', $controller->evaluate($empty, 'attempt')['status']);
        $this->assertSame('blocked', $controller->evaluate($empty, 'phase_3')['status']);

        $withAttempt = ['phase_1_attempt' => ['operator_prediction' => 'redis']];
        $this->assertSame('phase_2', $controller->currentPhase($withAttempt));
        $this->assertSame('passed', $controller->evaluate($withAttempt, 'compare')['status']);
        $this->assertSame('blocked', $controller->evaluate($withAttempt, 'attempt')['status']);
    }
}
