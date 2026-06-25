<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordBudgetAllocator;
use PHPUnit\Framework\TestCase;

/**
 * Locks portfolio optimization under budget: spend the budget on the highest-ROAS keywords first (greedy is
 * optimal for divisible ad spend), never on money-losers, respect the budget cap. The "exactly how to spend
 * R$X for max profit" capability. Deterministic.
 */
class KeywordBudgetAllocatorTest extends TestCase
{
    private KeywordBudgetAllocator $a;

    protected function setUp(): void
    {
        $this->a = new KeywordBudgetAllocator;
    }

    /** @param array<string,mixed> $over */
    private function row(string $kw, int $volume, float $cpc, float $revenue, float $profit): array
    {
        return ['keyword' => $kw, 'volume' => $volume, 'cpc' => $cpc, 'expected_revenue' => $revenue, 'expected_profit' => $profit];
    }

    public function test_spends_highest_roas_first_and_excludes_losers(): void
    {
        $proj = [
            $this->row('low_roas', 1000, 1.0, 1500, 500),    // custo 1000, ROAS 1.5
            $this->row('high_roas', 1000, 0.5, 2000, 1500),  // custo 500, ROAS 4.0 (mais eficiente)
            $this->row('loser', 1000, 2.0, 1800, -200),      // lucro negativo → fora
        ];
        $r = $this->a->allocate($proj, 500); // budget só pro mais eficiente

        $this->assertSame('high_roas', $r['portfolio'][0]['keyword'], 'maior ROAS primeiro');
        $this->assertSame(1, count($r['portfolio']), 'budget 500 enche só o high_roas (custo 500)');
        $this->assertSame(1, $r['excluded_losers']);
        $this->assertLessThanOrEqual(500.0, $r['budget_used']);
    }

    public function test_respects_budget_and_partial_fills(): void
    {
        $proj = [
            $this->row('a', 1000, 0.5, 3000, 2500), // ROAS 6, custo 500
            $this->row('b', 1000, 1.0, 1500, 500),  // ROAS 1.5, custo 1000
        ];
        $r = $this->a->allocate($proj, 750); // enche 'a' (500) + metade de 'b' (250)

        $this->assertSame('a', $r['portfolio'][0]['keyword']);
        $this->assertSame(0.25, $r['portfolio'][1]['fraction'], 'b entra parcial (250/1000)');
        $this->assertSame(750.0, $r['budget_used']);
    }

    public function test_deterministic(): void
    {
        $proj = [$this->row('a', 1000, 0.5, 3000, 2500), $this->row('b', 1000, 1.0, 1500, 500)];
        $this->assertSame($this->a->allocate($proj, 750), $this->a->allocate($proj, 750));
    }
}
