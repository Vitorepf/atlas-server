<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPortfolioBalancer;
use Tests\TestCase;

final class AtlasExternalBrainPortfolioBalancerTest extends TestCase
{
    private function balancer(): AtlasExternalBrainPortfolioBalancer
    {
        return new AtlasExternalBrainPortfolioBalancer;
    }

    public function test_high_give_back_poison_malformed_collision_pressure_shifts_toward_repair_and_queue_self_healing(): void
    {
        $baseline = $this->balancer()->balancePortfolio([]);
        $r = $this->balancer()->balancePortfolio([
            'give_back_rate' => 0.9,
            'poison_rate' => 0.9,
            'malformed_rate' => 0.9,
            'collision_rate' => 0.9,
        ]);

        self::assertGreaterThan($baseline['percentages']['repair'], $r['percentages']['repair']);
        self::assertGreaterThan($baseline['percentages']['queue_self_healing'], $r['percentages']['queue_self_healing']);
        self::assertSame(100, $r['total_percent']);
        self::assertNotEmpty($r['reasons']);
    }

    public function test_high_simplification_debt_shifts_toward_simplify_without_starving_repair_or_verification(): void
    {
        $baseline = $this->balancer()->balancePortfolio([]);
        $r = $this->balancer()->balancePortfolio(['simplification_debt' => 0.9]);

        self::assertGreaterThan($baseline['percentages']['simplify'], $r['percentages']['simplify']);
        self::assertGreaterThanOrEqual(10, $r['percentages']['repair']);
        self::assertGreaterThanOrEqual(10, $r['percentages']['verification']);
        self::assertSame(100, $r['total_percent']);
        self::assertNotEmpty($r['reasons']);
    }

    public function test_healthy_shallow_queue_with_high_leverage_keeps_build_and_research_active_and_normalizes_to_100(): void
    {
        $baseline = $this->balancer()->balancePortfolio([]);
        $r = $this->balancer()->balancePortfolio([
            'queue_shallow' => true,
            'high_leverage_candidates' => true,
        ]);

        self::assertGreaterThan($baseline['percentages']['build'], $r['percentages']['build']);
        self::assertGreaterThan($baseline['percentages']['research'], $r['percentages']['research']);
        self::assertSame(100, $r['total_percent']);
        self::assertNotEmpty($r['reasons']);
    }

    public function test_no_signals_returns_baseline_distribution_summing_to_100(): void
    {
        $r = $this->balancer()->balancePortfolio([]);

        self::assertSame(100, $r['total_percent']);
        foreach (AtlasExternalBrainPortfolioBalancer::PORTFOLIO_LANES as $lane) {
            self::assertArrayHasKey($lane, $r['percentages']);
        }
        self::assertNotEmpty($r['reasons']);
    }
}
