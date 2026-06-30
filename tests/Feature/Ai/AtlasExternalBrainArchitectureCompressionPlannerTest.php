<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainArchitectureCompressionPlanner;
use Tests\TestCase;

/**
 * Proves the hardened AC:
 *   AC1 — groups overlapping organs by purpose, inputs and outputs (not just class names)
 *   AC2 — merge/retire candidates include risk_level, expected_line_reduction,
 *           preserved_contracts, required_tests
 *   AC3 — high-risk or behavior-unique organs are NOT marked retire_now
 *   AC4 — plan_hash is stable for the same inventory
 */
final class AtlasExternalBrainArchitectureCompressionPlannerTest extends TestCase
{
    private function planner(): AtlasExternalBrainArchitectureCompressionPlanner
    {
        return new AtlasExternalBrainArchitectureCompressionPlanner();
    }

    // ── AC1: semantic grouping by purpose_tag ─────────────────────────────────

    public function test_groups_organs_by_shared_purpose_tag_not_by_capability_label(): void
    {
        // Two organs with DIFFERENT capability_labels but the SAME purpose_tag.
        // Old code (label-only) would NOT merge them; new code must.
        $result = $this->planner()->plan([
            'organs' => [
                [
                    'id'                => 'organ-cost-v1',
                    'capability_labels' => ['cost_gate_v1'],      // unique label
                    'purpose_tag'       => 'backlog_cost_estimation',
                    'input_types'       => ['BacklogSnapshot'],
                    'output_types'      => ['CostEstimate'],
                    'line_count'        => 90,
                ],
                [
                    'id'                => 'organ-cost-v2',
                    'capability_labels' => ['budget_gate_v2'],    // different unique label
                    'purpose_tag'       => 'backlog_cost_estimation',  // SAME purpose_tag
                    'input_types'       => ['WorkQueue'],
                    'output_types'      => ['BudgetReport'],
                    'line_count'        => 75,
                ],
            ],
        ]);

        $merges = array_values(array_filter($result['candidates'], fn ($c) => $c['action'] === 'merge'));
        $this->assertNotEmpty($merges, 'Shared purpose_tag must produce a merge candidate');

        $mergedIds = $merges[0]['organ_ids'];
        $this->assertContains('organ-cost-v1', $mergedIds);
        $this->assertContains('organ-cost-v2', $mergedIds);
    }

    public function test_purpose_tag_merge_has_group_type_purpose_tag(): void
    {
        $result = $this->planner()->plan([
            'organs' => [
                ['id' => 'a', 'capability_labels' => ['unique_a'], 'purpose_tag' => 'scorer', 'line_count' => 50],
                ['id' => 'b', 'capability_labels' => ['unique_b'], 'purpose_tag' => 'scorer', 'line_count' => 40],
            ],
        ]);

        $merges = array_values(array_filter($result['candidates'], fn ($c) => $c['action'] === 'merge'));
        $this->assertNotEmpty($merges);
        $this->assertSame('purpose_tag', $merges[0]['group_type']);
        $this->assertSame('scorer',      $merges[0]['group_label']);
    }

    public function test_different_purpose_tags_do_not_produce_semantic_merge(): void
    {
        $result = $this->planner()->plan([
            'organs' => [
                ['id' => 'a', 'capability_labels' => ['unique_a'], 'purpose_tag' => 'cost_model',  'line_count' => 50],
                ['id' => 'b', 'capability_labels' => ['unique_b'], 'purpose_tag' => 'value_gate', 'line_count' => 40],
            ],
        ]);

        $merges = array_filter($result['candidates'], fn ($c) => $c['action'] === 'merge');
        $this->assertEmpty($merges, 'Different purpose_tags must not merge');
    }

    // ── AC1: semantic grouping by I/O type overlap ────────────────────────────

    public function test_groups_organs_by_overlapping_input_and_output_types(): void
    {
        // Two organs with NO shared capability_labels or purpose_tag but overlapping I/O types.
        $result = $this->planner()->plan([
            'organs' => [
                [
                    'id'                => 'reporter',
                    'capability_labels' => ['report_gen'],
                    'input_types'       => ['TaskQueue', 'Inventory'],
                    'output_types'      => ['Report', 'Summary'],
                    'line_count'        => 120,
                ],
                [
                    'id'                => 'analyzer',
                    'capability_labels' => ['queue_analysis'],
                    'input_types'       => ['TaskQueue', 'Stats'],   // shares 'TaskQueue'
                    'output_types'      => ['Report', 'Dashboard'],  // shares 'Report'
                    'line_count'        => 90,
                ],
            ],
        ]);

        $merges = array_values(array_filter($result['candidates'], fn ($c) => $c['action'] === 'merge'));
        $this->assertNotEmpty($merges, 'I/O overlap must produce a merge candidate');

        $organIds = $merges[0]['organ_ids'];
        $this->assertContains('reporter', $organIds);
        $this->assertContains('analyzer', $organIds);
        $this->assertSame('io_semantic_overlap', $merges[0]['group_type']);
    }

