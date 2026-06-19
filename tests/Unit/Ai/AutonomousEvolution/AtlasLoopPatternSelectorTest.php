<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternRegistry;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternSelector;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternSpec;

/**
 * Proves the Selector's contract: it routes each objective kind to an APPROPRIATE (and distinct) pattern,
 * REFUSES cosmetic/proxy work outright (the load-bearing anti-Goodhart gate), only ever returns selectable
 * patterns, ranks stronger verification above weaker, and is deterministic.
 */
final class AtlasLoopPatternSelectorTest extends \Tests\TestCase
{
    private function selector(): AtlasLoopPatternSelector
    {
        return new AtlasLoopPatternSelector;
    }

    private function registry(): AtlasLoopPatternRegistry
    {
        return new AtlasLoopPatternRegistry;
    }

    /** A real, non-cosmetic objective with a healthy expected impact. */
    private function objective(string $kind, array $overrides = []): array
    {
        return array_merge([
            'objective_kind' => $kind,
            'expected_impact' => 0.8,
            'evidence' => 0.5,
            'risk' => 0.3,
            'cost' => 0.3,
            'cosmetic' => false,
            'touches_loop' => false,
        ], $overrides);
    }

    public function test_each_objective_kind_selects_an_appropriate_and_distinct_pattern(): void
    {
        $selector = $this->selector();
        $registry = $this->registry();

        $expected = [
            'docs' => 'docs_sweep',
            'bug' => 'production_error_sweep',
            'self_improvement' => 'self_improving_champion',
        ];

        $picked = [];
        foreach ($expected as $kind => $patternId) {
            $result = $selector->select($this->objective($kind), $registry);

            $this->assertFalse($result['rejected'], "kind '{$kind}' should select, not reject");
            $this->assertInstanceOf(AtlasLoopPatternSpec::class, $result['pattern']);
            $this->assertSame($patternId, $result['pattern']->id, "kind '{$kind}' must route to '{$patternId}'");
            $this->assertContains($kind, $result['pattern']->objectiveKinds());

            $picked[$kind] = $result['pattern']->id;
        }

        // Different kinds select different patterns — the selector is not a constant function.
        $this->assertCount(count($expected), array_unique($picked), 'distinct kinds must map to distinct patterns');
    }

    public function test_verification_and_refactor_route_to_their_lanes(): void
    {
        $selector = $this->selector();
        $registry = $this->registry();

        // verification -> a verification-lane pattern (harness / fresh-clone / devils-advocate / baseline).
        $verification = $selector->select($this->objective('verification'), $registry);
        $this->assertFalse($verification['rejected']);
        $this->assertContains('verification', $verification['pattern']->objectiveKinds());
        $this->assertContains(
            $verification['pattern']->id,
            ['loop_harness_verification', 'fresh_clone', 'devils_advocate', 'post_release_baseline'],
            'verification must land in a verification-lane pattern'
        );

        // refactor -> ticket_to_pr_ready, and NEVER the deprecated cosmetic tombstone.
        $refactor = $selector->select($this->objective('refactor'), $registry);
        $this->assertFalse($refactor['rejected']);
        $this->assertSame('ticket_to_pr_ready', $refactor['pattern']->id);
        $this->assertNotSame('legacy_blind_refactor', $refactor['pattern']->id);
    }

    public function test_cosmetic_objective_is_rejected_with_null_pattern(): void
    {
        $selector = $this->selector();
        $registry = $this->registry();

        $result = $selector->select(
            $this->objective('refactor', ['cosmetic' => true, 'expected_impact' => 0.9]),
            $registry
        );

        $this->assertTrue($result['rejected'], 'a cosmetic objective MUST be rejected');
        $this->assertNull($result['pattern'], 'a rejected cosmetic objective yields no pattern');
        $this->assertSame(0.0, $result['score']);
        $this->assertSame([], $result['ranking']);
        $this->assertStringContainsStringIgnoringCase('cosmetic', $result['reason']);
    }

    public function test_zero_impact_objective_without_real_target_is_rejected(): void
    {
        $selector = $this->selector();
        $registry = $this->registry();

        // Not flagged cosmetic, but ~zero expected impact == proxy work the loop must refuse.
        $result = $selector->select(
            $this->objective('refactor', ['cosmetic' => false, 'expected_impact' => 0.0]),
            $registry
        );

        $this->assertTrue($result['rejected'], 'zero-impact proxy work MUST be rejected');
        $this->assertNull($result['pattern']);
    }

    public function test_touches_loop_does_not_bypass_the_cosmetic_gate(): void
    {
        $selector = $this->selector();
        $registry = $this->registry();

        // The loop's first territory is itself, but that grants NO exemption from the cosmetic gate.
        $result = $selector->select(
            $this->objective('refactor', ['cosmetic' => true, 'touches_loop' => true]),
            $registry
        );

        $this->assertTrue($result['rejected']);
        $this->assertNull($result['pattern']);
    }

