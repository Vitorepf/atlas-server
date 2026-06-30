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
}
