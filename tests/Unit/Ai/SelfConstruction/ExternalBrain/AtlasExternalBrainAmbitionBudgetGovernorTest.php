<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmbitionBudgetGovernor;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasExternalBrainAmbitionBudgetGovernor:
 *   - Falling yield reallocates budget to deeper modes before allowing honest_exhausted.
 *   - honest_exhausted requires all modes attempted WITH genuine evidence.
 *   - Quota consumption alone (without evidence) never satisfies the exhaustion gate.
 *   - Governor never rewards padding to fill quota (anti-Goodhart).
 */
final class AtlasExternalBrainAmbitionBudgetGovernorTest extends TestCase
{
    private AtlasExternalBrainAmbitionBudgetGovernor $gov;

    protected function setUp(): void
    {
        $this->gov = new AtlasExternalBrainAmbitionBudgetGovernor;
    }

    // ---------- fresh state ----------

    public function test_fresh_state_allocates_to_first_mode(): void
    {
        $r = $this->gov->allocate([]);
        $this->assertSame(AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT, $r['next_mode']);
        $this->assertFalse($r['honest_exhausted']);
        $this->assertFalse($r['reallocation_triggered']);
        $this->assertSame([], $r['reallocated_from']);
        $this->assertGreaterThan(0, $r['budget_slice']);
    }

    // ---------- reallocation on falling yield ----------

    public function test_falling_yield_on_easy_bug_hunt_triggers_reallocation(): void
    {
        $r = $this->gov->allocate([
            'quota_total'    => 100,
            'quota_consumed' => 40,
            'yield_by_mode'  => [AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT => 0.10],
        ]);

        $this->assertTrue($r['reallocation_triggered']);
        $this->assertContains(AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT, $r['reallocated_from']);
        $this->assertFalse($r['honest_exhausted']);
    }

    public function test_reallocation_increases_weight_of_deeper_mode(): void
    {
        // No reallocation baseline
        $baseline = $this->gov->allocate(['quota_total' => 100, 'quota_consumed' => 40]);

        // With falling yield on easy_bug_hunt
        $reallocated = $this->gov->allocate([
            'quota_total'    => 100,
            'quota_consumed' => 40,
            'yield_by_mode'  => [AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT => 0.10],
        ]);

        $this->assertGreaterThan(
            $baseline['mode_weights'][AtlasExternalBrainAmbitionBudgetGovernor::MODE_DEEP_ARCHITECTURE],
            $reallocated['mode_weights'][AtlasExternalBrainAmbitionBudgetGovernor::MODE_DEEP_ARCHITECTURE],
            'deep_architecture weight must grow when easy_bug_hunt yield falls',
        );
        $this->assertLessThan(
            $baseline['mode_weights'][AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT],
            $reallocated['mode_weights'][AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT],
            'easy_bug_hunt weight must shrink on reallocation',
        );
    }

    public function test_quota_run_with_falling_yield_reallocates_before_honest_exhausted(): void
    {
        // Simulate: quota almost consumed, all modes tried, but yield on shallow modes is low.
        // Acceptance-criteria scenario: falling yield → reallocation → NOT honest_exhausted (no evidence).
        $allModes = [
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT,
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_DEEP_ARCHITECTURE,
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_RESEARCH_ADAPTATION,
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_CONSOLIDATION,
        ];

        $r = $this->gov->allocate([
            'quota_total'     => 100,
            'quota_consumed'  => 90,          // quota nearly spent
            'attempted_modes' => $allModes,
            'yield_by_mode'   => [
                AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT      => 0.05,
                AtlasExternalBrainAmbitionBudgetGovernor::MODE_DEEP_ARCHITECTURE  => 0.15,
            ],
            // No evidence: quota was consumed by low-value tasks (padding scenario)
            'evidence_by_mode' => [],
        ]);

        // Must NOT be honest_exhausted — no genuine evidence means no earned exit
        $this->assertFalse($r['honest_exhausted'], 'quota exhaustion without evidence must not grant honest_exhausted');

        // Reallocation must have fired because yield was low on shallow modes
        $this->assertTrue($r['reallocation_triggered']);
    }

    // ---------- honest_exhausted gate ----------

    public function test_honest_exhausted_only_after_all_modes_have_evidence(): void
    {
        $all = [
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT,
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_DEEP_ARCHITECTURE,
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_RESEARCH_ADAPTATION,
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_CONSOLIDATION,
        ];
        $evidence = [];
        foreach ($all as $m) {
            $evidence[$m] = ['evidence:'.$m];
        }

        $r = $this->gov->allocate(['attempted_modes' => $all, 'evidence_by_mode' => $evidence]);
        $this->assertTrue($r['honest_exhausted']);
        $this->assertSame(AtlasExternalBrainAmbitionBudgetGovernor::MODE_HONEST_EXHAUSTED, $r['next_mode']);
        $this->assertSame(0, $r['budget_slice']);
    }

