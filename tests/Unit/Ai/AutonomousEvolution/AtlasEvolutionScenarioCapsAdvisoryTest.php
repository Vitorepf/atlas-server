<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionScenarioExplorer;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use Illuminate\Support\Facades\Config;
use ReflectionMethod;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 4 · Slice 10 — kill the one-shot "give up at patience". With caps_advisory ON and a real
 * time budget, patience becomes a HINT and the search keeps looking for a better solution while budget
 * remains (the canon's "nunca desistir"). Without a budget the patience stop MUST stay hard (no unbounded
 * search), and flag-OFF is byte-identical (the frozen convergence-count tests hold).
 *
 * (The §8 smaller-diff→leverage tie-break inversion is NOT shipped: a faithful leverage signal does not
 * exist at the isolated scenario layer — the workspace materializes src/<basename> with no caller graph —
 * and a diff-derived "leverage" is the forbidden sign-flipped proxy. Documented as architecture-bound.)
 */
final class AtlasEvolutionScenarioCapsAdvisoryTest extends TestCase
{
    private function explorer(): AtlasEvolutionScenarioExplorer
    {
        $driver = new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                throw new \RuntimeException('shouldBreakSearch must never invoke the driver');
            }
        };

        return new AtlasEvolutionScenarioExplorer($driver, new AtlasEvolutionFrozenJudge);
    }

    /** @param array<string,mixed>|null $best */
    private function shouldBreak(int $i, int $min, ?array $best, int $noImprove, int $patience, int $timeBudget, float $start): bool
    {
        $m = new ReflectionMethod(AtlasEvolutionScenarioExplorer::class, 'shouldBreakSearch');
        $m->setAccessible(true);

        return (bool) $m->invoke($this->explorer(), $i, $min, $best, $noImprove, $patience, $timeBudget, $start);
    }

    public function test_flag_off_hard_stops_at_patience_byte_identical(): void
    {
        Config::set('atlas.loop.scenario_caps_advisory', false);
        // winner exists, last $patience scenarios did not beat it, no budget ⇒ stop (exactly as before).
        $this->assertTrue($this->shouldBreak(5, 1, ['x' => 1], 3, 3, 0, microtime(true)));
    }

    public function test_caps_advisory_on_with_budget_persists_past_patience(): void
    {
        Config::set('atlas.loop.scenario_caps_advisory', true);
        // patience reached, but a positive budget remains (just started) ⇒ keep searching (do NOT stop).
        $this->assertFalse($this->shouldBreak(5, 1, ['x' => 1], 3, 3, 100, microtime(true)));
    }

    public function test_caps_advisory_on_with_no_budget_still_hard_stops_no_runaway(): void
    {
        Config::set('atlas.loop.scenario_caps_advisory', true);
        // caps_advisory ON but NO budget ⇒ patience stays a HARD stop (the anti-runaway guarantee).
        $this->assertTrue($this->shouldBreak(5, 1, ['x' => 1], 3, 3, 0, microtime(true)));
    }

    public function test_an_elapsed_budget_always_stops_regardless_of_flag(): void
    {
        foreach ([true, false] as $flag) {
            Config::set('atlas.loop.scenario_caps_advisory', $flag);
            // budget elapsed (started 10s ago, budget 1s) ⇒ stop, even mid-improvement, even flag ON.
            $this->assertTrue($this->shouldBreak(5, 1, ['x' => 1], 0, 3, 1, microtime(true) - 10.0));
        }
    }

    public function test_below_min_or_no_winner_never_stops(): void
    {
        Config::set('atlas.loop.scenario_caps_advisory', false);
        $this->assertFalse($this->shouldBreak(0, 1, ['x' => 1], 9, 3, 0, microtime(true)), 'below min scenarios');
        $this->assertFalse($this->shouldBreak(9, 1, null, 9, 3, 0, microtime(true)), 'no winner yet');
    }
}
