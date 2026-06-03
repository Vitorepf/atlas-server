<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop;

use App\Services\Ai\Finance\StrategyLoop\MarketDataCache;
use PHPUnit\Framework\TestCase;

/**
 * Pins the loop's most important honesty primitive: a strategy can NEVER see a bar
 * from its own future. If this test is green, look-ahead through the data layer is
 * structurally impossible. Uses real-scale epoch stamps so the µs→ms normalization
 * (Binance's 2025 switch to 16-digit microseconds) is exercised at the true boundary.
 */
final class MarketDataCacheLookAheadTest extends TestCase
{
    private string $dir;

    // 2020-09-13 .. +2 days, in MILLISECONDS.
    private const B1_OPEN = 1600000000000;

    private const B1_CLOSE = 1600086399999;

    private const B2_OPEN = 1600086400000;

    private const B2_CLOSE = 1600172799999;

    private const B3_OPEN = 1600172800000;   // expected after µs normalization

    private const B3_CLOSE = 1600259199999;  // expected after µs normalization

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/atlas-mdcache-'.bin2hex(random_bytes(4));
        @mkdir($this->dir, 0o755, true);
        // header + 3 candles; bar3 is written in MICROSECONDS (×1000) to prove the fold.
        $csv = "open_time,open,high,low,close,volume,close_time\n"
            ."1600000000000,10,11,9,10.5,100,1600086399999\n"           // bar1
            ."1600086400000,10.5,12,10,11.5,120,1600172799999\n"        // bar2
            ."1600172800000000,11.5,13,11,12.5,140,1600259199999000\n"; // bar3 (µs)
        file_put_contents($this->dir.'/BTCUSDT-1d.csv', $csv);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/BTCUSDT-1d.csv');
        @rmdir($this->dir);
    }

    public function test_load_returns_all_bars_sorted_and_normalized(): void
    {
        $bars = (new MarketDataCache($this->dir))->load('BTCUSDT', '1d');

        $this->assertCount(3, $bars);
        $this->assertSame(self::B1_OPEN, $bars[0]->openTime);
        $this->assertSame(self::B2_OPEN, $bars[1]->openTime);
        // the µs row is folded to ms
        $this->assertSame(self::B3_OPEN, $bars[2]->openTime);
        $this->assertSame(self::B3_CLOSE, $bars[2]->closeTime);
        $this->assertSame(12.5, $bars[2]->close);
    }

    public function test_as_of_excludes_any_bar_still_in_the_future(): void
    {
        $cache = new MarketDataCache($this->dir);

        // as-of exactly bar2's close: bars 1 and 2 are known, bar3 is NOT.
        $known = $cache->barsAsOf('BTCUSDT', '1d', self::B2_CLOSE);
        $this->assertCount(2, $known);
        $this->assertSame(self::B2_OPEN, $known[1]->openTime);

        // one ms before bar2 closes: only bar1 is known.
        $this->assertCount(1, $cache->barsAsOf('BTCUSDT', '1d', self::B2_CLOSE - 1));

        // the invariant, stated as an assertion: nothing visible closes in the future.
        foreach ($cache->barsAsOf('BTCUSDT', '1d', self::B2_CLOSE) as $b) {
            $this->assertLessThanOrEqual(self::B2_CLOSE, $b->closeTime);
        }
    }
}