    public function test_all_attempted_without_evidence_is_not_exhausted(): void
    {
        $all = [
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT,
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_DEEP_ARCHITECTURE,
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_RESEARCH_ADAPTATION,
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_CONSOLIDATION,
        ];

        $r = $this->gov->allocate(['attempted_modes' => $all, 'evidence_by_mode' => []]);
        $this->assertFalse($r['honest_exhausted']);
        $this->assertNotSame(AtlasExternalBrainAmbitionBudgetGovernor::MODE_HONEST_EXHAUSTED, $r['next_mode']);
    }

    public function test_partial_evidence_is_not_exhausted(): void
    {
        $all = [
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT,
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_DEEP_ARCHITECTURE,
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_RESEARCH_ADAPTATION,
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_CONSOLIDATION,
        ];
        // Only 3 of 4 modes have evidence
        $evidence = [
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT      => ['ref:a'],
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_DEEP_ARCHITECTURE  => ['ref:b'],
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_RESEARCH_ADAPTATION => ['ref:c'],
            // consolidation has no evidence
        ];

        $r = $this->gov->allocate(['attempted_modes' => $all, 'evidence_by_mode' => $evidence]);
        $this->assertFalse($r['honest_exhausted']);
        // Should re-queue the mode missing evidence
        $this->assertSame(AtlasExternalBrainAmbitionBudgetGovernor::MODE_CONSOLIDATION, $r['next_mode']);
    }

    // ---------- anti-Goodhart: padding must not satisfy the governor ----------

    public function test_full_quota_consumption_without_evidence_does_not_grant_exhaustion(): void
    {
        // Quota is 100% consumed. All modes attempted. But evidence_by_mode is empty.
        // The governor must continue allocating rather than declare honest_exhausted.
        $all = [
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT,
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_DEEP_ARCHITECTURE,
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_RESEARCH_ADAPTATION,
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_CONSOLIDATION,
        ];

        $r = $this->gov->allocate([
            'quota_total'     => 100,
            'quota_consumed'  => 100,   // quota fully spent
            'attempted_modes' => $all,
            'evidence_by_mode' => [],   // zero genuine evidence — all padding
        ]);

        $this->assertFalse($r['honest_exhausted'], 'padding must not buy honest_exhausted');
        $this->assertNotSame(AtlasExternalBrainAmbitionBudgetGovernor::MODE_HONEST_EXHAUSTED, $r['next_mode']);
    }

    public function test_low_value_task_volume_without_evidence_never_earns_exit(): void
    {
        // Even with very high quota_consumed (padding scenario), no evidence = no exit.
        $r = $this->gov->allocate([
            'quota_total'    => 50,
            'quota_consumed' => 200,    // consumed 4× the total (massive over-run by low-value tasks)
            'evidence_by_mode' => [],
        ]);

        $this->assertFalse($r['honest_exhausted']);
    }

    // ---------- sequential mode progression ----------

    public function test_modes_are_allocated_in_ladder_order_without_yield_signal(): void
    {
        $attempted = [];
        $ladder = [
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT,
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_DEEP_ARCHITECTURE,
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_RESEARCH_ADAPTATION,
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_CONSOLIDATION,
        ];

        foreach ($ladder as $expected) {
            $r = $this->gov->allocate(['attempted_modes' => $attempted]);
            $this->assertSame($expected, $r['next_mode'], "expected $expected when attempted=".implode(',', $attempted));
            $this->assertFalse($r['honest_exhausted']);
            $attempted[] = $r['next_mode'];
        }
    }

    // ---------- schema + determinism ----------

    public function test_schema_is_correct(): void
    {
        $r = $this->gov->allocate([]);
        $this->assertSame(AtlasExternalBrainAmbitionBudgetGovernor::SCHEMA, $r['schema']);
    }

    public function test_same_state_produces_identical_output(): void
    {
        $state = [
            'quota_total'    => 100,
            'quota_consumed' => 30,
            'yield_by_mode'  => [AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT => 0.20],
            'attempted_modes' => [AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT],
            'evidence_by_mode' => [AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT => ['ref:x']],
        ];

        $this->assertSame(
            json_encode($this->gov->allocate($state), JSON_UNESCAPED_SLASHES),
            json_encode($this->gov->allocate($state), JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_budget_slice_is_positive_when_quota_remains(): void
    {
        $r = $this->gov->allocate(['quota_total' => 100, 'quota_consumed' => 10]);
        $this->assertGreaterThan(0, $r['budget_slice']);
        $this->assertGreaterThan(0, $r['quota_remaining']);
    }

    public function test_budget_slice_is_zero_when_no_quota_remains(): void
    {
        $r = $this->gov->allocate(['quota_total' => 100, 'quota_consumed' => 100]);
        $this->assertSame(0, $r['budget_slice']);
        $this->assertSame(0, $r['quota_remaining']);
    }
}
