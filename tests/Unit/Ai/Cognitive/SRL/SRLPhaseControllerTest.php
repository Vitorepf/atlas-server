<?php

namespace Tests\Unit\Ai\Cognitive\SRL;

use App\Services\Ai\Cognitive\SRL\SRLPhaseController;
use Tests\TestCase;

class SRLPhaseControllerTest extends TestCase
{
    public function test_phase_controller_blocks_skips_and_allows_next_phase(): void
    {
        $controller = app(SRLPhaseController::class);

        $empty = [];
        $this->assertSame('passed', $controller->evaluate($empty, 'forethought')['status']);
        $this->assertSame('blocked', $controller->evaluate($empty, 'self_reflection')['status']);

        $withForethought = ['forethought' => ['objective' => 'learn']];
        $this->assertSame('passed', $controller->evaluate($withForethought, 'performance')['status']);
        $this->assertSame('blocked', $controller->evaluate($withForethought, 'forethought')['status']);
    }
}
