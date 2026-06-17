<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopPlanReadinessGate;
use Tests\TestCase;

/**
 * ACDE P3 — the spec-coverage readiness band REPLANS a DAG that drops an acceptance criterion (no node covers
 * it). Default OFF is byte-identical; a criterion whose keyword appears in a node is covered (no false REPLAN).
 */
final class AtlasLoopSpecCoverageBandTest extends TestCase
{
    private const PREFIX = 'spec_criterion_uncovered:';

    private function plan(array $criteria): array
    {
        return [
            'plan_id' => 'p1',
            'acceptance_criteria' => $criteria,
            'nodes' => [
                ['id' => 'n1', 'request' => 'implement computeTotal for the widget', 'target_area' => 'a.php', 'acceptance' => ['commands' => ['php a']]],
                ['id' => 'n2', 'request' => 'extract a small helper', 'target_area' => 'b.php', 'acceptance' => ['commands' => ['php b']]],
            ],
        ];
    }

    /** @param list<string> $gaps */
    private function specGaps(array $gaps): array
    {
        return array_values(array_filter($gaps, static fn (string $g): bool => str_starts_with($g, self::PREFIX)));
    }

    public function test_off_never_emits_a_coverage_gap_byte_identical(): void
    {
        $r = (new AtlasLoopPlanReadinessGate)->assess(
            $this->plan(['the widget must compute the total', 'handle empty input gracefully']),
            ['a.php', 'b.php'],
        );

        $this->assertSame([], $this->specGaps($r['gaps']));
    }

    public function test_armed_replans_on_an_uncovered_criterion(): void
    {
        config(['atlas.loop.spec_coverage_band_enabled' => true]);

        $r = (new AtlasLoopPlanReadinessGate)->assess(
            $this->plan(['the widget must compute the total', 'handle empty input gracefully']),
            ['a.php', 'b.php'],
        );

        // 'compute the total / widget' is covered by node n1; 'handle empty input gracefully' is covered by none.
        $gaps = $this->specGaps($r['gaps']);
        $this->assertCount(1, $gaps);
        $this->assertStringContainsString('handle empty input', $gaps[0]);
        $this->assertFalse($r['ready']);
        $this->assertSame(AtlasLoopPlanReadinessGate::REPLAN, $r['decision']);
    }

    public function test_armed_passes_when_every_criterion_is_covered(): void
    {
        config(['atlas.loop.spec_coverage_band_enabled' => true]);

        $r = (new AtlasLoopPlanReadinessGate)->assess(
            $this->plan(['compute the total widget value', 'extract the helper cleanly']),
            ['a.php', 'b.php'],
        );

        $this->assertSame([], $this->specGaps($r['gaps']), 'every criterion shares a keyword with a node');
    }
}
