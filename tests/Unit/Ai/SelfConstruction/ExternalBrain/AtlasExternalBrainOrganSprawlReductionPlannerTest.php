<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOrganSprawlReductionPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOrganSprawlReductionPlannerTest extends TestCase
{
    private AtlasExternalBrainOrganSprawlReductionPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new AtlasExternalBrainOrganSprawlReductionPlanner;
    }

    private function organ(string $id, array $overrides = []): array
    {
        return array_merge([
            'organ_id'               => $id,
            'capability_labels'      => ['some_capability'],
            'evidence_strength'      => 0.80,
            'consumer_count'         => 3,
            'scaffold_status'        => 'active',
            'line_count'             => 100,
            'has_replacement_owner'  => false,
            'has_test_coverage'      => false,
            'overlap_organs'         => [],
        ], $overrides);
    }

    private function plan(array ...$organs): array
    {
        return $this->planner->plan(['organs' => $organs]);
    }

    private function findEntry(array $result, string $id): array
    {
        foreach ($result['ranked_actions'] as $entry) {
            if ($entry['organ_id'] === $id) {
                return $entry;
            }
        }
        $this->fail("Organ '{$id}' not found in ranked_actions.");
    }

    // ── Schema / required keys ────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->plan($this->organ('o1'));

        foreach (['schema', 'ranked_actions', 'expected_line_delta', 'capability_preserved_count', 'first_safe_batch', 'required_tests'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainOrganSprawlReductionPlanner::SCHEMA, $result['schema']);
    }

    // ── AC2: safe retire ──────────────────────────────────────────────────────

    public function test_low_evidence_with_safety_retires(): void
    {
        $result = $this->plan($this->organ('safe-r', [
            'evidence_strength'     => 0.10,
            'line_count'            => 150,
            'has_replacement_owner' => true,
            'has_test_coverage'     => true,
        ]));

        $entry = $this->findEntry($result, 'safe-r');
        $this->assertSame(AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_RETIRE, $entry['action']);
        $this->assertSame(-150, $entry['line_delta']);
    }

    // ── AC2: blocked retire ───────────────────────────────────────────────────

    public function test_low_evidence_without_replacement_is_retire_blocked(): void
    {
        $result = $this->plan($this->organ('blocked-r', [
            'evidence_strength'     => 0.10,
            'has_replacement_owner' => false,
            'has_test_coverage'     => true,
        ]));

        $entry = $this->findEntry($result, 'blocked-r');
        $this->assertSame(AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_RETIRE_BLOCKED, $entry['action']);
        $this->assertSame(0, $entry['line_delta']);
        $this->assertStringContainsString('replacement_owner', implode(' ', $entry['reasons']));
    }

    public function test_low_evidence_without_test_coverage_is_retire_blocked(): void
    {
        $result = $this->plan($this->organ('blocked-r2', [
            'evidence_strength'     => 0.10,
            'has_replacement_owner' => true,
            'has_test_coverage'     => false,
        ]));

        $entry = $this->findEntry($result, 'blocked-r2');
        $this->assertSame(AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_RETIRE_BLOCKED, $entry['action']);
        $this->assertStringContainsString('test_coverage', implode(' ', $entry['reasons']));
    }

    // ── AC2: safe merge ───────────────────────────────────────────────────────

    public function test_overlap_with_safety_merges(): void
    {
        $result = $this->plan($this->organ('safe-m', [
            'overlap_organs'        => ['organ-v2'],
            'line_count'            => 200,
            'has_replacement_owner' => true,
            'has_test_coverage'     => true,
        ]));

        $entry = $this->findEntry($result, 'safe-m');
        $this->assertSame(AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_MERGE, $entry['action']);
        $this->assertSame(-100, $entry['line_delta']); // 50% of 200
    }

    // ── AC2: blocked merge (new) ──────────────────────────────────────────────

    public function test_overlap_without_replacement_is_merge_blocked(): void
    {
        $result = $this->plan($this->organ('blocked-m', [
            'overlap_organs'        => ['organ-v2'],
            'has_replacement_owner' => false,
            'has_test_coverage'     => true,
        ]));

        $entry = $this->findEntry($result, 'blocked-m');
        $this->assertSame(AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_MERGE_BLOCKED, $entry['action']);
        $this->assertSame(0, $entry['line_delta']);
        $this->assertStringContainsString('replacement_owner', implode(' ', $entry['reasons']));
    }

    public function test_overlap_without_test_coverage_is_merge_blocked(): void
    {
        $result = $this->plan($this->organ('blocked-m2', [
            'overlap_organs'        => ['organ-v2'],
            'has_replacement_owner' => true,
            'has_test_coverage'     => false,
        ]));

        $entry = $this->findEntry($result, 'blocked-m2');
        $this->assertSame(AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_MERGE_BLOCKED, $entry['action']);
        $this->assertStringContainsString('test_coverage', implode(' ', $entry['reasons']));
    }

    // ── AC2: simplify oversized low-consumer organ ────────────────────────────

    public function test_oversized_low_consumer_simplifies(): void
    {
        $result = $this->plan($this->organ('big', [
            'line_count'     => 400,
            'consumer_count' => 1,
        ]));

        $entry = $this->findEntry($result, 'big');
        $this->assertSame(AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_SIMPLIFY, $entry['action']);
        $this->assertLessThan(0, $entry['line_delta']);
    }

    // ── AC2: keep high-value organ ────────────────────────────────────────────

    public function test_healthy_organ_is_kept(): void
    {
        $result = $this->plan($this->organ('keep-me', [
            'evidence_strength' => 0.90,
            'consumer_count'    => 5,
            'line_count'        => 80,
        ]));

        $entry = $this->findEntry($result, 'keep-me');
        $this->assertSame(AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_KEEP, $entry['action']);
        $this->assertSame(0, $entry['line_delta']);
    }

    // ── AC3: required_tests for every preserved capability ───────────────────

    public function test_keep_action_includes_required_tests_per_capability(): void
    {
        $result = $this->plan($this->organ('keep-me', [
            'capability_labels' => ['emit_tasks', 'score_quality'],
        ]));

        $entry = $this->findEntry($result, 'keep-me');
        $this->assertNotEmpty($entry['required_tests']);
        $this->assertStringContainsString('emit_tasks', implode(' ', $entry['required_tests']));
        $this->assertStringContainsString('score_quality', implode(' ', $entry['required_tests']));
    }

    // ── AC3: first_safe_batch ordered by line reduction, then fewest capabilities

    public function test_first_safe_batch_highest_line_reduction_first(): void
    {
        $result = $this->plan(
            $this->organ('small', ['evidence_strength' => 0.05, 'line_count' => 50,  'has_replacement_owner' => true, 'has_test_coverage' => true]),
            $this->organ('large', ['evidence_strength' => 0.05, 'line_count' => 300, 'has_replacement_owner' => true, 'has_test_coverage' => true]),
        );

        $this->assertSame('large', $result['first_safe_batch'][0]);
        $this->assertSame('small', $result['first_safe_batch'][1]);
    }

    // ── AC4: deterministic ordering — retire_blocked first ───────────────────

    public function test_retire_blocked_comes_before_keep_in_ranked_actions(): void
    {
        $result = $this->plan(
            $this->organ('k1'),
            $this->organ('rb', ['evidence_strength' => 0.05]),
        );

        $actions   = array_column($result['ranked_actions'], 'action');
        $blockedPos = array_search(AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_RETIRE_BLOCKED, $actions, true);
        $keepPos    = array_search(AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_KEEP, $actions, true);

        $this->assertLessThan($keepPos, $blockedPos);
    }

    // ── AC4: expected_line_delta sums retire + simplify ───────────────────────

    public function test_expected_line_delta_sums_all_deltas(): void
    {
        $result = $this->plan(
            $this->organ('r1', ['evidence_strength' => 0.05, 'line_count' => 100, 'has_replacement_owner' => true, 'has_test_coverage' => true]),
            $this->organ('s1', ['line_count' => 300, 'consumer_count' => 1]),
            $this->organ('k1'),
        );

        // retire delta = -100; simplify delta = -90 (30% of 300)
        $this->assertSame(-190, $result['expected_line_delta']);
    }

    // ── Empty input ───────────────────────────────────────────────────────────

    public function test_empty_organs_returns_zero_state(): void
    {
        $result = $this->plan();

        $this->assertSame([], $result['ranked_actions']);
        $this->assertSame(0, $result['expected_line_delta']);
        $this->assertSame([], $result['first_safe_batch']);
    }

    // ── AC3: rationale + risk_level on every entry ────────────────────────────

    public function test_every_ranked_action_has_rationale_and_risk_level(): void
    {
        $result = $this->plan(
            $this->organ('keep-me'),
            $this->organ('blocked-r', ['evidence_strength' => 0.05]),
        );

        foreach ($result['ranked_actions'] as $entry) {
            $this->assertArrayHasKey('rationale', $entry, "Missing rationale on {$entry['organ_id']}");
            $this->assertArrayHasKey('risk_level', $entry, "Missing risk_level on {$entry['organ_id']}");
            $this->assertIsString($entry['rationale']);
            $this->assertIsString($entry['risk_level']);
            $this->assertNotEmpty($entry['rationale']);
            $this->assertNotEmpty($entry['risk_level']);
        }
    }

    public function test_retire_blocked_has_high_risk_level(): void
    {
        $result = $this->plan($this->organ('rb', ['evidence_strength' => 0.05]));
        $entry  = $this->findEntry($result, 'rb');

        $this->assertSame('high', $entry['risk_level']);
    }

    public function test_merge_blocked_has_medium_risk_level(): void
    {
        $result = $this->plan($this->organ('mb', [
            'overlap_organs'        => ['other'],
            'has_replacement_owner' => false,
            'has_test_coverage'     => true,
        ]));
        $entry = $this->findEntry($result, 'mb');

        $this->assertSame('medium', $entry['risk_level']);
    }

    public function test_keep_and_retire_and_simplify_have_low_risk_level(): void
    {
        $result = $this->plan(
            $this->organ('k', ['evidence_strength' => 0.90, 'consumer_count' => 5]),
            $this->organ('r', ['evidence_strength' => 0.05, 'line_count' => 50, 'has_replacement_owner' => true, 'has_test_coverage' => true]),
            $this->organ('s', ['line_count' => 400, 'consumer_count' => 1]),
        );

        foreach (['k', 'r', 's'] as $id) {
            $this->assertSame('low', $this->findEntry($result, $id)['risk_level'], "Expected low risk for $id");
        }
    }

    // ── AC2: wrapper/template detection ──────────────────────────────────────

    public function test_wrapper_organ_classified_as_simplify(): void
    {
        $result = $this->plan($this->organ('wrap-01', [
            'organ_type' => 'wrapper',
            'line_count' => 150,
        ]));
        $entry = $this->findEntry($result, 'wrap-01');

        $this->assertSame(AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_SIMPLIFY, $entry['action']);
        $this->assertStringContainsString('consolidation_candidate', implode(' ', $entry['reasons']));
        $this->assertStringContainsString('consolidate', $entry['rationale']);
        $this->assertLessThan(0, $entry['line_delta']);
    }

    public function test_template_organ_classified_as_simplify(): void
    {
        $result = $this->plan($this->organ('tmpl-01', [
            'organ_type' => 'template',
            'line_count' => 200,
        ]));
        $entry = $this->findEntry($result, 'tmpl-01');

        $this->assertSame(AtlasExternalBrainOrganSprawlReductionPlanner::ACTION_SIMPLIFY, $entry['action']);
        $this->assertStringContainsString('consolidation_candidate', implode(' ', $entry['reasons']));
    }

    // ── AC2: capability_groups output ────────────────────────────────────────

    public function test_plan_output_has_capability_groups_key(): void
    {
        $result = $this->plan($this->organ('o1'));
        $this->assertArrayHasKey('capability_groups', $result);
        $this->assertIsArray($result['capability_groups']);
    }

    public function test_organs_sharing_label_form_a_group(): void
    {
        $result = $this->plan(
            $this->organ('a', ['capability_labels' => ['emit_tasks', 'score']]),
            $this->organ('b', ['capability_labels' => ['emit_tasks', 'route']]),
            $this->organ('c', ['capability_labels' => ['unrelated']]),
        );

        $groups = $result['capability_groups'];
        $this->assertCount(1, $groups, 'Only one overlap group should form (a+b share emit_tasks)');

        $group = $groups[0];
        $this->assertContains('a', $group['organ_ids']);
        $this->assertContains('b', $group['organ_ids']);
        $this->assertNotContains('c', $group['organ_ids']);
        $this->assertStringContainsString('emit_tasks', $group['capability_intent']);
        $this->assertArrayHasKey('overlap_type', $group);
        $this->assertArrayHasKey('consolidation_recommendation', $group);
    }

    public function test_wrapper_organ_in_group_produces_wrapper_overlap_type(): void
    {
        $result = $this->plan(
            $this->organ('core', ['capability_labels' => ['process_task']]),
            $this->organ('wrap', ['capability_labels' => ['process_task'], 'organ_type' => 'wrapper']),
        );

        $groups = $result['capability_groups'];
        $this->assertCount(1, $groups);
        $this->assertSame('wrapper', $groups[0]['overlap_type']);
        $this->assertSame('consolidate_into_core_service', $groups[0]['consolidation_recommendation']);
    }

    public function test_no_overlap_produces_empty_capability_groups(): void
    {
        $result = $this->plan(
            $this->organ('x', ['capability_labels' => ['label_x']]),
            $this->organ('y', ['capability_labels' => ['label_y']]),
        );

        $this->assertSame([], $result['capability_groups']);
    }
}
