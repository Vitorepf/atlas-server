<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordParetoConcentrator;
use PHPUnit\Framework\TestCase;

/**
 * Locks Marshall's 80/20 operationalized: the smallest set of keywords capturing X% of projected revenue.
 * Deterministic; sorts by revenue desc; captures at least the cut; empty-safe.
 */
class KeywordParetoConcentratorTest extends TestCase
{
    private KeywordParetoConcentrator $c;

    protected function setUp(): void
    {
        $this->c = new KeywordParetoConcentrator;
    }

    public function test_finds_smallest_set_capturing_the_cut(): void
    {
        // 1 keyword domina (800) + 4 triviais (50 cada = 200). total 1000; 80% = 800 → só o herói entra.
        $ranked = [
            ['keyword' => 'a', 'expected_revenue' => 50.0],
            ['keyword' => 'hero', 'expected_revenue' => 800.0],
            ['keyword' => 'b', 'expected_revenue' => 50.0],
            ['keyword' => 'c', 'expected_revenue' => 50.0],
            ['keyword' => 'd', 'expected_revenue' => 50.0],
        ];
        $r = $this->c->concentrate($ranked, 0.80);
        $this->assertSame(1, $r['vital_count'], 'o herói sozinho captura 80%');
        $this->assertSame('hero', $r['vital_few'][0]['keyword']);
        $this->assertSame(5, $r['total_count']);
        $this->assertGreaterThanOrEqual(800.0, $r['revenue_captured']);
    }

    public function test_ignores_zero_revenue_and_is_empty_safe(): void
    {
        $this->assertSame(0, $this->c->concentrate([['keyword' => 'x', 'expected_revenue' => 0.0]])['vital_count']);
        $this->assertSame(0, $this->c->concentrate([])['total_count']);
    }

    public function test_deterministic(): void
    {
        $ranked = [['keyword' => 'a', 'expected_revenue' => 300.0], ['keyword' => 'b', 'expected_revenue' => 100.0]];
        $this->assertEquals($this->c->concentrate($ranked), $this->c->concentrate($ranked));
    }
}
