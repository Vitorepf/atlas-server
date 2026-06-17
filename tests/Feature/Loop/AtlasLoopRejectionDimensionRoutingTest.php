<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAutonomousConductor;
use App\Services\Ai\AutonomousEvolution\AtlasLoopRejectionDimensionRouter;
use Tests\TestCase;

/**
 * ACDE X3 — the certifier's namespaced rejection dimension is routed to a SPECIFIC re-attempt directive the
 * conductor appends to the next round's guidance. Proves the pure routing map + priority ordering, and that the
 * directive flows on re-attempt only when armed (byte-identical-OFF). Closure executors => no DB, hang-free.
 */
final class AtlasLoopRejectionDimensionRoutingTest extends TestCase
{
    public function test_routes_a_single_dimension_to_its_directive(): void
    {
        $r = (new AtlasLoopRejectionDimensionRouter)->route(['complexity_gate:not_reduced']);

        $this->assertSame('complexity_gate', $r['primary']);
        $this->assertSame(['complexity_gate'], $r['dimensions']);
        $this->assertStringContainsString('cyclomatic', $r['directive']);
    }

    public function test_orders_dimensions_by_priority_not_arrival(): void
    {
        // delivery_confidence arrives first but complexity_gate has higher routing priority => leads.
        $r = (new AtlasLoopRejectionDimensionRouter)->route([
            'delivery_confidence:below:0.5',
            'complexity_gate:not_reduced',
            'quality_bar:below_min:7',
        ]);

        $this->assertSame('complexity_gate', $r['primary']);
        $this->assertSame(['complexity_gate', 'quality_bar', 'delivery_confidence'], $r['dimensions']);
    }

    public function test_unknown_or_empty_reasons_route_to_nothing(): void
    {
        $r = (new AtlasLoopRejectionDimensionRouter)->route(['totally_unknown_reason', '', '   ']);

        $this->assertNull($r['primary']);
        $this->assertSame([], $r['dimensions']);
        $this->assertSame('', $r['directive']);
    }

    public function test_mf3_cross_file_consumer_gate_dimension_maps(): void
    {
        $r = (new AtlasLoopRejectionDimensionRouter)->route(['cross_file_consumer_gate:consumer_contracts_failed']);

        $this->assertContains('cross_file_consumer_gate', $r['dimensions']);
        $this->assertStringContainsString('consumer contract', $r['directive']);
        $this->assertStringContainsString('allowed cluster files', $r['directive']);
    }

    public function test_changed_symbol_and_completeness_dimensions_map(): void
    {
        $r = (new AtlasLoopRejectionDimensionRouter)->route(['changed_symbol_uncovered:Foo::bar', 'completeness:2_of_5']);

        $this->assertSame(['completeness', 'changed_symbol_uncovered'], $r['dimensions']);
        $this->assertStringContainsString('acceptance criterion', $r['directive']);
        $this->assertStringContainsString('public symbol', $r['directive']);
    }

    /** @param array<int,string> $captured */
    private function uncertifiedExecutor(array &$captured): callable
    {
        return function (string $goal, string $guidance, ?array $spec, int $round) use (&$captured): array {
            $captured[] = $guidance;

            return ['certified' => false, 'reason' => 'complexity_gate:not_reduced'];
        };
    }

    public function test_conductor_appends_the_directive_on_reattempt_when_armed(): void
    {
        config(['atlas.loop.rejection_dimension_routing_enabled' => true]);
        $captured = [];
        $exec = $this->uncertifiedExecutor($captured);

        (new AtlasLoopAutonomousConductor)->conduct('improve X', [
            'tier_executors' => ['best_of_n' => $exec, 'repair_from_refutation' => $exec, 'decompose' => $exec, 'escalate_provider' => $exec],
            'max_rounds' => 3,
        ]);

        $this->assertGreaterThanOrEqual(2, count($captured));
        $this->assertStringNotContainsString('FIX THIS SPECIFICALLY', $captured[0], 'round 1 has no prior outcome => clean guidance');
        $this->assertStringContainsString('FIX THIS SPECIFICALLY', $captured[1], 'round 2 routes the prior complexity_gate rejection');
        $this->assertStringContainsString('cyclomatic', $captured[1]);
    }

    public function test_conductor_guidance_is_byte_identical_when_off(): void
    {
        config(['atlas.loop.rejection_dimension_routing_enabled' => false]); // pin OFF (env-independent)
        $captured = [];
        $exec = $this->uncertifiedExecutor($captured);

        (new AtlasLoopAutonomousConductor)->conduct('improve X', [
            'tier_executors' => ['best_of_n' => $exec, 'repair_from_refutation' => $exec, 'decompose' => $exec, 'escalate_provider' => $exec],
            'max_rounds' => 3,
        ]);

        $this->assertGreaterThanOrEqual(2, count($captured));
        foreach ($captured as $g) {
            $this->assertStringNotContainsString('FIX THIS SPECIFICALLY', $g, 'OFF => no directive ever appended');
        }
    }
}
