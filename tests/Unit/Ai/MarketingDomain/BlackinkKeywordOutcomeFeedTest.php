<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\BlackinkKeywordOutcomeFeed;
use PHPUnit\Framework\TestCase;

/**
 * Locks the pure core of the antifragile feed: given real (term/clicks/conversions) rows, it produces a
 * calibration where the winner is up-weighted and the loser down-weighted. The read-only pull() is the
 * only DB-touching part (validated live), kept thin around this pure core.
 */
class BlackinkKeywordOutcomeFeedTest extends TestCase
{
    public function test_calibration_from_rows_separates_winner_from_loser(): void
    {
        $cal = (new BlackinkKeywordOutcomeFeed)->calibrationFromRows([
            ['term' => 'gelatin trick', 'clicks' => 300, 'conversions' => 18],
            ['term' => 'gelatin recipe', 'clicks' => 300, 'conversions' => 0],
        ]);

        $this->assertArrayHasKey('weights', $cal);
        $this->assertGreaterThan(1.0, $cal['weights']['gelatin trick']);
        $this->assertLessThan(1.0, $cal['weights']['gelatin recipe']);
        $this->assertGreaterThan(0.0, $cal['baseline_cvr']);
    }

    public function test_empty_rows_is_safe(): void
    {
        $cal = (new BlackinkKeywordOutcomeFeed)->calibrationFromRows([]);
        $this->assertSame([], $cal['weights']);
    }
}
