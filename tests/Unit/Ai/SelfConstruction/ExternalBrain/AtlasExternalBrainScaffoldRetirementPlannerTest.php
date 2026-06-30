<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainScaffoldRetirementPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainScaffoldRetirementPlannerTest extends TestCase
{
    private AtlasExternalBrainScaffoldRetirementPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new AtlasExternalBrainScaffoldRetirementPlanner;
    }

    private function scaffold(string $id, array $overrides = []): array
    {
        return array_merge([
            'scaffold_id'               => $id,
            'lift_score'                => 0.70,
            'failure_recurrence_rate'   => 0.10,
            'overlap_score'             => 0.20,
            'recent_successful_outcomes' => 5,
            'replacement_candidate'     => null,
        ], $overrides);
    }

    private function plan(array ...$scaffolds): array
    {
        return $this->planner->plan(['scaffolds' => $scaffolds]);
    }

    private function findEntry(array $result, string $id): array
    {
        foreach ($result['plan'] as $entry) {
            if ($entry['scaffold_id'] === $id) {
                return $entry;
            }
        }
        $this->fail("Scaffold '{$id}' not found in plan.");
    }

    // ── Schema / required keys ────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->plan($this->scaffold('s1'));

        foreach (['schema', 'plan', 'retire_count', 'merge_count', 'keep_count'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::SCHEMA, $result['schema']);
    }

    public function test_each_plan_entry_has_required_keys(): void
    {
        $result = $this->plan($this->scaffold('s1'));

        foreach (['scaffold_id', 'action', 'reasons', 'replacement_candidate', 'keep_rationale'] as $k) {
            $this->assertArrayHasKey($k, $result['plan'][0]);
        }
    }

    // ── AC2: retire — low lift ────────────────────────────────────────────────

    public function test_low_lift_scaffold_is_retired(): void
    {
        $result = $this->plan($this->scaffold('low-lift', ['lift_score' => 0.10]));

        $entry = $this->findEntry($result, 'low-lift');
        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_RETIRE, $entry['action']);
        $this->assertStringContainsString('lift_score', implode(' ', $entry['reasons']));
    }

    // ── AC2: retire — high failure recurrence ─────────────────────────────────

    public function test_high_failure_recurrence_scaffold_is_retired(): void
    {
        $result = $this->plan($this->scaffold('fail-heavy', ['failure_recurrence_rate' => 0.65]));

        $entry = $this->findEntry($result, 'fail-heavy');
        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_RETIRE, $entry['action']);
        $this->assertStringContainsString('failure_recurrence_rate', implode(' ', $entry['reasons']));
    }

    // ── AC2: retire — high overlap with replacement ───────────────────────────

    public function test_high_overlap_with_replacement_retires_scaffold(): void
    {
        $result = $this->plan($this->scaffold('old-v1', [
            'overlap_score'         => 0.80, // > 0.70
            'replacement_candidate' => 'scaffold-v2',
        ]));

        $entry = $this->findEntry($result, 'old-v1');
        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_RETIRE, $entry['action']);
        $this->assertSame('scaffold-v2', $entry['replacement_candidate']);
    }

    // ── AC2: merge — moderate overlap with replacement ────────────────────────

    public function test_moderate_overlap_with_replacement_merges_scaffold(): void
    {
        $result = $this->plan($this->scaffold('partial', [
            'overlap_score'         => 0.55, // > 0.40, <= 0.70
            'replacement_candidate' => 'scaffold-v2',
        ]));

        $entry = $this->findEntry($result, 'partial');
        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_MERGE, $entry['action']);
        $this->assertSame('scaffold-v2', $entry['replacement_candidate']);
    }

    // ── AC3: keep — strong lift and low failures ──────────────────────────────

    public function test_strong_scaffold_is_kept(): void
    {
        $result = $this->plan($this->scaffold('great-scaffold', [
            'lift_score'                => 0.85,
            'failure_recurrence_rate'   => 0.05,
            'recent_successful_outcomes' => 10,
        ]));

        $entry = $this->findEntry($result, 'great-scaffold');
        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_KEEP, $entry['action']);
        $this->assertNotNull($entry['keep_rationale']);
        $this->assertNull($entry['replacement_candidate']);
    }

    // ── AC3: keep_rationale explains quality preservation ────────────────────

    public function test_keep_rationale_mentions_quality(): void
    {
        $result = $this->plan($this->scaffold('good', ['lift_score' => 0.75]));

        $entry = $this->findEntry($result, 'good');
        $this->assertStringContainsString('quality', $entry['keep_rationale']);
    }

    // ── Counts match actions ──────────────────────────────────────────────────

    public function test_action_counts_are_accurate(): void
    {
        $result = $this->plan(
            $this->scaffold('r1', ['lift_score' => 0.05]),                // retire
            $this->scaffold('r2', ['failure_recurrence_rate' => 0.90]),   // retire
            $this->scaffold('m1', ['overlap_score' => 0.55, 'replacement_candidate' => 'v2']), // merge
            $this->scaffold('k1'),                                         // keep
        );

        $this->assertSame(2, $result['retire_count']);
        $this->assertSame(1, $result['merge_count']);
        $this->assertSame(1, $result['keep_count']);
    }

    // ── Empty input ───────────────────────────────────────────────────────────

    public function test_empty_scaffolds_returns_zero_counts(): void
    {
        $result = $this->plan();

        $this->assertSame([], $result['plan']);
        $this->assertSame(0, $result['retire_count']);
        $this->assertSame(0, $result['merge_count']);
        $this->assertSame(0, $result['keep_count']);
    }

    // ── Retire takes priority over overlap ────────────────────────────────────

    public function test_low_lift_beats_overlap_merge_for_retire(): void
    {
        // low lift + moderate overlap → retire, not merge
        $result = $this->plan($this->scaffold('conflict', [
            'lift_score'            => 0.10,  // retire-worthy
            'overlap_score'         => 0.55,  // merge-worthy
            'replacement_candidate' => 'v2',
        ]));

        $entry = $this->findEntry($result, 'conflict');
        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_RETIRE, $entry['action']);
    }
}
