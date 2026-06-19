<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMutationOperators;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionEngine;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 2 · Slice 9 — the projection engine's content-fixpoint is a SET-THEORETIC fact, not a
 * panel mood: converged iff the obligation set stabilized AND the critic raised-then-resolved a material
 * obligation AND the binding axis is covered; otherwise it PARKS (no livelock). Normalization is
 * rephrase-proof and a fabricated mutation operator cannot manufacture coverage.
 */
final class AtlasLoopProjectionEngineTest extends TestCase
{
    private AtlasLoopProjectionEngine $engine;

    private string $realMutop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new AtlasLoopProjectionEngine();
        $this->realMutop = (string) array_key_first(AtlasLoopMutationOperators::map());
    }

    public function test_converges_when_set_stabilizes_critic_engaged_and_binding_axis_covered(): void
    {
        $designOb = ['kind' => 'complexity_reduced', 'target_symbol' => 'App\\Foo::bar', 'assertion_ref' => 'mutop:'.$this->realMutop];
        $criticOb = ['kind' => 'mutation_killed', 'target_symbol' => 'App\\Foo::bar', 'assertion_ref' => 'chartest:tests/FooTest.php::test_bar'];
        $criticKey = $this->engine->obligationKey($criticOb);

        $designer = function (int $round, array $current) use ($designOb): array {
            return $round === 1 ? [$designOb] : []; // round 1 proposes; later rounds address the critic (no new)
        };
        $criticRound = 0;
        $critic = function (array $current) use (&$criticRound, $criticOb, $criticKey): array {
            $criticRound++;

            return $criticRound === 1
                ? ['add' => [$criticOb], 'resolved' => []]    // raises a material obligation
                : ['add' => [], 'resolved' => [$criticKey]];  // then resolves it ⇒ engaged
        };

        $res = $this->engine->project('non_trivial', $designer, $critic, 8);

        $this->assertSame('converged', $res['status']);
        $this->assertTrue($res['binding_axis_covered'], 'complexity_reduced covers the non_trivial bottleneck');
        $this->assertTrue($res['critic_engaged']);
        $this->assertCount(2, $res['obligations']);
    }

    public function test_a_contract_that_never_grew_is_suspect_and_parks(): void
    {
        // The designer proposes one obligation; the critic raises NOTHING. A projection the critic never
        // materially engaged is NOT a fixpoint — it parks (a silent critic is suspect, not convergence).
        $ob = ['kind' => 'complexity_reduced', 'target_symbol' => 'App\\Foo::bar', 'assertion_ref' => 'mutop:'.$this->realMutop];
        $designer = fn (int $round, array $current): array => $round === 1 ? [$ob] : [];
        $critic = fn (array $current): array => ['add' => [], 'resolved' => []];

        $res = $this->engine->project('non_trivial', $designer, $critic, 5);

        $this->assertSame('parked', $res['status']);
        $this->assertFalse($res['critic_engaged']);
    }

    public function test_oscillation_parks_no_livelock(): void
    {
        // Designer keeps inventing a brand-new obligation every round ⇒ the set never stabilizes ⇒ PARK.
        $designer = fn (int $round, array $current): array => [[
            'kind' => 'mutation_killed', 'target_symbol' => 'App\\Foo::m'.$round, 'assertion_ref' => 'mutop:'.$this->realMutop,
        ]];
        $critic = fn (array $current): array => ['add' => [], 'resolved' => []];

        $res = $this->engine->project('non_trivial', $designer, $critic, 4);

        $this->assertSame('parked', $res['status']);
        $this->assertSame('oscillation_no_content_fixpoint', $res['reason']);
        $this->assertSame(4, $res['rounds']);
    }

    public function test_normalization_is_rephrase_proof(): void
    {
        $a = $this->engine->obligationKey(['kind' => 'Complexity_Reduced ', 'target_symbol' => ' \\App\\Foo :: bar ', 'assertion_ref' => 'MUTOP:'.strtoupper($this->realMutop)]);
        $b = $this->engine->obligationKey(['kind' => 'complexity_reduced', 'target_symbol' => 'app\\foo::bar', 'assertion_ref' => 'mutop:'.$this->realMutop]);

        $this->assertNotNull($a);
        $this->assertSame($a, $b, 'whitespace/case/leading-slash differences collapse to ONE key');
    }

    public function test_a_fabricated_or_out_of_enum_obligation_is_rejected(): void
    {
        // A mutation operator that is not in the real registry cannot ground an obligation.
        $this->assertNull($this->engine->obligationKey([
            'kind' => 'mutation_killed', 'target_symbol' => 'App\\Foo::bar', 'assertion_ref' => 'mutop:totally_fake_operator_xyz',
        ]));
        // An out-of-enum kind cannot smuggle a phantom axis.
        $this->assertNull($this->engine->obligationKey([
            'kind' => 'looks_legit', 'target_symbol' => 'App\\Foo::bar', 'assertion_ref' => 'mutop:'.$this->realMutop,
        ]));
        // An assertion_ref with no concrete discriminator is ungrounded.
        $this->assertNull($this->engine->obligationKey([
            'kind' => 'mutation_killed', 'target_symbol' => 'App\\Foo::bar', 'assertion_ref' => 'trust me bro',
        ]));
        // …and a well-formed, registry-grounded one IS accepted.
        $this->assertNotNull($this->engine->obligationKey([
            'kind' => 'mutation_killed', 'target_symbol' => 'App\\Foo::bar', 'assertion_ref' => 'mutop:'.$this->realMutop,
        ]));
    }

    public function test_convergence_requires_the_binding_axis_to_be_covered(): void
    {
        // Stable set + an engaged critic, but NO obligation maps to the binding axis (wired) ⇒ NOT converged.
        $designOb = ['kind' => 'complexity_reduced', 'target_symbol' => 'App\\Foo::bar', 'assertion_ref' => 'mutop:'.$this->realMutop];
        $criticOb = ['kind' => 'complexity_reduced', 'target_symbol' => 'App\\Foo::baz', 'assertion_ref' => 'mutop:'.$this->realMutop];
        $criticKey = $this->engine->obligationKey($criticOb);
        $designer = fn (int $round, array $current): array => $round === 1 ? [$designOb] : [];
        $criticRound = 0;
        $critic = function (array $current) use (&$criticRound, $criticOb, $criticKey): array {
            $criticRound++;

            return $criticRound === 1 ? ['add' => [$criticOb], 'resolved' => []] : ['add' => [], 'resolved' => [$criticKey]];
        };

        $res = $this->engine->project('wired', $designer, $critic, 6);

        $this->assertSame('parked', $res['status'], 'complexity_reduced never covers the wired axis ⇒ no convergence');
        $this->assertFalse($res['binding_axis_covered']);
    }
}
