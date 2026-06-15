<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAutonomousConductor;
use Tests\TestCase;

/**
 * Absurd-leap 1 — the autonomous conductor. Drives a goal to certification through the escalating tiers,
 * feeding working memory forward, stopping on cert or budget. Pure orchestration over injected executors.
 */
final class AtlasLoopAutonomousConductorTest extends TestCase
{
    public function test_accepts_on_first_tier_when_best_of_n_certifies(): void
    {
        $out = (new AtlasLoopAutonomousConductor)->conduct('goal', [
            'max_rounds' => 6,
            'tier_executors' => [
                'best_of_n' => fn () => ['certified' => true, 'reason' => 'certified'],
            ],
        ]);
        $this->assertTrue($out['certified']);
        $this->assertSame(1, $out['rounds']);
        $this->assertSame('certified', $out['final_reason']);
        $this->assertCount(1, $out['tier_history']);
    }

    public function test_escalates_through_tiers_until_one_certifies(): void
    {
        $seenGuidance = [];
        $out = (new AtlasLoopAutonomousConductor)->conduct('goal', [
            'max_rounds' => 6,
            'tier_executors' => [
                'best_of_n' => fn ($g, $guid) => ['certified' => false, 'reason' => 'complexity_not_reduced'],
                'repair_from_refutation' => function ($g, $guid) use (&$seenGuidance) {
                    $seenGuidance[] = $guid; // round 2 should carry the do-not-repeat digest from round 1

                    return ['certified' => false, 'reason' => 'sibling_test_red'];
                },
                'decompose' => fn () => ['certified' => true, 'reason' => 'certified'],
            ],
        ]);
        $this->assertTrue($out['certified'], json_encode($out['tier_history']));
        $this->assertSame(3, $out['rounds']);
        $this->assertSame(['best_of_n', 'repair_from_refutation', 'decompose'], array_map(fn ($h) => $h['tier'], $out['tier_history']));
        $this->assertNotEmpty($seenGuidance[0] ?? '', 'working-memory guidance from prior failed rounds is fed forward');
    }

    public function test_gives_up_at_budget_when_nothing_certifies(): void
    {
        $fail = fn () => ['certified' => false, 'reason' => 'still_red'];
        $out = (new AtlasLoopAutonomousConductor)->conduct('goal', [
            'max_rounds' => 3,
            'tier_executors' => [
                'best_of_n' => $fail,
                'repair_from_refutation' => $fail,
                'decompose' => $fail,
                'escalate_provider' => $fail,
            ],
        ]);
        $this->assertFalse($out['certified']);
        $this->assertStringContainsString('budget_exhausted', $out['final_reason']);
        $this->assertSame(3, $out['rounds']);
    }

    public function test_skips_unwired_tier_and_escalates(): void
    {
        $out = (new AtlasLoopAutonomousConductor)->conduct('goal', [
            'max_rounds' => 6,
            'tier_executors' => [
                // best_of_n NOT wired => skipped; repair certifies
                'repair_from_refutation' => fn () => ['certified' => true, 'reason' => 'certified'],
            ],
        ]);
        $this->assertTrue($out['certified']);
        $skipped = array_values(array_filter($out['tier_history'], fn ($h) => ($h['skipped'] ?? false) === true));
        $this->assertNotEmpty($skipped, 'an unwired tier is skipped, not fatal');
        $this->assertSame('best_of_n', $skipped[0]['tier']);
    }

    public function test_compiles_spec_and_merges_on_certify(): void
    {
        $merged = false;
        $out = (new AtlasLoopAutonomousConductor)->conduct('make export idempotent', [
            'max_rounds' => 4,
            'compile_spec' => fn ($g) => ['summary' => $g, 'acceptance_criteria' => [['id' => 'a', 'description' => 'x', 'required' => true]]],
            'tier_executors' => [
                'best_of_n' => fn () => ['certified' => true, 'reason' => 'certified', 'provider' => 'hermes_cli'],
            ],
            'merge' => function ($lastOutcome) use (&$merged) {
                $merged = true;

                return ['merged' => true];
            },
        ]);
        $this->assertTrue($out['certified']);
        $this->assertTrue($out['merged'], 'a certified delivery is merged via the governed hook');
        $this->assertTrue($merged);
        $this->assertSame('make export idempotent', $out['spec']['summary']);
    }

