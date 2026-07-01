<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainScaffoldRetirementPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainScaffoldRetirementPlannerTest extends TestCase
{
    private function planner(): AtlasExternalBrainScaffoldRetirementPlanner
    {
        return new AtlasExternalBrainScaffoldRetirementPlanner;
    }

    private function planOne(array $scaffold): array
    {
        $result = $this->planner()->plan(['scaffolds' => [$scaffold]]);

        return $result['plan'][0];
    }

    // ── AC2: low lift with evidence, or high failure recurrence, retires ─────

    public function test_low_lift_with_evidence_retires_and_reports_reduction_and_risk(): void
    {
        $entry = $this->planOne([
            'scaffold_id' => 's1',
            'lift_score' => 0.05,
            'has_lift_evidence' => true,
            'maintenance_cost' => 0.8,
            'failure_recurrence_rate' => 0.1,
        ]);

        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_RETIRE, $entry['action']);
        $this->assertGreaterThan(0.0, $entry['expected_complexity_reduction']);
        $this->assertArrayHasKey('capability_risk', $entry);
    }

    public function test_high_failure_recurrence_retires(): void
    {
        $entry = $this->planOne([
            'scaffold_id' => 's2',
            'lift_score' => 0.9,
            'failure_recurrence_rate' => 0.6,
        ]);

        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_RETIRE, $entry['action']);
    }

    // ── AC3: high overlap+replacement retires, moderate overlap merges, stale costly low-lift downgrades ──

    public function test_high_overlap_with_replacement_retires(): void
    {
        $entry = $this->planOne([
            'scaffold_id' => 's3',
            'lift_score' => 0.9,
            'overlap_score' => 0.8,
            'replacement_candidate' => 'NewScaffold',
        ]);

        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_RETIRE, $entry['action']);
        $this->assertSame('NewScaffold', $entry['replacement_candidate']);
    }

    public function test_moderate_overlap_with_replacement_merges(): void
    {
        $entry = $this->planOne([
            'scaffold_id' => 's4',
            'lift_score' => 0.9,
            'overlap_score' => 0.5,
            'replacement_candidate' => 'NewScaffold',
        ]);

        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_MERGE, $entry['action']);
    }

    public function test_stale_costly_low_lift_downgrades(): void
    {
        $entry = $this->planOne([
            'scaffold_id' => 's5',
            'lift_score' => 0.3,
            'maintenance_cost' => 0.7,
            'stale_usage_days' => 45,
        ]);

        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_DOWNGRADE, $entry['action']);
    }

    // ── AC4: missing lift evidence refuses retirement, keeps with keep_rationale ──

    public function test_missing_lift_evidence_refuses_retirement_and_keeps(): void
    {
        $entry = $this->planOne([
            'scaffold_id' => 's6',
            'lift_score' => 0.02,
            'has_lift_evidence' => false,
        ]);

        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_KEEP, $entry['action']);
        $this->assertNotNull($entry['keep_rationale']);
        $this->assertStringContainsString('lift_evidence_missing', $entry['keep_rationale']);
    }

    public function test_plan_counts_actions_across_multiple_scaffolds(): void
    {
        $result = $this->planner()->plan(['scaffolds' => [
            ['scaffold_id' => 'a', 'lift_score' => 0.05, 'has_lift_evidence' => true],
            ['scaffold_id' => 'b', 'lift_score' => 0.9, 'overlap_score' => 0.5, 'replacement_candidate' => 'X'],
            ['scaffold_id' => 'c', 'lift_score' => 0.9],
        ]]);

        $this->assertSame(1, $result['retire_count']);
        $this->assertSame(1, $result['merge_count']);
        $this->assertSame(1, $result['keep_count']);
    }
}
