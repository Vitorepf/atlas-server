<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordOsBacktest;
use PHPUnit\Framework\TestCase;

/**
 * Locks the backtest harness: it measures the OS hit-rate against REAL converting keywords (the winners
 * that actually sold, from Blackink read-only), and surfaces the MISSES as calibration targets. Validated
 * live at 90.7% over 108 real winners.
 */
class KeywordOsBacktestTest extends TestCase
{
    public function test_measures_hit_rate_and_surfaces_misses(): void
    {
        $patterns = [[
            'niche' => 'weight_loss',
            'converting_keywords' => [
                ['term' => 'gelatin trick', 'conversions' => 10], // mechanism → qualified
                ['term' => 'memory loss', 'conversions' => 5],     // bare symptom → OS excludes (miss)
                ['term' => '{keyword}', 'conversions' => 3],        // DKI placeholder → ignored
            ],
        ]];

        $r = (new KeywordOsBacktest)->run($patterns);

        $this->assertSame(2, $r['winners'], 'o placeholder {keyword} não conta como keyword real');
        $this->assertSame(1, $r['qualified']);
        $this->assertEqualsWithDelta(0.5, $r['overall_hit_rate'], 0.001);
        $this->assertArrayHasKey('weight_loss', $r['per_niche']);
        $this->assertSame('memory loss', $r['misses'][0]['term']);
    }

    public function test_empty_patterns_is_zero_not_crash(): void
    {
        $r = (new KeywordOsBacktest)->run([]);
        $this->assertSame(0.0, $r['overall_hit_rate']);
        $this->assertSame(0, $r['winners']);
    }
}
