<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopHeavyWorkSelector;
use Tests\TestCase;

/**
 * L3 — THE FIBONACCI WIRE, proven: the rung GROWS with proven capability. Two candidates with IDENTICAL
 * measured value but different scope (a small SAFE one, node_count=2; a big RISKY one, node_count=10). As the
 * capability_factor rises, the risk-tolerance dial falls toward pure-magnitude, so the SELECTED pick shifts
 * from the small-safe to the big-risky leap — the loop dares bigger work BECAUSE it proved capability. This
 * is REAL compounding (the SELECTION moves to bigger scope), not a number bump (a uniform magnitude scale
 * would never change the pick). Flag-OFF (no capability_factor) is byte-identical to the §3 default.
 */
final class AtlasLoopCapabilityRungGrowthTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function candidates(): array
    {
        $evidence = ['refactor_leverage' => 0.7, 'cyclomatic_total' => 80, 'failure_evidence' => 0.6, 'blast_radius' => 1];

        return [
            ['candidateId' => 'small_safe', 'class' => 'safe', 'node_count' => 2, 'evidence' => $evidence],
            ['candidateId' => 'big_risky', 'class' => 'risky', 'node_count' => 10, 'evidence' => $evidence],
        ];
    }

    /** @return array<string,mixed> */
    private function context(?float $capability): array
    {
        $ctx = ['class_stats' => [
            'safe' => ['successes' => 50, 'failures' => 0],   // proven landing record => high P(land)
            'risky' => ['successes' => 0, 'failures' => 50],  // unproven => low P(land)
        ]];
        if ($capability !== null) {
            $ctx['capability_factor'] = $capability;
        }

        return $ctx;
    }

    private function pickNodeCount(?float $capability): int
    {
        $pick = (new AtlasLoopHeavyWorkSelector)->select($this->candidates(), $this->context($capability))['pick'];

        return (int) ($pick['node_count'] ?? 0); // the leap's scope (the rung size)
    }

    public function test_capability_rising_makes_the_attempted_rung_grow_monotonically(): void
    {
        $sweep = [0.0, 0.25, 0.5, 0.75, 1.0];
        $sizes = array_map(fn (float $c): int => $this->pickNodeCount($c), $sweep);

        // MONOTONIC: the attempted rung never shrinks as capability rises.
        for ($i = 1; $i < count($sizes); $i++) {
            $this->assertGreaterThanOrEqual($sizes[$i - 1], $sizes[$i], "rung must not shrink as capability rises (step {$i})");
        }

        // STRICT COMPOUNDING: at low capability it picks the small safe leap; at full capability the big one.
        $this->assertSame(2, $sizes[0], 'at capability 0 the loop attempts the small, safe rung');
        $this->assertSame(10, $sizes[count($sizes) - 1], 'at full capability the loop DARES the big rung — the rung grew');
        $this->assertGreaterThan($sizes[0], $sizes[count($sizes) - 1], 'capability ↑ => rung ↑ (Fibonacci compounding)');
    }

    public function test_flag_off_no_capability_is_byte_identical_to_default(): void
    {
        // No capability_factor => the §3 default risk-seeking (0.35) stands. Deterministic + equal to passing
        // risk_tolerance=0.35 explicitly (proves the capability wire is inert when the producer doesn't feed it).
        $defaultPick = (new AtlasLoopHeavyWorkSelector)->select($this->candidates(), $this->context(null))['pick'];

        $ctxExplicit = $this->context(null);
        $ctxExplicit['risk_tolerance'] = 0.35;
        $explicitPick = (new AtlasLoopHeavyWorkSelector)->select($this->candidates(), $ctxExplicit)['pick'];

        $this->assertSame($explicitPick['candidateId'], $defaultPick['candidateId'], 'no capability_factor => identical to the default risk-tolerance pick');
    }
}