    public function test_no_io_merge_when_input_overlaps_but_output_does_not(): void
    {
        $result = $this->planner()->plan([
            'organs' => [
                ['id' => 'x', 'capability_labels' => ['cap_x'], 'input_types' => ['Shared'], 'output_types' => ['FooResult'],  'line_count' => 60],
                ['id' => 'y', 'capability_labels' => ['cap_y'], 'input_types' => ['Shared'], 'output_types' => ['BarResult'],  'line_count' => 50],
            ],
        ]);

        $merges = array_filter($result['candidates'], fn ($c) => $c['action'] === 'merge');
        $this->assertEmpty($merges, 'Shared input only (no shared output) must not merge');
    }

    public function test_no_io_merge_when_output_overlaps_but_input_does_not(): void
    {
        $result = $this->planner()->plan([
            'organs' => [
                ['id' => 'x', 'capability_labels' => ['cap_x'], 'input_types' => ['FooIn'], 'output_types' => ['SharedOut'], 'line_count' => 60],
                ['id' => 'y', 'capability_labels' => ['cap_y'], 'input_types' => ['BarIn'], 'output_types' => ['SharedOut'], 'line_count' => 50],
            ],
        ]);

        $merges = array_filter($result['candidates'], fn ($c) => $c['action'] === 'merge');
        $this->assertEmpty($merges, 'Shared output only (no shared input) must not merge');
    }

    public function test_dedup_prevents_duplicate_merge_candidate_for_same_organ_group(): void
    {
        // Organs share BOTH a capability_label AND a purpose_tag — must emit only ONE merge.
        $result = $this->planner()->plan([
            'organs' => [
                ['id' => 'a', 'capability_labels' => ['gate'], 'purpose_tag' => 'filtering', 'line_count' => 50],
                ['id' => 'b', 'capability_labels' => ['gate'], 'purpose_tag' => 'filtering', 'line_count' => 40],
            ],
        ]);

        $merges = array_filter($result['candidates'], fn ($c) => $c['action'] === 'merge');
        $this->assertCount(1, $merges, 'Overlapping grouping dimensions must not duplicate the merge candidate');
    }

    // ── AC2: required new fields on merge candidates ──────────────────────────

    public function test_merge_candidate_has_all_required_new_fields(): void
    {
        $result = $this->planner()->plan([
            'organs' => [
                ['id' => 'm1', 'capability_labels' => ['scorer'], 'line_count' => 100,
                 'contracts' => ['ScorerInterface', 'Serializable'], 'required_tests' => ['ScorerTest']],
                ['id' => 'm2', 'capability_labels' => ['scorer'], 'line_count' => 80,
                 'contracts' => ['ScorerInterface'],                 'required_tests' => ['ScorerV2Test']],
            ],
        ]);

        $merges = array_values(array_filter($result['candidates'], fn ($c) => $c['action'] === 'merge'));
        $this->assertNotEmpty($merges);
        $m = $merges[0];

        foreach (['risk_level', 'expected_line_reduction', 'preserved_contracts', 'required_tests'] as $field) {
            $this->assertArrayHasKey($field, $m, "merge candidate missing field: {$field}");
        }

        // expected_line_reduction must equal abs(expected_line_delta) for negative delta
        $this->assertGreaterThanOrEqual(0, $m['expected_line_reduction']);
        $this->assertSame(max(0, -$m['expected_line_delta']), $m['expected_line_reduction']);

        // preserved_contracts: union of both organs' contracts
        $this->assertContains('ScorerInterface', $m['preserved_contracts']);
        $this->assertContains('Serializable',    $m['preserved_contracts']);

        // required_tests: union of both organs' tests (no duplicates)
        $this->assertContains('ScorerTest',   $m['required_tests']);
        $this->assertContains('ScorerV2Test', $m['required_tests']);
        $this->assertSameSize(array_unique($m['required_tests']), $m['required_tests'], 'required_tests must not have duplicates');
    }

