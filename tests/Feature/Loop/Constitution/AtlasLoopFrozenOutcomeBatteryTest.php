<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Constitution;

use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopFrozenOutcomeBattery;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopExpectedValueDecider;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 3 · Slice 5 — the outcome battery pins "refactor = zero improvement": the REAL EV decider
 * ranks every frozen leap above its paired refactor (the pairs are EV-correct, not invented), and a selector
 * that ranks a refactor at/above a leap is REJECTED.
 */
final class AtlasLoopFrozenOutcomeBatteryTest extends TestCase
{
    private AtlasLoopFrozenOutcomeBattery $battery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->battery = new AtlasLoopFrozenOutcomeBattery();
    }

    /** A ranker backed by the REAL ExpectedValueDecider. */
    private function evRanker(): callable
    {
        return function (array $refactor, array $leap, array $axisValues): string {
            $d = (new AtlasLoopExpectedValueDecider())->decide(
                [$refactor, $leap],
                ['axis_values' => $axisValues, 'class_stats' => [], 'max_node_count' => 1],
            );

            return (string) ($d['winner']['candidateId'] ?? '');
        };
    }

    public function test_the_real_ev_decider_upholds_every_frozen_ranking(): void
    {
        // The pairs are constructed against the REAL EV math: the decider ranks the leap above the refactor
        // in EVERY frozen pair ⇒ zero violations. (Proves the correct-ranking is not an invented number.)
        $this->assertSame([], $this->battery->violations($this->evRanker()));
        $this->assertFalse($this->battery->rejectsSelector($this->evRanker()), 'the honest selector is not rejected');
    }

    public function test_a_blinder_selector_that_prefers_refactors_is_rejected(): void
    {
        // A selector that always ranks the refactor higher erodes the zero-improvement property ⇒ REJECTED,
        // every pair violated.
        $blinder = static fn (array $refactor, array $leap, array $axisValues): string => (string) $refactor['candidateId'];

        $violations = $this->battery->violations($blinder);
        $this->assertCount(count($this->battery->pairs()), $violations, 'every pair is violated by the refactor-preferring blinder');
        $this->assertTrue($this->battery->rejectsSelector($blinder));
    }

    public function test_root_hash_is_stable_and_pairs_are_well_formed(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->battery->rootHash());
        $this->assertSame($this->battery->rootHash(), $this->battery->rootHash());
        foreach ($this->battery->pairs() as $pair) {
            $this->assertSame('leap', $pair['leap']['candidateId']);
            $this->assertSame('refactor', $pair['refactor']['candidateId']);
            $this->assertGreaterThan((float) $pair['refactor']['value'], (float) $pair['leap']['value'], 'leap carries higher panel value');
        }
    }
}