    public function test_selection_only_ever_returns_a_selectable_pattern(): void
    {
        $selector = $this->selector();
        $registry = $this->registry();

        $selectableIds = array_map(
            static fn (AtlasLoopPatternSpec $s): string => $s->id,
            $registry->selectable()
        );

        foreach (AtlasLoopPatternSelector::OBJECTIVE_KINDS as $kind) {
            $result = $selector->select($this->objective($kind), $registry);
            if ($result['rejected']) {
                continue;
            }
            $this->assertTrue($result['pattern']->isSelectable(), "kind '{$kind}' returned a non-selectable pattern");
            $this->assertContains($result['pattern']->id, $selectableIds);

            // Defence: the deprecated cosmetic tombstone and the quarantined external entry are never picked.
            $this->assertNotSame('legacy_blind_refactor', $result['pattern']->id);
            $this->assertNotSame('loop_library_full_product_eval', $result['pattern']->id);
        }
    }

    public function test_stronger_verification_pattern_outranks_a_weaker_one(): void
    {
        $selector = $this->selector();

        // Two patterns fit the SAME objective_kind; identical except verification strength. The one with
        // more gates + an independent verifier lane MUST win, proving verification drives the ranking.
        $strong = AtlasLoopPatternSpec::fromArray([
            'id' => 'strong_verify',
            'version' => '1.0.0',
            'intent' => 'bug',
            'trigger_schema' => ['objective_kinds' => ['bug']],
            'success_gates' => ['repro turns green', 'root cause named', 'no regression in suite'],
            'terminal_states' => ['success', 'blocked'],
            'durability_mode' => 'single_cycle',
            'sandbox_profile' => ['allowed' => ['read_only', 'worktree_write']],
            'agent_lane_policy' => ['self_approval' => false, 'verifier_independent' => true],
            'risk_level' => 'low',
            'source' => 'operator_seed',
            'status' => 'default',
        ]);

        $weak = AtlasLoopPatternSpec::fromArray([
            'id' => 'weak_verify',
            'version' => '1.0.0',
            'intent' => 'bug',
            'trigger_schema' => ['objective_kinds' => ['bug']],
            'success_gates' => ['it looks fixed'], // single weak gate
            'terminal_states' => ['success', 'blocked'],
            'durability_mode' => 'single_cycle',
            'sandbox_profile' => ['allowed' => ['read_only', 'worktree_write']],
            'agent_lane_policy' => ['self_approval' => true, 'verifier_independent' => false], // self-certifying
            'risk_level' => 'low',
            'source' => 'operator_seed',
            'status' => 'default',
        ]);

        // Register weak FIRST so a win for strong cannot be an insertion-order artifact.
        $registry = new AtlasLoopPatternRegistry([$weak, $strong]);

        $result = $selector->select($this->objective('bug'), $registry);

        $this->assertFalse($result['rejected']);
        $this->assertSame('strong_verify', $result['pattern']->id, 'stronger verification must win');
        $this->assertSame('strong_verify', $result['ranking'][0]['id']);
        $this->assertSame('weak_verify', $result['ranking'][1]['id']);
        $this->assertGreaterThan($result['ranking'][1]['score'], $result['ranking'][0]['score']);
    }

    public function test_ranking_is_deterministic_across_repeated_calls(): void
    {
        $selector = $this->selector();
        $registry = $this->registry();

        $first = $selector->select($this->objective('verification'), $registry);
        $second = $selector->select($this->objective('verification'), $registry);

        $this->assertSame($first['ranking'], $second['ranking'], 'identical inputs must yield identical ranking');
        $this->assertSame($first['pattern']->id, $second['pattern']->id);
        $this->assertSame($first['score'], $second['score']);

        // The ranking is sorted by score DESC (deterministic, id tie-broken).
        $scores = array_map(static fn (array $r): float => $r['score'], $first['ranking']);
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores, 'ranking must be ordered by score descending');
    }

    public function test_unknown_objective_kind_is_rejected(): void
    {
        $selector = $this->selector();
        $registry = $this->registry();

        $result = $selector->select($this->objective('marketing_blast'), $registry);

        $this->assertTrue($result['rejected']);
        $this->assertNull($result['pattern']);
        $this->assertSame([], $result['ranking']);
    }

    /**
     * Regression (adversarially found): a non-finite expected_impact must NOT slip the anti-cosmetic
     * gate. `NAN <= EPSILON` is false by IEEE-754, so without a finite-check a NaN impact would be
     * treated as healthy work. NaN and ±INF must all route into the negligible-impact rejection.
     */
    public function test_non_finite_impact_is_rejected_not_treated_as_healthy_work(): void
    {
        $selector = $this->selector();
        $registry = $this->registry();

        foreach (['NaN' => NAN, '+INF' => INF, '-INF' => -INF] as $label => $impact) {
            $result = $selector->select(
                $this->objective('refactor', ['expected_impact' => $impact, 'cosmetic' => false]),
                $registry
            );

            $this->assertTrue($result['rejected'], "{$label} impact must be rejected");
            $this->assertNull($result['pattern'], "{$label} impact must yield no pattern");
            $this->assertSame(0.0, $result['score'], "{$label} impact must score 0.0");
        }
    }
}