    public function test_delete_candidate_has_all_required_new_fields(): void
    {
        $result = $this->planner()->plan([
            'organs' => [
                [
                    'id'                    => 'stale-target',
                    'stale_scaffold_marker' => true,
                    'replacement_owner'     => 'NewImplementation',
                    'test_coverage'         => true,
                    'line_count'            => 150,
                    'contracts'             => ['StaleInterface', 'LegacyAdapter'],
                    'required_tests'        => ['MigrationSmokeTest'],
                ],
            ],
        ]);

        $deletes = array_values(array_filter($result['candidates'], fn ($c) => $c['action'] === 'delete'));
        $this->assertNotEmpty($deletes);
        $d = $deletes[0];

        foreach (['risk_level', 'expected_line_reduction', 'preserved_contracts', 'required_tests'] as $field) {
            $this->assertArrayHasKey($field, $d, "delete candidate missing field: {$field}");
        }
        $this->assertSame(150,                  $d['expected_line_reduction']);
        $this->assertContains('StaleInterface',  $d['preserved_contracts']);
        $this->assertContains('LegacyAdapter',   $d['preserved_contracts']);
        $this->assertContains('MigrationSmokeTest', $d['required_tests']);
    }

    public function test_expected_line_reduction_is_nonnegative_for_all_actions(): void
    {
        $result = $this->planner()->plan([
            'organs'           => [
                ['id' => 'del', 'stale_scaffold_marker' => true, 'replacement_owner' => 'x',
                 'test_coverage' => true, 'line_count' => 100],
                ['id' => 'big', 'line_count' => 300, 'test_coverage' => true],
                ['id' => 'blocked', 'stale_scaffold_marker' => true, 'replacement_owner' => '',
                 'test_coverage' => true],
            ],
            'growth_threshold' => 200,
        ]);

        foreach ($result['candidates'] as $candidate) {
            $this->assertArrayHasKey('expected_line_reduction', $candidate);
            $this->assertGreaterThanOrEqual(0, $candidate['expected_line_reduction'],
                "expected_line_reduction must be ≥0 for action={$candidate['action']}");
        }
    }

    // ── AC3: retire_now is false for high-risk or behavior-unique organs ───────

    public function test_retire_now_is_true_for_low_risk_safe_delete_of_normal_organ(): void
    {
        $result = $this->planner()->plan([
            'organs' => [
                [
                    'id'                    => 'safe-retire',
                    'stale_scaffold_marker' => true,
                    'replacement_owner'     => 'NewOrgan',
                    'test_coverage'         => true,
                    'behavior_unique'       => false,
                    'line_count'            => 80,
                ],
            ],
        ]);

        $deletes = array_values(array_filter($result['candidates'], fn ($c) => $c['action'] === 'delete'));
        $this->assertNotEmpty($deletes);
        $this->assertTrue($deletes[0]['retire_now'], 'Low-risk delete of non-unique organ must be retire_now=true');
    }

    public function test_retire_now_is_false_for_behavior_unique_organ_despite_safe_delete(): void
    {
        $result = $this->planner()->plan([
            'organs' => [
                [
                    'id'                    => 'unique-stale',
                    'stale_scaffold_marker' => true,
                    'replacement_owner'     => 'NewOrgan',
                    'test_coverage'         => true,
                    'behavior_unique'       => true,   // <-- cannot retire_now
                    'line_count'            => 120,
                ],
            ],
        ]);

        $deletes = array_values(array_filter($result['candidates'], fn ($c) => $c['action'] === 'delete'));
        $this->assertNotEmpty($deletes);
        $this->assertFalse($deletes[0]['retire_now'],
            'behavior_unique organ must never be retire_now, even when name looks redundant');
    }

    public function test_retire_now_is_false_for_high_risk_keep_candidate(): void
    {
        $result = $this->planner()->plan([
            'organs' => [
                [
                    'id'                    => 'stale-no-owner',
                    'stale_scaffold_marker' => true,
                    'replacement_owner'     => '',    // no owner → keep, risk=high
                    'test_coverage'         => true,
                    'line_count'            => 100,
                ],
            ],
        ]);

        $keeps = array_values(array_filter($result['candidates'], fn ($c) => $c['action'] === 'keep'));
        $this->assertNotEmpty($keeps);
        $this->assertFalse($keeps[0]['retire_now'], 'High-risk keep must be retire_now=false');
    }

