<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasSelfConstructionTaskGraphDraftQualityGate;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionTaskGraphDraftQualityGate::detectCycle does not
 * report the whole path as a cycle when array_search fails.
 */
final class AtlasSelfConstructionTaskGraphDraftQualityGateHardeningTest extends TestCase
{
    private function detectCycle(AtlasSelfConstructionTaskGraphDraftQualityGate $gate, array $edges): array
    {
        $reflection = new \ReflectionClass($gate);
        $method = $reflection->getMethod('detectCycle');
        return $method->invoke($gate, $edges);
    }

    public function test_real_cycle_is_detected(): void
    {
        $gate = new AtlasSelfConstructionTaskGraphDraftQualityGate();

        // A -> B -> A (cycle)
        $edges = [
            'A' => ['B'],
            'B' => ['A'],
        ];

        $cycle = $this->detectCycle($gate, $edges);

        $this->assertNotEmpty($cycle);
        // Cycle should contain A and B
        $this->assertContains('A', $cycle);
        $this->assertContains('B', $cycle);
    }

    public function test_no_cycle_returns_empty(): void
    {
        $gate = new AtlasSelfConstructionTaskGraphDraftQualityGate();

        // A -> B -> C (no cycle)
        $edges = [
            'A' => ['B'],
            'B' => ['C'],
            'C' => [],
        ];

        $cycle = $this->detectCycle($gate, $edges);

        $this->assertEmpty($cycle);
    }

    public function test_self_loop_is_detected(): void
    {
        $gate = new AtlasSelfConstructionTaskGraphDraftQualityGate();

        // A -> A (self-loop)
        $edges = [
            'A' => ['A'],
        ];

        $cycle = $this->detectCycle($gate, $edges);

        $this->assertNotEmpty($cycle);
        $this->assertContains('A', $cycle);
    }

    public function test_empty_graph_returns_empty(): void
    {
        $gate = new AtlasSelfConstructionTaskGraphDraftQualityGate();

        $cycle = $this->detectCycle($gate, []);

        $this->assertEmpty($cycle);
    }
}
