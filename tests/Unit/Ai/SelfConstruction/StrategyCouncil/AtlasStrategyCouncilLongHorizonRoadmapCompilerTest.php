<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\StrategyCouncil;

use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilLongHorizonRoadmapCompiler;
use PHPUnit\Framework\TestCase;

final class AtlasStrategyCouncilLongHorizonRoadmapCompilerTest extends TestCase
{
    private function compiler(): AtlasStrategyCouncilLongHorizonRoadmapCompiler
    {
        return new AtlasStrategyCouncilLongHorizonRoadmapCompiler;
    }

    private function gap(string $id, array $deps = []): array
    {
        return ['id' => $id, 'name' => ucfirst(str_replace('-', ' ', $id)), 'depends_on' => $deps];
    }

    // ── AC1: phased roadmap output shape ──────────────────────────────────────

    public function test_output_has_schema_version_and_all_three_phases(): void
    {
        $r = $this->compiler()->compile([]);
        $this->assertSame(AtlasStrategyCouncilLongHorizonRoadmapCompiler::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('near_term', $r['phases']);
        $this->assertArrayHasKey('mid_term', $r['phases']);
        $this->assertArrayHasKey('long_term', $r['phases']);
    }

    public function test_single_gap_lands_in_near_term(): void
    {
        $r = $this->compiler()->compile([
            'gap_index'         => [$this->gap('gap-1')],
            'calibrated_impact' => ['gap-1' => 0.9],
            'worker_capacity'   => ['near_term' => 3, 'mid_term' => 3],
        ]);

        $this->assertContains('gap-1', $r['phases']['near_term']['gaps']);
        $this->assertNotContains('gap-1', $r['phases']['mid_term']['gaps']);
        $this->assertNotContains('gap-1', $r['phases']['long_term']['gaps']);
    }

    public function test_gaps_beyond_capacity_spill_to_mid_and_long(): void
    {
        $gaps = array_map(fn (int $i) => $this->gap("gap-$i"), range(1, 7));
        $r    = $this->compiler()->compile([
            'gap_index'         => $gaps,
            'worker_capacity'   => ['near_term' => 2, 'mid_term' => 3],
        ]);

        $this->assertCount(2, $r['phases']['near_term']['gaps']);
        $this->assertCount(3, $r['phases']['mid_term']['gaps']);
        $this->assertCount(2, $r['phases']['long_term']['gaps']);
        $this->assertSame(7, $r['total_gaps_scheduled']);
    }

    public function test_higher_impact_gaps_scheduled_first(): void
    {
        $r = $this->compiler()->compile([
            'gap_index' => [
                $this->gap('low-imp'),
                $this->gap('high-imp'),
            ],
            'calibrated_impact' => ['low-imp' => 0.2, 'high-imp' => 0.9],
            'worker_capacity'   => ['near_term' => 1, 'mid_term' => 1],
        ]);

        $this->assertSame(['high-imp'], $r['phases']['near_term']['gaps']);
        $this->assertSame(['low-imp'], $r['phases']['mid_term']['gaps']);
    }

    public function test_compression_candidates_noted_in_correct_phase(): void
    {
        $r = $this->compiler()->compile([
            'gap_index'              => [$this->gap('gap-a'), $this->gap('gap-b')],
            'worker_capacity'        => ['near_term' => 1, 'mid_term' => 2],
            'compression_candidates' => ['gap-b'],
        ]);

        $this->assertContains('gap-b', $r['phases']['mid_term']['compression_applied']);
        $this->assertEmpty($r['phases']['near_term']['compression_applied']);
    }

    // ── AC2: queue pressure cap + prerequisite chain ordering ─────────────────

    public function test_high_queue_pressure_reduces_near_term_capacity(): void
    {
        $gaps = array_map(fn (int $i) => $this->gap("gap-$i"), range(1, 6));
        $r    = $this->compiler()->compile([
            'gap_index'       => $gaps,
            'queue_forecast'  => ['current_pressure' => 0.9],
            'worker_capacity' => ['near_term' => 5, 'mid_term' => 4],
        ]);

        $this->assertTrue($r['queue_pressure_capped']);
        $this->assertTrue($r['phases']['near_term']['queue_pressure_capped']);
        // With reduction factor 0.6: floor(5 * 0.6) = 3
        $this->assertCount(3, $r['phases']['near_term']['gaps']);
    }

    public function test_low_queue_pressure_does_not_cap_near_term(): void
    {
        $gaps = array_map(fn (int $i) => $this->gap("gap-$i"), range(1, 4));
        $r    = $this->compiler()->compile([
            'gap_index'       => $gaps,
            'queue_forecast'  => ['current_pressure' => 0.5],
            'worker_capacity' => ['near_term' => 3, 'mid_term' => 3],
        ]);

        $this->assertFalse($r['queue_pressure_capped']);
        $this->assertCount(3, $r['phases']['near_term']['gaps']);
    }

    public function test_prerequisite_must_come_before_dependent_in_phases(): void
    {
        $r = $this->compiler()->compile([
            'gap_index' => [
                $this->gap('dep-of-a', []),
                $this->gap('gap-a', ['dep-of-a']),
            ],
            'calibrated_impact' => ['dep-of-a' => 0.3, 'gap-a' => 0.95],
            'worker_capacity'   => ['near_term' => 1, 'mid_term' => 1],
        ]);

        // Even though gap-a has higher impact, dep-of-a must land first.
        $this->assertContains('dep-of-a', $r['phases']['near_term']['gaps']);
        $this->assertContains('gap-a', $r['phases']['mid_term']['gaps']);
    }

    public function test_gap_with_dependency_marked_as_chain_completion(): void
    {
        $r = $this->compiler()->compile([
            'gap_index' => [
                $this->gap('base'),
                $this->gap('derived', ['base']),
            ],
            'worker_capacity' => ['near_term' => 2, 'mid_term' => 2],
        ]);

        // 'derived' has a dependency → it's a chain completion wherever it lands.
        $allCompletions = array_merge(
            $r['phases']['near_term']['chain_completions'],
            $r['phases']['mid_term']['chain_completions'],
            $r['phases']['long_term']['chain_completions'],
        );
        $this->assertContains('derived', $allCompletions);
        $this->assertNotContains('base', $allCompletions);
    }

    public function test_prerequisite_chains_respected_flag_is_always_true(): void
    {
        $r = $this->compiler()->compile([
            'gap_index'       => [$this->gap('g1'), $this->gap('g2', ['g1'])],
            'worker_capacity' => ['near_term' => 1, 'mid_term' => 1],
        ]);

        $this->assertTrue($r['prerequisite_chains_respected']);
    }

    public function test_cyclic_gap_index_reports_dependency_blockers_and_unscheduled_gaps(): void
    {
        $r = $this->compiler()->compile([
            'gap_index'       => [$this->gap('g1', ['g2']), $this->gap('g2', ['g1']), $this->gap('g3')],
            'worker_capacity' => ['near_term' => 5, 'mid_term' => 5],
        ]);

        $this->assertFalse($r['prerequisite_chains_respected']);
        $this->assertSame(1, $r['total_gaps_scheduled']);
        $this->assertContains('g1', $r['dependency_blockers']);
        $this->assertContains('g2', $r['dependency_blockers']);
        $this->assertContains('g1', $r['unscheduled_gap_ids']);
        $this->assertContains('g2', $r['unscheduled_gap_ids']);
        $this->assertNotContains('g3', $r['dependency_blockers']);

        $allScheduled = array_merge(
            $r['phases']['near_term']['gaps'],
            $r['phases']['mid_term']['gaps'],
            $r['phases']['long_term']['gaps'],
        );
        $this->assertSame(['g3'], $allScheduled);
    }

    public function test_normal_dependency_chain_still_schedules_every_gap_and_respects_chains(): void
    {
        $r = $this->compiler()->compile([
            'gap_index'       => [$this->gap('g1'), $this->gap('g2', ['g1']), $this->gap('g3', ['g2'])],
            'worker_capacity' => ['near_term' => 5, 'mid_term' => 5],
        ]);

        $this->assertTrue($r['prerequisite_chains_respected']);
        $this->assertSame(3, $r['total_gaps_scheduled']);
        $this->assertSame([], $r['dependency_blockers']);
        $this->assertSame([], $r['unscheduled_gap_ids']);
    }

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'gap_index'         => [$this->gap('g1'), $this->gap('g2', ['g1']), $this->gap('g3')],
            'calibrated_impact' => ['g1' => 0.7, 'g2' => 0.5, 'g3' => 0.9],
            'queue_forecast'    => ['current_pressure' => 0.6],
            'worker_capacity'   => ['near_term' => 2, 'mid_term' => 2],
        ];
        $a = $this->compiler()->compile($facts);
        $b = $this->compiler()->compile($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── AC3: taskable slices + not_queue_ready abstract items ─────────────────

    public function test_admitted_roadmap_item_produces_taskable_slice(): void
    {
        $gap = $this->gap('gap-1');
        $gap['allowed_files'] = ['app/Foo.php', 'tests/FooTest.php'];
        $gap['acceptance_criteria'] = ['php artisan test tests/FooTest.php'];
        $gap['required_evidence'] = ['tests_or_gates_result'];

        $r = $this->compiler()->compile(['gap_index' => [$gap]]);

        $this->assertCount(1, $r['taskable_slices']);
        $slice = $r['taskable_slices'][0];
        $this->assertSame('gap-1', $slice['gap_id']);
        $this->assertSame(['app/Foo.php', 'tests/FooTest.php'], $slice['allowed_files']);
        $this->assertSame(['php artisan test tests/FooTest.php'], $slice['acceptance_criteria']);
        $this->assertSame(['tests_or_gates_result'], $slice['required_evidence']);
        $this->assertSame([], $r['not_queue_ready_gap_ids']);
    }

    public function test_abstract_roadmap_item_without_scope_is_not_queue_ready(): void
    {
        $r = $this->compiler()->compile(['gap_index' => [$this->gap('abstract-gap')]]);

        $this->assertSame([], $r['taskable_slices']);
        $this->assertContains('abstract-gap', $r['not_queue_ready_gap_ids']);
    }

    public function test_partial_scope_without_acceptance_or_evidence_is_not_queue_ready(): void
    {
        $gap = $this->gap('half-done');
        $gap['allowed_files'] = ['app/Foo.php'];
        // no acceptance_criteria, no required_evidence

        $r = $this->compiler()->compile(['gap_index' => [$gap]]);

        $this->assertSame([], $r['taskable_slices']);
        $this->assertContains('half-done', $r['not_queue_ready_gap_ids']);
    }

    public function test_mixed_admitted_and_abstract_items_are_partitioned_correctly(): void
    {
        $ready = $this->gap('ready-gap');
        $ready['allowed_files'] = ['app/Bar.php'];
        $ready['acceptance_criteria'] = ['php artisan test tests/BarTest.php'];
        $ready['required_evidence'] = ['tests_or_gates_result'];

        $r = $this->compiler()->compile(['gap_index' => [$ready, $this->gap('abstract-gap')]]);

        $this->assertCount(1, $r['taskable_slices']);
        $this->assertSame('ready-gap', $r['taskable_slices'][0]['gap_id']);
        $this->assertSame(['abstract-gap'], $r['not_queue_ready_gap_ids']);
    }

    // ── AC4: capability_arcs, dependency_chains, proof_milestones, simplification_waves, stop_go_checkpoints, risk_notes ──

    public function test_empty_facts_yield_empty_new_fields_with_all_checkpoints_green(): void
    {
        $r = $this->compiler()->compile([]);

        $this->assertSame([], $r['capability_arcs']);
        $this->assertSame([], $r['dependency_chains']);
        $this->assertSame([], $r['proof_milestones']);
        $this->assertSame([], $r['simplification_waves']);
        $this->assertSame([], $r['risk_notes']);
        foreach ($r['stop_go_checkpoints'] as $checkpoint) {
            $this->assertTrue($checkpoint['go']);
        }
    }

    public function test_single_gap_yields_single_capability_arc(): void
    {
        $r = $this->compiler()->compile(['gap_index' => [$this->gap('gap-1')]]);

        $this->assertCount(1, $r['capability_arcs']);
        $this->assertSame('arc_0', $r['capability_arcs'][0]['arc']);
        $this->assertSame(['gap-1'], $r['capability_arcs'][0]['gaps']);
    }

    public function test_dependent_gaps_yield_two_arcs_and_a_dependency_chain_edge(): void
    {
        $r = $this->compiler()->compile([
            'gap_index' => [$this->gap('base'), $this->gap('derived', ['base'])],
        ]);

        $this->assertCount(2, $r['capability_arcs']);
        $this->assertSame(['base'], $r['capability_arcs'][0]['gaps']);
        $this->assertSame(['derived'], $r['capability_arcs'][1]['gaps']);
        $this->assertSame([['from' => 'base', 'to' => 'derived']], $r['dependency_chains']);
    }

    public function test_compression_candidate_produces_a_simplification_wave_for_its_phase(): void
    {
        $r = $this->compiler()->compile([
            'gap_index'              => [$this->gap('gap-a'), $this->gap('gap-b')],
            'worker_capacity'        => ['near_term' => 1, 'mid_term' => 2],
            'compression_candidates' => ['gap-b'],
        ]);

        $this->assertCount(1, $r['simplification_waves']);
        $this->assertSame('mid_term', $r['simplification_waves'][0]['phase']);
        $this->assertSame(['gap-b'], $r['simplification_waves'][0]['gaps']);
    }

    public function test_taskable_gap_with_required_evidence_produces_a_proof_milestone(): void
    {
        $gap = $this->gap('gap-1');
        $gap['allowed_files'] = ['app/Foo.php'];
        $gap['acceptance_criteria'] = ['php artisan test'];
        $gap['required_evidence'] = ['tests_or_gates_result'];

        $r = $this->compiler()->compile(['gap_index' => [$gap]]);

        $this->assertCount(1, $r['proof_milestones']);
        $this->assertSame('gap-1', $r['proof_milestones'][0]['gap_id']);
        $this->assertSame(['tests_or_gates_result'], $r['proof_milestones'][0]['required_evidence']);
    }

    public function test_dependency_cycle_and_pressure_and_abstract_items_all_surface_in_risk_notes_and_checkpoints(): void
    {
        $r = $this->compiler()->compile([
            'gap_index'      => [$this->gap('g1', ['g2']), $this->gap('g2', ['g1']), $this->gap('abstract')],
            'queue_forecast' => ['current_pressure' => 0.9],
        ]);

        $this->assertContains('dependency_cycle_or_missing_dependency_detected', $r['risk_notes']);
        $this->assertContains('queue_pressure_above_cap_near_term_capacity_reduced', $r['risk_notes']);
        $this->assertContains('abstract_roadmap_items_not_queue_ready', $r['risk_notes']);

        $goByCheckpoint = [];
        foreach ($r['stop_go_checkpoints'] as $c) {
            $goByCheckpoint[$c['checkpoint']] = $c['go'];
        }
        $this->assertFalse($goByCheckpoint['dependency_integrity']);
        $this->assertFalse($goByCheckpoint['queue_pressure']);
        $this->assertFalse($goByCheckpoint['queue_readiness']);
    }

    public function test_new_roadmap_fields_are_deterministic_across_repeated_compiles(): void
    {
        $facts = [
            'gap_index'         => [$this->gap('g1'), $this->gap('g2', ['g1']), $this->gap('g3')],
            'calibrated_impact' => ['g1' => 0.7, 'g2' => 0.5, 'g3' => 0.9],
            'queue_forecast'    => ['current_pressure' => 0.6],
            'worker_capacity'   => ['near_term' => 2, 'mid_term' => 2],
        ];
        $a = $this->compiler()->compile($facts);
        $b = $this->compiler()->compile($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