    public function test_retire_now_is_false_for_missing_coverage_keep_candidate(): void
    {
        $result = $this->planner()->plan([
            'organs' => [
                [
                    'id'                    => 'no-coverage',
                    'stale_scaffold_marker' => true,
                    'replacement_owner'     => 'Owner',
                    'test_coverage'         => false,  // missing coverage → keep
                    'line_count'            => 80,
                ],
            ],
        ]);

        $keeps = array_values(array_filter($result['candidates'], fn ($c) => $c['action'] === 'keep'));
        $this->assertNotEmpty($keeps);
        $this->assertFalse($keeps[0]['retire_now']);
    }

    public function test_retire_now_is_false_for_merge_candidates(): void
    {
        $result = $this->planner()->plan([
            'organs' => [
                ['id' => 'a', 'capability_labels' => ['gate'], 'line_count' => 80],
                ['id' => 'b', 'capability_labels' => ['gate'], 'line_count' => 60],
            ],
        ]);

        $merges = array_values(array_filter($result['candidates'], fn ($c) => $c['action'] === 'merge'));
        $this->assertNotEmpty($merges);
        $this->assertFalse($merges[0]['retire_now'], 'Merge candidates must never be retire_now');
    }

    public function test_retire_now_is_false_for_simplify_candidates(): void
    {
        $result = $this->planner()->plan([
            'organs'           => [
                ['id' => 'big', 'line_count' => 300, 'test_coverage' => true],
            ],
            'growth_threshold' => 200,
        ]);

        $simplifies = array_values(array_filter($result['candidates'], fn ($c) => $c['action'] === 'simplify'));
        $this->assertNotEmpty($simplifies);
        $this->assertFalse($simplifies[0]['retire_now']);
    }

    public function test_organs_with_redundant_names_but_behavior_unique_are_not_retire_now(): void
    {
        // Two organs whose names suggest redundancy ("V1" vs "V2" of the same concept),
        // but one is flagged behavior_unique — retire_now must be false.
        $result = $this->planner()->plan([
            'organs' => [
                [
                    'id'                    => 'BacklogCostModelV1',
                    'capability_labels'     => ['cost_model'],
                    'stale_scaffold_marker' => true,
                    'replacement_owner'     => 'BacklogCostModelV2',
                    'test_coverage'         => true,
                    'behavior_unique'       => true,   // unique despite name similarity
                    'line_count'            => 200,
                ],
                [
                    'id'                    => 'BacklogCostModelV2',
                    'capability_labels'     => ['cost_model_v2'],
                    'line_count'            => 180,
                    'test_coverage'         => true,
                ],
            ],
        ]);

        $deletes = array_values(array_filter($result['candidates'], fn ($c) => $c['action'] === 'delete'));
        $this->assertNotEmpty($deletes);
        $this->assertFalse($deletes[0]['retire_now'],
            'behavior_unique organ must not be retire_now even when name suggests redundancy');
    }

    // ── AC4: plan_hash is stable ──────────────────────────────────────────────

    public function test_plan_hash_is_stable_for_identical_inventory(): void
    {
        $inventory = [
            'organs' => [
                ['id' => 'x', 'capability_labels' => ['scorer'],    'line_count' => 100,
                 'purpose_tag' => 'evaluation', 'input_types' => ['Spec'], 'output_types' => ['Score']],
                ['id' => 'y', 'capability_labels' => ['evaluator'], 'line_count' => 80,
                 'purpose_tag' => 'evaluation', 'input_types' => ['Spec'], 'output_types' => ['Score']],
            ],
        ];

        $h1 = $this->planner()->plan($inventory)['plan_hash'];
        $h2 = $this->planner()->plan($inventory)['plan_hash'];

        $this->assertSame($h1, $h2, 'plan_hash must be deterministic for identical inventory');
    }

    public function test_plan_hash_differs_for_different_inventory(): void
    {
        $r1 = $this->planner()->plan([
            'organs' => [['id' => 'a', 'capability_labels' => ['gate'],  'line_count' => 100]],
        ]);
        $r2 = $this->planner()->plan([
            'organs' => [['id' => 'b', 'capability_labels' => ['valve'], 'line_count' => 200]],
        ]);

        $this->assertNotSame($r1['plan_hash'], $r2['plan_hash'],
            'plan_hash must differ for different organ inventories');
    }

    public function test_plan_hash_starts_with_compression_prefix(): void
    {
        $result = $this->planner()->plan(['organs' => []]);
        $this->assertStringStartsWith('compression_', $result['plan_hash']);
    }
}
