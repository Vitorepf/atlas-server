<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\Maestro\ProviderLearning\AtlasMaestroProviderRecommendationEngine;
use Tests\TestCase;

/**
 * Proves bestProviderFor() ranks providers by Wilson score lower bound
 * so 47/50 (Wilson LB ~0.84) beats 5/5 (Wilson LB ~0.57) even though
 * the raw rates are 0.94 vs 1.0.
 *
 * BEFORE Wilson: a provider with 5/5 (raw 1.0) is ranked #1 over 47/50 (raw 0.94).
 * AFTER Wilson:  47/50 (Wilson LB ~0.84) beats 5/5 (Wilson LB ~0.57) — better-proven wins.
 *
 * Tests the Wilson function via reflection since the real ledger depends on
 * a database with seeded facts. The existing tests in
 * AtlasMaestroProviderRecommendationEngineTest prove the full integration.
 */
final class AtlasMaestroProviderRecommendationEngineWilsonTest extends TestCase
{
    public function test_wilson_lower_bound_47_of_50_exceeds_5_of_5(): void
    {
        // Test the Wilson lower bound function directly via a reflection accessor
        // to prove 47/50 > 5/5 under the Wilson score.
        $ref = new \ReflectionMethod(
            AtlasMaestroProviderRecommendationEngine::class,
            'wilsonLowerBound',
        );
        $ref->setAccessible(true);

        $lb5of5 = $ref->invoke(null, 5.0, 5.0);
        $lb47of50 = $ref->invoke(null, 47.0, 50.0);

        // 5/5 raw rate = 1.0, but Wilson LB ~0.57 (wide uncertainty).
        // 47/50 raw rate = 0.94, but Wilson LB ~0.84 (narrow uncertainty).
        $this->assertGreaterThan($lb5of5, $lb47of50, 'Wilson LB of 47/50 must exceed 5/5');
        $this->assertLessThan(0.75, $lb5of5, '5/5 Wilson LB must be below 0.75 (not statistically confident)');
        $this->assertGreaterThan(0.75, $lb47of50, '47/50 Wilson LB must be above 0.75 (statistically confident)');
    }

    public function test_wilson_lower_bound_truly_dominant_thin_provider_still_wins(): void
    {
        $ref = new \ReflectionMethod(
            AtlasMaestroProviderRecommendationEngine::class,
            'wilsonLowerBound',
        );
        $ref->setAccessible(true);

        // Provider A: 5/5 (Wilson LB ~0.57)
        // Provider B: 5/50 (Wilson LB ~0.04)
        $lbDominant = $ref->invoke(null, 5.0, 5.0);
        $lbWeak = $ref->invoke(null, 5.0, 50.0);

        $this->assertGreaterThan($lbWeak, $lbDominant,
            '5/5 despite thin sample beats 5/50 (low success rate)');
    }

    public function test_wilson_lower_bound_returns_0_for_empty_sample(): void
    {
        $ref = new \ReflectionMethod(
            AtlasMaestroProviderRecommendationEngine::class,
            'wilsonLowerBound',
        );
        $ref->setAccessible(true);

        $lb = $ref->invoke(null, 0.0, 0.0);

        $this->assertSame(0.0, $lb);
    }

    public function test_wilson_lower_bound_is_deterministic(): void
    {
        $ref = new \ReflectionMethod(
            AtlasMaestroProviderRecommendationEngine::class,
            'wilsonLowerBound',
        );
        $ref->setAccessible(true);

        $a = $ref->invoke(null, 47.0, 50.0);
        $b = $ref->invoke(null, 47.0, 50.0);

        $this->assertSame($a, $b);
    }
}
