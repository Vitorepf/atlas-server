<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopExpectedValueDecider;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObjectiveProducer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopSystemAxisService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTouchesAxesProducer;
use ReflectionMethod;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 4 · Slice 7 — wiring the EV brain into the producer. The load-bearing proof: when the
 * BINDING system axis changes, the EV-reordered winner RE-PIVOTS to the candidate that relieves it — and
 * the proof flows through the REAL touches-axes deriver + REAL EV decider on raw packet signals (only the
 * axis vector is injected), never by hand-injecting pre-derived touches_axes.
 */
final class AtlasLoopObjectiveProducerEvPickTest extends TestCase
{
    /** A producer whose ONLY injected collaborator is the axis vector (a controlled grade). */
    private function producerWithAxis(callable $grader): AtlasLoopObjectiveProducer
    {
        return new AtlasLoopObjectiveProducer(
            analyzer: null,
            stateReader: null,
            critic: null,
            origination: null,
            axis: new AtlasLoopSystemAxisService($grader),
            touches: new AtlasLoopTouchesAxesProducer(),   // REAL deriver
            ev: new AtlasLoopExpectedValueDecider(),       // REAL decider
        );
    }

    /** @return list<array<string,mixed>> two real-signal packets: a WIRED hub and a verifiable refactor. */
    private function packets(): array
    {
        return [
            // hub: 5 production callers (≥ hub 3) ⇒ machine axes {real_target, wired, compounding}
            ['path' => 'app/Svc/Hub.php', 'caller_count' => 5, 'cyclomatic' => 20, 'verifiable' => false, '_score' => ['leverage' => 12.0]],
            // verifiable complex orphan ⇒ machine axes {real_target, non_trivial}
            ['path' => 'app/Svc/Complex.php', 'caller_count' => 0, 'cyclomatic' => 25, 'verifiable' => true, '_score' => ['leverage' => 12.0]],
        ];
    }

    private function gradeWith(string $deficientAxis): callable
    {
        return function (string $repoRoot, ?int $window) use ($deficientAxis): array {
            $axes = ['wired' => 0.95, 'real_target' => 0.95, 'non_trivial' => 0.95, 'compounding' => 0.95, 'safety' => 0.95];
            $axes[$deficientAxis] = 0.1; // this axis binds

            return ['graded_merges' => 9, 'axes' => $axes];
        };
    }

    public function test_winner_repivots_when_the_binding_axis_changes(): void
    {
        $packets = $this->packets();

        // Binding = WIRED ⇒ the hub (touches wired) must lead.
        [$orderedWired] = $this->producerWithAxis($this->gradeWith('wired'))
            ->reorderByExpectedValue($packets, '/tmp/x');
        $this->assertSame('app/Svc/Hub.php', $orderedWired[0]['path'], 'wired binds ⇒ the wired hub wins');

        // Binding = NON_TRIVIAL ⇒ the verifiable refactor (touches non_trivial) must lead — same packets,
        // only the axis vector moved. This is the re-pivot, driven by the real touches_axes membership.
        [$orderedNt, $ev] = $this->producerWithAxis($this->gradeWith('non_trivial'))
            ->reorderByExpectedValue($packets, '/tmp/x');
        $this->assertSame('app/Svc/Complex.php', $orderedNt[0]['path'], 'non_trivial binds ⇒ the verifiable refactor wins');
        $this->assertSame('non_trivial', $ev['binding_axis']);
        $this->assertGreaterThan(0.9, $ev['relief'], 'the winner saturates relief on the binding axis');
    }

    public function test_fails_open_to_leverage_order_when_the_axis_layer_throws(): void
    {
        $throwing = function (string $repoRoot, ?int $window): array {
            throw new \RuntimeException('axis layer down');
        };
        $packets = $this->packets();

        [$ordered, $ev] = $this->producerWithAxis($throwing)->reorderByExpectedValue($packets, '/tmp/x');

        $this->assertNull($ev, 'a throwing axis layer yields no EV pick');
        $this->assertSame($packets, $ordered, 'fail-open: the leverage order is returned untouched');
    }

    public function test_leverage_to_value_is_monotonic_injective_and_never_clamps_to_100(): void
    {
        $m = new ReflectionMethod(AtlasLoopObjectiveProducer::class, 'leverageToValue');
        $m->setAccessible(true);
        $p = new AtlasLoopObjectiveProducer();

        $low = (float) $m->invoke($p, 1.0);
        $mid = (float) $m->invoke($p, 12.0);
        $high = (float) $m->invoke($p, 1000.0);

        $this->assertGreaterThan($low, $mid, 'monotonic');
        $this->assertGreaterThan($mid, $high, 'monotonic');
        $this->assertLessThan(100.0, $high, 'never a universal clamp to 100 — value keeps discriminating');
        // injective: two distinct leverages never collapse to the same value.
        $this->assertNotSame((float) $m->invoke($p, 50.0), (float) $m->invoke($p, 51.0));
    }

    public function test_flag_defaults_off_and_a_single_candidate_is_a_noop(): void
    {
        // byte-identical-OFF anchor: produce() only consults the EV brain when this flag is ON.
        $this->assertFalse((bool) config('atlas.loop.producer_ev_pick_enabled', false));

        // A lone floor-passer cannot be re-pivoted — reorder is a stable no-op (never spuriously drops it).
        $solo = [['path' => 'app/Svc/Only.php', 'caller_count' => 1, 'cyclomatic' => 12, 'verifiable' => true, '_score' => ['leverage' => 9.0]]];
        [$ordered] = $this->producerWithAxis($this->gradeWith('wired'))->reorderByExpectedValue($solo, '/tmp/x');
        $this->assertSame($solo, $ordered);
    }
}
