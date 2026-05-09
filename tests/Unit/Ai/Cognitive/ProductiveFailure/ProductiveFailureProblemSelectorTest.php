<?php

namespace Tests\Unit\Ai\Cognitive\ProductiveFailure;

use App\Services\Ai\Cognitive\ProductiveFailure\ProductiveFailureProblemSelector;
use Tests\TestCase;

class ProductiveFailureProblemSelectorTest extends TestCase
{
    public function test_problem_selector_returns_calibrated_problem_with_stable_node_id(): void
    {
        $selector = app(ProductiveFailureProblemSelector::class);

        $problem = $selector->select('queue batching', 'programming', 3);
        $again = $selector->select('queue batching', 'programming', 3);

        $this->assertSame('selected', $problem['status']);
        $this->assertSame($problem['knowledge_node_id'], $again['knowledge_node_id']);
        $this->assertSame(4, $problem['expected_difficulty']);
        $this->assertStringContainsString('queue batching', $problem['prediction_prompt']);
        $this->assertSame([3, 5], $problem['calibration']['productive_failure_band']);
    }
}