    public function test_reaches_escalate_provider_tier_then_exhausts(): void
    {
        // (workflow fix #2) drive all four tiers to fail with budget >= 4 so escalate_provider ACTUALLY
        // dispatches and the conductor terminates via ladder_exhausted_uncertified.
        $ran = [];
        $fail = function ($g, $guid, $spec, $round) use (&$ran) {
            return ['certified' => false, 'reason' => 'still_red'];
        };
        $out = (new AtlasLoopAutonomousConductor)->conduct('goal', [
            'max_rounds' => 8,
            'tier_executors' => [
                'best_of_n' => $fail,
                'repair_from_refutation' => $fail,
                'decompose' => $fail,
                'escalate_provider' => function () use (&$ran) {
                    $ran[] = 'escalate_provider';

                    return ['certified' => false, 'reason' => 'still_red'];
                },
            ],
        ]);
        $this->assertContains('escalate_provider', $ran, 'the strongest tier is actually dispatched');
        $this->assertFalse($out['certified']);
        $this->assertSame('ladder_exhausted_uncertified', $out['final_reason']);
    }

    public function test_thrashing_fast_forwards_a_tier_at_conductor_level(): void
    {
        // (workflow fix #3) repeated IDENTICAL failures trip the ledger's thrashing signal, which makes the
        // ladder skip the adjacent tier — so the same failure does not burn every tier one-by-one.
        $tiersRun = [];
        $sameFail = function ($g, $guid, $spec, $round) use (&$tiersRun) {
            // strategy/reason identical every round => thrashing after the threshold
            return ['certified' => false, 'reason' => 'complexity_not_reduced', 'strategy' => 'same'];
        };
        $out = (new AtlasLoopAutonomousConductor)->conduct('goal', [
            'max_rounds' => 8,
            'thrash_threshold' => 2,
            'tier_executors' => [
                'best_of_n' => function ($g, $gd, $s, $r) use (&$tiersRun, $sameFail) {
                    $tiersRun[] = 'best_of_n';

                    return $sameFail($g, $gd, $s, $r);
                },
                'repair_from_refutation' => function ($g, $gd, $s, $r) use (&$tiersRun, $sameFail) {
                    $tiersRun[] = 'repair';

                    return $sameFail($g, $gd, $s, $r);
                },
                'decompose' => function ($g, $gd, $s, $r) use (&$tiersRun, $sameFail) {
                    $tiersRun[] = 'decompose';

                    return $sameFail($g, $gd, $s, $r);
                },
                'escalate_provider' => function ($g, $gd, $s, $r) use (&$tiersRun, $sameFail) {
                    $tiersRun[] = 'escalate_provider';

                    return $sameFail($g, $gd, $s, $r);
                },
            ],
        ]);
        // Thrashing trips after 2 identical failures (best_of_n + repair), so the round-3 decision jumps
        // +2 from repair past 'decompose' straight to 'escalate_provider'. Net effect: a tier is SKIPPED.
        $this->assertContains('best_of_n', $tiersRun);
        $this->assertContains('repair', $tiersRun);
        $this->assertNotContains('decompose', $tiersRun, 'thrashing skipped a tier (jumped past decompose)');
        $this->assertLessThan(4, count(array_unique($tiersRun)), 'not every tier was burned one-by-one');
        $this->assertTrue($out['convergence']['thrashing']);
    }

    public function test_a_throwing_tier_executor_is_caught_and_escalates(): void
    {
        // (workflow fix #5) a tier that throws does NOT abort the conduct — it is a failed round, the ladder
        // escalates, and a later tier can still certify.
        $out = (new AtlasLoopAutonomousConductor)->conduct('goal', [
            'max_rounds' => 6,
            'tier_executors' => [
                'best_of_n' => function () {
                    throw new \RuntimeException('provider crashed');
                },
                'repair_from_refutation' => fn () => ['certified' => true, 'reason' => 'certified'],
            ],
        ]);
        $this->assertTrue($out['certified'], 'a throwing tier is survived, not fatal');
        $threw = array_values(array_filter($out['tier_history'], fn ($h) => str_contains((string) ($h['reason'] ?? ''), 'tier_threw')));
        $this->assertNotEmpty($threw, 'the throw is recorded as a failed round');
    }
}
