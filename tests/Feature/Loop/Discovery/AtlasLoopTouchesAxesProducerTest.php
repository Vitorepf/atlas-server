<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTouchesAxesProducer;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 4 · Slice 7.5 — the touches_axes producer. Each test pins the EXACT machine axis SET for a
 * path/signal class (not a count>0 tautology), and proves the model-bound axes are never machine-claimed.
 */
final class AtlasLoopTouchesAxesProducerTest extends TestCase
{
    private AtlasLoopTouchesAxesProducer $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = new AtlasLoopTouchesAxesProducer();
    }

    public function test_generated_test_and_docs_paths_touch_no_machine_axis(): void
    {
        foreach ([
            'app/Services/Generated/Thing.php',
            'tests/Feature/FooTest.php',
            'app/Foo/BarTest.php',
            'docs/whatever.md',
        ] as $path) {
            $out = $this->p->produce($path, 9, 40, true, 3, 10);
            $this->assertSame([], $out['touches_axes'], "$path is scaffolding — relieves nothing");
        }
    }

    public function test_real_orphan_non_refactor_touches_only_real_target(): void
    {
        $out = $this->p->produce('app/Svc/Orphan.php', 0, 4, false, 3, 10);
        $this->assertSame(['real_target'], $out['touches_axes']);
    }

    public function test_real_wired_non_hub_touches_real_target_and_wired_not_compounding(): void
    {
        $out = $this->p->produce('app/Svc/Wired.php', 1, 4, false, 3, 10);
        $this->assertSame(['real_target', 'wired'], $out['touches_axes']);
    }

    public function test_real_hub_touches_real_target_wired_and_compounding(): void
    {
        $out = $this->p->produce('app/Svc/Hub.php', 5, 4, false, 3, 10);
        $this->assertSame(['real_target', 'wired', 'compounding'], $out['touches_axes']);
    }

    public function test_verifiable_complex_real_adds_non_trivial_via_the_deterministic_refactor_door(): void
    {
        $out = $this->p->produce('app/Svc/Complex.php', 0, 25, true, 3, 10);
        $this->assertSame(['real_target', 'non_trivial'], $out['touches_axes']);

        // cyclomatic below the refactor threshold ⇒ NO non_trivial claim (the door is real, not a label).
        $low = $this->p->produce('app/Svc/Simple.php', 0, 5, true, 3, 10);
        $this->assertNotContains('non_trivial', $low['touches_axes']);

        // not verifiable (no sibling test) ⇒ NO non_trivial claim even if complex.
        $unverifiable = $this->p->produce('app/Svc/ComplexNoTest.php', 0, 40, false, 3, 10);
        $this->assertNotContains('non_trivial', $unverifiable['touches_axes']);
    }

    public function test_safety_is_never_machine_claimed_only_advisory(): void
    {
        // safety (1 − incidents) needs a model judgement pre-build — it must NEVER ride the machine set,
        // for ANY signal combination, but must be reported as advisory so the gap is explicit.
        foreach ([[5, 40, true], [0, 0, false], [10, 99, true]] as [$callers, $cx, $verif]) {
            $out = $this->p->produce('app/Svc/X.php', $callers, $cx, $verif, 3, 10);
            $this->assertNotContains('safety', $out['touches_axes']);
            $this->assertContains('safety', $out['advisory_axes']);
        }
    }

    public function test_is_deterministic_and_forPacket_reads_gather_fields(): void
    {
        $packet = ['path' => 'app/Svc/Hub.php', 'caller_count' => 5, 'cyclomatic' => 25, 'verifiable' => true];
        $a = $this->p->forPacket($packet);
        $b = $this->p->forPacket($packet);
        $this->assertSame($a, $b, 'pure: identical signals ⇒ identical set (no provider/clock/DB)');
        $this->assertSame(['real_target', 'wired', 'compounding', 'non_trivial'], $a);
    }
}
