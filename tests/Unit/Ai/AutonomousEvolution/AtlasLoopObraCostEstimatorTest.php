<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopObraCostEstimator;
use PHPUnit\Framework\TestCase;

/**
 * ACDE M4 — the obra cost estimator de-orphans the budget scheduler's cost input from frozen attempt
 * telemetry. Pure core (no DB, no container) => hang-free. Proves the per-provider mean spend (USD preferred,
 * tokens fallback), the provider-invoked + positive-cost filters, and that attachCosts never clobbers an obra
 * that already carries costs.
 */
final class AtlasLoopObraCostEstimatorTest extends TestCase
{
    private function estimator(): AtlasLoopObraCostEstimator
    {
        return new AtlasLoopObraCostEstimator;
    }

    private function row(array ...$attempts): array
    {
        return ['target_path' => 'app/X/Y.php', 'attempt_metrics' => array_values($attempts)];
    }

    public function test_aggregates_mean_cost_per_provider_usd_preferred(): void
    {
        $rows = [
            $this->row(
                ['provider' => 'codex', 'provider_invoked' => true, 'cost_estimate_usd' => 0.10],
                ['provider' => 'codex', 'provider_invoked' => true, 'cost_estimate_usd' => 0.30],
            ),
            $this->row(
                ['provider' => 'minimax', 'provider_invoked' => true, 'tokens_used' => 1000], // no usd => tokens fallback
            ),
        ];

        $costs = $this->estimator()->aggregate($rows);

        $this->assertEqualsWithDelta(0.20, $costs['codex'], 1e-9, 'mean of 0.10 and 0.30');
        $this->assertEqualsWithDelta(1000.0, $costs['minimax'], 1e-9, 'tokens fallback when no USD cost');
    }

    public function test_skips_non_invoked_and_non_positive_cost_attempts(): void
    {
        $rows = [
            $this->row(
                ['provider' => 'ghost', 'provider_invoked' => false, 'cost_estimate_usd' => 5.0], // not invoked
                ['provider' => 'zero', 'provider_invoked' => true, 'cost_estimate_usd' => 0.0, 'tokens_used' => 0], // no positive cost
                ['provider' => '', 'provider_invoked' => true, 'cost_estimate_usd' => 1.0], // blank provider
            ),
        ];

        $this->assertSame([], $this->estimator()->aggregate($rows));
    }

    public function test_attach_costs_never_clobbers_existing_costs(): void
    {
        $obras = [
            ['id' => 'a', 'target_path' => 'app/X/Y.php', 'costs' => ['minimax' => 0.5]],
            ['id' => 'b'], // no target => untouched, no DB call
        ];

        $out = $this->estimator()->attachCosts($obras);

        $this->assertSame(['minimax' => 0.5], $out[0]['costs'], 'an obra with costs is left as-is (no telemetry override)');
        $this->assertArrayNotHasKey('costs', $out[1], 'an obra with no target gets no costs (and triggers no DB read)');
    }
}
