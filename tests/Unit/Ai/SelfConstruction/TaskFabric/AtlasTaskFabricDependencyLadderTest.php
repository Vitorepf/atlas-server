<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricDependencyLadder;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasTaskFabricDependencyLadder: independent tasks share a single wave; a linear chain produces
 * one packet per wave with depends_on edges; a fan-in puts consumers in a later wave than each producer;
 * a cycle produces cycle_detected:<id> blockers; two packets in the same wave writing the same
 * allowed_files yields allowed_files_conflict:<path>.
 */
final class AtlasTaskFabricDependencyLadderTest extends TestCase
{
    public function test_independent_packets_collapse_into_one_wave(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'A', 'produces' => [], 'consumes' => [], 'allowed_files' => ['app/A.php']],
            ['id' => 'B', 'produces' => [], 'consumes' => [], 'allowed_files' => ['app/B.php']],
        ]);
        $this->assertSame([['A', 'B']], $r['waves']);
        $this->assertSame([], $r['blockers']);
    }

    public function test_linear_chain_yields_one_packet_per_wave_with_depends_on_edges(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'svc', 'produces' => ['Service'], 'consumes' => [], 'allowed_files' => ['app/S.php']],
            ['id' => 'cli', 'produces' => ['Cli'], 'consumes' => ['Service'], 'allowed_files' => ['app/Cli.php']],
            ['id' => 'integ', 'produces' => [], 'consumes' => ['Cli'], 'allowed_files' => ['tests/Integ.php']],
        ]);
        $this->assertSame([['svc'], ['cli'], ['integ']], $r['waves']);
        $this->assertSame(['svc'], $r['depends_on']['cli']);
        $this->assertSame(['cli'], $r['depends_on']['integ']);
        $this->assertSame([], $r['blockers']);
    }

    public function test_fan_in_places_consumer_after_every_producer(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'p1', 'produces' => ['A'], 'allowed_files' => ['app/A.php']],
            ['id' => 'p2', 'produces' => ['B'], 'allowed_files' => ['app/B.php']],
            ['id' => 'c1', 'consumes' => ['A', 'B'], 'allowed_files' => ['app/Joined.php']],
        ]);
        $this->assertSame([['p1', 'p2'], ['c1']], $r['waves']);
        $this->assertSame(['p1', 'p2'], $r['depends_on']['c1']);
    }

    public function test_cycle_yields_cycle_detected_blockers(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'X', 'produces' => ['x_sym'], 'consumes' => ['y_sym']],
            ['id' => 'Y', 'produces' => ['y_sym'], 'consumes' => ['x_sym']],
        ]);
        $this->assertContains('cycle_detected:X', $r['blockers']);
        $this->assertContains('cycle_detected:Y', $r['blockers']);
    }

    public function test_missing_producer_yields_missing_producer_blocker(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'consumer', 'consumes' => ['MissingThing']],
        ]);
        $this->assertContains('missing_producer_for:MissingThing', $r['blockers']);
    }

    public function test_same_file_conflict_within_a_wave_is_blocker(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'a', 'allowed_files' => ['app/Shared.php']],
            ['id' => 'b', 'allowed_files' => ['app/Shared.php']],
        ]);
        $this->assertContains('allowed_files_conflict:app/Shared.php', $r['blockers']);
    }

    // ── prerequisite_evidence checks ─────────────────────────────────────────

    public function test_producer_with_empty_prerequisite_evidence_surfaces_blocker(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'prod', 'produces' => ['Widget'], 'allowed_files' => ['app/Widget.php'], 'prerequisite_evidence' => []],
            ['id' => 'cons', 'consumes' => ['Widget'], 'allowed_files' => ['app/Consumer.php']],
        ]);
        $this->assertContains('missing_prerequisite_evidence:Widget', $r['blockers']);
    }

    public function test_producer_with_prerequisite_evidence_does_not_surface_blocker(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'prod', 'produces' => ['Widget'], 'allowed_files' => ['app/Widget.php'], 'prerequisite_evidence' => ['tests_or_gates_result']],
            ['id' => 'cons', 'consumes' => ['Widget'], 'allowed_files' => ['app/Consumer.php']],
        ]);
        $this->assertNotContains('missing_prerequisite_evidence:Widget', $r['blockers']);
    }

    public function test_producer_without_prerequisite_evidence_field_does_not_surface_blocker(): void
    {
        // Field absent (not declared) means we don't check — backward compat.
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'prod', 'produces' => ['Widget'], 'allowed_files' => ['app/Widget.php']],
            ['id' => 'cons', 'consumes' => ['Widget'], 'allowed_files' => ['app/Consumer.php']],
        ]);
        $this->assertNotContains('missing_prerequisite_evidence:Widget', $r['blockers']);
    }

    // ── ambiguous_producer ────────────────────────────────────────────────────

    public function test_two_producers_for_same_symbol_yield_ambiguous_producer_blocker(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'prod-a', 'produces' => ['Widget'], 'allowed_files' => ['app/A/Widget.php']],
            ['id' => 'prod-b', 'produces' => ['Widget'], 'allowed_files' => ['app/B/Widget.php']],
            ['id' => 'cons', 'consumes' => ['Widget'], 'allowed_files' => ['app/Consumer.php']],
        ]);
        $this->assertContains('ambiguous_producer:Widget', $r['blockers']);
    }

    public function test_pinned_producer_id_suppresses_ambiguous_producer_blocker(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'prod-a', 'produces' => ['Widget'], 'allowed_files' => ['app/A/Widget.php']],
            ['id' => 'prod-b', 'produces' => ['Widget'], 'allowed_files' => ['app/B/Widget.php']],
            ['id' => 'cons', 'consumes' => ['Widget'], 'allowed_files' => ['app/Consumer.php'], 'producer_pins' => ['Widget' => 'prod-a']],
        ]);
        $this->assertNotContains('ambiguous_producer:Widget', $r['blockers']);
        $this->assertSame(['prod-a'], $r['depends_on']['cons']);
    }

    // ── conflict_reasons ──────────────────────────────────────────────────────

    public function test_conflict_reasons_empty_when_no_file_conflicts(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'A', 'allowed_files' => ['app/A.php']],
            ['id' => 'B', 'allowed_files' => ['app/B.php']],
        ]);
        $this->assertSame([], $r['conflict_reasons']);
    }

    public function test_conflict_reasons_contains_packet_ids_and_reason_code_for_file_collision(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'x', 'allowed_files' => ['app/Shared.php']],
            ['id' => 'y', 'allowed_files' => ['app/Shared.php']],
        ]);
        $this->assertCount(1, $r['conflict_reasons']);
        $cr = $r['conflict_reasons'][0];
        $this->assertSame('file_collision', $cr['reason_code']);
        $this->assertSame('app/Shared.php', $cr['file']);
        // packet_ids are sorted deterministically
        $this->assertSame(['x', 'y'], $cr['packet_ids']);
        $this->assertSame(0, $cr['wave_index']);
    }

    public function test_determinism_two_calls_same_input_identical_output(): void
    {
        $input = [
            ['id' => 'svc', 'produces' => ['S']],
            ['id' => 'cli', 'consumes' => ['S']],
        ];
        $l = new AtlasTaskFabricDependencyLadder;
        $this->assertSame(json_encode($l->ladder($input)), json_encode($l->ladder($input)));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC2: rung_type ordering — foundation before proof before cleanup before feature
    // ═══════════════════════════════════════════════════════════════════════

    public function test_foundation_repair_placed_before_feature_expansion_in_same_wave(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'F', 'rung_type' => 'feature_expansion', 'allowed_files' => ['app/Feature.php']],
            ['id' => 'R', 'rung_type' => 'foundation_repair', 'allowed_files' => ['app/Foundation.php']],
        ]);

        $this->assertCount(1, $r['waves']);
        $this->assertSame(['R', 'F'], $r['waves'][0],
            'foundation_repair must appear before feature_expansion in the wave');
    }

    public function test_rung_types_ordered_by_priority_across_waves(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'A', 'rung_type' => 'foundation_repair', 'produces' => ['F'], 'allowed_files' => ['app/F.php']],
            ['id' => 'B', 'rung_type' => 'proof_gate', 'produces' => ['P'], 'allowed_files' => ['app/P.php'], 'consumes' => ['F']],
            ['id' => 'C', 'rung_type' => 'cleanup', 'produces' => ['C'], 'allowed_files' => ['app/C.php'], 'consumes' => ['P']],
            ['id' => 'D', 'rung_type' => 'feature_expansion', 'allowed_files' => ['app/D.php'], 'consumes' => ['C']],
        ]);

        $this->assertCount(4, $r['waves']);
        $this->assertSame(['A'], $r['waves'][0], 'foundation_repair first');
        $this->assertSame(['B'], $r['waves'][1], 'proof_gate second');
        $this->assertSame(['C'], $r['waves'][2], 'cleanup third');
        $this->assertSame(['D'], $r['waves'][3], 'feature_expansion last');
    }

    public function test_ordered_rungs_includes_deduplicated_rung_types_in_priority_order(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'A', 'rung_type' => 'foundation_repair', 'allowed_files' => ['app/A.php']],
            ['id' => 'B', 'rung_type' => 'feature_expansion', 'allowed_files' => ['app/B.php']],
        ]);

        $this->assertContains('foundation_repair', $r['ordered_rungs']);
        $this->assertContains('feature_expansion', $r['ordered_rungs']);
        $this->assertLessThan(
            array_search('feature_expansion', $r['ordered_rungs'], true),
            array_search('foundation_repair', $r['ordered_rungs'], true),
            'foundation_repair must appear before feature_expansion in ordered_rungs',
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC3: blocked_by_prerequisite when earlier rungs are absent
    // ═══════════════════════════════════════════════════════════════════════

    public function test_feature_expansion_without_foundation_repair_is_blocked(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'feat', 'rung_type' => 'feature_expansion', 'allowed_files' => ['app/Feature.php']],
        ]);

        $this->assertNotEmpty($r['blocked_tasks']);
        $this->assertSame('feat', $r['blocked_tasks'][0]['packet_id']);
        $this->assertContains('foundation_repair', $r['blocked_tasks'][0]['missing_prerequisite_rungs']);
    }

    public function test_feature_expansion_with_all_prerequisites_not_blocked(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'R', 'rung_type' => 'foundation_repair', 'allowed_files' => ['app/R.php']],
            ['id' => 'G', 'rung_type' => 'proof_gate', 'allowed_files' => ['app/G.php']],
            ['id' => 'C', 'rung_type' => 'cleanup', 'allowed_files' => ['app/C.php']],
            ['id' => 'F', 'rung_type' => 'feature_expansion', 'allowed_files' => ['app/F.php']],
        ]);

        $this->assertSame([], $r['blocked_tasks'],
            'feature with all three prerequisites present must not be blocked');
    }

    public function test_unlock_reason_present_when_tasks_blocked(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'feat', 'rung_type' => 'feature_expansion', 'allowed_files' => ['app/Feature.php']],
        ]);

        $this->assertNotNull($r['unlock_reason']);
        $this->assertStringContainsString('feat', $r['unlock_reason']);
        $this->assertStringContainsString('foundation_repair', $r['unlock_reason']);
    }

    public function test_unlock_reason_null_when_no_tasks_blocked(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'R', 'rung_type' => 'foundation_repair', 'allowed_files' => ['app/R.php']],
        ]);

        $this->assertNull($r['unlock_reason']);
    }

    public function test_feature_expansion_with_some_prerequisites_present_still_blocked(): void
    {
        // Only foundation_repair present, missing proof_gate and cleanup.
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'R', 'rung_type' => 'foundation_repair', 'allowed_files' => ['app/R.php']],
            ['id' => 'F', 'rung_type' => 'feature_expansion', 'allowed_files' => ['app/F.php']],
        ]);

        $this->assertNotEmpty($r['blocked_tasks']);
        $this->assertSame('F', $r['blocked_tasks'][0]['packet_id']);
        $this->assertContains('proof_gate', $r['blocked_tasks'][0]['missing_prerequisite_rungs']);
        $this->assertContains('cleanup', $r['blocked_tasks'][0]['missing_prerequisite_rungs']);
        $this->assertNotContains('foundation_repair', $r['blocked_tasks'][0]['missing_prerequisite_rungs']);
    }

    public function test_output_includes_ordered_rungs_blocked_tasks_and_unlock_reason(): void
    {
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'feat', 'rung_type' => 'feature_expansion', 'allowed_files' => ['app/F.php']],
        ]);

        $this->assertArrayHasKey('ordered_rungs', $r);
        $this->assertArrayHasKey('blocked_tasks', $r);
        $this->assertArrayHasKey('unlock_reason', $r);
    }

    public function test_non_feature_rungs_not_blocked_when_other_rungs_missing(): void
    {
        // foundation_repair doesn't need proof_gate or cleanup — only feature_expansion does.
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'R', 'rung_type' => 'foundation_repair', 'allowed_files' => ['app/R.php']],
        ]);

        $this->assertSame([], $r['blocked_tasks'],
            'non-feature rungs must not be marked as blocked');
    }

    public function test_default_rung_type_is_feature_expansion_for_backward_compat(): void
    {
        // Packets without explicit rung_type default to feature_expansion.
        $r = (new AtlasTaskFabricDependencyLadder)->ladder([
            ['id' => 'A', 'allowed_files' => ['app/A.php']],
        ]);

        $this->assertArrayHasKey('ordered_rungs', $r);
        $this->assertSame(['feature_expansion'], $r['ordered_rungs']);
        $this->assertNotEmpty($r['blocked_tasks'],
            'default feature_expansion without earlier rungs must be blocked');
    }
}
