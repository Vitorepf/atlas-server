<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMaturityGapBatchPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainMaturityGapBatchPlannerTest extends TestCase
{
    private AtlasExternalBrainMaturityGapBatchPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new AtlasExternalBrainMaturityGapBatchPlanner();
    }

    // AC 2: high-severity gaps become ordered task waves with prerequisite notes
    public function test_high_severity_gaps_become_ordered_waves(): void
    {
        $result = $this->planner->plan([
            [
                'gap_id' => 'gap-A',
                'severity' => 'critical',
                'impact_score' => 0.9,
                'prerequisite_gap_ids' => [],
                'proof_ready' => true,
                'group' => 'autonomy',
                'allowed_scope_hint' => 'app/Autonomy/',
                'expected_maturity_delta' => '+0.2',
            ],
            [
                'gap_id' => 'gap-B',
                'severity' => 'high',
                'impact_score' => 0.7,
                'prerequisite_gap_ids' => ['gap-A'],
                'proof_ready' => true,
                'group' => 'queue_quality',
                'allowed_scope_hint' => 'app/Queue/',
                'expected_maturity_delta' => '+0.1',
            ],
        ]);

        $this->assertCount(2, $result['waves']);
        // Wave 1: gap-A (no prerequisites)
        $this->assertSame('gap-A', $result['waves'][0]['tasks'][0]['task_id']);
        // Wave 2: gap-B (depends on gap-A)
        $this->assertSame('gap-B', $result['waves'][1]['tasks'][0]['task_id']);
        $this->assertNotEmpty($result['waves'][1]['tasks'][0]['prerequisite_notes']);
    }

    // AC 3: low-impact gaps are held
    public function test_low_impact_gaps_held(): void
    {
        $result = $this->planner->plan([
            [
                'gap_id' => 'gap-low',
                'severity' => 'low',
                'impact_score' => 0.1,
                'proof_ready' => true,
            ],
            [
                'gap_id' => 'gap-high',
                'severity' => 'high',
                'impact_score' => 0.8,
                'proof_ready' => true,
            ],
        ]);

        $this->assertContains('gap-low', $result['held']);
        $this->assertNotContains('gap-high', $result['held']);
    }

    // AC 4: each planned task includes proof target, scope hint, maturity delta
    public function test_planned_tasks_have_proof_scope_delta(): void
    {
        $result = $this->planner->plan([
            [
                'gap_id' => 'gap-X',
                'severity' => 'high',
                'impact_score' => 0.9,
                'proof_ready' => true,
                'group' => 'simplification',
                'allowed_scope_hint' => 'app/Simplify/',
                'expected_maturity_delta' => '+0.15',
            ],
        ]);

        $task = $result['waves'][0]['tasks'][0];
        $this->assertSame('simplification_test', $task['proof_target']);
        $this->assertSame('app/Simplify/', $task['allowed_scope_hint']);
        $this->assertSame('+0.15', $task['expected_maturity_delta']);
    }

    public function test_empty_gaps_produce_empty_waves(): void
    {
        $result = $this->planner->plan([]);
        $this->assertEmpty($result['waves']);
        $this->assertEmpty($result['held']);
    }

    public function test_proof_ready_tasks_prioritized_first(): void
    {
        $result = $this->planner->plan([
            [
                'gap_id' => 'not-ready',
                'impact_score' => 0.9,
                'proof_ready' => false,
                'group' => 'x',
            ],
            [
                'gap_id' => 'ready',
                'impact_score' => 0.9,
                'proof_ready' => true,
                'group' => 'y',
            ],
        ]);

        $firstTask = $result['waves'][0]['tasks'][0];
        $this->assertSame('ready', $firstTask['task_id']);
    }
}
