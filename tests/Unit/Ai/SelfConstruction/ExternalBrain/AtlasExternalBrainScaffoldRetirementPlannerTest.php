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
            'behavior_parity'           => true,
            'replacement_coverage'      => true,
            'rollback_path'             => true,
            'knowledge_sync_plan'       => true,
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

    // ── Downgrade ──────────────────────────────────────────────────────────────

    public function test_costly_stale_moderate_lift_scaffold_is_downgraded(): void
    {
        $result = $this->plan($this->scaffold('costly-stale', [
            'lift_score' => 0.30,
            'maintenance_cost' => 0.80,
            'stale_usage_days' => 45,
        ]));

        $entry = $this->findEntry($result, 'costly-stale');
        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_DOWNGRADE, $entry['action']);
        $this->assertSame(1, $result['downgrade_count']);
    }

    public function test_moderate_lift_low_maintenance_cost_is_not_downgraded(): void
    {
        $result = $this->plan($this->scaffold('cheap-moderate', [
            'lift_score' => 0.30,
            'maintenance_cost' => 0.10,
            'stale_usage_days' => 45,
        ]));

        $entry = $this->findEntry($result, 'cheap-moderate');
        $this->assertNotSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_DOWNGRADE, $entry['action']);
    }

    // ── Refuse retirement without lift evidence ──────────────────────────────

    public function test_missing_lift_evidence_refuses_retirement_even_with_zero_lift(): void
    {
        $result = $this->plan($this->scaffold('no-evidence', [
            'lift_score' => 0.0,
            'has_lift_evidence' => false,
        ]));

        $entry = $this->findEntry($result, 'no-evidence');
        $this->assertNotSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_RETIRE, $entry['action']);
        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_KEEP, $entry['action']);
        $this->assertStringContainsString('lift_evidence_missing', implode(' ', $entry['reasons']));
    }

    public function test_high_failure_recurrence_still_retires_without_lift_evidence(): void
    {
        // Failure-recurrence retirement is independent of lift evidence.
        $result = $this->plan($this->scaffold('failing-no-evidence', [
            'has_lift_evidence' => false,
            'failure_recurrence_rate' => 0.90,
        ]));

        $entry = $this->findEntry($result, 'failing-no-evidence');
        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_RETIRE, $entry['action']);
    }

    // ── Expected complexity reduction and capability risk ────────────────────

    public function test_each_entry_reports_expected_complexity_reduction_and_capability_risk(): void
    {
        $result = $this->plan($this->scaffold('s1'));

        $entry = $this->findEntry($result, 's1');
        $this->assertArrayHasKey('expected_complexity_reduction', $entry);
        $this->assertArrayHasKey('capability_risk', $entry);
    }

    // ── quality_floor_preserved + rollback_condition ──────────────────────────

    public function test_keep_action_always_preserves_quality_floor(): void
    {
        $result = $this->plan($this->scaffold('safe'));

        $entry = $this->findEntry($result, 'safe');
        $this->assertTrue($entry['quality_floor_preserved']);
    }

    public function test_retire_includes_rollback_condition_when_replacement_present(): void
    {
        $result = $this->plan($this->scaffold('old-v1', [
            'overlap_score'         => 0.80,
            'replacement_candidate' => 'scaffold-v2',
        ]));

        $entry = $this->findEntry($result, 'old-v1');
        $this->assertNotNull($entry['rollback_condition']);
        $this->assertStringContainsString('scaffold-v2', $entry['rollback_condition']);
    }

    public function test_retire_uses_explicit_rollback_condition_when_supplied(): void
    {
        $result = $this->plan($this->scaffold('old-v2', [
            'overlap_score'         => 0.80,
            'replacement_candidate' => 'scaffold-v3',
            'rollback_condition'    => 'revert within 7 days if lift drops below 0.5',
        ]));

        $entry = $this->findEntry($result, 'old-v2');
        $this->assertSame('revert within 7 days if lift drops below 0.5', $entry['rollback_condition']);
    }

    public function test_high_overlap_without_replacement_does_not_retire(): void
    {
        $result = $this->plan($this->scaffold('no-replacement', [
            'overlap_score'         => 0.90,
            'replacement_candidate' => null,
        ]));

        $entry = $this->findEntry($result, 'no-replacement');
        $this->assertNotSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_RETIRE, $entry['action']);
        $this->assertNull($entry['rollback_condition']);
    }

    public function test_low_lift_retire_reports_quality_floor_preserved_based_on_capability_risk(): void
    {
        $result = $this->plan($this->scaffold('r1', ['lift_score' => 0.05, 'failure_recurrence_rate' => 0.10]));

        $entry = $this->findEntry($result, 'r1');
        $this->assertArrayHasKey('quality_floor_preserved', $entry);
        $this->assertIsBool($entry['quality_floor_preserved']);
    }

    public function test_retire_action_reports_higher_complexity_reduction_than_keep(): void
    {
        $retired = $this->plan($this->scaffold('r1', ['lift_score' => 0.05, 'maintenance_cost' => 0.50]));
        $kept = $this->plan($this->scaffold('k1', ['maintenance_cost' => 0.50]));

        $retiredEntry = $this->findEntry($retired, 'r1');
        $keptEntry = $this->findEntry($kept, 'k1');

        $this->assertGreaterThan($keptEntry['expected_complexity_reduction'], $retiredEntry['expected_complexity_reduction']);
    }

    // ── AC: retirement blocked when no fallback covers required sections ───────

    public function test_retirement_blocked_when_replacement_does_not_cover_required_sections(): void
    {
        $result = $this->plan($this->scaffold('harmful', [
            'overlap_score'                 => 0.80,
            'replacement_candidate'         => 'scaffold-v2',
            'required_sections'             => ['guardrails', 'proof_obligations'],
            'replacement_covered_sections'  => ['guardrails'], // missing proof_obligations
        ]));

        $entry = $this->findEntry($result, 'harmful');
        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_KEEP, $entry['action']);
        $this->assertStringContainsString('proof_obligations', implode(' ', $entry['reasons']));
    }

    public function test_retirement_proceeds_when_replacement_covers_all_required_sections(): void
    {
        $result = $this->plan($this->scaffold('safe-to-retire', [
            'overlap_score'                 => 0.80,
            'replacement_candidate'         => 'scaffold-v2',
            'required_sections'             => ['guardrails', 'proof_obligations'],
            'replacement_covered_sections'  => ['guardrails', 'proof_obligations'],
        ]));

        $entry = $this->findEntry($result, 'safe-to-retire');
        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_RETIRE, $entry['action']);
        $this->assertSame('redirect_to_replacement_fully_covered', $entry['worker_impact']);
    }

    public function test_no_required_sections_declared_preserves_legacy_retire_behavior(): void
    {
        $result = $this->plan($this->scaffold('legacy', [
            'overlap_score'         => 0.80,
            'replacement_candidate' => 'scaffold-v2',
        ]));

        $entry = $this->findEntry($result, 'legacy');
        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_RETIRE, $entry['action']);
    }

    // ── AC: retirement plans include migration_notes and worker_impact fields ──

    public function test_every_entry_has_migration_notes_and_worker_impact_keys(): void
    {
        $result = $this->plan($this->scaffold('s1'));

        $entry = $this->findEntry($result, 's1');
        $this->assertArrayHasKey('migration_notes', $entry);
        $this->assertArrayHasKey('worker_impact', $entry);
        $this->assertNotEmpty($entry['migration_notes']);
    }

    public function test_keep_action_has_none_worker_impact(): void
    {
        $result = $this->plan($this->scaffold('s1'));

        $entry = $this->findEntry($result, 's1');
        $this->assertSame('none', $entry['worker_impact']);
    }

    public function test_retire_without_replacement_reports_requires_manual_review(): void
    {
        $result = $this->plan($this->scaffold('no-fallback', ['lift_score' => 0.05]));

        $entry = $this->findEntry($result, 'no-fallback');
        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_RETIRE, $entry['action']);
        $this->assertSame('requires_manual_review_no_replacement', $entry['worker_impact']);
    }

    // ── AC2: retirement_ready requires all four proofs ───────────────────────

    public function test_retirement_ready_true_when_all_four_proofs_present(): void
    {
        $result = $this->plan($this->scaffold('safe-retire', [
            'lift_score'         => 0.05,
            'behavior_parity'    => true,
            'replacement_coverage' => true,
            'rollback_path'      => true,
            'knowledge_sync_plan' => true,
        ]));

        $entry = $this->findEntry($result, 'safe-retire');
        $this->assertTrue($entry['retirement_ready']);
        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_RETIRE, $entry['action']);
    }

    public function test_retirement_ready_false_when_one_proof_missing(): void
    {
        $result = $this->plan($this->scaffold('partial-proof', [
            'lift_score'         => 0.05,
            'behavior_parity'    => true,
            'replacement_coverage' => true,
            'rollback_path'      => true,
            'knowledge_sync_plan' => false,  // missing knowledge sync
        ]));

        $entry = $this->findEntry($result, 'partial-proof');
        $this->assertFalse($entry['retirement_ready']);
    }

    // ── AC3: retirement blocked when safety proofs missing ───────────────────

    public function test_retirement_blocked_when_behavior_parity_missing(): void
    {
        $result = $this->plan($this->scaffold('no-behavior-parity', [
            'lift_score'         => 0.05,
            'behavior_parity'    => false,
        ]));

        $entry = $this->findEntry($result, 'no-behavior-parity');
        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_KEEP, $entry['action']);
        $this->assertStringContainsString('behavior_parity', implode(' ', $entry['reasons']));
    }

    public function test_retirement_blocked_when_all_four_proofs_missing(): void
    {
        $result = $this->plan($this->scaffold('no-proofs-at-all', [
            'lift_score'         => 0.05,
            'behavior_parity'    => false,
            'replacement_coverage' => false,
            'rollback_path'      => false,
            'knowledge_sync_plan' => false,
        ]));

        $entry = $this->findEntry($result, 'no-proofs-at-all');
        $this->assertSame(AtlasExternalBrainScaffoldRetirementPlanner::ACTION_KEEP, $entry['action']);
        $this->assertStringContainsString('behavior_parity', implode(' ', $entry['reasons']));
        $this->assertStringContainsString('replacement_coverage', implode(' ', $entry['reasons']));
        $this->assertStringContainsString('rollback_path', implode(' ', $entry['reasons']));
        $this->assertStringContainsString('knowledge_sync_plan', implode(' ', $entry['reasons']));
    }

    // ── AC4: new output fields ────────────────────────────────────────────────

    public function test_every_entry_has_retirement_ready_plan_preserved_capabilities_and_rollback_steps(): void
    {
        $result = $this->plan(
            $this->scaffold('retire-me', ['lift_score' => 0.05, 'replacement_candidate' => 'v2']),
            $this->scaffold('keep-me'),
        );

        foreach ($result['plan'] as $entry) {
            $this->assertArrayHasKey('retirement_ready', $entry);
            $this->assertArrayHasKey('retirement_plan', $entry);
            $this->assertArrayHasKey('preserved_capabilities', $entry);
            $this->assertArrayHasKey('rollback_steps', $entry);
        }
    }

    public function test_retirement_plan_null_when_keeping(): void
    {
        $result = $this->plan($this->scaffold('safe'));

        $entry = $this->findEntry($result, 'safe');
        $this->assertNull($entry['retirement_plan']);
    }

    public function test_retirement_plan_not_null_when_retiring(): void
    {
        $result = $this->plan($this->scaffold('old', [
            'lift_score'         => 0.05,
            'replacement_candidate' => 'v2',
        ]));

        $entry = $this->findEntry($result, 'old');
        $this->assertNotNull($entry['retirement_plan']);
        $this->assertStringContainsString('retire', $entry['retirement_plan']);
    }

    public function test_preserved_capabilities_includes_replacement_when_present(): void
    {
        $result = $this->plan($this->scaffold('old', [
            'lift_score'            => 0.05,
            'replacement_candidate' => 'v2',
        ]));

        $entry = $this->findEntry($result, 'old');
        $this->assertContains('v2', $entry['preserved_capabilities']);
    }

    public function test_rollback_steps_not_empty_when_retiring(): void
    {
        $result = $this->plan($this->scaffold('old', [
            'lift_score'         => 0.05,
            'replacement_candidate' => 'v2',
        ]));

        $entry = $this->findEntry($result, 'old');
        $this->assertNotEmpty($entry['rollback_steps']);
        $this->assertStringContainsString('v2', implode(' ', $entry['rollback_steps']));
    }

    // ── Determinism: output_hash for stability ───────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = ['scaffolds' => [
            $this->scaffold('s1', ['lift_score' => 0.05, 'replacement_candidate' => 'v2']),
            $this->scaffold('s2'),
        ]];

        $this->assertSame(
            json_encode($this->planner->plan($input)),
            json_encode($this->planner->plan($input)),
        );
    }
}
