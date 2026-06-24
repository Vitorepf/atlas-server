<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\BlackinkSearchTermHarvester;
use PHPUnit\Framework\TestCase;

/**
 * Locks the L2 real-discovery vector: real search terms (utm_term) are deduped (merging traffic), DKI
 * placeholders and empties dropped, each classified by intent, and prioritized deterministically —
 * a converter outranks a non-converter, intent breaks ties, and the order is stable.
 */
class BlackinkSearchTermHarvesterTest extends TestCase
{
    private BlackinkSearchTermHarvester $h;

    protected function setUp(): void
    {
        $this->h = new BlackinkSearchTermHarvester;
    }

    public function test_dedups_skips_junk_and_classifies(): void
    {
        $r = $this->h->fromRows([
            ['term' => 'blue salt trick', 'clicks' => 100, 'conversions' => 5],
            ['term' => 'what is gelatin', 'clicks' => 200, 'conversions' => 0],
            ['term' => 'blue salt trick', 'clicks' => 50, 'conversions' => 2], // dup → merges traffic
            ['term' => '{keyword}', 'clicks' => 30, 'conversions' => 0],        // DKI placeholder → skip
            ['term' => '   ', 'clicks' => 10, 'conversions' => 0],              // empty → skip
        ]);

        $this->assertSame(2, $r['count'], 'dedup + skip junk');
        $this->assertSame(1, $r['converters']);

        $top = $r['terms'][0];
        $this->assertSame('blue salt trick', $top['term'], 'o que vendeu lidera a descoberta');
        $this->assertSame(150, $top['clicks'], 'tráfego das variantes idênticas somado');
        $this->assertSame(7, $top['conversions']);
        $this->assertTrue($top['converted']);
        $this->assertArrayHasKey('intent_tier', $top);
    }

    public function test_deterministic_stable_order(): void
    {
        $rows = [
            ['term' => 'aaa pill', 'clicks' => 40, 'conversions' => 0],
            ['term' => 'bbb pill', 'clicks' => 40, 'conversions' => 0],
        ];
        $a = array_column($this->h->fromRows($rows)['terms'], 'term');
        $b = array_column($this->h->fromRows(array_reverse($rows))['terms'], 'term');
        $this->assertSame($a, $b, 'mesma entrada (qualquer ordem) → mesma saída');
    }

    public function test_empty_is_safe(): void
    {
        $r = $this->h->fromRows([]);
        $this->assertSame(0, $r['count']);
        $this->assertSame([], $r['terms']);
    }
}
